#!/usr/bin/env bash
# Mac-native version of pkg_build.sh.
#
# Builds a Slackware-compatible .txz package from the source/ tree using
# BSD tar's built-in xz support (no Slackware `makepkg` required, no
# Unraid roundtrip). The result drops into ./archive/ and is installable
# on Unraid via `installpkg` exactly like the original .txz format.
#
# Usage:
#   ./pkg_build.sh            -> archive/community.applications-YYYY.MM.DD-x86_64-1.txz
#   ./pkg_build.sh <suffix>   -> archive/community.applications-YYYY.MM.DD<suffix>-x86_64-1.txz
#
# Replaces the legacy Linux script (saved as pkg_build.sh.bak) which used
# Slackware's makepkg + `cp --parents` (GNU-only) and only ran on Unraid.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
SOURCE_DIR="$ROOT/source/community.applications"
ARCHIVE_DIR="$ROOT/archive"

if [ ! -d "$SOURCE_DIR" ]; then
	echo "Source tree not found at:" >&2
	echo "  $SOURCE_DIR" >&2
	echo "Run ./copy_to_git.sh first." >&2
	exit 1
fi

version="$(date +%Y.%m.%d)${1:-}"
output="$ARCHIVE_DIR/community.applications-${version}-x86_64-1.txz"

mkdir -p "$ARCHIVE_DIR"

# Mirror source/ into a staging dir so we can normalise perms and strip
# mac/dev metadata without touching the repo. trap cleans it up on exit.
staging="$(mktemp -d -t pkg_build.XXXXXX)"
trap 'rm -rf "$staging"' EXIT

echo "==> Staging $SOURCE_DIR"
COPYFILE_DISABLE=1 cp -R "$SOURCE_DIR/" "$staging/"

# Drop mac/dev artefacts that should never ship.
find "$staging" \( \
	-name ".DS_Store" -o \
	-name "._*"       -o \
	-name "sftp-config.json" \
	\) -delete

# Slackware tradition: world-readable, executable-where-needed. Matches the
# `chmod 0755 -R .` the legacy makepkg invocation did. installpkg preserves
# whatever modes are in the tar archive.
chmod -R 0755 "$staging"

echo "==> Building $output"
# BSD tar's -J = xz compression. COPYFILE_DISABLE keeps any internal cp
# calls (and `tar -p` xattr preservation) from leaking AppleDouble entries.
# installpkg on Unraid sees a vanilla `tar xJf` archive — same shape the
# Slackware makepkg produced.
COPYFILE_DISABLE=1 tar -C "$staging" -cJf "$output" .

echo "==> Wrote $(du -h "$output" | awk '{print $1}')"
echo "MD5: $(md5 -q "$output")"
