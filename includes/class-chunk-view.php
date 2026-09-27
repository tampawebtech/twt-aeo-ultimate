<?php
/**
 * Chunk View — show the page the way a retrieval index will hold it.
 *
 * The sequel to Bot View. Bot View answers "what does the crawler receive?";
 * this answers "once it has that, how does it cut the page up, and does each
 * piece survive being cut away from the rest?"
 *
 * Deliberately free of every external dependency: it reuses TWTAEO_Bot_View for
 * the no-JavaScript fetch and TWTAEO_HTML_To_Markdown for cleaning, then splits
 * and scores in pure PHP. No API key, no embeddings, no remote service. That is
 * not a limitation — the retrieval failures it detects are mechanical, so a
 * model would add cost without adding truth.
 *
 * Every entry point runs behind TWTAEO_Host_Profile so a cheap host slows down
 * or pauses rather than falling over.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Chunk_View {

	/** Module slug in TWTAEO_Module_Loader. */
	const MODULE = 'chunk-view';

	/** Cached per-post analyses, keyed by post ID. Not autoloaded. */
	const OPTION_CACHE = 'twtaeo_chunk_view_cache';

	/** How many analyses to retain before the oldest is dropped. */
	const CACHE_LIMIT = 40;

	/**
	 * The chunks themselves, per post. Kept in post meta rather than the summary
	 * option so wp_options stays small; the "_twtaeo_" prefix means uninstall
	 * already removes it.
	 */
	const META_CHUNKS = '_twtaeo_chunk_view_chunks';

	private function __construct() {}

	/** True when the module is switched on. */
	public static function is_module_active() {
		if ( ! class_exists( 'TWTAEO_Module_Loader' ) ) {
			return false;
		}

		$loader = new TWTAEO_Module_Loader();

		return $loader->is_active( self::MODULE );
	}

	/* ─────────────────────────── analysis ─────────────────────────── */

	/**
	 * Analyse a single URL: fetch as a bot, clean, chunk, score.
	 *
	 * @param string $url     URL to analyse.
	 * @param int    $post_id Optional post ID, for caching and linking.
	 * @return array|WP_Error
	 */
	public static function analyze_url( $url, $post_id = 0 ) {
		$gate = TWTAEO_Host_Profile::can_run();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		TWTAEO_Host_Profile::budget_start();

		$fetched = TWTAEO_Bot_View::fetch( $url, TWTAEO_Bot_View::BOT_UA );
		if ( is_wp_error( $fetched ) ) {
			// Deliberately NOT a breaker failure. A bad URL, a DNS miss or a
			// refused connection says nothing about whether this host can cope
			// with indexing — and three typos in a row would otherwise pause
			// the engine and tell the user their server is at fault.
			//
			// The breaker exists for local distress: ticks that blow their time
			// or memory budget, and fatals. Those are raised by the work loop,
			// not by a one-shot fetch the user asked for.
			TWTAEO_Host_Profile::record_success();

			return $fetched;
		}

		$code = isset( $fetched['code'] ) ? (int) $fetched['code'] : 0;
		$html = isset( $fetched['body'] ) ? (string) $fetched['body'] : '';

		if ( $code < 200 || $code >= 400 || '' === trim( $html ) ) {
			TWTAEO_Host_Profile::record_success(); // The host behaved; the URL did not.

			// Ask again as a browser. If that works, the page is fine and the
			// server is refusing GPTBot specifically — which means the real
			// GPTBot is refused too, and that is the finding, not a fetch error.
			$browser      = TWTAEO_Bot_View::fetch( $url, TWTAEO_Bot_View::BROWSER_UA );
			$browser_code = is_array( $browser ) && isset( $browser['code'] ) ? (int) $browser['code'] : 0;
			if ( $browser_code >= 200 && $browser_code < 400 ) {
				return new WP_Error(
					'twtaeo_chunk_view_bot_blocked',
					sprintf(
						/* translators: 1: HTTP status the crawler received, 2: HTTP status a browser received. */
						__( 'This server refuses GPTBot: it answered HTTP %1$d to the crawler but HTTP %2$d to a normal browser. The real GPTBot is turned away the same way, so ChatGPT cannot read or cite this page. The block sits in front of WordPress — look for a user-agent rule in .htaccess, a security or "block AI bots" plugin, a firewall/CDN bot setting, or ask your host.', 'twt-aeo-ultimate' ),
						$code,
						$browser_code
					)
				);
			}

			return new WP_Error(
				'twtaeo_chunk_view_http',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The crawler received HTTP %d with no usable content. Check Bot View for this URL first.', 'twt-aeo-ultimate' ),
					$code
				)
			);
		}

		$markdown = self::to_markdown( $html );

		if ( '' === trim( $markdown ) ) {
			TWTAEO_Host_Profile::record_success();

			return new WP_Error(
				'twtaeo_chunk_view_empty',
				__( 'The crawler received HTML but no readable text — the content is probably rendered by JavaScript. Bot View will show what arrived.', 'twt-aeo-ultimate' )
			);
		}

		$chunks = TWTAEO_Chunker::chunk( $markdown );

		$result = array(
			'url'          => $url,
			'post_id'      => (int) $post_id,
			'analyzed_at'  => time(),
			'http_code'    => $code,
			'html_bytes'   => strlen( $html ),
			'text_chars'   => strlen( $markdown ),
			'chunk_count'  => count( $chunks ),
			'page_score'   => TWTAEO_Chunker::page_score( $chunks ),
			'chunks'       => $chunks,
			'issue_totals' => self::tally_issues( $chunks ),
			'elapsed'      => TWTAEO_Host_Profile::tick_elapsed(),
		);

		TWTAEO_Host_Profile::record_success();

		if ( $post_id ) {
			self::cache_put( (int) $post_id, $result );
		}

		return $result;
	}

	/**
	 * Analyse a published post by ID.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public static function analyze_post( $post_id ) {
		$post_id = absint( $post_id );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return new WP_Error( 'twtaeo_chunk_view_post', __( 'That post could not be found.', 'twt-aeo-ultimate' ) );
		}

		$url = get_permalink( $post );
		if ( ! $url ) {
			return new WP_Error( 'twtaeo_chunk_view_url', __( 'That post has no public URL to fetch.', 'twt-aeo-ultimate' ) );
		}

		return self::analyze_url( $url, $post_id );
	}

	/**
	 * Convert fetched HTML to clean Markdown, after narrowing to the page's
	 * main content region.
	 *
	 * The narrowing is the important half. Without it the first chunk of every
	 * page is the document title and a skip link, and on a real site the nav
	 * menu, cookie banner and footer each become their own scored chunk. Those
	 * are never what gets cited, so reporting "your navigation is not
	 * self-contained" is noise that teaches people to ignore the screen.
	 *
	 * Retrieval pipelines do the same narrowing before they index, so matching
	 * that behaviour is also the more faithful simulation.
	 */
	private static function to_markdown( $html ) {
		$html = self::main_content_html( $html );

		if ( class_exists( 'TWTAEO_HTML_To_Markdown' ) ) {
			$markdown = (string) TWTAEO_HTML_To_Markdown::convert( $html );
		} else {
			$stripped = preg_replace( '#<(script|style|nav|header|footer|aside|form|noscript)[^>]*>.*?</\1>#is', '', $html );
			$markdown = wp_strip_all_tags( (string) $stripped );
		}

		return trim( self::strip_chrome_lines( $markdown ) );
	}

	/**
	 * Narrow HTML to its main content region, most specific container first.
	 *
	 * Falls back to the whole document when nothing matches, because a page
	 * with no recognisable content wrapper is still worth analysing — it just
	 * gets analysed as-is rather than not at all.
	 *
	 * @param string $html Full page HTML.
	 * @return string
	 */
	private static function main_content_html( $html ) {
		if ( ! class_exists( 'DOMDocument' ) || '' === trim( $html ) ) {
			return $html;
		}

		$previous = libxml_use_internal_errors( true );

		$doc = new DOMDocument();
		// Force UTF-8: libxml assumes Latin-1 without a declared encoding and
		// mangles anything non-ASCII, which shows up as broken spec symbols.
		$loaded = $doc->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $html;
		}

		$xpath = new DOMXPath( $doc );

		// Remove non-content furniture wherever it sits in the tree.
		$junk = $xpath->query(
			'//script | //style | //noscript | //nav | //header | //footer | //aside | //form'
			. ' | //*[@role="navigation"] | //*[@role="banner"] | //*[@role="contentinfo"]'
			. ' | //*[@aria-hidden="true"]'
			. ' | //*[contains(concat(" ", normalize-space(@class), " "), " screen-reader-text ")]'
			. ' | //*[contains(concat(" ", normalize-space(@class), " "), " skip-link ")]'
		);

		if ( $junk ) {
			foreach ( iterator_to_array( $junk ) as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		// Most specific content container wins.
		$candidates = array(
			'//main',
			'//*[@role="main"]',
			'//article',
			'//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " page-content ")]',
			'//*[@id="content"]',
			'//body',
		);

		foreach ( $candidates as $query ) {
			$found = $xpath->query( $query );
			if ( ! $found || 0 === $found->length ) {
				continue;
			}

			$inner = '';
			foreach ( $found as $node ) {
				$inner .= $doc->saveHTML( $node );
			}

			// A container that holds almost nothing is the wrong container.
			if ( strlen( wp_strip_all_tags( $inner ) ) > 200 ) {
				return $inner;
			}
		}

		return $html;
	}

	/**
	 * Drop residual chrome the converter leaves behind as plain lines —
	 * skip links, and a leading bare page title that duplicates the H1.
	 *
	 * @param string $markdown Converted Markdown.
	 * @return string
	 */
	private static function strip_chrome_lines( $markdown ) {
		$lines = explode( "\n", (string) $markdown );
		$out   = array();

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			// Skip links, in any of the forms themes emit them.
			if ( preg_match( '/^\[?\s*skip to (?:main )?content\s*\]?/i', $trimmed ) ) {
				continue;
			}

			// A link-only line pointing at an in-page anchor is navigation.
			if ( preg_match( '/^\[[^\]]*\]\(#[^)]*\)$/', $trimmed ) ) {
				continue;
			}

			$out[] = $line;
		}

		return implode( "\n", $out );
	}

	/**
	 * Count issues by code across a set of chunks, so the page summary can lead
	 * with the failure that appears most often.
	 *
	 * @param array[] $chunks Chunk arrays.
	 * @return array<string,int>
	 */
	private static function tally_issues( array $chunks ) {
		$totals = array();

		foreach ( $chunks as $chunk ) {
			if ( empty( $chunk['issues'] ) || ! is_array( $chunk['issues'] ) ) {
				continue;
			}
			foreach ( $chunk['issues'] as $issue ) {
				$code = isset( $issue['code'] ) ? $issue['code'] : 'unknown';

				$totals[ $code ] = isset( $totals[ $code ] ) ? $totals[ $code ] + 1 : 1;
			}
		}

		arsort( $totals );

		return $totals;
	}

	/* ─────────────────────────── cache ─────────────────────────── */

	/**
	 * Store an analysis, trimming the oldest once CACHE_LIMIT is reached.
	 *
	 * Chunk bodies are dropped from the cached copy: a full page of chunk text
	 * is tens of kilobytes, and forty of those in one option row is exactly the
	 * kind of bloat wp_options should never carry. The summary is what the
	 * listing screen needs; a detail view re-analyses.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $result  Analysis result.
	 */
	private static function cache_put( $post_id, array $result ) {
		$cache = get_option( self::OPTION_CACHE, array() );
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}

		$summary = $result;
		unset( $summary['chunks'] );

		$cache[ $post_id ] = $summary;

		// Without the chunks a saved analysis is only a score — nothing to
		// reopen. update_post_meta() unslashes, so slash first or backslashes
		// in the page text are eaten.
		update_post_meta( $post_id, self::META_CHUNKS, wp_slash( isset( $result['chunks'] ) ? $result['chunks'] : array() ) );

		if ( count( $cache ) > self::CACHE_LIMIT ) {
			uasort(
				$cache,
				static function ( $a, $b ) {
					$a_time = isset( $a['analyzed_at'] ) ? (int) $a['analyzed_at'] : 0;
					$b_time = isset( $b['analyzed_at'] ) ? (int) $b['analyzed_at'] : 0;

					return $a_time <=> $b_time;
				}
			);

			$kept  = array_slice( $cache, -self::CACHE_LIMIT, null, true );
			foreach ( array_diff_key( $cache, $kept ) as $dropped_id => $unused ) {
				delete_post_meta( (int) $dropped_id, self::META_CHUNKS );
			}
			$cache = $kept;
		}

		update_option( self::OPTION_CACHE, $cache, false );
	}

	/**
	 * Cached summaries, newest first.
	 *
	 * @return array[]
	 */
	public static function get_cached() {
		$cache = get_option( self::OPTION_CACHE, array() );
		if ( ! is_array( $cache ) ) {
			return array();
		}

		uasort(
			$cache,
			static function ( $a, $b ) {
				$a_time = isset( $a['analyzed_at'] ) ? (int) $a['analyzed_at'] : 0;
				$b_time = isset( $b['analyzed_at'] ) ? (int) $b['analyzed_at'] : 0;

				return $b_time <=> $a_time;
			}
		);

		return $cache;
	}

	/**
	 * One saved analysis with its chunks, for reopening from the history.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Null when nothing is saved for this post. Analyses
	 *                    saved before the chunks were kept come back with an
	 *                    empty `chunks` list.
	 */
	public static function get_cached_result( $post_id ) {
		$post_id = absint( $post_id );
		$cache   = get_option( self::OPTION_CACHE, array() );
		if ( ! $post_id || ! is_array( $cache ) || empty( $cache[ $post_id ] ) || ! is_array( $cache[ $post_id ] ) ) {
			return null;
		}
		$result           = $cache[ $post_id ];
		$chunks           = get_post_meta( $post_id, self::META_CHUNKS, true );
		$result['chunks'] = is_array( $chunks ) ? $chunks : array();

		return $result;
	}

	/** Forget every cached analysis. */
	public static function clear_cache() {
		$cache = get_option( self::OPTION_CACHE, array() );
		if ( is_array( $cache ) ) {
			foreach ( array_keys( $cache ) as $post_id ) {
				delete_post_meta( (int) $post_id, self::META_CHUNKS );
			}
		}
		delete_option( self::OPTION_CACHE );
	}

	/* ─────────────────────────── presentation ─────────────────────────── */

	/**
	 * Band a score for display. Kept here so the page and any future report
	 * agree on where the lines fall.
	 *
	 * @param int $score 0–100.
	 * @return array{label:string, color:string}
	 */
	public static function band( $score ) {
		$score = (int) $score;

		if ( $score >= 85 ) {
			return array(
				'label' => __( 'Retrievable', 'twt-aeo-ultimate' ),
				'color' => '#00753a',
			);
		}

		if ( $score >= 60 ) {
			return array(
				'label' => __( 'Needs context', 'twt-aeo-ultimate' ),
				'color' => '#996800',
			);
		}

		return array(
			'label' => __( 'Not self-contained', 'twt-aeo-ultimate' ),
			'color' => '#b32d2e',
		);
	}

	/**
	 * Candidate posts for the picker: published, public, newest first.
	 *
	 * @param int $limit Maximum posts to return.
	 * @return WP_Post[]
	 */
	public static function candidate_posts( $limit = 100 ) {
		$types = get_post_types(
			array(
				'public'             => true,
				'publicly_queryable' => true,
			)
		);

		// get_post_types() omits 'page' under publicly_queryable; add it back.
		$types['page'] = 'page';
		unset( $types['attachment'] );

		return get_posts(
			array(
				'post_type'        => array_values( $types ),
				'post_status'      => 'publish',
				'numberposts'      => (int) $limit,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
	}
}
