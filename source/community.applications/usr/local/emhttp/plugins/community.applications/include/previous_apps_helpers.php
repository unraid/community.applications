<?php

class PreviousAppsHelpers {
	/**
	 * Remove the image tag from a Docker repository string while preserving registry ports.
	 *
	 * @param string $repository Docker repository or image reference.
	 * @return string The repository with a trailing image tag removed if that tag's `:` occurs after the last `/`; otherwise the original string.
	 */
	private static function stripImageTag(string $repository): string {
		$lastSlash = strrpos($repository, "/");
		$lastColon = strrpos($repository, ":");
		if ($lastColon === false) return $repository;
		if ($lastSlash !== false && $lastColon < $lastSlash) return $repository;
		return substr($repository, 0, $lastColon);
	}

	/**
	 * Removes specific previous-apps cache files referenced by CA_PATHS.
	 *
	 * Attempts to unlink files for the keys: 'community-templates-allSearchResults',
	 * 'community-templates-catSearchResults', 'repositoriesDisplayed',
	 * 'startupDisplayed', and 'dockerSearchActive'. If a path key is not set or
	 * file deletion fails, the function continues without raising an error.
	 */
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

	/**
	 * Resolve the `installed` and `filter` context for previous-apps views.
	 *
	 * When Action Centre is enabled, forces `installed` to `"action"` and `filter` to an empty string;
	 * otherwise reads `installed` and `filter` from POST and clears previous-apps cache files.
	 *
	 * @param bool $enableActionCentre If true, return the Action Centre context override.
	 * @return array{installed:string,filter:string} Associative array with keys `installed` and `filter`.
	 */
	public static function resolvePreviousAppsContext($enableActionCentre) {
		if ( $enableActionCentre ) {
			return ['installed' => "action", 'filter' => ""];
		}

		$installed = getPost("installed","");
		$filter = getPost("filter","");
		self::clearPreviousAppsCaches();

		return ['installed' => $installed, 'filter' => $filter];
	}

	/**
	 * Load the Docker update status mapping when Docker is running.
	 *
	 * If Docker is not running, or the persisted status file is missing or does not
	 * contain an array, an empty array is returned.
	 *
	 * @param bool $dockerRunning Whether Docker is currently running.
	 * @return array Associative array of update statuses keyed by repository; empty array when unavailable.
	 */
	public static function loadDockerUpdateStatus($dockerRunning) {
		if ( ! $dockerRunning ) {
			return [];
		}

		$status = readJsonFile(CA_PATHS['dockerUpdateStatus']);

		/* readJsonFile() tries unserialize() before json_decode(), so a truthy
		   non-array could leak through and crash the array-indexing consumers. */
		return is_array($status) ? $status : [];
	}

	/**
	 * Collects Docker application templates applicable for display based on runtime state and filter.
	 *
	 * Selects between installed-mode and legacy-mode collection:
	 * - If Docker is not running or the provided filter excludes Docker, returns an empty array.
	 * - If `$installed` equals `"true"` or Action Centre is enabled (`"action"`), gathers installed Docker apps and checks update/moderation state.
	 * - Otherwise gathers legacy (removable) Docker applications.
	 *
	 * @param bool $dockerRunning Whether Docker is running; when false the function returns an empty array.
	 * @param string $installed Installation context; `"true"` selects installed mode, `"action"` forces Action Centre behaviour.
	 * @param string $filter Optional filter value; non-empty values other than `"docker"` cause an empty result.
	 * @param array $info Runtime Docker information used to match running containers to templates.
	 * @param int &$updateCount Incremented when an update is detected for an application (passed by reference).
	 * @param array $templates Catalog templates used to enrich or match XML templates.
	 * @param array $extraBlacklist Moderator blacklist overrides keyed by catalog repository.
	 * @param array $extraDeprecated Moderator deprecation overrides keyed by catalog repository.
	 * @param array $dockerUpdateStatus Preloaded docker update status map (string => bool) used in Action Centre mode.
	 * @return array The list of templates prepared for display (possibly enriched with uninstall/update/action-centre metadata).
	 */
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

