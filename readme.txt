=== TWT AEO Ultimate ===
Contributors: jadeslair
Tags: aeo, schema, seo, structured data, llms
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Answer Engine Optimization for WordPress. Structured data, AI crawler controls, E-E-A-T author profiles, and publisher signals — all in one place.

== Description ==

**TWT AEO Ultimate** prepares your WordPress site for the AI-first web. As search shifts from ten blue links to AI-generated answers, the signals that get your content cited — structured schema, author authority, publisher identity, and machine-readable content — matter more than ever.

The plugin is built as a fully modular system. Enable only what your site needs; every feature toggles independently, keeping your admin clean and performance light.

**Core modules:**

= 🤖 AI Ready =

Turn your site into a first-class citizen for AI agents and LLM crawlers with a single toggle.

* **llms.txt & llms-full.txt** — Serves a spec-compliant index file at `/llms.txt` and a curated Markdown handshake at `/llms-full.txt` (for ChatGPT, Perplexity, Claude, etc.) containing clean content stripped of HTML noise.
* **Markdown Content Negotiation** — Automatically serves Markdown via `Accept: text/markdown` HTTP headers to visiting AI agents.
* **Content-Signal Headers** — Outputs draft `Content-Signal` headers (`ai-train`, `search`, `ai-input`) to communicate your data licensing and usage preferences.
* **Agent Search API** — A dedicated REST endpoint (`/wp-json/aeo/v1/search`) for AI agents querying your content.
* **MCP Integration & WebMCP** — Exposes a Model Context Protocol server card and browser injection so local AI assistants can discover your site's capabilities.
* **OAuth / OIDC Discovery** — Supports RFC 9728 Protected Resource Metadata and OAuth Authorization Server discovery for authenticated agent access.
* **Granular Rate Limiting** — Per-IP and per-bot throttling. Block specific crawlers or set custom limits for over 20 bots (GPTBot, Meta-ExternalAgent, AppleBot-Extended, and more).
* **API Catalog** — Generates an RFC 9727 `.well-known/api-catalog` to index your site’s public endpoints.

= 🏢 Company Profile =

Correctly splits your corporate entity (Publisher) from individual writers (Authors) to maximize entity-graph clarity for search engines.

* **Organization JSON-LD Schema** — Generates core publisher fields including legal name, logo, founding year, contact points, and `sameAs` social links.
* **Open Graph Publisher/Author Split** — Outputs explicit `article:publisher` and `article:author` tags connecting content to your brand's Facebook page and to individual writer profiles.
* **Meta Business Sync** — Adds the sitewide `fb:app_id` that most SEO suites omit, unlocking Meta Domain Insights and AI-powered Ad Attribution.
* **SEO Plugin Sync** — One-click import from Yoast SEO or Rank Math. Fills in missing fields without ever overwriting your existing data.

= ✍️ Author & E-E-A-T =

Deep author profiles that build robust Person schema and establish authentic Experience, Expertise, Authoritativeness, and Trustworthiness.

* **Rich Author Metadata** — Fields for job titles, credential lines, areas of expertise, and years of industry experience.
* **Verified Certifications** — Repeatable fields for licenses, credentials, and issuing organizations, output as `EducationalOccupationalCredential` in JSON-LD.
* **Social Profiles** — Connects personal networks (LinkedIn, X, GitHub, YouTube, and more) as `sameAs` links in the author's Person schema.
* **Frontend Enhancements** — Optional author hover cards and schema-driven author boxes.
* **E-E-A-T Completeness Score** — Audits profile strength across 8 core trust signals.

= 🗺️ Smart Sitemap Generator =

Flexible, cached XML sitemaps built to support modern discovery mechanisms.

* **Custom Route Handling** — Generates a dedicated `/aeo-sitemap.xml` with per-post-type controls over inclusion, update frequency, and priority.
* **Transient Caching** — 1-hour transient cache, cleared automatically only on content changes (save, update, delete).
* **Parallel llms-full.txt** — Keeps your sitemap and your `/llms-full.txt` output mirrored automatically.
* **Collision Protection** — Detects active Yoast, Rank Math, or AIOSEO sitemaps and steps aside to avoid duplicates.

= 🔍 Schema Detector & Enhancer =

Scans on-page structure and injects structured schema where your theme or page builder left it out.

