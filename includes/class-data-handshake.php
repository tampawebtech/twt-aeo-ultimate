<?php
/**
 * Data Handshake & Purge
 *
 * Periodically bundles the client site's accumulated AEO data into a single
 * clean JSON payload, transmits it to the centralized Agency Hub over the
 * authenticated channel (API key via Key Resolver), and — only after the Hub
 * confirms receipt — purges the transmitted local logs to prevent site bloat.
 *
 * Bundle contents:
 *   • baseline             — 90-day GA4/GSC baseline for the tracked set
 *   • schema_deployments   — schema types + deployment timestamps per tracked page
 *   • performance          — post-deployment GA4 metrics: traffic, AI referrers,
 *                            bounce rate, key events
 *   • crawler_log          — AI bot hits accumulated since the last handshake
 *
 * The transmit→ack→purge cycle is the "handshake": nothing is deleted locally
 * until the Hub returns a success response for that exact bundle.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Data_Handshake {

	const CRON_HOOK  = 'twtaeo_data_handshake';
	const OPTION_LAST = 'twtaeo_last_handshake';
	const PERF_DAYS  = 30; // Post-deployment performance window.

	// ── Registration ─────────────────────────────────────────────────────────

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// ── Handshake cycle ────────────────────────────────────────────────────────

	/**
	 * Build → transmit → (on confirmed receipt) purge.
	 *
	 * @param bool $force Run even if last handshake was recent (manual trigger).
	 * @return array Result summary.
	 */
	public static function run( $force = false ) {
		if ( ! class_exists( 'TWTAEO_Pro_Transmitter' ) || ! TWTAEO_Pro_Transmitter::is_connected() ) {
			return array( 'status' => 'not_connected' );
		}

		// Boundary timestamp: only data captured at/before this point is eligible
		// for purge, so events arriving mid-transmission are never lost.
		$cutoff = time();
		$bundle = self::build_bundle( $cutoff );

		$ok = TWTAEO_Pro_Transmitter::send( 'data_bundle', $bundle );

		if ( ! $ok ) {
			update_option( self::OPTION_LAST, array(
				'status'      => 'failed',
				'attempted_at' => current_time( 'mysql' ),
			), false );
			return array( 'status' => 'failed' );
		}

		$purged = self::purge( $cutoff );

		$result = array(
			'status'        => 'ok',
			'transmitted_at' => current_time( 'mysql' ),
			'cutoff'        => gmdate( 'c', $cutoff ),
			'counts'        => array(
				'baseline_pages'     => count( $bundle['baseline']['pages'] ?? array() ),
				'schema_deployments' => count( $bundle['schema_deployments'] ?? array() ),
				'crawler_entries'    => count( $bundle['crawler_log'] ?? array() ),
				'crawler_purged'     => $purged['crawler'],
			),
		);
		update_option( self::OPTION_LAST, $result, false );

		return $result;
	}

	// ── Bundle assembly ──────────────────────────────────────────────────────────

	/**
	 * @param int $cutoff Unix timestamp boundary for log inclusion.
	 * @return array Clean JSON-serializable payload.
	 */
	private static function build_bundle( $cutoff ) {
		return array(
			'site_url'           => home_url(),
			'site_name'          => get_bloginfo( 'name' ),
			'plugin_version'     => TWTAEO_VERSION,
			'generated_at'       => current_time( 'mysql' ),
			'window_end'         => gmdate( 'c', $cutoff ),
			'baseline'           => self::collect_baseline(),
			'schema_deployments' => self::collect_schema_deployments(),
			'meta_inventory'     => self::collect_meta_inventory(),
			'performance'        => self::collect_performance(),
			'crawler_log'        => self::collect_crawler_log( $cutoff ),
		);
	}

	/**
	 * 90-day baseline for the tracked set (site summary + per-page).
	 */
	private static function collect_baseline() {
		$out = array(
			'summary' => class_exists( 'TWTAEO_Baseline_Metrics' ) ? TWTAEO_Baseline_Metrics::get_site_summary() : array(),
			'pages'   => array(),
		);

		if ( ! class_exists( 'TWTAEO_Baseline_Crawl' ) || ! class_exists( 'TWTAEO_Baseline_Metrics' ) ) {
			return $out;
		}

		foreach ( TWTAEO_Baseline_Crawl::get_tracked_ids() as $pid ) {
			$baseline = TWTAEO_Baseline_Metrics::get_for_post( $pid );
			if ( ! $baseline ) {
				continue;
			}
			$out['pages'][] = array(
				'post_id' => (int) $pid,
				'url'     => get_permalink( $pid ),
				'title'   => get_the_title( $pid ),
				'tier'    => $baseline['tier'],
				'data'    => $baseline['data'],
			);
		}

		return $out;
	}

	/**
	 * Schema types + deployment timestamps for the tracked pages.
	 */
	private static function collect_schema_deployments() {
		$out = array();

		if ( ! class_exists( 'TWTAEO_Baseline_Crawl' ) ) {
			return $out;
		}

		foreach ( TWTAEO_Baseline_Crawl::get_tracked_ids() as $pid ) {
			$schema      = get_post_meta( $pid, TWTAEO_Scan_Store::META_SCHEMA, true );
			$deployed    = get_post_meta( $pid, '_twtaeo_schema_deployed', true );
			$og_deployed = get_post_meta( $pid, '_twtaeo_og_deployed', true );

			if ( empty( $schema ) && empty( $deployed ) && empty( $og_deployed ) ) {
				continue;
			}

			$types    = is_array( $schema ) ? array_values( $schema ) : ( $schema ? array( $schema ) : array() );
			$deployed = is_array( $deployed ) ? $deployed : array();

			// Open Graph activation is logged the same way as schema — as its own
			// deployment type, so it flows into the Agency deployments record too.
			if ( $og_deployed ) {
				$deployed['OpenGraph'] = $og_deployed;
				if ( ! in_array( 'OpenGraph', $types, true ) ) {
					$types[] = 'OpenGraph';
				}
			}

			$out[] = array(
				'post_id'      => (int) $pid,
				'url'          => get_permalink( $pid ),
				'schema_types' => $types,
				'deployed_at'  => $deployed,
			);
		}

		return $out;
	}

	/**
	 * Lightweight live meta-tag inventory (schema types + OG tags) per tracked page.
	 */
	private static function collect_meta_inventory() {
		$out = array();

		if ( ! class_exists( 'TWTAEO_Baseline_Crawl' ) || ! class_exists( 'TWTAEO_Meta_Inventory' ) ) {
			return $out;
		}

		foreach ( TWTAEO_Baseline_Crawl::get_tracked_ids() as $pid ) {
			$inventory = TWTAEO_Meta_Inventory::scan( $pid );
			if ( empty( $inventory ) ) {
				continue;
			}
			$out[] = array_merge(
				array( 'post_id' => (int) $pid, 'url' => get_permalink( $pid ) ),
				$inventory
			);
		}

		return $out;
	}

	/**
	 * Post-deployment performance: GA4 traffic, AI referrers, bounce, key events.
	 */
	private static function collect_performance() {
		$perf = array(
			'window_days'  => self::PERF_DAYS,
			'available'    => false,
		);

		if ( ! class_exists( 'TWTAEO_Google_OAuth' ) || ! TWTAEO_Google_OAuth::is_connected() ) {
			return $perf;
		}

		$config = TWTAEO_Google_OAuth::get_config();
		if ( empty( $config['ga4_property_id'] ) ) {
			return $perf;
		}

		$pid   = $config['ga4_property_id'];
		$range = array( array( 'startDate' => self::PERF_DAYS . 'daysAgo', 'endDate' => 'yesterday' ) );

		// Totals — traffic, bounce, key events.
		$metrics = array(
			array( 'name' => 'sessions' ),
			array( 'name' => 'screenPageViews' ),
			array( 'name' => 'totalUsers' ),
			array( 'name' => 'bounceRate' ),
			array( 'name' => 'keyEvents' ),
		);
		$totals = TWTAEO_Google_OAuth::ga4_run_report( $pid, array(
			'dateRanges' => $range,
			'metrics'    => $metrics,
		) );

		// Older GA4 properties reject `keyEvents` and fail the whole query — retry
		// without it so traffic/bounce still come through (key_events falls back to 0).
		if ( is_wp_error( $totals ) ) {
			array_pop( $metrics );
			$totals = TWTAEO_Google_OAuth::ga4_run_report( $pid, array(
				'dateRanges' => $range,
				'metrics'    => $metrics,
			) );
		}

		if ( ! is_wp_error( $totals ) ) {
			$raw  = array();
			$rows = $totals['rows'] ?? array();
			if ( ! empty( $rows[0]['metricValues'] ) && ! empty( $totals['metricHeaders'] ) ) {
				foreach ( $totals['metricHeaders'] as $i => $header ) {
					$raw[ $header['name'] ] = $rows[0]['metricValues'][ $i ]['value'] ?? '0';
				}
			}
			$perf['available']   = true;
			$perf['sessions']    = (int) ( $raw['sessions'] ?? 0 );
			$perf['pageviews']   = (int) ( $raw['screenPageViews'] ?? 0 );
			$perf['users']       = (int) ( $raw['totalUsers'] ?? 0 );
			$perf['bounce_rate'] = round( (float) ( $raw['bounceRate'] ?? 0 ) * 100, 2 );
			$perf['key_events']  = (int) ( $raw['keyEvents'] ?? 0 );
		}

		// Referrers by category — sessions from known AI and social platforms.
		$by_source            = self::collect_referrers( $pid, $range );
		$perf['ai_referrers']             = $by_source['ai'];
		$perf['social_referrers']         = $by_source['social'];
		$perf['social_referral_sessions'] = array_sum( $by_source['social'] );

		return $perf;
	}

	private static function collect_referrers( $property_id, $range ) {
		$report = TWTAEO_Google_OAuth::ga4_run_report( $property_id, array(
			'dateRanges' => $range,
			'dimensions' => array( array( 'name' => 'sessionSource' ) ),
			'metrics'    => array( array( 'name' => 'sessions' ) ),
			'orderBys'   => array( array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ) ),
			'limit'      => 100,
		) );

		if ( is_wp_error( $report ) ) {
			return array( 'ai' => array(), 'social' => array() );
		}

		$ai_patterns     = array( 'claude.ai', 'perplexity.ai', 'chatgpt.com', 'openai.com', 'you.com', 'copilot.microsoft.com', 'bard.google.com', 'gemini.google.com' );
		$social_patterns = array( 'facebook.com', 'fb.com', 'instagram.com', 'l.instagram.com', 't.co', 'twitter.com', 'x.com', 'linkedin.com', 'lnkd.in', 'pinterest.com', 'youtube.com', 'reddit.com', 'tiktok.com', 'threads.net' );

		$ai     = array();
		$social = array();

		foreach ( $report['rows'] ?? array() as $row ) {
			$source   = strtolower( trim( $row['dimensionValues'][0]['value'] ?? '' ) );
			$sessions = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			if ( ! $sessions ) {
				continue;
			}
			foreach ( $ai_patterns as $pattern ) {
				if ( strpos( $source, $pattern ) !== false ) {
					$ai[ $source ] = $sessions;
					break;
				}
			}
			foreach ( $social_patterns as $pattern ) {
				if ( strpos( $source, $pattern ) !== false ) {
					$social[ $source ] = $sessions;
					break;
				}
			}
		}

		return array( 'ai' => $ai, 'social' => $social );
	}

	/**
	 * AI crawler hits captured at/before the cutoff.
	 */
	private static function collect_crawler_log( $cutoff ) {
		if ( ! class_exists( 'TWTAEO_AI_Crawler_Logger' ) ) {
			return array();
		}

		$log = get_option( TWTAEO_AI_Crawler_Logger::OPTION_LOG, array() );

		return array_values( array_filter( (array) $log, function ( $e ) use ( $cutoff ) {
			return isset( $e['time'] ) && $e['time'] <= $cutoff;
		} ) );
	}

	// ── Purge ──────────────────────────────────────────────────────────────────

	/**
	 * Remove transmitted local logs (entries at/before the cutoff). Entries that
	 * arrived during transmission (after the cutoff) are preserved for next time.
	 *
	 * @param int $cutoff
	 * @return array Purge counts.
	 */
	private static function purge( $cutoff ) {
		$purged = array( 'crawler' => 0 );

		if ( class_exists( 'TWTAEO_AI_Crawler_Logger' ) ) {
			$key = TWTAEO_AI_Crawler_Logger::OPTION_LOG;
			$log = (array) get_option( $key, array() );

			$remaining = array_values( array_filter( $log, function ( $e ) use ( $cutoff ) {
				return isset( $e['time'] ) && $e['time'] > $cutoff;
			} ) );

			$purged['crawler'] = count( $log ) - count( $remaining );
			update_option( $key, $remaining, false );
		}

		/**
		 * Lets other modules purge their own transmitted logs after a confirmed handshake.
		 *
		 * @param int $cutoff Unix timestamp boundary.
		 */
		do_action( 'twtaeo_handshake_purged', $cutoff );

		return $purged;
	}

	// ── Accessors ─────────────────────────────────────────────────────────────────

	public static function get_last_handshake() {
		return get_option( self::OPTION_LAST, array() );
	}
}
