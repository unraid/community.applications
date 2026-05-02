#!/usr/bin/env bash
# Run all CA unit tests on the Unraid server.
# Tests live at /Volumes/GitHub/community.applications/tests/ on the Mac, which
# maps to /mnt/user/GitHub/community.applications/tests/ on the server.
# This dir is OUTSIDE source/community.applications/ so pkg_build.sh never
# packages them — they exist only for local CI.
#
# Usage: ./run.sh
#        Exits 0 if every suite passes, 1 if any suite has failures.
set -u

REMOTE_HOST="${CA_TEST_HOST:-root@unraida-1.tail4a32cc.ts.net}"
REMOTE_TESTS_DIR="/mnt/user/GitHub/community.applications/tests"

# Quoted heredoc (<<'EOF') so $variables aren't expanded client-side; we pass
# REMOTE_TESTS_DIR explicitly via the ssh command env so the remote shell uses
# its own value with no escaping required inside the script body.
ssh -o BatchMode=yes "$REMOTE_HOST" REMOTE_TESTS_DIR="$REMOTE_TESTS_DIR" bash -s <<'EOF'
set -u
overall=0
for t in "$REMOTE_TESTS_DIR"/test_*.php; do
  echo
  echo "════════════════════════════════════════════════════════"
  echo "  $(basename "$t")"
  echo "════════════════════════════════════════════════════════"
  if ! php "$t"; then
    overall=1
  fi
done
echo
echo "════════════════════════════════════════════════════════"
if [ $overall -eq 0 ]; then
  echo "  ALL SUITES PASSED"
else
  echo "  ONE OR MORE SUITES FAILED"
fi
echo "════════════════════════════════════════════════════════"
exit $overall
EOF
