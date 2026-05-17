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
require_once "$docroot/plugins/community.applications/skins/Narrow/skin.php";
require_once "$docroot/plugins/dynamix.plugin.manager/include/PluginHelpers.php";
require_once "$docroot/webGui/include/Markdown.php";

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
switch ($_POST['action']) {
	case 'get_content':
		get_content();
		break;
	case 'force_update':
		force_update();
		break;
	case 'force_update_skip':
		force_update_skip();
		break;
	case 'display_content':
		display_content();
		break;
	case 'dismiss_warning':
		dismiss_warning();
		break;
	case 'previous_apps':
		previous_apps();
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
		getPopupDescription();
		break;
	case 'getRepoDescription':
		getRepoDescription();
		break;
	case 'getReadmeSection':
		getReadmeSection();
		break;
	case 'getTemplateChanges':
		getTemplateChanges();
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
 * DownloadApplicationFeed() must run before community template merge so private repos apply correctly.
 */
function DownloadApplicationFeed() {
	exec("rm -rf ".escapeshellarg(CA_PATHS['tempFiles']));
	@mkdir(CA_PATHS['tempFiles'],0777,true);
	@unlink(CA_PATHS['downloadLocks']);
	@mkdir(CA_PATHS['templates-community'],0777,true);

	$currentFeed = "Primary Server";
	if ( CA_PATHS['localONLY'] ) {
		$ApplicationFeed = json_decode(file_get_contents(CA_PATHS['application-feed-local']),true);
	} else {
		$downloadURL = randomFile();
		ca_publish("ca_gettingTemplates","1");
		// With CURLOPT_MAX_RECV_SPEED_LARGE capped, allow ample time for a full feed download.
		$ApplicationFeed = download_json(CA_PATHS['application-feed'], $downloadURL, 300);
		if ( (! is_array($ApplicationFeed['applist']??false)) || (empty($ApplicationFeed['applist']??[])) ) {
			$currentFeed = "Backup Server";
			$ApplicationFeed = download_json(CA_PATHS['pluginProxy'].CA_PATHS['application-feedBackup'], $downloadURL, 300);
		}
		@unlink($downloadURL);
		if ( (! is_array($ApplicationFeed['applist'])) || empty($ApplicationFeed['applist']) ) {
			@unlink(CA_PATHS['currentServer']);
			ca_file_put_contents(CA_PATHS['appFeedDownloadError'],$downloadURL);
			return false;
		}
	}
	ca_file_put_contents(CA_PATHS['currentServer'],$currentFeed);
	$i = 0;
	$lastUpdated['last_updated_timestamp'] = $ApplicationFeed['last_updated_timestamp'];
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
			$o['Author']        = $o['PluginAuthor'];
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
	touch(CA_PATHS['haveTemplates']);

	return true;
}

/**
 * Return moderation/statistics details for sidebar popups
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
 * Persist the moderation Repository view's user-toggled ignore list to the flash drive. Posted as JSON via POST['ignored']; written verbatim to CA_PATHS['ignoredRepos']. Read by DownloadApplicationFeed() to stamp hideFromCA on matching templates.
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
 * Builds and returns debugging zip URL
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
 * Selects an app of the day
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
				if ( $template['trending'] && ($template['downloads'] > 100000) ) {
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
			$sortOrder['sortBy'] = "downloads";
			$sortOrder['sortDir'] = "Down";
			usort($file,"mySort");
			$repos = [];
			foreach ($file as $template) {
				if ( !isset($template['PluginURL']) ) continue;
				if ( ! $template['downloads'] ?? false ) continue;

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
				if ( isset($template['trending']) && ($template['downloads'] > 10000) ) {
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
 * Checks selected app for eligibility as app of day
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
 * Gets the repositories that are listed on any given display
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
 * get_content - get the results from templates according to categories, filters, etc
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
		postReturn(['status' => "ok"]);
		return;
	}
	force_update();
}
/**
 * force_update -> forces an update of the applications
 */
function force_update() {

	require_once __DIR__ . '/force_update_helpers.php';
	// If another update is already running, don't fetch metadata; just wait for it to finish.
	if (is_file(CA_PATHS['gettingTemplates'])) {
		while ( is_file(CA_PATHS['gettingTemplates']) ) {
			sleep(1);
			clearstatcache();
		}
		// Another process should have refreshed templates; return.
		postReturn(['status' => "ok"]);
		return;
	}
	touch(CA_PATHS['gettingTemplates']);

	getFullGlobals();

	if (!empty(CA_PATHS['localONLY'])) {
		ForceUpdateHelpers::resetTemplatesCache(true);
	}

	$lastUpdatedOld = readJsonFile(CA_PATHS['lastUpdated-old']);
	debug("old feed timestamp: ".($lastUpdatedOld['last_updated_timestamp'] ?? ""));

	$latestUpdate = ForceUpdateHelpers::fetchLatestUpdateMetadata();

	if (ForceUpdateHelpers::shouldRefreshTemplates($latestUpdate, $lastUpdatedOld)) {
		ForceUpdateHelpers::resetTemplatesCache();
	}

	if (!ForceUpdateHelpers::templatesAvailable()) {
		if (!DownloadApplicationFeed()) {
			@unlink(CA_PATHS['gettingTemplates']);
			@unlink(CA_PATHS['haveTemplates']);
			postReturn(ForceUpdateHelpers::buildDownloadFailureResponse());
			return;
		}
	}

	//getConvertedTemplates();
	moderateTemplates();
	touch(CA_PATHS['haveTemplates']);
	@unlink(CA_PATHS['gettingTemplates']);
	$script = ForceUpdateHelpers::buildUpdateScript();

	writeGlobals($GLOBALS['templates']);
	postReturn(['status' => "ok", 'script' => $script]);
}



/**
 * display_content - displays the templates according to view mode, sort order, etc
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
 * dismiss_warning - dismisses the warning from appearing at startup
 */
function dismiss_warning() {

	ca_file_put_contents(CA_PATHS['warningAccepted'],"warning dismissed");
	unset($GLOBALS['caSettings']['NoInstalls']);
	write_ini_file(CA_PATHS['pluginSettings'],$GLOBALS['caSettings']);
	postReturn(['status'=>"warning dismissed"]);
}

/**
 * Displays the list of installed or previously installed apps
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
 * Removes an app from the previously installed list (ie: deletes the user template
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
 * Checks for an update still available (to update display) after update installed
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
 * Uninstalls a docker
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
 * Pins / Unpins an application for later viewing
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
 * Gets if any apps are pinned or not
 */
function areAppsPinned() {

	postReturn(['status' => in_array(true,readJsonFile(CA_PATHS['pinnedV2']))]);
}

/**
 * Displays the pinned applications
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
 * Displays the possible branch tags for an app
 */
function displayTags() {
	$leadTemplate = getPost("leadTemplate","oops");
	$rename = getPost("rename","false");
	postReturn(['tags'=>formatTags($leadTemplate,$rename)]);
}

/**
 * Displays The Statistics For The Appfeed
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
	$statistics['primaryServerUrl'] = CA_PATHS['application-feed'];
	$statistics['backupServerUrl'] = CA_PATHS['application-feedBackup'];

	postReturn(['statistics'=>$statistics]);
}

/**
 * Creates the entries for autocomplete on searches
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
 * Displays the changelog
 */
function caChangeLog() {
	postReturn(["changelog"=>Markdown(ca_plugin("changes","/var/log/plugins/community.applications.plg"))."<br><br>"]);
}

/**
 * Populates the category list
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
 * Get the html for the popup
 */
function getPopupDescription() {
	$appNumber = getPost("appPath","");
	postReturn(getPopupDescriptionSkin($appNumber));
}

/**
 * Get the html for a repo popup
 */
function getRepoDescription() {
	$repository = html_entity_decode(getPost("repository",""),ENT_QUOTES);
	postReturn(getRepoDescriptionSkin($repository));
}

/**
 * Fetch + sanitize rendered README for sidebar injection
 */
function getReadmeSection() {
	$readmeId = trim((string)getPost("readmeId", ""));
	$cacheKey = trim((string)getPost("cacheKey", ""));
	$url = trim((string)getPost("url", ""));
	$fallback = trim((string)getPost("fallback", ""));

	$html = caDownloadAndRenderReadme($url, $cacheKey);
	if ($html === "" && $fallback !== "") {
		$html = caDownloadAndRenderReadme($fallback, $cacheKey);
	}

	postReturn([
		"readmeId" => $readmeId,
		"ok" => ($html !== ""),
		"html" => $html,
		"message" => ($html !== "" ? "" : tr("README can't be loaded"))
	]);
}

/**
 * Fetch + sanitize rendered template/plugin changes on demand
 */
function getTemplateChanges() {
	$changesId = trim((string)getPost("changesId", ""));
	$cacheKey = trim((string)getPost("cacheKey", ""));
	$url = trim((string)getPost("url", ""));
	$type = trim((string)getPost("type", "")); // "plugin" or "xml"

	$html = caDownloadAndRenderTemplateChanges($url, $cacheKey, $type);

	postReturn([
		"changesId" => $changesId,
		"ok" => ($html !== ""),
		"html" => $html,
		"message" => ($html !== "" ? "" : tr("Change log can't be loaded"))
	]);
}

/**
 * Fetch changelog/markdown for a template or plugin URL and return sanitized HTML.
 *
 * @param string $url
 * @param string $cacheKey Unused (kept for API compatibility)
 * @param string $type "plugin" or "xml" (container template)
 * @return string HTML fragment or empty string on failure
 */
function caDownloadAndRenderTemplateChanges(string $url, string $cacheKey = "", string $type = ""): string {
	if ($url === "") return "";

	/* No on-disk caching (cacheKey was untrusted POST input flowing into a
	   filename) and no SSRF surface — caFetchChangelogContents enforces
	   https-only / size-capped / redirect-protocol-restricted curl. */
	$raw = caFetchChangelogContents($url);
	if ($raw === "" || trim($raw) === "") return "";

	$changes = "";
	if ($type === "plugin") {
		/* ca_plugin("changes", $path) needs a file path. Use a transient
		   tempfile in /tmp that we unlink immediately after parsing. */
		$tmp = @tempnam(sys_get_temp_dir(), "ca_chgs_");
		if ($tmp !== false) {
			@file_put_contents($tmp, $raw);
			$changes = @ca_plugin("changes", $tmp) ?: "";
			@unlink($tmp);
		}
	} else {
		/* Strict XML parse: LIBXML_NONET disables external network entity
		   loading; libxml ≥ 2.9 already disables external general entities by
		   default but the flag is belt-and-suspenders against XXE. */
		$xml = @simplexml_load_string($raw, "SimpleXMLElement", LIBXML_NONET | LIBXML_NOCDATA);
		if ($xml && isset($xml->Changes)) {
			$changes = (string)$xml->Changes;
		}
	}

	$changes = (string)$changes;
	if ($changes === "") return "";

	/* Strip any raw HTML from the source first so the markdown processor
	   only sees plain text — markdown's own syntax can't generate dangerous
	   tags or attributes (no onclick, no javascript: in parens, no inline
	   styles), so once raw HTML is gone the markdown output is bounded to a
	   safe shape. The post-render whitelist + anchor/img sanitizers below
	   are the second-layer defense. */
	$changes = str_replace("    ", "&nbsp;&nbsp;&nbsp;&nbsp;", $changes);
	$changes = strip_tags($changes);
	$changes = Markdown($changes);

	/* Match the whole anchor element so we can drop the wrapper entirely (not
	   just the href) when the link points anywhere other than http(s). Relative
	   paths like /Main/... or /Settings/... would otherwise survive as styled
	   pseudo-links and trick users into clicking through to local GUI pages. */
	$changes = preg_replace_callback(
		"/<a\\b([^>]*)>(.*?)<\\/a>/is",
		static function ($matches) {
			$attrs = $matches[1];
			$inner = $matches[2];
			if (preg_match("/\\bhref\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))/i", $attrs, $hrefMatch)) {
				$href = ($hrefMatch[2] ?? "") ?: (($hrefMatch[3] ?? "") ?: ($hrefMatch[4] ?? ""));
				if (caIsPublicHttpUrl($href)) {
					$safeHref = htmlspecialchars($href, ENT_QUOTES);
					$titleHtml = "";
					if (preg_match("/\\btitle\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))/i", $attrs, $titleMatch)) {
						$title = ($titleMatch[2] ?? "") ?: (($titleMatch[3] ?? "") ?: ($titleMatch[4] ?? ""));
						if ($title !== "") {
							$titleHtml = " title='".htmlspecialchars($title, ENT_QUOTES)."'";
						}
					}
					return "<a href='{$safeHref}' target='_blank' rel='noopener noreferrer'{$titleHtml}>{$inner}</a>";
				}
			}
			/* No href / non-http(s) href — strip the anchor wrapper, keep text. */
			return $inner;
		},
		$changes
	);

	$changes = preg_replace_callback(
		"/<(img)\\b([^>]*)>/i",
		static function ($matches) {
			$attrs = $matches[2];
			if (preg_match("/\\bsrc\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))/i", $attrs, $srcMatch)) {
				$src = ($srcMatch[2] ?? "") ?: (($srcMatch[3] ?? "") ?: ($srcMatch[4] ?? ""));
				if (!caIsPublicHttpUrl($src)) {
					return "";
				}
				$safeSrc = htmlspecialchars($src, ENT_QUOTES);
				$alt = "Changelog image";
				if (preg_match("/\\balt\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))/i", $attrs, $altMatch)) {
					$altRaw = ($altMatch[2] ?? "") ?: (($altMatch[3] ?? "") ?: ($altMatch[4] ?? ""));
					if ($altRaw !== "") $alt = $altRaw;
				}
				$titleHtml = "";
				if (preg_match("/\\btitle\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))/i", $attrs, $titleMatch)) {
					$title = ($titleMatch[2] ?? "") ?: (($titleMatch[3] ?? "") ?: ($titleMatch[4] ?? ""));
					if ($title !== "") {
						$titleHtml = " title='".htmlspecialchars($title, ENT_QUOTES)."'";
					}
				}
				return "<img src='{$safeSrc}' alt='".htmlspecialchars($alt, ENT_QUOTES)."'{$titleHtml}>";
			}
			return "";
		},
		$changes
	);

	return trim((string)$changes);
}

/* Same hardening profile as caFetchReadmeContents but without a host
   whitelist — plugin .plg URLs and template .xml URLs come from many
   third-party sources (GitHub, forums, custom hosts), so we lean on the
   protocol restriction (https only, including redirects), redirect cap, and
   1 MB size cap to constrain SSRF / DoS surface. */
function caFetchChangelogContents(string $url): string {
	$maxBytes = 1024 * 1024; // 1 MB
	$buf = "";

	$ch = curl_init();
	curl_setopt_array($ch, [
		CURLOPT_URL            => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS      => 3,
		CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
		CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTPS,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_FAILONERROR    => true,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$buf, $maxBytes) {
			$len = strlen($data);
			if (strlen($buf) + $len > $maxBytes) {
				return -1;
			}
			$buf .= $data;
			return $len;
		},
	]);
	@curl_exec($ch);
	if (PHP_MAJOR_VERSION < 8) {
		call_user_func('curl_close', $ch);
	}

	return $buf;
}

