# Playbooks

Six worked analyses. Each names the tools to call and the questions the output
has to answer. Use one when the request is broad; use single tools when the
question is narrow.

All six are **read-only**. None of them changes anything. Where a finding has a
fix the connector could apply, name the tool and let the user decide — then
follow the two-step protocol in `tools.md`.

Finish every one of these the same way: show the numbers each conclusion rests
on, then **the five actions that would help most, in order**, each tied to the
page or question it applies to.

---

## 1. AI visibility deep dive

*"Am I showing up in AI answers, and why not?"*

Call `export_data` with `dataset: ai_visibility` and `run: all`, following
`next_offset` until you have every row. Save it to `aeo-exports/` if you can
write files (see `SKILL.md`). Then
`get_competitor_citations` and `get_content_gaps` to explain the gaps.

Work out:

- The citation rate — overall, per engine, per question level and family, and
  **per run over time**. Exclude `unavailable` from the denominator and say so;
  see the four verdicts in `tools.md`.
- Which questions no engine cites the site for at all
- Which other domains are cited most, and on which questions
- Whether answers that name the business still fail to link to it (mentioned but
  not cited is a distinct, fixable problem)
- What the engines' predicted follow-up questions have in common

The per-run trend is the part a summary cannot give you. A 40% citation rate
that was 25% last month is a success story; the same number falling from 60% is
an emergency.

---

## 2. Crawler and referral analysis

*"Do AI systems read my site, and does it send me anyone?"*

Export `ai_crawler_log` and `ai_referrals` (follow `next_offset`), then call
`get_uncrawled_pages` and `get_funnel_audit`.

Work out:

- Visits per bot and per day, and the pages each bot reads most
- Whether crawlers fetch `robots.txt`, `llms.txt`, and the Markdown versions of
  pages — if they never fetch those, the AI Ready features are not earning
  their keep
- Which pages AI answers send people to, and from which engines
- Important pages no AI crawler has visited
- Whether the pages AI reads link onward to the pages that make money

Check the window in `get_ai_crawler_activity` before calling anything "never".

---

## 3. Full AEO health report

*"How is my site doing?"* — the one to reach for on "audit my site".

`get_site_overview` first. Export `page_scores` and `index_status`. Then
`get_eeat_scorecard`, `get_ai_readiness`, `get_schema_conflicts`,
`get_faq_schema_status`, `get_image_alt_gaps` and `get_ai_visibility_summary`.

Cover:

- How readiness is **spread across pages**, not just the average — twenty good
  pages and eighty bad ones averages the same as a hundred mediocre ones, and
  needs a completely different plan
- Which categories hold the site back
- Pages Google has not indexed, or has not re-crawled in 90+ days, and the
  likely cause of each
- Missing trust signals
- Whether schema and AI-agent features are set up well

Write it for a business owner: short summary first, then the detail.

---

## 4. Quick wins

*"What's the fastest thing I can do?"*

`get_lowest_scoring_pages`, `get_image_alt_gaps`, `get_faq_schema_status`,
`get_404_report`, `get_eeat_scorecard`, `get_schema_conflicts`.

List up to ten changes ranked by impact for the effort. For each, say which tool
would make it (`set_meta_description`, `set_image_alt_text`, `sync_faq_schema`,
`add_404_redirect`, `update_company_profile` and so on) and **exactly what it
would write**.

Then stop. Make no change. Wait for the user to pick, and apply them one at a
time, each with its own preview and its own approval.

---

## 5. Competitor gap analysis

*"Who gets cited instead of me?"*

`get_competitor_citations`, then `export_data` with `dataset: ai_visibility` for
every question another domain won, then `get_content_gaps`. For the questions
lost most often, use `search_content` to check whether the site or its uploaded
documents **already** answer them.

Group the losses by theme, name the competing domains in each, and for each
theme say whether the fix is:

- new content,
- improving an existing page — name it, or
- making an existing answer easier for AI engines to find.

That third case is the one people miss. If `search_content` finds a solid answer
already on the site, the content is not the problem; discoverability is.

Report who is cited as a factual finding. Do not editorialise about competitors.

---

## 6. Single-page deep dive

*"What's wrong with this page?"* — needs the URL.

`get_page_aeo_report`, `get_bot_view` and `get_image_alt_gaps` for that page.
Then look it up in `get_index_status`, `get_faq_schema_status`,
`get_uncrawled_pages`, and the `ai_referrals` and `ai_crawler_log` exports.

Explain what holds this page back from being cited by AI engines and found in
Google, in order of impact, and what to change.

`get_bot_view` is the one that finds the problems nobody expected — a firewall
serving bots a challenge page, or content that only exists after JavaScript
runs. If a page scores well and still gets no AI traffic, start there.
