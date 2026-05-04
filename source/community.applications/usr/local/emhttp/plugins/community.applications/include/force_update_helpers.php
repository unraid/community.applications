<?php

class ForceUpdateHelpers {
	/**
	 * Reset template-related state by removing temporary template files and clearing the in-memory template cache.
	 *
	 * If `$ensureTemplatesDirectory` is true, ensures the community templates directory exists after resetting.
	 *
	 * @param bool $ensureTemplatesDirectory Whether to create the community templates directory if missing.
	 */
	public static function resetTemplatesCache(bool $ensureTemplatesDirectory = false): void {
		exec("rm -rf ".escapeshellarg(CA_PATHS['tempFiles']));

		if ($ensureTemplatesDirectory) {
			@mkdir(CA_PATHS['templates-community'], 0777, true);
		}

		$GLOBALS['templates'] = [];
	}

	/**
	 * Fetches the latest application feed metadata, attempting a primary source then a backup and returning a validated metadata array.
	 *
	 * Attempts to download the "last updated" JSON from the primary feed and, if invalid, retries using a backup feed.
	 * If both attempts fail the function returns an empty array. Ensures the returned array contains a `last_updated_timestamp`
	 * key; when that key is absent it is set to INF.
	 *
	 * @return array The fetched metadata array (may be empty). Guaranteed to contain a `last_updated_timestamp` entry.
	 */
	public static function fetchLatestUpdateMetadata(): array {
		@unlink(CA_PATHS['lastUpdated']);

		// Ensure force_update cannot hang forever if the remote is stalled.
		$latestUpdate = download_json(CA_PATHS['application-feed-last-updated'], CA_PATHS['lastUpdated'], 60);

		if (!self::isValidUpdateMetadata($latestUpdate)) {
			$latestUpdate = download_json(CA_PATHS['pluginProxy'] . CA_PATHS['application-feed-last-updatedBackup'], CA_PATHS['lastUpdated'], 60);
		}

		if (!self::isValidUpdateMetadata($latestUpdate)) {
			$latestUpdate = [];
		}

		if (!isset($latestUpdate['last_updated_timestamp'])) {
			$latestUpdate['last_updated_timestamp'] = INF;
			@unlink(CA_PATHS['lastUpdated']);
		}

		debug("new appfeed timestamp: ".($latestUpdate['last_updated_timestamp'] ?? ""));

		return $latestUpdate;
	}

	/**
	 * Determine whether templates need to be refreshed by comparing update metadata.
	 *
	 * @param array $latestUpdate Metadata array for the latest feed; `last_updated_timestamp` defaults to 0 if missing.
	 * @param array $lastUpdatedOld Previously stored metadata; `last_updated_timestamp` defaults to 0 if missing.
	 * @return bool `true` if the `last_updated_timestamp` values differ, `false` otherwise.
	 */
	public static function shouldRefreshTemplates(array $latestUpdate, array $lastUpdatedOld): bool {
		return ($latestUpdate['last_updated_timestamp'] ?? 0) != ($lastUpdatedOld['last_updated_timestamp'] ?? 0);
	}

	/**
	 * Checks whether community templates are available for use.
	 *
	 * @return bool `true` if the community templates marker file exists and the in-memory template list is non-empty, `false` otherwise.
	 */
	public static function templatesAvailable(): bool {
		clearstatcache();
		return file_exists(CA_PATHS['community-templates-info']) && !empty($GLOBALS['templates']);
	}

