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

############################################################################
# function #  Convert CA markup tokens into clickable sidebar search links #
/**
 * Convert CA tokenized sidebar search tokens in text into clickable sidebar search links.
 *
 * Finds tokens of the form `//term\` and replaces each occurrence with an HTML anchor
 * that invokes `doSidebarSearch(term)` when clicked. The visible anchor text and the
 * JavaScript argument are escaped for safe HTML and JS string usage respectively.
 *
 * @param mixed $text The input content to process; typically a string containing tokens.
 * @return mixed The transformed string with embedded sidebar search links, or the original
 *               input unchanged if it is not a non-empty string or no tokens are found.
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
 * Produce a cleaned, HTML-safe overview string suitable for display in a card.
 *
 * Chooses the best available source in this order: `Overview` (preferring `OriginalOverview` when present), then `OriginalDescription`, then `Description`; decodes HTML entities, converts `[`/`]` to `<`/`>`, converts newlines to `<br>`, preserves sequences of four spaces as non‑breaking spaces, runs Markdown (allowing only `<br>`), and returns the resulting string with only `<br>` tags permitted.
 *
 * @param array $template Template data; expects any of the keys `Overview`, `OriginalOverview`, `OriginalDescription`, or `Description`.
 * @return string The formatted overview HTML string (only `<br>` tags retained). 
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
 * Prepare the template's change-log presentation and store it in the template array.
 *
 * If the template is a plugin, or declares an external changelog, this function
 * does not fetch content immediately — it inserts a placeholder HTML `<div>`
 * into `$template['display_changes']` containing data attributes required for
 * lazy loading (cache key, source URL, changelog type, loaded flag).
 *
 * If the template already contains an embedded `Changes` string, that content
 * is normalized (4-space sequences to `&nbsp;`, `[`/`]` to `<`/`>`, Markdown
 * rendered) and written back to `$template['Changes']`; when non-empty the
 * same rendered HTML is stored in `$template['display_changes']`.
 *
 * Mutates:
 *  - May set `$template['display_changes']` to either a formatted HTML string
 *    or a placeholder `<div>` with data attributes:
 *      - `data-changes-id` (derived from sha256 of source URL),
 *      - `data-changes-cachekey` (sha256),
 *      - `data-changes-url` (escaped source URL),
 *      - `data-changes-type` ("plugin" or "xml"),
 *      - `data-changes-loaded='0'`.
 *  - May replace `$template['Changes']` with rendered HTML when an embedded
 *    Changes value is present.
 *
 * Parameters expected on input (used by this function):
 *  - `Plugin` (truthy to force plugin behaviour)
 *  - `PluginURL` (source URL for plugin changelog)
 *  - `Changes` (embedded changelog text)
 *  - `ChangeLogPresent` (truthy when an external XML changelog exists)
 *  - `caTemplateURL` or `TemplateURL` (source URL for external changelog)
 *
 * @param array &$template Template data structure to read from and modify.
 */
function caFormatTemplateChanges(array &$template) {
	// For plugins, always lazy-fetch from the .plg (ignore any embedded Changes field).
	if (!empty($template['Plugin'])) {
		$type = "plugin";
		$templateURL = (string)($template['PluginURL'] ?? "");
		if (!$templateURL) return;

		$cacheKey = hash("sha256", $templateURL);
		$changesId = "ca_changes_" . $cacheKey;
		$safeUrl = htmlspecialchars($templateURL, ENT_QUOTES);
		$template['display_changes'] = "<div id='{$changesId}' class='ca_template_changes {$changesId}' data-changes-id='{$changesId}' data-changes-cachekey='{$cacheKey}' data-changes-url='{$safeUrl}' data-changes-type='{$type}' data-changes-loaded='0'>".tr("Loading change log...")."</div>";
		return;
	}

	// If changes are already present in the template, format them immediately (no downloads).
	if (trim((string)($template['Changes'] ?? ""))) {
		$changes = (string)$template['Changes'];
		$changes = str_replace("    ","&nbsp;&nbsp;&nbsp;&nbsp;",$changes);
		$changes = str_replace(["[","]"],["<",">"],$changes);
		$changes = Markdown(strip_tags($changes,"<br>"));
		$template['Changes'] = $changes;
		if (trim($changes)) {
			$template['display_changes'] = trim($changes);
		}
		return;
	}

	// Otherwise, lazily fetch changes (plugin .plg or template XML) from the sidebar after render.
	$type = "";
	$templateURL = "";
	if ($template['ChangeLogPresent']) {
		$type = "xml";
		$templateURL = (string)($template['caTemplateURL'] ?: ($template['TemplateURL']??""));
	}

	if (!$templateURL) return;

	$cacheKey = hash("sha256", $templateURL);
	$changesId = "ca_changes_" . $cacheKey;
	$safeUrl = htmlspecialchars($templateURL, ENT_QUOTES);
	$template['display_changes'] = "<div id='{$changesId}' class='ca_template_changes {$changesId}' data-changes-id='{$changesId}' data-changes-cachekey='{$cacheKey}' data-changes-url='{$safeUrl}' data-changes-type='{$type}' data-changes-loaded='0'>".tr("Loading change log...")."</div>";
}

################################################################################
# Collect docker state used by popups (running containers and update metadata) #
/**
 * Initialize and return Docker runtime state used by the UI.
 *
 * @return array An array with three elements:
 *               0) associative array mapping container `Name` to container info (empty if Docker is not running);
 *               1) list of running Docker containers (empty if Docker is not running);
 *               2) docker update status array (empty if Docker is not running).
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

####################################################################################
# Locate a template entry based on an app identifier within the displayed listings #
/**
 * Locate a template entry in the displayed community list by InstallPath or Path.
 *
 * Searches the $displayed['community'] array for an entry whose `InstallPath` equals
 * $appNumber; if not found, iteratively searches for a matching `Path` (advancing a
 * start index) until a present entry is found or the search fails.
 *
 * @param array $displayed Array containing a 'community' key with template entries.
 * @param mixed $appNumber Install path or identifier to locate.
 * @return array First element is the matched template entry or `null`, second element is the numeric index of the entry or `false` if not found.
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

##########################################################################
# Determine selection status and identifiers for docker/plugin templates #
/ **
 * Resolve whether a template corresponds to a running Docker container and return the matched container or plugin identifier.
 *
 * The function may normalize the template's `Repository` by prefixing `library/` when missing.
 *
 * @param array &$template The template data (modified in-place when repository normalization is applied).
 * @param array $dockerRunning List of running containers; each entry is expected to include at least `Image` and `Name`.
 * @return array An indexed array with three elements:
 *               - (bool|null) selected: `true` when a running container matches the template, `null` otherwise.
 *               - (string|null) name: matched container `Name` when `selected` is `true`, `null` otherwise.
 *               - (string|null) pluginName: basename of `PluginURL` when the template is a plugin, `null` otherwise.
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

######################################################################
# Normalize and format the Additional Requirements field for display #
/**
 * Normalize a template's Requires field into safe, rendered HTML with sidebar search links.
 *
 * The input is trimmed, carriage returns removed, line breaks converted to `<br>`, HTML tags
 * stripped except `<br>`, Markdown rendered, and sidebar search tokens transformed into clickable links.
 *
 * @param string|null $requires The raw Requires field content (may be empty or null).
 * @return string|null The normalized HTML string with Markdown and sidebar search links applied, or the original falsy value.
 */
function caNormalizeRequiresField($requires) {
	if (!$requires) {
		return $requires;
	}

	$requires = str_replace(["\r","\n","&#xD;"],["","<br>",""],trim($requires));
	$requires = Markdown(strip_tags($requires,"<br>"));
	return caApplySidebarSearchLinks($requires);
}

