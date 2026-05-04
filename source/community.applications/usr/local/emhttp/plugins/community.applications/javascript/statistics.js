/**
 * Load and render a moderation panel into the sidenav for the specified script.
 *
 * Requests moderation data for the given `script` and replaces the sidenav content
 * with the rendered moderation UI when data is returned. For the "Repository"
 * view, collects the current ignored-repository list and submits it back to the
 * server; if the server reports a change, sets `window.caRepoIgnoreDirty` to true.
 * If data cannot be loaded, a warning notice is shown in the sidenav.
 *
 * @param {string} script - The moderation view to display (e.g., "Repository", "Invalid", "Fixed").
 * @param {string} [title] - Optional title for the moderation panel.
 */

function showModeration(script, title) {
	$("#sidenavContent").html("<div class='moderationContainer'></div>");
	$(".moderationContainer").html($("#sidebarLoading").html());
	post({ action: "showModeration", script: script }, function(result) {
		if (result && result.data) {
			$("#sidenavContent").html("<div class='moderationContainer'>" + renderModerationContent(script, result.data) + "</div>");
			/* For the Repository view: once the table is rendered with rows
			   pre-marked from the on-disk ignored list, immediately POST that
			   same list back so the server can prune any stale entries that
			   no longer correspond to a real repo. The server's diff check
			   is the gate — if nothing actually changed, no write happens. */
			if (script === "Repository") {
				const initial = caCollectIgnoredRepos();
				if (initial !== null) {
					post({ action: "saveIgnoredRepos", ignored: JSON.stringify(initial) }, function(res) {
						if (res && res.changed) window.caRepoIgnoreDirty = true;
					});
				}
			}
		} else {
			$("#sidenavContent").html("<div class='notice warning'>" + tr("Unable to load moderation data") + "</div>");
		}
	});
}

/**
 * Escape special HTML characters in the given value for safe insertion into HTML.
 *
 * Converts the value to a string and replaces &, <, >, ", and ' with their HTML entities.
 * @param {*} value - The value to escape; null or undefined become an empty string.
 * @returns {string} The escaped string suitable for embedding in HTML.
function caEscapeHtml(value) {
	if (value === undefined || value === null) return "";
	return String(value)
		.replace(/&/g, "&amp;")
		.replace(/</g, "&lt;")
		.replace(/>/g, "&gt;")
		.replace(/"/g, "&quot;")
		.replace(/'/g, "&#39;");
}

/**
 * Convert a value to an HTML-safe string suitable for insertion into page markup.
 * @param {*} value - The value to convert (may be an array, boolean, string, number, null, or undefined).
 * @returns {string} An HTML-safe string:
 *  - `""` for `null` or `undefined`.
 *  - For arrays: the JSON string of the array with HTML characters escaped.
 *  - For booleans: the localized `"Yes"` or `"No"`.
 *  - For other values: the string form with HTML characters escaped and newlines converted to `<br>`.
 */
function caValueToHtml(value) {
	if (value === undefined || value === null) return "";
	if (Array.isArray(value)) {
		return caEscapeHtml(JSON.stringify(value));
	}
	if (typeof value === "boolean") {
		return value ? tr("Yes") : tr("No");
	}
	return caEscapeHtml(String(value)).replace(/\n/g, "<br>");
}

/**
 * Clone the first child element of the DOM element identified by the given id.
 * @param {string} id - The id of the container element (without the leading `#`).
 * @return {jQuery} The cloned first child element.
 */
function caCloneTemplate(id) {
	return $("#" + id).children().first().clone();
}

/**
 * Render the moderation panel HTML for the given moderation script.
 *
 * @param {string} script - The moderation view to render; supported values: `"Repository"`, `"Invalid"`, `"Fixed"`.
 * @param {Object} payload - Server-provided data used to populate the selected moderation view.
 * @returns {string} An HTML string for the requested moderation panel, or a warning notice if the script is unrecognized.
 */
function renderModerationContent(script, payload) {
	switch (script) {
		case "Repository":
			return renderModerationRepositories(payload);
		case "Invalid":
			return renderModerationInvalid(payload);
		case "Fixed":
			return renderModerationFixed(payload);
		default:
			return "<div class='notice warning'>" + tr("Unable to load moderation data") + "</div>";
	}
}

