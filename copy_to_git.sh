#!/usr/bin/env bash
# Mac-native version of copy_to_git.sh.
#
# Pulls the live Community Applications plugin tree from the SMB-mounted
# Unraid share into this repo's source/ directory, then regenerates ca.md5.
# Stand-in for the legacy Linux script (kept in copy_to_git.sh.bak) which
# ran on the Unraid host itself and copied from /usr/local/emhttp/...
#
# Run from anywhere — the script resolves its own location and operates on
# paths relative to the repo root.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
SRC="/Volumes/EMHTTP PLUGINS/community.applications"
DST="$ROOT/source/community.applications/usr/local/emhttp/plugins/community.applications"

if [ ! -d "$SRC" ]; then
	echo "Live install not mounted at:" >&2
	echo "  $SRC" >&2
	echo "Mount the EMHTTP PLUGINS SMB share in Finder and retry." >&2
	exit 1
fi

echo "==> Syncing $SRC -> source/"
rm -rf "$DST"
mkdir -p "$DST"

# COPYFILE_DISABLE keeps `cp` from sprinkling ._* AppleDouble metadata files
# alongside the copied tree. The trailing slash on $SRC copies the directory
# *contents* (matches the legacy script's `cp /path/*` behaviour).
COPYFILE_DISABLE=1 cp -R "$SRC/" "$DST/"

# Belt-and-braces: scrub any mac metadata that snuck in via the SMB layer.
echo "==> Pruning macOS metadata files"
find "$DST" -name ".DS_Store" -delete
find "$DST" -name "._*"       -delete

# Regenerate ca.md5 in the Linux md5sum format (`<hash>  <relative path>`,
# two-space separator) so `md5sum -c` works on the Unraid side.
echo "==> Regenerating ca.md5"
cd "$DST"
rm -f ca.md5
find . -type f -exec md5 -r {} + | awk '{print $1"  "$2}' > ca.md5

echo "==> Done. Source tree updated at:"
echo "    $DST"
