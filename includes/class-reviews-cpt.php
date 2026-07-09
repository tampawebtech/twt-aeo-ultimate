<?php
/**
 * Reviews CPT
 *
 * Registers the twtaeo_review custom post type and handles all storage,
 * retrieval, and frontend form submission.
 *
 * Storage:
 *   post_title   = reviewer name
 *   post_content = review text
 *   post_status  = draft (pending approval) | publish (approved)
 *
 * Meta keys:
 *   _twtaeo_review_rating   — integer 1–5
 *   _twtaeo_review_email    — reviewer email (not displayed publicly)
 *   _twtaeo_review_source   — 'internal' | 'gbp' | 'trustpilot' | 'yelp'
 *   _twtaeo_review_post_id  — associated post/product ID (0 = site-level)
 *   _twtaeo_review_context  — 'local' | 'product' | 'service' | 'general'
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Reviews_CPT {

	const POST_TYPE    = 'twtaeo_review';
	const META_RATING  = '_twtaeo_review_rating';
	const META_EMAIL   = '_twtaeo_review_email';
	const META_SOURCE  = '_twtaeo_review_source';
	const META_POST_ID = '_twtaeo_review_post_id';
	const META_CONTEXT = '_twtaeo_review_context';
	const NONCE_SUBMIT = 'twtaeo_review_submit';

	// One-time flag: legacy "twt_" → "twtaeo_" prefix migration has run.
	const MIGRATED_OPTION = 'twtaeo_prefix_migrated';

	// ── Hook registration ─────────────────────────────────────────────────────

	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_legacy_prefix' ) );
	}

	// ── Legacy prefix migration ───────────────────────────────────────────────

	/**
	 * One-time rename of the data that used the old 3-character "twt_" prefix to
	 * the plugin's "twtaeo_" prefix: the reviews post type and all persisted
	 * post-meta keys (reviews + Google/Bing merchant-sync snapshots). Runs once,
	 * guarded by an option flag, so existing installs keep their data after the
	 * prefix change. Idempotent — finds zero rows on fresh installs.
	 */
	public static function maybe_migrate_legacy_prefix() {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}

		global $wpdb;

		// Reviews post type: twt_review → twtaeo_review.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time data migration; no cache to invalidate.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_type' => self::POST_TYPE ),
			array( 'post_type' => 'twt_review' )
		);

		// Post-meta keys: _twt_* → _twtaeo_*.
		$meta_map = array(
			'_twt_review_rating'  => self::META_RATING,
			'_twt_review_email'   => self::META_EMAIL,
			'_twt_review_source'  => self::META_SOURCE,
			'_twt_review_post_id' => self::META_POST_ID,
			'_twt_review_context' => self::META_CONTEXT,
			'_twt_bmc_sync'       => '_twtaeo_bmc_sync',
			'_twt_gmc_sync'       => '_twtaeo_gmc_sync',
		);
		foreach ( $meta_map as $old_key => $new_key ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-time meta_key rename migration; no cache to invalidate.
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_key' => $new_key ),
				array( 'meta_key' => $old_key )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}

		update_option( self::MIGRATED_OPTION, TWTAEO_VERSION, false );
	}

	// ── Post type ─────────────────────────────────────────────────────────────

	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Reviews', 'twt-aeo-ultimate' ),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'supports'            => array( 'title', 'editor' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	// ── Storage helpers ───────────────────────────────────────────────────────

	/**
	 * Get approved reviews, optionally scoped to a post ID.
	 *
	 * @param int $post_id  0 = site-level reviews only, -1 = all
	 * @param int $limit
	 * @return WP_Post[]
	 */
	public static function get_approved( $post_id = -1, $limit = -1 ) {
		$args = array(
			'post_type'              => self::POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		);

		if ( $post_id >= 0 ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::META_POST_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'value'   => $post_id,
					'type'    => 'NUMERIC',
					'compare' => '=',
				),
			);
		}

		return get_posts( $args );
	}

	/**
	 * Get all reviews (any status) for the admin table.
	 *
	 * @param string $status  'any' | 'publish' | 'draft'
	 * @return WP_Post[]
	 */
	public static function get_all( $status = 'any' ) {
		return get_posts( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => $status,
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );
	}

	/**
	 * Calculate aggregate rating from approved reviews.
	 *
	 * @param int $post_id  0 = site-level, -1 = all
	 * @return array|null  { count, average } or null if no reviews
	 */
	public static function get_aggregate( $post_id = -1 ) {
		$reviews = self::get_approved( $post_id );
		if ( empty( $reviews ) ) {
			return null;
		}

		$total = 0;
		foreach ( $reviews as $review ) {
			$rating = (int) get_post_meta( $review->ID, self::META_RATING, true );
			$total += max( 1, min( 5, $rating ) );
		}

		$count = count( $reviews );
		return array(
			'count'   => $count,
			'average' => round( $total / $count, 1 ),
		);
	}

	/**
	 * Get WooCommerce product aggregate rating from WC's own review comments.
	 *
	 * @param int $post_id
	 * @return array|null
	 */
	public static function get_wc_aggregate( $post_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$comments = get_comments( array(
			'post_id' => $post_id,
			'status'  => 'approve',
			'type'    => 'review',
		) );

		if ( empty( $comments ) ) {
			return null;
		}

		$total = 0;
		$count = 0;
		foreach ( $comments as $comment ) {
			$rating = (int) get_comment_meta( $comment->comment_ID, 'rating', true );
			if ( $rating >= 1 && $rating <= 5 ) {
				$total += $rating;
				$count++;
			}
		}

		if ( ! $count ) {
			return null;
		}

		return array(
			'count'    => $count,
			'average'  => round( $total / $count, 1 ),
			'comments' => $comments,
		);
	}

	/**
	 * Insert a new review.
	 *
	 * @param array $data
	 * @return int|WP_Error  Post ID on success.
	 */
	public static function insert( array $data ) {
		$settings  = TWTAEO_Reviews_Schema_Writer::get_settings();
		$status    = ! empty( $settings['auto_approve'] ) ? 'publish' : 'draft';

		$post_id = wp_insert_post( array(
			'post_type'    => self::POST_TYPE,
			'post_title'   => sanitize_text_field( $data['name'] ?? '' ),
			'post_content' => sanitize_textarea_field( $data['content'] ?? '' ),
			'post_status'  => $status,
			'post_date'    => current_time( 'mysql' ),
		) );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_RATING,  max( 1, min( 5, (int) ( $data['rating']  ?? 5 ) ) ) );
		update_post_meta( $post_id, self::META_EMAIL,   sanitize_email( $data['email']   ?? '' ) );
		update_post_meta( $post_id, self::META_SOURCE,  sanitize_key(   $data['source']  ?? 'internal' ) );
		update_post_meta( $post_id, self::META_POST_ID, (int)           ( $data['post_id'] ?? 0 ) );
		update_post_meta( $post_id, self::META_CONTEXT, sanitize_key(   $data['context'] ?? 'general' ) );

		return $post_id;
	}

	// ── Frontend submit handler ───────────────────────────────────────────────

	public static function handle_submit() {
		check_ajax_referer( self::NONCE_SUBMIT, 'nonce' );

		$name    = sanitize_text_field( wp_unslash( $_POST['reviewer_name']    ?? '' ) );
		$email   = sanitize_email(      wp_unslash( $_POST['reviewer_email']   ?? '' ) );
		$rating  = max( 1, min( 5, absint( wp_unslash( $_POST['rating'] ?? 5 ) ) ) );
		$content = sanitize_textarea_field( wp_unslash( $_POST['review_content'] ?? '' ) );
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );

		if ( ! $name || ! $content ) {
			wp_send_json_error( __( 'Name and review are required.', 'twt-aeo-ultimate' ) );
		}

		$settings = TWTAEO_Reviews_Schema_Writer::get_settings();
		if ( ! empty( $settings['require_email'] ) && ! $email ) {
			wp_send_json_error( __( 'Email address is required.', 'twt-aeo-ultimate' ) );
		}
		if ( $email && ! is_email( $email ) ) {
			wp_send_json_error( __( 'Invalid email address.', 'twt-aeo-ultimate' ) );
		}

		$review_id = self::insert( array(
			'name'    => $name,
			'email'   => $email,
			'rating'  => $rating,
			'content' => $content,
			'post_id' => $post_id,
			'source'  => 'internal',
			'context' => TWTAEO_Reviews_Schema_Writer::detect_context(),
		) );

		if ( is_wp_error( $review_id ) ) {
			wp_send_json_error( __( 'Failed to submit review. Please try again.', 'twt-aeo-ultimate' ) );
		}

		$status  = get_post_status( $review_id );
		$message = ( 'publish' === $status )
			? __( 'Thank you for your review!', 'twt-aeo-ultimate' )
			: __( 'Thank you! Your review is pending approval.', 'twt-aeo-ultimate' );

		wp_send_json_success( array( 'message' => $message ) );
	}
}
