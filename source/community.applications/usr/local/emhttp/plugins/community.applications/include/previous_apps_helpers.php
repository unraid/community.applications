<?php

class PreviousAppsHelpers {
	/* Strip a docker image tag without breaking registry ports.
	   `registry:5000/ns/app:latest` → `registry:5000/ns/app`
	   `library/foo:latest`           → `library/foo`
	   `library/foo`                  → `library/foo`
	   The port colon comes BEFORE the last slash; the tag colon comes AFTER. */
	private static function stripImageTag(string $repository): string {
		$lastSlash = strrpos($repository, "/");
		$lastColon = strrpos($repository, ":");
		if ($lastColon === false) return $repository;
		if ($lastSlash !== false && $lastColon < $lastSlash) return $repository;
		return substr($repository, 0, $lastColon);
	}

	public static function clearPreviousAppsCaches() {
		$paths = [
			'community-templates-allSearchResults',
			'community-templates-catSearchResults',
			'repositoriesDisplayed',
			'startupDisplayed',
			'dockerSearchActive'
		];

		foreach ($paths as $pathKey) {
			if ( isset(CA_PATHS[$pathKey]) ) {
				@unlink(CA_PATHS[$pathKey]);
			}
		}
	}

	public static function resolvePreviousAppsContext($enableActionCentre) {
		if ( $enableActionCentre ) {
			return ['installed' => "action", 'filter' => ""];
		}

		$installed = getPost("installed","");
		$filter = getPost("filter","");
		self::clearPreviousAppsCaches();

		return ['installed' => $installed, 'filter' => $filter];
	}

	public static function loadDockerUpdateStatus($dockerRunning) {
		if ( ! $dockerRunning ) {
			return [];
		}

		$status = readJsonFile(CA_PATHS['dockerUpdateStatus']);

		return $status ?: [];
	}

	public static function collectDockerApplications($dockerRunning, $installed, $filter, $info, &$updateCount, $templates, $extraBlacklist, $extraDeprecated, $dockerUpdateStatus) {
		if ( ! $dockerRunning ) {
			return [];
		}

		if ( $filter && $filter !== "docker" ) {
			return [];
		}

		$allFiles = glob(CA_PATHS['dockerManTemplates']."/*.xml") ?: [];
		$isActionCentre = ($installed === "action");

		if ( $installed === "true" || $isActionCentre ) {
			return self::collectInstalledDockerApplications($allFiles, $info, $templates, $dockerUpdateStatus, $extraBlacklist, $extraDeprecated, $isActionCentre, $updateCount);
		}

		return self::collectLegacyDockerApplications($allFiles, $info, $templates);
	}

	public static function collectPluginApplications($installed, $filter, $templates, &$updateCount) {
		if ( $filter && $filter !== "plugins" ) {
			return [];
		}

		$isActionCentre = ($installed === "action");

		if ( $installed === "true" || $isActionCentre ) {
			return self::collectInstalledPluginApplications($templates, $isActionCentre, $updateCount);
		}

		return self::collectLegacyPluginApplications($templates);
	}

