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
				'queuedText'  => __( 'Running in background', 'twt-aeo-ultimate' ),
				'skippedText' => __( 'Skipped — handled by another plugin', 'twt-aeo-ultimate' ),
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

		// Smart Collections — the build modal is the only thing here needing JS.
		if ( strpos( $hook, TWTAEO_Page_RAG_Engine::SLUG ) !== false ) {
			wp_enqueue_script(
				'twt-aeo-documents',
				TWTAEO_PLUGIN_URL . 'admin/assets/js/documents.js',
				array(),
				TWTAEO_VERSION,
				true
			);
			$docs_status = TWTAEO_Doc_Library::status();
			wp_localize_script(
				'twt-aeo-documents',
				'twtaeoDocs',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => TWTAEO_Doc_Library::AJAX_TICK,
					'nonce'   => wp_create_nonce( TWTAEO_Doc_Library::NONCE ),
					'pending' => ! empty( $docs_status['pending'] ),
					'maxFile' => TWTAEO_Doc_Requirements::max_document_bytes(),
					'maxPost' => TWTAEO_Doc_Requirements::post_max_bytes(),
					'i18n'    => array(
						'working'       => __( 'Reading documents and indexing pages… you can keep working; this page will refresh when it is done.', 'twt-aeo-ultimate' ),
						'done'          => __( 'Done. Refreshing…', 'twt-aeo-ultimate' ),
						'failed'        => __( 'Processing stopped responding. Reload the page to try again.', 'twt-aeo-ultimate' ),
						/* translators: 1: posts done, 2: posts total. */
						'indexing'      => __( 'Indexing… %1$s of %2$s published pages and posts.', 'twt-aeo-ultimate' ),
						'confirmDelete' => __( 'Delete this document and all of its passages?', 'twt-aeo-ultimate' ),
						/* translators: 1: file name, 2: file size, 3: limit. */
						'tooBig'        => __( '"%1$s" is %2$s. This server can read documents up to %3$s in one go.', 'twt-aeo-ultimate' ),
						/* translators: 1: file name, 2: percent. */
						'uploading'     => __( 'Uploading "%1$s" — %2$s', 'twt-aeo-ultimate' ),
						/* translators: 1: file name, 2: size already on the server. */
						'resuming'      => __( 'Resuming "%1$s" from %2$s already on the server…', 'twt-aeo-ultimate' ),
						/* translators: %s: seconds. */
						'retrying'      => __( 'Connection lost — retrying in %s seconds. You can leave this page open; the upload continues where it stopped. If you close it, choose the same file again later to resume.', 'twt-aeo-ultimate' ),
						'uploaded'      => __( 'Upload complete. Reading the document now…', 'twt-aeo-ultimate' ),
						/* translators: 1: total size, 2: limit. */
						'tooBigTotal'   => __( 'Together these files are %1$s, more than your server accepts in one upload (%2$s). Upload them a few at a time.', 'twt-aeo-ultimate' ),
						'split'         => TWTAEO_Doc_Requirements::split_instructions(),
						'copied'        => __( 'Copied', 'twt-aeo-ultimate' ),
						'copyFailed'    => __( 'Select the text and copy it manually', 'twt-aeo-ultimate' ),
					),
				)
			);
		}

		if ( strpos( $hook, 'twt-aeo-collections' ) !== false ) {
			wp_enqueue_style(
				'twt-aeo-smart-collections',
				TWTAEO_PLUGIN_URL . 'admin/assets/css/smart-collections.css',
				array( 'twt-aeo-admin' ),
				TWTAEO_VERSION
			);

			wp_enqueue_script(
				'twt-aeo-smart-collections',
				TWTAEO_PLUGIN_URL . 'admin/assets/js/page-smart-collections.js',
				array( 'jquery' ),
				TWTAEO_VERSION,
				true
			);

			wp_localize_script(
				'twt-aeo-smart-collections',
				'twtaeoCollections',
				array(
					'nonce'    => wp_create_nonce( TWTAEO_Page_Smart_Collections::NONCE_DRAFT ),
					'minItems' => TWTAEO_Smart_Collections::MIN_BUILD_ITEMS,
					'i18n'     => array(
						'outOfStock'   => __( 'out of stock', 'twt-aeo-ultimate' ),
						'genericError' => __( 'Could not draft a collection for that query. Try again, or build one by hand.', 'twt-aeo-ultimate' ),
						'tooFew'       => sprintf(
							/* translators: %d: minimum number of products. */
							__( 'Pick at least %d products. A shorter list is a pair, not a collection.', 'twt-aeo-ultimate' ),
							TWTAEO_Smart_Collections::MIN_BUILD_ITEMS
						),
					),
				)
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

		// AI Visibility board — only on its page.
		if ( strpos( $hook, 'twt-aeo-ai-visibility' ) !== false ) {
			wp_enqueue_style(
				'twt-aeo-ai-visibility',
				TWTAEO_PLUGIN_URL . 'admin/assets/css/page-ai-visibility.css',
				array( 'twt-aeo-admin' ),
				TWTAEO_VERSION
			);

			wp_enqueue_script(
				'twt-aeo-ai-visibility',
				TWTAEO_PLUGIN_URL . 'admin/assets/js/page-ai-visibility.js',
				array( 'jquery' ),
				TWTAEO_VERSION,
				true
			);

			$engine_labels = array();
			foreach ( TWTAEO_Visibility_Types::ENGINES as $engine_id => $engine_meta ) {
				$engine_labels[ $engine_id ] = $engine_meta['label'];
			}
			wp_localize_script( 'twt-aeo-ai-visibility', 'twtAeoVisibility', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( TWTAEO_Visibility_Types::NONCE ),
				'actions' => array(
					'start'         => TWTAEO_Visibility_Types::AJAX_START,
					'step'          => TWTAEO_Visibility_Types::AJAX_STEP,
					'stop'          => TWTAEO_Visibility_Types::AJAX_STOP,
					'preview'       => TWTAEO_Visibility_Types::AJAX_PREVIEW,
					'save'          => TWTAEO_Visibility_Types::AJAX_SAVE,
					'demo'          => TWTAEO_Visibility_Types::AJAX_DEMO,
					'undemo'        => TWTAEO_Visibility_Types::AJAX_UNDEMO,
					'resetQ'        => TWTAEO_Visibility_Types::AJAX_RESET_Q,
					'saveQuestions' => TWTAEO_Visibility::AJAX_SAVE_Q,
					'polish'        => TWTAEO_Visibility::AJAX_POLISH,
					'saveBrands'    => TWTAEO_Visibility::AJAX_SAVE_B,
				),
				'engines' => $engine_labels,
				'strings' => array(
					'starting'   => __( 'Starting…', 'twt-aeo-ultimate' ),
					'stopping'   => __( 'Stopping after this check…', 'twt-aeo-ultimate' ),
					'stopped'    => __( 'Stopped.', 'twt-aeo-ultimate' ),
					'done'       => __( 'Run complete — loading results…', 'twt-aeo-ultimate' ),
					'last'       => __( 'last:', 'twt-aeo-ultimate' ),
					'saving'     => __( 'Saving…', 'twt-aeo-ultimate' ),
					'building'   => __( 'Building the list — reads your catalogue, spends nothing…', 'twt-aeo-ultimate' ),
					'loading'    => __( 'Loading…', 'twt-aeo-ultimate' ),
					'removing'   => __( 'Removing…', 'twt-aeo-ultimate' ),
					'retrying'   => __( 'Network hiccup — retrying…', 'twt-aeo-ultimate' ),
					'background' => __( 'The background worker is on it — waiting…', 'twt-aeo-ultimate' ),
					'polishing'  => __( 'Asking your AI provider to rephrase the post questions…', 'twt-aeo-ultimate' ),
					/* translators: %1$s: number of questions rephrased. */
					'polished'   => __( '%1$s questions rephrased — saved with source “ai”.', 'twt-aeo-ultimate' ),
					'gaveUp'     => __( 'Too many network failures in a row — the run is saved; resume it any time.', 'twt-aeo-ultimate' ),
					'error'      => __( 'Something went wrong.', 'twt-aeo-ultimate' ),
					'day'        => __( 'day', 'twt-aeo-ultimate' ),
					'days'       => __( 'days', 'twt-aeo-ultimate' ),
					'capZero'    => __( '— (cap is 0)', 'twt-aeo-ultimate' ),
					'fitsIn'     => __( 'fits in', 'twt-aeo-ultimate' ),
					/* translators: 1: enabled question count, 2: total question count. */
					'enabledOf'  => __( '%1$s of %2$s questions enabled.', 'twt-aeo-ultimate' ),
					'notSaved'   => __( 'Not saved — these would not match anything:', 'twt-aeo-ultimate' ),
				),
			) );
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