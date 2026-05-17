# CodeRabbit Fixes WIP

## Context

- Repo: unraid/community.applications
- Branch: Refactoring
- PR: #14
- PR URL: https://github.com/unraid/community.applications/pull/14
- Generated at: 2026-05-01

## Inputs Pulled

- [x] Unresolved CodeRabbit review threads pulled (6 items)
- [x] Top-level CodeRabbit review notes pulled
- [x] Top-level nitpicks extracted into queue (most recent review: 2026-04-30T21:38:46Z)

## Fix Queue

| Item ID | Type | File | Line | Summary | Status | Link | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| CR-001 | thread | Apps.page | n/a | Don't `.remove()` private stats row — verify show/hide already in place | DONE | https://github.com/unraid/community.applications/pull/14#discussion_r2512911794 | statistics.js:300-307 already uses `$privateRow.show()/.hide()` (fixed in c49f7ec). Comment was filed against Apps.page but actual code lives in statistics.js. |
| CR-002 | thread | include/helpers.php | n/a | XSS in `$buildRow` — escape `$label`/`$description`/`$defaultTag` | DONE | https://github.com/unraid/community.applications/pull/14#discussion_r2525886907 | User reversed prior "feed is sanitized" call — applied. `$buildRow` now htmlspecialchars's $path/$label; $description treated as pre-built HTML (caller pre-escapes fragments). $defaultTag and $defaultTagDescription escaped at call sites. BranchDescription escaped before passing in. `php -l` clean. |
| CR-003 | thread | skins/Narrow/styles/community.applications.css | 38 | `[style]` selector won't match initial hidden state — verify class-based toggle | DONE | https://github.com/unraid/community.applications/pull/14#discussion_r3012864690 | CSS now uses class-based `body:has(.sidenavShow)` (line 36, etc.). The old `#alternateView[style]` selector no longer exists in the file. |
| CR-004 | thread | include/get_content_helpers.php | 264, 314 | Use strict `strpos !== false` checks for filter matching | DONE | https://github.com/unraid/community.applications/pull/14#discussion_r3108887263 | Both strpos calls now use `!== false` / `=== false`. `php -l` clean. |
| CR-005 | thread | javascript/helpers.js | n/a | `showAlternateView()` clones template fragments with fixed IDs into live DOM | DONE | https://github.com/unraid/community.applications/pull/14#discussion_r3114414827 | Converted inner-template IDs to classes: caFixedRepoJump/caFixedToggleAll/caFixedPluginDupesSection/caFixedDuplicateReposSection → classes in skin.html. statistics.js find() calls updated. Inline onclick handlers updated to use class selectors. Wrapping `#caChangeLog`/`#caCredits` aren't duplicated (only $src.contents() cloned, not $src itself). |
| CR-006 | thread | javascript/clickHandlers.js | n/a | `caResponsiveOS` declared with `var` not `window.` — verify orientation handler registers | DONE | https://github.com/unraid/community.applications/pull/14#discussion_r3144746846 | The orientation handler that consumed this var has been refactored away — `grep -rn caResponsiveOS` returned only the declaration. Removed the dead `window.caResponsiveOS = …` line per the no-legacy-paths policy. The other two globals (`caEnableLegacyExternalLinkGuard`, `caSpotlightIconBackup`) are still consumed and stay. |
| NIT-001 | nitpick | skins/Narrow/skin_helpers.php | 507-516 | `caDockerContext` unconditional readJsonFile is redundant | DONE | https://github.com/unraid/community.applications/pull/14#pullrequestreview-4208688362 | Removed the unconditional pre-branch read — `dockerUpdateStatus` now set exactly once in either branch. `php -l` clean. |
| NIT-002 | nitpick | skins/Narrow/skin_helpers.php | 335 | `caBuildActionsContext` has unused `$dockerRunning` parameter | DONE | https://github.com/unraid/community.applications/pull/14#pullrequestreview-4208688362 | Removed `$dockerRunning` from signature and from sole call site at skin.php:793. `php -l` clean for both files. |

## Execution Log

### 1. CR-001 — private stats row
- Action: verified `statistics.js:300-307` already uses `.show()/.hide()`
- Validation: `grep` confirmed no `.remove()` on `$privateRow`
- Result: DONE (no edit needed; was fixed in c49f7ec)

### 2. CR-002 — escape `$buildRow` content (pivoted from BLOCKED to DONE per user)
- Action: htmlspecialchars `$path`/`$label` inside `$buildRow`; treat description as pre-built HTML; pre-escape `$defaultTag`, `$defaultTagDescription`, and `BranchDescription` at call sites in `caGenerateAvailableTagsHTML`
- Validation: `php -l include/helpers.php`
- Result: DONE

### 3. CR-003 — `[style]` selector
- Action: verified CSS uses class-based `body:has(.sidenavShow)` — old `#alternateView[style]` selector no longer exists
- Result: DONE (no edit needed)

### 4. CR-004 — strict `strpos` checks
- Action: `strpos($filter,"/")` → `!== false`; `! strpos($filter," Repository")` → `=== false`
- Validation: `php -l include/get_content_helpers.php`
- Result: DONE

