<?php
/**
 * AI Visibility — the ONE guarded outbound helper.
 *
 * Every probing call to an assistant goes through `post()`. It exists so a new
 * adapter cannot quietly skip the three rules that protect the merchant:
 *
 *  1. FIXED allow-list of provider hosts. The URL is built from constants, but
 *     the list is still checked here so a typo or a future "configurable
 *     endpoint" cannot steer a merchant's key at an arbitrary host.
 *  2. The merchant's key is REDACTED from every error string. Gemini carries
 *     the key in the query string and Google echoes the request URL in some
 *     error bodies; other providers echo headers on 401. Nothing that leaves
 *     this class may contain the secret.
 *  3. Never throws; a network failure becomes `error`.
 *
 * Timing: one grounded call with Sonnet 5 thinking can take 20–40 s, so the
 * HTTP timeout is 40 s and a run is advanced ONE check per request. The
 * site's PHP `max_execution_time` therefore has to allow ~45 s for the AJAX
 * step endpoint; the step handler should call `set_time_limit( 60 )` where the
 * host permits it. No redirects are followed (a provider API never redirects a
 * POST, and following one could move the key to another host).
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility_Http {

	/** Seconds one provider call may take. See the docblock before lowering. */
	const TIMEOUT = 40;

	/** Hosts a probing request may be sent to. Anything else is refused before the request is built. */
	const ALLOWED_HOSTS = array(
		'api.anthropic.com',
		'api.openai.com',
		'generativelanguage.googleapis.com',
		'api.perplexity.ai',
		'api.x.ai',
		'api.mistral.ai',
	);

	/** Longest provider error detail we keep (keys are already gone by then). */
	const DETAIL_MAX = 300;

	private function __construct() {}

	/**
	 * POST JSON to a provider.
	 *
	 * @param string $url    Absolute https URL on an allow-listed host.
	 * @param array  $args   wp_remote_post args: `headers` (array), `body` (string|array — arrays are JSON-encoded).
	 * @param string $secret The merchant's key, so it can be scrubbed from anything we return.
	 * @return array { ok: bool, status: int, body: array|null, raw: string, error: string }
	 */
	public static function post( $url, array $args, $secret = '' ) {
		$url    = (string) $url;
		$secret = (string) $secret;
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		if ( 'https' !== $scheme || ! in_array( $host, self::ALLOWED_HOSTS, true ) ) {
			return self::result( false, 0, null, '', self::redact( 'Refused: ' . ( $host ? $host : '(no host)' ) . ' is not an allowed provider host.', $secret ) );
		}

		$body = isset( $args['body'] ) ? $args['body'] : '';
		if ( is_array( $body ) || is_object( $body ) ) {
			$body = wp_json_encode( $body );
		}
		$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		if ( ! isset( $headers['content-type'] ) && ! isset( $headers['Content-Type'] ) ) {
			$headers['content-type'] = 'application/json';
		}

		$request = array(
			'method'      => 'POST',
			'timeout'     => isset( $args['timeout'] ) ? (int) $args['timeout'] : self::TIMEOUT,
			'redirection' => 0,
			'headers'     => $headers,
			'body'        => (string) $body,
			'user-agent'  => 'TWT-AEO-Ultimate/' . ( defined( 'TWTAEO_VERSION' ) ? TWTAEO_VERSION : '0' ) . '; ' . home_url( '/' ),
		);

		try {
			$response = wp_remote_post( $url, $request );
		} catch ( \Throwable $e ) {
			return self::result( false, 0, null, '', self::redact( $e->getMessage(), $secret ) );
		}

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			if ( false !== stripos( $msg, 'timed out' ) || false !== stripos( $msg, 'timeout' ) ) {
				/* translators: %d: seconds */
				$msg = sprintf( __( 'No answer within %d s.', 'twt-aeo-ultimate' ), $request['timeout'] );
			}
			return self::result( false, 0, null, '', self::redact( $msg, $secret ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$raw    = self::redact( $raw, $secret );

		if ( $status < 200 || $status >= 300 ) {
			$detail = self::provider_detail( $raw, $secret );
			return self::result( false, $status, null, $raw, 'HTTP ' . $status . ( '' !== $detail ? ': ' . $detail : '' ) );
		}

		$decoded = '' !== $raw ? json_decode( $raw, true ) : array();
		if ( ! is_array( $decoded ) ) {
			return self::result( false, $status, null, $raw, __( 'The provider returned something that was not JSON.', 'twt-aeo-ultimate' ) );
		}

		return self::result( true, $status, $decoded, $raw, '' );
	}

	/**
	 * Scrub a secret from a string. Both passes always run: the literal key, and
	 * the generic credential patterns (Bearer tokens, `key=` pairs) in case a
	 * provider echoed a DIFFERENT token than the one we sent.
	 *
	 * TWTAEO_Logger::redact() is private in the shipped logger, so the same two
	 * patterns are mirrored here rather than reached through reflection.
	 *
	 * @param string $text
	 * @param string $secret
	 * @return string
	 */
	public static function redact( $text, $secret = '' ) {
		$text   = (string) $text;
		$secret = (string) $secret;
		if ( '' !== $secret ) {
			$text = str_replace( $secret, '[your key]', $text );
			$enc  = rawurlencode( $secret );
			if ( $enc !== $secret ) {
				$text = str_replace( $enc, '[your key]', $text );
			}
		}
		$text = preg_replace(
			'/(?<![\w-])(api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|secret|password|passwd|pwd|auth|token|signature|key)([=:]\s*["\']?)[^\s"\'&]+/i',
			'$1$2[redacted]',
			$text
		);
		$text = preg_replace( '/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[redacted]', (string) $text );
		return null === $text ? '' : $text;
	}

	/**
	 * The provider's own account of what went wrong, one line, capped.
	 *
	 * @param string $raw    Response body (already redacted).
	 * @param string $secret
	 * @return string
	 */
	public static function provider_detail( $raw, $secret = '' ) {
		$message = $raw;
		$body    = json_decode( (string) $raw, true );
		if ( is_array( $body ) ) {
			if ( isset( $body['error']['message'] ) ) {
				$message = $body['error']['message'];
			} elseif ( isset( $body['error']['error']['message'] ) ) {
				$message = $body['error']['error']['message'];
			} elseif ( isset( $body['message'] ) ) {
				$message = $body['message'];
			} elseif ( isset( $body['detail'] ) ) {
				$message = $body['detail'];
			} elseif ( isset( $body['error'] ) ) {
				$message = $body['error'];
			}
		}
		if ( ! is_scalar( $message ) ) {
			$message = wp_json_encode( $message );
		}
		$text = trim( preg_replace( '/\s+/', ' ', (string) $message ) );
		$text = self::redact( $text, $secret );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, self::DETAIL_MAX );
		}
		return substr( $text, 0, self::DETAIL_MAX );
	}

	private static function result( $ok, $status, $body, $raw, $error ) {
		return array(
			'ok'     => (bool) $ok,
			'status' => (int) $status,
			'body'   => $body,
			'raw'    => (string) $raw,
			'error'  => (string) $error,
		);
	}
}
