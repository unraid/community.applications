# CodeRabbit Fixes WIP

## Context

- Repo: unraid/community.applications
- Branch: feat/sidebar-live-stats
- PR: #78
- PR URL: https://github.com/unraid/community.applications/pull/78
- Generated at: 2026-05-29

## Inputs Pulled

- [x] Unresolved CodeRabbit review threads pulled (7)
- [x] Top-level CodeRabbit review notes pulled
- [x] Top-level nitpicks extracted into queue (1)

## Fix Queue

| Item ID | Type | File | Line | Summary | Status | Link | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| CR-001 | thread | plugins/CHANGES.md | 29 | "three dials" should be "four" | DONE | https://github.com/unraid/community.applications/pull/78#discussion_r3321407238 | plugins/CHANGES.md updated |
| CR-002 | thread | source/.../Apps.page | 1577 | caStopLiveStats() in showRepoPopup/showAlternateView | DONE | https://github.com/unraid/community.applications/pull/78#discussion_r3321407239 | Apps.page: caStopLiveStats() at top of both |
| CR-003 | thread | source/.../include/exec.php | 1331 | Unlink startupDisplayed in duplicates branch | DONE | https://github.com/unraid/community.applications/pull/78#discussion_r3321407242 | exec.php: @unlink before writeJsonFile |
| CR-004 | thread | source/.../include/exec.php | 2357 | Gate startLiveStatsPublisher server-side | DONE | https://github.com/unraid/community.applications/pull/78#discussion_r3321407244 | exec.php: feature gate returns ok:false early |
| CR-005 | thread | source/.../scripts/caLiveStats.php | 75 | Replace pidfile+posix_kill with flock | DONE | https://github.com/unraid/community.applications/pull/78#discussion_r3321407248 | caLiveStats.php: flock LOCK_EX|LOCK_NB; php -l clean |
| CR-006 | thread | source/.../skins/Narrow/skin.php | 502 | Tabs as buttons with aria-pressed | DONE | https://github.com/unraid/community.applications/pull/78#discussion_r3321407250 | skin.php: 4 <button role=tab>; CSS reset + focus + Theme--sidebar override |
| CR-007 | thread | source/.../skins/Narrow/styles/community.applications.css | n/a | Dynamic bottom padding for #sidenavContent | DONE | https://github.com/unraid/community.applications/pull/78#discussion_r3321407261 | CSS var(--ca-sidenav-bottom, 9rem); Apps.page caSyncSidenavBottomPadding measures + stamps; called on relocate/swap/resize |
| NIT-001 | nitpick | source/.../skins/Narrow/styles/community.applications.css | 1250-1270 | Remove dead selectors from transition blocks | DONE | https://github.com/unraid/community.applications/pull/78#pullrequestreview-4385684801 | CSS: dead selectors removed; transition lives only on .sidebar .sidenav |

## Execution Log

- CR-001..CR-006: completed per evidence column.
- CR-007: CSS uses `padding-bottom: var(--ca-sidenav-bottom, 9rem)`; JS `caSyncSidenavBottomPadding()` measures `.popupActionsArea` `outerHeight(true) + 16px` and stamps `--ca-sidenav-bottom` on `document.documentElement.style`. Called from `caRelocatePopupActions()`, from the pending-feed `replaceWith` swap, and from the debounced window resize handler. Hidden / missing bar -> property removed so CSS fallback (9rem) kicks back in.
- NIT-001: dropped `.popupCloseArea` / `.popupActionsArea` from the unconditional `.sidebar .sidenav` show/hide transition blocks (they were dead — the hide block at L1265-70 always won, and the real visibility lives on later `body:has(.sidenavShow) .popupCloseArea` / `.popupActionsArea` rules).

## Final Checks

- [x] Queue reviewed: no `TODO` left
- [x] Remaining `BLOCKED` items documented with reason (none)
- [ ] Re-pulled CodeRabbit threads and reviews
