<?php
/**
 * AI Visibility — module wiring.
 *
 * Binds the `ai-visibility` module to WordPress: creates the run tables once
 * per request, and registers the AJAX handlers the board page
 * (`admin/pages/class-page-ai-visibility.php`) talks to. All logic lives in
 * `includes/ai-visibility/*` — this class only sanitises input, checks the
 * nonce and capability, calls the store and answers JSON.
 *
 * Every handler answers with `wp_send_json_success` / `wp_send_json_error`.
 * The step handler is the exception in spirit: it ALWAYS answers 200 with the
 * store's result object, because a provider error is data about one check
 * (recorded as `unavailable`), not a failed request — the client loop keeps
 * going on both success and failure and reads the flags inside.
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility {

	/** Saving the merchant-edited question set (ticks) — not in the shared contract, page-only. */
	const AJAX_SAVE_Q = 'twtaeo_visibility_save_questions';

	/** AI-rephrasing awkward template questions — page-only, cheap tier. */
	const AJAX_POLISH = 'twtaeo_visibility_polish_questions';

	/** Company profiles + tracked brand items (the brand citation tracker). */
	const AJAX_SAVE_B = 'twtaeo_visibility_save_brands';

	/** Post questions AI-rephrased per request; the page loops until none remain. */
	const POLISH_CHUNK = 20;

	/** WP-Cron hook: advances a running run when no board page is doing it. */
	const CRON_HOOK = 'twtaeo_visibility_cron';

	/** Transient held while ANY stepper (page or cron) is inside a batch. */
	const LOCK_KEY = 'twtaeo_visibility_lock';

	/**
	 * Transient the page's step handler refreshes on every call. While it is
	 * fresh the cron worker stands down — an open tab steps every few seconds,
	 * far faster than cron ever will, and two steppers on one cursor would
	 * record the same check twice.
	 */
	const TOUCH_KEY = 'twtaeo_visibility_page_touch';

	/** Seconds a cron batch may spend stepping before it re-queues itself. */
	const BATCH_SECONDS = 20;

	/** @var bool Tables are checked once per request, not once per hook. */
	private static $booted = false;

	private function __construct() {}

	/**
	 * Called on `init` when the module is active (see TWTAEO_Plugin::run()).
	 */
	public static function register_hooks() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		if ( class_exists( 'TWTAEO_Visibility_Store' ) && is_admin() ) {
			TWTAEO_Visibility_Store::maybe_create_tables();
		}

		$map = array(
			TWTAEO_Visibility_Types::AJAX_START   => 'ajax_start',
			TWTAEO_Visibility_Types::AJAX_STEP    => 'ajax_step',
			TWTAEO_Visibility_Types::AJAX_STOP    => 'ajax_stop',
			TWTAEO_Visibility_Types::AJAX_PREVIEW => 'ajax_preview',
			TWTAEO_Visibility_Types::AJAX_SAVE    => 'ajax_save',
			TWTAEO_Visibility_Types::AJAX_DEMO    => 'ajax_demo',
			TWTAEO_Visibility_Types::AJAX_UNDEMO  => 'ajax_undemo',
			TWTAEO_Visibility_Types::AJAX_RESET_Q => 'ajax_reset_questions',
			self::AJAX_SAVE_Q                     => 'ajax_save_questions',
			self::AJAX_POLISH                     => 'ajax_polish_questions',
			self::AJAX_SAVE_B                     => 'ajax_save_brands',
			TWTAEO_Visibility_Types::AJAX_PERSONAS_SAVE    => 'ajax_personas_save',
			TWTAEO_Visibility_Types::AJAX_PERSONAS_SUGGEST => 'ajax_personas_suggest',
			TWTAEO_Visibility_Types::AJAX_PERSONAS_TOGGLE  => 'ajax_personas_toggle',
		);
		foreach ( $map as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $method ) );
		}
		// A file download, so admin-post rather than AJAX.
		add_action( 'admin_post_' . TWTAEO_Visibility_Types::EXPORT_ACTION, array( __CLASS__, 'export_download' ) );

		// Bound in every context, not just admin — a queued event otherwise
		// fires into nothing (same lesson as the Autopilot hook).
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_worker' ) );
	}

	/* ─────────────────────── background worker ─────────────────────── */

	/** Deactivation: drop the queued worker event (see TWTAEO_Deactivator). */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_transient( self::LOCK_KEY );
		delete_transient( self::TOUCH_KEY );
	}

	/** Queue the worker unless one is already queued. */
	private static function schedule_worker( $delay ) {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + max( 30, (int) $delay ), self::CRON_HOOK );
		}
	}

	/** The newest real run still in `running` state, or ''. */
	private static function active_run_id() {
		if ( ! class_exists( 'TWTAEO_Visibility_Store' ) ) {
			return '';
		}
		foreach ( (array) TWTAEO_Visibility_Store::list_runs() as $r ) {
			if ( is_array( $r ) && isset( $r['status'] ) && 'running' === (string) $r['status'] && empty( $r['demo'] ) ) {
				return isset( $r['id'] ) ? (string) $r['id'] : '';
			}
		}
		return '';
	}

	/**
	 * Advance the active run for up to BATCH_SECONDS, then re-queue while work
	 * remains. Runs entirely on the store's queue + cursor, so it picks up
	 * wherever the page loop left off. Stands down while a board page is
	 * stepping (TOUCH_KEY fresh). A run that pauses itself (daily cap) or
	 * loses a key stops the chain — those need the owner, not a retry loop.
	 */
	public static function cron_worker() {
		$run_id = self::active_run_id();
		if ( '' === $run_id ) {
			return;
		}
		// register_hooks() only upgrades the schema in admin. A run resumed by
		// cron alone after a plugin update would otherwise write checks into a
		// table that has not grown its new columns yet, and $wpdb->insert fails
		// quietly — the check would simply vanish. Once per batch, not per step.
		TWTAEO_Visibility_Store::maybe_create_tables();
		if ( get_transient( self::TOUCH_KEY ) ) {
			self::schedule_worker( 120 );
			return;
		}
		if ( get_transient( self::LOCK_KEY ) ) {
			self::schedule_worker( 180 );
			return;
		}

		$deadline = time() + self::BATCH_SECONDS;
		do {
			// Refreshed per step: one engine call can take 20-30s (Muse up to
			// 90s, plus the follow-up call), and a lock that expires mid-step
			// would let a second stepper in.
			set_transient( self::LOCK_KEY, time(), 180 );
			if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
				set_time_limit( 120 ); // Per step, as the AJAX step handler does.
			}
			$result = TWTAEO_Visibility_Store::step_run( $run_id );
			if ( ! is_array( $result ) || ! empty( $result['done'] ) || ! empty( $result['paused'] ) ) {
				break;
			}
			if ( ! empty( $result['needs_key'] ) ) {
				TWTAEO_Visibility_Store::pause_run( $run_id, isset( $result['error'] ) ? (string) $result['error'] : '' );
				break;
			}
			if ( empty( $result['ok'] ) ) {
				break;
			}
			// Only background answers left: nothing to do until they are due
			// another poll, so hand back and come round again.
			if ( ! empty( $result['waiting'] ) ) {
				break;
			}
		} while ( time() < $deadline );
		delete_transient( self::LOCK_KEY );

		if ( '' !== self::active_run_id() ) {
			self::schedule_worker( 60 );
		}
	}

	/** The capability the board and its handlers require. */
	public static function cap() {
		return class_exists( 'TWTAEO_Roles' ) ? TWTAEO_Roles::CAP : 'manage_options';
	}

	/* ───────────────────────────── guards ───────────────────────────── */

	/** Nonce + capability, or a JSON error. Every handler starts here. */
	private static function guard() {
		check_ajax_referer( TWTAEO_Visibility_Types::NONCE, 'nonce' );
		if ( ! current_user_can( self::cap() ) ) {
			wp_send_json_error( array( 'error' => __( 'You do not have permission to do that.', 'twt-aeo-ultimate' ) ), 403 );
		}
	}

	/** A POST field as a trimmed string ('' when absent). */
	private static function post_text( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_ajax_referer.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/** A POST field as a non-negative int. */
	private static function post_int( $key, $default = 0 ) {
		$raw = self::post_text( $key );
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return (int) $default;
		}
		return max( 0, (int) floor( (float) $raw ) );
	}

	/**
	 * The allocation the page posted: five counts + `engine[]`, clamped by the
	 * pure class so the ceilings live in one place.
	 *
	 * @param array $fallback The saved allocation, used for any count not posted.
	 * @return array Allocation.
	 */
	private static function post_allocation( array $fallback ) {
		$a = array();
		foreach ( array( 'company', 'per_brand', 'per_collection', 'per_type', 'per_product', 'per_post' ) as $k ) {
			$a[ $k ] = self::post_int( $k, isset( $fallback[ $k ] ) ? (int) $fallback[ $k ] : 0 );
		}
		$engines = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_ajax_referer.
		$raw = isset( $_POST['engine'] ) ? map_deep( wp_unslash( $_POST['engine'] ), 'sanitize_text_field' ) : array();
		if ( is_string( $raw ) ) {
			$raw = array( $raw );
		}
		if ( is_array( $raw ) ) {
			foreach ( $raw as $e ) {
				$e = sanitize_key( (string) $e );
				if ( isset( TWTAEO_Visibility_Types::ENGINES[ $e ] ) ) {
					$engines[] = $e;
				}
			}
		}
		$a['engines'] = array_values( array_unique( $engines ) );
		return TWTAEO_Visibility_Questions::clamp_allocation( $a );
	}

	/** Settings with every key present, whatever the store holds. */
	private static function settings() {
		$s = TWTAEO_Visibility_Store::get_settings();
		$s = is_array( $s ) ? $s : array();
		if ( empty( $s['allocation'] ) || ! is_array( $s['allocation'] ) ) {
			$s['allocation'] = TWTAEO_Visibility_Questions::apply_preset( 'standard', array() );
		}
		return $s;
	}

	/* ──────────────────────────── handlers ──────────────────────────── */

	/** Start a run for the posted allocation. Answers { run_id, total }. */
	public static function ajax_start() {
		self::guard();
		$settings = self::settings();
		$alloc    = self::post_allocation( $settings['allocation'] );
		$opts     = array();
		$max      = self::post_int( 'max_questions', 0 );
		if ( $max > 0 ) {
			$opts['max_questions'] = $max;
		}
		if ( empty( $alloc['engines'] ) ) {
			wp_send_json_error( array( 'error' => __( 'Tick at least one engine you hold a key for.', 'twt-aeo-ultimate' ) ) );
		}
		// '' = the baseline. An id that no longer exists runs as the baseline
		// too rather than failing — the picker was simply stale. Personas
		// switched off means the baseline, whatever was posted.
		$persona = TWTAEO_Visibility_Personas::enabled() ? TWTAEO_Visibility_Personas::find( sanitize_title( self::post_text( 'persona' ) ) ) : null;
		if ( $persona ) {
			$opts['persona'] = $persona;
		}
		// Journey mode: follow-up turns past the first answer (0 = off).
		$opts['journey'] = min( (int) TWTAEO_Visibility_Types::JOURNEY_MAX, self::post_int( 'journey', 0 ) );
		// Where the run searches from. Nothing chosen (or "auto") means the
		// site's own location; "none" is the only way to search from nowhere.
		if ( class_exists( 'TWTAEO_Visibility_Location' ) ) {
			$country = strtoupper( self::post_text( 'loc_country' ) );
			if ( '' === $country || 'AUTO' === $country ) {
				$location = TWTAEO_Visibility_Location::site_default();
			} elseif ( 'NONE' === $country ) {
				$location = null;
			} else {
				$location = TWTAEO_Visibility_Location::clean(
					array(
						'country' => $country,
						'region'  => self::post_text( 'loc_region' ),
						'city'    => self::post_text( 'loc_city' ),
						'source'  => 'owner',
					)
				);
			}
			if ( $location ) {
				$opts['location'] = $location;
			}
		}
		// A site that never wrote personas gets some suggested from its own
		// pages, once, for the next run's picker. This run stays the baseline.
		$before = count( TWTAEO_Visibility_Personas::all() );
		$after  = count( TWTAEO_Visibility_Personas::maybe_autofill() );
		$run = TWTAEO_Visibility_Store::start_run( $alloc, $opts );
		if ( is_wp_error( $run ) ) {
			wp_send_json_error( array( 'error' => $run->get_error_message(), 'needs_key' => 'needs_key' === $run->get_error_code() ) );
		}
		if ( ! is_array( $run ) || empty( $run['id'] ) ) {
			wp_send_json_error( array( 'error' => __( 'Could not start a run.', 'twt-aeo-ultimate' ) ) );
		}
		$queue = isset( $run['queue'] ) && is_array( $run['queue'] ) ? $run['queue'] : array();
		// Backstop: if the tab closes, cron finishes the run from the cursor.
		self::schedule_worker( 90 );
		wp_send_json_success( array(
			'run_id'             => (string) $run['id'],
			'total'              => count( $queue ),
			'cursor'             => isset( $run['cursor'] ) ? (int) $run['cursor'] : 0,
			'personas_suggested' => $after > $before ? $after : 0,
		) );
	}

	/* ─────────────────────────── personas ─────────────────────────── */

	/**
	 * Save the persona list. The page posts it whole as JSON rows
	 * `{label, source}`; an empty list is a valid save.
	 */
	public static function ajax_personas_save() {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() ran check_ajax_referer; every label is sanitised in TWTAEO_Visibility_Personas::clean().
		$raw   = isset( $_POST['personas'] ) ? wp_unslash( $_POST['personas'] ) : '[]';
		$saved = TWTAEO_Visibility_Personas::save( is_string( $raw ) ? $raw : array() );
		wp_send_json_success( array(
			'personas' => $saved,
			'message'  => empty( $saved )
				? __( 'Personas cleared. Runs will ask as nobody in particular.', 'twt-aeo-ultimate' )
				/* translators: %d: number of personas saved. */
				: sprintf( _n( '%d persona saved.', '%d personas saved.', count( $saved ), 'twt-aeo-ultimate' ), count( $saved ) ),
		) );
	}

	/** Switch buyer personas on or off for the whole site. The list itself is kept either way. */
	public static function ajax_personas_toggle() {
		self::guard();
		$on = '1' === self::post_text( 'on' );
		TWTAEO_Visibility_Store::save_settings( array( 'personas_on' => $on ) );
		wp_send_json_success( array( 'on' => $on ) );
	}

	/* ─────────────────────────── export ─────────────────────────── */

	/**
	 * Stream the CSV: `run` is a run id or 'all' (every kept real run).
	 * GET via admin-post.php, nonce + capability checked.
	 */
	public static function export_download() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'twt-aeo-ultimate' ) );
		}
		check_admin_referer( TWTAEO_Visibility_Types::EXPORT_ACTION );
		$which = isset( $_GET['run'] ) ? sanitize_text_field( wp_unslash( $_GET['run'] ) ) : 'all';

		$runs = TWTAEO_Visibility_Export::runs( $which );
		if ( empty( $runs ) ) {
			wp_die( esc_html__( 'There are no recorded checks to export yet. Sample data is never exported.', 'twt-aeo-ultimate' ) );
		}
		$csv = TWTAEO_Visibility_Export::to_csv( TWTAEO_Visibility_Export::rows( $runs ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . TWTAEO_Visibility_Export::filename( $which ) . '"' );
		header( 'Content-Length: ' . strlen( $csv ) );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, every cell quoted and formula-guarded in TWTAEO_Visibility_Export::cell().
		exit;
	}

	/**
	 * Suggest personas from the site's own pages and add them to the list,
	 * marked as suggested. Personas already there are kept as they are.
	 */
	public static function ajax_personas_suggest() {
		self::guard();
		$labels = TWTAEO_Visibility_Personas::suggest();
		if ( is_wp_error( $labels ) ) {
			wp_send_json_error( array( 'error' => $labels->get_error_message() ) );
		}
		// The list as it stands on screen (unsaved edits included), else the stored one.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() ran check_ajax_referer; labels are sanitised in TWTAEO_Visibility_Personas::clean().
		$raw  = isset( $_POST['personas'] ) ? wp_unslash( $_POST['personas'] ) : null;
		$rows = is_string( $raw ) ? TWTAEO_Visibility_Personas::clean( $raw ) : TWTAEO_Visibility_Personas::all();
		$had  = count( $rows );
		foreach ( (array) $labels as $label ) {
			$rows[] = array( 'label' => $label, 'source' => 'site' );
		}
		$saved = TWTAEO_Visibility_Personas::save( $rows );
		$added = max( 0, count( $saved ) - $had );
		wp_send_json_success( array(
			'personas' => $saved,
			'message'  => $added
				/* translators: %d: number of personas added. */
				? sprintf( _n( '%d persona suggested from your site. Edit or remove it as you like.', '%d personas suggested from your site. Edit or remove any of them as you like.', $added, 'twt-aeo-ultimate' ), $added )
				: __( 'Nothing new to suggest — your list already covers what your pages describe.', 'twt-aeo-ultimate' ),
		) );
	}

	/**
	 * Advance one check. Always HTTP 200 with the store's result object, so the
	 * client loop reads `ok` / `done` / `paused` / `needs_key` from the body.
	 */
	public static function ajax_step() {
		self::guard();
		$run_id = self::post_text( 'run_id' );
		if ( '' === $run_id ) {
			wp_send_json( array( 'ok' => false, 'error' => __( 'Missing run id.', 'twt-aeo-ultimate' ) ) );
		}
		// Tell the cron worker a page is on duty, BEFORE the lock check, so the
		// takeover window (page just reopened, worker mid-batch) stays one step.
		set_transient( self::TOUCH_KEY, time(), 90 );
		if ( get_transient( self::LOCK_KEY ) ) {
			// The worker is inside a batch; the page waits instead of stepping
			// the same cursor. `busy` is not an error — the JS loop just polls.
			wp_send_json( array( 'ok' => true, 'busy' => true ) );
		}
		// One step is the answer call (up to 40 s) plus the follow-up call (up to
		// 25 s) — more than the 30 s many hosts allow a request by default.
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}
		$result = TWTAEO_Visibility_Store::step_run( $run_id );
		if ( ! is_array( $result ) ) {
			$result = array( 'ok' => false, 'error' => __( 'The run could not be advanced.', 'twt-aeo-ultimate' ) );
		}
		if ( empty( $result['done'] ) && empty( $result['paused'] ) ) {
			self::schedule_worker( 180 ); // covers resumed runs, which never pass ajax_start
		}
		wp_send_json( $result );
	}

	/** Stop a run (keeps what was recorded). */
	public static function ajax_stop() {
		self::guard();
		$run_id = self::post_text( 'run_id' );
		if ( '' === $run_id ) {
			wp_send_json_error( array( 'error' => __( 'Missing run id.', 'twt-aeo-ultimate' ) ) );
		}
		TWTAEO_Visibility_Store::stop_run( $run_id );
		wp_send_json_success( array( 'run_id' => $run_id ) );
	}

	/**
	 * Build (do not run) the question list for the posted allocation. Reads
	 * the catalogue, spends nothing. Answers grouped counts + the full list.
	 */
	public static function ajax_preview() {
		self::guard();
		$settings  = self::settings();
		$alloc     = self::post_allocation( $settings['allocation'] );
		$input     = TWTAEO_Visibility_Inputs::load( $alloc );
		$questions = TWTAEO_Visibility_Questions::build_questions( is_array( $input ) ? $input : array(), $alloc );
		$questions = is_array( $questions ) ? array_values( $questions ) : array();

		// Carry the merchant's earlier ticks forward by id, so a rebuild does not
		// silently re-enable something they switched off — and carry AI-polished
		// or hand-edited text the same way, so a rebuild does not resurrect the
		// awkward template phrasing.
		$questions = TWTAEO_Visibility_Store::merge_saved_questions( $questions, TWTAEO_Visibility_Store::load_questions() );

		$by_level = array();
		foreach ( TWTAEO_Visibility_Types::LEVELS as $level ) {
			$by_level[ $level ] = 0;
		}
		$enabled = 0;
		foreach ( $questions as $q ) {
			$level = isset( $q['level'] ) ? (string) $q['level'] : '';
			if ( isset( $by_level[ $level ] ) ) {
				$by_level[ $level ]++;
			}
			if ( ! empty( $q['enabled'] ) ) {
				$enabled++;
			}
		}
		wp_send_json_success( array(
			'total'     => count( $questions ),
			'enabled'   => $enabled,
			'by_level'  => $by_level,
			'questions' => $questions,
			'html'      => TWTAEO_Page_AI_Visibility::render_question_list( $questions ),
		) );
	}

	/**
	 * Save the company's owned profiles and the tracked brand items.
	 *
	 * Anything unusable is reported back rather than quietly dropped. A URL that
	 * silently fails to save is the worst outcome here: the merchant believes a
	 * property is being tracked, the citations it earns keep landing in the
	 * competitor column, and the board reads as a gap that isn't one.
	 */
	public static function ajax_save_brands() {
		self::guard();

		// Both sniffs must be listed in the TRAILING annotation: a trailing
		// phpcs:ignore on a statement's own line overrides an own-line comment
		// above it, so a nonce ignore placed above would be discarded.
		$raw_company = isset( $_POST['company_urls'] ) ? wp_unslash( $_POST['company_urls'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() ran check_ajax_referer; sanitised per field in TWTAEO_Visibility_Store::clean_property_urls().
		$raw_items = isset( $_POST['brand_items'] ) && is_array( $_POST['brand_items'] ) ? wp_unslash( $_POST['brand_items'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() ran check_ajax_referer; sanitised per field in TWTAEO_Visibility_Store::clean_brand_items().

		$rejected = self::rejected_urls( $raw_company, __( 'Company', 'twt-aeo-ultimate' ) );
		foreach ( (array) $raw_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = isset( $item['label'] ) ? sanitize_text_field( (string) $item['label'] ) : __( 'Brand', 'twt-aeo-ultimate' );
			foreach ( self::rejected_urls( isset( $item['urls'] ) ? $item['urls'] : array(), $label ) as $r ) {
				$rejected[] = $r;
			}
		}

		$saved = TWTAEO_Visibility_Store::save_settings(
			array(
				'company_urls' => $raw_company,
				'brand_items'  => $raw_items,
			)
		);

		$items    = isset( $saved['brand_items'] ) ? (array) $saved['brand_items'] : array();
		$profiles = count( isset( $saved['company_urls'] ) ? (array) $saved['company_urls'] : array() );
		foreach ( $items as $item ) {
			$profiles += count( isset( $item['urls'] ) ? (array) $item['urls'] : array() );
		}

		wp_send_json_success(
			array(
				'message'      => sprintf(
					/* translators: 1: number of brand items, 2: number of owned profile URLs. */
					_n( '%1$s brand tracked, %2$s owned profile watched.', '%1$s brands tracked, %2$s owned profiles watched.', count( $items ), 'twt-aeo-ultimate' ),
					number_format_i18n( count( $items ) ),
					number_format_i18n( $profiles )
				),
				'company_urls' => isset( $saved['company_urls'] ) ? $saved['company_urls'] : array(),
				'brand_items'  => $items,
				'rejected'     => $rejected,
			)
		);
	}

	/**
	 * URLs that will not be stored, each with the reason in plain words.
	 *
	 * @param mixed  $urls  Raw list or newline/comma text.
	 * @param string $owner Whose list this is, for the message.
	 * @return array Rows of [ owner, url, reason ].
	 */
	private static function rejected_urls( $urls, $owner ) {
		if ( ! class_exists( 'TWTAEO_Visibility_Brands' ) ) {
			return array();
		}
		if ( is_string( $urls ) ) {
			$urls = preg_split( '/[\r\n,]+/', $urls );
		}
		$out = array();
		foreach ( (array) $urls as $u ) {
			$u = sanitize_text_field( (string) $u );
			if ( '' === trim( $u ) ) {
				continue;
			}
			$problem = TWTAEO_Visibility_Brands::property_problem( $u );
			if ( '' === $problem ) {
				continue;
			}
			if ( 'needs_path' === $problem ) {
				$reason = __( 'This is a shared platform, so add the full page address — your own page on it, like linkedin.com/company/your-brand. The bare domain would count every citation on that platform as yours. (A domain of your own needs no path — paste it as it is.)', 'twt-aeo-ultimate' );
			} else {
				$reason = __( 'Not a usable http(s) address.', 'twt-aeo-ultimate' );
			}
			$out[] = array(
				'owner'  => (string) $owner,
				'url'    => $u,
				'reason' => $reason,
			);
		}
		return $out;
	}

	/** Save allocation, engines, daily cap, X handle and alternate hosts. */
	public static function ajax_save() {
		self::guard();
		$settings = self::settings();
		$alloc    = self::post_allocation( $settings['allocation'] );

		$next = $settings;
		$next['allocation'] = $alloc;

		$cap = self::post_text( 'daily_cap' );
		if ( '' !== $cap && is_numeric( $cap ) ) {
			$next['daily_cap'] = max( 0, (int) $cap );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['x_handle'] ) ) {
			$next['x_handle'] = ltrim( self::post_text( 'x_handle' ), '@' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['alternate_hosts'] ) ) {
			$hosts = array();
			foreach ( preg_split( '/[\s,]+/', self::post_text( 'alternate_hosts' ) ) as $h ) {
				$h = strtolower( trim( $h ) );
				$h = preg_replace( '#^https?://#', '', $h );
				$h = trim( (string) $h, '/' );
				if ( '' !== $h && preg_match( '/^[a-z0-9.-]+$/', $h ) ) {
					$hosts[] = $h;
				}
			}
			$next['alternate_hosts'] = array_values( array_unique( $hosts ) );
		}

		TWTAEO_Visibility_Store::save_settings( $next );
		$counts = TWTAEO_Visibility_Inputs::catalog_counts();
		$totals = TWTAEO_Visibility_Questions::allocation_totals( $alloc, is_array( $counts ) ? $counts : array() );
		wp_send_json_success( array(
			'allocation' => $alloc,
			'totals'     => $totals,
			'message'    => __( 'Allocation saved. Runs will use these numbers until you change them.', 'twt-aeo-ultimate' ),
		) );
	}

	/** Seed a labelled sample run from the site's real catalogue. */
	public static function ajax_demo() {
		self::guard();
		$run = TWTAEO_Visibility_Store::create_demo_run();
		if ( is_wp_error( $run ) ) {
			wp_send_json_error( array( 'error' => $run->get_error_message() ) );
		}
		$id = is_array( $run ) && isset( $run['id'] ) ? (string) $run['id'] : '';
		wp_send_json_success( array( 'run_id' => $id ) );
	}

	/** Remove every sample run. Real runs are untouched. */
	public static function ajax_undemo() {
		self::guard();
		$n = TWTAEO_Visibility_Store::delete_demo_runs();
		wp_send_json_success( array( 'removed' => is_numeric( $n ) ? (int) $n : null ) );
	}

	/** Forget the merchant's ticks; runs go back to the templates. */
	public static function ajax_reset_questions() {
		self::guard();
		TWTAEO_Visibility_Store::reset_questions();
		wp_send_json_success( array( 'message' => __( 'Question set reset to the templates.', 'twt-aeo-ultimate' ) ) );
	}

	/**
	 * Rephrase awkward post questions with the cheap-tier AI client. Post titles
	 * dropped into templates read clumsily ("What is What to Put on Your Website
	 * to Get Found?") — this sends `title + current question` for a slice of the
	 * saved set to the merchant's enrichment provider and stores the natural
	 * rewrite with source `ai`. Works ONLY on the saved set (nothing invisible
	 * runs), only on post-level questions, and keeps ticks and ids — verdicts
	 * stay comparable across runs because ids never change.
	 *
	 * Paged by `offset` so 100+ posts do not sit inside one PHP request: each
	 * call handles POLISH_CHUNK candidates and answers { processed, remaining,
	 * changed, html }; the page loops until remaining is 0. An item the model
	 * mangles (bad JSON, empty, no question mark) keeps its template text.
	 */
	public static function ajax_polish_questions() {
		self::guard();
		if ( ! class_exists( 'TWTAEO_AI_Client' ) ) {
			wp_send_json_error( array( 'error' => __( 'The AI client is not available on this install.', 'twt-aeo-ultimate' ) ) );
		}
		$saved = TWTAEO_Visibility_Store::load_questions();
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			wp_send_json_error( array( 'error' => __( 'Build and save the question list first — polishing works on the saved set.', 'twt-aeo-ultimate' ) ) );
		}

		// Candidates: post-level questions still carrying template phrasing.
		$candidates = array();
		foreach ( $saved as $i => $q ) {
			if ( is_array( $q ) && 'post' === ( $q['level'] ?? '' ) && 'template' === ( $q['source'] ?? 'template' ) ) {
				$candidates[] = $i;
			}
		}
		$offset = self::post_int( 'offset', 0 );
		$slice  = array_slice( $candidates, $offset, self::POLISH_CHUNK );
		if ( empty( $slice ) ) {
			wp_send_json_success( array(
				'processed' => 0,
				'remaining' => 0,
				'changed'   => 0,
				'html'      => TWTAEO_Page_AI_Visibility::render_question_list( $saved ),
			) );
		}

		$items = array();
		foreach ( $slice as $i ) {
			$items[] = array(
				'id'       => (string) $saved[ $i ]['id'],
				'title'    => (string) ( $saved[ $i ]['scope_label'] ?? '' ),
				'question' => (string) $saved[ $i ]['text'],
			);
		}
		$prompt = implode( "\n", array(
			'Each item below is a question auto-generated by dropping a blog post title into a template, so some read awkwardly (e.g. "What is What to Put on Your Website to Get Found?").',
			'For EVERY item, return the question a real person would type into an AI assistant to find what that article covers. Keep the article\'s key topic words so the subject stays searchable. One sentence, plain text, ending with a question mark. If the existing question already reads naturally, return it unchanged.',
			'Reply with ONLY this JSON, one entry per input item: {"questions":[{"id":"...","question":"..."}]}',
			'',
			wp_json_encode( array( 'items' => $items ) ),
		) );
		$raw = TWTAEO_AI_Client::complete( '', $prompt, array(
			'max_tokens'  => 2000,
			'temperature' => 0,
			'json'        => true,
			'system'      => 'You rewrite awkward auto-generated questions into natural questions. You reply with only JSON.',
		) );
		if ( is_wp_error( $raw ) ) {
			wp_send_json_error( array( 'error' => $raw->get_error_message() ) );
		}
		$decoded = TWTAEO_AI_Client::extract_json( $raw );
		if ( is_wp_error( $decoded ) ) {
			wp_send_json_error( array( 'error' => $decoded->get_error_message() ) );
		}

		$by_id = array();
		$rows  = isset( $decoded['questions'] ) && is_array( $decoded['questions'] ) ? $decoded['questions'] : array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['id'], $row['question'] ) ) {
				$by_id[ (string) $row['id'] ] = sanitize_text_field( wp_strip_all_tags( (string) $row['question'] ) );
			}
		}

		$changed = 0;
		foreach ( $slice as $i ) {
			$id   = (string) $saved[ $i ]['id'];
			$text = isset( $by_id[ $id ] ) ? trim( $by_id[ $id ] ) : '';
			$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
			if ( '' === $text || $len < 10 || $len > 200 || '?' !== substr( $text, -1 ) ) {
				continue; // Mangled rewrite — the template text stays.
			}
			if ( $text !== (string) $saved[ $i ]['text'] ) {
				$saved[ $i ]['text']   = $text;
				$saved[ $i ]['source'] = 'ai';
				$changed++;
			}
		}
		if ( $changed > 0 ) {
			TWTAEO_Visibility_Store::save_questions( $saved );
		}

		wp_send_json_success( array(
			'processed'   => count( $slice ),
			// Rewritten items left the candidate pool (source is now `ai`), so
			// the next slice starts after only the ones that kept template text.
			'next_offset' => $offset + count( $slice ) - $changed,
			'remaining'   => max( 0, count( $candidates ) - $offset - count( $slice ) ),
			'changed'     => $changed,
			'html'        => TWTAEO_Page_AI_Visibility::render_question_list( $saved ),
		) );
	}

	/**
	 * Save the question set: the hidden `questions` JSON is the list, and a
	 * `q_<id>` field present means ticked. Missing means unticked — collapsed
	 * levels on the page post hidden `q_<id>` inputs for their enabled rows so
	 * a save from a half-expanded list keeps them.
	 */
	public static function ajax_save_questions() {
		self::guard();
		// The payload is a JSON document — sanitizing the raw string would corrupt
		// it, so it travels unfiltered exactly this far and every decoded field is
		// sanitized individually below.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() ran check_ajax_referer; fields sanitized after decode.
		$raw  = isset( $_POST['questions'] ) ? wp_unslash( $_POST['questions'] ) : '';
		$list = json_decode( is_string( $raw ) ? $raw : '', true );
		if ( ! is_array( $list ) || empty( $list ) ) {
			wp_send_json_error( array( 'error' => __( 'Build the question list first, then save it.', 'twt-aeo-ultimate' ) ) );
		}
		$ticked = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		foreach ( array_keys( $_POST ) as $key ) {
			if ( 0 === strpos( (string) $key, 'q_' ) ) {
				$ticked[ substr( (string) $key, 2 ) ] = true;
			}
		}
		$clean   = array();
		$enabled = 0;
		foreach ( $list as $q ) {
			if ( ! is_array( $q ) || empty( $q['id'] ) || empty( $q['text'] ) ) {
				continue;
			}
			$id    = sanitize_text_field( (string) $q['id'] );
			$level = sanitize_key( isset( $q['level'] ) ? (string) $q['level'] : '' );
			if ( ! in_array( $level, TWTAEO_Visibility_Types::LEVELS, true ) ) {
				continue;
			}
			$truth = null;
			if ( isset( $q['truth'] ) && is_array( $q['truth'] ) && ! empty( $q['truth']['statement'] ) ) {
				$truth = array(
					'kind'      => sanitize_key( isset( $q['truth']['kind'] ) ? (string) $q['truth']['kind'] : '' ),
					'statement' => sanitize_text_field( (string) $q['truth']['statement'] ),
				);
			}
			$row = array(
				'id'          => $id,
				'level'       => $level,
				'scope_id'    => sanitize_text_field( isset( $q['scope_id'] ) ? (string) $q['scope_id'] : '' ),
				'scope_label' => sanitize_text_field( isset( $q['scope_label'] ) ? (string) $q['scope_label'] : '' ),
				'family'      => sanitize_key( isset( $q['family'] ) ? (string) $q['family'] : '' ),
				'text'        => sanitize_text_field( (string) $q['text'] ),
				'source'      => in_array( isset( $q['source'] ) ? $q['source'] : '', array( 'template', 'ai', 'merchant' ), true ) ? $q['source'] : 'template',
				'truth'       => $truth,
				'enabled'     => isset( $ticked[ $id ] ),
			);
			if ( $row['enabled'] ) {
				$enabled++;
			}
			$clean[] = $row;
		}
		if ( empty( $clean ) ) {
			wp_send_json_error( array( 'error' => __( 'Nothing to save.', 'twt-aeo-ultimate' ) ) );
		}
		TWTAEO_Visibility_Store::save_questions( $clean );
		wp_send_json_success( array(
			'total'   => count( $clean ),
			'enabled' => $enabled,
			'message' => sprintf(
				/* translators: 1: enabled count, 2: total count */
				__( '%1$d of %2$d questions enabled. Runs ask the enabled ones.', 'twt-aeo-ultimate' ),
				$enabled,
				count( $clean )
			),
		) );
	}
}
