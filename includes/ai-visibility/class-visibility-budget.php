<?php
/**
 * AI Visibility — the per-day check cap.
 *
 * Counts CHECKS, not money — provider prices vary and we never show a dollar
 * figure. A run pauses (does not fail) when today's cap is reached and resumes
 * tomorrow or when the merchant raises the cap. Same semantics as the Shopify
 * app's `visibility` budget kind.
 *
 * Storage: OPTION_SPEND = [ 'YYYY-MM-DD' => n ], last 3 days kept, not
 * autoloaded. "Today" is the site's timezone (`current_time`).
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility_Budget {

	const DAYS_KEPT = 3;

	private function __construct() {}

	/** Today's cap: the saved setting, else the default. Never below 1. */
	public static function cap() {
		$settings = get_option( TWTAEO_Visibility_Types::OPTION_SETTINGS, array() );
		$cap      = is_array( $settings ) && isset( $settings['daily_cap'] ) ? (int) $settings['daily_cap'] : 0;
		if ( $cap <= 0 ) {
			$cap = (int) TWTAEO_Visibility_Types::DEFAULT_DAILY_CAP;
		}
		return max( 1, $cap );
	}

	/** @return array { used, cap, allowed, day } */
	public static function check() {
		$day  = self::today();
		$used = self::used_on( $day );
		$cap  = self::cap();
		return array(
			'used'    => $used,
			'cap'     => $cap,
			'allowed' => $used < $cap,
			'day'     => $day,
		);
	}

	/** Count checks against today. */
	public static function record( $n = 1 ) {
		$n = max( 0, (int) $n );
		if ( 0 === $n ) {
			return;
		}
		$day   = self::today();
		$spend = self::read();
		$spend[ $day ] = ( isset( $spend[ $day ] ) ? (int) $spend[ $day ] : 0 ) + $n;
		self::write( $spend );
	}

	/** How many checks today so far. */
	public static function used_today() {
		return self::used_on( self::today() );
	}

	/**
	 * @param array $state From check().
	 * @return string
	 */
	public static function paused_message( array $state ) {
		$cap = isset( $state['cap'] ) ? (int) $state['cap'] : self::cap();
		return sprintf(
			/* translators: %s: number of checks */
			__( 'Paused at today\'s cap of %s checks — resumes tomorrow; raise the cap in settings.', 'twt-aeo-ultimate' ),
			number_format_i18n( $cap )
		);
	}

	/* ─────────────────────────── internals ─────────────────────────── */

	private static function today() {
		return (string) current_time( 'Y-m-d' );
	}

	private static function used_on( $day ) {
		$spend = self::read();
		return isset( $spend[ $day ] ) ? max( 0, (int) $spend[ $day ] ) : 0;
	}

	private static function read() {
		$spend = get_option( TWTAEO_Visibility_Types::OPTION_SPEND, array() );
		return is_array( $spend ) ? $spend : array();
	}

	private static function write( array $spend ) {
		// Keep the last DAYS_KEPT calendar days only.
		$keep  = array();
		$today = self::today();
		$floor = gmdate( 'Y-m-d', strtotime( $today . ' -' . ( self::DAYS_KEPT - 1 ) . ' days' ) );
		foreach ( $spend as $day => $n ) {
			$day = (string) $day;
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) && $day >= $floor && $day <= $today ) {
				$keep[ $day ] = (int) $n;
			}
		}
		ksort( $keep );
		if ( false === get_option( TWTAEO_Visibility_Types::OPTION_SPEND, false ) ) {
			add_option( TWTAEO_Visibility_Types::OPTION_SPEND, $keep, '', 'no' );
		} else {
			update_option( TWTAEO_Visibility_Types::OPTION_SPEND, $keep, false );
		}
	}
}
