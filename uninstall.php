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

/**
 * Remove all plugin data. Wrapped in a function so its working variables stay
 * out of the global scope.
 */
function twtaeo_uninstall_cleanup() {
	global $wpdb;

	// ── Options ──────────────────────────────────────────────────────────────
	$options = array(
		// Core plugin settings & module state.
		'twtaeo_settings',
		'twtaeo_installed_at',
		'twtaeo_review_dismissed',
		'twtaeo_help_offer_dismissed',
		'twtaeo_active_modules',

		// E-E-A-T / EEAT detector.
		'twtaeo_eeat_scan',

		// Google OAuth / Search Console.
		'twtaeo_google_creds',
		'twtaeo_google_tokens',
		'twtaeo_google_config',
		'twtaeo_google_oauth_state',
		'twtaeo_google_sa_key',

		// Bing Webmaster.
		'twtaeo_bing_creds',

		// Merchant sync queue state.
		'twtaeo_msync_state_gmc',
		'twtaeo_msync_state_bmc',
		'twtaeo_gmc_last_sync',
		'twtaeo_bmc_last_sync',

		// Auto index scan state.
		'twtaeo_auto_scan_queue',
		'twtaeo_deindex_fingerprint',
		'twtaeo_auto_scan_last',
		'twtaeo_index_scan',

		// Background scan state.
		'twtaeo_bg_scan_state',

		// AEO Score history.
		'twtaeo_score_history',
	);

	foreach ( $options as $twtaeo_option ) {
		delete_option( $twtaeo_option );
	}

	delete_transient( 'twtaeo_score_summary' );

	// ── Merchant feed staging table ──────────────────────────────────────────
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}twtaeo_merchant_feed" );

	// ── Content Gap Engine: documents, passages, and anything still waiting ─
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}twtaeo_cge_chunks" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}twtaeo_cge_docs" );
	foreach ( array( 'twtaeo_cge_db_version', 'twtaeo_cge_site_index', 'twtaeo_host_profile', 'twtaeo_cge_breaker', 'twtaeo_cge_disabled', 'twtaeo_chunk_view_cache' ) as $twtaeo_option ) {
		delete_option( $twtaeo_option );
	}
	wp_clear_scheduled_hook( 'twtaeo_cge_docs_tick' );

	// Uploaded files still waiting to be read (processed ones are already gone).
	$twtaeo_uploads = wp_upload_dir( null, false );
	$twtaeo_doc_dir = trailingslashit( $twtaeo_uploads['basedir'] ) . 'twtaeo-docs';
	if ( is_dir( $twtaeo_doc_dir ) ) {
		foreach ( (array) scandir( $twtaeo_doc_dir ) as $twtaeo_file ) {
			$twtaeo_path = $twtaeo_doc_dir . '/' . $twtaeo_file;
			if ( is_file( $twtaeo_path ) ) {
				wp_delete_file( $twtaeo_path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own, now empty, folder.
		rmdir( $twtaeo_doc_dir );
	}

	// ── Pending merchant sync jobs ───────────────────────────────────────────
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'twt-aeo-merchant-sync' );
	}

	// ── Post meta ────────────────────────────────────────────────────────────
	// Delete all post meta rows whose key starts with '_twtaeo_' — covers every
	// meta key written by the plugin; safe because the prefix is unique to us.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_twtaeo\_%'" );

	// ── User meta ────────────────────────────────────────────────────────────
	// Author Entity profile fields stored per-user.
	$user_meta_keys = array(
		'twtaeo_job_title',
		'twtaeo_credentials',
		'twtaeo_expertise',
		'twtaeo_years_experience',
		'twtaeo_social',
		'twtaeo_certifications',
		// RAG Engine: each user's last answer to the document AI-label consent box.
		'twtaeo_cge_ai_consent',
	);

	foreach ( $user_meta_keys as $twtaeo_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $twtaeo_key ) );
	}

	// Clean up any remaining user meta with our prefix.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'twtaeo\_%'" );
}

twtaeo_uninstall_cleanup();