* **Semantic Detection** — Monitors and compiles JSON-LD for **FAQs** (`FAQPage`), **Services** (`Service`), and **Contact pages** (`ContactPoint`).

= 🎛️ Command Center =

A unified dashboard showing your site's optimization health and bot activity at a glance.

* **AI Crawler Watch** — Live feed of AI engine visits, breakdown by parent company, most-crawled pages, and direct links to block or throttle specific crawlers.
* **Conflict Resolver** — Flags duplicate schema blocks injected by competing plugins or scripts.
* **Readiness Score** — A real-time score showing how prepared your site is for AI search.

= 🛠️ Specialized Add-ons =

* **IndexNow** — Instant URL submission to Bing, Yandex, and other compatible engines on publish or update.
* **Local Pack Schema** — `LocalBusiness` output with a NAP (Name, Address, Phone) consistency checker.
* **WooCommerce Bridge** — Product and Review schema for e-commerce catalog visibility.
* **PR Bridge AI** — Press release detection, content restructuring, and wire-service distribution.
* **Content Generator** — In-dashboard, AI-assisted drafting aligned with AEO best practices.

= TWT Agency Connectivity =

This plugin includes optional integration with the **TWT Agency** dashboard for cross-site analytics, historical telemetry, and bulk schema oversight. The plugin remains 100% functional as a standalone install — no paid tier or subscription required.

== Installation ==

1. Upload the entire `twt-aeo-ultimate` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin via the **Plugins** screen in your WordPress dashboard.
3. Go to **TWT AEO → Modules** to review and enable the features you want.
4. Set up your publisher identity under **TWT AEO → Company**.
5. Update your user profiles under **Users → All Users** to complete each author's E-E-A-T data.
6. Configure bot visibility and access controls at **TWT AEO → AI Ready**.

**Important:** After activating, go to **Settings → Permalinks** and click **Save Changes** once. This flushes rewrite rules so the virtual routes for `/llms.txt`, `/llms-full.txt`, and `/aeo-sitemap.xml` resolve correctly.

== Frequently Asked Questions ==

= Does this plugin conflict with Yoast SEO or Rank Math? =
No. TWT AEO Ultimate detects active SEO suites and steps back from any metadata they already output (Organization, Article, Open Graph). It acts as an enhancement layer, adding the signals those suites typically miss — `article:publisher`, rate-limit controls for 20+ AI agents, author credential schema, and `llms.txt` processing.

= What is llms.txt and why do I need it? =
`llms.txt` is an emerging standard (see llmstxt.org) that gives AI systems a clean, machine-readable summary of what your site contains and how to access it. Think of it as a `robots.txt` written for language models. The companion endpoint `/llms-full.txt` serves your content as clean Markdown so crawlers can ingest it without parsing token-heavy HTML.

= Why does the publisher/author Open Graph split matter? =
Standard social tags often blur authorship by failing to separate your brand from the individual writer. TWT AEO Ultimate keeps them distinct: `article:publisher` establishes the company identity, while `article:author` links to the individual author. AI models use this split to assess authority and attribute content accurately.

= What does the Meta Business Sync do? =
It adds the sitewide `fb:app_id` tag, which links your domain to your Meta Developer App. That unlocks the Meta Domain Insights dashboard and helps Meta attribute your content correctly across its ad and AI systems. Most SEO plugins don't output this tag.

= Can I block specific AI bots or engines safely? =
Yes. Under **TWT AEO → AI Ready → Security & Rate Limiting**, each of 20+ known AI crawlers has a Block toggle and a custom rate-limit override. Blocking a bot writes a `Disallow` entry to your virtual `robots.txt` and returns a 403 on its requests — both advisory and hard enforcement.

= Will the rate limiter affect my human visitors? =
No. The filter matches requests by User-Agent against the known AI crawler list only. Regular browsers, ordinary visitors, and traditional search engines (like Googlebot) pass through unaffected.

= Will this add overhead or slow down my site? =
No. Frontend metadata, schema, and virtual assets are served from the WordPress Transients API with a 1-hour cache. Heavier work runs only when a post is saved or settings change. The crawler rate limiter adds a single transient read per page load, and only for known AI agents.

= What are the system requirements? =
PHP 7.4 or higher (PHP 8.0+ recommended) and WordPress 6.0+. For full functionality on WordPress 7.0 and its modern data views, MySQL 8.0 and a PHP memory limit of at least 256MB are recommended.

