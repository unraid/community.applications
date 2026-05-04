<?php

class ForceUpdateHelpers {
	/**
	 * Reset template cache and the in-memory templates state.
	 *
	 * Deletes the on-disk templates cache at CA_PATHS['tempFiles'], clears $GLOBALS['templates'],
	 * and, if requested, ensures the CA_PATHS['templates-community'] directory exists (created recursively with 0777 permissions).
	 *
	 * @param bool $ensureTemplatesDirectory If true, create CA_PATHS['templates-community'] if it does not exist.
	 */
	public static function resetTemplatesCache(bool $ensureTemplatesDirectory = false): void {
		exec("rm -rf ".escapeshellarg(CA_PATHS['tempFiles']));

		if ($ensureTemplatesDirectory) {
			@mkdir(CA_PATHS['templates-community'], 0777, true);
		}

		$GLOBALS['templates'] = [];
	}

	/**
	 * Retrieves the latest update metadata from the application feed.
	 *
	 * Attempts to download and validate metadata from the primary feed and falls back to a backup feed if necessary.
	 * Ensures the returned array always contains a `last_updated_timestamp` key (set to `INF` when no valid timestamp is available).
	 *
	 * @return array The parsed metadata array from the feed, or an empty array if no valid metadata could be obtained. The array will include a `last_updated_timestamp` value. 
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
	 * Determine whether templates should be refreshed by comparing recorded update timestamps.
	 *
	 * @param array $latestUpdate Latest update metadata; may include a `last_updated_timestamp` key.
	 * @param array $lastUpdatedOld Previously recorded update metadata; may include a `last_updated_timestamp` key.
	 * @return bool `true` if the `last_updated_timestamp` values differ, `false` otherwise.
	 */
	public static function shouldRefreshTemplates(array $latestUpdate, array $lastUpdatedOld): bool {
		return ($latestUpdate['last_updated_timestamp'] ?? 0) != ($lastUpdatedOld['last_updated_timestamp'] ?? 0);
	}

	/**
	 * Checks whether community templates are available locally.
	 *
	 * Returns `true` only when the community templates marker file exists and the in-memory templates list contains entries.
	 *
	 * @return bool `true` if the community templates marker file exists and the in-memory templates list is not empty, `false` otherwise.
	 */
	public static function templatesAvailable(): bool {
		clearstatcache();
		return file_exists(CA_PATHS['community-templates-info']) && !empty($GLOBALS['templates']);
	}

	/**
	 * Build a UI response describing a failed application-feed download and perform related cleanup.
	 *
	 * The returned array contains a JavaScript snippet to hide feed-only UI and an HTML payload explaining
	 * the failure (including server date/DNS guidance and the last recorded JSON error message).
	 *
	 * Side effects: deletes CA_PATHS['appFeedDownloadError'] and CA_PATHS['community-templates-info'] (errors suppressed)
	 * and clears $GLOBALS['templates'].
	 *
	 * @return array{script: string, data: string} An associative array with:
	 *   - `script`: JS to run on the client (hides feed-only elements).
	 *   - `data`: HTML to display to the user describing the failure and the last JSON error message.
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

	/**
	 * Build a JavaScript snippet that updates the UI with the last feed update time and, if needed, adds a deprecation banner.
	 *
	 * The returned script sets the title attribute of elements matching `.showStatistics` to the formatted
	 * "last updated" timestamp read from the previous feed metadata. If the installed OS version is lower
	 * than the Community Applications plugin's `MinVer`, the script also calls `addBannerWarning` with a deprecation message.
	 *
	 * @return string JavaScript code that updates the feed timestamp tooltip and conditionally inserts a deprecation banner.
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

	/ **
	 * Determines whether the provided update metadata contains a valid `last_updated_timestamp`.
	 *
	 * @param mixed $metadata The metadata payload to validate; expected to be an array containing a `last_updated_timestamp` entry.
	 * @return bool `true` if `$metadata` is an array and contains a non-empty `last_updated_timestamp`, `false` otherwise.
	 */
	private static function isValidUpdateMetadata($metadata): bool {
		return is_array($metadata) && !empty($metadata['last_updated_timestamp']);
	}
}
?>