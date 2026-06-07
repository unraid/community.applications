/*
########################################
#                                      #
# Community Applications               #
# Copyright 2020-2026, Lime Technology #
# Copyright 2015-2026, Andrew Zawadzki #
#                                      #
# Licensed under GPL-2.0-or-later      #
#                                      #
########################################

SPDX-License-Identifier: GPL-2.0-or-later
*/

/**
 * @file Moderation/repository display, charts, and auxiliary statistics UI for Community Applications.
 */

/**
 * Load a moderation/statistics script into the sidebar via POST `showModeration`.
 *
 * @param {string} script Which dataset to render (e.g. "Repository", "Invalid", "Fixed")
 * @param {string} title Window/sidebar title (unused in some paths)
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
 * Escape text for safe HTML insertion.
 *
 * @param {string|undefined|null} value
 * @returns {string}
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
 * Render a JSON value as display HTML (arrays JSON-stringified, booleans translated).
 *
 * @param {*} value
 * @returns {string}
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
 * Return a deep clone of the first child of a hidden template `#id` (for moderation shells).
 *
 * @param {string} id Element id
 * @returns {jQuery}
 */
function caCloneTemplate(id) {
	return $("#" + id).children().first().clone();
}

/**
 * Dispatch to the renderer for the selected moderation view.
 *
 * @param {string} script View name
 * @param {object} payload Server JSON
 * @returns {string} HTML snippet
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
 * Build the repository moderation table (ignore toggles, links, initial ignored set).
 *
 * @param {object} payload
 * @param {Array<{name: string, url: string}>} [payload.repositories]
 * @param {string[]} [payload.ignored]
 * @returns {string} HTML
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
	/* Filter link — show only the disabled (ignored) repos, with a toggle back
	   to the full list. Only meaningful when at least one repo is disabled, so
	   it starts hidden and caUpdateRepoFilterBar() shows/hides it as the user
	   toggles rows. */
	const hasDisabled = repos.some(function(r) {
		return ignoredSet[r.name] && !/github\.com\/unraid\//i.test(r.url || "");
	});
	const $filterBar = $("<div class='caRepoFilterBar'></div>");
	$filterBar.append($("<span class='caRepoFilterToggle popUpLink' role='button' tabindex='0'></span>").text(tr("Show disabled only")));
	if (!hasDisabled) $filterBar.addClass("ca_hide");
	$body.append($filterBar);
	$body.append($tableWrap);
	return $("<div></div>").append($shell).html();
}

/**
 * Show/hide the "Show disabled only" link based on whether any repo is currently
 * disabled, and drop out of filtered mode when the last disabled repo is
 * re-enabled (so the table never ends up showing nothing).
 *
 * @returns {void}
 */
function caUpdateRepoFilterBar() {
	const $table = $(".caModerationRepoTable");
	const $bar = $(".caRepoFilterBar");
	if (!$table.length || !$bar.length) return;
	const hasDisabled = $table.find("tr.caRepoIgnored[data-repo-name]").length > 0;
	$bar.toggleClass("ca_hide", !hasDisabled);
	if (!hasDisabled && $table.hasClass("caShowDisabledOnly")) {
		$table.removeClass("caShowDisabledOnly");
		$(".caRepoFilterToggle").text(tr("Show disabled only"));
	}
}

/* Click handler for the +/- icon — toggles the row's ignored state and
   immediately persists the full current selection to the flash drive. The
   server is authoritative on whether the file actually changed; if it did,
   /tmp/$CA gets wiped so the next feed-driven render reflects the new list,
   and we set a session-scoped dirty flag so showStatistics() (or any other
   exit path) knows to trigger a Home reload. Body-delegated so it works
   against the cloned alt-view markup that lives under #sidenavContent. */
window.caRepoIgnoreDirty = false;
/**
 * On DOM ready: delegate click/keydown on `.caRepoIgnoreToggle` to flip the
 * repository's ignored state, update the row's strike-through/icon, and POST
 * the new full ignore list to the server (sets `window.caRepoIgnoreDirty` when
 * the server reports an actual change so a later view transition can force a
 * reload).
 */
$(function() {
	/**
	 * Toggle the row's ignored state and persist the full ignore list to the server.
	 *
	 * @param {Event} e Click or keydown event (keydown only acts on Enter/Space).
	 */
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
		caUpdateRepoFilterBar();
	});

	/* "Show disabled only" / "Show all repositories" filter toggle. Adds a class
	   the CSS uses to hide non-ignored rows; flips the link label each click. */
	$("body").on("click keydown", ".caRepoFilterToggle", function(e) {
		if (e.type === "keydown" && e.key !== "Enter" && e.key !== " ") return;
		e.preventDefault();
		const $table = $(".caModerationRepoTable");
		if (!$table.length) return;
		const disabledOnly = $table.toggleClass("caShowDisabledOnly").hasClass("caShowDisabledOnly");
		$(this).text(disabledOnly ? tr("Show all repositories") : tr("Show disabled only"));
	});
});