### 5. CR-005 — duplicate IDs from cloned templates
- Action: converted inner template IDs in `skin.html` (`caFixedRepoJump`/`caFixedToggleAll`/`caFixedPluginDupesSection`/`caFixedDuplicateReposSection`) to classes; updated `statistics.js` find() calls; updated inline `onclick` handlers to use class selectors; wrapped `<label for=…>` around its `<select>` to drop the for-id requirement
- Validation: `grep find\(\"#caFixed` returns nothing
- Result: DONE

### 6. CR-006 — `caResponsiveOS` scope
- Action: discovered the orientation handler that consumed it has been refactored away — variable is dead. Removed `window.caResponsiveOS = …` from `Apps.page` per the no-legacy-paths policy
- Validation: `grep -rn caResponsiveOS` returns no matches
- Result: DONE

### 7. NIT-001 — `caDockerContext` redundant readJsonFile
- Action: removed the unconditional pre-branch `readJsonFile` call; both branches assign `$dockerUpdateStatus` exactly once
- Validation: `php -l skins/Narrow/skin_helpers.php`
- Result: DONE

### 8. NIT-002 — `caBuildActionsContext` unused `$dockerRunning` param
- Action: removed parameter from signature in `skin_helpers.php`; removed from sole call site in `skin.php`
- Validation: `php -l` on both files
- Result: DONE

## Round 2 — Duplicate items still flagged in latest review (2026-04-30T22:31:23Z)

| Item ID | Type | File | Line | Summary | Status | Link | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| NEW-001 | inline | skins/Narrow/skin_helpers.php | 1591 | Blacklist badge title typo: "template / has been blacklisted" → drop stray slash | DONE | https://github.com/unraid/community.applications/pull/14#pullrequestreview-4208914985 | "/ " removed from tr() string. |
| NEW-002 | duplicate | skins/Narrow/skin_helpers.php | 720-726 | Unconditional `$template['pluginVersion']` overwrite after the file_exists branch — breaks update detection when temp file missing | DONE | https://github.com/unraid/community.applications/pull/14#pullrequestreview-4208914985 | Removed the unconditional line after the guarded block; only the guarded branch (file_exists + truthy + newer) updates pluginVersion now. |
| NEW-003 | duplicate | skins/Narrow/skin_helpers.php | 1395-1396 | `data-context` embeds raw `json_encode($supportContext)` — needs `JSON_HEX_QUOT \| JSON_HEX_APOS` like sibling renderer | DONE | https://github.com/unraid/community.applications/pull/14#pullrequestreview-4208914985 | json_encode now uses `JSON_HEX_QUOT \| JSON_HEX_APOS \| JSON_HEX_TAG \| JSON_HEX_AMP` — quote/apostrophe/tag/ampersand all hex-escaped; safe inside single-quoted attribute. |
| NEW-004 | duplicate | skins/Narrow/skin_helpers.php | 680-701 | Docker fallback install branch: `!Blacklist \|\| !Compatible` lets blacklisted/incompatible templates surface install buttons | DONE | https://github.com/unraid/community.applications/pull/14#pullrequestreview-4208914985 | Outer guard tightened to `!Blacklist` only. Reinstall and Remove-from-Previous remain unconditional within the not-blacklisted branch (need to be available even on incompatible OS so user can clean up). Fresh Install actions now gated on `$canFreshInstall` which honors the user's hideIncompatible/hideDeprecated settings. `php -l` clean. |
| NEW-005 | duplicate | scripts/dockerConvert.php + Apps.page + include/exec.php | 81-85, 2780, 2251 | description forwarded as base64 but decoded inconsistently (JS `atob`, not PHP) — CodeRabbit can't see across the JS/PHP boundary and keeps flagging it | DONE | https://github.com/unraid/community.applications/pull/14#pullrequestreview-4208891459 | Moved decode to a single canonical PHP boundary point. Apps.page no longer atob()'s — passes descB64 verbatim to both endpoints. dockerConvert.php and exec.php convert_docker() now `base64_decode($_GET/'description', true)` with truthy fall-back to empty. Single source of truth for the wire contract. `php -l` clean for both. |
| NEW-006 | duplicate | include/previous_apps_helpers.php | 103-106, 217 | `explode(":", Repository)[0]` strips registry port, not just docker tag — same bug pattern fixed at lines 141-144 | DONE | https://github.com/unraid/community.applications/pull/14#pullrequestreview-4208534919 | Added private static `stripImageTag($repository)` helper using "colon after last slash" rule. Both call sites (line 105 and 217) now use the helper. `php -l` clean. |

## Final Checks

- [x] Queue reviewed: no `TODO` left (round 1)
- [x] Remaining `BLOCKED` items documented with reason — none remaining (CR-002 unblocked per user)
- [x] Re-pulled CodeRabbit threads and reviews (still 6 unresolved server-side; will auto-close after next push triggers CodeRabbit re-review)
- [x] No unhandled top-level nitpick remains
