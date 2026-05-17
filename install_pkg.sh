#!/usr/bin/env bash
# install_pkg.sh — Install a built CA .txz onto Unraid from the Mac.
#
# scp's the package into /tmp on the Unraid box and runs `installpkg`
# over the same ssh transport. No SMB share involvement, no AppleDouble
# / chflags weirdness, no leftover files in /mnt/user/GitHub. The remote
# /tmp copy is removed once installpkg returns.
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
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
ARCHIVE_DIR="$ROOT/archive"
HOST_FILE="$ROOT/.unraid-host"
REMOTE_TMP="/tmp/community.applications-install.txz"

# Resolve the SSH target.
if [ -n "${UNRAID_HOST:-}" ]; then
	: # env var wins, nothing to do
elif [ -r "$HOST_FILE" ]; then
	# First non-blank, non-comment line of the file.
	UNRAID_HOST="$(sed -n '/^[[:space:]]*#/d; /^[[:space:]]*$/d; p; q' "$HOST_FILE" | tr -d '[:space:]')"
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

PKG_NAME="$(basename "$PKG")"

echo "==> Transferring $PKG_NAME -> $UNRAID_HOST:$REMOTE_TMP"
scp -q "$PKG" "$UNRAID_HOST:$REMOTE_TMP"

echo "==> Installing on $UNRAID_HOST"
echo "    rm -rf /tmp/community.applications /usr/local/emhttp/plugins/community.applications"
echo "    installpkg $REMOTE_TMP"
# Pass the package path as a positional arg so the remote shell never re-parses
# it (no command substitution, no globbing) — safe even if the path were to
# someday contain shell metacharacters.
ssh "$UNRAID_HOST" sh -s -- "$REMOTE_TMP" <<'EOF'
set -e
rm -rf /tmp/community.applications /usr/local/emhttp/plugins/community.applications
installpkg "$1"
rm -f "$1"
EOF

echo "==> Done. Reload /Apps in the Unraid GUI to see the change."
