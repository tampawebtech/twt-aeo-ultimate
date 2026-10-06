=== TWT AEO Ultimate ===
Contributors: jadeslair
Tags: woocommerce, answer engine optimization, llms.txt, schema, ai citation
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.30.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Get cited by ChatGPT, Gemini, Perplexity and Google AI Overviews: connected schema, llms.txt, E-E-A-T, WooCommerce AEO and a RAG Engine.

== Description ==

**AEO Ultimate** makes your WordPress site readable, trustworthy and citable for AI answer engines (ChatGPT, Gemini, Claude, Perplexity and Google AI Overviews) and measures whether they actually cite you. It publishes connected @graph JSON-LD schema, clear publisher and author identity (E-E-A-T) and AI-agent discovery files such as llms.txt, and runs alongside your existing SEO plugin without duplicating what it already outputs.

**Built for SEO professionals.** Everything is free: no license, no paid tier, no feature locked away. AI features run on your own API keys (Anthropic, OpenAI, Google, Perplexity, xAI or Mistral), so you pay the provider directly and choose the model. The work is bulk and semi-automated: fill every missing alt text, social description and FAQ, Service or Contact schema across a site in one pass, generate product schema for a whole catalogue, and let the plugin suggest the missing E-E-A-T details and apply them for you. AI-powered bulk actions show the number of billed calls and ask before running, so there are no surprise bills. Access & Roles lets an SEO manager or a client's marketing team use the tools without being a full administrator.

Fully modular: 29 features, each switched on or off on its own, so your site runs only what it needs.

= RAG Engine =

AI answer engines don't read your pages top to bottom. They cut the web into short passages, pull back the few that best match a question, and write their answer from those (retrieval-augmented generation). The RAG Engine shows your site the way that process sees it, and finds the answers your site is missing.

* **Documents**: upload the spec sheets, manuals, catalogs and FAQs your business already works from (PDF, Word or text). They are read on your own server and the file is deleted once read; only the searchable passages are kept. Tables in PDFs come out as real tables.
* **Search Placement**: the Google and Bing searches where you rank 4th to 15th that your documents answer and the ranking page does not, with the page to add the answer to. Searches you still rank for whose page is now in the Trash or unpublished are flagged with a one-click restore.
* **AI Citations**: questions from your AI Visibility runs where an engine left you out, beside the passages from your documents that answer them.
* **Next questions**: the follow-up questions the AI engines expect your buyers to ask next, checked against your pages and documents. Where your documents have the answer, you get the passage to publish; where nothing answers it yet, you get the content to write. Questions about your business itself (turnaround, warranty, shipping, reviews) are grouped by topic with the page that should answer them.
* **Laws, codes and safety data**: quoted word for word with their citation, and checked online for changes since your copy's date.
* **Chunk View**: any page split into the passages a retrieval index would hold, with the ones that fall apart on their own flagged.

Nothing is published for you: every suggestion comes with a Copy button and an Edit page link.

= AI Visibility =

Asks eight AI engines (ChatGPT, Gemini, Claude, Perplexity, Grok, Le Chat, Muse by Meta AI and DeepSeek) the questions your customers ask, on your own API keys, and shows per engine whether your site was cited, named without a link, or left out, and who was cited instead, or whether the engine cited no sources at all. Answers about your products are also checked against your real prices, stock and policies.

DeepSeek support is new: it works, and is still being tested. Most of DeepSeek's use is outside the US, led by Asia, with Russia and India near the top, and much of India searches in English. If your buyers are mostly in the US you can leave it off; if you sell internationally, especially into India, it is worth tracking.

