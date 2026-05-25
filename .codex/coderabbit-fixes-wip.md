# CodeRabbit Fixes WIP

## Context

- Repo: unraid/community.applications
- Branch: feat/card-sidebar-badge-stack
- PR: #73
- PR URL: https://github.com/unraid/community.applications/pull/73
- Generated at: 2026-05-25

## Inputs Pulled

- [x] Unresolved CodeRabbit review threads pulled (1 item)
- [x] Top-level CodeRabbit review notes pulled (1 review, no nitpicks)
- [x] Top-level nitpicks extracted into queue (none)

## Fix Queue

| Item ID | Type | File | Line | Summary | Status | Link | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| CR-001 | thread | skins/Narrow/skin_helpers.php | 1925 | XSS — `VerMessage` from feed is interpolated raw into `title='{$verMsg}'`; single-quote in feed data breaks attribute, can execute script | DONE | https://github.com/unraid/community.applications/pull/73#discussion_r3299762340 | Wrapped `$template['VerMessage'] ?? <fallback>` in `htmlspecialchars(..., ENT_QUOTES, "UTF-8")` so any HTML metacharacters in feed data get encoded before landing in the badge `title=` attribute. Added comment block explaining why this is the only spot needing escape (all other badge titles use static `tr()` strings). `php -l` clean. |

## Execution Log

### 1. CR-001 — escape VerMessage before title-attr interpolation
- Action: Wrapped the `VerMessage` resolution in `htmlspecialchars((string)($template['VerMessage'] ?? <fallback>), ENT_QUOTES, "UTF-8")`. `ENT_QUOTES` chosen so both `'` and `"` get encoded — the attribute uses single quotes today but the escape stays correct if that ever flips. Inline comment documents why this is the only feed-derived title in the badge stack (every other badge title is a static `tr()` call).
- Validation: `php -l` clean. The `htmlspecialchars` call hardens the same XSS class CR identified — a hostile maintainer publishing `VerMessage="' onerror='..."` would now render the payload as inert text inside the title attribute instead of escaping the attribute context.
- Result: DONE

## Final Checks

- [ ] Queue reviewed: no `TODO` left
- [ ] Remaining `BLOCKED` items documented with reason
- [ ] Re-pulled CodeRabbit threads and reviews
- [ ] No unhandled top-level nitpick remains
