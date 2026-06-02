<?php
/**
 * Crawler Visibility Tester
 *
 * Simulates AI bot requests to the site homepage by spoofing User-Agent headers
 * via wp_remote_get, then maps the HTTP response code to a human-readable status
 * and a targeted fix recommendation.
 *
 * If a bot request fails, a second "neutral" Chrome UA request is fired automatically.
 * When the neutral UA passes but the bot UA fails, the result is flagged as
 * confirmed User-Agent Blocking rather than a generic connection error.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Crawler_Tester {

	const NONCE = 'twtaeo_crawler_test';

	/**
	 * Neutral Chrome UA used for the UA-shuffle confirmation test.
	 */
	const NEUTRAL_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

	/**
	 * 2026 AI bot user-agents to test.
	 */
	private static $bots = array(
		array(
			'name'  => 'GPTBot (OpenAI)',
			'short' => 'GPTBot',
			'ua'    => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)',
		),
		array(
			'name'  => 'ClaudeBot (Anthropic)',
			'short' => 'ClaudeBot',
			'ua'    => 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +https://www.anthropic.com/claudebot)',
		),
		array(
			'name'  => 'PerplexityBot',
			'short' => 'PerplexityBot',
			'ua'    => 'Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://www.perplexity.ai/proximity)',
		),
		array(
			'name'  => 'Applebot-Extended (Apple)',
			'short' => 'Applebot',
			'ua'    => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15 (Applebot-Extended/1.0)',
		),
	);

	/**
	 * Test a single bot user-agent against the site homepage.
	 *
	 * Returns:
	 *   code         int     HTTP status code (0 = WP_Error / connection failed)
	 *   status       string  Human-readable status label
	 *   label        string  'success' | 'warning' | 'error'
	 *   fix          string  Targeted recommendation
	 *   transport    string  'cURL' | 'Streams' — whichever WordPress would use
	 *   error        string  WP_Error message if the request itself failed
	 *   ua_blocked   bool    true when neutral UA passes but bot UA fails
	 *   neutral_code int     HTTP code returned by the neutral Chrome UA retry
	 *
	 * @param string $bot_user_agent
	 * @return array
	 */
	public static function test_crawler_access( $bot_user_agent ) {
		$transport = extension_loaded( 'curl' ) ? 'cURL' : 'Streams';
		$args      = array(
			'timeout'    => 20,
			'sslverify'  => false,
			'user-agent' => $bot_user_agent,
			'headers'    => array(
				'Accept' => 'text/html,application/xhtml+xml',
			),
		);

		$response = wp_remote_get( get_home_url(), $args );

		// ── Bot request failed entirely (WP_Error) ────────────────────────────
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();

			// UA shuffle: retry with neutral Chrome UA to distinguish between
			// a server-side UA block and a genuine connection / SSL problem.
			$neutral_response = wp_remote_get( get_home_url(), array_merge( $args, array(
				'user-agent' => self::NEUTRAL_UA,
			) ) );

			$neutral_code = is_wp_error( $neutral_response )
				? 0
				: (int) wp_remote_retrieve_response_code( $neutral_response );

			$ua_blocked = ( ! is_wp_error( $neutral_response ) && $neutral_code >= 200 && $neutral_code < 400 );

			if ( $ua_blocked ) {
				$analysis = array(
					'status' => 'UA Blocked',
					'label'  => 'error',
					'fix'    => 'The server accepted a standard Chrome browser but rejected the bot User-Agent. This is deliberate User-Agent blocking. Check your WAF, Cloudflare Bot Fight Mode, Wordfence, or server-level block lists and add an allow rule for this bot\'s user-agent string.',
				);
			} else {
				$analysis = self::analyze( 0 );
			}

			$analysis['code']         = 0;
			$analysis['transport']    = $transport;
			$analysis['error']        = $error_message;
			$analysis['ua_blocked']   = $ua_blocked;
			$analysis['neutral_code'] = $neutral_code;
			return $analysis;
		}

		// ── Bot request returned an HTTP error code ───────────────────────────
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 400 ) {
			// UA shuffle: check if a neutral UA would have succeeded.
			$neutral_response = wp_remote_get( get_home_url(), array_merge( $args, array(
				'user-agent' => self::NEUTRAL_UA,
			) ) );

			$neutral_code = is_wp_error( $neutral_response )
				? 0
				: (int) wp_remote_retrieve_response_code( $neutral_response );

			$ua_blocked = ( ! is_wp_error( $neutral_response ) && $neutral_code >= 200 && $neutral_code < 400 );

			$analysis = self::analyze( $code );

			// Strengthen the fix message when UA blocking is confirmed.
			if ( $ua_blocked ) {
				$analysis['status'] = 'UA Blocked (' . $code . ')';
				$analysis['label']  = 'error';
				$analysis['fix']    = 'Confirmed User-Agent Blocking — the server returned ' . $code . ' for this bot but accepted a standard Chrome browser. Add an allow rule for this bot\'s user-agent in your WAF, Cloudflare, or Wordfence settings.';
			}

			$analysis['code']         = $code;
			$analysis['transport']    = $transport;
			$analysis['error']        = '';
			$analysis['ua_blocked']   = $ua_blocked;
			$analysis['neutral_code'] = $neutral_code;
			return $analysis;
		}

		// ── Bot request succeeded ─────────────────────────────────────────────
		$analysis                   = self::analyze( $code );
		$analysis['code']           = $code;
		$analysis['transport']      = $transport;
		$analysis['error']          = '';
		$analysis['ua_blocked']     = false;
		$analysis['neutral_code']   = null;
		return $analysis;
	}

	/**
	 * Run all bot tests and return results.
	 *
	 * @return array[]
	 */
	public static function run_all_tests() {
		$results = array();
		foreach ( self::$bots as $bot ) {
			$result          = self::test_crawler_access( $bot['ua'] );
			$result['name']  = $bot['name'];
			$result['short'] = $bot['short'];
			$result['ua']    = $bot['ua'];
			$results[]       = $result;
		}
		return $results;
	}

	/**
	 * Map an HTTP status code to a status label and fix recommendation.
	 *
	 * @param int $code
	 * @return array { status, label, fix }
	 */
	private static function analyze( $code ) {
		switch ( true ) {
			case ( $code >= 200 && $code < 400 ):
				return array(
					'status' => 'Accessible',
					'label'  => 'success',
					'fix'    => '',
				);

			case ( $code === 401 ):
				return array(
					'status' => 'Auth Required',
					'label'  => 'warning',
					'fix'    => 'HTTP Basic Auth or a staging lock is blocking this bot. Remove password protection for your homepage, or whitelist the bot\'s IP range in your server or host control panel.',
				);

			case ( $code === 403 ):
				return array(
					'status' => 'Firewall Block',
					'label'  => 'error',
					'fix'    => 'A WAF or Cloudflare Bot Fight Mode is blocking this crawler. In Cloudflare: Security → Bots → turn off Bot Fight Mode, or add a WAF bypass rule matching the bot\'s user-agent string. Also check Wordfence or similar firewall plugins.',
				);

			case ( $code === 429 ):
				return array(
					'status' => 'Rate Limited',
					'label'  => 'warning',
					'fix'    => 'Your server or hosting provider is rate-limiting this bot. Common on managed WordPress hosts (WP Engine, Kinsta, Flywheel). Contact your host to whitelist AI crawler IP ranges, or raise your rate-limit threshold in the host\'s control panel.',
				);

			case ( $code === 503 ):
				return array(
					'status' => 'Service Unavailable',
					'label'  => 'error',
					'fix'    => 'A maintenance mode plugin, Cloudflare "Under Attack" mode, or server-side redirect loop is serving a 503. Disable maintenance mode and check Cloudflare Security Level.',
				);

			case ( $code === 0 ):
				return array(
					'status' => 'Connection Failed',
					'label'  => 'error',
					'fix'    => 'The loopback HTTP request failed entirely. Some managed hosts block internal wp_remote_get calls. Check if loopback requests are enabled (WP Site Health → Loopback Requests) or test from an external tool.',
				);

			default:
				return array(
					'status' => 'HTTP ' . $code,
					'label'  => 'warning',
					'fix'    => 'Unexpected response code ' . $code . '. Check your server error logs. If you see a redirect chain (3xx), verify it resolves cleanly and does not loop.',
				);
		}
	}
}