* **Search location**: each run searches from a place: automatically your business address (Local Pack) or store country, or any country, state and city you choose, so a Tampa shop is measured the way Tampa buyers see the answers. Engines whose APIs take a search location use it; the others are told where the user is.
* **Buyer personas**: ask as one of your buyers ("machine shop owner", "manufacturing engineer"). The persona reaches the engine as background about the user, the way the consumer apps personalise, and the question is sent exactly as written. Type your own, or have them suggested from your main pages. Switch personas off to ask as nobody in particular.
* **Follow-up questions**: every answer comes back with the questions that engine expects the buyer to ask next.
* **Follow the conversation**: optionally ask the engine its own top follow-up in the same conversation, one or two turns deep, and see whether you are still cited further into the buyer's research. Follow-up turns are kept out of the headline numbers so runs stay comparable.
* **Your own questions**: add the questions you want asked, one per line, and reword any built-in question. Both are kept when the list is rebuilt.
* **Is this you?**: when an engine cites a listing that carries your name (your Shopify app, Clutch profile, plugin page or LinkedIn page), you are asked once whether it is yours. Yes counts it as you and re-scores your past runs for free; pages other sites wrote about you get their own slice instead of counting as competitors.
* **CSV export**: every question, answer, verdict and follow-up in one file, with the persona, the full answer text and pattern columns (where a brand or subject falls in the question, model numbers, places, hiring or how-to). Each row names the site and business type, so exports from several client sites stack into one sheet for analysis.

These are controlled simulations through the engines' APIs, not a copy of any one user's private chat history: the consumer apps personalise and change daily, and a run is the closest reproducible measure.

= AEO Score & Setup =

The AEO Score grades your site from 0 to 100 and shows exactly where each point is lost, with every gap linked to its fix. The Setup Wizard gets you there in one click with Autopilot, in two steps with The Basics, or in five with Expert.

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

The core engine detects the schema already on each page, classifies the page (homepage, service, article, location or product), and adds JSON-LD **only where it's missing**: Organization, Article, NewsArticle, Person, FAQPage, Service, Product, LocalBusiness, ContactPoint, Event, Review, AggregateRating and BreadcrumbList. Everything on a page is woven into one connected `@graph` (WebSite → Organization → WebPage, cross-referenced by `@id`), so AI crawlers read your site as one set of facts. The Knowledge Graph screen defines your entities and links them to Wikipedia and Wikidata, and duplicate-schema conflicts between plugins are flagged.

= AI Ready: llms.txt & Crawler Control =

`llms.txt` and `llms-full.txt`, Markdown content negotiation for AI agents, Content-Signal headers, agent-discovery endpoints (Agent Skills Index, MCP and WebMCP, OAuth/OIDC discovery, RFC 9727 API catalog, RFC 8288 Link headers), and per-bot blocking and rate limits for 20+ AI crawlers such as GPTBot, ClaudeBot, PerplexityBot and Google-Extended. You decide which AI systems may read, cite or train on your content. Where creating `/.well-known/` files needs server access the plugin doesn't have, you get copy-and-paste instructions.

* **Open Knowledge Format (OKF) bundle**: your site as a machine-readable knowledge directory at `/okf/`, on by default and linked from llms.txt.
* **Clean llms.txt**: pages set to noindex in your SEO plugin, password-protected pages and WooCommerce cart, checkout and account pages are left out. Switch llms.txt off if another plugin already serves it.
* **WordPress Abilities (WordPress 6.9+)**: ask your own AI assistant about your AEO data. It can read your AI Visibility results, AI crawler activity, pages no AI crawler has visited, any page's AEO report and your RAG Engine content gaps. Read-only and administrators only; nothing leaves your site unless you connect an AI tool yourself.

= Company & Author Identity (E-E-A-T) =

Organization JSON-LD (legal name, logo, contact points, sameAs), a clean `article:publisher` / `article:author` Open Graph split, a sitewide `fb:app_id` for Meta Domain Insights, and one-click import from Yoast or Rank Math. Author profiles output as Person schema with credentials, expertise, years of experience and social links, with an E-E-A-T scorecard for both authors and the company, optional author boxes and hover cards.

= Image SEO & Open Graph =

Alt text and Open Graph titles, descriptions and share images, generated from your content or with AI. One screen manages Open Graph and Twitter/X cards across every page. Bulk actions fill every missing description, alt text and FAQ, Service or Contact schema at once; AI-powered ones show the number of billed calls and ask first.

= Local SEO =

