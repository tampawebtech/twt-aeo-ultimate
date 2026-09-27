<?php
/**
 * Background Scan
 *
 * The dashboard's "Scan All Pages" button scans the 200 most recent posts
 * interactively in the browser. On sites with more content, this class picks
 * up the remainder server-side in small self-chaining WP-cron batches, so the
 * whole catalog gets scanned without the admin keeping a tab open.
 *
 * Progress lives in one state option: the dashboard polls it to render a live
 * progress bar, and a dismissible admin notice announces completion.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Background_Scan {

	const HOOK         = 'twtaeo_background_scan';
	const OPTION_STATE = 'twtaeo_bg_scan_state';
	const LOCK         = 'twtaeo_bg_scan_lock';
	const BATCH        = 25;

	/** Must match the dashboard's embedded foreground ID list size. */
	const FOREGROUND_CAP = 200;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_batch' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss_notice' ) );
	}

	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	// ── State ────────────────────────────────────────────────────────────────

	public static function get_state() {
		return wp_parse_args( get_option( self::OPTION_STATE, array() ), array(
			'status'      => 'idle', // idle|running|done
			'queue'       => array(),
			'total'       => 0,
			'done'        => 0,
			'errors'      => 0,
			'started_at'  => 0,
			'finished_at' => 0,
			'notice'      => false,
		) );
	}

	public static function is_running( array $state ) {
		return 'running' === $state['status'];
	}

	/** State without the (potentially huge) ID queue, for AJAX responses. */
	public static function state_payload() {
		$state = self::get_state();
		unset( $state['queue'] );
		$state['running'] = 'running' === $state['status'];
		return $state;
	}

	// ── Start ────────────────────────────────────────────────────────────────

	/**
	 * Queue scannable posts for background scanning.
	 * No-op (returns the in-flight state) when a scan is already running.
	 *
	 * The default offset skips the head of the list because the Dashboard scans
	 * those in the foreground while the page is open, and queueing them twice
	 * would scan them twice.
	 *
	 * A caller with NO foreground loop — the Agency Connector driving a scan
	 * remotely, where there is no browser on this site at all — must pass 0.
	 * Leaving the default would silently skip the newest FOREGROUND_CAP posts,
	 * which are usually the ones that matter most, and the site would look
	 * scanned while its best pages were not.
	 *
	 * @param int|null $offset Posts to skip. Null uses the foreground cap.
	 * @return array Current state.
	 */
	public static function start( $offset = null ) {
		$state = self::get_state();
		if ( self::is_running( $state ) ) {
			return $state;
		}

		$offset = ( null === $offset ) ? self::FOREGROUND_CAP : max( 0, (int) $offset );

		// Same query/order as the dashboard's embedded list.
		$ids = get_posts( array(
			'post_type'      => TWTAEO_Scan_Store::get_scannable_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'offset'         => $offset,
			'fields'         => 'ids',
		) );

		if ( empty( $ids ) ) {
			return $state;
		}

		$state = array(
			'status'      => 'running',
			'queue'       => array_map( 'intval', $ids ),
			'total'       => count( $ids ),
			'done'        => 0,
			'errors'      => 0,
			'started_at'  => time(),
			'finished_at' => 0,
			'notice'      => false,
		);
		update_option( self::OPTION_STATE, $state, false );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}

		return $state;
	}

	// ── Batched scanning ─────────────────────────────────────────────────────

	/** Scan one batch, then chain the next event until the queue drains. */
	public static function run_batch() {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );

		$state = self::get_state();
		if ( ! self::is_running( $state ) ) {
			delete_transient( self::LOCK );
			return;
		}

		$batch = array_splice( $state['queue'], 0, self::BATCH );

		foreach ( $batch as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue; // Deleted/unpublished since queueing — not an error.
			}
			try {
				TWTAEO_Scan_Store::scan_and_store( $post );
			} catch ( \Throwable $e ) {
				$state['errors']++;
				if ( class_exists( 'TWTAEO_Logger' ) ) {
					TWTAEO_Logger::log_exception( $e, array( 'post_id' => $post_id ) );
				}
			}
		}

		$state['done'] += count( $batch );

		if ( empty( $state['queue'] ) ) {
			$state['status']      = 'done';
			$state['finished_at'] = time();
			$state['notice']      = true;
		} elseif ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 15, self::HOOK );
		}

		update_option( self::OPTION_STATE, $state, false );
		delete_transient( self::LOCK );
	}

	// ── Completion notice ────────────────────────────────────────────────────

	public static function maybe_render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = self::get_state();
		if ( 'done' !== $state['status'] || empty( $state['notice'] ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %d: number of pages scanned in the background. */
			__( 'TWT AEO background scan finished — %d pages scanned.', 'twt-aeo-ultimate' ),
			(int) $state['done']
		);
		if ( $state['errors'] ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of pages that failed to scan. */
				__( '(%d pages could not be scanned.)', 'twt-aeo-ultimate' ),
				(int) $state['errors']
			);
		}

		$dismiss_url = wp_nonce_url( add_query_arg( 'twtaeo_bg_scan_dismiss', 1 ), 'twtaeo_bg_scan_dismiss' );

		printf(
			'<div class="notice notice-success"><p>%s <a href="%s">%s</a> &middot; <a href="%s">%s</a></p></div>',
			esc_html( $message ),
			esc_url( admin_url( 'admin.php?page=twt-aeo' ) ),
			esc_html__( 'View results', 'twt-aeo-ultimate' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'twt-aeo-ultimate' )
		);
	}

	public static function maybe_dismiss_notice() {
		if ( ! isset( $_GET['twtaeo_bg_scan_dismiss'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'twtaeo_bg_scan_dismiss' );

		$state           = self::get_state();
		$state['notice'] = false;
		update_option( self::OPTION_STATE, $state, false );

		wp_safe_redirect( remove_query_arg( array( 'twtaeo_bg_scan_dismiss', '_wpnonce' ) ) );
		exit;
	}
}
