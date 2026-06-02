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
		add_action( 'admin_menu', array( $this->admin_menu, 'register_menus' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_wizard' ) );
		add_action( 'admin_init', array( 'TWTAEO_Page_Command_Center', 'maybe_handle_post' ) );
		add_filter( 'admin_body_class', array( $this, 'wizard_body_class' ) );
		add_action( 'wp_ajax_twtaeo_wizard_autopilot', array( 'TWTAEO_Page_Setup_Wizard', 'ajax_autopilot' ) );
		add_action( 'admin_enqueue_scripts', array( 'TWTAEO_Admin_Assets', 'enqueue' ) );
		add_action( 'init', array( 'TWTAEO_Scan_Store', 'register_hooks' ) );
		add_action( 'init', array( 'TWTAEO_AI_Crawler_Logger', 'register_hooks' ) );

		// Register Service Schema Writer — outputs JSON-LD on frontend.
		add_action( 'init', array( 'TWTAEO_Service_Schema_Writer', 'register_hooks' ) );

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
		if ( $this->modules->is_active( 'woocommerce-detector' ) ) {
			add_action( 'init', array( 'TWTAEO_WooCommerce_Detector', 'register_hooks' ) );
		}

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

		add_action( 'wp_ajax_twtaeo_save_social_graph',    array( $this, 'ajax_save_social_graph' ) );
		add_action( 'wp_ajax_twtaeo_save_twitter_site',    array( $this, 'ajax_save_twitter_site' ) );
		add_action( 'wp_ajax_twtaeo_save_linkedin_company', array( $this, 'ajax_save_linkedin_company' ) );
		add_action( 'wp_ajax_twtaeo_save_og_data',         array( $this, 'ajax_save_og_data' ) );

		add_action( 'wp_ajax_twtaeo_indexnow_verify_key',  array( $this, 'ajax_indexnow_verify_key' ) );
		add_action( 'wp_ajax_twtaeo_indexnow_submit',      array( $this, 'ajax_indexnow_submit' ) );
		add_action( 'wp_ajax_twtaeo_indexnow_submit_all',  array( $this, 'ajax_indexnow_submit_all' ) );

		add_action( 'wp_ajax_twtaeo_local_pack_nap_google',  array( $this, 'ajax_local_pack_nap_google' ) );
		add_action( 'wp_ajax_twtaeo_local_pack_nap_bing',    array( $this, 'ajax_local_pack_nap_bing' ) );
		add_action( 'wp_ajax_twtaeo_local_pack_autofill',    array( $this, 'ajax_local_pack_autofill' ) );
		add_action( 'wp_ajax_twtaeo_inject_robots',          array( $this, 'ajax_inject_robots' ) );
		add_action( 'wp_ajax_twtaeo_toggle_module',         array( $this, 'ajax_toggle_module' ) );
		add_action( 'wp_ajax_twtaeo_scan_page',             array( $this, 'ajax_scan_page' ) );
		add_action( 'wp_ajax_twtaeo_scan_all',              array( $this, 'ajax_scan_all' ) );
		add_action( 'wp_ajax_twtaeo_get_service_prefill',   array( $this, 'ajax_get_service_prefill' ) );
		add_action( 'wp_ajax_twtaeo_save_service_schema',   array( $this, 'ajax_save_service_schema' ) );
		add_action( 'wp_ajax_twtaeo_delete_service_schema', array( $this, 'ajax_delete_service_schema' ) );
		add_action( 'wp_ajax_twtaeo_cc_disconnect',         array( $this, 'ajax_cc_disconnect' ) );
		// Hub CPT, shortcodes, and metabox.
		add_action( 'init', array( 'TWTAEO_Hub_CPT', 'register_hooks' ) );
		add_action( 'load-post.php',     array( 'TWTAEO_Hub_Metabox', 'register_hooks' ) );
		add_action( 'load-post-new.php', array( 'TWTAEO_Hub_Metabox', 'register_hooks' ) );

		add_action( 'wp_ajax_twtaeo_generate_content',      array( $this, 'ajax_generate_content' ) );
		add_action( 'wp_ajax_twtaeo_save_content_draft',    array( $this, 'ajax_save_content_draft' ) );
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
		add_action( 'wp_ajax_twtaeo_diagnose_server',       array( $this, 'ajax_diagnose_server' ) );
		add_action( 'wp_ajax_twtaeo_apply_apache_fix',      array( $this, 'ajax_apply_apache_fix' ) );
		add_action( 'wp_ajax_twtaeo_index_scan_url',        array( $this, 'ajax_index_scan_url' ) );
		add_action( 'wp_ajax_twtaeo_index_clear',           array( $this, 'ajax_index_clear' ) );
		add_action( 'wp_ajax_twtaeo_index_send_to_pro',     array( $this, 'ajax_index_send_to_pro' ) );
		add_action( 'wp_ajax_twtaeo_run_crawler_tests',       array( $this, 'ajax_run_crawler_tests' ) );
		add_action( 'wp_ajax_twtaeo_schema_conflict_scan',    array( $this, 'ajax_schema_conflict_scan' ) );
		add_action( 'wp_ajax_twtaeo_schema_conflict_suppress', array( $this, 'ajax_schema_conflict_suppress' ) );
		add_action( 'wp_ajax_twtaeo_seo_schema_delete',        array( $this, 'ajax_seo_schema_delete' ) );
		add_action( 'wp_ajax_twtaeo_faq_generate_one',   array( $this, 'ajax_faq_generate_one' ) );
		add_action( 'wp_ajax_twtaeo_faq_generate_batch', array( $this, 'ajax_faq_generate_batch' ) );

		add_action( 'wp_ajax_twtaeo_create_eeat_page',  array( $this, 'ajax_create_eeat_page' ) );
		add_action( 'wp_ajax_twtaeo_get_sameas',         array( $this, 'ajax_get_sameas' ) );
		add_action( 'wp_ajax_twtaeo_save_sameas',        array( $this, 'ajax_save_sameas' ) );
		add_action( 'wp_ajax_twtaeo_get_author_meta',    array( $this, 'ajax_get_author_meta' ) );
		add_action( 'wp_ajax_twtaeo_save_author_meta',   array( $this, 'ajax_save_author_meta' ) );

		add_action( 'wp_ajax_twtaeo_gmc_save_settings', array( $this, 'ajax_gmc_save_settings' ) );
		add_action( 'wp_ajax_twtaeo_gmc_sync',          array( $this, 'ajax_gmc_sync' ) );
		add_action( 'wp_ajax_twtaeo_gmc_disconnect',    array( $this, 'ajax_gmc_disconnect' ) );

		add_action( 'wp_ajax_twtaeo_bmc_save_settings', array( $this, 'ajax_bmc_save_settings' ) );
		add_action( 'wp_ajax_twtaeo_bmc_sync',          array( $this, 'ajax_bmc_sync' ) );
		add_action( 'wp_ajax_twtaeo_bmc_disconnect',    array( $this, 'ajax_bmc_disconnect' ) );

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

	public function ajax_toggle_module() {
		check_ajax_referer( 'twtaeo_modules_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$slug   = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
		$active = rest_sanitize_boolean( wp_unslash( $_POST['active'] ?? false ) );
		$this->modules->toggle( $slug, $active );
		wp_send_json_success( array( 'slug' => $slug, 'active' => $active ) );
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
		) );
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
	 * AJAX: Generate AI content — supports both existing posts and "create new" mode.
	 */
	public function ajax_generate_content() {
		check_ajax_referer( TWTAEO_Page_Content_Generator::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$industry = sanitize_key( wp_unslash( $_POST['industry'] ?? '' ) );
		$subtopic = sanitize_key( wp_unslash( $_POST['subtopic'] ?? '' ) );
		if ( ! $industry || ! $subtopic ) {
			wp_send_json_error( 'Missing required fields.' );
		}

		$post_id       = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$create_type   = '';
		$create_title  = '';
		$create_parent = 0;

		if ( $post_id ) {
			if ( ! get_post( $post_id ) ) {
				wp_send_json_error( 'Post not found.' );
			}
		} else {
			$allowed_create = array( 'post', 'page', 'twtaeo_hub', 'twtaeo_hub_sub' );
			$create_type    = sanitize_key( wp_unslash( $_POST['create_type']  ?? '' ) );
			$create_title   = sanitize_text_field( wp_unslash( $_POST['create_title'] ?? '' ) );
			$create_parent  = absint( wp_unslash( $_POST['create_parent'] ?? 0 ) );
			if ( ! in_array( $create_type, $allowed_create, true ) || ! $create_title ) {
				wp_send_json_error( 'Missing required fields.' );
			}
		}

		$allowed_semantic = array( 'voice-search', 'featured-snippet', 'comparison-table', 'misconceptions', 'local-trust' );
		$semantic_raw     = sanitize_text_field( wp_unslash( $_POST['semantic_elements'] ?? '' ) );
		$semantic_parts   = $semantic_raw ? explode( ',', $semantic_raw ) : array();
		$semantic_clean   = array();
		foreach ( $semantic_parts as $part ) {
			$part = sanitize_key( trim( $part ) );
			if ( in_array( $part, $allowed_semantic, true ) ) {
				$semantic_clean[] = $part;
			}
		}

		$options = array(
			'intent'            => sanitize_key( wp_unslash( $_POST['intent']        ?? '' ) ),
			'tone'              => sanitize_key( wp_unslash( $_POST['tone']          ?? '' ) ),
			'audience'          => sanitize_key( wp_unslash( $_POST['audience']      ?? '' ) ),
			'pov'               => sanitize_key( wp_unslash( $_POST['pov']          ?? '' ) ),
			'length'            => sanitize_key( wp_unslash( $_POST['length']        ?? '' ) ),
			'format'            => sanitize_key( wp_unslash( $_POST['format']        ?? '' ) ),
			'focus'             => sanitize_key( wp_unslash( $_POST['focus']         ?? '' ) ),
			'cta'               => sanitize_key( wp_unslash( $_POST['cta']           ?? '' ) ),
			'reading_level'     => sanitize_key( wp_unslash( $_POST['reading_level'] ?? '' ) ),
			'notes'             => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'semantic_elements' => $semantic_clean,
		);

		$results = TWTAEO_Content_Generator::generate( $post_id, $industry, $subtopic, $options, $create_title );

		if ( isset( $results['error'] ) ) {
			wp_send_json_error( $results['error'] );
		}

		if ( $create_type ) {
			$results['__meta'] = array(
				'is_new'        => true,
				'create_type'   => $create_type,
				'create_title'  => $create_title,
				'create_parent' => $create_parent,
			);
		}

		wp_send_json_success( $results );
	}

	/**
	 * AJAX: Save generated content as a new draft post, page, or hub.
	 */
	public function ajax_save_content_draft() {
		check_ajax_referer( TWTAEO_Page_Content_Generator::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$allowed_create = array( 'post', 'page', 'twtaeo_hub', 'twtaeo_hub_sub' );
		$create_type    = sanitize_key( wp_unslash( $_POST['create_type']  ?? '' ) );
		$create_title   = sanitize_text_field( wp_unslash( $_POST['create_title'] ?? '' ) );
		$post_content   = wp_kses_post( wp_unslash( $_POST['post_content'] ?? '' ) );
		$create_parent  = absint( wp_unslash( $_POST['create_parent'] ?? 0 ) );

		if ( ! in_array( $create_type, $allowed_create, true ) || ! $create_title ) {
			wp_send_json_error( 'Invalid request.' );
		}

		$post_type   = ( $create_type === 'twtaeo_hub_sub' ) ? 'twtaeo_hub' : $create_type;
		$post_parent = ( $create_type === 'twtaeo_hub_sub' && $create_parent ) ? $create_parent : 0;

		// Inject auto-linking shortcodes into the content.
		if ( $create_type === 'twtaeo_hub' ) {
			$post_content .= "\n\n[twtaeo_hub_nav]";
		} elseif ( $create_type === 'twtaeo_hub_sub' ) {
			$post_content = "[twtaeo_hub_breadcrumb]\n\n" . $post_content;
		}

		$post_id = wp_insert_post( array(
			'post_type'    => $post_type,
			'post_title'   => $create_title,
			'post_content' => $post_content,
			'post_status'  => 'draft',
			'post_parent'  => $post_parent,
		), true );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( $post_id->get_error_message() );
		}

		wp_send_json_success( array(
			'post_id'  => $post_id,
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
		) );
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

		ob_start();
		do_action( 'wp_head' );
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
			'/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',
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
		$json_string = sanitize_textarea_field( wp_unslash( $_POST['json'] ?? '' ) );
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

		wp_send_json_success( array(
			'name'          => $name,
			'description'   => $description,
			'price'         => (string) $price,
			'currency'      => $currency,
			'sku'           => $sku,
			'availability'  => $availability,
			'image_url'     => $image_url,
			'url'           => get_permalink( $post_id ),
			'site_name'     => get_bloginfo( 'name' ),
			'site_url'      => home_url(),
			'intent'        => $intent,
			'recommended'   => $recommended,
			'edit_url'      => $edit_url,
			'missing'       => $missing,
			'has_existing'  => (bool) $existing,
			'existing_json' => $existing
				? wp_json_encode( $existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
				: '',
		) );
	}

	/**
	 * AJAX: Auto-generate and save Product/Service schema for all products that need it.
	 */
	public function ajax_wc_generate_all() {
		check_ajax_referer( 'twtaeo_wc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$results = TWTAEO_WooCommerce_Detector::scan_all();
		$saved   = 0;
		$skipped = 0;
		$errors  = array();

		foreach ( $results as $item ) {
			$post        = $item['post'];
			$wc_data     = $item['wc_data'];
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

		wp_send_json_success( array(
			'saved'   => $saved,
			'skipped' => $skipped,
			'errors'  => $errors,
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
	private static function build_wc_auto_schema( WP_Post $post, $type ) {
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

		if ( $type === 'Product' ) {
			$price    = $wc_product ? (string) $wc_product->get_price() : '';
			$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
			$sku      = $wc_product ? $wc_product->get_sku() : '';
			$avail    = ( $wc_product && $wc_product->get_stock_status() !== 'instock' )
				? 'https://schema.org/OutOfStock'
				: 'https://schema.org/InStock';

			$schema = array(
				'@context'    => 'https://schema.org',
				'@type'       => 'Product',
				'name'        => $name,
				'url'         => $url,
			);
			if ( $description ) $schema['description'] = $description;
			if ( $image_url )   $schema['image']       = $image_url;
			if ( $sku )         $schema['sku']         = $sku;
			if ( $price !== '' ) {
				$schema['offers'] = array(
					'@type'         => 'Offer',
					'price'         => $price,
					'priceCurrency' => $currency,
					'availability'  => $avail,
					'url'           => $url,
				);
			}
			$schema['seller'] = array( '@type' => 'Organization', 'name' => $site_name, 'url' => $site_url );
			return $schema;
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

	// ── Open Graph AJAX (legacy) ──────────────────────────────────────────────

	public function ajax_save_og_data() {
		check_ajax_referer( 'twtaeo_og_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( 'Invalid post.' );
		}

		$saved = TWTAEO_OG_Writer::save( $post_id, array(
			'og_title'       => sanitize_text_field( wp_unslash( $_POST['og_title']       ?? '' ) ),
			'og_description' => sanitize_textarea_field( wp_unslash( $_POST['og_description'] ?? '' ) ),
			'og_type'        => sanitize_key( wp_unslash( $_POST['og_type'] ?? 'website' ) ),
			'og_image'       => esc_url_raw( wp_unslash( $_POST['og_image'] ?? '' ) ),
		) );

		if ( $saved ) {
			wp_send_json_success( array( 'post_id' => $post_id ) );
		} else {
			wp_send_json_error( 'Failed to save.' );
		}
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

		$result = TWTAEO_GMC_Sync_Engine::run_sync();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
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

		$result = TWTAEO_BMC_Sync_Engine::run_sync();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	public function ajax_bmc_disconnect() {
		check_ajax_referer( 'twtaeo_bmc_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		TWTAEO_Bing_Merchant_Center::disconnect();
		wp_send_json_success();
	}
}