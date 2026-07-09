<?php
/**
 * Admin Menu
 *
 * Registers the top-level sidebar menu and all subpages.
 * Modules that declare a native WP menu location get injected there
 * when their module is active.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Admin_Menu {

	/**
	 * @var TWTAEO_Module_Loader
	 */
	private $modules;

	public function __construct( TWTAEO_Module_Loader $modules ) {
		$this->modules = $modules;
	}

	public function register_menus() {
		// Top-level menu.
		add_menu_page(
			__( 'TWT AEO', 'twt-aeo-ultimate' ),
			__( 'TWT AEO', 'twt-aeo-ultimate' ),
			'manage_options',
			'twt-aeo',
			array( 'TWTAEO_Page_Dashboard', 'render' ),
			$this->get_menu_icon(),
			30
		);

		// Dashboard (mirrors top-level).
		add_submenu_page(
			'twt-aeo',
			__( 'Dashboard', 'twt-aeo-ultimate' ),
			__( 'Dashboard', 'twt-aeo-ultimate' ),
			'manage_options',
			'twt-aeo',
			array( 'TWTAEO_Page_Dashboard', 'render' )
		);

		// Modules.
		add_submenu_page(
			'twt-aeo',
			__( 'Modules', 'twt-aeo-ultimate' ),
			__( 'Modules', 'twt-aeo-ultimate' ),
			'manage_options',
			'twt-aeo-modules',
			array( 'TWTAEO_Page_Modules', 'render' )
		);

		// Settings.
		add_submenu_page(
			'twt-aeo',
			__( 'Settings', 'twt-aeo-ultimate' ),
			__( 'Settings', 'twt-aeo-ultimate' ),
			'manage_options',
			'twt-aeo-settings',
			array( 'TWTAEO_Page_Settings', 'render' )
		);

		// Diagnostics — always visible (System Info + error log for support).
		add_submenu_page(
			'twt-aeo',
			__( 'Diagnostics', 'twt-aeo-ultimate' ),
			__( 'Diagnostics', 'twt-aeo-ultimate' ),
			'manage_options',
			'twt-aeo-diagnostics',
			array( 'TWTAEO_Page_Diagnostics', 'render' )
		);

		// AEO Command Center — always visible.
		add_submenu_page(
			'twt-aeo',
			__( 'Command Center', 'twt-aeo-ultimate' ),
			__( 'Command Center', 'twt-aeo-ultimate' ),
			'manage_options',
			'twt-aeo-command-center',
			array( 'TWTAEO_Page_Command_Center', 'render' )
		);

		// Social Graph (OG + Twitter/X) — only when module is active.
		if ( $this->modules->is_active( 'social-graph' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'Social Graph', 'twt-aeo-ultimate' ),
				__( 'Social Graph', 'twt-aeo-ultimate' ),
				'manage_options',
				'twt-aeo-social-graph',
				array( 'TWTAEO_Page_Social_Graph', 'render' )
			);
		}

		// Schema Detector (FAQ + Service + Contact) — show if any of the three modules is active.
		if ( $this->modules->is_active( 'faq-detector' ) || $this->modules->is_active( 'service-detector' ) || $this->modules->is_active( 'contact-detector' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'Schema Detector', 'twt-aeo-ultimate' ),
				__( 'Schema Detector', 'twt-aeo-ultimate' ),
				'manage_options',
				'twt-aeo-schema-detector',
				array( 'TWTAEO_Page_Schema_Detector', 'render' )
			);
		}

		// Image SEO — always visible (alt text for posts/pages; products handled on the WooCommerce page).
		add_submenu_page(
			'twt-aeo',
			__( 'Image SEO', 'twt-aeo-ultimate' ),
			__( 'Image SEO', 'twt-aeo-ultimate' ),
			'manage_options',
			'twt-aeo-image-seo',
			array( 'TWTAEO_Page_Image_SEO', 'render' )
		);

		// E-E-A-T Scorecard — always visible (Author Info + Company Info are tabs within this page).
		add_submenu_page(
			'twt-aeo',
			__( 'E-E-A-T', 'twt-aeo-ultimate' ),
			__( 'E-E-A-T', 'twt-aeo-ultimate' ),
			'manage_options',
			'twt-aeo-eeat',
			array( 'TWTAEO_Page_EEAT', 'render' )
		);

		// WooCommerce — show if any of the three WooCommerce modules is active.
		if ( ( $this->modules->is_active( 'woocommerce-detector' ) || $this->modules->is_active( 'woocommerce-gmc' ) || $this->modules->is_active( 'woocommerce-bmc' ) ) && class_exists( 'WooCommerce' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'WooCommerce', 'twt-aeo-ultimate' ),
				__( 'WooCommerce', 'twt-aeo-ultimate' ),
				'manage_options',
				'twt-aeo-woocommerce',
				array( 'TWTAEO_Page_WooCommerce_Detector', 'render' )
			);
		}

		// Local Pack — only when module is active.
		if ( $this->modules->is_active( 'local-pack' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'Local Pack', 'twt-aeo-ultimate' ),
				__( 'Local Pack', 'twt-aeo-ultimate' ),
				'manage_options',
				'twt-aeo-local-pack',
				array( 'TWTAEO_Page_Local_Pack', 'render' )
			);
		}

		// Reviews — only when module is active.
		if ( $this->modules->is_active( 'reviews' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'Reviews', 'twt-aeo-ultimate' ),
				__( 'Reviews', 'twt-aeo-ultimate' ),
				'manage_options',
				'twt-aeo-reviews',
				array( 'TWTAEO_Page_Reviews', 'render' )
			);
		}

		// Google News — only when module is active.
		if ( $this->modules->is_active( 'news' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'Google News', 'twt-aeo-ultimate' ),
				__( 'Google News', 'twt-aeo-ultimate' ),
				'manage_options',
				'twt-aeo-news',
				array( 'TWTAEO_Page_News', 'render' )
			);
		}

		// Sitemap Generator — only when module is active.
		if ( $this->modules->is_active( 'sitemap' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'Sitemap', 'twt-aeo-ultimate' ),
				__( 'Sitemap', 'twt-aeo-ultimate' ),
				'manage_options',
				'twt-aeo-sitemap',
				array( 'TWTAEO_Page_Sitemap', 'render' )
			);
		}

		// PR Bridge — only when module is active.
		if ( $this->modules->is_active( 'pr-bridge' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'PR Bridge AI', 'twt-aeo-ultimate' ),
				__( 'PR Bridge AI', 'twt-aeo-ultimate' ),
				'manage_options',
				'twt-aeo-pr-bridge',
				array( 'TWTAEO_Page_PR_Bridge', 'render' )
			);
		}

		// Not Indexed — always visible.
		add_submenu_page(
			'twt-aeo',
			__( 'Not Indexed', 'twt-aeo-ultimate' ),
			__( 'Not Indexed', 'twt-aeo-ultimate' ),
			'manage_options',
			'twt-aeo-not-indexed',
			array( 'TWTAEO_Page_Not_Indexed', 'render' )
		);

		// AI Ready — always visible.
		add_submenu_page(
			'twt-aeo',
			__( 'AI Ready', 'twt-aeo-ultimate' ),
			__( 'AI Ready', 'twt-aeo-ultimate' ),
			'manage_options',
			'twt-aeo-ai-ready',
			array( 'TWTAEO_Page_AI_Ready', 'render' )
		);

		// Setup Wizard — hidden from nav (null parent), accessible via direct URL.
		add_submenu_page(
			null,
			__( 'Setup Wizard', 'twt-aeo-ultimate' ),
			__( 'Setup Wizard', 'twt-aeo-ultimate' ),
			'manage_options',
			'twt-aeo-setup-wizard',
			array( 'TWTAEO_Page_Setup_Wizard', 'render' )
		);

		// Inject contextual module pages into native WP sections
		// when those modules are active.
		$this->register_contextual_pages();
	}

	/**
	 * Register module pages that live inside native WP admin sections
	 * (e.g. Appearance > Author Schema).
	 */
	private function register_contextual_pages() {
		$all_modules = $this->modules->get_all();

		foreach ( $all_modules as $slug => $module ) {
			if ( empty( $module['menu'] ) ) {
				continue;
			}

			if ( ! $this->modules->is_active( $slug ) ) {
				continue;
			}

			$menu    = $module['menu'];
			$parent  = $menu['parent'] ?? '';
			$title   = $menu['title'] ?? $module['title'];

			// Map friendly parent names to WP slugs.
			$parent_map = array(
				'appearance' => 'themes.php',
				'settings'   => 'options-general.php',
				'tools'      => 'tools.php',
				'posts'      => 'edit.php',
				'pages'      => 'edit.php?post_type=page',
			);

			$parent_slug = $parent_map[ $parent ] ?? $parent;

			if ( ! $parent_slug ) {
				continue;
			}

			$page_slug = 'twt-aeo-module-' . $slug;

			add_submenu_page(
				$parent_slug,
				esc_html( $title ),
				esc_html( $title ),
				'manage_options',
				$page_slug,
				function() use ( $slug, $title ) {
					echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
					echo '<p>' . esc_html__( 'Module settings coming soon.', 'twt-aeo-ultimate' ) . '</p>';
					echo '</div>';
				}
			);
		}
	}

	/**
	 * SVG icon for the sidebar menu as a base64 data URI.
	 *
	 * @return string
	 */
	private function get_menu_icon() {
		// Simple AEO-style icon — a signal/wave mark.
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none">
			<circle cx="10" cy="10" r="2" fill="black"/>
			<path d="M10 4a6 6 0 0 1 6 6" stroke="black" stroke-width="1.5" stroke-linecap="round" fill="none"/>
			<path d="M10 1a9 9 0 0 1 9 9" stroke="black" stroke-width="1.5" stroke-linecap="round" fill="none"/>
			<path d="M10 4a6 6 0 0 0-6 6" stroke="black" stroke-width="1.5" stroke-linecap="round" fill="none"/>
			<path d="M10 1a9 9 0 0 0-9 9" stroke="black" stroke-width="1.5" stroke-linecap="round" fill="none"/>
		</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard data-URI encoding for an inline admin-menu SVG icon, not obfuscation.
	}
}