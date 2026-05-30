<?
########################################
#                                      #
# Community Applications               #
# Copyright 2020-2026, Lime Technology #
# Copyright 2015-2026, Andrew Zawadzki #
#                                      #
# Licensed under GPL-2.0-or-later      #
# SPDX-License-Identifier:             #
#   GPL-2.0-or-later                   #
#                                      #
########################################

/**
 * HTTP entry point for Community Applications AJAX/actions from the Apps UI.
 *
 * Loads Unraid/Docker integration, routes POST action to handlers (content,
 * installs, pinning, moderation, etc.), and returns HTML/JSON for the frontend.
 */

ini_set('memory_limit','256M');  // REQUIRED LINE
ini_set('display_errors', 'Off'); // All display errors wind up breaking CA
if (false) {
	// IDE-only hint for static analyzers; never executed at runtime.
	require_once __DIR__ . "/_ide_stubs.php";
}


### Translations section has to be first so that nothing else winds up caching the file(s)

$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: "/usr/local/emhttp";

$_SERVER['REQUEST_URI'] = "docker/apps";

require_once "$docroot/plugins/dynamix/include/Wrappers.php";
require_once "$docroot/plugins/dynamix/include/Translations.php";
require_once "$docroot/plugins/dynamix/include/publish.php";
require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php"; # must be first include due to paths defined
require_once "$docroot/plugins/community.applications/include/paths.php";
require_once "$docroot/plugins/community.applications/include/helpers.php";
/* skins/Narrow/skin.php (+ skin_helpers.php it pulls in) is ~3300 lines of
   rendering code that only 5 dispatch cases actually need (get_content,
   display_content, getPopupDescription, getRepoDescription, search_dockerhub).
   Lazy-load it inside those cases via caRequireSkin() below instead of paying
   the parse cost on every POST. */
require_once "$docroot/plugins/dynamix.plugin.manager/include/PluginHelpers.php";

/**
 * Lazy-load the Narrow skin (skin.php + skin_helpers.php). Safe to call from
 * any case branch — PHP's require_once dedupes by absolute path. Used by the
 * cases that emit rendered HTML (`get_content`, `display_content`,
 * `getPopupDescription`, `getRepoDescription`, `search_dockerhub`).
 *
 * Pulls in Markdown.php alongside — skin / skin_helpers call markdown() in
 * several spots (overview rendering, repo bio, cards-loop Requires, etc.),
 * so every caRequireSkin consumer also needs the renderer.
 */
function caRequireSkin(): void {
	global $docroot;
	require_once "$docroot/plugins/community.applications/skins/Narrow/skin.php";
	require_once "$docroot/webGui/include/Markdown.php";
}

################################################################################
# Set up any default settings (when not explicitely set by the settings module #
################################################################################

getGlobals();

$DockerClient = new DockerClient();
$DockerTemplates = new DockerTemplates();

@mkdir(CA_PATHS['tempFiles'],0777,true);

if ( !is_dir(CA_PATHS['templates-community']) ) {
	@mkdir(CA_PATHS['templates-community'],0777,true);
	@unlink(CA_PATHS['community-templates-info']);
}

debug("POST CALLED ({$_POST['action']})\n".print_r($_POST,true));

$sortOrder = readJsonFile(CA_PATHS['sortOrder']);
if ( ! $sortOrder ) {
	$sortOrder['sortBy'] = "Name";
	$sortOrder['sortDir'] = "Up";
	writeJsonFile(CA_PATHS['sortOrder'],$sortOrder);
}

############################################
##                                        ##
## BEGIN MAIN ROUTINES CALLED BY THE HTML ##
##                                        ##
############################################
$GLOBALS['action'] = $_POST['action'] ?? "Unknown";

/* Cross-tab feed-update detection. JS sets caFeedCheck=true on every
   request from a tab that has previously called `registerTab` (which
   creates a per-tab marker file at CA_PATHS['registeredTabs']/{tabId}).
   If the marker is now missing, another tab has triggered a feed
   download (which wipes the entire tempFiles directory, including the
   registered-tabs subfolder) and this tab is operating on stale state —
   short-circuit the action and tell JS to throw up the reload banner.
   Cheap is_file check on every request, no nchan, no buffered-message
   race. The tab that initiated the download re-registers itself inside
   DownloadApplicationFeed before returning, so the driving tab stays
   in sync. */
if ( ! empty($_POST['caFeedCheck']) ) {
	$_caTabIdForCheck = (string)($_POST['tabId'] ?? '');
	if ($_caTabIdForCheck === '' || ! file_exists(CA_PATHS['registeredTabs'] . '/' . basename($_caTabIdForCheck))) {
		postReturn(['feedUpdated' => true]);
		return;
	}
}

switch ($_POST['action']) {
	case 'registerTab':
		/* Bootstrap-time tab registration. JS calls this once after the
		   tab finishes its initial load and before arming the feedCheck
		   flag. Creates the marker file the pre-switch guard checks.
		   Reports back whether the marker is actually on disk so the
		   client only arms its caFeedCheck flag against a real
		   registration — ensureTabRegistered rejects malformed tabIds
		   and mkdir/touch failures otherwise. */
		postReturn(['status' => ensureTabRegistered() ? 'ok' : 'failed']);
		break;
	case 'get_content':
		caRequireSkin();
		get_content();
		break;
	case 'hydrateFullFeed':
		hydrateFullFeed();
		break;
	case 'force_update':
		force_update();
		break;
	case 'force_update_skip':
		force_update_skip();
		break;
	case 'display_content':
		caRequireSkin();
		display_content();
		break;
	case 'dismiss_warning':
		dismiss_warning();
		break;
	case 'previous_apps':
		previous_apps();
		break;
	case 'installedAndPreviousCounts':
		installedAndPreviousCounts();
		break;
	case 'remove_application':
		remove_application();
		break;
	case 'updatePLGstatus':
		updatePLGstatus();
		break;
	case 'uninstall_docker':
		uninstall_docker();
		break;
	case "pinApp":
		pinApp();
		break;
	case "areAppsPinned":
		areAppsPinned();
		break;
	case "pinnedApps":
		pinnedApps();
		break;
	case 'displayTags':
		displayTags();
		break;
	case 'statistics':
		statistics();
		break;
	case 'caCompareFeedShas':
		caCompareFeedShas();
		break;
	case 'showModeration':
		showModeration();
		break;
	case 'saveIgnoredRepos':
		saveIgnoredRepos();
		break;
	case 'populateAutoComplete':
		populateAutoComplete();
		break;
	case 'caChangeLog':
		caChangeLog();
		break;
	case 'get_categories':
		get_categories();
		break;
	case 'getPopupDescription':
		caRequireSkin();
		getPopupDescription();
		break;
	case 'getRepoDescription':
		caRequireSkin();
		getRepoDescription();
		break;
	case 'getTemplateDiff':
		/* Dev-mode Diff button only — lazy-load so day-to-day requests don't
		   pay the parse cost for the ~320-line diff backend nobody else hits. */
		require_once __DIR__ . "/diff.php";
		getTemplateDiff();
		break;
	case 'getDevRawURL':
		/* Dev-mode Plugin/Template buttons: fetch the URL server-side and feed
		   it into the diff modal overlay (also handles LIBXML_NOENT decoding
		   for the Plugin button's two-column raw / decoded view). */
		getDevRawURL();
		break;
	case 'getRepoDuplicates':
		/* Dev + admin only: render the duplicate-Name templates for a single
		   repo into the main cards area. Mirrors get_content's response so the
		   client can hand it straight to updateDisplay(). */
		caRequireSkin();
		getRepoDuplicates();
		break;
	case 'startLiveStatsPublisher':
		/* Idempotently spawn the long-running scripts/caLiveStats.php
		   publisher. The sidebar's live-stats panel posts this on open;
		   if the publisher's already running (any tab on any browser
		   keeping it warm), this is a no-op. publish() in the script
		   self-terminates ~10s after the last subscriber drops. */
		startLiveStatsPublisher();
		break;
	case 'caFetchSidebarSource':
		/* Sidebar README/Changes loader: fetch over PHP (avoids client-side
		   CORS failures from repo hosts that don't set Access-Control-Allow-
		   Origin) and cache to templates-community keyed by RepoName so
		   repeat opens are a disk hit. */
		caFetchSidebarSource();
		break;
	case 'createXML':
		createXML();
		break;
	case 'switchLanguage':
		switchLanguage();
		break;
	case 'remove_multiApplications':
		remove_multiApplications();
		break;
	case 'getCategoriesPresent':
		getCategoriesPresent();
		break;
	case 'toggleFavourite':
		toggleFavourite();
		break;
	case 'getFavourite':
		getFavourite();
		break;
	case 'changeSortOrder':
		changeSortOrder();
		break;
	case 'getSortOrder':
		getSortOrder();
		break;
	case 'defaultSortOrder':
		defaultSortOrder();
		break;
	case 'javascriptError':
		javascriptError();
		break;
	case 'downloadDebugging':
		downloadDebugging();
		break;
	case 'onStartupScreen':
		onStartupScreen();
		break;
	case 'convert_docker':
		convert_docker();
		break;
	case 'search_dockerhub':
		caRequireSkin();
		search_dockerhub();
		break;
	case 'getPortsInUse':
		postReturn(["portsInUse"=>getPortsInUse()]);
		break;
	case 'getLastUpdate':
		postReturn(['lastUpdate'=>getLastUpdate(getPost("ID","Unknown"))]);
		break;
	case 'enableActionCentre':
		enableActionCentre();
		break;
	case 'var_dump':
		break;
	case 'checkRequirements':
		checkRequirements();
		break;
	case 'saveMultiPluginPending':
		saveMultiPluginPending();
		break;
	case 'downloadStatistics':
		downloadStatistics();
		break;
	case 'checkPluginInProgress':
		checkPluginInProgress();
		break;
	case 'networkAlreadyCreated':
		networkAlreadyCreated();
		break;
	case 'clearStartUpDisplayed':
		clearStartUpDisplayed();
		break;
	###############################################
	# Return an error if the action doesn't exist #
	###############################################
	default:
		postReturn(["error"=>"Unknown post action ".htmlspecialchars($_POST['action'])]);
		break;
}
/**
 * Download and merge the application feed into local template caches.
 *
 * Initial-load is slim only — tries `applicationFeed-small.json` on the
 * primary server, then on the GitHub backup. If both fail the user sees
 * the standard download-failed response; we never fall back to the full
 * `applicationFeed.json` here. The full feed is fetched asynchronously by
 * the `hydrateFullFeed` action (triggered from JS post-`force_update`),
 * which fills in the on-disk full cache so install-time port-conflict
 * detection and createXML have Config available.
 *
 * Returns 'slim' on success, false on failure.
 *
 * DownloadApplicationFeed() must run before community template merge so private repos apply correctly.
 *
 * @return false|string
 */
function DownloadApplicationFeed() {
	exec("rm -rf ".escapeshellarg(CA_PATHS['tempFiles']));
	@mkdir(CA_PATHS['tempFiles'],0777,true);
	@unlink(CA_PATHS['downloadLocks']);
	@mkdir(CA_PATHS['templates-community'],0777,true);

	$downloadURL = randomFile();
	/* ca_gettingTemplates publish (the "other tab updated the feed" trigger)
	   has moved into writeGlobals via signalFeedReady() — fires when the
	   small cache lands. The background full-feed hydrate writes only the
	   full cache and stays silent so it doesn't double-fire. */

	/* Primary slim. 10-minute cURL timeout / shared=false: per-request
	   tempfile, so we don't want download_url to serialize unrelated calls. */
	$smallFeed = download_json(CA_PATHS['application-feed-small'], $downloadURL, 600, false);
	$currentFeed = "Primary Server (slim)";
	if ( ! is_array($smallFeed['applist'] ?? null) || empty($smallFeed['applist']) ) {
		/* Primary slim unavailable — try the GitHub backup of the slim feed.
		   No fallback to the full feed beyond this; if both slim tiers fail
		   the user gets the standard download-failed response and retries. */
		$smallFeed = download_json(CA_PATHS['pluginProxy'].CA_PATHS['application-feed-smallBackup'], $downloadURL, 600, false);
		$currentFeed = "Backup Server (slim)";
	}
	if ( ! is_array($smallFeed['applist'] ?? null) || empty($smallFeed['applist']) ) {
		/* Don't unlink $downloadURL on failure — leave the raw bytes
		   (preserved by download_json under $shared=false) on disk so
		   buildDownloadFailureResponse can read them for the
		   partial-download hint + json_last_error_msg detail. */
		@unlink(CA_PATHS['currentServer']);
		ca_file_put_contents(CA_PATHS['appFeedDownloadError'],$downloadURL);
		return false;
	}
	@unlink($downloadURL);

	return processApplicationFeed($smallFeed, $currentFeed) ? 'slim' : false;
}

/**
 * Apply CA's per-template transformations to a freshly-downloaded feed and
 * persist the auxiliary files (categoryList / repositoryList / extraBlacklist
 * / extraDeprecated / lastUpdated-old / invalidXML / currentServer). Sets
 * `$GLOBALS['templates']` to the processed array so the caller can run
 * moderation and writeGlobals afterwards.
 *
 * Used by both `DownloadApplicationFeed()` (initial load) and
 * `hydrateFullFeedWork()` (background full-feed pull after a slim-feed success).
 *
 * @param array $ApplicationFeed Decoded JSON: applist, categories, repositories, blacklisted, deprecated, last_updated_timestamp
 * @param string $currentFeed Label written to currentServer (Primary / Backup / Local / slim)
 * @return bool
 */
