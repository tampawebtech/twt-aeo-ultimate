<?php
/**
 * Core plugin class.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Plugin {

	/** @var TWTAEO_Admin_Menu */
	private $admin_menu;

	/** @var TWTAEO_Module_Loader */
	public $modules;

	public function __construct() {
		$this->modules    = new TWTAEO_Module_Loader();
		$this->admin_menu = new TWTAEO_Admin_Menu( $this->modules );
	}

	public function run() {
		// Role access control — register the capability filter before menus build.
		TWTAEO_Roles::register_hooks();

		add_action( 'admin_menu', array( $this->admin_menu, 'register_menus' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_wizard' ) );
		add_action( 'admin_init', array( 'TWTAEO_Page_Command_Center', 'maybe_handle_post' ) );
		// The output switches on the Schema Conflict Detector page. Nonce and
		// capability are both checked inside the handler.
		add_action( 'admin_post_twtaeo_output_control', array( 'TWTAEO_Page_Schema_Conflicts', 'handle_output_control_post' ) );
		add_filter( 'admin_body_class', array( $this, 'wizard_body_class' ) );
		add_action( 'wp_ajax_twtaeo_wizard_autopilot', array( 'TWTAEO_Page_Setup_Wizard', 'ajax_autopilot' ) );
		add_action( 'wp_ajax_twtaeo_autopilot_status', array( 'TWTAEO_Page_Setup_Wizard', 'ajax_autopilot_status' ) );
		// The cron hook must always be bound, or a queued autopilot event fires
		// into nothing after a reload.
		TWTAEO_Autopilot::register_hooks();
		add_action( 'admin_enqueue_scripts', array( 'TWTAEO_Admin_Assets', 'enqueue' ) );
		add_action( 'init', array( 'TWTAEO_Scan_Store', 'register_hooks' ) );
		add_action( 'init', array( 'TWTAEO_AI_Crawler_Logger', 'register_hooks' ) );
		add_action( 'init', array( 'TWTAEO_Review_Notice', 'register_hooks' ) );

		// Invalidate the cached E-E-A-T scan when the active plugin set changes.
		TWTAEO_EEAT_Detector::register_hooks();

		// Register Service Schema Writer — outputs JSON-LD on frontend.
		add_action( 'init', array( 'TWTAEO_Service_Schema_Writer', 'register_hooks' ) );

		// Register Contact Schema Writer — outputs Organization/ContactPage JSON-LD on frontend.
		add_action( 'init', array( 'TWTAEO_Contact_Schema_Writer', 'register_hooks' ) );

		// Article schema on blog posts (NewsArticle handles posts marked as news).
		add_action( 'init', array( 'TWTAEO_Article_Schema_Writer', 'register_hooks' ) );

		// Content provenance (digitalSourceType) — editor declaration of how a
		// post was produced, published on its Article/NewsArticle node.
		add_action( 'init', array( 'TWTAEO_Content_Provenance', 'register_hooks' ) );

		// AI meta descriptions — editor metabox, auto-on-save, front-end output.
		add_action( 'init', array( 'TWTAEO_AI_Description', 'register_hooks' ) );

		// AI Ready — Markdown negotiation, llms.txt, Content-Signal headers, REST API.
		add_action( 'init', array( 'TWTAEO_AI_Ready', 'on_init' ) );

		// Author Entity module: profile form + Person schema output + hover cards.
		if ( $this->modules->is_active( 'author-entity' ) ) {
			add_action( 'init', array( 'TWTAEO_Author_Meta', 'register_hooks' ) );
			add_action( 'init', array( 'TWTAEO_Author_Schema_Writer', 'register_hooks' ) );
		}
		if ( $this->modules->is_active( 'author-hover-cards' ) ) {
			add_action( 'init', array( 'TWTAEO_Author_Hover', 'register_hooks' ) );
		}

		// Register detector hooks — only when their modules are active.
		if ( $this->modules->is_active( 'pr-bridge' ) ) {
			add_action( 'init', array( 'TWTAEO_PR_Detector', 'register_hooks' ) );
			add_action( 'init', array( 'TWTAEO_PR_Metabox', 'register_hooks' ) );
		}

		if ( $this->modules->is_active( 'local-pack' ) ) {
			add_action( 'init', array( 'TWTAEO_Local_Pack', 'register_hooks' ) );
		}

		if ( $this->modules->is_active( 'sitemap' ) ) {
			add_action( 'init', array( 'TWTAEO_Sitemap_Generator', 'register_hooks' ) );
		}

		if ( $this->modules->is_active( 'faq-detector' ) ) {
			add_action( 'init', array( 'TWTAEO_FAQ_Detector', 'register_hooks' ) );
		}
		if ( $this->modules->is_active( 'service-detector' ) ) {
			add_action( 'init', array( 'TWTAEO_Service_Detector', 'register_hooks' ) );
		}
		if ( $this->modules->is_active( 'contact-detector' ) ) {
			add_action( 'init', array( 'TWTAEO_Contact_Detector', 'register_hooks' ) );
		}
		// ⚠️ AEO Ultimate for WooCommerce handshake — only that plugin defines
		// aeowc_claims_surface(), during its plugins_loaded bootstrap. When it is
		// active, the commerce writers ported from it stay unregistered here:
		// running both would publish every node twice. Detection/scanning still
		// runs; only front-end emission stands down.
		$aeowc_active = function_exists( 'aeowc_claims_surface' );

		if ( $this->modules->is_active( 'woocommerce-detector' ) ) {
			add_action( 'init', array( 'TWTAEO_WooCommerce_Detector', 'register_hooks' ) );
			// Extension-aware price semantics (Bookings/Subscriptions/Bundles read
			// "From: $X", not a definite price). Self-guards on WooCommerce.
			add_action( 'init', array( 'TWTAEO_WC_Extensions', 'register_hooks' ) );
			if ( ! $aeowc_active ) {
				// Assembles every FAQ source on a product page into one FAQPage
				// node. Registered after the sources it collects from.
				TWTAEO_Product_FAQ_Generator::register_hooks();
				TWTAEO_Product_FAQ::register_hooks();
			}
		}

		// Content Gap Engine documents ride on the Chunk View module: same
		// chunker, same host governor, same default-off switch.
		if ( $this->modules->is_active( 'chunk-view' ) ) {
			TWTAEO_Doc_Library::register_hooks();
			TWTAEO_Page_Documents::register_hooks();
			TWTAEO_Page_RAG_Engine::register_hooks();
		}

		if ( $this->modules->is_active( 'smart-collections' ) && ! $aeowc_active ) {
			add_action( 'init', array( 'TWTAEO_Collection_Schema_Writer', 'register_hooks' ) );
		}

		if ( $this->modules->is_active( 'promotions' ) && ! $aeowc_active ) {
			add_action( 'init', array( 'TWTAEO_Offer_Pricing', 'register_hooks' ) );
			add_action( 'init', array( 'TWTAEO_Dynamic_Pricing', 'register_hooks' ) );
			// Weekly re-derivation of dynamic-pricing detection. A discount rule is
			// not a product, so no product hook fires when one ends — without this,
			// a cached price outlives the rule that produced it indefinitely.
			TWTAEO_Pricing_Refresh::init();
		}

		// Drafts a Smart Collection for a query. Returns a proposal and writes
		// nothing — creation is a separate, nonced POST the merchant makes after
		// reading it.
		add_action( 'wp_ajax_twtaeo_collection_draft', array( 'TWTAEO_Page_Smart_Collections', 'ajax_draft' ) );

		// Bing promotions feed download. On admin_post so the file headers are sent
		// before any admin HTML; the handler checks the nonce and the capability.
		add_action( 'admin_post_twtaeo_promotions_bing_feed', array( 'TWTAEO_Page_Promotions', 'download_bing_feed' ) );

		add_action( 'wp_ajax_twtaeo_generate_product_faqs_php', array( $this, 'ajax_generate_product_faqs_php' ) );
		add_action( 'wp_ajax_twtaeo_generate_product_faqs_ai',  array( $this, 'ajax_generate_product_faqs_ai' ) );

		if ( $this->modules->is_active( 'social-graph' ) ) {
			add_action( 'init', array( 'TWTAEO_OG_Writer', 'register_hooks' ) );
			add_action( 'init', array( 'TWTAEO_Twitter_Writer', 'register_hooks' ) );
		}

		if ( $this->modules->is_active( 'index-now' ) ) {
			add_action( 'init', array( 'TWTAEO_Index_Now', 'register_hooks' ) );
		}

		if ( $this->modules->is_active( 'last-updated-badge' ) ) {
			add_action( 'init', array( 'TWTAEO_Last_Updated_Badge', 'register_hooks' ) );
		}

		if ( $this->modules->is_active( 'news' ) ) {
			add_action( 'init', array( 'TWTAEO_News_Sitemap',       'register_hooks' ) );
			add_action( 'init', array( 'TWTAEO_News_Schema_Writer', 'register_hooks' ) );
			add_action( 'init', array( 'TWTAEO_News_Meta',          'register_hooks' ) );
		}

		if ( $this->modules->is_active( 'reviews' ) ) {
			add_action( 'init', array( 'TWTAEO_Reviews_CPT',           'register_hooks' ) );
			add_action( 'init', array( 'TWTAEO_Reviews_Schema_Writer', 'register_hooks' ) );
			add_action( 'init', array( 'TWTAEO_Reviews_Shortcode',     'register_hooks' ) );
		}

		if ( $this->modules->is_active( 'ai-rate-limiter' ) ) {
			add_action( 'init', array( 'TWTAEO_Rate_Limiter', 'register_hooks' ) );
		}

		if ( $this->modules->is_active( TWTAEO_Visibility_Types::MODULE_SLUG ) ) {
			add_action( 'init', array( 'TWTAEO_Visibility', 'register_hooks' ) );
		}

		if ( $this->modules->is_active( 'smart-404' ) ) {
			add_action( 'init', array( 'TWTAEO_Smart_404', 'register_hooks' ) );
		}

		// The unified Knowledge Graph is always on. It decides per-page whether to
		// fold (when 2+ schema blocks would otherwise appear) or stay out of the way.
		add_action( 'init', array( 'TWTAEO_Knowledge_Graph', 'register_hooks' ) );

		add_action( 'wp_ajax_twtaeo_save_social_graph',    array( $this, 'ajax_save_social_graph' ) );
		add_action( 'wp_ajax_twtaeo_save_twitter_site',    array( $this, 'ajax_save_twitter_site' ) );
		add_action( 'wp_ajax_twtaeo_save_linkedin_company', array( $this, 'ajax_save_linkedin_company' ) );

		add_action( 'wp_ajax_twtaeo_indexnow_verify_key',  array( $this, 'ajax_indexnow_verify_key' ) );
		add_action( 'wp_ajax_twtaeo_indexnow_submit',      array( $this, 'ajax_indexnow_submit' ) );
		add_action( 'wp_ajax_twtaeo_indexnow_submit_all',  array( $this, 'ajax_indexnow_submit_all' ) );

		add_action( 'wp_ajax_twtaeo_local_pack_nap_google',  array( $this, 'ajax_local_pack_nap_google' ) );
		add_action( 'wp_ajax_twtaeo_local_pack_nap_bing',    array( $this, 'ajax_local_pack_nap_bing' ) );
		add_action( 'wp_ajax_twtaeo_local_pack_autofill',    array( $this, 'ajax_local_pack_autofill' ) );
		add_action( 'wp_ajax_twtaeo_inject_robots',          array( $this, 'ajax_inject_robots' ) );
		add_action( 'wp_ajax_twtaeo_remove_robots',          array( $this, 'ajax_remove_robots' ) );
		add_action( 'wp_ajax_twtaeo_toggle_module',         array( $this, 'ajax_toggle_module' ) );
		add_action( 'wp_ajax_twtaeo_scan_page',             array( $this, 'ajax_scan_page' ) );
		add_action( 'wp_ajax_twtaeo_get_page_score',        array( $this, 'ajax_get_page_score' ) );
		add_action( 'wp_ajax_twtaeo_autofill_schemas',      array( $this, 'ajax_autofill_schemas' ) );
		add_action( 'wp_ajax_twtaeo_ai_schema_summary',     array( $this, 'ajax_ai_schema_summary' ) );
		add_action( 'wp_ajax_twtaeo_scan_all',              array( $this, 'ajax_scan_all' ) );
		add_action( 'wp_ajax_twtaeo_bg_scan_start',         array( $this, 'ajax_bg_scan_start' ) );
		add_action( 'wp_ajax_twtaeo_dashboard_generate_product', array( $this, 'ajax_dashboard_generate_product' ) );
		add_action( 'wp_ajax_twtaeo_bg_scan_status',        array( $this, 'ajax_bg_scan_status' ) );
		add_action( 'wp_ajax_twtaeo_data_handshake',        array( $this, 'ajax_data_handshake' ) );
		add_action( 'wp_ajax_twtaeo_get_service_prefill',   array( $this, 'ajax_get_service_prefill' ) );
		add_action( 'wp_ajax_twtaeo_save_service_schema',   array( $this, 'ajax_save_service_schema' ) );
		add_action( 'wp_ajax_twtaeo_delete_service_schema', array( $this, 'ajax_delete_service_schema' ) );
		add_action( 'wp_ajax_twtaeo_get_contact_prefill',   array( $this, 'ajax_get_contact_prefill' ) );
		add_action( 'wp_ajax_twtaeo_save_contact_schema',   array( $this, 'ajax_save_contact_schema' ) );
		add_action( 'wp_ajax_twtaeo_delete_contact_schema', array( $this, 'ajax_delete_contact_schema' ) );
		add_action( 'wp_ajax_twtaeo_cc_disconnect',         array( $this, 'ajax_cc_disconnect' ) );
		// Hub CPT, shortcodes, and metabox.
		add_action( 'init', array( 'TWTAEO_Hub_CPT', 'register_hooks' ) );
		add_action( 'load-post.php',     array( 'TWTAEO_Hub_Metabox', 'register_hooks' ) );
		add_action( 'load-post-new.php', array( 'TWTAEO_Hub_Metabox', 'register_hooks' ) );

		add_action( 'wp_ajax_twtaeo_pr_transform',          array( $this, 'ajax_pr_transform' ) );
		add_action( 'wp_ajax_twtaeo_pr_save',               array( $this, 'ajax_pr_save' ) );
		add_action( 'wp_ajax_twtaeo_pr_delete',             array( $this, 'ajax_pr_delete' ) );
		add_action( 'wp_ajax_twtaeo_pr_submit',             array( $this, 'ajax_pr_submit' ) );
		add_action( 'init', array( 'TWTAEO_Custom_Schema_Writer', 'register_hooks' ) );
		add_action( 'wp_ajax_twtaeo_get_custom_schema',    array( $this, 'ajax_get_custom_schema' ) );
		add_action( 'wp_ajax_twtaeo_save_custom_schema',   array( $this, 'ajax_save_custom_schema' ) );
		add_action( 'wp_ajax_twtaeo_delete_custom_schema', array( $this, 'ajax_delete_custom_schema' ) );
		add_action( 'wp_ajax_twtaeo_get_schema_prefill',   array( $this, 'ajax_get_schema_prefill' ) );
		add_action( 'wp_ajax_twtaeo_wc_get_product_data',  array( $this, 'ajax_wc_get_product_data' ) );
		add_action( 'wp_ajax_twtaeo_wc_generate_all',      array( $this, 'ajax_wc_generate_all' ) );
		add_action( 'wp_ajax_twtaeo_wc_save_schema',           array( $this, 'ajax_wc_save_schema' ) );
		add_action( 'wp_ajax_twtaeo_wc_search_products',       array( $this, 'ajax_wc_search_products' ) );
		add_action( 'wp_ajax_twtaeo_wc_save_shipping_settings', array( $this, 'ajax_wc_save_shipping_settings' ) );
		add_action( 'wp_ajax_twtaeo_wc_ai_extract',            array( $this, 'ajax_wc_ai_extract' ) );
		add_action( 'wp_ajax_twtaeo_wc_ai_vision',             array( $this, 'ajax_wc_ai_vision' ) );
		add_action( 'wp_ajax_twtaeo_wc_ai_describe',           array( $this, 'ajax_wc_ai_describe' ) );
		add_action( 'wp_ajax_twtaeo_wc_ai_sameas',             array( $this, 'ajax_wc_ai_sameas' ) );
		add_action( 'wp_ajax_twtaeo_wc_ai_google_category',    array( $this, 'ajax_wc_ai_google_category' ) );
		add_action( 'wp_ajax_twtaeo_wc_ai_standardize',        array( $this, 'ajax_wc_ai_standardize' ) );
		add_action( 'wp_ajax_twtaeo_wc_ai_competitive',        array( $this, 'ajax_wc_ai_competitive' ) );
		add_action( 'wp_ajax_twtaeo_wc_ai_alt',                array( $this, 'ajax_wc_ai_alt' ) );
		add_action( 'wp_ajax_twtaeo_img_get_post_images',      array( $this, 'ajax_img_get_post_images' ) );
		add_action( 'wp_ajax_twtaeo_img_generate_alt',         array( $this, 'ajax_img_generate_alt' ) );
		add_action( 'wp_ajax_twtaeo_img_save_alt',             array( $this, 'ajax_img_save_alt' ) );
		add_action( 'wp_ajax_twtaeo_img_set_featured',         array( $this, 'ajax_img_set_featured' ) );
		add_action( 'wp_ajax_twtaeo_diagnose_server',       array( $this, 'ajax_diagnose_server' ) );
		add_action( 'wp_ajax_twtaeo_apply_apache_fix',      array( $this, 'ajax_apply_apache_fix' ) );
		add_action( 'wp_ajax_twtaeo_gsc_inspect',           array( $this, 'ajax_gsc_inspect' ) );
		add_action( 'wp_ajax_twtaeo_psi_check',             array( $this, 'ajax_psi_check' ) );
		add_action( 'wp_ajax_twtaeo_leak_scan_start',       array( $this, 'ajax_leak_scan_start' ) );
		add_action( 'wp_ajax_twtaeo_leak_scan_next',        array( $this, 'ajax_leak_scan_next' ) );
		add_action( 'wp_ajax_twtaeo_code_health_scan',      array( $this, 'ajax_code_health_scan' ) );
		add_action( 'wp_ajax_twtaeo_index_scan_url',        array( $this, 'ajax_index_scan_url' ) );
		add_action( 'wp_ajax_twtaeo_index_clear',           array( $this, 'ajax_index_clear' ) );
		add_action( 'wp_ajax_twtaeo_index_send_to_pro',     array( $this, 'ajax_index_send_to_pro' ) );
		add_action( 'wp_ajax_twtaeo_run_crawler_tests',       array( $this, 'ajax_run_crawler_tests' ) );
		add_action( 'wp_ajax_twtaeo_schema_conflict_scan',    array( $this, 'ajax_schema_conflict_scan' ) );
		add_action( 'wp_ajax_twtaeo_schema_conflict_suppress', array( $this, 'ajax_schema_conflict_suppress' ) );
		add_action( 'wp_ajax_twtaeo_seo_schema_delete',        array( $this, 'ajax_seo_schema_delete' ) );
		add_action( 'wp_ajax_twtaeo_faq_generate_one',   array( $this, 'ajax_faq_generate_one' ) );
		add_action( 'wp_ajax_twtaeo_service_generate_one', array( $this, 'ajax_service_generate_one' ) );
		add_action( 'wp_ajax_twtaeo_faq_generate_batch', array( $this, 'ajax_faq_generate_batch' ) );

		add_action( 'wp_ajax_twtaeo_create_eeat_page',  array( $this, 'ajax_create_eeat_page' ) );
		add_action( 'wp_ajax_twtaeo_get_sameas',         array( $this, 'ajax_get_sameas' ) );
		add_action( 'wp_ajax_twtaeo_save_sameas',        array( $this, 'ajax_save_sameas' ) );
		add_action( 'wp_ajax_twtaeo_kg_search',          array( $this, 'ajax_kg_search' ) );
		add_action( 'wp_ajax_twtaeo_kg_resolve_sameas',  array( $this, 'ajax_kg_resolve_sameas' ) );
		add_action( 'wp_ajax_twtaeo_get_author_meta',    array( $this, 'ajax_get_author_meta' ) );
		add_action( 'wp_ajax_twtaeo_save_author_meta',   array( $this, 'ajax_save_author_meta' ) );
		add_action( 'wp_ajax_twtaeo_generate_authority', array( 'TWTAEO_Author_Meta', 'ajax_generate_snippet' ) );

		add_action( 'wp_ajax_twtaeo_ai_desc_generate',   array( $this, 'ajax_ai_desc_generate' ) );
		add_action( 'wp_ajax_twtaeo_ai_desc_bulk',       array( $this, 'ajax_ai_desc_bulk' ) );
		add_action( 'wp_ajax_twtaeo_ai_desc_start',      array( $this, 'ajax_ai_desc_start' ) );
		add_action( 'wp_ajax_twtaeo_ai_desc_stop',       array( $this, 'ajax_ai_desc_stop' ) );
		add_action( 'wp_ajax_twtaeo_ai_desc_status',     array( $this, 'ajax_ai_desc_status' ) );
		add_action( 'wp_ajax_twtaeo_ai_desc_settings',   array( $this, 'ajax_ai_desc_settings' ) );
		add_action( 'wp_ajax_twtaeo_ai_desc_social',     array( $this, 'ajax_ai_desc_generate_social' ) );
		add_action( 'wp_ajax_twtaeo_ai_og_image',        array( $this, 'ajax_ai_og_image' ) );
		add_action( 'wp_ajax_twtaeo_set_featured',       array( $this, 'ajax_set_featured_image' ) );
		add_action( 'wp_ajax_twtaeo_og_fill_from_meta',  array( $this, 'ajax_og_fill_from_meta' ) );
		add_action( 'wp_ajax_twtaeo_og_census_block',    array( $this, 'ajax_og_census_block' ) );

		add_action( 'wp_ajax_twtaeo_gmc_save_settings', array( $this, 'ajax_gmc_save_settings' ) );
		add_action( 'wp_ajax_twtaeo_gmc_sync',          array( $this, 'ajax_gmc_sync' ) );
		add_action( 'wp_ajax_twtaeo_gmc_disconnect',    array( $this, 'ajax_gmc_disconnect' ) );

		add_action( 'wp_ajax_twtaeo_bmc_save_settings', array( $this, 'ajax_bmc_save_settings' ) );
		add_action( 'wp_ajax_twtaeo_bmc_sync',          array( $this, 'ajax_bmc_sync' ) );
		add_action( 'wp_ajax_twtaeo_bmc_disconnect',    array( $this, 'ajax_bmc_disconnect' ) );

		add_action( 'wp_ajax_twtaeo_msync_status',      array( $this, 'ajax_msync_status' ) );

		// Schema conflict suppression filters — must register at init time
		// so they fire before wp_head on the frontend.
		add_action( 'init', array( 'TWTAEO_Schema_Conflict_Detector', 'register_hooks' ) );
	}

	public function get_modules() {
		return $this->modules;
	}

	public function maybe_redirect_to_wizard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}
		if ( ! get_transient( 'twtaeo_redirect_to_wizard' ) ) {
			return;
		}
		// Don't redirect if already on the wizard.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'twt-aeo-setup-wizard' === $page ) {
			return;
		}
		delete_transient( 'twtaeo_redirect_to_wizard' );
		wp_safe_redirect( admin_url( 'admin.php?page=twt-aeo-setup-wizard' ) );
		exit;
	}

	public function wizard_body_class( $classes ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'twt-aeo-setup-wizard' === $page ) {
			$classes .= ' twt-aeo-wizard-page';
		}
		return $classes;
	}

	public function ajax_inject_robots() {
		check_ajax_referer( TWTAEO_AI_Ready::NONCE_INJECT, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$result = TWTAEO_AI_Ready::inject_into_physical_robots();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( array( 'message' => 'Content-Signal directive written to robots.txt.' ) );
	}

	public function ajax_remove_robots() {
		check_ajax_referer( TWTAEO_AI_Ready::NONCE_INJECT, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$result = TWTAEO_AI_Ready::delete_physical_robots();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( array( 'message' => 'Physical robots.txt removed — WordPress now serves it dynamically.' ) );
	}

	public function ajax_toggle_module() {
		check_ajax_referer( 'twtaeo_modules_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$slug   = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
		$active = rest_sanitize_boolean( wp_unslash( $_POST['active'] ?? false ) );
		$this->modules->toggle( $slug, $active );

		// Tear down the Smart 404 scan cron when its module is switched off.
		if ( 'smart-404' === $slug && ! $active && class_exists( 'TWTAEO_Smart_404' ) ) {
			TWTAEO_Smart_404::clear_schedule();
		}

		wp_send_json_success( array( 'slug' => $slug, 'active' => $active ) );
	}

	/**
	 * AJAX: Run a Data Handshake on demand (bundle → transmit → purge-on-ack).
	 */
	public function ajax_data_handshake() {
		check_ajax_referer( 'twtaeo_handshake_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'twt-aeo-ultimate' ) ) );
		}

		if ( ! class_exists( 'TWTAEO_Data_Handshake' ) ) {
			wp_send_json_error( array( 'message' => __( 'Handshake unavailable.', 'twt-aeo-ultimate' ) ) );
		}

		$result = TWTAEO_Data_Handshake::run( true );

		if ( ( $result['status'] ?? '' ) !== 'ok' ) {
			$message = ( ( $result['status'] ?? '' ) === 'not_connected' )
				? __( 'Not connected to the Agency Hub. Add your Agency URL and API key in Settings first.', 'twt-aeo-ultimate' )
				: __( 'The Agency Hub did not confirm receipt. Nothing was purged — will retry on the next scheduled sync.', 'twt-aeo-ultimate' );
			wp_send_json_error( array( 'message' => $message, 'result' => $result ) );
		}

		$counts = $result['counts'] ?? array();
		wp_send_json_success( array(
			'message'        => __( 'Handshake complete.', 'twt-aeo-ultimate' ),
			'transmitted_at' => $result['transmitted_at'] ?? '',
			'counts'         => $counts,
		) );
	}

	public function ajax_scan_page() {
		check_ajax_referer( 'twtaeo_scan_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			wp_send_json_error( 'Invalid post ID' );
		}

		$result = TWTAEO_Scan_Store::scan_and_store( $post );

		wp_send_json_success( array(
			'post_id'    => $post_id,
			'intent'     => $result['intent']['label'] ?? '',
			'confidence' => $result['intent']['confidence'] ?? '',
			'missing'    => $result['evaluation']['missing'] ?? array(),
			'present'    => $result['evaluation']['present'] ?? array(),
			'scanned_at' => $result['scanned_at'] ?? '',
			'score'      => $result['aeo_score']['score'] ?? null,
		) );
	}

	/**
	 * AJAX: full AEO Score breakdown for one page — every line item with its
	 * points, the citation reason, and the fix link. The score is the sum of
	 * these lines; nothing hidden.
	 */
	public function ajax_get_page_score() {
		check_ajax_referer( TWTAEO_Custom_Schema_Writer::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}
		$breakdown = TWTAEO_Aeo_Score::get_breakdown( $post_id );
		if ( ! $breakdown ) {
			wp_send_json_error( __( 'No score yet — scan this page first.', 'twt-aeo-ultimate' ) );
		}
		wp_send_json_success( $breakdown );
	}

	/**
	 * AJAX: one batch of the Dashboard's bulk schema autofill. The client
	 * loops until done=true, accumulating per-type counts.
	 */
	public function ajax_autofill_schemas() {
		check_ajax_referer( TWTAEO_Custom_Schema_Writer::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$offset = absint( wp_unslash( $_POST['offset'] ?? 0 ) );
		wp_send_json_success( TWTAEO_Schema_Autofill::run_batch( $offset ) );
	}

	/**
	 * AJAX: AI-written summary for a schema modal description field. One API
	 * call per click, always user-initiated — never run in bulk from here.
	 */
	public function ajax_ai_schema_summary() {
		check_ajax_referer( TWTAEO_Custom_Schema_Writer::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}
		if ( ! class_exists( 'TWTAEO_AI_Description' ) || ! TWTAEO_AI_Description::is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'AI descriptions are not enabled — configure a provider under Settings first.', 'twt-aeo-ultimate' ) ) );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'twt-aeo-ultimate' ) ) );
		}
		$text = TWTAEO_AI_Description::generate_for_post( $post_id );
		if ( is_wp_error( $text ) ) {
			wp_send_json_error( array( 'message' => $text->get_error_message() ) );
		}
		wp_send_json_success( array( 'text' => $text ) );
	}

	/**
	 * AJAX: Scan a single page from the Scan All queue.
	 * Called repeatedly by the frontend to process one page at a time.
	 */
	public function ajax_scan_all() {
		check_ajax_referer( 'twtaeo_scan_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			wp_send_json_error( 'Invalid post ID' );
		}

		$result = TWTAEO_Scan_Store::scan_and_store( $post );

		wp_send_json_success( array(
			'post_id'    => $post_id,
			'intent'     => $result['intent']['label'] ?? '',
			'confidence' => $result['intent']['confidence'] ?? '',
			'missing'    => count( $result['evaluation']['missing'] ?? array() ),
			'present'    => count( $result['evaluation']['present'] ?? array() ),
		) );
	}

	/**
	 * AJAX: Queue the posts beyond the foreground cap for background scanning.
	 */
	public function ajax_bg_scan_start() {
		check_ajax_referer( 'twtaeo_scan_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		TWTAEO_Background_Scan::start();
		wp_send_json_success( TWTAEO_Background_Scan::state_payload() );
	}

	/**
	 * AJAX: Background scan progress, polled by the dashboard.
	 */
	public function ajax_bg_scan_status() {
		check_ajax_referer( 'twtaeo_scan_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		wp_send_json_success( TWTAEO_Background_Scan::state_payload() );
	}

	/**
	 * AJAX: Get pre-fill data for the service schema modal.
	 */
	public function ajax_get_service_prefill() {
		check_ajax_referer( 'twtaeo_service_schema_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID' );
		}
		wp_send_json_success( TWTAEO_Service_Schema_Writer::get_prefill( $post_id ) );
	}

	/**
	 * AJAX: Save generated service schema to postmeta.
	 */
	public function ajax_save_service_schema() {
		check_ajax_referer( 'twtaeo_service_schema_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID' );
		}
		$fields = array(
			'name'          => sanitize_text_field( wp_unslash( $_POST['name']          ?? '' ) ),
			'description'   => sanitize_textarea_field( wp_unslash( $_POST['description']   ?? '' ) ),
			'service_type'  => sanitize_text_field( wp_unslash( $_POST['service_type']  ?? '' ) ),
			'area_served'   => sanitize_text_field( wp_unslash( $_POST['area_served']   ?? '' ) ),
			'provider_name' => sanitize_text_field( wp_unslash( $_POST['provider_name'] ?? '' ) ),
			'phone'         => sanitize_text_field( wp_unslash( $_POST['phone']         ?? '' ) ),
		);
		if ( empty( $fields['name'] ) ) {
			wp_send_json_error( 'Service Name is required.' );
		}
		$saved = TWTAEO_Service_Schema_Writer::save( $post_id, $fields );
		if ( $saved ) {
			wp_send_json_success( array( 'post_id' => $post_id ) );
		} else {
			wp_send_json_error( 'Failed to save schema.' );
		}
	}

	/**
	 * AJAX: Delete generated service schema from postmeta.
	 */
	public function ajax_delete_service_schema() {
		check_ajax_referer( 'twtaeo_service_schema_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID' );
		}
		TWTAEO_Service_Schema_Writer::delete( $post_id );
		wp_send_json_success( array( 'post_id' => $post_id ) );
	}

	/**
	 * AJAX: Get pre-fill data for the contact schema modal / top panel.
	 */
	public function ajax_get_contact_prefill() {
		check_ajax_referer( 'twtaeo_contact_schema_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID' );
		}
		wp_send_json_success( TWTAEO_Contact_Schema_Writer::get_prefill( $post_id ) );
	}

	/**
	 * AJAX: Save generated contact schema to postmeta.
	 *
	 * Also mirrors the business details back into the central Local Pack
	 * profile so the Contact tab and Local Pack page stay in sync.
	 */
	public function ajax_save_contact_schema() {
		check_ajax_referer( 'twtaeo_contact_schema_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID' );
		}
		$fields = array(
			'name'           => sanitize_text_field( wp_unslash( $_POST['name']           ?? '' ) ),
			'business_type'  => sanitize_text_field( wp_unslash( $_POST['business_type']  ?? 'Organization' ) ),
			'phone'          => sanitize_text_field( wp_unslash( $_POST['phone']          ?? '' ) ),
			'email'          => sanitize_email(      wp_unslash( $_POST['email']          ?? '' ) ),
			'contact_type'   => sanitize_text_field( wp_unslash( $_POST['contact_type']   ?? '' ) ),
			'street_address' => sanitize_text_field( wp_unslash( $_POST['street_address'] ?? '' ) ),
			'city'           => sanitize_text_field( wp_unslash( $_POST['city']           ?? '' ) ),
			'state'          => sanitize_text_field( wp_unslash( $_POST['state']          ?? '' ) ),
			'zip'            => sanitize_text_field( wp_unslash( $_POST['zip']            ?? '' ) ),
			'country'        => sanitize_text_field( wp_unslash( $_POST['country']        ?? '' ) ),
		);
		if ( empty( $fields['name'] ) ) {
			wp_send_json_error( 'Business Name is required.' );
		}

		$saved = TWTAEO_Contact_Schema_Writer::save( $post_id, $fields );
		if ( ! $saved ) {
			wp_send_json_error( 'Failed to save schema.' );
		}

		// Mirror business details back into the central Local Pack profile.
		if ( class_exists( 'TWTAEO_Local_Pack' ) ) {
			$lp = TWTAEO_Local_Pack::get_settings();
			$lp['business_name']  = $fields['name'];
			$lp['phone']          = $fields['phone'];
			$lp['email']          = $fields['email'];
			$lp['street_address'] = $fields['street_address'];
			$lp['city']           = $fields['city'];
			$lp['state']          = $fields['state'];
			$lp['zip']            = $fields['zip'];
			if ( ! empty( $fields['country'] ) ) {
				$lp['country'] = $fields['country'];
			}
			TWTAEO_Local_Pack::save_settings( $lp );
		}

		wp_send_json_success( array( 'post_id' => $post_id ) );
	}

	/**
	 * AJAX: Delete generated contact schema from postmeta.
	 */
	public function ajax_delete_contact_schema() {
		check_ajax_referer( 'twtaeo_contact_schema_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID' );
		}
		TWTAEO_Contact_Schema_Writer::delete( $post_id );
		wp_send_json_success( array( 'post_id' => $post_id ) );
	}

	/**
	 * AJAX: Disconnect a Command Center service (google or bing).
	 */
	public function ajax_cc_disconnect() {
		check_ajax_referer( TWTAEO_Page_Command_Center::NONCE_DISCONNECT, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$service  = sanitize_key( wp_unslash( $_POST['service'] ?? '' ) );
		$redirect = add_query_arg( array( 'page' => 'twt-aeo-command-center', 'twtaeo_tab' => 'settings' ), admin_url( 'admin.php' ) );

		switch ( $service ) {
			case 'google':
				TWTAEO_Google_OAuth::disconnect();
				wp_send_json_success( array( 'redirect' => $redirect ) );
				break;
			case 'bing':
				TWTAEO_Bing_Webmaster::disconnect();
				wp_send_json_success( array( 'redirect' => $redirect ) );
				break;
			default:
				wp_send_json_error( 'Unknown service.' );
		}
	}

	/**
	 * AJAX: Inspect a single URL via the Search Console URL Inspection API.
	 *
	 * Results are cached per-URL for an hour to stay well within the API's
	 * per-property daily quota. Returns a flattened payload the panel renders.
	 */
	public function ajax_gsc_inspect() {
		check_ajax_referer( 'twtaeo_gsc_inspect', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'twt-aeo-ultimate' ) ) );
		}

		if ( ! TWTAEO_Google_OAuth::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Connect your Google account in Settings first.', 'twt-aeo-ultimate' ) ) );
		}

		$config   = TWTAEO_Google_OAuth::get_config();
		$site_url = $config['gsc_site_url'] ?? '';
		if ( ! $site_url ) {
			wp_send_json_error( array( 'message' => __( 'Set your Search Console site URL in Settings first.', 'twt-aeo-ultimate' ) ) );
		}

		$page_url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		if ( ! $page_url ) {
			wp_send_json_error( array( 'message' => __( 'Enter a URL to inspect.', 'twt-aeo-ultimate' ) ) );
		}

		$cache_key = 'twtaeo_gsc_inspect_' . md5( $page_url );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$cached['cached'] = true;
			wp_send_json_success( $cached );
		}

		$result = TWTAEO_Google_OAuth::gsc_inspect_url( $site_url, $page_url );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$index = $result['indexStatusResult'] ?? array();
		$mob   = $result['mobileUsabilityResult'] ?? array();
		$rich  = $result['richResultsResult'] ?? array();

		$rich_types = array();
		foreach ( $rich['detectedItems'] ?? array() as $item ) {
			if ( ! empty( $item['richResultType'] ) ) {
				$rich_types[] = $item['richResultType'];
			}
		}

		$payload = array(
			'cached'           => false,
			'url'              => $page_url,
			'verdict'          => $index['verdict'] ?? 'NEUTRAL',
			'coverage'         => $index['coverageState'] ?? '—',
			'robots'           => $index['robotsTxtState'] ?? '—',
			'indexing'         => $index['indexingState'] ?? '—',
			'fetch'            => $index['pageFetchState'] ?? '—',
			'crawled_as'       => $index['crawledAs'] ?? '—',
			'last_crawl'       => $index['lastCrawlTime'] ?? '',
			'google_canonical' => $index['googleCanonical'] ?? '',
			'user_canonical'   => $index['userCanonical'] ?? '',
			'canonical_match'  => ( ! empty( $index['googleCanonical'] ) && ! empty( $index['userCanonical'] ) )
				? ( $index['googleCanonical'] === $index['userCanonical'] )
				: null,
			'mobile_verdict'   => $mob['verdict'] ?? '',
			'rich_verdict'     => $rich['verdict'] ?? '',
			'rich_types'       => $rich_types,
			'report_link'      => $result['inspectionResultLink'] ?? '',
		);

		set_transient( $cache_key, $payload, HOUR_IN_SECONDS );
		wp_send_json_success( $payload );
	}

	/**
	 * AJAX: Run a PageSpeed Insights analysis on one URL (mobile or desktop).
	 * Results are cached per URL+strategy in the PSI client.
	 */
	public function ajax_psi_check() {
		check_ajax_referer( 'twtaeo_psi_check', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'twt-aeo-ultimate' ) ) );
		}

		$url      = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		$strategy = sanitize_key( wp_unslash( $_POST['strategy'] ?? 'mobile' ) );
		if ( ! $url ) {
			wp_send_json_error( array( 'message' => __( 'Enter a URL to analyze.', 'twt-aeo-ultimate' ) ) );
		}

		$result = TWTAEO_Google_PageSpeed::analyze( $url, $strategy );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/** AJAX: Start a traffic-leak scan — selects GA4 candidates and primes state. */
	public function ajax_leak_scan_start() {
		check_ajax_referer( 'twtaeo_leak_scan', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'twt-aeo-ultimate' ) ) );
		}

		$min_sessions = absint( wp_unslash( $_POST['min_sessions'] ?? 10 ) );
		$result       = TWTAEO_Traffic_Leak_Scanner::start( $min_sessions );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/** AJAX: Audit one candidate URL in the active scan. */
	public function ajax_leak_scan_next() {
		check_ajax_referer( 'twtaeo_leak_scan', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'twt-aeo-ultimate' ) ) );
		}

		$run_id = sanitize_text_field( wp_unslash( $_POST['run_id'] ?? '' ) );
		$index  = absint( wp_unslash( $_POST['index'] ?? 0 ) );
		$result = TWTAEO_Traffic_Leak_Scanner::scan_one( $run_id, $index );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/** AJAX: Scan published content for performance anti-patterns (no API). */
	public function ajax_code_health_scan() {
		check_ajax_referer( 'twtaeo_code_health', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'twt-aeo-ultimate' ) ) );
		}
		wp_send_json_success( TWTAEO_Perf_Detector::scan_all() );
	}

	/** AJAX: Transform a post into AP-style press release copy using Claude. */
	public function ajax_pr_transform() {
		check_ajax_referer( TWTAEO_PR_Metabox::NONCE_TRANSFORM, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}
		$result = TWTAEO_PR_Transformer::transform( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( array( 'content' => $result ) );
	}

	/** AJAX: Save the (edited) AP-style press release to post meta. */
	public function ajax_pr_save() {
		check_ajax_referer( TWTAEO_PR_Metabox::NONCE_SAVE, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$content = sanitize_textarea_field( wp_unslash( $_POST['content'] ?? '' ) );
		if ( ! $post_id || ! $content ) {
			wp_send_json_error( 'Missing required fields.' );
		}
		TWTAEO_PR_Transformer::save( $post_id, $content );
		wp_send_json_success( array( 'post_id' => $post_id ) );
	}

	/** AJAX: Delete the saved press release from post meta. */
	public function ajax_pr_delete() {
		check_ajax_referer( TWTAEO_PR_Metabox::NONCE_SAVE, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}
		TWTAEO_PR_Transformer::delete( $post_id );
		wp_send_json_success( array( 'post_id' => $post_id ) );
	}

	/** AJAX: Submit the formatted press release to the chosen wire service. */
	public function ajax_pr_submit() {
		check_ajax_referer( TWTAEO_PR_Metabox::NONCE_SUBMIT, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$tier    = sanitize_key( wp_unslash( $_POST['tier'] ?? '' ) );
		if ( ! $post_id || ! $tier ) {
			wp_send_json_error( 'Missing required fields.' );
		}
		$result = TWTAEO_PR_Distributor::submit( $post_id, $tier );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result['message'] );
		}
	}

	/**
	 * Detect active SEO plugin name(s).
	 *
	 * @return string
	 */
	public function detect_seo_plugin() {
		return TWTAEO_SEO_Compatibility::get_plugin_name();
	}

	/**
	 * Detect page type — works in admin context.
	 *
	 * @return string
	 */
	public function detect_page_type() {
		if ( is_admin() ) {
			$show_on_front = get_option( 'show_on_front' );
			if ( $show_on_front === 'page' && get_option( 'page_on_front' ) ) {
				return 'Homepage (Static Page)';
			}
			if ( $show_on_front === 'posts' ) {
				return 'Homepage (Blog Index)';
			}
			return 'Homepage';
		}

		if ( is_front_page() ) { return 'Homepage'; }
		if ( is_single() )     { return 'Post'; }
		if ( is_page() )       { return 'Page'; }
		if ( is_archive() )    { return 'Archive'; }

		return 'Unknown';
	}

	/**
	 * Detect schema types on a post using all available strategies.
	 *
	 * Unlike the original first-match-wins approach, this merges results from
	 * ALL postmeta sources so that schema from multiple plugins (e.g. Rank Math
	 * AND SASWP) is captured in a single scan. HTTP and wp_head are skipped in
	 * admin to prevent block editor script queue corruption.
	 *
	 * @param WP_Post|int|null $post Optional post to scan. Defaults to front page.
	 * @return array { page_title, schema_types, source }
	 */
	public function detect_schema_types( $post = null ) {
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}

		if ( ! $post ) {
			$front_page_id = (int) get_option( 'page_on_front' );
			$post          = $front_page_id ? get_post( $front_page_id ) : null;
		}

		$page_title   = $post ? get_the_title( $post ) : get_bloginfo( 'name' );
		$schema_types = array();
		$sources      = array();

		// Strategy 0 — this plugin's own stored schemas. These are published on
		// wp_head (or folded into the Knowledge Graph), but the HTTP/wp_head
		// strategies below cannot be relied on inside AJAX or CLI scans — and a
		// scan that cannot see the plugin's own output reports pages as missing
		// schema they already publish, forever.
		if ( $post ) {
			$own = array_keys( TWTAEO_Custom_Schema_Writer::get_all( $post->ID ) );
			if ( class_exists( 'TWTAEO_Service_Schema_Writer' )
				&& TWTAEO_Service_Schema_Writer::get( $post->ID ) ) {
				$own[] = 'Service';
			}
			$own = array_unique( $own );
			if ( ! empty( $own ) ) {
				foreach ( $own as $type ) {
					if ( ! in_array( $type, $schema_types, true ) ) {
						$schema_types[] = $type;
					}
				}
				$sources[] = 'TWT AEO';
			}
		}

		// Strategy 1 — Rank Math postmeta.
		if ( defined( 'RANK_MATH_VERSION' ) && $post ) {
			$result = $this->detect_schema_rank_math( $post );
			if ( ! empty( $result ) ) {
				foreach ( $result as $type ) {
					if ( ! in_array( $type, $schema_types, true ) ) {
						$schema_types[] = $type;
					}
				}
				$sources[] = 'Rank Math';
			}
		}

		// Strategy 2 — Yoast postmeta.
		if ( defined( 'WPSEO_VERSION' ) && $post ) {
			$result = $this->detect_schema_yoast( $post );
			if ( ! empty( $result ) ) {
				foreach ( $result as $type ) {
					if ( ! in_array( $type, $schema_types, true ) ) {
						$schema_types[] = $type;
					}
				}
				$sources[] = 'Yoast SEO';
			}
		}

		// Strategy 3 — SASWP (Schema & Structured Data For WP).
		// Always runs regardless of other plugins — SASWP stores schema
		// independently and is never captured by Rank Math or Yoast checks.
		if ( $post ) {
			$saswp_types = $this->detect_schema_saswp( $post );
			if ( ! empty( $saswp_types ) ) {
				foreach ( $saswp_types as $type ) {
					if ( ! in_array( $type, $schema_types, true ) ) {
						$schema_types[] = $type;
					}
				}
				$sources[] = 'SASWP';
			}
		}

		// Strategy 4 — HTTP loopback (frontend only).
		if ( empty( $schema_types ) ) {
			$url    = $post ? get_permalink( $post ) : home_url( '/' );
			$result = $this->detect_schema_via_http( $url );
			if ( ! empty( $result ) ) {
				foreach ( $result as $type ) {
					if ( ! in_array( $type, $schema_types, true ) ) {
						$schema_types[] = $type;
					}
				}
				$sources[] = 'HTTP fetch';
			}
		}

		// Strategy 5 — wp_head buffer (frontend only).
		if ( empty( $schema_types ) ) {
			$result = $this->detect_schema_via_wp_head( $post );
			if ( ! empty( $result ) ) {
				foreach ( $result as $type ) {
					if ( ! in_array( $type, $schema_types, true ) ) {
						$schema_types[] = $type;
					}
				}
				$sources[] = 'wp_head';
			}
		}

		if ( empty( $schema_types ) ) {
			return array( 'page_title' => $page_title, 'schema_types' => array( 'No JSON-LD schema detected' ), 'source' => 'none' );
		}

		sort( $schema_types );
		return array(
			'page_title'   => $page_title,
			'schema_types' => $schema_types,
			'source'       => implode( ', ', $sources ),
		);
	}

	/**
	 * Evaluate schema expectations using page intent classification.
	 *
	 * @param WP_Post|int|null $post Optional post. Defaults to front page.
	 * @return array
	 */
	public function evaluate_schema_expectations( $post = null ) {
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}

		if ( ! $post ) {
			$front_page_id = (int) get_option( 'page_on_front' );
			$post          = $front_page_id ? get_post( $front_page_id ) : null;
		}

		// Classify the page intent.
		$intent      = TWTAEO_Page_Intent::classify( $post );
		$schema_data = $this->detect_schema_types( $post );
		$found       = $schema_data['schema_types'];

		$present          = array();
		$missing          = array();
		$optional_missing = array();

		foreach ( $intent['expected'] as $type ) {
			if ( in_array( $type, $found, true ) ) {
				$present[] = $type;
			} else {
				$missing[] = $type;
			}
		}

		foreach ( $intent['recommended'] as $type ) {
			if ( ! in_array( $type, $found, true ) ) {
				$optional_missing[] = $type;
			}
		}

		return array(
			'page_title'   => $schema_data['page_title'],
			'intent'       => $intent,
			'present'      => $present,
			'missing'      => $missing,
			'recommended'  => $optional_missing,
			'not_required' => $intent['not_required'],
			'source'       => $schema_data['source'],
		);
	}

	// ── Private detection strategies ─────────────────────────────────────────

	/**
	 * Normalize a raw @type value to canonical PascalCase schema.org label.
	 * Strips schema.org URL prefix and ucfirst-s the local name so that
	 * "product", "https://schema.org/Product", and "Product" all collapse to
	 * "Product" before deduplication comparisons.
	 */
	private function normalize_schema_type( $type ) {
		$type = trim( $type );
		// Strip schema.org URL prefixes (http and https).
		$type = preg_replace( '#^https?://schema\.org/#', '', $type );
		// Normalize first character to uppercase.
		return $type !== '' ? ucfirst( $type ) : '';
	}

	/**
	 * Detect schema types from SASWP (Schema & Structured Data For WP).
	 * Checks saswp_custom_schema_field and all other saswp_ postmeta keys.
	 *
	 * @param WP_Post $post
	 * @return array
	 */
	private function detect_schema_saswp( WP_Post $post ) {
		$schema_types = array();

		// Check the main custom schema field.
		$custom = get_post_meta( $post->ID, 'saswp_custom_schema_field', true );
		if ( ! empty( $custom ) ) {
			$data = json_decode( $custom, true );
			if ( is_array( $data ) ) {
				$items = isset( $data['@graph'] ) ? $data['@graph'] : array( $data );
				foreach ( $items as $item ) {
					if ( isset( $item['@type'] ) ) {
						$types = is_array( $item['@type'] ) ? $item['@type'] : array( $item['@type'] );
						foreach ( $types as $t ) {
							$t = $this->normalize_schema_type( $t );
							if ( $t && ! in_array( $t, $schema_types, true ) ) {
								$schema_types[] = $t;
							}
						}
					}
				}
			}
		}

		// Broad catch-all — scan all saswp_ postmeta keys for JSON-LD @type values.
		$all_meta = get_post_meta( $post->ID );
		foreach ( $all_meta as $key => $values ) {
			if ( strpos( $key, 'saswp_' ) !== 0 ) {
				continue;
			}
			foreach ( $values as $value ) {
				if ( ! is_string( $value ) || strpos( $value, '@type' ) === false ) {
					continue;
				}
				$data = json_decode( $value, true );
				if ( ! is_array( $data ) ) {
					continue;
				}
				$items = isset( $data['@graph'] ) ? $data['@graph'] : array( $data );
				foreach ( $items as $item ) {
					if ( isset( $item['@type'] ) ) {
						$types = is_array( $item['@type'] ) ? $item['@type'] : array( $item['@type'] );
						foreach ( $types as $t ) {
							$t = $this->normalize_schema_type( $t );
							if ( $t && ! in_array( $t, $schema_types, true ) ) {
								$schema_types[] = $t;
							}
						}
					}
				}
			}
		}

		return $schema_types;
	}

	private function detect_schema_rank_math( WP_Post $post ) {
		$schema_types = array();
		$meta         = get_post_meta( $post->ID );

		foreach ( $meta as $key => $values ) {
			if ( strpos( $key, 'rank_math_schema_' ) !== 0 ) {
				continue;
			}
			foreach ( $values as $value ) {
				$data = maybe_unserialize( $value );
				if ( ! is_array( $data ) || ! isset( $data['@type'] ) ) {
					continue;
				}
				$types = is_array( $data['@type'] ) ? $data['@type'] : array( $data['@type'] );
				foreach ( $types as $t ) {
					$t = $this->normalize_schema_type( $t );
					if ( $t && ! in_array( $t, $schema_types, true ) ) {
						$schema_types[] = $t;
					}
				}
			}
		}

		foreach ( array( 'WebSite', 'WebPage' ) as $type ) {
			if ( ! in_array( $type, $schema_types, true ) ) {
				$schema_types[] = $type;
			}
		}

		$rm_titles = get_option( 'rank_math_titles', array() );
		$kg_type   = $rm_titles['knowledgegraph_type'] ?? 'organization';

		if ( $kg_type === 'organization' && ! in_array( 'Organization', $schema_types, true ) ) {
			$schema_types[] = 'Organization';
		} elseif ( $kg_type === 'person' && ! in_array( 'Person', $schema_types, true ) ) {
			$schema_types[] = 'Person';
		}

		return $schema_types;
	}

	private function detect_schema_yoast( WP_Post $post ) {
		$schema_types = array( 'WebSite', 'WebPage', 'Organization' );

		$page_type = $this->normalize_schema_type( get_post_meta( $post->ID, '_yoast_wpseo_schema_page_type', true ) );
		if ( $page_type && ! in_array( $page_type, $schema_types, true ) ) {
			$schema_types[] = $page_type;
		}

		$article_type = $this->normalize_schema_type( get_post_meta( $post->ID, '_yoast_wpseo_schema_article_type', true ) );
		if ( $article_type && ! in_array( $article_type, $schema_types, true ) ) {
			$schema_types[] = $article_type;
		}

		return $schema_types;
	}

	/**
	 * Detect schema via HTTP loopback request.
	 *
	 * Skipped in admin context to prevent block editor timeouts and
	 * script queue issues caused by mid-request HTTP calls.
	 *
	 * @param string $url
	 * @return array
	 */
	private function detect_schema_via_http( $url ) {
		// Never run an HTTP loopback from inside the admin — it can cause
		// timeouts on page save and corrupt the block editor script queue.
		if ( is_admin() ) {
			return array();
		}

		$response = wp_remote_get( $url, array(
			'timeout'    => 10,
			'sslverify'  => false,
			'user-agent' => 'twt-aeo-ultimate/' . TWTAEO_VERSION,
		) );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		return $this->parse_json_ld_from_html( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Detect schema by buffering wp_head output.
	 *
	 * Skipped in admin context — calling do_action( 'wp_head' ) inside the
	 * admin corrupts WordPress's script dependency queue and breaks the
	 * block editor (jQuery loads out of order, cascading errors).
	 *
	 * @param WP_Post|null $post
	 * @return array
	 */
	private function detect_schema_via_wp_head( $post ) {
		// Never fire wp_head from inside the admin — it breaks the block editor.
		if ( is_admin() ) {
			return array();
		}

		$original_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

		if ( $post ) {
			$GLOBALS['post'] = $post;
			setup_postdata( $post );
		}

		// Fire core's wp_head to capture the rendered <head> for analysis. Hook
		// name held in a variable — it is core's, not ours to prefix.
		$core_action = 'wp_head';
		ob_start();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core hook, not ours to prefix.
		do_action( $core_action );
		$html = ob_get_clean();

		if ( $post ) {
			$GLOBALS['post'] = $original_post;
			wp_reset_postdata();
		}

		return $this->parse_json_ld_from_html( $html );
	}

	private function parse_json_ld_from_html( $html ) {
		$schema_types = array();

		preg_match_all(
			'/<scr[i]pt[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/scr[i]pt>/is',
			$html,
			$matches
		);

		if ( empty( $matches[1] ) ) {
			return $schema_types;
		}

		foreach ( $matches[1] as $json_ld ) {
			$data = json_decode( $json_ld, true );
			if ( ! $data ) {
				continue;
			}

			$items = isset( $data['@graph'] ) ? $data['@graph'] : array( $data );

			foreach ( $items as $item ) {
				if ( ! isset( $item['@type'] ) ) {
					continue;
				}
				$types = is_array( $item['@type'] ) ? $item['@type'] : explode( ',', $item['@type'] );
				foreach ( $types as $t ) {
					$t = $this->normalize_schema_type( $t );
					if ( $t && ! in_array( $t, $schema_types, true ) ) {
						$schema_types[] = $t;
					}
				}
			}
		}

		return $schema_types;
	}

	public function ajax_get_custom_schema() {
		check_ajax_referer( TWTAEO_Custom_Schema_Writer::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$type    = sanitize_text_field( wp_unslash( $_POST['schema_type'] ?? '' ) );
		if ( ! $post_id || ! $type ) {
			wp_send_json_error( 'Invalid parameters.' );
		}
		$existing = TWTAEO_Custom_Schema_Writer::get_by_type( $post_id, $type );
		if ( $existing ) {
			wp_send_json_success( array(
				'json'   => wp_json_encode( $existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'exists' => true,
			) );
		} else {
			$template = TWTAEO_Custom_Schema_Writer::get_template( $type, $post_id );
			wp_send_json_success( array(
				'json'   => wp_json_encode( $template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'exists' => false,
			) );
		}
	}

	public function ajax_save_custom_schema() {
		check_ajax_referer( TWTAEO_Custom_Schema_Writer::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id     = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$type        = sanitize_text_field( wp_unslash( $_POST['schema_type'] ?? '' ) );
		// The payload is JSON, not free text: text-field sanitizers strip legitimate
		// JSON (escape sequences, HTML in answer strings). Safety comes from save(),
		// which rejects anything json_decode() can't parse and re-encodes before
		// storage — the raw string itself is never stored or output.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated and re-encoded in TWTAEO_Custom_Schema_Writer::save().
		$json_string = trim( (string) wp_unslash( $_POST['json'] ?? '' ) );
		if ( ! $post_id || ! $type || ! $json_string ) {
			wp_send_json_error( 'Missing required fields.' );
		}
		$result = TWTAEO_Custom_Schema_Writer::save( $post_id, $type, $json_string );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( array( 'post_id' => $post_id, 'type' => $type ) );
	}

	public function ajax_delete_custom_schema() {
		check_ajax_referer( TWTAEO_Custom_Schema_Writer::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$type    = sanitize_text_field( wp_unslash( $_POST['schema_type'] ?? '' ) );
		if ( ! $post_id || ! $type ) {
			wp_send_json_error( 'Invalid parameters.' );
		}
		TWTAEO_Custom_Schema_Writer::delete( $post_id, $type );
		wp_send_json_success( array( 'post_id' => $post_id, 'type' => $type ) );
	}

	public function ajax_get_schema_prefill() {
		check_ajax_referer( TWTAEO_Custom_Schema_Writer::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( 'Post not found.' );
		}

		$author = class_exists( 'TWTAEO_Author_Meta' )
			? TWTAEO_Author_Meta::get_author_data( $post->post_author )
			: array();

		$social = $author['social'] ?? array();

		wp_send_json_success( array(
			'post_title'       => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
			'post_date'        => get_the_date( 'Y-m-d', $post ),
			'post_modified'    => get_post_modified_time( 'Y-m-d', false, $post ),
			'post_url'         => get_permalink( $post_id ),
			'site_name'        => get_bloginfo( 'name' ),
			'site_url'         => home_url(),
			'site_description' => get_bloginfo( 'description' ),
			'author'           => array(
				'name'      => $author['name']      ?? '',
				'email'     => $author['email']     ?? '',
				'url'       => ! empty( $author['url'] ) ? $author['url'] : ( $author['profile_url'] ?? '' ),
				'job_title' => $author['job_title'] ?? '',
				'linkedin'  => $social['linkedin']  ?? '',
				'twitter'   => $social['twitter']   ?? '',
				'facebook'  => $social['facebook']  ?? '',
				'website'   => $social['website']   ?? '',
			),
		) );
	}

	/**
	 * AJAX: Return product data needed to pre-fill the WC schema dialog.
	 */
	public function ajax_wc_get_product_data() {
		check_ajax_referer( 'twtaeo_wc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'product' ) {
			wp_send_json_error( 'Product not found.' );
		}

		$wc_product  = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
		$name        = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
		$description = '';
		$price       = '';
		$currency    = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		$sku         = '';
		$availability = 'https://schema.org/InStock';
		$image_url   = '';

		if ( $wc_product ) {
			$short = wp_strip_all_tags( $wc_product->get_short_description() );
			$description  = $short ?: wp_strip_all_tags( $post->post_content );
			$price        = $wc_product->get_price();
			$sku          = $wc_product->get_sku();
			$availability = ( $wc_product->get_stock_status() === 'instock' )
				? 'https://schema.org/InStock'
				: 'https://schema.org/OutOfStock';
			$img_id = $wc_product->get_image_id();
			if ( $img_id ) {
				$image_url = wp_get_attachment_url( $img_id );
			}
		}
		if ( ! $image_url ) {
			$image_url = get_the_post_thumbnail_url( $post_id, 'full' ) ?: '';
		}

		$wc_data     = TWTAEO_WooCommerce_Detector::scan( $post );
		$intent      = $wc_data['intent'];
		$recommended = $wc_data['recommended_schema'];
		$edit_url    = get_edit_post_link( $post_id, 'raw' );
		$existing    = TWTAEO_Custom_Schema_Writer::get_by_type( $post_id, $recommended );
		$missing     = self::get_wc_product_missing( $wc_product, $edit_url );

		// Rich Product detection (attributes, identifiers, relationships, reviews).
		$attributes    = TWTAEO_WooCommerce_Detector::detect_attributes( $wc_product );
		$identifiers   = TWTAEO_WooCommerce_Detector::detect_identifiers( $wc_product );
		$relationships = TWTAEO_WooCommerce_Detector::detect_relationships( $wc_product );
		$reviews       = TWTAEO_WooCommerce_Detector::detect_reviews( $post_id, $wc_product );

		wp_send_json_success( array(
			'name'           => $name,
			'description'    => $description,
			'price'          => (string) $price,
			'currency'       => $currency,
			'sku'            => $sku,
			'availability'   => $availability,
			'image_url'      => $image_url,
			'url'            => get_permalink( $post_id ),
			'site_name'      => get_bloginfo( 'name' ),
			'site_url'       => home_url(),
			'intent'         => $intent,
			'recommended'    => $recommended,
			'edit_url'       => $edit_url,
			'missing'        => $missing,
			'has_existing'   => (bool) $existing,
			'existing_json'  => $existing
				? wp_json_encode( $existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
				: '',
			// Rich Product fields.
			'attributes'     => $attributes,
			'identifiers'    => $identifiers,
			'item_condition' => 'https://schema.org/NewCondition',
			'relationships'  => array(
				'is_variant_of' => $relationships['is_variant_of'],
				'related'       => $relationships['related'],
				'similar'       => self::wc_picked_chips( $post_id, TWTAEO_WooCommerce_Detector::META_SIMILAR ),
				'accessory'     => self::wc_picked_chips( $post_id, TWTAEO_WooCommerce_Detector::META_ACCESSORY ),
			),
			'reviews'        => array(
				'aggregate' => $reviews['aggregate'],
				'count'     => count( $reviews['reviews'] ),
			),
			'shipping'       => TWTAEO_WooCommerce_Detector::get_shipping_defaults(),
			'return'         => TWTAEO_WooCommerce_Detector::get_return_defaults(),
			'ai_attributes'      => TWTAEO_Product_Enricher::get_cached_attributes( $post_id ),
			'ai_vision'          => TWTAEO_Product_Enricher::get_cached_vision( $post_id ),
			'ai_google_category' => ( $gcat = TWTAEO_Product_Enricher::get_cached_google_category( $post_id ) ) ? $gcat['data'] : null,
			'image_alt'          => TWTAEO_Product_Enricher::image_alt_summary( $post_id ),
			'brand_sameas'       => array_values( (array) get_post_meta( $post_id, TWTAEO_WooCommerce_Detector::META_SAMEAS, true ) ),
		) );
	}

	/**
	 * AJAX: Run on-demand AI attribute extraction for one product.
	 */
	public function ajax_wc_ai_extract() {
		check_ajax_referer( 'twtaeo_wc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}

		$result = TWTAEO_Product_Enricher::extract_attributes( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$cached = TWTAEO_Product_Enricher::get_cached_attributes( $post_id );
		wp_send_json_success( array(
			'data'      => $result,
			'provider'  => $cached['provider'] ?? '',
			'time'      => $cached['time'] ?? time(),
			'time_diff' => human_time_diff( $cached['time'] ?? time() ) . ' ago',
		) );
	}

	/**
	 * AJAX: Rewrite/enhance a product description via AI.
	 */
	public function ajax_wc_ai_describe() {
		check_ajax_referer( 'twtaeo_wc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}
		$current = isset( $_POST['current'] ) ? sanitize_textarea_field( wp_unslash( $_POST['current'] ) ) : '';

		$result = TWTAEO_Product_Enricher::enhance_description( $post_id, $current );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( array(
			'text'     => $result,
			'provider' => TWTAEO_AI_Client::enrich_provider(),
		) );
	}

	/**
	 * AJAX: Standardize custom attributes into schema.org fields via AI.
	 */
	public function ajax_wc_ai_standardize() {
		check_ajax_referer( 'twtaeo_wc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$rows = array();
		if ( isset( $_POST['additional'] ) && is_array( $_POST['additional'] ) ) {
			foreach ( wp_unslash( $_POST['additional'] ) as $row ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( ! is_array( $row ) ) {
					continue;
				}
				$rows[] = array(
					'name'  => sanitize_text_field( $row['name'] ?? '' ),
					'value' => sanitize_text_field( $row['value'] ?? '' ),
				);
			}
		}

		$result = TWTAEO_Product_Enricher::standardize_attributes( $rows );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( array(
			'data'     => $result,
			'provider' => TWTAEO_AI_Client::enrich_provider(),
		) );
	}

	/**
	 * AJAX: Flag competitor-expected fields this product is missing.
	 */
	public function ajax_wc_ai_competitive() {
		check_ajax_referer( 'twtaeo_wc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}

		$result = TWTAEO_Product_Enricher::competitive_gaps( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( array(
			'recommended' => $result,
			'provider'    => TWTAEO_AI_Client::retrieval_provider(),
		) );
	}

	/**
	 * AJAX: Resolve brand authority (sameAs) URLs via web-grounded retrieval.
	 */
	public function ajax_wc_ai_sameas() {
		check_ajax_referer( 'twtaeo_wc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$brand   = sanitize_text_field( wp_unslash( $_POST['brand'] ?? '' ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}

		$urls = TWTAEO_Product_Enricher::resolve_brand_sameas( $brand );
		if ( is_wp_error( $urls ) ) {
			wp_send_json_error( $urls->get_error_message() );
		}
		wp_send_json_success( array(
			'urls'     => $urls,
			'provider' => TWTAEO_AI_Client::retrieval_provider(),
		) );
	}

	/**
	 * AJAX: Resolve the product's official Google Product Category (CategoryCode)
	 * via web-grounded retrieval.
	 */
	public function ajax_wc_ai_google_category() {
		check_ajax_referer( 'twtaeo_wc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}

		$result = TWTAEO_Product_Enricher::resolve_google_category( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( array(
			'category' => $result,
			'provider' => TWTAEO_AI_Client::retrieval_provider(),
		) );
	}

	/**
	 * AJAX: Extract visual attributes from a product's image via AI vision.
	 */
	public function ajax_wc_ai_vision() {
		check_ajax_referer( 'twtaeo_wc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}

		$result = TWTAEO_Product_Enricher::extract_vision_attributes( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$cached = TWTAEO_Product_Enricher::get_cached_vision( $post_id );
		wp_send_json_success( array(
			'data'      => $result,
			'provider'  => $cached['provider'] ?? '',
			'time'      => $cached['time'] ?? time(),
			'time_diff' => human_time_diff( $cached['time'] ?? time() ) . ' ago',
		) );
	}

	/**
	 * AJAX: Return a post/page's images for the Image SEO edit modal.
	 */
	public function ajax_img_get_post_images() {
		check_ajax_referer( TWTAEO_Page_Image_SEO::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}
		wp_send_json_success( array( 'images' => TWTAEO_Image_Optimizer::modal_images( $post_id ) ) );
	}

	/**
	 * AJAX: Generate alt text for a post/page's images via AI vision.
	 */
	public function ajax_img_generate_alt() {
		check_ajax_referer( TWTAEO_Page_Image_SEO::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}
		$result = TWTAEO_Image_Optimizer::generate_alt( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		$result['cov_html'] = TWTAEO_Product_Enricher::alt_badge_html( TWTAEO_Image_Optimizer::image_summary( $post_id ) );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Save manually-edited alt text for a post/page's images.
	 */
	public function ajax_img_save_alt() {
		check_ajax_referer( TWTAEO_Page_Image_SEO::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}
		$map = array();
		if ( isset( $_POST['alts'] ) && is_array( $_POST['alts'] ) ) {
			foreach ( wp_unslash( $_POST['alts'] ) as $key => $alt ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$map[ sanitize_key( $key ) ] = sanitize_text_field( $alt );
			}
		}

		$result = TWTAEO_Image_Optimizer::save_manual( $post_id, $map );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		$result['cov_html'] = TWTAEO_Product_Enricher::alt_badge_html( TWTAEO_Image_Optimizer::image_summary( $post_id ) );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Set a media-library image as a post's featured image.
	 *
	 * Backs the "Add an image" button on the Image SEO screen for pages that
	 * have no images at all — the featured image is the one every theme
	 * outputs and the one og:image and schema fall back to.
	 */
	public function ajax_img_set_featured() {
		check_ajax_referer( TWTAEO_Page_Image_SEO::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id       = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$attachment_id = absint( wp_unslash( $_POST['attachment_id'] ?? 0 ) );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( 'Invalid post ID.' );
		}
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			wp_send_json_error( 'The selected item is not an image.' );
		}

		if ( ! set_post_thumbnail( $post_id, $attachment_id ) ) {
			wp_send_json_error( 'Could not set the featured image.' );
		}

		$summary = TWTAEO_Image_Optimizer::image_summary( $post_id );
		wp_send_json_success( array(
			'total'    => $summary['total'],
			'missing'  => $summary['missing'],
			'cov_html' => TWTAEO_Product_Enricher::alt_badge_html( $summary ),
		) );
	}

	/**
	 * AJAX: Fill missing alt text on a product's images via AI vision.
	 */
	public function ajax_wc_ai_alt() {
		check_ajax_referer( 'twtaeo_wc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}

		$result = TWTAEO_Product_Enricher::fill_image_alt( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		$result['alt_html'] = TWTAEO_Product_Enricher::alt_badge_html(
			TWTAEO_Product_Enricher::image_alt_summary( $post_id )
		);
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Assemble and save a rich Product schema from modal field values.
	 */
	public function ajax_wc_save_schema() {
		check_ajax_referer( 'twtaeo_wc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}

		// Scalar field overrides.
		$overrides = array();
		foreach ( array( 'name', 'description', 'sku', 'price', 'currency', 'brand', 'color', 'material', 'size', 'gtin', 'mpn' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$overrides[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		if ( isset( $_POST['description'] ) ) {
			$overrides['description'] = sanitize_textarea_field( wp_unslash( $_POST['description'] ) );
		}
		if ( isset( $_POST['image'] ) ) {
			$overrides['image'] = esc_url_raw( wp_unslash( $_POST['image'] ) );
		}
		if ( isset( $_POST['availability'] ) ) {
			$overrides['availability'] = esc_url_raw( wp_unslash( $_POST['availability'] ) );
		}
		if ( isset( $_POST['item_condition'] ) ) {
			$overrides['item_condition'] = esc_url_raw( wp_unslash( $_POST['item_condition'] ) );
		}

		// additionalProperty rows.
		if ( isset( $_POST['additional'] ) && is_array( $_POST['additional'] ) ) {
			$rows = array();
			foreach ( wp_unslash( $_POST['additional'] ) as $row ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( ! is_array( $row ) ) {
					continue;
				}
				$rows[] = array(
					'name'  => sanitize_text_field( $row['name'] ?? '' ),
					'value' => sanitize_text_field( $row['value'] ?? '' ),
				);
			}
			$overrides['additional'] = $rows;
		}

		// Manual relationship pickers (arrays of product IDs). Always set the keys
		// so an empty selection clears previously-saved picks.
		$overrides['is_similar_to'] = ( isset( $_POST['is_similar_to'] ) && is_array( $_POST['is_similar_to'] ) )
			? array_filter( array_map( 'absint', wp_unslash( $_POST['is_similar_to'] ) ) )
			: array();
		$overrides['is_accessory_for'] = ( isset( $_POST['is_accessory_for'] ) && is_array( $_POST['is_accessory_for'] ) )
			? array_filter( array_map( 'absint', wp_unslash( $_POST['is_accessory_for'] ) ) )
			: array();
		update_post_meta( $post_id, TWTAEO_WooCommerce_Detector::META_SIMILAR, array_values( $overrides['is_similar_to'] ) );
		update_post_meta( $post_id, TWTAEO_WooCommerce_Detector::META_ACCESSORY, array_values( $overrides['is_accessory_for'] ) );

		// Brand sameAs authority URLs (always set so an empty selection clears them).
		$overrides['same_as'] = ( isset( $_POST['same_as'] ) && is_array( $_POST['same_as'] ) )
			? array_filter( array_map( 'esc_url_raw', wp_unslash( $_POST['same_as'] ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw sanitizes each element via array_map.
			: array();
		update_post_meta( $post_id, TWTAEO_WooCommerce_Detector::META_SAMEAS, array_values( $overrides['same_as'] ) );

		$overrides['include_reviews'] = ! isset( $_POST['include_reviews'] ) || rest_sanitize_boolean( wp_unslash( $_POST['include_reviews'] ) );

		$schema = TWTAEO_WooCommerce_Detector::build_product_schema( $post_id, $overrides );
		if ( ! $schema ) {
			wp_send_json_error( 'Could not build schema for this product.' );
		}
		if ( empty( $schema['name'] ) ) {
			wp_send_json_error( 'Name is required.' );
		}

		$json   = wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$result = TWTAEO_Custom_Schema_Writer::save( $post_id, 'Product', $json );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		// Optionally write the (enhanced) description back to the product itself.
		$write_desc = isset( $_POST['update_product_desc'] ) && rest_sanitize_boolean( wp_unslash( $_POST['update_product_desc'] ) );
		if ( $write_desc && ! empty( $overrides['description'] ) ) {
			wp_update_post( array(
				'ID'           => $post_id,
				'post_content' => $overrides['description'],
			) );
		}

		wp_send_json_success( array(
			'type'              => 'Product',
			'optimization_html' => TWTAEO_WooCommerce_Detector::optimization_badge_html(
				TWTAEO_WooCommerce_Detector::schema_optimization( $post_id )
			),
		) );
	}

	/**
	 * Resolve a stored array of product IDs into picker chip objects.
	 *
	 * @param int    $post_id
	 * @param string $meta_key
	 * @return array[] Each: { id, text, url }
	 */
	private static function wc_picked_chips( $post_id, $meta_key ) {
		$ids = get_post_meta( $post_id, $meta_key, true );
		$out = array();
		foreach ( (array) $ids as $id ) {
			$id = absint( $id );
			if ( ! $id ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'product' ) {
				continue;
			}
			$out[] = array(
				'id'   => $id,
				'text' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) . ' (#' . $id . ')',
				'url'  => get_permalink( $id ),
			);
		}
		return $out;
	}

	/**
	 * AJAX: Search published products for the relationship pickers.
	 */
	public function ajax_wc_search_products() {
		check_ajax_referer( 'twtaeo_wc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$term = sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) );
		if ( strlen( $term ) < 2 || ! function_exists( 'wc_get_products' ) ) {
			wp_send_json_success( array() );
		}

		$products = wc_get_products( array(
			's'      => $term,
			'limit'  => 15,
			'status' => 'publish',
			'return' => 'objects',
		) );

		$out = array();
		foreach ( $products as $product ) {
			$out[] = array(
				'id'   => $product->get_id(),
				'text' => html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ) . ' (#' . $product->get_id() . ')',
				'url'  => get_permalink( $product->get_id() ),
			);
		}
		wp_send_json_success( $out );
	}

	/**
	 * AJAX: Save site-wide shipping & return-policy defaults.
	 */
	public function ajax_wc_save_shipping_settings() {
		check_ajax_referer( 'twtaeo_wc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$shipping = array(
			'enabled'      => ! empty( $_POST['ship_enabled'] ) && rest_sanitize_boolean( wp_unslash( $_POST['ship_enabled'] ) ),
			'country'      => strtoupper( sanitize_text_field( wp_unslash( $_POST['ship_country'] ?? 'US' ) ) ),
			'rate_type'    => ( sanitize_key( wp_unslash( $_POST['ship_rate_type'] ?? 'flat' ) ) === 'free' ) ? 'free' : 'flat',
			'rate'         => sanitize_text_field( wp_unslash( $_POST['ship_rate'] ?? '' ) ),
			'currency'     => strtoupper( sanitize_text_field( wp_unslash( $_POST['ship_currency'] ?? 'USD' ) ) ),
			'handling_min' => absint( wp_unslash( $_POST['ship_handling_min'] ?? 0 ) ),
			'handling_max' => absint( wp_unslash( $_POST['ship_handling_max'] ?? 1 ) ),
			'transit_min'  => absint( wp_unslash( $_POST['ship_transit_min'] ?? 1 ) ),
			'transit_max'  => absint( wp_unslash( $_POST['ship_transit_max'] ?? 5 ) ),
		);

		$return = array(
			'enabled' => ! empty( $_POST['ret_enabled'] ) && rest_sanitize_boolean( wp_unslash( $_POST['ret_enabled'] ) ),
			'country' => strtoupper( sanitize_text_field( wp_unslash( $_POST['ret_country'] ?? 'US' ) ) ),
			'days'    => absint( wp_unslash( $_POST['ret_days'] ?? 30 ) ),
			'fees'    => ( sanitize_key( wp_unslash( $_POST['ret_fees'] ?? 'free' ) ) === 'paid' ) ? 'paid' : 'free',
			'method'  => esc_url_raw( wp_unslash( $_POST['ret_method'] ?? 'https://schema.org/ReturnByMail' ) ),
		);

		update_option( TWTAEO_WooCommerce_Detector::OPTION_SHIPPING, $shipping );
		update_option( TWTAEO_WooCommerce_Detector::OPTION_RETURN, $return );

		wp_send_json_success( array( 'shipping' => $shipping, 'return' => $return ) );
	}

	/**
	 * AJAX: Auto-generate and save Product/Service schema, one batch per call.
	 *
	 * ⚠️ Walks the WHOLE catalogue via product_ids_page(), never scan_all() —
	 * scan_all() stops at DISPLAY_CAP (200), and looping it here is why a large
	 * store generated exactly 179 of 200 and silently ignored the rest of the
	 * catalogue. The client re-calls with the returned cursor until done.
	 */
	public function ajax_wc_generate_all() {
		check_ajax_referer( 'twtaeo_wc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$batch  = TWTAEO_WooCommerce_Detector::GENERATE_BATCH;

		$total = TWTAEO_WooCommerce_Detector::total_products();
		$ids   = TWTAEO_WooCommerce_Detector::product_ids_page( $offset, $batch );

		$saved   = 0;
		$skipped = 0;
		$errors  = array();

		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$wc_data     = TWTAEO_WooCommerce_Detector::scan( $post );
			$recommended = $wc_data['recommended_schema'];

			if ( TWTAEO_Custom_Schema_Writer::get_by_type( $post->ID, $recommended ) ) {
				$skipped++;
				continue;
			}

			$schema = self::build_wc_auto_schema( $post, $recommended );
			if ( ! $schema ) {
				$errors[] = get_the_title( $post );
				continue;
			}

			$json   = wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$result = TWTAEO_Custom_Schema_Writer::save( $post->ID, $recommended, $json );

			if ( is_wp_error( $result ) ) {
				$errors[] = get_the_title( $post );
			} else {
				$saved++;
			}
		}

		$processed = $offset + count( $ids );

		wp_send_json_success( array(
			'saved'     => $saved,
			'skipped'   => $skipped,
			'errors'    => $errors,
			// Cursor state. `done` is driven by an empty page rather than by
			// processed >= total, so a product deleted mid-run cannot leave the
			// client looping against a total it will never reach.
			'offset'    => $offset,
			'next'      => $processed,
			'total'     => $total,
			'done'      => empty( $ids ),
		) );
	}

	/**
	 * Check a WooCommerce product for missing fields that affect schema quality.
	 *
	 * @param WC_Product|null $wc_product
	 * @param string          $edit_url
	 * @return array[]  Each item: { field, message, fix_url }
	 */
	private static function get_wc_product_missing( $wc_product, $edit_url ) {
		$missing = array();
		if ( ! $wc_product ) {
			return $missing;
		}

		if ( $wc_product->get_price() === '' || $wc_product->get_price() === false ) {
			$missing[] = array(
				'field'   => 'Price',
				'message' => 'No price set — required for Product schema offers.',
				'fix_url' => $edit_url,
			);
		}

		if ( ! trim( wp_strip_all_tags( $wc_product->get_short_description() ) ) ) {
			$missing[] = array(
				'field'   => 'Short Description',
				'message' => 'Short description is empty — recommended for rich results.',
				'fix_url' => $edit_url,
			);
		}

		if ( ! $wc_product->get_image_id() ) {
			$missing[] = array(
				'field'   => 'Product Image',
				'message' => 'No product image — helps search engines display rich previews.',
				'fix_url' => $edit_url,
			);
		}

		return $missing;
	}

	/**
	 * Build a minimal auto-generated schema array for a WooCommerce product.
	 *
	 * @param WP_Post $post
	 * @param string  $type  'Product' or 'Service'
	 * @return array|null
	 */
	public static function build_wc_auto_schema( WP_Post $post, $type ) {
		// Product schema is assembled by the central rich builder so the bulk path
		// and the per-product modal stay in sync.
		if ( $type === 'Product' ) {
			return TWTAEO_WooCommerce_Detector::build_product_schema( $post->ID );
		}

		$wc_product  = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
		$name        = html_entity_decode( get_the_title( $post->ID ), ENT_QUOTES, 'UTF-8' );
		$url         = get_permalink( $post->ID );
		$site_name   = get_bloginfo( 'name' );
		$site_url    = home_url();
		$description = '';
		$image_url   = '';

		if ( $wc_product ) {
			$short       = wp_strip_all_tags( $wc_product->get_short_description() );
			$description = $short ?: wp_strip_all_tags( $post->post_content );
			$img_id      = $wc_product->get_image_id();
			if ( $img_id ) {
				$image_url = wp_get_attachment_url( $img_id );
			}
		}
		if ( ! $image_url ) {
			$image_url = get_the_post_thumbnail_url( $post->ID, 'full' ) ?: '';
		}

		if ( $type === 'Service' ) {
			$schema = array(
				'@context' => 'https://schema.org',
				'@type'    => 'Service',
				'name'     => $name,
				'url'      => $url,
				'provider' => array( '@type' => 'Organization', 'name' => $site_name, 'url' => $site_url ),
			);
			if ( $description ) $schema['description'] = $description;
			if ( $image_url )   $schema['image']       = $image_url;
			return $schema;
		}

		return null;
	}

	/**
	 * AJAX: Run schema conflict scan via homepage loopback.
	 */
	public function ajax_schema_conflict_scan() {
		check_ajax_referer( TWTAEO_Schema_Conflict_Detector::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$result = TWTAEO_Schema_Conflict_Detector::scan();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Save or remove a schema suppression entry.
	 */
	public function ajax_schema_conflict_suppress() {
		check_ajax_referer( TWTAEO_Schema_Conflict_Detector::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$plugin = sanitize_key( wp_unslash( $_POST['plugin'] ?? '' ) );
		$type   = sanitize_text_field( wp_unslash( $_POST['type'] ?? '' ) );
		$active = rest_sanitize_boolean( wp_unslash( $_POST['active'] ?? false ) );

		if ( ! $plugin || ! $type ) {
			wp_send_json_error( 'Missing parameters.' );
		}
		if ( ! in_array( $plugin, array( 'yoast', 'rank_math', 'aioseo' ), true ) ) {
			wp_send_json_error( 'Unsupported plugin.' );
		}

		TWTAEO_Schema_Conflict_Detector::save_suppression( $plugin, $type, $active );
		wp_send_json_success( array( 'plugin' => $plugin, 'type' => $type, 'active' => $active ) );
	}

	/**
	 * AJAX: Delete schema postmeta written by a specific SEO plugin.
	 */
	public function ajax_seo_schema_delete() {
		check_ajax_referer( TWTAEO_Schema_Conflict_Detector::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$plugin = sanitize_key( wp_unslash( $_POST['plugin'] ?? '' ) );

		if ( ! in_array( $plugin, array( 'yoast', 'rank_math', 'aioseo' ), true ) ) {
			wp_send_json_error( 'Unsupported plugin.' );
		}

		$rows = TWTAEO_Schema_Conflict_Detector::delete_plugin_schema( $plugin );
		wp_send_json_success( array( 'plugin' => $plugin, 'rows_deleted' => $rows ) );
	}

	/**
	 * AJAX: Run crawler visibility tests for all configured AI bots.
	 */
	public function ajax_run_crawler_tests() {
		check_ajax_referer( TWTAEO_Crawler_Tester::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		wp_send_json_success( TWTAEO_Crawler_Tester::run_all_tests() );
	}

	/**
	 * AJAX: Run server diagnostic for the .well-known path.
	 */
	public function ajax_diagnose_server() {
		check_ajax_referer( TWTAEO_AI_Ready::NONCE_DIAGNOSE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		wp_send_json_success( TWTAEO_AI_Ready::diagnose_server() );
	}

	/**
	 * AJAX: Apply the Apache .htaccess fix for the .well-known path.
	 */
	public function ajax_apply_apache_fix() {
		check_ajax_referer( TWTAEO_AI_Ready::NONCE_DIAGNOSE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		wp_send_json_success( TWTAEO_AI_Ready::apply_wellknown_apache_fix() );
	}

	/**
	 * AJAX: Generate and save FAQPage schema for a single post.
	 */
	public function ajax_faq_generate_one() {
		check_ajax_referer( TWTAEO_FAQ_Detector::NONCE_GENERATE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Missing post ID.' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'Post not found.' ) );
		}

		$qa_pairs = TWTAEO_FAQ_Detector::extract_qa_pairs( $post );
		if ( empty( $qa_pairs ) ) {
			wp_send_json_error( array(
				'message'    => 'Could not extract Q&A pairs from this post.',
				'post_id'    => $post_id,
				'post_title' => get_the_title( $post_id ),
			) );
		}

		$schema = TWTAEO_FAQ_Detector::build_faqpage_schema( $post_id, $qa_pairs );
		TWTAEO_Custom_Schema_Writer::save( $post_id, 'FAQPage', wp_json_encode( $schema ) );

		wp_send_json_success( array(
			'post_id'    => $post_id,
			'post_title' => get_the_title( $post_id ),
			'qa_count'   => count( $qa_pairs ),
		) );
	}

	/**
	 * AJAX: One-click Service schema for a single post — prefills from the page
	 * (title + content) via the existing writer and saves, no modal needed.
	 */
	public function ajax_service_generate_one() {
		check_ajax_referer( 'twtaeo_service_schema_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid post.' ) );
		}

		$prefill = TWTAEO_Service_Schema_Writer::get_prefill( $post_id );
		if ( empty( $prefill['name'] ) ) {
			wp_send_json_error( array( 'message' => 'Could not build Service schema for this page.' ) );
		}

		$saved = TWTAEO_Service_Schema_Writer::save( $post_id, array(
			'name'          => $prefill['name'],
			'description'   => $prefill['description']   ?? '',
			'service_type'  => $prefill['service_type']  ?? '',
			'area_served'   => $prefill['area_served']   ?? '',
			'provider_name' => $prefill['provider_name'] ?? '',
			'phone'         => $prefill['phone']         ?? '',
		) );

		if ( ! $saved ) {
			wp_send_json_error( array( 'message' => 'Failed to save Service schema.' ) );
		}
		wp_send_json_success( array( 'post_id' => $post_id ) );
	}

	/**
	 * AJAX: Generate FAQPage schema for one post from a batch queue.
	 * Frontend calls this repeatedly, advancing through the queue one post at a time.
	 */
	public function ajax_faq_generate_batch() {
		check_ajax_referer( TWTAEO_FAQ_Detector::NONCE_GENERATE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Missing post ID.' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array(
				'post_id' => $post_id,
				'skipped' => true,
				'message' => 'Post not found.',
			) );
		}

		$qa_pairs = TWTAEO_FAQ_Detector::extract_qa_pairs( $post );
		if ( empty( $qa_pairs ) ) {
			wp_send_json_success( array(
				'post_id'    => $post_id,
				'post_title' => get_the_title( $post_id ),
				'skipped'    => true,
				'message'    => 'No extractable Q&A pairs found.',
			) );
		}

		$schema = TWTAEO_FAQ_Detector::build_faqpage_schema( $post_id, $qa_pairs );
		TWTAEO_Custom_Schema_Writer::save( $post_id, 'FAQPage', wp_json_encode( $schema ) );

		wp_send_json_success( array(
			'post_id'    => $post_id,
			'post_title' => get_the_title( $post_id ),
			'qa_count'   => count( $qa_pairs ),
			'skipped'    => false,
		) );
	}

	// ── Index Status AJAX ─────────────────────────────────────────────────────

	/**
	 * Inspect one URL via GSC URL Inspection API + run local heuristics.
	 * Called once per URL by the JS progress loop.
	 */
	public function ajax_index_scan_url() {
		check_ajax_referer( TWTAEO_Index_Status::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$post_id  = absint( wp_unslash( $_POST['post_id']  ?? 0 ) );
		$url      = esc_url_raw( wp_unslash( $_POST['url']      ?? '' ) );
		$site_url = esc_url_raw( wp_unslash( $_POST['site_url'] ?? '' ) );

		if ( ! $post_id || ! $url || ! $site_url ) {
			wp_send_json_error( 'Missing parameters.' );
		}

		$gsc = TWTAEO_Index_Status::inspect_url( $url, $site_url );
		if ( is_wp_error( $gsc ) ) {
			wp_send_json_error( $gsc->get_error_message() );
		}

		$heuristics = TWTAEO_Index_Heuristics::analyze( $post_id );

		TWTAEO_Index_Status::save_result( $post_id, $gsc, $heuristics ?? array() );

		wp_send_json_success( array(
			'post_id'    => $post_id,
			'gsc'        => $gsc,
			'heuristics' => $heuristics,
		) );
	}

	// ── Local Pack Auto-fill AJAX ────────────────────────────────────────────

	public function ajax_local_pack_autofill() {
		check_ajax_referer( TWTAEO_Page_Local_Pack::NONCE_NAP, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		wp_send_json_success( TWTAEO_Local_Pack::gather_autofill_data() );
	}

	// ── Local Pack NAP AJAX ───────────────────────────────────────────────────

	public function ajax_local_pack_nap_google() {
		check_ajax_referer( TWTAEO_Page_Local_Pack::NONCE_NAP, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$method   = sanitize_key( wp_unslash( $_POST['method'] ?? 'kg' ) );
		$settings = TWTAEO_Local_Pack::get_settings();
		$name     = $settings['business_name'] ?? '';

		if ( 'gbp' === $method ) {
			$external = TWTAEO_Local_Pack_NAP::check_gbp();
		} else {
			$kg_key  = sanitize_text_field( wp_unslash( $_POST['kg_key'] ?? $settings['google_kg_api_key'] ?? '' ) );
			$external = TWTAEO_Local_Pack_NAP::check_google_kg( $name, $kg_key );
		}

		if ( is_wp_error( $external ) ) {
			wp_send_json_error( $external->get_error_message() );
		}

		$stored = array(
			'name'    => $name,
			'phone'   => $settings['phone']   ?? '',
			'address' => implode( ', ', array_filter( array(
				$settings['street_address'] ?? '',
				$settings['city']           ?? '',
				$settings['state']          ?? '',
				$settings['zip']            ?? '',
			) ) ),
		);

		wp_send_json_success( array(
			'rows'   => TWTAEO_Local_Pack_NAP::compare( $stored, $external ),
			'source' => $external['source'],
		) );
	}

	public function ajax_local_pack_nap_bing() {
		check_ajax_referer( TWTAEO_Page_Local_Pack::NONCE_NAP, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$settings = TWTAEO_Local_Pack::get_settings();
		$name     = $settings['business_name'] ?? '';
		$bing_key = sanitize_text_field( wp_unslash( $_POST['bing_key'] ?? $settings['bing_api_key'] ?? '' ) );
		$lat      = $settings['latitude']  ?? '';
		$lng      = $settings['longitude'] ?? '';

		$external = TWTAEO_Local_Pack_NAP::check_bing( $name, $bing_key, $lat, $lng );

		if ( is_wp_error( $external ) ) {
			wp_send_json_error( $external->get_error_message() );
		}

		$stored = array(
			'name'    => $name,
			'phone'   => $settings['phone']   ?? '',
			'address' => implode( ', ', array_filter( array(
				$settings['street_address'] ?? '',
				$settings['city']           ?? '',
				$settings['state']          ?? '',
				$settings['zip']            ?? '',
			) ) ),
		);

		wp_send_json_success( array(
			'rows'   => TWTAEO_Local_Pack_NAP::compare( $stored, $external ),
			'source' => $external['source'],
		) );
	}

	// ── E-E-A-T AJAX ─────────────────────────────────────────────────────────

	public function ajax_create_eeat_page() {
		check_ajax_referer( 'twtaeo_eeat_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$title   = sanitize_text_field( wp_unslash( $_POST['page_title']   ?? '' ) );
		$slug    = sanitize_title( wp_unslash( $_POST['page_slug']          ?? '' ) );
		$content = wp_kses_post( wp_unslash( $_POST['page_content']         ?? '' ) );

		if ( ! $title ) {
			wp_send_json_error( 'Page title is required.' );
		}

		// Return edit URL if the page already exists.
		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $existing ) {
			wp_send_json_success( array( 'edit_url' => get_edit_post_link( $existing->ID, 'raw' ) ) );
		}

		$page_id = wp_insert_post( array(
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
			'post_status'  => 'draft',
			'post_type'    => 'page',
		) );

		if ( is_wp_error( $page_id ) ) {
			wp_send_json_error( $page_id->get_error_message() );
		}

		wp_send_json_success( array( 'edit_url' => get_edit_post_link( $page_id, 'raw' ) ) );
	}

	public function ajax_get_sameas() {
		check_ajax_referer( 'twtaeo_eeat_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$company = TWTAEO_Company_Profile::get();

		wp_send_json_success( array(
			'name'      => $company['name']             ?? '',
			'linkedin'  => $company['social_linkedin']  ?? '',
			'facebook'  => $company['social_facebook']  ?? '',
			'twitter'   => $company['social_twitter']   ?? '',
			'instagram' => $company['social_instagram'] ?? '',
			'youtube'   => $company['social_youtube']   ?? '',
			'google'    => '',
		) );
	}

	public function ajax_save_sameas() {
		check_ajax_referer( 'twtaeo_eeat_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$company = TWTAEO_Company_Profile::get();

		$company['social_linkedin']  = esc_url_raw( wp_unslash( $_POST['linkedin']  ?? '' ) );
		$company['social_facebook']  = esc_url_raw( wp_unslash( $_POST['facebook']  ?? '' ) );
		$company['social_twitter']   = esc_url_raw( wp_unslash( $_POST['twitter']   ?? '' ) );
		$company['social_instagram'] = esc_url_raw( wp_unslash( $_POST['instagram'] ?? '' ) );
		$company['social_youtube']   = esc_url_raw( wp_unslash( $_POST['youtube']   ?? '' ) );

		update_option( TWTAEO_Company_Profile::OPTION_KEY, $company );
		TWTAEO_EEAT_Detector::clear_cache();

		wp_send_json_success();
	}

	/**
	 * AJAX: Look up brand/entity candidates in the Google Knowledge Graph.
	 *
	 * Used by the sameAs editor to verify which entity Google recognises and to
	 * pull its authoritative URL + entity ID into Organization sameAs.
	 */
	public function ajax_kg_search() {
		check_ajax_referer( 'twtaeo_eeat_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'twt-aeo-ultimate' ) ) );
		}

		$query  = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
		$result = TWTAEO_Google_Knowledge_Graph::search( $query );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'entities' => $result ) );
	}

	/**
	 * AJAX: Ground an entity or topic name to authority sameAs URLs (Wikipedia /
	 * Wikidata / official site) for the Knowledge Graph admin page.
	 */
	public function ajax_kg_resolve_sameas() {
		check_ajax_referer( TWTAEO_KG_Entities::NONCE_AJAX, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'twt-aeo-ultimate' ) ) );
		}

		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$type = sanitize_text_field( wp_unslash( $_POST['type'] ?? 'thing' ) );

		$urls = TWTAEO_KG_Entities::resolve_same_as( $name, $type );
		if ( is_wp_error( $urls ) ) {
			wp_send_json_error( array( 'message' => $urls->get_error_message() ) );
		}

		wp_send_json_success( array( 'urls' => $urls ) );
	}

	public function ajax_get_author_meta() {
		check_ajax_referer( 'twtaeo_eeat_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$user_id = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			wp_send_json_error( 'Invalid user.' );
		}

		$data = TWTAEO_Author_Meta::get_author_data( $user_id );

		wp_send_json_success( array(
			'bio'              => $data['bio']              ?? '',
			'job_title'        => $data['job_title']        ?? '',
			'credentials'      => $data['credentials']      ?? '',
			'expertise'        => $data['expertise']        ?? '',
			'years_experience' => $data['years_experience'] ?? '',
		) );
	}

	public function ajax_save_author_meta() {
		check_ajax_referer( 'twtaeo_eeat_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$user_id = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			wp_send_json_error( 'Invalid user.' );
		}

		update_user_meta( $user_id, 'description',             sanitize_textarea_field( wp_unslash( $_POST['bio']              ?? '' ) ) );
		update_user_meta( $user_id, 'twtaeo_job_title',       sanitize_text_field(     wp_unslash( $_POST['job_title']        ?? '' ) ) );
		update_user_meta( $user_id, 'twtaeo_credentials',     sanitize_text_field(     wp_unslash( $_POST['credentials']      ?? '' ) ) );
		update_user_meta( $user_id, 'twtaeo_expertise',       sanitize_text_field(     wp_unslash( $_POST['expertise']        ?? '' ) ) );
		update_user_meta( $user_id, 'twtaeo_years_experience', absint( wp_unslash( $_POST['years_experience'] ?? 0 ) ) );

		TWTAEO_EEAT_Detector::clear_cache();

		wp_send_json_success();
	}

	// ── AI Meta Description ─────────────────────────────────────────────────────

	/** Generate (and save) an AI meta description for a single post. */
	public function ajax_ai_desc_generate() {
		check_ajax_referer( TWTAEO_AI_Description::NONCE, 'nonce' );

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}

		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$result   = TWTAEO_AI_Description::generate_for_post( $post_id, $provider ?: null );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		TWTAEO_AI_Description::save_description( $post_id, $result );
		wp_send_json_success( array( 'post_id' => $post_id, 'description' => $result ) );
	}

	/** Return the list of published posts/pages missing a description. */
	public function ajax_ai_desc_bulk() {
		check_ajax_referer( TWTAEO_AI_Description::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}

		// Count how many posts are missing a description, for the cost confirmation.
		$count = count( TWTAEO_AI_Description::get_posts_missing_description( 2000 ) );
		wp_send_json_success( array( 'count' => $count ) );
	}

	/** Start the background bulk job (runs server-side; survives navigation). */
	public function ajax_ai_desc_start() {
		check_ajax_referer( TWTAEO_AI_Description::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}

		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$mode     = isset( $_POST['mode'] ) && 'og' === $_POST['mode'] ? 'og' : 'meta';
		wp_send_json_success( TWTAEO_AI_Description::start_job( $provider ?: null, $mode ) );
	}

	/** Stop the running background bulk job (keeps what was already created). */
	public function ajax_ai_desc_stop() {
		check_ajax_referer( TWTAEO_AI_Description::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}

		wp_send_json_success( TWTAEO_AI_Description::stop_job() );
	}

	/** Generate (without saving) a social-friendly description for one post. */
	public function ajax_ai_desc_generate_social() {
		check_ajax_referer( TWTAEO_AI_Description::NONCE, 'nonce' );

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}

		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$result   = TWTAEO_AI_Description::generate_for_post( $post_id, $provider ?: null, 'social' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array( 'description' => $result ) );
	}

	/** Generate an og:image for one post with OpenAI and add it to the library. */
	public function ajax_ai_og_image() {
		check_ajax_referer( TWTAEO_AI_Description::NONCE, 'nonce' );

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( __( 'You do not have permission to add media.', 'twt-aeo-ultimate' ) );
		}

		$style     = isset( $_POST['style'] ) ? sanitize_key( wp_unslash( $_POST['style'] ) ) : 'clean';
		$modifiers = array(
			'title'       => ! empty( $_POST['use_title'] ),
			'description' => ! empty( $_POST['use_description'] ),
			'brand'       => ! empty( $_POST['use_brand'] ),
		);

		$result = TWTAEO_AI_Description::generate_og_image( $post_id, $style, $modifiers );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/** Set an existing attachment as a post's featured image (post thumbnail). */
	public function ajax_set_featured_image() {
		check_ajax_referer( TWTAEO_AI_Description::NONCE, 'nonce' );

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$att_id  = absint( wp_unslash( $_POST['attachment_id'] ?? 0 ) );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}
		if ( ! $att_id || 'attachment' !== get_post_type( $att_id ) || ! wp_attachment_is_image( $att_id ) ) {
			wp_send_json_error( __( 'That image could not be found.', 'twt-aeo-ultimate' ) );
		}

		if ( ! set_post_thumbnail( $post_id, $att_id ) ) {
			wp_send_json_error( __( 'Could not set the featured image.', 'twt-aeo-ultimate' ) );
		}

		wp_send_json_success();
	}

	/**
	 * Bulk-fill Open Graph descriptions from each post's existing meta
	 * description (no AI call). Free and instant.
	 */
	/**
	 * AJAX: scan one block for the site-wide Social Graph census.
	 *
	 * The browser calls this once per block (1..N); counts accumulate
	 * server-side in the census option, and the final block stamps
	 * completed_at. One block per request keeps each call safely inside
	 * PHP's time limit no matter how large the site is.
	 */
	public function ajax_og_census_block() {
		check_ajax_referer( 'twtaeo_social_graph_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}

		$block        = max( 1, absint( wp_unslash( $_POST['block'] ?? 1 ) ) );
		$total_items  = TWTAEO_OG_Detector::total_items();
		$total_blocks = max( 1, (int) ceil( $total_items / TWTAEO_OG_Detector::SCAN_BLOCK ) );
		$block        = min( $block, $total_blocks );

		$counts = TWTAEO_OG_Detector::census_block( $block );

		$empty_totals = array( 'scanned' => 0, 'complete' => 0, 'missing_image' => 0, 'missing_text' => 0, 'needs_work' => 0, 'tw_explicit' => 0 );
		$state        = ( 1 === $block ) ? array() : get_option( TWTAEO_OG_Detector::CENSUS_OPTION, array() );
		$totals       = ( is_array( $state ) && isset( $state['totals'] ) ) ? $state['totals'] : $empty_totals;
		foreach ( $counts as $k => $v ) {
			$totals[ $k ] = ( $totals[ $k ] ?? 0 ) + $v;
		}

		$state = array(
			'totals'       => $totals,
			'total_items'  => $total_items,
			'blocks_done'  => $block,
			'total_blocks' => $total_blocks,
			'started_at'   => ( 1 === $block || empty( $state['started_at'] ) ) ? time() : $state['started_at'],
			'completed_at' => ( $block >= $total_blocks ) ? time() : 0,
		);
		update_option( TWTAEO_OG_Detector::CENSUS_OPTION, $state, false );

		wp_send_json_success( $state );
	}

	public function ajax_og_fill_from_meta() {
		check_ajax_referer( 'twtaeo_social_graph_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}

		$filled  = 0;
		$skipped = 0;
		foreach ( TWTAEO_AI_Description::get_posts_missing_og( 2000 ) as $post_id ) {
			$meta = TWTAEO_AI_Description::get_existing_description( $post_id );
			if ( '' === $meta ) {
				$skipped++;
				continue;
			}
			TWTAEO_AI_Description::save_og_description( $post_id, $meta );
			$filled++;
		}

		wp_send_json_success( array( 'filled' => $filled, 'skipped' => $skipped ) );
	}

	/** Return the current background bulk job state for polling. */
	public function ajax_ai_desc_status() {
		check_ajax_referer( TWTAEO_AI_Description::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}

		// Drive a batch from the poll itself so the job progresses even when
		// WP-Cron is not firing (common on local / loopback-restricted sites).
		// The job's internal lock prevents overlap with the scheduled cron run.
		TWTAEO_AI_Description::run_job_batch();

		wp_send_json_success( TWTAEO_AI_Description::job_payload() );
	}

	/** Save the AI-description enable toggle and provider from the dashboard. */
	public function ajax_ai_desc_settings() {
		check_ajax_referer( TWTAEO_AI_Description::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'twt-aeo-ultimate' ) );
		}

		$settings = get_option( 'twtaeo_settings', array() );
		$settings[ TWTAEO_AI_Description::SETTING_ENABLED ] = ! empty( $_POST['enabled'] ) ? 1 : 0;

		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'claude';
		if ( ! in_array( $provider, array( 'claude', 'openai', 'gemini' ), true ) ) {
			$provider = 'claude';
		}
		$settings[ TWTAEO_AI_Description::SETTING_PROVIDER ] = $provider;

		update_option( 'twtaeo_settings', $settings );
		wp_send_json_success();
	}

	/** Clear all stored scan results. */
	public function ajax_index_clear() {
		check_ajax_referer( TWTAEO_Index_Status::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		TWTAEO_Index_Status::clear_results();
		wp_send_json_success();
	}

	/** Send de-indexed report to the pro dashboard. */
	public function ajax_index_send_to_pro() {
		check_ajax_referer( TWTAEO_Index_Status::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$items = TWTAEO_Index_Status::get_deindexed_for_transmission();
		if ( empty( $items ) ) {
			wp_send_json_error( 'No de-indexed URLs to send.' );
		}
		TWTAEO_Pro_Transmitter::send_deindex_report( $items );
		wp_send_json_success( array( 'count' => count( $items ) ) );
	}

	// ── Social Graph AJAX ─────────────────────────────────────────────────────

	public function ajax_save_social_graph() {
		check_ajax_referer( 'twtaeo_social_graph_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( 'Invalid post.' );
		}

		$og_saved = TWTAEO_OG_Writer::save( $post_id, array(
			'og_title'       => sanitize_text_field( wp_unslash( $_POST['og_title']       ?? '' ) ),
			'og_description' => sanitize_textarea_field( wp_unslash( $_POST['og_description'] ?? '' ) ),
			'og_type'        => sanitize_key( wp_unslash( $_POST['og_type'] ?? 'website' ) ),
			'og_image'       => esc_url_raw( wp_unslash( $_POST['og_image'] ?? '' ) ),
			'og_image_alt'   => sanitize_text_field( wp_unslash( $_POST['og_image_alt'] ?? '' ) ),
		) );

		$tw_saved = TWTAEO_Twitter_Writer::save( $post_id, array(
			'tw_card'    => sanitize_key( wp_unslash( $_POST['tw_card']    ?? 'summary_large_image' ) ),
			'tw_creator' => sanitize_text_field( wp_unslash( $_POST['tw_creator'] ?? '' ) ),
		) );

		if ( $og_saved && $tw_saved ) {
			wp_send_json_success( array( 'post_id' => $post_id ) );
		} else {
			wp_send_json_error( 'Failed to save.' );
		}
	}

	public function ajax_save_twitter_site() {
		check_ajax_referer( 'twtaeo_social_graph_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$handle = sanitize_text_field( wp_unslash( $_POST['handle'] ?? '' ) );
		TWTAEO_Twitter_Writer::save_site_handle( $handle );
		wp_send_json_success( array( 'handle' => $handle ) );
	}

	public function ajax_save_linkedin_company() {
		check_ajax_referer( 'twtaeo_social_graph_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$url     = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		$company = TWTAEO_Company_Profile::get();
		$company['social_linkedin'] = $url;
		TWTAEO_Company_Profile::save( $company );
		wp_send_json_success( array( 'url' => $url ) );
	}

	// ── IndexNow AJAX ─────────────────────────────────────────────────────────

	public function ajax_indexnow_verify_key() {
		check_ajax_referer( TWTAEO_Page_Index_Now::NONCE_AJAX, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		wp_send_json_success( TWTAEO_Index_Now::verify_key_file() );
	}

	public function ajax_indexnow_submit() {
		check_ajax_referer( TWTAEO_Page_Index_Now::NONCE_AJAX, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$raw  = sanitize_textarea_field( wp_unslash( $_POST['urls'] ?? '' ) );
		$urls = array_filter( array_map( 'esc_url_raw', preg_split( '/\s+/', $raw ) ) );
		if ( empty( $urls ) ) {
			wp_send_json_error( 'No valid URLs provided.' );
		}
		wp_send_json_success( TWTAEO_Index_Now::submit( array_values( $urls ) ) );
	}

	public function ajax_indexnow_submit_all() {
		check_ajax_referer( TWTAEO_Page_Index_Now::NONCE_AJAX, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$settings   = TWTAEO_Index_Now::get_settings();
		$post_types = ! empty( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post', 'page' );

		$posts = get_posts( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'fields'         => 'ids',
		) );

		$urls = array_filter( array_map( 'get_permalink', $posts ) );
		if ( empty( $urls ) ) {
			wp_send_json_error( 'No published posts found.' );
		}
		wp_send_json_success( TWTAEO_Index_Now::submit( array_values( $urls ) ) );
	}

	// ── Google Merchant Center ────────────────────────────────────────────────

	public function ajax_gmc_save_settings() {
		check_ajax_referer( 'twtaeo_gmc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$client_id     = sanitize_text_field( wp_unslash( $_POST['gmc_client_id'] ?? '' ) );
		$client_secret = sanitize_text_field( wp_unslash( $_POST['gmc_client_secret'] ?? '' ) );
		$merchant_id   = sanitize_text_field( wp_unslash( $_POST['gmc_merchant_id'] ?? '' ) );

		if ( $client_id ) {
			TWTAEO_Google_Merchant_Center::save_credentials( $client_id, $client_secret );
		}
		if ( $merchant_id ) {
			TWTAEO_Google_Merchant_Center::save_config( $merchant_id );
		}

		wp_send_json_success( array( 'message' => 'Settings saved.' ) );
	}

	public function ajax_gmc_sync() {
		check_ajax_referer( 'twtaeo_gmc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$this->start_merchant_sync( 'gmc', 'TWTAEO_GMC_Sync_Engine' );
	}

	/**
	 * Start a merchant sync as a queued background run; only when Action
	 * Scheduler is missing does it fall back to the old single-request sync.
	 */
	private function start_merchant_sync( $provider, $engine ) {
		$state = TWTAEO_Merchant_Sync_Queue::start( $provider );

		if ( is_wp_error( $state ) ) {
			if ( 'no_scheduler' === $state->get_error_code() ) {
				$result = $engine::run_sync();
				if ( is_wp_error( $result ) ) {
					wp_send_json_error( $result->get_error_message() );
				}
				wp_send_json_success( array( 'queued' => false, 'summary' => $result ) );
			}
			wp_send_json_error( $state->get_error_message() );
		}

		wp_send_json_success( array( 'queued' => true, 'state' => $state ) );
	}

	/**
	 * Progress of a queued merchant sync, polled by the admin UI.
	 */
	public function ajax_msync_status() {
		$provider = ( isset( $_POST['provider'] ) && 'bmc' === $_POST['provider'] ) ? 'bmc' : 'gmc';
		check_ajax_referer( 'bmc' === $provider ? 'twtaeo_bmc_nonce' : 'twtaeo_gmc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$state            = TWTAEO_Merchant_Sync_Queue::get_state( $provider );
		$state['running'] = TWTAEO_Merchant_Sync_Queue::is_running( $state );
		wp_send_json_success( $state );
	}

	public function ajax_gmc_disconnect() {
		check_ajax_referer( 'twtaeo_gmc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		TWTAEO_Google_Merchant_Center::disconnect();
		wp_send_json_success();
	}

	// ── Bing Merchant Center ──────────────────────────────────────────────────

	public function ajax_bmc_save_settings() {
		check_ajax_referer( 'twtaeo_bmc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$client_id       = sanitize_text_field( wp_unslash( $_POST['bmc_client_id'] ?? '' ) );
		$client_secret   = sanitize_text_field( wp_unslash( $_POST['bmc_client_secret'] ?? '' ) );
		$developer_token = sanitize_text_field( wp_unslash( $_POST['bmc_developer_token'] ?? '' ) );
		$store_id        = sanitize_text_field( wp_unslash( $_POST['bmc_store_id'] ?? '' ) );

		if ( $client_id ) {
			TWTAEO_Bing_Merchant_Center::save_credentials( $client_id, $client_secret, $developer_token );
		} elseif ( $developer_token ) {
			$creds = TWTAEO_Bing_Merchant_Center::get_credentials();
			TWTAEO_Bing_Merchant_Center::save_credentials(
				$creds['client_id'] ?? '',
				$creds['client_secret'] ?? '',
				$developer_token
			);
		}

		if ( $store_id ) {
			TWTAEO_Bing_Merchant_Center::save_config( $store_id );
		}

		wp_send_json_success( array( 'message' => 'Settings saved.' ) );
	}

	public function ajax_bmc_sync() {
		check_ajax_referer( 'twtaeo_bmc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$this->start_merchant_sync( 'bmc', 'TWTAEO_BMC_Sync_Engine' );
	}

	public function ajax_bmc_disconnect() {
		check_ajax_referer( 'twtaeo_bmc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		TWTAEO_Bing_Merchant_Center::disconnect();
		wp_send_json_success();
	}

	/**
	 * AJAX: one-click Product schema from the Dashboard's missing-schema tag.
	 *
	 * The dashboard's schema modal is a form, and a Product node is not
	 * formable by hand — offers, identifiers, shipping and returns all come
	 * from the product itself. This reuses the WooCommerce tab's generator so
	 * a product on the dashboard is never a dead end.
	 */
	public function ajax_dashboard_generate_product() {
		check_ajax_referer( TWTAEO_Custom_Schema_Writer::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || 'product' !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'This is not a WooCommerce product.', 'twt-aeo-ultimate' ) ) );
		}
		if ( ! TWTAEO_WooCommerce_Detector::is_woocommerce_active() ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce is not active.', 'twt-aeo-ultimate' ) ) );
		}

		$item        = TWTAEO_WooCommerce_Detector::scan( $post );
		$recommended = $item['recommended_schema'] ?? 'Product';

		$schema = self::build_wc_auto_schema( $post, $recommended );
		if ( ! $schema ) {
			wp_send_json_error( array( 'message' => __( 'Could not build schema for this product.', 'twt-aeo-ultimate' ) ) );
		}

		$json   = wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$result = TWTAEO_Custom_Schema_Writer::save( $post_id, $recommended, $json );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'post_id' => $post_id, 'type' => $recommended ) );
	}

	public function ajax_generate_product_faqs_php() {
		check_ajax_referer( 'twtaeo_product_faqs_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Missing product ID.' ) );
		}

		$faqs = TWTAEO_Product_FAQ_Generator::generate_php_faqs( $post_id );
		wp_send_json_success( $faqs );
	}

	public function ajax_generate_product_faqs_ai() {
		check_ajax_referer( 'twtaeo_product_faqs_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Missing product ID.' ) );
		}

		$faqs = TWTAEO_Product_FAQ_Generator::generate_ai_faqs( $post_id );
		if ( is_wp_error( $faqs ) ) {
			wp_send_json_error( array( 'message' => $faqs->get_error_message() ) );
		}

		wp_send_json_success( $faqs );
	}
}