<?php
/**
 * Runs on plugin activation.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Activator {

	public static function activate() {
		if ( false === get_option( 'twtaeo_settings' ) ) {
			add_option( 'twtaeo_settings', array(
				'version'      => TWTAEO_VERSION,
				'display_mode' => 'simple',
			) );
		}

		// Redirect to setup wizard on first activation.
		if ( ! get_option( 'twtaeo_setup_complete' ) ) {
			set_transient( 'twtaeo_redirect_to_wizard', true, 60 );
		}

		// Kick off the historical & baseline crawl in the background.
		if ( class_exists( 'TWTAEO_Baseline_Crawl' ) ) {
			TWTAEO_Baseline_Crawl::maybe_schedule();
		}
	}
}