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
	);

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

		$urls = array();
		foreach ( $posts as $post ) {
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
		$scan              = get_option( self::OPTION_SCAN, array() );
		$scan[ $post_id ]  = array(
			'gsc'        => $gsc,
			'heuristics' => $heuristics,
			'scanned_at' => time(),
		);
		update_option( self::OPTION_SCAN, $scan, false );
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
