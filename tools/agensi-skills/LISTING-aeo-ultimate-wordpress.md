# Agensi listing draft — AEO Ultimate for WordPress

Not part of the skill payload. Submit only the `aeo-ultimate-wordpress/` folder;
this file is the copy to paste into the submission form.

- **Price:** Free
- **Category:** Marketing & SEO
- **Suggested tags:** aeo, answer-engine-optimization, ai-visibility, wordpress,
  seo, schema, mcp, chatgpt, llms-txt, geo

## Title

AEO Ultimate for WordPress — audit your AI answer visibility

## Short description

Ask your agent whether ChatGPT, Gemini, Claude and Perplexity cite your
WordPress site — and what to fix so they do. Reads first-party AEO data straight
from the site over MCP: AI citations, AI crawler visits, Google indexing, page
readiness, schema, E-E-A-T and content gaps.

## Long description

Rank tracking tells you where you sit in a list of blue links. It tells you
nothing about whether an AI engine named you when someone asked it a question.
This skill answers that, from your own site's data rather than a third-party
estimate.

It drives the MCP server built into the free
[AEO Ultimate](https://wordpress.org/plugins/twt-aeo-ultimate/) WordPress
plugin, which gives your agent 25 read tools covering:

- **AI citations** — per engine, which tracked questions cited you, mentioned
  you without a link, or ignored you, and how that has moved between runs
- **Competitor citations** — which domains get cited instead, on which questions
- **AI crawlers** — GPTBot, ClaudeBot, PerplexityBot and others: what they read,
  how often, and whether they fetch your `robots.txt` and `llms.txt`
- **AI referrals** — the visits that actually arrive from an AI answer
- **Page readiness** — a scored breakdown per page, with the single
  highest-value fix on each
- **Google and Bing** — index status, pages not re-crawled in 90+ days, Search
  Console and Bing Webmaster performance, GA4's AI Assistant channel
- **Technical** — schema conflicts with other SEO plugins, FAQ schema drift,
  image alt-text gaps, 404s and redirects, E-E-A-T and author signals
- **Content gaps** — questions AI answered without citing you that your own
  pages or documents could have answered
- **Raw CSV export** for every dataset, so the analysis can go deeper than a
  dashboard

Six ready-made playbooks ship with the skill: AI visibility deep dive, crawler
and referral analysis, full AEO health report, quick wins, competitor gap
analysis, and single-page deep dive.

If the site owner opts in, 14 further tools can apply fixes — meta descriptions,
Open Graph text, alt text, FAQ schema, redirects, schema suppression, profile
fields. Writes are **off by default**, and every one of them previews first and
applies only on explicit approval, so an agent cannot change a live site on its
own initiative.

### Requirements

- A WordPress site with the free
  [AEO Ultimate](https://wordpress.org/plugins/twt-aeo-ultimate/) plugin
  installed and the MCP connector switched on
- An administrator account and HTTPS
- Any MCP-capable agent

Setup is a URL plus an application password you generate in WordPress — no
account, no subscription, and no per-site limit. Full instructions ship inside
the skill, and there is a hosted OAuth endpoint for clients that prefer a browser
login. Docs: [aeoultimate.com/docs/claude/](https://aeoultimate.com/docs/claude/).

### Why free

The plugin is free and so is this. More sites with real AEO data is the point.
More at [aeoultimate.com](https://aeoultimate.com/).

## Submission checklist

- [ ] Tested in Claude Code
- [ ] Tested in a second agent (Agensi asks for at least two — Cursor or Codex
      CLI is the obvious pair)
- [ ] Tested against a site with writes **off** (the common case — confirm the
      skill degrades to recommendations)
- [ ] Tested against a site with writes **on** (confirm the preview/approve loop
      holds and the agent does not self-confirm)
- [ ] No credentials anywhere in the bundle
- [ ] No scripts in the bundle — markdown only, which keeps the security scan
      clean
- [ ] Frontmatter is `name` + `description` only, for cross-agent compatibility

## Roadmap

Siblings to add later as `aeo-ultimate-shopify/` and `aeo-ultimate-wix/`, both
free for the same reason. Keep the structure identical so the shared parts —
two-step protocol, reporting rules, playbook shapes — stay in step.

## Form field answers

- **Other tool / domain:** `aeoultimate.com`
- **File scopes:** `aeo-exports/**`

### Permissions notes

This skill makes no network calls of its own and contains no scripts — it is
markdown instructions only. All data access happens through the AEO Ultimate MCP
server, which the site owner configures themselves in their own agent's MCP
settings; no credential is included in or read by this bundle.

The connector requires a WordPress administrator account and HTTPS, and is
read-only by default. Write tools appear only if the owner enables them in a
separate WordPress setting. Every write is two-step: the first call returns a
preview and changes nothing, and the change applies only when the owner approves
that specific preview. The skill instructs the agent never to approve on the
owner's behalf. All changes are logged and reversible via an undo tool.

The only filesystem write is optional CSV export to `aeo-exports/` in the
working directory.

Additional notes if the form allows them: the skill treats everything the server
returns — page text, AI answers, competitor content — as data rather than
instructions, which matters because some of it originates from third-party AI
engines; and it is told never to invent author credentials or years of
experience, since one write tool edits biographical claims about real people.

### Affiliate link

`https://www.agensi.io/r/AEOULTIMATE` — 20% of every purchase a referred buyer
makes (skills, bundles, subscription renewals) for 6 months from signup, no cap,
$3.50 minimum payout. Deep-link with `?to=/skills/<slug>`.

Marketing pages only: never in the plugin, the plugin docs or the wordpress.org
readme. Full reasoning and the article placement rules are in
`Desktop/Agensi Skill/AFFILIATE-LINKS.md`.
