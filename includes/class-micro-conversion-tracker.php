<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TWTAEO_Micro_Conversion_Tracker {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_tracker' ) );
		add_action( 'wp_ajax_twtaeo_micro_conversion',        array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_twtaeo_micro_conversion', array( __CLASS__, 'handle' ) );
	}

	public static function enqueue_tracker() {
		if ( ! TWTAEO_Pro_Transmitter::is_connected() ) return;

		wp_enqueue_script(
			'twtaeo-micro-tracker',
			TWTAEO_PLUGIN_URL . 'public/js/twt-micro-conversion-tracker.js',
			array(),
			TWTAEO_VERSION,
			true
		);

		wp_localize_script( 'twtaeo-micro-tracker', 'twtaeoMicroTracker', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'twtaeo_micro_conversion' ),
		) );
	}

	public static function handle() {
		check_ajax_referer( 'twtaeo_micro_conversion', 'nonce' );

		$allowed = array( 'email_click', 'phone_tap' );
		$event   = sanitize_key( wp_unslash( $_POST['event_type'] ?? '' ) );
		$page    = esc_url_raw( wp_unslash( $_POST['page_url']   ?? '' ) );
		$device  = sanitize_key( wp_unslash( $_POST['device']    ?? 'desktop' ) );

		if ( ! in_array( $event, $allowed, true ) ) {
			wp_send_json_error( 'invalid_event' );
		}

		TWTAEO_Pro_Transmitter::send_micro_conversion( $event, $page, $device );
		wp_send_json_success();
	}
}
