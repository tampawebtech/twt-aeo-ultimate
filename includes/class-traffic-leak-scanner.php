<?php
/**
 * Traffic Leak Scanner
 *
 * Finds pages that draw real traffic but bleed it through slow mobile loads:
 *
 *   1. Pull GA4 for the highest-traffic pages, keep those above a session
 *      threshold that also show a high bounce rate (the leak candidates).
 *   2. Run a mobile PageSpeed audit on each (one per AJAX request, so no
 *      single request risks a timeout — PageSpeed itself can take ~10–30s).
 *   3. Classify each as a traffic leak by severity (high traffic + high bounce
 *      + slow mobile score).
 *
 * State (candidate list + accumulated results) lives in a wp_option, polled by
 * the dashboard, so reopening the tab shows the last scan. The browser drives
 * the per-URL loop, mirroring the schema Background Scan already in this plugin.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Traffic_Leak_Scanner {

	const STATE_OPTION   = 'twtaeo_leak_scan_state';
	const MAX_CANDIDATES = 15;
	const CANDIDATE_BOUNCE_FLOOR = 0.55; // Only audit pages bouncing at least this much.

	// ── State ──────────────────────────────────────────────────────────────────

	public static function get_state() {
		return wp_parse_args( get_option( self::STATE_OPTION, array() ), array(
			'status'      => 'idle', // idle|scanning|done|failed
			'run_id'      => '',
			'error'       => '',
			'candidates'  => array(),
			'results'     => array(),
			'total'       => 0,
			'done'        => 0,
			'started_at'  => 0,
			'finished_at' => 0,
		) );
	}

	private static function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	public static function state_payload() {
		$s = self::get_state();
		return array(
			'status'  => $s['status'],
			'error'   => $s['error'],
			'total'   => (int) $s['total'],
			'done'    => (int) $s['done'],
			'results' => array_values( $s['results'] ),
		);
	}

	// ── Start: select candidates from GA4 ───────────────────────────────────────

	/**
	 * Build the candidate list from GA4 and prime the scan state.
	 *
	 * @param int $min_sessions Minimum sessions (last 28 days) to qualify.
	 * @return array|WP_Error    The new state payload, or why it couldn't start.
	 */
	public static function start( $min_sessions = 10 ) {
		if ( ! TWTAEO_Google_OAuth::is_connected() ) {
			return new WP_Error( 'not_connected', __( 'Connect your Google account in Settings first.', 'twt-aeo-ultimate' ) );
		}

		$config      = TWTAEO_Google_OAuth::get_config();
		$property_id = $config['ga4_property_id'] ?? '';
		if ( ! $property_id ) {
			return new WP_Error( 'no_property', __( 'Set your GA4 Property ID in Settings first.', 'twt-aeo-ultimate' ) );
		}

		$min_sessions = max( 1, (int) $min_sessions );
		$candidates   = self::fetch_candidates( $property_id, $min_sessions );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		if ( empty( $candidates ) ) {
			$state = self::get_state();
			$state['status']      = 'done';
			$state['candidates']  = array();
			$state['results']     = array();
			$state['total']       = 0;
			$state['done']        = 0;
			$state['finished_at'] = time();
			self::save_state( $state );
			return self::state_payload();
		}

		$state = array(
			'status'      => 'scanning',
			'run_id'      => md5( uniqid( 'leak', true ) ),
			'error'       => '',
			'candidates'  => $candidates,
			'results'     => array(),
			'total'       => count( $candidates ),
			'done'        => 0,
			'started_at'  => time(),
			'finished_at' => 0,
		);
		self::save_state( $state );

		return array_merge( self::state_payload(), array( 'run_id' => $state['run_id'] ) );
	}

	/**
	 * GA4: highest-traffic pages over the threshold that also bounce a lot.
	 *
	 * @param string $property_id
	 * @param int    $min_sessions
	 * @return array|WP_Error
	 */
	private static function fetch_candidates( $property_id, $min_sessions ) {
		$report = TWTAEO_Google_OAuth::ga4_run_report( $property_id, array(
			'dateRanges' => array( array( 'startDate' => '28daysAgo', 'endDate' => 'yesterday' ) ),
			'dimensions' => array( array( 'name' => 'pagePath' ) ),
			'metrics'    => array(
				array( 'name' => 'sessions' ),
				array( 'name' => 'bounceRate' ),
				array( 'name' => 'screenPageViews' ),
			),
			'orderBys'   => array( array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ) ),
			'limit'      => 50,
		) );

		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$candidates = array();
		foreach ( $report['rows'] ?? array() as $row ) {
			$path     = (string) ( $row['dimensionValues'][0]['value'] ?? '/' );
			$sessions = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			$bounce   = (float) ( $row['metricValues'][1]['value'] ?? 0 );

			if ( $sessions < $min_sessions || $bounce < self::CANDIDATE_BOUNCE_FLOOR ) {
				continue;
			}

			$candidates[] = array(
				'path'     => $path,
				'url'      => home_url( $path ),
				'sessions' => $sessions,
				'bounce'   => round( $bounce, 4 ),
			);

			if ( count( $candidates ) >= self::MAX_CANDIDATES ) {
				break;
			}
		}

		return $candidates;
	}

	// ── Scan one candidate (called per AJAX request) ────────────────────────────

	/**
	 * Audit one candidate URL with PageSpeed and record the verdict.
	 *
	 * @param string $run_id Run this call belongs to (guards against stale loops).
	 * @param int    $index  Candidate index to process.
	 * @return array|WP_Error
	 */
	public static function scan_one( $run_id, $index ) {
		$state = self::get_state();

		if ( $state['run_id'] !== $run_id || 'scanning' !== $state['status'] ) {
			return new WP_Error( 'stale', __( 'This scan is no longer active.', 'twt-aeo-ultimate' ) );
		}

		$index = (int) $index;
		if ( ! isset( $state['candidates'][ $index ] ) ) {
			return new WP_Error( 'bad_index', __( 'No such candidate.', 'twt-aeo-ultimate' ) );
		}

		$cand = $state['candidates'][ $index ];
		$psi  = TWTAEO_Google_PageSpeed::analyze( $cand['url'], 'mobile' );

		if ( is_wp_error( $psi ) ) {
			$score = null;
			$lcp   = '—';
		} else {
			$score = $psi['score'];
			$lcp   = $psi['lab']['lcp']['display'] ?? '—';
		}

		$verdict = self::classify( (int) $cand['sessions'], (float) $cand['bounce'], $score );

		$result = array(
			'path'      => $cand['path'],
			'url'       => $cand['url'],
			'sessions'  => (int) $cand['sessions'],
			'bounce'    => round( (float) $cand['bounce'] * 100, 1 ),
			'score'     => $score,
			'lcp'       => $lcp,
			'verdict'   => $verdict['label'],
			'severity'  => $verdict['severity'],
		);

		$state['results'][ $index ] = $result;
		$state['done']              = count( $state['results'] );

		if ( $state['done'] >= $state['total'] ) {
			$state['status']      = 'done';
			$state['finished_at'] = time();
		}
		self::save_state( $state );

		return array(
			'index'  => $index,
			'result' => $result,
			'done'   => (int) $state['done'],
			'total'  => (int) $state['total'],
			'status' => $state['status'],
		);
	}

	/**
	 * Severity of a page as a traffic leak: high traffic is already established
	 * by the candidate filter, so this weighs bounce against mobile speed.
	 *
	 * @param int        $sessions
	 * @param float      $bounce 0–1
	 * @param int|null   $score  Mobile performance 0–100, or null when unknown.
	 * @return array{label:string,severity:string}
	 */
	private static function classify( $sessions, $bounce, $score ) {
		if ( null === $score ) {
			return array( 'label' => __( 'Speed unknown', 'twt-aeo-ultimate' ), 'severity' => 'unknown' );
		}
		if ( $score < 50 && $bounce >= 0.7 ) {
			return array( 'label' => __( 'Leaking — slow & high bounce', 'twt-aeo-ultimate' ), 'severity' => 'critical' );
		}
		if ( $score < 70 && $bounce >= 0.6 ) {
			return array( 'label' => __( 'At risk — watch speed', 'twt-aeo-ultimate' ), 'severity' => 'warning' );
		}
		if ( $score >= 90 ) {
			return array( 'label' => __( 'Fast — bounce is content, not speed', 'twt-aeo-ultimate' ), 'severity' => 'ok' );
		}
		return array( 'label' => __( 'Acceptable', 'twt-aeo-ultimate' ), 'severity' => 'ok' );
	}
}
