<?php
/**
 * Merchant Sync Queue
 *
 * Runs the GMC/BMC catalog sync as a chain of short Action Scheduler jobs so
 * it survives the execution-time and memory limits of shared hosting:
 *
 *   1. Fetch — one feed page (≤250 items) per job, staged into a feed table.
 *      Page tokens make this naturally resumable.
 *   2. Diff  — one chunk of WC products per job, matched against the staged
 *      feed by indexed SKU/GTIN lookups instead of whole-feed memory maps.
 *   3. Finalize — stamp the last-sync time, clear the score cache, clean up.
 *
 * Progress and the accumulated summary live in a per-provider state option,
 * polled by the admin UI. Falls back to the engines' synchronous run_sync()
 * when Action Scheduler isn't available (it ships with WooCommerce, which
 * these modules require, so that path is a safety net).
 *
 * @package TWTAEO_Connector
 */


// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Merchant_Sync_Queue {

	const HOOK_FETCH = 'twtaeo_msync_fetch';
	const HOOK_DIFF    = 'twtaeo_msync_diff';
	const GROUP        = 'twt-aeo-merchant-sync';
	const DIFF_CHUNK   = 100;
	const MAPS_PREFIX  = 'twtaeo_msync_maps_';

	/** Runs older than this are considered abandoned and may be restarted. */
	const STALE_AFTER = 30 * MINUTE_IN_SECONDS;

	public static function init() {
		add_action( self::HOOK_FETCH, array( __CLASS__, 'handle_fetch' ), 10, 4 );
		add_action( self::HOOK_DIFF,  array( __CLASS__, 'handle_diff' ),  10, 3 );
	}

	public static function available() {
		return function_exists( 'as_enqueue_async_action' );
	}

	// ── State ────────────────────────────────────────────────────────────────

	private static function state_key( $provider ) {
		return 'twtaeo_msync_state_' . $provider;
	}

	public static function get_state( $provider ) {
		return wp_parse_args( get_option( self::state_key( $provider ), array() ), array(
			'status'        => 'idle', // idle|fetching|diffing|done|failed
			'run_id'        => '',
			'error'         => '',
			'pages_fetched' => 0,
			'feed_items'    => 0,
			'diff_done'     => 0,
			'diff_total'    => 0,
			'started_at'    => 0,
			'finished_at'   => 0,
			'summary'       => array(),
		) );
	}

	private static function save_state( $provider, array $state ) {
		update_option( self::state_key( $provider ), $state, false );
	}

	public static function is_running( array $state ) {
		return in_array( $state['status'], array( 'fetching', 'diffing' ), true )
			&& $state['started_at'] > time() - self::STALE_AFTER;
	}

	private static function fail( $provider, array $state, $message ) {
		$state['status']      = 'failed';
		$state['error']       = $message;
		$state['finished_at'] = time();
		self::save_state( $provider, $state );
		if ( ! empty( $state['run_id'] ) ) {
			delete_transient( self::MAPS_PREFIX . $state['run_id'] );
		}
		error_log( '[TWT AEO] Merchant sync (' . $provider . ') failed: ' . $message );
	}

	// ── Start ────────────────────────────────────────────────────────────────

	/**
	 * Kick off a queued sync for 'gmc' or 'bmc'.
	 *
	 * @return array|WP_Error  The new state, or why it couldn't start.
	 */
	public static function start( $provider ) {
		if ( ! in_array( $provider, array( 'gmc', 'bmc' ), true ) ) {
			return new WP_Error( 'bad_provider', 'Unknown provider.' );
		}
		if ( ! self::available() ) {
			return new WP_Error( 'no_scheduler', 'Action Scheduler is not available.' );
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'no_wc', 'WooCommerce is not active.' );
		}

		if ( 'gmc' === $provider && ! TWTAEO_Google_Merchant_Center::get_merchant_id() ) {
			return new WP_Error( 'no_merchant_id', 'Merchant Center ID is not configured.' );
		}
		if ( 'bmc' === $provider && ! TWTAEO_Bing_Merchant_Center::get_store_id() ) {
			return new WP_Error( 'no_store_id', 'Bing Merchant Center Store ID is not configured.' );
		}

		$state = self::get_state( $provider );
		if ( self::is_running( $state ) ) {
			return new WP_Error( 'already_running', 'A sync is already in progress.' );
		}

		self::maybe_create_table();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom feed-staging table; background-job write, caching not applicable.
		$wpdb->delete( self::table(), array( 'provider' => $provider ), array( '%s' ) );

		$run_id = md5( uniqid( $provider, true ) );
		$state  = array(
			'status'        => 'fetching',
			'run_id'        => $run_id,
			'error'         => '',
			'pages_fetched' => 0,
			'feed_items'    => 0,
			'diff_done'     => 0,
			'diff_total'    => 0,
			'started_at'    => time(),
			'finished_at'   => 0,
			'summary'       => array(),
		);
		self::save_state( $provider, $state );

		as_enqueue_async_action( self::HOOK_FETCH, array( $provider, 'product', '', $run_id ), self::GROUP );

		return $state;
	}

	// ── Stage 1: fetch feed pages ────────────────────────────────────────────

	/**
	 * Fetch one feed page and stage its items, then chain the next job.
	 *
	 * @param string $provider   gmc|bmc
	 * @param string $kind       product|status
	 * @param string $page_token Pagination token ('' = first page).
	 * @param string $run_id     Run this job belongs to.
	 */
	public static function handle_fetch( $provider, $kind, $page_token, $run_id ) {
		$state = self::get_state( $provider );
		if ( $state['run_id'] !== $run_id || 'fetching' !== $state['status'] ) {
			return; // Stale job from a superseded or failed run.
		}

		try {
			$endpoint = ( 'product' === $kind ) ? 'products' : 'productstatuses';

			if ( 'gmc' === $provider ) {
				$page = TWTAEO_Google_Merchant_Center::fetch_page( TWTAEO_Google_Merchant_Center::get_merchant_id(), $endpoint, $page_token );
			} else {
				$page = TWTAEO_Bing_Merchant_Center::fetch_page( TWTAEO_Bing_Merchant_Center::get_store_id(), $endpoint, $page_token );
			}

			if ( is_wp_error( $page ) ) {
				self::fail( $provider, $state, $page->get_error_message() );
				return;
			}

			self::stage_items( $provider, $kind, $page['items'] );

			$state['pages_fetched']++;
			if ( 'product' === $kind ) {
				$state['feed_items'] += count( $page['items'] );
			}

			if ( $page['next'] ) {
				as_enqueue_async_action( self::HOOK_FETCH, array( $provider, $kind, $page['next'], $run_id ), self::GROUP );
			} elseif ( 'product' === $kind ) {
				as_enqueue_async_action( self::HOOK_FETCH, array( $provider, 'status', '', $run_id ), self::GROUP );
			} else {
				// Feed fully staged — switch to diffing the local catalog.
				global $wpdb;
				$state['status']     = 'diffing';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off product count during a background sync; must be fresh.
				$state['diff_total'] = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
				);
				as_enqueue_async_action( self::HOOK_DIFF, array( $provider, 0, $run_id ), self::GROUP );
			}

			self::save_state( $provider, $state );
		} catch ( \Throwable $e ) {
			self::fail( $provider, $state, $e->getMessage() );
		}
	}

	/**
	 * Stage one page of feed items into the lookup table.
	 *
	 * Uses $wpdb->insert() per row (the table holds at most one feed page —
	 * ≤250 rows — per call), which handles escaping internally and keeps the
	 * write off the raw-SQL path.
	 */
	private static function stage_items( $provider, $kind, array $items ) {
		if ( empty( $items ) ) {
			return;
		}

		global $wpdb;
		$table = self::table();

		foreach ( $items as $item ) {
			if ( 'product' === $kind ) {
				$offer_id    = strtolower( (string) ( $item['offerId'] ?? '' ) );
				$gtin        = (string) ( $item['gtin'] ?? '' );
				$product_ref = (string) ( $item['id'] ?? '' );
			} else {
				$offer_id    = '';
				$gtin        = '';
				$product_ref = (string) ( $item['productId'] ?? '' );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom feed-staging table; background-job write.
			$wpdb->insert(
				$table,
				array(
					'provider'    => $provider,
					'kind'        => $kind,
					'offer_id'    => substr( $offer_id, 0, 190 ),
					'gtin'        => substr( $gtin, 0, 64 ),
					'product_ref' => substr( $product_ref, 0, 190 ),
					'payload'     => wp_json_encode( $item ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	// ── Stage 2: diff local products in chunks ───────────────────────────────

	/**
	 * Diff the next chunk of WC products against the staged feed.
	 *
	 * @param string $provider gmc|bmc
	 * @param int    $last_id  Highest product post ID already processed.
	 * @param string $run_id   Run this job belongs to.
	 */
	public static function handle_diff( $provider, $last_id, $run_id ) {
		$state = self::get_state( $provider );
		if ( $state['run_id'] !== $run_id || 'diffing' !== $state['status'] ) {
			return;
		}

		try {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paged product ID scan during a background sync; must be fresh.
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'product' AND post_status = 'publish' AND ID > %d
				 ORDER BY ID ASC
				 LIMIT %d",
				(int) $last_id, self::DIFF_CHUNK
			) ) );

			if ( empty( $ids ) ) {
				self::finalize( $provider, $state );
				return;
			}

			_prime_post_caches( $ids, false, true );

			list( $by_sku, $by_gtin, $statuses ) = self::feed_maps( $provider, $run_id );

			$engine        = ( 'gmc' === $provider ) ? 'TWTAEO_GMC_Sync_Engine' : 'TWTAEO_BMC_Sync_Engine';
			$chunk_summary = $engine::diff_chunk( $ids, $by_sku, $by_gtin, $statuses );

			foreach ( $chunk_summary as $key => $count ) {
				$state['summary'][ $key ] = ( $state['summary'][ $key ] ?? 0 ) + $count;
			}
			$state['diff_done'] += count( $ids );
			self::save_state( $provider, $state );

			as_enqueue_async_action( self::HOOK_DIFF, array( $provider, end( $ids ), $run_id ), self::GROUP );
		} catch ( \Throwable $e ) {
			self::fail( $provider, $state, $e->getMessage() );
		}
	}

	/**
	 * Build the engine lookup maps from the staged feed for this provider:
	 * products keyed by SKU and GTIN, statuses keyed by feed product ref.
	 *
	 * Two fully-prepared, non-dynamic queries (provider + kind only) so the
	 * SQL is a fixed literal — diff_chunk() then matches its chunk against the
	 * returned maps. Cached for the run so each chunk reuses one build.
	 *
	 * @return array [ by_sku, by_gtin, statuses ]
	 */
	private static function feed_maps( $provider, $run_id ) {
		$cache_key = self::MAPS_PREFIX . $run_id;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$by_sku   = array();
		$by_gtin  = array();
		$statuses = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom feed-staging table; result cached in a transient just below.
		$products = $wpdb->get_results( $wpdb->prepare(
			"SELECT offer_id, gtin, payload FROM {$wpdb->prefix}twtaeo_merchant_feed WHERE provider = %s AND kind = %s",
			$provider,
			'product'
		) );
		foreach ( $products as $row ) {
			$item = json_decode( $row->payload, true );
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( $row->offer_id !== '' ) {
				$by_sku[ $row->offer_id ] = $item;
			}
			if ( $row->gtin !== '' ) {
				$by_gtin[ $row->gtin ] = $item;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom feed-staging table; result cached in a transient just below.
		$status_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT product_ref, payload FROM {$wpdb->prefix}twtaeo_merchant_feed WHERE provider = %s AND kind = %s",
			$provider,
			'status'
		) );
		foreach ( $status_rows as $row ) {
			$status = json_decode( $row->payload, true );
			if ( is_array( $status ) ) {
				$statuses[ $row->product_ref ] = $status;
			}
		}

		$maps = array( $by_sku, $by_gtin, $statuses );
		set_transient( $cache_key, $maps, HOUR_IN_SECONDS );

		return $maps;
	}

	// ── Stage 3: finalize ────────────────────────────────────────────────────

	private static function finalize( $provider, array $state ) {
		$engine = ( 'gmc' === $provider ) ? 'TWTAEO_GMC_Sync_Engine' : 'TWTAEO_BMC_Sync_Engine';

		update_option( $engine::OPTION_LAST, time() );
		delete_transient( 'twtaeo_' . $provider . '_integrity_score' );

		$state['status']      = 'done';
		$state['finished_at'] = time();
		self::save_state( $provider, $state );

		if ( ! empty( $state['run_id'] ) ) {
			delete_transient( self::MAPS_PREFIX . $state['run_id'] );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom feed-staging table cleanup after a background sync.
		$wpdb->delete( self::table(), array( 'provider' => $provider ), array( '%s' ) );
	}

	// ── Feed staging table ───────────────────────────────────────────────────

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'twtaeo_merchant_feed';
	}

	private static function maybe_create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta() is the WordPress-sanctioned table builder; it runs its own
		// query, keeping this off the raw $wpdb->query() path.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			provider varchar(8) NOT NULL,
			kind varchar(10) NOT NULL,
			offer_id varchar(190) NOT NULL DEFAULT '',
			gtin varchar(64) NOT NULL DEFAULT '',
			product_ref varchar(190) NOT NULL DEFAULT '',
			payload longtext NOT NULL,
			PRIMARY KEY  (id),
			KEY provider_kind_offer (provider, kind, offer_id),
			KEY provider_kind_gtin (provider, kind, gtin),
			KEY provider_kind_ref (provider, kind, product_ref)
		) {$charset};" );
	}
}
