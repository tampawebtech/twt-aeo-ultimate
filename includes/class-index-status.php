<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Connects to the GSC URL Inspection API to identify de-indexed pages,
 * then pairs each result with the local heuristic scan.
 */
class TWTAEO_Index_Status {

	const NONCE       = 'twtaeo_index_status';
	const OPTION_SCAN = 'twtaeo_index_scan';

	// Coverage states treated as "not indexed".
	const NOT_INDEXED = array(
		'Crawled - currently not indexed',
		'Discovered - currently not indexed',
		'Duplicate without user-selected canonical',
		'Duplicate, Google chose different canonical than user',
		'Excluded by \'noindex\' tag',
		// Google has forgotten the URL entirely — the verdict is NEUTRAL, not
		// FAIL, so it has to be listed here or it would read as "indexed".
		'URL is unknown to Google',
	);

	/*
	 * Crawl-age thresholds (days since Googlebot last fetched an indexed URL).
	 * A study of 1.4M GSC URLs found pages uncrawled for ~130 days become
	 * markedly more likely to drop from the index (~190 days → "unknown").
	 * Watch starts earlier so there is time to act before the cliff.
	 */
	const CRAWL_WATCH_DAYS = 90;
	const CRAWL_RISK_DAYS  = 130;

	// ── Crawl Age ────────────────────────────────────────────────────────────

	/**
	 * Whole days since Google last crawled the URL, or null when unknown.
	 *
	 * @param array|null $gsc Stored GSC result row.
	 * @return int|null
	 */
	public static function crawl_age_days( $gsc ) {
		$last = is_array( $gsc ) ? ( $gsc['last_crawl'] ?? '' ) : '';
		$ts   = $last ? strtotime( $last ) : false;
		if ( ! $ts ) {
			return null;
		}
		return max( 0, (int) floor( ( time() - $ts ) / DAY_IN_SECONDS ) );
	}

	/**
	 * Crawl-staleness bucket for an INDEXED page: 'risk', 'watch' or ''.
	 * Not-indexed pages return '' — they already have a bigger problem shown.
	 *
	 * @param array|null $gsc Stored GSC result row.
	 * @return string
	 */
	public static function crawl_risk( $gsc ) {
		if ( ! is_array( $gsc ) || ! empty( $gsc['not_indexed'] ) ) {
			return '';
		}
		$age = self::crawl_age_days( $gsc );
		if ( null === $age ) {
			return '';
		}
		if ( $age >= self::CRAWL_RISK_DAYS ) {
			return 'risk';
		}
		return $age >= self::CRAWL_WATCH_DAYS ? 'watch' : '';
	}

	/**
	 * Indexed pages whose last Google crawl is in the watch or risk range,
	 * oldest crawl first.
	 *
	 * @return array[]
	 */
	public static function get_stale_crawls() {
		$stale = array();
		foreach ( self::get_results() as $post_id => $result ) {
			$gsc  = $result['gsc'] ?? null;
			$risk = self::crawl_risk( $gsc );
			if ( '' === $risk || self::is_excluded_page( $post_id ) || 'publish' !== get_post_status( (int) $post_id ) ) {
				continue;
			}
			$stale[] = array(
				'post_id'    => (int) $post_id,
				'title'      => get_the_title( (int) $post_id ),
				'url'        => $gsc['url'] ?? get_permalink( (int) $post_id ),
				'risk'       => $risk,
				'age_days'   => self::crawl_age_days( $gsc ),
				'last_crawl' => $gsc['last_crawl'],
				'scanned_at' => $result['scanned_at'] ?? 0,
			);
		}
		usort( $stale, static function ( $a, $b ) {
			return $b['age_days'] <=> $a['age_days'];
		} );
		return $stale;
	}

	// ── URL Inspection API ───────────────────────────────────────────────────

