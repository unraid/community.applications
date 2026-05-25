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

- Fixed: Plugin sidebar now shows the "Update" button when an update is available — the previous build silently dropped it on every sidebar open after the first
- Changed: Sidebar "Pin" button moved from the action button row to the support button row (right before any dev-mode buttons) — declutters the action row and groups the toggle with the other passive on/off buttons
- Changed: Sidebar action buttons show "Unavailable until feed downloads" when opened before the background full-feed hydrate completes — buttons appear in place automatically the moment the hydrate finishes
- Changed: When an app has an Update available, the "Install second" button is hidden in the sidebar — installing a second instance while an update is pending would have copied the old image anyway
- Fixed: Another browser tab updating the application feed no longer requires the current tab to be lucky with timing to see the reload banner — the check now runs the next time the user does anything on the stale tab, instead of relying on a real-time push that could be missed
- Changed: Search suggestion buttons in the search popup are about 50% larger — easier to read and click, especially on a phone-sized viewport
- Changed: Initial application-feed download now grabs a slimmed-down feed (no Config blocks) for a faster first paint — the full feed loads in the background so install-time port-conflict detection is ready by the time the user clicks Install
- Changed: If the primary application feed is unreachable, CA now falls back to a slim copy hosted on GitHub instead of pulling the full feed as a second try
- Fixed: Reloading Apps without a feed update no longer rewrites the small templates cache with the full-cache contents
- Fixed: CA's search-modal autocomplete styling no longer leaks onto other Unraid plugins' awesomplete dropdowns elsewhere on the page
- Changed: Developer-mode template Diff button now passes the template URL straight to the server instead of looking it up by Path — drops two huge in-memory copies of the templates array and keeps the diff under PHP's 256M memory limit on large feeds
- Added: "Use whole display window" setting (Settings panel, default off, 7.2+ only) — reclaims the Unraid OS header strip so the app browsing area fills the full browser window
- Changed: Top bar buttons (Menu / Search / Sort / Apps / DockerHub) now carry FontAwesome icons; on narrow viewports they collapse to icon-only to free horizontal room
- Changed: Sort button shortened from "Sort By: <selection>" to just "Sort" with a sort icon — the active sort is already highlighted in the icon row beneath it
- Changed: Page body never scrolls — keeps the header / search bar / sidebar pinned in place on mobile instead of letting touch-drag yank the layout around
- Changed: When the search popup is open, the Sort / Apps / DockerHub / "Displaying x of N" controls hide so the search input has the whole top row
- Changed: Sidebar action row tidies itself on narrow viewports — the install / update / uninstall colored buttons drop their right-align push and visual separator so everything just flows naturally
- Changed: Tap-stuck orange highlight on the screenshot/video popup's close button and arrows is no longer visible after a touch on mobile
- Changed: Settings panel's body description for "Use whole display window" and similar version-gated options now flag "(7.2+ only)" inline; controls grey out and disable themselves on older OS versions
- Changed: Sidebar action buttons restyled — Install / Reinstall / Install second are blue, Update is green, Uninstall / Remove are red, all right-aligned and icon-prefixed; secondary buttons (WebUI / Edit / Pin / etc.) stay on the left with a visual separator between the two groups
- Changed: Screenshot / video popup close button now floats at a fixed position top-right, larger and red
- Changed: "Install second instance" button label shortened to "Install second"; "Tailscale WebUI" shortened to "TS WebUI"
- Added: "Autoplay videos" setting (Settings panel, default off) — controls whether YouTube / Vimeo videos in the sidebar's screenshot gallery start playing automatically when opened
- Fixed: First tap on a sidebar button or video thumbnail now registers immediately — most visible on mobile, where the first tap used to be wasted "waking up" the sidebar
- Fixed: Closing a screenshot or video popup now returns the sidebar to the originating app instead of falling through to whichever app happened to be first in the list
- Fixed: The "Choose A Branch To Install" picker no longer closes the sidebar — it now floats over the sidebar the same way the port-conflict prompt does, and the sidebar stays put whether you pick a branch or cancel
- Fixed: README and changelog content in the sidebar loads reliably for repos hosted on sites without permissive CORS headers — the fetch now goes through the Unraid backend
- Fixed: Clicking the Home button consistently returns to the Apps view
- Fixed: Card flag badges (incompatible / deprecated / blacklisted) render reliably again
- Changed: Sidebar / scroll / search / category state now stored per-tab in sessionStorage instead of cookies — each browser tab tracks its own state without interfering with other tabs, and the sidebar is restored to the same app you had open when returning to the Apps page
- Chore: Plugin cache is no longer rewritten on every load when nothing has changed
- Fixed: Closed a stored-XSS path in the sidebar popup's Install / Update buttons — a hostile maintainer publishing a template with a crafted `RequiresFile` value could otherwise execute arbitrary JS in the user's Unraid GUI session when the user clicked the button
- Changed: Icon, screenshot, README, and changelog image URLs now reject private-network hosts (RFC1918, link-local, CGNAT, IPv6 ULA, plus `.local` / `.internal` / `.lan` mDNS-style hostnames) — closes a CSRF surface where an auto-loaded image could fire a request at a device on the user's LAN
- Added: `referrerpolicy='no-referrer'` on every template-supplied image (popup icon, card icon, screenshots, video thumbnails, licence, README / changelog images) so a third-party host doesn't see the user's Unraid URL on each render
- Changed: README and changelog now render client-side via marked + DOMPurify instead of server-side — browser HTTP cache services repeat sidebar opens for free between application-feed refreshes, and the same cache invalidates automatically on feed refresh
- Changed: Additional Requirements block also runs through the new client-side sanitizer pipeline for consistency
- Changed: Developer-mode "Plugin" / "Template" buttons (sidebar dev mode) now open the source inside the existing diff overlay — Plugin renders raw `.plg` alongside an entity-decoded column with each `&name;` substitution highlighted
- Changed: Dev-mode close button on the diff / source overlay restyled to match the rest of the CA action buttons
- Changed: Gallery arrows on the screenshot / video lightbox recolor the caret on hover instead of painting an orange backdrop behind it
- Changed: Back-to-top and move-to-end floating buttons hide while a screenshot / video lightbox is open so they don't sit clickable on top of the dimmed page
- Fixed: Markdown headings without a space after the `#`s (e.g. `###2024.01.15`) render as headings again — restores the permissive behavior of the previous server-side renderer for the many plugin changelogs that rely on it
- Fixed: `<![CDATA[...]]>` blocks inside `<CHANGES>` no longer leak the literal `]]>` marker into the rendered output
- Fixed: `&version;` and other DTD-declared entities inside a plugin's `<CHANGES>` block now expand to their substituted values before markdown rendering, matching the prior server-side behavior
- Chore: Bundled DOMPurify 3.2.4 (Apache 2.0 / MPL 2.0) into the CA library bundle for the client-side sanitization layer; credits updated
- Chore: Sidebar metadata downloads (README, plugin `.plg`, container template XML, dev-mode source modal) share a single on-disk cache under `templates-community-apps`, wiped on application-feed refresh — fewer redundant downloads when re-opening apps or jumping between the sidebar and the dev-mode modal
- Added: Developer-mode "Diff" button in the sidebar (containers only) — compares the upstream `TemplateURL` XML against the entry in the live application feed, side-by-side, with per-character highlighting on changed lines
- Changed: Download progress strip no longer auto-appends an Abort button to every status copy; the button is now baked into the Updating-Applications dialog only
- Changed: Concurrent identical downloads now share a single in-flight fetch via an OS-level file lock, instead of each request kicking off its own
- Chore: Lazy-load the Narrow skin renderer (~3300 lines) only on the dispatch cases that actually emit HTML, shaving the per-request parse cost on all data-only POSTs (pinning, statistics, force_update, etc.)
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
- Fixed: Plugin-install dialog after a mixed Docker + plugin multi-install no longer opens parked behind the main display
- Fixed: A feed update from another browser session while an install dialog is open now waits until the install finishes before showing the reload notice
- Changed: Mobile layout pass — sidebar README, trend charts, and floating scroll buttons hide on phone-sized viewports; menu strip alignment and chrome positions tightened across nav-top and sidebar themes
- Changed: Opening the mobile menu now scrolls the menu back to the top instead of leaving it wherever it was last
- Removed: "All statistics are only gathered every 30 days" note from the sidebar
