#!/usr/bin/env bash
# Mac-native version of copy_to_git.sh.
#
# Pulls the live Community Applications plugin tree from Unraid over SSH
# (no SMB share involvement) into this repo's source/ directory, then
# regenerates ca.md5. Replaces the legacy Linux script (saved as
# copy_to_git.sh.bak) that ran on the Unraid host itself.
#
# SSH target resolution (no hostname ever lives in tracked source):
#   1. UNRAID_HOST env var, if set
#   2. ./.unraid-host file at the repo root (single line "user@host")
#   Neither -> the script exits with a setup hint.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
HOST_FILE="$ROOT/.unraid-host"
REMOTE_SRC="/usr/local/emhttp/plugins/community.applications"
LOCAL_DST="$ROOT/source/community.applications/usr/local/emhttp/plugins/community.applications"

# Resolve the SSH target.
if [ -n "${UNRAID_HOST:-}" ]; then
	: # env var wins
elif [ -r "$HOST_FILE" ]; then
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

echo "==> Pulling $UNRAID_HOST:$REMOTE_SRC -> source/"
rm -rf "$LOCAL_DST"
mkdir -p "$(dirname "$LOCAL_DST")"
# scp -r into the *parent* directory; scp recreates the leaf dir
# (community.applications) inside it, so the final path lands at $LOCAL_DST.
scp -rq "$UNRAID_HOST:$REMOTE_SRC" "$(dirname "$LOCAL_DST")/"

# Belt-and-braces: scrub any mac metadata that snuck in (shouldn't with scp
# from a linux source, but keep the cleanup so the workflow is consistent).
# Also drop any Claude/agent local state that may have been left in the live
# install — those files have no business in the release manifest.
echo "==> Pruning macOS metadata + local-agent state"
find "$LOCAL_DST" -name ".DS_Store" -delete
find "$LOCAL_DST" -name "._*"       -delete
rm -rf "$LOCAL_DST/.claude"

# Regenerate ca.md5 in Linux md5sum format (`<hash>  <relative path>`,
# two-space separator) so `md5sum -c` works on the Unraid side.
echo "==> Regenerating ca.md5"
cd "$LOCAL_DST"
rm -f ca.md5
find . -type f -exec md5 -r {} + | awk '{h=$1; $1=""; sub(/^ /, ""); print h"  "$0}' > ca.md5

echo "==> Done. Source tree updated at:"
echo "    $LOCAL_DST"
