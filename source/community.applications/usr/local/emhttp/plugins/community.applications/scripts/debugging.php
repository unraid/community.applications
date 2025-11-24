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

require_once "$docroot/plugins/community.applications/include/paths.php";

$file = $_POST['file'];
if (! $file ) {
  return;
}
@copy("/var/log/phplog", "/tmp/phplog.txt");
exec("zip -qlj ".escapeshellarg("$docroot/$file")." ".escapeshellarg(CA_PATHS['logging'])." /tmp/phplog.txt");
@unlink("/tmp/phplog.txt");
echo "/$file";
?>