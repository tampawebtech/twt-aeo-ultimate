<?php
/**
 * Scan Store
 *
 * Handles storing and retrieving AEO scan results in postmeta.
 * Scans are triggered automatically on save_post.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Scan_Store {

	const META_INTENT      = '_twtaeo_intent';
	const META_SCHEMA      = '_twtaeo_schema';
	const META_EVALUATION  = '_twtaeo_evaluation';
	const META_LAST_SCAN   = '_twtaeo_last_scan';

	/**
	 * Register the save_post hook.
	 */
	public static function register_hooks() {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
	}

	/**
	 * Fires on save_post. Scans the post and stores results.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function on_save_post( $post_id, $post ) {
		// Skip autosaves, revisions, and non-public post types.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( wp_is_post_revision( $post_id ) )                  return;
		if ( ! in_array( $post->post_type, self::get_scannable_post_types(), true ) ) return;
		if ( $post->post_status === 'trash' )                   return;

		// Avoid infinite loops from meta saves.
		remove_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20 );

		self::scan_and_store( $post );

		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
	}

	/**
	 * Run a full scan on a post and store results.
	 *
	 * @param WP_Post|int $post
	 * @return array Evaluation result.
	 */
	public static function scan_and_store( $post ) {
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}

		if ( ! $post ) {
			return array();
		}

		$plugin     = $GLOBALS['twtaeo_plugin'] ?? null;
		$intent     = TWTAEO_Page_Intent::classify( $post );
		$evaluation = $plugin ? $plugin->evaluate_schema_expectations( $post ) : array();
		$schema     = $plugin ? $plugin->detect_schema_types( $post ) : array();

		$result = array(
			'intent'     => $intent,
			'evaluation' => $evaluation,
			'schema'     => $schema,
			'scanned_at' => current_time( 'mysql' ),
		);

		update_post_meta( $post->ID, self::META_INTENT,     $intent );
		update_post_meta( $post->ID, self::META_EVALUATION, $evaluation );
		update_post_meta( $post->ID, self::META_SCHEMA,     $schema );
		update_post_meta( $post->ID, self::META_LAST_SCAN,  current_time( 'mysql' ) );

		if ( class_exists( 'TWTAEO_Aeo_Score' ) ) {
			$result['aeo_score'] = TWTAEO_Aeo_Score::score_and_store( $post, $result );
		}

		return $result;
	}

	/**
	 * Get the stored scan result for a post.
	 *
	 * @param int $post_id
	 * @return array|null
	 */
	public static function get_scan( $post_id ) {
		$last_scan = get_post_meta( $post_id, self::META_LAST_SCAN, true );

		if ( ! $last_scan ) {
			return null;
		}

		return array(
			'intent'     => get_post_meta( $post_id, self::META_INTENT, true ),
			'evaluation' => get_post_meta( $post_id, self::META_EVALUATION, true ),
			'schema'     => get_post_meta( $post_id, self::META_SCHEMA, true ),
			'scanned_at' => $last_scan,
		);
	}

	/**
	 * Get all scanned pages/posts with their results.
	 *
	 * @return array
	 */
	public static function get_all_scans() {
		$posts = get_posts( array(
			'post_type'      => self::get_scannable_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::META_LAST_SCAN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'compare' => 'EXISTS',
				),
			),
		) );

		$results = array();

		foreach ( $posts as $post ) {
			$scan = self::get_scan( $post->ID );
			if ( $scan ) {
				$results[] = array(
					'post'  => $post,
					'scan'  => $scan,
				);
			}
		}

		return $results;
	}

	/**
	 * Get all published pages/posts regardless of scan status, with pagination.
	 *
	 * @param int $paged    Current page number (1-based).
	 * @param int $per_page Results per page.
	 * @return array
	 */
	public static function get_all_pages_with_status( $paged = 1, $per_page = 25 ) {
		$posts = get_posts( array(
			'post_type'      => self::get_scannable_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		$results = array();

		foreach ( $posts as $post ) {
			$scan = self::get_scan( $post->ID );
			$results[] = array(
				'post' => $post,
				'scan' => $scan,
			);
		}

		return $results;
	}

	/**
	 * Get the total number of published scannable posts (for pagination).
	 *
	 * @return int
	 */
	public static function get_total_post_count() {
		$query = new WP_Query( array(
			'post_type'      => self::get_scannable_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );

		return (int) $query->found_posts;
	}

	/**
	 * Count site-wide issues across all scanned pages.
	 *
	 * @return array { total, scanned, no_scan, missing_schema, intent_counts }
	 */
	public static function get_site_summary() {
		$total          = self::get_total_post_count();
		$scanned        = 0;
		$missing_schema = 0;
		$no_scan        = 0;
		$intent_counts  = array();

		// Walk all posts in batches to count scanned state without loading all into memory.
		$page = 1;
		do {
			$posts = get_posts( array(
				'post_type'      => self::get_scannable_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'paged'          => $page,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			) );

			foreach ( $posts as $post_id ) {
				$scan = self::get_scan( $post_id );

				if ( ! $scan ) {
					$no_scan++;
					continue;
				}

				$scanned++;
				$evaluation = $scan['evaluation'] ?? array();

				if ( ! empty( $evaluation['missing'] ) ) {
					$missing_schema++;
				}

				$intent_slug = $scan['intent']['intent'] ?? 'general';
				$intent_counts[ $intent_slug ] = ( $intent_counts[ $intent_slug ] ?? 0 ) + 1;
			}

			$page++;
		} while ( count( $posts ) === 100 );

		return array(
			'total'          => $total,
			'scanned'        => $scanned,
			'no_scan'        => $no_scan,
			'missing_schema' => $missing_schema,
			'intent_counts'  => $intent_counts,
		);
	}

	/**
	 * Post types we scan.
	 *
	 * @return array
	 */
	public static function get_scannable_post_types() {
		$types = array( 'page', 'post' );

		if ( post_type_exists( 'product' ) ) {
			$types[] = 'product';
		}

		return apply_filters( 'twtaeo_scannable_post_types', $types );
	}
}