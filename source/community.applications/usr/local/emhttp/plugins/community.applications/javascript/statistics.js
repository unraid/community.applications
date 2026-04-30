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
	$("#sidenavContent").html("<div class='moderationContainer'><div>");
	$(".moderationContainer").html($("#sidebarLoading").html());
	post({ action: "showModeration", script: script }, function(result) {
		if (result && result.data) {
			$("#sidenavContent").html("<div class='moderationContainer'>" + renderModerationContent(script, result.data) + "</div>");
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
	const $shell = caCloneTemplate("caModerationShellTemplate");
	const $body = $shell.find(".caModerationBody");
	const $tableWrap = caCloneTemplate("caModerationRepositoryTableTemplate");
	const $table = $tableWrap.find(".caModerationRepoTable");
	repos.forEach(function(repo) {
		const name = repo.name || "";
		const url = repo.url || "";
		const $row = $("<tr></tr>");
		$row.append($("<td></td>").append($("<span class='ca_bold'></span>").text(name)));
		$row.append($("<td></td>").append($("<a class='popUpLink' target='_blank'></a>").attr("href", url).text(url)));
		$table.append($row);
	});
	$body.append($tableWrap);
	return $("<div></div>").append($shell).html();
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
	if (!repos.length) {
		$body.append("<br><br><div class='ca_center'><span class='ca_bold'>" + tr("No templates were automatically fixed") + "</span></div>");
		return $("<div></div>").append($shell).html();
	}

	const $fixed = caCloneTemplate("caModerationFixedTemplate");
	const intro = caEscapeHtml(payload.intro || "");
	const notes = caEscapeHtml(payload.notes || "");
	const helpUrl = caEscapeHtml(payload.helpUrl || "#");
	$fixed.find(".caModerationFixedIntro").html(intro + "<br><br>" + notes + " <a href='" + helpUrl + "' target='_blank'>" + tr("HERE") + "</a><br><br>");
	const $jump = $fixed.find("#caFixedRepoJump");
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
	var $allButton = $root.find("#caFixedToggleAll").first();
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
	var $allButton = $root.find("#caFixedToggleAll").first();
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
	post({ action: "statistics" }, function(result) {
		const stats = result.statistics || {};
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
		if (hasPrivate) {
			setText("private", stats.private);
		} else {
			$privateRow.remove();
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
