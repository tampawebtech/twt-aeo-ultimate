<?php
/**
 * Plugin Name:       TWT AEO Ultimate
 * Plugin URI:        https://tampawebtech.com/twt-aeo-ultimate
 * Description:       Schema markup, structured data & llms.txt for SEO and AI search — FAQ, Product, Local Business & Article JSON-LD, plus E-E-A-T author signals and AI crawler controls.
 * Version:           2.29.0
 * Author:            Tampa Web Technologies
 * Author URI:        https://tampawebtech.com
 * License:           GPL-2.0+
 * Text Domain:       twt-aeo-ultimate
 * Requires at least: 6.2
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ⚠️ AEO Ultimate for WooCommerce gates its take-over offer on this string
// (`AEOWC_Free_Plugin_Bridge::HANDSHAKE_SINCE`), because a copy without
// TWTAEO_Commerce_Handoff cannot stand down however the merchant answers. 2.13.0 is
// the release that added it — do not lower this, and keep the two in step.
define( 'TWTAEO_VERSION',    '2.29.0' );
define( 'TWTAEO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TWTAEO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load the logger first and arm the fatal-error catcher so crashes anywhere in
// the load chain (including the requires below) are captured for Diagnostics.
require_once TWTAEO_PLUGIN_DIR . 'includes/class-logger.php';
TWTAEO_Logger::init();

require_once TWTAEO_PLUGIN_DIR . 'includes/class-crypt.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-key-resolver.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-activator.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-review-notice.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-roles.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-seo-compatibility.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-page-intent.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-scan-store.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-aeo-score.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-bot-view.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-ai-referrals.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-funnel-audit.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-schema-autofill.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-background-scan.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-baseline-crawl.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-baseline-metrics.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-meta-inventory.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-data-handshake.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-module-loader.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-scan-pager.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-faq-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-plugin.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/class-admin-menu.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/class-admin-assets.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/class-metabox.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-dashboard.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-dashboard-v2.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-not-indexed.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-modules.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-settings.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-diagnostics.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-faq-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-schema-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-service-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-service-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-author-box-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-author-box.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-author.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-woocommerce-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-woocommerce-detector.php';
// Commerce feature set ported from AEO Ultimate for WooCommerce (2.14.0):
// extension-aware pricing, product FAQ assembly, Smart Collections, and
// Promotions & Price Rules. All WooCommerce-gated internally.
require_once TWTAEO_PLUGIN_DIR . 'includes/class-wc-extensions.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-product-faq.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-product-faq-generator.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-buyer-intent.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-smart-collections.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-collection-schema-writer.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-smart-collections.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-coupon-rules.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-price-tiers.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-offer-pricing.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-dynamic-pricing.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-pricing-refresh.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-promotions-feed.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-promotions.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-contact-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-contact-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-service-schema-writer.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-contact-schema-writer.php';
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
require_once TWTAEO_PLUGIN_DIR . 'includes/class-ai-models.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-ai-client.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-product-enricher.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-image-optimizer.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-image-seo.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-hub-cpt.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/class-hub-metabox.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/Integrations/class-google-service-account.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/Integrations/class-google-oauth.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/Integrations/class-google-knowledge-graph.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/Integrations/class-google-pagespeed.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-traffic-leak-scanner.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-perf-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/Integrations/class-google-merchant-center.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/Integrations/class-bing-merchant-center.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/Integrations/class-bing-webmaster.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-gmc-sync-engine.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-bmc-sync-engine.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-merchant-sync-queue.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-command-center.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-html-to-markdown.php';
// Content Gap Engine. Host Profile first: every other piece gates on it, and it
// is what keeps a $6/month shared account from being taken down by a work loop.
require_once TWTAEO_PLUGIN_DIR . 'includes/class-host-profile.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-chunker.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-chunk-view.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-content-index.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-doc-requirements.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-pdf-tables.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-doc-extractor.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-doc-library.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-doc-profile.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-law-check.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-rag-signals.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-crawler-tester.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-schema-conflict-detector.php';
// Lets a merchant running AEO Ultimate for WooCommerce choose which plugin owns a
// shared surface. Inert — and unreachable — without that plugin installed.
// Required before the writers that consult it.
require_once TWTAEO_PLUGIN_DIR . 'includes/class-commerce-handoff.php';
// The merchant's own switches for anything this plugin writes that something else
// might also write. Everything defaults to on, so this changes nothing until used.
// Must load before every writer, because each one asks it before registering.
require_once TWTAEO_PLUGIN_DIR . 'includes/class-output-control.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-ai-ready.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-oauth-server.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-ai-ready.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-bot-view.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-chunk-view.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-documents.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-rag-engine.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-funnel-audit.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-schema-conflicts.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-pro-transmitter.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-micro-conversion-tracker.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-ai-crawler-logger.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-rate-limiter.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-smart-404.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-lost-pages.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-kg-entities.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-multilingual.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-content-provenance.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-knowledge-graph.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-knowledge-graph.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-smart-404.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-index-heuristics.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-index-status.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-auto-index-scan.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-connector-rest.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-abilities.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-local-pack.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-local-pack-nap.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-local-pack.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-og-detector.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-og-writer.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-twitter-writer.php';
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
require_once TWTAEO_PLUGIN_DIR . 'includes/class-article-schema-writer.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-ai-description.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-news.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-reviews-cpt.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-reviews-schema-writer.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-reviews-shortcode.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-reviews.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-setup-wizard.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-autopilot.php';
// AI Visibility — citation engine. Pure logic first (types → questions →
// verdict → sample), then the WP-facing halves (http → engines → inputs →
// budget → store → observed), then the module class and its board page.
require_once TWTAEO_PLUGIN_DIR . 'includes/ai-visibility/class-visibility-types.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/ai-visibility/class-visibility-questions.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/ai-visibility/class-visibility-verdict.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/ai-visibility/class-visibility-brands.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/ai-visibility/class-visibility-sample.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/ai-visibility/class-visibility-http.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/ai-visibility/class-visibility-engines.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/ai-visibility/class-visibility-inputs.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/ai-visibility/class-visibility-budget.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/ai-visibility/class-visibility-store.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/ai-visibility/class-visibility-personas.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/ai-visibility/class-visibility-location.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/ai-visibility/class-visibility-export.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/ai-visibility/class-visibility-observed.php';
require_once TWTAEO_PLUGIN_DIR . 'includes/class-visibility.php';
require_once TWTAEO_PLUGIN_DIR . 'admin/pages/class-page-ai-visibility.php';

register_activation_hook( __FILE__, array( 'TWTAEO_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TWTAEO_Deactivator', 'deactivate' ) );

function twtaeo_run() {
	$GLOBALS['twtaeo_plugin'] = new TWTAEO_Plugin();
	$GLOBALS['twtaeo_plugin']->run();
	TWTAEO_Metabox::register_hooks();
	TWTAEO_OAuth_Server::init();
	TWTAEO_Pro_Transmitter::init();
	TWTAEO_Micro_Conversion_Tracker::init();
	TWTAEO_Baseline_Crawl::init();
	TWTAEO_Data_Handshake::init();
	TWTAEO_Merchant_Sync_Queue::init();
	TWTAEO_Auto_Index_Scan::init();
	TWTAEO_Bot_View::init();
	TWTAEO_AI_Referrals::init();
	TWTAEO_Funnel_Audit::init();
	TWTAEO_Connector_Rest::init();
	TWTAEO_Abilities::register_hooks();
	TWTAEO_Background_Scan::init();

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