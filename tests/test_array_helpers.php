<?php
/* Tests for array/lookup utilities in include/helpers.php:
   searchArray, duplicateArrayWithoutKeys, filterMatch */

require_once __DIR__ . "/lib.php";
require_once "/usr/local/emhttp/plugins/community.applications/include/helpers.php";

echo "=== searchArray ===\n";

$templates = [
	["Repository" => "linuxserver/plex",        "Name" => "Plex"],
	["Repository" => "linuxserver/sonarr",      "Name" => "Sonarr"],
	["Repository" => "linuxserver/radarr",      "Name" => "Radarr"],
	["Repository" => "ghcr.io/owner/foo:latest","Name" => "Foo"],
];

eq("finds first match",          0, searchArray($templates, "Repository", "linuxserver/plex"));
eq("finds middle match",         1, searchArray($templates, "Repository", "linuxserver/sonarr"));
eq("finds last match",           3, searchArray($templates, "Repository", "ghcr.io/owner/foo:latest"));
ok("missing returns false",      searchArray($templates, "Repository", "no/such") === false);
ok("missing key returns false",  searchArray($templates, "DoesNotExist", "x") === false);
ok("empty array returns false",  searchArray([], "Repository", "x") === false);

eq("startingIndex skips earlier matches",
   2,
   searchArray($templates, "Name", "Radarr", 1));

ok("startingIndex past last match → false",
   searchArray($templates, "Repository", "linuxserver/plex", 1) === false);

/* Search by Name */
eq("searches by Name, case sensitive equality",
   1, searchArray($templates, "Name", "Sonarr"));

echo "\n=== duplicateArrayWithoutKeys ===\n";

/* duplicateArrayWithoutKeys is designed for arrays-of-arrays (e.g. the
   templates list). Each row must be an array; the function:
     - drops the specified keys from each row
     - stamps an "ArrayIndex" key on each row with that row's source index
     - returns the copy (source is also mutated to add ArrayIndex on each row,
       per the comment "stamp index on the original")
*/

$rows = [
	["Name" => "Plex",   "Repository" => "linuxserver/plex",   "secret" => "drop-me"],
	["Name" => "Sonarr", "Repository" => "linuxserver/sonarr", "secret" => "drop-me"],
];

$kept = duplicateArrayWithoutKeys($rows, ["secret"]);
eq("specified key removed from each row + ArrayIndex stamped",
   [
	["Name" => "Plex",   "Repository" => "linuxserver/plex",   "ArrayIndex" => 0],
	["Name" => "Sonarr", "Repository" => "linuxserver/sonarr", "ArrayIndex" => 1],
   ],
   $kept);

// Empty keysToRemove just stamps ArrayIndex (no key removal)
$rows2 = [["a" => 1], ["b" => 2]];
$kept2 = duplicateArrayWithoutKeys($rows2);
eq("no keys to remove → still stamps ArrayIndex",
   [["a" => 1, "ArrayIndex" => 0], ["b" => 2, "ArrayIndex" => 1]],
   $kept2);

// Scalar entries pass through as-is (no ArrayIndex stamp on non-array rows)
$mixed = ["a" => 1, "b" => ["x" => "keep", "secret" => "drop"]];
$kept3 = duplicateArrayWithoutKeys($mixed, ["secret"]);
eq("scalar rows pass through; array rows are filtered + stamped",
   [
	"a" => 1,
	"b" => ["x" => "keep", "ArrayIndex" => "b"],
   ],
   $kept3);

// Removing an absent key from each row is a no-op (ArrayIndex still stamped)
$rows4 = [["x" => 1]];
$kept4 = duplicateArrayWithoutKeys($rows4, ["nope"]);
eq("removing absent key still stamps ArrayIndex",
   [["x" => 1, "ArrayIndex" => 0]],
   $kept4);

echo "\n=== filterMatch ===\n";

/* filterMatch($filter, $searchArray, $exact=true) — used in template search.
   Each entry in $searchArray is a candidate string to match against $filter.
   Behavior:
     - exact=true (default): every space-separated word in $filter must appear
       (substring) in at least one candidate.
     - exact=false: same loose word-by-word substring match. */

ok("single word matches one of the candidates",
   (bool)filterMatch("plex", ["Plex Media Server", "linuxserver/plex"]));

ok("word missing from all candidates",
   !filterMatch("zzzzz", ["Plex Media Server", "linuxserver/plex"]));

ok("multi-word filter where every word appears (in any candidate)",
   (bool)filterMatch("plex media", ["Plex Media Server", "linuxserver/plex"]));

ok("multi-word filter, one word absent → no match",
   !filterMatch("plex spotify", ["Plex Media Server", "linuxserver/plex"]));

ok("empty candidate list → no match",
   !filterMatch("anything", []));

ok("mixed case filter against mixed case candidate (case-insensitive)",
   (bool)filterMatch("PLEX", ["Plex Media Server"]));

suite_done();
