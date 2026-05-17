#!/bin/bash
# ---------------------------------------------------------------------------
# updatePLG.sh - Check for and install a pending plugin update.
#
# Asks the dynamix plugin manager to refresh the plugin's update metadata,
# compares the staged version in /tmp/plugins to the currently installed
# version in /var/log/plugins, and invokes `plugin update` only when the
# versions differ. Avoids reinstalling an identical version.
#
# Arguments:
#   $1 - Plugin file name (e.g. `myplugin.plg`).
# Returns:
#   0 on success (no-op or successful update); non-zero if the underlying
#   plugin update command fails. Writes progress messages to stdout.
# ---------------------------------------------------------------------------

echo Getting update information...
/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin check "$1" > /dev/null 2>&1
UPDATEVER=$(/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin version /tmp/plugins/$1)
NEWVER=$(/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin version /var/log/plugins/$1)
if [ $UPDATEVER == $NEWVER ]; then
	echo "Not reinstalling same version"
else
	echo Installing update...
	/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin update "$1"
fi
