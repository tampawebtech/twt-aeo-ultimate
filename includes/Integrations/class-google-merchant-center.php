<?php
/**
 * Google Merchant Center Integration
 *
 * Handles a dedicated OAuth 2.0 flow for the Merchant Center Content API scope.
 * Credentials, tokens, and merchant config are stored separately from the
 * GA4/GSC OAuth session so they can be connected independently.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Google_Merchant_Center {

	const OPTION_CREDS   = 'twtaeo_gmc_creds';
	const OPTION_TOKENS  = 'twtaeo_gmc_tokens';
	const OPTION_CONFIG  = 'twtaeo_gmc_config';
	const OPTION_STATE   = 'twtaeo_gmc_oauth_state';

	const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const API_BASE  = 'https://shoppingcontent.googleapis.com/content/v2.1';

	const SCOPE = 'https://www.googleapis.com/auth/content';

	// ── Credentials & Config ─────────────────────────────────────────────────

	public static function get_credentials() {
		return get_option( self::OPTION_CREDS, array() );
	}

	public static function save_credentials( $client_id, $client_secret ) {
		update_option( self::OPTION_CREDS, array(
			'client_id'     => sanitize_text_field( $client_id ),
			'client_secret' => sanitize_text_field( $client_secret ),
		) );
	}

	public static function get_config() {
		return get_option( self::OPTION_CONFIG, array() );
	}

	public static function save_config( $merchant_id ) {
		update_option( self::OPTION_CONFIG, array(
			'merchant_id' => sanitize_text_field( $merchant_id ),
		) );
	}

	public static function get_merchant_id() {
		$config = self::get_config();
		return $config['merchant_id'] ?? '';
	}

	// ── Connection State ─────────────────────────────────────────────────────

	public static function is_connected() {
		$tokens = self::get_tokens();
		return ! empty( $tokens['refresh_token'] ) && ! empty( self::get_merchant_id() );
	}

	public static function disconnect() {
		delete_option( self::OPTION_TOKENS );
		delete_option( self::OPTION_STATE );
		delete_transient( 'twtaeo_gmc_products' );
		delete_transient( 'twtaeo_gmc_statuses' );
	}

	// ── OAuth Flow ───────────────────────────────────────────────────────────

	/**
	 * Redirect URI — must be registered in Google Cloud Console.
	 */
	public static function get_redirect_uri() {
		return admin_url( 'admin.php?page=twt-aeo-woocommerce&tab=gmc' );
	}

	/**
	 * Build the Google OAuth URL for the Merchant Center content scope.
	 */
	public static function get_oauth_url() {
		$creds = self::get_credentials();
		if ( empty( $creds['client_id'] ) ) {
			return '';
		}

		$state = wp_create_nonce( 'twtaeo_gmc_oauth' );
		update_option( self::OPTION_STATE, $state );

		return self::AUTH_URL . '?' . http_build_query( array(
			'client_id'     => $creds['client_id'],
			'redirect_uri'  => self::get_redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		) );
	}

	/**
	 * Exchange authorization code for tokens.
	 *
	 * @param string $code
	 * @param string $state
	 * @return true|WP_Error
	 */
	public static function handle_callback( $code, $state ) {
		$stored = get_option( self::OPTION_STATE, '' );
		if ( empty( $stored ) || ! hash_equals( $stored, $state ) ) {
			return new WP_Error( 'invalid_state', 'Invalid OAuth state. Please try connecting again.' );
		}
		delete_option( self::OPTION_STATE );

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
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			return new WP_Error( $body['error'], $body['error_description'] ?? 'Token exchange failed.' );
		}

		update_option( self::OPTION_TOKENS, array(
			'access_token'  => $body['access_token'] ?? '',
			'refresh_token' => $body['refresh_token'] ?? '',
			'expires_at'    => time() + (int) ( $body['expires_in'] ?? 3600 ),
		) );

		return true;
	}

	// ── Token Management ─────────────────────────────────────────────────────

	private static function get_tokens() {
		return get_option( self::OPTION_TOKENS, array() );
	}

	public static function get_access_token() {
		$tokens = self::get_tokens();

		if ( empty( $tokens['access_token'] ) ) {
			return '';
		}

		if ( ! empty( $tokens['expires_at'] ) && time() > ( $tokens['expires_at'] - 60 ) ) {
			if ( is_wp_error( self::refresh_access_token() ) ) {
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
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			self::disconnect();
			return new WP_Error( $body['error'], $body['error_description'] ?? 'Token refresh failed.' );
		}

		$tokens['access_token'] = $body['access_token'] ?? '';
		$tokens['expires_at']   = time() + (int) ( $body['expires_in'] ?? 3600 );
		update_option( self::OPTION_TOKENS, $tokens );

		return true;
	}

	// ── Content API — Products ───────────────────────────────────────────────

	/**
	 * Fetch all products from Merchant Center (handles pagination).
	 *
	 * @param string $merchant_id
	 * @return array|WP_Error  Flat array of product resource objects.
	 */
	public static function list_all_products( $merchant_id ) {
		$token = self::get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'not_connected', 'Not connected to Google Merchant Center.' );
		}

		$products   = array();
		$page_token = '';

		do {
			$url    = self::API_BASE . '/' . rawurlencode( $merchant_id ) . '/products';
			$params = array( 'maxResults' => 250 );
			if ( $page_token ) {
				$params['pageToken'] = $page_token;
			}

			$response = wp_remote_get( add_query_arg( $params, $url ), array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'timeout' => 30,
			) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code !== 200 ) {
				return new WP_Error( 'gmc_error', $data['error']['message'] ?? 'Merchant Center API error (HTTP ' . $code . ').' );
			}

			$products   = array_merge( $products, $data['resources'] ?? array() );
			$page_token = $data['nextPageToken'] ?? '';

		} while ( $page_token );

		return $products;
	}

	// ── Content API — Product Statuses ───────────────────────────────────────

	/**
	 * Fetch all product statuses (rejection codes, approval status).
	 *
	 * @param string $merchant_id
	 * @return array|WP_Error  Keyed by GMC product ID.
	 */
	public static function list_all_product_statuses( $merchant_id ) {
		$token = self::get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'not_connected', 'Not connected to Google Merchant Center.' );
		}

		$statuses   = array();
		$page_token = '';

		do {
			$url    = self::API_BASE . '/' . rawurlencode( $merchant_id ) . '/productstatuses';
			$params = array( 'maxResults' => 250 );
			if ( $page_token ) {
				$params['pageToken'] = $page_token;
			}

			$response = wp_remote_get( add_query_arg( $params, $url ), array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'timeout' => 30,
			) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code !== 200 ) {
				return new WP_Error( 'gmc_error', $data['error']['message'] ?? 'Merchant Center API error (HTTP ' . $code . ').' );
			}

			foreach ( $data['resources'] ?? array() as $status ) {
				$statuses[ $status['productId'] ] = $status;
			}

			$page_token = $data['nextPageToken'] ?? '';

		} while ( $page_token );

		return $statuses;
	}
}
