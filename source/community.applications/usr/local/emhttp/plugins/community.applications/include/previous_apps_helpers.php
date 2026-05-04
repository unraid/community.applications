<?php

class PreviousAppsHelpers {
	/**
	 * Remove the tag portion from a Docker image repository string while preserving registry ports.
	 *
	 * @param string $repository Docker image repository or image name, possibly including a registry host:port and a tag (e.g. "registry:5000/ns/app:latest").
	 * @return string The repository string with a trailing tag removed when present (e.g. "registry:5000/ns/app"), leaving registry port separators intact.
	 */
	private static function stripImageTag(string $repository): string {
		$lastSlash = strrpos($repository, "/");
		$lastColon = strrpos($repository, ":");
		if ($lastColon === false) return $repository;
		if ($lastSlash !== false && $lastColon < $lastSlash) return $repository;
		return substr($repository, 0, $lastColon);
	}

	/**
	 * Remove cached files used by Community Applications for previous-apps searches and displays.
	 *
	 * Removes a fixed set of cache files referenced by the CA_PATHS constants; file deletion failures are suppressed and ignored.
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
	 * Resolve the previous-apps context used to select installed/legacy items.
	 *
	 * When Action Centre mode is enabled, returns a context that forces Action
	 * Centre behavior; otherwise reads `installed` and `filter` from POST and
	 * clears previous app caches before returning them.
	 *
	 * @param bool $enableActionCentre If truthy, force Action Centre mode.
	 * @return array{installed:string,filter:string} Associative array with keys:
	 *         - `installed`: the installed mode (`"action"`, `"true"`, or other POST value)
	 *         - `filter`: the filter string (empty or the POST `filter` value)
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
	 * Loads Docker update status data when Docker is running and returns it as an array.
	 *
	 * If Docker is not running or the underlying status file does not contain an array, an empty array is returned.
	 *
	 * @param bool $dockerRunning Whether Docker is currently running.
	 * @return array Associative array of Docker update status, or an empty array when unavailable.
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
	 * Collects Docker application templates that should be displayed as removable or updatable based on the current environment and Action Centre mode.
	 *
	 * @param bool $dockerRunning Whether Docker is running; when false an empty array is returned.
	 * @param string $installed Value from request context controlling mode: `"true"` for installed view, `"action"` for Action Centre mode, or other for legacy view.
	 * @param string $filter Optional UI filter; when non-empty and not `"docker"` an empty array is returned.
	 * @param array $info Array of currently installed Docker container information.
	 * @param int &$updateCount Incremented when an Action Centre update candidate is detected.
	 * @param array $templates Catalog templates indexed by repository/ID used to enrich XML templates.
	 * @param array $extraBlacklist Map of moderator blacklist overrides keyed by canonical repository.
	 * @param array $extraDeprecated Map of moderator deprecation overrides keyed by canonical repository.
	 * @param array $dockerUpdateStatus Preloaded docker update status data used in Action Centre mode.
	 * @return array An array of template entries to display (removable and/or update candidates). 
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
	 * Build the list of plugin templates to display based on installation context and filter.
	 *
	 * Chooses between installed-mode and legacy-mode plugin collection depending on the
	 * `$installed` flag and respects the `$filter` (returns an empty array when the filter
	 * is non-empty and not equal to `"plugins"`).
	 *
	 * @param string $installed "true" for installed mode, "action" for Action Centre mode, or other values for legacy mode.
	 * @param string $filter A UI filter string; only `"plugins"` or empty allow plugin results.
	 * @param array $templates Catalog templates available for matching.
	 * @param int &$updateCount Reference to a counter that may be incremented when updates are detected.
	 * @return array An array of plugin templates to display (may be empty).
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
	 * Build the list of installed Docker templates to display (marking uninstallable entries and Action Centre update/ moderation flags).
	 *
	 * Iterates XML template files, matches each to a running container from `$info`, enriches template metadata from the catalog `$templates`, applies Action Centre update detection using `$dockerUpdateStatus`, applies moderator overrides from `$extraBlacklist` and `$extraDeprecated`, and returns the prepared templates to present to the user.
	 *
	 * @param string[] $allFiles Paths to Docker template XML files to consider.
	 * @param array[] $info Array of installed Docker container info entries (each item must include at least `Name` and `Image`).
	 * @param array[] $templates Catalog templates indexed numerically (used to enrich XML templates; expected fields include `Repository`, `ID`, `TemplateURL`, etc.).
	 * @param array<string,array> $dockerUpdateStatus Mapping of normalized repository keys to update status data (used in Action Centre mode).
	 * @param array<string,string> $extraBlacklist Moderator-provided blacklist messages keyed by catalog repository.
	 * @param array<string,string> $extraDeprecated Moderator-provided deprecation messages keyed by catalog repository.
	 * @param bool $isActionCentre Whether Action Centre mode is active (enables update/moderation checks).
	 * @param int &$updateCount Reference to a counter that will be incremented for each detected update.
	 * @return array Prepared list of templates to display (each template may include flags such as `Uninstall`, `ID`, `actionCentre`, `UpdateAvailable`, `Blacklist`, `Deprecated`, and `ModeratorComment`).
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
	 * Build a list of legacy Docker templates that are not currently running and are eligible for removal.
	 *
	 * Processes XML template files and returns templates that have no matching running container, are not blacklisted,
	 * and are prepared for display as removable legacy applications (fields such as `Removable`, `InstallPath`,
	 * `UnknownCompatible`, and overview/description fields are set or preserved). When available, matching catalog
	 * metadata is merged into the template using `TemplateURL` or a repository match with tags stripped.
	 *
	 * @param string[] $allFiles Array of filesystem paths to Docker XML template files to evaluate.
	 * @param array[] $info Array of installed Docker container info entries to check for running matches.
	 * @param array[] $templates Array of catalog templates used to enrich XML templates when a match is found.
	 * @return array[] An array of templates prepared for display as removable legacy Docker applications.
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
	 * Build the list of installed plugin templates (including language packs) to display,
	 * marking uninstallable entries and, when in Action Centre mode, detecting and flagging updates.
	 *
	 * Processes each template that represents an installed plugin: sets `InstallPath` and `Uninstall`,
	 * includes the template when appropriate, and in Action Centre mode only includes templates whose
	 * installed plugin URL matches the template and that pass Action Centre visibility rules.
	 * When an update is detected (by version comparison or existing `UpdateAvailable`), sets
	 * `actionCentre` and `UpdateAvailable` on the template and increments `$updateCount`.
	 *
	 * @param array $templates Array of plugin templates to evaluate.
	 * @param bool $isActionCentre True when Action Centre mode is active; alters visibility and update checks.
	 * @param int &$updateCount Incremented for each plugin (or language pack) detected as having an update.
	 * @return array An array of templates to display for installed plugins and installed language packs.
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
	 * Collects templates corresponding to installed language pack plugin definitions.
	 *
	 * Scans the installed languages directory for files named `lang-<code>.xml` (excluding `en_US`),
	 * locates the matching template by `LanguagePack`, marks it uninstallable, and includes it in the
	 * returned list. In Action Centre mode, templates are flagged for Action Centre, validated via
	 * `languageCheck()`, marked `UpdateAvailable` and cause `$updateCount` to be incremented when added.
	 *
	 * @param array $templates List of available templates to search.
	 * @param bool $isActionCentre When true, apply Action Centre update/visibility rules.
	 * @param int  &$updateCount Reference to the update counter; incremented for each language pack
	 *                          marked `UpdateAvailable`.
	 * @return array An array of language-pack templates to display (each template may be modified with
	 *               `Uninstall`, `actionCentre`, and `UpdateAvailable` flags).
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
	 * Builds a list of plugin templates corresponding to legacy `.plg` files that are not currently installed.
	 *
	 * Scans legacy plugin locations for `.plg` files, matches each file to a template by the plugin's install URL (falling back to repository basename),
	 * excludes templates that are blacklisted or (when configured) incompatible, skips plugins already present in /boot/config/plugins/,
	 * requires the legacy `.plg` to provide a plugin URL via `ca_plugin("pluginURL", ...)`, and deduplicates matches case-insensitively.
	 *
	 * Matched templates are marked with `Removable = true` and `InstallPath` set to the `.plg` path.
	 *
	 * @param array $templates Array of available plugin templates to match against.
	 * @return array An array of matched template entries to display for removal.
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