== Screenshots ==

1. **Command Center** — Real-time AI Crawler Watch monitor and bot traffic breakdown.
2. **AI Ready Configuration** — Per-bot rate limits and block toggles for 20+ AI agents.
3. **Company Profile Manager** — Brand identity fields and Meta Business integration.
4. **Author Profile Extensions** — Custom fields on the WordPress user profile for certifications and credentials.
5. **E-E-A-T Quality Assessment** — Trust-signal scoring across your authors.
6. **Sitemap Generator** — Post-type selection for both XML and Markdown endpoints.
7. **Modules Screen** — Enable or disable each feature independently.

== Changelog ==

= 2.1.0 =

* **Compatibility:** Validated against WordPress 7.0; now requires MySQL 8.0.
* **Feature:** Rebuilt the AI Crawler Watch interface on the WordPress DataViews framework (React) with live client-side filtering, sorting, and status — falling back to a classic table on older environments (< WP 6.5).
* **Integration:** Registered core operations with the WordPress 7.0 Command Palette and Client-Side Abilities API. Schema Conflict Scan and IndexNow Submit All are now available via `Ctrl+K` and core AI assistants.
* **Block Editor:** Added iframe-aware state tracking. The AEO Status metabox now reads block state via the `wp.data` store, avoiding direct DOM access in the iframed editor.
* **Developer:** Added the `TWT_AEO_Key_Resolver` for API credentials, resolved in priority order (PHP constant → environment variable → Connectors → database). Constants are never written to the database.
* **Server Safeguards:** Added environment checks on admin pages that flag low memory conditions (≤ 128MB) which can conflict with WP 7.0 AI features.

= 1.0.17 =

* Added: Company Profile module — Organization schema, `article:publisher` / `article:author` OG split, `fb:app_id` (Meta Business Sync), and Yoast / Rank Math one-click sync.
* Added: Per-bot controls — custom rate limits and Block toggles for 20 known AI crawlers.
* Added: `/llms-full.txt` generation mirrored against your sitemap configuration.

= 1.0.16 =

* Added: Repeatable `EducationalOccupationalCredential` fields on the author profile.
* Added: Configurable frontend author hover cards.

= 1.0.15 =

* Added: XML sitemap generator at `/aeo-sitemap.xml`.
* Added: Conflict detection against external SEO-suite sitemaps.
* Added: IndexNow publishing hooks.

= 1.0.14 =

* Added: AI Ready toolkit — `llms.txt`, agent endpoints, and discovery/authentication routes.
* Added: MCP server configuration.

= 1.0.13 =

* Added: Command Center overview with crawler analytics and a default 30-day log retention.

= 1.0.12 =

* Added: Open Graph and social metadata output with automatic conflict avoidance.

= 1.0.11 =

* Added: Author profile schema and a configurable frontend author box.

= 1.0.10 =

* Added: Schema Detector for FAQ and Service content.
* Added: Local Pack module and LocalBusiness schema.

= 1.0.0 =

* Initial release — module loader, dashboard, settings, and data transmitter.

== External Services ==

This plugin can optionally connect to third-party providers to power AI content generation, indexing, and PR distribution. Connections stay dormant unless you explicitly enable a feature and add your own API credentials in the settings panel.

= Anthropic Claude API =

