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
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: "/usr/local/emhttp";

$_SERVER['REQUEST_URI'] = "docker/apps";
@require_once "$docroot/plugins/dynamix/include/Translations.php";
require_once "$docroot/plugins/community.applications/include/paths.php";
require_once "$docroot/plugins/community.applications/include/helpers.php";

if ( is_file(CA_PATHS['updateRunning']) && file_exists("/proc/".@file_get_contents(CA_PATHS['updateRunning'])) ) {
  echo tr("Check for updates already running")."\n";
  exit();
}

file_put_contents(CA_PATHS['updateRunning'],getmypid());

$updateFile = is_file("$docroot/plugins/dynamix.docker.manager/scripts/dockerupdate") ? "dockerupdate" : "dockerupdate.php";
echo tr("Checking for docker container updates")."\n";
exec("$docroot/plugins/dynamix.docker.manager/scripts/dockerupdate check nonotify > /dev/null 2>&1");
echo tr("Checking for plugin updates")."\n";
foreach (glob("/var/log/plugins/*.plg") as $plg) {
  if ( $plg == "/var/log/plugins/community.applications.plg" || $plg == "/var/log/plugins/unRAIDServer.plg" || $plg == "/var/log/plugins/gui.search.plg" || $plg == "/var/log/plugins/page.notes.plg")
    continue; // avoid possible race condition since CA / gui.search automatically check for updates for themselves when on Apps tab
  echo sprintf(tr("Checking %s"),$plg)."\n";
  exec("$docroot/plugins/dynamix.plugin.manager/scripts/plugin check ".escapeshellarg(basename($plg))." > /dev/null 2>&1");
}
echo tr("Checking for language updates")."\n";
foreach (glob("/var/log/plugins/lang-*.xml") as $lang) {
  $lingo = str_replace(["lang-",".xml"],["",""],$lang);
  echo sprintf(tr("Checking %s"),basename($lingo))."\n";
  exec("$docroot/plugins/dynamix.plugin.manager/scripts/language check ".basename($lingo));
}
@unlink(CA_PATHS['updateRunning']);
?>