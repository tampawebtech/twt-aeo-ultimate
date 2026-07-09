<?php
/**
 * Google PageSpeed Insights API (v5)
 *
 * Runs a Lighthouse performance analysis on a URL and returns a flattened,
 * dashboard-friendly result: the overall performance score, the key lab metrics
 * (LCP, CLS, TBT, FCP, Speed Index), and — when Google has enough real-world
 * data — the CrUX field verdict.
 *
 * Auth: uses the shared Google API key (TWTAEO_Google_OAuth::get_api_key()) when
 * present for the higher 25k/day quota. PSI also answers without a key at a
 * lower rate, so the key is optional here. Results are cached per URL+strategy.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Google_PageSpeed {

	const API_URL      = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
	const CACHE_PREFIX = 'twtaeo_psi_';
	const CACHE_TTL    = 6 * HOUR_IN_SECONDS;

	/**
	 * Analyze a URL's performance.
	 *
	 * @param string $url      Absolute URL to test.
	 * @param string $strategy 'mobile' (default) or 'desktop'.
	 * @return array|WP_Error  Flattened result, or an error.
	 */
	public static function analyze( $url, $strategy = 'mobile' ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' === $url ) {
			return new WP_Error( 'empty_url', __( 'Enter a URL to analyze.', 'twt-aeo-ultimate' ) );
		}

		$strategy = in_array( $strategy, array( 'mobile', 'desktop' ), true ) ? $strategy : 'mobile';

		$cache_key = self::CACHE_PREFIX . md5( $strategy . '|' . $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$cached['cached'] = true;
			return $cached;
		}

		$args = array(
			'url'      => $url,
			'strategy' => $strategy,
			'category' => 'performance',
		);
		$key = TWTAEO_Google_OAuth::get_api_key();
		if ( '' !== $key ) {
			$args['key'] = $key;
		}

		// Lighthouse runs server-side at Google and can take 10–30s.
		$response = wp_remote_get( add_query_arg( $args, self::API_URL ), array( 'timeout' => 60 ) );

		if ( is_wp_error( $response ) ) {
			TWTAEO_Logger::error( 'PageSpeed network error', array(
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
			) );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = $data['error']['message'] ?? sprintf(
				/* translators: %d: HTTP status code */
				__( 'PageSpeed API error (HTTP %d).', 'twt-aeo-ultimate' ),
				$code
			);
			TWTAEO_Logger::error( 'PageSpeed error (HTTP ' . $code . ')', array( 'message' => $message ) );
			return new WP_Error( 'psi_error', $message );
		}

		$result = self::normalize( $data, $url, $strategy );
		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Flatten the (very large) PSI/Lighthouse payload to the fields we surface.
	 *
	 * @param array  $data
	 * @param string $url
	 * @param string $strategy
	 * @return array
	 */
	private static function normalize( $data, $url, $strategy ) {
		$lh     = $data['lighthouseResult'] ?? array();
		$audits = $lh['audits'] ?? array();

		$score = isset( $lh['categories']['performance']['score'] ) && null !== $lh['categories']['performance']['score']
			? (int) round( (float) $lh['categories']['performance']['score'] * 100 )
			: null;

		$lab = array();
		$metric_audits = array(
			'lcp' => 'largest-contentful-paint',
			'fcp' => 'first-contentful-paint',
			'cls' => 'cumulative-layout-shift',
			'tbt' => 'total-blocking-time',
			'si'  => 'speed-index',
		);
		foreach ( $metric_audits as $short => $audit_id ) {
			if ( isset( $audits[ $audit_id ] ) ) {
				$lab[ $short ] = array(
					'display' => (string) ( $audits[ $audit_id ]['displayValue'] ?? '—' ),
					'score'   => isset( $audits[ $audit_id ]['score'] ) ? (float) $audits[ $audit_id ]['score'] : null,
				);
			}
		}

		// CrUX real-world field data, when Google has enough samples for this URL.
		$field    = array();
		$loading  = $data['loadingExperience'] ?? array();
		$field['verdict'] = $loading['overall_category'] ?? '';
		foreach ( (array) ( $loading['metrics'] ?? array() ) as $metric => $info ) {
			$field['metrics'][ $metric ] = array(
				'percentile' => $info['percentile'] ?? null,
				'category'   => $info['category'] ?? '',
			);
		}

		return array(
			'cached'       => false,
			'url'          => $url,
			'strategy'     => $strategy,
			'score'        => $score,
			'lab'          => $lab,
			'field'        => $field,
			'analyzed_at'  => gmdate( 'c' ),
		);
	}
}
