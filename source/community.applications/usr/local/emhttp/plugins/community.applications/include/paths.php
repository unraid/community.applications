<?
########################################
#                                      #
# Community Applications               #
# Copyright 2020-2025, Lime Technology #
# Copyright 2015-2025, Andrew Zawadzki #
#                                      #
# Licenced under GPLv2                 #
#                                      #
########################################

$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: "/usr/local/emhttp";
require_once "$docroot/plugins/dynamix/include/Wrappers.php";

$CA = "community.applications";
$tempFiles = "/tmp/$CA/tempFiles";
$flashDrive = "/boot/config/plugins/$CA";

if ( ! isset($dockerManPaths) ) {
  $dockerManPaths = [
    'autostart-file' => "/var/lib/docker/unraid-autostart",
    'update-status'  => "/var/lib/docker/unraid-update-status.json",
    'template-repos' => "/boot/config/plugins/dockerMan/template-repos",
    'templates-user' => "/boot/config/plugins/dockerMan/templates-user",
    'templates-usb'  => "/boot/config/plugins/dockerMan/templates",
    'images'         => "/var/lib/docker/unraid/images",
    'user-prefs'     => "/boot/config/plugins/dockerMan/userprefs.cfg",
    'plugin'         => "$docroot/plugins/dynamix.docker.manager",
    'images-ram'     => "$docroot/state/plugins/dynamix.docker.manager/images",
    'webui-info'     => "$docroot/state/plugins/dynamix.docker.manager/docker.json"
  ];
}

$dynamixSettings = parse_plugin_cfg("dynamix");

