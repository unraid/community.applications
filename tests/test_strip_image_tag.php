<?php
/* Tests for PreviousAppsHelpers::stripImageTag()
   The "colon after the last slash" rule that distinguishes a docker tag
   (which we want to strip) from a registry port (which we want to keep). */

require_once __DIR__ . "/lib.php";
require_once "/usr/local/emhttp/plugins/community.applications/include/previous_apps_helpers.php";

/* The method is private static — reach in via Reflection. */
$ref = new ReflectionMethod("PreviousAppsHelpers", "stripImageTag");
$ref->setAccessible(true);
$strip = function (string $repo) use ($ref) { return $ref->invoke(null, $repo); };

echo "=== stripImageTag ===\n";

$cases = [
	// Plain images
	["library/foo",                          "library/foo",          "no tag, plain"],
	["library/foo:latest",                   "library/foo",          "tagged"],
	["library/foo:1.2.3",                    "library/foo",          "version tag"],
	["library/foo:sha-abc123",               "library/foo",          "sha tag"],

	// Single-segment images
	["foo",                                  "foo",                  "single segment, no tag"],
	["foo:latest",                           "foo",                  "single segment, tagged"],

	// Registry with port — colon BEFORE the last slash, must be preserved
	["registry:5000/repo",                   "registry:5000/repo",   "registry port, no tag"],
	["registry:5000/repo:latest",            "registry:5000/repo",   "registry port + tag"],
	["registry:5000/ns/app:1.0",             "registry:5000/ns/app", "registry port + nested ns + tag"],
	["registry.example.com:443/repo:v2",     "registry.example.com:443/repo", "fqdn:port + tag"],

	// Registry without port — no tag
	["ghcr.io/owner/repo",                   "ghcr.io/owner/repo",   "ghcr no tag"],
	["ghcr.io/owner/repo:latest",            "ghcr.io/owner/repo",   "ghcr tagged"],

	// Edge cases
	["",                                     "",                     "empty string"],
	[":latest",                              "",                     "leading colon (no name)"],
];

foreach ($cases as [$in, $expected, $note]) {
	eq("strip ({$note}): {$in}", $expected, $strip($in));
}

suite_done();
