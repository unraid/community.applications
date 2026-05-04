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
 * Replace sidebar search tokens in text with safe, clickable anchor elements.
 *
 * Scans the input for tokens of the form `//...\` and replaces each occurrence with
 * an escaped <a> element that invokes `doSidebarSearch(...)` with the token text.
 *
 * @param string $text Input text that may contain sidebar search tokens.
 * @return string The text with tokens replaced by anchor elements; if the input is not a non-empty string or contains no tokens, the original value is returned.
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
 * Format a template's overview or description into sanitized HTML for display.
 *
 * Chooses the most appropriate source among `Overview`, `OriginalOverview`,
 * `OriginalDescription`, and `Description`, then decodes HTML entities,
 * converts `[`/`]` to `<`/`>`, converts newlines to `<br>`, replaces four
 * spaces with non-breaking spaces, trims, runs the result through Markdown,
 * and strips all tags except `<br>`.
 *
 * @param array $template Template data; consults `Overview`, `OriginalOverview`,
 *                        `OriginalDescription`, and `Description` keys.
 * @return string The formatted HTML overview allowing `<br>` tags.
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
 * Prepare and populate a template's change-log display fields.
 *
 * When the template represents a plugin, replaces any embedded change log with a lazy-loading placeholder that points to the plugin URL. If the template already contains an inline `Changes` value, formats that text for safe HTML/Markdown display and assigns it to both `Changes` and `display_changes`. Otherwise, when a ChangeLog is present, inserts a lazy-loading placeholder that will fetch the XML change log later. Modifications are applied in-place to the provided `$template` array (notably `Changes` and `display_changes`).
 *
 * @param array &$template Template data array to modify; may gain `Changes` and/or `display_changes` entries.
 * @return void
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
 * Gather Docker runtime information, the current container list, and the recorded update status.
 *
 * When Docker is running, returns an associative map of container info keyed by container Name,
 * the list of containers from the provided Docker client, and the decoded update-status array
 * from CA_PATHS['dockerUpdateStatus'] (coerced to an array). When Docker is not running, returns
 * empty arrays for all three values.
 *
 * @return array An array with three elements:
 *               - 0: array<string,array> $info     Associative map of container info keyed by container Name.
 *               - 1: array $dockerRunning         List of containers as returned by $DockerClient->getDockerContainers().
 *               - 2: array $dockerUpdateStatus   Update status decoded from CA_PATHS['dockerUpdateStatus'] or [].
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
 * Locate a template entry in the displayed community list by application number.
 *
 * Searches the provided display data for a community template whose `InstallPath`
 * equals the given application number; if none is found, repeatedly searches for
 * an entry whose `Path` equals the application number until an existing entry
 * is found or the list is exhausted.
 *
 * @param array $displayed Array containing a 'community' key with a list of templates.
 * @param mixed $appNumber The application identifier to match against `InstallPath` or `Path`.
 * @return array [array|null, int|false] A two-element tuple: the matched template array (or `null` if not found)
 *     and the index of the matched entry (or `false` if not found).
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
/**
 * Determine whether a template corresponds to a running Docker container and extract matching identifiers.
 *
 * Modifies $template['Repository'] to prepend "library/" when it contains no slash.
 *
 * @param array &$template Template data (expects keys: 'Plugin', 'Repository', 'Name', and for plugins 'PluginURL'). Passed by reference; 'Repository' may be updated.
 * @param array $dockerRunning List of running containers where each entry provides at least 'Image' and 'Name'.
 * @return array An indexed array containing:
 *               - $selected: `true` if a running container matches this template, `null` otherwise.
 *               - $name: the matching container's `Name` when selected, `null` otherwise.
 *               - $pluginName: basename of `PluginURL` for plugin templates, `null` for non-plugins.
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
/****
 * Normalize a template's "Requires" text into HTML suitable for display.
 *
 * Converts carriage returns/newlines to `<br>`, strips disallowed tags while preserving `<br>`,
 * renders Markdown, and converts tokenized sidebar search markers into safe clickable links.
 *
 * @param string|null $requires The raw "Requires" field value.
 * @return string|null The normalized HTML string, or the original falsy value when input is empty.
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
 * Build a list of support-link definitions for a template using its social/contact fields and repository fallbacks.
 *
 * Each entry is an associative array with keys `icon`, `link`, and `text`, suitable for rendering support buttons.
 *
 * @param array $template Template data containing optional fields: `Project`, `Discord`, `Facebook`, `Reddit`, `Support`, `SupportLanguage`, `Registry`, `caTemplateURL`, and `TemplateURL`.
 * @param array $allRepositories Map of repository identifiers to repository metadata; used to fall back to a repository `Discord` URL when the template `Discord` is absent.
 * @return array List of support-link definitions; each element is `["icon" => string, "link" => string, "text" => string]`.
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
	 * Prepare trend and download chart data and update template display fields.
	 *
	 * Normalizes category and icon, derives an overview fallback, appends canvas placeholders
	 * to the provided template description when trend/download data are present, sets a
	 * localized changelog message for templates without a Language, formats trend date labels,
	 * and computes per-bin download deltas and totals for charting.
	 *
	 * @param array &$template Template array (modified in-place: may set `Category`, `Icon`, `display_changelogMessage`, and may append canvases to its description-related fields).
	 * @param string &$templateDescription HTML description string to which canvas placeholders may be appended.
	 * @return array Associative array with keys:
	 *               - 'chartLabel' (array|string): labels for the trend chart (from `trendsDate`).
	 *               - 'downloadLabel' (array|string): labels for the download chart (from `trendsDate`).
	 *               - 'down' (int[]): per-bin download delta values for charting.
	 *               - 'totalDown' (int[]): raw download bins.
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
	 * Set pin-related display fields on a template based on whether it is pinned.
	 *
	 * When a pin entry exists for the key "{Repository}&{SortName}" in $pinnedApps, the template is configured
	 * for the pinned state; otherwise it is configured for the unpinned state.
	 *
	 * The following keys are written into $template:
	 * - 'pinned'       : button label for the current action
	 * - 'pinnedAlt'    : alternate label for the inverse action
	 * - 'pinnedTitle'  : tooltip text for the pin control
	 * - 'pinnedClass'  : CSS class 'pinned' or 'unpinned'
	 *
	 * @param array &$template Template array that will be modified in-place.
	 * @param array $pinnedApps Associative array of pinned entries keyed by "Repository&SortName".
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
	 * Prepare and load a language pack for a template and return the resolved country code.
	 *
	 * When the template declares a language, determines the target country code from
	 * `LanguageDefault` (falls back to `en_US`) or `LanguagePack`. If the country code
	 * is not `en_US`, ensures a cached language file exists under the temporary files
	 * directory (downloading it if missing) and loads it with `parse_lang_file`. The
	 * parsed language entries are written to the `$language` reference; when no pack
	 * is used or the file cannot be loaded, `$language` is set to an empty array.
	 *
	 * @param array $template Reference to the template array; expects keys `Language`, `LanguageDefault`, and `LanguagePack`.
	 * @param array $language Reference that will be populated with parsed language entries or an empty array.
	 * @return string|null The resolved country code (for example, "en_US") when a language is declared, or `null` if the template has no language.
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
	 * Build the action-menu context for a template (docker, plugin, or language) based on current system state.
	 *
	 * Populates the list of action definitions appropriate for the template (install, update, uninstall, settings,
	 * web UI links, etc.) and returns that list. The provided $template is updated in-place with selection/install
	 * flags where applicable (for example `Installed` and `UpdateAvailable`).
	 *
	 * @param array &$template Template data; may be modified to reflect discovered state (e.g. `Installed`, `UpdateAvailable`).
	 * @param array $info Runtime Docker/container metadata indexed by container name (used to derive WebUI URLs and installed template paths).
	 * @param array $dockerUpdateStatus Mapping of normalized repository strings to update status entries.
	 * @param bool $selected True when the template corresponds to a currently selected/installed container.
	 * @param string $name Container name corresponding to the selected instance (when applicable).
	 * @param string $pluginName Basename of the plugin file (when template is a plugin).
	 * @return array An ordered list of action entries; each entry is an associative array describing a UI action
	 *               (commonly keys: `icon`, `text`, `action`, or `divider`) or a single-item placeholder like `["text"=>"Pending"]`.
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
	 * Build and return the actions list for a language pack entry and update the template with language-related flags/changes.
	 *
	 * The function appends install/switch/update/uninstall actions based on whether the specified language pack
	 * (identified by $countryCode) is installed, matches the current system locale, or has a pending/installed update.
	 * It also sets `$template['UpdateAvailable']` when an installed language has an update and sets or unsets
	 * `$template['Changes']` for non-`en_US` language packs to point to the language changelog.
	 *
	 * @param array &$template Template data; modified in-place to reflect update availability and `Changes` link.
	 * @param string|null $countryCode ISO country code for the language pack (e.g., `en_US`). May be null.
	 * @param array $actionsContext Existing actions to which language actions will be appended.
	 * @return array The resulting actions context array, possibly replaced with a single pending entry when a pending package exists.
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
 * Gather Docker runtime and update-status context used by the UI.
 *
 * When Docker is running this loads system Docker info and the docker update-status
 * JSON (coerced to an array when decoding does not produce one). When Docker is not
 * running both `info` and `dockerUpdateStatus` are returned as empty arrays.
 *
 * Also computes a `dockerWarningFlag` (string `"true"`/`"false"`) that indicates whether
 * a Docker-related warning should be shown, and a `dockerNotEnabled` value used by
 * the warning display:
 * - `"false"` when no warning is required,
 * - `"true"` when a generic warning is needed,
 * - `1` when the array is started but Docker is not enabled,
 * - `2` when the array is started and Docker was enabled but failed to start,
 * - `3` when the array (md) is not started.
 *
 * A small `displayHeader` script string is also produced that calls the client-side
 * addDockerWarning(...) initializer and exposes the `dockerNotEnabled` flag.
 *
 * @return array{
 *   info: array,                  // result of getAllInfo() or [] when Docker not running
 *   dockerUpdateStatus: array,    // decoded docker update-status as array (or [])
 *   dockerNotEnabled: int|string, // one of "false", "true", or numeric codes 1|2|3
 *   dockerWarningFlag: string,    // "true" when a warning should be shown, otherwise "false"
 *   displayHeader: string         // inline <script> markup to initialize the UI warning
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
 * Normalize a selected-apps payload and produce a lookup object of checked entries.
 *
 * Ensures the returned `$selectedApps` is an array with keys `docker` and `plugin`
 * (each an array). Builds `$checkedOffApps` by merging those two lists and converting
 * entries into an object via `arrayEntriesToObject`.
 *
 * @param mixed $selectedApps The incoming selection payload (may be null, array, or other falsy value).
 * @return array [ $selectedApps, $checkedOffApps ] where:
 *               - `$selectedApps` is the normalized array with `docker` and `plugin` keys.
 *               - `$checkedOffApps` is an object produced by `arrayEntriesToObject` for quick membership lookup.
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
 * Return the subset of templates that belong on the requested page.
 *
 * @param array $file Full list of templates (ordered).
 * @param int $pageNumber 1-based page index to extract.
 * @return array The list of templates to display for the requested page (may be empty).
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
 * Apply moderation overrides (blacklist or deprecation) to a template using repository maps.
 *
 * If the template is not a repository template, sets `Blacklist` and/or `Deprecated`
 * to `true` and populates `ModeratorComment` from the corresponding entry in
 * `$extraBlacklist` or `$extraDeprecated` when the repository matches. Existing
 * `Blacklist`/`Deprecated` flags are not overwritten.
 *
 * @param array $template Associative template data; returned with any applied overrides.
 * @param array $extraBlacklist Map of repository => moderator message used to mark blacklist.
 * @param array $extraDeprecated Map of repository => moderator message used to mark deprecated.
 * @return array The template array after applying moderation overrides.
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
 * Normalize and convert a template's comment fields and its `Requires` text for safe display.
 *
 * ModeratorComment and CAComment are processed with sidebar-search link conversion.
 * If `Requires` is present, it is trimmed, newlines/carriage-returns are converted to `<br>`,
 * single quotes are escaped to `&#39;`, HTML tags are stripped except `<br>`, then rendered with
 * Markdown and finally passed through the sidebar-search link conversion.
 *
 * @param array $template Template data array to process; specific fields updated in-place are `ModeratorComment`, `CAComment`, and `Requires`.
 * @return array The input `$template` with normalized `ModeratorComment`, `CAComment`, and (when present) `Requires` fields.
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
 * Build action context for a Docker template and determine its installed/update state.
 *
 * Examines running Docker containers and update status to mark the template as installed or updatable,
 * and constructs an actions context (install/edit/uninstall/update/etc.) appropriate to the template and current runtime state.
 *
 * @param array $template The template metadata; may be annotated with keys like `Repository`, `Name`, `InstallPath`, `ID`, `BranchID`, `PortsUsed`, `Blacklist`, `Compatible`, and `Deprecated`. This array may be modified to set `Installed` and `UpdateAvailable`.
 * @param array $info Array of running container information (each entry expected to contain at least `Name`, `Image`, `url`, `running`, and `template`).
 * @param array $dockerUpdateStatus Mapping of normalized repository strings to update status entries (used to determine if an update is available).
 * @return array [ $template, $actionsContext ] where `$template` is the (possibly modified) template array and `$actionsContext` is a sequential list of action definitions (associative arrays with keys like `icon`, `text`, `action`, `divider`, or `alternate`) suitable for rendering action buttons.
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
 * Builds action entries for a plugin template and updates the template's install/update state.
 *
 * Modifies the provided template data to reflect whether the plugin is installed, whether an update
 * is available, and adjusts the plugin version when a newer temporary download exists. Constructs
 * an actions context array with entries such as Install/Reinstall, Update, Settings, Uninstall,
 * and Remove from Previous Apps as applicable.
 *
 * @param array $template The plugin template data (repository fields, URLs, flags and metadata). The returned template may include updated keys such as `Installed`, `UpdateAvailable`, and `pluginVersion`.
 * @return array An array with two elements: 0) the possibly modified template array, and 1) an actionsContext array of action definitions (each action is an associative array describing icon, text, action, and optional divider).
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
 * Build and return language-pack actions and update the template's language-related state.
 *
 * Evaluates whether the template's language pack is installed, up-to-date, or pending and
 * appends appropriate actions (install, switch, update, uninstall) to the provided actions
 * context. Also sets template flags such as `Installed` and `UpdateAvailable`.
 *
 * @param array $template Template data; expected keys used include `LanguageDefault`, `LanguagePack`, `TemplateURL`, `SwitchLanguage`, `UpdateLanguage`, and `Uninstall`.
 * @param array $actionsContext Existing list of action entries to be extended; each entry is an associative array describing an action or divider.
 * @return array A two-element array: the (possibly modified) `$template` and the updated `$actionsContext`.
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
 * Build a small inline script that renders page navigation controls for the template list.
 *
 * Optionally forces a 25-items-per-page limit for Docker searches and will not produce output when navigation is disabled via settings.
 *
 * @param int $pageNumber Current page index (1-based or 0-based as used by caller).
 * @param int $totalApps Total number of applications available for paging.
 * @param bool $dockerSearch When true, use Docker-search mode (forces per-page to 25 and selects Docker search page function).
 * @param bool $displayCount Whether to include the result count in the rendered navigation.
 * @return string|null HTML <script> tag invoking the client-side navigation renderer, or null when navigation is disabled by configuration. */
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
 * Summarizes template counts and download statistics for templates belonging to a repository.
 *
 * Filters out branch templates, blacklisted templates, deprecated templates when global
 * hideDeprecated is enabled, and incompatible templates when global hideIncompatible is enabled.
 *
 * @param array  $templates  List of template records to summarize.
 * @param string $repository The repository name to filter templates by (matches `RepoName`).
 * @return array Associative totals with keys:
 *               - `apps` (int): number of included templates.
 *               - `languages` (int): number of language-pack templates.
 *               - `plugins` (int): number of plugin templates.
 *               - `docker` (int): number of docker templates (templates with `Registry`).
 *               - `downloads` (int): sum of `downloads` for docker templates that report downloads.
 *               - `downloadDockerCount` (int): count of docker templates that reported `downloads`.
 *               - `avgDownloads` (int): integer average downloads per reporting docker template (0 when none).
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
 * Build an HTML donation section for a repository when a valid donate URL is provided.
 *
 * The function looks for a valid `DonateLink` in the `$repo` array and, if present,
 * returns a small block of HTML containing optional `DonateText` and a localized
 * "Donate" button linking to the provided URL. If `DonateLink` is missing or not
 * a valid URL, an empty string is returned.
 *
 * @param array $repo Repository data; expects `DonateLink` (URL) and optional `DonateText`.
 * @return string HTML markup for the donate section, or an empty string when no valid donate link exists.
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
 * Build an HTML fragment that displays repository photos and videos as media thumbnails.
 *
 * The function looks for `Photo` and `Video` entries in `$repo` (each may be a string or an array of URLs),
 * validates each URL, and emits thumbnail markup wrapped in <span> elements with `data-mfp-src` attributes
 * suitable for Magnific Popup/lightbox usage.
 *
 * @param array $repo Repository metadata; recognized keys:
 *                    - `Photo`: string|array of image URLs to include as screenshots.
 *                    - `Video`: string|array of video URLs (YouTube or other) to include as playable thumbnails.
 * @return string An HTML string containing zero or more thumbnail <span> elements inside a containing <div>.
 *                Returns an empty string when no valid photo or video URLs are present.
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
 * Build an HTML block of external repository links (webpage, forum, social) for a repository card.
 *
 * Each supported link key (WebPage, Forum, profile, Facebook, Reddit, Twitter, Discord) is included
 * only when present in $repo and its value is a valid URL; URLs are escaped before insertion.
 *
 * @param array $repo Associative repository data; expected keys include 'WebPage', 'Forum', 'profile',
 *                    'Facebook', 'Reddit', 'Twitter', and 'Discord' with URL string values.
 * @return string HTML fragment containing zero or more escaped link buttons wrapped in a div.
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

