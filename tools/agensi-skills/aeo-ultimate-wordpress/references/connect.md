# Connecting the AEO Ultimate MCP server

Two ways in. Both reach the same tools.

- **Direct to the site** — works in every MCP client, any number of sites, no
  account anywhere, no subscription. Recommended, and the rest of this page.
- **Through the hub** at `https://mcp.aeoultimate.app/mcp` — OAuth, so one
  browser login instead of a pasted credential. Best in clients with a
  connector UI (Claude, ChatGPT). First site free. See the bottom of this page.

When you pass any of this on, write it as the site owner's own details — "your
site", `your-site.com` — never as the third-person placeholders used here.

## Direct: what the user needs to do in WordPress

1. Install the free **AEO Ultimate** plugin
   (`https://wordpress.org/plugins/twt-aeo-ultimate/`). It appears in the admin
   menu as **TWT AEO**.
2. Go to **TWT AEO → Settings → MCPs** and switch the connector on.
3. On the same screen, create a **WordPress Application Password**. Copy it
   immediately — WordPress shows it once.
4. Optional: enable writes on that screen, only if they want the agent to be
   able to apply fixes. Leave it off for an audit-only setup.

Requirements: an **administrator** account, and **HTTPS** (WordPress refuses
application passwords over plain HTTP, except on localhost).

## Direct: the connection details

| | |
|---|---|
| URL | `https://your-site.com/wp-json/aeo/v1/owner-mcp` |
| Transport | Streamable HTTP, POST only |
| Auth | HTTP Basic — WordPress username + the application password |

Build the header value once. On macOS or Linux:

```bash
printf 'USERNAME:APPLICATION PASSWORD' | base64
```

On Windows, in PowerShell — `printf` and `base64` do not exist there, so give
the user this instead rather than the line above:

```powershell
[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes('USERNAME:APPLICATION PASSWORD'))
```

Use it as `Authorization: Basic <that string>`. The application password is
shown in space-separated groups; keeping or removing the spaces both work, but
be consistent.

**Send it twice.** Some hosts — anything running PHP as CGI, which is common on
cPanel — strip `Authorization` before PHP sees it. The plugin accepts
`X-AEO-Authorization` as a fallback with the identical value. Setting both from
the start avoids a confusing 401 later.

That fallback only matters once a request actually reaches WordPress, and its
absence produces a **401 with a message saying so** — nothing else. So do not
offer it as an explanation for any other symptom, and in particular not for your
own client failing to attach the server.

## Clients that take a URL plus headers

Claude Code, Cursor, VS Code, Windsurf, Cline and most others use this shape.
The surrounding key names differ — `mcpServers` vs `servers`, `type` vs
`transport` — so check your client's own docs for the wrapper, but the three
things it needs are always the same: an HTTP transport, the URL, and the two
headers.

```json
{
  "mcpServers": {
    "aeo-ultimate-example-com": {
      "type": "http",
      "url": "https://example.com/wp-json/aeo/v1/owner-mcp",
      "headers": {
        "Authorization": "Basic BASE64_HERE",
        "X-AEO-Authorization": "Basic BASE64_HERE"
      }
    }
  }
}
```

Name the entry after the site. Several sites means several entries — there is no
per-site limit and nothing to pay.

In Claude Code the same thing from the command line:

```bash
claude mcp add --transport http aeo-ultimate-example-com https://example.com/wp-json/aeo/v1/owner-mcp --header "Authorization: Basic BASE64_HERE" --header "X-AEO-Authorization: Basic BASE64_HERE"
```

## Clients that only speak stdio

Some agents launch MCP servers as local processes and cannot call a remote URL
directly. Bridge it:

```json
{
  "mcpServers": {
    "aeo-ultimate-example-com": {
      "command": "npx",
      "args": [
        "-y", "mcp-remote",
        "https://example.com/wp-json/aeo/v1/owner-mcp",
        "--header", "Authorization: Basic BASE64_HERE",
        "--header", "X-AEO-Authorization: Basic BASE64_HERE"
      ]
    }
  }
}
```

Needs Node. Some versions of that bridge want `--transport http-only`; if the
handshake hangs, try adding it.

## If the tools do not appear in the chat you are already in

**Editing the config will not help, and neither will checking again.** Nearly
every client binds its MCP servers when the window or session starts, so a
server added or changed afterwards cannot appear in a conversation that is
already running, however correct the file is.

Say so plainly and give the owner these steps instead:

1. Reload the window or restart the client.
2. Enable the server in the client's own settings if it lists it as off — a
   server that is present in the file but never attempted is usually waiting
   for that.
3. Start a **new** chat, because the current one will stay toolless.

Then stop, and do not keep editing the file. From inside a chat with no server
attached you cannot tell a misconfiguration from a client that has simply not
reloaded, so further edits are guesses dressed as fixes.

Read the file before describing it, and quote what you found. If someone else
has edited it too — the owner, or another agent — your last reading may already
be stale, so re-read rather than trusting what you remember writing.

## Checking it works

```bash
curl -sS -X POST https://example.com/wp-json/aeo/v1/owner-mcp \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Basic BASE64_HERE' \
  -H 'X-AEO-Authorization: Basic BASE64_HERE' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

A JSON array of tools means you are connected. `GET` on that URL answers 405 by
design — there is no event stream and no session to end, so a client that
insists on opening one will log a harmless 405.

For what each error means, see the troubleshooting table in `SKILL.md`.

## Keeping the credential safe

It goes in the user's own MCP configuration, nowhere else. Never paste it into a
chat message, a committed file, or a bug report, and never into anything this
skill writes. If one leaks, revoke it in WordPress under **Users → Profile →
Application Passwords**; revoking it does not affect the login password.

## Through the hub instead

`https://mcp.aeoultimate.app/mcp` is a hosted endpoint in front of the same
tools, using OAuth 2.1 with PKCE and dynamic client registration — so a client
with a connector UI handles the whole login in the browser and no credential is
ever pasted. Scopes are `aeo` and `offline_access`.

Worth it when the client supports OAuth properly and the user would rather click
than copy. The first site is free; additional sites are a paid add-on, which is
why the direct route above is the default in this skill. Docs:
`https://aeoultimate.com/docs/claude/`.