function processApplicationFeed(array $ApplicationFeed, string $currentFeed): bool {
	ca_file_put_contents(CA_PATHS['currentServer'],$currentFeed);
	$i = 0;
	$lastUpdated['last_updated_timestamp'] = $ApplicationFeed['last_updated_timestamp'] ?? null;
	writeJsonFile(CA_PATHS['lastUpdated-old'],$lastUpdated);

	/* User-curated ignore list (set in the moderation Repository view) — any
	   template whose Repo/RepoName matches gets stamped hideFromCA so the
	   block right below drops it from the feed. */
	$ignoredRepos = readJsonFile(CA_PATHS['ignoredRepos'], []);
	$ignoredRepos = is_array($ignoredRepos) ? array_flip(array_filter($ignoredRepos, "is_string")) : [];

	$invalidXML = [];
	foreach ($ApplicationFeed['applist'] as $o) {
		if ( (! isset($o['Repository']) ) && (! isset($o['Plugin']) ) && (!isset($o['Language']) )){
			$invalidXML[] = $o;
			continue;
		}
		$repoName = $o['Repo'] ?? null;
		if ( $repoName && isset($ignoredRepos[$repoName]) ) {
			$o['hideFromCA'] = true;
		}
		if ( $o['hideFromCA'] ?? false )
			continue;

		$o['CategoryList'] = $o['CategoryList'] ?? [];
		if ( $o['CategoryList'] ) {
			$o['Category'] = $o['Category'] ?? "";
			foreach ($o['CategoryList'] as $cat) {
				$cat = str_replace("-",":",$cat);
				if ( ! strpos($cat,":") )
					$cat .= ":";
				$o['Category'] .= "$cat ";
			}
		}

		$o['Category'] = $o['Category'] ?? "";
		$o['Category'] = trim($o['Category']);
		if ( ! $o['Category'] )
			$o['Category'] = "Other:";


		if ( $o['RecommendedRaw'] ?? null) {
			$o['RecommendedDate'] = strtotime($o['RecommendedRaw']);
			$o['Category'] .= " spotlight:";
		}

		if ( $o['Language'] ?? null) {
			$o['Category'] = "Language:";
			$o['Compatible'] = true;
			$o['Repository'] = "library/";
		}

		# Move the appropriate stuff over into a CA data file
		$o['ID']            = $i;
		$o['Displayable']   = true;
		$o['Author']        = getAuthor($o);
		$o['DockerHubName'] = strtolower($o['Name']);
		$o['RepoName']      = $o['Repo'];
		$o['SortAuthor']    = $o['Author'];
		$o['SortName']      = str_replace("-"," ",$o['Name']);
		$o['SortName']      = preg_replace('/\s+/',' ',$o['SortName']);
		$o['random']        = rand();

		if ( $o['CAComment'] ?? null) {
				$tmpComment = explode("&zwj;",$o['CAComment']);  // non printable delimiter character
				$o['CAComment'] = "";
				foreach ($tmpComment as $comment) {
					if ( $comment )
						$o['CAComment'] .= tr($comment)."  ";
				}
		}
		if ( $o['RequiresFile'] ?? null) $o['RequiresFile'] = trim($o['RequiresFile']);
		if ( $o['Requires'] ?? null) 		$o['Requires'] = trim($o['Requires']);

		$des = $o['OriginalOverview'] ?? ($o['Overview']??null);
		$des = ($o['Language']??null) ? ($o['Description']??null) : $des;
		if ( ! $des && ($o['Description']??null) )
			$des = $o['Description'];
		if ( ! ($o['Language']??null) ) {
			$des = (string)($des ?? "");
			$des = str_replace(["[","]"],["<",">"],$des);
			$des = str_replace("\n","  ",$des);
			$des = html_entity_decode($des);
		}

		if ( $o['PluginURL'] ?? null ) {
			/* Some legacy plugin feed entries don't carry PluginAuthor; null-
			   coalesce to "" so the assignment doesn't emit an
			   undefined-index warning on every feed-process pass. */
			$o['Author']        = (string)($o['PluginAuthor'] ?? "");
			$o['Repository']    = $o['PluginURL'];
		}

		$o['Blacklist'] = ($o['CABlacklist']??null) ? true : ($o['Blacklist']??false);
		$o['MinVer'] = max([($o['MinVer']??null),($o['UpdateMinVer']??null)]);
		$tag = explode(":",$o['Repository']);
		if (! isset($tag[1]))
			$tag[1] = "latest";
		$o['Path'] = CA_PATHS['templates-community']."/".alphaNumeric($o['RepoName'])."/".alphaNumeric($o['Author'])."-".alphaNumeric($o['Name'])."-{$tag[1]}";
		if ( file_exists($o['Path'].".xml") ) {
			$o['Path'] .= "(1)";
		}
		$o['Path'] .= ".xml";

		$o = fixTemplates($o);
		if ( ! $o ) continue;

		$o['PortsUsed'] = portsUsed($o);

		if ( is_array($o['trends']??null) && count($o['trends']) > 1 ) {
			$o['trendDelta'] = round(end($o['trends']) - $o['trends'][0],4);
			$o['trendAverage'] = round(array_sum($o['trends'])/count($o['trends']),4);
		}

		$o['Category'] = str_replace("Status:Beta","",$o['Category']);    # undo changes LT made to my xml schema for no good reason
		$o['Category'] = str_replace("Status:Stable","",$o['Category']);
		$myTemplates[$i] = $o;

		$ApplicationFeed['repositories'][$o['RepoName']]['downloads'] = $ApplicationFeed['repositories'][$o['RepoName']]['downloads'] ?? 0;
		$ApplicationFeed['repositories'][$o['RepoName']]['trending'] = $ApplicationFeed['repositories'][$o['RepoName']]['trending'] ?? 0;

		$ApplicationFeed['repositories'][$o['RepoName']]['downloads']++;
		$ApplicationFeed['repositories'][$o['RepoName']]['trending'] += $o['trending']??null;
		if ( ! ($o['ModeratorComment']??null) == "Duplicated Template" ) {
			$oFirstSeen = $o['FirstSeen'] ?? null;
			if ($oFirstSeen !== null) {
				$repoFirstSeen = $ApplicationFeed['repositories'][$o['RepoName']]['FirstSeen'] ?? null;
				if ($repoFirstSeen !== null) {
					if ($oFirstSeen < $repoFirstSeen) {
						$ApplicationFeed['repositories'][$o['RepoName']]['FirstSeen'] = $oFirstSeen;
					}
				} else {
					$ApplicationFeed['repositories'][$o['RepoName']]['FirstSeen'] = $oFirstSeen;
				}
			}
		}
		if ( is_array($o['Branch']??null) ) {
			if ( ! isset($o['Branch'][0]) ) {
				$tmp = $o['Branch'];
				unset($o['Branch']);
				$o['Branch'][] = $tmp;
			}
			foreach($o['Branch'] as $branch) {
				if ( is_array($branch['Tag'] ?? null) ) // if someone listed the same tag twice, drop the tag altogether
					continue;
				$subBranch = $o;
				$masterRepository = explode(":",$subBranch['Repository']);
				if ( ! ($branch['Tag']??null) ) {
					continue;
				}
				if ( (!isset($masterRepository[1]) && ($branch['Tag']??null) == "latest" ) || (isset($masterRepository[1]) && ($branch['Tag']??null) == $masterRepository[1]) ) {
				//debug("Default tag is latest, but branch is also latest.  Skipping {$o['RepoName']} {$subBranch['Repository']} {$branch['Tag']}");
				continue;
				}
				$i = ++$i;

				$o['BranchDefault'] = $masterRepository[1] ?? null;

				$subBranch['Repository'] = $masterRepository[0].":". ($branch['Tag'] ?? ""); #This takes place before any xml elements are overwritten by additional entries in the branch, so you can actually change the repo the app draws from
				$subBranch['BranchName'] = $branch['Tag'] ?? "";
				$subBranch['BranchDescription'] = $branch['TagDescription'] ? $branch['TagDescription'] : $branch['Tag'];
				$subBranch['Path'] = CA_PATHS['templates-community']."/".$i.".xml";
				$subBranch['Displayable'] = false;
				$subBranch['ID'] = $i;
				$subBranch['Overview'] = $o['OriginalOverview'] ?? $o['Overview'];
				$subBranch['Description'] = $o['OriginalDescription'] ?? ($o['Description']??null);
				$replaceKeys = array_diff(array_keys($branch),["Tag","TagDescription"]);
				foreach ($replaceKeys as $key) {
					$subBranch[$key] = $branch[$key];
				}
				unset($subBranch['Branch']);
				$myTemplates[$i] = $subBranch;
				$o['BranchID'][] = $i;
			}
		}
		unset($o['Branch']);
		if ( $o['OriginalOverview']??null ) {
			$o['Overview'] = $o['OriginalOverview'];
			unset($o['OriginalOverview']);
			unset($o['Description']);
		}
		if ( $o['OriginalDescription']??null ) {
			$o['Description'] = $o['OriginalDescription'];
			unset($o['OriginalDescription']);
		}
		$myTemplates[$o['ID']] = $o;
		$i = ++$i;

	}

	if ( $invalidXML )
		writeJsonFile(CA_PATHS['invalidXML_txt'],$invalidXML);
	else
		@unlink(CA_PATHS['invalidXML_txt']);

	$GLOBALS['templates'] = $myTemplates;
	writeJsonFile(CA_PATHS['categoryList'],$ApplicationFeed['categories']);

	foreach ($ApplicationFeed['repositories'] as &$repo) {
		if ( $repo['downloads'] ?? false ) {
			$repo['trending'] = $repo['trending'] / $repo['downloads'];
		}
	}

	writeJsonFile(CA_PATHS['repositoryList'],$ApplicationFeed['repositories']);
	writeJsonFile(CA_PATHS['extraBlacklist'],$ApplicationFeed['blacklisted']);
	writeJsonFile(CA_PATHS['extraDeprecated'],$ApplicationFeed['deprecated']);

	updatePluginSupport($myTemplates);
	/* haveTemplates touch + ca_publish are handled by signalFeedReady()
	   inside writeGlobals — fires the moment the small cache is on disk
	   so other tabs can reload, regardless of whether the slim path or
	   the full-feed fallback ran. The hydrate path writes only the full
	   cache and stays silent so we don't double-fire. */

	return true;
}

/**
 * Inner worker for the background hydrate. Pulls the full `applicationFeed.json`
 * (primary then GitHub backup), runs the same per-template pipeline
 * `DownloadApplicationFeed()` runs, applies moderation, and writes both
 * templates caches so install-time port-conflict detection and createXML
 * have Config available.
 *
 * Returns one of "already_fresh" | "ok" | "failed" without touching the
 * HTTP response, so it's safe to call from createXML's synchronous
 * safety-net path as well as from the `hydrateFullFeed` action handler.
 */
function hydrateFullFeedWork(): string {
	clearstatcache();
	$devMode = ($GLOBALS['caSettings']['dev'] ?? null) === "yes";
	/* tempFiles gets wiped on every DownloadApplicationFeed() entry, so the
	   full cache file's mere existence means it was written by either a
	   full-feed download or a prior hydrate — no stale state to worry about. */
	if ( is_file(CA_PATHS['community-templates-info-full']) ) {
		return 'already_fresh';
	}

	$downloadURL = randomFile();
	$ApplicationFeed = download_json(CA_PATHS['application-feed'], $downloadURL, 600, false);
	$label = "Primary Server (full)";
	if ( (! is_array($ApplicationFeed['applist'] ?? null)) || empty($ApplicationFeed['applist']) ) {
		$ApplicationFeed = download_json(CA_PATHS['pluginProxy'].CA_PATHS['application-feedBackup'], $downloadURL, 600, false);
		$label = "Backup Server (full)";
	}
	/* Dev mode: stash the raw applicationFeed.json snapshot before
	   deleting the per-request tempfile so the Diff/Plugin/Template
	   modals don't have to re-download it. The slim-feed download in
	   DownloadApplicationFeed() never produced this cache (it grabs
	   the slimmed-down file), so the full-feed hydrate is the
	   responsible writer in the slim-first path. */
	if ( is_array($ApplicationFeed['applist'] ?? null) && ! empty($ApplicationFeed['applist']) && $devMode ) {
		@copy($downloadURL, CA_PATHS['rawAppFeed']);
	}
	@unlink($downloadURL);

	if ( ! is_array($ApplicationFeed['applist'] ?? null) || empty($ApplicationFeed['applist']) ) {
		return 'failed';
	}

	if ( ! processApplicationFeed($ApplicationFeed, $label) ) {
		return 'failed';
	}

	/* Run moderation on the freshly-downloaded full templates so the
	   on-disk full cache mirrors what force_update's full-feed branch
	   would produce. The small cache was already written by the slim
	   path that preceded us — don't overwrite it here. No feed-ready
	   signal: that fired when writeGlobals wrote the small cache; the
	   full-cache hydrate is silent so subscribers don't double-reload. */
	moderateTemplates();
	writeJsonFile(CA_PATHS['community-templates-info-full'], $GLOBALS['templates']);
	return 'ok';
}

/**
 * Background hydrate action — wraps hydrateFullFeedWork() with the standard
 * postReturn payload. Triggered by JS once `force_update` returns success.
 * Safe to call unconditionally; the worker short-circuits with
 * "already_fresh" when the full cache is already on disk.
 */
function hydrateFullFeed() {
	postReturn(['status' => hydrateFullFeedWork()]);
}

/**
 * Return moderation/statistics details for sidebar popups.
 *
 * Routes on POST['script'] ∈ {Repository, Invalid, Fixed} and returns an array
 * structured for the moderation pane. Calls postReturn() which echoes JSON.
 *
 * @return void
 */
function showModeration() {
	$script = getPost("script", "");
	$allowedScripts = ["Repository", "Invalid", "Fixed"];
	if ( ! in_array($script, $allowedScripts, true) ) {
		postReturn(["moderationType" => $script, "data" => []]);
		return;
	}

	switch ($script) {
		case "Repository":
			$repositories = readJsonFile(CA_PATHS['repositoryList'], []);
			$repos = [];
			foreach ((array)$repositories as $name => $repo) {
				$url = is_array($repo) ? ($repo['url'] ?? "") : "";
				if ($url) {
					$repos[] = ["name" => (string)$name, "url" => (string)$url];
				}
			}
			usort($repos, function($a, $b) {
				return strnatcasecmp($a['name'], $b['name']);
			});
			$ignored = readJsonFile(CA_PATHS['ignoredRepos'], []);
			$ignored = is_array($ignored) ? array_values(array_filter($ignored, "is_string")) : [];
			postReturn(["moderationType" => $script, "data" => ["repositories" => $repos, "ignored" => $ignored]]);
			return;

		case "Invalid":
			$invalidTemplates = readJsonFile(CA_PATHS['invalidXML_txt'], []);
			if ( ! is_array($invalidTemplates) || ! count($invalidTemplates) ) {
				postReturn(["moderationType" => $script, "data" => ["items" => []]]);
				return;
			}
			ksort($invalidTemplates, SORT_NATURAL | SORT_FLAG_CASE);
			$items = [];
			foreach ($invalidTemplates as $template => $errors) {
				$title = (string)$template;
				$details = [];
				if (is_array($errors)) {
					$templatePath = $errors['TemplatePath'] ?? $errors['templatePath'] ?? $errors['templatepath'] ?? null;
					if ($templatePath) {
						$title = str_replace("/tmp/GitHub/repositoryClone/", "", (string)$templatePath);
					}
					$errorList = $errors['errors'] ?? $errors['Errors'] ?? null;
					if (is_array($errorList) && count($errorList)) {
						$details[] = ["label" => "errors", "value" => "", "isSubRule" => false];
						foreach ($errorList as $errorEntry) {
							$details[] = ["label" => "", "value" => $errorEntry, "isSubRule" => true];
						}
					}
					foreach ($errors as $key => $value) {
						$keyLower = strtolower((string)$key);
						if ($keyLower === "templatepath" || $keyLower === "errors" || $keyLower === "firstseen") {
							continue;
						}
						if (is_int($key)) {
							$details[] = ["label" => "", "value" => $value, "isSubRule" => false];
						} else {
							$details[] = ["label" => (string)$key, "value" => $value, "isSubRule" => false];
						}
					}
				} else {
					$details[] = ["label" => "", "value" => $errors, "isSubRule" => false];
				}
				if (!count($details)) {
					$details[] = ["label" => "", "value" => "—", "isSubRule" => false];
				}
				$items[] = ["title" => $title, "details" => $details];
			}
			postReturn([
				"moderationType" => $script,
				"data" => [
					"intro" => tr("These templates are invalid and the application they are referring to is unknown"),
					"items" => $items
				]
			]);
			return;

		case "Fixed":
			$fixedTemplates = readJsonFile(CA_PATHS['fixedTemplates_txt'], []);
			$repositories = [];
			if (is_array($fixedTemplates) && count($fixedTemplates)) {
				ksort($fixedTemplates, SORT_NATURAL | SORT_FLAG_CASE);
				foreach (array_keys($fixedTemplates) as $repository) {
					$repoItems = [];
					$fixCount = 0;
					foreach ((array)$fixedTemplates[$repository] as $repo => $errors) {
						$errorList = [];
						foreach ((array)$errors as $error) {
							$errorList[] = (string)$error;
						}
						$fixCount += count($errorList);
						$repoItems[] = ["name" => (string)$repo, "errors" => $errorList];
					}
					$repositories[] = [
						"name" => (string)$repository,
						"fixCount" => $fixCount,
						"items" => $repoItems
					];
				}
			}

			$pluginDupes = [];
			$dupeList = readJsonFile(CA_PATHS['pluginDupes'], []);
			if ($dupeList) {
				$templates = readJsonFile(CA_PATHS['community-templates-info'], []);
				foreach (array_keys((array)$dupeList) as $dupe) {
					$entries = [];
					foreach ((array)$templates as $template) {
						if (basename($template['PluginURL'] ?? "") == $dupe) {
							$entries[] = trim(($template['Author'] ?? "")." - ".($template['Name'] ?? ""));
						}
					}
					$pluginDupes[] = ["filename" => (string)$dupe, "entries" => $entries];
				}
			}

			$duplicateRepos = [];
			$templates = readJsonFile(CA_PATHS['community-templates-info'], []);
			foreach ((array)$templates as $template) {
				$templateRepo = str_replace(":latest", "", $template['Repository'] ?? "");
				if (!$templateRepo) {
					continue;
				}
				$count = 0;
				foreach ((array)$templates as $searchTemplates) {
					if ( $template['Language'] ?? false) continue;
					if (str_replace(["lscr.io/","ghcr.io/"], "", $templateRepo) == str_replace(":latest", "", str_replace(["lscr.io/","ghcr.io/"], "", $searchTemplates['Repository'] ?? ""))) {
						if (($searchTemplates['BranchName'] ?? false) || ($searchTemplates['Blacklist'] ?? false) || ($searchTemplates['Deprecated'] ?? false)) {
							continue;
						}
						$count++;
					}
				}
				if ($count > 1) {
					$duplicateRepos[] = "Duplicated Template: ".($template['RepoName'] ?? "")." - ".$templateRepo." - ".($template['Name'] ?? "");
				}
			}

			postReturn([
				"moderationType" => $script,
				"data" => [
					"intro" => tr("All of these errors found have been fixed automatically"),
					"notes" => tr("Note that many of these errors can be avoided by following the directions"),
					"helpUrl" => "https://forums.unraid.net/topic/57181-real-docker-faq/#comment-566084",
					"repositories" => $repositories,
					"pluginDupesTitle" => tr("The following plugins have duplicated filenames and are not able to be installed simultaneously:"),
					"pluginDupes" => $pluginDupes,
					"duplicateReposTitle" => tr("The following docker applications refer to the same docker repository but may have subtle changes in the template to warrant this"),
					"duplicateRepos" => $duplicateRepos
				]
			]);
			return;
	}
}

/**
 * Persist the moderation Repository view's user-toggled ignore list to the flash drive.
 *
 * Posted as JSON via POST['ignored']; normalized to a unique sorted string list
 * and written to CA_PATHS['ignoredRepos'] (or unlinked when empty). When the
 * list changes, also wipes /tmp/$CA so the next page load re-downloads the feed.
 * Calls postReturn() with a status JSON payload.
 *
 * @return void
 */
