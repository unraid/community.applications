#!/usr/bin/env bash
# Build an installable Community Applications preview for a pull request.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PR_NUMBER="${1:?usage: $0 <pr-number> <git-sha> [output-directory]}"
GIT_SHA="${2:?usage: $0 <pr-number> <git-sha> [output-directory]}"
OUTPUT_DIR="${3:-$ROOT/dist/pr-preview}"
SOURCE_DIR="$ROOT/source/community.applications"
PLUGIN_TEMPLATE="$ROOT/plugins/community.applications.plg"
SHORT_SHA="${GIT_SHA:0:7}"
VERSION="$(date -u +%Y.%m.%d)-pr${PR_NUMBER}-${SHORT_SHA}"
PACKAGE="community.applications-${VERSION}-x86_64-1.txz"
BASE_URL="https://raw.githubusercontent.com/unraid/community.applications/pr-previews/pr/${PR_NUMBER}"

if [[ ! "$PR_NUMBER" =~ ^[0-9]+$ ]]; then
	echo "PR number must be numeric: $PR_NUMBER" >&2
	exit 1
fi
if [[ ! -d "$SOURCE_DIR" || ! -f "$PLUGIN_TEMPLATE" ]]; then
	echo "Run this script from a complete community.applications checkout." >&2
	exit 1
fi

rm -rf "$OUTPUT_DIR"
mkdir -p "$OUTPUT_DIR"
STAGING="$(mktemp -d -t ca-pr-preview.XXXXXX)"
trap 'rm -rf "$STAGING"' EXIT

COPYFILE_DISABLE=1 cp -R "$SOURCE_DIR/" "$STAGING/"
find "$STAGING" \( -name '.DS_Store' -o -name '._*' -o -name 'sftp-config.json' \) -delete
find "$STAGING" -name '.claude' -type d -prune -exec rm -rf {} + 2>/dev/null || true
chmod -R 0755 "$STAGING"

if tar --version 2>/dev/null | grep -q 'GNU tar'; then
	tar -C "$STAGING" --owner=0 --group=0 --numeric-owner -cJf "$OUTPUT_DIR/$PACKAGE" .
else
	COPYFILE_DISABLE=1 tar -C "$STAGING" --uid 0 --gid 0 --uname root --gname root -cJf "$OUTPUT_DIR/$PACKAGE" .
fi

if command -v md5sum >/dev/null 2>&1; then
	MD5="$(md5sum "$OUTPUT_DIR/$PACKAGE" | awk '{print $1}')"
else
	MD5="$(md5 -q "$OUTPUT_DIR/$PACKAGE")"
fi

python3 - "$PLUGIN_TEMPLATE" "$OUTPUT_DIR/community.applications.plg" "$VERSION" "$MD5" "$BASE_URL/$PACKAGE" "$BASE_URL/community.applications.plg" <<'PY'
from pathlib import Path
import re
import sys

source, destination, version, md5, package_url, plugin_url = sys.argv[1:]
text = Path(source).read_text()
replacements = {
    "version": version,
    "md5": md5,
    "pluginURL": plugin_url,
}
for entity, value in replacements.items():
    text, count = re.subn(
        rf'(<!ENTITY\s+{entity}\s+")[^"]*(">)',
        rf'\g<1>{value}\g<2>',
        text,
        count=1,
    )
    if count != 1:
        raise SystemExit(f"could not replace {entity} entity")

text, count = re.subn(
    r'(<FILE Name="/boot/config/plugins/&name;/&name;-&version;-x86_64-1\.txz" Run="upgradepkg --install-new --reinstall">\s*<URL>)[^<]*(</URL>)',
    rf'\g<1>{package_url}\g<2>',
    text,
    count=1,
)
if count != 1:
    raise SystemExit("could not replace package URL")

Path(destination).write_text(text)
PY

cat > "$OUTPUT_DIR/preview.json" <<EOF
{"pr":${PR_NUMBER},"sha":"${GIT_SHA}","version":"${VERSION}","package":"${PACKAGE}"}
EOF

echo "Built $OUTPUT_DIR/community.applications.plg"
echo "Installer: $BASE_URL/community.applications.plg"