##################################################################
# Build the Support button context for a template card or popup  #
/**
 * Builds an ordered list of support link context entries for a template.
 *
 * Prefers explicit support fields on the template and uses repository metadata as a fallback
 * for the Discord link when available.
 *
 * @param array $template Template data array containing optional support fields (e.g. Project, Discord, Support, Registry, caTemplateURL, TemplateURL, SupportLanguage).
 * @param array $allRepositories Repository metadata lookup used to resolve fallback values (e.g. Discord).
 * @return array An array of support link context objects; each entry is an associative array with keys `icon`, `link`, and `text`.
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
		$supportContext[] = ["icon"=>"ca_fa-support","link"=>$template['Support'],"text"=>$template['SupportLanguage'] ?: tr("Support Forum")];
	}
	if ($template['Registry']) {
		$supportContext[] = ["icon"=>"ca_fa-docker","link"=>$template['Registry'],"text"=>tr("Registry")];
	}
	if ($GLOBALS['caSettings']['dev'] == "yes") {
		$supportContext[] = ["icon"=>"ca_fa-template","link"=>$template['caTemplateURL'] ?: ($template['TemplateURL'] ?? ""), "text"=>tr("Application Template")];
	}

	return $supportContext;
}

##############################################################################
# Prepare trend data/markup for templates with download and usage statistics #
/**
	 * Prepare chart labels and download trend datasets for a template and append any chart canvases to its description.
	 *
	 * Mutates `$template` (normalizes Category and Icon, may set `display_changelogMessage`) and appends chart `<canvas>` elements to
	 * `$templateDescription` when trend/download data is present.
	 *
	 * @param array &$template Template data; trend-related keys (`trends`, `trendsDate`, `downloadtrend`, `ID`, `Category`, `Icon`, `PluginURL`, `Language`, etc.) may be read and updated.
	 * @param string &$templateDescription HTML description that will receive appended `<canvas>` elements when applicable.
	 * @return array{chartLabel: array|string, downloadLabel: array|string, down: int[], totalDown: int[]} Associative array with:
	 *   - `chartLabel`: labels for the trend chart (dates) or empty string.
	 *   - `downloadLabel`: labels for the download chart (dates) or empty string.
	 *   - `down`: relative download-segment heights computed from `downloadtrend`.
	 *   - `totalDown`: raw download totals from `downloadtrend`.
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

#########################################################################
# Resolve pinned/unpinned state for templates based on user preferences #
/**
	 * Set pinned/unpinned UI labels and CSS class on a template according to the pinned-apps map.
	 *
	 * Updates the passed `$template` (by reference) with keys `pinned`, `pinnedAlt`,
	 * `pinnedTitle`, and `pinnedClass` based on whether the composite key
	 * "`{$template['Repository']}&{$template['SortName']}`" exists and is truthy in
	 * `$pinnedApps`.
	 *
	 * @param array &$template Template associative array to modify; will receive
	 *                         `pinned`, `pinnedAlt`, `pinnedTitle`, and `pinnedClass`.
	 * @param array $pinnedApps Map of pinned apps keyed by "Repository&SortName".
	 */
function caResolvePinnedState(array &$template, array $pinnedApps) {
		if ($pinnedApps["{$template['Repository']}&{$template['SortName']}"] ?? false) {
			$template['pinned'] = tr("Unpin App");
			$template['pinnedAlt'] = tr("Pin App");
			$template['pinnedTitle'] = tr("Click to unpin this application");
			$template['pinnedClass'] = "pinned";
		} else {
			$template['pinned'] = tr("Pin App");
			$template['pinnedAlt'] = tr("Unpin App");
			$template['pinnedTitle'] = tr("Click to pin this application");
			$template['pinnedClass'] = "unpinned";
		}
	}

############################################################################
# Retrieve language pack metadata and load translation files when required #
/**
	 * Prepare and load the language pack for a language template.
	 *
	 * If the template represents a language, determines the country code (uses "en_US" when LanguageDefault is set,
	 * otherwise uses LanguagePack), ensures a cached language file exists under the temp files directory (downloading it
	 * if missing), parses the language file into the provided $language array, and returns the country code.
	 *
	 * @param array &$template Template data; expects keys `Language`, `LanguageDefault`, and `LanguagePack`.
	 * @param array &$language Output parameter that will be populated with parsed language entries (empty array for en_US or on failure).
	 * @return string|null The resolved country code (e.g., "en_US" or other locale) when the template is a language, or `null` when not a language template.
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

#######################################################################
# Build the context menu for template actions (install/update/manage) #
/**
	 * Build the action menu context for a template card and update related template flags.
	 *
	 * Generates an ordered list of action descriptors (buttons, dividers) appropriate for
	 * the template's type (Docker, plugin, language) and runtime state. Also sets
	 * template flags such as `Installed`, `UpdateAvailable`, and `installedVersion` when detected.
	 *
	 * @param array &$template The template data structure (will be mutated to set installation/update flags).
	 * @param array $info Mapping of running Docker containers indexed by container name (container metadata used for WebUI/uninstall actions).
	 * @param array $dockerUpdateStatus Mapping of repository strings (normalized to include tags/`library/`) to update status arrays.
	 * @param bool $selected True when the template matches a running/selected container; otherwise false.
	 * @param string $name Container name corresponding to the selected running container (used as key into $info).
	 * @param string $pluginName Basename of the plugin (derived from PluginURL) used for plugin-specific actions and pending checks.
	 * @return array An ordered array of action descriptors. Each descriptor is an associative array representing a button or divider (e.g. ["icon"=>..., "text"=>..., "action"=>...] or ["divider"=>true]).
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
							$actionsContext[] = ["icon"=>"ca_fa-globe","text"=>tr("Tailscale WebUI"),"action"=>"openNewWindow('{$info[$name]['TSurl']}','_blank');"];
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
							$actionsContext[] = ["icon"=>"ca_fa-install","text"=>tr("Install second instance"),"action"=>"displayTags('{$template['ID']}',true,'".$template['PortsUsed']."');"];
						} else {
							$actionsContext[] = ["icon"=>"ca_fa-install","text"=>tr("Install second instance"),"action"=>"popupInstallXML('".addslashes($template['Path'])."','second','".$template['PortsUsed']."');"];
						}
					}
					if (is_file($info[$name]['template'])) {
						$actionsContext[] = ["icon"=>"ca_fa-edit","text"=>tr("Edit"),"action"=>"popupInstallXML('".addslashes($info[$name]['template'])."','edit');"];
					}
					$actionsContext[] = ["divider"=>true];
					if ($info[$name]['template']) {
						$actionsContext[] = ["icon"=>"ca_fa-delete","text"=>"<span class='ca_red'>".tr("Uninstall")."</span>","action"=>"uninstallDocker('".addslashes($info[$name]['template'])."','{$template['Name']}');"];
						$template['Installed'] = true;
					}
				} elseif (!$template['Blacklist']) {
					if ($template['InstallPath']) {
						$userTemplate = readXmlFile($template['InstallPath'],false,false);
						if (!$template['Blacklist']) {
							$actionsContext[] = ["icon"=>"ca_fa-install","text"=>tr("Reinstall"),"action"=>"popupInstallXML('".addslashes($template['InstallPath'])."','user','".portsUsed($userTemplate)."');"];
							$actionsContext[] = ["divider"=>true];
						}
						$actionsContext[] = ["icon"=>"ca_fa-delete","text"=>"<span class='ca_red'>".tr("Remove from Previous Apps")."</span>","action"=>"removeApp('{$template['InstallPath']}','{$template['Name']}');"];
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
				if ($template['installedVersion'] != $template['pluginVersion'] || (is_file("/tmp/plugins/$pluginName") && $template['installedVersion'] != ca_plugin("version","/tmp/plugins/$pluginName"))) {
					if (is_file(CA_PATHS['pluginTempDownload'])) {
						@copy(CA_PATHS['pluginTempDownload'],"/tmp/plugins/$pluginName");
						$template['UpdateAvailable'] = true;
						$actionsContext[] = ["icon"=>"ca_fa-update","text"=>tr("Update"),"action"=>"installPlugin('$pluginName',true);"];
					}
				} else {
					$template['UpdateAvailable'] = false;
				}

				$pluginSettings = ($pluginName == "community.applications.plg") ? "ca_settings" : ca_plugin("launch","/var/log/plugins/$pluginName");
				if ($pluginSettings) {
					$actionsContext[] = ["icon"=>"ca_fa-pluginSettings","text"=>tr("Settings"),"action"=>"openNewWindow('/Apps/$pluginSettings');"];
				}
				if ($pluginName != "community.applications.plg") {
					if (!empty($actionsContext)) {
						$actionsContext[] = ["divider"=>true];
					}
					$actionsContext[] = ["icon"=>"ca_fa-delete","text"=>"<span class='ca_red'>".tr("Uninstall")."</span>","action"=>"uninstallApp('/var/log/plugins/$pluginName','".str_replace(" ","&nbsp;",$template['Name'])."');"];
				}
			} elseif (!$template['Blacklist']) {
				if (($template['Compatible'] || $GLOBALS['caSettings']['hideIncompatible'] !== "true") && !($template['UninstallOnly'] ?? false)) {
					if (!$template['Deprecated'] || $GLOBALS['caSettings']['hideDeprecated'] !== "true" || ($template['Deprecated'] && $template['InstallPath'])) {
						if (($template['RequiresFile'] && is_file($template['RequiresFile'])) || !$template['RequiresFile']) {
							$buttonTitle = $template['InstallPath'] ? tr("Reinstall") : tr("Install");
							/* Build install URL flags additively. The previous form overwrote
							   the deprecated flag with the incompatible one (and inverted the
							   compatibility polarity), so deprecated+incompatible plugins lost
							   their deprecated marker and compatible plugins were mislabeled. */
							$installFlags = "";
							if ( ! empty($template['Deprecated']) )      $installFlags .= "&deprecated";
							if ( empty($template['Compatible']) )        $installFlags .= "&incompatible";
							$actionsContext[] = ["icon"=>"ca_fa-install","text"=>$buttonTitle,"action"=>"installPlugin('{$template['PluginURL']}{$installFlags}');"];
						}
					}
				}
				if ($template['InstallPath']) {
					if (!empty($actionsContext)) {
						$actionsContext[] = ["divider"=>true];
					}
					$actionsContext[] = ["icon"=>"ca_fa-delete","text"=>"<span class='ca_red'>".tr("Remove from Previous Apps")."</span>","action"=>"removeApp('{$template['InstallPath']}','$pluginName');"];
				}
			}
			if (is_file(CA_PATHS['pluginPending'].$pluginName)) {
				$actionsContext = [["text"=>tr("Pending")]];
			}
		}

		return $actionsContext;
	}