/* Dedicated hardened fetcher for README content. Doesn't go through
   download_url() because:
     - 1 MB cap via WRITEFUNCTION abort, so a hostile repo can't DoS us.
     - Redirects allowed but restricted to https and capped at 3 hops, then
       the effective URL host is rechecked against the GitHub raw whitelist
       so a redirect can't smuggle in a different origin's content.
     - 15s timeout / 5s connect.
   download_url() keeps its general-purpose behavior (FOLLOWLOCATION, proxy
   fallback, etc.) for every other caller. */
function caFetchReadmeContents(string $url): string {
	$maxBytes = 1024 * 1024; // 1 MB
	$buf = "";

	$ch = curl_init();
	curl_setopt_array($ch, [
		CURLOPT_URL            => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS      => 3,
		CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
		CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTPS,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_FAILONERROR    => true,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$buf, $maxBytes) {
			$len = strlen($data);
			if (strlen($buf) + $len > $maxBytes) {
				return -1; // abort transfer
			}
			$buf .= $data;
			return $len;
		},
	]);
	@curl_exec($ch);
	$finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
	if (PHP_MAJOR_VERSION < 8) {
		call_user_func('curl_close', $ch);
	}

	// Make sure a redirect didn't smuggle us off-host before content-trust.
	$finalHost = strtolower((string)parse_url($finalUrl, PHP_URL_HOST));
	if (!in_array($finalHost, ["raw.githubusercontent.com", "www.raw.githubusercontent.com"], true)) {
		return "";
	}
	return $buf;
}

