<?php
/**
 * Bing Webmaster Tools Integration
 *
 * Handles API key authentication and data fetching from Bing Webmaster Tools.
 *
 * @package TWTAEO_Connector
 */
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Bing_Webmaster {

	const OPTION_CREDS = 'twtaeo_bing_creds';
	const API_BASE     = 'https://ssl.bing.com/webmaster/api.svc/json';

	// ── Credentials ──────────────────────────────────────────────────────────

	public static function get_credentials() {
		$creds = get_option( self::OPTION_CREDS, array() );
		if ( isset( $creds['api_key'] ) ) {
			$creds['api_key'] = TWTAEO_Crypt::decrypt( (string) $creds['api_key'] );
		}
		return $creds;
	}

	public static function save_credentials( $api_key, $site_url ) {
		update_option( self::OPTION_CREDS, array(
			'api_key'  => TWTAEO_Crypt::encrypt( sanitize_text_field( $api_key ) ),
			'site_url' => esc_url_raw( $site_url ),
		) );
		delete_transient( 'twtaeo_bing_stats_v2' );
		delete_transient( 'twtaeo_bing_crawl' );
		delete_transient( 'twtaeo_bing_suggestions' );
	}

	public static function is_connected() {
		$creds = self::get_credentials();
		return ! empty( $creds['api_key'] ) && ! empty( $creds['site_url'] );
	}

	public static function disconnect() {
		delete_option( self::OPTION_CREDS );
		delete_transient( 'twtaeo_bing_stats_v2' );
		delete_transient( 'twtaeo_bing_crawl' );
		delete_transient( 'twtaeo_bing_suggestions' );
	}

	// ── API Requests ─────────────────────────────────────────────────────────

	/**
	 * Make an authenticated GET request to the Bing Webmaster API.
	 *
	 * @param string $method API method name.
	 * @param array  $params Additional query parameters.
	 * @return array|WP_Error
	 */
	private static function request( $method, $params = array() ) {
		$creds = self::get_credentials();

		if ( empty( $creds['api_key'] ) ) {
			return new WP_Error( 'no_api_key', 'Bing Webmaster API key is not configured.' );
		}

		$params['apikey'] = $creds['api_key'];
		$url = self::API_BASE . '/' . $method . '?' . http_build_query( $params );

		$response = wp_remote_get( $url, array(
			'timeout' => 20,
			'headers' => array( 'Accept' => 'application/json' ),
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[TWT AEO] Bing Webmaster API network error (' . $method . ') — ' . $response->get_error_code() . ': ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg   = $body['d']['ErrorMessage'] ?? ( $body['Message'] ?? 'Bing API error (HTTP ' . $code . ').' );
			$error = new WP_Error( 'bing_error', $msg );
			error_log( '[TWT AEO] Bing Webmaster API error (' . $method . ', HTTP ' . $code . ') — ' . $error->get_error_message() );
			return $error;
		}

		return $body;
	}

	// ── API Methods ──────────────────────────────────────────────────────────

	/**
	 * Verify connectivity by fetching site info.
	 * Returns true on success, WP_Error on failure.
	 *
	 * @return bool|WP_Error
	 */
	public static function verify_connection() {
		$creds = self::get_credentials();

		if ( empty( $creds['api_key'] ) || empty( $creds['site_url'] ) ) {
			return new WP_Error( 'not_configured', 'Bing credentials are not configured.' );
		}

		$result = self::request( 'GetUserSites' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Get query statistics for the configured site.
	 *
	 * Returns monthly keyword impression/click data.
	 *
	 * @return array|WP_Error
	 */
	public static function get_query_stats() {
		$creds = self::get_credentials();

		if ( empty( $creds['site_url'] ) ) {
			return new WP_Error( 'no_site', 'No site URL configured.' );
		}

		return self::request( 'GetQueryStats', array( 'siteUrl' => $creds['site_url'] ) );
	}

	/**
	 * Get crawl statistics for the configured site.
	 *
	 * @return array|WP_Error
	 */
	public static function get_crawl_stats() {
		$creds = self::get_credentials();

		if ( empty( $creds['site_url'] ) ) {
			return new WP_Error( 'no_site', 'No site URL configured.' );
		}

		return self::request( 'GetCrawlStats', array( 'siteUrl' => $creds['site_url'] ) );
	}

	/**
	 * Get all sites verified under this API key.
	 *
	 * @return array|WP_Error
	 */
	public static function get_user_sites() {
		return self::request( 'GetUserSites' );
	}

	/**
	 * Get keyword/page stats (top queries by impressions).
	 *
	 * @return array|WP_Error
	 */
	public static function get_keyword_stats() {
		$creds = self::get_credentials();

		if ( empty( $creds['site_url'] ) ) {
			return new WP_Error( 'no_site', 'No site URL configured.' );
		}

		return self::request( 'GetKeywordStats', array( 'siteUrl' => $creds['site_url'] ) );
	}

	/**
	 * Get SEO suggestions for the configured site.
	 *
	 * Returns per-page SEO issues with Bing severity levels:
	 *   1 = Critical, 2 = Warning, 3 = Notice.
	 *
	 * @return array|WP_Error
	 */
	public static function get_seo_suggestions() {
		$creds = self::get_credentials();

		if ( empty( $creds['site_url'] ) ) {
			return new WP_Error( 'no_site', 'No site URL configured.' );
		}

		return self::request( 'GetSEOSuggestions', array( 'siteUrl' => $creds['site_url'] ) );
	}
}
