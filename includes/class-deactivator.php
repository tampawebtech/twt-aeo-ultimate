<?php
/**
 * Runs on plugin deactivation.
 *
 * Deactivation is not uninstallation — keep persistent data intact.
 * Teardown of cron jobs or transients would go here when added.
 *
 * @package TWTAEO_Connector
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Deactivator {

	/**
	 * Fires when the plugin is deactivated via the Plugins screen.
	 *
	 * Intentionally lightweight: options and data are preserved so
	 * reactivation restores the previous state without reconfiguration.
	 */
	public static function deactivate() {
		// Stop the baseline crawl cron; its progress/state option is preserved.
		if ( class_exists( 'TWTAEO_Baseline_Crawl' ) ) {
			TWTAEO_Baseline_Crawl::clear_schedule();
		}

		// Stop the data handshake cron; last-handshake record is preserved.
		if ( class_exists( 'TWTAEO_Data_Handshake' ) ) {
			TWTAEO_Data_Handshake::clear_schedule();
		}

		// Stop the auto index scan crons; fingerprint/results are preserved.
		if ( class_exists( 'TWTAEO_Auto_Index_Scan' ) ) {
			TWTAEO_Auto_Index_Scan::clear_schedule();
		}

		// Stop the background scan cron; its queue/state option is preserved.
		if ( class_exists( 'TWTAEO_Background_Scan' ) ) {
			TWTAEO_Background_Scan::clear_schedule();
		}
	}
}