/**
 * Fetch README.md from raw.githubusercontent.com and return HTML via markdown().
 *
 * @param string $url
 * @param string $cacheKey Unused (kept for API compatibility)
 * @return string
 */
function caDownloadAndRenderReadme(string $url, string $cacheKey = ""): string {
	if ($url === "") return "";
	$parts = @parse_url($url);
	if (!is_array($parts)) return "";
	$host = strtolower($parts['host'] ?? "");
	$path = (string)($parts['path'] ?? "");
	if (!in_array($host, ["raw.githubusercontent.com", "www.raw.githubusercontent.com"], true)) return "";
	if (!preg_match("/\\/README\\.md$/i", $path)) return "";

	/* No caching: README is an at-render thing — user can hit the GitHub URL
	   directly for the live copy. Avoids cacheKey path-traversal concerns and
	   keeps disk usage bounded. */
	$readmeContents = caFetchReadmeContents($url);
	if ($readmeContents === "" || trim($readmeContents) === "") {
		return "";
	}

	/* Strip any raw HTML up front so markdown only sees plain text — markdown
	   syntax can't produce dangerous tags or attributes (no onclick, no
	   javascript: in href parens, no inline styles), so the output is
	   bounded to a safe shape. The whitelist + anchor/img sanitizers below
	   are the second-layer defense. */
	$readmeContents = strip_tags((string)$readmeContents);
	$readmeContents = Markdown($readmeContents);

	/* Match the whole anchor element so we can drop the wrapper entirely (not
	   just the href) when the link points anywhere other than http(s). Relative
	   paths like /Main/... or /Settings/... would otherwise survive as styled
	   pseudo-links and trick users into clicking through to local GUI pages. */
	$readmeContents = preg_replace_callback(
		"/<a\\b([^>]*)>(.*?)<\\/a>/is",
		static function ($matches) {
			$attrs = $matches[1];
			$inner = $matches[2];
			if (preg_match("/\\bhref\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))/i", $attrs, $hrefMatch)) {
				$href = ($hrefMatch[2] ?? "") ?: (($hrefMatch[3] ?? "") ?: ($hrefMatch[4] ?? ""));
				if (caIsPublicHttpUrl($href)) {
					$safeHref = htmlspecialchars($href, ENT_QUOTES);
					$titleHtml = "";
					if (preg_match("/\\btitle\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))/i", $attrs, $titleMatch)) {
						$title = ($titleMatch[2] ?? "") ?: (($titleMatch[3] ?? "") ?: ($titleMatch[4] ?? ""));
						if ($title !== "") {
							$titleHtml = " title='".htmlspecialchars($title, ENT_QUOTES)."'";
						}
					}
					return "<a href='{$safeHref}' target='_blank' rel='noopener noreferrer'{$titleHtml}>{$inner}</a>";
				}
			}
			/* No href / non-http(s) href — strip the anchor wrapper, keep text. */
			return $inner;
		},
		$readmeContents
	);

	$readmeContents = preg_replace_callback(
		"/<(img)\\b([^>]*)>/i",
		static function ($matches) {
			$attrs = $matches[2];
			if (preg_match("/\\bsrc\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))/i", $attrs, $srcMatch)) {
				$src = ($srcMatch[2] ?? "") ?: (($srcMatch[3] ?? "") ?: ($srcMatch[4] ?? ""));
				if (!caIsPublicHttpUrl($src)) {
					return "";
				}
				$safeSrc = htmlspecialchars($src, ENT_QUOTES);
				$alt = "README image";
				if (preg_match("/\\balt\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))/i", $attrs, $altMatch)) {
					$altRaw = ($altMatch[2] ?? "") ?: (($altMatch[3] ?? "") ?: ($altMatch[4] ?? ""));
					if ($altRaw !== "") $alt = $altRaw;
				}
				$titleHtml = "";
				if (preg_match("/\\btitle\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))/i", $attrs, $titleMatch)) {
					$title = ($titleMatch[2] ?? "") ?: (($titleMatch[3] ?? "") ?: ($titleMatch[4] ?? ""));
					if ($title !== "") {
						$titleHtml = " title='".htmlspecialchars($title, ENT_QUOTES)."'";
					}
				}
				return "<img src='{$safeSrc}' alt='".htmlspecialchars($alt, ENT_QUOTES)."'{$titleHtml}>";
			}
			return "";
		},
		$readmeContents
	);

	return (string)$readmeContents;
}

