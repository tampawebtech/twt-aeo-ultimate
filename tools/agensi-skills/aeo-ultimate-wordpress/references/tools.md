# Tool reference

Bare tool names. Your client may prefix them — match against the tools it
actually exposes.

Many tools take **no arguments at all**. Where a tool targets a single page, it
accepts `post_id` (integer) **or** `url` (string) — pass one, not both.

## Read tools

Always available. Safe to call freely, subject to the hourly rate limit of 600
requests.

### Overview and scoring

| Tool | Args | Returns |
|---|---|---|
| `get_site_overview` | — | Site AEO score and trend, average page score, category percentages, site-level checklist gaps, five lowest-scoring pages, latest AI Visibility totals. **The usual first call.** |
| `get_lowest_scoring_pages` | `limit` | Worst-scoring published pages, each with the single highest-value fix. |
| `get_page_aeo_report` | `post_id` \| `url` | One page's full breakdown: score, each category, and every check not fully passing, with reason and suggested fix. |

### AI visibility and citations

| Tool | Args | Returns |
|---|---|---|
| `get_ai_visibility_summary` | — | Latest run: per engine (ChatGPT, Gemini, Claude and others), how many tracked questions cited the site, mentioned it without a link, or did not cite it — plus per-question results. **Four verdicts, not three** — see below. |
| `get_competitor_citations` | — | Which other domains the engines cited, how often, on which engines, and who won each question the site lost. |
| `get_tracked_questions` | — | The questions AI Visibility asks, each with `question_id` and enabled state, plus the buyer personas runs can use. |
| `get_content_gaps` | — | From the RAG Engine: questions answered without citing the site that the owner's documents could answer, and predicted follow-ups nothing answers yet. |
| `search_content` | `query` *(required)* | RAG Engine search across pages and uploaded documents — the passages that best answer a question. Use it to check whether an answer already exists somewhere before recommending new content. |

#### The four AI Visibility verdicts

`cited`, `mentioned`, `not_cited` — and **`unavailable`**, which means the engine
did not return a usable answer for that question at all (not configured, errored,
or refused). It is not a loss.

Divide by the wrong denominator and every number you report is wrong. State
which denominator you used. `cited / (cited + mentioned + not_cited)` is the
citation rate among questions that actually got answered; including
`unavailable` understates performance, sometimes badly. If `unavailable` is a
large share, that is itself the headline finding — the owner's engine setup
needs attention before any content work is worth doing.

`mentioned` deserves its own line in any report: the engine knew the business
and talked about it without linking. That is a different, usually easier problem
than not being known at all.

### Crawlers and referrals

| Tool | Args | Returns |
|---|---|---|
| `get_ai_crawler_activity` | `days` | Which AI crawlers visited (GPTBot, ClaudeBot, PerplexityBot and others), how often, last seen, pages read most, and whether they fetched `robots.txt` and `llms.txt`. **Returns a `window` field — read it.** |
| `get_uncrawled_pages` | `post_type`, `limit` | Published, indexable pages no AI crawler requested *within the log's window*. Not the same as never visited. |
| `get_ai_referrals` | `days` | Visits from people who clicked through from an AI answer: totals per engine and landing pages. |
| `get_bot_view` | `post_id` \| `url` | Loads the page as GPTBot and as a browser and compares: firewall blocks, bot challenges, JavaScript-only content, missing schema. Takes a few seconds. |
| `get_funnel_audit` | — | Money pages against the informational pages AI actually reads and sends visitors to, with AI referrals, crawls, inbound links and clicks from the homepage. |

### Search engines