LocalBusiness schema with a business-type picker, opening hours, service area and geo coordinates. A NAP (name, address, phone) audit against Google Knowledge Graph, Google Business Profile and Bing Maps, plus the directories your industry should be listed in.

= Sitemaps & Indexing =

A cached XML sitemap and a Google News sitemap, with IndexNow pinging Bing and other engines on publish. The **Not Indexed** report finds published pages missing from Google's index and flags likely causes, and warns about indexed pages Google has stopped crawling before they drop out.

= Command Center =

Google Search Console, Google Analytics 4 and Bing Webmaster Tools in one view, with Google PageSpeed audits (Lighthouse score and Core Web Vitals). AI Crawler Watch shows which AI bots read your site and what they waste requests on, and the plugin counts visits that arrive from ChatGPT, Perplexity, Claude, Gemini and Copilot, with no cookies and no per-visitor data. A traffic-leak scan finds high-traffic pages that lose mobile visitors to slow loads, and a content scan flags inline scripts, base64 images and other page-builder leftovers.

= Use with Claude =

Ask Claude about your AI citations, AI crawler visits, Google indexing, schema and content gaps, and let it make fixes you approve. Switch the connector on under Settings → MCPs (off by default, administrators only) and connect Claude Code or Claude Desktop with one command, or Claude on the web and mobile through an aeoultimate.app account (free for one site; more sites are a paid plan with a 14-day trial). Every change is previewed first, logged, and can be reverted.

= More Tools =

Customer reviews with AggregateRating and Review schema, a "Last Updated" freshness badge, PR Bridge AI for press releases, and an AI Prompt Rate Limiter to protect your API budget.

= External Services =

