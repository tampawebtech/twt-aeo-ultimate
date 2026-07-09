=== TWT AEO Ultimate ===
Contributors: jadeslair
Tags: aeo, schema, seo, structured data, llms
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Answer Engine Optimization for WordPress. Structured data, AI crawler controls, E-E-A-T author profiles, and publisher signals — all in one place.

== Description ==

**TWT AEO Ultimate** prepares your WordPress site for the AI-first web. As search shifts from ten blue links to AI-generated answers, the signals that get your content cited — structured schema, author authority, publisher identity, and machine-readable content — matter more than ever.

Fully modular: enable only what your site needs. Every feature toggles independently.

= 🤖 AI Ready =

llms.txt and llms-full.txt endpoints, Markdown content negotiation for AI agents, Content-Signal headers, an Agent Search REST API, MCP/WebMCP discovery, OAuth/OIDC discovery, per-bot rate limiting and blocking for 20+ AI crawlers, and an RFC 9727 API catalog.

= 🏢 Company Profile =

Organization JSON-LD (legal name, logo, contact points, sameAs), an explicit article:publisher / article:author Open Graph split, sitewide fb:app_id for Meta Domain Insights, and one-click import from Yoast or Rank Math.

= ✍️ Author & E-E-A-T =

Rich author profiles: job titles, credentials output as EducationalOccupationalCredential schema, expertise areas, years of experience, social sameAs links, optional hover cards and author boxes, and an E-E-A-T completeness score.

= 🗺️ Smart Sitemap Generator =

Cached /aeo-sitemap.xml with per-post-type controls, a mirrored llms-full.txt, and collision protection with Yoast, Rank Math, and AIOSEO sitemaps.

= 🔍 Schema Detector & Command Center =

Detects FAQs, Services, and Contact pages and fills in missing JSON-LD. The Command Center shows live AI-crawler activity, duplicate-schema conflicts, and a real-time readiness score.

= 🛠️ Add-ons =

Image SEO (AI alt text), IndexNow, LocalBusiness schema with a NAP consistency checker, rich WooCommerce product schema with AI product enrichment and Google & Bing Merchant Center sync, and PR Bridge AI press-release distribution.

= External Services & TWT Agency =

Optional AI features connect to Anthropic Claude, OpenAI, Google Gemini, and Perplexity using your own API keys. Other optional integrations cover Google and Microsoft APIs, IndexNow, and PR wire services. Nothing is sent anywhere until you configure a feature and explicitly trigger it — see the FAQ for the full list with terms and privacy links, and the bundled EXTERNAL-SERVICES.md for the complete outbound-link inventory. Optional TWT Agency connectivity is available; the plugin is fully functional standalone.

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

= What external services does this plugin connect to? =
Only the ones you configure, and only when you trigger them:

* **Anthropic Claude** — AI writing and enrichment. [Terms](https://www.anthropic.com/legal/aup) | [Privacy](https://www.anthropic.com/legal/privacy)
* **OpenAI** — descriptions, OG images, image analysis. [Terms](https://openai.com/policies/terms-of-use) | [Privacy](https://openai.com/policies/privacy-policy)
* **Google Gemini** — descriptions, vision, grounded lookups. [Terms](https://policies.google.com/terms) | [Privacy](https://policies.google.com/privacy)
* **Perplexity** — brand authority and gap research. [Terms](https://www.perplexity.ai/hub/legal/terms-of-service) | [Privacy](https://www.perplexity.ai/hub/legal/privacy-policy)
* **Google APIs** — Search Console, Analytics, Knowledge Graph, Business Profile, Merchant Center. [Terms](https://policies.google.com/terms) | [Privacy](https://policies.google.com/privacy)
* **Microsoft / Bing APIs** — Webmaster Tools, Merchant Center, Maps Local Search. [Terms](https://www.microsoft.com/en-us/servicesagreement) | [Privacy](https://www.microsoft.com/en-us/privacy/privacystatement)
* **IndexNow** — URL submission on publish. [Terms & Privacy](https://www.indexnow.org/terms)
* **EIN Presswire** — press-release distribution. [Terms](https://www.einpresswire.com/legal/terms) | [Privacy](https://www.einpresswire.com/legal/privacy)
* **EasyPRwire** — press-release distribution. [Terms](https://easyprwire.com/terms-and-condition) | [Privacy](https://easyprwire.com/privacy-policy)
* **TWT Agency** (optional) and opt-in token telemetry. [Terms](https://tampawebtech.com/plugin-terms/) | [Privacy](https://tampawebtech.com/plugin-privacy-policies/)

Each request sends only the data needed for that action, using your own API key. No site-visitor personal data is ever transmitted. The plugin also displays outbound links to industry directories and documentation sites — no data is sent to those; the complete inventory with each site's terms and privacy policy ships in EXTERNAL-SERVICES.md inside the plugin folder.

== Screenshots ==

1. **Command Center** — Real-time AI Crawler Watch monitor and bot traffic breakdown.
2. **AI Ready Configuration** — Per-bot rate limits and block toggles for 20+ AI agents.
3. **Company Profile Manager** — Brand identity fields and Meta Business integration.
4. **Author Profile Extensions** — Custom fields on the WordPress user profile for certifications and credentials.
5. **E-E-A-T Quality Assessment** — Trust-signal scoring across your authors.
6. **Sitemap Generator** — Post-type selection for both XML and Markdown endpoints.
7. **Modules Screen** — Enable or disable each feature independently.

== Changelog ==

= 2.9.0 =

EXTERNAL-SERVICES.md and the full version history in changelog.txt, both bundled with the plugin.
* **Compliance:** Verified every disclosed terms/privacy link resolves and corrected outdated ones; expanded disclosures to name each API endpoint and dashboard link.
* **i18n:** Regenerated the translation template and fixed translator-comment mismatches.

= 2.8.0 =


* **Feature:** When the server filesystem is not writable, the AI Ready page now shows step-by-step instructions plus the exact, copy-and-paste contents for creating the `/.well-known/` discovery files (and the `robots.txt` Content-Signal/Sitemap directives) by hand.
* **Compliance:** Expanded the External Services disclosures with the optional industry-directory links the plugin can surface, and corrected/removed several directory URLs.

= Earlier versions =

The complete version history ships in the changelog.txt file bundled with the plugin.

== Upgrade Notice ==

= 2.9.0 =

= 2.8.0 =


= 2.3.1 =


= 2.1.0 =
WordPress 7.0 compatibility update. Requires MySQL 8.0 and recommends a 256MB PHP memory limit. Adds React DataViews rendering, Command Palette bindings, and secure credential handling via `TWT_AEO_Key_Resolver`. After updating, visit TWT AEO → Modules to confirm your configuration.

= 1.0.17 =
Adds the Company Profile module (`article:publisher`, `fb:app_id`), per-bot rate-limit controls, and `/llms-full.txt` output. **Action required:** after updating, go to Settings → Permalinks and click Save to register the new rewrite rules.