	private static function collectInstalledDockerApplications($allFiles, $info, $templates, $dockerUpdateStatus, $extraBlacklist, $extraDeprecated, $isActionCentre, &$updateCount) {
		$displayed = [];

		foreach ($allFiles as $xmlfile) {
			$template = readXmlFile($xmlfile);
			if ( ! $template ) {
				continue;
			}

			$template['Overview'] = fixDescription($template['Overview']);
			$template['Description'] = $template['Overview'];
			$template['CardDescription'] = $template['Overview'];
			$template['InstallPath'] = $xmlfile;
			$template['UnknownCompatible'] = true;

			$containerID = false;
			$isRunning = false;
			/* Default $catalogRepo to the original (catalog) Repository so
			   moderation lookups still work when the search loop below finds a
			   running container but no catalog template, and the Repository
			   mutation never happens. */
			$catalogRepo = $template['Repository'];

			foreach ($info as $installedDocker) {
				if ( $installedDocker['Name'] != $template['Name'] ) {
					continue;
				}

				if ( ! startsWith(str_replace("library/","",$installedDocker['Image']), $template['Repository']) && ! startsWith($installedDocker['Image'],$template['Repository']) ) {
					continue;
				}

				$isRunning = true;
				$searchResult = searchArray($templates,'Repository',$template['Repository']);
				if ( $searchResult === false ) {
					$searchResult = searchArray($templates,'Repository',self::stripImageTag($template['Repository']));
				}

				if ( $searchResult !== false ) {
					if ( ($template['TemplateURL'] ?? false) ) {
						if ( ($templates[$searchResult]['TemplateURL'] ?? INF) != $template['TemplateURL'] ) {
							$search = searchArray($templates,'TemplateURL',$template['TemplateURL']);
							$searchResult = $search === false ? $searchResult : $search;
						}
					}

					$tempPath = $template['InstallPath'];
					$containerID = $templates[$searchResult]['ID'];
					$tmpOvr = $template['Overview'];
					$template = $templates[$searchResult];
					$template['Name'] = $installedDocker['Name'];
					$template['Overview'] = $tmpOvr;
					$template['CardDescription'] = $tmpOvr;
					$template['InstallPath'] = $tempPath;
					$template['SortName'] = str_replace("-"," ",$template['Name']);
					/* Preserve the catalog Repository before we overwrite it with the
					   running image name — moderation overrides ($extraBlacklist /
					   $extraDeprecated) are keyed by the canonical catalog repository,
					   and the running image often carries a tag or registry prefix
					   that wouldn't match those keys. */
					$catalogRepo = $template['Repository'];
					$template['Repository'] = $installedDocker['Image'];
				}

				break;
			}

			if ( ! $isRunning ) {
				continue;
			}

			$template['Uninstall'] = true;
			$template['ID'] = $containerID;

			if ( $isActionCentre ) {
				/* "Already tagged" means a colon AFTER the last slash — `registry:5000/repo`
				   has a port, not a tag, and still needs `:latest` appended. */
				$repoStr = $template['Repository'];
				$lastSlash = strrpos($repoStr, "/");
				$colonAfterPath = $lastSlash !== false ? strpos($repoStr, ":", $lastSlash) : strpos($repoStr, ":");
				$tmpRepo = $colonAfterPath !== false ? $repoStr : $repoStr.":latest";
				if ( strpos($tmpRepo,"/") === false ) {
					$tmpRepo = "library/$tmpRepo";
				}

				$status = $dockerUpdateStatus[$tmpRepo]['status'] ?? null;
				if ( $tmpRepo && ($status === "false" || $status === false) ) {
					$template['actionCentre'] = true;
					$template['UpdateAvailable'] = true;
					$updateCount++;
				}

				if ( ! ($template['Blacklist'] ?? false) && ! ($template['Deprecated'] ?? false) ) {
					/* Look up overrides by $catalogRepo (saved above) — $template['Repository']
					   here is the running image name and won't match the catalog-keyed override map. */
					$overrideKey = $catalogRepo ?? $template['Repository'];
					if ( $extraBlacklist[$overrideKey] ?? false ) {
						$template['Blacklist'] = true;
						$template['ModeratorComment'] = $extraBlacklist[$overrideKey];
					}
					if ( $extraDeprecated[$overrideKey] ?? false ) {
						$template['Deprecated'] = true;
						$template['ModeratorComment'] = $extraDeprecated[$overrideKey];
					}
				}

				if ( ! ($template['Blacklist'] ?? false) && ! ($template['Deprecated'] ?? false) && ! ($template['actionCentre'] ?? null) ) {
					continue;
				}
			}

			if ( $isActionCentre ) {
				$template['actionCentre'] = true;
			}

			$displayed[] = $template;
		}

		return $displayed;
	}

	private static function collectLegacyDockerApplications($allFiles, $info, $templates) {
		$displayed = [];

		foreach ($allFiles as $xmlfile) {
			$template = readXmlFile($xmlfile);
			if ( ! $template ) {
				continue;
			}
			if ( $template['Blacklist'] ?? false ) {
				continue;
			}

			$template['Overview'] = fixDescription($template['Overview']);
			$template['Description'] = $template['Overview'];
			$template['CardDescription'] = $template['Overview'];
			$template['InstallPath'] = $xmlfile;
			$template['UnknownCompatible'] = true;
			$template['Removable'] = true;

			$isRunning = false;
			foreach ($info as $installedDocker) {
				if ( ! startsWith(str_replace("library/","",$installedDocker['Image']), $template['Repository']) && ! startsWith($installedDocker['Image'],$template['Repository']) ) {
					continue;
				}

				if ( $installedDocker['Name'] == $template['Name'] ) {
					$isRunning = true;
					continue;
				}
			}

			if ( $isRunning ) {
				continue;
			}

			$foundflag = false;
			$testRepo = self::stripImageTag($template['Repository']);

			if ( $template['TemplateURL'] ?? false ) {
				$search = searchArray($templates,'TemplateURL',$template['TemplateURL']);
				if ( $search !== false ) {
					$foundflag = true;

					$tempPath = $template['InstallPath'];
					$tempName = $template['Name'];
					$tempOvr = $template['Overview'];
					$template = $templates[$search];
					$template['Overview'] = $tempOvr;
					$template['Description'] = $tempOvr;
					$template['CardDescription'] = $tempOvr;
					$template['Removable'] = true;
					$template['InstallPath'] = $tempPath;
					$template['Name'] = $tempName;
					$template['SortName'] = str_replace("-"," ",$template['Name']);
				}
			}

			if ( ! $foundflag ) {
				foreach ($templates as $appTemplate) {
					if ( ! startsWith($appTemplate['Repository'],$testRepo) ) {
						continue;
					}

					$tempPath = $template['InstallPath'];
					$tempName = $template['Name'];
					$tempOvr = $template['Overview'];
					$template = $appTemplate;
					$template['Overview'] = $tempOvr;
					$template['Description'] = $tempOvr;
					$template['CardDescription'] = $tempOvr;
					$template['Removable'] = true;
					$template['InstallPath'] = $tempPath;
					$template['Name'] = $tempName;
					$template['SortName'] = str_replace("-"," ",$template['Name']);
					break;
				}
			}

			if ( ! ($template['Blacklist'] ?? false) ) {
				$displayed[] = $template;
			}
		}

		return $displayed;
	}

