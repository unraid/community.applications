#!/bin/bash
# ---------------------------------------------------------------------------
# languageInstall.sh - Install or update a translation pack.
#
# When the action in $1 is `update`, first asks the dynamix plugin manager to
# refresh the language pack's update metadata, then dispatches the requested
# action to the manager's `language` script.
#
# Arguments:
#   $1 - Action to perform (e.g. `install`, `update`, `remove`).
#   $2 - Language pack identifier (e.g. `de_DE`).
# Returns:
#   Exit status of the final `language` invocation; writes that command's
#   progress output to stdout.
# ---------------------------------------------------------------------------
if [ "$1" = "update" ]; then
	/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/language check $2
fi
/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/language $1 $2