/**
 * Render the "Repository" moderation view as an HTML string.
 *
 * Builds a table of repositories with per-row ignore state and toggle controls; first-party
 * Unraid repositories are rendered as protected (no toggle and cannot be ignored).
 *
 * @param {Object} payload - Server-provided payload.
 * @param {Array<Object>} [payload.repositories] - Repository entries; each may contain `name` and `url`.
 * @param {Array<string>} [payload.ignored] - Repository names that should be initially marked ignored; this list is stored on the rendered table in the `data-ignored-initial` attribute.
 * @returns {string} HTML markup for the rendered moderation shell containing the repository table.
 */
function renderModerationRepositories(payload) {
	const repos = Array.isArray(payload.repositories) ? payload.repositories : [];
	/* Initial ignored set — server reads CA_PATHS['ignoredRepos']; rows that
	   are already in the set render with strike-through + minus icon. */
	const ignoredInitial = Array.isArray(payload.ignored) ? payload.ignored : [];
	const $shell = caCloneTemplate("caModerationShellTemplate");
	const $body = $shell.find(".caModerationBody");
	const $tableWrap = caCloneTemplate("caModerationRepositoryTableTemplate");
	const $table = $tableWrap.find(".caModerationRepoTable");
	$table.attr("data-ignored-initial", JSON.stringify(ignoredInitial));
	const ignoredSet = ignoredInitial.reduce(function(acc, n) { acc[n] = true; return acc; }, {});
	repos.forEach(function(repo) {
		const name = repo.name || "";
		const url = repo.url || "";
		const isIgnored = !!ignoredSet[name];
		/* First-party Unraid repos can't be ignored — skip the +/- cell so
		   the user can't toggle them (and the row is never in the posted
		   ignore list either). Match `github.com/unraid/` in the URL. */
		const isProtected = /github\.com\/unraid\//i.test(url);
		const $row = $("<tr></tr>").attr("data-repo-name", name);
		if (!isProtected && isIgnored) $row.addClass("caRepoIgnored");
		const $toggleCell = $("<td class='caRepoIgnoreCell'></td>");
		if (!isProtected) {
			const $toggle = $("<span class='caRepoIgnoreToggle ca_href' role='button' tabindex='0'></span>")
				.text(isIgnored ? "+" : "−");          /* + when hidden, − when active */
			$toggleCell.append($toggle);
		}
		$row.append($toggleCell);
		$row.append($("<td></td>").append($("<span class='caRepoName ca_bold'></span>").text(name)));
		$row.append($("<td></td>").append($("<a class='popUpLink' target='_blank' rel='noopener noreferrer'></a>").attr("href", url).text(url)));
		$table.append($row);
	});
	$body.append($tableWrap);
	return $("<div></div>").append($shell).html();
}

/* Click handler for the +/- icon — toggles the row's ignored state and
   immediately persists the full current selection to the flash drive. The
   server is authoritative on whether the file actually changed; if it did,
   /tmp/$CA gets wiped so the next feed-driven render reflects the new list,
   and we set a session-scoped dirty flag so showStatistics() (or any other
   exit path) knows to trigger a Home reload. Body-delegated so it works
   against the cloned alt-view markup that lives under #sidenavContent. */
window.caRepoIgnoreDirty = false;
$(function() {
	$("body").on("click keydown", ".caRepoIgnoreToggle", function(e) {
		if (e.type === "keydown" && e.key !== "Enter" && e.key !== " ") return;
		e.preventDefault();
		const $row = $(this).closest("tr[data-repo-name]");
		const willIgnore = !$row.hasClass("caRepoIgnored");
		$row.toggleClass("caRepoIgnored", willIgnore);
		$(this).text(willIgnore ? "+" : "−");
		const current = caCollectIgnoredRepos();
		if (current === null) return;
		post({ action: "saveIgnoredRepos", ignored: JSON.stringify(current) }, function(result) {
			if (result && result.changed) window.caRepoIgnoreDirty = true;
		});
	});
});

