<?php
/**
 * Connect with Google through aeoultimate.app
 *
 * Tampa Web Technologies runs one verified Google OAuth app at aeoultimate.app,
 * so site owners can connect Search Console, Analytics and Business Profile
 * with one click instead of creating their own Google Cloud project. The
 * hub keeps the encrypted refresh token and hands this site short-lived
 * access tokens; the site then calls Google itself, so report data never
 * passes through the hub.
 *
 * Bring-your-own OAuth (TWTAEO_Google_OAuth) and service accounts keep working
 * exactly as before; this is an extra way to connect, not a replacement.
 *
 * On by default. A site can turn it off by defining TWTAEO_GOOGLE_HUB as false
 * in wp-config (or returning false from the twtaeo_google_hub_enabled filter).
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Google_Hub {

	const OPTION        = 'twtaeo_google_hub';
	const OPTION_FLOW   = 'twtaeo_google_hub_flow';
	const TOKEN_CACHE   = 'twtaeo_google_hub_access';
	const NOTICE_PREFIX = 'twtaeo_google_hub_notice_';

	const DEFAULT_HUB_URL = 'https://api.aeoultimate.app';

	/** Feature names the hub knows, as sent to /google/connect/start. */
	const FEATURES = array( 'search_console', 'analytics', 'business_profile' );

	public static function register_hooks() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_return' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	// ── Settings ─────────────────────────────────────────────────────────────

	public static function is_enabled() {
		$on = ! defined( 'TWTAEO_GOOGLE_HUB' ) || TWTAEO_GOOGLE_HUB;
		return (bool) apply_filters( 'twtaeo_google_hub_enabled', $on );
	}

	public static function hub_url() {
		$url = defined( 'TWTAEO_GOOGLE_HUB_URL' ) ? (string) TWTAEO_GOOGLE_HUB_URL : self::DEFAULT_HUB_URL;
		return untrailingslashit( $url );
	}

	private static function get_state() {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	private static function save_state( array $state ) {
		update_option( self::OPTION, $state, false );
	}

	private static function site_token() {
		$state = self::get_state();
		return empty( $state['token'] ) ? '' : TWTAEO_Crypt::decrypt( (string) $state['token'] );
	}

	// ── Connection State ─────────────────────────────────────────────────────

	public static function is_connected() {
		return '' !== self::site_token() && empty( self::get_state()['reconnect'] );
	}

	/** Connected once, but Google access was removed: the owner must connect again. */
	public static function needs_reconnect() {
		return '' !== self::site_token() && ! empty( self::get_state()['reconnect'] );
	}

	public static function has_feature( $feature ) {
		return self::is_connected() && in_array( $feature, self::get_features(), true );
	}

	public static function get_features() {
		$features = self::get_state()['features'] ?? array();
		return is_array( $features ) ? $features : array();
	}

	public static function get_email() {
		return (string) ( self::get_state()['email'] ?? '' );
	}

	// ── Connect ──────────────────────────────────────────────────────────────

	/**
	 * Ask the hub for a sign-in link.
	 *
	 * @param string[] $features   From self::FEATURES.
	 * @param string   $return_url Admin page to come back to (must be on this site).
	 * @return string|WP_Error The URL to send the browser to.
	 */
	public static function start( array $features, $return_url ) {
		$features = array_values( array_intersect( $features, self::FEATURES ) );
		if ( empty( $features ) ) {
			return new WP_Error( 'no_features', __( 'Nothing to connect.', 'twt-aeo-ultimate' ) );
		}
		// Reconnecting keeps what is already connected (Google adds the new access to it).
		if ( self::site_token() ) {
			$features = array_values( array_unique( array_merge( self::get_features(), $features ) ) );
		}

		$verifier  = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE verifier encoding.
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE challenge encoding.

		$headers = array( 'Content-Type' => 'application/json' );
		if ( self::site_token() ) {
			$headers['Authorization'] = 'Bearer ' . self::site_token();
		}
		$response = wp_remote_post( self::hub_url() . '/google/connect/start', array(
			'headers' => $headers,
			'body'    => wp_json_encode( array(
				'site_url'   => home_url( '/' ),
				'return_url' => $return_url,
				'features'   => $features,
				'challenge'  => $challenge,
			) ),
			'timeout' => 15,
		) );
		$body = self::decode( $response );
		if ( is_wp_error( $body ) ) {
			TWTAEO_Logger::warning( 'Connect with Google: could not start sign-in.', array( 'error' => $body->get_error_message() ) );
			return $body;
		}
		if ( empty( $body['flow'] ) || empty( $body['go_url'] ) ) {
			return new WP_Error( 'hub_error', __( 'aeoultimate.app did not return a sign-in link.', 'twt-aeo-ultimate' ) );
		}

		update_option( self::OPTION_FLOW, array(
			'flow'     => (string) $body['flow'],
			'verifier' => TWTAEO_Crypt::encrypt( $verifier ),
			'return'   => $return_url,
			'expires'  => time() + 15 * MINUTE_IN_SECONDS,
		), false );

		return (string) $body['go_url'];
	}

	/**
	 * The browser comes back from the hub with ?twtaeo_google_flow=… and either
	 * a handoff code or an error. Exchange the code (with the verifier only
	 * this site knows) for the site's connection token.
	 */
	public static function maybe_handle_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth return; bound to the stored flow id and verifier instead of a nonce.
		if ( empty( $_GET['twtaeo_google_flow'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$flow_id = sanitize_text_field( wp_unslash( $_GET['twtaeo_google_flow'] ) );
		$code    = isset( $_GET['twtaeo_google_code'] ) ? sanitize_text_field( wp_unslash( $_GET['twtaeo_google_code'] ) ) : '';
		$error   = isset( $_GET['twtaeo_google_error'] ) ? sanitize_key( wp_unslash( $_GET['twtaeo_google_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$flow = get_option( self::OPTION_FLOW, array() );
		$back = remove_query_arg( array( 'twtaeo_google_flow', 'twtaeo_google_code', 'twtaeo_google_error' ) );
		if ( ! is_array( $flow ) || empty( $flow['flow'] ) || ! hash_equals( (string) $flow['flow'], $flow_id ) ) {
			// Not ours (or already handled, e.g. a reload): drop the parameters quietly.
			wp_safe_redirect( $back );
			exit;
		}
		delete_option( self::OPTION_FLOW );

		if ( $error || '' === $code ) {
			self::notice( 'warning', 'access_denied' === $error
				? __( 'Google connection cancelled.', 'twt-aeo-ultimate' )
				: __( 'Google did not complete the connection. Please try again.', 'twt-aeo-ultimate' ) );
			wp_safe_redirect( $back );
			exit;
		}
		if ( time() > (int) ( $flow['expires'] ?? 0 ) ) {
			self::notice( 'error', __( 'The Google sign-in took too long. Please connect again.', 'twt-aeo-ultimate' ) );
			wp_safe_redirect( $back );
			exit;
		}

		$response = wp_remote_post( self::hub_url() . '/google/connect/claim', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'flow'     => $flow_id,
				'code'     => $code,
				'verifier' => TWTAEO_Crypt::decrypt( (string) $flow['verifier'] ),
			) ),
			'timeout' => 15,
		) );
		$body = self::decode( $response );
		if ( is_wp_error( $body ) || empty( $body['site_token'] ) ) {
			$message = is_wp_error( $body ) ? $body->get_error_message() : 'no site_token';
			TWTAEO_Logger::warning( 'Connect with Google: could not finish the connection.', array( 'error' => $message ) );
			self::notice( 'error', __( 'Could not finish connecting Google. Please try again.', 'twt-aeo-ultimate' ) );
			wp_safe_redirect( $back );
			exit;
		}

		self::save_state( array(
			'token'        => TWTAEO_Crypt::encrypt( (string) $body['site_token'] ),
			'email'        => sanitize_email( (string) ( $body['email'] ?? '' ) ),
			'features'     => array_values( array_intersect( (array) ( $body['features'] ?? array() ), self::FEATURES ) ),
			'connected_at' => time(),
		) );
		delete_transient( self::TOKEN_CACHE );
		self::clear_google_caches();

		$email = self::get_email();
		self::notice( 'success', $email
			/* translators: %s: Google account email address. */
			? sprintf( __( 'Google connected as %s.', 'twt-aeo-ultimate' ), $email )
			: __( 'Google connected.', 'twt-aeo-ultimate' ) );
		wp_safe_redirect( $back );
		exit;
	}

	// ── Access Tokens ────────────────────────────────────────────────────────

	/**
	 * A Google access token from the hub, cached until shortly before it expires.
	 *
	 * @param string $feature Optional: only answer when this feature is connected.
	 * @return string Empty when not connected or the hub can't be reached.
	 */
	public static function get_access_token( $feature = '' ) {
		if ( ! self::is_connected() || ( $feature && ! self::has_feature( $feature ) ) ) {
			return '';
		}
		$cached = get_transient( self::TOKEN_CACHE );
		if ( is_string( $cached ) && '' !== $cached ) {
			$token = TWTAEO_Crypt::decrypt( $cached );
			if ( $token ) {
				return $token;
			}
		}

		$response = wp_remote_post( self::hub_url() . '/google/token', array(
			'headers' => array( 'Authorization' => 'Bearer ' . self::site_token() ),
			'timeout' => 15,
		) );
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$body = self::decode( $response );

		if ( 401 === $code ) {
			// Access removed in the Google account, or the connection was deleted on the hub.
			$state              = self::get_state();
			$state['reconnect'] = true;
			self::save_state( $state );
			TWTAEO_Logger::warning( 'Connect with Google: Google access was removed; the site must connect again.' );
			return '';
		}
		if ( is_wp_error( $body ) || empty( $body['access_token'] ) ) {
			TWTAEO_Logger::warning( 'Connect with Google: could not get an access token.', array(
				'error' => is_wp_error( $body ) ? $body->get_error_message() : 'empty token',
			) );
			return '';
		}

		$ttl = (int) ( $body['expires_in'] ?? 0 ) - 2 * MINUTE_IN_SECONDS;
		if ( $ttl > 0 ) {
			set_transient( self::TOKEN_CACHE, TWTAEO_Crypt::encrypt( (string) $body['access_token'] ), $ttl );
		}
		// Keep the granted features current (the owner can remove access per product at Google).
		if ( isset( $body['features'] ) && is_array( $body['features'] ) ) {
			$state             = self::get_state();
			$state['features'] = array_values( array_intersect( $body['features'], self::FEATURES ) );
			self::save_state( $state );
		}
		return (string) $body['access_token'];
	}

	// ── Disconnect ───────────────────────────────────────────────────────────

	/** Forget the connection here and on the hub (which revokes it at Google when nothing else uses it). */
	public static function disconnect() {
		$token = self::site_token();
		if ( $token ) {
			// Best effort: the local side is cleared either way.
			wp_remote_post( self::hub_url() . '/google/disconnect', array(
				'headers'  => array( 'Authorization' => 'Bearer ' . $token ),
				'timeout'  => 10,
				'blocking' => true,
			) );
		}
		delete_option( self::OPTION );
		delete_option( self::OPTION_FLOW );
		delete_transient( self::TOKEN_CACHE );
		self::clear_google_caches();
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * @param array|WP_Error $response
	 * @return array|WP_Error
	 */
	private static function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$reason = is_array( $body ) ? ( $body['reason'] ?? $body['error'] ?? '' ) : '';
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'hub_http_' . $code, $reason ? (string) $reason : sprintf( __( 'aeoultimate.app answered with HTTP %d.', 'twt-aeo-ultimate' ), $code ) );
		}
		return is_array( $body ) ? $body : array();
	}

	private static function clear_google_caches() {
		foreach ( array( 'twtaeo_ga4_totals', 'twtaeo_ga4_top_pages', 'twtaeo_ga4_top_pages_v2', 'twtaeo_ga4_ai_traffic', 'twtaeo_gsc_totals', 'twtaeo_gsc_top_queries', 'twtaeo_gsc_properties' ) as $key ) {
			delete_transient( $key );
		}
	}

	public static function notice( $type, $message ) {
		set_transient( self::NOTICE_PREFIX . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 5 * MINUTE_IN_SECONDS );
	}

	public static function render_notice() {
		$key    = self::NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notice['type'] ),
			esc_html( $notice['message'] )
		);
	}
}
