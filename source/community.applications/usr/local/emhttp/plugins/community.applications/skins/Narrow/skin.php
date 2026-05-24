<?PHP
########################################
#                                      #
# Community Applications               #
# Copyright 2020-2026, Lime Technology #
# Copyright 2015-2026, Andrew Zawadzki #
#                                      #
# Licensed under GPL-2.0-or-later      #
# SPDX-License-Identifier:             #
#   GPL-2.0-or-later                   #
#                                      #
########################################

/**
 * Narrow skin: PHP-rendered fragments and helpers for the Community Applications UI.
 *
 * Popup/action menus, support links, and other server-generated markup used by Apps.page.
 */

require_once __DIR__.'/skin_helpers.php';

/**
 * Remove orphan and duplicate dividers from action arrays.
 *
 * Strips leading dividers, collapses consecutive dividers into one, and trims trailing dividers.
 *
 * @param  array<int,array<string,mixed>> $actionsContext Raw action descriptors with optional `divider` flags.
 * @return array<int,array<string,mixed>> Re-indexed list with stray dividers removed.
 */
function caCompactPopupActionContext(array $actionsContext): array {
	$compacted = [];
	foreach ($actionsContext as $context) {
		if (!empty($context['divider'])) {
			if (empty($compacted) || !empty($compacted[count($compacted) - 1]['divider'])) {
				continue;
			}
		}
		$compacted[] = $context;
	}

	while (!empty($compacted) && !empty($compacted[count($compacted) - 1]['divider'])) {
		array_pop($compacted);
	}

	return array_values($compacted);
}

/**
 * Keep only valid support entries for popup button rendering.
 *
 * Filters out entries where either link or visible text is empty after trimming/stripping tags.
 *
 * @param  array<int,mixed> $supportContext Raw support descriptors.
 * @return array<int,array<string,mixed>> Re-indexed, filtered support entries.
 */
function caNormalizePopupSupportContext(array $supportContext): array {
	return array_values(array_filter($supportContext, static function ($context) {
		if (!is_array($context)) {
			return false;
		}
		$link = trim((string)($context['link'] ?? ""));
		$action = trim((string)($context['action'] ?? ""));
		$text = trim(strip_tags((string)($context['text'] ?? "")));
		/* Entries are valid if they either point somewhere (link) or fire a JS
		   action (e.g. the dev-mode Diff button) — provided they have visible
		   text on the button. */
		return (($link !== "" || $action !== "") && $text !== "");
	}));
}

/**
 * Remove popup shortcut actions from context and expose preferred quick action.
 *
 * Pulls Uninstall and WebUI/Settings entries out of the main actions list so the
 * popup template can render them in dedicated slots. WebUI is preferred over Settings.
 *
 * @param  array<int,array<string,mixed>> $actionsContext Full action descriptors.
 * @return array{0: array<int,array<string,mixed>>, 1: ?array<string,mixed>, 2: ?array<string,mixed>}
 *                Tuple of [remaining actions, popup shortcut, uninstall action].
 */
function caNormalizePopupActions(array $actionsContext): array {
	$popupShortcutContext = [];
	$popupUninstallAction = null;
	$filteredContext = array_values(array_filter($actionsContext, static function ($context) use (&$popupShortcutContext, &$popupUninstallAction) {
		$text = trim(strip_tags((string)($context['text'] ?? "")));
		if (in_array($text, [tr("Uninstall"), "Uninstall"], true)) {
			$popupUninstallAction = $context;
			return false;
		}
		if (in_array($text, [tr("WebUI"), tr("Settings"), "WebUI", "Settings"], true)) {
			$popupShortcutContext[] = $context;
			return false;
		}

		return true;
	}));

	$filteredContext = caCompactPopupActionContext($filteredContext);
	$popupShortcut = null;
	foreach ($popupShortcutContext as $context) {
		$text = trim(strip_tags((string)($context['text'] ?? "")));
		if ($text === tr("WebUI") || $text === "WebUI") {
			$popupShortcut = $context;
			break;
		}
	}
	if (!$popupShortcut && !empty($popupShortcutContext)) {
		$popupShortcut = $popupShortcutContext[0];
	}

	return [$filteredContext, $popupShortcut, $popupUninstallAction];
}

/**
 * Generate the display HTML for the application sidebar popup.
 *
 * Reads statistics JSON from CA_PATHS, evaluates current locale/session state, and
 * renders the popup template using output buffering. Calls validURL() and htmlspecialchars()
 * to harden user-supplied URLs before emitting them.
 *
 * @param  array<string,mixed>|mixed $template Template entry (non-arrays are coerced to []).
 * @return string Rendered HTML markup.
 */
