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

function caEscapeHtml(value) {
	if (value === undefined || value === null) return "";
	return String(value)
		.replace(/&/g, "&amp;")
		.replace(/</g, "&lt;")
		.replace(/>/g, "&gt;")
		.replace(/"/g, "&quot;")
		.replace(/'/g, "&#39;");
}

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

function caCloneTemplate(id) {
	return $("#" + id).children().first().clone();
}

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

/* Collect the current ignore set from the live moderation table. Returns
   null if the table isn't currently rendered. */
function caCollectIgnoredRepos() {
	const $table = $(".caModerationRepoTable");
	if (!$table.length) return null;
	const ignored = [];
	$table.find("tr.caRepoIgnored[data-repo-name]").each(function() {
		ignored.push($(this).attr("data-repo-name") || "");
	});
	return ignored.filter(Boolean).sort();
}

/* Called on transitions out of the Repository view (showStatistics() and
   the .ca_modal_overlay close path). If any earlier toggle actually
   changed the on-disk list, click Home so the page restarts against the
   freshly-wiped /tmp tree. */
function caRestartIfRepoIgnoreDirty() {
	if (!window.caRepoIgnoreDirty) return;
	window.caRepoIgnoreDirty = false;
	try { $(".startupButton").first().trigger("click"); } catch (e) { /* no-op */ }
}

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

function caToggleFixedDetails(id, button) {
	var $section = $("#" + id);
	if (!$section.length) return;
	var isHidden = $section.css("display") === "none";
	$section.css("display", isHidden ? "block" : "none");
	if (button) $(button).html(isHidden ? "<i class='fa fa-minus' aria-hidden='true'></i>" : "<i class='fa fa-plus' aria-hidden='true'></i>");
	caSyncFixedToggleAllState();
}

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

function caToggleAllFixedDetails(button) {
	var showAll = !(button && button.dataset && button.dataset.showAll === "1");
	caSetAllFixedDetails(showAll);
}

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

function caScrollModerationSection(selector) {
	if (!selector) return;
	var $container = $("#sidenavContent .moderationContainer");
	var $target = $container.find(selector).first();
	var $sidenav = $(".sidenav");
	if (!$target.length || !$sidenav.length) return;
	var nextTop = $sidenav.scrollTop() + $target.position().top - 16;
	$sidenav.stop(true).animate({ scrollTop: nextTop }, 250);
}

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
