<?php
/**
 * Google Service Account
 *
 * The zero-friction alternative to per-site OAuth: paste one service-account
 * JSON key (owned by the agency) and add its email address as a user on the
 * client's Search Console property / GA4 property. No Google Cloud project
 * per client, no consent screens, no test users, no expiring refresh tokens.
 *
 * Authentication is the standard JWT-bearer flow (RS256, signed locally with
 * openssl) — access tokens are cached in a transient and renewed silently.
 *
 * Key resolution order (first hit wins):
 *   1. TWTAEO_GOOGLE_SA_KEY       — PHP constant holding the raw JSON
 *   2. TWTAEO_GOOGLE_SA_KEY_FILE  — PHP constant with a path to the JSON file
 *   3. twtaeo_google_sa_key       — DB option (pasted in Command Center)
 *
 * @package TWTAEO_Connector
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Google_Service_Account {

	const OPTION_KEY      = 'twtaeo_google_sa_key';
	const TOKEN_TRANSIENT = 'twtaeo_google_sa_token';

	// ── Key access ───────────────────────────────────────────────────────────

	/**
	 * Resolve and decode the service-account key.
	 *
	 * @return array|null Decoded key, or null when absent/invalid.
	 */
	public static function get_key() {
		$raw = '';

		if ( defined( 'TWTAEO_GOOGLE_SA_KEY' ) ) {
			$raw = (string) constant( 'TWTAEO_GOOGLE_SA_KEY' );
		} elseif ( defined( 'TWTAEO_GOOGLE_SA_KEY_FILE' ) && is_readable( constant( 'TWTAEO_GOOGLE_SA_KEY_FILE' ) ) ) {
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			$raw = $wp_filesystem ? (string) $wp_filesystem->get_contents( constant( 'TWTAEO_GOOGLE_SA_KEY_FILE' ) ) : '';
		} else {
			$raw = TWTAEO_Crypt::decrypt( (string) get_option( self::OPTION_KEY, '' ) );
		}

		if ( $raw === '' ) {
			return null;
		}

		$key = json_decode( $raw, true );
		if ( ! is_array( $key ) || empty( $key['client_email'] ) || empty( $key['private_key'] ) ) {
			return null;
		}

		return $key;
	}

	public static function is_configured() {
		return null !== self::get_key();
	}

	/** True when the key comes from wp-config rather than the database. */
	public static function is_protected() {
		return defined( 'TWTAEO_GOOGLE_SA_KEY' ) || defined( 'TWTAEO_GOOGLE_SA_KEY_FILE' );
	}

	/** The address to add as a user in Search Console / GA4. */
	public static function get_client_email() {
		$key = self::get_key();
		return $key ? (string) $key['client_email'] : '';
	}

	/**
	 * Validate and store a pasted JSON key.
	 *
	 * @param string $json
	 * @return true|WP_Error
	 */
	public static function save_key( $json ) {
		$json = trim( (string) $json );
		$key  = json_decode( $json, true );

		if ( ! is_array( $key ) ) {
			return new WP_Error( 'bad_json', 'That is not valid JSON. Paste the entire contents of the downloaded key file.' );
		}
		if ( ( $key['type'] ?? '' ) !== 'service_account' || empty( $key['client_email'] ) || empty( $key['private_key'] ) ) {
			return new WP_Error( 'bad_key', 'That JSON is not a service-account key (expected type "service_account" with client_email and private_key).' );
		}
		if ( ! openssl_pkey_get_private( $key['private_key'] ) ) {
			return new WP_Error( 'bad_private_key', 'The private key inside the JSON could not be parsed.' );
		}

		update_option( self::OPTION_KEY, TWTAEO_Crypt::encrypt( $json ), false );
		delete_transient( self::TOKEN_TRANSIENT );

		return true;
	}

	public static function delete_key() {
		delete_option( self::OPTION_KEY );
		delete_transient( self::TOKEN_TRANSIENT );
	}

	// ── Token (JWT bearer flow) ──────────────────────────────────────────────

	/**
	 * Return a valid access token for the configured scopes, minting a new
	 * one via the JWT-bearer grant when the cached token has expired.
	 *
	 * @return string Empty on failure.
	 */
	public static function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		$key = self::get_key();
		if ( ! $key ) {
			return '';
		}

		$now       = time();
		$token_uri = ! empty( $key['token_uri'] ) ? $key['token_uri'] : 'https://oauth2.googleapis.com/token';

		$header = self::b64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = self::b64url( wp_json_encode( array(
			'iss'   => $key['client_email'],
			'scope' => TWTAEO_Google_OAuth::SCOPES,
			'aud'   => $token_uri,
			'iat'   => $now,
			'exp'   => $now + 3600,
		) ) );

		$signature = '';
		if ( ! openssl_sign( $header . '.' . $claims, $signature, $key['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			error_log( '[TWT AEO] Google SA: failed to sign JWT (openssl).' );
			return '';
		}

		$assertion = $header . '.' . $claims . '.' . self::b64url( $signature );

		$response = wp_remote_post( $token_uri, array(
			'timeout' => 20,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $assertion,
			),
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[TWT AEO] Google SA token request failed — ' . $response->get_error_message() );
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			error_log( '[TWT AEO] Google SA token error — ' . ( $body['error_description'] ?? $body['error'] ?? 'unknown' ) );
			return '';
		}

		// Cache slightly short of the real expiry so we never serve a dead token.
		$ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 300 );
		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], $ttl );

		return $body['access_token'];
	}

	private static function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url encoding for JWT assertion per RFC 7519, not obfuscation.
	}
}
