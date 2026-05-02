<?php
/* Tests for the template-normalization helpers in include/helpers.php:
   addMissingVars, removeXMLtags, fixAttributes, fixTemplates.

   makeXML is intentionally NOT covered here — it depends on Array2XML which
   is loaded by the dynamix DockerClient bootstrap (not loaded in tests). */

require_once __DIR__ . "/lib.php";
require_once "/usr/local/emhttp/plugins/community.applications/include/helpers.php";
$_POST = [];
require_once "/usr/local/emhttp/plugins/community.applications/include/paths.php";

ca_test_load_fixtures();
$GLOBALS['caSettings']['unRaidVersion'] = "7.0.0";

echo "=== addMissingVars (PHP 8 compliance: stamp known-key defaults onto a template) ===\n";

$template = [
	"Name"       => "Test App",
	"Repository" => "test/app",
];
$out = addMissingVars($template);

ok("preserves Name",        ($out['Name']       ?? null) === "Test App");
ok("preserves Repository",  ($out['Repository'] ?? null) === "test/app");
/* Implementation stamps a fixed list of expected keys onto the array — pick a
   handful that production reads from the template. (Compatible isn't in the
   list — it's set by versionCheck-time logic, not stamped here.) */
foreach (["Category", "MinVer", "MaxVer", "Beta", "Plugin", "Deprecated", "Blacklist"] as $expected) {
	ok("stamps default for missing '{$expected}'",  array_key_exists($expected, $out));
}

eq("non-array input passes through (string)",
   "string",
   addMissingVars("string"));
eq("non-array input passes through (null)",
   null,
   addMissingVars(null));
eq("non-array input passes through (int)",
   42,
   addMissingVars(42));

$preserved = ["Category" => "Media", "MinVer" => "6.10"];
$out2 = addMissingVars($preserved);
eq("existing values preserved (Category)",  "Media",  $out2['Category']);
eq("existing values preserved (MinVer)",    "6.10",   $out2['MinVer']);

echo "\n=== removeXMLtags (recursive in-place tag stripping) ===\n";

$tpl = ["Description" => "<b>Bold</b> and <i>italic</i> text"];
removeXMLtags($tpl);
ok("strips <b>",  strpos($tpl['Description'], "<b>") === false, $tpl['Description']);
ok("strips <i>",  strpos($tpl['Description'], "<i>") === false, $tpl['Description']);
ok("preserves text content",
   strpos($tpl['Description'], "Bold") !== false &&
   strpos($tpl['Description'], "italic") !== false,
   $tpl['Description']);

/* Nested arrays — recursion */
$tpl = [
	"App" => [
		"Name"        => "<i>Styled</i>",
		"Description" => "<b>Bold</b>",
	],
];
removeXMLtags($tpl);
ok("recurses into nested arrays (Name tags stripped)",
   strpos($tpl['App']['Name'], "<") === false,
   $tpl['App']['Name']);
ok("recurses into nested arrays (Description tags stripped)",
   strpos($tpl['App']['Description'], "<") === false,
   $tpl['App']['Description']);

/* <br> handling quirk: implementation converts <br>→\n and only writes back
   if trim(text) !== trim(strip_tags(text)). When the only "tag" is <br>, after
   conversion both sides are equal, so the write-back is skipped — element
   stays untouched. Document that quirk. <br> only gets normalized when it
   appears alongside *other* tags that would actually trigger the rewrite. */
$tpl = ["Description" => "Line 1<br>Line 2"];
removeXMLtags($tpl);
eq("plain <br> alone left untouched (quirk: only rewrites when other tags present)",
   "Line 1<br>Line 2",
   $tpl['Description']);

$tpl = ["Description" => "<b>Bold</b><br>Plain"];
removeXMLtags($tpl);
ok("<br> normalized when accompanied by other tags",
   strpos($tpl['Description'], "<br>") === false &&
   strpos($tpl['Description'], "Bold")  !== false &&
   strpos($tpl['Description'], "Plain") !== false,
   $tpl['Description']);

/* Plain string without tags — left alone */
$tpl = ["Description" => "no tags here"];
removeXMLtags($tpl);
eq("plain string passes through",  "no tags here",  $tpl['Description']);