/**
 * Collects the list of currently ignored repository names from the rendered moderation table.
 * @returns {string[] | null} A sorted array of ignored repository names, or `null` if the moderation repository table is not present.
 */
function caCollectIgnoredRepos() {
	const $table = $(".caModerationRepoTable");
	if (!$table.length) return null;
	const ignored = [];
	$table.find("tr.caRepoIgnored[data-repo-name]").each(function() {
		ignored.push($(this).attr("data-repo-name") || "");
	});
	return ignored.filter(Boolean).sort();
}

/**
 * Restart the Home page when the repository ignore list was modified during this session.
 *
 * If the session-scoped dirty flag is set, clears it and activates the first
 * Home startup button so the page restarts against the updated on-disk state.
 * Any errors during activation are ignored.
 */
function caRestartIfRepoIgnoreDirty() {
	if (!window.caRepoIgnoreDirty) return;
	window.caRepoIgnoreDirty = false;
	try { $(".startupButton").first().trigger("click"); } catch (e) { /* no-op */ }
}

/**
 * Renders the "Invalid" moderation panel from the provided payload and returns the resulting HTML.
 *
 * @param {Object} payload - Moderation data.
 *   - {string} [intro] - Optional introductory text for the panel.
 *   - {Array<Object>} [items] - Array of invalid-item objects. Each item may include:
 *       - {string} [title] - Title for the item.
 *       - {Array<Object>} [details] - Array of detail objects. Each detail may include:
 *           - {string} [label] - Optional label for the detail.
 *           - {*} value - Detail value (rendered via caValueToHtml).
 *           - {boolean} [isSubRule] - When true, the detail is rendered as a sub-rule.
 * @returns {string} HTML string containing the rendered invalid-templates moderation panel.
 */
function renderModerationInvalid(payload) {
	const items = Array.isArray(payload.items) ? payload.items : [];
	const $shell = caCloneTemplate("caModerationShellTemplate");
	const $body = $shell.find(".caModerationBody");
	if (!items.length) {
		$body.append("<br><br><div class='ca_center'><span class='ca_bold'>" + tr("No invalid templates found") + "</span></div>");
		return $("<div></div>").append($shell).html();
	}
	const $list = caCloneTemplate("caModerationInvalidListTemplate");
	$list.find(".caModerationInvalidIntro .ca_moderationTitle").text(payload.intro || "");
	const $itemsWrap = $list.find(".caModerationInvalidItems");
	items.forEach(function(item) {
		const $item = caCloneTemplate("caModerationInvalidItemTemplate");
		$item.find(".ca_moderationTitle").text(item.title || "");
		const details = Array.isArray(item.details) ? item.details : [];
		const $details = $item.find(".ca_moderationDetails");
		details.forEach(function(detail) {
			const label = detail.label || "";
			const subClass = detail.isSubRule ? " ca_moderationSubRule" : "";
			const $rule = $("<div class='ca_moderationRule" + subClass + "'></div>");
			if (label) {
				$rule.append($("<span class='ca_bold'></span>").text(label + ": "));
				$rule.append(caValueToHtml(detail.value));
			} else {
				$rule.html(caValueToHtml(detail.value));
			}
			$details.append($rule);
		});
		$itemsWrap.append($item);
	});
	$body.append($list);
	return $("<div></div>").append($shell).html();
}

/**
 * Render the "Fixed" moderation panel showing repositories that were automatically fixed and related summaries.
 *
 * Builds and returns the HTML for the fixed-results moderation shell using fields from the payload:
 * - payload.repositories: array of repository objects (each may include name, fixCount, items[] with entry.name and entry.errors[]).
 * - payload.pluginDupes: array of plugin-duplicate groups (each may include filename and entries[]).
 * - payload.duplicateRepos: array of duplicate-repo lines.
 * - payload.intro, payload.notes, payload.helpUrl, payload.pluginDupesTitle, payload.duplicateReposTitle: optional display strings.
 *
 * @param {Object} payload - Data used to populate the fixed moderation view.
 * @returns {string} The rendered moderation shell as an HTML string.
 */
