=== TWT AEO Ultimate ===
Contributors: jadeslair
Tags: woocommerce, answer engine optimization, llms.txt, schema, ai citation
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.25.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Get cited by ChatGPT, Gemini, Perplexity and Google AI Overviews: connected schema, llms.txt, E-E-A-T, WooCommerce AEO and a RAG Engine.

== Description ==

**AEO Ultimate** makes your WordPress site readable, trustworthy and citable for AI answer engines (ChatGPT, Gemini, Claude, Perplexity and Google AI Overviews) and measures whether they actually cite you. It publishes connected @graph JSON-LD schema, clear publisher and author identity (E-E-A-T) and AI-agent discovery files such as llms.txt, and runs alongside your existing SEO plugin without duplicating what it already outputs.

Fully modular: every feature switches on or off on its own, so your site runs only what it needs.

= RAG Engine =

AI answer engines don't read your pages top to bottom. They cut the web into short passages, pull back the few that best match a question, and write their answer from those (retrieval-augmented generation). The RAG Engine shows your site the way that process sees it, and finds the answers your site is missing.

* **Documents**: upload the spec sheets, manuals, catalogs and FAQs your business already works from (PDF, Word or text). They are read on your own server and the file is deleted once read; only the searchable passages are kept. Tables in PDFs come out as real tables.
* **Search Placement**: the Google and Bing searches where you rank 4th to 15th that your documents answer and the ranking page does not, with the page to add the answer to.
* **AI Citations**: questions from your AI Visibility runs where an engine left you out, beside the passages from your documents that answer them.
* **Laws, codes and safety data**: quoted word for word with their citation, and checked online for changes since your copy's date.
* **Chunk View**: any page split into the passages a retrieval index would hold, with the ones that fall apart on their own flagged.

Nothing is published for you: every suggestion comes with a Copy button and an Edit page link.

= AI Visibility =

Asks ChatGPT, Gemini, Claude, Perplexity, Grok and Le Chat the questions your customers ask, on your own API keys, and shows per engine whether your site was cited, named without a link, or left out, and who was cited instead. Answers about your products are also checked against your real prices, stock and policies.

= WooCommerce AEO =

Built for stores: every commerce feature exists so an answer engine can quote your catalogue accurately.

* **Product Schema Engine**: every product publishes a connected Product or ProductGroup @graph with per-variant offers, identifiers (GTIN, MPN, SKU), images, reviews, shipping from your live zones, and your return policy.
* **Extension-Aware Pricing**: products from Bookings, Subscriptions, Bundles and similar extensions publish "From" prices or ranges, never a price their own page contradicts.
* **Product FAQs**: every FAQ source on a product page is assembled into one FAQPage node, with free and AI generators for the questions shoppers ask.
* **Products Dashboard**: walks your whole catalogue, names exactly what each product is missing, and generates schema in bulk.
* **Google and Bing Merchant Center**: compares your catalogue with what each engine lists, surfacing missing products, rejected items and price mismatches.
* **Smart Collections**: publishes "Starter Kit" or "Under $500" style categories as real Collections with structured data an assistant can quote.
* **Promotions & Price Rules**: quantity breaks and member prices published as honest price specifications, with coupon codes sent to Merchant Center.
* **AI Image Alt Text**: fills missing product image alt text in bulk.
* **Plays nicely**: WooCommerce's own Product schema is replaced only with your consent, and if AEO Ultimate for WooCommerce runs alongside, this plugin stands down so nothing publishes twice.

= Schema Markup & Structured Data =

The core engine detects the schema already on each page, classifies the page (homepage, service, article, location or product), and adds JSON-LD **only where it's missing**: Organization, Article, NewsArticle, Person, FAQPage, Service, Product, LocalBusiness, ContactPoint, Event, Review, AggregateRating and BreadcrumbList. Everything on a page is woven into one connected `@graph` (WebSite → Organization → WebPage, cross-referenced by `@id`), so AI crawlers read your site as one set of facts. Duplicate-schema conflicts are flagged.

= AI Ready: llms.txt & Crawler Control =

`llms.txt` and `llms-full.txt`, Markdown content negotiation for AI agents, Content-Signal headers, agent-discovery endpoints (Agent Skills Index, MCP and WebMCP, OAuth/OIDC discovery, RFC 9727 API catalog, RFC 8288 Link headers), and per-bot blocking and rate limits for 20+ AI crawlers such as GPTBot, ClaudeBot, PerplexityBot and Google-Extended. You decide which AI systems may read, cite or train on your content. Where creating `/.well-known/` files needs server access the plugin doesn't have, you get copy-and-paste instructions.