/**
 * Creates the XML for a container install
 */
function createXML() {

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
 * Switch to a language
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
 * Delete multiple checked off apps from previous apps
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
 * Get's the categories present on a search
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
	}

	postReturn(array_values(array_unique($categories)));
}

/**
 * Set's the favourite repository
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
 * Returns the favourite repository
 */
function getFavourite() {

	postReturn(["favourite"=>$GLOBALS['caSettings']['favourite']]);
}
/**
 * Changes the sort order
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
 * Gets the sort order when restoring state
 */
function getSortOrder() {
	global $sortOrder;

	postReturn(["sortBy"=>$sortOrder['sortBy'],"sortDir"=>$sortOrder['sortDir']]);
}

/**
 * Reset the sort order to default when reloading Apps page
 */
function defaultSortOrder() {
	global $sortOrder;

	$sortOrder['sortBy'] = "Name";
	$sortOrder['sortDir'] = "Up";
	writeJsonFile(CA_PATHS['sortOrder'],$sortOrder);
	postReturn(['status'=>"ok"]);
}

/**
 * Checks whether we're on the startup screen when restoring state
 */
function onStartupScreen() {

	postReturn(['status'=>is_file(CA_PATHS['startupDisplayed'])]);
}

/**
 * convert_docker - called when system adds a container from dockerHub
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

/* Look up a DockerHub search result by Repository in the per-tab cache. The
   cache may not have the result if the page that contained it was evicted by
   a more recent search; the caller is expected to fall back to derived data
   when this returns null. */
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
 */
