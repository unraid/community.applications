/**
 * Render the moderation pane for the given script and load its data from the server.
 *
 * Replaces the sidebar content with a moderation container, shows a loading state, requests moderation data,
 * and injects the rendered moderation HTML when data is returned. For the "Repository" script, collects the
 * currently ignored repositories and posts them back to the server so the server can prune stale entries;
 * if the server reports changes, sets `window.caRepoIgnoreDirty = true`. If the request fails or returns no data,
 * shows a warning notice in the sidebar.
 *
 * @param {string} script - The moderation view to load (e.g., "Repository", "Invalid", "Fixed").
 * @param {string} [title] - Optional title for the moderation pane (not required by all scripts).
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
 * Produce an HTML-escaped string safe for insertion into HTML.
 *
 * Converts `undefined` and `null` to an empty string, otherwise returns the input
 * coerced to string with the characters `&`, `<`, `>`, `"`, and `'` replaced by
 * their corresponding HTML entities.
 * @param {*} value - The value to escape; may be any type (including null/undefined).
 * @returns {string} The escaped string suitable for HTML contexts.
 */
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
 * Convert a value into HTML-safe content suitable for insertion into the UI.
 * @param {*} value - The value to format; may be a string, boolean, array, null, or undefined.
 * @returns {string} A string containing HTML-safe content: empty for `null`/`undefined`; localized `"Yes"`/`"No"` for booleans; escaped JSON for arrays; otherwise the escaped string with line breaks replaced by `<br>`.
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
 * Clone the first child element of the DOM node with the given id.
 * @param {string} id - The id of the container element (without a leading `#`).
 * @return {jQuery} A cloned jQuery element of the container's first child.
 */
function caCloneTemplate(id) {
	return $("#" + id).children().first().clone();
}

/**
 * Selects the appropriate moderation renderer for the given script type and returns its HTML.
 * @param {string} script - One of "Repository", "Invalid", or "Fixed" indicating which moderation view to render.
 * @param {Object} payload - Data object passed to the chosen renderer; structure depends on `script`.
 * @returns {string} The rendered HTML for the requested moderation content, or a warning notice HTML if `script` is unrecognized.
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
 * Build the "Repositories" moderation section HTML from server payload.
 *
 * @param {Object} payload - Data from the server for repository moderation.
 * @param {Array<Object>} [payload.repositories] - List of repository objects; each may contain `name` and `url`.
 * @param {Array<string>} [payload.ignored] - List of repository names that should be initially marked ignored.
 * @return {string} The rendered HTML for the repositories moderation table. The output includes per-repository rows with an ignore toggle (except for protected Unraid first-party repos), rows marked `caRepoIgnored` for initially ignored entries, and a `data-ignored-initial` attribute on the table containing the JSON-encoded initial ignored list.
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
 * Collects the repository names currently marked as ignored in the moderation table.
 *
 * @returns {string[]|null} Sorted array of ignored repository names, or `null` if the moderation table is not present.
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
 * Trigger a Home restart when the repository ignore list has been modified.
 *
 * If the session-level dirty flag `window.caRepoIgnoreDirty` is set, this clears
 * the flag and attempts to activate the first `.startupButton` to restart the
 * page so the UI reloads against the updated on-disk state. Any errors during
 * the attempt are ignored.
 */
function caRestartIfRepoIgnoreDirty() {
	if (!window.caRepoIgnoreDirty) return;
	window.caRepoIgnoreDirty = false;
	try { $(".startupButton").first().trigger("click"); } catch (e) { /* no-op */ }
}