* **Open Knowledge Format (OKF) bundle**: your site as a machine-readable knowledge directory at `/okf/`, on by default and linked from llms.txt.

= Company & Author Identity (E-E-A-T) =

Organization JSON-LD (legal name, logo, contact points, sameAs), a clean `article:publisher` / `article:author` Open Graph split, a sitewide `fb:app_id` for Meta Domain Insights, and one-click import from Yoast or Rank Math. Author profiles output as Person schema with credentials, expertise, years of experience and social links, with an E-E-A-T scorecard for both authors and the company, optional author boxes and hover cards.

= Image SEO & Open Graph =

Alt text and Open Graph titles, descriptions and share images, generated from your content or with AI. One screen manages Open Graph and Twitter/X cards across every page. Bulk actions fill every missing description, alt text and FAQ, Service or Contact schema at once; AI-powered ones show the number of billed calls and ask first.

= Local SEO =

LocalBusiness schema with a business-type picker, opening hours, service area and geo coordinates. A NAP (name, address, phone) audit against Google Knowledge Graph, Google Business Profile and Bing Maps, plus the directories your industry should be listed in.

= Sitemaps & Indexing =

A cached XML sitemap and a Google News sitemap, with IndexNow pinging Bing and other engines on publish. The **Not Indexed** report finds published pages missing from Google's index and flags likely causes.

= Command Center =

Google Search Console, Google Analytics 4 and Bing Webmaster Tools in one view, with Google PageSpeed audits (Lighthouse score and Core Web Vitals). A traffic-leak scan finds high-traffic pages that lose mobile visitors to slow loads, and a content scan flags inline scripts, base64 images and other page-builder leftovers.

= More Tools =

Customer reviews with AggregateRating and Review schema, a "Last Updated" freshness badge, PR Bridge AI for press releases, and an AI Prompt Rate Limiter to protect your API budget.

= External Services & TWT Agency =

Optional AI features connect to Anthropic Claude, OpenAI, Google Gemini, Perplexity, xAI Grok and Mistral using your own API keys. Other optional integrations cover Google and Microsoft APIs, IndexNow and press-release services. Nothing is sent anywhere until you configure a feature and use it. See the FAQ for the full list with terms and privacy links, and the bundled EXTERNAL-SERVICES.md for the complete inventory. Optional TWT Agency connectivity is available; the plugin is fully functional on its own.

== Installation ==

= Automatic installation (recommended) =

1. In your WordPress dashboard, go to **Plugins → Add New Plugin**.
2. Search for **TWT AEO Ultimate**.
3. Click **Install Now**, then **Activate** once installation finishes.

You can also install from a downloaded ZIP: on the **Plugins → Add New Plugin** screen, click **Upload Plugin**, choose the `twt-aeo-ultimate.zip` file, then **Install Now** and **Activate**.

= Manual installation (FTP) =

1. Upload the entire `twt-aeo-ultimate` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin via the **Plugins** screen in your WordPress dashboard.

= After activating =

1. Go to **TWT AEO → Modules** to review and enable the features you want.
2. Set up your publisher identity under **TWT AEO → E-E-A-T** (the **Company** tab).
3. Complete each author's E-E-A-T data on their profile under **Users → All Users**.
4. Configure bot visibility and access controls at **TWT AEO → AI Ready**.

**Important:** After activating, go to **Settings → Permalinks** and click **Save Changes** once. This flushes rewrite rules so the virtual routes for `/llms.txt`, `/llms-full.txt`, and `/aeo-sitemap.xml` resolve correctly.

== Frequently Asked Questions ==

= Does it work with Yoast SEO, Rank Math or AIOSEO? =
Yes. AEO Ultimate detects your SEO plugin and steps back from anything it already outputs, adding only what is missing: connected schema, author credentials, the publisher/author split, llms.txt and AI-crawler controls.

= Which schema types does it add? =
Organization, Article, NewsArticle, Person, FAQPage, Service, Product, LocalBusiness, ContactPoint, Event, Review, AggregateRating and BreadcrumbList, each only where it suits the page and isn't already there.

