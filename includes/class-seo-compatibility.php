<?php
/**
 * SEO Plugin Compatibility Checker
 *
 * Detects active SEO plugins, identifies conflicts, and reports
 * what each plugin is already handling so we never duplicate.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_SEO_Compatibility {

	/**
	 * All supported SEO plugins with their detection constants,
	 * option keys, and known schema outputs.
	 *
	 * @var array
	 */
	private static $plugins = array(

		'rank-math' => array(
			'name'          => 'Rank Math',
			'constant'      => 'RANK_MATH_VERSION',
			'option_key'    => 'rank_math_titles',
			'schema_output' => array(
				'always'      => array( 'WebSite', 'WebPage', 'Organization' ),
				'conditional' => array(
					'Article'     => 'Posts with article schema enabled',
					'BlogPosting' => 'Posts with BlogPosting schema enabled',
					'FAQPage'     => 'Pages with FAQ blocks',
					'BreadcrumbList' => 'When breadcrumbs enabled',
				),
			),
			'aeo_gaps'      => array(
				'Service schema not auto-generated for service pages',
				'No page intent classification — same schema on all page types',
				'sameAs / entity disambiguation not prompted',
			),
		),

		'yoast' => array(
			'name'          => 'Yoast SEO',
			'constant'      => 'WPSEO_VERSION',
			'option_key'    => 'wpseo',
			'schema_output' => array(
				'always'      => array( 'WebSite', 'WebPage', 'Organization' ),
				'conditional' => array(
					'Article'        => 'Posts (default)',
					'FAQPage'        => 'Pages with Yoast FAQ block',
					'BreadcrumbList' => 'When breadcrumbs enabled',
					'HowTo'          => 'Pages with Yoast HowTo block',
				),
			),
			'aeo_gaps'      => array(
				'Service schema not generated',
				'No location-level schema without Local SEO add-on',
				'No page intent classification',
			),
		),

		'aioseo' => array(
			'name'          => 'All in One SEO',
			'constant'      => 'AIOSEO_VERSION',
			'option_key'    => 'aioseo_options',
			'schema_output' => array(
				'always'      => array( 'WebSite', 'WebPage', 'Organization' ),
				'conditional' => array(
					'Article'      => 'Posts (default)',
					'FAQPage'      => 'Manual per-page setup',
					'LocalBusiness' => 'With Local SEO module',
				),
			),
			'aeo_gaps'      => array(
				'Schema per page requires manual setup',
				'No automatic page intent detection',
				'Service schema not auto-generated',
			),
		),

		'seopress' => array(
			'name'          => 'SEOPress',
			'constant'      => 'SEOPRESS_VERSION',
			'option_key'    => 'seopress_titles',
			'schema_output' => array(
				'always'      => array( 'WebSite', 'Organization' ),
				'conditional' => array(
					'WebPage'      => 'Most page types',
					'Article'      => 'Posts',
					'LocalBusiness' => 'With structured data module',
					'FAQPage'      => 'Manual setup',
				),
			),
			'aeo_gaps'      => array(
				'Structured data requires PRO for full automation',
				'No page intent classification',
			),
		),

		'seo-framework' => array(
			'name'          => 'The SEO Framework',
			'constant'      => 'THE_SEO_FRAMEWORK_VERSION',
			'option_key'    => 'autodescription-site-settings',
			'schema_output' => array(
				'always'      => array( 'WebSite', 'WebPage' ),
				'conditional' => array(
					'Organization' => 'When business type configured',
					'Article'      => 'Posts',
				),
			),
			'aeo_gaps'      => array(
				'Minimal schema by design — most types need extensions',
				'No FAQ, Service, or LocalBusiness schema by default',
			),
		),

		'saswp' => array(
			'name'          => 'Schema & Structured Data for WP & AMP',
			'constant'      => 'SASWP_VERSION',
			'option_key'    => 'saswp_settings',
			'schema_output' => array(
				'always'      => array(),
				'conditional' => array(
					'FAQPage'       => 'Manual per-page setup',
					'LocalBusiness' => 'Manual per-page setup',
					'Service'       => 'Manual per-page setup',
					'Article'       => 'Manual per-page setup',
					'Organization'  => 'Manual per-page setup',
				),
			),
			'aeo_gaps'      => array(
				'All schema types require manual configuration per page — no automatic detection',
				'No page intent classification or schema type suggestion',
				'No FAQ, Service, or LocalBusiness auto-generation',
			),
		),

	);

	/**
	 * Detect all currently active SEO plugins.
	 *
	 * @return array List of active plugin definition arrays.
	 */
	public static function detect_active_plugins() {
		$active = array();

		foreach ( self::$plugins as $slug => $plugin ) {
			if ( defined( $plugin['constant'] ) ) {
				$version = constant( $plugin['constant'] );
				$active[ $slug ] = array_merge( $plugin, array(
					'slug'    => $slug,
					'version' => $version,
					'active'  => true,
				) );
			}
		}

		return $active;
	}

	/**
	 * Check for conflicts — multiple SEO plugins active simultaneously.
	 *
	 * @return array|null Conflict data, or null if no conflict.
	 */
	public static function detect_conflicts() {
		$active = self::detect_active_plugins();

		if ( count( $active ) < 2 ) {
			return null;
		}

		$names           = array_column( $active, 'name' );
		$all_always      = array();
		$duplicate_types = array();

		// Find schema types that multiple plugins output simultaneously.
		foreach ( $active as $plugin ) {
			foreach ( $plugin['schema_output']['always'] as $type ) {
				if ( isset( $all_always[ $type ] ) ) {
					$duplicate_types[] = $type;
				}
				$all_always[ $type ] = $plugin['name'];
			}
		}

		$duplicate_types = array_unique( $duplicate_types );

		return array(
			'plugins'         => $names,
			'count'           => count( $active ),
			'duplicate_types' => $duplicate_types,
			'message'         => sprintf(
				'%s are both active and outputting schema. This creates duplicate %s on every page, which can confuse AI systems and search engines.',
				implode( ' and ', $names ),
				implode( ', ', $duplicate_types )
			),
		);
	}

	/**
	 * Get the primary detected SEO plugin (first one found).
	 *
	 * @return array|null Plugin definition, or null if none detected.
	 */
	public static function get_primary_plugin() {
		$active = self::detect_active_plugins();
		return ! empty( $active ) ? reset( $active ) : null;
	}

	/**
	 * Get the name of the detected SEO plugin(s).
	 *
	 * @return string Plugin name(s) or 'None Detected'.
	 */
	public static function get_plugin_name() {
		$active = self::detect_active_plugins();

		if ( empty( $active ) ) {
			return 'None Detected';
		}

		return implode( ' + ', array_column( $active, 'name' ) );
	}

	/**
	 * Get AEO gaps for the active SEO plugin(s).
	 *
	 * @return array List of gap descriptions.
	 */
	public static function get_aeo_gaps() {
		$active = self::detect_active_plugins();
		$gaps   = array();

		foreach ( $active as $plugin ) {
			foreach ( $plugin['aeo_gaps'] as $gap ) {
				$gaps[] = '[' . $plugin['name'] . '] ' . $gap;
			}
		}

		return $gaps;
	}

	/**
	 * Get schema types the active plugin(s) always output.
	 * Used to avoid flagging these as missing when they're covered.
	 *
	 * @return array
	 */
	public static function get_covered_schema_types() {
		$active  = self::detect_active_plugins();
		$covered = array();

		foreach ( $active as $plugin ) {
			foreach ( $plugin['schema_output']['always'] as $type ) {
				$covered[] = $type;
			}
		}

		return array_unique( $covered );
	}
}