function displayPopup($template) {

	$template = is_array($template) ? $template : [];
	extract($template);

	$Repo = $Repo ?? "";
	$Private = $Private ?? false;
	$RepoName = $RepoName ?? $Repo;
	$RepoShort = $RepoShort ?? "";

	if (! $Private) {
		$RepoName = str_replace("' Repository","",str_replace("'s Repository","",$Repo));
		$RepoName = str_replace("Repository","",$RepoName);
	} else {
		$RepoName = str_replace("' Repository","",str_replace("'s Repository","",$RepoName));
		$Repo = $RepoName;
	}
	if ($RepoShort) {
		$RepoName = $RepoShort;
	}

	$actionsContext = is_array($actionsContext ?? null) ? $actionsContext : [];
	$supportContext = is_array($supportContext ?? null) ? $supportContext : [];
	$supportContext = caNormalizePopupSupportContext($supportContext);
	$popupShortcut = is_array($popupShortcut ?? null) ? $popupShortcut : null;
	$popupUninstallAction = is_array($popupUninstallAction ?? null) ? $popupUninstallAction : null;
	[$actionsContext, $normalizedPopupShortcut, $normalizedPopupUninstallAction] = caNormalizePopupActions($actionsContext);
	if (!$popupShortcut) {
		$popupShortcut = $normalizedPopupShortcut;
	}
	if (!$popupUninstallAction) {
		$popupUninstallAction = $normalizedPopupUninstallAction;
	}
	$NoPin = $NoPin ?? false;
	$Blacklist = $Blacklist ?? false;
	$LanguagePack = $LanguagePack ?? "";
	$Language = $Language ?? false;
	$Plugin = $Plugin ?? false;
	$Installed = $Installed ?? false;
	$UpdateAvailable = $UpdateAvailable ?? false;
	$Beta = $Beta ?? false;
	$SortName = $SortName ?? "";
	$pinnedClass = $pinnedClass ?? "";
	$display_icon = $display_icon ?? "";
	$display_ovr = $display_ovr ?? "";
	$Category = $Category ?? "";
	$Repository = $Repository ?? "";
	$downloads = $downloads ?? 0;
	$stars = $stars ?? "";
	$LastUpdate = $LastUpdate ?? null;
	$installedVersion = $installedVersion ?? null;
	$pluginVersion = $pluginVersion ?? null;
	$Compatible = $Compatible ?? null;
	$UnknownCompatible = $UnknownCompatible ?? null;
	$MinVer = $MinVer ?? "";
	$MaxVer = $MaxVer ?? "";
	$Licence = $Licence ?? ($License ?? "");
	$ProfileIcon = $ProfileIcon ?? "";
	$display_changes = $display_changes ?? null;
	$display_changelogMessage = $display_changelogMessage ?? "";
	$downloadtrend = $downloadtrend ?? false;
	$trends = is_array($trends ?? null) ? $trends : [];
	$Requires = $Requires ?? "";
	$RequiresFile = $RequiresFile ?? "";
	$Deprecated = $Deprecated ?? false;
	$VerMessage = $VerMessage ?? "";
	$Blacklist = $Blacklist ?? false;
	$CAComment = $CAComment ?? "";
	$disclaimLineLink = $disclaimLineLink ?? "";
	$disclaimLine1 = $disclaimLine1 ?? "";
	$Featured = $Featured ?? false;
	$UninstallOnly = $UninstallOnly ?? false;
	$RecommendedReason = is_array($RecommendedReason ?? null) ? $RecommendedReason : [];
	$RecommendedWho = $RecommendedWho ?? "";
	$RecommendedDate = $RecommendedDate ?? time();
	$ModeratorComment = $ModeratorComment ?? "";

	$normalizeList = static function ($value) {
		if (empty($value)) {
			return [];
		}
		return is_array($value) ? $value : [$value];
	};

	$Screenshot = $normalizeList($Screenshot ?? []);
	$Photo = $normalizeList($Photo ?? []);
	$Video = $normalizeList($Video ?? []);

	$FirstSeen = $FirstSeen ?? 0;
	$FirstSeen = ($FirstSeen < 1433649600) ? 1433000000 : $FirstSeen;
	$DateAdded = tr(date("M j, Y", $FirstSeen), 0);
	$favRepoClass = (($GLOBALS['caSettings']['favourite'] ?? null) == $Repo) ? "fav" : "nonfav";

	$requiresFileNotMet = false;
	if (is_string($RequiresFile ?? null) && trim($RequiresFile) !== "" && !is_file($RequiresFile)) {
		$requiresFileNotMet = true;
	}

	if ($Requires && ! is_file($RequiresFile ?? "")) {
		$notMet = $requiresFileNotMet ? " <span class='ca_bold'>- ".tr("Not met")."</span>" : "";
		/* Emit a placeholder with the RAW Requires text JSON-encoded in the
		   data attribute. caRenderSidebarRequires() in Apps.page picks it up
		   right after the sidebar paints and runs it through the same
		   strip→marked→DOMPurify→search-link pipeline as README/Changes —
		   one renderer everywhere, plus the DOMPurify defense-in-depth pass. */
		$rawRequires    = (string)$template['Requires'];
		$requiresJson   = (string)json_encode($rawRequires, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
		$requiresAttr   = htmlspecialchars($requiresJson, ENT_QUOTES);
		$RequiresMessage = "<div class='additionalRequirementsHeader'>".tr("Additional Requirements")."$notMet</div><div class='additionalRequirements ca_requires_pending' data-requires='{$requiresAttr}'>".tr("Loading requirements...")."</div>";
	} else {
		$RequiresMessage = "";
	}

	if ($Deprecated) {
		$ModeratorComment .= "<br>".tr("This application template has been deprecated");
	}
	if (! ($Compatible ?? false) && ! ($UnknownCompatible ?? false)) {
		$ModeratorComment .= $VerMessage ?: "<br>".tr("This application is not compatible with your version of Unraid.");
	}
	if ($Blacklist) {
		$ModeratorComment .= "<br>".tr("This application template has been blacklisted.");
	}
	if ($Language && $LanguagePack !== "en_US") {
		if (validURL($disclaimLineLink)) {
			$safeDisclaim = htmlspecialchars($disclaimLineLink, ENT_QUOTES);
			$ModeratorComment .= "<a href='$safeDisclaim' target='_blank'>$disclaimLine1</a>";
		} else {
			$ModeratorComment .= $disclaimLine1;
		}
	}
	if ((! ($Compatible ?? false) || ($UninstallOnly ?? false)) && ($Featured ?? false)) {
		$ModeratorComment = "<span class='featuredIncompatible'>".sprintf(tr("%s is incompatible with your OS version.  Please update the OS to proceed"), $Name)."</span>";
	}

	/* Two separate sidebar blocks, same styling: ModeratorComment (curated /
	   moderation-derived warnings) goes BEFORE the description; CAComment
	   (auto-generated security/config heads-up like privileged mode, custom
	   network) goes AFTER. No "Note:" header, normal text size. */
	$ModeratorCommentBlock = $ModeratorComment ? "<div class='modComment'>$ModeratorComment</div>" : "";
	$CACommentBlock        = $CAComment        ? "<div class='modComment'>$CAComment</div>"        : "";

	$RecommendedBlock = "";
	if ($RecommendedReason) {
		$RecommendedLanguage = $_SESSION['locale'] ?? "en_US";
		if (empty($RecommendedReason[$RecommendedLanguage])) {
			$RecommendedLanguage = "en_US";
		}

		if (! empty($RecommendedReason[$RecommendedLanguage])) {
			preg_match_all("/\/\/(.*?)\\\\/m", $RecommendedReason[$RecommendedLanguage], $searchMatches);
			if (count($searchMatches[1])) {
				foreach ($searchMatches[1] as $searchResult) {
					/* Same defense as caApplySidebarSearchLinks: htmlspecialchars
					   the visible anchor text, json_encode the JS argument. */
					$safeText = htmlspecialchars($searchResult, ENT_QUOTES, "UTF-8");
					$jsArg    = json_encode($searchResult, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
					$RecommendedReason[$RecommendedLanguage] = str_replace(
						"//$searchResult\\\\",
						"<a style='cursor:pointer;' onclick='doSidebarSearch({$jsArg});'>{$safeText}</a>",
						$RecommendedReason[$RecommendedLanguage]
					);
				}
			}

			if (! $RecommendedWho) {
				$RecommendedWho = tr("Unraid Staff");
			}

			$RecommendedBlock = "
			<div class='spotlightPopup'>
				<div class='spotlightIconArea ca_center'>
					<div><img class='spotlightIcon' src='".CA_PATHS['SpotlightIcon']."' alt='Spotlight'></img></div>
					<div class='spotlightDate spotlightDateSidebar'>".tr(date("M Y", $RecommendedDate), 0)."</div>
				</div>
				<div class='spotlightInfoArea'>
					<div class='spotlightHeader'></div>
					<div class='spotlightWhy'>".tr("Why we picked it")."</div>
					<div class='spotlightMessage'>{$RecommendedReason[$RecommendedLanguage]}</div>
					<div class='spotlightWho'>- $RecommendedWho</div>
				</div>
			</div>
			";
		}
	}

	$mediaBlock = "";
	$pictures = $Screenshot ?: $Photo;
	if ($pictures || $Video) {
		$mediaSections = [];
		if ($pictures) {
			foreach ($pictures as $shot) {
				$shot = trim($shot);
				/* caIsPublicHttpUrl instead of validURL — popup images auto-fetch
				   on render, so the stricter LAN/.local/RFC1918 rejection that
				   guards the popup icon at line 873 has to apply here too.
				   referrerpolicy='no-referrer' only hides the referrer; it
				   doesn't stop the request itself. */
				if ($shot === "" || !caIsPublicHttpUrl($shot)) {
					continue;
				}
				$safeShot = htmlspecialchars($shot, ENT_QUOTES);
				/* span (not <a>) so the legacy external-link click handler doesn't
				   intercept this — magnific uses data-mfp-src as the source. */
				$mediaSections[] = "<span class='screenshot mfp-image' data-mfp-src='$safeShot'><img class='screen' src='$safeShot' referrerpolicy='no-referrer'></img></span>";
			}
		}
		if ($Video) {
			foreach ($Video as $vid) {
				$vid = trim($vid);
				if ($vid === "" || !caIsPublicHttpUrl($vid)) {
					continue;
				}
				$thumbnail = trim((string)getYoutubeThumbnail($vid));
				/* The youtube thumbnail comes from a fixed-format helper, but it's
				   still derived from a feed-supplied URL — gate it with the same
				   public-URL check so an attacker-controlled video URL can't
				   produce a thumbnail src pointing at a LAN host. */
				if ($thumbnail === "" || !caIsPublicHttpUrl($thumbnail)) {
					continue;
				}
				$safeVid = htmlspecialchars($vid, ENT_QUOTES);
				$safeThumb = htmlspecialchars($thumbnail, ENT_QUOTES);
				$mediaSections[] = "<span class='screenshot mfp-iframe videoPlayOverlay' data-mfp-src='$safeVid' style='position: relative; display: inline-block;'><img class='screen' src='$safeThumb' referrerpolicy='no-referrer'></span>";
			}
		}
		if ($mediaSections) {
			$mediaBlock = "<div class='caMediaGallery'>".implode("", $mediaSections)."</div>";
		}
	}

	$appType = $Plugin ? tr("Plugin") : tr("Docker");
	$appType = $Language ? tr("Language") : $appType;

	$detailsRows = [];
	$detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Application Type")."</td><td class='popupTableRight'>$appType</td></tr>";
	$detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Categories")."</td><td class='popupTableRight'>$Category</td></tr>";
	$detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Added")."</td><td class='popupTableRight'>$DateAdded</td></tr>";

	if (! $Plugin) {
		$downloadText = getDownloads($downloads);
		if ($downloadText) {
			$detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Downloads")."</td><td class='popupTableRight'>$downloadText</td></tr>";
		}
	}

	if (! $Plugin && ! $LanguagePack) {
		$detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Repository")."</td><td class='popupTableRight' style='white-space:nowrap;'>$Repository</td></tr>";
	}
	if ($stars) {
		$detailsRows[] = "<tr><td class='popupTableLeft'>".tr("DockerHub Stars:")."</td><td class='popupTableRight'>$stars <span class='dockerHubStar'></span></td></tr>";
	}
	if (! $Plugin && ! $Language) {
		$tagExplode = explode(":", $Repository);
		$tag = $tagExplode[1] ?? "";
		if (! $tag || strtolower($tag) === "latest") {
			$lastUpdateMsg = $LastUpdate ? tr(date("M j, Y", $LastUpdate), 0) : tr("Unknown");
			$detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Last Update:")."</td><td class='popupTableRight'><span id='template{$template['ID']}'>$lastUpdateMsg <span class='ca_note'><span class='ca_fa-asterisk'></span></span></span></td></tr>";
		}
	}
	if ($Plugin && isset($installedVersion)) {
		$detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Installed Version")."</td><td class='popupTableRight'>$installedVersion</td></tr>";
		if ($installedVersion != $pluginVersion) {
			$detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Upgrade Version")."</td><td class='popupTableRight'>$pluginVersion</td></tr>";
		}
	}
	if ($Plugin && ! isset($installedVersion)) {
		$detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Current Version")."</td><td class='popupTableRight'>$pluginVersion</td></tr>";
	}

	if ($Plugin || ! ($Compatible ?? null)) {
		if ($MinVer) {
			$detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Min OS")."</td><td class='popupTableRight'>$MinVer</td></tr>";
		}
		if ($MaxVer) {
			$detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Max OS")."</td><td class='popupTableRight'>$MaxVer</td></tr>";
		}
	}

	if ($Licence) {
		/* Same stricter check as the popup icon + media gallery above — a
		   licence URL pointing at a LAN host would auto-fetch on popup open. */
		if (caIsPublicHttpUrl($Licence)) {
			$safeLicence = htmlspecialchars($Licence, ENT_QUOTES);
			$Licence = "<img class='licence' src='$safeLicence' referrerpolicy='no-referrer' onerror='this.outerHTML=&quot;<a href=$safeLicence target=_blank>".tr("Click Here")."</a>&quot;;this.onerror=null;' ></img>";
		}
		$detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Licence")."</td><td class='popupTableRight'>$Licence</td></tr>";
	}

	$chartBlock = "";
	if (count($trends) > 1 && $downloadtrend) {
		$chartBlock = "
			<div class='popupChartBlock'>
				<div><span class='charts'><span class='chartMenu selectedMenu' data-chart='trendChart'>".tr("Trend Per Month")."</span><span class='chartMenu' data-chart='downloadChart'>".tr("Downloads Per Month")."</span><span class='chartMenu' data-chart='totalDownloadChart'>".tr("Total Downloads")."</span></div>
				<div>
					<div><canvas id='trendChart' class='caChart' height=1 width=3></canvas></div>
					<div><canvas id='downloadChart' class='caChart' style='display:none;' height=1 width=3></canvas></div>
					<div><canvas id='totalDownloadChart' class='caChart' style='display:none;' height=1 width=3></canvas></div>
				</div>
			</div>
		";
	}

	$changeLogBlock = "";
	if (isset($display_changes)) {
		$changeLogBlock = "
			<div class='popupChangeLogBlock'>
				<div class='changelogTitle'>".tr("Change Log")."</div>
				<div class='changelogMessage'>$display_changelogMessage</div>
				<div class='changelog popup_readmore'>$display_changes</div>
			</div>
		";
	}

	$moderationBlock = "";
	$moderation = readJsonFile(CA_PATHS['statistics']);
	$repoKey = str_replace("library/", "", $Repository);
	if (isset($moderation['fixedTemplates'][$Repo][$repoKey])) {
		$errors = array_map(static function ($error) {
			return "<li class='templateErrorsList'>$error</li>";
		}, $moderation['fixedTemplates'][$Repo][$repoKey]);
		if ($errors) {
			$moderationBlock = "<div class='popupModerationBlock'><div class='templateErrors'>".tr("Template Errors")."</div>".implode("", $errors)."</div>";
		}
	}

	$readmeSection = caBuildReadmeSectionDiv($template);
	$readmeButton = "";
	if ($readmeSection === "" && !empty($template['ReadMe']) && validURL($template['ReadMe'])) {
		$safeReadmeUrl = htmlspecialchars($template['ReadMe'], ENT_QUOTES);
		$readmeButton = "<div class='caButton actionsPopup'><a href='{$safeReadmeUrl}' target='_blank' rel='noopener noreferrer'><span class='ca_fa-readme'> ".tr("Read Me First")."</span></a></div>";
	}
	$installFirstAction = null;
	$actionsButtonItems = [];
	if (count($actionsContext) === 1) {
		$singleActionText = trim(strip_tags((string)($actionsContext[0]['text'] ?? "")));
		if ($singleActionText === tr("Install") || $singleActionText === "Install") {
			$installFirstAction = $actionsContext[0];
		}
	}
	foreach ($actionsContext as $context) {
		if (!empty($context['divider'])) {
			continue;
		}
		if ($installFirstAction && (($context['action'] ?? "") === ($installFirstAction['action'] ?? ""))) {
			continue;
		}
		$actionsButtonItems[] = $context;
	}

	/* Partition $actionsButtonItems into three visual groups so the popup
	   action row can right-align primary install actions (blue) and
	   destructive actions (red) while keeping secondary actions (Edit,
	   Pin, etc.) on the left. The CSS uses a margin-left:auto trick on
	   .actionsInstall / .actionsUninstall to push the right group to the
	   far edge, which requires the DOM order to be LEFT first then RIGHT
	   — render the three buckets in sequence below in .popupStickyActions.

	   Matching on `strip_tags`-stripped text because some labels arrive
	   wrapped in <span class='ca_red'> (eg. plugin "Remove", "Reinstall
	   From Previous Apps") that gets stripped later for visual display
	   anyway. Also accepts the untranslated English variants so the
	   detection survives a missing/partial locale file. */
	$installFamilyTexts = [
		tr("Install"), tr("Reinstall"),
		tr("Install second"), tr("Reinstall From Previous Apps"),
		"Install", "Reinstall",
		"Install second", "Reinstall From Previous Apps",
	];
	$updateFamilyTexts = [
		tr("Update"),
		"Update",
	];
	$uninstallFamilyTexts = [
		tr("Uninstall"), tr("Remove"),
		"Uninstall", "Remove",
	];
	$leftActionItems = [];
	$rightInstallActionItems = [];
	$rightUpdateActionItems = [];
	$rightUninstallActionItems = [];
	foreach ($actionsButtonItems as $actionItem) {
		$itemText = trim(strip_tags((string)($actionItem['text'] ?? "")));
		if (in_array($itemText, $installFamilyTexts, true)) {
			$rightInstallActionItems[] = $actionItem;
		} elseif (in_array($itemText, $updateFamilyTexts, true)) {
			$rightUpdateActionItems[] = $actionItem;
		} elseif (in_array($itemText, $uninstallFamilyTexts, true)) {
			$rightUninstallActionItems[] = $actionItem;
		} else {
			$leftActionItems[] = $actionItem;
		}
	}

	ob_start();
	?>
	<div class='popup'>
		<div class='popupContent'>
			<?php /* Per-app docker-disabled notice — sits above the icon/name
			         block instead of as a page-level banner so it travels with
			         the app context. Only shown for non-plugin / non-language
			         apps (the only kinds that need docker to install). */ ?>
			<?php if (! caIsDockerRunning() && (! $Plugin && ! $Language)): ?>
				<div class='popupDockerDisabled'><?= tr("Docker Service Not Enabled - Only Plugins Available To Be Installed Or Managed") ?></div>
			<?php endif; ?>
			<div class='ca_popupIconArea'>
				<div class='popupIcon'><?= $display_icon ?></div>
				<div class='popupInfo'>
					<div class='popupName ellipsis'><?= $Name ?></div>

					<?php if (! $Language): ?>
						<div class='popupAuthorMain'><?= $Author ?></div>
					<?php endif; ?>

					<?php /* Support buttons stay in the popup body and scroll
					         with the rest of the content. Action buttons live
					         in .popupStickyActions below this block and get
					         relocated to the close area by JS. */ ?>
					<?php if (!empty($supportContext)): ?>
						<div class='popupSupportRow'>
							<?php foreach ($supportContext as $sc): ?>
								<?php
								/* Optional caller-supplied extra class (e.g. ca_devMode for
								   dev-mode-only buttons) — appended to the static caButton +
								   supportPopup pair so responsive CSS can target it. */
								$scExtraClass = !empty($sc['class']) ? " ".htmlspecialchars($sc['class'], ENT_QUOTES) : "";
								?>
								<?php if (!empty($sc['action'])): ?>
									<div class='caButton supportPopup<?= $scExtraClass ?>' onclick="<?= htmlspecialchars($sc['action'], ENT_QUOTES) ?>"><span class='<?= $sc['icon'] ?>'> <?= $sc['text'] ?></span></div>
								<?php elseif (validURL($sc['link'] ?? "")): ?>
									<div class='caButton supportPopup<?= $scExtraClass ?>'><a href='<?= htmlspecialchars($sc['link'], ENT_QUOTES) ?>' target='_blank'><span class='<?= $sc['icon'] ?>'> <?= $sc['text'] ?></span></a></div>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php if ($Repo): ?>
						<div class='popupMaintainerLine'><?= tr("Maintainer") ?>: <?= $RepoName ?></div>
						<div class='caButton ca_repoSearchPopUp popupProfile' data-repository='<?= htmlentities($Repo, ENT_QUOTES) ?>'><?= tr("All Apps") ?></div>
						<div class='caButton repoPopup' data-repository='<?= htmlentities($Repo, ENT_QUOTES) ?>'><?= tr("Profile") ?></div>
						<div class='caButton ca_favouriteRepo <?= $favRepoClass ?>' data-repository='<?= htmlentities($Repo, ENT_QUOTES) ?>'><?= tr("Favourite") ?></div>
					<?php endif; ?>
				</div>
			</div>
			<?php /* Action buttons only — gets relocated to .popupCloseAreaButtons
			         by Apps.page's caRelocatePopupActions() at popup-load time.

			         Emission order matters: LEFT items first (WebUI shortcut,
			         Read Me First, other secondary actions, Pin), then RIGHT
			         BLUE (install-family — Install / Reinstall / Install
			         second instance), then RIGHT GREEN (Update), then RIGHT
			         RED (Uninstall / Remove). The CSS's `margin-left:auto`
			         push on .actionsInstall / .actionsUpdate / .actionsUninstall
			         (community.applications.css) relies on this DOM order to
			         right-align the right group as one contiguous block. */ ?>
			<div class='popupStickyActions'>
				<?php /* Always htmlspecialchars(..., ENT_QUOTES) the action onclick — these
				         strings get built with interpolated template data (RequiresFile,
				         pluginName, paths, etc.) and an unescaped emit lets a hostile
				         maintainer break out of the JS string literal and execute
				         arbitrary JS in the user's GUI session. The support context
				         block above (line 492) already escapes; this block needs to
				         match. The browser decodes the entities back to literal quotes
				         when parsing the onclick attribute, so the resulting JS is
				         identical to the un-escaped form for legitimate inputs. */ ?>

				<?php /* Two icon paths in this block:
				         - LEFT GROUP buttons (popupShortcut, leftActionItems)
				           all share .caButton.actionsPopup with no per-action
				           differentiator, so their FontAwesome glyph is
				           attached by threading $context['icon'] (a
				           ca_fa-XXX class set by skin_helpers.php) into the
				           outer class list. htmlspecialchars on every
				           interpolation because the strings come from the
				           template feed.
				         - RIGHT GROUP / pin buttons have unique semantic
				           classes (.actionsInstall / .actionsUpdate /
				           .actionsUninstall / .pinPopup), so their icons
				           are attached purely in CSS via ::before — see
				           community.applications.css. No class threading
				           needed here for those. */ ?>

				<?php /* === LEFT GROUP ============================================ */ ?>
				<?php if (!empty($popupShortcut['action'])): ?>
					<div class='caButton actionsPopup <?= htmlspecialchars($popupShortcut['icon'] ?? '', ENT_QUOTES) ?>'><span onclick="<?= htmlspecialchars($popupShortcut['action'], ENT_QUOTES) ?>"><?= htmlspecialchars(trim(strip_tags((string)($popupShortcut['text'] ?? tr("WebUI")))), ENT_QUOTES) ?></span></div>
				<?php endif; ?>
				<?= $readmeButton ?>

				<?php foreach ($leftActionItems as $actionItem): ?>
					<?php $iconClass = htmlspecialchars($actionItem['icon'] ?? '', ENT_QUOTES); ?>
					<?php if (!empty($actionItem['action'])): ?>
						<div class='caButton actionsPopup <?= $iconClass ?>'><span onclick="<?= htmlspecialchars($actionItem['action'], ENT_QUOTES) ?>"><?= htmlspecialchars(trim(strip_tags((string)($actionItem['text'] ?? ""))), ENT_QUOTES) ?></span></div>
					<?php else: ?>
						<div class='caButton actionsPopup <?= $iconClass ?>'><span><?= htmlspecialchars(trim(strip_tags((string)($actionItem['text'] ?? ""))), ENT_QUOTES) ?></span></div>
					<?php endif; ?>
				<?php endforeach; ?>

				<?php if ($LanguagePack !== "en_US" && ! $Blacklist && ! $NoPin): ?>
					<div class='caButton pinPopup <?= htmlspecialchars((string)$pinnedClass, ENT_QUOTES) ?>' data-repository='<?= htmlspecialchars((string)$Repository, ENT_QUOTES) ?>' data-name='<?= htmlspecialchars((string)$SortName, ENT_QUOTES) ?>'><span><?= tr("Pin") ?></span></div>
				<?php endif; ?>

				<?php /* === RIGHT BLUE GROUP — install-family ===================== */ ?>
				<?php if (!empty($installFirstAction['action'])): ?>
					<div class='caButton actionsPopup actionsInstall'><span onclick="<?= htmlspecialchars($installFirstAction['action'], ENT_QUOTES) ?>"><?= htmlspecialchars(trim(strip_tags((string)$installFirstAction['text'])), ENT_QUOTES) ?></span></div>
				<?php endif; ?>

				<?php foreach ($rightInstallActionItems as $actionItem): ?>
					<?php if (!empty($actionItem['action'])): ?>
						<div class='caButton actionsPopup actionsInstall'><span onclick="<?= htmlspecialchars($actionItem['action'], ENT_QUOTES) ?>"><?= htmlspecialchars(trim(strip_tags((string)($actionItem['text'] ?? ""))), ENT_QUOTES) ?></span></div>
					<?php else: ?>
						<div class='caButton actionsPopup actionsInstall'><span><?= htmlspecialchars(trim(strip_tags((string)($actionItem['text'] ?? ""))), ENT_QUOTES) ?></span></div>
					<?php endif; ?>
				<?php endforeach; ?>

				<?php /* === RIGHT GREEN GROUP — Update ============================ */ ?>
				<?php foreach ($rightUpdateActionItems as $actionItem): ?>
					<?php if (!empty($actionItem['action'])): ?>
						<div class='caButton actionsPopup actionsUpdate'><span onclick="<?= htmlspecialchars($actionItem['action'], ENT_QUOTES) ?>"><?= htmlspecialchars(trim(strip_tags((string)($actionItem['text'] ?? ""))), ENT_QUOTES) ?></span></div>
					<?php else: ?>
						<div class='caButton actionsPopup actionsUpdate'><span><?= htmlspecialchars(trim(strip_tags((string)($actionItem['text'] ?? ""))), ENT_QUOTES) ?></span></div>
					<?php endif; ?>
				<?php endforeach; ?>

				<?php /* === RIGHT RED GROUP — destructive ========================= */ ?>
				<?php foreach ($rightUninstallActionItems as $actionItem): ?>
					<?php if (!empty($actionItem['action'])): ?>
						<div class='caButton actionsPopup actionsUninstall'><span onclick="<?= htmlspecialchars($actionItem['action'], ENT_QUOTES) ?>"><?= htmlspecialchars(trim(strip_tags((string)($actionItem['text'] ?? ""))), ENT_QUOTES) ?></span></div>
					<?php else: ?>
						<div class='caButton actionsPopup actionsUninstall'><span><?= htmlspecialchars(trim(strip_tags((string)($actionItem['text'] ?? ""))), ENT_QUOTES) ?></span></div>
					<?php endif; ?>
				<?php endforeach; ?>

				<?php if (!empty($popupUninstallAction['action'])): ?>
					<div class='caButton actionsPopup actionsUninstall'><span onclick="<?= htmlspecialchars($popupUninstallAction['action'], ENT_QUOTES) ?>"><?= htmlspecialchars(trim(strip_tags((string)($popupUninstallAction['text'] ?? tr("Uninstall")))), ENT_QUOTES) ?></span></div>
				<?php endif; ?>
			</div>

			<?= $ModeratorCommentBlock ?>
			<div class='popupDescription popup_readmore'><?= $display_ovr ?></div>
			<?= $CACommentBlock ?>
			<?= $RequiresMessage ?>
			<?= $readmeSection ?>
			<?= $RecommendedBlock ?>
			<?= $mediaBlock ?>
			<?= $chartBlock ?>
			<div>
				<div class='popupInfoSection'>
					<div class='popupInfoLeft'>
						<div class='rightTitle'><?= tr("Details") ?></div>
						<table class='popupTable contents'>
							<?= implode("", $detailsRows) ?>
						</table>
					</div>
				</div>
			</div>
			<?= $changeLogBlock ?>
			<?= $moderationBlock ?>
		</div>
	</div>
	</div>
	<?php

	return ob_get_clean();
}

/**
 * Render the main apps grid: loads the active displayed-templates cache and delegates to my_display_apps.
 *
 * @param int $pageNumber
 * @param mixed $selectedApps
 * @param bool $startup
 * @param bool $returnArray When true, return HTML string only (no echo path)
 * @return array|string|void
 */
function display_apps($pageNumber=1,$selectedApps=false,$startup=false,$returnArray=false) {

	$filesToCheck = [
		CA_PATHS['repositoriesDisplayed'] ?? null,
		CA_PATHS['community-templates-catSearchResults'] ?? null,
		CA_PATHS['community-templates-displayed'] ?? null
	];

	$file = [];
	foreach ($filesToCheck as $path) {
		if ($path && is_file($path)) {
			$file = readJsonFile($path);
			break;
		}
	}

	$communityApplications = [];
	if (!empty($file['community']) && is_array($file['community'])) {
		$communityApplications = $file['community'];
	}
	$totalApplications = count($communityApplications);

	if ($totalApplications) {
		return my_display_apps($communityApplications,$pageNumber,$selectedApps,$startup,$returnArray);
	}

	$emptyHtml = "<div class='ca_NoAppsFound'>".tr("No Matching Applications Found")."</div><script>$('.multi_installDiv').hide();hideSortIcons();</script>";

	if ($returnArray) {
		return [
			'header' => $emptyHtml,
			'cards' => [],
			'scripts' => '',
			'totalApps' => 0,
			'pageNumber' => (int)$pageNumber,
		];
	}
	return $emptyHtml;
}

/**
 * Core card renderer: paginates $file templates, builds card HTML, navigation, and scripts.
 *
 * @param array<int,array<string,mixed>> $file Displayed templates list
 * @param int $pageNumber
 * @param mixed $selectedApps
 * @param bool $startup
 * @param bool $returnArray
 * @return array|string|void
 */
function my_display_apps($file,$pageNumber=1,$selectedApps=false,$startup=false,$returnArray=false,$emitNavScript=true) {

	$repositories = readJsonFile(CA_PATHS['repositoryList']);
	$extraBlacklist = readJsonFile(CA_PATHS['extraBlacklist']);
	$extraDeprecated = readJsonFile(CA_PATHS['extraDeprecated']);
	$pinnedApps = readJsonFile(CA_PATHS['pinnedV2']);

	$ct = "";
	$cardsArray = [];
	$count = 0;

	$dockerContext = caDockerContext();
	$displayHeader = "";

	[$selectedApps, $checkedOffApps] = caNormalizeSelectedApps($selectedApps);
	$displayedTemplates = caSliceDisplayedTemplates($file, $pageNumber);

	foreach ($displayedTemplates as $template) {
		$template = addMissingVars($template);
		$template = caApplyModerationOverrides($template, $extraBlacklist, $extraDeprecated);

		if ($template['RepositoryTemplate']) {
			$template['Icon'] = $template['icon'] ?? "/plugins/dynamix.docker.manager/images/question.png";

			if (! isset($template['bio'])) {
				$template['CardDescription'] = tr("No description present");
			} else {
				$template['bio'] = strip_tags(markdown($template['bio']));
				$template['Description'] = $template['bio'];
			}
			$template['display_dockerName'] = $template['RepoName'];
			$template['ca_fav'] = $GLOBALS['caSettings']['favourite'] && ($GLOBALS['caSettings']['favourite'] == $template['RepoName']);

			$cardHtml = displayCard($template);
			$cardsArray[] = $cardHtml;
			$ct .= $cardHtml;
			$count++;
			if ($count == $GLOBALS['caSettings']['maxPerPage']) {
				break;
			}
			continue;
		}

		$actionsContext = [];
		$canInstall = ! $template['NoInstall'] && ! ($GLOBALS['caSettings']['NoInstalls'] ?? false);

		if (! $template['Language']) {
			if (! $template['Plugin']) {
				if ($canInstall) {
					[$template, $actionsContext] = caProcessDockerTemplate($template, $dockerContext['info'], $dockerContext['dockerUpdateStatus']);
				}
			} else {
				if ($canInstall) {
					[$template, $actionsContext] = caProcessPluginTemplate($template);
				} else {
					$template['Installed'] = checkInstalledPlugin($template);
				}
			}
		}

		if ($template['Language']) {
			[$template, $actionsContext] = caProcessLanguageTemplate($template, $actionsContext);
		}

		$template['actionsContext'] = $actionsContext;

		$template['ca_fav'] = $GLOBALS['caSettings']['favourite'] && ($GLOBALS['caSettings']['favourite'] == $template['RepoName']);
		if (strpos($template['Repository'], "/") === false) {
			$template['Pinned'] = $pinnedApps["library/{$template['Repository']}&{$template['SortName']}"] ?? false;
		} else {
			$template['Pinned'] = $pinnedApps["{$template['Repository']}&{$template['SortName']}"] ?? false;
		}

		if (isset($template['Repo'])) {
			$template['Twitter'] = $template['Twitter'] ?? ($repositories[$template['Repo']]['Twitter'] ?? null);
			$template['Reddit'] = $template['Reddit'] ?? ($repositories[$template['Repo']]['Reddit'] ?? null);
			$template['Facebook'] = $template['Facebook'] ?? ($repositories[$template['Repo']]['Facebook'] ?? null);
			$template['Discord'] = $template['Discord'] ?? ($repositories[$template['RepoName']]['Discord'] ?? null);
		} else {
			$template['Twitter'] = $template['Twitter'] ?? null;
			$template['Reddit'] = $template['Reddit'] ?? null;
			$template['Facebook'] = $template['Facebook'] ?? null;
			$template['Discord'] = $template['Discord'] ?? null;
		}

		$previousAppName = $template['Plugin'] ? $template['PluginURL'] : $template['Name'];
		if (isset($checkedOffApps[$previousAppName])) {
			$template['checked'] = $checkedOffApps[$previousAppName] ? "checked" : "";
		}

		if (! $template['Plugin']) {
			$tmpRepo = $template['Repository'];
			if (! strpos($tmpRepo, "/")) {
				$tmpRepo = "library/$tmpRepo";
			}
			foreach ($dockerContext['info'] as $testDocker) {
				if (($tmpRepo == $testDocker['Image'] || "$tmpRepo:latest" == $testDocker['Image']) && ($template['Name'] == $testDocker['Name'])) {
					$template['Installed'] = true;
					break;
				}
			}
		} else {
			$pluginName = basename($template['PluginURL']);
			$template['Installed'] = checkInstalledPlugin($template);
		}

		if ($template['Language']) {
			$template['Installed'] = is_dir(CA_PATHS['languageInstalled']."{$template['LanguagePack']}") && ! $template['Uninstall'];
		}

		if (startsWith($template['Repository'], ["library/", "registry.hub.docker.com/library/"]) || strpos($template['Repository'], "/") === false) {
			$template['Official'] = true;
		}

		$cardHtml = displayCard($template);
		$cardsArray[] = $cardHtml;
		$ct .= $cardHtml;
		$count++;
		if ($count == $GLOBALS['caSettings']['maxPerPage']) {
			break;
		}
	}

	/* Suppressed on the home page — handleHomeStartupDisplay() builds 5-6
	   sections, each of which would emit its own caRenderPageNavigation()
	   that stomps on data.nextpage / data.totalApps. The last section's
	   value would then mislead the infinite-scroll trigger and the display
	   count. Home has no pagination concept anyway. */
	$navScript = $emitNavScript ? getPageNavigation($pageNumber, count($file), false, true) : "";
	$ct .= $navScript;

	if (! $count) {
		$displayHeader .= "<div class='ca_NoAppsFound'>".tr("No Matching Applications Found")."</div><script>hideSortIcons();</script>";
	}

	if ($count == 1 && ! isset($template['homeScreen']) && $pageNumber == 1) {
		if ($template['RepositoryTemplate']) {
			$displayHeader .= "<script>showRepoPopup('".htmlentities($template['RepoName'], ENT_QUOTES)."');</script>";
		} else {
			if ($template['InstallPath']) {
				$template['Path'] = $template['InstallPath'];
			}
			$displayHeader .= "<script>showSidebarApp('{$template['Path']}','{$template['Name']}');</script>";
		}
	}

	if ($returnArray) {
		return [
			'header' => $displayHeader,
			'cards' => $cardsArray,
			'scripts' => $navScript,
			'totalApps' => count($file),
			'pageNumber' => (int)$pageNumber,
		];
	}

	return "$displayHeader$ct";
}

/**
 * Build the popup payload (description + trend data) for a given application.
 *
 * Reads CA_PATHS JSON caches, mutates `$GLOBALS['caSettings']['NoInstalls']` when the
 * install warning hasn't been accepted, may unlink CA_PATHS['pluginTempDownload'] on disk,
 * and relies on the global `$DockerClient` and `$language`. Falls back to the default `?`
 * icon for non-http(s) Icon URLs.
 *
 * @param  mixed $appNumber Identifier of the application to describe (Path/InstallPath).
 * @return array{description:string,trendData?:mixed,trendLabel?:string,downloadtrend?:mixed,downloadLabel?:string,totaldown?:mixed,totaldownLabel?:string,supportContext?:array<int,mixed>,actionsContext?:array<int,mixed>,ID?:mixed}
 */
function getPopupDescriptionSkin($appNumber) {
	global $language, $DockerClient;

	clearstatcache();
	if (empty($GLOBALS['templates']) || !is_file(CA_PATHS['community-templates-info'])) {
		return [
			"description" => ""
		];
	}

	$allRepositories = readJsonFile(CA_PATHS['repositoryList'], []);
	$allRepositories = is_array($allRepositories) ? $allRepositories : [];
	$extraBlacklist = readJsonFile(CA_PATHS['extraBlacklist'], []);
	$extraBlacklist = is_array($extraBlacklist) ? $extraBlacklist : [];
	$extraDeprecated = readJsonFile(CA_PATHS['extraDeprecated'], []);
	$extraDeprecated = is_array($extraDeprecated) ? $extraDeprecated : [];
	$pinnedApps = readJsonFile(CA_PATHS['pinnedV2'], []);
	$pinnedApps = is_array($pinnedApps) ? $pinnedApps : [];

	$templateDescription = "";

	[$info, $dockerRunning, $dockerUpdateStatus] = caInitializeDockerState($DockerClient);

	if (!is_file(CA_PATHS['warningAccepted'])) {
		$GLOBALS['caSettings']['NoInstalls'] = true;
	}

	$displayedPath = is_file(CA_PATHS['community-templates-allSearchResults'])
		? CA_PATHS['community-templates-allSearchResults']
		: CA_PATHS['community-templates-displayed'];
	$displayed = readJsonFile($displayedPath, []);
	$displayed = is_array($displayed) ? $displayed : [];

	[$template, $index] = caLocateTemplate($displayed, $appNumber);

	if (!$template) {
		$file = &$GLOBALS['templates'];
		$index = searchArray($file,"Path",$appNumber);
		if ($index === false) {
			return [
				"description" => ""
			];
		}
		$template = $file[$index];
	}

	$template = addMissingVars($template);

	if (!$template['Blacklist'] && isset($extraBlacklist[$template['Repository']])) {
		$template['Blacklist'] = true;
		$template['ModeratorComment'] = $extraBlacklist[$template['Repository']];
	}

	if (!$template['Deprecated'] && isset($extraDeprecated[$template['Repository']])) {
		$template['Deprecated'] = true;
		$template['ModeratorComment'] = $extraDeprecated[$template['Repository']];
	}

	$template['Profile'] = $allRepositories[$template['RepoName']]['profile'] ?? "";
	$template['ProfileIcon'] = $allRepositories[$template['RepoName']]['icon'] ?? "";

	$countryCode = caPrepareLanguagePack($template, $language);

	[$selected, $name, $pluginName] = caResolveSelectionState($template, $dockerRunning);

	$template['display_ovr'] = caFormatOverview($template);

	caFormatTemplateChanges($template);

	$template['Icon'] = $template['Icon'] ?: "/plugins/dynamix.docker.manager/images/question.png";
	if ($template['IconFA']) {
		$template['IconFA'] = $template['IconFA'] ?: $template['Icon'];
		$templateIcon = startsWith($template['IconFA'],"icon-") ? "{$template['IconFA']} unraidIcon" : "fa fa-{$template['IconFA']}";
		$template['display_icon'] = "<i class='$templateIcon popupIcon'></i>";
	} else {
		$template['Icon'] = $template["Icon-{$GLOBALS['caSettings']['dynamixTheme']}"] ?? $template['Icon'];
		/* Stricter than validURL: caIsPublicHttpUrl additionally rejects
		   RFC1918 / link-local / CGNAT / IPv6 ULA / .local (mDNS) hosts. The
		   icon URL is fetched automatically by the browser when the popup
		   renders — no click required — so a malicious template specifying
		   `Icon=http://192.168.1.1/admin/reboot` could otherwise CSRF a LAN
		   device. Anything that fails falls back to the local "?" image. */
		$iconCandidate = (string)$template['Icon'];
		$safeIcon = caIsPublicHttpUrl($iconCandidate) ? $iconCandidate : "/plugins/dynamix.docker.manager/images/question.png";
		$safeIconAttr = htmlspecialchars($safeIcon, ENT_QUOTES);
		$template['display_icon'] = "<img class='popupIcon screenshot' href='{$safeIconAttr}' src='{$safeIconAttr}' alt='Application Icon' referrerpolicy='no-referrer'>";
	}

	$template['ModeratorComment'] = caApplySidebarSearchLinks($template['ModeratorComment']);
	$template['CAComment'] = caApplySidebarSearchLinks($template['CAComment']);
	/* Requires is no longer server-rendered — emitted raw + JSON-encoded into
	   the popup HTML (above) and processed client-side by caRenderSidebarRequires. */

	$actionsContext = caBuildActionsContext($template, $info, $dockerUpdateStatus, $selected, $name ?? null, $pluginName ?? null);

	if ($template['Language']) {
		$actionsContext = caBuildLanguageActions($template, $countryCode, $actionsContext);
	}
	[$actionsContext, $popupShortcut, $popupUninstallAction] = caNormalizePopupActions($actionsContext);
	$template['popupShortcut'] = $popupShortcut;
	$template['popupUninstallAction'] = $popupUninstallAction;

	$supportContext = caBuildSupportContext($template, $allRepositories);

	$trendContext = caPrepareTrendVisuals($template, $templateDescription);
	$chartLabel = $trendContext['chartLabel'] ?? "";
	$downloadLabel = $trendContext['downloadLabel'] ?? "";
	$down = $trendContext['down'] ?? [];
	$totalDown = $trendContext['totalDown'] ?? [];

	caResolvePinnedState($template, $pinnedApps);

	$template['actionsContext'] = $actionsContext;
	$template['supportContext'] = $supportContext;

	@unlink(CA_PATHS['pluginTempDownload']);

	return [
		"description"=>displayPopup($template),
		"trendData"=>$template['trends'],
		"trendLabel"=>$chartLabel ?: "",
		"downloadtrend"=>$down ?: "",
		"downloadLabel"=>$downloadLabel ?: "",
		"totaldown"=>$totalDown ?: "",
		"totaldownLabel"=>$downloadLabel ?: "",
		"supportContext"=>$supportContext,
		"actionsContext"=>$actionsContext,
		"ID"=>$template['ID'] ?? false
	];
}

/**
 * Build the popup payload (description) for a repository profile view.
 *
 * Reads CA_PATHS['repositoryList'] from disk and assembles donate/media/links/stats sections.
 *
 * @param  string $repository Repository name (key in repositoryList).
 * @return array{description:string} Popup payload.
 */
function getRepoDescriptionSkin($repository) {

	$repositories = readJsonFile(CA_PATHS['repositoryList']);
	$templates = &$GLOBALS['templates'];

	$repo = $repositories[$repository] ?? [];
	$iconUrl = $repo['icon'] ?? null;
	/* caIsPublicHttpUrl, not validURL — repo icon auto-fetches on popup open
	   so the same LAN-host rejection that guards the popup / card icons has
	   to apply here too. */
	$safeIconUrl = ($iconUrl && caIsPublicHttpUrl($iconUrl)) ? htmlspecialchars($iconUrl, ENT_QUOTES) : "";
	$iconPrefix = $safeIconUrl ? "<span class='screenshot mfp-image' data-mfp-src='{$safeIconUrl}'>" : "";
	$iconPostfix = $safeIconUrl ? "</span>" : "";
	$repoIcon = $safeIconUrl ?: "/plugins/dynamix.docker.manager/images/question.png";
	$repoBio = isset($repo['bio']) ? markdown($repo['bio']) : "<br><center>".tr("No description present");
	$favRepoClass = ($GLOBALS['caSettings']['favourite'] == $repository) ? "fav" : "nonfav";
	$encodedRepository = htmlentities($repository, ENT_QUOTES);

	$totals = caSummarizeRepositoryTemplates($templates, $repository);

	$donationSection = caBuildRepoDonationSection($repo);
	$mediaSection = caBuildRepoMediaSection($repo);
	$linksSection = caBuildRepoLinkSection($repo);
	$statsSection = caBuildRepoStatsSection($repo, $totals);

	$seeAllAppsLabel = tr("See All Apps");
	$favouriteLabel = tr("Favourite");
	$repoBio = strip_tags($repoBio);

	$repoUrlButton = "";
	if (($GLOBALS['caSettings']['dev'] ?? null) === "yes" && !empty($repo['url']) && validURL($repo['url'])) {
		$safeRepoUrl = htmlspecialchars($repo['url'], ENT_QUOTES);
		$repoUrlButton = "<a class='caButton ca_repoUrl' href='{$safeRepoUrl}' target='_blank' rel='noopener noreferrer'>".tr("Repository")."</a>";
	}

	$popupContent = "
		<div class='popupContent'>
			<div class='ca_popupIconArea'>
				<div class='popupIcon'>
					$iconPrefix<img class='popupIcon' src='{$repoIcon}' referrerpolicy='no-referrer'>$iconPostfix
				</div>
				<div class='popupInfo'>
					<div class='popupName ellipsis'>$repository</div>
					<div class='caButton ca_repoSearchPopUp popupProfile' data-repository='{$encodedRepository}'>$seeAllAppsLabel</div>
					<div class='caButton ca_favouriteRepo $favRepoClass' data-repository='{$encodedRepository}'>$favouriteLabel</div>
					{$repoUrlButton}
				</div>
			</div>
			<div class='popupRepoDescription'>$repoBio</div>
			$donationSection
			$mediaSection
		</div>
		<div class='repoLinks'>
			$linksSection
			$statsSection
		</div>
	";

	$popup = "<div class='popup'>$popupContent</div>";

	return ["description"=>$popup];
}

/**
 * Render the Docker Hub search results page (cards + pagination).
 *
 * Reads CA_PATHS['dockerSearchResults'] from disk and mutates
 * `$GLOBALS['caSettings']['NoInstalls']` based on the warning-accepted flag.
 *
 * @param  int  $pageNumber  1-based page index.
 * @param  bool $returnArray When true, return a structured array instead of HTML.
 * @return string|array{header:string,cards:array<int,string>,scripts:string,totalApps:int,pageNumber:int}
 */
function displaySearchResults($pageNumber, $returnArray=false) {

	$searchData = readJsonFile(CA_PATHS['dockerSearchResults']);
	$numPages = $searchData['num_pages'] ?? 0;
	$results = $searchData['results'] ?? [];
	$templates = &$GLOBALS['templates'];
	$GLOBALS['caSettings']['NoInstalls'] = !is_file(CA_PATHS['warningAccepted']);

	$cards = array_map(
		function ($result) use ($templates) {
			$preparedResult = buildDockerHubResult($result, $templates, $GLOBALS['caSettings']['NoInstalls']);
			return displayCard($preparedResult);
		},
		$results
	);

	$navScript = dockerNavigate($numPages, $pageNumber);

	if ($returnArray) {
		return [
			'header' => '',
			'cards' => array_values($cards),
			'scripts' => $navScript,
			'totalApps' => (int)($numPages * 25),
			'pageNumber' => (int)$pageNumber,
		];
	}

	$cardsHtml = implode("", $cards);
	return "<div class='ca_templatesDisplay'>{$cardsHtml}</div>".$navScript;
}

/**
 * Render a single application/repository/Docker Hub card.
 *
 * Branches on `$template['RepositoryTemplate']`, `$template['DockerHub']`, and
 * `$template['Language']` to pick the right CSS classes, action buttons, icon source,
 * and overlay flag. Tabs and newlines are stripped from the final markup.
 *
 * @param  array<string,mixed>|mixed $template Template entry (non-arrays return "").
 * @return string Rendered card HTML, or empty string for invalid input.
 */
function displayCard($template) {

	if (!is_array($template)) {
		return "";
	}

	if (!empty($template['RepositoryTemplate'])) {
		$template['DockerHub'] = false;
	}

	if (!empty($template['DockerHub'])) {
		$popupType = null;
	} else {
		$popupType = !empty($template['RepositoryTemplate']) ? "ca_repoPopup" : "ca_appPopup";

		if (empty($template['RepositoryTemplate']) && !empty($template['Language'])) {
			$template['Category'] = "";
		}
	}

	/* Repository cards aren't app templates — only stamp ca_appTemplate on
	   non-repo (and non-docker, handled separately) cards. */
	$class = !empty($template['RepositoryTemplate']) ? "" : "ca_appTemplate";
	$repoName = $template['RepoName'] ?? "";
	[$appType, $typeTitle] = caResolveAppType($template);

	if (!empty($template['InstallPath'])) {
		$template['Path'] = $template['InstallPath'];
	}

	$template['Category'] = caNormalizeCategory($template['Category'] ?? "");
	$author = caResolveAuthor($template, $repoName);

	$id = $template['ID'] ?? "";
	$holderClass = "";
	$cardClass = "ca_appPopup";
	$actionsContext = $template['actionsContext'] ?? [];
	$name = $template['Name'] ?? "";

	if (!empty($template['RepositoryTemplate'])) {
		$repositoryContext = caBuildRepositoryContext($template, $repoName, $author);
		$holderClass = $repositoryContext['holderClass'];
		$cardClass = $repositoryContext['cardClass'];
		$id = $repositoryContext['id'];
		$actionsContext = $repositoryContext['actionsContext'];
		$name = $repositoryContext['name'];
		$author = $repositoryContext['author'];
		$template = array_merge($template, $repositoryContext['overrides']);
	}
	/* Cards no longer render any support buttons — the sidebar owns that.
	   Both branches above used to set $supportContext but nothing here reads
	   it, so the build call and field were pure waste. */

	[$cardStart, $card, $backgroundClickable] = caBuildBottomLineSection($template, $cardClass, $popupType, $holderClass, $class, $name, $repoName);

	if (!empty($template['DockerHub'])) {
		$card .= caRenderActionsButtons($actionsContext, $template['PluginURL'] ?? "", $template['LanguagePack'] ?? "", $name, (string) $id);
	}
	$card .= "<span class='{$appType}' title='".htmlentities($typeTitle)."'></span>";
	/* Favourite + pinned glyphs no longer render as corner overlays — they
	   live inline ahead of the author/name lines (see caBuildApplicationHeader). */

	$type = caResolveCheckboxType($appType);
	$previousAppName = !empty($template['Plugin']) ? ($template['PluginURL'] ?? "") : $name;
	$card .= caRenderCheckbox($template, $previousAppName, $name, $type);

	$card .= "</div>";
	$card .= "<div class='{$cardClass} {$backgroundClickable}'>";
	$card .= "<div class='ca_iconArea'>";
	$card .= caBuildIconMarkup($template, !empty($template['DockerHub']));
	$card .= "</div>";
	$card .= caBuildApplicationHeader($template, $name, $author, $template['Category']);
	$card .= "</div>";

	$overview = caNormalizeOverview($template, $name);
	$descClass = !empty($template['RepositoryTemplate']) ? "cardDescriptionRepo" : "cardDescription";
	$card .= "<div class='{$descClass} {$backgroundClickable}'><div class='cardDesc'>{$overview}</div></div>";

	if (!empty($template['RecommendedDate'])) {
		$card .= "
			<div class='homespotlightIconArea ca_center''>
				<div><img class='spotlightIcon' src='".CA_PATHS['SpotlightIcon']."' alt='Spotlight'></img></div>
				<div class='spotlightDate'>".tr(date("M Y", $template['RecommendedDate']), 0)."</div>
			</div>
		";
	}

	if (!empty($template['Installed']) || !empty($template['Uninstall'])) {
		$flagTextStart = tr("Installed")." · ";
		$flagTextEnd = "";
	} else {
		$flagTextStart = "";
		$flagTextEnd = "";
	}

	$cardFlag = caBuildCardFlag($template, $flagTextStart, $flagTextEnd);

	$cardEnd = "</div>";
	/* The corner-ribbon flag is rendered inside .ca_holder so it can position
	   absolutely against the card itself rather than relying on negative-margin
	   sibling tricks against an outer wrapper. */
	$cardFinish = "{$cardStart}{$cardFlag}{$card}{$cardEnd}";

	return str_replace(["\t", "\n"], "", $cardFinish);
}
?>
