<?php
/* Tests for the per-tab cache path suffixing in include/paths.php.
   $caTabSuffix is derived from $_POST['tabId'] when present and matching the
   strict regex; suffixes a small whitelist of cache-file paths so two tabs
   in the same browser don't stomp each other's filtered/displayed/search
   caches.

   We can't redefine the CA_PATHS constant, so this test exercises the
   behavior by re-running the regex check directly. */

require_once __DIR__ . "/lib.php";

echo "=== \$_POST['tabId'] regex (^[A-Za-z0-9_-]{8,64}$) ===\n";

$pattern = '/^[A-Za-z0-9_-]{8,64}$/';

// Valid tab IDs
$valid = [
	"abcd1234",                        // 8 chars (min)
	"abcdefghABCDEFGH01234567",        // mixed case + digits
	"tab_with_underscores_only_aaa",
	"tab-with-hyphens-only-aaa",
	str_repeat("a", 64),               // 64 chars (max)
];
foreach ($valid as $v) {
	ok("accepts: '" . substr($v, 0, 16) . (strlen($v) > 16 ? "..." : "") . "' (" . strlen($v) . " chars)",
	   (bool)preg_match($pattern, $v));
}

// Invalid tab IDs
$invalid = [
	""                              => "empty string",
	"short"                         => "7 chars (under min)",
	str_repeat("a", 65)             => "65 chars (over max)",
	"tab id with spaces"            => "contains spaces",
	"tab.with.dots"                 => "contains dots",
	"tab/with/slashes"              => "contains slashes (path traversal attempt)",
	"../../etc/passwd"              => "directory traversal",
	"tab\nnewline"                  => "contains newline",
	"tab\$with\$special"            => "contains shell metacharacters",
	"<script>alert(1)</script>"     => "HTML injection attempt",
];
foreach ($invalid as $v => $note) {
	ok("rejects: {$note}",  preg_match($pattern, $v) === 0);
}

echo "\n=== CA_PATHS structure ===\n";

/* require_once paths.php inside this scope after the request env is faked,
   so we can read CA_PATHS without triggering the live cache-suffix logic.
   We don't set $_POST['tabId'], so the suffix must be empty. */
$_POST = [];
require_once "/usr/local/emhttp/plugins/community.applications/include/paths.php";

ok("CA_PATHS is defined as a constant",       defined("CA_PATHS"));
ok("CA_PATHS is an array",                    is_array(CA_PATHS));

// Per-tab suffixed paths — without a tabId, no suffix
$untabbed_keys = [
	"community-templates-displayed",
	"community-templates-allSearchResults",
	"community-templates-catSearchResults",
	"startupDisplayed",
	"repositoriesDisplayed",
	"sortOrder",
	"dockerSearchResults",
	"dockerSearchActive",
];
foreach ($untabbed_keys as $k) {
	ok("CA_PATHS['{$k}'] exists and is a string",
	   isset(CA_PATHS[$k]) && is_string(CA_PATHS[$k]));
	$path = CA_PATHS[$k];
	/* When tabId is unset, the per-tab paths shouldn't carry a literal suffix
	   like ".tab123" — they should look like the bare path. */
	ok("CA_PATHS['{$k}'] has no per-tab suffix without \$_POST['tabId']",
	   !preg_match('/\.[A-Za-z0-9_-]{8,64}\.json$|\.[A-Za-z0-9_-]{8,64}$/', $path),
	   $path);
}

// Other key paths exist
$expected_keys = [
	"tempFiles",
	"flashDrive",
	"templates-community",
	"application-feed",
	"application-feed-last-updated",
	"unRaidVersion",
	"unRaidVars",
	"docker_cfg",
	"pluginPending",
	"dockerManTemplates",
];
foreach ($expected_keys as $k) {
	ok("CA_PATHS['{$k}'] is set",  isset(CA_PATHS[$k]) && CA_PATHS[$k] !== "");
}

// Production safety: localONLY and humanReadable must NEVER be true in a release
ok("CA_PATHS['localONLY'] is FALSE (must never ship as true)",
   isset(CA_PATHS['localONLY']) && CA_PATHS['localONLY'] === false);
ok("CA_PATHS['humanReadable'] is FALSE (must never ship as true)",
   isset(CA_PATHS['humanReadable']) && CA_PATHS['humanReadable'] === false);

suite_done();