	/**
	 * Build the list of plugin templates to display based on installed and filter context.
	 *
	 * Chooses between installed (including Action Centre) and legacy collection paths:
	 * - If a non-empty filter other than "plugins" is provided, returns an empty array.
	 * - If $installed is "true" or "action", collects installed/plugin Action Centre candidates.
	 * - Otherwise collects legacy plugin entries.
	 *
	 * @param string $installed "'true' for installed view, 'action' for Action Centre mode, or other values for legacy mode."
	 * @param string $filter Filter value; only empty or "plugins" will allow plugin collection.
	 * @param array $templates Catalog templates to search and match against.
	 * @param int &$updateCount Reference to a counter that may be incremented when updates are detected.
	 * @return array An array of plugin templates selected for display and annotated with uninstall/update metadata.
	 */
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

	/**
	 * Collects installed Docker templates that correspond to running containers and prepares them for display or Action Centre processing.
	 *
	 * Processes each Docker XML template file, matches it to running containers from $info, merges catalog template data when available,
	 * marks uninstallable entries, applies update detection using $dockerUpdateStatus when Action Centre is enabled, and applies moderator
	 * overrides from $extraBlacklist / $extraDeprecated. Only templates representing running containers are returned; in Action Centre mode
	 * templates are further filtered to those with an update or a moderator override.
	 *
	 * @param string[] $allFiles Paths to Docker template XML files to evaluate.
	 * @param array[] $info Array of installed Docker container info entries (each must include at least 'Name' and 'Image').
	 * @param array[] $templates Catalog templates to match against (indexed array of template associative arrays).
	 * @param array<string,array> $dockerUpdateStatus Mapping of normalized repository keys to update status entries.
	 * @param array<string,string> $extraBlacklist Moderator blacklist overrides keyed by catalog repository.
	 * @param array<string,string> $extraDeprecated Moderator deprecation overrides keyed by catalog repository.
	 * @param bool $isActionCentre Whether Action Centre mode is enabled (enables update checks and moderator filtering).
	 * @param int &$updateCount Incremented for each template determined to have an available update (passed by reference).
	 * @return array[] Array of templates prepared for display (each template is an associative array with merged/annotated fields).
	 */
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
				   has a port, not a tag, and still needs `:latest` appended.
				   Use $catalogRepo (saved before the running-image overwrite above)
				   so the dockerUpdateStatus key matches what the catalog produced. */
				$repoStr = $catalogRepo ?? $template['Repository'];
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