= Does it support llms.txt and AI crawlers? =
Yes. It serves `/llms.txt` and `/llms-full.txt`, Markdown versions of your pages for AI agents, and Content-Signal headers, and lets you block or rate-limit each of 20+ AI crawlers. Blocking a bot adds it to your robots.txt and refuses its requests. People and ordinary search engines such as Googlebot are never affected.

= What does the RAG Engine do with my documents? =
It reads them on your own server, keeps only the searchable passages, and deletes the uploaded file. Nothing is sent to an outside service unless you switch on AI labelling (then only the first few pages go to your chosen AI provider, once) or a law or code is checked online (then only its name, sections and date are sent, never its text). Deleting a document removes its passages too.

= Do I need API keys? =
No, not for the core: schema, llms.txt, crawler controls, sitemaps, E-E-A-T and the RAG Engine's document search all work without one. AI features (AI Visibility, AI writing, image analysis, AI document labels and the law check) use your own key for the provider you choose, and you pick the model under Settings → AI Models.

= Will it slow down my site? =
No. Schema, metadata and virtual files are cached, heavier work runs only when content or settings change, and the crawler rate limiter checks only known AI agents. Background work such as reading documents measures what your host can handle and pauses rather than overloading it.

= What is the OKF bundle? =
Google's Open Knowledge Format publishes your organization, products, categories, posts and policies as files AI systems can read. AEO Ultimate serves it at `/okf/` automatically.

= What are the system requirements? =
WordPress 6.2 or later and PHP 7.4 or later (8.0+ recommended). A PHP memory limit of 256MB is recommended for reading large PDFs.