##############################################################################
# Build action contexts for language pack templates within the card renderer #
/**
	 * Build language-specific action items for a language template.
	 *
	 * Adds install/switch/update/uninstall actions to the provided actions context for templates that represent language packs,
	 * and sets template-side flags such as `UpdateAvailable` and `Changes` when applicable.
	 *
	 * @param array &$template The template array for the language; may be modified (e.g. `UpdateAvailable`, `Changes`).
	 * @param string|null $countryCode The language pack code (e.g. `en_US`) that the template represents, or null if not determined.
	 * @param array $actionsContext Current list of action items to augment; each action is an associative array describing an action/button.
	 * @return array The augmented actions context array.
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
				$actionsContext[] = ["icon"=>"ca_fa-delete","text"=>"<span class='ca_red'>".tr("Uninstall")."</span>","action"=>"removeLanguage('$countryCode');"];
			}
		}

		if ($countryCode !== "en_US") {
			$template['Changes'] = "<center><a href='https://github.com/unraid/lang-$countryCode/commits/master' target='_blank'>".tr("Click here to view the language changelog")."</a></center>";
		} else {
			unset($template['Changes']);
		}

		if (file_exists(CA_PATHS['pluginPending'].$template['LanguagePack']) || file_exists(CA_PATHS['pluginPending']."lang-{$template['LanguagePack']}.xml")) {
			$actionsContext = [["text"=>tr("Pending")]];
		}

		return $actionsContext;
	}



########################################################################
# Assemble docker-related context (warnings, info caches) for listings #
/**
 * Collects Docker runtime information, update status, and warning state for header display.
 *
 * Returns an associative array containing Docker metadata and a small script fragment
 * used to render a header warning when Docker is not available or not started.
 *
 * @return array{
 *   info: array,                    // Output of getAllInfo() when Docker is running, empty array otherwise.
 *   dockerUpdateStatus: array,      // Parsed JSON update status coerced to an array.
 *   dockerNotEnabled: int|string,   // Warning code or string: 1=array started but docker disabled, 2=docker enabled but failed to start, 3=array not started, or "true"/"false" when not resolved.
 *   dockerWarningFlag: string,      // "true" when Docker is not running and installs are allowed, "false" otherwise.
 *   displayHeader: string           // HTML <script> snippet that invokes client-side warning logic.
 * }
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

	$dockerWarningFlag = (! caIsDockerRunning() && ! ($GLOBALS['caSettings']['NoInstalls'] ?? false)) ? "true" : "false";
	$dockerNotEnabled = $dockerWarningFlag;

	if ($dockerNotEnabled === "true") {
		$unRaidVars = parse_ini_file(CA_PATHS['unRaidVars']);
		$dockerVars = parse_ini_file(CA_PATHS['docker_cfg']);

		if ($unRaidVars['mdState'] === "STARTED" && $dockerVars['DOCKER_ENABLED'] !== "yes") {
			$dockerNotEnabled = 1; // Array started, docker not enabled
		}
		if ($unRaidVars['mdState'] === "STARTED" && $dockerVars['DOCKER_ENABLED'] === "yes") {
			$dockerNotEnabled = 2; // Docker failed to start
		}
		if ($unRaidVars['mdState'] !== "STARTED") {
			$dockerNotEnabled = 3; // Array not started
		}
	}

	$displayHeader = "<script>addDockerWarning($dockerNotEnabled);var dockerNotEnabled = $dockerWarningFlag;</script>";

	return [
		'info'               => $info,
		'dockerUpdateStatus' => $dockerUpdateStatus,
		'dockerNotEnabled'   => $dockerNotEnabled,
		'dockerWarningFlag'  => $dockerWarningFlag,
		'displayHeader'      => $displayHeader
	];
}

#####################################################################
# Normalize the structure of the multi-select payload used in CA UI #
/**
 * Normalize a selected-apps payload and produce a lookup of checked entries.
 *
 * Ensures the returned selected apps array always contains `docker` and `plugin` keys
 * as arrays (defaults to empty arrays when missing or falsy), and computes a compact
 * lookup object of all checked entries merged from both domains.
 *
 * @param mixed $selectedApps The incoming selection structure (may be null, falsy, or an array
 *                            with optional `docker` and `plugin` lists).
 * @return array An indexed array with two elements:
 *               0 => (array) The normalized `$selectedApps` with guaranteed `docker` and `plugin` arrays.
 *               1 => (array) `$checkedOffApps` — an associative lookup produced by `arrayEntriesToObject`
 *                             from the merged values of the `docker` and `plugin` lists.
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

###################################################################
# Slice the current page worth of templates from the full listing #
/**
 * Selects a single page of templates from the full list using the configured page size.
 *
 * Uses $GLOBALS['caSettings']['maxPerPage'] as the page size and returns the templates
 * that belong to the requested 1-based page number.
 *
 * @param array $file The complete list/array of templates to paginate.
 * @param int $pageNumber The 1-based page index to extract.
 * @return array The subset of templates for the specified page (empty if page is out of range).
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

#############################################################################
# Apply moderation overrides (blacklist/deprecation comments) to a template #
/**
 * Apply moderator blacklist or deprecation overrides to a template when it is not a repository template.
 *
 * If the template is not marked as a repository template, this will:
 * - set `Blacklist = true` and update `ModeratorComment` when the template's `Repository` exists in `$extraBlacklist` and the template is not already blacklisted;
 * - set `Deprecated = true` and update `ModeratorComment` when the template's `Repository` exists in `$extraDeprecated` and the template is not already deprecated.
 *
 * @param array $template The template record to modify; expected to contain at least `RepositoryTemplate`, `Repository`, `Blacklist`, `Deprecated`, and `ModeratorComment` keys.
 * @param array $extraBlacklist Map of repository name => moderator comment to apply as a blacklist override.
 * @param array $extraDeprecated Map of repository name => moderator comment to apply as a deprecation override.
 * @return array The modified template array (may be unchanged if no overrides apply).
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

##########################################################################
# Apply search-link transforms to moderator/CA comments + requires for   #
# the sidebar render. The install actions no longer carry a separate     #
# comment payload — the sidebar always shows these blocks before the     #
# install button, so a second confirm-swal would be redundant.           #
/**
 * Normalizes and embeds sidebar-search links into a template's comment and requirement fields.
 *
 * Transforms `ModeratorComment` and `CAComment` by applying sidebar search link conversion.
 * If `Requires` is present and non-empty, normalizes line breaks and HTML, renders Markdown (preserving `<br>`), 
 * then applies sidebar search link conversion.
 *
 * @param array $template Template data; fields `ModeratorComment`, `CAComment`, and `Requires` may be modified.
 * @return array The template array with updated comment and requires fields.
 */
function caPrepareTemplateComments(array $template): array {
	$template['ModeratorComment'] = caApplySidebarSearchLinks($template['ModeratorComment'] ?? "");
	$template['CAComment'] = caApplySidebarSearchLinks($template['CAComment'] ?? "");

	if ($template['Requires']) {
		$template['Requires'] = markdown(strip_tags(str_replace(["\r", "\n", "&#xD;", "'"], ["", "<br>", "", "&#39;"], trim($template['Requires'])), "<br>"));
		$template['Requires'] = caApplySidebarSearchLinks($template['Requires']);
	}

	return $template;
}