	/**
	 * Inspect a single URL via the GSC URL Inspection API.
	 * Uses the existing OAuth access token — no extra scope needed (webmasters.readonly is sufficient).
	 *
	 * @param string $url       Full URL to inspect.
	 * @param string $site_url  The GSC property (e.g. https://example.com/).
	 * @return array|WP_Error
	 */
	public static function inspect_url( $url, $site_url ) {
		$token = TWTAEO_Google_OAuth::get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'not_connected', 'Not connected to Google Search Console.' );
		}

		$response = wp_remote_post(
			'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode( array(
					'inspectionUrl' => $url,
					'siteUrl'       => $site_url,
				) ),
			)
		);

		if ( is_wp_error( $response ) ) return $response;

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = $data['error']['message'] ?? 'URL Inspection API error (HTTP ' . $code . ')';
			return new WP_Error( 'inspect_error', $msg );
		}

		$idx = $data['inspectionResult']['indexStatusResult'] ?? array();

		$coverage = $idx['coverageState'] ?? '';
		$verdict  = $idx['verdict']       ?? 'VERDICT_UNSPECIFIED';

		return array(
			'url'            => $url,
			'verdict'        => $verdict,
			'coverage_state' => $coverage,
			'last_crawl'     => $idx['lastCrawlTime']  ?? '',
			'robots_txt'     => $idx['robotsTxtState'] ?? '',
			'indexing_state' => $idx['indexingState']  ?? '',
			'not_indexed'    => in_array( $coverage, self::NOT_INDEXED, true ) || $verdict === 'FAIL',
		);
	}

	// ── Scannable URL List ───────────────────────────────────────────────────

	/**
	 * Whether a page should be left out of index-status reporting entirely.
	 *
	 * WooCommerce's and Easy Digital Downloads' utility pages (cart, checkout,
	 * receipt/success, failure, my account, purchase history) are transactional
	 * and noindex by design — "not indexed" is correct behaviour there, not a
	 * problem to surface or spend GSC inspection quota on. A child of one of
	 * those pages (e.g. a receipt page nested under checkout) is part of the
	 * same flow and equally excluded.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function is_excluded_page( $post_id ) {
		$post_id  = (int) $post_id;
		$excluded = array();

		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $wc_page ) {
				$excluded[] = (int) wc_get_page_id( $wc_page );
			}
		}

		if ( function_exists( 'edd_get_option' ) ) {
			foreach ( array( 'purchase_page', 'success_page', 'failure_page', 'purchase_history_page' ) as $edd_page ) {
				$excluded[] = (int) edd_get_option( $edd_page, 0 );
			}
		}

		$excluded = array_filter( $excluded );
		if ( empty( $excluded ) ) {
			return false;
		}
		if ( in_array( $post_id, $excluded, true ) ) {
			return true;
		}
		foreach ( get_post_ancestors( $post_id ) as $ancestor ) {
			if ( in_array( (int) $ancestor, $excluded, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns published posts and pages to scan.
	 *
	 * @param int $limit
	 * @return array[]
	 */
	public static function get_scannable_urls( $limit = 50 ) {
		$posts = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		return self::build_entries( $posts );
	}

	/**
	 * Scan entries for specific post IDs (published posts/pages only).
	 *
	 * @param int[] $post_ids
	 * @return array[]
	 */
	public static function get_urls_for_posts( array $post_ids ) {
		$post_ids = array_filter( array_map( 'absint', $post_ids ) );
		if ( empty( $post_ids ) ) {
			return array();
		}
		$posts = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'post__in'       => $post_ids,
			'posts_per_page' => count( $post_ids ),
			'orderby'        => 'post__in',
		) );
		return self::build_entries( $posts );
	}

	/**
	 * The next URLs for the rolling daily scan: never-checked pages first,
	 * then whichever were checked longest ago. Covering the WHOLE site this
	 * way is what catches old, unedited pages Google has stopped crawling —
	 * a newest-first scan would never reach them.
	 *
	 * @param int $limit
	 * @return array[]
	 */
	public static function get_rolling_scan_urls( $limit ) {
		$ids = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );

		$results = self::get_results();
		$order   = array();
		foreach ( $ids as $pos => $id ) {
			// Position breaks ties, so never-scanned pages go newest-first.
			$order[ $id ] = array( (int) ( $results[ $id ]['scanned_at'] ?? 0 ), $pos );
		}
		uasort( $order, static function ( $a, $b ) {
			return $a <=> $b;
		} );

		$picked = array();
		foreach ( array_keys( $order ) as $id ) {
			if ( self::is_excluded_page( $id ) ) {
				continue;
			}
			$picked[] = $id;
			if ( count( $picked ) >= $limit ) {
				break;
			}
		}
		return self::get_urls_for_posts( $picked );
	}

	/**
	 * @param WP_Post[] $posts
	 * @return array[]
	 */
	private static function build_entries( array $posts ) {
		$urls = array();
		foreach ( $posts as $post ) {
			if ( self::is_excluded_page( $post->ID ) ) {
				continue;
			}
			$urls[] = array(
				'post_id'  => $post->ID,
				'url'      => get_permalink( $post ),
				'title'    => get_the_title( $post ),
				'modified' => $post->post_modified,
				'type'     => $post->post_type,
				'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
			);
		}
		return $urls;
	}

	// ── Result Storage ───────────────────────────────────────────────────────

	public static function save_result( $post_id, array $gsc, array $heuristics ) {
		self::save_results( array(
			$post_id => array( 'gsc' => $gsc, 'heuristics' => $heuristics ),
		) );
	}

	/**
	 * Store several results in one option write (the cron scans in batches;
	 * one write per batch instead of per URL keeps a large option cheap).
	 *
	 * @param array $rows post_id => array( 'gsc' => array, 'heuristics' => array ).
	 */
	public static function save_results( array $rows ) {
		if ( empty( $rows ) ) {
			return;
		}
		$scan = get_option( self::OPTION_SCAN, array() );
		$scan = is_array( $scan ) ? $scan : array();
		foreach ( $rows as $post_id => $row ) {
			$scan[ $post_id ] = array(
				'gsc'        => $row['gsc'],
				'heuristics' => $row['heuristics'],
				'scanned_at' => time(),
			);
		}
		update_option( self::OPTION_SCAN, $scan, false );
	}

	/**
	 * Drop stored rows for posts that are gone or no longer published, so the
	 * option doesn't grow forever as the rolling scan covers the whole site.
	 */
	public static function prune_results() {
		$scan = self::get_results();
		if ( empty( $scan ) || ! is_array( $scan ) ) {
			return;
		}
		$keep = array();
		foreach ( $scan as $post_id => $row ) {
			if ( 'publish' === get_post_status( (int) $post_id ) ) {
				$keep[ $post_id ] = $row;
			}
		}
		if ( count( $keep ) !== count( $scan ) ) {
			update_option( self::OPTION_SCAN, $keep, false );
		}
	}

	public static function get_results() {
		return get_option( self::OPTION_SCAN, array() );
	}

	public static function clear_results() {
		delete_option( self::OPTION_SCAN );
	}

	// ── Pro Dashboard Payload ────────────────────────────────────────────────

	/**
	 * Return only the de-indexed entries, formatted for pro dashboard transmission.
	 *
	 * @return array[]
	 */
	public static function get_deindexed_for_transmission() {
		$scan    = self::get_results();
		$payload = array();

		foreach ( $scan as $post_id => $result ) {
			if ( self::is_excluded_page( $post_id ) ) {
				continue;
			}
			if ( ! empty( $result['gsc']['not_indexed'] ) ) {
				$payload[] = array(
					'post_id'        => (int) $post_id,
					'url'            => $result['gsc']['url']            ?? '',
					'title'          => get_the_title( (int) $post_id ),
					'coverage_state' => $result['gsc']['coverage_state'] ?? '',
					'last_crawl'     => $result['gsc']['last_crawl']     ?? '',
					'heuristics'     => $result['heuristics']            ?? array(),
					'excerpt'        => $result['heuristics']['excerpt'] ?? '',
					'scanned_at'     => $result['scanned_at']            ?? 0,
				);
			}
		}

		return $payload;
	}
}