function caDockerNameFromRepo(string $repo): string {
	$parts = explode('/', $repo);
	return (string)end($parts);
}

/**
 * Public Docker Hub URL for a namespace/image repository string.
 */
function caDockerHubUrlFromRepo(string $repo): string {
	if (strpos($repo, '/') === false || strpos($repo, 'library/') === 0) {
		$name = caDockerNameFromRepo($repo);
		return "https://hub.docker.com/_/{$name}/";
	}
	return "https://hub.docker.com/r/{$repo}/";
}

/**
 * search_dockerhub - returns the results from dockerHub
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
 * Gets the last update issued to a container
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
 * Changes the max per page displayed
 */
function changeMax($max) {
	if ( $max !== $GLOBALS['caSettings']['maxPerPage'] ) {
		$GLOBALS['caSettings']['maxPerPage'] = $max;
		write_ini_file(CA_PATHS['pluginSettings'],$GLOBALS['caSettings']);
	}
}
/**
 * Enables if necessary the action centre Basically a duplicate of action centre code in previous apps
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
 * Checks the requirements being met on an upgrade
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
 * Saves the list of plugins which are pending installs
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
 * Downloads the stats file in the background
 */
function downloadStatistics() {

	if ( ! is_file(CA_PATHS['statistics']) )
		download_json(CA_PATHS['statisticsURL'],CA_PATHS['statistics']);
}

/**
 * Checks to see if THIS plugin's install/update is already in progress by looking for its basename inside CA_PATHS['pluginPending']. The caller passes whatever it has (a .plg URL, /var/log/plugins/foo.plg path, etc); basename normalizes them all to "foo.plg". If no path is provided the answer is "not in progress" (we can't lock against an unknown).
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
 * Clears the startup displayed flag in case of weird error
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