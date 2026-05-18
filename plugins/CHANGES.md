# Community Applications — Pending Changes

Running log of changes destined for the next release. Each PR appends its
user-visible bullets under **Unreleased** below.

Format mirrors the `<CHANGES>` block in [community.applications.plg](community.applications.plg)
so release time is a straight copy-paste:

- Keep the `- Added: …` / `- Changed: …` / `- Fixed: …` / `- Removed: …` / `- Chore: …` prefixes.
- One bullet per change, user-facing wording.
- Tag any 7.2+-only items explicitly (`7.2+:`).

At release:

1. Replace the `## Unreleased` heading with `###YYYY.MM.DD` (the plg's date format).
2. Paste that block at the top of `<CHANGES>` in the .plg.
3. Reset this file to a fresh empty `## Unreleased` section.

This file lives in `plugins/` next to the .plg and is **not** included in
the packaged plugin (`pkg_build.sh` only ships `source/community.applications/`).

---

## Unreleased

- Added: Developer-mode "Diff" button in the sidebar (containers only) — compares the upstream `TemplateURL` XML against the entry in the live application feed, side-by-side, with per-character highlighting on changed lines
- Changed: Download progress strip no longer auto-appends an Abort button to every status copy; the button is now baked into the Updating-Applications dialog only
- Changed: Concurrent identical downloads now share a single in-flight fetch via an OS-level file lock, instead of each request kicking off its own
- Added: Top Nav theme variants (azure and gray) supported in addition to the sidebar themes
- Added: Search now opens in its own popup window with a dedicated input
- Added: Browsing and searching use infinite scroll — keep scrolling to load more apps; no more page-number clicks
- Added: Per-repository ignore list (moderation tool) so entire repositories can be hidden from the catalog
- Added: Picture and video gallery in the sidebar — left/right arrow keys advance through items
- Added: Hover the cursor over a video in the gallery and arrow keys go to the player (for seek); hover the area around it and arrow keys navigate between gallery items
- Added: Cmd-K / Ctrl-K opens the search popup from anywhere in CA
- Added: Live download progress shown while feeds and template lists load
- Added: The per-app action buttons (Install / WebUI / Settings / Update / Edit / Uninstall / Pin) ride at the top of the sidebar and stay visible while you scroll through the app description
- Added: Accessibility pass — improved keyboard navigation, focus handling, and screen-reader markup throughout
- Changed: Home page now shows 6 apps per section
- Changed: Settings, Statistics, and Change Log all open inside the sidebar instead of opening as separate pages
- Changed: Sort controls moved into the search area for quicker access
- Changed: Pin button keeps a single label and turns green when the app is pinned (matches the Favourite Repo button)
- Changed: Support links in the sidebar are individual buttons instead of a Support dropdown menu
- Changed: Action button labels shortened — "Remove from Previous Apps" is now just "Remove"; the multi-select Delete button is now "Remove" too
- Changed: The multi-select Remove button on Previous Apps now actually appears when entries are checked off
- Changed: Moderator notes appear above the description; auto-generated configuration warnings appear below it (no header, normal text size, no red border)
- Changed: Heart and pin icons on the cards now sit inline next to the Details button instead of in the corner of the card
- Changed: Cards no longer show the speech-bubble icon for moderator/CA comments — those notes appear in the sidebar
- Changed: Cards no longer show the "Additional Requirements" warning icon — the sidebar still shows the full message and the install flow still blocks updates when required files are missing
- Changed: Plugin and language-pack cards labelled "Unraid Official" (when appropriate) instead of "Official Container"
- Changed: Mobile menu redone — survives orientation changes and works on older devices
- Changed: Close button on popups (screenshots / videos) restyled to match the rest of the action buttons
- Changed: Custom scrollbars throughout
- Changed: Sidebar opens faster — README and changelog content now load on demand instead of up-front
- Changed: Multiple CA browser tabs no longer interfere with each other — each tab tracks its own search and scroll state
- Changed: Clearer error messages when an update or template fetch fails
- Changed: Licence updated to GPL-2.0-or-later
- Removed: Pagination bar at the bottom of app lists (infinite scroll replaces it)
- Removed: Support for private repositories
- Removed: Banner that briefly appeared when toggling a Favourite Repo (it was hidden behind the sidebar dim overlay anyway)
- Removed: Action Centre banner alert (the menu indicator already signals pending updates)
- Fixed: Action Centre would sometimes never load templates; pending updates now show reliably
- Fixed: Picture/video popup: arrow keys no longer restart a playing video when there is only one item in the gallery
- Fixed: Popups with a broken image now fall back to the standard "?" placeholder instead of staying blank
- Fixed: Repository sidebar opened from a card now shows a close button
- Fixed: Repository sidebar no longer remembers the previously opened app
- Fixed: Cmd-K / Ctrl-K search shortcut now behaves correctly after the sidebar has been opened
- Fixed: Clicking a multi-install checkbox no longer opens the sidebar
- Fixed: Repository sometimes wouldn't open when clicked from a repository card
- Fixed: Repositories page sometimes appeared as a single column
- Fixed: Apps-per-page would reset incorrectly after an empty search and during state restore
- Fixed: Switching between Docker Hub search and regular search wasn't always consistent
- Fixed: Clearing the search from the popup didn't return to the home page
- Fixed: Menu state could break across orientation changes
- Fixed: PHP 8.0+: curl_close warnings in the system log
- Fixed: Plugin warning is now combined with the initial CYA agreement; previous-app installs are not offered until CYA is accepted
- Fixed: Debugging tools weren't reporting which files were being read
- Fixed: Security and reliability hardening — unsafe links stripped from rendered README and changelog content, template URLs validated before fetching, backend input handling tightened
