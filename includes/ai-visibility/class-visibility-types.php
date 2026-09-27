<?php
/**
 * AI Visibility — the shared contract (constants and shapes).
 *
 * Ported from the Shopify app's `aiVisibilityTypes.ts` (AEO Ultimate,
 * 2026-08-18). One file both halves of the module read, so the pure logic,
 * the server half, the page and the tests agree on one vocabulary.
 *
 * Shapes are plain associative arrays (PHP 7.4, no enums):
 *
 *   Question  = [ id, level, scope_id, scope_label, family, text, source, truth|null, enabled ]
 *   Fact      = [ kind, statement ]
 *   Check     = [ question_id, engine, at, verdict, accuracy, cited_urls[], our_urls[], domains[], excerpt, latency_ms, error|null, model, tools ]
 *   Allocation= [ company, per_collection, per_type, per_product, per_post, engines[] ]
 *   Run       = [ id, started_at, finished_at|null, allocation, questions[], queue[ [question_id, engine] ], cursor, checks[], status, message|null, demo ]
 *   RunSummary= [ id, started_at, finished_at|null, status, demo, checks, cited_pct, named_pct, wrong, by_engine{engine: pct|null} ]
 *   ShareOfVoice = [ engine, checks, with_citations, our_cited_pct, slices[ [domain, count, ours] ] ]
 *
 * Wording rule for everything that touches a merchant: presence and citations,
 * never "rank". The "API approximates the consumer app" caveat is load-bearing.
 *
 * @package TWT_AEO_Ultimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TWTAEO_Visibility_Types {

	/** Engine ids, what merchants call them, and which settings key holds the API key. */
	const ENGINES = array(
		'chatgpt'    => array( 'label' => 'ChatGPT',           'key' => 'openai' ),
		'gemini'     => array( 'label' => 'Gemini',            'key' => 'gemini' ),
		'claude'     => array( 'label' => 'Claude',            'key' => 'claude' ),
		'perplexity' => array( 'label' => 'Perplexity',        'key' => 'perplexity' ),
		'grok'       => array( 'label' => 'Grok (X)',          'key' => 'xai' ),
		'mistral'    => array( 'label' => 'Le Chat (Mistral)', 'key' => 'mistral' ),
	);

	/** Engines that ship in this build; an engine joins when its Settings key field exists. */
	const ENGINES_V1 = array( 'chatgpt', 'gemini', 'claude', 'perplexity', 'grok', 'mistral' );

	/**
	 * Probing models mirror each CONSUMER APP'S DEFAULT model -- what a shopper's
	 * phone runs -- not the strongest. Researched 2026-08-18; re-check whenever
	 * an app changes its default. Question generation and the accuracy judge are
	 * different jobs and stay on the cheap tier (TWTAEO_AI_Client).
	 */
	const MODELS = array(
		'claude'     => 'claude-sonnet-5',      // Claude.ai Free+Pro default since 2026-06-30
		'chatgpt'    => 'gpt-5.6-luna',         // ChatGPT Free/Go default since 2026-08-06 (bare gpt-5.6 = Sol, NOT Luna)
		'gemini'     => 'gemini-3.6-flash',     // Gemini app default since 2026-07-21
		'perplexity' => 'fast',                 // Agent API preset: Perplexity's documented stand-in for 'sonar' (web search on). Sonar API retired 2026-09-27.
		'grok'       => 'grok-4.6',             // live-verified 2026-08-20: answers + url_citation annotations came back on a real xAI key
		'mistral'    => 'mistral-medium-latest',
	);

	/** Claude's web-search server tool variant for Sonnet 5 / 4.6+. Haiku would need web_search_20250305. */
	const CLAUDE_SEARCH_TOOL = 'web_search_20260209';

	/** Where a question comes from. `post` is the WordPress addition (informational family). */
	const LEVELS = array( 'company', 'brand', 'collection', 'type', 'product', 'post' );

	const LEVEL_LABEL = array(
		'company'    => 'Company',
		'brand'      => 'Brand',        // a tracked brand item, asked about in its own right
		'collection' => 'Category',     // WooCommerce product categories play the Shopify "collection" role
		'type'       => 'Product type',
		'product'    => 'Product',
		'post'       => 'Post',
	);

	/** Verdict vocabulary -- cited beats named beats absent; unavailable = no answer recorded. */
	const VERDICTS = array( 'cited', 'named', 'absent', 'unavailable' );

	/**
	 * WHERE a citation of ours landed. A citation is a citation either way -- the
	 * headline counts both -- but a site whose own domain never gets picked up is
	 * looking at a different problem from one that is cited directly, so the two
	 * are never merged away. '' = we were not cited at all.
	 */
	const CITATION_SURFACES = array( '', 'site', 'profile', 'both' );

	const SURFACE_LABEL = array(
		'site'    => 'Your site',
		'profile' => 'Owned profile',
		'both'    => 'Site + profile',
	);

	/** Accuracy against a known fact; n/a when the question carries no fact or the check was unavailable. */
	const ACCURACIES = array( 'correct', 'wrong', 'not_stated', 'n/a' );

	/** Fact kinds the accuracy judge understands. */
	const FACT_KINDS = array( 'policy_refund', 'policy_shipping', 'ships_to', 'variant_option', 'price', 'availability', 'post_claim' );

	/**
	 * Allocation presets (owner's numbers: "minimum five, we want a lot, it's cheap").
	 * `per_post` is new here; Shopify had no posts.
	 */
	const PRESETS = array(
		'light'    => array( 'company' => 8,  'per_brand' => 4,  'per_collection' => 3, 'per_type' => 3, 'per_product' => 1, 'per_post' => 1 ),
		'standard' => array( 'company' => 15, 'per_brand' => 8,  'per_collection' => 5, 'per_type' => 5, 'per_product' => 3, 'per_post' => 2 ),
		'deep'     => array( 'company' => 25, 'per_brand' => 12, 'per_collection' => 8, 'per_type' => 8, 'per_product' => 6, 'per_post' => 4 ),
	);

	/** Slider ceilings -- the bar must not scroll indefinitely either. */
	const ALLOCATION_MAX = array( 'company' => 40, 'per_brand' => 20, 'per_collection' => 12, 'per_type' => 12, 'per_product' => 10, 'per_post' => 8 );

	/** Per-day cap on checks (count, not money) -- same semantics as the Shopify `visibility` budget. */
	const DEFAULT_DAILY_CAP = 1500;

	/** Runs kept per site (free plugin: one tier, keep the larger number). */
	const RUNS_KEPT = 12;

	/** Sample run for "Load sample data" -- seeded from the site's REAL products/posts. */
	const DEMO_ALLOCATION = array( 'company' => 12, 'per_brand' => 6, 'per_collection' => 5, 'per_type' => 5, 'per_product' => 2, 'per_post' => 3 );

	/**
	 * The tracking parameter each assistant appends to links it hands a shopper.
	 * ChatGPT's is documented (`utm_source=chatgpt.com`); Perplexity tags
	 * `utm_source=perplexity`. Gemini and Claude add nothing today.
	 */
	const REFERRAL_UTM = array(
		'chatgpt'    => 'utm_source=chatgpt.com',
		'perplexity' => 'utm_source=perplexity',
		'gemini'     => '',
		'claude'     => '',
		'grok'       => '',
		'mistral'    => '',
	);

	/** Referrer hosts that mean "an AI assistant sent this visit" (observed layer, GA4 sessionSource). */
	const AI_REFERRER_HOSTS = array(
		'chatgpt.com'           => 'chatgpt',
		'chat.openai.com'       => 'chatgpt',
		'openai.com'            => 'chatgpt',
		'perplexity.ai'         => 'perplexity',
		'www.perplexity.ai'     => 'perplexity',
		'gemini.google.com'     => 'gemini',
		'bard.google.com'       => 'gemini',
		'claude.ai'             => 'claude',
		'copilot.microsoft.com' => 'copilot',
		'bing.com'              => 'copilot',
		'www.bing.com'          => 'copilot',
		'x.com'                 => 'grok',
		'grok.com'              => 'grok',
		'chat.mistral.ai'       => 'mistral',
		'mistral.ai'            => 'mistral',
		'you.com'               => 'you',
		'duckduckgo.com'        => 'duckduckgo',
		'meta.ai'               => 'meta',
	);

	/** Option / table names, in one place. */
	const OPTION_SETTINGS   = 'twtaeo_visibility_settings';   // allocation, engines, daily_cap, x_handle, alternate_hosts, company_urls, brand_items
	const OPTION_QUESTIONS  = 'twtaeo_visibility_questions';  // merchant-edited question set (enabled flags)
	const OPTION_SPEND      = 'twtaeo_visibility_spend';      // [ 'YYYY-MM-DD' => n ]
	const OPTION_DB_VERSION = 'twtaeo_visibility_db_version';
	const DB_VERSION        = '3';   // 2: checks carry owned_domains / citation_surface / brand_hits; 3: + mentions (where the answer said the name)
	const TABLE_RUNS        = 'twtaeo_visibility_runs';       // prefixed with $wpdb->prefix
	const TABLE_CHECKS      = 'twtaeo_visibility_checks';

	/** AJAX action + nonce names. */
	const NONCE        = 'twtaeo_visibility';
	const AJAX_START   = 'twtaeo_visibility_start';
	const AJAX_STEP    = 'twtaeo_visibility_step';
	const AJAX_STOP    = 'twtaeo_visibility_stop';
	const AJAX_PREVIEW = 'twtaeo_visibility_preview';
	const AJAX_SAVE    = 'twtaeo_visibility_save';
	const AJAX_DEMO    = 'twtaeo_visibility_demo';     // load sample data
	const AJAX_UNDEMO  = 'twtaeo_visibility_undemo';   // remove sample data
	const AJAX_RESET_Q = 'twtaeo_visibility_reset_questions';

	/** Menu slug for the board page. */
	const PAGE_SLUG = 'twt-aeo-ai-visibility';

	/** Module slug in the registry. */
	const MODULE_SLUG = 'ai-visibility';

	private function __construct() {}
}
