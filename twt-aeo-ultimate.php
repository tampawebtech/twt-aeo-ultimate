<?php
/**
 * Plugin Name:       TWT AEO Ultimate
 * Plugin URI:        https://tampawebtech.com/twt-aeo-ultimate
 * Description:       Monitor AI search visibility, track AEO changes, and connect your site to TWT Agency. Install on each client site.
 * Version:           2.1.1
 * Author:            Tampa Web Technologies
 * Author URI:        https://tampawebtech.com
 * License:           GPL-2.0+
 * Text Domain:       twt-aeo-ultimate
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TWTAEO_VERSION',    '2.1.1' );
define( 'TWTAEO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TWTAEO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once TWTAEO_PLUGIN_DIR . 'includes/class-key-resolver.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-activator.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-seo-compatibility.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-page-intent.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-scan-store.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-module-loader.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-faq-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-plugin.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/class-admin-menu.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/class-admin-assets.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/class-metabox.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-dashboard.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-not-indexed.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-modules.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-settings.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-faq-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-schema-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-service-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-service-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-author-box-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-author-box.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-author.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-woocommerce-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-woocommerce-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-contact-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-contact-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-service-schema-writer.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-custom-schema-writer.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-author-meta.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-author-schema-writer.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-author-hover.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-author-entity.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-company-profile.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-company-og-writer.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-company-schema-writer.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-company.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-pr-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-pr-transformer.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-pr-distributor.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/class-pr-metabox.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-pr-bridge.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-industry-config.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-content-generator.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-hub-cpt.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/class-hub-metabox.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-content-generator.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/Integrations/class-google-oauth.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/Integrations/class-google-merchant-center.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/Integrations/class-bing-merchant-center.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/Integrations/class-bing-webmaster.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-gmc-sync-engine.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-bmc-sync-engine.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-command-center.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-html-to-markdown.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-crawler-tester.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-schema-conflict-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-ai-ready.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-oauth-server.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-ai-ready.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-schema-conflicts.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-pro-transmitter.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-rest-remote-generate.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-micro-conversion-tracker.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-ai-crawler-logger.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-rate-limiter.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-index-heuristics.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-index-status.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-local-pack.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-local-pack-nap.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-local-pack.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-og-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-og-writer.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-twitter-writer.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-og-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-social-graph.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-index-now.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-index-now.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-eeat-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-last-updated-badge.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-eeat.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-sitemap-generator.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-sitemap.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-news-sitemap.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-news-meta.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-news-schema-writer.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-news.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-reviews-cpt.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-reviews-schema-writer.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-reviews-shortcode.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-reviews.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-setup-wizard.php';

register_activation_hook( __FILE__, array( 'TWTAEO_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TWTAEO_Deactivator', 'deactivate' ) );

function twtaeo_run() {
	$GLOBALS['twtaeo_plugin'] = new TWTAEO_Plugin();
	$GLOBALS['twtaeo_plugin']->run();
	TWTAEO_Metabox::register_hooks();
	TWTAEO_OAuth_Server::init();
	TWTAEO_Pro_Transmitter::init();
	TWTAEO_Rest_Remote_Generate::init();
	TWTAEO_Micro_Conversion_Tracker::init();

	// Company profile — OG tags (priority 1) and schema (priority 5).
	add_action( 'init', function () {
		TWTAEO_Company_OG_Writer::register_hooks();
		TWTAEO_Company_Schema_Writer::register_hooks();
	} );

	// Enqueue wp.media on the Company Profile page and the E-E-A-T page (which embeds Company Info as a tab).
	add_action( 'admin_enqueue_scripts', function ( $hook ) {
		if ( strpos( $hook, 'twt-aeo-company' ) !== false || strpos( $hook, 'twt-aeo-eeat' ) !== false ) {
			wp_enqueue_media();
		}
	} );
}
twtaeo_run();