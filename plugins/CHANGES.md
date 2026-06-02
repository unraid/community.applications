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

- Changed: The status-message area now sits inline just before the page footer instead of pinned to the bottom-left corner
- Chore: Removed the unused `.spinnerBackground` overlay (dead element reference + CSS)
- Fixed: A card's Install / Manage button now matches its "installed" badge. Containers installed without an explicit tag (Docker reports them as `:latest`), as well as plugins and language packs (including in the Installed Apps view), correctly show "Manage" instead of "Install"
- Fixed: The English (en_US) language card no longer offers "Switch to this language" when English is already the active language (an empty/blank locale now counts as English)
- Changed: Changing a setting, or enabling/disabling a repository, now puts up a blurred full-screen notice with a live 10-second countdown before reloading, instead of reloading instantly (or not at all). The setting is saved immediately; the reload just applies it
- Added: The Repositories list has a "Show disabled only" link to filter to just the disabled repositories, with a toggle back to the full list (appears only when at least one repo is disabled)
- Changed: Sidebar video previews now render in a 16:9 frame, and hovering any sidebar screenshot or video enlarges it to twice its size while keeping it within the sidebar (it no longer spills off the edge); the play icon stays centered on the enlarged video
- Fixed: Long branch descriptions in the "Choose A Branch To Install" prompt now wrap to the next line instead of overflowing
- Changed: Links inside an app's README in the sidebar now match the rest of the sidebar's link styling
- Fixed: Dev-mode "Diff" and "CA" buttons now appear in app sidebars when developer mode is turned on after the app feed has already loaded (the feed snapshot they need wasn't being created in that case)
- Fixed: The Statistics page now shows the session (tab) ID and a primary-vs-backup feed sync check when admin mode is enabled (the backup statistics feed location was also missing)
- Added: Alexa Sterling added to the Credits
- Chore: Removed some unused internal actions and consolidated the developer/admin-mode checks
- Added: Videos in an app's sidebar now autoplay a muted, looping preview right in the thumbnail. Clicking still opens the full-size player (which stays paused until you press play). The separate "Autoplay videos" setting has been removed — sidebar previews are always on now
- Fixed: Dev-mode "Diff" button now works for plugins — it was comparing against the plugin's install file instead of its template, so it never found a match
- Fixed: When "Limit search results" narrows everything away, the "ALL RESULTS" link no longer floats off to the side of "No Matching Applications Found" — it now sits on its own line beneath a "Settings limited the search results." note
- Changed: Links inside an app's "Additional Requirements" text now match the rest of the sidebar's links (orange, with a hover highlight)
- Changed: App / repo / docker template cards have rounded corners (1rem radius) and more breathing room between them (2rem right + bottom margin)
- Changed: Card hover shadow uses the brand orange at full opacity for a stronger lift
- Added: Desktop search has moved into the main toolbar. Type directly into the always-visible input — results stream in live with a 300ms debounce; only one search is in flight at a time, and middle keystrokes during a burst are dropped so a fast-typed "tester" runs one search for "t" and one for "tester" instead of one per character. Suggestions show as a horizontal strip of chip buttons inline after the results count, with left/right chevrons to scroll through them; the right chevron is pinned to the viewport so it can't disappear off-screen. Each chevron greys out when scrolling that direction is a no-op. Clearing the input (backspace, the × icon, or "Clear Search" from the menu) restores the exact menu state the user was on when they started typing — including expanding the parent of a restored sub-category — so they land back where they were instead of being dumped to Home. Mobile keeps the existing search-modal flow untouched
- Added: New "Keep Search In Focus" setting (default off). When on, the page keeps focus on the search input after every click, so the user can keep typing without re-clicking. Auto-detects touch devices (phones / tablets) and skips the auto-refocus there so the on-screen keyboard doesn't pop on every tap; the setting is a manual override for the edge cases the auto-detect misses
- Added: When the user clicks Installed Apps / Previous Apps / Pinned Apps / Action Centre / Favourite Repo while a search is active, the search input + suggestion strip are cleared first so the new section isn't shown alongside a stale query
- Added: Slide-in sidebar (per-app, settings, statistics, credits, changelog, etc.) now folds the mobile menu away first when it's open, so the sidebar never opens overlapping the menu
- Changed: Settings list reorder — "Hide Deprecated Applications", "Hide Incompatible Applications", and "Disable Featured Applications" moved to the bottom (just above "Enable developer mode"), since they're rarely changed once set
- Changed: Settings / Statistics / Credits / Change Log / Moderation views no longer reserve space for the per-app action button strip at the bottom of the sidebar — those views have no per-app actions and the now-reclaimed space matters on short viewports. The scroll-to-top / scroll-to-bottom affordances also drop back to their default bottom offset in those views
- Changed: When a category has sub-categories, clicking the parent now leaves any other parent that has a selected sub fully expanded; clicking the same parent again no longer flips it to a compact view. (Replaces the brief experiment from PR #80 that compacted other expanded branches)
- Changed: Display-count line on the results header now reads "X - Y of Z" instead of "Displaying X - Y of Z". The "Limit search results" affordance after it was also relabelled from "Search results limited due to user settings / Show All Results" to a single "ALL RESULTS" link styled the same as the Home page's "SHOW MORE"
- Changed: Single-result searches no longer auto-open the sidebar / repo popup. Live search would otherwise pop the sidebar every time a narrowed query briefly hit one match
- Changed: "Limit search results" now also matches RepoName as a name-priority hit (alongside Name / SortName) instead of falling through to the catch-all "any field" bucket, so a search like "linuxserver" lands in the higher-relevance bucket
- Changed: Renamed "Limit searches to name" setting to "Limit search results". Description is now "Limits results to name, author, repository." When the setting is on, the search matches against Name, Author, and the maintainer's repo name only — the Docker Hub image path (and its tag) is excluded so a search for "test" no longer pulls in every container tagged ":latest"
- Added: When "Limit search results" is on, the results header now reads "Displaying X - Y of Z — Search results limited due to user settings Show All Results". The "Show All Results" link widens the current search past the limit; the override stays in effect while you refine via category clicks and only resets when you type a new search term. The same hint + link appears beneath "No Matching Applications Found" when the limit narrowed everything away. Docker Hub search ignores the setting entirely so the hint never shows there
- Added: Plugins now get the dev-mode "Diff" entry in the sidebar (alongside the existing "CA" internal diff) — backend already accepted plugin URLs; only the UI gate was excluding them
- Fixed: Autocomplete suggestion chips no longer show visible gaps around the highlighted match ("d oll ar" → "dollar"). The chips were inheriting `.caButton`'s `inline-flex` + `gap` layout and turning each text node into a separate flex item
- Chore: `getAllInfo()` cache (`info.json`) is now explicitly invalidated on install, uninstall, update, edit, language switch, save/restore state, init paths without a feed update, and whenever you navigate to a CA child page (Install / AddContainer / etc.). New server helper `caDropInfoCache()` plus a JS-callable `dropInfoCache` action wire the same drop into both halves of the stack — closes a class of edge cases where the cached file lagged behind real container state
- Changed: Sidebar categories with sub-categories no longer fetch on parent-click — the parent now just expands the sub list. A new auto-generated "All" entry at the top of each sub list shows the combined results (same as the parent used to). Clicking a parent that's already expanded won't collapse another parent that has an active selection; expansions with nothing picked auto-tidy when the menu re-opens or after a content fetch. Installed Apps and Previous Apps work the same way; if all real subs under "All" are disabled, "All" auto-disables too (pure CSS, so future sub lists get the behavior for free). On mobile, clicking a parent leaves the menu open until you actually pick a sub or "All"
- Added: Repo sidebar now shows a "Duplicates" button (dev mode + admin only) that lists every template touching this repo whose docker image is duplicated somewhere — both cross-maintainer overlap and the same maintainer republishing under a different display Name. Image keys are normalized so `nginx`, `library/nginx`, `_/nginx:latest`, `ghcr.io/owner/c`, and `lscr.io/owner/c:latest` all collapse together, with the registry hostname stripped so Docker Hub / GHCR / LSCR / quay.io / localhost-port refs that share the same `owner/container[:tag]` path are treated as the same image
- Added: New "Duplicates" entry in the left menu beneath Repositories (dev mode + admin only) showing every duplicate template across the whole appfeed — every image with two-or-more templates anywhere, with all copies shown side by side using the same image-key normalization as the per-repo Duplicates button
- Added: New "Display usage graphs" setting (default off, 7.2+ only). When enabled on a 7.2+ "responsive" OS, the sidebar's live-stats panel renders for running docker containers. The setting card is always shown in Settings but stays disabled and greyed on legacy chrome (same pattern as the existing "Use whole display window" toggle). Setting sits in the Settings list immediately above "Enable developer mode".
- Added: Sidebar now shows live runtime stats for running docker containers. One small nchan publisher script (`scripts/caLiveStats.php`) is spawned per container being watched — sidebars on the same container share one publisher, sidebars on different containers spawn their own lightweight publishers. Each tick does exactly one Docker socket call (for that container) plus a handful of `/proc` and `/sys` reads for host counters, then broadcasts a flat JSON snapshot to channel `/sub/stats_<containerName>` every second. The sidebar subscribes via WebSocket; on each message it picks out the values, computes byte-rate deltas client-side, and updates the gauges and charts. Cadence is adaptive (publisher measures tick duration and sleeps `1s − elapsed`, floored at 100ms) so the rate stays close to 1Hz even on busy hosts. Each publisher self-terminates ~10 seconds after the last subscriber for its channel disconnects (via dynamix's `publish()` abort path), so an idle server with nobody watching the sidebar pays zero cost. The spawn endpoint is idempotent per-container: `pgrep --ns $$ -f` matches script-path + container-arg, so concurrent sidebar opens for the same container don't race-start duplicate publishers, and ones for different containers run independently. Chart X-axis uses the publisher's server-side timestamp (not browser arrival time) so the line stays smooth even if a WebSocket frame is briefly delayed. A Summary / CPU / Memory / Network I/O tab strip switches between a default summary view of four side-by-side analog-speedometer dials (CPU, Unraid CPU, Memory, Network) and three rolling SmoothieCharts (same library the Unraid dashboard uses for its CPU/network plots) sized to a ~10-second visible window. Each dial is a classic instrument-cluster gauge — white face with bezel, dark major + minor tick marks, yellow / orange / red warning bands at 70 / 85 / 95 %, blue center hub, tapered red needle, big metric label in the dial interior and current value below. Network's full-scale ceiling is the *fastest single NIC's* link speed (read from `/sys/class/net/*/speed`, max not sum, since a single flow can't outrun one wire), so the red zone literally maps to wire saturation; if no usable link speed is reported it falls back to a session peak. Chart views keep both container and host overlay lines for context, but the dials show container only — needles share the same axis and the host value is implicit (always ≥ container, visible on the chart tab when needed). CPU is host-normalized so a fully-saturated container caps at 100% regardless of how many cores it sees (unlike `docker stats`, which would report up to 100% × cores), with the chart's Y-axis tracking the visible-window peak rather than pinning at 100; the memory chart's Y-max is pinned to the host's total RAM so the container and host lines share a meaningful axis; the network chart plots RX / TX byte-rates derived from cumulative counters between polls. Disk I/O was deliberately not included — Docker's `blkio_stats` is cgroup-accounted and misses I/O that goes through bind-mounted volumes (which is most of what containers actually do on Unraid), so the per-container figure isn't honest and any host-vs-container comparison would be misleading. Summary view is sized to match the chart canvases so switching views doesn't reflow the popup. Chart canvases lazy-bind on first tab click but all series accumulate from poll one, so switching tabs after a while still shows history. Polling tears down automatically on sidebar close and restarts cleanly when the sidebar re-opens — plugins, language packs, repository cards, and stopped containers don't render the block
- Changed: Home page "Most Popular Plugins" row now ranks by *last month's* installs instead of lifetime downloads, and labels the row accordingly — surfaces what's actually catching on right now rather than perennial favorites
- Added: Plugin sidebars now show the same Trend / Downloads-Per-Month / Total-Downloads charts that Docker container sidebars have, computed from the appfeed's monthly plugin install stats (rolling 11-month window ending at the last complete month — current month is excluded as partial data)
- Added: Sidebar media gallery now includes a clickable thumbnail strip (carousel) at the bottom of the fullscreen popup when there's more than one screenshot/video — click any thumb to jump to that slide. The current slide's thumb is enlarged for easy tracking. Strip auto-hides on phone-sized or short viewports
- Added: App / repo icons now open the sidebar media gallery (instead of a solo popup) so you can arrow / carousel through icon → screenshots → videos as one gallery. Icon isn't shown twice in the visible media row
- Changed: Sidebar screenshots/videos appear above the README section instead of below, so visuals show up closer to the description
- Changed: Sidebar screenshots whose URL exactly matches the app icon are now dropped — templates that listed the icon under Screenshot no longer paint a duplicate icon-as-thumbnail in the media row
- Changed: Plugin trend-chart x-axis labels render as compact "Apr '26" style (no day number, year included so the year boundary is obvious)
- Fixed: Sidebar gallery thumbnails that fail to load are now removed from the gallery entirely rather than swapped for a placeholder image — broken screenshots/videos no longer occupy slots in the carousel
- Fixed: App / repo profile icons that fail to load keep the placeholder but are no longer clickable — clicking a question-mark icon used to open a fullscreen placeholder
- Fixed: Sidebar media gallery from a previously-opened app no longer carries over its thumbnail strip when the next popup opens (e.g. clicking an app icon, or visiting an app with no media)
- Fixed: Videos in the gallery attempt to resume from where you left off when arrow-navigating away and back in the same browser session (best-effort — only works for HTTPS Unraid GUIs and not protected/restricted YouTube videos)
- Fixed: Sidebar README cache now keys off both the repository and the app name, so two templates sharing a repo no longer serve each other's README from cache
- Changed: Assorted UI tweaks across cards, sidebar, search popup, and diff overlay
- Changed: Mobile / responsive layout improvements
- Changed: CA now follows the active Unraid OS theme more strictly on 7.2+ — custom themes inherit automatically
- Changed: Cards show every applicable status badge (Installed + Updated, Incompatible + Deprecated, etc.) instead of only the highest-priority one — extra badges wrap to a second row inside the top-right corner without crossing the icon. Blacklist supersedes Deprecated and the LT-branded "Official" supersedes the plain "Official" so duplicate chips don't pile up
- Added: Sidebar header now shows the same status badge row above the app icon — surfaces Installed / Updated / Incompatible / etc. without having to scroll the sidebar body
- Fixed: Plugin sidebar now shows the "Update" button when an update is available — the previous build silently dropped it on every sidebar open after the first
- Fixed: Uninstalling a language pack now refreshes the application list immediately — cards were stuck showing "Installed" until you navigated away and back (Action Centre was unaffected)
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