/* Null/missing values */
$tpl = ["Description" => null];
removeXMLtags($tpl);
ok("null value handled without warning",  array_key_exists("Description", $tpl));

echo "\n=== fixAttributes (normalize @attributes container) ===\n";

/* Single Config entry with @attributes — wraps into an array of one */
$tpl = ["Config" => ["@attributes" => ["Type" => "Port", "Default" => "8080"]]];
fixAttributes($tpl, "Config");
ok("single-entry Config wrapped into array of one",
   isset($tpl['Config'][0]['@attributes']),
   var_export($tpl, true));

/* Already-wrapped Config (multiple entries) — unchanged shape */
$tpl = ["Config" => [
	["@attributes" => ["Type" => "Port", "Default" => "8080"]],
	["@attributes" => ["Type" => "Variable", "Default" => "X"]],
]];
$snapshot = $tpl;
fixAttributes($tpl, "Config");
ok("already-wrapped Config preserved",  $tpl === $snapshot);

/* Missing attribute → no-op */
$tpl = ["Name" => "Foo"];
$snapshot = $tpl;
fixAttributes($tpl, "Config");
ok("missing attribute → no-op",  $tpl === $snapshot);

/* Non-array attribute → no-op */
$tpl = ["Config" => "not an array"];
$snapshot = $tpl;
fixAttributes($tpl, "Config");
ok("scalar attribute → no-op",  $tpl === $snapshot);

echo "\n=== fixTemplates (defaulting + boolean coercion) ===\n";

/* MinVer defaulting — non-plugin gets 6.0 */
$out = fixTemplates(["Name" => "Foo"]);
eq("non-plugin template defaults MinVer to 6.0",  "6.0",  $out['MinVer']);

/* MinVer defaulting — plugin gets 6.1 */
$out = fixTemplates(["Name" => "FooPlugin", "Plugin" => true]);
eq("plugin template defaults MinVer to 6.1",  "6.1",  $out['MinVer']);

/* MinVer already set — preserved */
$out = fixTemplates(["MinVer" => "7.5"]);
eq("existing MinVer preserved",  "7.5",  $out['MinVer']);

/* Deprecated/Blacklist boolean coercion — appfeed sometimes ships strings */
$out = fixTemplates(["Deprecated" => "true"]);
ok("Deprecated 'true' string → true bool",   $out['Deprecated'] === true);

$out = fixTemplates(["Deprecated" => "false"]);
ok("Deprecated 'false' string → false bool", $out['Deprecated'] === false);

$out = fixTemplates(["Blacklist" => "1"]);
ok("Blacklist '1' string → true bool",       $out['Blacklist'] === true);

$out = fixTemplates(["Blacklist" => 0]);
ok("Blacklist int 0 → false bool",           $out['Blacklist'] === false);

$out = fixTemplates([]);
ok("missing Deprecated → false bool",        $out['Deprecated'] === false);
ok("missing Blacklist → false bool",         $out['Blacklist'] === false);

/* DeprecatedMaxVer flips Deprecated when current Unraid version exceeds it */
$out = fixTemplates(["DeprecatedMaxVer" => "6.12"]);  // current 7.0.0 > 6.12
ok("DeprecatedMaxVer past current OS → marks Deprecated",   $out['Deprecated'] === true);

$out = fixTemplates(["DeprecatedMaxVer" => "8.0"]);   // current 7.0.0 < 8.0
ok("DeprecatedMaxVer ahead of current OS → no force-deprecate",
   $out['Deprecated'] === false);

/* Config Description scrubbing — generic placeholders get cleared */
$out = fixTemplates([
	"Config" => ["@attributes" => [
		"Type"        => "Path",
		"Description" => "Container Path: /data",
	]],
]);
eq("'Container Path:' description scrubbed",
   "",
   $out['Config']['@attributes']['Description']);

$out = fixTemplates([
	"Config" => ["@attributes" => [
		"Type"        => "Variable",
		"Description" => "User-provided meaningful text",
	]],
]);
eq("user-supplied description preserved",
   "User-provided meaningful text",
   $out['Config']['@attributes']['Description']);

suite_done();