Optional AI features connect to Anthropic Claude, OpenAI, Google Gemini, Perplexity, xAI Grok, Mistral, Meta (Muse) and DeepSeek using your own API keys. Other optional integrations cover Google and Microsoft APIs, IndexNow and press-release services. Nothing is sent anywhere until you configure a feature and use it. See the FAQ for the full list with terms and privacy links, and the bundled external-services.txt for the complete inventory.

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
* **Meta (Muse, Meta Model API)**: AI Visibility checks. [Terms](https://ai.developer.meta.com/legal/terms-of-service) | [Privacy](https://www.facebook.com/privacy/policy/)
* **DeepSeek**: AI Visibility checks. [Terms](https://cdn.deepseek.com/policies/en-US/deepseek-open-platform-terms-of-service.html) | [Privacy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html)
* **Google APIs**: Search Console, Analytics, Knowledge Graph, Business Profile, Merchant Center. [Terms](https://policies.google.com/terms) | [Privacy](https://policies.google.com/privacy)
* **Microsoft / Bing APIs**: Webmaster Tools, Merchant Center, Maps Local Search. [Terms](https://www.microsoft.com/en-us/servicesagreement) | [Privacy](https://www.microsoft.com/en-us/privacy/privacystatement)
* **IndexNow**: URL submission on publish. [Terms & Privacy](https://www.indexnow.org/terms)
* **EIN Presswire**: press-release distribution. [Terms](https://www.einpresswire.com/legal/terms) | [Privacy](https://www.einpresswire.com/legal/privacy)
* **EasyPRwire**: press-release distribution. [Terms](https://easyprwire.com/terms-and-condition) | [Privacy](https://easyprwire.com/privacy-policy)
* **Claude connector** (off by default): answers the Claude app you connect; optionally relayed through Tampa Web Technologies' hosted connector at mcp.aeoultimate.app for Claude on the web and mobile. [Terms](https://www.anthropic.com/legal/consumer-terms) | [Privacy](https://www.anthropic.com/legal/privacy)
* **Tampa Web Technologies** (opt-in only): token-usage telemetry, the optional Pro Dashboard connection and the hosted Claude connector. [Terms](https://tampawebtech.com/plugin-terms/) | [Privacy](https://tampawebtech.com/plugin-privacy-policies/)

Each request sends only the data needed for that action, using your own API key. No site-visitor personal data is ever transmitted. The plugin also displays outbound links to industry directories and documentation sites; no data is sent to those. The complete inventory (every service that receives data, what it receives, and its terms and privacy policy, plus every linked site) ships in external-services.txt inside the plugin folder.

== Screenshots ==

1. **AEO Score**: Your site graded 0–100, with the site-wide breakdown of where points are lost, the site-setup checklist, your score over time, and a per-page status table below: every point named, every gap linked to its fix.
2. **AI Visibility (who gets cited instead)**: Ask eight AI engines (ChatGPT, Gemini, Claude, Perplexity, Grok, Le Chat, Muse and DeepSeek) the questions your customers actually ask, then see per engine whether your site was cited, named without a link, or absent, and exactly which competitors were cited in your place. Shown here with one-click sample data, built from your own catalogue so you can see the board before a single API call is spent.
3. **AI Visibility (every question, every engine)**: The full grid: each question against each engine, with the verdict and an accuracy check against your real prices, stock levels and policies. Open any row to read what the engine said and which sources it leaned on.
4. **Command Center**: Real-time AI Crawler Watch monitor and bot traffic breakdown.
5. **AI Ready Configuration**: Per-bot rate limits and block toggles for 20+ AI agents.
6. **Company Profile Manager**: Brand identity fields and Meta Business integration.
7. **Author Profile Extensions**: Custom fields on the WordPress user profile for certifications and credentials.
8. **Schema Detector**: Auto-detects FAQ, Service, and Contact pages, fills in missing JSON-LD, and flags duplicate-schema conflicts.
9. **Image SEO**: Finds images missing alt text across your posts and pages and generates descriptive, AEO-friendly alt attributes.
10. **Modules Screen**: Enable or disable each feature independently.
11. **WooCommerce AEO**: Product schema with one-click sync to Google and Bing Merchant Center, integrity scoring, and rejection logs.
12. **Not Indexed**: Uses Google Search Console to surface published pages missing from Google's index and flags likely causes: thin content, high keyword density, and missing heading hierarchy. It also lists indexed pages Google hasn't crawled in 90 days or more, since pages left uncrawled for about 130 days are much more likely to be dropped.
13. **RAG Engine**: Setup status for your documents, your pages, Search Console, Bing and AI citation checks, and where each tab takes you: Documents, Chunk View, Search Placement and AI Citations.

== Changelog ==

= 2.30.1 =
* Fixed: the "Claude on the web and mobile" setup on Settings → MCPs now shows the site's connection key to paste into app.aeoultimate.app. It referred to a key the page didn't display.
* Improved: the setup explains that the connector URL is the same for everyone and the connection key is what links the site to your account, and that any email address works for the account.

= 2.30.0 =
* New: Use with Claude. A private connector (MCP server) lets Claude read this site's AEO data and, if you allow it, make changes. It is off by default, only administrators can use it, and Claude signs in with a WordPress Application Password you create for it on the new Settings → MCPs tab, which also shows the exact setup for Claude Code, Claude Desktop and Cowork.
* New: Claude on the web and mobile. Add the site and its connection key to a free account at app.aeoultimate.app and connect Claude once at mcp.aeoultimate.app, with no password to paste into Claude. One site is free; connecting more sites to one account is a paid plan that starts with a 14-day trial. The connector in this plugin stays free either way.
* New: 22 read tools. AI Visibility summary and competitor citations, AI crawler activity and pages AI crawlers have not visited, AI referral traffic, a full AEO report for any page, lowest-scoring pages, a site overview, content gaps, Google index status, schema conflicts, the AI-readiness checklist, FAQ schema status, the E-E-A-T scorecard, what AI crawlers see on a page, the 404 and redirect report, the funnel audit, image alt-text gaps, tracked questions and personas, search across pages and documents, and the log of changes made through Claude.
* New: export_data hands Claude whole datasets as paged CSV (AI Visibility results, crawler visits, referrals, index status and more), so it can analyse trends across every run instead of a summary.
* New: six ready-made analyses Claude can start from: analyse AI Visibility, analyse AI crawlers, a full AEO health report, quick wins, competitor gap analysis, and a deep dive on one page.
* New: 14 change tools, behind a separate Allow changes switch that is off by default. Set a meta description or Open Graph text, update FAQ schema, write image alt text, add a 404 redirect, suppress a duplicate schema type, turn an AI Ready feature on or off, edit tracked questions and buyer personas, update the company and author profiles, re-check a page in Google, run an AI Visibility check, and undo a change.
* New: every change works in two steps. Claude first shows exactly what will change, and applies it only after you say yes, with a confirmation that expires after 15 minutes. One page at a time, never in bulk.
* New: change history with revert. The last 50 changes made through Claude are listed on the MCPs tab with before and after values and who made them. Revert puts the exact previous value back, warns you if the page was edited since, and Claude can undo a change too.
* New: Claude's suggestions are grounded in what the connector can actually do on this site. Read results end with related tools and fixes, and fixes are only offered when changes are allowed.
* Security: the connector refuses requests from other websites, is limited to 600 calls an hour per user, never shares API keys, settings, visitor IP addresses or the full text of AI answers, and treats questions and answers written by other AI models as data, never as instructions.
* Changed: the external-services inventory is now external-services.txt (previously EXTERNAL-SERVICES.md) and lists the Claude connector.

= 2.29.0 =
* New: crawl age on Index Status and the Not Indexed page. Every scanned page shows how many days ago Google last crawled it, amber from 90 days and red from 130, where pages become much more likely to drop out of Google's index.
* New: "Indexed, But Google Hasn't Been Back" on the Not Indexed page lists indexed pages Google is crawling less and less, oldest first, with what to do about them. Index Status gets a Crawl Going Stale count.
* Improved: the daily index check now works through your whole site, 100 pages a day, pages never checked first and then the ones checked longest ago. It used to check only the 50 most recently edited pages, so old pages were never re-checked.
* Fixed: pages Google reports as "URL is unknown to Google" counted as indexed. They are now reported as not indexed.

= 2.28.1 =
* New: add your own questions to AI Visibility, one per line. They are asked on every run on top of the allocation and kept when you rebuild the list from templates.
* New: reword any question with Edit, and put a single question back to its original wording without resetting the list.
* New: "Is this you?" on AI Visibility results. Cited listings on platforms such as Shopify, Clutch, GitHub, LinkedIn and wordpress.org that carry your name in their address are offered for confirmation. Yes counts the page as you and re-scores your kept runs without asking the engines again; No is remembered and never asked again.
* New: pages about you. Reviews, comparisons and other pages that other sites wrote about you, and listings waiting for your answer, get their own "pages about you" slice and list instead of counting as competitors.
* Improved: saving "What counts as you" now re-scores your kept runs, so registering a page you own corrects past results for free.
* Improved: your plugin page's language copies on wordpress.org (en-gb., de. and so on) and your company's LinkedIn posts now count as you once the page is registered.
* Improved: a company can register up to 25 owned pages (was 12).
* Changed: app store addresses (apps.shopify.com, Wix, the Chrome Web Store) need the full listing address, so a bare store address can no longer claim every app cited there.
* Fixed: AI engines you added a key for after saving your allocation stayed unticked, so runs skipped them (Grok, DeepSeek and Muse on existing sites). They are now ticked; an engine you untick yourself stays off.

= 2.28.0 =
* New: Muse (Meta AI) joins AI Visibility, through the Meta Model API with web search. Muse answers slowly, so its questions are sent in the background and collected as they finish: the rest of the run never waits for it.
* New: DeepSeek joins AI Visibility, through DeepSeek's API with web search, for sites that sell outside the US. Working, and still being tested. AI Visibility now covers eight AI engines.
* New: search location for AI Visibility runs ("Search from"). Automatic from your Local Pack address or store country, or any country, state and city; English-speaking markets listed first. Runs are only compared with runs from the same place, and the CSV export has the location columns.
* New: "Gave no sources" in AI Visibility. An answer that did not cite you and cited no one else is marked as such, per engine and per answer, and in the CSV, so "not cited" is no longer read as a competitor winning.
* New: Markdown versions of your pages at `/page.md`, with discovery links and YAML frontmatter, and an llms.txt option to link each page to its Markdown version. The crawler log records whether HTML or Markdown was served. New AI bots recognised: Claude-User, Claude-SearchBot and Perplexity-User.
* New: FAQ schema drift detection. When a page's visible FAQ and its FAQPage schema no longer match, the Schema Detector's FAQ tab and the editor say so, and schema this plugin writes resyncs on save.
* New: RAG Engine Next questions group follow-ups about your business (turnaround, warranty, shipping, rush service, reviews, pricing) by topic, with your page for each topic or a note that none exists yet.
* New: RAG Engine Search Placement flags searches you still rank for whose page is in the Trash or unpublished, with a restore link.
* New: GPT-6.1 Sol in the OpenAI model list.
* Improved: RAG Engine matching. Passages must contain a question's most distinctive word, follow-ups that say "it" keep the model they refer to, and office address lists and tables of contents are no longer offered as answers.
* Improved: AI Visibility questions built from headings written in your own voice ("Models We Service") are rephrased the way a buyer asks, or skipped.
* Improved: runs with follow-up turns show them beside the check count ("375 checks + 345 follow-up turns"), and the progress line says how the growing total splits.
* Changed: the banner on the plugin's screens now offers help in the support forum instead of asking for a review.
* Changed: a bold "don't close this page" warning while documents upload.
* Fixed: approving, unapproving or deleting a review on the Reviews page could stop at "The link you followed has expired".
* Fixed: AI-written meta descriptions could stop mid-sentence. They now end at a full sentence, and the Social Graph screen flags existing descriptions that are cut off.
* Fixed: GPT-6.1 models were sent a reasoning setting they refuse.

= 2.27.0 =
* New: WordPress Abilities (WordPress 6.9 and later). Five read-only abilities let an AI assistant you connect to your site read your AI Visibility results, AI crawler activity, pages no AI crawler has visited, a page's AEO report and your RAG Engine content gaps. Administrators only. Answers are summaries: no visitor data, raw crawler requests, full AI answers, settings or keys are ever returned.
* Changed: llms.txt now leaves out pages set to noindex in Yoast, Rank Math, SEOPress or All in One SEO (v3 settings), password-protected pages, and WooCommerce cart, checkout and account pages.
* Changed: the llms.txt card on AI Ready explains when to leave llms.txt on and when to switch it off.

= 2.26.0 =
* New: buyer personas in AI Visibility. Ask a run as one of your buyers; the persona is sent to the engine as background about the user, and the question itself is never changed. Edit personas on the AI Visibility page or under Local Pack → Business Profile, or have them suggested from your main pages (meta title and description, or the first paragraph). Switch personas off to ask every run as nobody in particular.
* New: follow-up questions. Each AI Visibility answer comes back with the follow-up questions that engine expects the buyer to ask next, from a second, short call to the same engine with no web search. Shown under every answer.
* New: Follow the conversation (optional, per run). Asks the engine its own top follow-up in the same conversation, one or two turns deep, and records whether you are cited at each step. Each question's conversation finishes before the next question starts, so a stopped run keeps complete conversations. Follow-up turns appear as their own labelled rows and are kept out of the headline numbers, trends and comparisons.
* New: CSV export of AI Visibility runs (this run or every kept run). One row per question asked, followed by its follow-up questions, with the persona, the verdict, the full answer and pattern columns for analysis. Each row names the site and business type so exports from several sites combine into one sheet.
* New: Next questions in the RAG Engine's AI Citations tab. The follow-ups the engines predicted, checked against your pages and documents: passages to publish where your documents answer them, and a list of what nothing on your site answers yet.
* Fixed: Gemini answers in AI Visibility were cut off after a sentence or two. Gemini 3.x spends part of its output limit thinking, which left little room for the answer and could drop citations. Gemini and ChatGPT answers now have room to finish, so their cited and named rates may rise compared with earlier runs.
* Fixed: an AI Visibility check could fail to save without any message if the database table was missing a column. The table is now repaired and the save retried.

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

= 2.23.0 =
* New: Chunk View (off by default; switch it on under Modules). Shows a page the way a retrieval index holds it: fetched like a no-JavaScript AI crawler, split into passages, and each passage scored on whether it still makes sense on its own. Works on any URL, including competitor pages, with no API key.
* New: host governor. Heavy work measures what your host can handle and pauses instead of timing out on smaller hosting plans.
* Fixed: AI Visibility ignored allocation changes once a question list had been saved, and brand questions were never asked.
* Fixed: the AI Visibility question counts ignored "Per brand" and overcounted categories, posts and products.

= 2.22.0 =
* New: AI Visibility shows the exact passage where each engine named your company or brands, so you see how you were mentioned, not just that you were.
* Fixed: stored checks dropped owned-profile citation data, so brand totals undercounted. Re-run a check to refresh them.

= 2.21.0 =
* New: Bot View. See any page the way no-JavaScript AI crawlers (GPTBot, ClaudeBot, PerplexityBot) see it: what schema is visible, what is locked behind JavaScript, and which hidden script data could become schema.
* New: every Bot View audit fetches the page twice, as GPTBot and as a normal browser, exposing firewalls that turn AI crawlers away.
* New: a weekly Bot View scan of your homepage and recently updated content, with every finding linked to its fix.
* Fixed: AI Crawler Watch no longer counts Bot View's own audits as crawler visits.

= 2.20.1 =
* New: duplicate meta descriptions are flagged on the Not Indexed screen, with an inline AI fix.
* Fixed: headings that were not questions could be published as FAQ schema.
* Fixed: saved custom schema of an unusual type could be invisible and impossible to delete.
* Fixed: accented letters, dashes and emoji in stored schema could be saved as literal codes.
* Fixed: Easy Digital Downloads checkout and account pages were reported as not indexed.

= 2.20.0 =
* New: content provenance. Articles can declare how they were made (human, AI-assisted or AI-generated) using schema.org's digitalSourceType.
* New: author archives publish ProfilePage schema.
* New: multilingual schema for WPML and Polylang: each translation declares its language and links to the others.
* New: a contextual authority statement, drafted with AI, that explains an author's credentials in plain words.

= 2.19.0 =
* Critical: Google Merchant Center sync moved to Google's Merchant API before the old Content API shut down.
* New: author certifications are machine-recognizable credentials, with a category, the awarding body and its Wikipedia or Wikidata link.
* New: AI Visibility turns every question where you were left out into a brief of what to publish.
* New: AI Visibility checks its citations against real referral visits, and tracks each brand over time.
* Fixed: pushing a promotion to Merchant Center caused a fatal error.

= 2.18.0 =
* New: brand citation tracking. Each brand you track is asked about by name, and a brand can be a whole website, not only social profiles.
* New: the press-release features work on WordPress 7.0 without an API key of your own.
* Improved: Search Console top queries no longer fill up with rank-tracker noise.
* Fixed: /okf/ and /llms.txt could fail on hosts without PHP's mbstring extension.

= 2.17.0 =
* New: AI Visibility, the citation engine. Asks AI engines the questions your customers ask and records whether you were cited, named or left out.
* New: Grok (xAI) and Le Chat (Mistral) join ChatGPT, Gemini, Claude and Perplexity.
* New: an Open Knowledge Format (OKF) bundle at /okf/.
* New: runs continue in the background without the tab open, and awkward questions can be polished with AI.

= 2.16.0 =
* New: the AEO Score, a 0 to 100 citability grade that shows where every point is lost.
* New: one-click "Auto-Fill Missing Schema" on the Dashboard, and "Generate with AI" for schema descriptions.
* New: Review and HowTo forms in the schema editor.
* Fixed: Search Console domain properties could not be connected.

= 2.15.0 =
* Fixed: every scanning screen stopped at the 200 most recent items. Scans now cover the whole site.
* New: catalogue-wide search and pagers on the big tables, and the FAQ detector scans products.
* Changed: replacing WooCommerce's own Product schema now needs the store owner's consent.
* Improved: one consistent image fallback for social cards, and products with gallery images are no longer reported as missing an image.

= 2.14.0 =
* New: the full commerce schema suite from AEO Ultimate for WooCommerce: Product and ProductGroup graphs, Smart Collections and Promotions.
* New: the Setup Wizard's Autopilot now does real work, running every setup step that needs no AI in the background.
* Fixed: "Generate Schema for All" and "Scan All Pages" stopped after 200 items.
* Compatibility: verified with WordPress 7.1 and WooCommerce 11.

= 2.13.0 =
* New: bulk actions on every screen that had a per-row button, so a whole site can be brought to full coverage in one pass. AI-powered ones show the billed call count and ask first.
* New: a switch for every output that could collide with another plugin, on the Schema Conflict Detector page.
* Fixed: the text served to AI crawlers (llms.txt, llms-full.txt, Markdown pages and Agent Skills documents) was HTML-encoded, so characters such as `>` and `&` reached crawlers as `&gt;` and `&amp;`, breaking the llms.txt format.
* Fixed: the WordPress 7.0 AI Client connection.

= 2.12.1 =
* Fixed: hosts without PHP's libsodium extension could hit a critical error when saving keys.
* Fixed: the Not Indexed page could fail on scan results from older versions.
* Improved: WooCommerce cart, checkout and account pages are no longer reported as not indexed, and fixable issues get a Fix button.

= 2.12.0 =
* Fixed: the Author Entity (E-E-A-T) page could fail to load on some sites.

= 2.11.0 =
* New: a unified Knowledge Graph. Every schema block on a page is woven into one connected @graph, with duplicate Organization output consolidated.
* New: the Knowledge Graph page, to define entities, relationships and per-page about and mentions, grounded in Wikipedia and Wikidata.

= 2.10.0 =
* New: Smart 404 Rescue (off by default). Heals broken internal links, ignores hacker probes, and recovers pages Google still ranks you for with suggested 301 redirects, without a bloated 404 log.
* New: Access & Roles. Let an Editor or an SEO Manager role use the tools without being a full administrator.

= 2.9.1 =
* New: Product schema includes Google's merchant-listing fields: price valid-until dates and sale periods.
* New: an AI resolver maps each product to its exact Google Product Category.
* New: Merchant Center sync flags products whose category disagrees with the feed.

= 2.9.0 =
* First release on WordPress.org.
* Removed: the built-in content generator (now the separate TWT Content Generator plugin) and dormant bulk image generation code.
* Improved: every outside service the plugin can contact is listed with its terms and privacy links.

The complete, detailed changelog for every release ships in the changelog.txt file bundled with the plugin.

== Upgrade Notice ==

= 2.30.1 =
Shows the connection key you need to connect Claude on the web and mobile.

= 2.30.0 =
Use your AEO data in Claude: an optional, admin-only connector with 36 tools and six ready-made analyses. Changes are off by default, always previewed first, logged and reversible.

= 2.29.0 =
Warns about indexed pages Google has stopped crawling before they drop out of the index, checks your whole site over time instead of only recent pages, and reports pages Google has forgotten.

= 2.28.1 =
AI Visibility now asks every engine you hold a key for, lets you add and reword questions, and asks "Is this you?" about cited listings that carry your name, re-scoring past runs for free.

= 2.28.0 =
Adds Muse (Meta AI) and DeepSeek to AI Visibility, a search location for each run, Markdown versions of your pages, and FAQ schema drift detection. RAG Engine matching is tighter and follow-ups about your business are grouped by topic.

= 2.27.0 =
Lets your own AI assistant read your AEO data through WordPress Abilities (WordPress 6.9+, read-only, administrators only), and keeps noindexed and checkout pages out of llms.txt.

= 2.26.0 =
Adds buyer personas, follow-up questions, conversation follow-through and CSV export to AI Visibility, and fixes Gemini answers being cut short. Gemini cited and named rates may rise after updating because full answers are now read.

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