	private static function collectInstalledPluginApplications($templates, $isActionCentre, &$updateCount) {
		$displayed = [];

		foreach ($templates as $template) {
			if ( ! ($template['Plugin'] ?? null) ) {
				continue;
			}

			$filename = pathinfo($template['Repository'],PATHINFO_BASENAME);
			if ( ! checkInstalledPlugin($template) ) {
				continue;
			}

			$template['InstallPath'] = "/var/log/plugins/$filename";
			$template['Uninstall'] = true;

			if ( $isActionCentre && $template['PluginURL'] && ($template['Name'] ?? "") !== "Community Applications" ) {
				if ( strtolower(trim(ca_plugin("pluginURL","/var/log/plugins/$filename"))) !== strtolower(trim($template['PluginURL'])) ) {
					continue;
				}

				$installedVersion = ca_plugin("version","/var/log/plugins/$filename");
				$pluginUpdated = false;
				$templatePluginVersion = $template['pluginVersion'] ?? null;
				$hasNewVersion = ($templatePluginVersion !== null)
					&& (strcmp($installedVersion, (string)$templatePluginVersion) < 0);
				if ( $hasNewVersion || ($template['UpdateAvailable'] ?? null) ) {
					$template['actionCentre'] = true;
					$template['UpdateAvailable'] = true;
					$pluginUpdated = true;
				}

				if ( is_file("/tmp/plugins/$filename") && strcmp($installedVersion,ca_plugin("version","/tmp/plugins/$filename")) < 0 ) {
					$template['actionCentre'] = true;
					$template['UpdateAvailable'] = true;
					$pluginUpdated = true;
				}

				if ( $pluginUpdated ) {
					$updateCount++;
				}
			}

			if ( $isActionCentre && ! ($template['Blacklist'] ?? false) && ! ($template['Deprecated'] ?? false) && ($template['Compatible'] ?? false) && ! ($template['actionCentre'] ?? null) ) {
				continue;
			}

			if ( $isActionCentre ) {
				$template['actionCentre'] = true;
			}

			$displayed[] = $template;
		}

		$displayed = array_merge($displayed, self::collectInstalledLanguagePacks($templates, $isActionCentre, $updateCount));

		return $displayed;
	}

	private static function collectInstalledLanguagePacks($templates, $isActionCentre, &$updateCount) {
		$displayed = [];
		$languagesDir = CA_PATHS['languageInstalled'] ?? null;

		if ( ! $languagesDir || ! is_dir($languagesDir) ) {
			return $displayed;
		}

		$installedLanguages = array_diff(scandir($languagesDir) ?: [],[".","..","en_US"]);

		foreach ($installedLanguages as $language) {
			$index = searchArray($templates,"LanguagePack",$language);
			if ( $index === false ) {
				continue;
			}

			$languageTemplate = $templates[$index];
			$languageTemplate['Uninstall'] = true;

			if ( $isActionCentre ) {
				$languageTemplate['actionCentre'] = true;
				if ( ! languageCheck($languageTemplate) ) {
					continue;
				}

				$languageTemplate['Updated'] = true;
				$updateCount++;
			}

			$displayed[] = $languageTemplate;
		}

		return $displayed;
	}

	private static function collectLegacyPluginApplications($templates) {
		$displayed = [];
		$alreadySeen = [];

		$sources = [
			"/boot/config/plugins-error/*.plg",
			"/boot/config/plugins-removed/*.plg"
		];

		$allPlugs = [];
		foreach ($sources as $pattern) {
			$results = glob($pattern) ?: [];
			$allPlugs = array_merge($allPlugs, $results);
		}

		foreach ($allPlugs as $oldplug) {
			foreach ($templates as $template) {
				if ( basename($oldplug) != basename($template['Repository']) ) {
					continue;
				}

				if ( file_exists("/boot/config/plugins/".basename($oldplug)) ) {
					continue;
				}

				if ( ($template['Blacklist'] ?? false) || ( ($GLOBALS['caSettings']['hideIncompatible'] == "true") && ! ($template['Compatible'] ?? false) ) ) {
					continue;
				}

				$oldPlugURL = trim(ca_plugin("pluginURL",$oldplug));
				if ( ! $oldPlugURL ) {
					continue;
				}

				if ( strtolower(trim($template['PluginURL']??"")) != strtolower(trim($oldPlugURL)) ) {
					continue;
				}

				$template['Removable'] = true;
				$template['InstallPath'] = $oldplug;
				/* Dedup key normalized the same way the comparison above is so case/
				   whitespace variants of the same URL aren't treated as distinct. */
				$oldPlugURLKey = strtolower(trim($oldPlugURL));
				if ( isset($alreadySeen[$oldPlugURLKey]) ) {
					continue;
				}

				$alreadySeen[$oldPlugURLKey] = true;
				$displayed[] = $template;
				break;
			}
		}

		return $displayed;
	}
}
?>