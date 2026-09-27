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
				'version'          => TWTAEO_VERSION,
				'display_mode'     => 'simple',
				// A fresh install has no older model to keep — start on the current defaults.
				'ai_models_pinned' => 1,
			) );
		}

		// Stamp first-install time for the 7-day review nudge (never overwrite).
		if ( false === get_option( 'twtaeo_installed_at' ) ) {
			add_option( 'twtaeo_installed_at', time(), '', false );
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