define("CA_PATHS",[
  'tempFiles'                           => "/tmp/$CA/tempFiles",
  'flashDrive'                          => "/boot/config/plugins/$CA",
  'templates-community'                 => "$tempFiles/templates-community-apps",           /* templates and temporary files stored here.  Deleted every update of applications */
  'community-templates-url'             => "https://raw.githubusercontent.com/Squidly271/Community-Applications-Moderators/master/Repositories.json",
  'PublicServiceAnnouncement'           => "https://raw.githubusercontent.com/Squidly271/Community-Applications-Moderators/master/PublicServiceAnnouncement.txt",
  'community-templates-info'            => "$tempFiles/templates_new.json",                     /* json file containing all of the templates */
  'community-templates-info-old'        => "$tempFiles/templates.json",  /* this file is for plugin script to update suppport URLs on plugins.  Has to be in JSON format */
  'haveTemplates'												=> "$tempFiles/haveTemplates",
  'community-templates-displayed'       => "$tempFiles/displayed.json",                     /* json file containing all of the templates currently displayed */
  'community-templates-allSearchResults'=> "$tempFiles/allSearchResults.json",
  'community-templates-catSearchResults'=> "$tempFiles/catSearchResults.json",
  'startupDisplayed'                    => "$tempFiles/startupDisplayed",
  'repositoriesDisplayed'               => "$tempFiles/repositoriesDisplayed.json",
  'localONLY'                           => false,    /* THIS MUST NOT BE SET TO TRUE WHEN DOING A RELEASE */
  'humanReadable'                       => false,     /* THIS MUST NOT BE SET TO TRUE WHEN DOING A RELEASE */
  'application-feed'                    => "https://ca.unraid.net/assets/feed/applicationFeed.json",
  'application-feed-last-updated'       => "https://ca.unraid.net/assets/feed/applicationFeed-lastUpdated.json",
  'application-feedBackup'              => "https://raw.githubusercontent.com/Squidly271/AppFeed/master/applicationFeed.json",
  'application-feed-last-updatedBackup' => "https://raw.githubusercontent.com/Squidly271/AppFeed/master/applicationFeed-lastUpdated.json",
  'application-feed-local'              => "/tmp/GitHub/AppFeed/applicationFeed.json",
  'appFeedDownloadError'                => "$tempFiles/downloaderror.txt",
  'categoryList'                        => "$tempFiles/categoryList.json",
  'repositoryList'                      => "$tempFiles/repositoryList.json",
  'extraBlacklist'                      => "$tempFiles/extraBlacklist.json",
  'extraDeprecated'                     => "$tempFiles/extraDeprecated.json",
  'sortOrder'                           => "$tempFiles/sortOrder.json",
  'currentServer'                       => "$tempFiles/currentServer.txt",
  'lastUpdated'                         => "$tempFiles/lastUpdated.json",
  'lastUpdated-old'                     => "$tempFiles/lastUpdated-old.json",
  'addConverted'                        => "$tempFiles/TrippingTheRift",                    /* flag to indicate a rescan needed since a dockerHub container was added */
  'convertedTemplates'                  => "$flashDrive/private/",                        /* path to private repositories on boot device */
  'moderationURL'                       => "https://raw.githubusercontent.com/Squidly271/Community-Applications-Moderators/master/Moderation.json",
  'moderation'                          => "$tempFiles/moderation.json",                    /* json file that has all of the moderation */
  'unRaidVersion'                       => "/etc/unraid-version",
  'unRaidVars'                          => "/var/local/emhttp/var.ini",
  'network_ini'                         => "/var/local/emhttp/network.ini",
  'docker_cfg'                          => "/boot/config/docker.cfg",
  'dockerUpdateStatus'                  => "/var/lib/docker/unraid-update-status.json",
  'pinnedV2'                            => "$flashDrive/pinned_appsV2.json",
  'appOfTheDay'                         => "$tempFiles/appOfTheDay.json",
  'statistics'                          => "$tempFiles/statistics.json",
  'statisticsURL'                       => "https://assets.ca.unraid.net/feed/statistics.json",
  'pluginSettings'                      => "$flashDrive/community.applications.cfg",
  'fixedTemplates_txt'                  => "$tempFiles/caFixed.txt",
  'invalidXML_txt'                      => "$tempFiles/invalidxml.txt",
  'warningAccepted'                     => "$flashDrive/accepted",
  'pluginWarning'                       => "$flashDrive/plugins_accepted",
  'pluginDupes'                         => "$tempFiles/pluginDupes.json",
  'pluginTempDownload'                  => "$tempFiles/pluginTempFile.plg",
  'dockerManTemplates'                  => $dockerManPaths['templates-user'],
  'disksINI'                            => "/var/local/emhttp/disks.ini",
  'dynamixSettings'                     => "/boot/config/plugins/dynamix/dynamix.cfg",
  'dockerSettings'                      => "/boot/config/docker.cfg",
  'defaultAppdataPath'                  => "/mnt/user/appdata/",
  'installedLanguages'                  => "/boot/config/plugins",
  'dynamixUpdates'                      => "/tmp/plugins",
  'LanguageErrors'                      => "https://squidly271.github.io/languageErrors.html",
  'CA_languageBase'                     => "https://assets.ca.unraid.net/feed/languages/",
  'CA_logs'                             => "/tmp/CA_logs",
  'logging'                             => "/tmp/CA_logs/ca_log.txt",
  'languageInstalled'                   => "/usr/local/emhttp/languages/",
  'updateTime'                          => "/tmp/$CA/checkForUpdatesTime", # can't be in /tmp/community.applications/tempFiles because new feed downloads erases everything there
  'updateRunning'                       => "/tmp/$CA/updateRunning",
  'info'                                => "$tempFiles/info.json",
  'dockerSearchResults'                 => "$tempFiles/dockerSearch.json",
  'dockerSearchInstall'                 => "$tempFiles/dockerConvert.xml",
  'dockerSearchActive'                  => "$tempFiles/dockerSearchActive",
  'dockerConvertFlash'                  => $dockerManPaths['templates-user']."/my-CA_TEST_CONTAINER_DOCKERHUB.xml",
  'pluginPending'                       => "/tmp/plugins/pluginPending/",
  'phpErrorSettings'                    => "/etc/php.d/errors-php.ini",
  'pluginProxy'                         => "https://ca.unraid.net/dl/",
  'RepositoryAssets'                    => "http://ca.unraid.net/dl/https://assets.ca.unraid.net/feed/repositories/",
  'PHPErrorLog'                         => "/var/log/phplog",
  'pluginAttributesCache'               => "$tempFiles/pluginAttributesCache",
  'downloadLocks'                       => "/tmp/ca_downloadLocks.json",
  'SpotlightIcon-backup'								=> "https://github.com/unraid/community.applications/raw/master/webImages/spotlight_{$dynamixSettings['theme']}.png",
  'SpotlightIcon'                       => "https://assets.ca.unraid.net/feed/webImages/spotlight_{$dynamixSettings['theme']}.png"
]);
?>