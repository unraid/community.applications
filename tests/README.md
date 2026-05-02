# Community Applications — Unit Tests

Lightweight PHP test suites for CA. Lives **outside** `source/community.applications/`,
so `pkg_build.sh` never packages anything in this directory — these files exist only
for local development and CI, never for distribution.

## Layout

| File | What it covers |
|---|---|
| `lib.php` | Tiny assertion framework (`ok()`, `eq()`, `suite_done()`). No PHPUnit, no composer. |
| `test_url_validation.php` | `validURL()`, `caIsPrivateOrLoopbackHost()`, `caIsPublicHttpUrl()` — the URL-gating surface used by template render sites and the README/changelog sanitizers |
| `test_strip_image_tag.php` | `PreviousAppsHelpers::stripImageTag()` — colon-after-last-slash docker tag stripping, registry-port preserved |
| `test_sanitizer.php` | End-to-end markdown render pipeline for README/changelog: raw HTML stripped, anchor/img rebuilt with positive attr whitelist, title-injection attempts sealed |
| `run.sh` | Orchestrator. SSH's to the server and runs every `test_*.php`. |

## Running

The tests live on the Mac at `/Volumes/GitHub/community.applications/tests/` but
need to execute on the Unraid server because they `require_once` live plugin
files (`/usr/local/emhttp/plugins/community.applications/include/...`) and the
markdown library bundled with Dynamix (`/usr/local/emhttp/plugins/dynamix/include/MarkdownExtra.inc.php`).

```sh
./run.sh
```

Override the host with `CA_TEST_HOST=root@another.host ./run.sh`.

Each suite prints `✓` / `✗` per assertion and a tail summary, then exits 0 (all
passed) or 1 (at least one failed). `run.sh` aggregates the suite exit codes
and reports overall pass/fail at the bottom.

## Adding a test

1. Create `test_<topic>.php` next to the others.
2. Top of file:
   ```php
   require_once __DIR__ . "/lib.php";
   require_once "/usr/local/emhttp/plugins/community.applications/include/<file>.php";
   ```
3. Use `ok("name", $bool, "optional detail")` or `eq("name", $expected, $actual)`.
4. End with `suite_done();`.
5. Re-run `./run.sh` — the orchestrator picks up `test_*.php` automatically.

## Conventions

- **Pure functions only.** If a function depends on `$GLOBALS`, the appfeed,
  filesystem state, or an MCP, the test should mock the input or set up a
  minimal fixture, not call live endpoints.
- **No network.** No real DNS lookups, no actual HTTP fetches. The pipeline
  tests pass synthetic markdown strings through the sanitizer, not real
  README URLs.
- **Use `ReflectionMethod` for private statics** (see `test_strip_image_tag.php`).
- **DOM-verify attribute injection.** Substring matches can give false
  positives — `test_sanitizer.php` parses the output through `DOMDocument` for
  the title-injection cases.
