#!/usr/bin/env bash
# install_pkg.sh — Install a built CA .txz onto Unraid from the Mac.
#
# Drops the package through the GitHub SMB share into Unraid's archive/
# directory and runs `installpkg` over SSH — matches your existing manual
# workflow exactly, no web-server / HTTP-fetch dance.
#
# Usage:
#   ./install_pkg.sh                       # newest .txz in ./archive/
#   ./install_pkg.sh path/to/file.txz      # explicit package
#
# SSH target resolution (no hostname ever lives in tracked source):
#   1. UNRAID_HOST env var, if set
#   2. ./.unraid-host file at the repo root (single line "user@host")
#   Neither -> the script exits with a setup hint.
# The .unraid-host file is gitignored so the box you push to (often a
# Tailscale name) never leaks into the repo or a fresh clone.
#
# Other overrides:
#   GH_SHARE_LOCAL        Mac path to /mnt/user/GitHub on Unraid
#                         (default: /Volumes/GitHub/community.applications/archive)
#   GH_SHARE_REMOTE       Equivalent path Unraid sees
#                         (default: /mnt/user/GitHub/community.applications/archive)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
ARCHIVE_DIR="$ROOT/archive"
HOST_FILE="$ROOT/.unraid-host"

# Resolve the SSH target.
if [ -n "${UNRAID_HOST:-}" ]; then
	: # env var wins, nothing to do
elif [ -r "$HOST_FILE" ]; then
	# First non-blank, non-comment line of the file.
	UNRAID_HOST="$(grep -v '^\s*\(#\|$\)' "$HOST_FILE" | head -1 | tr -d '[:space:]')"
fi

if [ -z "${UNRAID_HOST:-}" ]; then
	cat >&2 <<-EOMSG
		No Unraid host configured.

		Set it one of two ways:
		  - export UNRAID_HOST=user@host  (per-shell, not persisted)
		  - echo user@host > $HOST_FILE   (per-clone, gitignored)
	EOMSG
	exit 1
fi

GH_SHARE_LOCAL="${GH_SHARE_LOCAL:-/Volumes/GitHub/community.applications/archive}"
GH_SHARE_REMOTE="${GH_SHARE_REMOTE:-/mnt/user/GitHub/community.applications/archive}"

# Resolve which .txz to install.
if [ "$#" -ge 1 ]; then
	PKG="$1"
	if [ ! -f "$PKG" ]; then
		echo "Package not found: $PKG" >&2
		exit 1
	fi
else
	PKG="$(ls -t "$ARCHIVE_DIR"/*.txz 2>/dev/null | head -1 || true)"
	if [ -z "$PKG" ]; then
		echo "No .txz found in $ARCHIVE_DIR — run ./pkg_build.sh first." >&2
		exit 1
	fi
	echo "==> Using newest build: $(basename "$PKG")"
fi

# Sanity: the GitHub share has to be mounted so we can drop the file where
# Unraid expects to read it from.
if [ ! -d "$GH_SHARE_LOCAL" ]; then
	echo "GitHub SMB share not mounted at:" >&2
	echo "  $GH_SHARE_LOCAL" >&2
	echo "Mount it in Finder (or override GH_SHARE_LOCAL) and retry." >&2
	exit 1
fi

PKG_NAME="$(basename "$PKG")"
DEST_LOCAL="$GH_SHARE_LOCAL/$PKG_NAME"
DEST_REMOTE="$GH_SHARE_REMOTE/$PKG_NAME"

echo "==> Copying $PKG_NAME -> $GH_SHARE_LOCAL/"
# COPYFILE_DISABLE keeps cp from sprinkling ._* AppleDouble metadata onto
# the SMB target alongside the package itself.
COPYFILE_DISABLE=1 cp -p "$PKG" "$DEST_LOCAL"

echo "==> Installing on $UNRAID_HOST"
echo "    rm -rf /tmp/community.applications /usr/local/emhttp/plugins/community.applications"
echo "    installpkg $DEST_REMOTE"
ssh "$UNRAID_HOST" "rm -rf /tmp/community.applications /usr/local/emhttp/plugins/community.applications && installpkg \"$DEST_REMOTE\""

echo "==> Done. Reload /Apps in the Unraid GUI to see the change."
