# AEO Ultimate for WordPress

Ask your agent whether ChatGPT, Gemini, Claude and Perplexity cite your
WordPress site — and what to fix so they do.

This skill drives the MCP server built into the free
[AEO Ultimate](https://wordpress.org/plugins/twt-aeo-ultimate/) plugin, so the
answers come from your own site's data rather than a third-party estimate.

## What it does

- **AI citations** — per engine, which of your tracked questions got cited,
  mentioned without a link, or ignored, and how that has moved between runs
- **Competitor citations** — which domains get cited instead of you, and on
  which questions
- **AI crawlers** — GPTBot, ClaudeBot, PerplexityBot and others: what they read,
  how often, and whether they fetch your `robots.txt` and `llms.txt`
- **AI referrals** — the visits that actually arrive from an AI answer
- **Page readiness** — a scored breakdown per page with the highest-value fix on
  each
- **Google and Bing** — index status, pages not re-crawled in 90+ days, Search
  Console and Bing Webmaster performance, GA4's AI Assistant channel
- **Technical** — schema conflicts with other SEO plugins, FAQ schema drift,
  image alt-text gaps, 404s and redirects, E-E-A-T and author signals
- **Content gaps** — questions AI answered without citing you that your own
  pages or documents could have answered
- **CSV export** of every dataset, so analysis can go deeper than a dashboard

Six ready-made playbooks are included: AI visibility deep dive, crawler and
referral analysis, full AEO health report, quick wins, competitor gap analysis,
and single-page deep dive.

## Requirements

- A WordPress site with the free
  [AEO Ultimate](https://wordpress.org/plugins/twt-aeo-ultimate/) plugin, with
  the MCP connector switched on
- An administrator account and HTTPS
- Any MCP-capable agent

## Setup

1. Install the plugin. It appears in the WordPress admin menu as **TWT AEO**.
2. **TWT AEO → Settings → MCPs** — switch the connector on and create a
   WordPress Application Password. Copy it; WordPress shows it once.
3. Add the server to your agent's MCP configuration:

```json
{
  "mcpServers": {
    "aeo-ultimate-example-com": {
      "type": "http",
      "url": "https://example.com/wp-json/aeo/v1/owner-mcp",
      "headers": {
        "Authorization": "Basic BASE64_OF_USER_COLON_APP_PASSWORD",
        "X-AEO-Authorization": "Basic BASE64_OF_USER_COLON_APP_PASSWORD"
      }
    }
  }
}
```

Then ask your agent something like *"how is my site doing in AI answers?"*

`references/connect.md` covers this in full: how to build the header value, why
it is sent twice, how to bridge the server into agents that only launch local
processes, a `curl` command to confirm the connection, and the hosted OAuth
endpoint for clients that prefer a browser login.

No account and no subscription are needed, and there is no per-site limit — each
site is simply another entry in your config.

## Can it change my site?

Only if you let it. The connector is **read-only by default**. Writes are a
separate opt-in toggle, and even then every change previews first and applies
only after you approve that specific preview. The skill is written so your agent
cannot approve on your behalf.

## What's in here

| File | |
|---|---|
| `SKILL.md` | The instructions your agent reads |
| `references/connect.md` | Setup for every client type, and troubleshooting |
| `references/tools.md` | All 39 tools with arguments, and the approval protocol |
| `references/playbooks.md` | The six worked analyses |

## Links

- Plugin: [wordpress.org/plugins/twt-aeo-ultimate](https://wordpress.org/plugins/twt-aeo-ultimate/)
- Docs: [aeoultimate.com/docs/claude/](https://aeoultimate.com/docs/claude/)
- Home: [aeoultimate.com](https://aeoultimate.com/)

Free, like the plugin. Issues and suggestions are welcome.