function renderModerationFixed(payload) {
	const repos = Array.isArray(payload.repositories) ? payload.repositories : [];
	const pluginDupes = Array.isArray(payload.pluginDupes) ? payload.pluginDupes : [];
	const duplicateRepos = Array.isArray(payload.duplicateRepos) ? payload.duplicateRepos : [];
	const $shell = caCloneTemplate("caModerationShellTemplate");
	const $body = $shell.find(".caModerationBody");
	if (!repos.length && !pluginDupes.length && !duplicateRepos.length) {
		$body.append("<br><br><div class='ca_center'><span class='ca_bold'>" + tr("No templates were automatically fixed") + "</span></div>");
		return $("<div></div>").append($shell).html();
	}
	if (!repos.length) {
		$body.append("<br><br><div class='ca_center'><span class='ca_bold'>" + tr("No templates were automatically fixed") + "</span></div>");
	}

	const $fixed = caCloneTemplate("caModerationFixedTemplate");
	const intro = caEscapeHtml(payload.intro || "");
	const notes = caEscapeHtml(payload.notes || "");
	const helpUrl = caEscapeHtml(payload.helpUrl || "#");
	$fixed.find(".caModerationFixedIntro").html(intro + "<br><br>" + notes + " <a href='" + helpUrl + "' target='_blank'>" + tr("HERE") + "</a><br><br>");
	const $jump = $fixed.find(".caFixedRepoJump");
	repos.forEach(function(repo, idx) {
		$jump.append($("<option></option>").val("caFixedItem" + idx).text(repo.name || ""));
	});
	if (pluginDupes.length) {
		$fixed.find(".caModerationJumpPluginDupes").removeClass("ca_hide");
	}
	if (duplicateRepos.length) {
		$fixed.find(".caModerationJumpDuplicateRepos").removeClass("ca_hide");
	}
	if (pluginDupes.length && duplicateRepos.length) {
		$fixed.find(".caModerationJumpSep").removeClass("ca_hide");
	}
	const $list = $fixed.find(".caModerationFixedList");
	repos.forEach(function(repo, idx) {
		const $repoItem = caCloneTemplate("caModerationFixedRepoTemplate");
		const itemId = "caFixedItem" + idx;
		const detailsId = "caFixedDetails" + idx;
		const fixCount = parseInt(repo.fixCount || 0, 10) || 0;
		const fixLabel = fixCount === 1 ? tr("fix") : tr("fixes");
		$repoItem.attr("id", itemId);
		$repoItem.find(".ca_fixedDetails").attr("id", detailsId);
		$repoItem.find(".ca_fixedToggle")
			.attr("onclick", "caToggleFixedDetails(\"" + detailsId + "\", this);")
			.attr("aria-label", tr("Toggle repository details"));
		$repoItem.find(".caModerationFixedRepoTitle").text((repo.name || "") + " (" + fixCount + " " + fixLabel + ")");
		const $details = $repoItem.find(".ca_fixedDetails");
		(Array.isArray(repo.items) ? repo.items : []).forEach(function(entry) {
			const $entry = caCloneTemplate("caModerationFixedEntryTemplate");
			$entry.find(".caModerationFixedEntryName").text(entry.name || "");
			const $errors = $entry.find(".caModerationFixedErrors");
			(Array.isArray(entry.errors) ? entry.errors : []).forEach(function(error) {
				$errors.append($("<div class='ca_moderationRule ca_moderationSubRule'></div>").text(error));
			});
			$details.append($entry.children());
		});
		$list.append($repoItem);
	});
	$body.append($fixed);

	if (pluginDupes.length) {
		const $dupes = $("<div></div>");
		$dupes.append("<br><br>");
		$dupes.append($("<span class='ca_bold'></span>").text(payload.pluginDupesTitle || ""));
		$dupes.append("<br><br>");
		pluginDupes.forEach(function(item) {
			$dupes.append($("<span class='ca_bold'></span>").text(item.filename || ""));
			$dupes.append("<br>");
			(Array.isArray(item.entries) ? item.entries : []).forEach(function(entry) {
				$dupes.append($("<tt></tt>").text(entry).append("<br>"));
			});
			$dupes.append("<br>");
		});
		$body.find(".caModerationFixedPluginDupes").append($dupes.children());
	}

	if (duplicateRepos.length) {
		const $dupRepos = $("<div></div>");
		$dupRepos.append("<br>");
		$dupRepos.append($("<span class='ca_bold'></span>").text(payload.duplicateReposTitle || ""));
		$dupRepos.append("<br><br>");
		const $tt = $("<tt></tt>");
		duplicateRepos.forEach(function(line) {
			$tt.append(document.createTextNode(line));
			$tt.append("<br>");
		});
		$dupRepos.append($tt);
		$body.find(".caModerationFixedDuplicateRepos").append($dupRepos.children());
	}
	return $("<div></div>").append($shell).html();
}

