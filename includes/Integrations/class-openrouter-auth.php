<?php
/**
 * Connect with OpenRouter.
 *
 * OpenRouter's OAuth is PKCE, and what it hands back at the end is an ordinary
 * API key rather than a token that has to be refreshed. So this is not a
 * second way of authenticating, it is a friendlier way of obtaining the same
 * credential the Settings field takes: the owner clicks Connect instead of
 * copying a key out of another tab, and everything downstream is identical.
 *
 * There is no hub in the middle, unlike the Google connection: the exchange
 * goes straight from this site to openrouter.ai, so nothing of the owner's
 * passes through Tampa Web Technologies.
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_OpenRouter_Auth {

	/** Where the owner approves the connection. */
	const AUTH_URL = 'https://openrouter.ai/auth';

	/** Where the one-time code is exchanged for a key. */
	const EXCHANGE_URL = 'https://openrouter.ai/api/v1/auth/keys';

	/** Settings field the resulting key lands in, same as a pasted one. */
	const SETTING_FIELD = 'api_openrouter';

	/** Marker on the return URL, so this only reads codes meant for it. */
	const RETURN_FLAG = 'twtaeo_openrouter_connect';

	/** Per-user transient holding the PKCE verifier between the two legs. */
	const VERIFIER_PREFIX = 'twtaeo_openrouter_verifier_';

	/** Per-user transient carrying a result message across the redirect. */
	const NOTICE_PREFIX = 'twtaeo_openrouter_notice_';

	private function __construct() {}

	public static function register_hooks() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_start' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_return' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_notice' ) );
	}

	/** Whether a key is already held, however it was obtained. */
	public static function is_connected() {
		return '' !== TWTAEO_Key_Resolver::get( 'openrouter' );
	}

	/**
	 * The Settings URL this flow starts and ends at.
	 *
	 * @return string
	 */
	public static function settings_url() {
		return admin_url( 'admin.php?page=twt-aeo-settings' );
	}

	/** The button's href. The nonce is checked before anything is sent. */
	public static function connect_url() {
		return wp_nonce_url(
			add_query_arg( self::RETURN_FLAG, 'start', self::settings_url() ),
			'twtaeo_openrouter_start',
			'_twtaeo_or'
		);
	}

	// ── Leg one: send them to OpenRouter ──────────────────────────────────────

	public static function maybe_start() {
		if ( ! isset( $_GET[ self::RETURN_FLAG ] ) || 'start' !== $_GET[ self::RETURN_FLAG ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is verified immediately below; this only decides whether this request is ours at all.
			return;
		}
		// A credential, so administrators only, never the wider plugin role.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['_twtaeo_or'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_twtaeo_or'] ) ), 'twtaeo_openrouter_start' ) ) {
			return;
		}

		// 43 to 128 characters of unreserved ASCII, per RFC 7636.
		$verifier = wp_generate_password( 64, false, false );
		set_transient( self::VERIFIER_PREFIX . get_current_user_id(), $verifier, 10 * MINUTE_IN_SECONDS );

		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url encoding of the PKCE challenge, as the spec requires.

		// A bare wp-admin URL with no query string of its own.
		//
		// OpenRouter keeps only the path of the callback it is given and
		// rebuilds the query with its own code, so anything passed here is
		// thrown away. Handing it a settings URL meant the return landed on
		// admin.php with no page to render and nothing to identify the flow,
		// which is why the first attempt died silently. What proves this
		// callback belongs to a connection this person started is the PKCE
		// verifier, not a marker in a URL the provider is free to rewrite.
		$callback = admin_url();

		wp_redirect( // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Deliberately leaving the site for openrouter.ai, which wp_safe_redirect would block.
			add_query_arg(
				array(
					'callback_url'          => rawurlencode( $callback ),
					'code_challenge'        => $challenge,
					'code_challenge_method' => 'S256',
				),
				self::AUTH_URL
			)
		);
		exit;
	}

	// ── Leg two: swap the code for a key ──────────────────────────────────────

	/**
	 * The authorisation code, however the provider chose to append it.
	 *
	 * The callback this site hands over is a wp-admin URL, so it already has a
	 * query string. A provider that appends its code with "?" rather than "&"
	 * therefore glues it onto the last value instead of adding a parameter, and
	 * the code arrives inside `twtaeo_openrouter_connect=return?code=abc123`.
	 * Reading only $_GET['code'] misses that completely and the whole flow ends
	 * in silence, which is exactly how this first failed.
	 *
	 * @return string
	 */
	private static function code_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A provider's redirect cannot carry a nonce; the PKCE verifier is what authenticates this leg, and it is checked by the caller.
		if ( ! empty( $_GET['code'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['code'] ) );
		}

		foreach ( (array) $_GET as $value ) {
			if ( ! is_string( $value ) || false === strpos( $value, 'code=' ) ) {
				continue;
			}
			if ( preg_match( '/(?:^|[?&])code=([^&]+)/', wp_unslash( $value ), $m ) ) {
				return sanitize_text_field( rawurldecode( $m[1] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return '';
	}

	public static function maybe_handle_return() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// A live verifier is the thing that says a connection is in flight for
		// this person. Leading with it, rather than with an exact match on a
		// flag the provider may have mangled, is what makes this robust to how
		// the code was appended; it also means a stray ?code= on some other
		// admin screen can never be mistaken for this flow.
		$user_id  = get_current_user_id();
		$key_tr   = self::VERIFIER_PREFIX . $user_id;
		$verifier = get_transient( $key_tr );
		if ( ! $verifier ) {
			return;
		}

		// No screen check is possible: the provider strips the callback's query
		// string, so the return arrives on a bare wp-admin URL carrying nothing
		// but its code. The verifier above is the whole guard, which is sound
		// because it is per user, single use, and lives ten minutes.
		//
		// The cost of that is a narrow ambiguity: another plugin's OAuth
		// returning a ?code= to wp-admin while a connection of ours is in
		// flight would be picked up here. It would fail the exchange and
		// report an error, having written nothing.
		$code = self::code_from_request();
		if ( '' === $code ) {
			return; // Simply visiting Settings mid-flow is not a failed callback.
		}

		// From here a real callback is being handled, so the verifier is spent
		// whatever the outcome: a code must never be replayable.
		delete_transient( $key_tr );

		$response = wp_remote_post( self::EXCHANGE_URL, array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'code'                  => $code,
				'code_verifier'         => $verifier,
				'code_challenge_method' => 'S256',
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			self::notice( 'error', sprintf(
				/* translators: %s: error text. */
				__( 'Could not reach OpenRouter: %s', 'twt-aeo-ultimate' ),
				$response->get_error_message()
			) );
			self::finish();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$new_key = is_array( $body ) ? trim( (string) ( $body['key'] ?? '' ) ) : '';

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || '' === $new_key ) {
			self::notice( 'error', __( 'OpenRouter refused the connection. Nothing has been changed.', 'twt-aeo-ultimate' ) );
			self::finish();
		}

		// A key held in a constant or env var is the server owner's decision
		// and outranks anything stored here, so overwriting the database row
		// would write something that is never read. Say so rather than
		// pretending the connection took.
		if ( TWTAEO_Key_Resolver::is_protected( 'openrouter' ) ) {
			self::notice( 'error', sprintf(
				/* translators: %s: PHP constant name. */
				__( 'This site sets its OpenRouter key with %s, so it cannot be changed here.', 'twt-aeo-ultimate' ),
				TWTAEO_Key_Resolver::constant_name( 'openrouter' )
			) );
			self::finish();
		}

		$settings = get_option( 'twtaeo_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$settings[ self::SETTING_FIELD ] = TWTAEO_Crypt::encrypt( $new_key );
		update_option( 'twtaeo_settings', $settings );

		if ( class_exists( 'TWTAEO_Logger' ) ) {
			TWTAEO_Logger::info( 'OpenRouter connected through OAuth.', array( 'user' => $user_id ) );
		}

		self::notice( 'success', __( 'Connected to OpenRouter. The key is saved below and AI features can use it now.', 'twt-aeo-ultimate' ) );
		self::finish();
	}

	/** Disconnect: forget the stored key. The key itself lives on at OpenRouter. */
	public static function disconnect() {
		$settings = get_option( 'twtaeo_settings', array() );
		if ( is_array( $settings ) && isset( $settings[ self::SETTING_FIELD ] ) ) {
			unset( $settings[ self::SETTING_FIELD ] );
			update_option( 'twtaeo_settings', $settings );
		}
	}

	// ── Plumbing ──────────────────────────────────────────────────────────────

	/** Back to Settings with the query string cleaned of this flow's arguments. */
	private static function finish() {
		wp_safe_redirect( self::settings_url() );
		exit;
	}

	private static function notice( $type, $message ) {
		set_transient(
			self::NOTICE_PREFIX . get_current_user_id(),
			array( 'type' => $type, 'message' => $message ),
			5 * MINUTE_IN_SECONDS
		);
	}

	public static function maybe_render_notice() {
		$key    = self::NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'success' === ( $notice['type'] ?? '' ) ? 'success' : 'error',
			esc_html( $notice['message'] )
		);
	}
}
