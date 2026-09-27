<?php
/**
 * Smart 404 Rescue.
 *
 * Turns a 404 into a fix instead of a log row. On a genuine human 404 it:
 *   1. Applies any established 301 (from confirmed external equity) first.
 *   2. Skips hostile-probe traffic outright (Gate 0) — the bulk of all 404s.
 *   3. Skips bots (Gate 1) so only real visitors trigger any work.
 *   4. Finds the closest published URL by typo-distance against a cached slug map.
 *   5. If the visitor arrived from an INTERNAL page with a typo'd link, corrects
 *      that link at the source (auto-heal) and records the change for undo.
 *   6. Sends the visitor on to the correct page with a 302.
 *   7. Otherwise queues the dead URL for an async, cron-driven Gemini grounded
 *      check: if external sites still link to it, a 301 is created automatically
 *      (or, when no target is confident, it is surfaced for admin review).
 *
 * Nothing is written per-404. Persistent writes are bounded single options: the
 * undo log, the AI queue, the redirect store, and the recommendation list — plus
 * the corrected source link, i.e. the root cause.
 *
 * @package TWTAEO_Connector
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Smart_404 {

	/** Bounded undo log of auto-healed internal links. One option, FIFO-capped. */
	const OPTION_HEAL_LOG = 'twtaeo_s404_heal_log';

	/** Admin-added probe substrings, layered on top of the bundled baseline. */
	const OPTION_CUSTOM_PROBES = 'twtaeo_s404_custom_probes';

	/** Cached slug → post-ID map so matching never hits the DB per request. */
	const TRANSIENT_SLUGS = 'twtaeo_s404_slug_index';

	const MAX_HEAL_LOG   = 200;
	const SLUG_INDEX_TTL = 12 * HOUR_IN_SECONDS;

	/** Confidence (0–100) required to 302 a visitor to a guessed page. */
	const MIN_REDIRECT_SCORE = 80;

	/**
	 * Bar to auto-edit the source link. Tied to the redirect bar on purpose: if
	 * we're confident enough to send a visitor to a page, we're confident enough
	 * to correct the link that pointed at the dead URL. The undo log makes it safe.
	 */
	const MIN_HEAL_SCORE = self::MIN_REDIRECT_SCORE;

	// ── Persistent 301 store ─────────────────────────────────────────────────────

	/** Persisted 301s (from the GSC lost-page scanner or manual admin action). */
	const OPTION_REDIRECTS = 'twtaeo_s404_redirects';
	const MAX_REDIRECTS    = 1000;

	/** Weekly cron that runs the GSC lost-page scan (lost pages appear slowly). */
	const CRON_HOOK = 'twtaeo_s404_gsc_scan';

	/** In-process cache of the bundled + custom probe pattern set. */
	private static $patterns = null;

	public static function register_hooks() {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 5 );
		// Keep the slug index fresh without rebuilding it on every request.
		add_action( 'save_post', array( __CLASS__, 'flush_slug_index' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_slug_index' ) );

		self::migrate_legacy();

		// Weekly GSC lost-page scan (creates 301s for renamed/moved content).
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_scan' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	/** Cron callback: run the GSC lost-page scan if the integration is available. */
	public static function run_scheduled_scan() {
		if ( class_exists( 'TWTAEO_Lost_Pages' ) ) {
			TWTAEO_Lost_Pages::run_gsc_scan();
		}
	}

	/** Clear the scheduled scan — call when the module is switched off. */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( 'twtaeo_s404_ai_scan' ); // Legacy hook name.
	}

	/** One-time teardown of the retired Gemini external-link layer (cron + options). */
	private static function migrate_legacy() {
		if ( wp_next_scheduled( 'twtaeo_s404_ai_scan' ) ) {
			wp_clear_scheduled_hook( 'twtaeo_s404_ai_scan' );
		}
		if ( false !== get_option( 'twtaeo_s404_ai_queue', false ) ) {
			delete_option( 'twtaeo_s404_ai_queue' );
		}
		if ( false !== get_option( 'twtaeo_s404_recommend', false ) ) {
			delete_option( 'twtaeo_s404_recommend' );
		}

		// Purge redirects created by the retired Gemini layer — its external-link
		// guessing produced unreliable targets (e.g. /uploads/ → a random article).
		$redirects = self::get_redirects();
		$kept      = array();
		foreach ( $redirects as $key => $r ) {
			if ( 'ai' !== ( $r['source'] ?? '' ) ) {
				$kept[ $key ] = $r;
			}
		}
		if ( count( $kept ) !== count( $redirects ) ) {
			update_option( self::OPTION_REDIRECTS, $kept, false );
		}
	}

	// ── Request pipeline ─────────────────────────────────────────────────────────

	public static function handle() {
		if ( ! is_404() || is_admin() ) {
			return;
		}

		$path  = self::request_path();
		$query = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
		if ( '' === $path || '/' === $path ) {
			return;
		}

		// Step 0 — an established 301 (from the GSC scanner or admin) wins outright,
		// and applies to everyone including crawlers.
		$redirects = self::get_redirects();
		$key       = self::normalize_path( $path );
		if ( isset( $redirects[ $key ]['to'] ) && $redirects[ $key ]['to'] !== home_url( $path ) ) {
			wp_safe_redirect( $redirects[ $key ]['to'], 301 );
			exit;
		}

		// Gate 0 — hostile probe: exit with a plain 404. No matching, no storage.
		if ( self::is_hostile_probe( $path, $query ) ) {
			return;
		}

		// Gate 1 — bots: only real visitors are worth rescuing on the request path.
		if ( self::is_bot() ) {
			return;
		}

		$match = self::best_match( $path );
		if ( null === $match ) {
			// No confident typo match. Renamed/moved pages are recovered out-of-band
			// by the GSC lost-page scan (which can also see crawler 404s); nothing to
			// do here.
			return;
		}

		// Self-heal: if the visitor came from one of our own pages via a typo'd
		// link, fix that link at the source so it never 404s again.
		$referrer_id = self::internal_referrer_id();
		if ( $referrer_id && $match['score'] >= self::MIN_HEAL_SCORE ) {
			self::heal_internal_link( $referrer_id, $path, $match );
		}

		if ( $match['score'] >= self::MIN_REDIRECT_SCORE ) {
			wp_safe_redirect( $match['url'], 302 );
			exit;
		}
	}

	// ── Gate 0: hostile-probe filter ─────────────────────────────────────────────

	/**
	 * True when the request path/query looks like an automated attack probe and
	 * the rescue pipeline should be skipped entirely.
	 *
	 * Only ever consulted on requests that already 404'd, so a match can never
	 * block a real page — it only declines to rescue a dead URL.
	 *
	 * @param string $path  Decoded request path.
	 * @param string $query Raw query string.
	 * @return bool
	 */
	public static function is_hostile_probe( $path, $query = '' ) {
		$patterns = self::patterns();
		$haystack = strtolower( $path );
		$target   = $haystack . ( '' !== $query ? '?' . strtolower( $query ) : '' );

		// Exact whole-path probes (generic install/admin locations).
		$exact = strtolower( trim( (string) wp_parse_url( $path, PHP_URL_PATH ), '/' ) );
		if ( '' !== $exact && in_array( $exact, $patterns['exact_paths'], true ) ) {
			return true;
		}

		foreach ( $patterns['contains'] as $needle ) {
			if ( '' !== $needle && false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}

		$ext = strtolower( pathinfo( wp_parse_url( $path, PHP_URL_PATH ) ?: $path, PATHINFO_EXTENSION ) );
		if ( '' !== $ext && in_array( $ext, $patterns['extensions'], true ) ) {
			return true;
		}

		foreach ( $patterns['regex'] as $re ) {
			if ( @preg_match( $re, $target ) === 1 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Load and cache the bundled probe patterns, merged with any admin additions.
	 *
	 * @return array{contains:string[],extensions:string[],regex:string[]}
	 */
	private static function patterns() {
		if ( null !== self::$patterns ) {
			return self::$patterns;
		}

		$file = TWTAEO_PLUGIN_DIR . 'includes/hostile-probe-patterns.php';
		$base = is_readable( $file ) ? include $file : array();
		$base = is_array( $base ) ? $base : array();

		$patterns = array(
			'contains'    => isset( $base['contains'] ) ? array_map( 'strtolower', (array) $base['contains'] ) : array(),
			'exact_paths' => isset( $base['exact_paths'] ) ? array_map( 'strtolower', (array) $base['exact_paths'] ) : array(),
			'extensions'  => isset( $base['extensions'] ) ? array_map( 'strtolower', (array) $base['extensions'] ) : array(),
			'regex'       => isset( $base['regex'] ) ? (array) $base['regex'] : array(),
		);

		$custom = get_option( self::OPTION_CUSTOM_PROBES, array() );
		if ( is_array( $custom ) && ! empty( $custom ) ) {
			$patterns['contains'] = array_values( array_unique(
				array_merge( $patterns['contains'], array_map( 'strtolower', $custom ) )
			) );
		}

		self::$patterns = $patterns;
		return $patterns;
	}

	// ── Gate 1: bot detection ────────────────────────────────────────────────────

	/**
	 * Lightweight user-agent bot check. Path-based Gate 0 already removes most
	 * automated traffic; this catches the rest and any empty-UA requests.
	 *
	 * @return bool
	 */
	private static function is_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) )
			: '';
		if ( '' === $ua ) {
			return true;
		}
		return (bool) preg_match(
			'#bot|crawl|spider|slurp|scan|curl|wget|python|httpclient|libwww|okhttp|headless|facebookexternalhit|semrush|ahrefs|mj12#',
			$ua
		);
	}

	// ── Matching ─────────────────────────────────────────────────────────────────

	/**
	 * Find the best published-URL match for a 404'd path.
	 *
	 * @param string $path
	 * @param float  $min Minimum confidence to accept (default: the live-302 bar).
	 * @return array{url:string,post_id:int,score:float}|null
	 */
	private static function best_match( $path, $min = self::MIN_REDIRECT_SCORE ) {
		$slug = self::last_segment( $path );
		if ( '' === $slug ) {
			return null;
		}

		$best = null;
		foreach ( self::slug_index() as $candidate => $post_id ) {
			$score = self::slug_confidence( $slug, $candidate );
			if ( null === $best || $score > $best['score'] ) {
				$best = array( 'post_id' => (int) $post_id, 'score' => $score );
			}
		}

		if ( null === $best || $best['score'] < $min ) {
			return null;
		}

		$url = get_permalink( $best['post_id'] );
		if ( ! $url ) {
			return null;
		}
		$best['url'] = $url;
		return $best;
	}

	/**
	 * Confidence (0–100) that two slugs are the same page with a typo, from
	 * normalized Damerau-Levenshtein distance.
	 *
	 * @param string $a
	 * @param string $b
	 * @return float
	 */
	private static function slug_confidence( $a, $b ) {
		$a = strtolower( $a );
		$b = strtolower( $b );
		if ( $a === $b ) {
			return 100.0;
		}
		$la = strlen( $a );
		$lb = strlen( $b );
		$max = max( $la, $lb );
		if ( 0 === $max ) {
			return 0.0;
		}
		// A length gap that big can't be a typo — cheap reject before the matrix.
		if ( abs( $la - $lb ) > 5 ) {
			return 0.0;
		}
		return ( 1 - ( self::damerau_osa( $a, $b ) / $max ) ) * 100;
	}

	/**
	 * Optimal String Alignment distance: like Levenshtein, but a swap of two
	 * ADJACENT characters costs 1 edit instead of 2 — so common human typos
	 * ("desing" → "design", "frist" → "first") read as one mistake, not two.
	 *
	 * @param string $a
	 * @param string $b
	 * @return int
	 */
	private static function damerau_osa( $a, $b ) {
		$la = strlen( $a );
		$lb = strlen( $b );
		if ( 0 === $la ) {
			return $lb;
		}
		if ( 0 === $lb ) {
			return $la;
		}

		$d = array();
		for ( $i = 0; $i <= $la; $i++ ) {
			$d[ $i ][0] = $i;
		}
		for ( $j = 0; $j <= $lb; $j++ ) {
			$d[0][ $j ] = $j;
		}

		for ( $i = 1; $i <= $la; $i++ ) {
			for ( $j = 1; $j <= $lb; $j++ ) {
				$cost = ( $a[ $i - 1 ] === $b[ $j - 1 ] ) ? 0 : 1;
				$d[ $i ][ $j ] = min(
					$d[ $i - 1 ][ $j ] + 1,          // deletion
					$d[ $i ][ $j - 1 ] + 1,          // insertion
					$d[ $i - 1 ][ $j - 1 ] + $cost   // substitution
				);
				if ( $i > 1 && $j > 1
					&& $a[ $i - 1 ] === $b[ $j - 2 ]
					&& $a[ $i - 2 ] === $b[ $j - 1 ] ) {
					$d[ $i ][ $j ] = min( $d[ $i ][ $j ], $d[ $i - 2 ][ $j - 2 ] + 1 ); // transposition
				}
			}
		}

		return $d[ $la ][ $lb ];
	}

	/**
	 * Build (or read from transient) a map of published slug → post ID for all
	 * public post types. Permalinks are resolved lazily only for a winner.
	 *
	 * @return array<string,int>
	 */
	private static function slug_index() {
		$cached = get_transient( self::TRANSIENT_SLUGS );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$types = get_post_types( array( 'public' => true ), 'names' );
		if ( empty( $types ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// Bulk slug map with no core API equivalent; the result is cached in the
		// transient below, and the IN() list is fully parameterized via prepare()
		// with one %s per post type. The sniffs can't model the dynamic placeholder
		// count, so they are suppressed for this single, cached, prepared statement.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_name FROM {$wpdb->posts}
				 WHERE post_status = 'publish' AND post_name <> ''
				 AND post_type IN ($placeholders)
				 LIMIT 20000",
				$types
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$index = array();
		foreach ( (array) $rows as $row ) {
			$index[ $row['post_name'] ] = (int) $row['ID'];
		}

		set_transient( self::TRANSIENT_SLUGS, $index, self::SLUG_INDEX_TTL );
		return $index;
	}

	public static function flush_slug_index() {
		delete_transient( self::TRANSIENT_SLUGS );
	}

	// ── Self-heal ────────────────────────────────────────────────────────────────

	/**
	 * Correct a broken internal link at its source and record the change for undo.
	 * Only exact `href="…404 path…"` occurrences are rewritten, so nothing else
	 * in the content is touched.
	 *
	 * @param int    $post_id  Referring post that held the broken link.
	 * @param string $bad_path The 404'd path that was linked to.
	 * @param array  $match    { url, post_id, score } of the correct target.
	 */
	private static function heal_internal_link( $post_id, $bad_path, array $match ) {
		// Don't heal the same broken link twice.
		foreach ( self::get_heal_log() as $entry ) {
			if ( (int) $entry['post_id'] === (int) $post_id && $entry['bad_path'] === $bad_path ) {
				return;
			}
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		$correct   = $match['url'];
		$want      = self::normalize_path( $bad_path );
		if ( '' === $want ) {
			return;
		}
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		// Match by normalized link PATH, not exact string — so a stored href still
		// heals regardless of protocol (http/https), host form, or trailing slash.
		// Each rewrite is recorded so an undo reverses precisely what we changed.
		$edits   = array();
		$content = preg_replace_callback(
			'#href=(["\'])(.*?)\1#i',
			function ( $m ) use ( $want, $correct, $home_host, &$edits ) {
				$href = $m[2];
				$host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
				// Only internal links: relative, or pointing at our own host.
				if ( '' !== $host && $host !== $home_host ) {
					return $m[0];
				}
				if ( self::normalize_path( (string) wp_parse_url( $href, PHP_URL_PATH ) ) !== $want ) {
					return $m[0];
				}
				$new     = 'href=' . $m[1] . esc_url( $correct ) . $m[1];
				$edits[] = array( 'old' => $m[0], 'new' => $new );
				return $new;
			},
			$post->post_content
		);

		if ( null === $content || empty( $edits ) ) {
			return; // No internal link resolved to the dead path — leave content untouched.
		}

		wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ) );

		self::push_heal_log( array(
			'time'     => time(),
			'post_id'  => (int) $post_id,
			'bad_path' => $bad_path,
			'from'     => home_url( $bad_path ),
			'to'       => $correct,
			'score'    => round( (float) $match['score'], 1 ),
			'edits'    => $edits,
		) );
	}

	/**
	 * Reverse a healed link: restore the exact original href(s) in the post and
	 * drop the log entry. Best-effort — if the content changed since, only the
	 * edits still present are reversed.
	 *
	 * @param int    $post_id
	 * @param string $bad_path
	 * @return bool True if an entry was found and removed.
	 */
	public static function undo_heal( $post_id, $bad_path ) {
		$log   = self::get_heal_log();
		$found = false;
		$kept  = array();

		foreach ( $log as $entry ) {
			if ( ! $found && (int) $entry['post_id'] === (int) $post_id && $entry['bad_path'] === $bad_path ) {
				$found = true;
				$post  = get_post( $post_id );
				if ( $post ) {
					$content = $post->post_content;
					// Reverse each edit left-to-right, one occurrence at a time, so
					// several links rewritten to the SAME permalink each restore to
					// their own original href rather than collapsing to the first.
					$offset = 0;
					foreach ( (array) ( $entry['edits'] ?? array() ) as $edit ) {
						if ( ! isset( $edit['old'], $edit['new'] ) ) {
							continue;
						}
						$pos = strpos( $content, $edit['new'], $offset );
						if ( false === $pos ) {
							$pos = strpos( $content, $edit['new'] );
						}
						if ( false !== $pos ) {
							$content = substr_replace( $content, $edit['old'], $pos, strlen( $edit['new'] ) );
							$offset  = $pos + strlen( $edit['old'] );
						}
					}
					if ( $content !== $post->post_content ) {
						wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ) );
					}
				}
				continue; // Drop this entry from the log.
			}
			$kept[] = $entry;
		}

		if ( $found ) {
			update_option( self::OPTION_HEAL_LOG, $kept, false );
		}
		return $found;
	}

	/** @return array[] Newest-first undo log. */
	public static function get_heal_log() {
		$log = get_option( self::OPTION_HEAL_LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	private static function push_heal_log( array $entry ) {
		$log = self::get_heal_log();
		array_unshift( $log, $entry );
		if ( count( $log ) > self::MAX_HEAL_LOG ) {
			$log = array_slice( $log, 0, self::MAX_HEAL_LOG );
		}
		update_option( self::OPTION_HEAL_LOG, $log, false );
	}

	// ── Persistent 301 store + admin actions ─────────────────────────────────────

	/** @return array<string,array> Path-keyed redirect store. */
	public static function get_redirects() {
		$r = get_option( self::OPTION_REDIRECTS, array() );
		return is_array( $r ) ? $r : array();
	}

	/**
	 * Persist a 301 created from confirmed external equity. Bounded FIFO.
	 *
	 * @param string   $from_path
	 * @param string   $to_url
	 * @param string[] $sources External linking pages that justified the redirect.
	 */
	private static function add_redirect( $from_path, $to_url, array $sources, $source = 'ai' ) {
		$key = self::normalize_path( $from_path );
		if ( '' === $key || $to_url === home_url( $from_path ) ) {
			return;
		}

		$redirects = self::get_redirects();
		$redirects[ $key ] = array(
			'from'    => $from_path,
			'to'      => esc_url_raw( $to_url ),
			'created' => time(),
			'source'  => in_array( $source, array( 'manual', 'ai', 'gsc' ), true ) ? $source : 'ai',
			'equity'  => array_slice( $sources, 0, 5 ),
		);

		if ( count( $redirects ) > self::MAX_REDIRECTS ) {
			uasort( $redirects, static function ( $a, $b ) {
				return ( $a['created'] ?? 0 ) <=> ( $b['created'] ?? 0 );
			} );
			$redirects = array_slice( $redirects, count( $redirects ) - self::MAX_REDIRECTS, null, true );
		}

		update_option( self::OPTION_REDIRECTS, $redirects, false );
	}

	// ── Admin actions (consumed by the settings page) ────────────────────────────

	/**
	 * Create a redirect an admin chose by hand.
	 *
	 * @param string $from_path
	 * @param string $to_url
	 */
	public static function add_manual_redirect( $from_path, $to_url ) {
		$to_url = esc_url_raw( trim( $to_url ) );
		if ( '' === trim( $from_path ) || '' === $to_url ) {
			return;
		}
		self::add_redirect( $from_path, $to_url, array(), 'manual' );
	}

	/**
	 * Create a redirect from another subsystem (e.g. the GSC lost-page scanner).
	 * Public wrapper over the bounded store so callers don't touch internals.
	 *
	 * @param string   $from_path Dead path (or full URL).
	 * @param string   $to_url    Live target URL.
	 * @param string   $source    'gsc' | 'manual' | 'ai'.
	 * @param string[] $evidence  Optional supporting URLs (linking pages, etc.).
	 */
	public static function create_redirect( $from_path, $to_url, $source = 'gsc', array $evidence = array() ) {
		self::add_redirect( $from_path, $to_url, $evidence, $source );
	}

	/** True if a redirect already exists for this path (avoids re-proposing). */
	public static function has_redirect( $path ) {
		return isset( self::get_redirects()[ self::normalize_path( $path ) ] );
	}

	/** Remove a redirect from the store by its normalized path key. */
	public static function delete_redirect( $key ) {
		$redirects = self::get_redirects();
		if ( isset( $redirects[ $key ] ) ) {
			unset( $redirects[ $key ] );
			update_option( self::OPTION_REDIRECTS, $redirects, false );
		}
	}

	/** @return string[] Admin-added probe substrings. */
	public static function get_custom_probes() {
		$c = get_option( self::OPTION_CUSTOM_PROBES, array() );
		return is_array( $c ) ? array_values( $c ) : array();
	}

	/**
	 * Replace the admin custom-probe list. Values are lowercased, trimmed, deduped.
	 *
	 * @param string[] $patterns
	 */
	public static function save_custom_probes( array $patterns ) {
		$clean = array();
		foreach ( $patterns as $p ) {
			$p = strtolower( trim( wp_strip_all_tags( (string) $p ) ) );
			if ( '' !== $p ) {
				$clean[] = $p;
			}
		}
		update_option( self::OPTION_CUSTOM_PROBES, array_values( array_unique( $clean ) ), false );
		self::$patterns = null; // Invalidate the in-process cache.
	}

	/** Normalize a path to a stable store key: lowercase, no leading/trailing slash. */
	private static function normalize_path( $path ) {
		$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		return strtolower( trim( $path, '/' ) );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	/** Decoded path portion of the current request, without query string. */
	private static function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return rawurldecode( $path );
	}

	/** Final non-empty path segment (the presumed slug). */
	private static function last_segment( $path ) {
		$parts = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
		return empty( $parts ) ? '' : end( $parts );
	}

	/**
	 * If the referrer is a page on this site, return its post ID; else 0.
	 *
	 * @return int
	 */
	private static function internal_referrer_id() {
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		if ( '' === $ref ) {
			return 0;
		}
		$ref_host  = strtolower( (string) wp_parse_url( $ref, PHP_URL_HOST ) );
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $ref_host || $ref_host !== $home_host ) {
			return 0;
		}
		return (int) url_to_postid( $ref );
	}
}
