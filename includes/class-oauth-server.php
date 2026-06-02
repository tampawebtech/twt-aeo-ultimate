<?php
/**
 * Built-in OAuth 2.0 Authorization Server (client credentials grant)
 *
 * Provides:
 *  POST /wp-json/aeo/v1/oauth/token  — issue Bearer tokens
 *  GET  /wp-json/aeo/v1/oauth/jwks   — JSON Web Key Set (empty for opaque tokens)
 *
 * Tokens are opaque random strings stored as WP transients.
 * Client credentials (client_id / client_secret) are stored in a WP option;
 * secrets are hashed with wp_hash_password() (bcrypt).
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_OAuth_Server {

	const CLIENTS_OPTION  = 'twtaeo_oauth_clients';
	const TOKEN_PREFIX    = 'twtaeo_oat_';
	const TOKEN_TTL       = 3600; // 1 hour
	const NONCE_CREATE    = 'twtaeo_oauth_create_client';
	const NONCE_REVOKE    = 'twtaeo_oauth_revoke_client';

	// Rate limiting: max attempts per window before lockout.
	const RL_PREFIX       = 'twtaeo_rl_';
	const RL_MAX_ATTEMPTS = 5;
	const RL_WINDOW       = 900;  // 15 minutes
	const RL_LOCKOUT      = 900;  // 15-minute lockout

	// Input length caps.
	const MAX_CLIENT_ID_LEN     = 64;
	const MAX_CLIENT_SECRET_LEN = 128;
	const MAX_SCOPE_LEN         = 256;

	// ── Init ─────────────────────────────────────────────────────────────────

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_post_twtaeo_oauth_create_client', array( __CLASS__, 'handle_create_client' ) );
		add_action( 'admin_post_twtaeo_oauth_revoke_client', array( __CLASS__, 'handle_revoke_client' ) );
	}

	// ── REST routes ───────────────────────────────────────────────────────────

	public static function register_routes() {
		$s = TWTAEO_AI_Ready::get_settings();

		if ( empty( $s['oauth_discovery'] ) || ( $s['oauth_mode'] ?? 'builtin' ) !== 'builtin' ) {
			return;
		}

		register_rest_route( 'aeo/v1', '/oauth/token', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_token_request' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( 'aeo/v1', '/oauth/jwks', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'handle_jwks' ),
			'permission_callback' => '__return_true',
		) );
	}

	// ── Token endpoint ────────────────────────────────────────────────────────

	public static function handle_token_request( WP_REST_Request $request ) {
		$s = TWTAEO_AI_Ready::get_settings();
		if ( empty( $s['oauth_discovery'] ) ) {
			return self::oauth_error( 'server_error', 'OAuth server is not enabled.', 404 );
		}

		// Enforce rate limit before any credential work.
		$ip_key = self::rate_limit_key();
		if ( self::is_rate_limited( $ip_key ) ) {
			$response = self::oauth_error( 'too_many_requests', 'Too many failed attempts. Please wait before trying again.', 429 );
			$response->header( 'Retry-After', (string) self::RL_LOCKOUT );
			return $response;
		}

		$grant_type = sanitize_text_field( (string) $request->get_param( 'grant_type' ) );
		if ( $grant_type !== 'client_credentials' ) {
			return self::oauth_error( 'unsupported_grant_type', 'Only the client_credentials grant type is supported.', 400 );
		}

		list( $client_id, $client_secret ) = self::extract_credentials( $request );

		// Validate format and length before any DB look-up.
		if ( ! self::validate_client_id( $client_id ) || ! self::validate_client_secret( $client_secret ) ) {
			self::record_failed_attempt( $ip_key );
			return self::oauth_error( 'invalid_client', 'Client credentials are required (Basic auth or client_id/client_secret body params).', 401 );
		}

		$client = self::authenticate_client( $client_id, $client_secret );
		if ( ! $client ) {
			self::record_failed_attempt( $ip_key );
			return self::oauth_error( 'invalid_client', 'Invalid client_id or client_secret.', 401 );
		}

		// Successful auth — clear the failure counter.
		self::clear_rate_limit( $ip_key );

		// Validate and resolve requested scope — default to all client scopes.
		$scope_param = $request->get_param( 'scope' );
		if ( $scope_param !== null ) {
			$scope_str = substr( sanitize_text_field( (string) $scope_param ), 0, self::MAX_SCOPE_LEN );
			$requested = array_values( array_filter( explode( ' ', $scope_str ) ) );
		} else {
			$requested = $client['scopes'];
		}

		$granted = array_values( array_intersect( $requested, $client['scopes'] ) );

		if ( empty( $granted ) ) {
			return self::oauth_error( 'invalid_scope', 'None of the requested scopes are authorised for this client.', 400 );
		}

		$token = self::issue_token( $client_id, $granted );

		$response = new WP_REST_Response( array(
			'access_token' => $token,
			'token_type'   => 'Bearer',
			'expires_in'   => self::TOKEN_TTL,
			'scope'        => implode( ' ', $granted ),
		), 200 );

		// RFC 6749 §5.1 — no-cache on token responses.
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	// ── JWKS endpoint ─────────────────────────────────────────────────────────

	public static function handle_jwks() {
		// Opaque tokens — no public keys to publish. Return an empty keyset per RFC 7517.
		return rest_ensure_response( array( 'keys' => array() ) );
	}

	// ── Token verification (called from AI_Ready::rest_permission_check) ──────

	public static function verify_bearer( $token ) {
		if ( ! $token ) {
			return null;
		}
		$key  = self::TOKEN_PREFIX . hash( 'sha256', $token );
		$data = get_transient( $key );
		return $data ?: null;
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	private static function oauth_error( $code, $message, $status ) {
		$response = new WP_REST_Response( array(
			'error'             => $code,
			'error_description' => $message,
		), $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function extract_credentials( WP_REST_Request $request ) {
		// Try HTTP Basic Auth first (preferred per RFC 6749 §2.3.1).
		$auth = $request->get_header( 'authorization' );
		if ( $auth && strncasecmp( $auth, 'Basic ', 6 ) === 0 ) {
			$decoded = base64_decode( substr( $auth, 6 ), true );
			if ( $decoded !== false && strpos( $decoded, ':' ) !== false ) {
				return explode( ':', $decoded, 2 );
			}
		}
		// Fall back to request body params.
		return array(
			$request->get_param( 'client_id' ),
			$request->get_param( 'client_secret' ),
		);
	}

	// ── Input validation ──────────────────────────────────────────────────────

	private static function validate_client_id( $value ) {
		if ( ! is_string( $value ) || $value === '' ) {
			return false;
		}
		if ( strlen( $value ) > self::MAX_CLIENT_ID_LEN ) {
			return false;
		}
		// Client IDs we generate are hex with an "aeo_" prefix; allow any
		// printable ASCII except whitespace and control chars.
		return (bool) preg_match( '/^[\x21-\x7E]+$/', $value );
	}

	private static function validate_client_secret( $value ) {
		if ( ! is_string( $value ) || $value === '' ) {
			return false;
		}
		if ( strlen( $value ) > self::MAX_CLIENT_SECRET_LEN ) {
			return false;
		}
		return (bool) preg_match( '/^[\x21-\x7E]+$/', $value );
	}

	// ── Rate limiting ─────────────────────────────────────────────────────────

	private static function rate_limit_key() {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
		// Hash so the raw IP is not stored in the DB.
		return self::RL_PREFIX . hash( 'sha256', $ip );
	}

	private static function is_rate_limited( $key ) {
		$data = get_transient( $key );
		if ( ! $data ) {
			return false;
		}
		return isset( $data['count'] ) && $data['count'] >= self::RL_MAX_ATTEMPTS;
	}

	private static function record_failed_attempt( $key ) {
		$data  = get_transient( $key );
		$count = ( $data && isset( $data['count'] ) ) ? (int) $data['count'] + 1 : 1;
		set_transient( $key, array( 'count' => $count ), self::RL_WINDOW );
	}

	private static function clear_rate_limit( $key ) {
		delete_transient( $key );
	}

	private static function authenticate_client( $client_id, $client_secret ) {
		$clients = get_option( self::CLIENTS_OPTION, array() );
		if ( ! isset( $clients[ $client_id ] ) ) {
			return false;
		}
		$client = $clients[ $client_id ];
		if ( ! wp_check_password( $client_secret, $client['secret_hash'] ) ) {
			return false;
		}
		return $client;
	}

	private static function issue_token( $client_id, array $scopes ) {
		$token = bin2hex( random_bytes( 32 ) );
		$key   = self::TOKEN_PREFIX . hash( 'sha256', $token );
		set_transient( $key, array(
			'client_id' => $client_id,
			'scopes'    => $scopes,
		), self::TOKEN_TTL );
		return $token;
	}

	// ── Client management ─────────────────────────────────────────────────────

	public static function get_clients() {
		return get_option( self::CLIENTS_OPTION, array() );
	}

	public static function handle_create_client() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}
		check_admin_referer( self::NONCE_CREATE );

		$name   = sanitize_text_field( wp_unslash( $_POST['client_name'] ?? 'Unnamed Client' ) );
		$raw_scopes = is_array( $_POST['client_scopes'] ?? null ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['client_scopes'] ) ) : array( 'read' );
		$valid_scopes = array( 'read', 'search' );
		$scopes = array_values( array_intersect( $raw_scopes, $valid_scopes ) );
		if ( empty( $scopes ) ) {
			$scopes = array( 'read' );
		}
		if ( ! $name ) {
			$name = 'Unnamed Client';
		}

		$client_id  = 'aeo_' . bin2hex( random_bytes( 8 ) );
		$secret     = bin2hex( random_bytes( 24 ) );
		$clients    = get_option( self::CLIENTS_OPTION, array() );

		$clients[ $client_id ] = array(
			'name'        => $name,
			'scopes'      => $scopes,
			'secret_hash' => wp_hash_password( $secret ),
			'created_at'  => time(),
		);
		update_option( self::CLIENTS_OPTION, $clients );

		// Stash plain-text secret for 60 seconds so the admin page can show it once.
		set_transient( 'twtaeo_new_client_creds', array(
			'id'     => $client_id,
			'secret' => $secret,
			'name'   => $name,
		), 60 );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'twt-aeo-ai-ready', 'twtaeo_client_created' => '1' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function handle_revoke_client() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}
		check_admin_referer( self::NONCE_REVOKE );

		$client_id = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
		$clients   = get_option( self::CLIENTS_OPTION, array() );
		unset( $clients[ $client_id ] );
		update_option( self::CLIENTS_OPTION, $clients );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'twt-aeo-ai-ready', 'twtaeo_client_revoked' => '1' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
