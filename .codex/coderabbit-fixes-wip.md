# CodeRabbit Fixes WIP

## Context

- Repo: unraid/community.applications
- Branch: refactor/savestate-sessionstorage
- PR: #59
- PR URL: https://github.com/unraid/community.applications/pull/59
- Generated at: 2026-05-23

## Inputs Pulled

- [x] Unresolved CodeRabbit review threads pulled (round 1: 2 items + round 2: 3 new items after rebase)
- [x] Top-level CodeRabbit review notes pulled
- [x] Top-level nitpicks extracted into queue (none present)

## Fix Queue

| Item ID | Type | File | Line | Summary | Status | Link | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| CR-001 | thread | Apps.page | ~2307 (caReadSavedState) | Strict feed-mtime validation — snapshot with feedMtime=0 currently bypasses the freshness gate | DONE | https://github.com/unraid/community.applications/pull/59#discussion_r3291990467 | `caReadSavedState` now parses both saved and current mtime as int; treats <=0 on either side as a mismatch and evicts. |
| CR-002 | thread | Apps.page | 1147-1155 (closeSidebar) | In-memory `data.sidebarapppath` / `data.sidebarappname` weren't cleared on user close — next saveState() could re-persist a dismissed sidebar | DONE | https://github.com/unraid/community.applications/pull/59#discussion_r3292003815 | `closeSidebar`'s `!cookie` branch now blanks both fields on the `data` object alongside the cookie + sessionStorage clears. |
| CR-003 | thread | Apps.page | 593 | `<?=$ca_apps_referrer?>` injects cookie-derived value straight into JS — coerce to strict boolean server-side | DONE | https://github.com/unraid/community.applications/pull/59#discussion_r3292161207 | Hardened at the PHP-side variable definition (line 94): `$ca_apps_referrer = (($_COOKIE['ca_apps_referrer'] ?? "false") === "true") ? "true" : "false"`. Variable is now guaranteed to be one of two canonical tokens before any interpolation. |
| CR-004 | thread | Apps.page | 663 (else-branch reopen) | Sidebar reopen in fresh-startup else branch doesn't gate on `caSavedState.wasHome` — non-home snapshots can reopen too | DONE | https://github.com/unraid/community.applications/pull/59#discussion_r3292161210 | Added `caSavedState.wasHome` to the condition. Non-home snapshots that hit the else branch for other reasons (startupDisplayed, dockerConvertFlag, etc.) no longer reopen the sidebar via this path. |
| CR-005 | thread | CA_notices.page | 23-27 | `href.includes/endsWith` referrer detection is brittle for `/Apps` + query variants — use `location.pathname` | DONE | https://github.com/unraid/community.applications/pull/59#discussion_r3292161212 | Rewrote to use `location.pathname` + explicit `isAppsRoot`/`isAppsChild` flags. Query strings and fragments no longer affect matching; bare `/Apps` correctly leaves the cookie alone. |

## Execution Log

### 1. CR-001 — strict feed-mtime validation
- Action: Rewrote the mtime check in `caReadSavedState` from `if (s.feedMtime && window.caFeedMtime && s.feedMtime !== window.caFeedMtime)` to parse both via `parseInt(..., 10) || 0` and reject whenever either side is `<=0` or they differ. Cleared snapshot in the same eviction branch.
- Validation: Grep confirms new `savedMtime`/`currentMtime` locals + the strict guard. PHP/JS structural review clean.
- Result: DONE

### 2. CR-002 — clear in-memory sidebar identity
- Action: In `closeSidebar`'s `!cookie` branch, after the cookie wipe and before the sessionStorage snapshot mutation, added `data.sidebarapppath = ""; data.sidebarappname = "";`. Programmatic `closeSidebar(true)` (install flow) still skips the whole block.
- Validation: Grep confirms `data.sidebarapppath = ""` in the closeSidebar branch.
- Result: DONE

### 3. CR-003 — strict boolean coercion for `$ca_apps_referrer`
- Action: Hardened at the PHP-side variable definition (Apps.page:94). `$ca_apps_referrer = (($_COOKIE['ca_apps_referrer'] ?? "false") === "true") ? "true" : "false"`. Anything other than the literal cookie value "true" maps to "false".
- Validation: Variable is interpolated at line ~592 into JS as a bareword; only `true` or `false` can ever appear there now.
- Result: DONE

### 4. CR-004 — gate else-branch sidebar reopen on `wasHome`
- Action: Added `caSavedState.wasHome` to the condition at Apps.page ~666 so non-home snapshots that took the else branch for unrelated reasons (`$startupDisplayed == "true"`, dockerConvert, etc.) don't auto-reopen the sidebar against a stale snapshot. Comment updated to reflect the intent.
- Validation: The if-branch still handles non-home restore including its own sidebar reopen; the else-branch reopen is now strictly the home-snapshot path.
- Result: DONE

### 5. CR-005 — pathname-based referrer detection
- Action: Rewrote the CA_notices.page logic to use `window.location.pathname` instead of `href.includes/endsWith`. Computes `isAppsRoot` (`/Apps` or `/Apps/`) and `isAppsChild` (`/Apps/…` non-root) explicitly. Cookie is set true only on child pages, reset to false only when off CA entirely, left alone on the bare `/Apps` root.
- Validation: Query strings and URL fragments no longer affect the matcher. Bare `/Apps?foo=bar` is correctly treated as the root.
- Result: DONE

## Final Checks

- [x] Queue reviewed: no `TODO` left
- [x] Remaining `BLOCKED` items documented with reason (none)
- [ ] Re-pulled CodeRabbit threads and reviews (will re-pull after push)
- [x] No unhandled top-level nitpick remains