| Tool | Args | Returns |
|---|---|---|
| `get_index_status` | — | From Search Console scans: how many pages Google indexed, which it did not (with Google's reason plus on-page problems found), and indexed pages not re-crawled in 90+ days. |
| `get_search_performance` | — | Search Console, last 30 days: clicks, impressions, CTR, average position, top queries. Needs Google connected in Command Center. |
| `get_bing_webmaster` | — | Bing Webmaster, six months: clicks, impressions, position, top queries, crawl issues by severity. Bing also feeds Copilot. Needs a Bing API key in Command Center. |
| `get_google_analytics` | — | GA4, last 30 days: sessions, users, page views, bounce rate, engagement, top pages, and the AI Assistant channel broken down by source. Needs Google connected. |

### Technical and trust

| Tool | Args | Returns |
|---|---|---|
| `get_eeat_scorecard` | — | Experience, expertise, authoritativeness and trust signals present and missing, plus the company profile and every publishing author's profile with `user_id`. |
| `get_ai_readiness` | — | Which AI-agent features are switched on: Markdown for AI agents, `llms.txt`, content signals, agent discovery files and the rest. |
| `get_schema_conflicts` | — | Reads the homepage's JSON-LD and reports where another SEO plugin emits the same schema type, which fields each version lacks, and whether the other copy is suppressed. Loads the homepage, so takes a few seconds. |
| `get_faq_schema_status` | — | Pages with FAQ content but no `FAQPage` schema, and pages whose FAQ schema no longer matches the questions on the page. |
| `get_image_alt_gaps` | `post_id` \| `url` | Without a page: posts and pages with images missing alt text, worst first. With a page: that page's images, each with `image_key`, file, media title and current alt — ready for `set_image_alt_text`. |
| `get_404_report` | — | Redirects in place, old URLs Google still knows that now 404 (with the closest live page), and broken internal links fixed automatically. |
| `get_change_log` | — | Last 25 changes made through the connector: when, by whom, before and after text, whether still undoable, each with a `change_id`. |

### Raw data

**`export_data`** — row-level CSV for real analysis rather than summaries.

| Arg | Notes |
|---|---|
| `dataset` *(required)* | `ai_visibility`, `ai_crawler_log`, `ai_referrals`, `page_scores`, `index_status`, `funnel_audit`, `score_history`, `change_log` |
| `run` | `ai_visibility` only: `all`, `latest`, or a run id |
| `include_answers` | `ai_visibility` only: adds the AI answer text. Large. |
| `offset`, `limit` | Pagination |

Large datasets come in pages. **Follow `next_offset` until it stops** — a
conclusion drawn from page one of many is wrong. Save to `aeo-exports/` (the
only directory this skill writes to) and analyse it
properly if your environment allows files.

## Write tools

These appear **only if the owner enabled writes** under **TWT AEO → Settings →
MCPs**. If you do not see them, the answer is a recommendation, not a change.

### The two-step protocol — read before using any of them

1. Call the tool **without** `confirm_token`. Nothing changes. You get a preview
   of the exact change and a `confirm_token`.
2. **Show the preview to the user in full** — the before and the after.
3. Only if they explicitly approve, call again with **identical arguments** plus
   that `confirm_token`.

The token is bound to those exact arguments, expires in about 15 minutes, and
represents the owner's approval of that specific preview. Never generate one,
never reuse one across different arguments, never call step 2 off your own
judgement, and never treat silence or a general "go ahead, fix everything" as
approval of a preview they have not seen. Change arguments, get a new preview.

Apply changes **one at a time**, each with its own preview and its own approval.
`undo_change` is the escape hatch, not a substitute for asking.

| Tool | Args | Does |
|---|---|---|
| `set_meta_description` | `post_id`\|`url`, `description` *(req)* | Writes one page's meta description. |
| `set_open_graph` | `post_id`\|`url`, `og_title`, `og_description` | Writes one page's Open Graph title and description. |
| `set_image_alt_text` | `post_id`\|`url`, `alts` *(req)* — array of `{image_key, alt}` | Writes alt text for images on a page. Get the keys from `get_image_alt_gaps` for that page first. |
| `sync_faq_schema` | `post_id`\|`url` | Regenerates a page's FAQ schema from the questions currently on it. |
| `add_404_redirect` | `from` *(req)*, `to_post_id` \| `to_url` | Adds a redirect for a URL that now 404s. |
| `suppress_duplicate_schema` | `plugin` *(req)*, `type` *(req)*, `suppress` *(req)* | Suppresses one duplicated schema type from one other plugin. Base it on `get_schema_conflicts`, never on a guess about what is installed. |
| `set_ai_ready_feature` | `feature` *(req)*, `on` *(req)* | Turns one AI Ready feature on or off. Use the feature slugs `get_ai_readiness` returns. |
| `update_company_profile` | `fields` *(req)* | Updates company profile fields behind the E-E-A-T signals. |
| `update_author_profile` | `user_id` *(req)*, `bio`, `job_title`, `credentials`, `expertise`, `years_experience`, `social` | Updates one author's profile. `user_id` comes from `get_eeat_scorecard`. **Never invent credentials, job titles or years of experience** — these are claims about a real person. Ask. |
| `edit_tracked_questions` | `add`, `switch_on`, `switch_off` | Adds tracked questions or toggles existing ones by `question_id`. |
| `set_buyer_personas` | `personas` *(req)* | Sets the personas AI Visibility runs can ask as. |
| `recheck_google_index` | `post_id` \| `url` | Asks Google to re-check one page's index status. |
| `run_ai_visibility_check` | `max_questions` | Runs a fresh AI Visibility check. **Spends the owner's AI API credits.** Say so in the preview, state how many questions, and never run it to "refresh" data unprompted. |
| `undo_change` | `change_id` *(req)* | Reverses a change from `get_change_log`, where still undoable. |

### Two that deserve extra care

- **`run_ai_visibility_check`** costs real money on the owner's own API keys.
  It is the one write tool whose cost is invisible in the result. Always name
  the question count in the preview.
- **`update_author_profile`** writes biographical claims about a real human
  being. Fill only what the user tells you or what already exists elsewhere in
  their data. A plausible-sounding invented credential is a serious error, not a
  helpful default.
