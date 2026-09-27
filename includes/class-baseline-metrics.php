<?php
/**
 * Baseline Metrics Capture (batched)
 *
 * Pulls 90 days of historical GA4 + Search Console data and records a per-page
 * baseline. Driven by the baseline crawl's `baseline` phase, one small step per
 * cron tick so no request risks a server timeout.
 *
 * Storage policy (per project guidelines — minimal local DB, no long-term local
 * historical analytics):
 *   • Ranks 1–N (tracked set, default top 50): a SINGLE point-in-time baseline
 *     snapshot is stored locally in postmeta `_twtaeo_baseline_metrics`. It is a
 *     reference point, overwritten on each crawl — not an accumulating time series.
 *   • Ranks N+1 … 200: nothing is stored locally, keeping the site database
 *     lean. When the optional TWT Agency connection is enabled, the full record
 *     is transmitted there instead.
 *
 * Step machine (each step = one cron tick):
 *   fetch_ga4 → fetch_gsc → prepare → store (batched) → send (batched) → done
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Baseline_Metrics {

	const META_FULL    = '_twtaeo_baseline_metrics'; // Tracked set: single baseline snapshot.
	const OPTION_SITE  = 'twtaeo_baseline_metrics_summary';
	const OPTION_STATE = 'twtaeo_baseline_metrics_state';

	const MAX_PAGES     = 200; // Hard ceiling on pages captured (matches analytics row pull).
	const STORE_BATCH   = 25;  // Tracked pages written to postmeta per tick.
	const SEND_CHUNK    = 50;  // Overflow pages transmitted to Agency per tick.
	const LOOKBACK_DAYS = 90;

	// ── Availability ────────────────────────────────────────────────────────────

	/**
	 * A 90-day historical baseline requires GA4 and/or GSC.
	 */
	public static function is_available() {
		if ( ! class_exists( 'TWTAEO_Google_OAuth' ) || ! TWTAEO_Google_OAuth::is_connected() ) {
			return false;
		}
		$config = TWTAEO_Google_OAuth::get_config();
		return ! empty( $config['ga4_property_id'] ) || ! empty( $config['gsc_site_url'] );
	}

	// ── Batched runner ────────────────────────────────────────────────────────────

	/**
	 * Advance the capture by one step. Called repeatedly by the crawl.
	 *
	 * @param int[] $tracked_ids The monitored top-N post IDs (get the local snapshot).
	 * @return bool True when capture is complete; false when more work remains.
	 */
	public static function run_batch( $tracked_ids ) {
		$state = get_option( self::OPTION_STATE, array() );

		if ( empty( $state ) ) {
			update_option( self::OPTION_STATE, array(
				'step'    => 'fetch_ga4',
				'tracked' => array_values( array_map( 'intval', (array) $tracked_ids ) ),
				'period'  => self::period(),
				'ga4'     => array(),
				'gsc'     => array(),
				'cursor'  => 0,
			), false );
			return false;
		}

		switch ( $state['step'] ) {
			case 'fetch_ga4':
				return self::step_fetch_ga4( $state );
			case 'fetch_gsc':
				return self::step_fetch_gsc( $state );
			case 'prepare':
				return self::step_prepare( $state );
			case 'store':
				return self::step_store( $state );
			case 'send':
				return self::step_send( $state );
			default:
				delete_option( self::OPTION_STATE );
				return true;
		}
	}

	private static function step_fetch_ga4( $state ) {
		$config        = TWTAEO_Google_OAuth::get_config();
		$state['ga4']  = ! empty( $config['ga4_property_id'] ) ? self::fetch_ga4( $config['ga4_property_id'] ) : array();
		$state['step'] = 'fetch_gsc';
		update_option( self::OPTION_STATE, $state, false );
		return false;
	}

	private static function step_fetch_gsc( $state ) {
		$config        = TWTAEO_Google_OAuth::get_config();
		$state['gsc']  = ! empty( $config['gsc_site_url'] ) ? self::fetch_gsc( $config['gsc_site_url'] ) : array();
		$state['step'] = 'prepare';
		update_option( self::OPTION_STATE, $state, false );
		return false;
	}

	private static function step_prepare( $state ) {
		list( $by_post, $order ) = self::merge_sources( $state['ga4'], $state['gsc'] );

		$state['by_post']  = $by_post;
		$state['order']    = $order;
		$state['has_ga4']  = ! empty( $state['ga4'] );
		$state['has_gsc']  = ! empty( $state['gsc'] );
		unset( $state['ga4'], $state['gsc'] ); // Free the raw maps now they're merged.

		$state['step']   = 'store';
		$state['cursor'] = 0;
		update_option( self::OPTION_STATE, $state, false );
		return false;
	}

	private static function step_store( $state ) {
		$tracked = (array) $state['tracked'];
		$cursor  = (int) $state['cursor'];
		$slice   = array_slice( $tracked, $cursor, self::STORE_BATCH, true );

		foreach ( $slice as $i => $pid ) {
			self::store_full( $pid, $state['by_post'][ $pid ] ?? array(), $state['period'], $i + 1 );
		}

		$cursor += count( $slice );

		if ( $cursor >= count( $tracked ) ) {
			$state['step']   = 'send';
			$state['cursor'] = 0;
		} else {
			$state['cursor'] = $cursor;
		}

		update_option( self::OPTION_STATE, $state, false );
		return false;
	}

	private static function step_send( $state ) {
		$overflow = self::overflow_ids( $state );
		$cursor   = (int) $state['cursor'];
		$chunk    = array_slice( $overflow, $cursor, self::SEND_CHUNK, true );

		if ( ! empty( $chunk ) && class_exists( 'TWTAEO_Pro_Transmitter' ) && TWTAEO_Pro_Transmitter::is_connected() ) {
			$pages = array();
			foreach ( $chunk as $rank0 => $pid ) {
				$pages[] = self::agency_record( $pid, $state['by_post'][ $pid ] ?? array(), $state['period'], count( (array) $state['tracked'] ) + $rank0 + 1 );
			}
			TWTAEO_Pro_Transmitter::send_page_baseline( $pages, $state['period'] );
		}

		$cursor += count( $chunk );

		if ( $cursor >= count( $overflow ) || empty( $chunk ) ) {
			self::finalize( $state, count( $overflow ) );
			delete_option( self::OPTION_STATE );
			return true;
		}

		$state['cursor'] = $cursor;
		update_option( self::OPTION_STATE, $state, false );
		return false;
	}

	/**
	 * Overflow = traffic-ordered post IDs not in the tracked set, capped so the
	 * total captured (tracked + overflow) never exceeds MAX_PAGES.
	 */
	private static function overflow_ids( $state ) {
		$tracked_set = array_flip( (array) $state['tracked'] );
		$overflow    = array();

		foreach ( (array) $state['order'] as $pid ) {
			if ( ! isset( $tracked_set[ $pid ] ) ) {
				$overflow[] = $pid;
			}
		}

		$room = max( 0, self::MAX_PAGES - count( (array) $state['tracked'] ) );
		return array_slice( $overflow, 0, $room );
	}

	private static function finalize( $state, $overflow_count ) {
		update_option( self::OPTION_SITE, array(
			'captured_at'    => current_time( 'mysql' ),
			'period'         => $state['period'],
			'sources'        => array(
				'ga4' => ! empty( $state['has_ga4'] ),
				'gsc' => ! empty( $state['has_gsc'] ),
			),
			'tracked_count'  => count( (array) $state['tracked'] ),
			'overflow_count' => (int) $overflow_count,
			'overflow_sent'  => class_exists( 'TWTAEO_Pro_Transmitter' ) && TWTAEO_Pro_Transmitter::is_connected(),
		), false );
	}

	/**
	 * Discard any in-progress capture state (e.g. on re-baseline).
	 */
	public static function reset() {
		delete_option( self::OPTION_STATE );
	}

	// ── Fetching ──────────────────────────────────────────────────────────────────

	/**
	 * GA4: pageviews/sessions/users/engagement by page path, last 90 days.
	 *
	 * @return array normalized-path => metrics
	 */
	private static function fetch_ga4( $property_id ) {
		$report = TWTAEO_Google_OAuth::ga4_run_report( $property_id, array(
			'dateRanges' => array( array( 'startDate' => self::LOOKBACK_DAYS . 'daysAgo', 'endDate' => 'yesterday' ) ),
			'dimensions' => array( array( 'name' => 'pagePath' ) ),
			'metrics'    => array(
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'sessions' ),
				array( 'name' => 'totalUsers' ),
				array( 'name' => 'engagementRate' ),
				array( 'name' => 'userEngagementDuration' ),
			),
			'orderBys'   => array( array( 'metric' => array( 'metricName' => 'screenPageViews' ), 'desc' => true ) ),
			'limit'      => self::MAX_PAGES,
		) );

		if ( is_wp_error( $report ) ) {
			return array();
		}

		$names  = array_column( $report['metricHeaders'] ?? array(), 'name' );
		$result = array();

		foreach ( $report['rows'] ?? array() as $row ) {
			$path = self::norm_path( $row['dimensionValues'][0]['value'] ?? '' );
			if ( $path === '' ) {
				continue;
			}

			$v = array();
			foreach ( $names as $i => $name ) {
				$v[ $name ] = $row['metricValues'][ $i ]['value'] ?? 0;
			}

			$result[ $path ] = array(
				'pageviews'          => (int) ( $v['screenPageViews'] ?? 0 ),
				'sessions'           => (int) ( $v['sessions'] ?? 0 ),
				'users'              => (int) ( $v['totalUsers'] ?? 0 ),
				'engagement_rate'    => round( (float) ( $v['engagementRate'] ?? 0 ) * 100, 2 ),
				'engagement_seconds' => (int) ( $v['userEngagementDuration'] ?? 0 ),
			);
		}

		return $result;
	}

	/**
	 * GSC: clicks/impressions/ctr/position by page, last 90 days.
	 *
	 * @return array normalized-path => metrics
	 */
	private static function fetch_gsc( $site_url ) {
		$report = TWTAEO_Google_OAuth::gsc_search_analytics( $site_url, array(
			'startDate'  => gmdate( 'Y-m-d', strtotime( '-' . ( self::LOOKBACK_DAYS + 2 ) . ' days' ) ),
			'endDate'    => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
			'dimensions' => array( 'page' ),
			'rowLimit'   => self::MAX_PAGES,
		) );

		if ( is_wp_error( $report ) ) {
			return array();
		}

		$result = array();
		foreach ( $report['rows'] ?? array() as $row ) {
			$path = self::norm_path( $row['keys'][0] ?? '' );
			if ( $path === '' ) {
				continue;
			}

			$result[ $path ] = array(
				'clicks'      => (int) round( $row['clicks'] ?? 0 ),
				'impressions' => (int) round( $row['impressions'] ?? 0 ),
				'ctr'         => round( (float) ( $row['ctr'] ?? 0 ) * 100, 2 ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
			);
		}

		return $result;
	}

	// ── Merge ───────────────────────────────────────────────────────────────────

	/**
	 * Resolve GA4/GSC path maps onto post IDs and build a traffic-ordered list.
	 *
	 * @return array { 0: post_id => ['ga4'=>..,'gsc'=>..], 1: int[] ordered post IDs }
	 */
	private static function merge_sources( $ga4, $gsc ) {
		$scannable = TWTAEO_Scan_Store::get_scannable_post_types();
		$by_post   = array();
		$order     = array();

		$apply = function ( $map, $key ) use ( &$by_post, &$order, $scannable ) {
			foreach ( (array) $map as $path => $metrics ) {
				$pid = url_to_postid( home_url( $path ) );
				if ( ! $pid ) {
					continue;
				}
				$post = get_post( $pid );
				if ( ! $post || $post->post_status !== 'publish' || ! in_array( $post->post_type, $scannable, true ) ) {
					continue;
				}
				if ( ! isset( $by_post[ $pid ] ) ) {
					$by_post[ $pid ] = array();
					$order[]         = $pid;
				}
				$by_post[ $pid ][ $key ] = $metrics;
			}
		};

		$apply( $ga4, 'ga4' );
		$apply( $gsc, 'gsc' ); // GSC-only pages append after GA4-ranked ones.

		return array( $by_post, $order );
	}

	// ── Storage (tracked set only) ──────────────────────────────────────────────

	private static function store_full( $post_id, $metrics, $period, $rank ) {
		update_post_meta( $post_id, self::META_FULL, array(
			'ga4'         => $metrics['ga4'] ?? self::empty_ga4(),
			'gsc'         => $metrics['gsc'] ?? self::empty_gsc(),
			'rank'        => (int) $rank,
			'period'      => $period,
			'captured_at' => current_time( 'mysql' ),
		) );
	}

	// ── Agency transmission (overflow) ──────────────────────────────────────────

	private static function agency_record( $post_id, $metrics, $period, $rank ) {
		return array(
			'post_id'   => (int) $post_id,
			'url'       => get_permalink( $post_id ),
			'title'     => get_the_title( $post_id ),
			'post_type' => get_post_type( $post_id ),
			'rank'      => (int) $rank,
			'ga4'       => $metrics['ga4'] ?? self::empty_ga4(),
			'gsc'       => $metrics['gsc'] ?? self::empty_gsc(),
			'period'    => $period,
		);
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────

	private static function period() {
		return array(
			'days'  => self::LOOKBACK_DAYS,
			'start' => gmdate( 'Y-m-d', strtotime( '-' . self::LOOKBACK_DAYS . ' days' ) ),
			'end'   => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
		);
	}

	/**
	 * Normalize a URL or path to a lowercase, trailing-slash-trimmed path.
	 */
	private static function norm_path( $url_or_path ) {
		$path = ( strpos( $url_or_path, 'http' ) === 0 )
			? (string) wp_parse_url( $url_or_path, PHP_URL_PATH )
			: (string) $url_or_path;

		$path = strtolower( $path );
		if ( $path === '' ) {
			return '';
		}
		$path = '/' . ltrim( $path, '/' );
		return ( $path === '/' ) ? '/' : rtrim( $path, '/' );
	}

	private static function empty_ga4() {
		return array( 'pageviews' => 0, 'sessions' => 0, 'users' => 0, 'engagement_rate' => 0, 'engagement_seconds' => 0 );
	}

	private static function empty_gsc() {
		return array( 'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0 );
	}

	// ── Accessors ─────────────────────────────────────────────────────────────────

	/**
	 * Local baseline for a post — only the tracked set has one; beyond-cap
	 * ranks are not stored locally.
	 */
	public static function get_for_post( $post_id ) {
		$full = get_post_meta( $post_id, self::META_FULL, true );
		return ! empty( $full ) ? array( 'tier' => 'full', 'data' => $full ) : null;
	}

	public static function get_site_summary() {
		return get_option( self::OPTION_SITE, array() );
	}
}