function saveIgnoredRepos() {
	$raw = getPost("ignored", "[]");
	$decoded = json_decode($raw, true);
	if ( ! is_array($decoded) ) {
		$decoded = [];
	}
	/* Normalize to a unique, sorted, string-only list — defensive against the
	   client posting duplicates / non-strings. */
	$decoded = array_values(array_unique(array_filter($decoded, "is_string")));
	sort($decoded, SORT_NATURAL | SORT_FLAG_CASE);

	/* Compare against the on-disk version so we only invalidate caches when
	   the list actually changed (a normal close-without-toggling shouldn't
	   nuke /tmp/$CA or kick the user back to home). */
	$existing = readJsonFile(CA_PATHS['ignoredRepos'], []);
	$existing = is_array($existing) ? array_values(array_filter($existing, "is_string")) : [];
	sort($existing, SORT_NATURAL | SORT_FLAG_CASE);

	if ( $existing === $decoded ) {
		postReturn(["ok" => true, "changed" => false, "count" => count($decoded)]);
		return;
	}

	/* Empty list → unlink the file rather than persisting `[]` so we don't
	   leave an empty marker on the flash. Otherwise write the new list. */
	if ( empty($decoded) ) {
		if ( file_exists(CA_PATHS['ignoredRepos']) ) {
			@unlink(CA_PATHS['ignoredRepos']);
		}
	} else {
		writeJsonFile(CA_PATHS['ignoredRepos'], $decoded);
	}
	/* Wipe the working temp tree so the next page load re-downloads the
	   feed and re-runs DownloadApplicationFeed() with the new ignore list
	   in effect. dirname() gives the parent of tempFiles ('/tmp/$CA'), which
	   is the entire CA temp area. */
	exec("rm -rf ".escapeshellarg(dirname(CA_PATHS['tempFiles'])));
	postReturn(["ok" => true, "changed" => true, "count" => count($decoded), "restart" => true]);
}

/**
 * Sync Support URLs in the template list from installed plugin .plg files.
 *
 * @param array<int,array<string,mixed>> $templates
 * @return void
 */
function updatePluginSupport($templates) {
	$plugins = glob("/boot/config/plugins/*.plg");

	foreach ($plugins as $plugin) {
		$pluginURL = @ca_plugin("pluginURL",$plugin,true);
		$pluginEntry = searchArray($templates,"PluginURL",$pluginURL);
		if ( $pluginEntry === false ) {
			$pluginEntry = searchArray($templates,"PluginURL",str_replace("https://raw.github.com/","https://raw.githubusercontent.com/",$pluginURL));
		}
		if ( $pluginEntry !== false && $templates[$pluginEntry]['PluginURL']) {
			$xml = simplexml_load_file($plugin);
			if ( ! $templates[$pluginEntry]['Support'] ) {
				continue;
			}
			if ( @ca_plugin("support",$plugin,true) !== $templates[$pluginEntry]['Support'] ) {
				// remove existing support attribute if it exists
				if ( @ca_plugin("support",$plugin,true) ) {
					$existing_support = $xml->xpath("//PLUGIN/@support");
					foreach ($existing_support as $node) {
						unset($node[0]);
					}
				}
				$xml->addAttribute("support",$templates[$pluginEntry]['Support']);
				$dom = new DOMDocument('1.0');
				$dom->preserveWhiteSpace = false;
				$dom->formatOutput = true;
				$dom->loadXML($xml->asXML());
				ca_file_put_contents($plugin, $dom->saveXML());
			}
		}
	}
	dropAttributeCache();
}

// function getConvertedTemplates() {

//   getGlobals();
// # Start by removing any pre-existing private (converted templates)
//   $templates = &$GLOBALS['templates'];

//   if ( empty($templates) ) return false;

//   if ( ! is_dir(CA_PATHS['convertedTemplates']) ) {
//     return;
//   }

//   $myTemplates = [];
//   foreach ($templates as $template) {
//     if ( ! ($template['Private']??null) )
//       $myTemplates[] = $template;
//   }
//   $appCount = count($myTemplates);
//   $i = $appCount;

//   $privateTemplates = glob(CA_PATHS['convertedTemplates']."*/*.xml");
//   foreach ($privateTemplates as $templateXML) {
//     $o = addMissingVars(readXmlFile($templateXML));
//     if ( ! $o['Repository'] ) continue;

//     $o['Private']      = true;
//     $o['RepoName']     = basename(pathinfo($templateXML,PATHINFO_DIRNAME))." Repository";
//     $o['ID']           = $i;
//     $o['Displayable']  = true;
//     $o['Date']         = ( $o['Date'] ) ? strtotime( $o['Date'] ) : 0;
//     $o['SortAuthor']   = $o['Author'];
//     $o['Compatible']   = versionCheck($o);
//     $o['Description']  = $o['Description'] ?: $o['Overview'];
//     $o['CardDescription'] = strip_tags(trim(markdown($o['Description'])));
//     $o = fixTemplates($o);
//     $myTemplates[$i]  = $o;
//     $i = ++$i;
//   }
//   writeJsonFile(CA_PATHS['community-templates-info'],$myTemplates);
//   $GLOBALS['templates'] = $myTemplates;
// }

/**
 * Build a debugging zip from CA logs and the PHP log, returning the served URL.
 *
 * Reads POST['file'] for the zip filename (validated to match the CA-Logging-*.zip
 * pattern), shells out to `zip` with escaped paths, then returns the URL via
 * postReturn().
 *
 * @return void
 */
function downloadDebugging() {
	global $docroot;

	$file = basename((string)getPost("file", ""));
	if (!$file || strpos($file, "CA-Logging-") !== 0 || substr($file, -4) !== ".zip") {
		postReturn(["zip" => ""]);
		return;
	}

	@copy("/var/log/phplog", "/tmp/phplog.txt");
	exec("zip -qlj ".escapeshellarg("$docroot/$file")." ".escapeshellarg(CA_PATHS['logging'])." /tmp/phplog.txt");
	@unlink("/tmp/phplog.txt");

	postReturn(["zip" => "/$file"]);
}

/**
 * Pick the cohort of templates that fills the current home-screen row.
 *
 * Behaviour depends on $GLOBALS['caSettings']['startup'] (random, onlynew,
 * topperforming, topPlugins, trending, spotlight, featured). For "random" the
 * selection is cached in CA_PATHS['appOfTheDay'] and re-used for the rest of
 * the calendar day. Mutates global $sortOrder for the other modes.
 *
 * @param  array<int,array<string,mixed>>  $file  Application templates.
 * @return array<int,int|string> List of template IDs chosen for the row.
 */
function appOfDay($file) {
	global $sortOrder,$dynamixSettings;

	$max = getPost("maxHomeApps",6);
	$appOfDay = [];

	switch ($GLOBALS['caSettings']['startup']) {
		case "random":
			$oldAppDay = @filemtime(CA_PATHS['appOfTheDay']);
			$oldAppDay = $oldAppDay ?: 1;
			$oldAppDay = intval($oldAppDay / 86400);
			$currentDay = intval(time() / 86400);
			if ( $oldAppDay == $currentDay ) {
				$appOfDay = readJsonFile(CA_PATHS['appOfTheDay']);
				$flag = false;
				foreach ($appOfDay as $testApp) {
					if ( ! checkRandomApp($file[$testApp]) ) {
						$flag = true;
						break;
					}
				}
				if ( $flag )
					$appOfDay = null;
			}
			if ( ! $appOfDay ) {
				shuffle($file);
				foreach ($file as $template) {
					if ( ! checkRandomApp($template) ) continue;
					$appOfDay[] = $template['ID'];
					if (count($appOfDay) == $max) break;
				}
			}
			writeJsonFile(CA_PATHS['appOfTheDay'],$appOfDay);

			break;
		case "onlynew":
			$sortOrder['sortBy'] = "FirstSeen";
			$sortOrder['sortDir'] = "Down";
			usort($file,"mySort");
			foreach ($file as $template) {
				if ( ! $template['Compatible'] == "true" && $GLOBALS['caSettings']['hideIncompatible'] == "true" ) continue;
				if ( $template['FirstSeen'] > 1538357652 ) {
					if ( checkRandomApp($template) ) {
						$appOfDay[] = $template['ID'];
						if ( count($appOfDay) == $max ) break;
					}
				}
			}
			break;
		case "topperforming":
			$sortOrder['sortBy'] = "trending";
			$sortOrder['sortDir'] = "Down";
			usort($file,"mySort");
			$repos = [];
			foreach ($file as $template) {
				if ( ! isset($template['trends']) ) continue;
				if ( count($template['trends']) < 6 ) continue;
				if ( startsWith($template['Repository'],"ich777/steamcmd") ) continue; // because a ton of apps all use the same repo
				if ( $template['trending'] && ( ($template['PluginURL']??false) || ($template['downloads'] > 100000) ) ) {
					if ( checkRandomApp($template) ) {
						if ( in_array($template['Repository'],$repos) )
							continue;
						$repos[] = $template['Repository'];
						$appOfDay[] = $template['ID'];
						if ( count($appOfDay) == $max ) break;
					}
				}
			}
			break;
		case "topPlugins":
			$previousMonth = date("m", strtotime("first day of previous month"));
			foreach ($file as &$tplRef) {
				if ( ! isset($tplRef['PluginURL']) ) continue;
				$tplRef['lastMonthDownloads'] = (int)($tplRef['pluginStats'][$previousMonth] ?? 0);
			}
			unset($tplRef);
			$sortOrder['sortBy'] = "lastMonthDownloads";
			$sortOrder['sortDir'] = "Down";
			usort($file,"mySort");
			$repos = [];
			foreach ($file as $template) {
				if ( !isset($template['PluginURL']) ) continue;
				if ( ! ($template['lastMonthDownloads'] ?? 0) ) continue;

				// don't show patch within top installs on home page if it's already installed and featured is displayed

				if ( $template['Name'] == "Unraid Patch" && ($GLOBALS['caSettings']['featuredDisable'] == "no" || is_file("/var/log/plugins/unraid.patch.plg")) ) continue;

				if ( checkRandomApp($template) ) {
					$repos[] = $template['Repository'];
					$appOfDay[] = $template['ID'];
					if ( count($appOfDay) == $max ) break;

				}
			}
			break;
		case "trending":
			$sortOrder['sortBy'] = "trendDelta";
			$sortOrder['sortDir'] = "Down";
			usort($file,"mySort");
			$repos = [];
			foreach ($file as $template) {
				if ( isset($template['trends']) && count($template['trends'] ) < 3 ) continue;
				if ( startsWith($template['Repository'],"ich777/steamcmd") ) continue; // because a ton of apps all use the same repo`
				if ( isset($template['trending']) && ( ($template['PluginURL']??false) || ($template['downloads'] > 10000) ) ) {
					if ( checkRandomApp($template) ) {
						if ( in_array($template['Repository'],$repos) )
							continue;
						$repos[] = $template['Repository'];
						$appOfDay[] = $template['ID'];
						if ( count($appOfDay) == $max ) break;
					}
				}
			}
			break;
		case "spotlight":
			$sortOrder['sortBy'] = "RecommendedDate";
			$sortOrder['sortDir'] = "Down";
			usort($file,"mySort");
			foreach($file as $template) {
				if ($template['RecommendedDate']) {
					if ( ! checkRandomApp($template) ) continue;

					$appOfDay[] = $template['ID'];
					if ( count($appOfDay) == $max ) break;
				} else {
					break;
				}
			}
			break;
		case "featured":
			$containers = getAllInfo();
			$sortOrder['sortBy'] = "Featured";
			$sortOrder['sortDir'] = "Down";
			usort($file,"mySort");
			foreach($file as $template) {
				if ( ! isset($template['Featured'] ) )
					break;
					// Don't show it if the plugin is installed

				if ( ($template['PluginURL']??false) && is_file("/var/log/plugins/".basename($template['PluginURL'])) ) {
					if ( checkPluginUpdate($template['PluginURL']) ) {
						$appOfDay[] = $template['ID'];
						if ( count($appOfDay) == $max )
							break;
						continue;
					}
				}
				if ( ($template['PluginURL']??false) && is_file("/var/log/plugins/".basename($template['PluginURL'])) && ! ($template['UninstallOnly']??false) )
					continue;
				// Don't show it if the container is installed
				if ( ! ($template['PluginURL']??false) ) {
					$selected = false;

					if ( caIsDockerRunning() ) {
						foreach ($containers as $testDocker) {
							if ( ($template['Repository'] == $testDocker['Image'] ) || ($template['Repository'].":latest" == $testDocker['Image']) || (str_replace(":latest","",$template['Repository']) == $testDocker['Image']) ) {
								$selected = true;
								break;
							}
						}
					}
					if ( $selected )
						continue;
				}
				$appOfDay[] = $template['ID'];
				if ( count($appOfDay) == $max ) break;
			}
	}
	return $appOfDay ?: [];
}

/**
 * Eligibility check for app-of-the-day candidates.
 *
 * Rejects CA itself, branch templates, non-displayable rows, blacklisted rows,
 * and (when configured) incompatible/deprecated rows. Reads $GLOBALS['caSettings'].
 *
 * @param  array<string,mixed>  $test  Candidate template.
 * @return bool True when the template may be shown on the home screen.
 */
function checkRandomApp($test) {

	if ( $test['Name'] == "Community Applications" )  return false;
	if ( $test['BranchName'] ?? false)                        return false;
	if ( ! $test['Displayable'] )                     return false;
	if ( ! $test['Compatible'] && $GLOBALS['caSettings']['hideIncompatible'] == "true" ) return false;
	if ( $test['Blacklist'] )                         return false;
	if ( $test['Deprecated'] && ( $GLOBALS['caSettings']['hideDeprecated'] == "true" ) ) return false;

	return true;
}
/**
 * Build the Repositories view dataset and write it to the per-tab cache file.
 *
 * Reads the current displayed-templates JSON, deduplicates per repo, separates
 * "bio" repos from the rest, places the user's favourite first, and writes
 * CA_PATHS['repositoriesDisplayed']. Reads $GLOBALS['caSettings'].
 *
 * @return void
 */
function displayRepositories() {

	$repositories = readJsonFile(CA_PATHS['repositoryList']);
	if ( is_file(CA_PATHS['community-templates-allSearchResults']) ) {
		$temp = readJsonFile(CA_PATHS['community-templates-allSearchResults']);
		$templates = $temp['community'] ?? [];
	} else {
		$temp = readJsonFile(CA_PATHS['community-templates-displayed']);
		$templates = $temp['community'] ?? [];
	}

	if ( is_file(CA_PATHS['startupDisplayed']) ) {
		$templates = $GLOBALS['templates'] ?? [];
	}

	if ( ! is_array($templates) ) {
		$templates = [];
	}

	$allRepos = [];
	$bio = [];
	$fav = null;

	$prepareRepository = function ($repository, $repoName) {
		$repository['RepositoryTemplate'] = true;
		$repository['RepoName'] = $repoName;
		$repository['SortName'] = $repoName;

		return addMissingVars($repository);
	};

	foreach ($templates as $template) {
		if ( $template['Blacklist'] ) continue;
		if ( $template['Deprecated'] && $GLOBALS['caSettings']['hideDeprecated'] == "true" ) continue;
		if ( ! $template['Compatible'] && $GLOBALS['caSettings']['hideIncompatible'] == "true" ) continue;

		$repoName = $template['RepoName'] ?? null;
		if ( ! $repoName || ! isset($repositories[$repoName]) ) continue;

		$repository = $repositories[$repoName];

		if ( $repoName == $GLOBALS['caSettings']['favourite'] ) {
			$fav = $prepareRepository($repository, $repoName);
			continue;
		}

		$preparedRepository = $prepareRepository($repository, $repoName);

		if ( isset($repository['bio']) ) {
			$bio[$repoName] = $preparedRepository;
		} else {
			$allRepos[$repoName] = $preparedRepository;
		}
	}

	usort($bio,"mySort");
	usort($allRepos,"mySort");

	$combinedRepos = array_merge($bio,$allRepos);

	if ( $fav !== null ) {
		array_unshift($combinedRepos,$fav);
	}

	writeJsonFile(CA_PATHS['repositoriesDisplayed'],['community' => $combinedRepos]);
}



/**
 * Build the main Apps grid for the current category/filter and return the HTML.
 *
 * Reads POST keys (filter, category, newApp, mobileDevice, maxHomeApps,
 * startupDisplay, maxPerPage), dispatches into GetContentHelpers for filtering /
 * special-category handling, writes the per-tab displayed JSON, and calls
 * postReturn() with rendered HTML.
 *
 * @return void
 */
