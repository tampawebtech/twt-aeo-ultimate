<?php
/**
 * Auto Index Scan
 *
 * Makes the "detect" half of the Detect & Correct loop hands-free. A daily
 * cron inspects recent URLs via the GSC URL Inspection API (paired with the
 * local heuristics), in small self-chaining batches so no single request
 * exceeds a few API calls. When the resulting de-indexed set *changes*, the
 * report ships to the Agency hub automatically — no one has to log in and
 * click "Send to Pro".
 *
 * The change fingerprint means a stable bad state alerts once, recovery
 * resets it, and a new regression alerts again — matching how the hub's
 * alert cooldowns expect to be fed.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Auto_Index_Scan {

	const HOOK_START   = 'twtaeo_auto_index_scan';
	const HOOK_TICK    = 'twtaeo_auto_index_scan_tick';
	const OPTION_QUEUE = 'twtaeo_auto_scan_queue';
	const OPTION_FP    = 'twtaeo_deindex_fingerprint';
	const OPTION_LAST  = 'twtaeo_auto_scan_last';

	/** URLs inspected per scan (most recently modified first). */
	const SCAN_LIMIT = 50;

	/** Inspections per tick — keeps each cron request to ~10 short API calls. */
	const BATCH = 10;

	public static function init() {
		add_action( self::HOOK_START, array( __CLASS__, 'start' ) );
		add_action( self::HOOK_TICK,  array( __CLASS__, 'tick' ) );

		if ( ! wp_next_scheduled( self::HOOK_START ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', self::HOOK_START );
		}
	}

	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::HOOK_START );
		wp_clear_scheduled_hook( self::HOOK_TICK );
	}

	// ── Daily kickoff ─────────────────────────────────────────────────────────

	public static function start() {
		if ( ! class_exists( 'TWTAEO_Google_OAuth' ) || ! TWTAEO_Google_OAuth::is_connected() ) {
			return;
		}

		$config   = TWTAEO_Google_OAuth::get_config();
		$site_url = $config['gsc_site_url'] ?? '';
		if ( ! $site_url ) {
			return;
		}

		$entries = TWTAEO_Index_Status::get_scannable_urls( self::SCAN_LIMIT );
		if ( empty( $entries ) ) {
			return;
		}

		// A leftover queue from a dead run is replaced, not resumed — the daily
		// cadence makes a fresh pass the right recovery.
		update_option( self::OPTION_QUEUE, array(
			'site_url' => $site_url,
			'entries'  => array_map( function ( $e ) {
				return array( 'post_id' => $e['post_id'], 'url' => $e['url'] );
			}, $entries ),
		), false );

		if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK_TICK );
		}
	}

	// ── Batched inspection ────────────────────────────────────────────────────

	public static function tick() {
		$queue = get_option( self::OPTION_QUEUE, array() );
		if ( empty( $queue['entries'] ) ) {
			delete_option( self::OPTION_QUEUE );
			return;
		}

		$batch = array_splice( $queue['entries'], 0, self::BATCH );

		foreach ( $batch as $entry ) {
			$gsc = TWTAEO_Index_Status::inspect_url( $entry['url'], $queue['site_url'] );

			if ( is_wp_error( $gsc ) ) {
				// Quota, auth, or API failure — abort this run; tomorrow retries.
				if ( class_exists( 'TWTAEO_Logger' ) ) {
					TWTAEO_Logger::warning( 'Auto index scan aborted: ' . $gsc->get_error_message(), array( 'code' => $gsc->get_error_code() ) );
				}
				delete_option( self::OPTION_QUEUE );
				return;
			}

			$heuristics = TWTAEO_Index_Heuristics::analyze( $entry['post_id'] );
			TWTAEO_Index_Status::save_result( $entry['post_id'], $gsc, $heuristics ?? array() );
		}

		if ( ! empty( $queue['entries'] ) ) {
			update_option( self::OPTION_QUEUE, $queue, false );
			if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
				wp_schedule_single_event( time() + 60, self::HOOK_TICK );
			}
			return;
		}

		delete_option( self::OPTION_QUEUE );
		self::finish();
	}

	// ── Auto-send on change ───────────────────────────────────────────────────

	private static function finish() {
		update_option( self::OPTION_LAST, time(), false );

		$items       = TWTAEO_Index_Status::get_deindexed_for_transmission();
		$fingerprint = self::fingerprint( $items );

		if ( $fingerprint === get_option( self::OPTION_FP, '' ) ) {
			return; // Same de-indexed set as last report — nothing new to say.
		}

		if ( empty( $items ) ) {
			// Recovered: store the clean state so a future regression re-alerts.
			update_option( self::OPTION_FP, $fingerprint, false );
			return;
		}

		if ( ! class_exists( 'TWTAEO_Pro_Transmitter' ) || ! TWTAEO_Pro_Transmitter::is_connected() ) {
			return;
		}

		// Only advance the fingerprint when the hub confirms receipt, so a
		// failed delivery is retried after the next scan.
		if ( TWTAEO_Pro_Transmitter::send_deindex_report( $items ) ) {
			update_option( self::OPTION_FP, $fingerprint, false );
		}
	}

	private static function fingerprint( array $items ) {
		$set = array();
		foreach ( $items as $item ) {
			$set[] = ( $item['url'] ?? '' ) . '|' . ( $item['coverage_state'] ?? '' );
		}
		sort( $set );
		return md5( implode( "\n", $set ) );
	}
}
