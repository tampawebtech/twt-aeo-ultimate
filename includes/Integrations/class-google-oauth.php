<?php
/**
 * Google OAuth Integration
 *
 * Handles OAuth 2.0 flow, token management, and API calls for both
 * Google Analytics (GA4 Data API) and Google Search Console.
 *
 * @package TWTAEO_Connector
 */
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Google_OAuth {

	const OPTION_CREDS   = 'twtaeo_google_creds';
	const OPTION_TOKENS  = 'twtaeo_google_tokens';
	const OPTION_CONFIG  = 'twtaeo_google_config';
	const OPTION_API_KEY = 'twtaeo_google_api_key';

	const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	const GA4_API_BASE    = 'https://analyticsdata.googleapis.com/v1beta';
	const GA4_ADMIN_BASE  = 'https://analyticsadmin.googleapis.com/v1beta';
	const GSC_API_BASE    = 'https://searchconsole.googleapis.com/webmasters/v3';
	// URL Inspection lives on the v1 host, not the legacy webmasters/v3 host.
	const GSC_INSPECT_URL = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

	// Scopes: GA4 read-only + Search Console read-only.
	const SCOPES = 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly';

	// ── Credentials & Config ─────────────────────────────────────────────────

	public static function get_credentials() {
		$creds = get_option( self::OPTION_CREDS, array() );
		if ( isset( $creds['client_secret'] ) ) {
			$creds['client_secret'] = TWTAEO_Crypt::decrypt( (string) $creds['client_secret'] );
		}
		return $creds;
	}

	public static function save_credentials( $client_id, $client_secret ) {
		update_option( self::OPTION_CREDS, array(
			'client_id'     => sanitize_text_field( $client_id ),
			'client_secret' => TWTAEO_Crypt::encrypt( sanitize_text_field( $client_secret ) ),
		) );
		delete_transient( 'twtaeo_ga4_totals' );
		delete_transient( 'twtaeo_ga4_top_pages' );
		delete_transient( 'twtaeo_gsc_totals' );
		delete_transient( 'twtaeo_gsc_top_queries' );
	}

	public static function get_config() {
		return get_option( self::OPTION_CONFIG, array() );
	}

	public static function save_config( $ga4_property_id, $gsc_site_url ) {
		update_option( self::OPTION_CONFIG, array(
			'ga4_property_id' => sanitize_text_field( $ga4_property_id ),
			'gsc_site_url'    => esc_url_raw( $gsc_site_url ),
		) );
		delete_transient( 'twtaeo_ga4_totals' );
		delete_transient( 'twtaeo_ga4_top_pages' );
		delete_transient( 'twtaeo_gsc_totals' );
		delete_transient( 'twtaeo_gsc_top_queries' );
	}

	// ── Simple API Key (Knowledge Graph + PageSpeed Insights) ────────────────
	//
	// These two APIs read public Google data and authenticate with a plain API
	// key, not OAuth/service-account credentials — so the key lives on its own,
	// independent of the connection state above. One key powers both features.

	public static function get_api_key() {
		return TWTAEO_Crypt::decrypt( (string) get_option( self::OPTION_API_KEY, '' ) );
	}

	public static function has_api_key() {
		return self::get_api_key() !== '';
	}

	/**
	 * Save (or clear, when empty) the shared Google API key.
	 *
	 * @param string $key Raw key from the settings form.
	 */
	public static function save_api_key( $key ) {
		$key = sanitize_text_field( $key );
		if ( '' === $key ) {
			delete_option( self::OPTION_API_KEY );
		} else {
			update_option( self::OPTION_API_KEY, TWTAEO_Crypt::encrypt( $key ) );
		}
		// Bust any Knowledge Graph / PageSpeed caches keyed off the old key.
		delete_transient( 'twtaeo_kg_last_check' );
		delete_transient( 'twtaeo_psi_last_check' );
	}

	// ── Connection State ─────────────────────────────────────────────────────

	public static function is_connected() {
		// A configured service account is a connection — no OAuth dance needed.
		if ( class_exists( 'TWTAEO_Google_Service_Account' ) && TWTAEO_Google_Service_Account::is_configured() ) {
			return true;
		}
		$tokens = self::get_tokens();
		return ! empty( $tokens['refresh_token'] );
	}

	public static function disconnect() {
		delete_option( self::OPTION_TOKENS );
		delete_option( 'twtaeo_google_oauth_state' );
		// Clear cached data.
		delete_transient( 'twtaeo_ga4_totals' );
		delete_transient( 'twtaeo_ga4_top_pages' );
		delete_transient( 'twtaeo_gsc_totals' );
		delete_transient( 'twtaeo_gsc_top_queries' );
	}

	// ── OAuth Flow ───────────────────────────────────────────────────────────

	/**
	 * The exact URI registered in Google Cloud Console.
	 * Users must add this URL to their OAuth app's authorized redirect URIs.
	 *
	 * @return string
	 */
	public static function get_redirect_uri() {
		return admin_url( 'admin.php?page=twt-aeo-command-center' );
	}

	/**
	 * Build the Google OAuth authorization URL.
	 *
	 * @return string Empty string if credentials are not configured.
	 */
	public static function get_oauth_url() {
		$creds = self::get_credentials();
		if ( empty( $creds['client_id'] ) ) {
			return '';
		}

		$state = wp_create_nonce( 'twtaeo_google_oauth' );
		update_option( 'twtaeo_google_oauth_state', $state );

		return self::AUTH_URL . '?' . http_build_query( array(
			'client_id'     => $creds['client_id'],
			'redirect_uri'  => self::get_redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::SCOPES,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		) );
	}

	/**
	 * Exchange an authorization code for access + refresh tokens.
	 *
	 * @param string $code  Authorization code from Google callback.
	 * @param string $state State parameter to verify against stored value.
	 * @return true|WP_Error
	 */
	public static function handle_callback( $code, $state ) {
		$stored = get_option( 'twtaeo_google_oauth_state', '' );
		if ( empty( $stored ) || ! hash_equals( $stored, $state ) ) {
			return new WP_Error( 'invalid_state', 'Invalid OAuth state. Please try connecting again.' );
		}
		delete_option( 'twtaeo_google_oauth_state' );

		$creds = self::get_credentials();
		if ( empty( $creds['client_id'] ) || empty( $creds['client_secret'] ) ) {
			return new WP_Error( 'no_credentials', 'Google API credentials are not configured.' );
		}

		$response = wp_remote_post( self::TOKEN_URL, array(
			'body' => array(
				'code'          => $code,
				'client_id'     => $creds['client_id'],
				'client_secret' => $creds['client_secret'],
				'redirect_uri'  => self::get_redirect_uri(),
				'grant_type'    => 'authorization_code',
			),
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[TWT AEO] Google OAuth token exchange network error — ' . $response->get_error_code() . ': ' . $response->get_error_message() );
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			$error = new WP_Error( $body['error'], $body['error_description'] ?? 'Token exchange failed.' );
			error_log( '[TWT AEO] Google OAuth token exchange error — ' . $error->get_error_code() . ': ' . $error->get_error_message() );
			return $error;
		}

		self::save_tokens( array(
			'access_token'  => $body['access_token'] ?? '',
			'refresh_token' => $body['refresh_token'] ?? '',
			'expires_at'    => time() + (int) ( $body['expires_in'] ?? 3600 ),
		) );

		// Bust cached data — new account may differ from the previous connection.
		delete_transient( 'twtaeo_ga4_totals' );
		delete_transient( 'twtaeo_ga4_top_pages' );
		delete_transient( 'twtaeo_gsc_totals' );
		delete_transient( 'twtaeo_gsc_top_queries' );

		return true;
	}

	// ── Token Management ─────────────────────────────────────────────────────

	private static function get_tokens() {
		$tokens = get_option( self::OPTION_TOKENS, array() );
		foreach ( array( 'access_token', 'refresh_token' ) as $f ) {
			if ( isset( $tokens[ $f ] ) ) {
				$tokens[ $f ] = TWTAEO_Crypt::decrypt( (string) $tokens[ $f ] );
			}
		}
		return $tokens;
	}

	/**
	 * Persist tokens with the access/refresh tokens encrypted at rest.
	 *
	 * @param array $tokens
	 */
	private static function save_tokens( array $tokens ) {
		foreach ( array( 'access_token', 'refresh_token' ) as $f ) {
			if ( ! empty( $tokens[ $f ] ) ) {
				$tokens[ $f ] = TWTAEO_Crypt::encrypt( (string) $tokens[ $f ] );
			}
		}
		update_option( self::OPTION_TOKENS, $tokens );
	}

	/**
	 * Return a valid access token, refreshing if expired.
	 *
	 * @return string Empty on failure.
	 */
	public static function get_access_token() {
		// Service account first: silent, never expires, no per-site OAuth app.
		// Falls through to the OAuth tokens if the key fails for any reason.
		if ( class_exists( 'TWTAEO_Google_Service_Account' ) && TWTAEO_Google_Service_Account::is_configured() ) {
			$sa_token = TWTAEO_Google_Service_Account::get_access_token();
			if ( $sa_token ) {
				return $sa_token;
			}
		}

		$tokens = self::get_tokens();

		if ( empty( $tokens['access_token'] ) ) {
			return '';
		}

		if ( ! empty( $tokens['expires_at'] ) && time() > ( $tokens['expires_at'] - 60 ) ) {
			$refresh = self::refresh_access_token();
			if ( is_wp_error( $refresh ) ) {
				error_log( '[TWT AEO] Google OAuth token refresh failed — ' . $refresh->get_error_code() . ': ' . $refresh->get_error_message() );
				return '';
			}
			$tokens = self::get_tokens();
		}

		return $tokens['access_token'] ?? '';
	}

	private static function refresh_access_token() {
		$tokens = self::get_tokens();
		$creds  = self::get_credentials();

		if ( empty( $tokens['refresh_token'] ) || empty( $creds['client_id'] ) ) {
			return new WP_Error( 'no_refresh_token', 'No refresh token available.' );
		}

		$response = wp_remote_post( self::TOKEN_URL, array(
			'body' => array(
				'refresh_token' => $tokens['refresh_token'],
				'client_id'     => $creds['client_id'],
				'client_secret' => $creds['client_secret'],
				'grant_type'    => 'refresh_token',
			),
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[TWT AEO] Google OAuth token refresh network error — ' . $response->get_error_code() . ': ' . $response->get_error_message() );
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			self::disconnect();
			$error = new WP_Error( $body['error'], $body['error_description'] ?? 'Token refresh failed. Please reconnect.' );
			error_log( '[TWT AEO] Google OAuth token refresh error (disconnected) — ' . $error->get_error_code() . ': ' . $error->get_error_message() );
			return $error;
		}

		$tokens['access_token'] = $body['access_token'] ?? '';
		$tokens['expires_at']   = time() + (int) ( $body['expires_in'] ?? 3600 );
		self::save_tokens( $tokens );

		return true;
	}

	// ── GA4 Data API ─────────────────────────────────────────────────────────

	/**
	 * Run a GA4 Data API report.
	 *
	 * @param string $property_id Numeric property ID (or "properties/123").
	 * @param array  $body        Full request body.
	 * @return array|WP_Error
	 */
	public static function ga4_run_report( $property_id, $body ) {
		$token = self::get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'not_connected', 'Not connected to Google Analytics.' );
		}

		// Accept both "123456789" and "properties/123456789".
		$property_id = preg_replace( '/^properties\//', '', trim( $property_id ) );

		$response = wp_remote_post(
			self::GA4_API_BASE . '/properties/' . $property_id . ':runReport',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[TWT AEO] GA4 API network error — ' . $response->get_error_code() . ': ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$error = new WP_Error( 'ga4_error', $data['error']['message'] ?? 'GA4 API error (HTTP ' . $code . ').' );
			error_log( '[TWT AEO] GA4 API error (HTTP ' . $code . ') — ' . $error->get_error_message() );
			return $error;
		}

		return $data;
	}

	// ── Search Console API ───────────────────────────────────────────────────

	/**
	 * List all verified sites for the connected account.
	 *
	 * @return array|WP_Error
	 */
	public static function gsc_list_sites() {
		$token = self::get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'not_connected', 'Not connected to Google.' );
		}

		$response = wp_remote_get( self::GSC_API_BASE . '/sites', array(
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[TWT AEO] GSC list sites network error — ' . $response->get_error_code() . ': ' . $response->get_error_message() );
			return $response;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * Query Search Console search analytics.
	 *
	 * @param string $site_url   Verified site URL.
	 * @param array  $body       Request body.
	 * @return array|WP_Error
	 */
	public static function gsc_search_analytics( $site_url, $body ) {
		$token = self::get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'not_connected', 'Not connected to Google Search Console.' );
		}

		$response = wp_remote_post(
			self::GSC_API_BASE . '/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[TWT AEO] GSC search analytics network error — ' . $response->get_error_code() . ': ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$error = new WP_Error( 'gsc_error', $data['error']['message'] ?? 'Search Console API error (HTTP ' . $code . ').' );
			error_log( '[TWT AEO] GSC search analytics error (HTTP ' . $code . ') — ' . $error->get_error_message() );
			return $error;
		}

		return $data;
	}

	/**
	 * Inspect a single URL via the Search Console URL Inspection API.
	 *
	 * Returns real-time index status, the Google-selected vs user-declared
	 * canonical, robots.txt verdict, and rich-result / mobile-usability status.
	 * Requires the connected identity to have Owner or Full permission on the
	 * property — Restricted access (enough for search analytics) is rejected by
	 * Google with a 403 here.
	 *
	 * @param string $site_url  Verified property URL (must match GSC exactly).
	 * @param string $page_url  Absolute URL to inspect (within the property).
	 * @return array|WP_Error   The inspectionResult payload, or an error.
	 */
	public static function gsc_inspect_url( $site_url, $page_url ) {
		$token = self::get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'not_connected', 'Not connected to Google Search Console.' );
		}

		$response = wp_remote_post( self::GSC_INSPECT_URL, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'inspectionUrl' => $page_url,
				'siteUrl'       => $site_url,
				'languageCode'  => 'en-US',
			) ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			TWTAEO_Logger::error( 'GSC URL Inspection network error', array(
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
			) );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$message = $data['error']['message'] ?? 'URL Inspection API error (HTTP ' . $code . ').';
			// A 403 here almost always means the identity lacks Full/Owner access.
			if ( 403 === $code ) {
				$message .= ' — the connected account needs Owner or Full permission on this property (not Restricted).';
			}
			$error = new WP_Error( 'gsc_inspect_error', $message );
			TWTAEO_Logger::error( 'GSC URL Inspection error (HTTP ' . $code . ')', array( 'message' => $message ) );
			return $error;
		}

		return $data['inspectionResult'] ?? array();
	}
}