function get_content() {

	require_once __DIR__ . '/get_content_helpers.php';

	$filter       = getPost("filter",false);
	$categoryRaw  = getPost("category",false);
	$newApp       = filter_var(getPost("newApp",false),FILTER_VALIDATE_BOOLEAN);
	$mobileDevice = filter_var(getPost("mobileDevice",false),FILTER_VALIDATE_BOOLEAN);

	// If the templates cache is missing/empty, show the existing bottom banner and reload.
	clearstatcache();
	if ( !is_file(CA_PATHS['community-templates-info']) || empty($GLOBALS['templates']) ) {
		postReturn([
			'script' => "caShowFatalReloadBanner(tr('An error occurred. Click anywhere to reload the page.'));"
		]);
		return;
	}

	if ( $mobileDevice ) {
		$GLOBALS['caSettings']['maxPerPage'] = 6;
	}
	$maxHomeApps = getPost("maxHomeApps",6);

	$GLOBALS['caSettings']['startup'] = getPost("startupDisplay",false);
	@unlink(CA_PATHS['repositoriesDisplayed']);
	@unlink(CA_PATHS['dockerSearchActive']);

	$categoryContext = GetContentHelpers::resolveCategoryContext($categoryRaw);

	if ( $categoryContext['action'] === 'repos' ) {
			 displayRepositories(); // writes repositoriesDisplayed cache
			 $o['display_data'] = display_apps(1, false, false, true);
			 postReturn($o);
		return;
	}

	if ( $categoryContext['action'] === 'duplicates' ) {
		/* Defense in depth — the menu item is hidden when these conditions
		   aren't met, but a hand-crafted POST shouldn't be able to enumerate
		   the duplicates dataset either. */
		if (($GLOBALS['caSettings']['dev'] ?? null) !== "yes" || !is_file(CA_PATHS['caAdmin'])) {
			postReturn(["error"=>tr("Not authorized")]);
			return;
		}
		$duplicates = caFindDuplicateTemplates($GLOBALS['templates'], null);
		$displayApplications = ['community' => $duplicates];
		/* Mirror the cleanup getRepoDuplicates() does — without it the
		   startup-mode flag can leak past the duplicates view into the
		   next category navigation and incorrectly re-trigger the home
		   startup display. */
		@unlink(CA_PATHS['startupDisplayed']);
		writeJsonFile(CA_PATHS['community-templates-displayed'], $displayApplications);
		@unlink(CA_PATHS['community-templates-allSearchResults']);
		@unlink(CA_PATHS['community-templates-catSearchResults']);
		$o['display_data'] = display_apps(1, false, false, true);
		postReturn($o);
		return;
	}

	$categoryRegex = $categoryContext['categoryRegex'];
	$displayFlags = [
		'displayBlacklisted'  => $categoryContext['displayBlacklisted'],
		'displayDeprecated'   => $categoryContext['displayDeprecated'],
		'displayIncompatible' => $categoryContext['displayIncompatible'],
		'displayPrivates'     => $categoryContext['displayPrivates']
	];
	$noInstallComment = $categoryContext['noInstallComment'];

	if ( $categoryRegex && strpos($categoryRegex,":") !== false && $filter ) {
		$disp = readJsonFile(CA_PATHS['community-templates-allSearchResults']);
		$file = &$disp['community'];
	} else {
		$file = &$GLOBALS['templates'];
	}

	if ( empty($file)) {
		postReturn([
			'script' => "caShowFatalReloadBanner(tr('An error occurred. Click anywhere to reload the page.'));"
		]);
		return;
	}

	if ( ! $filter && $categoryRegex === "/NONE/i" ) {
		if ( GetContentHelpers::handleHomeStartupDisplay($file, $maxHomeApps) ) {
			return;
		}
	} else {
		@unlink(CA_PATHS['startupDisplayed']);
	}

	changeMax(getPost("maxPerPage",$GLOBALS['caSettings']['maxPerPage']));

	$displayApplications = [];
	$display  = [];
	$searchResults = [];

	foreach ($file as $template) {
		$template['NoInstall'] = $noInstallComment;

		if ( GetContentHelpers::handleSpecialTemplateDisplays($template, $display, $displayFlags) ) {
			continue;
		}

		if ( GetContentHelpers::shouldSkipTemplate($template, $displayFlags) ) {
			continue;
		}

		if ( $categoryRegex && ! preg_match($categoryRegex,$template['Category']) ) {
			continue;
		}
		if ( $categoryRegex === "/spotlight:/i" ) {
			$template['class'] = "ca_appTemplate";
		}

		if ( ($template['Plugin']??null) && file_exists("/var/log/plugins/".basename($template['PluginURL'])) ) {
			$template['InstallPath'] = $template['PluginURL'];
		}

		$template['NewApp'] = $newApp;

		if ( $filter ) {
			GetContentHelpers::handleFilteredTemplate($template,$filter,$searchResults);
			continue;
		}

		$display[] = $template;
	}

	if ( $filter ) {
		GetContentHelpers::sortSearchResultsBuckets($searchResults, $filter);
		$displayApplications['community'] = array_merge(
			$searchResults['officialHit'],
			$searchResults['fullNameHit'],
			$searchResults['nameHit'],
			$searchResults['favNameHit'],
			$searchResults['anyHit'],
			$searchResults['extraHit']
		);
	} else {
		usort($display,"mySort");
		$displayApplications['community'] = $display;
	}

	GetContentHelpers::cacheDisplayApplications($categoryRegex, $filter, $displayApplications);

	$o['display_data'] = display_apps(1, false, false, true);

	postReturn($o);
}

/**
 * Complete a pending force-update if templates are ready; otherwise run force_update.
 *
 * @return void
 */
function force_update_skip() {
	clearstatcache();
	if ( ! is_file(CA_PATHS['gettingTemplates']) && is_file(CA_PATHS['community-templates-info']) ) {
		/* Tab is exiting with the slim cache it walked in expecting. If
		   another tab's DownloadApplicationFeed wiped the registry while
		   this request was in flight (or between the tab's registerTab
		   and this call), the marker is gone — re-register here so a
		   subsequent caFeedCheck doesn't false-positive as stale. */
		ensureTabRegistered();
		postReturn(['status' => "ok"]);
		return;
	}
	force_update();
}
/**
 * Force a refresh of the application feed and rebuild local templates.
 *
 * Coordinates with the gettingTemplates lock so only one updater runs at a
 * time; waits for the existing run when present. Otherwise downloads metadata,
 * rebuilds the feed when stale, runs moderation, and posts an "ok" response.
 *
 * Side effects: touches/unlinks the gettingTemplates/haveTemplates lock files,
 * writes globals, calls postReturn().
 *
 * @return void
 */
function force_update() {

	require_once __DIR__ . '/force_update_helpers.php';
	// If another update is already running, don't fetch metadata; just wait for it to finish.
	if (is_file(CA_PATHS['gettingTemplates'])) {
		while ( is_file(CA_PATHS['gettingTemplates']) ) {
			sleep(1);
			clearstatcache();
		}
		/* The other tab's DownloadApplicationFeed wiped tempFiles
		   (including this tab's marker) during the wait. The wait-
		   then-ok path is now in sync with that fresh feed, so
		   re-register here — otherwise a subsequent caFeedCheck on
		   this tab would falsely surface the stale-feed banner. */
		ensureTabRegistered();
		postReturn(['status' => "ok"]);
		return;
	}
	touch(CA_PATHS['gettingTemplates']);

	/* Load the slim cache, not the full one — moderateTemplates and
	   buildUpdateScript only touch fields present in the slim copy, and
	   writeGlobals at the end writes $GLOBALS['templates'] as-is to the
	   small cache. Loading the full cache here would make the no-download
	   path write the full templates (Config and all) back to the small
	   cache, defeating the whole point of having two caches. */
	getGlobals();

	$lastUpdatedOld = readJsonFile(CA_PATHS['lastUpdated-old']);
	debug("old feed timestamp: ".($lastUpdatedOld['last_updated_timestamp'] ?? ""));

	$latestUpdate = ForceUpdateHelpers::fetchLatestUpdateMetadata();

	if (ForceUpdateHelpers::shouldRefreshTemplates($latestUpdate, $lastUpdatedOld)) {
		ForceUpdateHelpers::resetTemplatesCache();
	}

	/* Track whether THIS invocation actually pulled a fresh feed. */
	$freshlyDownloaded = false;
	if (!ForceUpdateHelpers::templatesAvailable()) {
		if (!DownloadApplicationFeed()) {
			@unlink(CA_PATHS['gettingTemplates']);
			@unlink(CA_PATHS['haveTemplates']);
			postReturn(ForceUpdateHelpers::buildDownloadFailureResponse());
			return;
		}
		$freshlyDownloaded = true;
	}

	@unlink(CA_PATHS['gettingTemplates']);
	$script = ForceUpdateHelpers::buildUpdateScript();

	/* Only moderate + write back when we actually downloaded a fresh feed.
	   On a no-download cycle the cache already on disk is the output of
	   the last download's moderation pass — re-running moderateTemplates +
	   writeGlobals would write identical bytes back, and only fires the
	   cross-tab "feed updated" banner unnecessarily. The one input that
	   could legitimately change moderation results between cycles without
	   a download is the Unraid version (Compatible / Deprecated / Featured
	   flags depend on it), and an OS-version change requires a reboot —
	   which wipes /tmp, including the templates cache, so the next
	   force_update reaches DownloadApplicationFeed and rebuilds anyway.
	   The full cache is filled in by hydrateFullFeedWork separately. */
	if ($freshlyDownloaded) {
		moderateTemplates();
		writeGlobals($GLOBALS['templates']);
	}
	/* Re-register the calling tab. If this invocation triggered
	   DownloadApplicationFeed, the wipe took the marker with it and we
	   need to restore it. If this was a no-download cycle, the marker
	   either still exists from registerTab (re-touch is a no-op) or
	   was wiped by ANOTHER tab's concurrent download since registerTab
	   — in which case this tab is now in sync with that fresh feed
	   (templates were just re-read from the post-download cache) and
	   should be re-registered. Either way, idempotent. */
	ensureTabRegistered();
	postReturn(['status' => "ok", 'script' => $script]);
}



/**
 * Render a single page of the displayed-templates JSON for the Apps grid.
 *
 * Reads POST pageNumber/maxPerPage/startup/selected and delegates to
 * display_apps(). When no displayed cache exists the response is a fatal
 * "reload" banner.
 *
 * @return void
 */
function display_content() {


	$pageNumber = getPost("pageNumber","1");

	changeMax(getPost("maxPerPage",$GLOBALS['caSettings']['maxPerPage']));
	$startup = getPost("startup",false);
	$selectedApps = json_decode(getPost("selected",false),true);
	$o['display'] = "";
	clearstatcache();
	if ( !file_exists(CA_PATHS['community-templates-displayed']) && !file_exists(CA_PATHS['repositoriesDisplayed']) ) {
		postReturn([
			'script' => "caShowFatalReloadBanner(tr('An error occurred. Click anywhere to reload the page.'));"
		]);
		return;
	}

	$o['display_data'] = display_apps($pageNumber,$selectedApps,$startup,true);

	postReturn($o);
}

/**
 * Mark the first-run installation warning as accepted.
 *
 * Writes the acceptance flag file, clears the NoInstalls setting, and persists
 * caSettings via write_ini_file().
 *
 * @return void
 */
function dismiss_warning() {

	ca_file_put_contents(CA_PATHS['warningAccepted'],"warning dismissed");
	unset($GLOBALS['caSettings']['NoInstalls']);
	write_ini_file(CA_PATHS['pluginSettings'],$GLOBALS['caSettings']);
	postReturn(['status'=>"warning dismissed"]);
}

/**
 * Build the Installed Apps / Previously Installed Apps / Action Centre listing.
 *
 * Aggregates Docker and plugin sections through PreviousAppsHelpers, writes
 * the per-tab displayed JSON, and either returns whether any rows were emitted
 * (Action Centre mode) or calls postReturn() with the action centre badge count.
 *
 * @param  bool  $enableActionCentre  When true, only return whether rows exist; do not echo.
 * @return bool|void Returns bool in Action Centre mode, otherwise void.
 */
function previous_apps($enableActionCentre=false) {

	require_once __DIR__ . '/previous_apps_helpers.php';

	changeMax(getPost("maxPerPage",$GLOBALS['caSettings']['maxPerPage']));

	$context = PreviousAppsHelpers::resolvePreviousAppsContext($enableActionCentre);
	$installed = $context['installed'];
	$filter = $context['filter'];

	$info = getAllInfo();

	$file = &$GLOBALS['templates'];
	$extraBlacklist = readJsonFile(CA_PATHS['extraBlacklist']) ?: [];
	$extraDeprecated = readJsonFile(CA_PATHS['extraDeprecated']) ?: [];
	$displayed = [];
	$updateCount = 0;

	$dockerRunning = caIsDockerRunning();
	$dockerUpdateStatus = PreviousAppsHelpers::loadDockerUpdateStatus($dockerRunning);

	$displayed = array_merge(
		$displayed,
		PreviousAppsHelpers::collectDockerApplications($dockerRunning, $installed, $filter, $info, $updateCount, $file, $extraBlacklist, $extraDeprecated, $dockerUpdateStatus)
	);

	$displayed = array_merge(
		$displayed,
		PreviousAppsHelpers::collectPluginApplications($installed, $filter, $file, $updateCount)
	);

	if ( $enableActionCentre ) {
		return ! empty($displayed);
	}

	if ( isset($displayed) && is_array($displayed) ) {
		usort($displayed,"mySort");
	}
	$displayedApplications['community'] = $displayed;
	writeJsonFile(CA_PATHS['community-templates-displayed'],$displayedApplications);
	if ( $installed == "action" && empty($displayed) ) {
		postReturn(['status'=>"ok",'script'=>'$(".actionCentre").hide();$(".startupButton").trigger("click");']);
	} else {
		postReturn(['status'=>"ok",'updateCount'=>$updateCount]);
	}
}

/**
 * Return the per-submenu counts used to enable/disable the Installed Apps and
 * Previous Apps submenu items without rerendering result pages.
 *
 * @return void
 */
function installedAndPreviousCounts() {
	require_once __DIR__ . '/previous_apps_helpers.php';
	postReturn(['status'=>"ok", 'counts'=>PreviousAppsHelpers::installedAndPreviousCounts()]);
}

/**
 * Delete the user template/plugin file behind a Previously Installed row.
 *
 * Reads POST['application'], validates it resolves under /boot/config and is
 * either an xml or plg file, then unlinks. Calls postReturn().
 *
 * @return void
 */
function remove_application() {
	$application = realpath(getPost("application",""));
	if ( ! (strpos($application,"/boot/config") === false) ) {
		if ( pathinfo($application,PATHINFO_EXTENSION) == "xml" || pathinfo($application,PATHINFO_EXTENSION) == "plg" )
			@unlink($application);
	}
	postReturn(['status'=>"ok"]);
}

/**
 * After a plugin install/update, refresh UpdateAvailable on the displayed list.
 *
 * Reads POST['filename'], walks the cached community-templates-displayed JSON,
 * re-runs checkPluginUpdate() for the matching plugin and persists the cache.
 *
 * @return void
 */
function updatePLGstatus() {

	$filename = getPost("filename","");
	$displayed = readJsonFile(CA_PATHS['community-templates-displayed']);
	$superCategories = array_keys($displayed);
	foreach ($superCategories as $category) {
		foreach ($displayed[$category] as $template) {
			if ( strpos($template['PluginURL'],$filename) )
				$template['UpdateAvailable'] = checkPluginUpdate($filename);

			$newDisplayed[$category][] = $template;
		}
	}
	writeJsonFile(CA_PATHS['community-templates-displayed'],$newDisplayed);
	postReturn(['status'=>"ok"]);
}

/**
 * Stop and remove a Docker container plus its image; prune dangling volumes.
 *
 * Reads POST['application'] (path to user template XML), extracts the
 * container Name from it, calls into the global $DockerClient to stop/remove,
 * shells out to `docker volume prune`, refreshes the running-info cache via
 * getAllInfo(true), and posts a status reply.
 *
 * @return void
 */
function uninstall_docker() {
	global $DockerClient;

	$application = getPost("application","");

# get the name of the container / image
	$doc = new DOMDocument();
	$doc->load($application);
	$containerName  = stripslashes($doc->getElementsByTagName( "Name" )->item(0)->nodeValue);

	$dockerRunning = $DockerClient->getDockerContainers();
	$container = searchArray($dockerRunning,"Name",$containerName);

	if ( $dockerRunning[$container]['Running'] )
		myStopContainer($dockerRunning[$container]['Id']);

	$DockerClient->removeContainer($containerName,$dockerRunning[$container]['Id']);
	$DockerClient->removeImage($dockerRunning[$container]['ImageId']);
	exec("/usr/bin/docker volume prune");
	getAllInfo(true);
	postReturn(['status'=>"Uninstalled"]);
}

/**
 * Toggle the pinned state of an application.
 *
 * Reads POST['repository'] and POST['name'], updates the pinned list in
 * CA_PATHS['pinnedV2'], and returns whether any apps remain pinned.
 *
 * @return void
 */
function pinApp() {

	$repository = getPost("repository","oops");
	$name = getPost("name","oops");
	$pinnedApps = readJsonFile(CA_PATHS['pinnedV2']);
	if (isset($pinnedApps["$repository&$name"]) )
		$pinnedApps["$repository&$name"] = false;
	else
	$pinnedApps["$repository&$name"] = "$repository&$name";
	$pinnedApps = array_filter($pinnedApps);
	writeJsonFile(CA_PATHS['pinnedV2'],$pinnedApps);
	postReturn(['status' => in_array(true,$pinnedApps)]);
}

/**
 * Return whether the user has any pinned apps (drives the menu visibility).
 *
 * @return void
 */
function areAppsPinned() {

	postReturn(['status' => in_array(true,readJsonFile(CA_PATHS['pinnedV2']))]);
}

/**
 * Render the Pinned Apps grid for the user.
 *
 * Resolves the pinned list (CA_PATHS['pinnedV2']) into concrete templates via
 * PinnedAppsHelpers::findPinnedTemplate(), clears search-result caches, writes
 * the displayed JSON, and calls postReturn() with the page script.
 *
 * @return void
 */
