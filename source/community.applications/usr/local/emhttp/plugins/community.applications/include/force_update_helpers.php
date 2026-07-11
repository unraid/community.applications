<?php

class ForceUpdateHelpers {
	/**
	 * Recursively wipe the CA tempFiles tree and clear in-memory templates.
	 *
	 * Side effects: shells out to `rm -rf` against CA_PATHS['tempFiles'],
	 * optionally recreates the community templates dir, and resets
	 * $GLOBALS['templates'].
	 *
	 * @param  bool  $ensureTemplatesDirectory  When true, mkdir() the community templates directory after wiping.
	 * @return void
	 */
	public static function resetTemplatesCache(bool $ensureTemplatesDirectory = false): void {
		exec("rm -rf ".escapeshellarg(CA_PATHS['tempFiles']));

		if ($ensureTemplatesDirectory) {
			@mkdir(CA_PATHS['templates-community'], 0777, true);
		}

		$GLOBALS['templates'] = [];
	}

	/**
	 * Download the application-feed last-updated JSON.
	 *
	 * Side effects: deletes/writes CA_PATHS['lastUpdated'] on disk, emits debug
	 * output, and reaches over the network with a 60s timeout. When the primary
	 * does not return a valid timestamp, falls back to INF so the caller treats
	 * the local cache as stale.
	 *
	 * @return array<string,mixed> Decoded metadata; always contains last_updated_timestamp.
	 */
	public static function fetchLatestUpdateMetadata(): array {
		@unlink(CA_PATHS['lastUpdated']);

		/* 60-second cap — this is the tiny last-updated probe (a few hundred
		   bytes); we don't want it pinning a force_update for longer than
		   that. */
		$latestUpdate = download_json(caFeedPath('application-feed-last-updated'), CA_PATHS['lastUpdated'], 60);

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
	 * Decide whether the on-disk templates cache is stale relative to the new metadata.
	 *
	 * @param  array<string,mixed>  $latestUpdate    Freshly downloaded metadata.
	 * @param  array<string,mixed>  $lastUpdatedOld  Previously stored metadata.
	 * @return bool True when the two timestamps disagree.
	 */
	public static function shouldRefreshTemplates(array $latestUpdate, array $lastUpdatedOld): bool {
		return ($latestUpdate['last_updated_timestamp'] ?? 0) != ($lastUpdatedOld['last_updated_timestamp'] ?? 0);
	}

	/**
	 * Return true when the on-disk template info JSON exists and the in-memory $templates global is populated.
	 *
	 * Side effect: clears the PHP stat cache so the file_exists() check is fresh.
	 *
	 * @return bool
	 */
	public static function templatesAvailable(): bool {
		clearstatcache();
		return file_exists(CA_PATHS['community-templates-info']) && !empty($GLOBALS['templates']);
	}

	/**
	 * Build the failure payload returned when the application feed download fails.
	 *
	 * Side effects: reads/deletes CA_PATHS files including the appFeedDownloadError
	 * temp file and community-templates-info, and clears $GLOBALS['templates']. Adds
	 * diagnostic copy that varies depending on whether the server clock looks correct
	 * (checkServerDate) and whether a partial download is detected.
	 *
	 * @return array<string,string> Response with `script` and `data` keys for the UI.
	 */
	public static function buildDownloadFailureResponse(): array {
		$response = ['script' => "$('.onlyShowWithFeed').hide();"];

		$cdnHint = (($GLOBALS['caSettings']['useCloudflareCDN'] ?? "no") === "yes")
			? tr("Alternatively you may need to disable the Cloudflare CDN feed in Settings.")
			: tr("Alternatively you may need to enable the Cloudflare CDN feed in Settings.");

		if (checkServerDate()) {
			$response['data'] = "<div class='ca_center'><font size='4'><span class='ca_bold'>"
				. tr("Download of appfeed failed.")
				. "</span></font><font size='3'><br><br>Community Applications requires your server to have internet access.  The most common cause of this failure is a failure to resolve DNS addresses.  You can try and reset your modem and router to fix this issue, or set static DNS addresses (Settings - Network Settings) of 208.67.222.222 and 208.67.220.220 and try again.<br><br>"
				. $cdnHint;
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

		@unlink(CA_PATHS['appFeedDownloadError']);
		@unlink(CA_PATHS['community-templates-info']);
		$GLOBALS['templates'] = [];

		return $response;
	}

	/**
	 * Build the JS snippet appended after a successful update.
	 *
	 * Sets the statistics tooltip title to the formatted feed timestamp, and
	 * appends a deprecation banner when the installed OS is older than the
	 * CA template's declared MinVer.
	 *
	 * @return string JavaScript fragment.
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
	 * Validate that the metadata blob is an array with a non-empty last_updated_timestamp.
	 *
	 * @param  mixed  $metadata
	 * @return bool
	 */
	private static function isValidUpdateMetadata($metadata): bool {
		return is_array($metadata) && !empty($metadata['last_updated_timestamp']);
	}
}
?>