	/**
	 * Builds a UI response describing a failed application feed download.
	 *
	 * The response contains a JavaScript snippet to hide feed-only UI and an HTML message
	 * explaining the failure. The message is tailored based on server date checks and,
	 * when available, includes diagnostics from a previously saved partial download and
	 * the last JSON error message. This method also removes the app feed error marker
	 * and community templates info files and clears the in-memory templates list.
	 *
	 * @return array An associative array with:
	 *               - 'script': JavaScript to execute in the UI.
	 *               - 'data'  : HTML string describing the download failure and diagnostics.
	 */
	public static function buildDownloadFailureResponse(): array {
		$response = ['script' => "$('.onlyShowWithFeed').hide();"];

		if (checkServerDate()) {
			$response['data'] = "<div class='ca_center'><font size='4'><span class='ca_bold'>"
				. tr("Download of appfeed failed.")
				. "</span></font><font size='3'><br><br>Community Applications requires your server to have internet access.  The most common cause of this failure is a failure to resolve DNS addresses.  You can try and reset your modem and router to fix this issue, or set static DNS addresses (Settings - Network Settings) of 208.67.222.222 and 208.67.220.220 and try again.<br><br>Alternatively, there is also a chance that the server handling the application feed is temporarily down.  See also <a href='https://forums.unraid.net/topic/120220-fix-common-problems-more-information/page/2/?tab=comments#comment-1101084' target='_blank'>this post</a> for more information";
		} else {
			$response['data'] = "<div class='ca_center'><font size='4'><span class='ca_bold'>"
				. tr("Download of appfeed failed.")
				. "</span></font><font size='3'><br><br>Community Applications requires your server to have internet access.  This could be because it appears that the current date and time of your server is incorrect.  Correct this within Settings - Date And Time.  See also <a href='https://forums.unraid.net/topic/120220-fix-common-problems-more-information/page/2/?tab=comments#comment-1101084' target='_blank'>this post</a> for more information";
		}

		$tempFile = @file_get_contents(CA_PATHS['appFeedDownloadError']);
		$downloaded = (is_string($tempFile) && $tempFile !== "" && is_file($tempFile))
			? (@file_get_contents($tempFile) ?: "")
			: "";

		if (strlen($downloaded) > 100) {
			$response['data'] .= "<font size='2' color='red'><br><br>It *appears* that a partial download of the application feed happened (or is malformed), therefore it is probable that the application feed is temporarily down.  Please try again later)</font>";
		}

		$response['data'] .= "<div class='ca_center'>Last JSON error Recorded: ";
		json_decode($downloaded, true);
		$response['data'] .= json_last_error_msg();
		$response['data'] .= "</div>";

		@unlink(CA_PATHS['appFeedDownloadError']);
		@unlink(CA_PATHS['community-templates-info']);
		$GLOBALS['templates'] = [];

		return $response;
	}

	/ **
	 * Builds a JavaScript snippet that updates the UI with the last application-feed update time and conditionally adds a deprecation banner.
	 *
	 * The returned script sets the `title` attribute on elements matching `.showStatistics` to a human-readable
	 * formatted `last_updated_timestamp` from `lastUpdated-old`. If the installed OS version is less than the
	 * Community Applications plugin's `MinVer`, the script also includes a call to `addBannerWarning(...)`
	 * with a translated deprecation message.
	 *
	 * @return string A JavaScript string that sets the `.showStatistics` tooltip to the formatted last-update time and may append an `addBannerWarning(...)` call when the OS is deprecated.
	 */
	public static function buildUpdateScript(): string {
		$appFeedTime = readJsonFile(CA_PATHS['lastUpdated-old']);
		$timestamp = $appFeedTime['last_updated_timestamp'] ?? 0;
		$updateTime = tr(date("F", $timestamp), 0) . date(" d, Y @ g:i a", $timestamp);
		$updateTime = str_replace("'", "&apos;", $updateTime);

		$script = "$('.showStatistics').attr('title','{$updateTime}');";

		$appfeedCA = searchArray(
			$GLOBALS['templates'],
			"PluginURL",
			"https://raw.githubusercontent.com/unraid/community.applications/master/plugins/community.applications.plg"
		);

		if ($appfeedCA !== false) {
			if (version_compare($GLOBALS['caSettings']['unRaidVersion'], $GLOBALS['templates'][$appfeedCA]['MinVer'], "<")) {
				$script .= "addBannerWarning('"
					. tr("Deprecated OS version.  No further updates to Community Applications will be issued for this OS version")
					. "');";
			}
		}

		return $script;
	}

	/**
	 * Checks whether the provided update metadata includes a non-empty last-updated timestamp.
	 *
	 * @param mixed $metadata The metadata to validate; expected to be an array containing a `last_updated_timestamp` entry.
	 * @return bool `true` if `$metadata` is an array and has a non-empty `last_updated_timestamp`, `false` otherwise.
	 */
	private static function isValidUpdateMetadata($metadata): bool {
		return is_array($metadata) && !empty($metadata['last_updated_timestamp']);
	}
}
?>