function pinnedApps() {

	require_once __DIR__ . '/pinned_apps_helpers.php';

	$pinnedApps = array_filter((array)readJsonFile(CA_PATHS['pinnedV2']));
	debug("pinned apps memory usage before: ".round(memory_get_usage()/1048576,2)." MB");
	$templates = &$GLOBALS['templates'];
	debug("pinned apps memory usage after: ".round(memory_get_usage()/1048576,2)." MB");

	PinnedAppsHelpers::clearPinnedCacheFiles([
		'community-templates-allSearchResults',
		'community-templates-catSearchResults',
		'repositoriesDisplayed',
		'startupDisplayed',
		'dockerSearchActive'
	]);

	$displayed = [];
	$hideIncompatible = ($GLOBALS['caSettings']['hideIncompatible'] ?? "false") === "true";

	foreach ($pinnedApps as $pinned) {
		if (!is_string($pinned) || strpos($pinned, '&') === false) {
			continue;
		}

		$template = PinnedAppsHelpers::findPinnedTemplate($templates, $pinned, $hideIncompatible);
		if ($template !== null) {
			$displayed[] = $template;
		}
	}

	usort($displayed, "mySort");
	if (empty($displayed)) {
		$script = "$('.caPinnedMenu').addClass('caMenuDisabled').removeClass('caMenuEnabled');";
	}

	$displayedApplications = [
		'community' => $displayed,
		'pinnedFlag' => true
	];

	writeJsonFile(CA_PATHS['community-templates-displayed'], $displayedApplications);
	postReturn(["status" => "ok", "script" => $script ?? ""]);
}

/**
 * Render the branch-tag picker rows for an app with multiple Tag branches.
 *
 * Reads POST['leadTemplate'] and POST['rename']; returns HTML via formatTags()
 * and postReturn().
 *
 * @return void
 */
function displayTags() {
	$leadTemplate = getPost("leadTemplate","oops");
	$rename = getPost("rename","false");
	postReturn(['tags'=>formatTags($leadTemplate,$rename)]);
}

/**
 * Compute and return the appfeed statistics shown in the Statistics popup.
 *
 * Downloads the statistics JSON on first call, walks the in-memory templates
 * to tally plugin/docker/private/blacklist/etc. counts, and renders feed
 * timestamp + current server. Calls postReturn().
 *
 * @return void
 */
function statistics() {

	if ( ! is_file(CA_PATHS['statistics']) )
		$statistics = download_json(CA_PATHS['statisticsURL'],CA_PATHS['statistics']);
	else
		$statistics = readJsonFile(CA_PATHS['statistics']);

	$repositories = readJsonFile(CA_PATHS['repositoryList']);
	$templates = &$GLOBALS['templates'];
	pluginDupe();
	$invalidXML = readJsonFile(CA_PATHS['invalidXML_txt']);
	$statistics['blacklist'] = $statistics['plugin'] = $statistics['docker'] = $statistics['private'] = $statistics['totalDeprecated'] = $statistics['totalIncompatible'] = $statistics['official'] = $statistics['invalidXML'] = 0;

	foreach ($templates as $template) {
		if ( ($template['Deprecated']??false) && ! ($template['Blacklist']??false) && ! ($template['BranchID']??false)) $statistics['totalDeprecated']++;

		if ( ! ($template['Compatible']??false) ) $statistics['totalIncompatible']++;

		if ( $template['Blacklist']??false ) $statistics['blacklist']++;

		if ( ($template['Private']??false) && ! ($template['Blacklist']??false)) {
			if ( ! ($GLOBALS['caSettings']['hideDeprecated'] == 'true' && ($template['Deprecated']??false)) ) {
				$statistics['private']++;
				continue;
			}
		}

		if ( ($template['Official']??false) && ! ($template['Blacklist']??false) )
			$statistics['official']++;

		if ( ! ($template['PluginURL']??false) && ! ($template['Repository']??false) )
			$statistics['invalidXML']++;
		else {
			if ( $template['PluginURL'] ?? false)
				$statistics['plugin']++;
			else {
				if ( $template['BranchID'] ?? false) {
					continue;
				} else {
					$statistics['docker']++;
				}
			}
		}
	}
	$statistics['totalApplications'] = $statistics['plugin']+$statistics['docker'];
	if ( $statistics['fixedTemplates'] )
		writeJsonFile(CA_PATHS['fixedTemplates_txt'],$statistics['fixedTemplates']);
	else
		@unlink(CA_PATHS['fixedTemplates_txt']);

	$appFeedTime = is_file(CA_PATHS['lastUpdated-old']) ? readJsonFile(CA_PATHS['lastUpdated-old']) : ['last_updated_timestamp' => 1];
	$updateTime = tr(date("F",$appFeedTime['last_updated_timestamp']),0).date(" d, Y @ g:i a",$appFeedTime['last_updated_timestamp']);
	$defaultArray = ['caFixed' => 0,'totalApplications' => 0, 'repository' => 0, 'docker' => 0, 'plugin' => 0, 'invalidXML' => 0, 'blacklist' => 0, 'totalIncompatible' =>0, 'totalDeprecated' => 0, 'totalModeration' => 0, 'private' => 0, 'NoSupport' => 0];

	$statistics = array_merge($defaultArray,$statistics);

	foreach ($statistics as &$stat) {
		if ( ! $stat ) $stat = "0";
	}

	$currentServer = @file_get_contents(CA_PATHS['currentServer']);
	if ( $currentServer != "Primary Server" )
		$currentServer = "<i class='fa fa-exclamation-triangle ca_serverWarning' aria-hidden='true'></i> $currentServer";

	$statistics['invalidXML'] = @count($invalidXML) ?: tr("unknown");
	$statistics['repositories'] = @count($repositories) ?: tr("unknown");
	$statistics['updateTime'] = $updateTime;
	$statistics['currentServer'] = tr($currentServer);

	/* Dev/admin-only diagnostic: include the requesting tab's session id
	   so the statistics popup can display it. Normal users get no tabId
	   field in the response and the row in the template stays hidden. */
	if (($GLOBALS['caSettings']['dev'] ?? "no") === "yes" && is_file(CA_PATHS['caAdmin'])) {
		$statistics['tabId'] = (string)($_POST['tabId'] ?? '');
	}

	postReturn(['statistics'=>$statistics]);
}

/**
 * Dev/admin-only diagnostic: fetch the three feed files (small, full,
 * statistics) from both the primary CA server and the GitHub backup,
 * compute SHA-256 of each body, and return per-file match results.
 *
 * Used by the bottom of the statistics popup (replaces the old
 * Primary/Backup server links) so a maintainer can spot-check whether
 * the two mirrors are in sync without leaving the GUI. Gated server-side
 * on `caSettings['dev']` and the on-disk `caAdmin` marker so the action
 * is a no-op for normal users.
 *
 * Returns:
 *   { enabled: bool,
 *     results: {
 *       small:      { primary: <sha|null>, backup: <sha|null>, match: bool },
 *       full:       { ... },
 *       statistics: { ... }
 *     }
 *   }
 * `null` sha values indicate the fetch failed for that URL.
 *
 * @return void
 */
function caCompareFeedShas() {
	if (($GLOBALS['caSettings']['dev'] ?? "no") !== "yes" || !is_file(CA_PATHS['caAdmin'])) {
		postReturn(['enabled' => false]);
		return;
	}

	$sources = [
		'small'      => ['primary' => CA_PATHS['application-feed-small'], 'backup' => CA_PATHS['application-feed-smallBackup']],
		'full'       => ['primary' => CA_PATHS['application-feed'],       'backup' => CA_PATHS['application-feedBackup']],
		'statistics' => ['primary' => CA_PATHS['statisticsURL'],          'backup' => CA_PATHS['statisticsURLBackup']],
	];

	$results = [];
	foreach ($sources as $name => $urls) {
		$primaryBody = download_url($urls['primary']);
		$backupBody  = download_url($urls['backup']);
		$primarySha  = (is_string($primaryBody) && strlen($primaryBody)) ? hash('sha256', $primaryBody) : null;
		$backupSha   = (is_string($backupBody)  && strlen($backupBody))  ? hash('sha256', $backupBody)  : null;
		$results[$name] = [
			'primary' => $primarySha,
			'backup'  => $backupSha,
			'match'   => ($primarySha !== null && $backupSha !== null && $primarySha === $backupSha),
		];
	}

	postReturn(['enabled' => true, 'results' => $results]);
}

/**
 * Build and return the autocomplete suggestion list for the search box.
 *
 * Waits for templates to be available, seeds with category names, walks each
 * template through PopulateAutoCompleteHelpers, and returns via postReturn().
 *
 * @return void
 */
function populateAutoComplete() {

	require_once __DIR__ . '/populate_autocomplete_helpers.php';

	$templates = PopulateAutoCompleteHelpers::waitForTemplates();
	$autoComplete = PopulateAutoCompleteHelpers::buildBaseSuggestions();
	$autoComplete = PopulateAutoCompleteHelpers::addTemplateSuggestions($templates, $autoComplete);
	$autoComplete[tr("language")] = tr("Language");

	postReturn(['autocomplete'=>PopulateAutoCompleteHelpers::finalizeSuggestions($autoComplete)]);
}

/**
 * Return the rendered CA plugin changelog (HTML markdown) for the About dialog.
 *
 * @return void
 */
function caChangeLog() {
	/* Doesn't go through caRequireSkin (no rendered HTML), so pull Markdown.php
	   in locally. require_once dedupes by path so this is a no-op if any other
	   handler in the same request already loaded it. */
	global $docroot;
	require_once "$docroot/webGui/include/Markdown.php";
	postReturn(["changelog"=>Markdown(ca_plugin("changes","/var/log/plugins/community.applications.plg"))."<br><br>"]);
}

/**
 * Render the category-menu <ul> markup for the sidebar.
 *
 * Reads CA_PATHS['categoryList'], translates descriptions, sorts alphabetically
 * by the translated string, builds nested <ul> for sub-categories, and appends
 * a "PRIVATE" entry when any private template is present. Calls postReturn().
 *
 * @return void
 */
function get_categories() {
	global $sortOrder;

	$categories = readJsonFile(CA_PATHS['categoryList']);
	if ( ! is_array($categories) || empty($categories) ) {
		$cat = "<ul><li>Category list N/A</li></ul>";
		postReturn(['categories'=>$cat]);
		return;
	} else {
		$categories[] = ["Des"=>"Language","Cat"=>"Language:"];

		foreach ($categories as $category) {
			$category['Des'] = tr($category['Des']);
			if ( isset($category['Sub']) && is_array($category['Sub']) ) {
				unset($subCat);
				foreach ($category['Sub'] as $subcategory) {
					$subcategory['Des'] = tr($subcategory['Des']);
					$subCat[] = $subcategory;
				}
				$category['Sub'] = $subCat;
			}
			$newCat[] = $category;
		}
		$sortOrder['sortBy'] = "Des";
		$sortOrder['sortDir'] = "Up";
		usort($newCat,"mySort"); // Sort it alphabetically according to the language.  May not work right in non-roman charsets

		$cat = "<ul class='caMenu'>";
		foreach ($newCat as $category) {
			$cat .= "<li class='categoryMenu caMenuItem nonDockerSearch' data-category='{$category['Cat']}'>".$category['Des']."</li>";
			if (isset($category['Sub']) && is_array($category['Sub'])) {
				/* Same nested <li class='subCategory'><ul><li>...</li></ul></li>
				   shape used in skin.html for Installed Apps / Previous Apps,
				   so the .subCategory bullet-suppression CSS and the
				   closest('.subCategory') click-handler logic both apply. */
				$cat .= "<li class='subCategory'><ul>";
				/* Auto-generated "All" entry at the top: shares the parent's
				   data-category so it fetches everything under the parent. The
				   parent itself no longer fetches on click — it only toggles the
				   sub menu. caCategoryAll marks it so cookie-restore prefers it
				   over the parent when both match the same data-category. */
				$cat .= "<li class='categoryMenu caMenuItem nonDockerSearch caCategoryAll' data-category='{$category['Cat']}'>".tr("All")."</li>";
				foreach($category['Sub'] as $subcategory) {
					$cat .= "<li class='categoryMenu caMenuItem nonDockerSearch' data-category='{$subcategory['Cat']}'>".$subcategory['Des']."</li>";
				}
				$cat .= "</ul></li>";
			}
		}
		$templates = &$GLOBALS['templates'];
		foreach ($templates as $template) {
			if ( ($template['Private']??null) == true && ! $template['Blacklist']) {
				$cat .= "<li class='categoryMenu caMenuItem nonDockerSearch' data-category='PRIVATE'>".tr("Private Apps")."</li>";
				break;
			}
		}
	}
	postReturn(["categories"=>$cat]);
}

/**
 * Return the rendered HTML for the per-app description popup.
 *
 * @return void
 */
function getPopupDescription() {
	$appNumber = getPost("appPath","");
	postReturn(getPopupDescriptionSkin($appNumber));
}

/**
 * Return the rendered HTML for the per-repository description popup.
 *
 * @return void
 */
function getRepoDescription() {
	$repository = html_entity_decode(getPost("repository",""),ENT_QUOTES);
	postReturn(getRepoDescriptionSkin($repository));
}

/**
 * Dev-mode Plugin/Template button backend: fetch a URL and return its bytes as
 * JSON for the diff modal overlay (Template = single column raw; Plugin =
 * two-column raw vs. entity-decoded so the dev can see exactly what the .plg
 * looks like after &name; / &version; / &MD5; etc. are substituted).
 *
 * Goes through caFetchCachedSource so the modal shares one on-disk copy with
 * the sidebar's Changes loader (templates-community), and gates on
 * caIsPublicHttpUrl so a stale template URL can't be coerced into an internal
 * SSRF target. When the `decode` POST flag is set, also returns a parsed
 * entity table in the `decoded` field — empty when the document is unparseable.
 */
function getDevRawURL() {
	if (($GLOBALS['caSettings']['dev'] ?? null) !== "yes") {
		postReturn(["ok"=>false, "message"=>tr("Dev mode is not enabled")]);
		return;
	}
	$url = trim((string)getPost("url", ""));
	if ($url === "") {
		postReturn(["ok"=>false, "message"=>tr("Missing URL")]);
		return;
	}
	if (!caIsPublicHttpUrl($url)) {
		postReturn(["ok"=>false, "message"=>tr("URL is not allowed")]);
		return;
	}
	/* Share the templates-community cache with the sidebar Changes loader so
	   opening the modal right after the sidebar populates is a disk hit, and
	   re-opening the modal for the same app skips the network entirely. */
	$cacheName = caCacheKeyForUrl($url);
	$content = $cacheName !== ""
		? caFetchCachedSource($url, $cacheName)
		: download_url($url, "", 30);
	if (!is_string($content) || $content === "" || trim($content) === "") {
		postReturn(["ok"=>false, "message"=>tr("Could not download")." ".$url]);
		return;
	}
	$response = ["ok"=>true, "content"=>$content];
	if (((string)getPost("decode", "")) === "1") {
		/* Hand the parsed entity table to the client (rather than a pre-decoded
		   string) so the JS can highlight each substitution site as it walks
		   the raw text — the decoded column is built character-by-character
		   with the replacements wrapped in <span class='ca_entitySub'>. */
		$response['entities'] = caExtractXmlEntities($content);
	}
	postReturn($response);
}

/**
 * Normalize a template's docker Repository field to canonical owner/name:tag
 * form so cross-repo comparison treats `nginx`, `library/nginx`, `_/nginx`,
 * `library/nginx:latest`, `ghcr.io/linuxserver/nginx`, and
 * `lscr.io/linuxserver/nginx:latest` consistently. Rules:
 *
 *   - Leading registry hostname is dropped — first path segment is treated
 *     as a registry when it contains `.` or `:` (port) or is exactly
 *     `localhost`. So `ghcr.io/owner/c` collapses to `owner/c`, which then
 *     matches the Docker Hub form `owner/c`. Same-registry cross-maintainer
 *     pairs still match each other because both sides shed the same prefix.
 *   - `_/foo` and bare `foo` (no slash) both become `library/foo`.
 *   - Missing tag → `:latest`. Tag detection looks at the colon position
 *     relative to the last slash so a `host:port/` prefix (when the registry
 *     strip above declines to fire) doesn't get its port misread as a tag.
 *   - Lowercased throughout — Docker reference grammar is case-insensitive
 *     for hostnames and namespace/name segments.
 *
 * @param  mixed  $repo Raw `Repository` field.
 * @return string Canonical key, or "" when the input is empty.
 */
function caNormalizeDockerRepo($repo) {
	$repo = strtolower(trim((string)$repo));
	if ($repo === "") return "";

	/* Strip registry hostname (ghcr.io/, lscr.io/, quay.io/,
	   registry.hub.docker.com/, localhost:5000/, …) so the path beyond it can
	   match the Docker Hub form. Detection follows Docker's reference grammar:
	   a leading segment qualifies as a hostname when it carries a dot, a port
	   colon, or is exactly `localhost`. */
	$firstSlash = strpos($repo, "/");
	if ($firstSlash !== false) {
		$first = substr($repo, 0, $firstSlash);
		if ($first === "localhost" || strpos($first, ".") !== false || strpos($first, ":") !== false) {
			$repo = substr($repo, $firstSlash + 1);
		}
	}

	if (strpos($repo, "_/") === 0) {
		$repo = "library/" . substr($repo, 2);
	} elseif (strpos($repo, "/") === false) {
		$repo = "library/" . $repo;
	}

	$slashPos = strrpos($repo, "/");
	$afterSlash = $slashPos === false ? $repo : substr($repo, $slashPos + 1);
	if (strpos($afterSlash, ":") === false) {
		$repo .= ":latest";
	}
	return $repo;
}