* **Used by:** Content Generator, PR Bridge AI.
* **Data sent:** Your content and prompt text, plus your Anthropic API key. No visitor identity data is sent.
* **Triggers:** Only on manual content generation or PR transformation actions.
* **Resources:** [Anthropic Acceptable Use Policy](https://www.anthropic.com/legal/aup) | [Anthropic Privacy Policy](https://www.anthropic.com/legal/privacy)

= OpenAI API =

* **Used by:** Content Generator (summary output alongside Claude).
* **Data sent:** A content prompt and your OpenAI API key.
* **Triggers:** Only during manual content generation, if an OpenAI key is configured.
* **Resources:** [OpenAI Terms of Use](https://openai.com/policies/terms-of-use) | [OpenAI Privacy Policy](https://openai.com/policies/privacy-policy)

= Perplexity AI API =

* **Used by:** Content Generator (research-focused perspective).
* **Data sent:** A research query and your Perplexity API key.
* **Triggers:** Only on explicit content generation, if a Perplexity key is configured.
* **Resources:** [Perplexity Terms of Service](https://www.perplexity.ai/hub/legal/terms-of-service) | [Perplexity Privacy Policy](https://www.perplexity.ai/hub/legal/privacy-policy)

= Google APIs (OAuth 2.0, Search Console, Analytics, Knowledge Graph, Business Profile, Merchant Center, Sitemap Ping) =

* **Used by:** Command Center reporting, Local Pack NAP checks, WooCommerce product sync, and sitemap notifications.
* **Data sent:** OAuth tokens, property/account identifiers, product data, or URLs — over standard Google OAuth flows. No end-user personal data is sent.
* **Triggers:** On dashboard reporting, scheduled product sync, or manual checks.
* **Resources:** [Google Terms of Service](https://policies.google.com/terms) | [Google Privacy Policy](https://policies.google.com/privacy)

= Microsoft / Bing APIs (OAuth, Merchant Center, Maps, Webmaster Tools, IndexNow) =

* **Used by:** E-commerce feed sync, NAP/address checks, and indexing.
* **Data sent:** OAuth tokens, structured product data, or published URLs sent to the relevant API endpoints.
* **Triggers:** On content publish/update events or catalog sync intervals.
* **Resources:** [Microsoft Services Agreement](https://www.microsoft.com/en-us/servicesagreement) | [Microsoft Privacy Statement](https://privacy.microsoft.com/en-us/privacystatement)

= IndexNow Network Protocol =

* **Used by:** IndexNow module.
* **Data sent:** Your IndexNow key and the public URLs of published or updated posts, sent to supporting search engines.
* **Triggers:** On post publish/update or manual submission.
* **Resources:** [IndexNow Protocol](https://www.indexnow.org/)

= EIN Presswire =

* **Used by:** PR Bridge AI distribution.
* **Data sent:** Press release headline and body, the source post URL, and your EIN Presswire API key.
* **Triggers:** Only when you click submit on the PR Bridge page.
* **Resources:** [EIN Presswire Terms](https://www.einpresswire.com/terms-of-service/) | [EIN Presswire Privacy Policy](https://www.einpresswire.com/privacy-policy/)

= EasyPRwire =

* **Used by:** PR Bridge AI distribution (alternative provider).
* **Data sent:** Press release headline and body, the source post URL, and your EasyPRwire API key.
* **Triggers:** Only when you click submit on the PR Bridge page.
* **Resources:** [EasyPRwire Terms](https://www.easypwire.com/terms/) | [EasyPRwire Privacy Policy](https://www.easypwire.com/privacy/)

= TWT Agency (optional) =

* **Used by:** Optional dashboard sync and remote monitoring.
* **Data sent:** Site AEO metrics, AI-crawler events, schema and content activity, analytics summaries, plugin/site info, and your TWT Agency API key. No site-visitor personal data is sent.
* **Triggers:** A daily snapshot plus event-driven pushes, only when a TWT Agency API key and Dashboard URL are configured.
* **Resources:** [TWT Terms of Use](https://tampawebtech.com/plugin-terms/) | [TWT Privacy Policy](https://tampawebtech.com/plugin-privacy-policies/)

= TWT AEO Token Telemetry (optional) =

* **Used by:** Content Generator (anonymous usage reporting to improve the plugin).
* **Data sent:** AI provider name, model slug, plugin version, and token counts only. No content, URLs, titles, API keys, or visitor data.
* **Triggers:** On an AI generation, only if you opt in via Settings.
* **Endpoint:** https://tampawebtech.com/wp-json/twt-aeo/v1/token-telemetry
* **Resources:** [TWT Privacy Policy](https://tampawebtech.com/plugin-privacy-policies/)

== Upgrade Notice ==

= 2.1.0 =
WordPress 7.0 compatibility update. Requires MySQL 8.0 and recommends a 256MB PHP memory limit. Adds React DataViews rendering, Command Palette bindings, and secure credential handling via `TWT_AEO_Key_Resolver`. After updating, visit TWT AEO → Modules to confirm your configuration.

= 1.0.17 =
Adds the Company Profile module (`article:publisher`, `fb:app_id`), per-bot rate-limit controls, and `/llms-full.txt` output. **Action required:** after updating, go to Settings → Permalinks and click Save to register the new rewrite rules.
