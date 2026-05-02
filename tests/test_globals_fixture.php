<?php
/* Tests for the ca_test_load_fixtures() helper itself, plus a sample of
   functions that depend on $GLOBALS — getAuthor(), mySort(), repositorySort(),
   favouriteSort() — to confirm the fixture stub gives them a working
   environment without the live request bootstrap. */

require_once __DIR__ . "/lib.php";
require_once "/usr/local/emhttp/plugins/community.applications/include/helpers.php";
$_POST = [];
require_once "/usr/local/emhttp/plugins/community.applications/include/paths.php";

ca_test_load_fixtures();

echo "=== Fixture state ===\n";

ok("\$GLOBALS['templates'] is an array",       is_array($GLOBALS['templates']));
ok("\$GLOBALS['caSettings'] is set",            is_array($GLOBALS['caSettings'] ?? null));
ok("caSettings has expected default keys",
   isset($GLOBALS['caSettings']['dockerSearch']) &&
   isset($GLOBALS['caSettings']['unRaidVersion']) &&
   isset($GLOBALS['caSettings']['dynamixTheme']));
ok("\$GLOBALS['sortOrder'] has sortBy + sortDir",
   isset($GLOBALS['sortOrder']['sortBy']) &&
   isset($GLOBALS['sortOrder']['sortDir']));
ok("\$GLOBALS['action'] set for debug() output",   ($GLOBALS['action'] ?? "") !== "");

/* Live-data path: if /tmp/community.applications/tempFiles/templates_new.json
   exists on this host, we expect $GLOBALS['templates'] to be non-empty. */
$live = CA_PATHS['community-templates-info'] ?? "";
if ($live !== "" && is_file($live)) {
	ok("live templates_new.json found → \$GLOBALS['templates'] non-empty",
	   count($GLOBALS['templates']) > 0,
	   "live={$live}, count=" . count($GLOBALS['templates']));
	$first = reset($GLOBALS['templates']);
	ok("first live template is an array",            is_array($first));
} else {
	ok("no live templates_new.json (test host without CA cache) — fixture left templates empty",
	   $GLOBALS['templates'] === []);
}

echo "\n=== getAuthor — pure on input, exercises various Repository shapes ===\n";

eq("PluginURL present → uses PluginAuthor",
   "alex",
   getAuthor(["PluginURL" => "https://x/foo.plg", "PluginAuthor" => "alex"]));

eq("Author field set → returns stripped Author",
   "limetech",
   getAuthor(["Author" => "limetech", "Repository" => "lscr.io/whatever"]));

eq("docker repo: linuxserver/sonarr → 'linuxserver'",
   "linuxserver",
   getAuthor(["Repository" => "linuxserver/sonarr"]));

eq("docker repo with tag stripped from author segment",
   "linuxserver",
   getAuthor(["Repository" => "linuxserver/sonarr:latest"]));

eq("ghcr.io prefix stripped before extracting author",
   "owner",
   getAuthor(["Repository" => "ghcr.io/owner/repo:1.0"]));

eq("lscr.io prefix stripped",
   "linuxserver",
   getAuthor(["Repository" => "lscr.io/linuxserver/sonarr"]));

eq("library/ prefix stripped",
   /* "library/foo" → strip "library/" → "foo" → explode("/") = ["foo"] →
      count<2 so push "" → ["foo",""] → repoEntry[count-2] = "foo" */
   "foo",
   getAuthor(["Repository" => "library/foo"]));

echo "\n=== mySort (uses \$GLOBALS['sortOrder']) ===\n";

$apps = [
	["SortName" => "Plex",   "downloads" => 1000, "FirstSeen" => 1700000000],
	["SortName" => "Sonarr", "downloads" => 500,  "FirstSeen" => 1600000000],
	["SortName" => "Radarr", "downloads" => 750,  "FirstSeen" => 1650000000],
];

// Sort by Name ascending
$GLOBALS['sortOrder'] = ["sortBy" => "Name", "sortDir" => "Up"];
$copy = $apps;
usort($copy, "mySort");
eq("sort by Name asc → Plex, Radarr, Sonarr",
   ["Plex", "Radarr", "Sonarr"],
   array_column($copy, "SortName"));

// Sort by Name descending
$GLOBALS['sortOrder'] = ["sortBy" => "Name", "sortDir" => "Down"];
$copy = $apps;
usort($copy, "mySort");
eq("sort by Name desc → Sonarr, Radarr, Plex",
   ["Sonarr", "Radarr", "Plex"],
   array_column($copy, "SortName"));

// Sort by downloads descending (numeric)
$GLOBALS['sortOrder'] = ["sortBy" => "downloads", "sortDir" => "Down"];
$copy = $apps;
usort($copy, "mySort");
eq("sort by downloads desc → 1000, 750, 500",
   [1000, 750, 500],
   array_column($copy, "downloads"));

// Sort by FirstSeen ascending
$GLOBALS['sortOrder'] = ["sortBy" => "FirstSeen", "sortDir" => "Up"];
$copy = $apps;
usort($copy, "mySort");
eq("sort by FirstSeen asc → oldest first",
   [1600000000, 1650000000, 1700000000],
   array_column($copy, "FirstSeen"));

echo "\n=== favouriteSort (uses \$GLOBALS['caSettings']['favourite']) ===\n";

$repos = [
	["Repo" => "linuxserver"],
	["Repo" => "ich777"],
	["Repo" => "binhex"],
];

$GLOBALS['caSettings']['favourite'] = "ich777";
$copy = $repos;
usort($copy, "favouriteSort");
eq("favourite repo bubbles to the top",
   "ich777",
   $copy[0]["Repo"]);

$GLOBALS['caSettings']['favourite'] = "no-such-repo";
$copy = $repos;
usort($copy, "favouriteSort");
eq("no matching favourite → array order preserved (stable enough for first item)",
   "linuxserver",
   $copy[0]["Repo"]);

echo "\n=== repositorySort (uses \$GLOBALS['caSettings']['favourite']) ===\n";

$repoCards = [
	["RepoName" => "linuxserver"],
	["RepoName" => "ich777"],
	["RepoName" => "binhex"],
];

$GLOBALS['caSettings']['favourite'] = "binhex";
$copy = $repoCards;
usort($copy, "repositorySort");
eq("favourite repo card surfaces first",
   "binhex",
   $copy[0]["RepoName"]);

suite_done();