/**
 * Find duplicate-Repository templates, optionally scoped to a single maintainer.
 *
 * Groups every non-repo template by canonical docker Repository (see
 * caNormalizeDockerRepo) and emits all members of any group with ≥2 entries.
 *
 *   - `$repository === null` → return every duplicate across the whole feed.
 *   - `$repository === "Foo"` → return every duplicate group that has at
 *     least one member with `RepoName === "Foo"`, and emit *all* members of
 *     those groups (so both the clicked repo's entry and any cross-repo
 *     copies are visible side by side).
 *
 * Entries are deduped on Path|RepoName|Name (the closest thing to a stable
 * identity in $GLOBALS['templates']) and sorted with mySort.
 *
 * @param  array<int,array<string,mixed>> $templates  $GLOBALS['templates'] or equivalent.
 * @param  string|null                    $repository Maintainer filter, or null for all repos.
 * @return array<int,array<string,mixed>>             Templates to display.
 */
function caFindDuplicateTemplates(array $templates, $repository = null) {
	$repoIndex = [];
	foreach ($templates as $template) {
		if (!empty($template['RepositoryTemplate'])) continue;
		/* Language packs have no docker Repository — without this guard they'd
		   all normalize to the same `library/:latest` key and collide as a
		   single giant phantom duplicate group. */
		if (!empty($template['Language'])) continue;
		/* Branch templates are per-tag expansions of a lead (the parser at the
		   top of exec.php emits them with BranchName set and Displayable=false).
		   They share the lead's RepoName but differ only in tag, so they'd
		   otherwise self-cluster against their own lead as fake duplicates.
		   Only the lead — which carries BranchID and no BranchName — counts. */
		if (!empty($template['BranchName'])) continue;
		$key = caNormalizeDockerRepo($template['Repository'] ?? "");
		if ($key === "") continue;
		$repoIndex[$key][] = $template;
	}

	$duplicates = [];
	$seen = [];
	foreach ($repoIndex as $entries) {
		if (count($entries) < 2) continue;

		if ($repository !== null) {
			/* Per-maintainer view: keep the group only when this maintainer
			   actually publishes the image — otherwise the user clicked a
			   repo that has nothing to do with this collision. */
			$touchesRepo = false;
			foreach ($entries as $entry) {
				if (($entry['RepoName'] ?? "") === $repository) { $touchesRepo = true; break; }
			}
			if (!$touchesRepo) continue;
		}

		foreach ($entries as $entry) {
			$dedupeKey = ($entry['Path'] ?? '').'|'.($entry['RepoName'] ?? '').'|'.($entry['Name'] ?? '');
			if (isset($seen[$dedupeKey])) continue;
			$seen[$dedupeKey] = true;
			$duplicates[] = $entry;
		}
	}

	usort($duplicates, "mySort");
	return $duplicates;
}

/**
 * Dev + admin only: render the duplicate-Repository templates touching a repo.
 *
 * Thin wrapper around caFindDuplicateTemplates() that scopes to the clicked
 * maintainer and pipes the result through the same display_apps() pipeline
 * that get_content uses, so the JS side hands the response straight to
 * updateDisplay(). The all-repos variant is reached through the "Duplicates"
 * menu item, which routes through get_content (action=duplicates) so it
 * participates in normal category navigation and state restore.
 *
 * @return void
 */
function getRepoDuplicates() {
	if (($GLOBALS['caSettings']['dev'] ?? null) !== "yes" || !is_file(CA_PATHS['caAdmin'])) {
		postReturn(["error"=>tr("Not authorized")]);
		return;
	}

	$repository = html_entity_decode(getPost("repository", ""), ENT_QUOTES);
	if ($repository === "") {
		postReturn(["error"=>tr("Missing repository")]);
		return;
	}

	$templates = &$GLOBALS['templates'];
	if (empty($templates)) {
		postReturn(["display_data"=>["header"=>"<div class='ca_NoAppsFound'>".tr("No Matching Applications Found")."</div>", "cards"=>[], "scripts"=>"", "totalApps"=>0, "pageNumber"=>1]]);
		return;
	}

	$duplicates = caFindDuplicateTemplates($templates, $repository);
	$displayApplications = ['community' => $duplicates];

	@unlink(CA_PATHS['repositoriesDisplayed']);
	@unlink(CA_PATHS['dockerSearchActive']);
	@unlink(CA_PATHS['startupDisplayed']);
	writeJsonFile(CA_PATHS['community-templates-displayed'], $displayApplications);
	@unlink(CA_PATHS['community-templates-allSearchResults']);
	@unlink(CA_PATHS['community-templates-catSearchResults']);

	postReturn(['display_data' => display_apps(1, false, false, true)]);
}


/**
 * Spawn a per-container live-stats nchan publisher on demand.
 *
 * One publisher process per container being watched. The sidebar passes
 * its container name; if a script is already running with that exact
 * container as its first argument (pgrep --ns $$ -f matches the full
 * command line including args), this call is a no-op. Otherwise the
 * script gets spawned with the container as argv[1] and starts publishing
 * to nchan channel `stats_<containerName>`.
 *
 * Why one-per-container vs. one-shared-for-all: Docker's stats API is
 * per-container with no bulk endpoint, so a single shared publisher
 * walking every running container scales linearly with the host's total
 * container count regardless of how many viewers there are. A per-
 * container publisher does ONE Docker call per tick and only runs when
 * someone's actually watching that container — the cost scales with
 * viewers (the right axis), not with what's installed.
 *
 * The pidfile and self-termination conventions are the same as the
 * shared model: publish() with abort=true strips the script back out
 * of /var/run/nchan.pid and exit()s ~10s after the last subscriber drops.
 *
 * @return void
 */
function startLiveStatsPublisher() {
	/* Server-side feature gate. Mirrors the same two checks
	   getPopupDescriptionSkin() uses to decide whether to render the
	   live-stats block in the sidebar HTML:
	     - User has flipped the "Display usage graphs" setting on.
	     - Unraid OS is responsive (>7.1.9999 by version_compare).
	   Without this gate, a hand-crafted POST could spawn a publisher
	   process even when the feature is disabled in Settings (or on an
	   OS that doesn't render the sidebar block at all). Fail fast
	   before any pgrep/exec runs. */
	$usageGraphsEnabled  = ($GLOBALS['caSettings']['displayUsageGraphs'] ?? "no") === "yes";
	$liveStatsResponsive = version_compare((string)($GLOBALS['caSettings']['unRaidVersion'] ?? "0"), "7.1.9999", ">");
	if (!$usageGraphsEnabled || !$liveStatsResponsive) {
		postReturn(['ok' => false, 'error' => 'live stats disabled']);
		return;
	}

	$containerName = trim((string)getPost("container", ""));
	if ($containerName === "" || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,254}$/', $containerName)) {
		postReturn(['ok' => false, 'error' => 'invalid container name']);
		return;
	}

	$docroot   = $_SERVER['DOCUMENT_ROOT'] ?? '/usr/local/emhttp';
	$scriptAbs = "$docroot/plugins/community.applications/scripts/caLiveStats.php";

	if (!is_file($scriptAbs)) {
		postReturn(['ok' => false, 'error' => 'publisher not installed']);
		return;
	}

	/* Dedupe per container: pgrep -f matches the full command line, so the
	   pattern "<absPath> <name>" hits only the publisher for THIS container.
	   Two sidebars on different containers spawn two scripts; two sidebars
	   on the same container share one. */
	$pids = [];
	$pgrepPattern = $scriptAbs . ' ' . $containerName;
	@exec('pgrep --ns $$ -f ' . escapeshellarg($pgrepPattern), $pids, $rv);
	if ($rv === 0 && !empty($pids)) {
		postReturn(['ok' => true, 'status' => 'already_running', 'pid' => (int)$pids[0]]);
		return;
	}

	/* Deliberately NOT touching /var/run/nchan.pid. That registry is for
	   .page files that declare Nchan=… so DefaultPageLayout.php can keep
	   their publisher always-on (auto-respawn at every page render). Our
	   publishers are on-demand instead: spawned by this endpoint only,
	   terminated by publish()'s abort path once nobody's watching. Adding
	   ourselves there would invite the auto-respawn loop to re-launch us
	   without our argv, and removeNChanScript() — keyed by script path,
	   not by argv — would tangle multiple per-container publishers
	   together at cleanup. The pidfile mechanism just doesn't model our
	   "spawn-per-viewer" lifecycle. */
	@exec(escapeshellarg($scriptAbs) . ' ' . escapeshellarg($containerName) . ' >/dev/null 2>&1 &');

	postReturn(['ok' => true, 'status' => 'started']);
}


/**
 * Sidebar README/Changes loader backend.
 *
 * The browser used to fetch README.md / .plg / template XML directly from
 * raw.githubusercontent.com etc. via fetch(). Repo hosts that don't set
 * Access-Control-Allow-Origin (anything other than raw.github.* basically)
 * broke under CORS. Routing the fetch through PHP avoids that — and reusing
 * caFetchCachedSource means concurrent sidebar opens dedupe through its
 * per-URL flock and repeat opens skip the network on a disk hit.
 *
 * Inputs (POST):
 *   url       Absolute https URL to fetch
 *   repoName  RepoName from the template entry (drives the cache file name)
 *   appName   Template Name (folded into the readme cache file name so two
 *             templates from the same repo don't share one README cache file)
 *   kind      "readme" or "changes"
 *
 * Cache file naming (under CA_PATHS['templates-community']):
 *   readme  → {alphaNumeric(repoName . appName)}-README.md
 *   changes → {alphaNumeric(repoName)}-{sanitisedBasename(url)}   (preserves .plg / .xml)
 *
 * Response: { ok: true, content: "<file contents>" }
 *           { ok: false, message: "<reason>" }
 *
 * @return void
 */
function caFetchSidebarSource() {
	$url      = trim((string)getPost("url", ""));
	$repoName = trim((string)getPost("repoName", ""));
	$appName  = trim((string)getPost("appName", ""));
	$kind     = (string)getPost("kind", "");

	if ($url === "" || !caIsPublicHttpUrl($url)) {
		postReturn(["ok"=>false, "message"=>tr("URL is not allowed")]);
		return;
	}
	if (!in_array($kind, ["readme", "changes"], true)) {
		postReturn(["ok"=>false, "message"=>tr("Unknown source kind")]);
		return;
	}

	$safeRepo = alphaNumeric($repoName);
	if ($safeRepo === "") {
		/* No usable RepoName — fall back to a URL hash so we still cache rather
		   than re-fetching every open. Keeps behaviour graceful if a future
		   feed entry forgets RepoName. */
		$safeRepo = substr(hash("sha256", $url), 0, 16);
	}

	if ($kind === "readme") {
		/* Fold the app name into the cache key so two templates from the
		   same repo (each pointing at its own README) don't share one cache
		   file and serve each other's body. alphaNumeric() strips spaces
		   and punctuation, leaving a filesystem-safe slug. */
		$cacheName = alphaNumeric($safeRepo . $appName) . "-README.md";
	} else {
		$path = (string)parse_url($url, PHP_URL_PATH);
		$base = basename($path);
		/* Allow letters, digits, dot, dash, underscore; everything else (incl.
		   slashes, query bits that slipped through parse_url, traversal dots)
		   collapses to underscore. caFetchCachedSource also rejects "/", "\\"
		   and ".." — this is the first line of defense. */
		$base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
		if ($base === "" || $base === "." || $base === "..") {
			$base = "source";
		}
		/* Short URL hash between repo name and basename so distinct source
		   URLs that happen to share a basename (e.g. two branches of the
		   same .plg in the same repo, or any two templates named the same
		   way under different paths) don't collide on disk and return
		   each other's bodies. 8 hex chars = 32 bits; vanishingly small
		   collision risk for the handful of templates per repo. */
		$urlHash = substr(hash("sha256", $url), 0, 8);
		$cacheName = $safeRepo . "-" . $urlHash . "-" . $base;
	}

	// No spaces in the on-disk filename — annoying to handle at the console.
	$cacheName = str_replace(' ', '', $cacheName);

	$content = caFetchCachedSource($url, $cacheName);
	if ($content === "") {
		postReturn(["ok"=>false, "message"=>tr("Could not download")." ".htmlspecialchars($url, ENT_QUOTES)]);
		return;
	}
	postReturn(["ok"=>true, "content"=>$content]);
}

/**
 * Pull `<!ENTITY name "value">` declarations out of a .plg's DOCTYPE block and
 * return them as a name=>value array. Plugin XML uses simple internal entities
 * (name, version, MD5, the download URL, etc.) referenced as &name; throughout
 * the body — that's everything we need for the decoded-side highlight view.
 *
 * Regex-based on purpose: DOMDocument with LIBXML_NOENT would normalize the
 * output (whitespace, attribute quoting) and we'd lose the line-for-line
 * alignment with the raw column. Cross-references between entities (e.g.,
 * `<!ENTITY full "&name;-&version;">`) are resolved with up to 5 passes so the
 * value handed to the JS is already fully expanded.
 *
 * @return array<string,string>
 */
function caExtractXmlEntities(string $xml): array {
	$entities = [];
	if (!preg_match('/<!DOCTYPE[^\[]*\[(.*?)\]\s*>/s', $xml, $dtMatch)) {
		return $entities;
	}
	$dtd = $dtMatch[1];
	if (!preg_match_all('/<!ENTITY\s+([A-Za-z_][\w.-]*)\s+(?:"([^"]*)"|\'([^\']*)\')\s*>/s', $dtd, $matches, PREG_SET_ORDER)) {
		return $entities;
	}
	$predef = ['&amp;'=>'&', '&lt;'=>'<', '&gt;'=>'>', '&quot;'=>'"', '&apos;'=>"'"];
	foreach ($matches as $m) {
		$name = $m[1];
		$value = ($m[2] !== '' ? $m[2] : ($m[3] ?? ''));
		$entities[$name] = strtr($value, $predef);
	}
	/* Resolve entity-in-entity references with a small fixed-point loop. Five
	   passes covers any realistic .plg; the loop bails early when nothing
	   changed. Self-references and unresolved names are left as-is. */
	for ($pass = 0; $pass < 5; $pass++) {
		$changed = false;
		foreach ($entities as $name => $value) {
			$expanded = preg_replace_callback('/&([A-Za-z_][\w.-]*);/', function($m) use ($entities, $name) {
				if ($m[1] === $name) return $m[0];
				return $entities[$m[1]] ?? $m[0];
			}, $value);
			if ($expanded !== $value) {
				$entities[$name] = $expanded;
				$changed = true;
			}
		}
		if (!$changed) break;
	}
	return $entities;
}

/**
 * Build the dockerMan install XML for a container template and write it to disk.
 *
 * Reads POST xml (template path) and type ("second" for rename flow), looks
 * the template up in $GLOBALS['templates'], normalizes networking/paths/ports/
 * tailscale-state-dir, optionally auto-adjusts conflicting host ports, and
 * writes the rendered XML to the chosen install path. Calls postReturn().
 *
 * @return void
 */