#############################################################################
# Build action contexts and flags for docker templates when rendering cards #
/**
 * Prepare action entries and installed/update flags for a Docker-type template.
 *
 * Examines running Docker containers and update status to mark the template as installed or updatable,
 * and builds an actions context (install, reinstall, update, edit, uninstall, etc.) appropriate for the
 * template's state and global settings.
 *
 * @param array $template The template record (may be mutated to set flags like `Installed` and `UpdateAvailable`).
 * @param array $info Array of Docker container info entries indexed numerically; each entry should include keys
 *                    such as `Name`, `Image`, `url`, `running`, `TSurl`, and `template`.
 * @param array $dockerUpdateStatus Mapping of repository identifiers (normalized to include `:latest` and
 *                                  `library/` prefix when needed) to update metadata; expected to include
 *                                  a `status` field indicating update availability (e.g., `"false"` for update).
 * @return array A two-element array: [0 => $template (possibly modified), 1 => $actionsContext], where
 *               `$actionsContext` is an array of action descriptor arrays (icons, text, JS action strings,
 *               dividers, etc.).
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
					$actionsContext[] = ["icon" => "ca_fa-globe", "text" => tr("Tailscale WebUI"), "action" => "openNewWindow('{$info[$ind]['TSurl']}','_blank');"];
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
						$actionsContext[] = ["icon" => "ca_fa-install", "text" => tr("Install second instance"), "action" => "displayTags('{$template['ID']}',true,'".$template['PortsUsed']."');"];
					} else {
						$actionsContext[] = ["icon" => "ca_fa-install", "text" => tr("Install second instance"), "action" => "popupInstallXML('".addslashes($template['Path'])."','second','".$template['PortsUsed']."');"];
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
				$actionsContext[] = ["icon" => "ca_fa-delete", "text" => tr("Remove from Previous Apps"), "alternate" => tr("Remove"), "action" => "removeApp('{$template['InstallPath']}','{$template['Name']}');"];
			} else {
				$canFreshInstall = (($template['Compatible'] ?? false) || ($GLOBALS['caSettings']['hideIncompatible'] ?? "true") !== "true")
					&& (! ($template['Deprecated'] ?? false) || ($GLOBALS['caSettings']['hideDeprecated'] ?? "true") !== "true");
				if (! ($template['BranchID'] ?? null)) {
					if (is_file(CA_PATHS['dockerManTemplates']."/my-{$template['Name']}.xml")) {
						$previousTemplatePath = CA_PATHS['dockerManTemplates']."/my-{$template['Name']}.xml";
						$test = readXmlFile($previousTemplatePath, true);
						if ($template['Repository'] == $test['Repository']) {
							$userTemplate = readXmlFile($previousTemplatePath, false, false);
							$actionsContext[] = ["icon" => "ca_fa-install", "text" => "<span class='ca_red'>".tr("Reinstall From Previous Apps")."</span>", "action" => "popupInstallXML('".addslashes($previousTemplatePath)."','user','".portsUsed($userTemplate)."');"];
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

#############################################################################
# Build action contexts and state for plugin templates when rendering cards #
/**
 * Prepare plugin-specific template state and build the actions menu for that template.
 *
 * Updates the provided template's install/update state (for example `Installed`, `UpdateAvailable`
 * and `pluginVersion` as applicable) and produces an actions context array representing UI actions
 * the user can take (install, update, settings, uninstall, etc.). If a pending plugin file exists,
 * the actions context is replaced with a single "Pending" entry.
 *
 * @param array $template The plugin template associative array; may be mutated to reflect resolved state.
 * @return array A two-element array: [0] the (possibly modified) template array, [1] an array of action items.
 *               Each action item is an associative array with keys such as:
 *               - `icon` (string): CSS/icon identifier,
 *               - `text` (string): visible label,
 *               - `action` (string): JavaScript/action payload,
 *               - `divider` (bool): true to indicate a separator entry.
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
			$actionsContext[] = ["icon" => "ca_fa-update", "text" => tr("Update"), "action" => "installPlugin('$pluginName',true,'','{$template['RequiresFile']}');"];
		} else {
			if (! $template['UpdateAvailable']) {
				$template['UpdateAvailable'] = false;
			}
		}
		$pluginSettings = ($pluginName == "community.applications.plg") ? "ca_settings" : ca_plugin("launch", "/var/log/plugins/$pluginName");
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
			$actionsContext[] = ["icon" => "ca_fa-install", "text" => $buttonTitle, "action" => "installPlugin('{$template['PluginURL']}{$installFlags}','$updateFlag','$requiresText');"];
		}
		if ($template['InstallPath']) {
			if (! empty($actionsContext)) {
				$actionsContext[] = ["divider" => true];
			}
			$actionsContext[] = ["icon" => "ca_fa-delete", "text" => tr("Remove from Previous Apps"), "action" => "removeApp('{$template['InstallPath']}','$pluginName');"];
		}
	}

	if (file_exists(CA_PATHS['pluginPending'].$pluginName)) {
		$actionsContext = [];
		$actionsContext[] = ["text" => tr("Pending")];
	}

	return [$template, $actionsContext];
}

##############################################################################
/**
 * Prepare actions and installation/update state for a language-pack template.
 *
 * Adds install/switch/uninstall/update actions to the provided actions context based on
 * whether the language pack is installed, pending, or has an available update, and sets
 * template flags such as `Installed` and `UpdateAvailable`.
 *
 * @param array $template The language template data (expects keys like `LanguagePack`, `LanguageDefault`, `TemplateURL`, `SwitchLanguage`, `UpdateLanguage`, `Uninstall`).
 * @param array $actionsContext Existing actions context to extend; may be replaced with a single "Pending" action if a pending install is detected.
 * @return array A two-element array: the possibly modified `$template` and the resulting `$actionsContext`.
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
 * Build the client-side page navigation payload and return a script tag that renders it.
 *
 * Forces the page function to "dockerSearch" when performing a Docker search (which also forces
 * the global maxPerPage to 25); otherwise uses "changePage". If pagination is disabled via
 * the global `caSettings['maxPerPage']` being negative, nothing is returned.
 *
 * @param int $pageNumber The current page number.
 * @param int $totalApps The total number of items across all pages.
 * @param bool $dockerSearch True to use Docker-search pagination behaviour; false for normal paging.
 * @param bool $displayCount Whether to include a visible item count in the navigation.
 * @return string|null The HTML <script> snippet that calls the page-navigation renderer, or null when pagination is disabled.
 */
