#!/bin/bash
# ---------------------------------------------------------------------------
# installPluginUpdate.sh - Trigger a plugin update via the dynamix plugin
# manager.
#
# Thin wrapper that invokes the dynamix plugin manager's `plugin update`
# command for the plugin name passed in $1. Output and exit status come
# directly from the underlying plugin manager script.
#
# Arguments:
#   $1 - Plugin file name (e.g. `myplugin.plg`) to update.
# Returns:
#   Exit status of the underlying `plugin update` invocation; writes the
#   plugin manager's progress/output to stdout.
# ---------------------------------------------------------------------------
/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin update "$1"
