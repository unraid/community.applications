# CodeRabbit Fixes WIP

## Context

- Repo: unraid/community.applications
- Branch: refactor/dynamix-var-delegation
- PR: #74
- PR URL: https://github.com/unraid/community.applications/pull/74
- Generated at: 2026-05-26

## Inputs Pulled

- [x] Unresolved CodeRabbit review threads pulled (3 items)
- [x] Top-level CodeRabbit review notes pulled — 2 outside-diff actionable + 2 nitpicks
- [x] Top-level nitpicks extracted into queue

## Fix Queue

| Item ID | Type | File | Line | Summary | Status | Link | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| CR-001 | thread | legacy-colors.css | 430 | Duplicate `--usage-bar-background-color` in azure block | DONE | https://github.com/unraid/community.applications/pull/74#discussion_r3301471686 | Removed the earlier `cyan-400` decl (kept `--gray-500` which is what wins in dynamix's own duplicated decl). Inline comment notes the verbatim-copy origin. |
| CR-002 | thread | legacy-colors.css | 511 | Cross-theme `--theme-gray--black-alt` reference inside azure block (undefined) | DONE | https://github.com/unraid/community.applications/pull/74#discussion_r3301471691 | Added `--theme-azure--black-alt: #121510` to the azure block and updated the `--dynamix-select-box-shadow` line to reference it. Inline comment notes dynamix has the same bug upstream. |
| CR-003 | thread | overrides.css | 34, 42-46, 77 | `.Theme--<layout> body:has(...)` selectors never match | DONE | https://github.com/unraid/community.applications/pull/74#discussion_r3301471694 | Flipped to `body.Theme--<layout>:has(...)` / `body.Theme--<layout>.<class>` for the multi-install bottom offset (sidebar + nav-top) and the five sidebar `#menu` z-index rules. |
| CR-004 | review/outside-diff | responsive.css | 326 | `.Theme--sidebar body.menuShowing .ca_modal_overlay` never matches | DONE | https://github.com/unraid/community.applications/pull/74#pullrequestreview-4360884975 | Flipped to `body.Theme--sidebar.menuShowing .ca_modal_overlay`. Mobile sidebar scrim now correctly fills viewport. |
| CR-005 | review/outside-diff | community.applications.css | 482-490 | `.caButton:hover { color: var(--ca-white-color) !important }` leaks white text onto action/close buttons | DONE | https://github.com/unraid/community.applications/pull/74#pullrequestreview-4360884975 | Changed hover color to `var(--inverse-text-color)` on both rules (the `!important` one and the anchor-text one). Action / close buttons now keep the theme's inverse-text color on hover instead of getting clobbered to white. |
| NIT-001 | nitpick | colors.css | 19-23 | Stale reference to deleted `themes/{azure,black,gray,white}.css` polyfill files | DONE | https://github.com/unraid/community.applications/pull/74#pullrequestreview-4360884975 | Updated the header-comment guidance to reference `community.applications.legacy-colors.css` and the four `.Theme--legacyOS.Theme--<name>` blocks inside it. |
| NIT-002 | nitpick | Apps.page | 214-218 | Comment overclaims "always wins the cascade" | DONE | https://github.com/unraid/community.applications/pull/74#pullrequestreview-4360884975 | Reworded to "generally take precedence ... at equal specificity — but later-loaded stylesheets can still override at the same specificity". |

## Execution Log

### 1. CR-001 — duplicate `--usage-bar-background-color`
- Action: Removed the cyan-400 declaration; kept the later `--gray-500` line so legacy parity matches dynamix's effective behavior. Comment added.
- Validation: `grep` for `usage-bar-background-color` inside azure block confirms single decl.
- Result: DONE

### 2. CR-002 — undefined `--theme-gray--black-alt` in azure scope
- Action: Defined `--theme-azure--black-alt: #121510` in the azure block; updated the `--dynamix-select-box-shadow` line in azure to reference the local var.
- Validation: `grep "theme-gray--black-alt"` confirms zero references outside the gray block (only the gray-block decl + uses + a comment explaining the bug in the azure block).
- Result: DONE

### 3. CR-003 — broken `.Theme--<layout> body:has(...)` selectors in overrides.css
- Action: Flipped six selectors from `.Theme--<layout> body[...]` to `body.Theme--<layout>[...]` (sidebar: multi-install offset + 5 z-index #menu rules; nav-top: multi-install offset).
- Validation: `grep` for `\.Theme--\(sidebar\|nav-top\) body` in overrides.css returns empty.
- Result: DONE

### 4. CR-004 — same selector bug in responsive.css scrim rule
- Action: Flipped `.Theme--sidebar body.menuShowing .ca_modal_overlay` → `body.Theme--sidebar.menuShowing .ca_modal_overlay`.
- Validation: `grep` returns empty.
- Result: DONE

### 5. CR-005 — `.caButton:hover` white-text override leaking through
- Action: Replaced `var(--ca-white-color)` with `var(--inverse-text-color)` in both rules. Inline comment explains the leak.
- Validation: Visual inspection of the diff confirms the change; the action / close button hover rules elsewhere in the file already use `--inverse-text-color` so the cascade is now consistent.
- Result: DONE

### 6. NIT-001 — stale `themes/` reference in colors.css header
- Action: Header now points at `community.applications.legacy-colors.css` and the four `.Theme--legacyOS.Theme--<name>` blocks inside it.
- Validation: Inspection.
- Result: DONE

### 7. NIT-002 — Apps.page cascade overclaim
- Action: Reworded the comment from "always wins the cascade" to "generally takes precedence at equal specificity — but later-loaded stylesheets can still override at the same specificity".
- Validation: `php -l` clean.
- Result: DONE

## Final Checks

- [x] Queue reviewed: no `TODO` left
- [x] Remaining `BLOCKED` items documented with reason (none)
- [ ] Re-pulled CodeRabbit threads and reviews (pending push)
- [ ] No unhandled top-level nitpick remains (pending push)