function getPageNavigation($pageNumber,$totalApps,$dockerSearch,$displayCount = true) {

	$pageFunction = $dockerSearch ? "dockerSearch" : "changePage";

	if ( $dockerSearch ) {
		$GLOBALS['caSettings']['maxPerPage'] = 25;
	}

	if ( $GLOBALS['caSettings']['maxPerPage'] < 0 ) {
		return;
	}

	$maxPerPage = max(1, (int)$GLOBALS['caSettings']['maxPerPage']);
	$maxMiddlePages = 3; // Change this value to control how many middle page numbers are shown.

	$navigationData = [
		'pageNumber' => (int)$pageNumber,
		'totalApps' => (int)$totalApps,
		'maxPerPage' => (int)$maxPerPage,
		'displayCount' => (bool)$displayCount,
		'pageFunction' => $pageFunction,
		'dockerSearch' => (bool)$dockerSearch,
		'maxMiddlePages' => (int)$maxMiddlePages,
	];
	$jsonNavigationData = json_encode($navigationData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

	return "<script>caRenderPageNavigation('ca_pageNavigation',$jsonNavigationData);</script>";
}


######################################################################
# Summarize repository statistics (counts/downloads) for repo popups #
/**
 * Summarizes templates belonging to a specific repository, aggregating counts and download statistics.
 *
 * Templates are excluded from the summary when they are branch variants, blacklisted, deprecated (when
 * deprecated templates are configured to be hidden), or incompatible (when incompatible templates are
 * configured to be hidden).
 *
 * @param array $templates List of template arrays to examine. Each template may contain keys such as
 *                         `RepoName`, `BranchID`, `Blacklist`, `Deprecated`, `Compatible`, `Registry`,
 *                         `downloads`, `PluginURL`, and `Language`.
 * @param string $repository Repository name to match against each template's `RepoName`.
 * @return array Associative array with the following keys:
 *               - `apps` (int): Total number of included templates for the repository.
 *               - `languages` (int): Count of templates marked as language packs.
 *               - `plugins` (int): Count of templates with a `PluginURL`.
 *               - `docker` (int): Count of templates that specify a `Registry` (docker templates).
 *               - `downloads` (int): Sum of `downloads` for docker templates included in the summary.
 *               - `downloadDockerCount` (int): Number of docker templates that contributed to `downloads`.
 *               - `avgDownloads` (int): Integer average downloads per docker template (floor of the division),
 *                 zero when there are no contributing docker templates.
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

##################################################################
# Build the donation section for repository popups               #
/**
 * Builds an HTML donation section for a repository when a valid donate URL is provided.
 *
 * If the repository array contains a valid `DonateLink`, returns a small HTML block
 * containing optional donate text and a Donate button linking to that URL; otherwise returns an empty string.
 *
 * @param array $repo Repository data array; expected keys include `DonateLink` (URL) and optional `DonateText`.
 * @return string HTML markup for the donation section, or an empty string when no valid donate link is present.
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

##################################################################
# Build the media (photos/videos) section for repository popups  #
/**
 * Build an HTML media section for a repository using its Photo and Video fields.
 *
 * Produces a container of clickable screenshot spans for each valid photo and video URL
 * found in `$repo['Photo']` and `$repo['Video']`. Photo entries become `<span>` elements
 * with `data-mfp-src` and an `<img>` child (images are escaped and hide on load error).
 * Video entries become `<span>` elements prepared for iframe playback with a YouTube
 * thumbnail. Invalid or empty URLs are skipped. Returns an empty string when no media
 * is present.
 *
 * @param array $repo Repository data array; may contain string or array values for
 *                    the `Photo` and `Video` keys.
 * @return string HTML fragment for the media section, or an empty string when none.
 */
function caBuildRepoMediaSection(array $repo): string {
	$hasPhoto = !empty($repo['Photo']);
	$hasVideo = !empty($repo['Video']);

	if (! $hasPhoto && ! $hasVideo) {
		return "";
	}

	$mediaHtml = "<div>";

	if ($hasPhoto) {
		$photos = is_array($repo['Photo']) ? $repo['Photo'] : [$repo['Photo']];
		foreach ($photos as $shot) {
			$shot = trim($shot);
			if ($shot === "" || !validURL($shot)) {
				continue;
			}
			$safeShot = htmlspecialchars($shot, ENT_QUOTES);
			/* span (not <a>) so the legacy external-link click handler doesn't
			   intercept this — magnific reads data-mfp-src for the source. */
			$mediaHtml .= "<span class='screenshot' data-mfp-src='{$safeShot}'><img class='screen' src='{$safeShot}' onerror='this.style.display=&quot;none&quot;'></img></span>";
		}
	}

	if ($hasVideo) {
		$videos = is_array($repo['Video']) ? $repo['Video'] : [$repo['Video']];
		foreach ($videos as $vid) {
			$vid = trim($vid);
			if ($vid === "" || !validURL($vid)) {
				continue;
			}
			$thumbnail = getYoutubeThumbnail($vid);
			$safeVid = htmlspecialchars($vid, ENT_QUOTES);
			$mediaHtml .= "<span class='screenshot mfp-iframe videoPlayOverlay' data-mfp-src='{$safeVid}' style='position: relative; display: inline-block;'><img class='screen' src='".trim($thumbnail)."'></span>";
		}
	}

	$mediaHtml .= "</div>";

	return $mediaHtml;
}

##################################################################
# Build social/project link buttons for repository popups        #
/**
 * Build an HTML block of link buttons for supported repository contact and social fields.
 *
 * Inspects known keys (WebPage, Forum, profile, Facebook, Reddit, Twitter, Discord) in the
 * provided repository array and, for each present value that passes URL validation, emits a
 * button-styled anchor with an appropriate CSS class and translated label.
 *
 * @param array $repo Repository data array; expected to contain URL strings under keys such as
 *                    'WebPage', 'Forum', 'profile', 'Facebook', 'Reddit', 'Twitter', 'Discord'.
 *                    Only entries that pass validURL() will produce buttons.
 * @return string HTML markup for a container <div class="repoLinkArea"> containing zero or more
 *                link buttons.
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

##################################################################
# Build the statistics table shown within repository popups      #
/**
 * Builds an HTML statistics section for a repository from precomputed totals.
 *
 * The output includes the "Added to CA" date (when present), totals for Docker
 * applications, plugins, optional language count, total applications, and —
 * when download data exists — total known downloads and average downloads per app.
 * In development mode and when a valid repository URL is provided, a Repository URL
 * link row is also included.
 *
 * @param array $repo Repository metadata (may contain `FirstSeen` timestamp and `url`).
 * @param array $totals Aggregated counts and stats. Expected keys include
 *                      `docker`, `plugins`, `apps`, and optionally `languages`,
 *                      `downloadDockerCount`, `downloads`, and `avgDownloads`.
 * @return string HTML fragment containing the repository statistics table.
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

	if (($GLOBALS['caSettings']['dev'] ?? null) === "yes" && !empty($repo['url']) && validURL($repo['url'])) {
		$safeRepoUrl = htmlspecialchars($repo['url'], ENT_QUOTES);
		$rows[] = "<tr><td class='repoLeft'><a class='popUpLink' href='{$safeRepoUrl}' target='_blank' rel='noopener noreferrer'>".tr("Repository URL")."</a></td></tr>";
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

##################################################################
# Render pagination controls for Docker Hub search results       #
/**
 * Renders page navigation controls for Docker Hub results.
 *
 * @param int $num_pages Total number of pages of results.
 * @param int $pageNumber Current page number (1-based).
 * @return string HTML/script markup for the page navigation controls.
 */
function dockerNavigate($num_pages, $pageNumber) {
	return getPageNavigation($pageNumber,$num_pages * 25, true);
}

#################################################################################
# Attempt to find a template matching a repository name (with :latest fallback) #
/**
 * Finds the index of a template that matches a repository name, falling back to the `:latest` suffix.
 *
 * @param array $templates Array of template entries to search (each entry expected to have a `Repository` key).
 * @param string $repository Repository name to match.
 * @return int|false The index of the matching template in `$templates` if found, `false` otherwise.
 */
function findTemplateMatch(array $templates, string $repository) {
	$templateIndex = searchArray($templates, "Repository", $repository);

	if ($templateIndex === false) {
		$templateIndex = searchArray($templates, "Repository", "{$repository}:latest");
	}

	return $templateIndex;
}

##################################################################
# Enrich a Docker Hub search result with CA metadata/actions     #
/**
 * Enriches a Docker Hub search result with UI fields and, when available, matching Community Applications template metadata.
 *
 * @param array $result Associative Docker Hub result array; will be augmented with UI fields such as `Icon`, `Category`, `Description`, `Compatible`, `display_dockerName`, `similarSearch` and an `actionsContext` when installs are allowed.
 * @param array $templates List of Community Applications templates used to find a CA template that matches the result's `Repository`.
 * @param bool $installsDisabled If true, install-related actions are removed from the returned result.
 * @return array The input `$result` array augmented with default UI fields, an `actionsContext` unless installs are disabled, and — when a non-deprecated, non-blacklisted CA template matches — `caTemplateExists`, overridden `Icon` and `Description`, `ID`, and an actions entry to show the template.
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

##################################################################
# Resolve the CA app type class/title for a template card        #
/**
 * Resolve the application type for a template and provide a user-facing type title.
 *
 * Examines the template's metadata (RepositoryTemplate, Plugin, Language, and Category)
 * to select one of the application type keys and a corresponding translated title.
 *
 * @param array $template Template data; relevant keys:
 *                        - 'RepositoryTemplate' (truthy for repository-backed templates)
 *                        - 'Plugin' (truthy for plugin templates)
 *                        - 'Language' (truthy for language packs)
 *                        - 'Category' (used to detect drivers when it contains "Drivers")
 * @return array A two-element array: [0 => $appType, 1 => $typeTitle].
 *               $appType is one of: "appRepository", "appPlugin", "appDocker", "appLanguage", "appDriver".
 *               $typeTitle is the translated, human-readable description for the type (empty string if none).
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

#######################################################################
# Normalize category labels used in cards (strip additional metadata) #
/**
 * Extracts the leading category token from a category string.
 *
 * @param string|null $category The category value to normalize.
 * @return string The substring before the first space or colon, or an empty string if the input is null or empty.
 */
function caNormalizeCategory(?string $category): string {
	if (!$category) {
		return "";
	}

	$category = explode(" ", $category)[0] ?? "";
	$category = explode(":", $category)[0] ?? "";

	return $category;
}

##################################################################
# Determine the author/maintainer label for a template card      #
/**
 * Resolve a display-friendly author name for a template.
 *
 * Determines the best author string for presentation: for DockerHub-backed templates it
 * returns the template `Author` field; for plugins it prefers `Author`; otherwise it
 * uses `RepoShort` when available and falls back to the provided repository name.
 * If the chosen author exactly matches the repository name and contains common
 * repository suffix patterns (e.g. " Repository", "'s Repository"), those patterns
 * are converted into a more natural possessive display using translations.
 *
 * @param array $template Template data array (may contain keys like `DockerHub`, `Plugin`, `Author`, `RepoShort`).
 * @param string $repoName Repository name to use as a fallback when `RepoShort` is not present.
 * @return string The resolved author display name.
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

##################################################################
# Build support button context for template cards (non-repo)     #
/**
 * Build a list of support link context entries for an application template.
 *
 * @param array $template Template data; may contain keys `Project`, `Discord`, `Support`, `SupportLanguage`, and `Registry`.
 * @return array An indexed array of support-context associative arrays. Each entry contains:
 *               - `icon` (string): CSS/icon identifier for the link.
 *               - `link` (string): URL for the support resource.
 *               - `text` (string): Visible label for the link.
 */
function caBuildSupportContextForApplication(array $template): array {
	$context = [];
	if (!empty($template['Project'])) {
		$context[] = ["icon" => "ca_fa-project", "link" => $template['Project'], "text" => tr("Project")];
	}
	if (!empty($template['Discord'])) {
		$context[] = ["icon" => "ca_discord", "link" => $template['Discord'], "text" => tr("Discord")];
	}
	if (!empty($template['Support'])) {
		$context[] = [
			"icon" => "ca_fa-support",
			"link" => $template['Support'],
			"text" => $template['SupportLanguage'] ?: tr("Support Forum")
		];
	}
	if (!empty($template['Registry'])) {
		$context[] = ["icon" => "docker", "link" => $template['Registry'], "text" => tr("Registry")];
	}

	return $context;
}

######################################################################
# Build an inline ReadMe section placeholder for GitHub README links #
/**
 * Build a lazy-loading README container for GitHub repository README links.
 *
 * Validates the template's `ReadMe` URL and, when it points to a GitHub repository
 * (repository root, a path ending with `/README.md`, or a URL with fragment `#README`),
 * returns an HTML `<div>` that contains:
 * - a link to view the README on the web,
 * - a placeholder body ("Loading README..."),
 * - data attributes `data-readme-url` and `data-readme-url-fallback` with raw.githubusercontent.com
 *   URLs for `main` and `master` branches, and
 * - `data-readme-id` and `data-readme-cachekey` (SHA-256 of the original README URL) for client-side caching.
 *
 * If the `ReadMe` value is missing, not parseable, or not a supported GitHub README URL, an empty
 * string is returned.
 *
 * @param array $template Template data; expected to contain a `ReadMe` URL string at key `ReadMe`.
 * @return string An HTML string for the README container when a supported GitHub README is detected, or
 *                an empty string otherwise.
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

	$rawMainUrl = "https://raw.githubusercontent.com/{$org}/{$repo}/main/README.md";
	$rawMasterUrl = "https://raw.githubusercontent.com/{$org}/{$repo}/master/README.md";
	// Stable cache key derived from the template-provided README URL (avoids re-trying main/master on repeat views).
	$cacheKey = hash("sha256", $readmeUrl);
	$readmeId = "ca_readme_" . $cacheKey;
	$safeReadmeUrl = htmlspecialchars($readmeUrl, ENT_QUOTES);
	$safeRawMainUrl = htmlspecialchars($rawMainUrl, ENT_QUOTES);
	$safeRawMasterUrl = htmlspecialchars($rawMasterUrl, ENT_QUOTES);
	return "<div id='{$readmeId}' class='ReadmeSection popupDescription popup_readmore {$readmeId}' data-readme-id='{$readmeId}' data-readme-cachekey='{$cacheKey}' data-readme-url='{$safeRawMainUrl}' data-readme-url-fallback='{$safeRawMasterUrl}'><div class='ReadmeSectionLabel ca_bold'><a class='popUpLink' href='{$safeReadmeUrl}' target='_blank' rel='noopener noreferrer'>".tr("View README on Web")."</a></div><div class='ca_readme_body'>".tr("Loading README...")."</div></div>";
}

#######################################################################
# Build repository card overrides/context when rendering repo entries #
/**
 * Build context data for a repository popup/card used by the skin.
 *
 * Creates a support links list from repository fields, derives a display name
 * from the provided author string, and produces an overrides map that clears
 * template-specific presentation fields so the repository popup shows a
 * repository-focused view.
 *
 * @param array $template The source template array (used to extract support links).
 * @param string $repoName Repository identifier (used for element id generation).
 * @param string $author The template author string (used to derive the display name).
 * @return array Associative array with keys:
 *               - "holderClass": container CSS class.
 *               - "cardClass": inner card CSS class.
 *               - "id": HTML id for the repository card (spaces removed).
 *               - "supportContext": array of support link objects ({icon, link, text}).
 *               - "actionsContext": empty actions array for repository view.
 *               - "name": computed display name for the repository.
 *               - "author": currently empty string (author moved into name).
 *               - "overrides": associative array of template fields cleared for repository display, with "Name" set to the computed display name.
 */
function caBuildRepositoryContext(array $template, string $repoName, string $author): array {
	$supportContext = [];

	if (!empty($template['profile'])) {
		$supportContext[] = ["icon" => "ca_profile", "link" => $template['profile'], "text" => tr("Profile")];
	}
	if (!empty($template['Forum'])) {
		$supportContext[] = ["icon" => "ca_forum", "link" => $template['Forum'], "text" => tr("Forum")];
	}
	if (!empty($template['Twitter'])) {
		$supportContext[] = ["icon" => "ca_twitter", "link" => $template['Twitter'], "text" => tr("Twitter")];
	}
	if (!empty($template['Reddit'])) {
		$supportContext[] = ["icon" => "ca_reddit", "link" => $template['Reddit'], "text" => tr("Reddit")];
	}
	if (!empty($template['Facebook'])) {
		$supportContext[] = ["icon" => "ca_facebook", "link" => $template['Facebook'], "text" => tr("Facebook")];
	}
	if (!empty($template['WebPage'])) {
		$supportContext[] = ["icon" => "ca_webpage", "link" => $template['WebPage'], "text" => tr("Web Page")];
	}

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
		"supportContext" => $supportContext,
		"actionsContext" => [],
		"name" => $name,
		"author" => "",
		"overrides" => $overrides
	];
}

