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
		// Placeholder — add flush_rewrite_rules(), wp_clear_scheduled_hook(), etc. here as needed.
	}
}
