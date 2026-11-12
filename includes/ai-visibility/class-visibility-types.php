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
 *   Check     = [ question_id, engine, at, verdict, accuracy, cited_urls[], our_urls[], domains[], excerpt, answer, latency_ms, error|null, model, tools, follow_ups[], follow_ups_error|null ]
 *   Allocation= [ company, per_collection, per_type, per_product, per_post, engines[] ]
 *   Persona   = [ id, label, source ]   source: owner (typed) | site (suggested from the site's own pages)
 *   Run       = [ id, started_at, finished_at|null, allocation, questions[], queue[ [question_id, engine, turn?] ], cursor, checks[], journey[], status, message|null, demo, persona|null ]
 *
 * Journey mode: `checks` hold the first answer to each question (turn 1) and
 * are all the board, the summaries, the trends and the RAG engine count.
 * Later turns — the engine's own top follow-up, asked in the same
 * conversation — live in `journey` (Check + turn + asked) and are shown
 * beside them, never mixed in, so a run with journeys still compares with
 * one without.
 *   RunSummary= [ id, started_at, finished_at|null, status, demo, checks, cited_pct, named_pct, wrong, by_engine{engine: pct|null}, persona|null ]
 *
 * A run is asked AS one persona or as none (the baseline) -- never a mix -- so
 * every number on the board, and every trend, compares like with like.
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
		'deepseek'   => array( 'label' => 'DeepSeek',          'key' => 'deepseek' ),
		'meta'       => array( 'label' => 'Muse (Meta AI)',    'key' => 'meta' ),
	);

	/** Engines that ship in this build; an engine joins when its Settings key field exists. */
	const ENGINES_V1 = array( 'chatgpt', 'gemini', 'claude', 'perplexity', 'grok', 'mistral', 'deepseek', 'meta' );

	/**
	 * Probing models mirror each CONSUMER APP'S DEFAULT model -- what a shopper's
	 * phone runs -- not the strongest. Researched 2026-08-18; re-check whenever
	 * an app changes its default. Question generation and the accuracy judge are
	 * different jobs and stay on the cheap tier (TWTAEO_AI_Client).
	 */
	const MODELS = array(
		'claude'     => 'claude-sonnet-5',      // Claude.ai Free+Pro default since 2026-06-30
		// ChatGPT Free and Go default, since OpenAI retired GPT-5.5 from the
		// consumer app around 2026-10-07. Was gpt-5.6-luna before 2026-11-11.
		// (A bare `gpt-6` is Sol, NOT Luna -- name the model exactly.)
		'chatgpt'    => 'gpt-6-luna',
		/*
		 * Gemini app default for FREE accounts. On 2026-10-09 Google took Flash
		 * and Pro away from unpaid personal accounts and left Flash-Lite as the
		 * only model in the app. That is a standing RESTRICTION, not a fallback
		 * after a limit: a free account starts here. Paying accounts get Flash
		 * (see MODELS_PAID). Was gemini-3.6-flash before 2026-11-11, which
		 * described the pre-October app and overstated what free users get.
		 *
		 * The version gap against the 3.8 Flash on the paid rung is deliberate
		 * and is the product's own: Google serves free accounts an older, smaller
		 * model, and reporting names it 3.5 Flash-Lite. So free-vs-paid on Gemini
		 * is a two-generation gap, which is what a free user actually meets --
		 * not a measurement artefact to be normalised away.
		 */
		'gemini'     => 'gemini-3.5-flash-lite',
		'perplexity' => 'fast',                 // Agent API preset: Perplexity's documented stand-in for 'sonar' (web search on). Sonar API retired 2026-09-27.
		'grok'       => 'grok-4.6',             // live-verified 2026-08-20: answers + url_citation annotations came back on a real xAI key
		'mistral'    => 'mistral-medium-latest',
		// DeepSeek's documented primary model: `deepseek-flash` calls the latest
		// Flash (V4.1 since 2026-09-10); deepseek-chat / deepseek-reasoner were
		// retired 2026-07-24. Asked through DeepSeek's Anthropic-compatible API,
		// the one DeepSeek documents web search for.
		'deepseek'   => 'deepseek-flash',
		// Meta Model API (public preview): the docs' recommended model. NOT the
		// "-contributor" variants: those let Meta train on prompts and answers,
		// which here are a client's questions and personas.
		'meta'       => 'muse-spark-1.3',
	);

	/**
	 * MARKET -- whose AI experience a run stands in for -- and TIER, which rung
	 * of that experience answered.
	 *
	 * Three things decide what a person's AI actually runs, and only one of them
	 * is the subscription:
	 *
	 *   paid      -- a paid plan's default. Mostly the SAME model as free, with
	 *                the thinking rationed less. Depth is what moves citations: a
	 *                published minimal-vs-high comparison on one engine found
	 *                roughly 5x the web searches, about twice the sources per
	 *                answer, and only about a quarter of cited domains in common.
	 *   free      -- a free plan's default, inside quota. What every run before
	 *                this asked, so it stays the baseline.
	 *   throttled -- a free plan PAST its quota. The apps do not refuse; they
	 *                quietly drop you to a smaller model, often after only a
	 *                handful of questions in one day. This rung is a different
	 *                MODEL, not less thinking, so it applies to every engine and
	 *                not only the ones with a reasoning dial.
	 *
	 * A market says which rungs are worth asking. DTC is free plus throttled --
	 * essentially no consumer is paying, but plenty of them are over quota. B2B
	 * adds paid, because a large minority of business buyers are on a plan.
	 *
	 * Rungs are reported as separate lines and never blended: a blended figure
	 * would bake in a survey percentage this plugin cannot show a source for.
	 *
	 * The market is ASKED, never inferred: a wrong guess skews a site's whole
	 * dataset, and the owner knows the answer in one click.
	 */
	const MARKETS = array(
		'dtc' => array(
			'label' => 'Consumers (DTC)',
			'note'  => 'Almost no consumer is paying, but many are over their daily quota and answered by a smaller model.',
		),
		'b2b' => array(
			'label' => 'Businesses (B2B)',
			'note'  => 'A large minority of business buyers are on a paid plan; the rest are on free, in and out of quota.',
		),
	);

	/**
	 * THE AUDIENCE MIX -- what share of each market's questions reaches the AI
	 * at each plan tier. Starting values only: the owner edits them on the AI
	 * Visibility page, and they want revisiting about monthly.
	 *
	 * Deliberately derived from as few invented numbers as possible, because a
	 * table of six precise-looking percentages reads as researched when it is
	 * not. There are exactly two inputs:
	 *
	 *   MARKET_PAID_SHARE      -- how much of a market is on a paid plan.
	 *   THROTTLED_SHARE_OF_FREE -- how much of what is left is answered AFTER
	 *                              a free plan's quota ran out.
	 *
	 * Everything else follows by subtraction, so there is one number to argue
	 * with per row rather than three.
	 *
	 * A caution worth keeping: these are AUDIENCE-level shares -- what fraction
	 * of the PEOPLE asking are on each plan. They are NOT a given engine's own
	 * plan mix ("57% of ChatGPT users are on Free"), which has a different
	 * denominator. Multiplying the two together is meaningful; treating them as
	 * the same axis double-counts. Nothing here records a per-engine mix,
	 * because no engine publishes one.
	 */
	const MARKET_PAID_SHARE = array(
		// Survey-level reading, Nov 2026: paid consumer plans are rare.
		'dtc' => 1,
		// Survey-level reading, Nov 2026: a large minority of businesses have
		// upgraded somebody's plan.
		'b2b' => 47,
	);

	/**
	 * Of the questions asked on a free plan, how many land after the quota ran
	 * out and were answered by the smaller model.
	 *
	 * THIS NUMBER IS NOT MEASURED. No app publishes its quota, its downgrade
	 * threshold or what it downgrades to; free quotas are small enough that a
	 * normal session crosses them, which is why the figure is not simply low.
	 * It is one honest guess in one place, and it is the first thing to correct
	 * once there is any real evidence.
	 */
	const THROTTLED_SHARE_OF_FREE = 40;

	/**
	 * When the shipped shares above were last researched. Shown beside them,
	 * so a figure six months stale cannot pass for a current one. Move it when
	 * MARKET_PAID_SHARE or THROTTLED_SHARE_OF_FREE changes, not otherwise.
	 */
	const MARKET_MIX_RESEARCHED = '2026-11-11';

	const DEFAULT_MARKET = 'dtc';

	/**
	 * Share of an audience a tier needs before it is asked BY DEFAULT. Below
	 * this a check would be spent on a slice too small to move any figure --
	 * paid consumer plans, at roughly 1%, are the case this exists for. The
	 * owner can still tick it on.
	 */
	const TIER_WEIGHT_FLOOR = 5;

	/** Rungs of the AI experience, strongest first. `free` is what every run before this asked. */
	const TIERS = array( 'paid', 'free', 'throttled' );

	const DEFAULT_TIER = 'free';

	const TIER_LABEL = array(
		'paid'      => 'Paid plan',
		'free'      => 'Free plan',
		'throttled' => 'Free plan, over quota',
	);

	/**
	 * What each rung asks for: `effort` is the provider's reasoning dial,
	 * `searches` the web-search cap where the provider takes one.
	 *
	 * `free` repeats exactly what this module sent before tiers existed, so
	 * every existing trend line stays comparable. `throttled` asks the same way
	 * and differs only in the model, which is what being downgraded means.
	 */
	const TIER_PROFILE = array(
		'paid'      => array( 'effort' => 'high', 'searches' => 10 ),
		'free'      => array( 'effort' => 'low',  'searches' => 3 ),
		'throttled' => array( 'effort' => 'low',  'searches' => 3 ),
	);

	/**
	 * Engines with a DOCUMENTED reasoning dial. That dial is what makes `paid`
	 * different from `free` on an engine whose model does not change by plan:
	 *
	 *   chatgpt -- Responses `reasoning.effort`
	 *   claude  -- `output_config.effort` plus the search tool's `max_uses`
	 *   gemini  -- `generationConfig.thinkingConfig.thinkingLevel`
	 *
	 * Grok and Mistral expose no such field. DeepSeek honours `effort`, but its
	 * own app's defaults are the thing being measured. Perplexity's deeper mode
	 * is a different Agent preset whose name is not confirmed. Verify each
	 * against a live key before adding it here.
	 */
	const EFFORT_DIAL = array( 'chatgpt', 'claude', 'gemini' );

	/**
	 * The smaller model a free account is dropped to once it is over quota.
	 *
	 * Haiku is the cheap rung of Claude's own family and is already carried in
	 * TWTAEO_AI_Models::choices(), so the ID is known good. What is NOT
	 * confirmed is that it is the model Claude actually falls back to -- no app
	 * documents its downgrade, and the November 2026 round of plan research
	 * turned up nothing on over-quota behaviour for any engine. Treat it as the
	 * best available stand-in and correct it once a fallback is observed.
	 *
	 * An engine absent here is asked once for `free` and `throttled` together:
	 * with no known lower model a second identical call would only spend one of
	 * the owner's checks.
	 */
	const MODELS_THROTTLED = array(
		'claude' => 'claude-haiku-4-5',
		/*
		 * Gemini is deliberately absent, and the reason is worth keeping: since
		 * 2026-10-09 Flash-Lite is the FLOOR EVERYONE lands on. A free account
		 * starts there, and a paying subscriber who exhausts an allowance drops
		 * there too (observed on AI Pro, 2026-11-11). So Gemini's throttled
		 * state and its free state are the same model, and the free row already
		 * describes both -- a second rung would send an identical request and
		 * bill the owner for it.
		 *
		 * Note what this model therefore does NOT cover: a PAID Gemini user who
		 * has run out and is on Flash-Lite. `paid` is one rung, so those
		 * questions are not separately counted -- but the answer they get is the
		 * free row, so nothing about it goes unmeasured. A fourth rung for
		 * "paid, exhausted" would only re-label questions, not reveal anything.
		 */
	);

	/**
	 * Paid-plan default model, for engines where the paid default is genuinely a
	 * DIFFERENT model rather than the same one thinking longer.
	 *
	 * Researched 2026-11-11, and both IDs were already carried in
	 * TWTAEO_AI_Models::choices(), so they are known to exist rather than
	 * guessed from a name in an article:
	 *
	 *   chatgpt -- ChatGPT Plus ($20) defaults to GPT-6.1 Sol in ordinary chat,
	 *              against Luna on Free and Go. Pro ($100+) goes further still,
	 *              to Astra, but Pro is a small enough slice of any audience
	 *              that Plus is the paid rung worth measuring.
	 *   gemini  -- the Gemini app serves Flash-Lite to free accounts and Flash
	 *              to paying ones, so Flash is the paid default.
	 *
	 * On NOT probing the top models (Argon, Carbon, GPT-6 Astra): they are
	 * picker choices, not defaults, and they are self-limiting in a way that
	 * matters here. Argon on AI Pro ($20) runs for a couple of chats and then
	 * drops the conversation back to Flash or Flash-Lite (observed 2026-11-11);
	 * Argon is also labelled "Code & analysis" and Carbon "Most complex work",
	 * which is not where an ordinary buying question goes. Between a default
	 * nobody has to choose and a model that has to be chosen, is reached for
	 * only when the default disappoints, and degrades within a few turns, the
	 * default is what almost every real question meets. Probing the top rung
	 * would spend checks describing a sliver of anybody's traffic.
	 *
	 * Everything else is absent on purpose. Claude Pro runs the same Sonnet 5
	 * as Claude Free -- what it buys is more thinking, which is the dial, not a
	 * model. Perplexity Pro unlocks a model PICKER rather than changing the
	 * default routing, so it has no single paid model to name. Grok, Mistral,
	 * DeepSeek and Muse publish nothing usable.
	 */
	const MODELS_PAID = array(
		'chatgpt' => 'gpt-6.1-sol',
		'gemini'  => 'gemini-3.8-flash',
	);

	/**
	 * Claude's web-search server tool variant for Sonnet 5 / 4.6+.
	 *
	 * Haiku does not know this one, and the `throttled` tier probes Haiku, so
	 * the variant is chosen per model -- see CLAUDE_SEARCH_TOOL_LEGACY.
	 */
	const CLAUDE_SEARCH_TOOL = 'web_search_20260209';

	/** The variant the smaller/older Claude models take, Haiku among them. */
	const CLAUDE_SEARCH_TOOL_LEGACY = 'web_search_20250305';

	/**
	 * The Anthropic web-search tool type sent to DeepSeek. DeepSeek documents
	 * web search on its Anthropic-compatible endpoint (as used by Claude Code)
	 * without naming a tool version, so this is the original, most widely
	 * implemented one. Confirm with a live key before relying on it.
	 */
	const DEEPSEEK_SEARCH_TOOL = 'web_search_20250305';

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
		'deepseek'   => '',
		'meta'       => '',
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
		'chat.deepseek.com'     => 'deepseek',
		'deepseek.com'          => 'deepseek',
		'you.com'               => 'you',
		'duckduckgo.com'        => 'duckduckgo',
		'meta.ai'               => 'meta',
	);

	/** Option / table names, in one place. */
	const OPTION_SETTINGS   = 'twtaeo_visibility_settings';   // allocation, engines, daily_cap, x_handle, alternate_hosts, company_urls, brand_items, personas
	const OPTION_QUESTIONS  = 'twtaeo_visibility_questions';  // merchant-edited question set (enabled flags)
	const OPTION_SPEND      = 'twtaeo_visibility_spend';      // [ 'YYYY-MM-DD' => n ]
	const OPTION_DB_VERSION = 'twtaeo_visibility_db_version';
	const DB_VERSION        = '7';   // 2: checks carry owned_domains / citation_surface / brand_hits; 3: + mentions (where the answer said the name); 4: + follow_ups on checks, persona on runs; 5: + the full answer text (for the CSV export); 6: + turn / asked (journey mode); 7: + tier (paid | free | throttled)

	/**
	 * Journey mode: how many follow-up turns a run may take past the first
	 * answer. Each turn asks the engine's own top follow-up in the same
	 * conversation — a real, searched check on the merchant's key.
	 */
	const JOURNEY_MAX = 2;

	/** Characters of answer text kept per check. Real answers run 1–4k; this only stops a runaway reply. */
	const ANSWER_MAX = 20000;

	/** Buyer personas a site may keep. Each one a run is asked as costs a full run. */
	const PERSONAS_MAX = 10;

	/** Follow-up questions kept per check. */
	const FOLLOW_UPS_MAX = 5;
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
	const AJAX_PERSONAS_SAVE    = 'twtaeo_visibility_personas_save';
	const AJAX_PERSONAS_SUGGEST = 'twtaeo_visibility_personas_suggest';
	const AJAX_PERSONAS_TOGGLE  = 'twtaeo_visibility_personas_toggle';

	/** admin-post action (a file download, not JSON) + its nonce. */
	const EXPORT_ACTION = 'twtaeo_visibility_export';

	/** Menu slug for the board page. */
	const PAGE_SLUG = 'twt-aeo-ai-visibility';

	/** Module slug in the registry. */
	const MODULE_SLUG = 'ai-visibility';

	private function __construct() {}
}