########################################################################
# Build the base card container, actions, and navigation footer markup #
/**
 * Build the bottom-line HTML container and action buttons for a template card.
 *
 * When the template has a Docker Hub URL, produces Docker-specific buttons and a docker-style background mode;
 * otherwise produces a card holder with data attributes (app path, name, repository, optional plugin URL) and a
 * Details button. The function does not echo output; it returns the fragments for the caller to embed.
 *
 * @param array  $template   Template data array.
 * @param string $cardClass  CSS class applied to the primary action button when not showing Docker Hub.
 * @param string|null $popupType Optional CSS class or popup type to include on the holder (nullable).
 * @param string $holderClass CSS class applied to the holder when not showing Docker Hub.
 * @param string $class      Additional CSS class applied to the holder when not showing Docker Hub.
 * @param string $name       Display name for the application (used in data-appname).
 * @param string $repoName   Repository name (used in data-repository).
 * @return array An array with three elements: [0] HTML string for the card start container, [1] HTML string for the card bottom-line content, [2] background mode CSS class (`dockerCardBackground` or `ca_backgroundClickable`).
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

	if (!empty($template['DockerHub']) && validURL($template['DockerHub'])) {
		$backgroundClickable = "dockerCardBackground";
		$safeDockerHub = htmlspecialchars($template['DockerHub'], ENT_QUOTES);
		$cardStart = "
			<div class='ca_holder ca_dockerTemplate {$popupType}'>";
		$card .= "
			<div class='ca_bottomLine {$bottomClass}'>
			<div class='caButton infoButton_docker ca_href' data-href='{$safeDockerHub}'>".tr("Docker Hub")."</div>
			<div class='caButton actionsButton similarSearch' data-search='".($template['similarSearch'] ?? "")."'>".tr("Similar")."</div>";
	} else {
		$backgroundClickable = "ca_backgroundClickable";
		$dataPluginURL = empty($template['PluginURL']) ? "" : "data-pluginurl='".htmlspecialchars((string)$template['PluginURL'], ENT_QUOTES)."'";
		$cardStart = "
			<div class='ca_holder {$class} {$popupType} {$holderClass}' data-apppath='".($template['Path'] ?? "")."' data-appname='{$name}' data-repository='".htmlentities($repoName, ENT_QUOTES)."' {$dataPluginURL}>";
		$card .= "
			<div class='ca_bottomLine {$bottomClass}'>
			<div class='caButton infoButton {$cardClass}'>".tr("Details")."</div>
		";
	}

	return [$cardStart, $card, $backgroundClickable];
}

##############################################################################
# Render the Support button(s) for a template card depending on context size #
/**
 * Render one or more support buttons for a template's support links.
 *
 * Filters and sanitizes the provided support contexts and returns:
 * - an empty string when there are no valid http(s) support links,
 * - a single clickable support button when exactly one valid context is present,
 * - a support button that exposes a JSON-encoded `data-context` for client-side handling when multiple contexts are present.
 *
 * @param array $supportContext Array of support context entries; each entry should be an associative array containing at least `link` (URL) and `text` (visible label).
 * @param string $name Template display name used to derive a stable element identifier.
 * @param string $id Unique suffix used with the sanitized name to avoid id collisions.
 * @return string HTML markup for the support button(s), or an empty string when no valid support links exist.
 */
