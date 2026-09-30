<?php
/**
 * TWT AEO Key Resolver
 *
 * Centralized API-key resolution with a three-tier priority chain:
 *
 *   1. PHP constant   (wp-config.php)          — never stored in DB, never logged
 *   2. Server env var (web-server / Docker)    — never stored in DB, never logged
 *   3. Plugin DB option                        — legacy / standalone fallback
 *
 * WordPress 7.0 Connectors keys are deliberately NOT resolved here. Those
 * credentials were granted by the user to WordPress core, not to this plugin,
 * so the plugin must never read connectors_ai_*_api_key options directly.
 * Instead, when no plugin-owned key is configured, the plugin's AI features route
 * the request through the AI Client API (wp_ai_client_prompt()), which lets
 * WordPress use the configured connection without ever exposing the raw key.
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
	 * Slug → [ PHP_CONSTANT, ENV_VAR, settings_db_field ]
	 */
	private static $map = array(
		'claude'        => array( 'TWTAEO_CLAUDE_KEY',     'TWTAEO_CLAUDE_KEY',     'api_claude' ),
		'openai'        => array( 'TWTAEO_OPENAI_KEY',     'TWTAEO_OPENAI_KEY',     'api_openai' ),
		'gemini'        => array( 'TWTAEO_GEMINI_KEY',     'TWTAEO_GEMINI_KEY',     'api_gemini' ),
		'perplexity'    => array( 'TWTAEO_PERPLEXITY_KEY', 'TWTAEO_PERPLEXITY_KEY', 'api_perplexity' ),
		'xai'           => array( 'TWTAEO_XAI_KEY',        'TWTAEO_XAI_KEY',        'api_xai' ),
		'mistral'       => array( 'TWTAEO_MISTRAL_KEY',    'TWTAEO_MISTRAL_KEY',    'api_mistral' ),
		'deepseek'      => array( 'TWTAEO_DEEPSEEK_KEY',   'TWTAEO_DEEPSEEK_KEY',   'api_deepseek' ),
		'meta'          => array( 'TWTAEO_META_KEY',       'TWTAEO_META_KEY',       'api_meta' ),
		'pro_key'       => array( 'TWTAEO_PRO_KEY',        'TWTAEO_PRO_KEY',        'pro_key' ),
		'pro_url'       => array( 'TWTAEO_PRO_URL',        'TWTAEO_PRO_URL',        'pro_url' ),
		'ein_presswire' => array( 'TWTAEO_EIN_KEY',        'TWTAEO_EIN_KEY',        'api_ein_presswire' ),
		'easypwire'     => array( 'TWTAEO_EASYPWIRE_KEY',  'TWTAEO_EASYPWIRE_KEY',  'api_easypwire' ),
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

		list( $constant, $env_var, $db_field ) = self::$map[ $slug ];

		// Tier 1 — PHP constant (wp-config.php or server config).
		if ( defined( $constant ) ) {
			return (string) constant( $constant );
		}

		// Tier 2 — Server environment variable.
		$env = getenv( $env_var );
		if ( $env !== false && $env !== '' ) {
			return (string) $env;
		}

		// Tier 3 — Plugin database option. Stored encrypted at rest; decrypt()
		// passes legacy plaintext through unchanged until its next save.
		$settings = get_option( 'twtaeo_settings', array() );
		return trim( TWTAEO_Crypt::decrypt( (string) ( $settings[ $db_field ] ?? '' ) ) );
	}

	/**
	 * True when the WordPress 7.0 AI Client API is available. When it is, the
	 * plugin's AI features can fulfil Claude/OpenAI requests through the user's
	 * configured connection without this plugin ever handling the raw key.
	 *
	 * @return bool
	 */
	public static function ai_client_available() {
		return function_exists( 'wp_ai_client_prompt' );
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
