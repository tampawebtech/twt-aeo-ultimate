<?php
/**
 * AI Referral Logger
 *
 * Counts human visits that arrive from AI answer engines — ChatGPT,
 * Perplexity, Claude, Gemini, Copilot — detected via the HTTP referrer or
 * the utm_source ChatGPT appends to outbound links. This is the plugin's
 * first-party stand-in for GA4's sessionSource filter: no external API,
 * no cookies, no per-visitor data. Counts are aggregated per day, per path,
 * per engine in a single option and pruned after 60 days.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_AI_Referrals {

	const OPTION_LOG = 'twtaeo_ai_referrals';
	const KEEP_DAYS  = 60;

	/**
	 * Referrer / utm_source fragments that identify an AI answer engine.
	 *
	 * @var array<string,string> substring => engine label.
	 */
	private static $engines = array(
		'chatgpt.com'          => 'ChatGPT',
		'chat.openai.com'      => 'ChatGPT',
		'perplexity.ai'        => 'Perplexity',
		'claude.ai'            => 'Claude',
		'gemini.google.com'    => 'Gemini',
		'copilot.microsoft.com' => 'Copilot',
		'you.com'              => 'You.com',
		'phind.com'            => 'Phind',
		'mistral.ai'           => 'Le Chat',
		'chat.mistral.ai'      => 'Le Chat',
		'chat.deepseek.com'    => 'DeepSeek',
		'deepseek.com'         => 'DeepSeek',
		'meta.ai'              => 'Meta AI',
	);

	/**
	 * Hook up front-end detection.
	 */
	public static function init() {
		add_action( 'wp', array( __CLASS__, 'detect_and_log' ) );
	}

	/**
	 * Log the current front-end request when it was referred by an AI engine.
	 */
	public static function detect_and_log() {
		if ( is_admin() || is_feed() || is_robots() || is_user_logged_in() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// Bots are handled by the AI Crawler Watch — this logger counts people.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
		if ( '' === $ua || preg_match( '/bot|crawl|spider|slurp|preview|fetch/i', $ua ) ) {
			return;
		}

		$engine = self::match_engine( isset( $_SERVER['HTTP_REFERER'] ) ? (string) $_SERVER['HTTP_REFERER'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- matched against a fixed list.

		// ChatGPT links carry utm_source=chatgpt.com even when the referrer is stripped.
		if ( ! $engine && isset( $_GET['utm_source'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only analytics.
			$engine = self::match_engine( sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( ! $engine ) {
			return;
		}

		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '/';
		$path = $path ? $path : '/';
		$day  = current_time( 'Y-m-d' );

		$log = get_option( self::OPTION_LOG, array() );

		if ( ! isset( $log[ $day ] ) ) {
			$log[ $day ] = array();
			$log         = self::prune( $log );
		}
		if ( ! isset( $log[ $day ][ $path ] ) ) {
			$log[ $day ][ $path ] = array();
		}
		$log[ $day ][ $path ][ $engine ] = ( isset( $log[ $day ][ $path ][ $engine ] ) ? (int) $log[ $day ][ $path ][ $engine ] : 0 ) + 1;

		update_option( self::OPTION_LOG, $log, false );
	}

	/**
	 * Match a referrer/utm value against the engine list.
	 *
	 * @param string $value Referrer URL or utm_source value.
	 * @return string Engine label or empty string.
	 */
	private static function match_engine( $value ) {
		if ( '' === $value ) {
			return '';
		}
		$value = strtolower( $value );
		foreach ( self::$engines as $needle => $label ) {
			if ( false !== strpos( $value, $needle ) ) {
				return $label;
			}
		}
		return '';
	}

	/**
	 * Referral counts per path over the last N days.
	 *
	 * @param int $days Window in days.
	 * @return array<string,array{total:int,engines:array<string,int>}> keyed by path.
	 */
	public static function get_counts( $days = 30 ) {
		$log    = get_option( self::OPTION_LOG, array() );
		$cutoff = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );
		$out    = array();

		foreach ( $log as $day => $paths ) {
			if ( $day < $cutoff || ! is_array( $paths ) ) {
				continue;
			}
			foreach ( $paths as $path => $engines ) {
				if ( ! isset( $out[ $path ] ) ) {
					$out[ $path ] = array(
						'total'   => 0,
						'engines' => array(),
					);
				}
				foreach ( (array) $engines as $engine => $count ) {
					$out[ $path ]['total']              += (int) $count;
					$out[ $path ]['engines'][ $engine ] = ( isset( $out[ $path ]['engines'][ $engine ] ) ? $out[ $path ]['engines'][ $engine ] : 0 ) + (int) $count;
				}
			}
		}

		return $out;
	}

	/**
	 * Drop days older than the retention window.
	 *
	 * @param array $log Full log.
	 * @return array
	 */
	private static function prune( $log ) {
		$cutoff = gmdate( 'Y-m-d', time() - ( self::KEEP_DAYS * DAY_IN_SECONDS ) );
		foreach ( array_keys( $log ) as $day ) {
			if ( $day < $cutoff ) {
				unset( $log[ $day ] );
			}
		}
		return $log;
	}
}
