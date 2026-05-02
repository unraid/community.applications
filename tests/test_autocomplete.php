<?php
/* Tests for the PopulateAutoCompleteHelpers class — search-suggestion builder.
   Recently fixed: stripPrefix used to use str_replace (stripping every
   occurrence of the prefix), now uses substr only when the prefix is at
   position 0. addNameSuggestion now stores stripped aliases as separate
   keys instead of overwriting one entry. */

require_once __DIR__ . "/lib.php";
require_once "/usr/local/emhttp/plugins/community.applications/include/helpers.php";  // for startsWith()
require_once "/usr/local/emhttp/plugins/community.applications/include/populate_autocomplete_helpers.php";

echo "=== PopulateAutoCompleteHelpers::stripPrefix (private static) ===\n";

$ref = new ReflectionMethod("PopulateAutoCompleteHelpers", "stripPrefix");
$ref->setAccessible(true);
$strip = function (string $value, string $prefix) use ($ref) {
	return $ref->invoke(null, $value, $prefix);
};

// Prefix at start → stripped
eq("strips leading prefix",         "plex",          $strip("docker-plex", "docker-"));
eq("strips multi-segment prefix",   "name",          $strip("library/name", "library/"));

// Prefix not at start → no change (older bug: would str_replace and remove from middle)
eq("prefix in middle untouched",    "abc-docker-xyz", $strip("abc-docker-xyz", "docker-"));
eq("multiple occurrences only first stripped",
   "docker-plex-docker", $strip("docker-docker-plex-docker", "docker-"));

// Edge cases
eq("empty value → empty",           "",              $strip("", "docker-"));
eq("empty prefix → unchanged",      "anything",      $strip("anything", ""));
eq("prefix exactly equals value → empty result",
   "",              $strip("docker-", "docker-"));
eq("value shorter than prefix → unchanged",
   "abc",           $strip("abc", "docker-very-long-prefix-"));

echo "\n=== PopulateAutoCompleteHelpers::buildBaseSuggestions ===\n";

/* buildBaseSuggestions reads CA_PATHS['categoryList'] via readJsonFile and
   feeds the results through tr(). lib.php stubs tr() to return its input,
   so this exercises the array-shaping path without needing a real
   translation bootstrap. */

$_POST = [];
require_once "/usr/local/emhttp/plugins/community.applications/include/paths.php";

$out = PopulateAutoCompleteHelpers::buildBaseSuggestions();
ok("buildBaseSuggestions returns array",       is_array($out));
ok("returns at least one category",            count($out) > 0);
ok("entries are strings",                       array_reduce($out, function ($carry, $v) { return $carry && is_string($v); }, true));
ok("contains some recognizable categories from the live categoryList file",
   /* Loose check: live CA_PATHS['categoryList'] should expose at least a few of these */
   (bool)array_intersect($out, ["AI", "Backup", "Cloud", "MediaServer", "Network", "Tools", "Other"]),
   var_export($out, true));

echo "\n=== addRepositorySuggestion (private static) — RepoName key handling ===\n";

$ref2 = new ReflectionMethod("PopulateAutoCompleteHelpers", "addRepositorySuggestion");
$ref2->setAccessible(true);
$addRepo = function ($template, $autoComplete) use ($ref2) {
	return $ref2->invoke(null, $template, $autoComplete);
};

// Template with Repo set, not a language pack → suggestion added
$result = $addRepo(["Repo" => "linuxserver"], []);
ok("Repo present, non-language → suggestion added",
   !empty($result) && in_array("linuxserver", $result, true),
   var_export($result, true));

// Language pack templates are excluded
$result = $addRepo(["Repo" => "linuxserver", "Language" => "fr_FR", "LanguageLocal" => "Français"], []);
eq("language template → no suggestion",
   [],
   $result);

// Missing Repo → no suggestion
$result = $addRepo(["Name" => "Plex"], []);
eq("missing Repo → no suggestion",
   [],
   $result);

suite_done();
