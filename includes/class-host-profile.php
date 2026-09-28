<?php
/**
 * Host Profile — measure the server, then refuse to exceed it.
 *
 * The Content Gap Engine chunks pages and (later) documents. Chunking is the
 * first thing this plugin does that burns sustained CPU in a loop, and that is
 * exactly the shape budget hosts kill. CloudLinux LVE throttles or 508s an
 * account for sustained CPU long before PHP's own memory_limit or
 * max_execution_time would ever fire, so "it fits in 128M" is not the question.
 *
 * We cannot test on every host, so nothing here assumes. This class:
 *
 *   - probes what the host will admit to (limits, shell_exec, FULLTEXT),
 *   - MEASURES what it can actually do (a real timed dot-product benchmark —
 *     an old PHP build on throttled shared CPU is easily 10x slower than the
 *     same ini settings on a VPS, and only the clock knows),
 *   - sizes every batch from that measurement rather than a fixed constant,
 *   - stops cleanly at a cursor when the tick's time or memory budget is spent,
 *   - trips a circuit breaker after repeated failures instead of retrying into
 *     a loop, and surfaces that as an honest notice.
 *
 * Design rule: start conservative and let the benchmark RAISE limits. Never
 * assume them. The worst case on an unknown host must be "slow", never "dead" —
 * slow earns a support question, dead earns a refund and a one-star review.
 *
 * Local testing: define TWTAEO_CGE_SIMULATE_SLOW (an integer multiplier) in
 * wp-config.php to scale the measured benchmark, so pause/resume behaviour can
 * be verified on fast development hardware. See also the synthetic capability
 * profiles in PROFILES, forced with TWTAEO_CGE_FORCE_PROFILE.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Host_Profile {

	/** Stored probe result. Not autoloaded — read only when work is about to run. */
	const OPTION_KEY = 'twtaeo_host_profile';

	/** Consecutive-failure counter and pause flag for the circuit breaker. */
	const OPTION_BREAKER = 'twtaeo_cge_breaker';

	/** Kill switch: disables the engine without deactivating the plugin. */
	const OPTION_DISABLED = 'twtaeo_cge_disabled';

	/** Re-probe when the stored profile is older than this (hosts get upgraded). */
	const PROFILE_TTL = WEEK_IN_SECONDS;

	/** Consecutive failed ticks before the breaker trips. */
	const BREAKER_THRESHOLD = 3;

	/** Fraction of max_execution_time a single tick may consume. */
	const TIME_BUDGET_FRACTION = 0.5;

	/** Fraction of memory_limit at which a tick stops and yields. */
	const MEMORY_CEILING_FRACTION = 0.7;

	/** Absolute floor. Below this the engine refuses to run at all. */
	const MIN_MEMORY_BYTES = 100663296; // 96M.

	/** Never batch smaller or larger than these, whatever the benchmark says. */
	const MIN_BATCH = 1;
	const MAX_BATCH = 50;

	/**
	 * Cost of one unit of work, expressed in benchmark dot-products.
	 *
	 * Calibrated against the real thing rather than guessed: chunking one page
	 * is an HTTP fetch, an HTML parse and several passes of regex over the
	 * body, which measured out around three thousand dot-products' worth of
	 * wall time on the development machine. The absolute figure matters less
	 * than that it is the right order of magnitude — too low and every host
	 * saturates at MAX_BATCH, which silently defeats the whole governor.
	 */
	const COST_CHUNK_PAGE = 3000.0;

	/** Embedding a stored chunk: no network, just pack and write. */
	const COST_EMBED_CHUNK = 250.0;

	/**
	 * Synthetic capability profiles for testing against hosts we do not own.
	 * Forced with TWTAEO_CGE_FORCE_PROFILE ('A'|'B'|'C'|'D'). See the build
	 * brief's Stage 0 — this is the cheapest and most repeatable test we have,
	 * and the only one that covers hosts that do not exist yet.
	 */
	const PROFILES = array(
		'A' => array( 'label' => 'Bargain shared', 'memory' => 67108864,  'time' => 30,  'shell' => false, 'slow' => 12 ),
		'B' => array( 'label' => 'Typical shared', 'memory' => 134217728, 'time' => 30,  'shell' => false, 'slow' => 6 ),
		'C' => array( 'label' => 'Managed WP',     'memory' => 268435456, 'time' => 60,  'shell' => false, 'slow' => 2 ),
		'D' => array( 'label' => 'VPS',            'memory' => 536870912, 'time' => 300, 'shell' => true,  'slow' => 1 ),
	);

	/** Wall-clock start of the current tick, set by budget_start(). */
	private static $tick_started = null;

	/** Byte ceiling for the current tick, set by budget_start(). */
	private static $tick_memory_cap = 0;

	private function __construct() {}

	/* ─────────────────────────── the probe ─────────────────────────── */

	/**
	 * Return the stored profile, probing if absent or stale.
	 *
	 * @param bool $force Re-probe even when a fresh profile exists.
	 * @return array
	 */
	public static function get( $force = false ) {
		$stored = get_option( self::OPTION_KEY, array() );

		$fresh = is_array( $stored )
			&& ! empty( $stored['probed_at'] )
			&& ( time() - (int) $stored['probed_at'] ) < self::PROFILE_TTL;

		if ( $fresh && ! $force ) {
			return $stored;
		}

		return self::probe();
	}

	/**
	 * Measure the host and store the result.
	 *
	 * @return array
	 */
	public static function probe() {
		$forced = self::forced_profile();

		if ( $forced ) {
			$memory_bytes = $forced['memory'];
			$max_time     = $forced['time'];
			$has_shell    = $forced['shell'];
		} else {
			$memory_bytes = self::memory_limit_bytes();
			$max_time     = self::max_execution_seconds();
			$has_shell    = self::shell_available();
		}

		$profile = array(
			'probed_at'    => time(),
			'forced'       => $forced ? $forced['label'] : '',
			'php_version'  => PHP_VERSION,
			'memory_bytes' => $memory_bytes,
			'memory_raw'   => self::memory_label( $forced, $memory_bytes ),
			'max_time'     => $max_time,
			'has_shell'    => $has_shell,
			'has_fulltext' => self::fulltext_available(),
			'ops_per_sec'  => 0,
			'bench_ms'     => 0.0,
			'usable'       => false,
			'reason'       => '',
		);

		// The measurement that actually matters. Everything above is the host's
		// own claim about itself; this is the clock's opinion.
		$bench                  = self::benchmark();
		$profile['bench_ms']    = $bench['ms'];
		$profile['ops_per_sec'] = $bench['ops_per_sec'];

		// Hard refusal below the memory floor. A site that cannot hold a batch
		// plus WordPress should never start, not start and die mid-loop.
		if ( $memory_bytes > 0 && $memory_bytes < self::MIN_MEMORY_BYTES ) {
			$profile['reason'] = sprintf(
				/* translators: %s: the host's PHP memory_limit, e.g. "64M". */
				__( 'PHP memory_limit is %s. The Content Gap Engine needs at least 96M.', 'twt-aeo-ultimate' ),
				$profile['memory_raw']
			);
		} else {
			$profile['usable'] = true;
		}

		update_option( self::OPTION_KEY, $profile, false );

		return $profile;
	}

	/**
	 * Timed dot-product benchmark against 512-dimension vectors — the same
	 * shape and width the vector rerank will use, so the number transfers.
	 *
	 * Deliberately small (a few ms) because this runs during an admin request.
	 *
	 * @return array{ms: float, ops_per_sec: int}
	 */
	public static function benchmark() {
		$dims  = 512;
		$pairs = 200;

		$a = array();
		$b = array();
		for ( $i = 0; $i < $dims; $i++ ) {
			$a[] = ( $i % 97 ) / 97.0;
			$b[] = ( $i % 89 ) / 89.0;
		}

		$start = microtime( true );
		for ( $p = 0; $p < $pairs; $p++ ) {
			$dot = 0.0;
			for ( $i = 0; $i < $dims; $i++ ) {
				$dot += $a[ $i ] * $b[ $i ];
			}
		}
		$elapsed = microtime( true ) - $start;

		// Guard against a clock that reports zero on very fast hardware.
		if ( $elapsed <= 0.0 ) {
			$elapsed = 0.0001;
		}

		$ops_per_sec = (int) round( $pairs / $elapsed );

		// Simulated slowdown, for testing pause/resume on fast hardware.
		$slow = self::slow_factor();
		if ( $slow > 1 ) {
			$ops_per_sec = (int) max( 1, round( $ops_per_sec / $slow ) );
			$elapsed     = $elapsed * $slow;
		}

		return array(
			'ms'          => round( $elapsed * 1000, 2 ),
			'ops_per_sec' => $ops_per_sec,
		);
	}

	/* ─────────────────────────── the governor ─────────────────────────── */

	/**
	 * How many units of work this tick may attempt.
	 *
	 * Derived from measured throughput and the tick's time budget, never from a
	 * hardcoded constant — that is the whole point of the benchmark.
	 *
	 * @param float $unit_cost_ops Cost of one unit in benchmark-ops. Use one of
	 *                             the COST_* constants; the default is a page.
	 * @return int
	 */
	public static function batch_size( $unit_cost_ops = self::COST_CHUNK_PAGE ) {
		$profile = self::get();

		$ops = isset( $profile['ops_per_sec'] ) ? (int) $profile['ops_per_sec'] : 0;
		if ( $ops < 1 ) {
			return self::MIN_BATCH;
		}

		$budget_seconds = self::time_budget_seconds();
		$affordable     = ( $ops * $budget_seconds ) / max( 1.0, (float) $unit_cost_ops );

		$batch = (int) floor( $affordable );
		$batch = max( self::MIN_BATCH, min( self::MAX_BATCH, $batch ) );

		/**
		 * Filter the computed batch size.
		 *
		 * @param int   $batch   Units this tick may attempt.
		 * @param array $profile The measured host profile.
		 */
		return (int) apply_filters( 'twtaeo_cge_batch_size', $batch, $profile );
	}

	/**
	 * Seconds a single tick may run for.
	 *
	 * @return float
	 */
	public static function time_budget_seconds() {
		$profile = self::get();
		$max     = isset( $profile['max_time'] ) ? (int) $profile['max_time'] : 30;

		// 0 means unlimited (typically CLI). Cap it anyway — an unlimited
		// runtime is still a shared CPU someone else is paying for.
		if ( $max <= 0 ) {
			$max = 60;
		}

		return max( 2.0, $max * self::TIME_BUDGET_FRACTION );
	}

	/**
	 * Open a tick. Call once before the work loop; pair with should_stop().
	 */
	public static function budget_start() {
		self::$tick_started = microtime( true );

		$profile = self::get();
		$limit   = isset( $profile['memory_bytes'] ) ? (int) $profile['memory_bytes'] : 0;

		self::$tick_memory_cap = $limit > 0
			? (int) ( $limit * self::MEMORY_CEILING_FRACTION )
			: 0;
	}

	/**
	 * True when this tick has spent its time or memory budget and should stop
	 * at the current cursor. Check every iteration — the cost is trivial next
	 * to the cost of being killed mid-loop.
	 *
	 * @return bool
	 */
	public static function should_stop() {
		if ( null === self::$tick_started ) {
			return false;
		}

		if ( ( microtime( true ) - self::$tick_started ) >= self::time_budget_seconds() ) {
			return true;
		}

		if ( self::$tick_memory_cap > 0 && memory_get_usage( true ) >= self::$tick_memory_cap ) {
			return true;
		}

		return false;
	}

	/** Seconds elapsed in the current tick. */
	public static function tick_elapsed() {
		return null === self::$tick_started ? 0.0 : round( microtime( true ) - self::$tick_started, 3 );
	}

	/* ────────────────── circuit breaker and kill switch ────────────────── */

	/**
	 * Record a failed tick. Trips the breaker at BREAKER_THRESHOLD.
	 *
	 * @param string $context Short description for Diagnostics.
	 */
	public static function record_failure( $context = '' ) {
		$state          = self::breaker_state();
		$state['count'] = (int) $state['count'] + 1;
		$state['last']  = time();

		if ( $state['count'] >= self::BREAKER_THRESHOLD ) {
			$state['tripped'] = true;
			$state['reason']  = $context;

			if ( class_exists( 'TWTAEO_Logger' ) ) {
				TWTAEO_Logger::error(
					'Content Gap Engine paused: host could not complete indexing.',
					array(
						'consecutive_failures' => $state['count'],
						'context'              => $context,
						'profile'              => self::get(),
					)
				);
			}
		}

		update_option( self::OPTION_BREAKER, $state, false );
	}

	/** Clear the failure counter after a clean tick. */
	public static function record_success() {
		$state = self::breaker_state();

		// Nothing to write; avoid a needless option update on every tick.
		if ( 0 === (int) $state['count'] && empty( $state['tripped'] ) ) {
			return;
		}

		update_option(
			self::OPTION_BREAKER,
			array(
				'count'   => 0,
				'tripped' => false,
				'last'    => time(),
				'reason'  => '',
			),
			false
		);
	}

	/** True when the breaker has tripped and work must not resume unattended. */
	public static function is_tripped() {
		$state = self::breaker_state();
		return ! empty( $state['tripped'] );
	}

	/** The reason the breaker tripped, for the admin notice. */
	public static function trip_reason() {
		$state = self::breaker_state();
		return (string) $state['reason'];
	}

	/** Operator reset, from the admin screen. */
	public static function reset_breaker() {
		delete_option( self::OPTION_BREAKER );
	}

	/**
	 * Master kill switch. Recoverable over WP-CLI or a direct DB edit when a
	 * site is already down:
	 *
	 *   wp option update twtaeo_cge_disabled 1
	 *
	 * or by defining TWTAEO_CGE_DISABLED in wp-config.php.
	 */
	public static function is_disabled() {
		if ( defined( 'TWTAEO_CGE_DISABLED' ) && TWTAEO_CGE_DISABLED ) {
			return true;
		}
		return (bool) get_option( self::OPTION_DISABLED, false );
	}

	/**
	 * Single gate every entry point checks before doing any work.
	 *
	 * @return true|WP_Error
	 */
	public static function can_run() {
		if ( self::is_disabled() ) {
			return new WP_Error(
				'twtaeo_cge_disabled',
				__( 'The Content Gap Engine is switched off.', 'twt-aeo-ultimate' )
			);
		}

		if ( self::is_tripped() ) {
			return new WP_Error(
				'twtaeo_cge_tripped',
				__( 'Indexing is paused because your host could not complete it. Reduce the amount of content, or reset from the Chunk View screen.', 'twt-aeo-ultimate' )
			);
		}

		$profile = self::get();
		if ( empty( $profile['usable'] ) ) {
			return new WP_Error(
				'twtaeo_cge_host',
				$profile['reason'] ? $profile['reason'] : __( 'This host cannot run the Content Gap Engine.', 'twt-aeo-ultimate' )
			);
		}

		return true;
	}

	/* ─────────────────────────── internals ─────────────────────────── */

	private static function breaker_state() {
		$state = get_option( self::OPTION_BREAKER, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		return wp_parse_args(
			$state,
			array(
				'count'   => 0,
				'tripped' => false,
				'last'    => 0,
				'reason'  => '',
			)
		);
	}

	/** Active synthetic profile, or null when measuring the real host. */
	private static function forced_profile() {
		if ( ! defined( 'TWTAEO_CGE_FORCE_PROFILE' ) ) {
			return null;
		}
		$key = strtoupper( (string) TWTAEO_CGE_FORCE_PROFILE );
		return isset( self::PROFILES[ $key ] ) ? self::PROFILES[ $key ] : null;
	}

	/** Benchmark slowdown multiplier, for testing on fast hardware. */
	private static function slow_factor() {
		$forced = self::forced_profile();
		if ( $forced ) {
			return (int) $forced['slow'];
		}
		if ( defined( 'TWTAEO_CGE_SIMULATE_SLOW' ) ) {
			return max( 1, (int) TWTAEO_CGE_SIMULATE_SLOW );
		}
		return 1;
	}

	/**
	 * Display label for the memory limit. ini_get() returns "-1" for unlimited,
	 * which reads as a broken value on the admin screen rather than a good one.
	 *
	 * @param array|null $forced       Active synthetic profile, if any.
	 * @param int        $memory_bytes Resolved limit in bytes; 0 = unlimited.
	 * @return string
	 */
	private static function memory_label( $forced, $memory_bytes ) {
		if ( $forced ) {
			return size_format( $memory_bytes );
		}

		if ( $memory_bytes <= 0 ) {
			return __( 'unlimited', 'twt-aeo-ultimate' );
		}

		return (string) ini_get( 'memory_limit' );
	}

	/** memory_limit in bytes; 0 when unlimited. */
	private static function memory_limit_bytes() {
		$raw = trim( (string) ini_get( 'memory_limit' ) );

		if ( '' === $raw || '-1' === $raw ) {
			return 0;
		}

		return (int) wp_convert_hr_to_bytes( $raw );
	}

	/** max_execution_time in seconds; 0 when unlimited. */
	private static function max_execution_seconds() {
		return (int) ini_get( 'max_execution_time' );
	}

	/**
	 * Whether shell_exec is genuinely callable. function_exists() alone is not
	 * enough — disable_functions leaves the symbol defined on some builds, and
	 * this decides whether OCR is offered at all.
	 */
	private static function shell_available() {
		if ( ! function_exists( 'shell_exec' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return ! in_array( 'shell_exec', $disabled, true );
	}

	/**
	 * Whether the chunk table can carry a FULLTEXT index. InnoDB gained
	 * FULLTEXT in MySQL 5.6 and MariaDB 10.0.5; stage one of hybrid retrieval
	 * depends on it, and without it retrieval falls back to a slower scan.
	 */
	private static function fulltext_available() {
		global $wpdb;

		$version = $wpdb->db_version();
		if ( ! $version ) {
			return false;
		}

		if ( method_exists( $wpdb, 'db_server_info' ) && stripos( (string) $wpdb->db_server_info(), 'mariadb' ) !== false ) {
			return version_compare( $version, '10.0.5', '>=' );
		}

		return version_compare( $version, '5.6', '>=' );
	}

	/** Human-readable summary for the admin screen and Diagnostics. */
	public static function summary() {
		$profile = self::get();

		return array(
			__( 'PHP version', 'twt-aeo-ultimate' )       => $profile['php_version'],
			__( 'Memory limit', 'twt-aeo-ultimate' )      => $profile['memory_raw'],
			__( 'Max execution', 'twt-aeo-ultimate' )     => $profile['max_time'] ? $profile['max_time'] . 's' : __( 'unlimited', 'twt-aeo-ultimate' ),
			__( 'Measured speed', 'twt-aeo-ultimate' )    => /* translators: %s: vector operations per second measured on this server. */
				sprintf( __( '%s vector ops/sec', 'twt-aeo-ultimate' ), number_format_i18n( (int) $profile['ops_per_sec'] ) ),
			__( 'Batch size', 'twt-aeo-ultimate' )        => self::batch_size(),
			__( 'Shell access', 'twt-aeo-ultimate' )      => $profile['has_shell'] ? __( 'yes (OCR possible)', 'twt-aeo-ultimate' ) : __( 'no (OCR unavailable)', 'twt-aeo-ultimate' ),
			__( 'FULLTEXT index', 'twt-aeo-ultimate' )    => $profile['has_fulltext'] ? __( 'supported', 'twt-aeo-ultimate' ) : __( 'unsupported', 'twt-aeo-ultimate' ),
			__( 'Simulated profile', 'twt-aeo-ultimate' ) => $profile['forced'] ? $profile['forced'] : __( 'none (real host)', 'twt-aeo-ultimate' ),
		);
	}
}
