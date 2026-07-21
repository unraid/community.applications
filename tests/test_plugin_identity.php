<?php
/* Regression tests for CA plugin URL identity matching. */

require_once dirname(__DIR__) . "/source/community.applications/usr/local/emhttp/plugins/community.applications/include/plugin_identity.php";

$failures = 0;

function expectPluginIdentity(string $label, bool $expected, string $installedUrl, string $templateUrl): void {
	global $failures;
	$actual = caPluginUrlMatchesTemplate($installedUrl, $templateUrl);
	if ($actual !== $expected) {
		$failures++;
		fwrite(STDERR, "FAIL: {$label}: expected " . ($expected ? "true" : "false") . ", got " . ($actual ? "true" : "false") . "\n");
		return;
	}
	echo "PASS: {$label}\n";
}

$canonicalCa = "https://raw.githubusercontent.com/unraid/community.applications/master/plugins/community.applications.plg";
$canonicalOther = "https://raw.githubusercontent.com/example/other/master/other.plg";
$preview = "https://raw.githubusercontent.com/unraid/community.applications/pr-previews/pr/127/community.applications.plg";

expectPluginIdentity("canonical URL", true, $canonicalCa, $canonicalCa);
expectPluginIdentity("normalization", true, "  " . strtoupper($canonicalCa) . "  ", $canonicalCa);
expectPluginIdentity("official CA PR preview", true, $preview, $canonicalCa);
expectPluginIdentity("preview is not another plugin", false, $preview, $canonicalOther);
expectPluginIdentity("fork preview rejected", false, str_replace("/unraid/", "/someone/", $preview), $canonicalCa);
expectPluginIdentity("non-numeric PR rejected", false, str_replace("/127/", "/abc/", $preview), $canonicalCa);
expectPluginIdentity("wrong preview branch rejected", false, str_replace("/pr-previews/", "/beta/", $preview), $canonicalCa);
expectPluginIdentity("wrong manifest rejected", false, str_replace("community.applications.plg", "other.plg", $preview), $canonicalCa);

if ($failures > 0) {
	fwrite(STDERR, "{$failures} plugin identity test(s) failed\n");
	exit(1);
}

echo "All plugin identity tests passed\n";
