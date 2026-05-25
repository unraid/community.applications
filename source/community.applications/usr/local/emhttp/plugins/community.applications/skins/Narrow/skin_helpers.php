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
 * Narrow skin helper functions (markup, formatting, and UI tokens).
 *
 * Sidebar search link injection, templates/snippets for cards and dialogs, and
 * related rendering utilities used by skin.php and the Apps frontend.
 */

/**
 * Convert CA markup tokens (`//term\`) into clickable sidebar search links.
 *
 * The visible anchor text is htmlspecialchars-escaped and the JS argument is
 * json_encoded so feed-supplied terms cannot break out of their HTML/JS contexts.
 *
 * @param  mixed $text Input string (non-strings are returned unchanged).
 * @return mixed The transformed string, or the original value if not a string.
 */
function caApplySidebarSearchLinks($text) {
	if (!is_string($text) || trim($text) === "") {
		return $text;
	}

	preg_match_all("/\/\/(.*?)&#92;/m", $text, $searchMatches);
	if (!count($searchMatches[1])) {
		return $text;
	}

	foreach ($searchMatches[1] as $searchResult) {
		/* The display text and the JS argument are both user-derived (token
		   came from feed-supplied description / overview content). Escape
		   each for its target context: htmlspecialchars for the visible
		   anchor text, json_encode for the onclick argument so a quote in
		   the term can't break out of the JS string literal. */
		$safeText = htmlspecialchars($searchResult, ENT_QUOTES, "UTF-8");
		$jsArg    = json_encode($searchResult, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
		$text = str_replace(
			"//$searchResult&#92;",
			"<a style='cursor:pointer;' onclick='doSidebarSearch({$jsArg});'>{$safeText}</a>",
			$text
		);
	}

	return $text;
}

/**
 * Build sanitized overview HTML for a template card (markdown, limited tags).
 *
 * @param array<string,mixed> $template
 * @return string
 */
function caFormatOverview(array $template) {
	$overview = $template['Overview'] ?? "";
	if ($overview) {
		$ovr = $template['OriginalOverview'] ?: $overview;
	} else {
		$ovr = $template['OriginalDescription'] ?: ($template['Description'] ?? "");
	}

	$ovr = html_entity_decode($ovr);
	$ovr = str_replace(["[","]"],["<",">"],$ovr);
	$ovr = str_replace("\n","<br>",$ovr);
	$ovr = str_replace("    ","&nbsp;&nbsp;&nbsp;&nbsp;",$ovr);
	$ovr = trim($ovr);

	$ovr = markdown(strip_tags($ovr,"<br>"));
	return strip_tags($ovr,"<br>");
}

/**
 * Populate lazy-load markers and metadata for template/plugin changelogs (mutates $template).
 *
 * @param array<string,mixed> $template
 * @return void
 */
function caFormatTemplateChanges(array &$template) {
	/* Cache-buster appended to the lazy-fetch URL — same `?v={mtime}` trick we
	   use for README (caBuildReadmeSectionDiv). Stable between appfeed refreshes
	   so the browser HTTP cache wins on repeat opens; changes whenever
	   templates.json is rewritten so stale change logs evict naturally.
	   Uses templates.json rather than templates_full.json — full can still be
	   downloading in the background when this renders, mtime would be missing
	   and the cache-buster would be empty. */
	static $changesCacheBust = null;
	if ($changesCacheBust === null) {
		$mtime = @filemtime(CA_PATHS['community-templates-info']);
		$changesCacheBust = $mtime ? ("?v=" . (int)$mtime) : "";
	}

	$safeRepoName = htmlspecialchars((string)($template['RepoName'] ?? ""), ENT_QUOTES);
	/* Loading placeholder rendered inside the lazy-fetch div. JS replaces the
	   whole inner DOM via .html(content) / .text("Change log can't be loaded")
	   once the postNoSpin call completes, so we don't need to hide or remove
	   it explicitly — it self-evicts on first paint. */
	$loadingChanges =
		"<div class='ca_center caLogoIcon'></div>".
		"<div class='ca_center ca_italic'>".tr("Loading change log...")."</div>";

	// For plugins, always lazy-fetch from the .plg.
	if (!empty($template['Plugin'])) {
		$templateURL = (string)($template['PluginURL'] ?? "");
		if (!$templateURL) return;

		$cacheKey = hash("sha256", $templateURL);
		$changesId = "ca_changes_" . $cacheKey;
		$safeUrl = htmlspecialchars($templateURL . $changesCacheBust, ENT_QUOTES);
		$template['display_changes'] = "<div id='{$changesId}' class='ca_template_changes {$changesId}' data-changes-id='{$changesId}' data-changes-url='{$safeUrl}' data-changes-repo='{$safeRepoName}' data-changes-loaded='0'>{$loadingChanges}</div>";
		return;
	}

	// For containers, lazy-fetch changes from the template XML after sidebar render.
	$templateURL = "";
	if ($template['ChangeLogPresent']) {
		$templateURL = (string)($template['caTemplateURL'] ?: ($template['TemplateURL']??""));
	}

	if (!$templateURL) return;

	$cacheKey = hash("sha256", $templateURL);
	$changesId = "ca_changes_" . $cacheKey;
	$safeUrl = htmlspecialchars($templateURL . $changesCacheBust, ENT_QUOTES);
	$template['display_changes'] = "<div id='{$changesId}' class='ca_template_changes {$changesId}' data-changes-id='{$changesId}' data-changes-url='{$safeUrl}' data-changes-repo='{$safeRepoName}' data-changes-loaded='0'>{$loadingChanges}</div>";
}

/**
 * Collect docker state used by popups (running containers and update metadata).
 *
 * Reads docker info via globals/helpers and the dockerUpdateStatus JSON file from disk.
 *
 * @param  object $DockerClient Docker client instance providing getDockerContainers().
 * @return array{0: array<string,array<string,mixed>>, 1: array<int,array<string,mixed>>, 2: array<string,mixed>}
 *                Tuple of [info indexed by container name, running containers, update status].
 */
function caInitializeDockerState($DockerClient) {
	$info = [];

	if (caIsDockerRunning()) {
		$infoTmp = getAllInfo();
		foreach ($infoTmp as $container) {
			$info[$container['Name']] = $container;
		}
		$dockerRunning = $DockerClient->getDockerContainers();
		$dockerUpdateStatus = readJsonFile(CA_PATHS['dockerUpdateStatus'], []);
		$dockerUpdateStatus = is_array($dockerUpdateStatus) ? $dockerUpdateStatus : [];
	} else {
		$dockerRunning = [];
		$dockerUpdateStatus = [];
	}

	return [$info, $dockerRunning, $dockerUpdateStatus];
}

/**
 * Locate a template entry based on an app identifier within the displayed listings.
 *
 * Searches first by InstallPath, then iterates by Path to find a matching index.
 *
 * @param  array<string,mixed> $displayed Cached displayed-templates structure (contains 'community').
 * @param  mixed               $appNumber Identifier to match against InstallPath/Path.
 * @return array{0: ?array<string,mixed>, 1: int|false} Tuple of [template, index] or [null, false] if not found.
 */
function caLocateTemplate(array $displayed, $appNumber) {
	$community = $displayed['community'] ?? [];
	$index = searchArray($community,"InstallPath",$appNumber);

	if ($index === false) {
		$startIndex = 0;
		while (true) {
			$ind = searchArray($community,"Path",$appNumber,$startIndex);
			if ($ind === false) {
				return [null, false];
			}
			if (isset($community[$ind])) {
				$index = $ind;
				break;
			}
			$startIndex = $ind + 1;
		}
	}

	if ($index !== false && isset($community[$index])) {
		return [$community[$index], $index];
	}

	return [null, false];
}

/**
 * Determine selection status and identifiers for docker/plugin templates.
 *
 * Mutates `$template['Repository']` to prepend `library/` when no registry slash is present.
 *
 * @param  array<string,mixed> $template      Template entry; modified in place.
 * @param  array<int,array<string,mixed>> $dockerRunning Running docker containers list.
 * @return array{0: ?bool, 1: ?string, 2: ?string} Tuple of [selected flag, container name, plugin name].
 */
function caResolveSelectionState(array &$template, array $dockerRunning) {
	$selected = null;
	$name = null;
	$pluginName = null;

	if (!$template['Plugin']) {
		if (!strpos($template['Repository'],"/")) {
			$template['Repository'] = "library/{$template['Repository']}";
		}
		foreach ($dockerRunning as $testDocker) {
			$repoMatch = ($template['Repository'] == $testDocker['Image']) || ("{$template['Repository']}:latest" == $testDocker['Image']);
			$nameMatch = ($template['Name'] == $testDocker['Name']);
			if ($repoMatch && $nameMatch) {
				$selected = true;
				$name = $testDocker['Name'];
				break;
			}
		}
	} else {
		$pluginName = basename($template['PluginURL']);
	}

	return [$selected, $name, $pluginName];
}

/**
 * Build the Support button context for a template card or popup.
 *
 * Reads `$GLOBALS['caSettings']['dev']` to optionally include template/plugin source links.
 *
 * @param  array<string,mixed>             $template        Template entry.
 * @param  array<string,array<string,mixed>> $allRepositories Repository metadata keyed by repo name.
 * @return array<int,array{icon:string,text:string,link?:string,action?:string}> Support entry list. Each entry has either a `link` (rendered as an `<a href>`) or an `action` (rendered as an `onclick` payload — dev-mode Diff button).
 */
function caBuildSupportContext(array $template, array $allRepositories) {
	$supportContext = [];
	if ($template['Project']) {
		$supportContext[] = ["icon"=>"ca_fa-project","link"=>$template['Project'],"text"=>tr("Project")];
	}
	if ($template['Discord']) {
		$supportContext[] = ["icon"=>"ca_discord","link"=>$template['Discord'],"text"=>tr("Discord")];
	} elseif (isset($template['Repo']) && isset($allRepositories[$template['Repo']]['Discord'])) {
		$supportContext[] = ["icon"=>"ca_discord","link"=>$allRepositories[$template['Repo']]['Discord'],"text"=>tr("Discord")];
	}
	if ($template['Facebook']) {
		$supportContext[] = ["icon"=>"ca_facebook","link"=>$template['Facebook'],"text"=>tr("Facebook")];
	}
	if ($template['Reddit']) {
		$supportContext[] = ["icon"=>"ca_reddit","link"=>$template['Reddit'],"text"=>tr("Reddit")];
	}
	if ($template['Support']) {
		$supportContext[] = ["icon"=>"ca_fa-support","link"=>$template['Support'],"text"=>$template['SupportLanguage'] ?: tr("Support")];
	}
	if ($template['Registry']) {
		$supportContext[] = ["icon"=>"ca_fa-docker","link"=>$template['Registry'],"text"=>tr("Registry")];
	}
	if ($GLOBALS['caSettings']['dev'] == "yes") {
		/* Every entry below is dev-mode only. The `class` is read by the
		   sidebar template (skin.php → displayPopup) and rendered onto the
		   caButton so responsive CSS can hide them on phone-sized viewports
		   (.ca_devMode { display: none } under 767px). */
		/* json_encode with the JS-safety flags produces a properly-quoted JS
		   string literal (including the outer quotes) — safer than addslashes
		   when the value lands inside an HTML onclick attribute, since it
		   neutralizes `<`, `>`, `&`, and `'` as well. */
		$jsFlags  = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
		/* Template / Plugin buttons route through caShowDevSource (diff.js)
		   which reuses the #caDiffView modal — a direct <a href> on a GitHub
		   release URL like .../releases/latest/download/foo.plg comes back
		   with Content-Disposition: attachment and the browser saves it. The
		   second arg is `asPlugin`: true requests the two-column raw vs.
		   entity-decoded view, false is a single-column source dump. */
		$templateUrl = (string)($template['caTemplateURL'] ?: ($template['TemplateURL'] ?? ""));
		if ($templateUrl !== "") {
			$templateUrlJs = json_encode($templateUrl, $jsFlags);
			$supportContext[] = ["icon"=>"ca_fa-template","action"=>"caShowDevSource({$templateUrlJs},false)","text"=>tr("Template"), "class"=>"ca_devMode"];
		}
		if (!empty($template['Plugin']) && !empty($template['PluginURL'])) {
			$pluginUrlJs = json_encode((string)$template['PluginURL'], $jsFlags);
			$supportContext[] = ["icon"=>"ca_fa-template","action"=>"caShowDevSource({$pluginUrlJs},true)","text"=>tr("Plugin"), "class"=>"ca_devMode"];
		}
		/* Pass the URL straight through to the diff endpoint so it can locate
		   the appfeed entry without loading any templates cache — saves a
		   huge memory load. TemplateURL for containers, PluginURL for the
		   internal-diff button on plugins. */
		$diffName = json_encode((string)($template['Name'] ?? ""), $jsFlags);
		$diffContainerUrl = json_encode($templateUrl, $jsFlags);
		$diffPluginUrl    = json_encode((string)($template['PluginURL'] ?? ""), $jsFlags);
		/* Both Diff buttons go through caGetCachedApplicationFeed which reads
		   the raw appfeed snapshot DownloadApplicationFeed() stashes to
		   rawAppFeed (dev-mode only). Until that file lands on disk the
		   buttons can't do anything useful — hide them entirely rather than
		   render an option that errors out on click. Handles the edge case
		   of opening a sidebar before the background feed download finishes
		   on a slow connection. */
		$diffFeedReady = is_file(CA_PATHS['rawAppFeed']);
		/* Diff is container-only — plugin .plg payloads don't survive the
		   array→XML round-trip cleanly and the resulting diff is more noise
		   than signal. */
		if ($diffFeedReady && empty($template['Plugin']) && $templateUrl !== "") {
			$supportContext[] = [
				"icon"   => "ca_fa-diff",
				"action" => "caShowTemplateDiff({$diffContainerUrl},{$diffName},'feed')",
				"text"   => tr("Diff"),
				"class"  => "ca_devMode",
			];
		}
		/* Internal diff (appfeed vs CA's internal templates_full.json) — only
		   when the admin marker file exists. Available for plugins too since
		   neither side does a source-XML round-trip. */
		if ($diffFeedReady && is_file(CA_PATHS['caAdmin'])) {
			$diffUrl = !empty($template['Plugin']) ? $diffPluginUrl : $diffContainerUrl;
			$supportContext[] = [
				"icon"   => "ca_fa-diff",
				"action" => "caShowTemplateDiff({$diffUrl},{$diffName},'internal')",
				"text"   => tr("CA"),
				"class"  => "ca_devMode",
			];
		}
	}

	return $supportContext;
}

/**
 * Prepare trend data/markup for templates with download and usage statistics.
 *
 * Mutates `$template` (Category, Icon, display_changelogMessage, trendsDate) and appends
 * canvas markup to `$templateDescription` when trend data is available.
 *
 * @param  array<string,mixed> $template            Template entry; modified in place.
 * @param  string              $templateDescription Buffer receiving chart canvas markup; modified in place.
 * @return array{chartLabel:mixed,downloadLabel:mixed,down:array<int,int>,totalDown:array<int,int>}
 */
function caPrepareTrendVisuals(array &$template, &$templateDescription) {
		$chartLabel = "";
		$downloadLabel = "";
		$down = [];
		$totalDown = [];

		if ($template['trending']) {
			$allApps = &$GLOBALS['templates'];
			$allTrends = array_unique(array_column($allApps,"trending"));
			rsort($allTrends);
			$trendRank = array_search($template['trending'],$allTrends);
			if ($trendRank !== false) {
				$trendRank += 1;
			}
		}

		$template['Category'] = categoryList($template['Category'],true);
		$template['Icon'] = $template['Icon'] ? $template['Icon'] : "/plugins/dynamix.docker.manager/images/question.png";

		if ($template['Overview']) {
			$ovr = $template['OriginalOverview'] ?: $template['Overview'];
		}
		if (!isset($ovr)) {
			$ovr = $template['OriginalDescription'] ?: $template['Description'];
		}

		if (is_array($template['trends']) && (count($template['trends']) > 1)) {
			if ($template['downloadtrend']) {
				$templateDescription .= "<div><canvas id='trendChart{$template['ID']}' class='caChart' height=1 width=3></canvas></div>";
				$templateDescription .= "<div><canvas id='downloadChart{$template['ID']}' class='caChart' height=1 width=3></canvas></div>";
				$templateDescription .= "<div><canvas id='totalDownloadChart{$template['ID']}' class='caChart' height=1 width=3></canvas></div>";
			}
		}

		if (!isset($template['Language']) || !$template['Language']) {
			$changeLogMessage = "Note: not all ";
			$changeLogMessage .= $template['PluginURL'] || $template['Language'] ? "authors" : "maintainers";
			$changeLogMessage .= " keep up to date on change logs<br>";
			$template['display_changelogMessage'] = tr($changeLogMessage);
		}

		if (isset($template['trendsDate'])) {
			array_walk($template['trendsDate'],function (&$entry) {
				$entry = tr(date("M",$entry),0).date(" j",$entry);
			});
		}

		if (is_array($template['trends'])) {
			if (is_array($template['downloadtrend']) && count($template['trends']) < count($template['downloadtrend'])) {
				array_shift($template['downloadtrend']);
			}

			$chartLabel = $template['trendsDate'];
			if (is_array($template['downloadtrend']) && !empty($template['downloadtrend'])) {
				$minDownload = intval(((100 - $template['trends'][0]) / 100) * ($template['downloadtrend'][0]));
				foreach ($template['downloadtrend'] as $download) {
					$totalDown[] = $download;
					$down[] = intval($download - $minDownload);
					$minDownload = $download;
				}
				$downloadLabel = $template['trendsDate'];
			}
		}

		return [
			'chartLabel' => $chartLabel ?? "",
			'downloadLabel' => $downloadLabel ?? "",
			'down' => $down ?? [],
			'totalDown' => $totalDown ?? []
		];
	}

/**
 * Resolve pinned/unpinned state for templates based on user preferences.
 *
 * Sets `$template['pinnedClass']` to `pinned` or `unpinned`.
 *
 * @param  array<string,mixed> $template   Template entry; modified in place.
 * @param  array<string,mixed> $pinnedApps Pinned-apps map keyed by `Repository&SortName`.
 * @return void
 */
function caResolvePinnedState(array &$template, array $pinnedApps) {
		/* Mirrors the favourite-repo button: static label, state shown via
		   `.pinned` / `.unpinned` colour classes. pinApp() in Apps.page just
		   toggles those — no text/title swap. */
		$template['pinnedClass'] = ($pinnedApps["{$template['Repository']}&{$template['SortName']}"] ?? false)
			? "pinned"
			: "unpinned";
	}

/**
 * Retrieve language pack metadata and load translation files when required.
 *
 * Downloads the requested locale file to CA_PATHS['tempFiles'] if absent (network I/O),
 * then parses it into `$language` (modified in place).
 *
 * @param  array<string,mixed> $template Template entry; read-only access.
 * @param  array<string,mixed> $language Translation map; populated in place.
 * @return string|null Country code resolved for the template, or null if not a language pack.
 */
function caPrepareLanguagePack(array &$template, array &$language) {
		if (!$template['Language']) {
			return null;
		}

		$countryCode = $template['LanguageDefault'] ? "en_US" : $template['LanguagePack'];
		if ($countryCode !== "en_US") {
			if (!is_file(CA_PATHS['tempFiles']."/CA_language-$countryCode")) {
				download_url(CA_PATHS['CA_languageBase']."$countryCode",CA_PATHS['tempFiles']."/CA_language-$countryCode");
			}
			$language = is_file(CA_PATHS['tempFiles']."/CA_language-$countryCode") ? @parse_lang_file(CA_PATHS['tempFiles']."/CA_language-$countryCode") : [];
		} else {
			$language = [];
		}

		return $countryCode;
	}

/**
 * Build the context menu for template actions (install/update/manage).
 *
 * Reads filesystem state (installed plugins, pending plugins, template XML files),
 * mutates `$template['UpdateAvailable']` / `$template['Installed']`, and may invoke
 * `@copy()` to stage plugin download into /tmp/plugins/.
 *
 * @param  array<string,mixed>             $template           Template entry; modified in place.
 * @param  array<string,array<string,mixed>> $info               Docker container info keyed by name.
 * @param  array<string,mixed>             $dockerUpdateStatus Update status keyed by Repository.
 * @param  ?bool                           $selected           Selection state from caResolveSelectionState.
 * @param  ?string                         $name               Resolved container name.
 * @param  ?string                         $pluginName         Resolved plugin basename.
 * @return array<int,array<string,mixed>> Ordered list of action descriptors.
 */
function caBuildActionsContext(array &$template, array $info, array $dockerUpdateStatus, $selected, $name, $pluginName) {
		$actionsContext = [];

		if ($template['Language']) {
			return $actionsContext;
		}

		if ($template['NoInstall'] || ($GLOBALS['caSettings']['NoInstalls'] ?? false)) {
			return $actionsContext;
		}

		if (!$template['Plugin']) {
			if (caIsDockerRunning()) {
				if ($selected) {
					if (($info[$name]['url'] ?? false) && ($info[$name]['running'] ?? false)) {
						$actionsContext[] = ["icon"=>"ca_fa-globe","text"=>tr("WebUI"),"action"=>"openNewWindow('{$info[$name]['url']}','_blank');"];
						if ($info[$name]['TSurl'] ?? false) {
							$actionsContext[] = ["icon"=>"ca_fa-globe","text"=>tr("TS WebUI"),"action"=>"openNewWindow('{$info[$name]['TSurl']}','_blank');"];
						}
					}
					/* Detect image tag presence by checking for `:` AFTER the last `/`
					   so registry-port colons (eg. `registry:5000/ns/app`) aren't
					   misread as tags. Mirrors PreviousAppsHelpers::stripImageTag. */
					$lastSlash = strrpos($template['Repository'], "/");
					$hasTag = $lastSlash !== false
						? strpos($template['Repository'], ":", $lastSlash) !== false
						: strpos($template['Repository'], ":") !== false;
					$tmpRepo = $hasTag ? $template['Repository'] : "{$template['Repository']}:latest";
					$tmpRepo = strpos($tmpRepo, "/") !== false ? $tmpRepo : "library/$tmpRepo";
					if ( ($dockerUpdateStatus[$tmpRepo]['status'] ?? "") == "false") {
						$template['UpdateAvailable'] = true;
						$actionsContext[] = ["icon"=>"ca_fa-update","text"=>tr("Update"),"action"=>"updateDocker('$name');"];
					} else {
						$template['UpdateAvailable'] = false;
					}
					if ($GLOBALS['caSettings']['defaultReinstall'] == "true" && !$template['Blacklist'] && $template['ID'] !== false) {
						if ($template['BranchID'] ?? false) {
							$actionsContext[] = ["icon"=>"ca_fa-install","text"=>tr("Install second"),"action"=>"displayTags('{$template['ID']}',true,'".$template['PortsUsed']."');"];
						} else {
							$actionsContext[] = ["icon"=>"ca_fa-install","text"=>tr("Install second"),"action"=>"popupInstallXML('".addslashes($template['Path'])."','second','".$template['PortsUsed']."');"];
						}
					}
					if (is_file($info[$name]['template'])) {
						$actionsContext[] = ["icon"=>"ca_fa-edit","text"=>tr("Edit"),"action"=>"popupInstallXML('".addslashes($info[$name]['template'])."','edit');"];
					}
					$actionsContext[] = ["divider"=>true];
					if ($info[$name]['template']) {
						$actionsContext[] = ["icon"=>"ca_fa-delete","text"=>tr("Uninstall"),"action"=>"uninstallDocker('".addslashes($info[$name]['template'])."','{$template['Name']}');"];
						$template['Installed'] = true;
					}
				} elseif (!$template['Blacklist']) {
					if ($template['InstallPath']) {
						$userTemplate = readXmlFile($template['InstallPath'],false,false);
						if (!$template['Blacklist']) {
							$actionsContext[] = ["icon"=>"ca_fa-install","text"=>tr("Reinstall"),"action"=>"popupInstallXML('".addslashes($template['InstallPath'])."','user','".portsUsed($userTemplate)."');"];
							$actionsContext[] = ["divider"=>true];
						}
						$actionsContext[] = ["icon"=>"ca_fa-delete","text"=>tr("Remove"),"action"=>"removeApp('{$template['InstallPath']}','{$template['Name']}');"];
					} else {
						if (!$template['Blacklist']) {
							if ($template['Compatible'] || $GLOBALS['caSettings']['hideIncompatible'] !== "true") {
								if (!$template['Deprecated'] || $GLOBALS['caSettings']['hideDeprecated'] !== "true") {
									if (!isset($template['BranchID'])) {
										$actionsContext[] = ["icon"=>"ca_fa-install","text"=>tr("Install"),"action"=>"popupInstallXML('".addslashes($template['Path'])."','default','".$template['PortsUsed']."');"];
									} else {
										$actionsContext[] = ["icon"=>"ca_fa-install","text"=>tr("Install"),"action"=>"displayTags('{$template['ID']}',false,'".$template['PortsUsed']."');"];
									}
								}
							}
						}
					}
				}
			}
		} else {
			if (checkInstalledPlugin($template)) {
				$template['Installed'] = true;
				$template['installedVersion'] = ca_plugin("version","/var/log/plugins/$pluginName");
				/* `!=` (not `<`) is intentional: also fires when installed > feed,
				   so a user can roll back to the feed version via the Update action. */
				if ($template['installedVersion'] != $template['pluginVersion'] || (is_file("/tmp/plugins/$pluginName") && $template['installedVersion'] != ca_plugin("version","/tmp/plugins/$pluginName"))) {
					/* `@copy` is best-effort: pluginTempDownload may or may not be
					   present (only the install/update flow stages it). When it's
					   missing the copy no-ops; the Update button still emits so the
					   sidebar matches the card's caProcessPluginTemplate path. The
					   prior `is_file(...)` gate around this whole block was dead —
					   nothing in the runtime produces pluginTempDownload during a
					   plain sidebar open, so plugins never showed an Update action. */
					@copy(CA_PATHS['pluginTempDownload'],"/tmp/plugins/$pluginName");
					$template['UpdateAvailable'] = true;
					/* json_encode the string args so a hostile feed value can't break out
					   of the JS string literal — produces a properly-quoted JS string and
					   escapes `<`/`>`/`&`/`'`/`"` for safety in the surrounding HTML
					   attribute. The emit-side htmlspecialchars in skin.php is the
					   second layer; this is the first. */
					$pluginNameJs = json_encode($pluginName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
					$actionsContext[] = ["icon"=>"ca_fa-update","text"=>tr("Update"),"action"=>"installPlugin({$pluginNameJs},true);"];
				} else {
					$template['UpdateAvailable'] = false;
				}

				$pluginSettings = ca_plugin("launch","/var/log/plugins/$pluginName");
				if ($pluginSettings) {
					$actionsContext[] = ["icon"=>"ca_fa-pluginSettings","text"=>tr("Settings"),"action"=>"openNewWindow('/Apps/$pluginSettings');"];
				}
				if ($pluginName != "community.applications.plg") {
					if (!empty($actionsContext)) {
						$actionsContext[] = ["divider"=>true];
					}
					$actionsContext[] = ["icon"=>"ca_fa-delete","text"=>tr("Uninstall"),"action"=>"uninstallApp('/var/log/plugins/$pluginName','".str_replace(" ","&nbsp;",$template['Name'])."');"];
				}
			} elseif (!$template['Blacklist']) {
				if (($template['Compatible'] || $GLOBALS['caSettings']['hideIncompatible'] !== "true") && !($template['UninstallOnly'] ?? false)) {
					if (!$template['Deprecated'] || $GLOBALS['caSettings']['hideDeprecated'] !== "true" || ($template['Deprecated'] && $template['InstallPath'])) {
						/* Always render the install action and pass updateFlag /
						   requiresText through to installPlugin() — matches the
						   behaviour in caProcessPluginTemplate() so the same plugin
						   isn't installable from the card but hidden in the sidebar.
						   When RequiresFile is declared but the file doesn't exist,
						   pass the markers so the install handler can warn. */
						$updateFlag = false;
						$requiresText = "";
						if (!empty($template['RequiresFile']) && !is_file($template['RequiresFile'])) {
							$requiresText = "AnythingHere";
							$updateFlag = true;
						}
						$buttonTitle = $template['InstallPath'] ? tr("Reinstall") : tr("Install");
						/* Build install URL flags additively. The previous form overwrote
						   the deprecated flag with the incompatible one (and inverted the
						   compatibility polarity), so deprecated+incompatible plugins lost
						   their deprecated marker and compatible plugins were mislabeled. */
						$installFlags = "";
						if ( ! empty($template['Deprecated']) )      $installFlags .= "&deprecated";
						if ( empty($template['Compatible']) )        $installFlags .= "&incompatible";
						/* See note at the Update emit above — json_encode each string arg
						   so feed-controlled values (PluginURL especially) can't break
						   out of the JS string literal. */
						$jsFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
						$installUrlJs    = json_encode($template['PluginURL'] . $installFlags, $jsFlags);
						$updateFlagJs    = json_encode((bool)$updateFlag, $jsFlags);
						$requiresTextJs  = json_encode($requiresText, $jsFlags);
						$actionsContext[] = ["icon"=>"ca_fa-install","text"=>$buttonTitle,"action"=>"installPlugin({$installUrlJs},{$updateFlagJs},{$requiresTextJs});"];
					}
				}
				if ($template['InstallPath']) {
					if (!empty($actionsContext)) {
						$actionsContext[] = ["divider"=>true];
					}
					$actionsContext[] = ["icon"=>"ca_fa-delete","text"=>tr("Remove"),"action"=>"removeApp('{$template['InstallPath']}','$pluginName');"];
				}
			}
			if (is_file(CA_PATHS['pluginPending'].$pluginName)) {
				$actionsContext = [["text"=>tr("Pending")]];
			}
		}

		return $actionsContext;
	}

/**
 * Build action contexts for language pack templates within the card renderer.
 *
 * Reads /usr/local/emhttp/languages/ and checks for pending plugin files on disk;
 * mutates `$template['UpdateAvailable']`.
 *
 * @param  array<string,mixed>             $template       Template entry; modified in place.
 * @param  ?string                         $countryCode    Locale code (e.g. "en_US").
 * @param  array<int,array<string,mixed>>  $actionsContext Existing actions list to extend.
 * @return array<int,array<string,mixed>> Updated actions list.
 */
function caBuildLanguageActions(array &$template, ?string $countryCode, array $actionsContext) {
		if (!$template['Language']) {
			return $actionsContext;
		}

		$dynamixSettings = @parse_ini_file(CA_PATHS['dynamixSettings'],true);
		$currentLanguage = $dynamixSettings['display']['locale'] ?? "en_US";
		$installedLanguages = array_diff(scandir("/usr/local/emhttp/languages"),[".",".."]);
		$installedLanguages = array_filter($installedLanguages,function ($v) {
			return is_dir("/usr/local/emhttp/languages/$v");
		});
		$installedLanguages[] = "en_US";
		$currentLanguage = (is_dir("/usr/local/emhttp/languages/$currentLanguage")) ? $currentLanguage : "en_US";

		if (in_array($countryCode,$installedLanguages)) {
			if ($currentLanguage != $countryCode) {
				$actionsContext[] = ["icon"=>"ca_fa-switchto","text"=>$template['SwitchLanguage'],"action"=>"CAswitchLanguage('$countryCode');"];
			}
		} else {
			$actionsContext[] = ["icon"=>"ca_fa-install","text"=>tr("Install"),"action"=>"installLanguage('{$template['TemplateURL']}','$countryCode');"];
		}

		if (file_exists("/var/log/plugins/lang-$countryCode.xml")) {
			if (languageCheck($template)) {
				$template['UpdateAvailable'] = true;
				$actionsContext[] = ["icon"=>"ca_fa-update","text"=>$template['UpdateLanguage'],"action"=>"updateLanguage('$countryCode');"];
			}
			if ($currentLanguage != $countryCode) {
				if (!empty($actionsContext)) {
					$actionsContext[] = ["divider"=>true];
				}
				$actionsContext[] = ["icon"=>"ca_fa-delete","text"=>tr("Uninstall"),"action"=>"removeLanguage('$countryCode');"];
			}
		}

		if (file_exists(CA_PATHS['pluginPending'].$template['LanguagePack']) || file_exists(CA_PATHS['pluginPending']."lang-{$template['LanguagePack']}.xml")) {
			$actionsContext = [["text"=>tr("Pending")]];
		}

		return $actionsContext;
	}



/**
 * Assemble docker-related context (running container info + update status) for listings.
 *
 * Reads CA_PATHS['dockerUpdateStatus']. When docker is off both members are empty arrays —
 * downstream renderers gracefully render no docker-specific UI in that case (the per-app
 * "Docker Service Not Enabled" notice lives in the sidebar template itself).
 *
 * @return array{info:array<string,mixed>,dockerUpdateStatus:array<string,mixed>}
 */
function caDockerContext(): array {
	if ( caIsDockerRunning() ) {
		$info = getAllInfo();
		$dockerUpdateStatus = readJsonFile(CA_PATHS['dockerUpdateStatus']);
		/* readJsonFile returns whatever the file decodes to — coerce to array
		   so caProcessDockerTemplate's typed array param doesn't trip when
		   the file is empty / scalar / corrupted. */
		if ( ! is_array($dockerUpdateStatus) ) {
			$dockerUpdateStatus = [];
		}
	} else {
		$info = [];
		$dockerUpdateStatus = [];
	}

	return [
		'info'               => $info,
		'dockerUpdateStatus' => $dockerUpdateStatus
	];
}

/**
 * Normalize the structure of the multi-select payload used in CA UI.
 *
 * Coerces falsy input to an empty array and ensures `docker` / `plugin` sub-keys exist.
 *
 * @param  mixed $selectedApps Raw selected-apps payload (array with docker/plugin sub-arrays, or falsy).
 * @return array{0: array<string,array<int,string>>, 1: array<string,bool>} Tuple of [normalized payload, checkedOffApps map].
 */
function caNormalizeSelectedApps($selectedApps): array {
	if (! $selectedApps) {
		$selectedApps = [];
	}

	$selectedApps['docker'] = $selectedApps['docker'] ?? [];
	$selectedApps['plugin'] = $selectedApps['plugin'] ?? [];

	$checkedOffApps = arrayEntriesToObject(@array_merge(@array_values($selectedApps['docker']), @array_values($selectedApps['plugin'])));

	return [$selectedApps, $checkedOffApps];
}

/**
 * Slice the current page worth of templates from the full listing.
 *
 * Reads `$GLOBALS['caSettings']['maxPerPage']` to determine page size.
 *
 * @param  array<int,array<string,mixed>> $file       Full ordered template list.
 * @param  int                            $pageNumber 1-based page index.
 * @return array<int,array<string,mixed>> Templates for the requested page.
 */
function caSliceDisplayedTemplates(array $file, int $pageNumber): array {
	$maxPerPage = (int)($GLOBALS['caSettings']['maxPerPage'] ?? 0);
	$startingApp = ($pageNumber - 1) * $maxPerPage + 1;
	$startingAppCounter = 0;
	$displayedTemplates = [];

	foreach ($file as $template) {
		$startingAppCounter++;
		if ($startingAppCounter < $startingApp) {
			continue;
		}
		$displayedTemplates[] = $template;
		if ($maxPerPage > 0 && count($displayedTemplates) >= $maxPerPage) {
			break;
		}
	}

	return $displayedTemplates;
}

/**
 * Apply moderation overrides (blacklist/deprecation comments) to a template.
 *
 * @param  array<string,mixed> $template        Template entry.
 * @param  array<string,string> $extraBlacklist  Repository -> moderator comment map.
 * @param  array<string,string> $extraDeprecated Repository -> moderator comment map.
 * @return array<string,mixed> Template with Blacklist/Deprecated/ModeratorComment fields updated.
 */
function caApplyModerationOverrides(array $template, array $extraBlacklist, array $extraDeprecated): array {
	if (! $template['RepositoryTemplate']) {
		if (! $template['Blacklist'] && isset($extraBlacklist[$template['Repository']])) {
			$template['Blacklist'] = true;
			$template['ModeratorComment'] = $extraBlacklist[$template['Repository']];
		}

		if (! $template['Deprecated'] && isset($extraDeprecated[$template['Repository']])) {
			$template['Deprecated'] = true;
			$template['ModeratorComment'] = $extraDeprecated[$template['Repository']];
		}
	}

	return $template;
}

/**
 * Build action contexts and flags for docker templates when rendering cards.
 *
 * Reads `$GLOBALS['caSettings']` and template XML files via readXmlFile() (filesystem I/O).
 *
 * @param  array<string,mixed>             $template           Template entry.
 * @param  array<int,array<string,mixed>>  $info               Running docker container info.
 * @param  array<string,mixed>             $dockerUpdateStatus Update status keyed by Repository.
 * @return array{0: array<string,mixed>, 1: array<int,array<string,mixed>>} Tuple of [updated template, actionsContext].
 */
function caProcessDockerTemplate(array $template, array $info, array $dockerUpdateStatus): array {
	$actionsContext = [];
	$selected = false;
	$name = "";

	if (caIsDockerRunning()) {
		foreach ($info as $testDocker) {
			/* Tag-vs-port colon disambiguation — see comment in caGenerateActionsContext. */
			$lastSlash = strrpos($template['Repository'], "/");
			$hasTag = $lastSlash !== false
				? strpos($template['Repository'], ":", $lastSlash) !== false
				: strpos($template['Repository'], ":") !== false;
			$tmpRepo = $hasTag ? $template['Repository'] : "{$template['Repository']}:latest";
			$tmpRepo = strpos($tmpRepo, "/") !== false ? $tmpRepo : "library/$tmpRepo";
			if ((($tmpRepo == $testDocker['Image'] && $template['Name'] == $testDocker['Name']) || "{$tmpRepo}:latest" == $testDocker['Image']) && ($template['Name'] == $testDocker['Name'])) {
				$selected = true;
				$name = $testDocker['Name'];
				break;
			}
		}

		$template['Installed'] = $selected;
		if ($selected) {
			$ind = searchArray($info, "Name", $name);
			if ($info[$ind]['url'] && $info[$ind]['running']) {
				$actionsContext[] = ["icon" => "ca_fa-globe", "text" => tr("WebUI"), "action" => "openNewWindow('{$info[$ind]['url']}','_blank');"];
				if ($info[$ind]['TSurl'] ?? false) {
					$actionsContext[] = ["icon" => "ca_fa-globe", "text" => tr("TS WebUI"), "action" => "openNewWindow('{$info[$ind]['TSurl']}','_blank');"];
				}
			}

			/* Tag-vs-port colon disambiguation — see comment in caGenerateActionsContext. */
			$lastSlash = strrpos($template['Repository'], "/");
			$hasTag = $lastSlash !== false
				? strpos($template['Repository'], ":", $lastSlash) !== false
				: strpos($template['Repository'], ":") !== false;
			$tmpRepo = $hasTag ? $template['Repository'] : "{$template['Repository']}:latest";
			$tmpRepo = strpos($tmpRepo, "/") !== false ? $tmpRepo : "library/$tmpRepo";

			if (($dockerUpdateStatus[$tmpRepo]['status'] ?? null) === "false") {
				$template['UpdateAvailable'] = true;
				$actionsContext[] = ["icon" => "ca_fa-update", "text" => tr("Update"), "action" => "updateDocker('$name');"];
			} else {
				$template['UpdateAvailable'] = false;
			}

			if ($GLOBALS['caSettings']['defaultReinstall'] == "true" && ! $template['Blacklist']) {
				if ($template['ID'] !== false) {
					if ($template['BranchID'] ?? false) {
						$actionsContext[] = ["icon" => "ca_fa-install", "text" => tr("Install second"), "action" => "displayTags('{$template['ID']}',true,'".$template['PortsUsed']."');"];
					} else {
						$actionsContext[] = ["icon" => "ca_fa-install", "text" => tr("Install second"), "action" => "popupInstallXML('".addslashes($template['Path'])."','second','".$template['PortsUsed']."');"];
					}
				}
			}

			if (is_file($info[$ind]['template'])) {
				$actionsContext[] = ["icon" => "ca_fa-edit", "text" => tr("Edit"), "action" => "popupInstallXML('".addslashes($info[$ind]['template'])."','edit');"];
			}

			$actionsContext[] = ["divider" => true];
			if ($info[$ind]['template']) {
				$actionsContext[] = ["icon" => "ca_fa-delete", "text" => tr("Uninstall"), "action" => "uninstallDocker('".addslashes($info[$ind]['template'])."','{$template['Name']}');"];
			}
		} elseif (! ($template['Blacklist'] ?? false)) {
			/* The Install/Reinstall branch fires for non-blacklisted templates
			   regardless of $template['Compatible'] — we need Reinstall and the
			   Previous-Apps remove action to remain available even on
			   incompatible OS versions (so the user can clean up). The fresh
			   "Install" actions further down get gated separately by the
			   Compatible/Deprecated checks the user has configured. */
			if ($template['InstallPath']) {
				$userTemplate = readXmlFile($template['InstallPath'], false, false);
				$actionsContext[] = ["icon" => "ca_fa-install", "text" => tr("Reinstall"), "action" => "popupInstallXML('".addslashes($template['InstallPath'])."','user','".portsUsed($userTemplate)."');"];
				$actionsContext[] = ["divider" => true];
				$actionsContext[] = ["icon" => "ca_fa-delete", "text" => tr("Remove"), "action" => "removeApp('{$template['InstallPath']}','{$template['Name']}');"];
			} else {
				$canFreshInstall = (($template['Compatible'] ?? false) || ($GLOBALS['caSettings']['hideIncompatible'] ?? "true") !== "true")
					&& (! ($template['Deprecated'] ?? false) || ($GLOBALS['caSettings']['hideDeprecated'] ?? "true") !== "true");
				if (! ($template['BranchID'] ?? null)) {
					if (is_file(CA_PATHS['dockerManTemplates']."/my-{$template['Name']}.xml")) {
						$previousTemplatePath = CA_PATHS['dockerManTemplates']."/my-{$template['Name']}.xml";
						$test = readXmlFile($previousTemplatePath, true);
						if ($template['Repository'] == $test['Repository']) {
							$userTemplate = readXmlFile($previousTemplatePath, false, false);
							$actionsContext[] = ["icon" => "ca_fa-install", "text" => tr("Reinstall From Previous Apps"), "action" => "popupInstallXML('".addslashes($previousTemplatePath)."','user','".portsUsed($userTemplate)."');"];
							$actionsContext[] = ["divider" => true];
						}
					}
					if ($canFreshInstall) {
						$actionsContext[] = ["icon" => "ca_fa-install", "text" => tr("Install"), "action" => "popupInstallXML('".addslashes($template['Path'])."','default','".$template['PortsUsed']."');"];
					}
				} else {
					if ($canFreshInstall) {
						$actionsContext[] = ["icon" => "ca_fa-install", "text" => tr("Install"), "action" => "displayTags('{$template['ID']}',false,'".$template['PortsUsed']."');"];
					}
				}
			}
		}
	}

	return [$template, $actionsContext];
}

/**
 * Build action contexts and state for plugin templates when rendering cards.
 *
 * Reads plugin metadata from /var/log/plugins/ and /tmp/plugins/ on disk, and may
 * `@copy()` the pending download into /tmp/plugins/ to stage an update.
 *
 * @param  array<string,mixed> $template Template entry.
 * @return array{0: array<string,mixed>, 1: array<int,array<string,mixed>>} Tuple of [updated template, actionsContext].
 */
function caProcessPluginTemplate(array $template): array {
	$actionsContext = [];
	$pluginName = basename($template['PluginURL']);
	$template['Installed'] = checkInstalledPlugin($template);

	if ($template['Installed'])  {
		$pluginInstalledVersion = ca_plugin("version", "/var/log/plugins/$pluginName");
		/* The temp .plg file in /tmp/plugins/$pluginName is dropped there by the
		   plugin updater after a fresh download. Only consider it when present
		   AND newer than the version we already have — never blindly overwrite
		   $template['pluginVersion'] with the result of ca_plugin() (which
		   returns false for missing files and would break update detection). */
		if (file_exists("/tmp/plugins/$pluginName")) {
			$tmpPluginVersion = ca_plugin("version", "/tmp/plugins/$pluginName");
			if ($tmpPluginVersion && strcmp($template['pluginVersion'], $tmpPluginVersion) < 0) {
				$template['pluginVersion'] = $tmpPluginVersion;
			}
		}

		if ((strcmp($pluginInstalledVersion, $template['pluginVersion']) < 0 || $template['UpdateAvailable']) && $template['Name'] !== "Community Applications" && (! ($template['UninstallOnly'] ?? false))) {
			@copy(CA_PATHS['pluginTempDownload'], "/tmp/plugins/$pluginName");
			$template['UpdateAvailable'] = true;
			/* json_encode pluginName so a URL-derived basename with quotes / weird
			   chars can't break out of the JS string. Dropped the trailing two args
			   (empty requires sentinel + raw RequiresFile path): the JS signature
			   is `installPlugin(pluginURL, update, requires)` — three args, the
			   fourth was dead data and the literal source of the XSS path. */
			$pluginNameJs = json_encode($pluginName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
			$actionsContext[] = ["icon" => "ca_fa-update", "text" => tr("Update"), "action" => "installPlugin({$pluginNameJs},true);"];
		} else {
			if (! $template['UpdateAvailable']) {
				$template['UpdateAvailable'] = false;
			}
		}
		$pluginSettings = ca_plugin("launch", "/var/log/plugins/$pluginName");
		if ($pluginSettings) {
			$actionsContext[] = ["icon" => "ca_fa-pluginSettings", "text" => tr("Settings"), "action" => "openNewWindow('/Apps/$pluginSettings');"];
		}

		if ($pluginName != "community.applications.plg") {
			if (! empty($actionsContext)) {
				$actionsContext[] = ["divider" => true];
			}
			$actionsContext[] = ["icon" => "ca_fa-delete", "text" => tr("Uninstall"), "action" => "uninstallApp('/var/log/plugins/$pluginName','".str_replace(" ", "&#32;", $template['Name'])."');"];
		}
	} elseif (! $template['Blacklist']) {
		$buttonTitle = $template['InstallPath'] ? tr("Reinstall") : tr("Install");
		/* Build install URL flags additively (deprecated + incompatible can both
		   apply at once). Previous form overwrote one with the other and had
		   the compatibility polarity inverted on top. */
		$installFlags = "";
		if ( ! empty($template['Deprecated']) )      $installFlags .= "&deprecated";
		if ( empty($template['Compatible']) )        $installFlags .= "&incompatible";

		$updateFlag = false;
		$requiresText = "";
		if ($template['RequiresFile'] && ! is_file($template['RequiresFile'])) {
			$requiresText = "AnythingHere";
			$updateFlag = true;
		}
		/* Reinstalls (existing $InstallPath) always allowed — that's how the
		   user removes a deprecated/incompatible plugin they already have.
		   Fresh installs respect the hideIncompatible / hideDeprecated user
		   settings: if either is on AND the template is incompatible /
		   deprecated, no install button. */
		$canInstall = ! ($template['UninstallOnly'] ?? false) && (
			! empty($template['InstallPath'])
			|| (
				( ($template['Compatible'] ?? false) || (($GLOBALS['caSettings']['hideIncompatible'] ?? "true") !== "true") )
				&& ( ! ($template['Deprecated'] ?? false) || (($GLOBALS['caSettings']['hideDeprecated'] ?? "true") !== "true") )
			)
		);
		if ($canInstall) {
			/* json_encode each string arg — same defense as the sidebar Install
			   emit above. PluginURL is feed-controlled, so the build-site safety
			   matters here even though skin.php's onclick emit also escapes. */
			$jsFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
			$installUrlJs    = json_encode($template['PluginURL'] . $installFlags, $jsFlags);
			$updateFlagJs    = json_encode((bool)$updateFlag, $jsFlags);
			$requiresTextJs  = json_encode($requiresText, $jsFlags);
			$actionsContext[] = ["icon" => "ca_fa-install", "text" => $buttonTitle, "action" => "installPlugin({$installUrlJs},{$updateFlagJs},{$requiresTextJs});"];
		}
		if ($template['InstallPath']) {
			if (! empty($actionsContext)) {
				$actionsContext[] = ["divider" => true];
			}
			$actionsContext[] = ["icon" => "ca_fa-delete", "text" => tr("Remove"), "action" => "removeApp('{$template['InstallPath']}','$pluginName');"];
		}
	}

	if (file_exists(CA_PATHS['pluginPending'].$pluginName)) {
		$actionsContext = [];
		$actionsContext[] = ["text" => tr("Pending")];
	}

	return [$template, $actionsContext];
}

/**
 * Add install/update/switch actions for language-pack templates.
 *
 * @param array<string,mixed> $template
 * @param array<int,array<string,mixed>> $actionsContext
 * @return array{0: array<string,mixed>, 1: array<int,array<string,mixed>>}
 */
function caProcessLanguageTemplate(array $template, array $actionsContext): array {
	$countryCode = $template['LanguageDefault'] ? "en_US" : $template['LanguagePack'];
	$dynamixSettings = @parse_ini_file(CA_PATHS['dynamixSettings'], true);
	$currentLanguage = $dynamixSettings['display']['locale'] ?? "en_US";
	$installedLanguages = array_diff(scandir("/usr/local/emhttp/languages"), [".", ".."]);
	$installedLanguages = array_filter($installedLanguages, function ($v) {
		return is_dir("/usr/local/emhttp/languages/$v");
	});
	$installedLanguages[] = "en_US";
	$currentLanguage = (is_dir("/usr/local/emhttp/languages/$currentLanguage")) ? $currentLanguage : "en_US";

	if (in_array($countryCode, $installedLanguages)) {
		if ($currentLanguage != $countryCode) {
			$actionsContext[] = ["icon" => "ca_fa-switchto", "text" => $template['SwitchLanguage'], "action" => "CAswitchLanguage('$countryCode');"];
		}
	} else {
		$actionsContext[] = ["icon" => "ca_fa-install", "text" => tr("Install"), "action" => "installLanguage('{$template['TemplateURL']}','$countryCode');"];
	}

	if (file_exists("/var/log/plugins/lang-$countryCode.xml")) {
		$template['Installed'] = true;
		if (languageCheck($template)) {
			$template['UpdateAvailable'] = true;
			$actionsContext[] = ["icon" => "ca_fa-update", "text" => $template['UpdateLanguage'], "action" => "updateLanguage('$countryCode');"];
		}
		if ($currentLanguage != $countryCode) {
			if (! empty($actionsContext)) {
				$actionsContext[] = ["divider" => true];
			}
			$actionsContext[] = ["icon" => "ca_fa-delete", "text" => tr("Uninstall"), "action" => "removeLanguage('$countryCode');"];
		}
	}

	if (file_exists(CA_PATHS['pluginPending'].$template['LanguagePack']) || file_exists(CA_PATHS['pluginPending']."lang-{$template['LanguagePack']}.xml")) {
		$actionsContext = [];
		$actionsContext[] = ["text" => tr("Pending")];
	}

	$template['Installed'] = is_dir(CA_PATHS['languageInstalled']."{$template['LanguagePack']}") && ! $template['Uninstall'];

	return [$template, $actionsContext];
}

/**
 * Paginator markup for app lists or Docker Hub search results.
 *
 * @param int $pageNumber
 * @param int $totalApps
 * @param bool $dockerSearch When true, uses dockerSearch page size and handler
 * @param bool $displayCount
 * @return string HTML
 */
function getPageNavigation($pageNumber,$totalApps,$dockerSearch,$displayCount = true) {
	if ( $dockerSearch ) {
		$GLOBALS['caSettings']['maxPerPage'] = 25;
	}

	if ( $GLOBALS['caSettings']['maxPerPage'] < 0 ) {
		return;
	}

	$maxPerPage = max(1, (int)$GLOBALS['caSettings']['maxPerPage']);

	$navigationData = [
		'pageNumber' => (int)$pageNumber,
		'totalApps' => (int)$totalApps,
		'maxPerPage' => (int)$maxPerPage,
		'dockerSearch' => (bool)$dockerSearch,
	];
	$jsonNavigationData = json_encode($navigationData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

	return "<script>caRenderPageNavigation($jsonNavigationData);</script>";
}


/**
 * Summarize repository statistics (counts/downloads) for repo popups.
 *
 * Reads `$GLOBALS['caSettings']` (hideDeprecated/hideIncompatible) for filter behaviour.
 *
 * @param  array<int,array<string,mixed>> $templates  Full template listing.
 * @param  string                         $repository Repository name to match against `RepoName`.
 * @return array{apps:int,languages:int,plugins:int,docker:int,downloads:int,downloadDockerCount:int,avgDownloads:int}
 */
function caSummarizeRepositoryTemplates(array $templates, string $repository): array {
	$totals = [
		'apps' => 0,
		'languages' => 0,
		'plugins' => 0,
		'docker' => 0,
		'downloads' => 0,
		'downloadDockerCount' => 0,
		'avgDownloads' => 0,
	];

	foreach ($templates as $template) {
		if (($template['RepoName'] ?? null) !== $repository) {
			continue;
		}
		if (isset($template['BranchID'])) {
			continue;
		}
		if (!empty($template['Blacklist'])) {
			continue;
		}
		if (!empty($template['Deprecated']) && (($GLOBALS['caSettings']['hideDeprecated'] ?? "false") !== "false")) {
			continue;
		}
		if (empty($template['Compatible']) && (($GLOBALS['caSettings']['hideIncompatible'] ?? "false") !== "false")) {
			continue;
		}

		if (!empty($template['Registry'])) {
			$totals['docker']++;
			if (!empty($template['downloads'])) {
				$totals['downloads'] += $template['downloads'];
				$totals['downloadDockerCount']++;
			}
		}

		if (!empty($template['PluginURL'])) {
			$totals['plugins']++;
		}

		if (!empty($template['Language'])) {
			$totals['languages']++;
		}

		$totals['apps']++;
	}

	if ($totals['downloadDockerCount'] && $totals['downloads']) {
		$totals['avgDownloads'] = intval($totals['downloads'] / $totals['downloadDockerCount']);
	}

	return $totals;
}

/**
 * Build the donation section for repository popups.
 *
 * @param  array<string,mixed> $repo Repository metadata containing DonateLink/DonateText.
 * @return string HTML markup, or empty string if no donate link.
 */
function caBuildRepoDonationSection(array $repo): string {
	$donateLink = $repo['DonateLink'] ?? "";
	if (empty($donateLink) || !validURL($donateLink)) {
		return "";
	}

	$donateText = $repo['DonateText'] ?? "";
	$donateLabel = tr("Donate");
	$safeDonate = htmlspecialchars($donateLink, ENT_QUOTES);

	return "
			<div class='donateArea'>
				<div class='repoDonateText'>{$donateText}</div>
				<a class='caButton donate' href='{$safeDonate}' target='_blank' rel='noopener noreferrer'>$donateLabel</a>
			</div>
	";
}

/**
 * Build the media (photos/videos) section for repository popups.
 *
 * Resolves YouTube thumbnails via getYoutubeThumbnail() for video tiles.
 *
 * @param  array<string,mixed> $repo Repository metadata containing Photo/Video entries.
 * @return string HTML media gallery markup, or empty string if no media.
 */
function caBuildRepoMediaSection(array $repo): string {
	$hasPhoto = !empty($repo['Photo']);
	$hasVideo = !empty($repo['Video']);

	if (! $hasPhoto && ! $hasVideo) {
		return "";
	}

	$mediaHtml = "<div class='caMediaGallery'>";

	if ($hasPhoto) {
		$photos = is_array($repo['Photo']) ? $repo['Photo'] : [$repo['Photo']];
		foreach ($photos as $shot) {
			$shot = trim($shot);
			/* caIsPublicHttpUrl, not validURL — these images auto-fetch on
			   render, same threat surface as the popup gallery in skin.php. */
			if ($shot === "" || !caIsPublicHttpUrl($shot)) {
				continue;
			}
			$safeShot = htmlspecialchars($shot, ENT_QUOTES);
			/* span (not <a>) so the legacy external-link click handler doesn't
			   intercept this — magnific reads data-mfp-src for the source. */
			$mediaHtml .= "<span class='screenshot mfp-image' data-mfp-src='{$safeShot}'><img class='screen' src='{$safeShot}' referrerpolicy='no-referrer' onerror='this.style.display=&quot;none&quot;'></img></span>";
		}
	}

	if ($hasVideo) {
		$videos = is_array($repo['Video']) ? $repo['Video'] : [$repo['Video']];
		foreach ($videos as $vid) {
			$vid = trim($vid);
			if ($vid === "" || !caIsPublicHttpUrl($vid)) {
				continue;
			}
			$thumbnail = trim((string)getYoutubeThumbnail($vid));
			/* Gate the thumbnail too — derived from a feed-supplied video URL,
			   so a hostile maintainer could otherwise smuggle a LAN host through
			   the thumbnail src even though the video URL passed the public check. */
			if ($thumbnail === "" || !caIsPublicHttpUrl($thumbnail)) {
				continue;
			}
			$safeVid = htmlspecialchars($vid, ENT_QUOTES);
			$safeThumb = htmlspecialchars($thumbnail, ENT_QUOTES);
			$mediaHtml .= "<span class='screenshot mfp-iframe videoPlayOverlay' data-mfp-src='{$safeVid}' style='position: relative; display: inline-block;'><img class='screen' src='{$safeThumb}' referrerpolicy='no-referrer'></span>";
		}
	}

	$mediaHtml .= "</div>";

	return $mediaHtml;
}

/**
 * Build social/project link buttons for repository popups.
 *
 * @param  array<string,mixed> $repo Repository metadata containing WebPage/Forum/Facebook/etc.
 * @return string HTML markup containing one anchor per valid social link.
 */
function caBuildRepoLinkSection(array $repo): string {
	$definitions = [
		'WebPage' => ['class' => 'ca_webpage', 'label' => "Web Page"],
		'Forum' => ['class' => 'ca_forum', 'label' => "Forum"],
		'profile' => ['class' => 'ca_profile', 'label' => "Forum Profile"],
		'Facebook' => ['class' => 'ca_facebook', 'label' => "Facebook"],
		'Reddit' => ['class' => 'ca_reddit', 'label' => "Reddit"],
		'Twitter' => ['class' => 'ca_twitter', 'label' => "Twitter"],
		'Discord' => ['class' => 'ca_discord_popup', 'label' => "Discord"],
	];

	$links = "";
	foreach ($definitions as $key => $definition) {
		if (empty($repo[$key]) || !validURL($repo[$key])) {
			continue;
		}
		$label = tr($definition['label']);
		$safeUrl = htmlspecialchars($repo[$key], ENT_QUOTES);
		$links .= "<a class='caButton {$definition['class']}' href='{$safeUrl}' target='_blank' rel='noopener noreferrer'> {$label}</a>";
	}

	return "<div class='repoLinkArea'>{$links}</div>";
}

/**
 * Build the statistics table shown within repository popups.
 *
 * @param  array<string,mixed> $repo   Repository metadata (FirstSeen optional).
 * @param  array<string,int>   $totals Totals from caSummarizeRepositoryTemplates().
 * @return string HTML statistics table.
 */
function caBuildRepoStatsSection(array $repo, array $totals): string {
	$rows = [];

	if (($repo['FirstSeen'] ?? 0) > 1) {
		$rows[] = "<tr><td class='repoLeft'>".tr("Added to CA")."</td><td class='repoRight'>".date("F j, Y", $repo['FirstSeen'])."</td></tr>";
	}

	$rows[] = "<tr><td class='repoLeft'>".tr("Total Docker Applications")."</td><td class='repoRight'>{$totals['docker']}</td></tr>";
	$rows[] = "<tr><td class='repoLeft'>".tr("Total Plugin Applications")."</td><td class='repoRight'>{$totals['plugins']}</td></tr>";

	if (array_key_exists('languages', $totals)) {
		$rows[] = "<tr><td class='repoLeft'>".tr("Total Languages")."</td><td class='repoRight'>{$totals['languages']}</td></tr>";
	}

	$rows[] = "<tr><td class='repoLeft'>".tr("Total Applications")."</td><td class='repoRight'>{$totals['apps']}</td></tr>";

	if ($totals['downloadDockerCount'] && $totals['downloads']) {
		$rows[] = "<tr><td class='repoLeft'>".tr("Total Known Downloads")."</td><td class='repoRight'>".number_format($totals['downloads'])."</td></tr>";
		$rows[] = "<tr><td class='repoLeft'>".tr("Average Downloads Per App")."</td><td class='repoRight'>".number_format($totals['avgDownloads'])."</td></tr>";
	}

	$rowsHtml = implode("", $rows);

	return "
		<div class='repoStats'>".tr("Statistics")."</div>
			<table class='repoTable'>
				{$rowsHtml}
			</table>
	";
}

/**
 * Render pagination controls for Docker Hub search results.
 *
 * @param  int $num_pages  Total number of Docker Hub result pages (25 per page).
 * @param  int $pageNumber Current 1-based page index.
 * @return string Pagination `<script>` markup (from getPageNavigation).
 */
function dockerNavigate($num_pages, $pageNumber) {
	return getPageNavigation($pageNumber,$num_pages * 25, true);
}

/**
 * Attempt to find a template matching a repository name (with :latest fallback).
 *
 * @param  array<int,array<string,mixed>> $templates  Full template listing.
 * @param  string                         $repository Repository name to match.
 * @return int|false Matching index, or false if not found.
 */
function findTemplateMatch(array $templates, string $repository) {
	$templateIndex = searchArray($templates, "Repository", $repository);

	if ($templateIndex === false) {
		$templateIndex = searchArray($templates, "Repository", "{$repository}:latest");
	}

	return $templateIndex;
}

/**
 * Enrich a Docker Hub search result with CA metadata/actions.
 *
 * Merges in icon/description/actions from a matching CA template (when present),
 * and base64-encodes the description so click-through install can survive cache eviction.
 *
 * @param  array<string,mixed>             $result           Raw Docker Hub search hit.
 * @param  array<int,array<string,mixed>>  $templates        Full CA template listing for cross-reference.
 * @param  bool                            $installsDisabled When true, omit the install action.
 * @return array<string,mixed> Enriched result ready for displayCard().
 */
function buildDockerHubResult(array $result, array $templates, bool $installsDisabled): array {
	$result['Icon'] = $result['Icon'] ?? "/plugins/dynamix.docker.manager/images/question.png";
	$result['Category'] = $result['Category'] ?? "Docker&nbsp;Hub&nbsp;Search";
	$result['Description'] = $result['Description'] ?: tr("No description present");
	$result['Compatible'] = true;
	$result['display_dockerName'] = "<a class='ca_applicationName ellipsis' style='cursor:pointer;' onclick='mySearch(this.innerText);' title='".tr("Search for similar containers")."'>{$result['Name']}</a>";
	$result['similarSearch'] = $result['Name'];

	if ($installsDisabled) {
		unset($result['actionsContext']);
	} else {
		/* Pass the Repository (e.g. "library/nginx" or "user/repo") rather than
		   the per-page integer ID — IDs reset 0..24 on every paged search,
		   so a stale page in the cache combined with the user clicking an
		   older result would install the wrong container. The card's
		   Description is base64-encoded so the click handler can pass it
		   through to the install path even when the server-side cache no
		   longer contains this Repository (e.g. user paged past). */
		$safeRepo = addslashes((string)$result['Repository']);
		$descB64  = base64_encode((string)($result['Description'] ?? ""));
		$result['actionsContext'] = [["icon" => "ca_fa-install", "text" => tr("Install"), "action" => "dockerConvert('{$safeRepo}','{$descB64}');"]];
	}

	$templateIndex = findTemplateMatch($templates, $result['Repository']);

	if ($templateIndex !== false && ! $templates[$templateIndex]['Deprecated'] && ! $templates[$templateIndex]['Blacklist']) {
		$result['caTemplateExists'] = true;
		$result['Icon'] = $templates[$templateIndex]['Icon'];
		$result['Description'] = $templates[$templateIndex]['Overview'] ?: $templates[$templateIndex]['Description'];
		unset($result['IconFA']);
		$result['ID'] = $templates[$templateIndex]['ID'];
		$result['actionsContext'] = [["icon" => "ca_fa-template", "text" => tr("Show Template"), "action" => "doSearch(false,'{$templates[$templateIndex]['Repository']}');"]];
	}

	return $result;
}

/**
 * Resolve the CA app type class/title for a template card.
 *
 * @param  array<string,mixed> $template Template entry.
 * @return array{0:string,1:string} Tuple of [CSS class name, hover title].
 */
function caResolveAppType(array $template): array {
	$repositoryTemplate = !empty($template['RepositoryTemplate']);
	$category = $template['Category'] ?? "";

	if ($repositoryTemplate) {
		$appType = "appRepository";
	} else {
		$appType = !empty($template['Plugin']) ? "appPlugin" : "appDocker";

		if (!empty($template['Language'])) {
			$appType = "appLanguage";
		} elseif (!empty($template['Plugin']) && strpos($category, "Drivers") !== false) {
			$appType = "appDriver";
		}
	}

	switch ($appType) {
		case "appPlugin":
			$typeTitle = tr("This application is a plugin");
			break;
		case "appDocker":
			$typeTitle = tr("This application is a docker container");
			break;
		case "appLanguage":
			$typeTitle = tr("This is a language pack");
			break;
		case "appDriver":
			$typeTitle = tr("This application is a driver (plugin)");
			break;
		default:
			$typeTitle = "";
			break;
	}

	return [$appType, $typeTitle];
}

/**
 * Normalize category labels used in cards (strip additional metadata).
 *
 * Keeps only the first whitespace- and colon-separated token (e.g. "Network:Proxy" -> "Network").
 *
 * @param  ?string $category Raw category string.
 * @return string Normalized leading token, or empty string.
 */
function caNormalizeCategory(?string $category): string {
	if (!$category) {
		return "";
	}

	$category = explode(" ", $category)[0] ?? "";
	$category = explode(":", $category)[0] ?? "";

	return $category;
}

/**
 * Determine the author/maintainer label for a template card.
 *
 * @param  array<string,mixed> $template Template entry.
 * @param  string              $repoName Default repository name to fall back to.
 * @return string Rendered author/maintainer label.
 */
function caResolveAuthor(array $template, string $repoName): string {
	if (!empty($template['DockerHub'])) {
		return $template['Author'] ?? "";
	}

	if (!empty($template['Plugin'])) {
		$author = $template['Author'] ?? "";
	} else {
		$author = $template['RepoShort'] ?? $repoName;
	}

	$author = $author ?? "";

	if ($author === $repoName) {
		if (strpos($author, "' Repository") !== false) {
			$author = sprintf(tr("%s's Repository"), str_replace("' Repository", "", $author));
		} elseif (strpos($author, "'s Repository") !== false) {
			$author = sprintf(tr("%s's Repository"), str_replace("'s Repository", "", $author));
		} elseif (strpos($author, " Repository") !== false) {
			$author = sprintf(tr("%s Repository"), str_replace(" Repository", "", $author));
		}
	}

	return $author;
}

/**
 * Build an inline ReadMe section placeholder for GitHub README links.
 *
 * Parses the URL and only emits a section for github.com URLs that point at a repo root,
 * a README fragment, or a README.md file. The actual content is fetched lazily client-side.
 *
 * @param  array<string,mixed> $template Template entry (uses `ReadMe` field).
 * @return string HTML markup, or empty string if URL is missing/invalid.
 */
function caBuildReadmeSectionDiv(array $template): string {
	$readmeUrl = trim($template['ReadMe'] ?? "");
	if ($readmeUrl === "") {
		return "";
	}

	$urlParts = @parse_url($readmeUrl);
	if (!is_array($urlParts)) {
		return "";
	}

	$host = strtolower($urlParts['host'] ?? "");
	$fragment = $urlParts['fragment'] ?? "";
	$path = $urlParts['path'] ?? "";
	$pathParts = array_values(array_filter(explode("/", trim($path, "/"))));
	$hasReadmeFragment = (strcasecmp($fragment, "README") === 0);
	$hasReadmeFile = preg_match("/\/README\.md$/i", $path);
	$isRepoRootPath = (count($pathParts) === 2);

	if (!in_array($host, ["github.com", "www.github.com"], true) || (!$hasReadmeFragment && !$hasReadmeFile && !$isRepoRootPath)) {
		return "";
	}

	$org = $pathParts[0] ?? "";
	$repo = $pathParts[1] ?? "";
	if ($org === "" || $repo === "") {
		return "";
	}

	/* Cache-buster: bumps every time the appfeed is refreshed (templates.json
	   is rewritten by DownloadApplicationFeed). Between refreshes the URL is
	   stable so the browser's HTTP cache serves repeat sidebar opens for free;
	   when the feed refreshes, the URL changes and a fresh README is fetched.
	   Uses templates.json rather than templates_full.json — the full file can
	   still be downloading in the background when this renders, mtime would
	   be missing and the cache-buster would silently be empty.
	   Cached statically so we don't filemtime() once per template in a list view. */
	static $readmeCacheBust = null;
	if ($readmeCacheBust === null) {
		$mtime = @filemtime(CA_PATHS['community-templates-info']);
		$readmeCacheBust = $mtime ? ("?v=" . (int)$mtime) : "";
	}
	$rawMainUrl = "https://raw.githubusercontent.com/{$org}/{$repo}/main/README.md{$readmeCacheBust}";
	$rawMasterUrl = "https://raw.githubusercontent.com/{$org}/{$repo}/master/README.md{$readmeCacheBust}";
	// Stable cache key derived from the template-provided README URL (avoids re-trying main/master on repeat views).
	$cacheKey = hash("sha256", $readmeUrl);
	$readmeId = "ca_readme_" . $cacheKey;
	$safeReadmeUrl = htmlspecialchars($readmeUrl, ENT_QUOTES);
	$safeRawMainUrl = htmlspecialchars($rawMainUrl, ENT_QUOTES);
	$safeRawMasterUrl = htmlspecialchars($rawMasterUrl, ENT_QUOTES);
	$safeRepoName = htmlspecialchars((string)($template['RepoName'] ?? ""), ENT_QUOTES);
	/* Loading placeholder rendered inside .ca_readme_body. JS replaces the
	   whole inner DOM via .html(text) once the postNoSpin call completes, so
	   it self-evicts on first paint and we don't have to hide it. */
	$loadingReadme =
		"<div class='ca_center caLogoIcon'></div>".
		"<div class='ca_center ca_italic'>".tr("Loading README...")."</div>";
	return "<div id='{$readmeId}' class='ReadmeSection popupDescription popup_readmore {$readmeId}' data-readme-id='{$readmeId}' data-readme-url='{$safeRawMainUrl}' data-readme-url-fallback='{$safeRawMasterUrl}' data-readme-repo='{$safeRepoName}'><div class='ReadmeSectionLabel ca_bold'><a class='popUpLink' href='{$safeReadmeUrl}' target='_blank' rel='noopener noreferrer'>".tr("View README on Web")."</a></div><div class='ca_readme_body'>{$loadingReadme}</div></div>";
}

/**
 * Build repository card overrides/context when rendering repo entries.
 *
 * Produces field-clearing overrides so repo cards don't inherit per-app metadata.
 *
 * @param  array<string,mixed> $template Template entry.
 * @param  string              $repoName Repository name.
 * @param  string              $author   Pre-resolved author label.
 * @return array{holderClass:string,cardClass:string,id:string,actionsContext:array<int,mixed>,name:string,author:string,overrides:array<string,mixed>}
 */
function caBuildRepositoryContext(array $template, string $repoName, string $author): array {
	/* No supportContext built here — repo cards don't render support buttons.
	   The sidebar's own caBuildSupportContext() handles that on click. */

	$name = str_replace(["' Repository", "'s Repository", " Repository"], "", html_entity_decode($author, ENT_QUOTES));
	$name = str_replace(["&apos;s", "'s"], "", $name);

	$fieldsToClear = [
		"Path",
		"author",
		"Repository",
		"Plugin",
		"IconFA",
		"ModeratorComment",
		"RecommendedDate",
		"UpdateAvailable",
		"Blacklist",
		"Official",
		"Trusted",
		"Pinned",
		"Deprecated",
		"Removable",
		"CAComment",
		"Installed",
		"Uninstalled",
		"Uninstall",
		"fav",
		"Beta",
		"Requires",
		"caTemplateExists",
		"actionCentre",
		"Overview",
		"imageNoClick"
	];

	$overrides = array_fill_keys($fieldsToClear, "");
	$overrides['Name'] = $name;

	return [
		"holderClass" => "repositoryCard",
		"cardClass" => "ca_repoinfo",
		"id" => str_replace(" ", "", $repoName),
		"actionsContext" => [],
		"name" => $name,
		"author" => "",
		"overrides" => $overrides
	];
}

/**
 * Build the base card container, actions, and navigation footer markup.
 *
 * @param  array<string,mixed> $template   Template entry.
 * @param  string              $cardClass  Inner card CSS class.
 * @param  ?string             $popupType  Popup CSS hook class, or null for DockerHub cards.
 * @param  string              $holderClass Extra holder CSS class.
 * @param  string              $class      Base card CSS class.
 * @param  string              $name       App name (for data-appname).
 * @param  string              $repoName   Repository name (for data-repository).
 * @return array{0:string,1:string,2:string} Tuple of [cardStart HTML, card HTML, backgroundClickable class].
 */
function caBuildBottomLineSection(
	array $template,
	string $cardClass,
	?string $popupType,
	string $holderClass,
	string $class,
	string $name,
	string $repoName
): array {
	$bottomClass = "ca_bottomLineSpotLight";
	$card = "";

	/* Favourite + pinned indicators sit inline right after the Details/Docker
	   Hub button — same row as the rest of the card buttons so they inherit
	   the surrounding font size. Both spans always render; their Show/Hide
	   classes (and pinApp()'s .toggle()) flip visibility without re-rendering. */
	$favSpan    = caRenderFavouriteSpan($template, $repoName, !empty($template['RepositoryTemplate']));
	$pinnedSpan = caRenderPinnedSpan($template);

	if (!empty($template['DockerHub']) && validURL($template['DockerHub'])) {
		$backgroundClickable = "dockerCardBackground";
		$safeDockerHub = htmlspecialchars($template['DockerHub'], ENT_QUOTES);
		$cardStart = "
			<div class='ca_holder ca_dockerTemplate {$popupType}'>";
		$card .= "
			<div class='ca_bottomLine {$bottomClass}'>
			<div class='caButton infoButton_docker ca_href' data-href='{$safeDockerHub}'>".tr("Docker Hub")."</div>
			{$favSpan}{$pinnedSpan}
			<div class='caButton actionsButton similarSearch' data-search='".($template['similarSearch'] ?? "")."'>".tr("Similar")."</div>";
	} else {
		$backgroundClickable = "ca_backgroundClickable";
		$dataPluginURL = empty($template['PluginURL']) ? "" : "data-pluginurl='".htmlspecialchars((string)$template['PluginURL'], ENT_QUOTES)."'";
		$cardStart = "
			<div class='ca_holder {$class} {$popupType} {$holderClass}' data-apppath='".($template['Path'] ?? "")."' data-appname='{$name}' data-repository='".htmlentities($repoName, ENT_QUOTES)."' {$dataPluginURL}>";
		$card .= "
			<div class='ca_bottomLine {$bottomClass}'>
			<div class='caButton infoButton {$cardClass}'>".tr("Details")."</div>
			{$favSpan}{$pinnedSpan}
		";
	}

	return [$cardStart, $card, $backgroundClickable];
}

/**
 * Render the Actions button/menu for a template card.
 *
 * Single-action Install contexts collapse to a direct-action button.
 *
 * @param  array<int,array<string,mixed>> $actionsContext Action descriptors.
 * @param  string                         $pluginUrl      Plugin URL (data attribute).
 * @param  string                         $languagePack   Language pack code (data attribute).
 * @param  string                         $name           App name (used for sanitized DOM id).
 * @param  string                         $id             Template ID suffix for unique DOM id.
 * @return string HTML button markup, or empty string when no actions.
 */
function caRenderActionsButtons(array $actionsContext, string $pluginUrl, string $languagePack, string $name, string $id): string {
	if (empty($actionsContext)) {
		return "";
	}

	if (count($actionsContext) === 1 && ($actionsContext[0]['text'] ?? "") === tr("Install")) {
		/* htmlspecialchars + ENT_QUOTES so apostrophes inside the JS body don't
		   collide with the surrounding onclick='...' delimiters. */
		$safeAction = htmlspecialchars($actionsContext[0]['action'], ENT_QUOTES);

		return "<div class='caButton actionsButton' data-pluginURL='{$pluginUrl}' data-languagePack='{$languagePack}' onclick='{$safeAction}'>{$actionsContext[0]['text']}</div>";
	}

	$sanitizedName = preg_replace("/[^a-zA-Z0-9]+/", "", $name).$id;

	return "<div class='caButton actionsButton actionsButtonContext' data-pluginURL='{$pluginUrl}' data-languagePack='{$languagePack}' id='actions{$sanitizedName}' data-context='".json_encode($actionsContext, JSON_HEX_QUOT | JSON_HEX_APOS)."'>".tr("Actions")."</div>";
}

/**
 * Render the favourite indicator span used on template/repo cards.
 *
 * @param  array<string,mixed> $template           Template entry (uses `ca_fav`).
 * @param  string              $repoName           Repository name for the data attribute.
 * @param  bool                $repositoryTemplate True when rendering a repo card.
 * @return string HTML span markup.
 */
function caRenderFavouriteSpan(array $template, string $repoName, bool $repositoryTemplate): string {
	$repositoryAttr = str_replace("'", "", $repoName);

	if (!empty($template['ca_fav'])) {
		$favText = $repositoryTemplate ? tr("This is your favourite repository") : tr("This application is from your favourite repository");
		return "<span class='favCardBackground favCardBackgroundShow' data-repository='{$repositoryAttr}' title='".htmlentities($favText)."'></span>";
	}

	return "<span class='favCardBackground favCardBackgroundHide' data-repository='{$repositoryAttr}'></span>";
}

/**
 * Render the pinned indicator span for template cards.
 *
 * @param  array<string,mixed> $template Template entry (uses Repository/SortName/Pinned).
 * @return string HTML span markup (hidden when not pinned).
 */
function caRenderPinnedSpan(array $template): string {
	$repository = $template['Repository'] ?? "";
	$pindata = (strpos($repository, "/") !== false) ? $repository : "library/{$repository}";
	$sortName = $template['SortName'] ?? "";
	$pinStyle = !empty($template['Pinned']) ? "" : "display:none;";

	return "<span class='pinnedCard' title='".htmlentities(tr("This application is pinned for later viewing"))."' data-pindata='{$pindata}{$sortName}' style='{$pinStyle}'></span>";
}

/**
 * Resolve the multi-select checkbox type (docker/plugin/language) for a card.
 *
 * @param  string $appType App type class produced by caResolveAppType().
 * @return string Checkbox type identifier (`docker`/`plugin`/`language`) or empty string.
 */
function caResolveCheckboxType(string $appType): string {
	switch ($appType) {
		case "appDocker":
			return "docker";
		case "appPlugin":
		case "appDriver":
			return "plugin";
		case "appLanguage":
			return "language";
		default:
			return "";
	}
}

/**
 * Render the multi-select checkbox used for bulk install/update flows.
 *
 * @param  array<string,mixed> $template        Template entry.
 * @param  string              $previousAppName Identifier for previous-apps reinstall (Path/PluginURL).
 * @param  string              $name            Human-readable app name.
 * @param  string              $type            Checkbox type from caResolveCheckboxType().
 * @return string HTML input markup, or empty string if no checkbox should render.
 */
function caRenderCheckbox(array $template, string $previousAppName, string $name, string $type): string {
	$checked = $template['checked'] ?? "";

	if (!empty($template['Removable']) && empty($template['DockerInfo']) && empty($template['Installed']) && empty($template['Blacklist'])) {
		return "<input class='ca_multiselect' title='".tr("Check off to select multiple reinstalls")."' type='checkbox' data-name='{$previousAppName}' data-humanName='{$name}' data-type='{$type}' data-deletepath='".($template['InstallPath'] ?? "")."' {$checked}>";
	}

	if (!empty($template['actionCentre']) && !empty($template['UpdateAvailable'])) {
		return "<input class='ca_multiselect' title='".tr("Check off to select multiple updates")."' type='checkbox' data-name='{$previousAppName}' data-humanName='{$name}' data-type='{$type}' data-language='".($template['LanguagePack'] ?? "")."' {$checked}>";
	}

	return "";
}

/**
 * Render the icon (image/font-awesome) for a template card.
 *
 * Only emits external `Icon` URLs when they pass validURL() to prevent same-origin
 * GETs against the user's own GUI from a malicious template.
 *
 * @param  array<string,mixed> $template  Template entry.
 * @param  bool                $dockerHub True when the card is a Docker Hub search result.
 * @return string HTML icon markup (img or i element).
 */
function caBuildIconMarkup(array $template, bool $dockerHub): string {
	$imageNoClick = $dockerHub ? "noClick" : ($template['imageNoClick'] ?? "");

	if (empty($template['IconFA'])) {
		/* Same protection as the popup icon path: caIsPublicHttpUrl rejects
		   not just non-http(s) but also RFC1918, link-local, mDNS .local, etc.
		   Icons auto-load when the card renders, so a malicious template URL
		   pointing at a LAN device would otherwise CSRF on every page paint. */
		$iconCandidate = (string)($template['Icon'] ?? "");
		$safeIcon = caIsPublicHttpUrl($iconCandidate) ? $iconCandidate : "/plugins/dynamix.docker.manager/images/question.png";
		$safeIconAttr = htmlspecialchars($safeIcon, ENT_QUOTES);
		return "
			<img class='ca_displayIcon {$imageNoClick}' src='{$safeIconAttr}' alt='Application Icon' referrerpolicy='no-referrer'></img>
		";
	}

	$displayIcon = $template['IconFA'] ?: ($template['Icon'] ?? "");
	$displayIconClass = startsWith($displayIcon, "icon-") ? $displayIcon : "fa fa-{$displayIcon}";

	return "<i class='ca_appPopup {$displayIconClass} displayIcon {$imageNoClick}'></i>";
}

/**
 * Build the header section (name/author/category) for a template card.
 *
 * Promotes LTOfficial / Official templates to a canonical author label.
 *
 * @param  array<string,mixed> $template Template entry (uses LTOfficial/Official).
 * @param  string              $name     App name.
 * @param  string              $author   Pre-resolved author label.
 * @param  string              $category Category label.
 * @return string HTML header markup.
 */
function caBuildApplicationHeader(array $template, string $name, string $author, string $category): string {
	$header = "
		<div class='ca_applicationName ellipsis'>{$name}
	";

	/* Author label tier:
	   - LTOfficial   = LimeTech-official plugin OR language pack    → "Unraid Official"
	   - Official     = Docker-Hub-official image (library/* repo)   → "Official Container"
	   - otherwise    = whatever caResolveAuthor() produced */
	if (!empty($template['LTOfficial'])) {
		$authorDisplay = tr("Unraid Official");
	} elseif (!empty($template['Official'])) {
		$authorDisplay = tr("Official Container");
	} else {
		$authorDisplay = $author;
	}

	$header .= "
				</div>
				<div class='ca_author ellipsis'>{$authorDisplay}</div>
				<div class='cardCategory'>{$category}</div>
	";

	return $header;
}

/**
 * Normalize overview/description copy for display in template cards.
 *
 * Runs the overview through markdown, strips tags, and prepends an "incompatible"
 * banner when applicable for featured but incompatible/uninstall-only templates.
 *
 * @param  array<string,mixed> $template Template entry.
 * @param  string              $name     App name (used in incompatibility banners).
 * @return string Plain-text overview ready for inclusion in a card.
 */
function caNormalizeOverview(array $template, string $name): string {
	$overview = $template['Overview'] ?: ($template['Description'] ?: ($template['Bio']??"") ?: "");

	if (! $overview) {
		$overview = tr("No description present");
	}

	$normalized = html_entity_decode($overview);
	$normalized = trim($normalized);
	$normalized = str_replace(["[", "]"], ["<", ">"], $normalized);
	$normalized = str_replace("\n", "<br>", $normalized);
	$normalized = markdown(strip_tags($normalized, "<br>"));
	$normalized = str_replace("\n", "<br>", $normalized);
	$overview = strip_tags(str_replace("<br>", " ", $normalized));

	$featured = $template['Featured'] ?? null;
	$uninstallOnly = $template['UninstallOnly'] ?? false;

	if ($uninstallOnly && $featured && is_file("/var/log/plugins/".basename($template['PluginURL'] ?? ""))) {
		$overview = "<span class='featuredIncompatible'>".sprintf(tr("%s is incompatible with your OS version.  Either uninstall %s or update the OS"), $name, $name)."</span>&nbsp;&nbsp;{$overview}";
	} elseif ( ((!($template['Compatible'] ?? null)) || $uninstallOnly) && $featured) {
		$overview = "<span class='featuredIncompatible'>".sprintf(tr("%s is incompatible with your OS version.  Please update the OS to proceed"), $name)."</span>&nbsp;&nbsp;{$overview}";
	}

	return $overview;
}

/**
 * Collect all applicable status badges for a template, in priority order.
 *
 * Shared helper backing both the card corner-badge stack and the sidebar
 * header badge row — same supersession rules, same DOM order, different
 * wrapper / positioning. Callers wrap the returned strings in whatever
 * container suits their layout (`.cardFlagStack` for cards,
 * `.sidebarFlagStack` for the sidebar).
 *
 * Priority order: UpdateAvailable, Installed, (Blacklist OR Deprecated),
 * caTemplateExists, Incompatible, (LTOfficial OR Official), Beta, Trusted.
 * Supersession pairs (`OR`) emit at most one badge each — Blacklist hides
 * Deprecated, LTOfficial hides Official (LT-branded variant trumps plain).
 *
 * @param  array<string,mixed> $template Template entry.
 * @return array<int,string> Ordered list of badge `<div>` HTML strings;
 *                           empty array when no badges apply.
 */
function caCollectBadges(array $template): array {
	$badges = [];

	if (!empty($template['UpdateAvailable'])) {
		$badges[] = "<div class='betaCardBackground'><div class='installedCardText ca_center'>".tr("UPDATED")."</div></div>";
	}

	if ((!empty($template['Installed']) || !empty($template['Uninstall'])) && empty($template['actionCentre'])) {
		$badges[] = "<div class='installedCardBackground'><div class='installedCardText ca_center'>".tr("INSTALLED")."</div></div>";
	}

	/* Blacklist supersedes Deprecated — Blacklisted is the stronger
	   moderator action (active removal-worthy) and Deprecated is the
	   weaker "no longer maintained" signal. A Blacklisted app is
	   implicitly past the point of "just deprecated", so showing both
	   chips is noise. Incompatible can co-occur with either since it's
	   an OS-version mismatch, not a maintainer judgement. */
	if (!empty($template['Blacklist'])) {
		$badges[] = "<div class='warningCardBackground'><div class='installedCardText ca_center' title='".tr("This application template has been blacklisted")."'>".tr("Blacklisted")."</div></div>";
	} elseif (!empty($template['Deprecated'])) {
		$badges[] = "<div class='warningCardBackground'><div class='installedCardText ca_center' title='".tr("This application template has been deprecated")."'>".tr("Deprecated")."</div></div>";
	}

	if (!empty($template['caTemplateExists'])) {
		$badges[] = "<div class='greenCardBackground'><div class='installedCardText ca_center' title='".tr("Template already exists in Apps")."'>".tr("Template")."</div></div>";
	}

	if (isset($template['Compatible']) && ! $template['Compatible']) {
		$verMsg = $template['VerMessage'] ?? tr("This application is not compatible with your version of Unraid");
		$badges[] = "<div class='warningCardBackground'><div class='installedCardText ca_center' title='{$verMsg}'>".tr("Incompatible")."</div></div>";
	}

	/* LTOfficial supersedes Official — the LT (Lime Technology) variant
	   carries the brand gradient and is a stronger claim than the plain
	   "Official" purple chip. Showing both would just be redundant
	   "OFFICIAL OFFICIAL" with no useful distinction. */
	if (!empty($template['LTOfficial'])) {
		$badges[] = "<div class='LTOfficialCardBackground'><div class='installedCardText ca_center' title='".tr("This is an official plugin")."'>".tr("OFFICIAL")."</div></div>";
	} elseif (!empty($template['Official'])) {
		$badges[] = "<div class='officialCardBackground'><div class='installedCardText ca_center' title='".tr("This is an official container")."'>".tr("OFFICIAL")."</div></div>";
	}

	if (!empty($template['Beta'])) {
		$badges[] = "<div class='betaCardBackground'><div class='installedCardText ca_center'>".tr("BETA")."</div></div>";
	}

	if (!empty($template['Trusted'])) {
		$badges[] = "<div class='spotlightCardBackground'><div class='installedCardText ca_center' title='".tr("This container is digitally signed")."'>".tr("Digitally Signed")."</div></div>";
	}

	return $badges;
}

/**
 * Build the corner-badge stack for a template card.
 *
 * Emits ALL applicable badges (see {@link caCollectBadges} for the rules)
 * inside `.cardFlagStack`, which positions the group top-right and flows
 * additional badges leftward via `flex-direction: row-reverse` +
 * `flex-wrap: wrap` in CSS. When the stack would overflow the card's safe
 * zone (i.e. the icon's horizontal footprint), the remaining badges wrap
 * to a new row below instead of disappearing behind the icon.
 *
 * DOM order = visual priority: first child sits in the top-right corner,
 * lower-priority badges fill leftward.
 *
 * @param  array<string,mixed> $template Template entry.
 * @return string HTML for `.cardFlagStack` wrapper + zero-or-more badges,
 *                or empty string if no badges apply.
 */
function caBuildCardFlag(array $template): string {
	$badges = caCollectBadges($template);
	if (empty($badges)) return "";

	return "<div class='cardFlagStack'>".implode("", $badges)."</div>";
}

/**
 * Build the sidebar header badge row for a template popup.
 *
 * Same badge set / supersession rules as the card corner stack
 * ({@link caCollectBadges}), but rendered as an in-flow row ABOVE the
 * sidebar's icon/name block. Sidebar is wider than a card so the full
 * width is available — single row by default, wraps to a second row if
 * the user somehow accumulates enough concurrent flags (rare in practice).
 *
 * DOM order = visual priority: badges flow left-to-right.
 *
 * @param  array<string,mixed> $template Template entry.
 * @return string HTML for `.sidebarFlagStack` wrapper + zero-or-more
 *                badges, or empty string if no badges apply.
 */
function caBuildSidebarFlag(array $template): string {
	$badges = caCollectBadges($template);
	if (empty($badges)) return "";

	return "<div class='sidebarFlagStack'>".implode("", $badges)."</div>";
}