= Does it include third-party code? =
Yes, one library: [smalot/pdfparser](https://github.com/smalot/pdfparser) 2.12.5, under the LGPL-3.0 licence (bundled with its licence in `includes/vendor/smalot-pdfparser`). It reads the text of PDFs you upload to the RAG Engine, on your own server.

= What external services does this plugin connect to? =
Only the ones you configure, and only when you trigger them:

* **Anthropic Claude**: AI writing and enrichment; RAG Engine document labels when you switch them on. [Terms](https://www.anthropic.com/legal/aup) | [Privacy](https://www.anthropic.com/legal/privacy)
* **OpenAI**: descriptions, OG images, image analysis; RAG Engine document labels when you switch them on. [Terms](https://openai.com/policies/terms-of-use) | [Privacy](https://openai.com/policies/privacy-policy)
* **Google Gemini**: descriptions, vision, grounded lookups, RAG Engine document labels and law check. [Terms](https://policies.google.com/terms) | [Privacy](https://policies.google.com/privacy)
* **Perplexity**: brand authority and gap research, AI Visibility checks, RAG Engine law check. [Terms](https://www.perplexity.ai/hub/legal/terms-of-service) | [Privacy](https://www.perplexity.ai/hub/legal/privacy-policy)
* **xAI Grok**: AI Visibility checks. [Terms](https://x.ai/legal/terms-of-service-enterprise) | [Privacy](https://x.ai/legal/privacy-policy)
* **Mistral (Le Chat)**: AI Visibility checks. [Terms](https://legal.mistral.ai/terms/commercial-terms-of-service) | [Privacy](https://legal.mistral.ai/terms/privacy-policy)
* **Google APIs**: Search Console, Analytics, Knowledge Graph, Business Profile, Merchant Center. [Terms](https://policies.google.com/terms) | [Privacy](https://policies.google.com/privacy)
* **Microsoft / Bing APIs**: Webmaster Tools, Merchant Center, Maps Local Search. [Terms](https://www.microsoft.com/en-us/servicesagreement) | [Privacy](https://www.microsoft.com/en-us/privacy/privacystatement)
* **IndexNow**: URL submission on publish. [Terms & Privacy](https://www.indexnow.org/terms)
* **EIN Presswire**: press-release distribution. [Terms](https://www.einpresswire.com/legal/terms) | [Privacy](https://www.einpresswire.com/legal/privacy)
* **EasyPRwire**: press-release distribution. [Terms](https://easyprwire.com/terms-and-condition) | [Privacy](https://easyprwire.com/privacy-policy)
* **TWT Agency** (optional) and opt-in token telemetry. [Terms](https://tampawebtech.com/plugin-terms/) | [Privacy](https://tampawebtech.com/plugin-privacy-policies/)

Each request sends only the data needed for that action, using your own API key. No site-visitor personal data is ever transmitted. The plugin also displays outbound links to industry directories and documentation sites; no data is sent to those. The complete inventory (every service that receives data, what it receives, and its terms and privacy policy, plus every linked site) ships in EXTERNAL-SERVICES.md inside the plugin folder.

== Screenshots ==

1. **AEO Score**: Your site graded 0–100, with the site-wide breakdown of where points are lost, the site-setup checklist, your score over time, and a per-page status table below: every point named, every gap linked to its fix.
2. **AI Visibility (who gets cited instead)**: Ask ChatGPT, Gemini, Claude, Perplexity, Grok and Le Chat the questions your customers actually ask, then see per engine whether your site was cited, named without a link, or absent, and exactly which competitors were cited in your place. Shown here with one-click sample data, built from your own catalogue so you can see the board before a single API call is spent.
3. **AI Visibility (every question, every engine)**: The full grid: each question against each engine, with the verdict and an accuracy check against your real prices, stock levels and policies. Open any row to read what the engine said and which sources it leaned on.
4. **Command Center**: Real-time AI Crawler Watch monitor and bot traffic breakdown.
5. **AI Ready Configuration**: Per-bot rate limits and block toggles for 20+ AI agents.
6. **Company Profile Manager**: Brand identity fields and Meta Business integration.
7. **Author Profile Extensions**: Custom fields on the WordPress user profile for certifications and credentials.
8. **Schema Detector**: Auto-detects FAQ, Service, and Contact pages, fills in missing JSON-LD, and flags duplicate-schema conflicts.
9. **Image SEO**: Finds images missing alt text across your posts and pages and generates descriptive, AEO-friendly alt attributes.
10. **Modules Screen**: Enable or disable each feature independently.
11. **WooCommerce AEO**: Product schema with one-click sync to Google and Bing Merchant Center, integrity scoring, and rejection logs.
12. **Not Indexed**: Uses Google Search Console to surface published pages missing from Google's index and flags likely causes: thin content, high keyword density, and missing heading hierarchy.

== Changelog ==

= 2.25.0 =
* New: RAG Engine (switch it on under Modules; it replaces the Chunk View screen). One screen for how AI retrieval sees your site: Chunk View, Documents, Search Placement and AI Citations.
* New: Documents. Upload PDFs, Word files and text: spec sheets, manuals, FAQs. They are read on your own server and the uploaded file is deleted once read; only the searchable passages are kept. Large files upload in pieces and resume after a dropped connection.
* New: tables in PDFs are rebuilt as real tables. Standard/option charts read "Standard", "Option" or "N/A" under the right model, and spec tables keep each value beside its label. Copy pastes a table straight into the editor.
* New: document labels. Each document is marked as a law, code, safety data sheet, contract, manual, spec sheet or other, with its place, edition and what to cite it as. Laws, codes and safety data are copied word for word with their citation. An optional switch lets AI read the first few pages to label them; you can correct any label.
* New: law check. For a law or code, an online search (Gemini or Perplexity) looks for changes after your copy's date and links to the official source. Only the citation is sent, never your document's text.
* New: Search Placement lists the Google and Bing searches you rank 4th to 15th for that your documents answer and the ranking page does not. Searches for a business to hire ("near me", "services", a town) are left out, with a note saying how many.
* New: AI Citations lists AI Visibility questions where an engine left you out, with the passages that answer them. It reads your existing runs, including paused or unfinished ones, and never runs new checks.
* Changed: the Search Console setting is now a dropdown of your Google account's own properties. Fixed: domain properties ("sc-domain:") could not be saved, and an address that did not exactly match a property failed with "User does not have sufficient permission".
* Changed: AI Visibility stops asking an engine for the rest of a run once it rejects its API key.

= 2.24.0 =
* Changed: Perplexity now runs on its Agent API. Perplexity retires the Sonar API on September 27, 2026; earlier versions of this plugin lose Perplexity answers in AI Visibility and Perplexity lookups after that date. Perplexity errors now say what went wrong in plain words: a rejected key, no credit, rate limiting, or an API Perplexity has retired.
* New: choose the model for each AI provider under Settings → AI Models, or type in any model ID. Adds GPT-6 (Luna, Sol, Astra), Gemini 3.5–3.8 Flash, Claude Sonnet 5 and Opus 5. Sites that already had a key keep the model they were using; new setups start on Claude Haiku 4.5, GPT-6 Luna and Gemini 3.6 Flash.
* Fixed: newer models reject settings older ones needed (temperature on Claude 5, "minimal" reasoning on GPT-6, "minimal" thinking on Gemini 3.7/3.8). Requests now adapt to the model chosen.
* Fixed: Gemini 2.5 Flash is now limited to Google accounts that already used it, so new Gemini keys could fail. New setups use Gemini 3.6 Flash; web-grounded lookups on a free-tier key fall back to 2.5 where the account allows it.
* Fixed: IndexNow no longer submits URLs from local, development or staging copies of a site (or from .local, .test, localhost and private-network addresses). The log shows these as "Skipped" with the reason.
* Fixed: Chunk View labelled sibling sections as nested ("Features › Pricing") on pages whose headings start at H2, which is most WordPress content. Each passage now shows the heading it actually sits under.

The complete changelog for every release ships in the changelog.txt file bundled with the plugin.

== Upgrade Notice ==

= 2.25.0 =
Adds the RAG Engine: upload your documents and see which searches and AI answers they could win you. Also required before September 27, 2026 if you use Perplexity and are updating from 2.23.0 or earlier: Perplexity retires its Sonar API that day.

= 2.24.0 =
Required before September 27, 2026 if you use Perplexity: Perplexity retires its Sonar API that day, and older versions of this plugin will stop getting Perplexity answers. Also adds a model picker for Claude, OpenAI and Gemini, with support for GPT-6 and Gemini 3.x.

= 2.23.0 =
Fixes AI Visibility runs ignoring allocation changes once a question list was saved, and adds Chunk View (off by default): see how AI retrieval splits your pages into passages and which passages fall apart out of context.

= 2.22.0 =
AI Visibility now shows the exact passage where each engine named your company or brands, so you see how you were mentioned, not just that you were. Also fixes stored checks dropping owned-profile citation data; re-run a visibility run after updating.

= 2.21.0 =
New Bot View screen: see any page the way no-JS AI crawlers (GPTBot, ClaudeBot, PerplexityBot) see it: what schema is visible, what is JavaScript-locked, and what hidden script data could be promoted into server-rendered schema.

= 2.20.1 =
Recommended for all sites: fixes FAQ schema publishing headings as fake questions, unicode corruption in stored schema, and a robots.txt issue that could make Google reject indexing requests. Adds duplicate meta description detection with an inline AI fix.

= 2.20.0 =
Adds machine-readable trust signals for AI search: per-post content provenance (digitalSourceType), ProfilePage schema on author archives, AI-drafted contextual authority statements for authors, and correct per-language schema on WPML/Polylang sites.

= 2.19.0 =
Critical for WooCommerce stores connected to Google Merchant Center: Google retired the Content API on August 18, 2026, breaking all Merchant Center features; this release moves to the Merchant API. Also adds machine-recognizable author credentials and citation-board fixes.

= 2.17.0 =
Adds AI Visibility, a citation engine that asks ChatGPT, Gemini, Claude, Perplexity, Grok and Le Chat what shoppers ask and records whether you were cited, named or absent. Adds an OKF bundle at /okf/. Fixes an upgrade bug that left new default-on modules switched off.

= 2.12.1 =
Fixes a critical error on servers without the PHP libsodium extension, where storing or reading a saved key or token could take a page down. Also fixes a fatal error on the Not Indexed page. Recommended for all sites.

= 2.12.0 =
Fixes a PHP fatal error that could prevent the Author Entity (E-E-A-T) admin page from loading. Recommended for all sites using author profiles.

= 2.11.0 =
Adds the unified Knowledge Graph: pages that output multiple schema types now ship one connected `@graph` (a cross-referenced entity spine) instead of scattered JSON-LD blocks, with duplicate Organization output consolidated. Single-schema pages are unchanged.

= 2.10.0 =
Adds the Smart 404 Rescue module: self-healing internal links, hacker-probe filtering, and Gemini-grounded external-link detection that auto-creates 301s, all without a bloated 404 log table.

= 2.9.1 =
Adds Google's newly recommended merchant-listing fields (priceValidUntil, sale validFrom/validThrough, and product category) plus a one-click AI resolver for the official Google Product Category (CategoryCode) to WooCommerce Product schema.

= 2.9.0 =
Initial public release on WordPress.org.
