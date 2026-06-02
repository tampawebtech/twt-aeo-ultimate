# TWT AEO Ultimate

A WordPress plugin by [Tampa Web Technologies](https://tampawebtech.com).

**Version:** 2.0.1 — Install on each client site to monitor AI search visibility, audit structured data, and optionally connect to TWT Agency.

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Modules](#modules)
- [Settings](#settings)
- [Admin Pages](#admin-pages)
- [TWT Agency Integration](#twt-agency-integration)
- [OAuth Server](#oauth-server)
- [AI Crawler Logging](#ai-crawler-logging)
- [File Structure](#file-structure)
- [Development](#development)
- [Deployment](#deployment)
- [License](#license)

---

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.0+ |
| PHP | 7.4+ |
| WooCommerce | 6.0+ *(optional — required for WooCommerce modules)* |

No build step. No npm. No Composer. Drop and activate.

---

## Installation

### Manual

1. Copy the `twt-aeo-ultimate/` folder into `wp-content/plugins/`
2. Activate via **Plugins → Installed Plugins**
3. Access the plugin at **AEO Ultimate** in the WordPress admin sidebar

### Via Git (recommended for dev)

```bash
cd /path/to/wp-content/plugins/
git clone git@github.com:jadeslair-svg/twt-aeo-ultimate.git
```

### Via SSH (production)

```bash
cd /path/to/wp-content/plugins/twt-aeo-ultimate/
git pull origin main
```

### Via WordPress Dashboard

Download a zip from GitHub → **Plugins → Add New → Upload Plugin**.

---

## Modules

Modules are toggled individually at **AEO Ultimate → Modules**. State is persisted in `wp_options` (`twt_aeo_active_modules`). Modules marked **default on** are enabled automatically on first activation.

### Free Modules

| Slug | Title | Default | Description |
|---|---|---|---|
| `schema-advisor` | Schema Advisor | On | Detects schema on each page and evaluates it against page intent. Flags missing or incorrect schema without false warnings. |
| `seo-compatibility` | SEO Plugin Compatibility | On | Detects your active SEO plugin (Yoast, Rank Math, AIOSEO, etc.) and checks what schema it already handles to prevent duplication or conflicts. |
| `page-intent` | Page Intent Classifier | On | Classifies each page as homepage, service, blog post, location, or product so all advice is context-relevant. |
| `author-box` | Author Box | On | Detects whether the theme displays a front-facing author box and author schema. Recommends fixes when missing. |
| `contact-detector` | Contact Detector | On | Scans contact pages for `Organization`, `PostalAddress`, and `ContactPoint` schema. Flags missing entity data and consolidation issues. |
| `author-entity` | Author Entity | On | Manage author E-E-A-T profiles, certifications, and social `sameAs` links. Outputs `Person` schema with `EducationalOccupationalCredential`. Adds AEO fields to Users → Profile. |
| `author-schema` | Author Schema | Off | Detects whether the theme displays front-facing author information and adds author schema only when appropriate and missing. |
| `social-graph` | Social Graph | Off | Unified Open Graph and Twitter/X Card manager. Scans all pages for OG coverage, shows missing fields at a glance, and lets you edit `og:title`, `og:description`, `og:image`, `og:type`, `twitter:card`, and `twitter:creator` in one modal. Auto-fills `twitter:creator` from Author Entity schema. |
| `faq-detector` | FAQ Detector | Off | Scans pages for accordion blocks, FAQ sections, and Q&A content. If found without `FAQPage` schema, flags it and can inject the schema automatically. |
| `service-detector` | Service Detector | Off | Scans pages, posts, and WooCommerce products for service-oriented content. Flags pages missing `Service` schema and rescans automatically on save. |
| `author-hover-cards` | Author Hover Cards | Off | Shows a lightweight author preview card when visitors hover over author links on posts. Displays bio, job title, certifications, expertise tags, and social profiles. |
| `local-pack` | Local Pack | Off | Build `LocalBusiness` schema with a predictive business-type picker, opening hours, service area, and geo coordinates. Audits NAP consistency against Google Knowledge Graph, Google Business Profile, and Bing Maps. |
| `sitemap` | Sitemap Generator | Off | Generates and serves an XML sitemap at `/aeo-sitemap.xml`. Configurable per post type with change frequency and priority. Cache invalidates automatically on content changes. Warns when Yoast, Rank Math, or AIOSEO already provides a sitemap. |
| `pr-bridge` | PR Bridge AI | Off | Detects press release intent in posts, transforms them into AP-style copy using Claude, and distributes to wire services (EIN Presswire, EasyPRWire). Includes a Media Report for tracking distribution status. |
| `index-now` | IndexNow | Off | Instantly notifies Bing, Yandex, and all IndexNow-compatible search engines when content is published or updated. Generates and hosts the verification key automatically — no file upload required. |
| `news` | Google News | Off | Generates a Google News sitemap at `/news-sitemap.xml`, outputs `NewsArticle` JSON-LD with full Author Entity integration, and adds a per-post metabox for article section, keywords, and sitemap exclusion. |
| `last-updated-badge` | Last Updated Badge | Off | Prepends a visible "Last Updated: [date]" line to single blog posts so AI models and readers always see an explicit freshness signal. Only shown when the post has been modified after its original publish date. |
| `reviews` | Reviews | Off | Collect and manage customer reviews via a custom post type. Outputs context-aware `AggregateRating` and `Review` schema — automatically scoped to `LocalBusiness`, `Product`, `Service`, or `Organization` based on active modules. Includes a frontend shortcode for review display and submission. |
| `woocommerce-detector` | WooCommerce | Off | Scans WooCommerce products for schema presence. Classifies products as physical or service, flags missing `Product` or `Service` schema, and rescans on save. *Requires WooCommerce.* |
| `woocommerce-gmc` | Google Merchant Center Sync | Off | Compares your WooCommerce catalog against your live Google Merchant Center feed. Tracks price and availability mismatches, GTIN/MPN/SKU gaps, feed sync latency, and surfaces rejection error codes directly from the Content API. *Requires WooCommerce.* |
| `woocommerce-bmc` | Bing Merchant Center Sync | Off | Compares your WooCommerce catalog against your live Bing Merchant Center feed via the Microsoft Advertising Shopping Content API. Tracks mismatches, GTIN/MPN/SKU gaps, sync latency, and item-level disapproval codes. *Requires WooCommerce.* |

### Pro Modules

| Slug | Title | Description |
|---|---|---|
| `content-generator` | Content Generator | Generate page content using Claude (body copy), OpenAI (concise summary), and Perplexity (deep research). Industry and subtopic selection tailors prompts to your use case. |
| `pro-reporting` | Agency Reporting | Connect client sites to TWT Agency. Track work done, correlate it to traffic changes, and generate monthly reports. |

---

## Settings

Settings are stored in the `twt_aeo_settings` WP option. All fields are under **AEO Ultimate → Settings**.

### AI API Keys

Used by the **Content Generator** module. Each key is optional — only providers with a key configured will generate content.

| Field | Provider | Role |
|---|---|---|
| `api_claude` | Anthropic Claude | Body copy generation |
| `api_openai` | OpenAI (ChatGPT) | Concise summary generation |
| `api_perplexity` | Perplexity | Deep research synthesis |

### Wire Service API Keys

Used by the **PR Bridge AI** module for press release distribution.

| Field | Service | Best For |
|---|---|---|
| `api_ein_presswire` | EIN Presswire | SMBs and agencies |
| `api_easypwire` | EasyPRWire | Niche and local distribution |

### TWT Agency

Connects the client site to TWT Agency for agency monitoring and reporting.

| Field | Description |
|---|---|
| `pro_enabled` | Toggle to enable the connection |
| `pro_url` | The ingest endpoint URL provided by your SEO professional |
| `pro_key` | API key provided by your SEO professional |

The plugin syncs a plugin status snapshot (site URL, plugin version, WP version, active modules) to the dashboard once daily via `wp_cron`.

**Pricing:** Starter — up to 20 sites, $49/yr · Agency — unlimited sites, $99/yr.

### Usage Telemetry

Opt-in anonymous token telemetry to help TWT publish real-world AI cost estimates. Toggle is `token_telemetry`.

**What is collected:** provider, model name, input/output token counts, cache hit/miss counts (Claude only), plugin version.

**What is never collected:** page titles, content, site URL, site name, API keys, user or account information.

---

## Admin Pages

The plugin registers a top-level **AEO Ultimate** menu in the WordPress admin sidebar with the following pages:

| Page | Class | Description |
|---|---|---|
| Dashboard | `TWT_AEO_Page_Dashboard` | Overview of active modules and site health |
| Modules | `TWT_AEO_Page_Modules` | Toggle modules on/off |
| Settings | `TWT_AEO_Page_Settings` | API keys, TWT Agency connection, telemetry |
| Command Center | `TWT_AEO_Page_Command_Center` | Google and Bing Merchant Center sync controls |
| Not Indexed | `TWT_AEO_Page_Not_Indexed` | Pages flagged as not indexed by crawlers or heuristics |
| AI Ready | `TWT_AEO_Page_AI_Ready` | Audit and configure AI-readiness signals (OAuth, robots.txt, structured data) |
| E-E-A-T | `TWT_AEO_Page_EEAT` | Scored audit across Experience, Expertise, Authoritativeness, and Trustworthiness signals |
| Schema Conflicts | `TWT_AEO_Page_Schema_Conflicts` | Detects schema duplication between this plugin and your active SEO plugin |
| Social Graph | `TWT_AEO_Page_Social_Graph` | OG/Twitter Card scanner and editor |
| Author | `TWT_AEO_Page_Author` | Author schema management |
| Author Entity | `TWT_AEO_Page_Author_Entity` | E-E-A-T author profiles and Person schema |
| Author Box | `TWT_AEO_Page_Author_Box` | Author box detection results |
| Local Pack | `TWT_AEO_Page_Local_Pack` | LocalBusiness schema builder and NAP audit |
| IndexNow | `TWT_AEO_Page_Index_Now` | IndexNow key status and submission log |
| Google News | `TWT_AEO_Page_News` | News sitemap and NewsArticle schema settings |
| Reviews | `TWT_AEO_Page_Reviews` | Customer review management |
| Sitemap | `TWT_AEO_Page_Sitemap` | XML sitemap configuration |
| PR Bridge | `TWT_AEO_Page_PR_Bridge` | Press release management and distribution log |
| FAQ Detector | `TWT_AEO_Page_FAQ_Detector` | Scan results for FAQ/Q&A content |
| Service Detector | `TWT_AEO_Page_Service_Detector` | Service schema scan results |
| OG Detector | `TWT_AEO_Page_OG_Detector` | Open Graph coverage scan |
| Contact Detector | `TWT_AEO_Page_Contact_Detector` | Contact/entity schema scan results |
| WooCommerce | `TWT_AEO_Page_WooCommerce_Detector` | Product schema scan results |
| Company | `TWT_AEO_Page_Company` | Company/Organization profile and schema |
| Content Generator | `TWT_AEO_Page_Content_Generator` | AI content generation interface |

---

## TWT Agency Integration

When `pro_enabled` is set and valid credentials are configured, the **Pro Transmitter** (`TWT_AEO_Pro_Transmitter`) sends structured JSON payloads to the TWT Agency endpoint. All transmissions are fire-and-forget with a 10-second timeout and use an `X-TWT-API-Key` header for authentication.

### Transmitted Event Types

| Event | When Sent |
|---|---|
| `plugin_status` | Daily via `wp_cron` — site URL, plugin version, WP version, active modules |
| `schema_added` | When any schema writer injects new JSON-LD |
| `schema_changed` | When existing schema is modified |
| `deindex_report` | When the Index Status module finds de-indexed pages |
| `ai_bot_event` | When a known AI crawler visits the site |
| `indexnow_submitted` | After an IndexNow URL submission |
| `schema_conflict` | When the Schema Conflict Detector finds a duplicate |
| `micro_conversion` | When a micro-conversion event is tracked |
| `gsc` | When a Google Search Console snapshot is captured |
| `analytics` | When an analytics snapshot is captured |

---

## OAuth Server

The plugin includes a built-in OAuth 2.0 authorization server (client credentials grant) for securing API access to AI agents and external tools.

**Endpoints:**

| Method | Path | Description |
|---|---|---|
| `POST` | `/wp-json/aeo/v1/oauth/token` | Issue a Bearer token |
| `GET` | `/wp-json/aeo/v1/oauth/jwks` | JSON Web Key Set (for discovery) |

**Key behaviors:**
- Tokens are opaque random strings stored as WP transients with a 1-hour TTL
- Client secrets are hashed with `wp_hash_password()` (bcrypt)
- Rate limiting: 5 attempts per 15-minute window before a 15-minute lockout
- The OAuth server only registers routes when enabled in AI Ready settings

Clients are managed from the **AI Ready** page under the Command Center.

---

## AI Crawler Logging

The plugin detects and logs visits from known AI and LLM crawlers in real time. Logs are stored in `wp_options` (capped at 500 entries, pruned daily). An admin notice fires when a new AI bot is detected for the first time.

**Tracked crawlers include:**

| Bot | Company |
|---|---|
| GPTBot, ChatGPT-User, OAI-SearchBot | OpenAI |
| Claude-Web, ClaudeBot, anthropic-ai | Anthropic |
| Google-Extended, Google-NotebookLM, GoogleOther | Google |
| PerplexityBot | Perplexity |
| Applebot-Extended | Apple |
| Bytespider | ByteDance |
| FacebookBot, meta-externalagent | Meta |
| Amazonbot | Amazon |
| CCBot | Common Crawl |
| Diffbot | Diffbot |
| cohere-ai | Cohere |
| PetalBot | Huawei |
| AI2Bot | Allen Institute for AI |
| YouBot | You.com |
| omgilibot, omgili | Webz.io |

---

## File Structure

```
twt-aeo-ultimate/
├── twt-aeo-ultimate.php               # Plugin header, constants, bootstrap
├── uninstall.php                      # Clean uninstall — removes all options
│
├── includes/
│   ├── class-plugin.php               # Hook registration, plugin lifecycle
│   ├── class-activator.php            # Activation logic and option seeding
│   ├── class-deactivator.php          # Deactivation cleanup
│   ├── class-module-loader.php        # Module registry and toggle state
│   ├── class-seo-compatibility.php    # SEO plugin detection
│   ├── class-page-intent.php          # Page intent classification
│   ├── class-scan-store.php           # Shared scan result cache
│   ├── class-faq-detector.php         # FAQ/Q&A content detection
│   ├── class-service-detector.php     # Service content detection
│   ├── class-author-box-detector.php  # Author box detection
│   ├── class-author-meta.php          # Author meta helpers
│   ├── class-author-schema-writer.php # Person schema output
│   ├── class-author-hover.php         # Author hover card frontend
│   ├── class-contact-detector.php     # Contact page entity detection
│   ├── class-woocommerce-detector.php # WooCommerce product schema detection
│   ├── class-service-schema-writer.php# Service schema output
│   ├── class-custom-schema-writer.php # Custom JSON-LD output
│   ├── class-company-profile.php      # Company profile data model
│   ├── class-company-og-writer.php    # Company Open Graph output
│   ├── class-company-schema-writer.php# Organization schema output
│   ├── class-pr-detector.php          # Press release intent detection
│   ├── class-pr-transformer.php       # AP-style press release rewrite via Claude
│   ├── class-pr-distributor.php       # Wire service distribution
│   ├── class-industry-config.php      # Industry vertical configuration
│   ├── class-content-generator.php    # Multi-provider AI content generation
│   ├── class-hub-cpt.php              # Hub custom post type
│   ├── class-html-to-markdown.php     # HTML → Markdown conversion for AI
│   ├── class-crawler-tester.php       # Crawlability testing
│   ├── class-schema-conflict-detector.php # Schema duplication detection
│   ├── class-ai-ready.php             # AI-readiness audit and settings
│   ├── class-oauth-server.php         # OAuth 2.0 authorization server
│   ├── class-pro-transmitter.php      # TWT Agency sync
│   ├── class-rest-remote-generate.php # REST endpoint for remote content generation
│   ├── class-micro-conversion-tracker.php # Micro-conversion event tracking
│   ├── class-ai-crawler-logger.php    # AI bot visit detection and logging
│   ├── class-index-heuristics.php     # Indexability heuristics
│   ├── class-index-status.php         # De-indexed page tracking
│   ├── class-local-pack.php           # LocalBusiness schema builder
│   ├── class-local-pack-nap.php       # NAP consistency audit
│   ├── class-og-detector.php          # Open Graph coverage scanner
│   ├── class-og-writer.php            # Open Graph tag output
│   ├── class-twitter-writer.php       # Twitter/X Card output
│   ├── class-index-now.php            # IndexNow key hosting and submission
│   ├── class-eeat-detector.php        # E-E-A-T signal scanner
│   ├── class-last-updated-badge.php   # Last Updated freshness badge
│   ├── class-sitemap-generator.php    # XML sitemap generation
│   ├── class-news-sitemap.php         # Google News sitemap generation
│   ├── class-news-meta.php            # News article meta fields
│   ├── class-news-schema-writer.php   # NewsArticle schema output
│   ├── class-reviews-cpt.php          # Reviews custom post type
│   ├── class-reviews-schema-writer.php# AggregateRating / Review schema output
│   ├── class-reviews-shortcode.php    # [reviews] shortcode
│   │
│   └── Integrations/
│       ├── class-google-oauth.php         # Google OAuth 2.0 client
│       ├── class-google-merchant-center.php # Google Merchant Center API
│       ├── class-bing-merchant-center.php   # Bing Merchant Center API
│       └── class-bing-webmaster.php         # Bing Webmaster Tools API
│
├── admin/
│   ├── class-admin-menu.php           # Top-level menu and subpage registration
│   ├── class-admin-assets.php         # CSS/JS enqueue
│   ├── class-metabox.php              # Post metabox (AEO Hub fields)
│   ├── class-pr-metabox.php           # Press release metabox
│   ├── class-hub-metabox.php          # Hub CPT metabox
│   ├── class-gmc-sync-engine.php      # GMC sync orchestration
│   ├── class-bmc-sync-engine.php      # BMC sync orchestration
│   │
│   └── pages/
│       ├── class-page-dashboard.php
│       ├── class-page-modules.php
│       ├── class-page-settings.php
│       ├── class-page-command-center.php
│       ├── class-page-not-indexed.php
│       ├── class-page-ai-ready.php
│       ├── class-page-eeat.php
│       ├── class-page-schema-conflicts.php
│       ├── class-page-social-graph.php
│       ├── class-page-og-detector.php
│       ├── class-page-author.php
│       ├── class-page-author-entity.php
│       ├── class-page-author-box.php
│       ├── class-page-local-pack.php
│       ├── class-page-index-now.php
│       ├── class-page-news.php
│       ├── class-page-reviews.php
│       ├── class-page-sitemap.php
│       ├── class-page-pr-bridge.php
│       ├── class-page-faq-detector.php
│       ├── class-page-schema-detector.php
│       ├── class-page-service-detector.php
│       ├── class-page-contact-detector.php
│       ├── class-page-woocommerce-detector.php
│       ├── class-page-company.php
│       └── class-page-content-generator.php
```

---

## Development

### Branching Convention

| Branch | Purpose |
|---|---|
| `main` | Stable, production-ready |
| `dev` | Active development |
| `feature/*` | Individual features before merging to dev |

### Constants

Defined in `twt-aeo-ultimate.php`:

| Constant | Value |
|---|---|
| `TWT_AEO_VERSION` | `1.0.17` |
| `TWT_AEO_PLUGIN_DIR` | Absolute server path to the plugin directory |
| `TWT_AEO_PLUGIN_URL` | Public URL to the plugin directory |

### Key WP Options

| Option | Description |
|---|---|
| `twt_aeo_settings` | All plugin settings (API keys, Pro credentials, toggles) |
| `twt_aeo_active_modules` | Array of active module slugs |
| `twt_aeo_pro_last_sync` | Timestamp of last successful TWT Agency sync |
| `twt_aeo_crawler_log` | Rolling AI crawler visit log (max 500 entries) |
| `twt_aeo_eeat_scan` | Cached E-E-A-T scan result |
| `twt_aeo_indexnow_key` | Auto-generated IndexNow verification key |
| `twt_aeo_oauth_clients` | Registered OAuth 2.0 clients |

---

## Deployment

### Manual upload

Download a zip from GitHub → **Plugins → Add New → Upload Plugin**.

### Via SSH

```bash
cd /path/to/wp-content/plugins/twt-aeo-ultimate/
git pull origin main
```

---

## License

Proprietary. All rights reserved — Tampa Web Technologies.
