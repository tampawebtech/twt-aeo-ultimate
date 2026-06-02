<?php
/**
 * Plugin Uninstall
 *
 * Runs automatically when the user clicks "Delete" on the Plugins page.
 * Removes ALL data created by TWT AEO Ultimate:
 *   - Plugin options (settings, module state, integrations)
 *   - Post meta (scan results, schema, PR Bridge, OG tags)
 *   - User meta (author entity profile fields)
 *
 * @package TWTAEO_Connector
 */

// WordPress sets this constant before calling uninstall.php.
// Exit immediately if called directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ── Options ───────────────────────────────────────────────────────────────────

$options = array(
	// Core plugin settings & module state.
	'twtaeo_settings',
	'twtaeo_active_modules',

	// E-E-A-T / EEAT detector.
	'twtaeo_eeat_scan',

	// Google OAuth / Search Console.
	'twtaeo_google_creds',
	'twtaeo_google_tokens',
	'twtaeo_google_config',
	'twtaeo_google_oauth_state',

	// Bing Webmaster.
	'twtaeo_bing_creds',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// ── Post meta ─────────────────────────────────────────────────────────────────
// Delete all post meta rows whose key starts with '_twtaeo_'.
// This covers every meta key written by the plugin without needing to list
// each one individually — safe because the prefix is unique to this plugin.

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_twtaeo\_%'"
);

// ── User meta ─────────────────────────────────────────────────────────────────
// Author Entity profile fields stored per-user.

$user_meta_keys = array(
	'twtaeo_job_title',
	'twtaeo_credentials',
	'twtaeo_expertise',
	'twtaeo_years_experience',
	'twtaeo_social',
	'twtaeo_certifications',
);

foreach ( $user_meta_keys as $key ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $key ) );
}

// Clean up any remaining user meta with our prefix.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'twtaeo\_%'"
);
