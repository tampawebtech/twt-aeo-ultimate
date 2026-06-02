<?php
/**
 * Local Pack NAP Checker
 *
 * Checks Name / Address / Phone consistency against:
 *   - Google Knowledge Graph Search API (free, API key only)
 *   - Google Business Profile API (OAuth, separate scope from Search Console)
 *   - Bing Maps Local Search API (free tier, Bing Maps key)
 *
 * Also handles the GBP OAuth flow (stored separately from the SC/GA4 connection).
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Local_Pack_NAP {

	const OPTION_GBP_TOKENS = 'twtaeo_local_pack_gbp_tokens';
	const OPTION_GBP_STATE  = 'twtaeo_local_pack_gbp_state';

	const KG_SEARCH_URL      = 'https://kgsearch.googleapis.com/v1/entities:search';
	const GBP_ACCOUNTS_URL   = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';
	const GBP_LOCATIONS_BASE = 'https://mybusinessbusinessinformation.googleapis.com/v1';
	const BING_SEARCH_URL    = 'https://dev.virtualearth.net/REST/v1/LocalSearch/';

	const GBP_SCOPE = 'https://www.googleapis.com/auth/business.manage';

	// ── Google Knowledge Graph ────────────────────────────────────────────────

	/**
	 * Search the Google Knowledge Graph for a business entity and return raw NAP.
	 *
	 * @param string $business_name  Business name to search.
	 * @param string $api_key        Google Knowledge Graph API key.
	 * @return array|WP_Error  { name, phone, address, source } or WP_Error.
	 */
	public static function check_google_kg( $business_name, $api_key ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_key', 'Google Knowledge Graph API key is not set.' );
		}
		if ( empty( $business_name ) ) {
			return new WP_Error( 'no_name', 'Business name is required.' );
		}

		$url = add_query_arg( array(
			'query'  => $business_name,
			'key'    => $api_key,
			'types'  => 'LocalBusiness',
			'limit'  => 3,
			'indent' => 'True',
		), self::KG_SEARCH_URL );

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = $body['error']['message'] ?? 'Knowledge Graph API error (HTTP ' . $code . ').';
			return new WP_Error( 'kg_error', $message );
		}

		$items = $body['itemListElement'] ?? array();
		if ( empty( $items ) ) {
			return new WP_Error( 'not_found', 'No matching entity found in Google Knowledge Graph.' );
		}

		// Use the highest-scored result.
		$result = $items[0]['result'] ?? array();

		$name    = $result['name'] ?? '';
		$phone   = $result['telephone'] ?? '';
		$address = $result['address'] ?? array();

		$address_string = implode( ', ', array_filter( array(
			$address['streetAddress']   ?? '',
			$address['addressLocality'] ?? '',
			$address['addressRegion']   ?? '',
			$address['postalCode']      ?? '',
		) ) );

		return array(
			'name'    => $name,
			'phone'   => $phone,
			'address' => $address_string,
			'raw'     => $result,
			'source'  => 'Google Knowledge Graph',
		);
	}

	// ── Google Business Profile OAuth ─────────────────────────────────────────

	/**
	 * Build the GBP authorization URL using the existing OAuth client credentials.
	 * Stored separately from the SC/GA4 connection.
	 *
	 * @return string|WP_Error
	 */
	public static function get_gbp_oauth_url() {
		$creds = TWTAEO_Google_OAuth::get_credentials();
		if ( empty( $creds['client_id'] ) ) {
			return new WP_Error( 'no_credentials', 'Google OAuth credentials are not configured. Set them up in the Command Center first.' );
		}

		$state = wp_create_nonce( 'twtaeo_gbp_oauth' );
		update_option( self::OPTION_GBP_STATE, $state );

		return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( array(
			'client_id'     => $creds['client_id'],
			'redirect_uri'  => self::get_gbp_redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::GBP_SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		) );
	}

	public static function get_gbp_redirect_uri() {
		return admin_url( 'admin.php?page=twt-aeo-local-pack&twt_gbp_callback=1' );
	}

	/**
	 * Exchange the authorization code from the GBP OAuth callback.
	 *
	 * @param string $code
	 * @param string $state
	 * @return true|WP_Error
	 */
	public static function handle_gbp_callback( $code, $state ) {
		$stored = get_option( self::OPTION_GBP_STATE, '' );
		if ( empty( $stored ) || ! hash_equals( $stored, $state ) ) {
			return new WP_Error( 'invalid_state', 'Invalid OAuth state. Please try connecting again.' );
		}
		delete_option( self::OPTION_GBP_STATE );

		$creds = TWTAEO_Google_OAuth::get_credentials();
		if ( empty( $creds['client_id'] ) || empty( $creds['client_secret'] ) ) {
			return new WP_Error( 'no_credentials', 'Google OAuth credentials are not configured.' );
		}

		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
			'body' => array(
				'code'          => $code,
				'client_id'     => $creds['client_id'],
				'client_secret' => $creds['client_secret'],
				'redirect_uri'  => self::get_gbp_redirect_uri(),
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

		update_option( self::OPTION_GBP_TOKENS, array(
			'access_token'  => $body['access_token']  ?? '',
			'refresh_token' => $body['refresh_token'] ?? '',
			'expires_at'    => time() + (int) ( $body['expires_in'] ?? 3600 ),
		) );

		return true;
	}

	public static function is_gbp_connected() {
		$tokens = get_option( self::OPTION_GBP_TOKENS, array() );
		return ! empty( $tokens['refresh_token'] );
	}

	public static function disconnect_gbp() {
		delete_option( self::OPTION_GBP_TOKENS );
		delete_option( self::OPTION_GBP_STATE );
	}

	private static function get_gbp_access_token() {
		$tokens = get_option( self::OPTION_GBP_TOKENS, array() );

		if ( empty( $tokens['access_token'] ) ) {
			return '';
		}

		if ( ! empty( $tokens['expires_at'] ) && time() > ( $tokens['expires_at'] - 60 ) ) {
			$refreshed = self::refresh_gbp_token( $tokens );
			if ( is_wp_error( $refreshed ) ) {
				return '';
			}
			$tokens = get_option( self::OPTION_GBP_TOKENS, array() );
		}

		return $tokens['access_token'] ?? '';
	}

	private static function refresh_gbp_token( array $tokens ) {
		$creds = TWTAEO_Google_OAuth::get_credentials();

		if ( empty( $tokens['refresh_token'] ) || empty( $creds['client_id'] ) ) {
			return new WP_Error( 'no_refresh', 'No refresh token available.' );
		}

		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
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
			self::disconnect_gbp();
			return new WP_Error( $body['error'], $body['error_description'] ?? 'Refresh failed.' );
		}

		$tokens['access_token'] = $body['access_token'] ?? '';
		$tokens['expires_at']   = time() + (int) ( $body['expires_in'] ?? 3600 );
		update_option( self::OPTION_GBP_TOKENS, $tokens );

		return true;
	}

	// ── Google Business Profile NAP ───────────────────────────────────────────

	/**
	 * Fetch the first GBP location for the connected account and return NAP.
	 *
	 * @return array|WP_Error
	 */
	public static function check_gbp() {
		$token = self::get_gbp_access_token();
		if ( ! $token ) {
			return new WP_Error( 'not_connected', 'Not connected to Google Business Profile.' );
		}

		// List accounts.
		$accounts_resp = wp_remote_get( self::GBP_ACCOUNTS_URL, array(
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $accounts_resp ) ) {
			return $accounts_resp;
		}

		$accounts_code = wp_remote_retrieve_response_code( $accounts_resp );
		$accounts_body = json_decode( wp_remote_retrieve_body( $accounts_resp ), true );

		if ( 200 !== $accounts_code ) {
			if ( 403 === $accounts_code ) {
				return new WP_Error( 'scope_missing', 'Business Profile permission was not granted. Please reconnect.' );
			}
			return new WP_Error( 'gbp_error', $accounts_body['error']['message'] ?? 'GBP accounts API error (HTTP ' . $accounts_code . ').' );
		}

		$accounts = $accounts_body['accounts'] ?? array();
		if ( empty( $accounts ) ) {
			return new WP_Error( 'no_accounts', 'No Google Business Profile accounts found for this Google account.' );
		}

		$account_name = $accounts[0]['name'] ?? '';
		if ( empty( $account_name ) ) {
			return new WP_Error( 'no_account_name', 'Could not retrieve GBP account name.' );
		}

		// List locations for the first account.
		$loc_url = self::GBP_LOCATIONS_BASE . '/' . $account_name . '/locations'
			. '?readMask=name,title,phoneNumbers,storefrontAddress';

		$loc_resp = wp_remote_get( $loc_url, array(
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $loc_resp ) ) {
			return $loc_resp;
		}

		$loc_code = wp_remote_retrieve_response_code( $loc_resp );
		$loc_body = json_decode( wp_remote_retrieve_body( $loc_resp ), true );

		if ( 200 !== $loc_code ) {
			return new WP_Error( 'gbp_locations_error', $loc_body['error']['message'] ?? 'GBP locations API error (HTTP ' . $loc_code . ').' );
		}

		$locations = $loc_body['locations'] ?? array();
		if ( empty( $locations ) ) {
			return new WP_Error( 'no_locations', 'No locations found on this Google Business Profile account.' );
		}

		$loc     = $locations[0];
		$name    = $loc['title'] ?? '';
		$phone   = $loc['phoneNumbers']['primaryPhone'] ?? '';
		$addr    = $loc['storefrontAddress'] ?? array();

		$address_string = implode( ', ', array_filter( array(
			implode( ' ', $addr['addressLines'] ?? array() ),
			$addr['locality']           ?? '',
			$addr['administrativeArea'] ?? '',
			$addr['postalCode']         ?? '',
		) ) );

		return array(
			'name'    => $name,
			'phone'   => $phone,
			'address' => $address_string,
			'raw'     => $loc,
			'source'  => 'Google Business Profile',
		);
	}

	// ── Bing Maps Local Search ────────────────────────────────────────────────

	/**
	 * Search Bing Maps Local Search API for the business.
	 *
	 * @param string $business_name
	 * @param string $api_key        Bing Maps API key (from bingmapsportal.com).
	 * @param string $lat            Business latitude (used to localize results).
	 * @param string $lng            Business longitude.
	 * @return array|WP_Error
	 */
	public static function check_bing( $business_name, $api_key, $lat = '', $lng = '' ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_key', 'Bing Maps API key is not set.' );
		}
		if ( empty( $business_name ) ) {
			return new WP_Error( 'no_name', 'Business name is required.' );
		}

		$args = array(
			'query' => $business_name,
			'key'   => $api_key,
		);

		if ( ! empty( $lat ) && ! empty( $lng ) ) {
			$args['userLocation'] = $lat . ',' . $lng;
		}

		$url      = add_query_arg( $args, self::BING_SEARCH_URL );
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = $body['errorDetails'][0] ?? 'Bing Maps API error (HTTP ' . $code . ').';
			return new WP_Error( 'bing_error', $message );
		}

		$resources = $body['resourceSets'][0]['resources'] ?? array();
		if ( empty( $resources ) ) {
			return new WP_Error( 'not_found', 'No matching business found in Bing Maps.' );
		}

		$biz     = $resources[0];
		$name    = $biz['name'] ?? '';
		$phone   = $biz['PhoneNumber'] ?? '';
		$addr    = $biz['Address'] ?? array();

		$address_string = implode( ', ', array_filter( array(
			$addr['addressLine']       ?? '',
			$addr['locality']          ?? '',
			$addr['adminDistrict']     ?? '',
			$addr['postalCode']        ?? '',
		) ) );

		return array(
			'name'    => $name,
			'phone'   => $phone,
			'address' => $address_string,
			'raw'     => $biz,
			'source'  => 'Bing Maps',
		);
	}

	// ── NAP Comparison ────────────────────────────────────────────────────────

	/**
	 * Compare stored NAP with data returned from an external source.
	 *
	 * @param array $stored   { name, phone, address } from site settings.
	 * @param array $external { name, phone, address, source } from API.
	 * @return array  Array of comparison rows: { field, stored, external, match, source }.
	 */
	public static function compare( array $stored, array $external ) {
		$rows = array();

		$fields = array(
			'name'    => 'Business Name',
			'phone'   => 'Phone Number',
			'address' => 'Address',
		);

		foreach ( $fields as $key => $label ) {
			$stored_val   = $stored[ $key ]   ?? '';
			$external_val = $external[ $key ] ?? '';

			$rows[] = array(
				'field'    => $label,
				'stored'   => $stored_val,
				'external' => $external_val,
				'match'    => self::values_match( $key, $stored_val, $external_val ),
				'source'   => $external['source'] ?? '',
			);
		}

		return $rows;
	}

	private static function values_match( $field, $a, $b ) {
		if ( '' === $a || '' === $b ) {
			return null; // Unknown — can't compare missing data.
		}

		if ( 'phone' === $field ) {
			return self::normalize_phone( $a ) === self::normalize_phone( $b );
		}

		if ( 'name' === $field ) {
			return self::fuzzy_match( $a, $b );
		}

		// Address: normalize and fuzzy match.
		return self::fuzzy_match( $a, $b );
	}

	private static function normalize_phone( $phone ) {
		return preg_replace( '/[^0-9]/', '', (string) $phone );
	}

	private static function fuzzy_match( $a, $b ) {
		$a = strtolower( trim( preg_replace( '/[^a-zA-Z0-9\s]/', '', (string) $a ) ) );
		$b = strtolower( trim( preg_replace( '/[^a-zA-Z0-9\s]/', '', (string) $b ) ) );

		if ( $a === $b ) {
			return true;
		}
		if ( strpos( $a, $b ) !== false || strpos( $b, $a ) !== false ) {
			return true;
		}
		// Levenshtein similarity > 80%.
		$max = max( strlen( $a ), strlen( $b ) );
		if ( $max > 0 && ( 1 - levenshtein( $a, $b ) / $max ) > 0.8 ) {
			return true;
		}

		return false;
	}
}
