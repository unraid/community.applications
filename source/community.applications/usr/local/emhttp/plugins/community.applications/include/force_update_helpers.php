<?php

class ForceUpdateHelpers {
  public static function resetTemplatesCache(array $caPaths, bool $ensureTemplatesDirectory = false): void {
    exec("rm -rf '{$caPaths['tempFiles']}'");

    if ($ensureTemplatesDirectory) {
      @mkdir($caPaths['templates-community'], 0777, true);
    }

    $GLOBALS['templates'] = [];
  }

  public static function fetchLatestUpdateMetadata(array $caPaths): array {
    @unlink($caPaths['lastUpdated']);

    $latestUpdate = download_json($caPaths['application-feed-last-updated'], $caPaths['lastUpdated'], "", 5);

    if (!self::isValidUpdateMetadata($latestUpdate)) {
      $latestUpdate = download_json(
        $caPaths['pluginProxy'] . $caPaths['application-feed-last-updatedBackup'],
        $caPaths['lastUpdated'],
        "",
        5
      );
    }

    if (!self::isValidUpdateMetadata($latestUpdate)) {
      $latestUpdate = [];
    }

    if (!isset($latestUpdate['last_updated_timestamp'])) {
      $latestUpdate['last_updated_timestamp'] = INF;
      @unlink($caPaths['lastUpdated']);
    }

    debug("new appfeed timestamp: ".($latestUpdate['last_updated_timestamp'] ?? ""));

    return $latestUpdate;
  }

  public static function shouldRefreshTemplates(array $latestUpdate, array $lastUpdatedOld): bool {
    return ($latestUpdate['last_updated_timestamp'] ?? 0) != ($lastUpdatedOld['last_updated_timestamp'] ?? 0);
  }

  public static function templatesAvailable(array $caPaths): bool {
    return file_exists($caPaths['community-templates-info']) && !empty($GLOBALS['templates']);
  }

  public static function buildDownloadFailureResponse(array $caPaths): array {
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

    $tempFile = @file_get_contents($caPaths['appFeedDownloadError']);
    $downloaded = @file_get_contents($tempFile);

    if (strlen($downloaded) > 100) {
      $response['data'] .= "<font size='2' color='red'><br><br>It *appears* that a partial download of the application feed happened (or is malformed), therefore it is probable that the application feed is temporarily down.  Please try again later)</font>";
    }

    $response['data'] .= "<div class='ca_center'>Last JSON error Recorded: ";
    $jsonDecode = json_decode($downloaded, true);
    $response['data'] .= json_last_error_msg();
    $response['data'] .= "</div>";

    @unlink($caPaths['appFeedDownloadError']);
    @unlink($caPaths['community-templates-info']);
    $GLOBALS['templates'] = [];

    return $response;
  }

  public static function buildUpdateScript(array $caPaths, array $caSettings): string {
    $appFeedTime = readJsonFile($caPaths['lastUpdated-old']);
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
      if (version_compare($caSettings['unRaidVersion'], $GLOBALS['templates'][$appfeedCA]['MinVer'], "<")) {
        $script .= "addBannerWarning('"
          . tr("Deprecated OS version.  No further updates to Community Applications will be issued for this OS version")
          . "');";
      }
    }

    return $script;
  }

  private static function isValidUpdateMetadata($metadata): bool {
    return is_array($metadata) && !empty($metadata['last_updated_timestamp']);
  }
}
?>