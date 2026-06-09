# CodeRabbit Fixes WIP

## Context

- Repo: unraid/community.applications
- Branch: feat/footer-status-logs-icon-contrast
- PR: #101
- PR URL: https://github.com/unraid/community.applications/pull/101
- Generated at: 2026-06-09

## Inputs Pulled

- [x] Unresolved CodeRabbit review threads pulled (4)
- [x] Top-level CodeRabbit review notes pulled (2 outside-diff actionable)
- [x] Top-level nitpicks extracted into queue (none)

## Fix Queue

| Item ID | Type | File | Line | Summary | Status | Link |
| --- | --- | --- | --- | --- | --- | --- |
| CR-001 | thread | Apps.page | 3397 | restoreState always switches to Apps chrome; persist + restore Docker Hub mode | DONE | https://github.com/unraid/community.applications/pull/101#discussion_r3378472879 |
| CR-002 | thread | Apps.page | 5551 | `dockerConvert()` callback uses `result.xml` unconditionally; gate redirect on success | DONE | https://github.com/unraid/community.applications/pull/101#discussion_r3378472886 |
| CR-003 | thread | include/helpers.php | 2290 | New curl helpers ignore proxy.cfg; extract `caApplyProxyCfg()` and apply | DONE | https://github.com/unraid/community.applications/pull/101#discussion_r3378472903 |
| CR-004 | thread | include/helpers.php | 2397 | `:latest` fallback only tries one tag — make arch-aware via Hub `images` metadata | DONE | https://github.com/unraid/community.applications/pull/101#discussion_r3378472909 |
| CR-OD1 | review/outside-diff | Apps.page | 1852-1860 | Reset `data.allLoaded` on every Docker→Apps exit path (extract `caLeaveDockerMode()`) | DONE | https://github.com/unraid/community.applications/pull/101 |
| CR-OD2 | review/outside-diff | include/exec.php | 3459-3498 | Single-page Docker Hub flow publishing upstream totals — clamp + expose separately | DONE | https://github.com/unraid/community.applications/pull/101 |

## Execution Log

- CR-001 — saveState persists `dockerMode: !!data.docker`; restoreState branches on it. Docker-mode path sets `data.docker = "searching docker"`, hides sort icons, shows `.templateSearch`, hides `.dockerSearch`. Apps-mode path unchanged.
- CR-002 — dockerConvert post() callback reads `result.xml` defensively; drops the redirect + surfaces `addBannerWarning(tr("Unable to convert..."))` when empty / malformed instead of navigating to `default:undefined`.
- CR-003 — added `caApplyProxyCfg(&$opts)` helper that reads `proxy.cfg` with the same precedence as `download_url()` (env-var override). All three new Docker Hub functions (`caGetDockerHubToken`, `caRegistryGet`, `caGetMostRecentDockerHubTag`) call it before `curl_setopt_array`.
- CR-004 — `caGetMostRecentDockerHubTag` accepts optional `$arch` param; when set, iterates the Hub tags list (page_size=25) and only returns tags whose `images[]` entry has `os=linux` + matching `architecture`. `caFetchDockerImageConfig` passes `$arch` to the fallback.
- CR-OD1 — added `caLeaveDockerMode()` helper that resets `data.docker = ""` + `data.allLoaded = false`. Replaced 8 transition sites: getContent (canonical), doSearch, clear-search block, previousApps, actionCentre, home-soft-reset, pins, caShowInApps. Init-time L290 left alone (initial state).
- CR-OD2 — `num_pages` clamped to 1; `num_results` reflects page's `count($dockerResults)`. Upstream totals moved to `upstream_num_pages` / `upstream_num_results`.

Validation: `php -l` clean on exec.php + Apps.page + helpers.php.

## Final Checks

- [x] Queue reviewed: no TODO left
- [x] Remaining BLOCKED items documented with reason (none)
- [ ] Re-pulled CodeRabbit threads and reviews (pending push)
- [ ] No unhandled top-level nitpick remains (pending push; none in initial pull)