/**
 * Toggle the visibility of a fixed-details section and update related UI state.
 *
 * Finds the element with the given id and toggles its display between visible and hidden;
 * if a button element is provided, updates its icon to a plus or minus accordingly,
 * and then synchronizes the global "toggle all" control state.
 *
 * @param {string} id - The DOM element id of the details section to toggle.
 * @param {HTMLElement|jQuery|undefined} [button] - Optional button element whose icon will be updated to reflect the new visibility.
 */
function caToggleFixedDetails(id, button) {
	var $section = $("#" + id);
	if (!$section.length) return;
	var isHidden = $section.css("display") === "none";
	$section.css("display", isHidden ? "block" : "none");
	if (button) $(button).html(isHidden ? "<i class='fa fa-minus' aria-hidden='true'></i>" : "<i class='fa fa-plus' aria-hidden='true'></i>");
	caSyncFixedToggleAllState();
}

/**
 * Show or hide all "fixed" detail sections in the moderation panel.
 * Updates each details section's visibility, each per-repo toggle icon, and the global "toggle all" button state and label.
 * @param {boolean} showAll - `true` to expand all fixed-detail sections, `false` to collapse them.
 */
function caSetAllFixedDetails(showAll) {
	var $root = $("#sidenavContent .moderationContainer").first();
	if (!$root.length) return;
	$root.find(".ca_fixedDetails").css("display", showAll ? "block" : "none");
	$root.find(".ca_fixedToggle").html(showAll ? "<i class='fa fa-minus' aria-hidden='true'></i>" : "<i class='fa fa-plus' aria-hidden='true'></i>");
	var $allButton = $root.find(".caFixedToggleAll").first();
	if ($allButton.length) {
		$allButton.attr("data-show-all", showAll ? "1" : "0");
		$allButton.text(showAll ? tr("Hide All") : tr("Show All"));
	}
}

/**
 * Toggle visibility of all fixed-details sections based on the toggle button's state.
 * @param {HTMLElement} [button] - Optional toggle button whose `data-show-all` attribute indicates the current state; when `data-show-all` is `"1"` the sections will be collapsed, otherwise they will be expanded.
 */
function caToggleAllFixedDetails(button) {
	var showAll = !(button && button.dataset && button.dataset.showAll === "1");
	caSetAllFixedDetails(showAll);
}

/**
 * Update the "Show/Hide All" control to reflect whether any fixed-detail sections are expanded.
 *
 * Locates the first `.moderationContainer` inside `#sidenavContent`, checks `.ca_fixedDetails` elements for visible sections,
 * and sets the `.caFixedToggleAll` button's `data-show-all` attribute to `"1"` when any section is expanded or `"0"` otherwise.
 * The button text is updated to the localized "Hide All" or "Show All" string.
 */
function caSyncFixedToggleAllState() {
	var $root = $("#sidenavContent .moderationContainer").first();
	if (!$root.length) return;
	var $sections = $root.find(".ca_fixedDetails");
	var $allButton = $root.find(".caFixedToggleAll").first();
	if (!$allButton.length || !$sections.length) return;
	var anyExpanded = $sections.toArray().some(function(el) {
		return $(el).css("display") !== "none";
	});
	$allButton.attr("data-show-all", anyExpanded ? "1" : "0");
	$allButton.text(anyExpanded ? tr("Hide All") : tr("Show All"));
}

