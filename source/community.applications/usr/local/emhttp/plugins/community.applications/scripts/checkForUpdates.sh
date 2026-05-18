#!/bin/bash
# ---------------------------------------------------------------------------
# checkForUpdates.sh - Bash entrypoint for the CA update check.
#
# Delegates to checkForUpdates.php which iterates installed plugins, docker
# containers, and languages and asks each manager to refresh its update
# metadata. Exists so that cron / shell callers have a stable shell-script
# entrypoint.
#
# Arguments:
#   None.
# Returns:
#   Exit status of checkForUpdates.php; writes that script's output to
#   stdout.
# ---------------------------------------------------------------------------
/usr/local/emhttp/plugins/community.applications/scripts/checkForUpdates.php

