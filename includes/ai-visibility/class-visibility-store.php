<?php
/**
 * AI Visibility — persistence and the run queue.
 *
 * Settings and the merchant's question set live in options (small, edited
 * rarely). Runs and their checks live in TWO CUSTOM TABLES, and here is why:
 * one run is questions × engines checks — a Standard allocation on a modest
 * catalogue is several hundred rows, each carrying a 300-char excerpt, the
 * cited URLs and the domain list; twelve runs are kept. Options autoload on
 * every admin page load (and `wp_options` rows are compared whole on every
 * update), so that would be megabytes re-read and re-written per step. The
 * plugin already has one custom-table precedent (TWTAEO_Merchant_Sync_Queue),
 * built the same way: `dbDelta()` behind a version-gated `maybe_create_tables()`.
 *
 * Run mechanics (from docs/ai-visibility-plan.md): a run is a persisted queue
 * advanced ONE check per request by the page's client loop — budget first
 * (pause, don't fail), then ask, judge, record. A reload never loses results
 * or re-spends budget. Every timestamp is UTC ISO-8601 in the arrays and a UTC
 * DATETIME in the tables.
 *
 * Run array: [ id, started_at, finished_at|null, allocation, questions[],
 *   queue[ { question_id, engine } ], cursor, checks[], status, message|null, demo ]
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility_Store {

	/** Settings option defaults (the allocation is the Standard preset; engines [] = "every engine with a key"). */
	const DEFAULT_X_HANDLE = '';

	private function __construct() {}

	/* ──────────────────────────── tables ──────────────────────────── */

	public static function runs_table() {
		global $wpdb;
		return $wpdb->prefix . TWTAEO_Visibility_Types::TABLE_RUNS;
	}

	public static function checks_table() {
		global $wpdb;
		return $wpdb->prefix . TWTAEO_Visibility_Types::TABLE_CHECKS;
	}

	/**
	 * Create or upgrade both tables. Idempotent: dbDelta() only issues the
	 * statements needed, and the OPTION_DB_VERSION gate skips it entirely once
	 * this version has run. Safe to call from activation, the page, and AJAX.
	 *
	 * Column names avoid MySQL reserved words on purpose (`cursor_pos`, not
	 * `cursor`; `checked_at`, not `at`) so no query needs back-ticks.
	 *
	 * @param bool $force Re-run dbDelta even when the version matches.
	 */
	public static function maybe_create_tables( $force = false ) {
		global $wpdb;
		$have = (string) get_option( TWTAEO_Visibility_Types::OPTION_DB_VERSION, '' );
		if ( ! $force && $have === (string) TWTAEO_Visibility_Types::DB_VERSION && self::tables_exist() ) {
			return;
		}
		$charset = $wpdb->get_charset_collate();
		$runs    = self::runs_table();
		$checks  = self::checks_table();

		// dbDelta() is the WordPress-sanctioned table builder; it runs its own
		// query, keeping this off the raw $wpdb->query() path.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$runs} (
			id varchar(64) NOT NULL,
			started_at datetime NOT NULL,
			finished_at datetime NULL,
			status varchar(16) NOT NULL DEFAULT 'running',
			demo tinyint(1) NOT NULL DEFAULT 0,
			allocation longtext NULL,
			questions longtext NULL,
			queue longtext NULL,
			cursor_pos int(11) NOT NULL DEFAULT 0,
			message text NULL,
			summary longtext NULL,
			persona text NULL,
			PRIMARY KEY  (id),
			KEY started_at (started_at)
		) {$charset};" );
		dbDelta( "CREATE TABLE {$checks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id varchar(64) NOT NULL,
			question_id varchar(191) NOT NULL,
			engine varchar(16) NOT NULL,
			checked_at datetime NOT NULL,
			verdict varchar(16) NOT NULL DEFAULT 'unavailable',
			accuracy varchar(16) NOT NULL DEFAULT 'n/a',
			cited_urls longtext NULL,
			our_urls longtext NULL,
			domains longtext NULL,
			owned_domains longtext NULL,
			citation_surface varchar(16) NOT NULL DEFAULT '',
			brand_hits longtext NULL,
			mentions longtext NULL,
			excerpt text NULL,
			answer longtext NULL,
			latency_ms int(11) NOT NULL DEFAULT 0,
			error text NULL,
			model varchar(64) NOT NULL DEFAULT '',
			tools varchar(191) NOT NULL DEFAULT '',
			follow_ups longtext NULL,
			follow_ups_error text NULL,
			turn tinyint(3) unsigned NOT NULL DEFAULT 1,
			asked text NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY run_engine (run_id, engine)
		) {$charset};" );

		update_option( TWTAEO_Visibility_Types::OPTION_DB_VERSION, (string) TWTAEO_Visibility_Types::DB_VERSION, false );
	}

	/** True when both tables are present (cheap SHOW TABLES). */
	public static function tables_exist() {
		global $wpdb;
		foreach ( array( self::runs_table(), self::checks_table() ) as $t ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) );
			if ( $found !== $t ) {
				return false;
			}
		}
		return true;
	}

	/** Drop both tables and the options (uninstall helper; not called by the plugin itself). */
	public static function drop_tables() {
		global $wpdb;
		foreach ( array( self::checks_table(), self::runs_table() ) as $t ) {
			// A trailing annotation overrides an own-line one, so every sniff for
			// this statement has to travel on the statement's own line.
			$wpdb->query( "DROP TABLE IF EXISTS `{$t}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- uninstall helper; table name from $wpdb->prefix + a class constant.
		}
		delete_option( TWTAEO_Visibility_Types::OPTION_DB_VERSION );
	}

	/* ─────────────────────────── settings ─────────────────────────── */

	public static function default_allocation() {
		$a            = TWTAEO_Visibility_Types::PRESETS['standard'];
		$a['engines'] = array();
		return $a;
	}

	public static function default_settings() {
		return array(
			'allocation'      => self::default_allocation(),
			'daily_cap'       => (int) TWTAEO_Visibility_Types::DEFAULT_DAILY_CAP,
			'x_handle'        => self::DEFAULT_X_HANDLE,
			'alternate_hosts' => array(),
			'company_urls'    => array(),
			'brand_items'     => array(),
			'personas'        => array(),
			'personas_on'     => true,
		);
	}

	/** @return array { allocation, daily_cap, x_handle, alternate_hosts[], company_urls[], brand_items[], personas[], personas_on } */
	public static function get_settings() {
		$stored = get_option( TWTAEO_Visibility_Types::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$s = self::default_settings();
		if ( isset( $stored['allocation'] ) && is_array( $stored['allocation'] ) ) {
			$s['allocation'] = self::clamp_allocation( array_merge( self::default_allocation(), $stored['allocation'] ) );
		}
		if ( isset( $stored['daily_cap'] ) && (int) $stored['daily_cap'] > 0 ) {
			$s['daily_cap'] = (int) $stored['daily_cap'];
		}
		if ( isset( $stored['x_handle'] ) ) {
			$s['x_handle'] = ltrim( sanitize_text_field( (string) $stored['x_handle'] ), '@' );
		}
		if ( isset( $stored['alternate_hosts'] ) ) {
			$s['alternate_hosts'] = self::clean_hosts( $stored['alternate_hosts'] );
		}
		if ( isset( $stored['company_urls'] ) ) {
			$s['company_urls'] = self::clean_property_urls( $stored['company_urls'] );
		}
		if ( isset( $stored['brand_items'] ) ) {
			$s['brand_items'] = self::clean_brand_items( $stored['brand_items'] );
		}
		if ( isset( $stored['personas'] ) && class_exists( 'TWTAEO_Visibility_Personas' ) ) {
			$s['personas'] = TWTAEO_Visibility_Personas::clean( $stored['personas'] );
		}
		if ( isset( $stored['personas_on'] ) ) {
			$s['personas_on'] = (bool) $stored['personas_on'];
		}
		return $s;
	}

	/**
	 * Merge and save. Only known keys are written; the allocation is clamped.
	 *
	 * @param array $settings Any subset of { allocation, daily_cap, x_handle, alternate_hosts, company_urls, brand_items, personas, personas_on }.
	 * @return array The saved settings.
	 */
	public static function save_settings( array $settings ) {
		$current = self::get_settings();
		if ( isset( $settings['allocation'] ) && is_array( $settings['allocation'] ) ) {
			$current['allocation'] = self::clamp_allocation( array_merge( $current['allocation'], $settings['allocation'] ) );
		}
		if ( isset( $settings['daily_cap'] ) ) {
			$cap = (int) $settings['daily_cap'];
			$current['daily_cap'] = $cap > 0 ? min( 100000, $cap ) : (int) TWTAEO_Visibility_Types::DEFAULT_DAILY_CAP;
		}
		if ( array_key_exists( 'x_handle', $settings ) ) {
			$current['x_handle'] = ltrim( sanitize_text_field( (string) $settings['x_handle'] ), '@' );
		}
		if ( array_key_exists( 'alternate_hosts', $settings ) ) {
			$current['alternate_hosts'] = self::clean_hosts( $settings['alternate_hosts'] );
		}
		if ( array_key_exists( 'company_urls', $settings ) ) {
			$current['company_urls'] = self::clean_property_urls( $settings['company_urls'] );
		}
		if ( array_key_exists( 'brand_items', $settings ) ) {
			$current['brand_items'] = self::clean_brand_items( $settings['brand_items'] );
		}
		if ( array_key_exists( 'personas', $settings ) && class_exists( 'TWTAEO_Visibility_Personas' ) ) {
			$current['personas'] = TWTAEO_Visibility_Personas::clean( $settings['personas'] );
		}
		if ( array_key_exists( 'personas_on', $settings ) ) {
			$current['personas_on'] = (bool) $settings['personas_on'];
		}
		update_option( TWTAEO_Visibility_Types::OPTION_SETTINGS, $current, false );
		return $current;
	}

	/** Clamp through the pure class when it is loaded; a local floor/ceiling otherwise. */
	public static function clamp_allocation( array $alloc ) {
		if ( class_exists( 'TWTAEO_Visibility_Questions' ) && method_exists( 'TWTAEO_Visibility_Questions', 'clamp_allocation' ) ) {
			$out = TWTAEO_Visibility_Questions::clamp_allocation( $alloc );
			if ( is_array( $out ) ) {
				return $out;
			}
		}
		$out = array();
		foreach ( TWTAEO_Visibility_Types::ALLOCATION_MAX as $k => $max ) {
			$v         = isset( $alloc[ $k ] ) ? (int) $alloc[ $k ] : 0;
			$out[ $k ] = max( 0, min( (int) $max, $v ) );
		}
		$engines = array();
		foreach ( (array) ( isset( $alloc['engines'] ) ? $alloc['engines'] : array() ) as $e ) {
			$e = (string) $e;
			if ( isset( TWTAEO_Visibility_Types::ENGINES[ $e ] ) && ! in_array( $e, $engines, true ) ) {
				$engines[] = $e;
			}
		}
		$out['engines'] = $engines;
		return $out;
	}

	private static function clean_hosts( $hosts ) {
		if ( is_string( $hosts ) ) {
			$hosts = preg_split( '/[\s,]+/', $hosts );
		}
		$out = array();
		foreach ( (array) $hosts as $h ) {
			$h = strtolower( trim( (string) $h ) );
			$h = preg_replace( '#^https?://#i', '', $h );
			$h = preg_replace( '#/.*$#', '', (string) $h );
			if ( '' !== $h && preg_match( '/^[a-z0-9.-]+$/', $h ) && ! in_array( $h, $out, true ) ) {
				$out[] = $h;
			}
		}
		return array_slice( $out, 0, 20 );
	}

	/* ─────────────────────────── questions ────────────────────────── */

	/** The merchant's edited set (ticks, added questions). Null when never saved. */
	public static function load_questions() {
		$stored = get_option( TWTAEO_Visibility_Types::OPTION_QUESTIONS, null );
		return is_array( $stored ) ? array_values( $stored ) : null;
	}

	/**
	 * Save the merchant's set. Rows need an id and a non-empty text; `enabled`
	 * defaults to true. Not autoloaded.
	 *
	 * @param array $questions
	 * @return array The cleaned set that was saved.
	 */
	public static function save_questions( array $questions ) {
		$clean = array();
		foreach ( $questions as $q ) {
			if ( ! is_array( $q ) || ! isset( $q['id'], $q['text'] ) || ! is_string( $q['id'] ) || ! is_string( $q['text'] ) ) {
				continue;
			}
			$text = trim( wp_strip_all_tags( $q['text'] ) );
			if ( '' === $text ) {
				continue;
			}
			$q['id']      = sanitize_text_field( $q['id'] );
			$q['text']    = $text;
			$q['enabled'] = ! ( isset( $q['enabled'] ) && ( false === $q['enabled'] || 0 === $q['enabled'] || '0' === $q['enabled'] || 'false' === $q['enabled'] ) );
			$q['level']   = isset( $q['level'] ) && in_array( $q['level'], TWTAEO_Visibility_Types::LEVELS, true ) ? $q['level'] : 'company';
			if ( ! isset( $q['scope_id'] ) ) {
				$q['scope_id'] = '';
			}
			$clean[] = $q;
		}
		update_option( TWTAEO_Visibility_Types::OPTION_QUESTIONS, $clean, false );
		return $clean;
	}

	public static function reset_questions() {
		delete_option( TWTAEO_Visibility_Types::OPTION_QUESTIONS );
	}

	/**
	 * Carry the merchant's saved choices onto a freshly built list, by id: a
	 * question they switched off stays off, and AI-polished or hand-edited text
	 * survives the rebuild. Questions the saved set never saw (a raised count, a
	 * new post) come through enabled, as built.
	 *
	 * @param array      $fresh Questions from build_questions() for the current allocation.
	 * @param array|null $saved The stored set, or null.
	 * @return array
	 */
	public static function merge_saved_questions( array $fresh, $saved ) {
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			return array_values( $fresh );
		}
		$enabled_by_id = array();
		$text_by_id    = array();
		foreach ( $saved as $q ) {
			if ( ! is_array( $q ) || ! isset( $q['id'] ) ) {
				continue;
			}
			$enabled_by_id[ (string) $q['id'] ] = ! empty( $q['enabled'] );
			if ( in_array( $q['source'] ?? '', array( 'ai', 'merchant' ), true ) && ! empty( $q['text'] ) ) {
				$text_by_id[ (string) $q['id'] ] = array( 'text' => (string) $q['text'], 'source' => (string) $q['source'] );
			}
		}
		foreach ( $fresh as &$q ) {
			if ( ! is_array( $q ) || ! isset( $q['id'] ) ) {
				continue;
			}
			$id = (string) $q['id'];
			if ( array_key_exists( $id, $enabled_by_id ) ) {
				$q['enabled'] = $enabled_by_id[ $id ];
			}
			if ( isset( $text_by_id[ $id ] ) ) {
				$q['text']   = $text_by_id[ $id ]['text'];
				$q['source'] = $text_by_id[ $id ]['source'];
			}
		}
		unset( $q );
		return array_values( $fresh );
	}

	/**
	 * Apply the allocation counts to a saved set: enabled questions only, and
	 * per scope no more than the allocation allows, in the saved order so the
	 * merchant's ordering wins.
	 */
	public static function select_saved_questions( array $saved, array $alloc ) {
		$a     = self::clamp_allocation( $alloc );
		$limit = array(
			'company'    => isset( $a['company'] ) ? (int) $a['company'] : 0,
			'brand'      => isset( $a['per_brand'] ) ? (int) $a['per_brand'] : 0,
			'collection' => isset( $a['per_collection'] ) ? (int) $a['per_collection'] : 0,
			'type'       => isset( $a['per_type'] ) ? (int) $a['per_type'] : 0,
			'product'    => isset( $a['per_product'] ) ? (int) $a['per_product'] : 0,
			'post'       => isset( $a['per_post'] ) ? (int) $a['per_post'] : 0,
		);
		$used = array();
		$out  = array();
		foreach ( $saved as $q ) {
			if ( ! is_array( $q ) || empty( $q['enabled'] ) ) {
				continue;
			}
			$level = isset( $q['level'] ) ? (string) $q['level'] : 'company';
			$key   = $level . ':' . ( isset( $q['scope_id'] ) ? (string) $q['scope_id'] : '' );
			$n     = isset( $used[ $key ] ) ? $used[ $key ] : 0;
			if ( $n >= ( isset( $limit[ $level ] ) ? $limit[ $level ] : 0 ) ) {
				continue;
			}
			$used[ $key ] = $n + 1;
			$out[]        = $q;
		}
		return $out;
	}

	/* ───────────────────────────── runs ───────────────────────────── */

	/**
	 * Open a run: snapshot the questions, queue every (question × engine-with-
	 * a-key) pair, persist. Nothing is asked here — the client loop calls
	 * `step_run()` once per check.
	 *
	 * @param array $alloc Allocation; `engines` [] means every engine with a key.
	 * @param array $opts  { max_questions?: int, sample?: bool (first engine only), persona?: Persona|null, journey?: int follow-up turns 0..JOURNEY_MAX }
	 * @return array Run
	 */
	public static function start_run( array $alloc, array $opts = array() ) {
		self::maybe_create_tables();
		$a = self::clamp_allocation( $alloc );

		$available = array();
		foreach ( TWTAEO_Visibility_Engines::availability() as $row ) {
			if ( ! empty( $row['available'] ) ) {
				$available[] = $row['engine'];
			}
		}
		$wanted  = ! empty( $a['engines'] ) ? $a['engines'] : TWTAEO_Visibility_Types::ENGINES_V1;
		$engines = array_values( array_intersect( $wanted, $available ) );
		if ( ! empty( $opts['sample'] ) ) {
			$engines = array_slice( $engines, 0, 1 );
		}

		// Always build from the allocation being run, then lay the saved ticks
		// and text over it. Selecting from the saved set alone froze a run at
		// whatever counts were in force when the list was last saved — raising
		// "per post" or "per brand" afterwards changed nothing.
		$input     = TWTAEO_Visibility_Inputs::load( $a );
		$questions = self::merge_saved_questions( self::build_questions( is_array( $input ) ? $input : array(), $a ), self::load_questions() );
		$questions = self::select_saved_questions( $questions, $a );
		if ( isset( $opts['max_questions'] ) && is_numeric( $opts['max_questions'] ) && (int) $opts['max_questions'] >= 0 ) {
			$questions = array_slice( $questions, 0, (int) $opts['max_questions'] );
		}
		$questions = array_values( $questions );

		$queue = array();
		foreach ( $questions as $q ) {
			foreach ( $engines as $engine ) {
				$queue[] = array( 'question_id' => (string) $q['id'], 'engine' => (string) $engine );
			}
		}

		$now               = self::now_iso();
		$alloc_with_engine = $a;
		$alloc_with_engine['engines'] = $engines;
		$alloc_with_engine['journey'] = isset( $opts['journey'] ) ? max( 0, min( (int) TWTAEO_Visibility_Types::JOURNEY_MAX, (int) $opts['journey'] ) ) : 0;
		$run = array(
			'id'          => wp_generate_uuid4(),
			'started_at'  => $now,
			'finished_at' => null,
			'allocation'  => $alloc_with_engine,
			'questions'   => $questions,
			'queue'       => $queue,
			'cursor'      => 0,
			'checks'      => array(),
			'journey'     => array(),
			'status'      => ! empty( $queue ) ? 'running' : 'done',
			'message'     => null,
			'demo'        => false,
			// Copied, not referenced: the run is stepped by cron with nobody
			// logged in, and a persona renamed or removed mid-run must not
			// change what the rest of the run is asked as.
			'persona'     => self::run_persona( isset( $opts['persona'] ) ? $opts['persona'] : null ),
		);
		if ( empty( $queue ) ) {
			$run['finished_at'] = $now;
			$run['message']     = empty( $engines )
				? __( 'No engine has a key yet — add one in Settings.', 'twt-aeo-ultimate' )
				: __( 'No questions to ask — raise the allocation or enable some questions.', 'twt-aeo-ultimate' );
		}
		self::save_run( $run );
		return $run;
	}

	/**
	 * A run with its checks assembled from the checks table. Null when unknown.
	 *
	 * @param string $id
	 * @return array|null
	 */
	public static function get_run( $id ) {
		global $wpdb;
		$id = (string) $id;
		if ( '' === $id || ! self::tables_exist() ) {
			return null;
		}
		$runs = self::runs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom table name built from $wpdb->prefix + a class constant; never user input.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$runs} WHERE id = %s", $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$run = self::row_to_run( $row );
		foreach ( self::load_checks( $id ) as $c ) {
			if ( $c['turn'] > 1 ) {
				$run['journey'][] = $c;
			} else {
				$run['checks'][] = $c;
			}
		}
		return $run;
	}

	/** Summaries newest first, from the `summary` column (computed and stored when missing). */
	public static function list_runs() {
		global $wpdb;
		if ( ! self::tables_exist() ) {
			return array();
		}
		$runs = self::runs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom table name built from $wpdb->prefix + a class constant; never user input.
		$rows = $wpdb->get_results( "SELECT id, summary FROM {$runs} ORDER BY started_at DESC, id DESC", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$summary = null;
			if ( isset( $row['summary'] ) && '' !== (string) $row['summary'] ) {
				$summary = json_decode( (string) $row['summary'], true );
			}
			if ( ! is_array( $summary ) || ! isset( $summary['id'] ) ) {
				$run = self::get_run( $row['id'] );
				if ( ! $run ) {
					continue;
				}
				$summary = self::summarize( $run );
				self::store_summary( $run['id'], $summary );
			}
			$out[] = $summary;
		}
		return $out;
	}

	/**
	 * Advance a run by ONE check. Budget first (pause, don't fail), then ask,
	 * judge, record.
	 *
	 * @param string $id
	 * @return array { ok, done?, check?, run?: summary, cursor?, total?, paused?, needs_key?, error? }
	 */
	public static function step_run( $id ) {
		$run = self::get_run( $id );
		if ( ! $run ) {
			return array( 'ok' => false, 'error' => __( 'That run no longer exists.', 'twt-aeo-ultimate' ) );
		}
		$total = count( $run['queue'] );
		if ( 'done' === $run['status'] || 'stopped' === $run['status'] ) {
			return array( 'ok' => true, 'done' => true, 'run' => self::summarize( $run ), 'cursor' => (int) $run['cursor'], 'total' => $total );
		}

		$step = self::next_step( $run );
		if ( null === $step ) {
			$run['status']      = 'done';
			$run['finished_at'] = $run['finished_at'] ? $run['finished_at'] : self::now_iso();
			self::save_run( $run );
			self::prune_runs( (int) TWTAEO_Visibility_Types::RUNS_KEPT );
			return array( 'ok' => true, 'done' => true, 'run' => self::summarize( $run ), 'cursor' => (int) $run['cursor'], 'total' => $total );
		}

		$state = TWTAEO_Visibility_Budget::check();
		if ( empty( $state['allowed'] ) ) {
			$run['status']  = 'paused';
			$run['message'] = TWTAEO_Visibility_Budget::paused_message( $state );
			self::save_run( $run );
			return array( 'ok' => false, 'paused' => true, 'error' => $run['message'], 'run' => self::summarize( $run ), 'cursor' => (int) $run['cursor'], 'total' => $total );
		}

		$engine = (string) $step['engine'];
		$key    = TWTAEO_Visibility_Engines::key_for( $engine );
		if ( '' === $key ) {
			$label = isset( TWTAEO_Visibility_Types::ENGINES[ $engine ]['label'] ) ? TWTAEO_Visibility_Types::ENGINES[ $engine ]['label'] : $engine;
			return array(
				'ok'        => false,
				'needs_key' => true,
				/* translators: %s: engine name */
				'error'     => sprintf( __( 'No key for %s — add it in Settings.', 'twt-aeo-ultimate' ), $label ),
			);
		}

		$question = null;
		foreach ( $run['questions'] as $q ) {
			if ( is_array( $q ) && isset( $q['id'] ) && (string) $q['id'] === (string) $step['question_id'] ) {
				$question = $q;
				break;
			}
		}
		$turn = isset( $step['turn'] ) ? max( 1, (int) $step['turn'] ) : 1;

		$key_rejected = false;
		$check        = array(
			'question_id' => (string) $step['question_id'],
			'engine'      => $engine,
			'at'          => self::now_iso(),
			'verdict'     => 'unavailable',
			'accuracy'    => 'n/a',
			'cited_urls'  => array(),
			'our_urls'         => array(),
			'domains'          => array(),
			'owned_domains'    => array(),
			'citation_surface' => '',
			'brand_hits'       => array(),
			'mentions'         => array(),
			'excerpt'          => '',
			'answer'           => '',
			'latency_ms'  => 0,
			'error'       => null,
			'model'       => isset( TWTAEO_Visibility_Types::MODELS[ $engine ] ) ? TWTAEO_Visibility_Types::MODELS[ $engine ] : '',
			'tools'       => '',
			'follow_ups'       => array(),
			'follow_ups_error' => null,
			'turn'             => $turn,
			'asked'            => '',
		);
		$ask_opts = ! empty( $run['persona']['label'] ) ? array( 'persona' => (string) $run['persona']['label'] ) : array();

		// Journey mode: a later turn asks the engine's own top follow-up from
		// the turn before, with the conversation so far as history.
		$asked = null === $question ? '' : (string) $question['text'];
		if ( null !== $question && $turn > 1 ) {
			$chain = self::conversation( $run, $question, $engine, $turn );
			$last  = end( $chain );
			$asked = $last && ! empty( $last['follow_ups'] ) ? (string) $last['follow_ups'][0] : '';
			$check['asked']      = $asked;
			$ask_opts['history'] = array_map(
				static function ( $t ) {
					return array( 'q' => $t['q'], 'a' => $t['a'] );
				},
				$chain
			);
		}

		if ( null === $question ) {
			$check['error'] = __( 'Question missing from the run snapshot.', 'twt-aeo-ultimate' );
		} elseif ( '' === $asked ) {
			$check['error'] = __( 'Nothing to follow up on: the turn before recorded no follow-up question.', 'twt-aeo-ultimate' );
		} else {
			$answer              = TWTAEO_Visibility_Engines::ask_with_citations( $engine, $key, $asked, $ask_opts );
			$check['latency_ms'] = (int) $answer['latency_ms'];
			$check['cited_urls'] = $answer['cited_urls'];
			$check['excerpt']    = self::excerpt( $answer['text'] );
			$check['answer']     = (string) $answer['text'];
			$check['model']      = (string) $answer['model'];
			$check['tools']      = implode( ',', (array) $answer['tools'] );
			if ( ! empty( $answer['error'] ) ) {
				$check['error'] = (string) $answer['error'];
				// The key itself was refused: every other question for this
				// engine would fail the same way, so skip them this run.
				$key_rejected = TWTAEO_Visibility_Engines::is_key_error( $answer['error'] );
			} else {
				$ctx               = self::verdict_context();
				$judged            = self::judge_verdict( array( 'text' => $answer['text'], 'cited_urls' => $answer['cited_urls'] ), $ctx );
				$check['verdict']          = $judged['verdict'];
				$check['our_urls']         = $judged['our_urls'];
				$check['domains']          = $judged['domains'];
				$check['owned_domains']    = isset( $judged['owned_domains'] ) ? $judged['owned_domains'] : array();
				$check['citation_surface'] = isset( $judged['citation_surface'] ) ? $judged['citation_surface'] : '';
				$check['brand_hits']       = isset( $judged['brand_hits'] ) ? $judged['brand_hits'] : array();
				$check['mentions']         = isset( $judged['mentions'] ) ? $judged['mentions'] : array();
				// The known fact belongs to the original question, not to a follow-up.
				if ( 1 === $turn && ! empty( $question['truth'] ) && is_array( $question['truth'] ) && 'unavailable' !== $check['verdict'] ) {
					$check['accuracy'] = TWTAEO_Visibility_Engines::judge_accuracy( $question['truth'], $answer['text'] );
				}
				// Same engine, same persona, no search. A failure here leaves the
				// verdict alone -- the check stands with its reason beside it.
				if ( '' !== trim( (string) $answer['text'] ) ) {
					$follow                    = TWTAEO_Visibility_Engines::follow_ups( $engine, $key, $asked, (string) $answer['text'], $ask_opts );
					$check['follow_ups']       = $follow['follow_ups'];
					$check['follow_ups_error'] = $follow['error'];
				}
			}
		}

		self::insert_check( $run['id'], $check );
		if ( $turn > 1 ) {
			$run['journey'][] = $check;
		} else {
			$run['checks'][] = $check;
		}
		$run['cursor'] = (int) $run['cursor'] + 1;
		$run['status'] = 'running';

		// Continue the conversation straight away: the next turn goes right
		// after this one, so each question's whole conversation finishes before
		// the next question starts. A run stopped halfway (or paused at the
		// daily cap) still holds complete conversations for everything it asked.
		$depth = isset( $run['allocation']['journey'] ) ? (int) $run['allocation']['journey'] : 0;
		if ( $turn <= $depth && null === $check['error'] && ! empty( $check['follow_ups'] ) ) {
			$queue = array_values( (array) $run['queue'] );
			array_splice(
				$queue,
				(int) $run['cursor'],
				0,
				array(
					array(
						'question_id' => (string) $step['question_id'],
						'engine'      => $engine,
						'turn'        => $turn + 1,
					),
				)
			);
			$run['queue'] = $queue;
			$total        = count( $run['queue'] );
		}
		if ( ! empty( $key_rejected ) ) {
			self::drop_engine( $run, $engine, (int) $run['cursor'] );
			$total = count( $run['queue'] );
		}
		$run['message'] = self::key_notice( $run );
		if ( 'unavailable' !== $check['verdict'] ) {
			TWTAEO_Visibility_Budget::record( 1 );
		}

		$done = $run['cursor'] >= $total;
		if ( $done ) {
			$run['status']      = 'done';
			$run['finished_at'] = self::now_iso();
		}
		self::save_run( $run );
		if ( $done ) {
			self::prune_runs( (int) TWTAEO_Visibility_Types::RUNS_KEPT );
			self::announce_finished( $run );
		}

		return array(
			'ok'     => true,
			'done'   => $done,
			'check'  => $check,
			'run'    => self::summarize( $run ),
			'cursor' => (int) $run['cursor'],
			'total'  => $total,
		);
	}

	/** Stop a running/paused run. Returns the run, or null when unknown. */
	public static function stop_run( $id ) {
		$run = self::get_run( $id );
		if ( ! $run ) {
			return null;
		}
		if ( 'running' === $run['status'] || 'paused' === $run['status'] ) {
			$run['status']      = 'stopped';
			$run['finished_at'] = self::now_iso();
			self::save_run( $run );
			self::announce_finished( $run );
		}
		return $run;
	}

	/**
	 * A run reached a terminal state ('done' or 'stopped').
	 *
	 * Listeners are handed the run whole -- questions, checks, allocation -- so
	 * nothing downstream has to re-read the tables to know what happened. Fired
	 * for a stopped run too: a partial run is still evidence, and a listener that
	 * wants only complete runs can read $run['status'].
	 *
	 * Sample-data runs are announced like any other; a listener that must not act
	 * on invented data checks $run['demo'] (the transmitter does).
	 *
	 * @param array $run The finished run.
	 */
	private static function announce_finished( array $run ) {
		/**
		 * Fires once when an AI Visibility run finishes.
		 *
		 * @param string $run_id Run id.
		 * @param array  $run    The whole run: questions, checks, allocation, status.
		 */
		do_action( 'twtaeo_visibility_run_finished', (string) $run['id'], $run );
	}

	/**
	 * Pause a running run with a message (e.g. the background worker found an
	 * engine's key gone mid-run). Paused runs stay resumable from the board.
	 *
	 * @param string $id
	 * @param string $message
	 * @return array|null The run, or null when it does not exist.
	 */
	public static function pause_run( $id, $message = '' ) {
		$run = self::get_run( $id );
		if ( ! $run ) {
			return null;
		}
		if ( 'running' === $run['status'] ) {
			$run['status']  = 'paused';
			$run['message'] = (string) $message;
			self::save_run( $run );
		}
		return $run;
	}

	/** Keep the newest `$keep` runs; a run still in flight is never pruned. */
	public static function prune_runs( $keep ) {
		global $wpdb;
		if ( ! self::tables_exist() ) {
			return 0;
		}
		$keep = max( 1, (int) $keep );
		$runs = self::runs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom table name built from $wpdb->prefix + a class constant; never user input.
		$rows = $wpdb->get_results( "SELECT id, status FROM {$runs} ORDER BY started_at DESC, id DESC", ARRAY_A );
		if ( count( (array) $rows ) <= $keep ) {
			return 0;
		}
		$kept    = 0;
		$removed = 0;
		foreach ( (array) $rows as $row ) {
			if ( $kept < $keep || in_array( $row['status'], array( 'running', 'paused' ), true ) ) {
				$kept++;
				continue;
			}
			self::delete_run( $row['id'] );
			$removed++;
		}
		return $removed;
	}

	/** Delete one run and its checks. */
	public static function delete_run( $id ) {
		global $wpdb;
		$id = (string) $id;
		if ( '' === $id ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables.
		$wpdb->delete( self::checks_table(), array( 'run_id' => $id ), array( '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables.
		$wpdb->delete( self::runs_table(), array( 'id' => $id ), array( '%s' ) );
	}

	/* ─────────────────────────── sample data ──────────────────────── */

	/**
	 * "Load sample data": a fabricated, LABELLED run built from the site's real
	 * categories, products and posts so the board looks like their store. All
	 * V1 engines appear regardless of keys — nothing is called. Replaces any
	 * earlier sample; never mixes into a real run.
	 *
	 * @return array Run
	 */
	public static function create_demo_run() {
		self::maybe_create_tables();
		self::delete_demo_runs();

		$alloc            = TWTAEO_Visibility_Types::DEMO_ALLOCATION;
		$alloc['engines'] = TWTAEO_Visibility_Types::ENGINES_V1;
		$input            = TWTAEO_Visibility_Inputs::load( $alloc );
		// Keep the sample readable: a handful of categories/types, a dozen products, a few posts.
		$input['collections'] = array_slice( $input['collections'], 0, 6 );
		$input['types']       = array_slice( $input['types'], 0, 6 );
		$input['products']    = array_slice( $input['products'], 0, 12 );
		$input['posts']       = array_slice( $input['posts'], 0, 8 );

		$questions = self::build_questions( $input, $alloc );
		$hosts     = isset( $input['site']['hosts'] ) ? (array) $input['site']['hosts'] : array();
		$name      = isset( $input['site']['name'] ) ? (string) $input['site']['name'] : '';
		$seed      = preg_replace( '/[^a-z0-9]/i', '', isset( $hosts[0] ) ? (string) $hosts[0] : '' );
		$seed      = '' !== $seed ? substr( $seed, 0, 24 ) : 'sample';

		$run = TWTAEO_Visibility_Sample::build_demo_run(
			$questions,
			TWTAEO_Visibility_Types::ENGINES_V1,
			array( 'hosts' => $hosts, 'site_name' => $name ),
			$seed,
			self::now_iso()
		);
		$run = self::normalize_run( $run );
		$run['demo'] = true;
		if ( '' === (string) $run['id'] ) {
			$run['id'] = 'demo-' . $seed;
		}
		$checks        = $run['checks'];
		$run['checks'] = array();
		self::save_run( $run );
		foreach ( $checks as $c ) {
			self::insert_check( $run['id'], $c );
		}
		$run['checks'] = $checks;
		self::store_summary( $run['id'], self::summarize( $run ) );
		return $run;
	}

	/** Remove every sample run. Real runs are untouched. @return int removed */
	public static function delete_demo_runs() {
		global $wpdb;
		if ( ! self::tables_exist() ) {
			return 0;
		}
		$runs = self::runs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom table name built from $wpdb->prefix + a class constant; never user input.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$runs} WHERE demo = 1 OR id LIKE %s", $wpdb->esc_like( 'demo-' ) . '%' ) );
		$n   = 0;
		foreach ( (array) $ids as $id ) {
			self::delete_run( $id );
			$n++;
		}
		return $n;
	}

	/* ─────────────────────────── internals ────────────────────────── */

	/** UTC ISO-8601, second precision. */
	public static function now_iso() {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	private static function to_sql_datetime( $iso ) {
		$iso = (string) $iso;
		if ( '' === $iso ) {
			return null;
		}
		$ts = strtotime( $iso );
		return false === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
	}

	private static function from_sql_datetime( $sql ) {
		$sql = (string) $sql;
		if ( '' === $sql || '0000-00-00 00:00:00' === $sql ) {
			return null;
		}
		$ts = strtotime( $sql . ' UTC' );
		return false === $ts ? null : gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}

	/** Coerce whatever the pure classes hand back into the canonical run shape. */
	private static function normalize_run( array $run ) {
		$out = array(
			'id'          => isset( $run['id'] ) ? (string) $run['id'] : '',
			'started_at'  => isset( $run['started_at'] ) ? (string) $run['started_at'] : self::now_iso(),
			'finished_at' => ! empty( $run['finished_at'] ) ? (string) $run['finished_at'] : null,
			'allocation'  => isset( $run['allocation'] ) && is_array( $run['allocation'] ) ? $run['allocation'] : array(),
			'questions'   => isset( $run['questions'] ) && is_array( $run['questions'] ) ? array_values( $run['questions'] ) : array(),
			'queue'       => array(),
			'cursor'      => isset( $run['cursor'] ) ? (int) $run['cursor'] : 0,
			'checks'      => array(),
			'journey'     => array(),
			'status'      => isset( $run['status'] ) ? (string) $run['status'] : 'done',
			'message'     => isset( $run['message'] ) && '' !== (string) $run['message'] ? (string) $run['message'] : null,
			'demo'        => ! empty( $run['demo'] ),
			'persona'     => self::run_persona( isset( $run['persona'] ) ? $run['persona'] : null ),
		);
		foreach ( (array) ( isset( $run['queue'] ) ? $run['queue'] : array() ) as $item ) {
			$pair = self::queue_pair( $item );
			if ( $pair ) {
				$out['queue'][] = $pair;
			}
		}
		foreach ( (array) ( isset( $run['checks'] ) ? $run['checks'] : array() ) as $c ) {
			if ( is_array( $c ) ) {
				$out['checks'][] = self::normalize_check( $c );
			}
		}
		foreach ( (array) ( isset( $run['journey'] ) ? $run['journey'] : array() ) as $c ) {
			if ( is_array( $c ) ) {
				$out['journey'][] = self::normalize_check( $c );
			}
		}
		return $out;
	}

	/** Accept { question_id, engine, turn? } or [ question_id, engine ]. Turn is kept only past 1. */
	private static function queue_pair( $item ) {
		if ( ! is_array( $item ) ) {
			return null;
		}
		if ( isset( $item['question_id'], $item['engine'] ) ) {
			$pair = array( 'question_id' => (string) $item['question_id'], 'engine' => (string) $item['engine'] );
			if ( isset( $item['turn'] ) && (int) $item['turn'] > 1 ) {
				$pair['turn'] = (int) $item['turn'];
			}
			return $pair;
		}
		if ( isset( $item[0], $item[1] ) ) {
			return array( 'question_id' => (string) $item[0], 'engine' => (string) $item[1] );
		}
		return null;
	}

	private static function normalize_check( array $c ) {
		$tools = isset( $c['tools'] ) ? $c['tools'] : '';
		if ( is_array( $tools ) ) {
			$tools = implode( ',', $tools );
		}
		return array(
			'question_id' => isset( $c['question_id'] ) ? (string) $c['question_id'] : '',
			'engine'      => isset( $c['engine'] ) ? (string) $c['engine'] : '',
			'at'          => isset( $c['at'] ) ? (string) $c['at'] : self::now_iso(),
			'verdict'     => isset( $c['verdict'] ) && in_array( $c['verdict'], TWTAEO_Visibility_Types::VERDICTS, true ) ? $c['verdict'] : 'unavailable',
			'accuracy'    => isset( $c['accuracy'] ) && in_array( $c['accuracy'], TWTAEO_Visibility_Types::ACCURACIES, true ) ? $c['accuracy'] : 'n/a',
			'cited_urls'  => isset( $c['cited_urls'] ) ? array_values( array_map( 'strval', (array) $c['cited_urls'] ) ) : array(),
			'our_urls'    => isset( $c['our_urls'] ) ? array_values( array_map( 'strval', (array) $c['our_urls'] ) ) : array(),
			'domains'          => isset( $c['domains'] ) ? array_values( array_map( 'strval', (array) $c['domains'] ) ) : array(),
			'owned_domains'    => isset( $c['owned_domains'] ) ? array_values( array_map( 'strval', (array) $c['owned_domains'] ) ) : array(),
			'citation_surface' => isset( $c['citation_surface'] ) && in_array( $c['citation_surface'], TWTAEO_Visibility_Types::CITATION_SURFACES, true ) ? (string) $c['citation_surface'] : '',
			'brand_hits'       => isset( $c['brand_hits'] ) && is_array( $c['brand_hits'] ) ? array_values( array_filter( $c['brand_hits'], 'is_array' ) ) : array(),
			'mentions'         => isset( $c['mentions'] ) && is_array( $c['mentions'] ) ? array_values( array_filter( $c['mentions'], 'is_array' ) ) : array(),
			'excerpt'          => isset( $c['excerpt'] ) ? (string) $c['excerpt'] : '',
			'answer'           => isset( $c['answer'] ) ? self::cap_answer( (string) $c['answer'] ) : '',
			'latency_ms'  => isset( $c['latency_ms'] ) ? (int) $c['latency_ms'] : 0,
			'error'       => isset( $c['error'] ) && '' !== (string) $c['error'] ? (string) $c['error'] : null,
			'model'       => isset( $c['model'] ) ? (string) $c['model'] : '',
			'tools'       => (string) $tools,
			'follow_ups'       => isset( $c['follow_ups'] ) && is_array( $c['follow_ups'] ) ? array_values( array_filter( array_map( 'strval', $c['follow_ups'] ), 'strlen' ) ) : array(),
			'follow_ups_error' => isset( $c['follow_ups_error'] ) && '' !== (string) $c['follow_ups_error'] ? (string) $c['follow_ups_error'] : null,
			'turn'             => isset( $c['turn'] ) ? max( 1, (int) $c['turn'] ) : 1,
			'asked'            => isset( $c['asked'] ) ? (string) $c['asked'] : '',
		);
	}

	/** Upsert the run row (checks are NOT written here) and refresh its summary column. */
	private static function save_run( array $run ) {
		global $wpdb;
		self::maybe_create_tables();
		$data = array(
			'id'          => (string) $run['id'],
			'started_at'  => self::to_sql_datetime( $run['started_at'] ),
			'finished_at' => self::to_sql_datetime( $run['finished_at'] ),
			'status'      => (string) $run['status'],
			'demo'        => ! empty( $run['demo'] ) ? 1 : 0,
			'allocation'  => wp_json_encode( $run['allocation'] ),
			'questions'   => wp_json_encode( $run['questions'] ),
			'queue'       => wp_json_encode( $run['queue'] ),
			'cursor_pos'  => (int) $run['cursor'],
			'message'     => null === $run['message'] ? null : (string) $run['message'],
			'summary'     => wp_json_encode( self::summarize( $run ) ),
			'persona'     => empty( $run['persona'] ) ? null : wp_json_encode( $run['persona'] ),
		);
		$format = array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; REPLACE = upsert on the PK.
		$wpdb->replace( self::runs_table(), $data, $format );
	}

	private static function store_summary( $id, array $summary ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->update( self::runs_table(), array( 'summary' => wp_json_encode( $summary ) ), array( 'id' => (string) $id ), array( '%s' ), array( '%s' ) );
	}

	private static function insert_check( $run_id, array $check ) {
		global $wpdb;
		$c    = self::normalize_check( $check );
		$data = array(
			'run_id'      => (string) $run_id,
			'question_id' => substr( $c['question_id'], 0, 191 ),
			'engine'      => substr( $c['engine'], 0, 16 ),
			'checked_at'  => self::to_sql_datetime( $c['at'] ),
			'verdict'     => $c['verdict'],
			'accuracy'    => $c['accuracy'],
			'cited_urls'  => wp_json_encode( $c['cited_urls'] ),
			'our_urls'    => wp_json_encode( $c['our_urls'] ),
			'domains'          => wp_json_encode( $c['domains'] ),
			'owned_domains'    => wp_json_encode( $c['owned_domains'] ),
			'citation_surface' => $c['citation_surface'],
			'brand_hits'       => wp_json_encode( $c['brand_hits'] ),
			'mentions'         => wp_json_encode( $c['mentions'] ),
			'excerpt'          => $c['excerpt'],
			'answer'           => $c['answer'],
			'latency_ms'       => (int) $c['latency_ms'],
			'error'            => $c['error'],
			'model'            => substr( $c['model'], 0, 64 ),
			'tools'            => substr( $c['tools'], 0, 191 ),
			'follow_ups'       => wp_json_encode( $c['follow_ups'] ),
			'follow_ups_error' => $c['follow_ups_error'],
			'turn'             => (int) $c['turn'],
			'asked'            => $c['asked'],
		);
		$format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		if ( false !== $wpdb->insert( self::checks_table(), $data, $format ) ) {
			return;
		}
		// A table short of a column this code writes (the stored schema version
		// got ahead of the table, e.g. an upgrade interrupted mid-way) rejects
		// every insert, and the check would vanish without a word. Rebuild the
		// schema once and try again; a second failure goes to the error log.
		self::maybe_create_tables( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		if ( false === $wpdb->insert( self::checks_table(), $data, $format ) && class_exists( 'TWTAEO_Logger' ) ) {
			TWTAEO_Logger::error( 'AI Visibility could not record a check.', array( 'db_error' => $wpdb->last_error, 'run_id' => (string) $run_id ) );
		}
	}

	private static function load_checks( $run_id ) {
		global $wpdb;
		$checks = self::checks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom table name built from $wpdb->prefix + a class constant; never user input.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$checks} WHERE run_id = %s ORDER BY id ASC", (string) $run_id ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'question_id' => (string) $r['question_id'],
				'engine'      => (string) $r['engine'],
				'at'          => self::from_sql_datetime( $r['checked_at'] ),
				'verdict'     => (string) $r['verdict'],
				'accuracy'    => (string) $r['accuracy'],
				'cited_urls'  => self::json_list( $r['cited_urls'] ),
				'our_urls'    => self::json_list( $r['our_urls'] ),
				'domains'          => self::json_list( $r['domains'] ),
				// Guarded: rows written before DB_VERSION 2 carry no such columns.
				'owned_domains'    => isset( $r['owned_domains'] ) ? self::json_list( $r['owned_domains'] ) : array(),
				'citation_surface' => isset( $r['citation_surface'] ) ? (string) $r['citation_surface'] : '',
				'brand_hits'       => isset( $r['brand_hits'] ) ? self::json_list( $r['brand_hits'] ) : array(),
				// Guarded: rows written before DB_VERSION 3 carry no such column.
				'mentions'         => isset( $r['mentions'] ) ? self::json_list( $r['mentions'] ) : array(),
				'excerpt'          => (string) $r['excerpt'],
				// Guarded: rows written before DB_VERSION 5 kept the excerpt only.
				'answer'           => isset( $r['answer'] ) ? (string) $r['answer'] : '',
				'latency_ms'  => (int) $r['latency_ms'],
				'error'       => ( null === $r['error'] || '' === $r['error'] ) ? null : (string) $r['error'],
				'model'       => (string) $r['model'],
				'tools'       => (string) $r['tools'],
				// Guarded: rows written before DB_VERSION 4 carry no such columns.
				'follow_ups'       => isset( $r['follow_ups'] ) ? self::json_list( $r['follow_ups'] ) : array(),
				'follow_ups_error' => isset( $r['follow_ups_error'] ) && '' !== (string) $r['follow_ups_error'] ? (string) $r['follow_ups_error'] : null,
				// Guarded: rows written before DB_VERSION 6 are all first turns.
				'turn'             => isset( $r['turn'] ) ? max( 1, (int) $r['turn'] ) : 1,
				'asked'            => isset( $r['asked'] ) ? (string) $r['asked'] : '',
			);
		}
		return $out;
	}

	private static function row_to_run( array $row ) {
		$queue = array();
		foreach ( (array) self::json_list( $row['queue'] ) as $item ) {
			$pair = self::queue_pair( $item );
			if ( $pair ) {
				$queue[] = $pair;
			}
		}
		$alloc = json_decode( (string) $row['allocation'], true );
		return array(
			'id'          => (string) $row['id'],
			'started_at'  => self::from_sql_datetime( $row['started_at'] ),
			'finished_at' => self::from_sql_datetime( $row['finished_at'] ),
			'allocation'  => is_array( $alloc ) ? $alloc : array(),
			'questions'   => self::json_list( $row['questions'] ),
			'queue'       => $queue,
			'cursor'      => (int) $row['cursor_pos'],
			'checks'      => array(),
			'journey'     => array(),
			'status'      => (string) $row['status'],
			'message'     => ( null === $row['message'] || '' === $row['message'] ) ? null : (string) $row['message'],
			'demo'        => ! empty( $row['demo'] ),
			'persona'     => self::run_persona( isset( $row['persona'] ) ? json_decode( (string) $row['persona'], true ) : null ),
		);
	}

	/**
	 * A run's persona as stored: { id, label }, or null for the baseline.
	 *
	 * @param mixed $p
	 * @return array|null
	 */
	private static function run_persona( $p ) {
		if ( ! is_array( $p ) || empty( $p['label'] ) ) {
			return null;
		}
		$label = sanitize_text_field( (string) $p['label'] );
		if ( '' === $label ) {
			return null;
		}
		return array(
			'id'    => isset( $p['id'] ) && '' !== (string) $p['id'] ? sanitize_title( (string) $p['id'] ) : sanitize_title( $label ),
			'label' => $label,
		);
	}

	private static function json_list( $json ) {
		$v = json_decode( (string) $json, true );
		return is_array( $v ) ? array_values( $v ) : array();
	}

	/* ───────────── seams to the pure classes (with safe fallbacks) ───────── */

	private static function build_questions( array $input, array $alloc ) {
		if ( class_exists( 'TWTAEO_Visibility_Questions' ) ) {
			$q = TWTAEO_Visibility_Questions::build_questions( $input, $alloc );
			return is_array( $q ) ? array_values( $q ) : array();
		}
		return array();
	}

	/**
	 * One question × engine conversation up to (not including) $turn, oldest
	 * first: [ [ turn, q, a, follow_ups ], … ]. Turn 1 is the original question
	 * and its answer; later turns are what journey mode asked.
	 *
	 * @param array  $run
	 * @param array  $question
	 * @param string $engine
	 * @param int    $turn
	 * @return array
	 */
	private static function conversation( array $run, array $question, $engine, $turn ) {
		$chain = array();
		$qid   = (string) $question['id'];
		foreach ( array_merge( (array) $run['checks'], isset( $run['journey'] ) ? (array) $run['journey'] : array() ) as $c ) {
			if ( ! is_array( $c ) || (string) $c['question_id'] !== $qid || (string) $c['engine'] !== (string) $engine ) {
				continue;
			}
			$t = isset( $c['turn'] ) ? max( 1, (int) $c['turn'] ) : 1;
			if ( $t >= $turn || null !== $c['error'] ) {
				continue;
			}
			$chain[ $t ] = array(
				'turn'       => $t,
				'q'          => 1 === $t ? (string) $question['text'] : (string) $c['asked'],
				'a'          => '' !== (string) $c['answer'] ? (string) $c['answer'] : (string) $c['excerpt'],
				'follow_ups' => (array) $c['follow_ups'],
			);
		}
		ksort( $chain );
		return array_values( $chain );
	}

	/**
	 * Take an engine's questions from $from on out of the queue, and note on
	 * the run how many were skipped, for key_notice().
	 *
	 * @param array  $run    Run, changed in place.
	 * @param string $engine
	 * @param int    $from   Queue position to start from (what is before it already ran).
	 */
	private static function drop_engine( array &$run, $engine, $from ) {
		$queue   = array_values( (array) $run['queue'] );
		$kept    = array_slice( $queue, 0, $from );
		$skipped = 0;
		foreach ( array_slice( $queue, $from ) as $item ) {
			if ( is_array( $item ) && isset( $item['engine'] ) && (string) $item['engine'] === (string) $engine ) {
				++$skipped;
				continue;
			}
			$kept[] = $item;
		}
		$run['queue'] = $kept;

		$rejected                        = isset( $run['allocation']['key_rejected'] ) ? (array) $run['allocation']['key_rejected'] : array();
		$rejected[ $engine ]             = ( isset( $rejected[ $engine ] ) ? (int) $rejected[ $engine ] : 0 ) + $skipped;
		$run['allocation']['key_rejected'] = $rejected;
		$run['message']                  = self::key_notice( $run );
	}

	/** "ChatGPT rejected its API key — 24 questions skipped…", or null. */
	private static function key_notice( array $run ) {
		$rejected = isset( $run['allocation']['key_rejected'] ) ? (array) $run['allocation']['key_rejected'] : array();
		if ( ! $rejected ) {
			return null;
		}
		$parts = array();
		foreach ( $rejected as $engine => $skipped ) {
			$label   = isset( TWTAEO_Visibility_Types::ENGINES[ $engine ]['label'] ) ? TWTAEO_Visibility_Types::ENGINES[ $engine ]['label'] : $engine;
			$parts[] = sprintf(
				/* translators: 1: engine name, 2: number of questions. */
				_n( '%1$s rejected its API key, so its remaining %2$d question was skipped.', '%1$s rejected its API key, so its remaining %2$d questions were skipped.', (int) $skipped, 'twt-aeo-ultimate' ),
				$label,
				(int) $skipped
			);
		}

		return implode( ' ', $parts ) . ' ' . __( 'Check the key in Settings; the next run will try it again.', 'twt-aeo-ultimate' );
	}

	private static function next_step( array $run ) {
		if ( class_exists( 'TWTAEO_Visibility_Verdict' ) && method_exists( 'TWTAEO_Visibility_Verdict', 'next_step' ) ) {
			$s = TWTAEO_Visibility_Verdict::next_step( $run );
			return is_array( $s ) ? self::queue_pair( $s ) : null;
		}
		$cursor = max( 0, (int) $run['cursor'] );
		return isset( $run['queue'][ $cursor ] ) ? self::queue_pair( $run['queue'][ $cursor ] ) : null;
	}

	private static function judge_verdict( array $answer, array $ctx ) {
		if ( class_exists( 'TWTAEO_Visibility_Verdict' ) && method_exists( 'TWTAEO_Visibility_Verdict', 'judge_verdict' ) ) {
			$j = TWTAEO_Visibility_Verdict::judge_verdict( $answer, $ctx );
			if ( is_array( $j ) && isset( $j['verdict'] ) ) {
				// Pass every judged field through — this wrapper once returned only
				// verdict/our_urls/domains, which silently emptied owned_domains,
				// citation_surface and brand_hits on every stored check.
				return array(
					'verdict'          => (string) $j['verdict'],
					'our_urls'         => isset( $j['our_urls'] ) ? array_values( (array) $j['our_urls'] ) : array(),
					'domains'          => isset( $j['domains'] ) ? array_values( (array) $j['domains'] ) : array(),
					'owned_domains'    => isset( $j['owned_domains'] ) ? array_values( (array) $j['owned_domains'] ) : array(),
					'citation_surface' => isset( $j['citation_surface'] ) ? (string) $j['citation_surface'] : '',
					'brand_hits'       => isset( $j['brand_hits'] ) && is_array( $j['brand_hits'] ) ? array_values( $j['brand_hits'] ) : array(),
					'mentions'         => isset( $j['mentions'] ) && is_array( $j['mentions'] ) ? array_values( $j['mentions'] ) : array(),
				);
			}
		}
		// Fallback: host match only (no name matching without the pure class).
		$our = array();
		$dom = array();
		foreach ( (array) $answer['cited_urls'] as $u ) {
			$h = TWTAEO_Visibility_Inputs::host_of( $u );
			if ( '' === $h ) {
				continue;
			}
			if ( ! in_array( $h, $dom, true ) ) {
				$dom[] = $h;
			}
			foreach ( (array) $ctx['hosts'] as $ours ) {
				if ( $h === $ours || ( '' !== $ours && substr( $h, -strlen( '.' . $ours ) ) === '.' . $ours ) ) {
					$our[] = $u;
					break;
				}
			}
		}
		return array( 'verdict' => ! empty( $our ) ? 'cited' : 'absent', 'our_urls' => $our, 'domains' => $dom );
	}

	private static function summarize( array $run ) {
		if ( class_exists( 'TWTAEO_Visibility_Verdict' ) && method_exists( 'TWTAEO_Visibility_Verdict', 'summarize_run' ) ) {
			$s = TWTAEO_Visibility_Verdict::summarize_run( $run );
			if ( is_array( $s ) ) {
				$s['persona'] = isset( $run['persona'] ) ? $run['persona'] : null;
				$s['journey'] = isset( $run['allocation']['journey'] ) ? (int) $run['allocation']['journey'] : 0;
				return $s;
			}
		}
		$all      = (array) $run['checks'];
		$answered = 0;
		$cited    = 0;
		$named    = 0;
		$wrong    = 0;
		foreach ( $all as $c ) {
			if ( 'unavailable' !== $c['verdict'] ) {
				$answered++;
				if ( 'cited' === $c['verdict'] ) {
					$cited++;
				}
				if ( 'cited' === $c['verdict'] || 'named' === $c['verdict'] ) {
					$named++;
				}
			}
			if ( 'wrong' === $c['accuracy'] ) {
				$wrong++;
			}
		}
		return array(
			'id'          => $run['id'],
			'started_at'  => $run['started_at'],
			'finished_at' => $run['finished_at'],
			'status'      => $run['status'],
			'demo'        => ! empty( $run['demo'] ),
			'checks'      => count( $all ),
			'cited_pct'   => $answered ? (int) round( $cited / $answered * 100 ) : 0,
			'named_pct'   => $answered ? (int) round( $named / $answered * 100 ) : 0,
			'wrong'       => $wrong,
			'by_engine'   => array(),
			'persona'     => isset( $run['persona'] ) ? $run['persona'] : null,
			'journey'     => isset( $run['allocation']['journey'] ) ? (int) $run['allocation']['journey'] : 0,
		);
	}

	/** The full answer, capped at ANSWER_MAX characters. */
	private static function cap_answer( $text ) {
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > TWTAEO_Visibility_Types::ANSWER_MAX ) {
			return mb_substr( $text, 0, TWTAEO_Visibility_Types::ANSWER_MAX );
		}
		return strlen( $text ) > TWTAEO_Visibility_Types::ANSWER_MAX ? substr( $text, 0, TWTAEO_Visibility_Types::ANSWER_MAX ) : $text;
	}

	private static function excerpt( $text ) {
		if ( class_exists( 'TWTAEO_Visibility_Verdict' ) && method_exists( 'TWTAEO_Visibility_Verdict', 'excerpt_of' ) ) {
			return (string) TWTAEO_Visibility_Verdict::excerpt_of( $text );
		}
		$t = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $t ) > 300 ) {
			return rtrim( mb_substr( $t, 0, 299 ) ) . '…';
		}
		return strlen( $t ) > 300 ? rtrim( substr( $t, 0, 299 ) ) . '…' : $t;
	}

	/**
	 * The names and hosts a verdict is judged against: the site's hosts, its
	 * name, each host's first label ("example" from example.com) and the
	 * brands it carries.
	 */
	private static function verdict_context() {
		$identity = TWTAEO_Visibility_Inputs::identity();
		$hosts    = (array) $identity['hosts'];
		$names    = array();
		if ( '' !== trim( (string) $identity['name'] ) ) {
			$names[] = trim( (string) $identity['name'] );
		}
		foreach ( $hosts as $h ) {
			$labels = explode( '.', (string) $h );
			if ( isset( $labels[0] ) && 'www' === $labels[0] ) {
				array_shift( $labels );
			}
			$first = isset( $labels[0] ) ? $labels[0] : '';
			if ( '' !== $first && count( $labels ) > 1 ) {
				$names[] = str_replace( '-', ' ', $first );
				$names[] = $first;
			}
		}
		foreach ( (array) $identity['brands'] as $b ) {
			$b = trim( (string) $b );
			if ( '' !== $b ) {
				$names[] = $b;
			}
		}
		$uniq = array();
		foreach ( $names as $n ) {
			if ( '' !== $n && ! in_array( $n, $uniq, true ) ) {
				$uniq[] = $n;
			}
		}
		$company_urls = isset( $identity['company_urls'] ) ? (array) $identity['company_urls'] : array();
		$items        = isset( $identity['brand_items'] ) ? (array) $identity['brand_items'] : array();
		$properties   = class_exists( 'TWTAEO_Visibility_Brands' )
			? TWTAEO_Visibility_Brands::all_properties( $hosts, $company_urls, $items, (string) $identity['name'] )
			: array();

		return array(
			'hosts'      => $hosts,
			'names'      => $uniq,
			'properties' => $properties,
			'items'      => $items,
		);
	}

	/**
	 * Owned-profile URLs, keeping only what can actually be matched later. A URL
	 * with no path is dropped: bare `linkedin.com` would book every LinkedIn
	 * citation on the internet as ours, inflating the score exactly as
	 * dishonestly as the old host-only rule deflated it.
	 *
	 * @param mixed $urls Raw list, or newline/comma separated text.
	 * @return string[]
	 */
	public static function clean_property_urls( $urls ) {
		if ( is_string( $urls ) ) {
			$urls = preg_split( '/[\r\n,]+/', $urls );
		}
		$out = array();
		if ( ! class_exists( 'TWTAEO_Visibility_Brands' ) ) {
			return $out;
		}
		foreach ( (array) $urls as $u ) {
			$u = esc_url_raw( trim( (string) $u ) );
			if ( '' === $u ) {
				continue;
			}
			if ( null === TWTAEO_Visibility_Brands::normalize_property( $u, 'profile' ) ) {
				continue;
			}
			if ( ! in_array( $u, $out, true ) ) {
				$out[] = $u;
			}
			if ( count( $out ) >= TWTAEO_Visibility_Brands::MAX_URLS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Brand items, sanitised field by field before the pure normaliser prunes
	 * whatever could never match.
	 *
	 * @param mixed $items Raw items.
	 * @return array Item[].
	 */
	public static function clean_brand_items( $items ) {
		if ( ! class_exists( 'TWTAEO_Visibility_Brands' ) ) {
			return array();
		}
		$clean = array();
		foreach ( (array) $items as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$phrases = isset( $raw['phrases'] ) ? $raw['phrases'] : array();
			if ( is_string( $phrases ) ) {
				$phrases = preg_split( '/\r\n|\r|\n/', $phrases );
			}
			$urls = isset( $raw['urls'] ) ? $raw['urls'] : array();
			if ( is_string( $urls ) ) {
				$urls = preg_split( '/[\r\n,]+/', $urls );
			}
			$clean[] = array(
				'label'   => sanitize_text_field( (string) ( isset( $raw['label'] ) ? $raw['label'] : '' ) ),
				'phrases' => array_map( 'sanitize_text_field', array_map( 'strval', (array) $phrases ) ),
				'urls'    => self::clean_property_urls( $urls ),
				'enabled' => ! isset( $raw['enabled'] ) || (bool) $raw['enabled'],
			);
		}
		return TWTAEO_Visibility_Brands::normalize_items( $clean );
	}
}
