<?php
/**
 * Lost Page Recovery — GSC-driven 301 builder.
 *
 * Finds URLs that once had search presence but now 404, matches each to the
 * closest live page by keyword overlap, and creates permanent 301s (which apply
 * to Googlebot too, unlike the per-visitor Smart 404 redirect). Two sources:
 *
 *   - Search Analytics (automatic): pulls the URLs Google shows you in results
 *     via the plugin's existing GSC connection, then keeps the ones that 404.
 *   - GSC export (manual): the "Not found (404)" coverage CSV, pasted/uploaded,
 *     for the long tail that has no impressions yet.
 *
 * Matching uses token overlap (not edit distance) because real lost pages are
 * renames and moves — different slugs, same topic — which edit distance misses.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Lost_Pages {

	/** Bounded store of the last scan's review candidates. */
	const OPTION_RESULTS = 'twtaeo_lost_pages';

	/** Confidence + matched-word bars for auto-creating a 301. */
	const AUTO_SCORE    = 70;
	const AUTO_MATCHED  = 2;   // Guard: a single shared word (e.g. "aeo") is not enough.
	const REVIEW_SCORE  = 45;

	const MAX_URLS    = 300;   // Per-scan ceiling — bounds HEAD-check time.
	const MAX_RESULTS = 500;   // Bounded review store.

	/** Words too generic to carry matching weight. */
	private static $stop = array(
		'the','a','an','to','of','for','and','or','in','on','is','how','why','what','your','you',
		'are','be','with','from','it','that','this','at','by','as','not','get','was','were','will',
		'can','do','it','its','our','we','they','their','vs','why','into','about','when','which',
	);

	// ── Scan orchestration ───────────────────────────────────────────────────────

	/**
	 * Automatic scan: pull pages from Search Analytics, keep the 404s, match+act.
	 *
	 * @param int $days
	 * @return array|WP_Error  Scan summary.
	 */
	public static function run_gsc_scan( $days = 90 ) {
		if ( ! class_exists( 'TWTAEO_Google_OAuth' ) ) {
			return new WP_Error( 'no_oauth', __( 'Google integration unavailable.', 'twt-aeo-ultimate' ) );
		}
		$config = TWTAEO_Google_OAuth::get_config();
		$site   = $config['gsc_site_url'] ?? '';
		if ( '' === $site ) {
			return new WP_Error( 'not_connected', __( 'Connect Google Search Console first (TWT AEO → Settings).', 'twt-aeo-ultimate' ) );
		}

		$data = TWTAEO_Google_OAuth::gsc_search_analytics( $site, array(
			'startDate'  => gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ),
			'endDate'    => gmdate( 'Y-m-d' ),
			'dimensions' => array( 'page' ),
			'rowLimit'   => self::MAX_URLS,
		) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$urls = array();
		foreach ( (array) ( $data['rows'] ?? array() ) as $row ) {
			$u = $row['keys'][0] ?? '';
			if ( '' !== $u ) {
				$urls[] = $u;
			}
		}
		return self::scan_urls( $urls, 'gsc-analytics' );
	}

	/**
	 * Scan an explicit list of URLs (e.g. pasted from the GSC coverage export).
	 *
	 * @param string[] $urls
	 * @return array
	 */
	public static function scan_urls( array $urls, $source = 'import' ) {
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$auto = array();
		$review = array();
		$processed = 0;

		foreach ( array_unique( $urls ) as $url ) {
			if ( $processed >= self::MAX_URLS ) {
				break;
			}
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( $host && $host !== $home_host ) {
				continue; // Only our own URLs.
			}
			// Skip obvious probe/asset noise using Smart 404's Gate 0.
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( class_exists( 'TWTAEO_Smart_404' ) && TWTAEO_Smart_404::is_hostile_probe( $path ) ) {
				continue;
			}
			if ( class_exists( 'TWTAEO_Smart_404' ) && TWTAEO_Smart_404::has_redirect( $path ) ) {
				continue; // Already handled.
			}
			if ( ! self::is_404( $url ) ) {
				continue; // Still live, or already redirects — nothing to do.
			}
			$processed++;

			$match = self::find_target( $url );
			if ( $match && $match['score'] >= self::AUTO_SCORE && $match['matched'] >= self::AUTO_MATCHED ) {
				if ( class_exists( 'TWTAEO_Smart_404' ) ) {
					TWTAEO_Smart_404::create_redirect( $path, $match['url'], 'gsc' );
				}
				$auto[] = array( 'from' => $url, 'to' => $match['url'], 'score' => $match['score'] );
			} elseif ( $match && $match['score'] >= self::REVIEW_SCORE ) {
				$review[] = array( 'from' => $url, 'path' => $path, 'to' => $match['url'], 'score' => $match['score'], 'title' => $match['title'] );
			} else {
				$review[] = array( 'from' => $url, 'path' => $path, 'to' => '', 'score' => 0, 'title' => '' );
			}
		}

		$summary = array(
			'scanned'  => $processed,
			'auto'     => count( $auto ),
			'review'   => count( $review ),
			'source'   => $source,
			'time'     => time(),
			'auto_list'   => array_slice( $auto, 0, self::MAX_RESULTS ),
			'review_list' => array_slice( $review, 0, self::MAX_RESULTS ),
		);
		update_option( self::OPTION_RESULTS, $summary, false );
		return $summary;
	}

	// ── Matching ─────────────────────────────────────────────────────────────────

	/**
	 * Find the best live target for a dead URL by keyword overlap against titles.
	 *
	 * @param string $dead_url
	 * @return array{url:string,post_id:int,score:int,matched:int,title:string}|null
	 */
	public static function find_target( $dead_url ) {
		$slug = self::last_segment( $dead_url );
		$dw   = self::words( $slug );
		if ( count( $dw ) < 1 ) {
			return null;
		}

		$q = new WP_Query( array(
			'post_type'           => get_post_types( array( 'public' => true ), 'names' ),
			'post_status'         => 'publish',
			's'                   => implode( ' ', $dw ),
			'posts_per_page'      => 5,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'fields'              => 'ids',
		) );

		$best = null;
		foreach ( (array) $q->posts as $pid ) {
			$url = get_permalink( $pid );
			if ( ! $url ) {
				continue;
			}
			list( $score, $matched ) = self::overlap( $dw, $url, get_the_title( $pid ) );
			if ( null === $best || $score > $best['score'] ) {
				$best = array(
					'url'     => $url,
					'post_id' => (int) $pid,
					'score'   => $score,
					'matched' => $matched,
					'title'   => html_entity_decode( get_the_title( $pid ), ENT_QUOTES, 'UTF-8' ),
				);
			}
		}
		return $best;
	}

	/**
	 * Token-overlap score of a dead slug's words against a candidate's URL + title.
	 *
	 * @param string[] $dead_words
	 * @param string   $cand_url
	 * @param string   $cand_title
	 * @return array{0:int,1:int}  [score 0-100, matched-word count]
	 */
	private static function overlap( array $dead_words, $cand_url, $cand_title ) {
		if ( empty( $dead_words ) ) {
			return array( 0, 0 );
		}
		$hay = ' ' . strtolower( str_replace( array( '-', '/' ), ' ',
			(string) wp_parse_url( $cand_url, PHP_URL_PATH ) . ' ' . $cand_title ) ) . ' ';
		$hay = preg_replace( '/[^a-z0-9 ]+/', ' ', $hay );
		$matched = 0;
		foreach ( $dead_words as $w ) {
			if ( false !== strpos( $hay, $w ) ) {
				$matched++;
			}
		}
		return array( (int) round( $matched / count( $dead_words ) * 100 ), $matched );
	}

	/** Significant, de-duplicated words of a slug (lowercase, stopwords removed). */
	private static function words( $slug ) {
		$s = strtolower( preg_replace( '/[^a-z0-9]+/i', ' ', str_replace( '-', ' ', $slug ) ) );
		$out = array();
		foreach ( preg_split( '/\s+/', trim( $s ) ) as $w ) {
			if ( strlen( $w ) > 2 && ! in_array( $w, self::$stop, true ) ) {
				$out[ $w ] = 1;
			}
		}
		return array_keys( $out );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	/** True when a URL currently returns a 404 (not live, not already redirecting). */
	private static function is_404( $url ) {
		$r = wp_remote_head( $url, array( 'timeout' => 10, 'redirection' => 0 ) );
		if ( is_wp_error( $r ) ) {
			return false; // Can't confirm — don't act.
		}
		return (int) wp_remote_retrieve_response_code( $r ) === 404;
	}

	private static function last_segment( $url ) {
		$parts = array_values( array_filter( explode( '/', trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) ) ) );
		return empty( $parts ) ? '' : end( $parts );
	}

	public static function get_results() {
		$r = get_option( self::OPTION_RESULTS, array() );
		return is_array( $r ) ? $r : array();
	}

	public static function clear_results() {
		delete_option( self::OPTION_RESULTS );
	}

	/** Parse URLs out of a pasted GSC export (CSV or newline list). */
	public static function parse_url_list( $raw ) {
		$urls = array();
		foreach ( preg_split( '/[\r\n]+/', (string) $raw ) as $line ) {
			$line = trim( $line );
			// Take the first column if CSV; ignore wildcards and headers.
			$first = trim( explode( ',', $line )[0] );
			if ( preg_match( '#^https?://#i', $first ) && false === strpos( $first, '*' ) ) {
				$urls[] = esc_url_raw( $first );
			}
		}
		return array_values( array_unique( array_filter( $urls ) ) );
	}
}
