<?php
/**
 * Help offer.
 *
 * A few days after install, a friendly, dismissible banner on the plugin's own
 * admin screens offers help in the WordPress.org support forum. It used to ask
 * for a review; a new plugin gets more from real support threads (and the
 * fixes that come out of them) than from a handful of early reviews.
 * "Ask a question" and "All good" hide it for good; "Maybe later" snoozes it
 * for another week. Only ever shown to admins, only on our pages.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Review_Notice {

	const OPTION_INSTALLED = 'twtaeo_installed_at';
	/** New key: sites that hid the old review request still see the help offer once. */
	const OPTION_DISMISSED = 'twtaeo_help_offer_dismissed';
	const DELAY_DAYS       = 3;
	const SUPPORT_URL      = 'https://wordpress.org/support/plugin/twt-aeo-ultimate/#new-topic-0';

	public static function register_hooks() {
		add_action( 'admin_init', array( __CLASS__, 'record_install' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render' ) );
	}

	/** Stamp the install time once — a fallback for installs predating this option. */
	public static function record_install() {
		if ( ! get_option( self::OPTION_INSTALLED ) ) {
			add_option( self::OPTION_INSTALLED, time(), '', false );
		}
	}

	/** Handle the banner's action links (ask / dismiss / later). */
	public static function handle_action() {
		if ( ! isset( $_GET['twtaeo_help_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! check_admin_referer( 'twtaeo_help' ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_GET['twtaeo_help_action'] ) );

		if ( 'later' === $action ) {
			// Restart the clock — resurface in another week.
			update_option( self::OPTION_INSTALLED, time() + ( 7 - self::DELAY_DAYS ) * DAY_IN_SECONDS, false );
		} else {
			// 'ask' or 'dismiss' — stop offering for good.
			update_option( self::OPTION_DISMISSED, 1, false );
		}

		if ( 'ask' === $action ) {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Intentional external redirect to the plugin's wordpress.org support forum (trusted constant); wp_safe_redirect would block the off-site host.
			wp_redirect( self::SUPPORT_URL );
			exit;
		}
		wp_safe_redirect( remove_query_arg( array( 'twtaeo_help_action', '_wpnonce' ) ) );
		exit;
	}

	public static function maybe_render() {
		if ( ! current_user_can( 'manage_options' ) || get_option( self::OPTION_DISMISSED ) ) {
			return;
		}
		// Only on the plugin's own screens — engaged users, no site-wide nagging.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of the current admin screen slug; changes no state.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, 'twt-aeo' ) ) {
			return;
		}
		$installed = (int) get_option( self::OPTION_INSTALLED );
		if ( ! $installed || ( time() - $installed ) < self::DELAY_DAYS * DAY_IN_SECONDS ) {
			return;
		}

		$link = function ( $action ) {
			return wp_nonce_url(
				add_query_arg( 'twtaeo_help_action', $action ),
				'twtaeo_help'
			);
		};

		echo '<div class="notice notice-info is-dismissible twtaeo-review-notice"><p>';
		echo '<strong>' . esc_html__( 'Need a hand with TWT AEO Ultimate?', 'twt-aeo-ultimate' ) . '</strong> ';
		echo esc_html__( 'Stuck on a setting, seeing something odd, or wishing it did one more thing? Ask in the support forum. We read and answer every thread, and a lot of what the plugin does today started as someone\'s question.', 'twt-aeo-ultimate' );
		echo '</p><p>';
		echo '<a href="' . esc_url( $link( 'ask' ) ) . '" class="button button-primary">'
			. esc_html__( 'Ask a question', 'twt-aeo-ultimate' ) . '</a> &nbsp; ';
		echo '<a href="' . esc_url( $link( 'dismiss' ) ) . '">'
			. esc_html__( 'All good, thanks', 'twt-aeo-ultimate' ) . '</a> &nbsp;&middot;&nbsp; ';
		echo '<a href="' . esc_url( $link( 'later' ) ) . '">'
			. esc_html__( 'Maybe later', 'twt-aeo-ultimate' ) . '</a>';
		echo '</p></div>';
	}
}