function createXML() {

	/* Slim-feed path leaves the full cache absent — only the slim-feed
	   download wrote the small cache; the full-cache write is deferred
	   to hydrateFullFeedWork (kicked off by JS) or the fallback full-feed
	   branch of force_update. If a fast-clicker hits Install before the
	   background hydrate completes, run hydration synchronously here so
	   adjustTemplatePorts() and the rest of the install pipeline have
	   Config + Network. The tempFiles directory is wiped on every feed
	   refresh, so file existence is a sufficient freshness signal. */
	if ( ! is_file(CA_PATHS['community-templates-info-full']) ) {
		hydrateFullFeedWork();
	}

	getFullGlobals();

	$dockerSettings = parse_ini_file(CA_PATHS['dockerSettings']);
	$xmlFile = getPost("xml","");
	$type = getPost("type","");
	if ( ! $xmlFile ) {
		postReturn(["error"=>"CreateXML: XML file was missing"]);
		return;
	}
	$templates = &$GLOBALS['templates'];
	if ( ! $templates ) {
		postReturn(["error"=>"Create XML: templates file missing or empty"]);
		return;
	}
	if ( !startsWith($xmlFile,"/boot/") ) {
		$index = searchArray($templates,"Path",$xmlFile);
		if ( $index === false ) {
			postReturn(["error"=>"Create XML: couldn't find template with path of $xmlFile"]);
			return;
		}
		$template = $templates[$index];

		download_url(CA_PATHS['RepositoryAssets'].str_replace("/","___",explode(":",$template['Repository'])[0]));

		if ( $template['OriginalOverview'] ?? false )
			$template['Overview'] = $template['OriginalOverview'];
		if ( $template['OriginalDescription'] ?? false )
			$template['Description'] = $template['OriginalDescription'];

		$template['Icon'] = $template["Icon-{$GLOBALS['caSettings']['dynamixTheme']}"] ?? ($template['Icon'] ?? "");

// switch from br0 to eth0 if necessary
		if ( isset($template['Networking']['Mode']) || isset($template['Network']) ) {
			$mode =$template['Network'] = $template['Network'] ?? $template['Networking']['Mode'];
			$mode = strtolower($template['Network']);
			if ( $mode && $mode !== "host" && $mode !== "bridge" ) {
				if ( ! file_exists("/sys/class/net/$mode") ) {
					$template['Network'] = file_exists('/sys/class/net/br0') ? 'br0' : 'eth0';
					unset($template['Networking']['Mode']);
				}
			}
		}
// Handle paths directly referencing disks / poola that aren't present in the user's system, and replace the path with the first disk present
		$unRaidDisks = parse_ini_file(CA_PATHS['disksINI'],true);

		$disksPresent = array_keys(array_filter($unRaidDisks, function($k) {
			return ($k['status'] !== "DISK_NP" && ! preg_match("/(parity|parity2|disks|diskP|diskQ)/",$k['name']));
		}));

		$cachePools = array_filter($unRaidDisks, function($k) {
			return ! preg_match("/disk\d(\d|$)|(parity|parity2|disks|flash|diskP|diskQ)/",$k['name']);
		});
		$cachePools = array_keys(array_filter($cachePools, function($k) {
			return $k['status'] !== "DISK_NP";
		}));

		// always prefer the default cache pool
		if ( in_array("cache",$cachePools) )
			array_unshift($cachePools,"cache"); // This will be a duplicate, but it doesn't matter as we only reference item0

		// Prefer cache pools over disks
		$disksPresent = array_merge($cachePools,$disksPresent,["disks"]);

		// check to see if user shares enabled
		$unRaidVars = parse_ini_file(CA_PATHS['unRaidVars']);
		if ( $unRaidVars['shareUser'] == "e" )
			$disksPresent[] = "user";
		if ( @is_array($template['Data']['Volume']) ) {
			$testarray = $template['Data']['Volume'];
			if ( ( ! isset($testarray[0]) ) || ( ! is_array($testarray[0]) ) ) $testarray = [$testarray];
			foreach ($testarray as &$volume) {
				if ( ! ($volume['HostDir'] ?? false) )
					continue;

				$diskReferenced = array_values(array_filter(explode("/",$volume['HostDir'])));
				if ( $diskReferenced[0] == "mnt" && $diskReferenced[1] && ! in_array($diskReferenced[1],$disksPresent) ) {
					$volume['HostDir'] = str_replace("/mnt/{$diskReferenced[1]}/","/mnt/{$disksPresent[0]}/",$volume['HostDir']);
				}
			}
			$template['Data']['Volume'] = $testarray;
		}

		$foundTSDir = false;
		if ( $template['Config'] ?? false ) {
			$testarray = $template['Config'] ?: [];
			if (!($testarray[0]??false)) $testarray = [$testarray];

			foreach ($testarray as &$config) {
				if ( is_array($config['@attributes']) ) {
					if ( $config['@attributes']['Type'] == "Path" ) {
						// handles where a container path is effectively a config path but it doesn't begin with /config
						if ( startsWith($config['value'],CA_PATHS['defaultAppdataPath']) || startsWith($config['@attributes']['Default'],CA_PATHS['defaultAppdataPath']) ) {
							if ( ! in_array($config['@attributes']['Target'],["/config","/data"]) ) {
								if ( ! ($TSFallBackDir ?? false) ) {
									$TSFallBackDir  = $config['@attributes']['Target'] ?? "";
								}
							} else {
								$foundTSDir = true;
								$TSFallBackDir = "";
							}
						}
						$config['value'] = str_replace(CA_PATHS['defaultAppdataPath'],$dockerSettings['DOCKER_APP_CONFIG_PATH'],$config['value']);
						$config['@attributes']['Default'] = str_replace(CA_PATHS['defaultAppdataPath'],$dockerSettings['DOCKER_APP_CONFIG_PATH'],$config['@attributes']['Default']);
						$defaultReferenced = array_values(array_filter(explode("/",$config['@attributes']['Default'])));

						if ( isset($defaultReferenced[0]) && isset($defaultReferenced[1]) ) {
							if ( $defaultReferenced[0] == "mnt" && $defaultReferenced[1] && ! in_array($defaultReferenced[1],$disksPresent) )
								$config['@attributes']['Default'] = str_replace("/mnt/{$defaultReferenced[1]}/","/mnt/{$disksPresent[0]}/",$config['@attributes']['Default']);
							}

						$valueReferenced = array_values(array_filter(explode("/",$config['value'])));
						if ( isset($valueReferenced[0]) && isset($valueReferenced[1]) ) {
							if ( $valueReferenced[0] == "mnt" && $valueReferenced[1] && ! in_array($valueReferenced[1],$disksPresent) )
								$config['value'] = str_replace("/mnt/{$valueReferenced[1]}/","/mnt/{$disksPresent[0]}/",$config['value']);
						}

						// Check for pre-existing folders only differing by "case" and adjust accordingly

						// Default path
						if ( ! $config['value'] ) { // Don't override default if value exists
							$configPath = explode("/",$config['@attributes']['Default']);
							$testPath = "/";
							foreach ($configPath as &$entry) {
								$directories = @scandir($testPath);
								if ( ! $directories ) {
									break;
								}
								foreach ($directories as $testDir) {
									if ( strtolower($testDir) == strtolower($entry) ) {
										if ( $testDir == $entry )
											break;

										$entry = $testDir;
									}
								}
								$testPath .= $entry."/";
							}
							$config['@attributes']['Default'] = implode("/",$configPath);
						}

						// entered path
						if ( $config['value'] ) {
							$configPath = explode("/",$config['value']);
							$testPath = "/";
							foreach ($configPath as &$entry) {
								$directories = @scandir($testPath);
								if ( ! $directories ) {
									break;
								}
								foreach ($directories as $testDir) {
									if ( strtolower($testDir) == strtolower($entry) ) {
										if ( $testDir == $entry )
											break;

										$entry = $testDir;
									}
								}
								$testPath .= $entry."/";
							}
							$config['value'] = implode("/",$configPath);
						}
					}
				}
			}
		}
		$template['Name'] = str_replace(" ","-",$template['Name']);
		$alreadyInstalled = getAllInfo();
		foreach ( $alreadyInstalled as $installed ) {
			if ( strtolower($template['Name']) == $installed['Name'] ) {
				for ( ;; ) {
					if (is_file(CA_PATHS['dockerManTemplates']."/my-{$template['Name']}.xml") ) {
						$template['Name'] .= "-1";
					} else break;
				}
			}
		}
		for ( ;; ) {
			if ($type == "second" && is_file(CA_PATHS['dockerManTemplates']."/my-{$template['Name']}.xml") ) {
				$template['Name'] .= "-1";
			} else break;
		}

		if ( empty($template['Config']) ) // handles extra garbage entry being created on templates that are v1 only
			unset($template['Config']);

		// Add in TSStateDir

		if ( version_compare($GLOBALS['caSettings']['unRaidVersion'],"7.0.0",">") && isTailScaleInstalled() ) {
			if ( isset($template['Config']) && (! $foundTSDir) && ($TSFallBackDir ?? false) ) {
				$template['Config'][] = ["@attributes"=>["Display"=>"advanced","Description"=>"Fallback container directory for tailscale state information - Added By Community Applications","Default"=>$TSFallBackDir,"Name"=>"TailScale Fallback State Directory","Target"=>"CA_TS_FALLBACK_DIR","Type"=>"Variable"],"value"=>$TSFallBackDir];
			}
		}

		// Auto-adjust conflicting host ports when the client opted in via the
		// "Adjust automatically?" prompt during install.
		if ( filter_var(getPost("adjustPorts", false), FILTER_VALIDATE_BOOLEAN) ) {
			adjustTemplatePorts($template, getPortsInUse());
		}

		$xml = makeXML($template);
		@mkdir(dirname($xmlFile),0777,true);
		ca_file_put_contents($xmlFile,$xml);
	}
	postReturn(["status"=>"ok","cache"=>$cacheVolume ?? ""]);
}

/**
 * Apply a language-pack selection to dynamix.cfg (display.locale).
 *
 * Reads POST['language']; "en_US" is normalized to "" (default). Errors when
 * the language pack isn't installed.
 *
 * @return void
 */
function switchLanguage() {

	$language = getPost("language","");
	if ( $language == "en_US" )
		$language = "";

	if ( ! is_dir("/usr/local/emhttp/languages/$language") )  {
		postReturn(["error"=>"language $language is not installed"]);
		return;
	}
	$dynamixSettings = @parse_ini_file(CA_PATHS['dynamixSettings'],true);
	$dynamixSettings['display']['locale'] = $language;
	write_ini_file(CA_PATHS['dynamixSettings'],$dynamixSettings);
	postReturn(["status"=> "ok"]);
}

/**
 * Delete multiple selected entries from Previous Apps in one request.
 *
 * Reads POST['apps'] (array of paths). Each path is realpath-checked to ensure
 * it lives under /boot/config/ before being unlinked. Calls postReturn().
 *
 * @return void
 */
function remove_multiApplications() {
	$apps = getPostArray("apps");
	if ( ! count($apps) ) {
		postReturn(["error"=>"No apps were in post when trying to remove multiple applications"]);
		return;
	}
	$error = "";
	foreach ($apps as $app) {
		if ( strpos(realpath($app),"/boot/config/") === false ) {
			$error = "Remove multiple apps: $app was not in /boot/config";
			break;
		}
		@unlink($app);
	}
	if ( $error )
		postReturn(["error"=>$error]);
	else
		postReturn(["status"=>"ok"]);
}

/**
 * Return the unique set of categories present in the current displayed/search cache.
 *
 * @return void
 */
function getCategoriesPresent() {

	if ( is_file(CA_PATHS['community-templates-allSearchResults']) )
		$displayed = readJsonFile(CA_PATHS['community-templates-allSearchResults']);
	else
		$displayed = readJsonFile(CA_PATHS['community-templates-displayed']);

	$categories = [];
	foreach ($displayed['community'] as $template) {
		$cats = explode(" ",$template['Category']);
		foreach ($cats as $category) {
			if (strpos($category,":")) {
				$categories[] = explode(":",$category)[0].":";
			}
			$categories[] = $category;
		}
	}
	if (! empty($categories) ) {
		$categories[] = "repos";
		$categories[] = "All";
		/* Keep the dev+admin "Duplicates" menu item enabled alongside
		   Repositories. The item is only rendered into the DOM when the
		   same gate passes server-side, so listing the category here
		   doesn't leak its existence to non-admin sessions. */
		if (($GLOBALS['caSettings']['dev'] ?? null) === "yes" && is_file(CA_PATHS['caAdmin'])) {
			$categories[] = "duplicates";
		}
	}

	postReturn(array_values(array_unique($categories)));
}

/**
 * Toggle the saved favourite repository on/off.
 *
 * Reads POST['repository']; clears the favourite when the same value is set.
 * Persists to CA_PATHS['pluginSettings'] and calls postReturn().
 *
 * @return void
 */
function toggleFavourite() {

	$repository = html_entity_decode(getPost("repository",""),ENT_QUOTES);
	if ( $GLOBALS['caSettings']['favourite'] == $repository )
		$repository = "";

	$GLOBALS['caSettings']['favourite'] = $repository;
	write_ini_file(CA_PATHS['pluginSettings'],$GLOBALS['caSettings']);
	postReturn(['status'=>"ok",'fav'=>$repository]);
}

/**
 * Return the currently saved favourite repository (from caSettings).
 *
 * @return void
 */
function getFavourite() {

	postReturn(["favourite"=>$GLOBALS['caSettings']['favourite']]);
}
/**
 * Update the current sort order and resort every cached display JSON.
 *
 * Reads POST['sortOrder'], writes it via writeJsonFile() to
 * CA_PATHS['sortOrder'], then re-sorts the displayed / search / repository
 * caches and persists each. Mutates global $sortOrder.
 *
 * @return void
 */
function changeSortOrder() {
	global $sortOrder;

	$sortOrder = getPostArray("sortOrder");
	writeJsonFile(CA_PATHS['sortOrder'],$sortOrder);

	if ( is_file(CA_PATHS['community-templates-displayed']) ) {
		$displayed = readJsonFile(CA_PATHS['community-templates-displayed']);
		if ($displayed['community'])
			usort($displayed['community'],"mySort");
		writeJsonFile(CA_PATHS['community-templates-displayed'],$displayed);
	}
	if ( is_file(CA_PATHS['community-templates-allSearchResults']) ) {
		$allSearchResults = readJsonFile(CA_PATHS['community-templates-allSearchResults']);
		if ( $allSearchResults['community'] )
			usort($allSearchResults['community'],"mySort");
		writeJsonFile(CA_PATHS['community-templates-allSearchResults'],$allSearchResults);
	}
	if ( is_file(CA_PATHS['community-templates-catSearchResults']) ) {
		$catSearchResults = readJsonFile(CA_PATHS['community-templates-catSearchResults']);
		if ( $catSearchResults['community'] )
			usort($catSearchResults['community'],"mySort");
		writeJsonFile(CA_PATHS['community-templates-catSearchResults'],$catSearchResults);
	}
	if ( is_file(CA_PATHS['repositoriesDisplayed']) ) {
		$reposDisplayed = readJsonFile(CA_PATHS['repositoriesDisplayed']);
		$bio = [];
		$nonbio = [];
		foreach ($reposDisplayed['community'] as $repo) {
			if ($repo['bio'])
				$bio[] = $repo;
			else
				$nonbio[] = $repo;
		}
		usort($bio,"mysort");
		usort($nonbio,"mysort");
		$reposDisplayed['community'] = array_merge($bio,$nonbio);
		writeJsonFile(CA_PATHS['repositoriesDisplayed'],$reposDisplayed);
	}
	postReturn(['status'=>"ok"]);
}
/**
 * Return the current sortBy/sortDir for the page when restoring UI state.
 *
 * Reads global $sortOrder.
 *
 * @return void
 */
function getSortOrder() {
	global $sortOrder;

	postReturn(["sortBy"=>$sortOrder['sortBy'],"sortDir"=>$sortOrder['sortDir']]);
}

/**
 * Reset sort to Name Up and persist it before the Apps page reload.
 *
 * Mutates global $sortOrder and writes CA_PATHS['sortOrder'].
 *
 * @return void
 */
function defaultSortOrder() {
	global $sortOrder;

	$sortOrder['sortBy'] = "Name";
	$sortOrder['sortDir'] = "Up";
	writeJsonFile(CA_PATHS['sortOrder'],$sortOrder);
	postReturn(['status'=>"ok"]);
}

/**
 * Return whether the home/startup screen flag file is present.
 *
 * @return void
 */
function onStartupScreen() {

	postReturn(['status'=>is_file(CA_PATHS['startupDisplayed'])]);
}

/**
 * Convert a DockerHub search result into a dockerMan install XML file.
 *
 * Reads POST['repo'] (stable image identifier), looks up the cached search
 * result for extras, accepts an optional base64 POST['description'] override
 * from the click handler, writes a per-request install XML under tempFiles,
 * and returns its path via postReturn(). Uses global $dockerManPaths.
 *
 * @return void
 */
function convert_docker() {
	global $dockerManPaths;

	/* DockerHub search results were previously keyed by per-page integer ID
	   (0..24) which reset every page — so a stale cached page combined with a
	   click on an earlier result would install the wrong container. We now
	   key by Repository ("user/repo" or "library/name"), which is stable. */
	$repo = trim((string)getPost("repo", ""));
	if ($repo === "") {
		postReturn(['error' => "convert_docker: missing repo"]);
		return;
	}

	$docker = caFindDockerHubResultByRepo($repo);

	$dockerfile = [];
	$dockerfile['Name'] = (string)($docker['Name'] ?? caDockerNameFromRepo($repo));
	/* Prefer the description forwarded from the click handler — that's the
	   description the user actually saw on the card. Falls back to whatever
	   the cache has, then to empty. The wire format is base64 (encoded by
	   skin_helpers so it survives an onclick attribute round-trip); decode
	   here at the boundary. */
	$clientDescription = "";
	$postedDescription = (string)getPost("description", "");
	if ($postedDescription !== "") {
		$decoded = base64_decode($postedDescription, true);
		$clientDescription = trim((string)($decoded !== false ? $decoded : ""));
	}
	$rawDescription = $clientDescription !== "" ? $clientDescription : (string)($docker['Description'] ?? "");
	$description = str_replace("&", "&amp;", $rawDescription);
	$dockerHubUrl = (string)($docker['DockerHub'] ?? caDockerHubUrlFromRepo($repo));
	$dockerfile['Description'] = $description."\n\nConverted By Community Applications   Always verify this template (and values)  against the support page for the container\n\n{$dockerHubUrl}";
	$dockerfile['Overview'] = $dockerfile['Description'];
	$dockerfile['Registry'] = $dockerHubUrl;
	$dockerfile['Repository'] = $repo;
	$dockerfile['BindTime'] = "true";
	$dockerfile['Privileged'] = "false";
	$dockerfile['Networking']['Mode'] = "bridge";

	$existing_templates = array_diff(scandir($dockerManPaths['templates-user']),[".",".."]);
	foreach ( $existing_templates as $template ) {
		if ( strtolower($dockerfile['Name']) == strtolower(str_replace(["my-",".xml"],["",""],$template)) )
			$dockerfile['Name'] .= "-1";
	}

	$dockerXML = makeXML($dockerfile);

	/* Per-request output path so two concurrent convert_docker calls (e.g. two
	   tabs) don't clobber each other's redirect target. Sweeping happens in
	   scripts/dockerConvert.php — both endpoints write into the same dir. */
	$convertToken = bin2hex(random_bytes(8));
	$installXmlPath = CA_PATHS['tempFiles']."/dockerConvert_{$convertToken}.xml";
	ca_file_put_contents($installXmlPath, $dockerXML);
	postReturn(['xml' => $installXmlPath]);
}

