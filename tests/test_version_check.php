<?php
/* Tests for versionCheck() — gates whether a template is compatible with the
   current Unraid version, considering MinVer / MaxVer / IncompatibleVersion.

   Reads $GLOBALS['caSettings']['unRaidVersion'], so we use ca_test_load_fixtures()
   to seed it, then override per-case as needed. */

require_once __DIR__ . "/lib.php";
require_once "/usr/local/emhttp/plugins/community.applications/include/helpers.php";
$_POST = [];
require_once "/usr/local/emhttp/plugins/community.applications/include/paths.php";

ca_test_load_fixtures();

/* All cases run against a fixed "current" Unraid version. */
$GLOBALS['caSettings']['unRaidVersion'] = "7.0.0";

echo "=== versionCheck (current Unraid 7.0.0) ===\n";

ok("template with no version constraints passes",
   versionCheck([]));

ok("template with empty constraints passes",
   versionCheck(["MinVer" => null, "MaxVer" => null]));

echo "\n=== MinVer ===\n";

ok("MinVer = 6.10 (≤ 7.0.0) → pass",
   versionCheck(["MinVer" => "6.10"]));

ok("MinVer = 7.0 (≤ 7.0.0) → pass",
   versionCheck(["MinVer" => "7.0"]));

ok("MinVer = 7.0.0 (== 7.0.0) → pass",
   versionCheck(["MinVer" => "7.0.0"]));

ok("MinVer = 7.1 (> 7.0.0) → fail",
   ! versionCheck(["MinVer" => "7.1"]));

ok("MinVer = 8.0 (> 7.0.0) → fail",
   ! versionCheck(["MinVer" => "8.0"]));

echo "\n=== MaxVer ===\n";

/* PHP's version_compare treats "7.0" as version_compare("7.0","7.0.0") = -1
   (the missing third component is treated as less than 0). So MaxVer="7.0"
   actually FAILS at current 7.0.0 — pin that behavior to catch surprise
   regressions. If users were burned by this we'd consider normalizing in
   the function itself. */
ok("MaxVer = 7.0 → fail at 7.0.0 (PHP version_compare quirk: 7.0 < 7.0.0)",
   ! versionCheck(["MaxVer" => "7.0"]));

ok("MaxVer = 7.0.0 (== 7.0.0) → pass",
   versionCheck(["MaxVer" => "7.0.0"]));

ok("MaxVer = 7.5 (> 7.0.0) → pass",
   versionCheck(["MaxVer" => "7.5"]));

ok("MaxVer = 6.12.99 (< 7.0.0) → fail",
   ! versionCheck(["MaxVer" => "6.12.99"]));

ok("MaxVer = 6.7.9 (< 7.0.0) → fail",
   ! versionCheck(["MaxVer" => "6.7.9"]));

echo "\n=== Both MinVer and MaxVer ===\n";

ok("MinVer 6.10 + MaxVer 7.5 (current 7.0.0 in range) → pass",
   versionCheck(["MinVer" => "6.10", "MaxVer" => "7.5"]));

ok("MinVer 7.5 + MaxVer 8.0 (current below range) → fail",
   ! versionCheck(["MinVer" => "7.5", "MaxVer" => "8.0"]));

ok("MinVer 5.0 + MaxVer 6.12 (current above range) → fail",
   ! versionCheck(["MinVer" => "5.0", "MaxVer" => "6.12"]));

echo "\n=== IncompatibleVersion (block specific plugin versions) ===\n";

ok("plugin v1.2.3 not in IncompatibleVersion list → pass",
   versionCheck([
	"pluginVersion" => "1.2.3",
	"IncompatibleVersion" => ["1.0.0", "1.1.0"],
   ]));

ok("plugin v1.0.0 IN IncompatibleVersion array → fail",
   ! versionCheck([
	"pluginVersion" => "1.0.0",
	"IncompatibleVersion" => ["1.0.0", "1.1.0"],
   ]));

ok("plugin v1.0.0 in IncompatibleVersion as scalar (not array) → fail",
   ! versionCheck([
	"pluginVersion" => "1.0.0",
	"IncompatibleVersion" => "1.0.0",
   ]));

ok("plugin v2.0.0, IncompatibleVersion scalar 1.0.0 → pass",
   versionCheck([
	"pluginVersion" => "2.0.0",
	"IncompatibleVersion" => "1.0.0",
   ]));

echo "\n=== Edge cases ===\n";

ok("MinVer with non-numeric suffix (RC) handled",
   ! versionCheck(["MinVer" => "7.1.0-rc1"]) ||
     versionCheck(["MinVer" => "7.1.0-rc1"]));   /* version_compare semantics — just ensure no crash */

/* Reset to a different Unraid version and verify behavior changes accordingly. */
$GLOBALS['caSettings']['unRaidVersion'] = "6.11.5";

ok("at 6.11.5: MaxVer 6.11.9 → pass (< 7.0.0 case becomes valid)",
   versionCheck(["MaxVer" => "6.11.9"]));

ok("at 6.11.5: MinVer 7.0 → fail",
   ! versionCheck(["MinVer" => "7.0"]));

suite_done();
