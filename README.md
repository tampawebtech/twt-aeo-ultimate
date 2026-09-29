# TWT AEO Ultimate

**Answer Engine Optimization for WordPress.** Get your site cited by ChatGPT, Gemini, Claude, Perplexity and Google AI Overviews, and measure whether it actually is.

[![WordPress plugin version](https://img.shields.io/wordpress/plugin/v/twt-aeo-ultimate?label=WordPress.org)](https://wordpress.org/plugins/twt-aeo-ultimate/)
[![Tested up to](https://img.shields.io/wordpress/plugin/tested/twt-aeo-ultimate)](https://wordpress.org/plugins/twt-aeo-ultimate/)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPLv2%2B-blue)](LICENSE)

**Download:** [wordpress.org/plugins/twt-aeo-ultimate](https://wordpress.org/plugins/twt-aeo-ultimate/) · **Docs:** [aeoultimate.com/docs/wordpress](https://aeoultimate.com/docs/wordpress/) · **Website:** [aeoultimate.com](https://aeoultimate.com/)

AEO Ultimate makes a WordPress site readable, trustworthy and citable for AI answer engines. It publishes connected @graph JSON-LD schema, clear publisher and author identity (E-E-A-T) and AI-agent discovery files such as llms.txt, runs alongside Yoast SEO, Rank Math or AIOSEO without duplicating their output, and shows you which answers your site is missing.

## Built for SEO professionals

* **Free**: no license, no paid tier, no feature locked away.
* **Bring your own key**: AI features run on your own Anthropic, OpenAI, Google, Perplexity, xAI or Mistral key. You pay the provider directly and choose the model.
* **Bulk**: fill every missing alt text, social description and FAQ, Service or Contact schema across a site in one pass, and generate product schema for a whole catalogue.
* **Semi-automated**: the plugin detects what each page is, adds schema only where it is missing, and suggests the missing E-E-A-T details for you to apply. AI-powered bulk actions show the number of billed calls and ask first.
* **Modular**: 29 modules, each switched on or off on its own.

## What's in it

### RAG Engine (new in 2.25)

AI answer engines cut the web into short passages and write their answers from the few that best match a question. The RAG Engine shows your site the way that process sees it, and works as a content gap finder: it finds the questions your customers search for that your pages don't answer, and the passage in your own documents that does.

* **Documents**: upload spec sheets, manuals, catalogs and FAQs (PDF, Word or text). They are read on your own server and the file is deleted once read. Tables in PDFs come out as real tables.
* **Search Placement**: the Google and Bing searches where you rank 4th to 15th that your documents answer and the ranking page does not. These are content gaps taken from your own ranking data.
* **AI Citations**: questions where an AI engine left you out, beside the passages from your documents that answer them.
* **Next questions**: the follow-up questions the engines expect your buyers to ask next, checked against your pages and documents: the passage to publish where your documents answer them, and a list of what nothing on your site answers yet.
* **Laws, codes and safety data**: quoted word for word with their citation, and checked online for changes since your copy's date.
* **Chunk View**: any page split into the passages a retrieval index would hold, with the weak ones flagged.

Nothing is published for you: every suggestion comes with a Copy button and an Edit page link.

### AI Visibility (buyer journeys new in 2.26)

Asks ChatGPT, Gemini, Claude, Perplexity, Grok and Le Chat the questions your customers ask and shows, per engine, whether your site was cited, named without a link, or left out, and who was cited instead.

* **Buyer personas**: ask a run as one of your buyers ("machine shop owner", "manufacturing engineer"). The persona reaches the engine as background about the user, the way the consumer apps personalise; the question is sent exactly as written. Type your own or have them suggested from your main pages.
* **Follow-up questions**: every answer comes back with the questions that engine expects the buyer to ask next.
* **Follow the conversation**: ask the engine its own top follow-up in the same conversation, one or two turns deep, and see whether you are still cited further into the buyer's research.
* **CSV export**: every question, answer, verdict and follow-up in one file, with the persona, the full answer text and pattern columns (brand and subject position, model numbers, places, hiring or how-to). Rows name the site and business type, so exports from many client sites stack into one sheet.

These are controlled simulations through the engines' APIs, not a copy of any one user's private chat history.

**AI Crawler Watch** logs which AI bots read your site and what they waste requests on, and **AI referral tracking** counts the visits that arrive from ChatGPT, Perplexity, Claude, Gemini and Copilot, with no cookies and no per-visitor data.

### Schema and entities

JSON-LD for Organization, Article, NewsArticle, Person, FAQPage, Service, Product, LocalBusiness, ContactPoint, Event, Review, AggregateRating and BreadcrumbList, added only where missing and woven into one connected `@graph`. A Knowledge Graph screen links your entities to Wikipedia and Wikidata, and the Schema Detector flags duplicate-schema conflicts between plugins.

### AI crawlers and discovery

`llms.txt` and `llms-full.txt`, Markdown content negotiation, Content-Signal headers, agent-discovery endpoints (Agent Skills Index, MCP and WebMCP, OAuth/OIDC discovery, RFC 9727 API catalog), an Open Knowledge Format bundle at `/okf/`, and per-bot blocking and rate limits for 20+ AI crawlers. Bot View shows any page the way a no-JavaScript crawler sees it. llms.txt leaves out noindexed, password-protected and WooCommerce checkout pages.

### WordPress Abilities (new in 2.27)

On WordPress 6.9+, the plugin registers five read-only abilities so an AI assistant you connect (for example through the WordPress MCP Adapter) can read your AI Visibility results, AI crawler activity, pages no AI crawler has visited, any page's AEO report and your RAG Engine content gaps. Administrators only. Answers are summaries: no visitor data, raw crawler requests, full AI answers, settings or keys are returned, and text written by outsiders (crawler URLs, AI-predicted questions) never goes out raw.

### WooCommerce

Product and ProductGroup schema with per-variant offers, identifiers, shipping and return policy; Google and Bing Merchant Center comparison; Smart Collections; promotions published as honest price specifications; bulk product alt text.

### And more

AEO Score (your site graded 0 to 100), a Setup Wizard with one-click Autopilot, E-E-A-T scorecards, author boxes and hover cards, Open Graph and Twitter/X cards, Local SEO with a NAP audit, sitemaps and IndexNow, a Not Indexed report, Command Center (Search Console, GA4, Bing Webmaster, PageSpeed), Smart 404 Rescue, Funnel Audit, customer reviews, PR Bridge AI, and a Diagnostics screen that catches fatal errors.

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.2 (tested up to 7.1) |
| PHP | 7.4 (8.0+ recommended) |
| WooCommerce | Optional, for the store features |

No build step: the plugin ships ready to run.

## Installation

1. In WordPress, go to **Plugins → Add New Plugin** and search for **TWT AEO Ultimate**, or upload the ZIP from [WordPress.org](https://wordpress.org/plugins/twt-aeo-ultimate/).
2. Activate it, then go to **Settings → Permalinks** and click **Save Changes** once so `/llms.txt` and the sitemap resolve.
3. Open **TWT AEO → Modules** (or the **Setup Wizard**) and switch on what your site needs.

Full setup guide: [aeoultimate.com/docs/getting-started](https://aeoultimate.com/docs/getting-started/).

## Privacy and external services

Nothing is sent anywhere until you configure a feature and use it. Uploaded documents are processed on your own server. Every outside service the plugin can contact, what it sends and the provider's terms are listed in [EXTERNAL-SERVICES.md](EXTERNAL-SERVICES.md).

## Changelog

See [changelog.txt](changelog.txt) for every release, or the [WordPress.org changelog](https://wordpress.org/plugins/twt-aeo-ultimate/#developers).

## Support

Questions and bug reports: the [WordPress.org support forum](https://wordpress.org/support/plugin/twt-aeo-ultimate/) or [GitHub issues](https://github.com/tampawebtech/twt-aeo-ultimate/issues).

## License

GPLv2 or later. Includes [smalot/pdfparser](https://github.com/smalot/pdfparser) (LGPL-3.0) for reading PDFs.

Made by [Tampa Web Technologies](https://tampawebtech.com/).
