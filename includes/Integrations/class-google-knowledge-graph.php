<?php
/**
 * Google Knowledge Graph Search API
 *
 * Looks up brand/person/place entities in Google's Knowledge Graph so the user
 * can verify which entity Google recognises and pull its authoritative URL
 * (usually Wikipedia/official site) and its stable entity ID (kg:/g/… MID) into
 * Organization or Person `sameAs`.
 *
 * Authenticates with the shared, plain Google API key (no OAuth) — see
 * TWTAEO_Google_OAuth::get_api_key(). Results are cached per query/key because
 * entities rarely change and the API is quota-limited.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Google_Knowledge_Graph {

	const API_URL      = 'https://kgsearch.googleapis.com/v1/entities:search';
	const CACHE_PREFIX = 'twtaeo_kg_';
	const CACHE_TTL    = DAY_IN_SECONDS;

	/**
	 * Whether a Google API key is available for lookups.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return TWTAEO_Google_OAuth::has_api_key();
	}

	/**
	 * Search the Knowledge Graph for entities matching a name.
	 *
	 * @param string $query Brand / person / place name.
	 * @param int    $limit Max results (1–10).
	 * @return array|WP_Error  List of normalized entity arrays, or an error.
	 */
	public static function search( $query, $limit = 5 ) {
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return new WP_Error( 'empty_query', __( 'Enter a name to look up.', 'twt-aeo-ultimate' ) );
		}

		$key = TWTAEO_Google_OAuth::get_api_key();
		if ( '' === $key ) {
			return new WP_Error( 'no_api_key', __( 'Add a Google API key in Command Center → Settings first.', 'twt-aeo-ultimate' ) );
		}

		$limit     = max( 1, min( 10, (int) $limit ) );
		$cache_key = self::CACHE_PREFIX . md5( $key . '|' . strtolower( $query ) . '|' . $limit );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// add_query_arg() URL-encodes values, so pass them raw.
		$url = add_query_arg( array(
			'query'     => $query,
			'limit'     => $limit,
			'languages' => 'en',
			'key'       => $key,
		), self::API_URL );

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			TWTAEO_Logger::error( 'Knowledge Graph network error', array(
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
				__( 'Knowledge Graph API error (HTTP %d).', 'twt-aeo-ultimate' ),
				$code
			);
			TWTAEO_Logger::error( 'Knowledge Graph error (HTTP ' . $code . ')', array( 'message' => $message ) );
			return new WP_Error( 'kg_error', $message );
		}

		$entities = self::normalize( $data );
		set_transient( $cache_key, $entities, self::CACHE_TTL );

		return $entities;
	}

	/**
	 * Flatten the API's itemListElement into a simple, escaped-friendly shape.
	 *
	 * @param array $data Decoded API response.
	 * @return array
	 */
	private static function normalize( $data ) {
		$out = array();

		foreach ( $data['itemListElement'] ?? array() as $element ) {
			$result = $element['result'] ?? array();
			if ( empty( $result['name'] ) ) {
				continue;
			}

			// "Thing" is on every entity and carries no signal — drop it.
			$types = array_values( array_diff( (array) ( $result['@type'] ?? array() ), array( 'Thing' ) ) );

			$out[] = array(
				'id'          => $result['@id'] ?? '',                                       // e.g. "kg:/g/11abc…"
				'name'        => $result['name'] ?? '',
				'types'       => $types,
				'description' => $result['description'] ?? '',                                // short label, e.g. "Software company"
				'detailed'    => $result['detailedDescription']['articleBody'] ?? '',
				'url'         => $result['detailedDescription']['url'] ?? ( $result['url'] ?? '' ), // usually Wikipedia, else official site
				'image'       => $result['image']['contentUrl'] ?? '',
				'score'       => isset( $element['resultScore'] ) ? round( (float) $element['resultScore'], 1 ) : 0,
			);
		}

		return $out;
	}
}