/**
 * Scrolls the selected fixed-repository row into view and expands its details if hidden.
 * @param {HTMLSelectElement|HTMLElement} select - A select element whose `value` is the id of the target repository row; if falsy or the target is not found, the function does nothing.
 */
function caJumpToFixedRepository(select) {
	if (!select || !select.value) return;
	var $row = $("#" + select.value);
	if (!$row.length) return;
	var $details = $row.find(".ca_fixedDetails").first();
	var $toggle = $row.find(".ca_fixedToggle").first();
	if ($details.length && $details.css("display") === "none") {
		$details.css("display", "block");
		if ($toggle.length) $toggle.html("<i class='fa fa-minus' aria-hidden='true'></i>");
	}
	caSyncFixedToggleAllState();
	$row[0].scrollIntoView({ behavior: "smooth", block: "start" });
}

/**
 * Scroll the moderation sidebar so the first element matching the selector is visible with a 16px top offset.
 * @param {string} selector - CSS selector for an element inside the moderation container; if falsy or the element is not found, the function does nothing.
 */
function caScrollModerationSection(selector) {
	if (!selector) return;
	var $container = $("#sidenavContent .moderationContainer");
	var $target = $container.find(selector).first();
	var $sidenav = $(".sidenav");
	if (!$target.length || !$sidenav.length) return;
	var nextTop = $sidenav.scrollTop() + $target.position().top - 16;
	$sidenav.stop(true).animate({ scrollTop: nextTop }, 250);
}

/**
 * Load server statistics and populate the statistics sidebar view.
 *
 * Fetches statistics from the server, updates fields and links inside the
 * #caStatisticsTemplate (including conditional display of the private row),
 * and switches the content to the statistics alternate view. If the session's
 * repository-ignore state was changed on disk, requests a restart before loading.
 */
function showStatistics() {
	/* If the user's repo-ignore toggles actually changed the on-disk list
	   during this session, restart via Home so the rebuilt feed is used. */
	caRestartIfRepoIgnoreDirty();
	post({ action: "statistics" }, function(result) {
		const stats = (result && result.statistics) || {};
		const unknownLabel = tr("unknown");
		const primaryServerLabel = tr("Primary Server");
		const safeValue = (value, fallback = "0") => {
			if (value === undefined || value === null || value === "") return fallback;
			return value;
		};
		const safeUrl = (url) => (typeof url === "string" && url.length) ? url : "#";
		const $content = $("#caStatisticsTemplate");
		const setText = (key, value, fallback = "0") => {
			const $el = $content.find("[data-stat-text='" + key + "']");
			if ($el.length) $el.text(safeValue(value, fallback));
		};
		const setHtml = (key, value, fallback = "") => {
			const $el = $content.find("[data-stat-html='" + key + "']");
			if ($el.length) $el.html(value !== undefined && value !== null && value !== "" ? value : fallback);
		};

		setText("updateTime", stats.updateTime, "");
		setHtml("currentServer", stats.currentServer, primaryServerLabel);
		setText("docker", stats.docker);
		setText("plugin", stats.plugin);
		setText("totalApplications", stats.totalApplications);
		setText("official", stats.official);
		setText("repositories", stats.repositories, unknownLabel);
		const hasPrivate = parseInt(stats.private, 10) > 0;
		const $privateRow = $content.find("[data-stat-row='private']");
		if ($privateRow.length) {
			if (hasPrivate) {
				$privateRow.show();
				setText("private", stats.private);
			} else {
				$privateRow.hide();
			}
		}
		setText("invalidXML", stats.invalidXML, unknownLabel);
		setText("caFixed", safeValue(stats.caFixed) + "+");
		setText("blacklist", stats.blacklist);
		setText("totalIncompatible", stats.totalIncompatible);
		setText("totalDeprecated", stats.totalDeprecated);
		$content.find("[data-stat-href='primaryServerUrl']").attr("href", safeUrl(stats.primaryServerUrl));
		$content.find("[data-stat-href='backupServerUrl']").attr("href", safeUrl(stats.backupServerUrl));

		$content.showAlternateView();
	});
}
