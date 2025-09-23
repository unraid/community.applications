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

$caPaths['tempFiles']                           = "/tmp/$CA/tempFiles";                            /* path to temporary files */
$caPaths['flashDrive']                          = "/boot/config/plugins/$CA";
$caPaths['templates-community']                 = $caPaths['tempFiles']."/templates-community-apps";           /* templates and temporary files stored here.  Deleted every update of applications */
$caPaths['community-templates-url']             = "https://raw.githubusercontent.com/Squidly271/Community-Applications-Moderators/master/Repositories.json";
$caPaths['PublicServiceAnnouncement']           = "https://raw.githubusercontent.com/Squidly271/Community-Applications-Moderators/master/PublicServiceAnnouncement.txt";
$caPaths['community-templates-info']            = $caPaths['tempFiles']."/templates_new.json";                     /* json file containing all of the templates */
$caPaths['community-templates-info-old']        = $caPaths['tempFiles']."/templates.json";  /* this file is for plugin script to update suppport URLs on plugins.  Has to be in JSON format */
$caPaths['haveTemplates']												= $caPaths['tempFiles']."/haveTemplates";
$caPaths['community-templates-displayed']       = $caPaths['tempFiles']."/displayed.json";                     /* json file containing all of the templates currently displayed */
$caPaths['community-templates-allSearchResults']= $caPaths['tempFiles']."/allSearchResults.json";
$caPaths['community-templates-catSearchResults']= $caPaths['tempFiles']."/catSearchResults.json";
$caPaths['startupDisplayed']                    = $caPaths['tempFiles']."/startupDisplayed";
$caPaths['repositoriesDisplayed']               = $caPaths['tempFiles']."/repositoriesDisplayed.json";
$caPaths['localONLY']                           = false;    /* THIS MUST NOT BE SET TO TRUE WHEN DOING A RELEASE */
$caPaths['application-feed']                    = "https://ca.unraid.net/assets/feed/applicationFeed.json";
$caPaths['application-feed-last-updated']       = "https://ca.unraid.net/assets/feed/applicationFeed-lastUpdated.json";
$caPaths['application-feedBackup']              = "https://raw.githubusercontent.com/Squidly271/AppFeed/master/applicationFeed.json";
$caPaths['application-feed-last-updatedBackup'] = "https://raw.githubusercontent.com/Squidly271/AppFeed/master/applicationFeed-lastUpdated.json";
$caPaths['application-feed-local']              = "/tmp/GitHub/AppFeed/applicationFeed.json";
$caPaths['appFeedDownloadError']                = $caPaths['tempFiles']."/downloaderror.txt";
$caPaths['categoryList']                        = $caPaths['tempFiles']."/categoryList.json";
$caPaths['repositoryList']                      = $caPaths['tempFiles']."/repositoryList.json";
$caPaths['extraBlacklist']                      = $caPaths['tempFiles']."/extraBlacklist.json";
$caPaths['extraDeprecated']                     = $caPaths['tempFiles']."/extraDeprecated.json";
$caPaths['sortOrder']                           = $caPaths['tempFiles']."/sortOrder.json";
$caPaths['currentServer']                       = $caPaths['tempFiles']."/currentServer.txt";
$caPaths['lastUpdated']                         = $caPaths['tempFiles']."/lastUpdated.json";
$caPaths['lastUpdated-old']                     = $caPaths['tempFiles']."/lastUpdated-old.json";
$caPaths['addConverted']                        = $caPaths['tempFiles']."/TrippingTheRift";                    /* flag to indicate a rescan needed since a dockerHub container was added */
$caPaths['convertedTemplates']                  = "{$caPaths['flashDrive']}/private/";                        /* path to private repositories on boot device */
$caPaths['moderationURL']                       = "https://raw.githubusercontent.com/Squidly271/Community-Applications-Moderators/master/Moderation.json";
$caPaths['moderation']                          = $caPaths['tempFiles']."/moderation.json";                    /* json file that has all of the moderation */
$caPaths['unRaidVersion']                       = "/etc/unraid-version";
$caPaths['unRaidVars']                          = "/var/local/emhttp/var.ini";
$caPaths['network_ini']                         = "/var/local/emhttp/network.ini";
$caPaths['docker_cfg']                          = "/boot/config/docker.cfg";
$caPaths['dockerUpdateStatus']                  = "/var/lib/docker/unraid-update-status.json";
$caPaths['pinnedV2']                            = "{$caPaths['flashDrive']}/pinned_appsV2.json";
$caPaths['appOfTheDay']                         = $caPaths['tempFiles']."/appOfTheDay.json";
$caPaths['statistics']                          = $caPaths['tempFiles']."/statistics.json";
$caPaths['statisticsURL']                       = "https://assets.ca.unraid.net/feed/statistics.json";
$caPaths['pluginSettings']                      = "{$caPaths['flashDrive']}/community.applications.cfg";
$caPaths['fixedTemplates_txt']                  = $caPaths['tempFiles']."/caFixed.txt";
$caPaths['invalidXML_txt']                      = $caPaths['tempFiles']."/invalidxml.txt";
$caPaths['warningAccepted']                     = "{$caPaths['flashDrive']}/accepted";
$caPaths['pluginWarning']                       = "{$caPaths['flashDrive']}/plugins_accepted";
$caPaths['pluginDupes']                         = $caPaths['tempFiles']."/pluginDupes.json";
$caPaths['pluginTempDownload']                  = $caPaths['tempFiles']."/pluginTempFile.plg";
$caPaths['dockerManTemplates']                  = $dockerManPaths['templates-user'];
$caPaths['disksINI']                            = "/var/local/emhttp/disks.ini";
$caPaths['dynamixSettings']                     = "/boot/config/plugins/dynamix/dynamix.cfg";
$caPaths['dockerSettings']                      = "/boot/config/docker.cfg";
$caPaths['defaultAppdataPath']                  = "/mnt/user/appdata/";
$caPaths['installedLanguages']                  = "/boot/config/plugins";
$caPaths['dynamixUpdates']                      = "/tmp/plugins";
$caPaths['LanguageErrors']                      = "https://squidly271.github.io/languageErrors.html";
$caPaths['CA_languageBase']                     = "https://assets.ca.unraid.net/feed/languages/";
$caPaths['CA_logs']                             = "/tmp/CA_logs";
$caPaths['logging']                             = "{$caPaths['CA_logs']}/ca_log.txt";
$caPaths['languageInstalled']                   = "/usr/local/emhttp/languages/";
$caPaths['updateTime']                          = "/tmp/$CA/checkForUpdatesTime"; # can't be in /tmp/community.applications/tempFiles because new feed downloads erases everything there
$caPaths['updateRunning']                       = "/tmp/$CA/updateRunning";
$caPaths['info']                                = $caPaths['tempFiles']."/info.json";
$caPaths['dockerSearchResults']                 = $caPaths['tempFiles']."/dockerSearch.json";
$caPaths['dockerSearchInstall']                 = $caPaths['tempFiles']."/dockerConvert.xml";
$caPaths['dockerSearchActive']                  = $caPaths['tempFiles']."/dockerSearchActive";
$caPaths['dockerConvertFlash']                  = $dockerManPaths['templates-user']."/my-CA_TEST_CONTAINER_DOCKERHUB.xml";
$caPaths['pluginPending']                       = "/tmp/plugins/pluginPending/";
$caPaths['phpErrorSettings']                    = "/etc/php.d/errors-php.ini";
$caPaths['pluginProxy']                         = "https://ca.unraid.net/dl/";
$caPaths['RepositoryAssets']                    = "http://ca.unraid.net/dl/https://assets.ca.unraid.net/feed/repositories/";
$caPaths['PHPErrorLog']                         = "/var/log/phplog";
$caPaths['pluginAttributesCache']               = $caPaths['tempFiles']."/pluginAttributesCache";

$dynamixSettings = parse_plugin_cfg("dynamix");
$caPaths['SpotlightIcon-backup']								= "https://github.com/unraid/community.applications/raw/master/webImages/spotlight_{$dynamixSettings['theme']}.png";
$caPaths['SpotlightIcon']                       = "https://assets.ca.unraid.net/feed/webImages/spotlight_{$dynamixSettings['theme']}.png";
?>