	/**
	 * Collects legacy Docker template entries that are not currently running and maps them to catalog templates when available.
	 *
	 * For each XML template file in $allFiles this will:
	 * - skip missing or blacklisted templates,
	 * - normalize description fields and mark the template as removable,
	 * - skip templates that match a running container,
	 * - attempt to replace the XML template with a matching catalog template (by TemplateURL or by repository stripped of its tag) while preserving InstallPath, Name, and Overview,
	 * - exclude templates that are blacklisted after matching.
	 *
	 * @param string[] $allFiles Paths to legacy Docker template XML files to inspect.
	 * @param array[]  $info     Array of installed Docker container info entries (each with at least 'Image' and 'Name').
	 * @param array[]  $templates Catalog templates to match against.
	 * @return array[] An array of templates representing removable legacy Docker applications (catalog data merged when found).
	 */
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
					/* Match the catalog repo (sans tag) against $testRepo exactly —
					   prefix matching previously let `space/foo` match against
					   `space/foobar` and copy the wrong template's metadata. */
					if ( self::stripImageTag($appTemplate['Repository'] ?? "") !== $testRepo ) {
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

	/**
	 * Collects installed plugin templates and installed language packs for display,
	 * marking each plugin as removable and annotating action-centre update metadata when enabled.
	 *
	 * When Action Centre mode is enabled, each plugin is validated against the installed
	 * plugin URL and its installed version (from /var/log/plugins/<file> and optionally
	 * /tmp/plugins/<file>) is compared to the template's `pluginVersion`. If a newer
	 * version is detected or `UpdateAvailable` is present on the template, the plugin
	 * will be flagged with `actionCentre = true`, `UpdateAvailable = true`, and the
	 * referenced `$updateCount` will be incremented. Plugins that fail URL validation
	 * or do not meet Action Centre eligibility (blacklist/deprecated/compatible) are
	 * excluded in Action Centre mode.
	 *
	 * @param array $templates Array of plugin templates to evaluate.
	 * @param bool $isActionCentre Whether Action Centre filtering and update checks are active.
	 * @param int &$updateCount Incremented for each plugin determined to have an available update.
	 * @return array List of templates to display; each selected template will include
	 *               `InstallPath` (set to "/var/log/plugins/<filename>"), `Uninstall = true`,
	 *               and may include `actionCentre` and `UpdateAvailable` flags.
	 */
	private static function collectInstalledPluginApplications($templates, $isActionCentre, &$updateCount) {
		$displayed = [];

		foreach ($templates as $template) {
			if ( ! ($template['Plugin'] ?? null) ) {
				continue;
			}

			/* Derive $filename from PluginURL — checkInstalledPlugin() and the
			   /var/log/plugins/... checks below are keyed off the installed
			   plugin filename produced by the plugin system, which comes from
			   PluginURL. Repository can diverge for custom XML, leaving us
			   reading the wrong file. */
			$pluginUrl = $template['PluginURL'] ?? null;
			if ( ! $pluginUrl ) {
				continue;
			}
			$filename = basename($pluginUrl);
			if ( ! checkInstalledPlugin($template) ) {
				continue;
			}

			$template['InstallPath'] = "/var/log/plugins/$filename";
			$template['Uninstall'] = true;

			if ( $isActionCentre && ($template['PluginURL'] ?? "") && ($template['Name'] ?? "") !== "Community Applications" ) {
				if ( strtolower(trim(ca_plugin("pluginURL","/var/log/plugins/$filename"))) !== strtolower(trim($template['PluginURL'] ?? "")) ) {
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

	/**
	 * Collects installed language pack templates found in the configured installed-languages directory.
	 *
	 * Scans the installed language XML files (files named `lang-<code>.xml`) and for each non-`en_US`
	 * language that has a matching template in `$templates` returns a template marked as uninstallable.
	 * When `$isActionCentre` is true, the template is marked for Action Centre and, if `languageCheck`
	 * passes, the template is marked `UpdateAvailable` and the referenced `$updateCount` is incremented.
	 *
	 * @param array $templates List of available templates to match against (each template as an associative array).
	 * @param bool $isActionCentre Whether Action Centre mode is enabled; affects update detection and tagging.
	 * @param int  &$updateCount Incremented when an installed language pack is detected as having an update.
	 * @return array Array of language pack templates to display (each template as an associative array).
	 */
	private static function collectInstalledLanguagePacks($templates, $isActionCentre, &$updateCount) {
		$displayed = [];
		/* Source the user's installed language *plugins* from the boot config
		   directory (lang-<code>.xml files) — not /usr/local/emhttp/languages/
		   which holds the runtime-extracted folders. languageCheck() and the
		   plugin-system both treat lang-<code>.xml as the source of truth. */
		$languagesDir = CA_PATHS['installedLanguages'] ?? null;

		if ( ! $languagesDir || ! is_dir($languagesDir) ) {
			return $displayed;
		}

		$entries = scandir($languagesDir) ?: [];

		foreach ($entries as $entry) {
			if ( preg_match('/^lang-(.+)\.xml$/', $entry, $matches) !== 1 ) {
				continue;
			}
			$language = $matches[1];
			if ( $language === "en_US" ) {
				continue;
			}
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

				$languageTemplate['UpdateAvailable'] = true;
				$updateCount++;
			}

			$displayed[] = $languageTemplate;
		}

		return $displayed;
	}

	/**
	 * Find legacy plugin files and match them to catalog templates for removable entries.
	 *
	 * Scans legacy plugin locations for `.plg` files, matches each file to a template by comparing
	 * basenames of the template's `PluginURL` (or `Repository` fallback) to the legacy filename,
	 * skips entries that currently exist in `/boot/config/plugins/`, ignores blacklisted or
	 * incompatible templates (honoring global `caSettings['hideIncompatible']`), verifies the
	 * installed plugin URL read from the legacy file matches the template's `PluginURL` (case- and
	 * whitespace-insensitive), and deduplicates by normalized plugin URL. Matched templates are
	 * marked with `Removable = true` and `InstallPath` set to the legacy file path.
	 *
	 * @param array $templates Catalog templates to search for matches; plugin templates should include `PluginURL` or `Repository`.
	 * @return array Templates matched to legacy plugin files, each annotated with `Removable` and `InstallPath`.
	 */
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
				/* Skip non-plugin templates and guard against missing PluginURL
				   so basename() doesn't throw a notice on custom/local XML.
				   Match on PluginURL (which is what the plugin system installs
				   from) — falling back to Repository keeps legacy templates
				   working when only the older field is populated. */
				if ( ! ($template['Plugin'] ?? false) ) {
					continue;
				}
				$pluginRef = $template['PluginURL'] ?? $template['Repository'] ?? "";
				if ( basename($oldplug) != basename($pluginRef) ) {
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