/**
 * Look up a DockerHub search result by Repository in the per-tab cache.
 *
 * The cache may not have the result if the page that contained it was evicted
 * by a more recent search; the caller is expected to fall back to derived
 * data when this returns null.
 *
 * @param  string  $repo
 * @return array<string,mixed>|null
 */
function caFindDockerHubResultByRepo(string $repo): ?array {
	if ($repo === "" || !is_file(CA_PATHS['dockerSearchResults'])) return null;
	$file = readJsonFile(CA_PATHS['dockerSearchResults']);
	$results = is_array($file) ? ($file['results'] ?? []) : [];
	foreach ($results as $r) {
		if (is_array($r) && (string)($r['Repository'] ?? '') === $repo) return $r;
	}
	return null;
}

/**
 * Last path segment of a Docker repo string (image name without registry prefix handling).
 *
 * @param  string  $repo
 * @return string
 */
function caDockerNameFromRepo(string $repo): string {
	$parts = explode('/', $repo);
	return (string)end($parts);
}

/**
 * Public Docker Hub URL for a namespace/image repository string.
 *
 * Official library images ("library/foo" or unscoped "foo") use the
 * /_/{name}/ path; everything else uses /r/{namespace}/{image}/.
 *
 * @param  string  $repo
 * @return string
 */
function caDockerHubUrlFromRepo(string $repo): string {
	if (strpos($repo, '/') === false || strpos($repo, 'library/') === 0) {
		$name = caDockerNameFromRepo($repo);
		return "https://hub.docker.com/_/{$name}/";
	}
	return "https://hub.docker.com/r/{$repo}/";
}

/**
 * Search Docker Hub for containers matching the user's filter and render results.
 *
 * Reads POST filter/page, hits the v1 registry search API, caches results to
 * CA_PATHS['dockerSearchResults'], and posts the rendered HTML. Side effects
 * include touching the search-active flag and unlinking caches on no-match.
 *
 * @return void
 */
function search_dockerhub() {

	$filter     = getPost("filter","");
	$pageNumber = getPost("page","1");

	$filter = str_replace(" ","%20",$filter);
	$filter = str_replace("/","%20",$filter);
	$jsonPage = download_url("https://registry.hub.docker.com/v1/search?q=$filter&page=$pageNumber");
	//$jsonPage = shell_exec("curl -s -X GET 'https://registry.hub.docker.com/v1/search?q=$filter&page=$pageNumber'");
	$pageresults = json_decode($jsonPage,true);
	$num_pages = $pageresults['num_pages'];

	if ($pageresults['num_results'] == 0) {
		$o['display'] = "<div class='ca_NoDockerAppsFound'>".tr("No Matching Applications Found On Docker Hub")."</div>";
		$o['script'] = "$('#dockerSearch').hide();";
		postReturn($o);
		@unlink(CA_PATHS['dockerSearchResults']);
		@unlink(CA_PATHS['dockerSearchActive']);
		return;
	}

	touch(CA_PATHS['dockerSearchActive']);
	$i = 0;
	foreach ($pageresults['results'] as $result) {
		unset($o);
		$o['IconFA'] = "docker";
		$o['Repository'] = $result['name'];
		$details = explode("/",$result['name']);
		$o['Author'] = $details[0];
		$o['Name'] = $details[1]??"";
		$o['Description'] = $result['description'];
		$o['Automated'] = $result['is_automated'];
		$o['Stars'] = $result['star_count'];
		$o['Official'] = $result['is_official'];
		$o['Trusted'] = $result['is_trusted'];
		if ( $o['Official'] ) {
			$o['DockerHub'] = "https://hub.docker.com/_/".$result['name']."/";
			$o['Name'] = $o['Author'];
		} else
			$o['DockerHub'] = "https://hub.docker.com/r/".$result['name']."/";

		$o['ID'] = $i;
		$searchName = str_replace("docker-","",$o['Name']);
		$searchName = str_replace("-docker","",$searchName);

		$dockerResults[$i] = addMissingVars($o);
		$i=++$i;
	}
	$dockerFile['num_pages'] = $num_pages;
	$dockerFile['page_number'] = $pageNumber;
	$dockerFile['results'] = $dockerResults;

	writeJsonFile(CA_PATHS['dockerSearchResults'],$dockerFile);
	postReturn(['display_data'=>displaySearchResults($pageNumber, true)]);
}
/**
 * Look up the last-updated date of a container from registry.hub.docker.com.
 *
 * Skips plugins and language packs; only handles latest-tag containers and
 * normalizes ghcr.io / lscr.io / cr.hotio.dev references to Docker Hub
 * coordinates. Retries up to 5 times with 1s sleeps to ride out short
 * network blips.
 *
 * @param  int|string  $ID  Template ID.
 * @return string|void Formatted date, "Unknown", or void when the template can't be looked up.
 */
function getLastUpdate($ID) {

	$count = 0;
	$registry_json = null;
	while ( $count < 5 ) {
		$templates = &$GLOBALS['templates'];
		if ( $templates ) break;
		sleep(1); # keep trying in case of a collision between reading and writing
		$count++;
	}
	$index = searchArray($templates,"ID",$ID);
	if ( $index === false )
		return "Unknown";

	$app = $templates[$index];
	if ( ($app['PluginURL']??null) || ($app['LanguageURL']??null) )
		return;

	if ( strpos($app['Repository'],"ghcr.io") !== false || strpos($app['Repository'],"cr.hotio.dev") !== false || strpos($app['Repository'],"lscr.io") !== false) { // try dockerhub for info on ghcr stuff
		$info = pathinfo($app['Repository']);
		$regs = basename($info['dirname'])."/".$info['filename'];
	} else {
		$regs = $app['Repository'];
	}
	$reg = explode(":",$regs);
	if ( ($reg[1] ?? "latest") !== "latest" )
		return tr("Unknown");

	if ( !strpos($reg[0],"/") )
		$reg[0] = "library/{$reg[0]}";

	$count = 0;
	$registry = false;
	while ( ! $registry && $count < 5 ) {
		$registry = download_url("https://registry.hub.docker.com/v2/repositories/{$reg[0]}");
		if ( ! $registry ) {
			$count++;
			sleep(1);
			continue;
		}
		$registry_json = json_decode($registry,true);
		if ( ! $registry_json['last_updated'] )
			return;

	}
	$registry_json['last_updated'] = $registry_json['last_updated'] ??  false;
	$lastUpdated = $registry_json['last_updated'] ? tr(date("M j, Y",strtotime($registry_json['last_updated'])),0) : "Unknown";

	return $lastUpdated;
}
/**
 * Update the persisted "max per page" preference when it differs from current value.
 *
 * Mutates $GLOBALS['caSettings']['maxPerPage'] and writes to CA_PATHS['pluginSettings'].
 *
 * @param  int|string  $max
 * @return void
 */
function changeMax($max) {
	if ( $max !== $GLOBALS['caSettings']['maxPerPage'] ) {
		$GLOBALS['caSettings']['maxPerPage'] = $max;
		write_ini_file(CA_PATHS['pluginSettings'],$GLOBALS['caSettings']);
	}
}
/**
 * Decide whether the Action Centre badge should be shown for the user.
 *
 * Waits for any in-flight update / template download to finish, then runs
 * previous_apps(true) which checks for updates/deprecated/blacklisted installed
 * apps. Calls postReturn() with "action"/"noaction".
 *
 * @return void
 */
function enableActionCentre() {

# wait til check for updates is finished
	for ( $i=0;$i<100;$i++ ) {
		if ( is_file(CA_PATHS['updateRunning']) && file_exists("/proc/".@file_get_contents(CA_PATHS['updateRunning'])) ) {
			debug("Action Centre sleeping -> update running");
			sleep(5);
			clearstatcache();
		}
		else break;
	}
	if ( $i >= 100 ) {
		debug("Something went wrong.  EnableActionCentre ran longer than 500 seconds");
		postReturn(['status'=>"noaction"]);
		return;
	}
# wait til templates are downloaded
	for ( $i=0;$i<100;$i++ ) {
		if ( ! is_file(CA_PATHS['haveTemplates']) ) {
			debug("Action Centre sleeping - no templates yet");
			clearstatcache();
			sleep(5);
		} else {
			debug("action centre: have templates");
			break;
		}
	}
	if ( $i >= 100 ) {
		debug("Something went wrong.  EnableActionCentre ran longer than 500 seconds");
		postReturn(['status'=>"noaction"]);
		return;
	}
	$displayed = previous_apps(true);

	/*
	$file = readJsonFile(CA_PATHS['community-templates-info']);
	$extraBlacklist = readJsonFile(CA_PATHS['extraBlacklist']);
	$extraDeprecated = readJsonFile(CA_PATHS['extraDeprecated']);

	if ( caIsDockerRunning() ) {
		$dockerUpdateStatus = readJsonFile(CA_PATHS['dockerUpdateStatus']);
	} else {
		$dockerUpdateStatus = [];
	}

	$info = getAllInfo();
# $info contains all installed containers
# now correlate that to a template;
# this section handles containers that have not been renamed from the appfeed
	if ( caIsDockerRunning() ) {
		$all_files = glob(CA_PATHS['dockerManTemplates']."/*.xml");
		$all_files = $all_files ?: [];
		foreach ($all_files as $xmlfile) {
			$o = readXmlFile($xmlfile);
			if ( ! $o ) continue;

			$runningflag = false;
			foreach ($info as $installedDocker) {
				if ( $installedDocker['Name'] == $o['Name'] ) {
					if ( startsWith(str_replace("library/","",$installedDocker['Image']), $o['Repository']) || startsWith($installedDocker['Image'],$o['Repository'])  ) {
						$runningflag = true;
						$searchResult = searchArray($file,'Repository',$o['Repository']);
						if ( $searchResult === false) {
							$searchResult = searchArray($file,'Repository',explode(":",$o['Repository'])[0]);
						}
						if ( $searchResult !== false )
							$o = $file[$searchResult];

						if ( $searchResult === false ) {
							$runningFlag = true;
							if ( $extraBlacklist[$o['Repository']] ?? false ) {
								$o['Blacklist'] = true;
							}
							if ( $extraDeprecated[$o['Repository']] ?? false ) {
								$o['Deprecated'] = true;
							}
						}
						break;
					}
				}
			}
			if ( $runningflag ) {
				$tmpRepo = strpos($o['Repository'],":") ? $o['Repository'] : $o['Repository'].":latest";
				$tmpRepo = strpos($tmpRepo,"/") ? $tmpRepo : "library/$tmpRepo";
				if ( $tmpRepo ) {
					if ( isset($dockerUpdateStatus[$tmpRepo]['status']) && $dockerUpdateStatus[$tmpRepo]['status'] == "false" )
						$o['actionCentre'] = true;
				}
				if ( ! $o['Blacklist'] && ! $o['Deprecated'] ) {
					if ( isset($extraBlacklist[$o['Repository']]) ) {
						$o['Blacklist'] = true;
					}
					if ( isset($extraDeprecated[$o['Repository']]) ) {
						$o['Deprecated'] = true;
					}
				}

				if ( !($o['Blacklist']??false) && !($o['Deprecated']??false) && !($o['actionCentre']??false)  )
					continue;

				$displayed[] = $o;
				break;
			}
		}
	}
# Now work on plugins
	foreach ($file as $template) {
		if ( ! ($template['Plugin']??null) ) continue;

		if ( $template['Name'] == "Community Applications" )
			continue;

		$filename = pathinfo($template['Repository'],PATHINFO_BASENAME);

		if ( checkInstalledPlugin($template) ) {
			$template['InstallPath'] = "/var/log/plugins/$filename";
			$template['Uninstall'] = true;
			if ( plugin("pluginURL","/var/log/plugins/$filename") !== $template['PluginURL'] )
				continue;

			$installedVersion = plugin("version","/var/log/plugins/$filename");
			if ( ( strcmp($installedVersion,$template['pluginVersion']) < 0 || ($template['UpdateAvailable']??null) ) ) {
				$template['actionCentre'] = true;
			}
			if ( ! ($template['actionCentre']??null) && is_file("/tmp/plugins/$filename") ) {
				if ( strcmp($installedVersion,plugin("version","/tmp/plugins/$filename")) < 0 )
					$template['actionCentre'] = true;
			}

			if ( !$template['Blacklist'] && !$template['Deprecated'] && $template['Compatible'] && !($template['actionCentre']??null) )
				continue;
			$displayed[] = $template;
			break;
		}
	}
	$installedLanguages = array_diff(scandir(CA_PATHS['languageInstalled']),[".","..","en_US"]);
	foreach ($installedLanguages as $language) {
		$index = searchArray($file,"LanguagePack",$language);
		if ( $index !== false ) {
			$tmpL = $file[$index];
			$tmpL['Uninstall'] = true;

			if ( !languageCheck($tmpL) )
				continue;

			$displayed[] = $tmpL;
			break;
		}
	}
*/
	if ( $displayed ) {
		debug("action center enabled");
		postReturn(['status'=>"action"]);
	} else {
		debug("action centre disabled");
		postReturn(['status'=>"noaction"]);
	}
}

/**
 * Verify that a template's "Requires" file exists on the running system.
 *
 * Reads POST['requires']; returns met=true when the field is empty or the
 * referenced path is a file.
 *
 * @return void
 */
function checkRequirements() {
	$requiresFile = getPost("requires","");
	if (! $requiresFile || ($requiresFile && is_file($requiresFile) ) ) {
		postReturn(['met'=>true]);
	} else {
		postReturn(['met'=>""]);
	}
}

/**
 * Touch sentinel files under pluginPending/ for each plugin pending install.
 *
 * Reads POST['plugin'] (*-delimited list of plugin URLs). Only triggers when
 * 2+ plugins are queued, since single plugins don't need coordination.
 *
 * @return void
 */
function saveMultiPluginPending() {

	$plugin = getPost("plugin","");
	$plugins = array_filter(explode("*",$plugin));
	if ( count($plugins) > 1 ) {
		exec("mkdir -p ".escapeshellarg(CA_PATHS['pluginPending']));
		foreach ($plugins as $plg) {
			if (! $plg ) continue;
			$pluginName = basename($plg);
			touch(CA_PATHS['pluginPending'].$pluginName);
		}
	}
	postReturn(['status'=>'ok']);
}

/**
 * Lazily download the appfeed statistics JSON (no-op when already cached).
 *
 * Writes CA_PATHS['statistics'] via download_json() when missing.
 *
 * @return void
 */
function downloadStatistics() {

	if ( ! is_file(CA_PATHS['statistics']) )
		download_json(CA_PATHS['statisticsURL'],CA_PATHS['statistics']);
}

/**
 * Report whether a specific plugin's install/update is already in progress.
 *
 * Checks for a basename sentinel inside CA_PATHS['pluginPending']. The caller
 * passes whatever it has (a .plg URL, /var/log/plugins/foo.plg path, etc);
 * basename normalizes them all to "foo.plg". If no path is provided the answer
 * is "not in progress" (we can't lock against an unknown). Clears the stat
 * cache for the flag path so long-lived FPM workers see fresh state.
 *
 * @return void
 */
function checkPluginInProgress() {

	$pluginPath = trim((string)getPost("pluginPath",""));
	if ($pluginPath === "") {
		postReturn(['inProgress' => ""]);
		return;
	}
	$pluginName = basename($pluginPath);
	if ($pluginName === "" || $pluginName === "." || $pluginName === "..") {
		postReturn(['inProgress' => ""]);
		return;
	}
	$flag = rtrim(CA_PATHS['pluginPending'], "/") . "/" . $pluginName;
	/* PHP caches stat results within a process; on a long-lived FPM worker the
	   cached state from an earlier request can be stale (an install script in
	   another process may have created or removed this flag in the meantime).
	   Targeted clearstatcache() forces a fresh fs lookup for just this path. */
	clearstatcache(true, $flag);
	postReturn(['inProgress' => is_file($flag) ? "$flag" : ""]);
}

/**
 * AJAX noop: reserved hook for "network already exists" handling.
 *
 * @return void
 */
function networkAlreadyCreated() {
}

/**
 * Unlink the startup-displayed flag file to recover from a stuck home screen.
 *
 * @return void
 */
function clearStartUpDisplayed() {

	@unlink(CA_PATHS['startupDisplayed']);
	postReturn(['done']);
}

/**
 * Log client-side JavaScript errors posted from the browser (no-op return path).
 *
 * @return void
 */
function javascriptError() {
	return;

	debug("******* ERROR **********\n".print_r($_POST,true));
}
?>