function caRenderSupportButtons(array $supportContext, string $name, string $id): string {
	$supportContext = array_values(array_filter($supportContext, static function ($context) {
		if (!is_array($context)) {
			return false;
		}
		$link = trim((string)($context['link'] ?? ""));
		$text = trim(strip_tags((string)($context['text'] ?? "")));
		/* Drop any context whose link isn't a real http(s) URL — the JS
		   ca_href handler treats a leading "/" as internal and would happily
		   open /Main/Dashboard on the user's own GUI. */
		return ($link !== "" && $text !== "" && validURL($link));
	}));

	if (empty($supportContext)) {
		return "";
	}

	if (count($supportContext) === 1) {
		$context = $supportContext[0];

		/* Sanitize the visible label — SupportLanguage can be a user-supplied
		   string in custom templates, so strip tags and escape before rendering.
		   The earlier filter only used the stripped form for emptiness, not output. */
		$displayText = trim(strip_tags((string)($context['text'] ?? "")));
		if ($displayText === tr("Support Forum")) {
			$displayText = tr("Support");
		}

		$safeLink = htmlspecialchars($context['link'], ENT_QUOTES);
		$safeText = htmlspecialchars($displayText, ENT_QUOTES, "UTF-8");
		return "<div class='caButton supportButton'><span class='ca_href' data-href='{$safeLink}' data-target='_blank'>{$safeText}</span></div>";
	}

	$sanitizedName = preg_replace("/[^a-zA-Z0-9]+/", "", $name).$id;

	return "
			<div class='caButton supportButton supportButtonCardContext' id='support{$sanitizedName}' data-context='".json_encode($supportContext, JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP)."'>".tr("Support")."</div>
		";
}

##################################################################
# Render the Actions button/menu for a template card             #
/**
 * Render the actions button HTML for a template card.
 *
 * When no actions are provided this returns an empty string.
 *
 * @param array $actionsContext List of action definitions. Each item should be an associative array containing at least:
 *                             - `text` (string): visible label for the action,
 *                             - `action` (string): JavaScript expression/handler to invoke.
 *                             Optionally `alternate` (string) can provide alternate display text.
 * @param string $pluginUrl Value written into the `data-pluginURL` attribute of the rendered element.
 * @param string $languagePack Value written into the `data-languagePack` attribute of the rendered element.
 * @param string $name Template display name used to form a stable element id suffix.
 * @param string $id Additional identifier appended to the sanitized name for the element id.
 * @return string HTML markup for a single-action button (direct onclick) or a context-actions button (JSON `data-context`), or an empty string if `$actionsContext` is empty.
 */
function caRenderActionsButtons(array $actionsContext, string $pluginUrl, string $languagePack, string $name, string $id): string {
	if (empty($actionsContext)) {
		return "";
	}

	if (count($actionsContext) === 1 && ($actionsContext[0]['text'] ?? "") === tr("Install")) {
		$dispText = $actionsContext[0]['alternate'] ?? $actionsContext[0]['text'];
		/* htmlspecialchars + ENT_QUOTES so apostrophes inside the JS body don't
		   collide with the surrounding onclick='...' delimiters. */
		$safeAction = htmlspecialchars($actionsContext[0]['action'], ENT_QUOTES);

		return "<div class='caButton actionsButton' data-pluginURL='{$pluginUrl}' data-languagePack='{$languagePack}' onclick='{$safeAction}'>{$dispText}</div>";
	}

	$sanitizedName = preg_replace("/[^a-zA-Z0-9]+/", "", $name).$id;

	return "<div class='caButton actionsButton actionsButtonContext' data-pluginURL='{$pluginUrl}' data-languagePack='{$languagePack}' id='actions{$sanitizedName}' data-context='".json_encode($actionsContext, JSON_HEX_QUOT | JSON_HEX_APOS)."'>".tr("Actions")."</div>";
}

###################################################################
# Render the favourite indicator span used on template/repo cards #
/**
 * Render the favorite indicator span for a template card.
 *
 * @param array $template Template data; presence of `$template['ca_fav']` makes the indicator visible.
 * @param string $repoName Repository identifier used for the `data-repository` attribute.
 * @param bool $repositoryTemplate True when the item is a repository template (affects the tooltip text).
 * @return string HTML `<span>` for the favorite indicator; visible with a title when favored, hidden otherwise.
 */
function caRenderFavouriteSpan(array $template, string $repoName, bool $repositoryTemplate): string {
	$repositoryAttr = str_replace("'", "", $repoName);

	if (!empty($template['ca_fav'])) {
		$favText = $repositoryTemplate ? tr("This is your favourite repository") : tr("This application is from your favourite repository");
		return "<span class='favCardBackground favCardBackgroundShow' data-repository='{$repositoryAttr}' title='".htmlentities($favText)."'></span>";
	}

	return "<span class='favCardBackground favCardBackgroundHide' data-repository='{$repositoryAttr}'></span>";
}

##################################################################
# Render the pinned indicator span for template cards            #
/**
 * Build the HTML span used to indicate a template is pinned.
 *
 * Expects $template to contain `Repository`, `SortName`, and `Pinned`. The returned span includes a
 * `data-pindata` attribute composed of the repository (prefixed with `library/` when the repository
 * contains no `/`) followed by the template `SortName`. When `Pinned` is empty the span is hidden
 * via inline `style="display:none;"`.
 *
 * @param array $template Template data; relevant keys: `Repository`, `SortName`, `Pinned`.
 * @return string HTML `<span>` element for the pinned indicator.
 */
function caRenderPinnedSpan(array $template): string {
	$repository = $template['Repository'] ?? "";
	$pindata = (strpos($repository, "/") !== false) ? $repository : "library/{$repository}";
	$sortName = $template['SortName'] ?? "";
	$pinStyle = !empty($template['Pinned']) ? "" : "display:none;";

	return "<span class='pinnedCard' title='".htmlentities(tr("This application is pinned for later viewing"))."' data-pindata='{$pindata}{$sortName}' style='{$pinStyle}'></span>";
}

##############################################################################
# Resolve the multi-select checkbox type (docker/plugin/language) for a card #
/ **
 * Map an application type identifier to the checkbox domain used for bulk selection.
 *
 * @param string $appType Application type identifier (e.g. 'appDocker', 'appPlugin', 'appLanguage', 'appDriver').
 * @return string The checkbox domain: `'docker'`, `'plugin'`, `'language'`, or an empty string when no domain applies.
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

#######################################################################
# Render the multi-select checkbox used for bulk install/update flows #
/**
 * Render an HTML checkbox for multi-select operations (reinstall or update) when the template qualifies.
 *
 * Depending on template flags this returns either:
 * - a checkbox for selecting multiple reinstalls when `Removable` is set and the template is not installed, has no DockerInfo, and is not blacklisted (includes `data-deletepath`), or
 * - a checkbox for selecting multiple updates when `actionCentre` and `UpdateAvailable` are set (includes `data-language`),
 * otherwise returns an empty string.
 *
 * @param array $template Template data; checks keys `Removable`, `DockerInfo`, `Installed`, `Blacklist`, `checked`, `InstallPath`, `actionCentre`, `UpdateAvailable`, and `LanguagePack`.
 * @param string $previousAppName Identifier used for the `data-name` attribute (usually a previous app filename or key).
 * @param string $name Human-readable template name used for the `data-humanName` attribute.
 * @param string $type Checkbox domain/type used for the `data-type` attribute (e.g., `docker`, `plugin`, `language`).
 * @return string An HTML `<input type="checkbox">` string when applicable, or an empty string when no checkbox should be shown.
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

##################################################################
# Render the icon (image/font-awesome) for a template card       #
/**
 * Builds HTML markup for an application's icon, preferring a Font Awesome class when provided
 * and otherwise emitting a safe <img> element (external URLs are validated and non-http(s) sources
 * fall back to a local question-mark image).
 *
 * The function reads $template['IconFA'] (Font Awesome name) and $template['Icon'] (image URL).
 * If $dockerHub is true the returned element receives the "noClick" CSS class (or uses
 * $template['imageNoClick'] when present and not a Docker Hub context).
 *
 * @param array $template Template data; expected keys used: 'IconFA', 'Icon', and optionally 'imageNoClick'.
 * @param bool $dockerHub When true, the icon element will include the "noClick" class.
 * @return string HTML string for the icon element (<i> for Font Awesome or <img> for an image).
 */