/**
 * Render the "Invalid templates" moderation section as an HTML string.
 *
 * Renders a list of invalid template items (or a "No invalid templates found" message when empty)
 * into the moderation shell and returns the resulting HTML.
 *
 * @param {Object} payload - Data used to build the section.
 * @param {Array<Object>} [payload.items] - Array of invalid items. Each item may include:
 *   - {string} [title] - Item title.
 *   - {Array<Object>} [details] - Detail entries for the item; each detail may include:
 *       - {string} [label] - Optional bold label for the detail.
 *       - {*} [value] - Detail value; converted to safe HTML by caValueToHtml.
 *       - {boolean} [isSubRule] - When true, renders the detail with sub-rule styling.
 * @param {string} [payload.intro] - Intro text shown above the items.
 * @returns {string} The rendered HTML for the invalid templates moderation section.
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
 * Render the "Fixed" moderation view and return its HTML.
 *
 * Builds the fixed-templates section including a jump/select for repositories, per-repository
 * fixed-item lists with expand/collapse controls, and optional plugin-duplicate and
 * duplicate-repository subsections. If no fixed data is provided, the output contains a
 * centered "No templates were automatically fixed" message.
 *
 * @param {Object} payload - Data used to populate the fixed view.
 * @param {Array<Object>} [payload.repositories] - Repository entries; each may include `name`, `fixCount`, and `items` (array of entries with `name` and `errors`).
 * @param {Array<Object>} [payload.pluginDupes] - Plugin-duplicate entries; each may include `filename` and `entries` (array of strings).
 * @param {Array<string>} [payload.duplicateRepos] - Lines describing duplicate repositories.
 * @param {string} [payload.intro] - Introductory HTML/text for the fixed section.
 * @param {string} [payload.notes] - Additional notes to display.
 * @param {string} [payload.helpUrl] - URL used for the help link (fallbacks to "#").
 * @param {string} [payload.pluginDupesTitle] - Title for the plugin-duplicates subsection.
 * @param {string} [payload.duplicateReposTitle] - Title for the duplicate-repositories subsection.
 * @returns {string} The rendered HTML for the fixed moderation content.
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
 * Toggle visibility of a fixed-repository details section in the moderation view.
 *
 * @param {string} id - ID of the details container to toggle (without the leading `#`).
 * @param {Element|jQuery} [button] - Optional toggle button element; its icon will be updated to indicate expanded/collapsed state.
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
 * Show or hide all fixed-entry detail sections within the moderation sidebar.
 *
 * @param {boolean} showAll - If true, expand all fixed details and set the master toggle to "Hide All"; if false, collapse all details and set the master toggle to "Show All".
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
 * Toggle the visibility of all fixed-details sections in the moderation view.
 *
 * Determines the intended "show all" state from the provided toggle control's
 * `data-show-all` attribute and applies that state to all fixed-detail sections.
 *
 * @param {HTMLElement} button - The toggle control element whose `dataset.showAll`
 *   value (`"1"` or other) indicates the current "show all" state; may be null or undefined.
 */
function caToggleAllFixedDetails(button) {
	var showAll = !(button && button.dataset && button.dataset.showAll === "1");
	caSetAllFixedDetails(showAll);
}

/**
 * Update the "Show All" control to match the current expanded/collapsed state of fixed-detail sections.
 *
 * Sets the `.caFixedToggleAll` button's `data-show-all` attribute and visible text to "Hide All" when any
 * `.ca_fixedDetails` element is visible, or "Show All" when all are hidden.
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
 * Scrolls to the fixed-repository section identified by the select's value and ensures its details are visible.
 *
 * If the selected value matches an element id, the function expands that repository's details (if hidden),
 * updates the repository toggle icon to indicate expanded state, synchronizes the global show/hide-all control,
 * and scrolls the repository row into view with smooth behavior. If `select` is invalid or the target element
 * cannot be found, the function returns without action.
 *
 * @param {HTMLSelectElement|HTMLElement} select - The select element whose current `value` is the id of the target repository row.
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
 * Scrolls the moderation sidebar so the first element matching `selector` is near the top.
 *
 * Smoothly animates the sidenav's scrollTop to position the target inside `#sidenavContent .moderationContainer`
 * with a 16px top offset over 250ms. If `selector` is falsy or no matching target/sidenav is found, the function does nothing.
 *
 * @param {string|Element|jQuery} selector - A CSS selector, DOM element, or jQuery object identifying the target inside the moderation container.
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
 * Fetches server statistics and populates the statistics view in the sidebar.
 *
 * Performs a restart-check for pending repository-ignore changes, requests statistics
 * from the server, and updates the #caStatisticsTemplate elements (text, HTML, and href
 * placeholders). Shows or hides the private-stats row based on the returned value
 * and invokes the template's alternate view display when complete.
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
