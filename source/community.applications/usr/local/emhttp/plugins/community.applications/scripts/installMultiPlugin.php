#!/usr/bin/php
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
require_once "/usr/local/emhttp/plugins/community.applications/include/paths.php";
require_once "/usr/local/emhttp/plugins/community.applications/include/helpers.php";

$apps = readJsonFile(CA_PATHS['community-templates-info']);
$plugins = explode("*",$argv[1]);
foreach ($plugins as $plugin) {
  echo $plugin;
  if (! $plugin ) continue;
  $pluginName = basename($plugin);
  $pathInfo = pathinfo($plugin);
  if ( $pathInfo['extension'] !== "plg" ) {
    if ( is_file("/var/log/plugins/lang-$pluginName.xml") ) {
      passthru("/usr/local/emhttp/plugins/community.applications/scripts/languageInstall.sh update $pluginName");
      continue;
    }
  }
  if ( searchArray($apps,"PluginURL",$plugin) !== false ) {
    if ( is_file("/var/log/plugins/$pluginName") ) {
      passthru("/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin update $pluginName");
    } else {
      passthru("/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin install ".escapeshellarg($plugin));
    }
  } else
    echo "$plugin not found in application feed\n";
  @unlink(CA_PATHS['pluginPending']."/".$pluginName);
}
passthru("/usr/local/emhttp/plugins/community.applications/scripts/updatePluginSupport.php");
?>