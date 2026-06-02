<?php
/**
 * TWT AEO Key Resolver
 *
 * Centralized API-key resolution with a four-tier priority chain:
 *
 *   1. PHP constant   (wp-config.php)          — never stored in DB, never logged
 *   2. Server env var (web-server / Docker)    — never stored in DB, never logged
 *   3. WP 7.0 Connectors API                  — managed by WordPress core
 *   4. Plugin DB option                        — legacy / standalone fallback
 *
 * Agency rule: define keys as PHP constants or env vars so they never touch
 * the WordPress database. The plugin will never write, read back, or log a key
 * that is resolved via tier 1 or 2.
 *
 * Example wp-config.php entries:
 *
 *   define( 'TWTAEO_CLAUDE_KEY',      'sk-ant-api03-…' );
 *   define( 'TWTAEO_OPENAI_KEY',      'sk-proj-…' );
 *   define( 'TWTAEO_PERPLEXITY_KEY',  'pplx-…' );
 *   define( 'TWTAEO_PRO_KEY',         'your-pro-dashboard-key' );
 *   define( 'TWTAEO_PRO_URL',         'https://agency.example.com/wp-json/…' );
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Key_Resolver {

	/**
	 * Slug → [ PHP_CONSTANT, ENV_VAR, settings_db_field, wp7_connector_option ]
	 * A null wp7_connector_option means no WP 7.0 Connector exists for this key.
	 */
	private static $map = array(
		'claude'        => array( 'TWTAEO_CLAUDE_KEY',     'TWTAEO_CLAUDE_KEY',     'api_claude',         'connectors_ai_anthropic_api_key' ),
		'openai'        => array( 'TWTAEO_OPENAI_KEY',     'TWTAEO_OPENAI_KEY',     'api_openai',         'connectors_ai_openai_api_key' ),
		'perplexity'    => array( 'TWTAEO_PERPLEXITY_KEY', 'TWTAEO_PERPLEXITY_KEY', 'api_perplexity',     null ),
		'pro_key'       => array( 'TWTAEO_PRO_KEY',        'TWTAEO_PRO_KEY',        'pro_key',            null ),
		'pro_url'       => array( 'TWTAEO_PRO_URL',        'TWTAEO_PRO_URL',        'pro_url',            null ),
		'ein_presswire' => array( 'TWTAEO_EIN_KEY',        'TWTAEO_EIN_KEY',        'api_ein_presswire',  null ),
		'easypwire'     => array( 'TWTAEO_EASYPWIRE_KEY',  'TWTAEO_EASYPWIRE_KEY',  'api_easypwire',      null ),
	);

	/**
	 * Resolve an API key or credential using the four-tier priority chain.
	 *
	 * @param string $slug One of the slugs defined in self::$map.
	 * @return string      Resolved value, or empty string if not found anywhere.
	 */
	public static function get( $slug ) {
		if ( ! isset( self::$map[ $slug ] ) ) {
			return '';
		}

		list( $constant, $env_var, $db_field, $wp7_option ) = self::$map[ $slug ];

		// Tier 1 — PHP constant (wp-config.php or server config).
		if ( defined( $constant ) ) {
			return (string) constant( $constant );
		}

		// Tier 2 — Server environment variable.
		$env = getenv( $env_var );
		if ( $env !== false && $env !== '' ) {
			return (string) $env;
		}

		// Tier 3 — WordPress 7.0 Connectors API (where applicable).
		if ( $wp7_option && function_exists( 'wp_is_connector_registered' ) ) {
			$connector_key = trim( (string) get_option( $wp7_option, '' ) );
			if ( $connector_key !== '' ) {
				return $connector_key;
			}
		}

		// Tier 4 — Plugin database option.
		$settings = get_option( 'twtaeo_settings', array() );
		return trim( (string) ( $settings[ $db_field ] ?? '' ) );
	}

	/**
	 * True if the key is protected (resolved via constant or env var).
	 * Use this in the settings UI to disable the field and show a badge.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public static function is_protected( $slug ) {
		if ( ! isset( self::$map[ $slug ] ) ) {
			return false;
		}
		list( $constant, $env_var ) = self::$map[ $slug ];
		if ( defined( $constant ) ) {
			return true;
		}
		$env = getenv( $env_var );
		return $env !== false && $env !== '';
	}

	/**
	 * Return the PHP constant name for a given slug (shown in the settings UI).
	 *
	 * @param string $slug
	 * @return string
	 */
	public static function constant_name( $slug ) {
		return isset( self::$map[ $slug ] ) ? self::$map[ $slug ][0] : '';
	}
}
