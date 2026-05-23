# CodeRabbit Fixes WIP

## Context

- Repo: unraid/community.applications
- Branch: feat/server-side-sidebar-fetch
- PR: #55
- PR URL: https://github.com/unraid/community.applications/pull/55
- Generated at: 2026-05-23 (rebased on master after #54/#56/#58 merged)

## Inputs Pulled

- [x] Unresolved CodeRabbit review threads pulled (2 items, addressed in earlier pass)
- [x] Top-level CodeRabbit review notes pulled
- [x] Top-level nitpicks extracted into queue (none)

## Fix Queue

| Item ID | Type | File | Line | Summary | Status | Link | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| CR-001 | thread | Apps.page | 3087-3095, 3182-3190 | postNoSpin Promise wrappers don't reject on transport failure — sidebar stuck in "Loading…" | DONE | https://github.com/unraid/community.applications/pull/55#discussion_r3291654064 | Both call sites swapped to `$.post(execURL, {…}).done().fail()` so transport errors reject the Promise and the existing catch surfaces the "can't be loaded" placeholder. tabId stamped manually since we're bypassing post()'s auto-stamping. |
| CR-002 | thread | include/exec.php | 1918-1929 | `changes` cache key `{repoName}-{basename(url)}` can collide across distinct URLs sharing a basename | DONE | https://github.com/unraid/community.applications/pull/55#discussion_r3291654068 | Inserted an 8-char `substr(hash("sha256",$url),0,8)` between `$safeRepo` and `$base` so two URLs that share a basename within the same repo no longer hash to the same on-disk filename. `php -l` clean. |

## Execution Log

### 1. CR-001 — postNoSpin → $.post with .fail() (Apps.page, both fetch sites)
- Action: Replaced both `new Promise(...)` wrappers around `postNoSpin` (readme + changes) with `$.post(execURL, {…}).done(resolve-or-reject).fail(reject)`. Reason: `postNoSpin` only invokes its callback on the 2xx success path; transport failures (network down, non-2xx, JSON parse) left the Promise unsettled and the section stuck in the "Loading…" placeholder.
- Validation: grep confirms no `postNoSpin.*caFetchSidebarSource` calls remain; both sites end in `.fail(function(){reject(new Error("transport failure"))})`.
- Result: DONE

### 2. CR-002 — exec.php cache key collision
- Action: In `caFetchSidebarSource()` `kind === "changes"` branch, inserted `$urlHash = substr(hash("sha256",$url),0,8)` between `$safeRepo` and `$base`. New cache filename shape: `{safeRepo}-{urlHash}-{base}`. README path unchanged (1:1 with safeRepo by construction).
- Validation: `php -l include/exec.php` clean.
- Result: DONE

## Final Checks

- [x] Queue reviewed: no `TODO` left
- [x] Remaining `BLOCKED` items documented with reason (none)
- [x] Re-pulled CodeRabbit threads and reviews after first pass — both threads showed `isResolved: true`
- [x] No unhandled top-level nitpick remains