##################################################################
# Build the statistics table shown within repository popups      #
/**
 * Build the repository statistics HTML block for a repository card.
 *
 * Generates a localized statistics section and table rows based on repository metadata
 * and aggregated totals (apps, docker/plugin counts, languages, and downloads).
 *
 * @param array $repo Repository metadata; may include `FirstSeen` (UNIX timestamp) and `url`.
 * @param array $totals Aggregated counts and metrics with keys such as `docker`, `plugins`, `languages` (optional), `apps`, `downloadDockerCount`, `downloads`, and `avgDownloads`.
 * @return string HTML markup for the statistics header and table.
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
 * Render page navigation for Docker results.
 *
 * @param int $num_pages The total number of Docker result pages.
 * @param int $pageNumber The current page number (1-based).
 * @return string HTML script markup for the page navigation controls.
 */
function dockerNavigate($num_pages, $pageNumber) {
	return getPageNavigation($pageNumber,$num_pages * 25, true);
}

#################################################################################
# Attempt to find a template matching a repository name (with :latest fallback) #
/**
 * Locate a template in a list by repository name, falling back to a `:latest` suffix.
 *
 * Searches the provided templates for an entry whose `Repository` equals `$repository`.
 * If no match is found, searches again for a repository equal to `$repository:latest`.
 *
 * @param array $templates List of template associative arrays.
 * @param string $repository Repository identifier to match (e.g., "owner/image" or "image:tag").
 * @return int|false The numeric index of the matching template in `$templates`, or `false` if no match is found.
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
 * Enriches a Docker Hub search result with display defaults, an install action (unless disabled),
 * and optional Community Applications template data when a matching template exists.
 *
 * When installs are enabled, the result will include an `actionsContext` entry to trigger installation.
 * If a matching, non-deprecated/non-blacklisted CA template is found, template metadata (icon, overview/description)
 * and a "Show Template" action replace or augment the Docker Hub result and `caTemplateExists` is set.
 *
 * @param array $result The raw Docker Hub result record (e.g. keys: Repository, Name, Description); this array is returned modified.
 * @param array $templates Array of Community Applications templates used to find a matching template by repository.
 * @param bool $installsDisabled When true, install actions are omitted from the returned result.
 * @return array The transformed result array containing UI fields such as `Icon`, `Category`, `Description`, `display_dockerName`,
 *               `similarSearch`, and optional `actionsContext`, `caTemplateExists`, and `ID`.
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

##################################################################
# Resolve the CA app type class/title for a template card        #
/**
 * Determine the UI app type class and its localized title for a template.
 *
 * Recognizes one of the types: "appRepository", "appPlugin", "appDocker", "appLanguage", or "appDriver".
 *
 * @param array $template Associative template data used to infer the app type (keys examined include 'RepositoryTemplate', 'Plugin', 'Language', and 'Category').
 * @return array [string $appType, string $typeTitle] First element is the app type string; second element is a localized descriptive title (empty string when not applicable).
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
 * Extracts the primary category token from a category string.
 *
 * @param string|null $category The category text which may contain secondary qualifiers separated by spaces or colons.
 * @return string The first token from `$category` before any space or colon, or an empty string if `$category` is empty or null.
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
 * Resolve the display author for a template, applying friendly normalization for repository-derived names.
 *
 * If the template comes from Docker Hub, the template's Author field is returned. For plugins the Author field is used; otherwise RepoShort (or the provided repo name) is used. When the resolved author exactly equals the repository name and contains patterns like "' Repository", "'s Repository", or " Repository", those patterns are replaced with localized, human-friendly variants (e.g., "X's Repository" or "X Repository").
 *
 * @param array  $template Template data array; may contain keys such as `DockerHub`, `Plugin`, `Author`, and `RepoShort`.
 * @param string $repoName Repository name fallback used when `RepoShort` is not present.
 * @return string The resolved, display-ready author name (possibly localized and normalized).
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
 * Builds support link entries for an application template.
 *
 * @param array $template Template data; uses keys `Project`, `Discord`, `Support`, `SupportLanguage`, and `Registry` when present.
 * @return array An array of support link definitions. Each entry is an associative array with keys:
 *               - `icon`: CSS/icon identifier,
 *               - `link`: destination URL,
 *               - `text`: display label.
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
 * Build a lazy-loading README popup placeholder for a template when a usable GitHub README URL is present.
 *
 * If the template provides a GitHub URL that points to a README (fragment "#README", a path ending in "/README.md",
 * or the repository root), returns an HTML placeholder <div> that contains a "View README on Web" link and a body
 * initialized with a localized "Loading README..." message. The placeholder includes data attributes with a stable
 * SHA-256 cache key and raw.githubusercontent.com URLs for `main` and `master` so the README can be fetched later.
 *
 * @param array $template Template data; the function reads the 'ReadMe' key for the README URL.
 * @return string An HTML string containing the README placeholder div when a valid GitHub README URL is found, or an
 * empty string otherwise.
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
 * Builds context data for rendering a repository card from a template entry.
 *
 * Constructs support links, derives a display name from the provided author string,
 * clears a set of template fields via an overrides map, and returns a structured
 * associative array used by the UI to render a repository card.
 *
 * @param array  $template The template entry array (may contain support links).
 * @param string $repoName The repository identifier (used for element id).
 * @param string $author   The original author string used to derive the display name.
 * @return array An associative array with keys:
 *               - 'holderClass': wrapper CSS class,
 *               - 'cardClass': card CSS class,
 *               - 'id': HTML id derived from $repoName,
 *               - 'supportContext': list of support link entries,
 *               - 'actionsContext': empty actions list,
 *               - 'name': derived display name,
 *               - 'author': empty string (reserved),
 *               - 'overrides': map of template fields cleared to empty strings.
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
/****
 * Builds the bottom-line section and holder markup for an application card.
 *
 * Chooses a Docker Hub layout when the template contains a valid `DockerHub` URL,
 * otherwise builds a regular application holder with data attributes (app path,
 * name, repository and optional plugin URL). Produces the holder opening markup,
 * the inner bottom-line HTML (buttons), and the CSS class used to mark the
 * clickable background area.
 *
 * @param array $template Template data array (may contain `DockerHub`, `PluginURL`, `Path`, `similarSearch`, etc.).
 * @param string $cardClass CSS class applied to the card's info/details button for non-Docker cards.
 * @param string|null $popupType Optional popup type class to include on the holder element.
 * @param string $holderClass Additional holder CSS class applied for non-Docker cards.
 * @param string $class General card class applied to the holder for non-Docker cards.
 * @param string $name Display name of the application (used in data-appname).
 * @param string $repoName Repository name (used in data-repository).
 * @return array [$cardStart, $card, $backgroundClickable] where:
 *               - $cardStart is the opening holder <div> markup (string),
 *               - $card is the inner bottom-line HTML containing action/info buttons (string),
 *               - $backgroundClickable is the CSS class name used to mark the clickable background (string).
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
 * Render support button(s) for a template from a support context array.
 *
 * Filters the provided support context for valid http(s) entries and returns
 * HTML for either a single direct support button (when one valid entry exists)
 * or a support-context button that exposes multiple entries.
 *
 * @param array $supportContext Array of support entries; each entry should be an associative array with at least `link` and `text` keys.
 * @param string $name Template display name used to derive a stable element id.
 * @param string $id Template id appended to the derived element id.
 * @return string HTML markup for the rendered support button(s). Empty string when no valid support entries are present.
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
 * Render the actions control for a template card.
 *
 * When a single "Install" action is present, returns a single clickable button that invokes the action inline.
 * Otherwise returns an "Actions" context button that embeds the full actions context as a JSON-encoded `data-context` attribute.
 *
 * @param array  $actionsContext List of action definitions; each entry may contain `text`, `action`, and optional `alternate`.
 * @param string $pluginUrl      Value used for the `data-pluginURL` attribute on the rendered element.
 * @param string $languagePack   Value used for the `data-languagePack` attribute on the rendered element.
 * @param string $name           Template display name used when constructing an element id for the context button.
 * @param string $id             Template identifier appended to the sanitized name when constructing the element id.
 * @return string HTML markup for the actions button(s).
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
 * Render the favourite indicator span for a template card.
 *
 * When the template is marked as a favourite (`$template['ca_fav']`), the returned span
 * includes a visible class and a localized `title` describing whether the favourite is
 * for the repository or the application. Otherwise the returned span uses the hidden class.
 *
 * @param array $template Template data array; checked for the `ca_fav` flag.
 * @param string $repoName Repository name used for the `data-repository` attribute (single quotes removed).
 * @param bool $repositoryTemplate If true, the title text refers to the repository; otherwise it refers to the application.
 * @return string An HTML `<span>` element for the favourite indicator.
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
 * Render the pinned indicator span for a template.
 *
 * The returned span is hidden when the template is not pinned and includes a
 * tooltip and a data-pindata attribute composed of the template repository
 * (prefixed with "library/" when no namespace is present) followed by the
 * template SortName.
 *
 * @param array $template Template record; expects optional keys `Repository`, `SortName`, and `Pinned`.
 * @return string An HTML <span> element used as the pinned indicator.
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
/**
 * Map an application card type to the multi-select checkbox group identifier.
 *
 * Supported mappings:
 * - "appDocker"  => "docker"
 * - "appPlugin"  => "plugin"
 * - "appDriver"  => "plugin"
 * - "appLanguage"=> "language"
 *
 * @param string $appType The template's application type identifier.
 * @return string The checkbox group name (`"docker"`, `"plugin"`, or `"language"`), or an empty string if no mapping exists.
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
 * Render a multi-select checkbox input for a template when the template is eligible for bulk actions.
 *
 * Returns an HTML input element configured for either "reinstall" (removable previous installs) or
 * "update" (action centre with updates available) bulk operations; returns an empty string when no
 * multi-select checkbox should be shown.
 *
 * @param array $template Template metadata used to determine eligibility and to populate data attributes.
 * @param string $previousAppName Identifier for the previous app instance placed in the `data-name` attribute.
 * @param string $name Human-friendly application name placed in the `data-humanName` attribute.
 * @param string $type Checkbox category (`docker`, `plugin`, `language`, etc.) placed in the `data-type` attribute.
 * @return string The HTML checkbox element or an empty string when not applicable.
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
 * Render the template's icon as an HTML element suitable for card display.
 *
 * Chooses a FontAwesome <i> element when `IconFA` is present; otherwise emits an
 * <img> tag. External icon URLs are only used when they are valid http(s) URLs;
 * otherwise a local question-mark image is used. Adds a `noClick` class when
 * $dockerHub is true or when the template requests a non-clickable image.
 *
 * @param array $template Template record containing icon fields (`IconFA`, `Icon`, `imageNoClick`).
 * @param bool $dockerHub When true, force the `noClick` CSS class for the output.
 * @return string HTML markup for the icon element.
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
 * Builds the HTML header block for an application card.
 *
 * Includes the application name, an optional warning/info icon when the template
 * contains reviewer comments or additional requirements, the author display
 * (or localized "Official Container" when applicable), and the category.
 *
 * The warning icon and its tooltip are shown when `CAComment` or `ModeratorComment`
 * are present, or when `Requires` is set and a referenced `RequiresFile` is missing.
 *
 * @param array $template Template data; used fields: `CAComment`, `ModeratorComment`, `Requires`, `RequiresFile`.
 * @param string $name The display name of the application.
 * @param string $author The author or maintainer text to display when not official.
 * @param string $category The category label for the application.
 * @param bool $official When true, the author area displays the localized "Official Container" label.
 * @return string HTML fragment for the application header (name, optional icon, author, and category).
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
 * Produce a cleaned, display-ready overview for a template.
 *
 * Chooses the first available descriptive field (Overview, Description, or Bio), normalizes entities and markup for presentation (decodes HTML entities, converts bracket-style tags and newlines, processes Markdown, and removes HTML tags for plain display), and prepends an incompatibility warning span when the template is featured but incompatible or uninstall-only on the host.
 *
 * @param array $template Template data array (expects keys like `Overview`, `Description`, `Bio`, `Featured`, `Compatible`, `UninstallOnly`, and `PluginURL`).
 * @param string $name Human-readable template name used in incompatibility messages.
 * @return string The normalized overview text ready for display (HTML warning spans may be prepended when applicable).
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
 * Produce a small HTML banner indicating the template's highest-priority status flag.
 *
 * Checks the template for status flags in the following precedence and returns the
 * corresponding small HTML banner (string): UpdateAvailable, Installed/Uninstall
 * (unless actionCentre is set), Blacklist, caTemplateExists, Compatible (explicitly
 * false), Deprecated, Official, LTOfficial, Beta, Trusted. If none apply an empty
 * string is returned.
 *
 * @param array $template Associative template data; function inspects keys such as
 *                        'UpdateAvailable', 'Installed', 'Uninstall', 'actionCentre',
 *                        'Blacklist', 'caTemplateExists', 'Compatible', 'VerMessage',
 *                        'Deprecated', 'Official', 'LTOfficial', 'Beta', and 'Trusted'.
 * @param string $flagTextStart Text to prepend before certain flag labels (used for compatibility/incompatible messaging).
 * @param string $flagTextEnd Text to append after certain flag labels (used for blacklist/deprecated/incompatible messaging).
 * @return string HTML banner for the selected flag, or an empty string when no flag applies.
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