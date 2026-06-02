<?php
/**
 * Remote Content Generation REST API
 *
 * Exposes three authenticated endpoints so a connected pro dashboard can
 * generate and save content on this client site without requiring a WP login.
 *
 * Authentication: X-TWT-API-Key header must match the stored pro_key and
 * pro_enabled must be true in twtaeo_settings.
 *
 * Routes:
 *   GET  /wp-json/twt-aeo/v1/remote/posts    — list pages/posts/hubs
 *   POST /wp-json/twt-aeo/v1/remote/generate — generate content
 *   POST /wp-json/twt-aeo/v1/remote/save     — save content as draft
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class TWTAEO_Rest_Remote_Generate {

	const ROUTE_NAMESPACE = 'twt-aeo/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( self::ROUTE_NAMESPACE, '/remote/posts', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_posts' ),
			'permission_callback' => array( __CLASS__, 'check_auth' ),
		) );

		register_rest_route( self::ROUTE_NAMESPACE, '/remote/generate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'generate_content' ),
			'permission_callback' => array( __CLASS__, 'check_auth' ),
		) );

		register_rest_route( self::ROUTE_NAMESPACE, '/remote/save', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'save_draft' ),
			'permission_callback' => array( __CLASS__, 'check_auth' ),
		) );
	}

	// ── Auth ─────────────────────────────────────────────────────────────────────

	public static function check_auth( WP_REST_Request $request ) {
		$settings = get_option( 'twtaeo_settings', array() );
		if ( empty( $settings['pro_enabled'] ) || empty( $settings['pro_key'] ) ) {
			return new WP_Error( 'pro_not_enabled', 'Pro connection is not enabled on this site.', array( 'status' => 403 ) );
		}
		$sent_key = $request->get_header( 'X-TWT-API-Key' );
		if ( ! hash_equals( (string) $settings['pro_key'], (string) $sent_key ) ) {
			return new WP_Error( 'invalid_api_key', 'Invalid API key.', array( 'status' => 403 ) );
		}
		return true;
	}

	// ── GET /remote/posts ─────────────────────────────────────────────────────────

	public static function get_posts( WP_REST_Request $request ) {
		$qargs  = array( 'post_status' => 'publish', 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' );
		$types  = array(
			'twtaeo_hub' => 'Hubs',
			'page'    => 'Pages',
			'post'    => 'Posts',
		);
		$groups = array();

		foreach ( $types as $type => $label ) {
			$posts = get_posts( array_merge( $qargs, array( 'post_type' => $type ) ) );
			if ( $posts ) {
				$items = array();
				foreach ( $posts as $p ) {
					$items[] = array( 'id' => $p->ID, 'title' => get_the_title( $p ) );
				}
				$groups[] = array( 'label' => $label, 'items' => $items );
			}
		}

		return rest_ensure_response( array(
			'site_name' => get_bloginfo( 'name' ),
			'groups'    => $groups,
		) );
	}

	// ── POST /remote/generate ─────────────────────────────────────────────────────

	public static function generate_content( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		$industry = sanitize_key( $body['industry'] ?? '' );
		$subtopic  = sanitize_key( $body['subtopic'] ?? '' );

		if ( ! $industry || ! $subtopic ) {
			return new WP_Error( 'missing_fields', 'industry and subtopic are required.', array( 'status' => 400 ) );
		}

		$post_id        = (int) ( $body['post_id'] ?? 0 );
		$title_override = sanitize_text_field( $body['title'] ?? '' );

		$allowed_semantic = array( 'voice-search', 'featured-snippet', 'comparison-table', 'misconceptions', 'local-trust' );
		$semantic_raw     = $body['semantic_elements'] ?? array();
		$semantic_clean   = array();
		foreach ( (array) $semantic_raw as $el ) {
			$el = sanitize_key( $el );
			if ( in_array( $el, $allowed_semantic, true ) ) {
				$semantic_clean[] = $el;
			}
		}

		$options = array(
			'intent'            => sanitize_key( $body['intent'] ?? '' ),
			'tone'              => sanitize_key( $body['tone'] ?? '' ),
			'audience'          => sanitize_key( $body['audience'] ?? '' ),
			'pov'               => sanitize_key( $body['pov'] ?? '' ),
			'length'            => sanitize_key( $body['length'] ?? '' ),
			'format'            => sanitize_key( $body['format'] ?? '' ),
			'focus'             => sanitize_key( $body['focus'] ?? '' ),
			'cta'               => sanitize_key( $body['cta'] ?? '' ),
			'reading_level'     => sanitize_key( $body['reading_level'] ?? '' ),
			'notes'             => sanitize_textarea_field( $body['notes'] ?? '' ),
			'semantic_elements' => $semantic_clean,
		);

		$results = TWTAEO_Content_Generator::generate( $post_id, $industry, $subtopic, $options, $title_override );

		if ( isset( $results['error'] ) ) {
			return new WP_Error( 'generation_error', $results['error'], array( 'status' => 500 ) );
		}

		return rest_ensure_response( $results );
	}

	// ── POST /remote/save ─────────────────────────────────────────────────────────

	public static function save_draft( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		$allowed_create = array( 'post', 'page', 'twtaeo_hub', 'twtaeo_hub_sub' );
		$create_type    = sanitize_key( $body['create_type'] ?? '' );
		$create_title   = sanitize_text_field( $body['create_title'] ?? '' );
		$post_content   = wp_kses_post( $body['post_content'] ?? '' );
		$create_parent  = (int) ( $body['create_parent'] ?? 0 );

		if ( ! in_array( $create_type, $allowed_create, true ) || ! $create_title ) {
			return new WP_Error( 'invalid_request', 'create_type and create_title are required.', array( 'status' => 400 ) );
		}

		$post_type   = ( $create_type === 'twtaeo_hub_sub' ) ? 'twtaeo_hub' : $create_type;
		$post_parent = ( $create_type === 'twtaeo_hub_sub' && $create_parent ) ? $create_parent : 0;

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
			return new WP_Error( 'insert_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array(
			'post_id'  => $post_id,
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
		) );
	}
}