/**
 * Collect the current ignore set from the live moderation table.
 *
 * Walks rows marked `.caRepoIgnored` in `.caModerationRepoTable` and returns a
 * sorted, deduplicated list of repository names. Returns `null` when the
 * moderation table is not currently rendered.
 *
 * @returns {string[]|null} Sorted ignored repo names, or null if no table is present.
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
 * If a prior toggle changed the on-disk ignore list, simulate a Home click to
 * restart the page so the rebuilt feed is used.
 *
 * Called on transitions out of the Repository view (`showStatistics()` and the
 * `.ca_modal_overlay` close path). Resets `window.caRepoIgnoreDirty` to false.
 *
 * @returns {void}
 */
function caRestartIfRepoIgnoreDirty() {
	if (!window.caRepoIgnoreDirty) return;
	window.caRepoIgnoreDirty = false;
	/* Show the reload-notice banner for 10s, THEN wipe /tmp and reload. The
	   wipe is deferred into the banner's beforeReload callback so it doesn't
	   take effect until the countdown elapses. */
	caShowReloadNoticeBanner(function(done) {
		post({ action: "clearTempForReload" }, function() { done(); });
	});
}

/**
 * Render "Invalid templates" list with rule details from payload.items.
 *
 * @param {object} payload
 * @returns {string} HTML
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
 * Render auto-fixed templates report (per-repo expandable sections, dupes, duplicate repo names).
 *
 * @param {object} payload
 * @returns {string} HTML
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
 * Toggle visibility of one repository's fix-detail block and refresh the global expand/collapse control.
 *
 * @param {string} id DOM id of `.ca_fixedDetails`
 * @param {HTMLElement|null} button Toggle button (icon swap)
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
 * Expand or collapse every `.ca_fixedDetails` block in the Fixed moderation view.
 *
 * @param {boolean} showAll True to show all details
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
 * Click handler target: flip between show-all and hide-all for fixed-template rows.
 *
 * @param {HTMLElement} button `.caFixedToggleAll` element
 */
function caToggleAllFixedDetails(button) {
	var showAll = !(button && button.dataset && button.dataset.showAll === "1");
	caSetAllFixedDetails(showAll);
}

/**
 * Update the "Show All / Hide All" button label/state from current expanded sections.
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
 * Jump to a repository block in the Fixed view from the `<select>` jump control.
 *
 * @param {HTMLSelectElement} select
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
 * Smooth-scroll the moderation container so `selector` is visible inside `.sidenav`.
 *
 * @param {string} selector CSS selector relative to `.moderationContainer`
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
 * Fetch and display CA usage statistics / charts in the sidebar (and handle repo-ignore reload).
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

		/* Dev/admin-only diagnostics. The server includes stats.tabId only when
		   dev+admin (caIsAdmin) is satisfied, so its presence is the admin
		   signal. Set the tab-id row synchronously (it's cloned into the live
		   view by showAlternateView below). */
		const isAdminDiag = stats.tabId !== undefined;
		if (isAdminDiag) {
			setText("tabId", stats.tabId, "");
			$content.find("#caTabIdRow").removeClass("ca_hide");
		}

		$content.showAlternateView();
		/* Opened from Credits? showAlternateView hides .popUpBack by default;
		   bring it back so there's a top-left arrow to Credits (the handler in
		   clickHandlers.js routes it there when caSidebarBackTarget is credits). */
		if (window.caSidebarBackTarget === "credits") $(".popUpBack").removeClass("ca_hide");

		/* Feed SHA comparison is a separate, slower call (downloads both mirrors
		   server-side), so fetch it after the view is up and patch the LIVE
		   #sidenavContent clone — modifying the template now would be ignored. */
		if (isAdminDiag) {
			postNoSpin({ action: "caCompareFeedShas" }, function(shaResult) {
				if (!shaResult || !shaResult.enabled || !shaResult.results) return;
				const $cell = $("#sidenavContent [data-stat-html='feedShaCheck']");
				if (!$cell.length) return; // user navigated away before it returned
				$cell.html(caBuildFeedShaHtml(shaResult.results));
				$("#sidenavContent #caFeedShaCheckRow").removeClass("ca_hide");
			});
		}
	});
}

/**
 * Build the primary-vs-GitHub-backup feed SHA comparison markup for the
 * statistics popup (dev/admin diagnostic). `results` is keyed small/full/
 * statistics, each { primary, backup, match } with null shas on fetch failure.
 *
 * @param {object} results
 * @returns {string} HTML
 */
function caBuildFeedShaHtml(results) {
	const order = [
		{ key: "small",      label: tr("Application feed (small)") },
		{ key: "full",       label: tr("Application feed (full)") },
		{ key: "statistics", label: tr("Statistics feed") }
	];
	const rows = order.map(function(item) {
		const r = results[item.key];
		if (!r) return "";
		let status;
		if (r.primary === null || r.backup === null) {
			let which;
			if (r.primary === null && r.backup === null) which = tr("both fetches failed");
			else if (r.primary === null)                 which = tr("primary fetch failed");
			else                                         which = tr("backup fetch failed");
			status = "<i class='fa fa-question-circle ca_serverWarning' aria-hidden='true'></i> " + which;
		} else if (r.match) {
			status = "<i class='fa fa-check' aria-hidden='true'></i> " + tr("in sync");
		} else {
			status = "<i class='fa fa-exclamation-triangle ca_serverWarning' aria-hidden='true'></i> " + tr("mismatch");
		}
		return "<div>" + item.label + ": " + status + "</div>";
	});
	return "<div class='ca_bold'>" + tr("Primary vs backup feed check") + "</div>" + rows.join("");
}
