#!/bin/bash

# Configuration
UNRAID_HOST="root@192.168.1.192"
PLUGIN_NAME="community.applications"
REMOTE_BUILD_DIR="/tmp/ca_build_workspace"
LOCAL_OUTPUT_DIR="./dist"
# Add timestamp (HHMM) and -dev suffix so it's obvious this is a dev build
VERSION=$(date +"%Y.%m.%d-%H%M-dev")
# We prefix the filename with dev. so the file itself is clearly distinguishable
OUTPUT_FILENAME="dev.${PLUGIN_NAME}-${VERSION}-x86_64-1.txz"

# Colors
GREEN='\033[0;32m'
NC='\033[0m' # No Color

# Kill any existing python servers we started
pkill -f "python3 -m http.server $PORT" 2>/dev/null

# 1. Clean previous builds
if [ -d "$LOCAL_OUTPUT_DIR" ]; then
    echo -e "${GREEN}==> Cleaning old build artifacts in $LOCAL_OUTPUT_DIR...${NC}"
    rm -rf "$LOCAL_OUTPUT_DIR"
fi
mkdir -p "$LOCAL_OUTPUT_DIR"

echo -e "${GREEN}==> Syncing source code to Unraid server ($UNRAID_HOST)...${NC}"
# We exclude .git and other dev files to keep the transfer fast and clean
rsync -avz --delete \
  --exclude '.git' \
  --exclude '.DS_Store' \
  --exclude 'dist' \
  --exclude 'archive' \
  ./ "$UNRAID_HOST:$REMOTE_BUILD_DIR/"

echo -e "${GREEN}==> Executing build on Unraid server...${NC}"
ssh "$UNRAID_HOST" "bash -s" <<EOF
    set -e # Exit on error
    
    # 1. Go to source directory
    cd "$REMOTE_BUILD_DIR/source/$PLUGIN_NAME"
    
    # 2. Set permissions usually required for Unraid plugins
    chmod -R 755 .
    
    # 3. Create a temporary build root for makepkg
    BUILD_ROOT=\$(mktemp -d)
    echo "Created temp build root at \$BUILD_ROOT"
    
    # 4. Copy files to build root (replicating the plugin structure)
    # The source structure seems to be source/community.applications/usr/...
    cp -R * "\$BUILD_ROOT/"
    
    # 5. Run makepkg
    # We output to the REMOTE_BUILD_DIR
    PACKAGE_NAME="${OUTPUT_FILENAME}"
    cd "\$BUILD_ROOT"
    makepkg -l y -c y "$REMOTE_BUILD_DIR/\$PACKAGE_NAME"
    
    # 6. Cleanup
    rm -rf "\$BUILD_ROOT"
    
    echo "Build complete: $REMOTE_BUILD_DIR/\$PACKAGE_NAME"
EOF

echo -e "${GREEN}==> Downloading built package to $LOCAL_OUTPUT_DIR...${NC}"
scp "$UNRAID_HOST:$REMOTE_BUILD_DIR/${OUTPUT_FILENAME}" "$LOCAL_OUTPUT_DIR/"

echo -e "${GREEN}==> Cleanup remote workspace...${NC}"
ssh "$UNRAID_HOST" "rm -rf $REMOTE_BUILD_DIR"

# 2. Detect Local IP for the HTTP Server
# We find which interface routes to the Unraid IP
UNRAID_IP=$(echo "$UNRAID_HOST" | cut -d@ -f2)
INTERFACE=$(route -n get "$UNRAID_IP" | grep 'interface:' | awk '{print $2}')
LOCAL_IP=$(ipconfig getifaddr "$INTERFACE")
PORT="8000"

echo -e "${GREEN}==> Generating .plg file for installation...${NC}"
# Calculate MD5 of the local .txz file
# BSD md5 (macOS) vs GNU md5sum (Linux)
if command -v md5 > /dev/null; then
    MD5_CHECKSUM=$(md5 -q "$LOCAL_OUTPUT_DIR/$OUTPUT_FILENAME")
else
    MD5_CHECKSUM=$(md5sum "$LOCAL_OUTPUT_DIR/$OUTPUT_FILENAME" | awk '{print $1}')
fi

# Define the PLG filename
# We MUST name it community.applications.plg so Unraid recognizes it as the official plugin
PLG_FILENAME="${PLUGIN_NAME}.plg"
PLG_PATH="$LOCAL_OUTPUT_DIR/$PLG_FILENAME"

# 3. Create the .plg file using HTTP URLs
# We use the official 'Run="upgradepkg --install-new"' attribute to ensure installation happens.
cat <<EOF > "$PLG_PATH"
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name      "community.applications">
<!ENTITY author    "DevBuild">
<!ENTITY version   "${VERSION}">
<!ENTITY md5       "${MD5_CHECKSUM}">
<!ENTITY launch    "Apps">
<!ENTITY plugdir   "/usr/local/emhttp/plugins/&name;">
<!ENTITY pluginURL "http://${LOCAL_IP}:${PORT}/${PLG_FILENAME}">
]>

<PLUGIN name="&name;" author="&author;" version="&version;" launch="&launch;" pluginURL="&pluginURL;" min="6.12.0" icon="users">

<FILE Name="/boot/config/plugins/community.applications/${OUTPUT_FILENAME}" Run="upgradepkg --install-new">
<URL>http://${LOCAL_IP}:${PORT}/${OUTPUT_FILENAME}</URL>
<MD5>&md5;</MD5>
</FILE>

<!-- Log success -->
<FILE Run="/bin/bash">
<INLINE>
echo "----------------------------------------------------"
echo " Dev Build Installed Successfully!"
echo " Version: &version;"
echo "----------------------------------------------------"
</INLINE>
</FILE>

</PLUGIN>
EOF

echo -e "${GREEN}==> Done! Artifacts in $LOCAL_OUTPUT_DIR:${NC}"
echo -e "   Package: $OUTPUT_FILENAME"
echo -e "   Plugin:  $PLG_FILENAME"
echo -e ""
echo -e "${GREEN}==> Ready to install!${NC}"

# Ensure directory exists on target
ssh "$UNRAID_HOST" "mkdir -p /boot/config/plugins/community.applications/"

echo -e "1. Copy this URL:"
echo -e "   ${GREEN}http://${LOCAL_IP}:${PORT}/${PLG_FILENAME}${NC}"
echo -e "2. Go to Unraid > Plugins > Install Plugin"
echo -e "3. Paste the URL and click Install."
echo -e ""
echo -e "${GREEN}==> Starting local web server on port $PORT... (Press Ctrl+C to stop)${NC}"

# Ensure we use python3 and handle potential errors cleanly
python3 -m http.server "$PORT" --directory "$LOCAL_OUTPUT_DIR"
