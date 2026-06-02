<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TWTAEO_Rate_Limiter {

	const OPTION_SETTINGS    = 'twtaeo_rate_limiter_settings';
	const OPTION_BLOCKED_LOG = 'twtaeo_rate_limiter_blocked_log';
	const MAX_LOG_ENTRIES    = 200;

	// Transient key prefix — one counter per bot slug per window.
	const TRANSIENT_PREFIX = 'twtaeo_rl_';

	// ── Hook Registration ─────────────────────────────────────────────────────

	public static function register_hooks() {
		add_filter( 'wp_ai_client_prevent_prompt', array( __CLASS__, 'intercept_ai_prompt' ), 10, 2 );
	}

	// ── Core Intercept ────────────────────────────────────────────────────────

	/**
	 * Hooked to wp_ai_client_prevent_prompt.
	 * Return true to abort the outbound LLM call; false to allow it.
	 *
	 * @param bool                      $prevent Current prevention flag.
	 * @param WP_AI_Client_Prompt_Builder $builder Prompt builder instance.
	 * @return bool
	 */
	public static function intercept_ai_prompt( $prevent, $builder ) {
		// Already blocked upstream — don't overwrite.
		if ( $prevent ) {
			return true;
		}

		$bot       = self::get_current_bot_signature();
		$ip        = self::get_user_ip();
		$ability   = method_exists( $builder, 'get_ability_name' ) ? $builder->get_ability_name() : 'unknown';

		if ( self::is_bot_blocked( $bot ) ) {
			self::log_blocked_visit( $bot, $ip, $ability, 'explicit_block' );
			return true;
		}

		if ( self::is_rate_limited( $bot, $ip ) ) {
			self::log_blocked_visit( $bot, $ip, $ability, 'rate_limit' );
			return true;
		}

		// Increment the window counter for this bot.
		self::increment_counter( $bot, $ip );

		return false;
	}

	// ── Bot / IP Detection ────────────────────────────────────────────────────

	/**
	 * Return the first matching known-bot key from the UA string, or the
	 * anonymised IP hash when no known bot is detected.
	 */
	public static function get_current_bot_signature() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		$known_bots = array(
			'GPTBot', 'ChatGPT-User', 'OAI-SearchBot',
			'ClaudeBot', 'Claude-Web', 'anthropic-ai',
			'Google-Extended', 'Googlebot',
			'PerplexityBot', 'YouBot', 'Applebot-Extended',
			'Bytespider', 'FacebookBot', 'meta-externalagent',
			'CCBot', 'Amazonbot', 'Diffbot', 'cohere-ai',
		);

		foreach ( $known_bots as $bot ) {
			if ( stripos( $ua, $bot ) !== false ) {
				return $bot;
			}
		}

		// Unknown agent — key on hashed IP so unknowns are still rate-limited.
		return 'unknown:' . md5( self::get_user_ip() );
	}

	public static function get_user_ip() {
		$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}

	// ── Block / Rate-limit Checks ─────────────────────────────────────────────

	public static function is_bot_blocked( $bot_signature ) {
		$settings = self::get_settings();
		$blocked  = $settings['blocked_bots'] ?? array();
		return in_array( $bot_signature, $blocked, true );
	}

	/**
	 * Returns true when the combined (bot + ip) counter has exceeded the
	 * configured threshold within the rolling time window.
	 */
	public static function is_rate_limited( $bot_signature, $ip ) {
		$settings  = self::get_settings();
		$threshold = absint( $settings['requests_per_window'] ?? 60 );
		$window    = absint( $settings['window_seconds']      ?? 60 );

		if ( $threshold === 0 ) {
			return false; // 0 = disabled
		}

		$key   = self::transient_key( $bot_signature, $ip, $window );
		$count = (int) get_transient( $key );

		return $count >= $threshold;
	}

	// ── Counter ───────────────────────────────────────────────────────────────

	private static function increment_counter( $bot_signature, $ip ) {
		$settings = self::get_settings();
		$window   = absint( $settings['window_seconds'] ?? 60 );
		$key      = self::transient_key( $bot_signature, $ip, $window );

		$count = (int) get_transient( $key );
		if ( $count === 0 ) {
			set_transient( $key, 1, $window );
		} else {
			// set_transient resets TTL; use a dedicated atomic approach via
			// option so the window stays anchored to the first request.
			set_transient( $key, $count + 1, $window );
		}
	}

	private static function transient_key( $bot_signature, $ip, $window ) {
		// Bucket IP into the same window slot as the bot so the counter is
		// per-bot-per-IP rather than global, keeping legit bots from blocking
		// each other.
		$slot = (int) ( time() / max( 1, $window ) );
		return self::TRANSIENT_PREFIX . md5( $bot_signature . $ip . $slot );
	}

	// ── Blocked Visit Log ─────────────────────────────────────────────────────

	public static function log_blocked_visit( $bot, $ip, $ability, $reason ) {
		$log   = get_option( self::OPTION_BLOCKED_LOG, array() );
		$entry = array(
			'bot'     => sanitize_text_field( $bot ),
			'ip'      => sanitize_text_field( $ip ),
			'ability' => sanitize_text_field( $ability ),
			'reason'  => $reason, // 'explicit_block' | 'rate_limit'
			'time'    => time(),
		);

		array_unshift( $log, $entry );
		if ( count( $log ) > self::MAX_LOG_ENTRIES ) {
			$log = array_slice( $log, 0, self::MAX_LOG_ENTRIES );
		}

		update_option( self::OPTION_BLOCKED_LOG, $log, false );
	}

	public static function get_blocked_log( $limit = 50 ) {
		return array_slice( get_option( self::OPTION_BLOCKED_LOG, array() ), 0, $limit );
	}

	public static function clear_blocked_log() {
		delete_option( self::OPTION_BLOCKED_LOG );
	}

	// ── Settings ──────────────────────────────────────────────────────────────

	public static function get_settings() {
		return wp_parse_args(
			get_option( self::OPTION_SETTINGS, array() ),
			array(
				'requests_per_window' => 60,   // max requests before block
				'window_seconds'      => 60,   // rolling window length in seconds
				'blocked_bots'        => array(), // explicitly blocked bot slugs
			)
		);
	}

	public static function save_settings( array $raw ) {
		$settings = array(
			'requests_per_window' => max( 0, absint( $raw['requests_per_window'] ?? 60 ) ),
			'window_seconds'      => max( 10, absint( $raw['window_seconds']      ?? 60 ) ),
			'blocked_bots'        => array_map( 'sanitize_text_field', (array) ( $raw['blocked_bots'] ?? array() ) ),
		);
		update_option( self::OPTION_SETTINGS, $settings );
	}
}
