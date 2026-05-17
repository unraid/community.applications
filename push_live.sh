#!/usr/bin/env bash
# push_live.sh — Push edits from the local source/ tree to the live CA
# install on Unraid via scp. No build / installpkg cycle, no SMB share.
# Use this for iterative edit-and-refresh during development; use
# install_pkg.sh when you want to exercise the real install flow.
#
# Usage:
#   ./push_live.sh                              # full sync (everything in source/.../community.applications)
#   ./push_live.sh source/.../Apps.page         # one file
#   ./push_live.sh source/.../include/exec.php source/.../skins/Narrow/skin.php   # several files
#   ./push_live.sh source/.../include/          # whole subdirectory
#
# Arguments must live inside ./source/community.applications/ — the
# corresponding remote path is derived by stripping that prefix and
# resolving against the live install root (/usr/local/emhttp/plugins/
# community.applications/).
#
# SSH target resolution (no hostname ever lives in tracked source):
#   1. UNRAID_HOST env var, if set
#   2. ./.unraid-host file at the repo root (single line "user@host")
#   Neither -> the script exits with a setup hint.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
HOST_FILE="$ROOT/.unraid-host"
SOURCE_ROOT="$ROOT/source/community.applications"
LIVE_PREFIX="/usr/local/emhttp/plugins/community.applications"

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

if [ ! -d "$SOURCE_ROOT$LIVE_PREFIX" ]; then
	echo "Source tree missing at $SOURCE_ROOT$LIVE_PREFIX — run ./copy_to_git.sh first." >&2
	exit 1
fi

# No args -> full sync of the entire plugin dir.
if [ "$#" -eq 0 ]; then
	echo "==> Full sync  source$LIVE_PREFIX/  ->  $UNRAID_HOST:$LIVE_PREFIX/"
	scp -rq "$SOURCE_ROOT$LIVE_PREFIX/." "$UNRAID_HOST:$LIVE_PREFIX/"
	echo "==> Done."
	exit 0
fi

# Args -> push specific files / dirs. Map each path to its mirror under
# /usr/local/emhttp/plugins/community.applications/ and scp it over.
for arg in "$@"; do
	if [ ! -e "$arg" ]; then
		echo "Path not found: $arg" >&2
		exit 1
	fi
	# Absolute path of arg (portable: dirname + pwd -P + basename).
	abs="$(cd -- "$(dirname -- "$arg")" && pwd -P)/$(basename -- "$arg")"
	# Strip the source root to get the relative-from-/ portion.
	rel="${abs#"$SOURCE_ROOT"/}"
	if [ "$rel" = "$abs" ]; then
		echo "Not inside $SOURCE_ROOT: $arg" >&2
		exit 1
	fi
	remote="/$rel"
	if [ -d "$abs" ]; then
		echo "==> $arg/  ->  $UNRAID_HOST:$remote/"
		# Trailing /. on src + trailing / on dst copies *contents* of the
		# directory into the existing remote directory.
		# Pass $remote as a positional arg so the remote shell never re-parses it.
		ssh "$UNRAID_HOST" sh -s -- "$remote" <<-'EOF'
			set -e
			mkdir -p "$1"
		EOF
		scp -rq "$abs/." "$UNRAID_HOST:$remote/"
	else
		echo "==> $arg  ->  $UNRAID_HOST:$remote"
		ssh "$UNRAID_HOST" sh -s -- "$(dirname "$remote")" <<-'EOF'
			set -e
			mkdir -p "$1"
		EOF
		scp -q "$abs" "$UNRAID_HOST:$remote"
	fi
done

echo "==> Done."
