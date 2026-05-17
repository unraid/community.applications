<?
########################################
#                                      #
# Community Applications               #
# Copyright 2020-2026, Lime Technology #
# Copyright 2015-2026, Andrew Zawadzki #
#                                      #
# Licensed under GPL-2.0-or-later      #
# SPDX-License-Identifier:             #
#   GPL-2.0-or-later                   #
#                                      #
########################################
?>
<style>
.logLine{color:black !important;}
</style>
<?
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: "/usr/local/emhttp";

$_SERVER['REQUEST_URI'] = "docker/apps";
@require_once "$docroot/plugins/dynamix/include/Translations.php";

require_once "/usr/local/emhttp/plugins/dynamix/include/Helpers.php";

$_GET['updateContainer'] = "true";
$_GET['mute'] = false;
//	$_GET['communityApplications'] = true;
include("/usr/local/emhttp/plugins/dynamix.docker.manager/include/CreateDocker.php");
?>
<script src='<?autov("/plugins/dynamix/javascript/dynamix.js");?>'></script>
<script>
// Redefine the done button to something CA can use
$(":button").attr("onclick","top.Shadowbox.close();");
window.scrollTo(0,1e10);
</script>
