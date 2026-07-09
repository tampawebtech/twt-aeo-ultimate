<?php
/**
 * Admin Assets
 *
 * Enqueues CSS and JS only on TWT AEO plugin pages.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Admin_Assets {

	public static function enqueue( $hook ) {
		// Load on all TWT AEO admin pages (top-level and all subpages).
		if ( strpos( $hook, 'twt-aeo' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'twt-aeo-admin',
			TWTAEO_PLUGIN_URL . 'admin/assets/css/admin.css',
			array(),
			TWTAEO_VERSION
		);

		wp_enqueue_script(
			'twt-aeo-admin',
			TWTAEO_PLUGIN_URL . 'admin/assets/js/admin.js',
			array( 'jquery', 'wp-i18n' ),
			TWTAEO_VERSION,
			true
		);

		wp_localize_script( 'twt-aeo-admin', 'twtAeo', array(
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'twtaeo_modules_nonce' ),
			'schemaPreviewText' => __( 'Schema Preview', 'twt-aeo-ultimate' ),
			'hidePreviewText'   => __( 'Hide Preview', 'twt-aeo-ultimate' ),
		) );
		wp_set_script_translations( 'twt-aeo-admin', 'twt-aeo-ultimate', TWTAEO_PLUGIN_DIR . 'languages' );

		// Client-Side Abilities — Command Palette + WP 7.0 Abilities API.
		// Registered on every TWT AEO admin page so they're available site-wide.
		$active_modules = get_option( TWTAEO_Module_Loader::OPTION_KEY, array() );
		wp_enqueue_script(
			'twt-aeo-abilities',
			TWTAEO_PLUGIN_URL . 'admin/assets/js/abilities.js',
			array( 'wp-data', 'wp-commands', 'wp-i18n' ),
			TWTAEO_VERSION,
			true
		);
		wp_localize_script( 'twt-aeo-abilities', 'twtAeoAbilities', array(
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'indexNowEnabled' => (
				in_array( 'index-now', $active_modules, true ) &&
				! empty( get_option( TWTAEO_Index_Now::OPTION_KEY, '' ) )
			),
			'nonces' => array(
				'schemaConflict' => wp_create_nonce( TWTAEO_Schema_Conflict_Detector::NONCE ),
				'indexNow'       => wp_create_nonce( TWTAEO_Page_Index_Now::NONCE_AJAX ),
			),
			'adminUrls' => array(
				'crawlerWatch'    => admin_url( 'admin.php?page=twt-aeo-command-center&twtaeo_tab=ai-crawler' ),
				'crawlabilityAudit' => admin_url( 'admin.php?page=twt-aeo-command-center&twtaeo_tab=crawlability' ),
			),
		) );

		// Setup Wizard assets — only on the wizard page.
		if ( strpos( $hook, 'twt-aeo-setup-wizard' ) !== false ) {
			wp_enqueue_style(
				'twt-aeo-setup-wizard',
				TWTAEO_PLUGIN_URL . 'admin/assets/css/setup-wizard.css',
				array(),
				TWTAEO_VERSION
			);

			wp_enqueue_script(
				'twt-aeo-setup-wizard',
				TWTAEO_PLUGIN_URL . 'admin/assets/js/setup-wizard.js',
				array(),
				TWTAEO_VERSION,
				true
			);

			wp_localize_script( 'twt-aeo-setup-wizard', 'twtAeoWizard', array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'runningText' => __( 'Running…', 'twt-aeo-ultimate' ),
				'doneText'    => __( 'Done', 'twt-aeo-ultimate' ),
				'errorText'   => __( 'Error', 'twt-aeo-ultimate' ),
				'retryText'   => __( 'Retry', 'twt-aeo-ultimate' ),
			) );
		}

		// PR Bridge assets — only on the Media Report admin page.
		if ( strpos( $hook, 'twt-aeo-pr-bridge' ) !== false ) {
			wp_enqueue_style(
				'twt-aeo-pr-bridge',
				TWTAEO_PLUGIN_URL . 'admin/assets/css/pr-bridge.css',
				array( 'twt-aeo-admin' ),
				TWTAEO_VERSION
			);
		}

		// Settings page — AI provider API-key row styles.
		if ( strpos( $hook, 'twt-aeo-settings' ) !== false ) {
			wp_enqueue_style(
				'twt-aeo-settings-api-keys',
				TWTAEO_PLUGIN_URL . 'admin/assets/css/settings-api-keys.css',
				array( 'twt-aeo-admin' ),
				TWTAEO_VERSION
			);
		}

		// Modules page — Pro Plugins promo-grid styles.
		if ( strpos( $hook, 'twt-aeo-modules' ) !== false ) {
			wp_enqueue_style(
				'twt-aeo-modules',
				TWTAEO_PLUGIN_URL . 'admin/assets/css/modules.css',
				array( 'twt-aeo-admin' ),
				TWTAEO_VERSION
			);
		}

		// Social Graph page styles.
		if ( strpos( $hook, 'twt-aeo-social-graph' ) !== false ) {
			wp_enqueue_style(
				'twt-aeo-social-graph',
				TWTAEO_PLUGIN_URL . 'admin/assets/css/social-graph.css',
				array( 'twt-aeo-admin' ),
				TWTAEO_VERSION
			);
		}

		// Schema Detector page — conflict-scan tab styles.
		if ( strpos( $hook, 'twt-aeo-schema-detector' ) !== false ) {
			wp_enqueue_style(
				'twt-aeo-schema-conflicts',
				TWTAEO_PLUGIN_URL . 'admin/assets/css/schema-conflicts.css',
				array( 'twt-aeo-admin' ),
				TWTAEO_VERSION
			);
		}

		// AI Ready assets — only on the AI Ready page.
		if ( strpos( $hook, 'twt-aeo-ai-ready' ) !== false ) {
			wp_enqueue_style(
				'twt-aeo-ai-ready',
				TWTAEO_PLUGIN_URL . 'admin/assets/css/ai-ready.css',
				array( 'twt-aeo-admin' ),
				TWTAEO_VERSION
			);

			wp_enqueue_script(
				'twt-aeo-ai-ready',
				TWTAEO_PLUGIN_URL . 'admin/assets/js/ai-ready.js',
				array( 'jquery', 'wp-i18n' ),
				TWTAEO_VERSION,
				true
			);
			wp_set_script_translations( 'twt-aeo-ai-ready', 'twt-aeo-ultimate', TWTAEO_PLUGIN_DIR . 'languages' );
		}

		// Command Center assets — only on the command center page.
		if ( strpos( $hook, 'twt-aeo-command-center' ) !== false ) {
			wp_enqueue_style(
				'twt-aeo-command-center',
				TWTAEO_PLUGIN_URL . 'admin/assets/css/command-center.css',
				array( 'twt-aeo-admin' ),
				TWTAEO_VERSION
			);

			wp_enqueue_script(
				'twt-aeo-command-center',
				TWTAEO_PLUGIN_URL . 'admin/assets/js/command-center.js',
				array( 'jquery', 'twt-aeo-admin', 'wp-i18n' ),
				TWTAEO_VERSION,
				true
			);

			wp_localize_script( 'twt-aeo-command-center', 'twtAeoCC', array(
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'copyText'          => __( 'Copy', 'twt-aeo-ultimate' ),
				'copiedText'        => __( 'Copied!', 'twt-aeo-ultimate' ),
				'disconnectText'    => __( 'Disconnect', 'twt-aeo-ultimate' ),
				'disconnectingText' => __( 'Disconnecting…', 'twt-aeo-ultimate' ),
				'disconnectConfirm' => __( 'Disconnect {service}? Your stored credentials will be removed.', 'twt-aeo-ultimate' ),
			) );
			wp_set_script_translations( 'twt-aeo-command-center', 'twt-aeo-ultimate', TWTAEO_PLUGIN_DIR . 'languages' );

			// DataViews live-feed component (WP 7.0+, degrades gracefully on older WP).
			// `wp-dataviews` isn't registered on every WP build, so only declare it as
			// a dependency when present — otherwise WP 6.9.1+ throws a _doing_it_wrong
			// notice for the unregistered handle.
			$dataviews_deps = array( 'wp-element', 'wp-i18n' );
			if ( wp_script_is( 'wp-dataviews', 'registered' ) ) {
				$dataviews_deps[] = 'wp-dataviews';
			}
			wp_enqueue_script(
				'twt-aeo-crawler-dataviews',
				TWTAEO_PLUGIN_URL . 'admin/assets/js/crawler-dataviews.js',
				$dataviews_deps,
				TWTAEO_VERSION,
				true
			);
			wp_set_script_translations( 'twt-aeo-crawler-dataviews', 'twt-aeo-ultimate', TWTAEO_PLUGIN_DIR . 'languages' );
		}
	}
}