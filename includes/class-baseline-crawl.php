<?php
/**
 * Historical & Baseline Crawl
 *
 * On first activation, runs an efficient batched background process that:
 *   1. Maps the site size (total scannable posts/pages, by type).
 *   2. Ranks pages by traffic and caps the tracked set to the top N (default 50).
 *   3. Scans that tracked set in small batches via the existing Scan Store.
 *
 * The work is driven by a self-rescheduling single WP-Cron event so no single
 * request ever does more than one small batch. State lives in one option.
 *
 * Traffic source is chosen by availability, best first:
 *   GA4 screenPageViews → GSC clicks → comment/recency heuristic.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Baseline_Crawl {

	const OPTION_STATE = 'twtaeo_baseline_crawl';
	const CRON_HOOK    = 'twtaeo_baseline_crawl_tick';

	const DEFAULT_CAP  = 50;  // Max pages tracked/analyzed.
	const SCAN_BATCH   = 10;  // Pages scanned per cron tick.
	const TICK_DELAY   = 60;  // Seconds between ticks.
	const RANK_FETCH   = 200; // Rows pulled from analytics before resolving to posts.

	// ── Registration ─────────────────────────────────────────────────────────

	/**
	 * Wire the cron handler. Called from twtaeo_run().
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_tick' ) );
	}

	/**
	 * Kick off the crawl. Safe to call repeatedly — only starts when idle
	 * (or when $force re-runs a completed crawl, e.g. from a "Re-baseline" button).
	 *
	 * @param bool $force Restart even if a crawl already ran.
	 */
	public static function maybe_schedule( $force = false ) {
		$state = self::get_state();

		if ( ! $force && ! empty( $state['status'] ) && $state['status'] !== 'idle' ) {
			return; // Already running or complete.
		}

		// Clear any half-finished metrics capture from a prior run.
		if ( class_exists( 'TWTAEO_Baseline_Metrics' ) ) {
			TWTAEO_Baseline_Metrics::reset();
		}

		self::save_state( array(
			'status'         => 'mapping',
			'site_size'      => 0,
			'type_counts'    => array(),
			'traffic_source' => '',
			'queue'          => array(),
			'cursor'         => 0,
			'tracked'        => array(),
			'error'          => null,
			'started_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
			'completed_at'   => null,
		) );

		self::schedule_next( 5 );
	}

	/**
	 * Clear any pending cron event. Called from deactivation.
	 */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	private static function schedule_next( $delay = self::TICK_DELAY ) {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
		}
	}

	// ── State Machine ──────────────────────────────────────────────────────────

	/**
	 * One cron tick. Advances exactly one phase (or one scan batch) then
	 * reschedules itself until the crawl is complete.
	 */
	public static function run_tick() {
		$state = self::get_state();
		$status = $state['status'] ?? 'idle';

		switch ( $status ) {
			case 'mapping':
				self::do_mapping( $state );
				break;
			case 'ranking':
				self::do_ranking( $state );
				break;
			case 'scanning':
				self::do_scanning( $state );
				break;
			case 'baseline':
				self::do_baseline( $state );
				break;
			default:
				// idle / complete / unknown — nothing to do.
				return;
		}
	}

	/**
	 * Phase 1 — count the site.
	 */
	private static function do_mapping( $state ) {
		$type_counts = array();
		$total       = 0;

		foreach ( TWTAEO_Scan_Store::get_scannable_post_types() as $type ) {
			$q = new WP_Query( array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			) );
			$count               = (int) $q->found_posts;
			$type_counts[ $type ] = $count;
			$total              += $count;
		}

		$state['site_size']   = $total;
		$state['type_counts'] = $type_counts;
		$state['status']      = 'ranking';
		self::save_state( $state );

		self::schedule_next();
	}

	/**
	 * Phase 2 — pick the top-N pages by traffic.
	 */
	private static function do_ranking( $state ) {
		$cap = self::get_cap();
		list( $queue, $source ) = self::build_ranked_queue( $cap );

		$state['queue']          = $queue;
		$state['tracked']        = $queue; // Final tracked set is the ranked queue.
		$state['traffic_source'] = $source;
		$state['cursor']         = 0;
		$state['status']         = empty( $queue ) ? 'complete' : 'scanning';

		if ( $state['status'] === 'complete' ) {
			$state['completed_at'] = current_time( 'mysql' );
		}

		self::save_state( $state );

		if ( $state['status'] === 'scanning' ) {
			self::schedule_next();
		}
	}

	/**
	 * Phase 3 — scan the tracked set, SCAN_BATCH at a time.
	 */
	private static function do_scanning( $state ) {
		$queue  = $state['queue'] ?? array();
		$cursor = (int) ( $state['cursor'] ?? 0 );
		$batch  = array_slice( $queue, $cursor, self::SCAN_BATCH );

		foreach ( $batch as $post_id ) {
			$post = get_post( $post_id );
			if ( $post && $post->post_status === 'publish' ) {
				TWTAEO_Scan_Store::scan_and_store( $post );
			}
		}

		$cursor         += count( $batch );
		$state['cursor'] = $cursor;

		if ( $cursor >= count( $queue ) || empty( $batch ) ) {
			// Schema scan done. Capture the 90-day traffic baseline next, if Google
			// is connected; otherwise there is no history to pull, so finish.
			if ( class_exists( 'TWTAEO_Baseline_Metrics' ) && TWTAEO_Baseline_Metrics::is_available() ) {
				$state['status'] = 'baseline';
				self::save_state( $state );
				self::schedule_next();
			} else {
				$state['status']       = 'complete';
				$state['completed_at'] = current_time( 'mysql' );
				self::save_state( $state );
			}
			return;
		}

		self::save_state( $state );
		self::schedule_next();
	}

	/**
	 * Phase 4 — pull 90 days of GA4/GSC history, one batched step per tick. The
	 * tracked set gets a single local baseline snapshot; ranks beyond the cap
	 * (up to 200) are shipped to the Agency plugin and not stored locally.
	 */
	private static function do_baseline( $state ) {
		$done = true;
		if ( class_exists( 'TWTAEO_Baseline_Metrics' ) ) {
			$done = TWTAEO_Baseline_Metrics::run_batch( $state['tracked'] ?? array() );
		}

		if ( $done ) {
			$state['status']       = 'complete';
			$state['completed_at'] = current_time( 'mysql' );
			self::save_state( $state );
			return;
		}

		// More baseline steps remain — come back next tick.
		self::schedule_next();
	}

	// ── Ranking ─────────────────────────────────────────────────────────────────

	/**
	 * Build the ranked list of post IDs to track, capped at $cap.
	 *
	 * @param int $cap
	 * @return array { 0: int[] post IDs, 1: string source slug }
	 */
	private static function build_ranked_queue( $cap ) {
		$ids = self::rank_by_ga4( $cap );
		if ( ! empty( $ids ) ) {
			return array( $ids, 'ga4' );
		}

		$ids = self::rank_by_gsc( $cap );
		if ( ! empty( $ids ) ) {
			return array( $ids, 'gsc' );
		}

		return array( self::rank_by_heuristic( $cap ), 'heuristic' );
	}

	/**
	 * Top pages by GA4 pageviews (last 90 days). Empty array if GA4 unavailable.
	 */
	private static function rank_by_ga4( $cap ) {
		if ( ! class_exists( 'TWTAEO_Google_OAuth' ) || ! TWTAEO_Google_OAuth::is_connected() ) {
			return array();
		}

		$config = TWTAEO_Google_OAuth::get_config();
		if ( empty( $config['ga4_property_id'] ) ) {
			return array();
		}

		$report = TWTAEO_Google_OAuth::ga4_run_report( $config['ga4_property_id'], array(
			'dateRanges' => array( array( 'startDate' => '90daysAgo', 'endDate' => 'yesterday' ) ),
			'dimensions' => array( array( 'name' => 'pagePath' ) ),
			'metrics'    => array( array( 'name' => 'screenPageViews' ) ),
			'orderBys'   => array( array( 'metric' => array( 'metricName' => 'screenPageViews' ), 'desc' => true ) ),
			'limit'      => self::RANK_FETCH,
		) );

		if ( is_wp_error( $report ) ) {
			return array();
		}

		$paths = array();
		foreach ( $report['rows'] ?? array() as $row ) {
			$path = $row['dimensionValues'][0]['value'] ?? '';
			if ( $path !== '' ) {
				$paths[] = $path;
			}
		}

		return self::resolve_paths_to_ids( $paths, $cap );
	}

	/**
	 * Top pages by GSC clicks (last 90 days). Empty array if GSC unavailable.
	 */
	private static function rank_by_gsc( $cap ) {
		if ( ! class_exists( 'TWTAEO_Google_OAuth' ) || ! TWTAEO_Google_OAuth::is_connected() ) {
			return array();
		}

		$config = TWTAEO_Google_OAuth::get_config();
		if ( empty( $config['gsc_site_url'] ) ) {
			return array();
		}

		$report = TWTAEO_Google_OAuth::gsc_search_analytics( $config['gsc_site_url'], array(
			'startDate'  => gmdate( 'Y-m-d', strtotime( '-90 days' ) ),
			'endDate'    => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
			'dimensions' => array( 'page' ),
			'rowLimit'   => self::RANK_FETCH,
		) );

		if ( is_wp_error( $report ) ) {
			return array();
		}

		$urls = array();
		foreach ( $report['rows'] ?? array() as $row ) {
			$url = $row['keys'][0] ?? '';
			if ( $url !== '' ) {
				$urls[] = $url;
			}
		}

		return self::resolve_paths_to_ids( $urls, $cap );
	}

	/**
	 * No analytics connected: rank by engagement proxy (comment count, then recency).
	 * This is the common day-one case immediately after install.
	 */
	private static function rank_by_heuristic( $cap ) {
		return get_posts( array(
			'post_type'      => TWTAEO_Scan_Store::get_scannable_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => $cap,
			'orderby'        => array( 'comment_count' => 'DESC', 'date' => 'DESC' ),
			'fields'         => 'ids',
		) );
	}

	/**
	 * Resolve a list of URLs or paths (highest-traffic first) to unique,
	 * published, scannable post IDs, preserving order and capping the count.
	 *
	 * @param string[] $paths URLs or site-relative paths.
	 * @param int      $cap
	 * @return int[]
	 */
	private static function resolve_paths_to_ids( $paths, $cap ) {
		$scannable = TWTAEO_Scan_Store::get_scannable_post_types();
		$ids       = array();

		foreach ( $paths as $path ) {
			if ( count( $ids ) >= $cap ) {
				break;
			}

			// Accept full URLs and site-relative paths alike.
			$url     = ( strpos( $path, 'http' ) === 0 ) ? $path : home_url( $path );
			$post_id = url_to_postid( $url );

			if ( ! $post_id || isset( $ids[ $post_id ] ) ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post || $post->post_status !== 'publish' ) {
				continue;
			}
			if ( ! in_array( $post->post_type, $scannable, true ) ) {
				continue;
			}

			$ids[ $post_id ] = true;
		}

		return array_map( 'intval', array_keys( $ids ) );
	}

	// ── Accessors ─────────────────────────────────────────────────────────────

	/**
	 * The page cap. Filterable so the paid tier (or a site owner) can raise it.
	 */
	public static function get_cap() {
		return (int) apply_filters( 'twtaeo_baseline_page_cap', self::DEFAULT_CAP );
	}

	public static function get_state() {
		$state = get_option( self::OPTION_STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	private static function save_state( $state ) {
		$state['updated_at'] = current_time( 'mysql' );
		update_option( self::OPTION_STATE, $state, false );
	}

	public static function is_complete() {
		$state = self::get_state();
		return ( $state['status'] ?? '' ) === 'complete';
	}

	public static function is_running() {
		$status = self::get_state()['status'] ?? 'idle';
		return in_array( $status, array( 'mapping', 'ranking', 'scanning', 'baseline' ), true );
	}

	/**
	 * The capped set of tracked post IDs. Empty until the crawl has ranked.
	 *
	 * @return int[]
	 */
	public static function get_tracked_ids() {
		$state = self::get_state();
		return array_map( 'intval', (array) ( $state['tracked'] ?? array() ) );
	}

	/**
	 * Whether a given post is within the tracked (top-N) set.
	 * Returns true if no baseline has run yet so nothing is silently dropped.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function is_tracked( $post_id ) {
		$tracked = self::get_tracked_ids();
		if ( empty( $tracked ) ) {
			return true;
		}
		return in_array( (int) $post_id, $tracked, true );
	}

	/**
	 * Progress summary for the admin UI.
	 *
	 * @return array
	 */
	public static function get_progress() {
		$state   = self::get_state();
		$status  = $state['status'] ?? 'idle';
		$queue   = (array) ( $state['queue'] ?? array() );
		$scanned = min( (int) ( $state['cursor'] ?? 0 ), count( $queue ) );

		return array(
			'status'         => $status,
			'site_size'      => (int) ( $state['site_size'] ?? 0 ),
			'type_counts'    => (array) ( $state['type_counts'] ?? array() ),
			'tracked_count'  => count( (array) ( $state['tracked'] ?? array() ) ),
			'cap'            => self::get_cap(),
			'scanned'        => $scanned,
			'traffic_source' => $state['traffic_source'] ?? '',
			'started_at'     => $state['started_at'] ?? null,
			'completed_at'   => $state['completed_at'] ?? null,
		);
	}
}
