# Security regression tests

Each file here reproduces a vulnerability that was actually shipped, and
asserts both that it is fixed and that the legitimate behaviour it guards
still works. They are not unit tests — they run against a real WordPress
install, create their own posts, and clean up after themselves.

**These never ship to wordpress.org.** `deploy_release.py` holds back
everything under `tests/` via `EXCLUDE_PREFIXES`, so they stay in git without
landing in a release. If you move or rename this directory, update that list.

## The tests

| File | Guards against | Fixed in |
|---|---|---|
| `okf-protected-posts.php` | Password-protected posts served in full through `/okf/` and `llms-full.txt` | 2.33.1 |
| `mcp-content-access.php` | MCP `get_page_content` serving drafts, private and password-protected posts, enumerable via `?p=<id>` | 2.33.2 |
| `publish-ready-content-loss.php` | The editor panel writing alt text against the saved body while the author was looking at an unsaved one, which discarded every unsaved change including the image being described | unreleased |

Both exit non-zero on failure, so they can be chained:

```bash
for t in tests/security/*.php; do wp eval-file "$t" || echo "FAILED: $t"; done
```

## Running them

From the **site root** (`app/public`), not the plugin directory — `eval-file`
paths are relative to where WP-CLI bootstraps.

Where `wp` is on your PATH and reaches the database, that is all you need.

Under Local (Windows) the bundled `wp` usually cannot reach MySQL, so call
Local's own PHP with the mysqli extension and Local's MySQL port:

```bash
LS="$APPDATA/Local/lightning-services/php-8.2.29+0/bin/win64"
"$LS/php.exe" -d extension_dir="$LS/ext" -d extension=mysqli -d mysqli.default_port=<PORT> \
  /path/to/wp-cli.phar eval-file \
  wp-content/plugins/twt-aeo-ultimate/tests/security/mcp-content-access.php
```

`<PORT>` changes whenever Local restarts the site. Read the current one from
Local's UI (Database tab), or from `app/conf/mysql/my.cnf`, or by checking
which port in the 10000–10100 range the site's mysqld is listening on.

## Why they call handlers directly

`mcp-content-access.php` calls the public static handlers rather than making
HTTP requests. An earlier version went over HTTP, wrote the wrong option name
so the routes never registered, got a 404 for everything — and reported a
clean run. **A test that cannot fail is worse than no test.** Both tests here
were confirmed to fail against the pre-fix code before being trusted:
`okf-protected-posts.php` reports 4 leaks at `d1da065~1`, and
`mcp-content-access.php` reports 6 at `136ac79~1` — three by permalink and
three by ID walk — while its public-post check still passes.

If you add a test, verify it fails on broken code first. Check out the parent
of the fix commit, run it, and watch it fail:

```bash
git stash && git checkout <fix-commit>~1 -- includes/
# run the test -- it must FAIL
git checkout HEAD -- includes/ && git stash pop
```

## The class of bug these cover

Password-protected posts keep `post_status = 'publish'`. A query that filters
only on status still matches them, which is why this slipped through twice in
surfaces that looked correctly gated. When adding any route, tool or feed that
returns post content:

- pass `'has_password' => false` to `WP_Query` / `get_posts()`
- read the stored `post_password` field directly; **do not** call
  `post_password_required()`, which honours the *visitor's* password cookie and
  will happily render protected content into a shared transient cache
- `WC_Product_Query` has no `has_password` argument — filter in the loop
- return an identical "not found" message for hidden and missing content, so
  the endpoint cannot be used to confirm a hidden post exists
- flush the output caches on the plugin version-change hook, or a fix ships
  while poisoned cache entries keep serving the leak for up to 12 hours
