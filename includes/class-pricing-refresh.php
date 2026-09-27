<?php
/**
 * Weekly re-derivation of dynamic-pricing detection.
 *
 * ── The hole this fills ───────────────────────────────────────────────────────
 * TWTAEO_Dynamic_Pricing caches what a pricing engine says it will charge, in post
 * meta, and the front end publishes from that cache without ever probing. The only
 * invalidation is product-scoped — `woocommerce_update_product`,
 * `woocommerce_new_product`, `save_post_product`.
 *
 * A discount **rule** is not a product. A merchant who ends a promotion, edits its
 * bands, or lets a scheduled rule run past its end date touches no product at all,
 * so nothing invalidates and the cached figure survives **indefinitely** — until
 * someone happens to save that product for an unrelated reason. Verified on the
 * test store: toggling a Flycart rule fired no invalidation whatsoever, and the
 * stored detection had to be cleared by hand.
 *
 * That is the failure this whole module exists to prevent, arriving by way of the
 * clock instead of by way of configuration: publishing a price the checkout will
 * not honour.
 *
 * ── Why a sweep and not a hook on their rule saves ────────────────────────────
 * Flycart does fire `advanced_woo_discount_rules_after_save_rule` and matching
 * delete actions, and hooking them would invalidate the moment a rule changed.
 * **The owner's call (2026-08-04) is not to** — merchants roll promotions
 * constantly, often ending one today and restarting it tomorrow, and reacting to
 * every rule save means re-deriving the catalogue on the merchant's marketing
 * cadence rather than our own. A weekly sweep deliberately sits out that churn:
 * the ends-today-back-tomorrow case resolves itself before the sweep ever looks.
 *
 * ⚠️ **The cost of that choice, recorded so it is not rediscovered as a bug.** A
 * promotion that ends and does *not* come back leaves us publishing the old,
 * lower price for up to one sweep interval. The interval is therefore the
 * worst-case window in which our structured data can contradict the checkout, and
 * the Promotions screen shows it rather than implying freshness we do not have.
 * Shortening the interval shortens that window; it does not change the shape.
 *
 * ── It re-derives; it must never merely clear ────────────────────────────────
 * `effective_unit_price_raw()` reads the cache and never probes, so a product with
 * no cache falls back to `get_price()`. On a store whose engine discounts from
 * quantity one that is the *catalogue* figure — 45 where the page renders 42.75.
 * Clearing on a schedule would therefore introduce the exact mismatch this guards
 * against, every week, for as long as the gap lasted. So the sweep walks batches
 * through TWTAEO_Dynamic_Pricing::scan_batch(), which overwrites each entry in
 * place, and a product is never without a cached answer.
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Pricing_Refresh {

	/** Recurring kick-off. */
	const CRON_HOOK = 'twtaeo_pricing_refresh_start';

	/** One batch of the walk. */
	const HOOK = 'twtaeo_pricing_refresh_tick';

	const GROUP        = 'twt-aeo-pricing-refresh';
	const OPTION_STATE = 'twtaeo_pricing_refresh_state';

	/**
	 * Products per batch.
	 *
	 * Each product costs a ladder of calls into someone else's rule engine — 20
	 * rungs, and on a variable product 20 per variation. Bounded for the same
	 * reason scan_batch() is bounded: an unbounded walk of a large catalogue is a
	 * timeout, not a slow request.
	 */
	const BATCH = 25;

	/** Seconds between batches. Headroom for the scheduler, not rate limiting. */
	const SPACING = 30;

	public static function init() {
		// Registered unconditionally: a tick already queued must still be able to
		// run (and to stand itself down) after the merchant switches things off.
		add_action( self::HOOK, array( __CLASS__, 'tick' ), 10, 1 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'start' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_weekly_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval

		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	/**
	 * `weekly` is not one of WordPress's built-in intervals.
	 *
	 * Two other classes here register it as well, each guarded the same way, so
	 * whichever runs first defines it and the rest defer. Do not assume one of
	 * them has already run — they are feature-gated and may not be active.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public static function add_weekly_schedule( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once weekly', 'twt-aeo-ultimate' ),
			);
		}
		return $schedules;
	}

	// ── Gating ────────────────────────────────────────────────────────────────

	/**
	 * Whether refreshing is worth doing at all.
	 *
	 * Both gates matter. With no engine hooked there is nothing to ask; with tier
	 * output off, `effective_unit_price_raw()` returns null regardless of what is
	 * cached, so nothing we refreshed would reach a page. Spending a merchant's
	 * CPU on a cache nothing reads is not defensible.
	 *
	 * @return bool
	 */
	public static function should_run() {
		if ( ! class_exists( 'TWTAEO_Dynamic_Pricing' ) || ! TWTAEO_Dynamic_Pricing::is_available() ) {
			return false;
		}
		return class_exists( 'TWTAEO_Price_Tiers' ) && TWTAEO_Price_Tiers::is_enabled();
	}

	/**
	 * Keep the recurring event in step with the gates.
	 *
	 * Runs on every `init`, which is cheap — `wp_next_scheduled()` is a single
	 * option read — and means switching the module on or off takes effect without
	 * the merchant having to re-save anything.
	 */
	public static function maybe_schedule() {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( self::should_run() ) {
			if ( ! $scheduled ) {
				// First run an hour out rather than immediately: activation is the
				// worst possible moment to start walking a catalogue.
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK );
			}
			return;
		}

		if ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON_HOOK );
		}
	}

	/** Remove everything this class scheduled. Called on deactivation. */
	public static function unschedule() {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	// ── State ─────────────────────────────────────────────────────────────────

	public static function get_state() {
		$state = get_option( self::OPTION_STATE, array() );
		return wp_parse_args( is_array( $state ) ? $state : array(), array(
			'running'       => false,
			'offset'        => 0,
			'scanned'       => 0,
			'with_tiers'    => 0,
			'engine_priced' => 0,
			'total'         => 0,
			'started_at'    => 0,
			'finished_at'   => 0,
			'last_error'    => '',
		) );
	}

	private static function save_state( array $state ) {
		update_option( self::OPTION_STATE, $state, false );
	}

	// ── The walk ──────────────────────────────────────────────────────────────

	/**
	 * Begin a sweep. Bound to the recurring event; also callable by the admin.
	 *
	 * @param bool $manual True when a merchant asked for it, which bypasses the
	 *                     "already running" guard so a wedged run can be retried.
	 * @return bool Whether a sweep was started.
	 */
	public static function start( $manual = false ) {
		if ( ! self::should_run() ) {
			return false;
		}

		$state = self::get_state();
		if ( ! empty( $state['running'] ) && ! $manual ) {
			// A sweep still walking when the next weekly tick lands means the
			// catalogue is larger than the interval. Let it finish rather than
			// starting a second walk over the same products.
			return false;
		}

		self::save_state( array(
			'running'       => true,
			'offset'        => 0,
			'scanned'       => 0,
			'with_tiers'    => 0,
			'engine_priced' => 0,
			'total'         => 0,
			'started_at'    => time(),
			'finished_at'   => 0,
			'last_error'    => '',
		) );

		self::schedule_tick( 5 );
		return true;
	}

	/**
	 * Run one batch and queue the next.
	 *
	 * @param int $run Ignored; present so a scheduled arg does not become a fatal.
	 */
	public static function tick( $run = 0 ) {
		$state = self::get_state();

		if ( empty( $state['running'] ) ) {
			return;
		}

		// The merchant may have switched tiers off mid-walk. Stand down rather
		// than spending the rest of the catalogue on a cache nothing will read.
		if ( ! self::should_run() ) {
			$state['running']     = false;
			$state['finished_at'] = time();
			$state['last_error']  = __( 'Stopped: quantity tier output was switched off during the refresh.', 'twt-aeo-ultimate' );
			self::save_state( $state );
			return;
		}

		$batch = TWTAEO_Dynamic_Pricing::scan_batch( self::BATCH, (int) $state['offset'] );

		$state['scanned']       += (int) $batch['scanned'];
		$state['with_tiers']    += (int) $batch['with_tiers'];
		$state['engine_priced'] += (int) $batch['engine_priced'];
		$state['total']          = (int) $batch['total'];

		$next = (int) $batch['next'];

		// `next` is 0 both when the walk is done and when a batch scanned nothing.
		// Treating "scanned nothing" as "keep going" would loop forever on an
		// offset past the end of the catalogue.
		if ( $next > 0 && (int) $batch['scanned'] > 0 ) {
			$state['offset'] = $next;
			self::save_state( $state );
			self::schedule_tick( self::SPACING );
			return;
		}

		$state['running']     = false;
		$state['offset']      = 0;
		$state['finished_at'] = time();
		self::save_state( $state );
	}

	/**
	 * Queue the next batch, preferring Action Scheduler.
	 *
	 * WooCommerce ships Action Scheduler, but check rather than assume — and fall
	 * back to WP-Cron so the sweep still completes without it.
	 *
	 * @param int $delay Seconds from now.
	 */
	private static function schedule_tick( $delay ) {
		$when = time() + max( 1, (int) $delay );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $when, self::HOOK, array( 'run' => 1 ), self::GROUP );
			return;
		}
		wp_schedule_single_event( $when, self::HOOK, array( 1 ) );
	}

	// ── Reporting ─────────────────────────────────────────────────────────────

	/**
	 * Worst-case age of the published pricing data, in seconds, or null.
	 *
	 * This is the honest number to put in front of a merchant: how long our
	 * structured data could have been contradicting their checkout. It is measured
	 * from the end of the last completed sweep, because that is the last moment
	 * every product was known to agree with the engine.
	 *
	 * @return int|null
	 */
	public static function staleness() {
		$state = self::get_state();
		if ( empty( $state['finished_at'] ) ) {
			return null;
		}
		return max( 0, time() - (int) $state['finished_at'] );
	}

	/** When the next sweep is due, or null when nothing is scheduled. */
	public static function next_run() {
		$next = wp_next_scheduled( self::CRON_HOOK );
		return $next ? (int) $next : null;
	}
}
