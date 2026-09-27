<?php
/**
 * Review Nudge.
 *
 * After the plugin has been installed for a week, shows a friendly, dismissible
 * banner on the plugin's own admin screens asking for a WordPress.org review.
 * Persistent: "leave a review" and "already did" hide it for good; "maybe later"
 * snoozes it for another week. Only ever shown to admins, only on our pages.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Review_Notice {

	const OPTION_INSTALLED = 'twtaeo_installed_at';
	const OPTION_DISMISSED = 'twtaeo_review_dismissed';
	const DELAY_DAYS       = 7;
	const REVIEW_URL       = 'https://wordpress.org/support/plugin/twt-aeo-ultimate/reviews/#new-post';

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

	/** Handle the banner's action links (review / dismiss / later). */
	public static function handle_action() {
		if ( ! isset( $_GET['twtaeo_review_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! check_admin_referer( 'twtaeo_review' ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_GET['twtaeo_review_action'] ) );

		if ( 'later' === $action ) {
			// Restart the clock — resurface in another week.
			update_option( self::OPTION_INSTALLED, time(), false );
		} else {
			// 'review' or 'dismiss' — stop nudging for good.
			update_option( self::OPTION_DISMISSED, 1, false );
		}

		if ( 'review' === $action ) {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Intentional external redirect to the plugin's wordpress.org reviews page (trusted constant); wp_safe_redirect would block the off-site host.
			wp_redirect( self::REVIEW_URL );
			exit;
		}
		wp_safe_redirect( remove_query_arg( array( 'twtaeo_review_action', '_wpnonce' ) ) );
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
				add_query_arg( 'twtaeo_review_action', $action ),
				'twtaeo_review'
			);
		};

		echo '<div class="notice notice-info is-dismissible twtaeo-review-notice"><p>';
		echo '<strong>' . esc_html__( 'Enjoying TWT AEO Ultimate?', 'twt-aeo-ultimate' ) . '</strong> ';
		echo esc_html__( 'You\'ve been using it for a week now. If it\'s helped your site get found by AI search, a quick review would mean a lot — and helps other agencies discover it.', 'twt-aeo-ultimate' );
		echo '</p><p>';
		echo '<a href="' . esc_url( $link( 'review' ) ) . '" class="button button-primary">'
			. esc_html__( '★ Leave a review', 'twt-aeo-ultimate' ) . '</a> &nbsp; ';
		echo '<a href="' . esc_url( $link( 'dismiss' ) ) . '">'
			. esc_html__( 'I already did', 'twt-aeo-ultimate' ) . '</a> &nbsp;&middot;&nbsp; ';
		echo '<a href="' . esc_url( $link( 'later' ) ) . '">'
			. esc_html__( 'Maybe later', 'twt-aeo-ultimate' ) . '</a>';
		echo '</p></div>';
	}
}
