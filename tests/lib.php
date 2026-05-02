<?php
/* Tiny assertion framework so tests don't need PHPUnit / composer.
   Each test file:
     require_once __DIR__ . "/lib.php";
     ok("name", $cond, "optional detail on fail");
     eq("name", $expected, $actual);
     ...
     suite_done();   // prints summary, exits 0/1

   Translation stubs: define _() and tr() BEFORE the test file pulls in
   helpers.php, so functions that call tr() (e.g. PopulateAutoCompleteHelpers::
   buildBaseSuggestions) don't crash on the missing gettext bootstrap.
   helpers.php's own tr() is wrapped in `if (!function_exists("tr"))` so our
   stub is what wins. _() returns the input string verbatim so tr() ends up
   returning the input string verbatim too — fine for test assertions, since
   we're testing logic not translation. */

if (!function_exists("_")) {
	function _($string, $options = -1) { return $string; }
}
if (!function_exists("tr")) {
	function tr($string, $options = -1) { return $string; }
}

/* Populate $GLOBALS the way the live request bootstrap does, so any function
   under test that reads $GLOBALS['templates'] / ['caSettings'] / ['sortOrder']
   has something sensible to work against.

   - $GLOBALS['templates'] uses the same source getGlobals() does:
     CA_PATHS['community-templates-info'] (i.e. /tmp/community.applications/
     tempFiles/templates_new.json) read via readJsonFile() so format detection
     (serialize vs JSON) matches production. If the file isn't present (e.g.
     running on a host without CA installed), $GLOBALS['templates'] stays []
     instead of crashing.

   - $GLOBALS['caSettings'] is a permissive default stub. Tests that exercise
     specific settings should override the keys they care about BEFORE calling
     the function under test. Mirrors what getSettings() would have set.

   - $GLOBALS['action'] is the request action label that debug() prepends to
     log lines; lib gives it 'unit_test' so debug spam is identifiable.

   - $GLOBALS['sortOrder'] is read by mySort(); given a default so sort tests
     don't crash before they configure it.

   Call ca_test_load_fixtures() AFTER your test has required paths.php and
   helpers.php — those need to be loaded first so CA_PATHS and readJsonFile()
   exist. */
function ca_test_load_fixtures(): void {
	$GLOBALS['action']    = $GLOBALS['action']    ?? "unit_test";
	$GLOBALS['sortOrder'] = $GLOBALS['sortOrder'] ?? ["sortBy" => "Name", "sortDir" => "Up"];

	$GLOBALS['caSettings'] = $GLOBALS['caSettings'] ?? [
		"dockerSearch"     => "yes",
		"unRaidVersion"    => "7.0.0",
		"favourite"        => "",
		"dynamixTheme"     => "black",
		"NoInstalls"       => false,
		"hideIncompatible" => "true",
		"hideDeprecated"   => "true",
		"dev"              => "no",
	];

	if (!isset($GLOBALS['templates'])) {
		$GLOBALS['templates'] = [];
		if (defined("CA_PATHS") && function_exists("readJsonFile")) {
			$path = CA_PATHS['community-templates-info'] ?? "";
			if ($path !== "" && is_file($path)) {
				$loaded = readJsonFile($path);
				if (is_array($loaded)) $GLOBALS['templates'] = $loaded;
			}
		}
	}
}

class CATest {
	public static int $passed = 0;
	public static int $failed = 0;
	public static array $failures = [];

	public static function ok(string $name, bool $cond, string $detail = "") {
		if ($cond) {
			self::$passed++;
			echo "  ✓ {$name}\n";
		} else {
			self::$failed++;
			self::$failures[] = $detail === "" ? $name : "{$name} — {$detail}";
			echo "  ✗ {$name}" . ($detail === "" ? "" : " — {$detail}") . "\n";
		}
	}

	public static function eq(string $name, $expected, $actual) {
		$cond = ($expected === $actual);
		$detail = $cond ? "" : "expected " . var_export($expected, true) . ", got " . var_export($actual, true);
		self::ok($name, $cond, $detail);
	}

	public static function summary(): int {
		echo "\nPassed: " . self::$passed . "    Failed: " . self::$failed . "\n";
		return self::$failed > 0 ? 1 : 0;
	}
}

function ok(string $n, bool $c, string $d = ""): void   { CATest::ok($n, $c, $d); }
function eq(string $n, $e, $a): void                    { CATest::eq($n, $e, $a); }
function suite_done(): void                              { exit(CATest::summary()); }
