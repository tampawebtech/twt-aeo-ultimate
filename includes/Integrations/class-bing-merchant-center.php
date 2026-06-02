<?php
/**
 * Bing Merchant Center Integration
 *
 * Handles OAuth 2.0 via the Microsoft identity platform and calls the
 * Microsoft Advertising Shopping Content API (Bing Merchant Center).
 *
 * Credentials/tokens are stored independently from the Bing Webmaster
 * API key so each can be connected or disconnected separately.
 *
 * Required Azure AD app:
 *   - Scope:        https://ads.microsoft.com/msads.manage offline_access
 *   - Redirect URI: admin.php?page=twt-aeo-woocommerce&tab=bmc
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Bing_Merchant_Center {

	const OPTION_CREDS  = 'twtaeo_bmc_creds';
	const OPTION_TOKENS = 'twtaeo_bmc_tokens';
	const OPTION_CONFIG = 'twtaeo_bmc_config';
	const OPTION_STATE  = 'twtaeo_bmc_oauth_state';

	const AUTH_URL  = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
	const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
	const API_BASE  = 'https://content.api.bingads.microsoft.com/shopping/v9.1/bmc';

	const SCOPE = 'https://ads.microsoft.com/msads.manage offline_access';

	// ── Credentials & Config ─────────────────────────────────────────────────

	public static function get_credentials() {
		return get_option( self::OPTION_CREDS, array() );
	}

	/**
	 * @param string $client_id        Azure AD application (client) ID.
	 * @param string $client_secret    Azure AD client secret.
	 * @param string $developer_token  Microsoft Advertising developer token.
	 */
	public static function save_credentials( $client_id, $client_secret, $developer_token ) {
		update_option( self::OPTION_CREDS, array(
			'client_id'       => sanitize_text_field( $client_id ),
			'client_secret'   => sanitize_text_field( $client_secret ),
			'developer_token' => sanitize_text_field( $developer_token ),
		) );
	}

	public static function get_config() {
		return get_option( self::OPTION_CONFIG, array() );
	}

	/** @param string $store_id  Bing Merchant Center store ID. */
	public static function save_config( $store_id ) {
		update_option( self::OPTION_CONFIG, array(
			'store_id' => sanitize_text_field( $store_id ),
		) );
	}

	public static function get_store_id() {
		$config = self::get_config();
		return $config['store_id'] ?? '';
	}

	// ── Connection State ─────────────────────────────────────────────────────

	public static function is_connected() {
		$tokens = self::get_tokens();
		$creds  = self::get_credentials();
		return ! empty( $tokens['refresh_token'] )
		    && ! empty( self::get_store_id() )
		    && ! empty( $creds['developer_token'] );
	}

	public static function disconnect() {
		delete_option( self::OPTION_TOKENS );
		delete_option( self::OPTION_STATE );
		delete_transient( 'twtaeo_bmc_products' );
		delete_transient( 'twtaeo_bmc_statuses' );
	}

	// ── OAuth Flow ───────────────────────────────────────────────────────────

	public static function get_redirect_uri() {
		return admin_url( 'admin.php?page=twt-aeo-woocommerce&tab=bmc' );
	}

	public static function get_oauth_url() {
		$creds = self::get_credentials();
		if ( empty( $creds['client_id'] ) ) {
			return '';
		}

		$state = wp_create_nonce( 'twtaeo_bmc_oauth' );
		update_option( self::OPTION_STATE, $state );

		return self::AUTH_URL . '?' . http_build_query( array(
			'client_id'     => $creds['client_id'],
			'redirect_uri'  => self::get_redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::SCOPE,
			'response_mode' => 'query',
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
			return new WP_Error( 'no_credentials', 'Microsoft Azure credentials are not configured.' );
		}

		$response = wp_remote_post( self::TOKEN_URL, array(
			'body' => array(
				'client_id'     => $creds['client_id'],
				'client_secret' => $creds['client_secret'],
				'redirect_uri'  => self::get_redirect_uri(),
				'code'          => $code,
				'grant_type'    => 'authorization_code',
				'scope'         => self::SCOPE,
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
				'client_id'     => $creds['client_id'],
				'client_secret' => $creds['client_secret'],
				'refresh_token' => $tokens['refresh_token'],
				'grant_type'    => 'refresh_token',
				'scope'         => self::SCOPE,
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
	 * Fetch all products from Bing Merchant Center (handles pagination).
	 *
	 * @param string $store_id
	 * @return array|WP_Error  Flat array of product resource objects.
	 */
	public static function list_all_products( $store_id ) {
		$token = self::get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'not_connected', 'Not connected to Bing Merchant Center.' );
		}

		$creds    = self::get_credentials();
		$headers  = self::build_headers( $token, $creds['developer_token'] ?? '' );
		$products = array();
		$next_url = self::API_BASE . '/' . rawurlencode( $store_id ) . '/products?maxResults=250';

		do {
			$response = wp_remote_get( $next_url, array(
				'headers' => $headers,
				'timeout' => 30,
			) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code !== 200 ) {
				return new WP_Error( 'bmc_error', $data['errors'][0]['message'] ?? 'Bing Merchant Center API error (HTTP ' . $code . ').' );
			}

			$products = array_merge( $products, $data['resources'] ?? array() );
			$next_url = ! empty( $data['nextPageToken'] )
				? self::API_BASE . '/' . rawurlencode( $store_id ) . '/products?maxResults=250&pageToken=' . rawurlencode( $data['nextPageToken'] )
				: '';

		} while ( $next_url );

		return $products;
	}

	// ── Content API — Product Statuses ───────────────────────────────────────

	/**
	 * Fetch all product statuses (disapprovals, warnings).
	 *
	 * @param string $store_id
	 * @return array|WP_Error  Keyed by Bing product ID.
	 */
	public static function list_all_product_statuses( $store_id ) {
		$token = self::get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'not_connected', 'Not connected to Bing Merchant Center.' );
		}

		$creds    = self::get_credentials();
		$headers  = self::build_headers( $token, $creds['developer_token'] ?? '' );
		$statuses = array();
		$next_url = self::API_BASE . '/' . rawurlencode( $store_id ) . '/productstatuses?maxResults=250';

		do {
			$response = wp_remote_get( $next_url, array(
				'headers' => $headers,
				'timeout' => 30,
			) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code !== 200 ) {
				return new WP_Error( 'bmc_error', $data['errors'][0]['message'] ?? 'Bing Merchant Center API error (HTTP ' . $code . ').' );
			}

			foreach ( $data['resources'] ?? array() as $status ) {
				$statuses[ $status['productId'] ] = $status;
			}

			$next_url = ! empty( $data['nextPageToken'] )
				? self::API_BASE . '/' . rawurlencode( $store_id ) . '/productstatuses?maxResults=250&pageToken=' . rawurlencode( $data['nextPageToken'] )
				: '';

		} while ( $next_url );

		return $statuses;
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Build request headers for the Microsoft Advertising Content API.
	 * The API uses AuthenticationToken (not Authorization: Bearer).
	 */
	private static function build_headers( $access_token, $developer_token ) {
		$headers = array(
			'AuthenticationToken' => $access_token,
			'Content-Type'        => 'application/json',
		);
		if ( $developer_token ) {
			$headers['DeveloperToken'] = $developer_token;
		}
		return $headers;
	}
}
