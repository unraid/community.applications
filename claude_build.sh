#!/bin/bash
# Claude's build wrapper: copy live plugin into git source dir, then
# build a "claude"-suffixed package so it's identifiable vs. user builds.
# Invoked from the Mac as:  ssh root@<host> "bash /mnt/user/GitHub/community.applications/claude_build.sh"
set -e
bash /mnt/user/GitHub/community.applications/copy_to_git.sh > /tmp/claude_copy_to_git.log 2>&1
bash /mnt/user/GitHub/community.applications/pkg_build.sh claude