function caBuildIconMarkup(array $template, bool $dockerHub): string {
	$imageNoClick = $dockerHub ? "noClick" : ($template['imageNoClick'] ?? "");

	if (empty($template['IconFA'])) {
		/* Same protection as the popup icon path: only emit external icon
		   URLs when they're real http(s) — anything else falls back to the
		   local "?" image so a malicious template can't trigger a same-origin
		   GET against the user's GUI. */
		$iconCandidate = (string)($template['Icon'] ?? "");
		$safeIcon = validURL($iconCandidate) ? $iconCandidate : "/plugins/dynamix.docker.manager/images/question.png";
		$safeIconAttr = htmlspecialchars($safeIcon, ENT_QUOTES);
		return "
			<img class='ca_displayIcon {$imageNoClick}' src='{$safeIconAttr}' alt='Application Icon'></img>
		";
	}

	$displayIcon = $template['IconFA'] ?: ($template['Icon'] ?? "");
	$displayIconClass = startsWith($displayIcon, "icon-") ? $displayIcon : "fa fa-{$displayIcon}";

	return "<i class='ca_appPopup {$displayIconClass} displayIcon {$imageNoClick}'></i>";
}

#######################################################################
# Build the header section (name/author/category) for a template card #
/**
 * Builds the HTML header fragment for an application card.
 *
 * The fragment contains the application name, an optional warning/info icon when
 * the template includes CA or moderator comments or has additional requirements,
 * the author line (or the translated "Official Container" label when $official
 * is true), and the category line.
 *
 * @param array $template Template data; checked keys include `CAComment`, `ModeratorComment`, `Requires`, and `RequiresFile`.
 * @param string $name Display name of the application.
 * @param string $author Author or publisher string to display when not official.
 * @param string $category Category label to display below the author.
 * @param bool $official If true, the author line is replaced with the translated "Official Container" label.
 * @return string HTML fragment for the card header.
 */
function caBuildApplicationHeader(array $template, string $name, string $author, string $category, bool $official): string {
	$header = "
		<div class='ca_applicationName ellipsis'>{$name}
	";

	if (!empty($template['CAComment']) || !empty($template['ModeratorComment']) || !empty($template['Requires'])) {
		$commentIcon = "";
		$warning = "";

		if (!empty($template['CAComment']) || !empty($template['ModeratorComment'])) {
			$commentIcon = "ca_fa-comment";
			$warning = tr("Click info to see the notes regarding this application");
		}

		if (!empty($template['Requires'])) {
			if (!empty($template['RequiresFile']) && !is_file($template['RequiresFile'])) {
				$commentIcon = "ca_fa-additional";
				$warning = tr("This application has additional requirements");
			}
		}

		$header .= "&nbsp;<span class='{$commentIcon} cardWarning' title='".htmlentities($warning, ENT_QUOTES)."'></span>";
	}

	$authorDisplay = $official ? tr("Official Container") : $author;

	$header .= "
				</div>
				<div class='ca_author ellipsis'>{$authorDisplay}</div>
				<div class='cardCategory'>{$category}</div>
	";

	return $header;
}

#####################################################################
# Normalize overview/description copy for display in template cards #
/**
 * Produce a normalized, display-ready overview string for a template.
 *
 * The returned value is cleaned and formatted for card display: HTML entities are decoded,
 * bracket-style markup is converted to angle brackets, Markdown is rendered, literal newlines
 * are normalized, and remaining HTML tags are removed so the result is safe for inline display.
 * If the template is marked as featured and detected as incompatible (or `UninstallOnly`),
 * a localized warning message referencing `$name` is prepended.
 *
 * @param array $template Template data array (expects keys like `Overview`, `Description`, `Bio`, `Featured`, `Compatible`, `UninstallOnly`, and optionally `PluginURL`).
 * @param string $name Display name used in any injected compatibility warning.
 * @return string The cleaned, formatted overview text ready for presentation.
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

###############################################################################
# Build the status flag/banner (installed, updated, etc.) for a template card #
/**
 * Generate the status/banner HTML for a template card based on its state.
 *
 * Evaluates template flags in priority order and returns a small HTML fragment
 * containing a status banner (e.g., Updated, Installed, Blacklisted, Template,
 * Incompatible, Deprecated, Official, LIMETECH, BETA, Digitally Signed). If no
 * status applies, returns an empty string.
 *
 * @param array $template Template data used to determine which flag to render.
 *                         Useful keys include `UpdateAvailable`, `Installed`,
 *                         `Uninstall`, `actionCentre`, `Blacklist`,
 *                         `caTemplateExists`, `Compatible`, `VerMessage`,
 *                         `Deprecated`, `Official`, `LTOfficial`, `Beta`,
 *                         and `Trusted`.
 * @param string $flagTextStart Optional prefix text to include before some flag labels.
 * @param string $flagTextEnd Optional suffix text to include after some flag labels.
 * @return string An HTML fragment for the card status/banner, or an empty string when none applies.
 */
function caBuildCardFlag(array $template, string $flagTextStart, string $flagTextEnd): string {
	if (!empty($template['UpdateAvailable'])) {
		return "
			<div class='betaCardBackground'>
				<div class='installedCardText ca_center'>".tr("UPDATED")."</div>
			</div>";
	}

	if ((!empty($template['Installed']) || !empty($template['Uninstall'])) && empty($template['actionCentre'])) {
		return "
			<div class='installedCardBackground'>
				<div class='installedCardText ca_center'>&nbsp;&nbsp;".tr("INSTALLED")."&nbsp;&nbsp;</div>
			</div>";
	}

	if (!empty($template['Blacklist'])) {
		return "
			<div class='warningCardBackground'>
				<div class='installedCardText ca_center' title='".tr("This application template has been blacklisted")."'>".tr("Blacklisted")."{$flagTextEnd}</div>
			</div>
		";
	}

	if (!empty($template['caTemplateExists'])) {
		return "
			<div class='greenCardBackground'>
				<div class='installedCardText ca_center' title='".tr("Template already exists in Apps")."'>".tr("Template")."</div>
			</div>
		";
	}

	if (isset($template['Compatible']) && ! $template['Compatible']) {
		$verMsg = $template['VerMessage'] ?? tr("This application is not compatible with your version of Unraid");

		return "
			<div class='warningCardBackground'>
				<div class='installedCardText ca_center' title='{$verMsg}'>{$flagTextStart}".tr("Incompatible")."{$flagTextEnd}</div>
			</div>
		";
	}

	if (!empty($template['Deprecated'])) {
		return "
			<div class='warningCardBackground'>
				<div class='installedCardText ca_center' title='".tr("This application template has been deprecated")."'>".tr("Deprecated")."{$flagTextEnd}</div>
			</div>
		";
	}

	if (!empty($template['Official'])) {
		return "
			<div class='officialCardBackground'>
				<div class='installedCardText ca_center' title='".tr("This is an official container")."'>".tr("OFFICIAL")."</div>
			</div>
		";
	}

	if (!empty($template['LTOfficial'])) {
		return "
			<div class='LTOfficialCardBackground'>
				<div class='installedCardText ca_center' title='".tr("This is an official plugin")."'>".tr("LIMETECH")."</div>
			</div>
		";
	}

	if (!empty($template['Beta'])) {
		return "
			<div class='betaCardBackground'>
				<div class='installedCardText ca_center'>".tr("BETA")."</div>
			</div>
		";
	}

	if (!empty($template['Trusted'])) {
		return "
			<div class='spotlightCardBackground'>
				<div class='installedCardText ca_center' title='".tr("This container is digitally signed")."'>".tr("Digitally Signed")."</div>
			</div>
		";
	}

	return "";
}