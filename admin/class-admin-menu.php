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

		// Smart Collections and Promotions are reached through the WooCommerce
		// screen's tab bar; their real submenu entries are hidden with CSS and
		// the highlight moved onto the WooCommerce item. See the long note in
		// register_menus() for why every other approach fails on WordPress 7.
		add_filter( 'submenu_file', array( $this, 'highlight_woocommerce_for_hidden_pages' ) );
		add_action( 'admin_head', array( $this, 'hide_commerce_tab_menu_items' ) );
	}

	/**
	 * Hide the Smart Collections / Promotions sidebar entries. They stay real
	 * registered submenu pages (access + parent detection need that on WP 7);
	 * only their menu items are suppressed.
	 */
	public function hide_commerce_tab_menu_items() {
		echo '<style>
			#adminmenu li:has( > a[href$="page=twt-aeo-collections"] ),
			#adminmenu li:has( > a[href$="page=twt-aeo-promotions"] ),
			#adminmenu li:has( > a[href$="page=twt-aeo-setup-wizard"] ) { display: none; }
		</style>';
	}

	/**
	 * Keep the WooCommerce submenu item highlighted on its hidden tab pages.
	 *
	 * @param string|null $submenu_file
	 * @return string|null
	 */
	public function highlight_woocommerce_for_hidden_pages( $submenu_file ) {
		global $plugin_page;
		if ( in_array( $plugin_page, array( 'twt-aeo-collections', 'twt-aeo-promotions' ), true ) ) {
			return 'twt-aeo-woocommerce';
		}
		return $submenu_file;
	}

	public function register_menus() {
		// Operational pages use the plugin capability so allowed roles can reach
		// them; credential/config pages below stay on 'manage_options' (admins only).
		$cap = class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';

		// Top-level menu.
		add_menu_page(
			__( 'TWT AEO', 'twt-aeo-ultimate' ),
			__( 'TWT AEO', 'twt-aeo-ultimate' ),
			$cap,
			'twt-aeo',
			array( 'TWTAEO_Page_Dashboard_V2', 'render' ),
			$this->get_menu_icon(),
			30
		);

		// Dashboard (mirrors top-level).
		add_submenu_page(
			'twt-aeo',
			__( 'Dashboard', 'twt-aeo-ultimate' ),
			__( 'Dashboard', 'twt-aeo-ultimate' ),
			$cap,
			'twt-aeo',
			array( 'TWTAEO_Page_Dashboard_V2', 'render' )
		);

		// AEO Score — the previous dashboard (AEO Score + trend, page
		// AEO status table, SEO conflict detection, AI meta descriptions,
		// Auto-Fill). Kept reachable while the new dashboard is evaluated.
		add_submenu_page(
			'twt-aeo',
			__( 'AEO Score', 'twt-aeo-ultimate' ),
			__( 'AEO Score', 'twt-aeo-ultimate' ),
			$cap,
			'twt-aeo-classic',
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

		// Access & Roles — administrator-only.
		add_submenu_page(
			'twt-aeo',
			__( 'Access &amp; Roles', 'twt-aeo-ultimate' ),
			__( 'Access &amp; Roles', 'twt-aeo-ultimate' ),
			'manage_options',
			'twt-aeo-access',
			array( 'TWTAEO_Roles', 'render_page' )
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
			$cap,
			'twt-aeo-command-center',
			array( 'TWTAEO_Page_Command_Center', 'render' )
		);

		// AI Visibility — the citation-engine board, when its module is active.
		if ( $this->modules->is_active( TWTAEO_Visibility_Types::MODULE_SLUG ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'AI Visibility', 'twt-aeo-ultimate' ),
				__( 'AI Visibility', 'twt-aeo-ultimate' ),
				$cap,
				TWTAEO_Visibility_Types::PAGE_SLUG,
				array( 'TWTAEO_Page_AI_Visibility', 'render' )
			);
		}

		// Social Graph (OG + Twitter/X) — only when module is active.
		if ( $this->modules->is_active( 'social-graph' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'Social Graph', 'twt-aeo-ultimate' ),
				__( 'Social Graph', 'twt-aeo-ultimate' ),
				$cap,
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
				$cap,
				'twt-aeo-schema-detector',
				array( 'TWTAEO_Page_Schema_Detector', 'render' )
			);
		}

		// Image SEO — always visible (alt text for posts/pages; products handled on the WooCommerce page).
		add_submenu_page(
			'twt-aeo',
			__( 'Image SEO', 'twt-aeo-ultimate' ),
			__( 'Image SEO', 'twt-aeo-ultimate' ),
			$cap,
			'twt-aeo-image-seo',
			array( 'TWTAEO_Page_Image_SEO', 'render' )
		);

		// E-E-A-T Scorecard — always visible (Author Info + Company Info are tabs within this page).
		add_submenu_page(
			'twt-aeo',
			__( 'E-E-A-T', 'twt-aeo-ultimate' ),
			__( 'E-E-A-T', 'twt-aeo-ultimate' ),
			$cap,
			'twt-aeo-eeat',
			array( 'TWTAEO_Page_EEAT', 'render' )
		);

		// WooCommerce — show if any of the three WooCommerce modules is active.
		if ( ( $this->modules->is_active( 'woocommerce-detector' ) || $this->modules->is_active( 'woocommerce-gmc' ) || $this->modules->is_active( 'woocommerce-bmc' ) ) && class_exists( 'WooCommerce' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'WooCommerce', 'twt-aeo-ultimate' ),
				__( 'WooCommerce', 'twt-aeo-ultimate' ),
				$cap,
				'twt-aeo-woocommerce',
				array( 'TWTAEO_Page_WooCommerce_Detector', 'render' )
			);
		}

		// Smart Collections and Promotions live as tabs on the WooCommerce screen,
		// not as sidebar items. They are registered as REAL submenu entries and
		// hidden with CSS (see hide_commerce_tab_menu_items), because on
		// WordPress 7 every "hidden page" trick fails a different way:
		//   - remove_submenu_page(): user_can_access_admin_page() consults the
		//     submenu registry → "Sorry, you are not allowed to access this page".
		//   - null/options.php parent: get_admin_page_parent() runs AFTER the
		//     parent_file filter in menu-header.php and unconditionally
		//     recomputes, clobbering the filtered value → sidebar loses its
		//     place (the old early-return protecting filtered values is gone).
		// A real entry gives correct access and native parent detection; the
		// submenu_file filter (which WP 7 does NOT clobber) moves the highlight
		// onto the WooCommerce item.
		if ( $this->modules->is_active( 'smart-collections' ) && class_exists( 'WooCommerce' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'Smart Collections', 'twt-aeo-ultimate' ),
				__( 'Smart Collections', 'twt-aeo-ultimate' ),
				$cap,
				'twt-aeo-collections',
				array( 'TWTAEO_Page_Smart_Collections', 'render' )
			);
		}

		if ( $this->modules->is_active( 'promotions' ) && class_exists( 'WooCommerce' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'Promotions', 'twt-aeo-ultimate' ),
				__( 'Promotions', 'twt-aeo-ultimate' ),
				$cap,
				TWTAEO_Page_Promotions::PAGE_SLUG,
				array( 'TWTAEO_Page_Promotions', 'render' )
			);
		}

		// Local Pack — only when module is active.
		if ( $this->modules->is_active( 'local-pack' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'Local Pack', 'twt-aeo-ultimate' ),
				__( 'Local Pack', 'twt-aeo-ultimate' ),
				$cap,
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
				$cap,
				'twt-aeo-reviews',
				array( 'TWTAEO_Page_Reviews', 'render' )
			);
		}

		// Knowledge Graph entities — always available (the unified graph is always on).
		add_submenu_page(
			'twt-aeo',
			__( 'Knowledge Graph', 'twt-aeo-ultimate' ),
			__( 'Knowledge Graph', 'twt-aeo-ultimate' ),
			$cap,
			'twt-aeo-knowledge-graph',
			array( 'TWTAEO_Page_Knowledge_Graph', 'render' )
		);

			if ( $this->modules->is_active( 'news' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'Google News', 'twt-aeo-ultimate' ),
				__( 'Google News', 'twt-aeo-ultimate' ),
				$cap,
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
				$cap,
				'twt-aeo-sitemap',
				array( 'TWTAEO_Page_Sitemap', 'render' )
			);
		}

		// Smart 404 Rescue — only when module is active.
		if ( $this->modules->is_active( 'smart-404' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'Smart 404', 'twt-aeo-ultimate' ),
				__( 'Smart 404', 'twt-aeo-ultimate' ),
				$cap,
				'twt-aeo-smart-404',
				array( 'TWTAEO_Page_Smart_404', 'render' )
			);
		}

		// PR Bridge — only when module is active.
		if ( $this->modules->is_active( 'pr-bridge' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'PR Bridge AI', 'twt-aeo-ultimate' ),
				__( 'PR Bridge AI', 'twt-aeo-ultimate' ),
				$cap,
				'twt-aeo-pr-bridge',
				array( 'TWTAEO_Page_PR_Bridge', 'render' )
			);
		}

		// Not Indexed — always visible.
		add_submenu_page(
			'twt-aeo',
			__( 'Not Indexed', 'twt-aeo-ultimate' ),
			__( 'Not Indexed', 'twt-aeo-ultimate' ),
			$cap,
			'twt-aeo-not-indexed',
			array( 'TWTAEO_Page_Not_Indexed', 'render' )
		);

		// AI Ready — always visible.
		add_submenu_page(
			'twt-aeo',
			__( 'AI Ready', 'twt-aeo-ultimate' ),
			__( 'AI Ready', 'twt-aeo-ultimate' ),
			$cap,
			'twt-aeo-ai-ready',
			array( 'TWTAEO_Page_AI_Ready', 'render' )
		);

		// Bot View — only when module is active.
		if ( $this->modules->is_active( 'bot-view' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'Bot View', 'twt-aeo-ultimate' ),
				__( 'Bot View', 'twt-aeo-ultimate' ),
				$cap,
				'twt-aeo-bot-view',
				array( 'TWTAEO_Page_Bot_View', 'render' )
			);
		}

		// Chunk View — only when module is active.
		if ( $this->modules->is_active( 'chunk-view' ) ) {
			// Chunk View, Documents, Search Placement and AI Citations are tabs
			// of one screen; the old Chunk View / Documents slugs redirect there.
			add_submenu_page(
				'twt-aeo',
				__( 'RAG Engine', 'twt-aeo-ultimate' ),
				__( 'RAG Engine', 'twt-aeo-ultimate' ),
				$cap,
				TWTAEO_Page_RAG_Engine::SLUG,
				array( 'TWTAEO_Page_RAG_Engine', 'render' )
			);
		}

		// Funnel Audit — only when module is active.
		if ( $this->modules->is_active( 'funnel-audit' ) ) {
			add_submenu_page(
				'twt-aeo',
				__( 'Funnel Audit', 'twt-aeo-ultimate' ),
				__( 'Funnel Audit', 'twt-aeo-ultimate' ),
				$cap,
				'twt-aeo-funnel-audit',
				array( 'TWTAEO_Page_Funnel_Audit', 'render' )
			);
		}

		// Setup Wizard — hidden from nav via CSS (see hide_commerce_tab_menu_items),
		// accessible via direct URL. ⚠️ Not a null parent: on WordPress 7 an
		// orphan page resolves no admin title, and admin-header.php's
		// strip_tags( null ) then throws a deprecation on every load.
		add_submenu_page(
			'twt-aeo',
			__( 'Setup Wizard', 'twt-aeo-ultimate' ),
			__( 'Setup Wizard', 'twt-aeo-ultimate' ),
			$cap,
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
		$cap = class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';

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
				$cap,
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