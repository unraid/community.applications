<?
########################################
#                                      #
# Community Applications               #
# Copyright 2020-2025, Lime Technology #
# Copyright 2015-2025, Andrew Zawadzki #
#                                      #
# Licenced under GPLv2                 #
#                                      #
########################################

ini_set('memory_limit','256M');  // REQUIRED LINE
ini_set('display_errors', 'Off'); // All display errors wind up breaking CA

$unRaidSettings = parse_ini_file("/etc/unraid-version");

### Translations section has to be first so that nothing else winds up caching the file(s)

$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: "/usr/local/emhttp";

$_SERVER['REQUEST_URI'] = "docker/apps";

require_once "$docroot/plugins/dynamix/include/Wrappers.php";
require_once "$docroot/plugins/dynamix/include/Translations.php";
require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php"; # must be first include due to paths defined
require_once "$docroot/plugins/community.applications/include/paths.php";
require_once "$docroot/plugins/community.applications/include/helpers.php";
require_once "$docroot/plugins/community.applications/skins/Narrow/skin.php";
require_once "$docroot/plugins/dynamix.plugin.manager/include/PluginHelpers.php";
require_once "$docroot/webGui/include/Markdown.php";

################################################################################
# Set up any default settings (when not explicitely set by the settings module #
################################################################################

$caSettings = parse_plugin_cfg("community.applications");
$dynamixSettings = parse_plugin_cfg("dynamix");

$caSettings['dockerSearch']  = "yes";
$caSettings['unRaidVersion'] = $unRaidSettings['version'];
$caSettings['favourite']     = isset($caSettings['favourite']) ? str_replace("*","'",$caSettings['favourite']) : "";
$caSettings['dynamixTheme']  = $dynamixSettings['theme'];

$caSettings['maxPerPage']    = (integer)$caSettings['maxPerPage'] ?: 12; // Handle possible corruption on file
if ( $caSettings['maxPerPage'] < 12 ) $caSettings['maxPerPage'] = 12;

if ( ! is_file($caPaths['warningAccepted']) )
  $caSettings['NoInstalls'] = true;

$DockerClient = new DockerClient();
$DockerTemplates = new DockerTemplates();

if ( ! caIsDockerRunning() ) {
  $caSettings['dockerSearch'] = "no";
}

@mkdir($caPaths['tempFiles'],0777,true);

if ( !is_dir($caPaths['templates-community']) ) {
  @mkdir($caPaths['templates-community'],0777,true);
  @unlink($caPaths['community-templates-info']);
}

debug("POST CALLED ({$_POST['action']})\n".print_r($_POST,true));


$sortOrder = readJsonFile($caPaths['sortOrder']);
if ( ! $sortOrder ) {
  $sortOrder['sortBy'] = "Name";
  $sortOrder['sortDir'] = "Up";
  writeJsonFile($caPaths['sortOrder'],$sortOrder);
}

############################################
##                                        ##
## BEGIN MAIN ROUTINES CALLED BY THE HTML ##
##                                        ##
############################################

switch ($_POST['action']) {
  case 'get_content':
    get_content();
    break;
  case 'force_update':
    force_update();
    break;
  case 'display_content':
    display_content();
    break;
  case 'dismiss_warning':
    dismiss_warning();
    break;
  case 'dismiss_plugin_warning':
    dismiss_plugin_warning();
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
  case 'changeMaxPerPage':
    changeMaxPerPage();
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
  case 'clearPluginInstallFlag':
    clearPluginInstallFlag();
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
#  DownloadApplicationFeed MUST BE CALLED prior to DownloadCommunityTemplates in order for private repositories to be merged correctly.

function DownloadApplicationFeed() {
  global $caPaths;

  //$info = readJsonFile($caPaths['info']);
  exec("rm -rf '{$caPaths['tempFiles']}'");
  @mkdir($caPaths['templates-community'],0777,true);

  $currentFeed = "Primary Server";
  if ( $caPaths['localONLY'] ) {
    $ApplicationFeed = json_decode(file_get_contents($caPaths['application-feed-local']),true);
  } else {
    $downloadURL = randomFile();
    $ApplicationFeed = download_json($caPaths['application-feed'],$downloadURL,"",30);
    if ( (! is_array($ApplicationFeed['applist']??false)) || (empty($ApplicationFeed['applist']??[])) ) {
      $currentFeed = "Backup Server";
      $ApplicationFeed = download_json($caPaths['pluginProxy'].$caPaths['application-feedBackup'],$downloadURL,"",-1);
    }
    @unlink($downloadURL);
    if ( (! is_array($ApplicationFeed['applist'])) || empty($ApplicationFeed['applist']) ) {
      @unlink($caPaths['currentServer']);
      ca_file_put_contents($caPaths['appFeedDownloadError'],$downloadURL);
      return false;
    }
  }
  ca_file_put_contents($caPaths['currentServer'],$currentFeed);
  $i = 0;
  $lastUpdated['last_updated_timestamp'] = $ApplicationFeed['last_updated_timestamp'];
  writeJsonFile($caPaths['lastUpdated-old'],$lastUpdated);

  $invalidXML = [];
  foreach ($ApplicationFeed['applist'] as $o) {
    if ( (! isset($o['Repository']) ) && (! isset($o['Plugin']) ) && (!isset($o['Language']) )){
      $invalidXML[] = $o;
      continue;
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
    $o['Path'] = $caPaths['templates-community']."/".alphaNumeric($o['RepoName'])."/".alphaNumeric($o['Author'])."-".alphaNumeric($o['Name'])."-{$tag[1]}";
    if ( file_exists($o['Path'].".xml") ) {
      $o['Path'] .= "(1)";
    }
    $o['Path'] .= ".xml";

    $o = fixTemplates($o);
    if ( ! $o ) continue;

    if ( is_array($o['trends']??null) && count($o['trends']) > 1 ) {
      $o['trendDelta'] = round(end($o['trends']) - $o['trends'][0],4);
      $o['trendAverage'] = round(array_sum($o['trends'])/count($o['trends']),4);
    }

    $o['Category'] = str_replace("Status:Beta","",$o['Category']);    # undo changes LT made to my xml schema for no good reason
    $o['Category'] = str_replace("Status:Stable","",$o['Category']);
    $myTemplates[$i] = $o;

    if ( ! ($o['Official']??null) ) {
      if ( ! ($o['DonateText']??null) && ($ApplicationFeed['repositories'][$o['RepoName']]['DonateText'] ?? false) )
        $o['DonateText'] = $ApplicationFeed['repositories'][$o['RepoName']]['DonateText'];
      if ( ! ($o['DonateLink']??null) && ($ApplicationFeed['repositories'][$o['RepoName']]['DonateLink'] ?? false) )
        $o['DonateLink'] = $ApplicationFeed['repositories'][$o['RepoName']]['DonateLink'];
    } else {
      $o['DonateText'] = $o['OfficialDonateText'] ?? null;
      $o['DonateLink'] = $o['OfficialDonateLink'] ?? null;
    }
    $ApplicationFeed['repositories'][$o['RepoName']]['downloads'] = $ApplicationFeed['repositories'][$o['RepoName']]['downloads'] ?? 0;
    $ApplicationFeed['repositories'][$o['RepoName']]['trending'] = $ApplicationFeed['repositories'][$o['RepoName']]['trending'] ?? 0;

    $ApplicationFeed['repositories'][$o['RepoName']]['downloads']++;
    $ApplicationFeed['repositories'][$o['RepoName']]['trending'] += $o['trending']??null;
    if ( ! ($o['ModeratorComment']??null) == "Duplicated Template" ) {
      if ( $ApplicationFeed['repositories'][$o['RepoName']]['FirstSeen'] ?? false) {
        if ( $o['FirstSeen'] < $ApplicationFeed['repositories'][$o['RepoName']]['FirstSeen'])
          $ApplicationFeed['repositories'][$o['RepoName']]['FirstSeen'] = $o['FirstSeen'];
      } else {
        $ApplicationFeed['repositories'][$o['RepoName']]['FirstSeen'] = $o['FirstSeen'];
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
        $subBranch['Path'] = $caPaths['templates-community']."/".$i.".xml";
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
    writeJsonFile($caPaths['invalidXML_txt'],$invalidXML);
  else
    @unlink($caPaths['invalidXML_txt']);

  writeJsonFile($caPaths['community-templates-info'],$myTemplates);
  $GLOBALS['templates'] = $myTemplates;
  writeJsonFile($caPaths['categoryList'],$ApplicationFeed['categories']);

  foreach ($ApplicationFeed['repositories'] as &$repo) {
    if ( $repo['downloads'] ?? false ) {
      $repo['trending'] = $repo['trending'] / $repo['downloads'];
    }
  }

  writeJsonFile($caPaths['repositoryList'],$ApplicationFeed['repositories']);
  writeJsonFile($caPaths['extraBlacklist'],$ApplicationFeed['blacklisted']);
  writeJsonFile($caPaths['extraDeprecated'],$ApplicationFeed['deprecated']);

  updatePluginSupport($myTemplates);
  touch($caPaths['haveTemplates']);

  return true;
}

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

function getConvertedTemplates() {
  global $caPaths;

  getGlobals();
# Start by removing any pre-existing private (converted templates)
  $templates = &$GLOBALS['templates'];

  if ( empty($templates) ) return false;

  $myTemplates = [];
  foreach ($templates as $template) {
    if ( ! ($template['Private']??null) )
      $myTemplates[] = $template;
  }
  $appCount = count($myTemplates);
  $i = $appCount;

  if ( ! is_dir($caPaths['convertedTemplates']) ) {
    writeJsonFile($caPaths['community-templates-info'],$myTemplates);
    $GLOBALS['templates'] = $myTemplates;
    return;
  }

  $privateTemplates = glob($caPaths['convertedTemplates']."*/*.xml");
  foreach ($privateTemplates as $templateXML) {
    $o = addMissingVars(readXmlFile($templateXML));
    if ( ! $o['Repository'] ) continue;

    $o['Private']      = true;
    $o['RepoName']     = basename(pathinfo($templateXML,PATHINFO_DIRNAME))." Repository";
    $o['ID']           = $i;
    $o['Displayable']  = true;
    $o['Date']         = ( $o['Date'] ) ? strtotime( $o['Date'] ) : 0;
    $o['SortAuthor']   = $o['Author'];
    $o['Compatible']   = versionCheck($o);
    $o['Description']  = $o['Description'] ?: $o['Overview'];
    $o['CardDescription'] = strip_tags(trim(markdown($o['Description'])));
    $o = fixTemplates($o);
    $myTemplates[$i]  = $o;
    $i = ++$i;
  }
  writeJsonFile($caPaths['community-templates-info'],$myTemplates);
  $GLOBALS['templates'] = $myTemplates;
}

#############################
# Selects an app of the day #
#############################
function appOfDay($file) {
  global $caPaths,$caSettings,$sortOrder,$dynamixSettings;

  $max = getPost("maxHomeApps",10);
  $appOfDay = [];

  switch ($caSettings['startup']) {
    case "random":
      $oldAppDay = @filemtime($caPaths['appOfTheDay']);
      $oldAppDay = $oldAppDay ?: 1;
      $oldAppDay = intval($oldAppDay / 86400);
      $currentDay = intval(time() / 86400);
      if ( $oldAppDay == $currentDay ) {
        $appOfDay = readJsonFile($caPaths['appOfTheDay']);
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
      writeJsonFile($caPaths['appOfTheDay'],$appOfDay);

      break;
    case "onlynew":
      $sortOrder['sortBy'] = "FirstSeen";
      $sortOrder['sortDir'] = "Down";
      usort($file,"mySort");
      foreach ($file as $template) {
        if ( ! $template['Compatible'] == "true" && $caSettings['hideIncompatible'] == "true" ) continue;
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

        if ( $template['Name'] == "Unraid Patch" && ($caSettings['featuredDisable'] == "no" || is_file("/var/log/plugins/unraid.patch.plg")) ) continue;

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

#####################################################
# Checks selected app for eligibility as app of day #
#####################################################
function checkRandomApp($test) {
  global $caSettings;

  if ( $test['Name'] == "Community Applications" )  return false;
  if ( $test['BranchName'] ?? false)                        return false;
  if ( ! $test['Displayable'] )                     return false;
  if ( ! $test['Compatible'] && $caSettings['hideIncompatible'] == "true" ) return false;
  if ( $test['Blacklist'] )                         return false;
  if ( $test['Deprecated'] && ( $caSettings['hideDeprecated'] == "true" ) ) return false;

  return true;
}
##############################################################
# Gets the repositories that are listed on any given display #
##############################################################
function displayRepositories() {
  global $caPaths, $caSettings;

  getGlobals();

  $repositories = readJsonFile($caPaths['repositoryList']);
  if ( is_file($caPaths['community-templates-allSearchResults']) ) {
    $temp = readJsonFile($caPaths['community-templates-allSearchResults']);
    $templates = $temp['community'] ?? [];
  } else {
    $temp = readJsonFile($caPaths['community-templates-displayed']);
    $templates = $temp['community'] ?? [];
  }

  if ( is_file($caPaths['startupDisplayed']) ) {
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
    if ( $template['Deprecated'] && $caSettings['hideDeprecated'] == "true" ) continue;
    if ( ! $template['Compatible'] && $caSettings['hideIncompatible'] == "true" ) continue;

    $repoName = $template['RepoName'] ?? null;
    if ( ! $repoName || ! isset($repositories[$repoName]) ) continue;

    $repository = $repositories[$repoName];

    if ( $repoName == $caSettings['favourite'] ) {
      $fav = $repository;
      $fav['RepositoryTemplate'] = true;
      $fav['RepoName'] = $repoName;
      $fav['SortName'] = $repoName;
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

  writeJsonFile($caPaths['repositoriesDisplayed'],['community' => $combinedRepos]);
}



######################################################################################
# get_content - get the results from templates according to categories, filters, etc #
######################################################################################
function get_content() {
  global $caPaths, $caSettings;

  require_once __DIR__ . '/get_content_helpers.php';
  
  getGlobals();

  $filter       = getPost("filter",false);
  $categoryRaw  = getPost("category",false);
  $newApp       = filter_var(getPost("newApp",false),FILTER_VALIDATE_BOOLEAN);
  $mobileDevice = filter_var(getPost("mobileDevice",false),FILTER_VALIDATE_BOOLEAN);

  if ( $mobileDevice ) {
    $caSettings['maxPerPage'] = 12;
  }
  $maxHomeApps = getPost("maxHomeApps",12);

  $caSettings['startup'] = getPost("startupDisplay",false);
  @unlink($caPaths['repositoriesDisplayed']);
  @unlink($caPaths['dockerSearchActive']);

  $categoryContext = GetContentHelpers::resolveCategoryContext($categoryRaw);

  if ( $categoryContext['action'] === 'repos' ) {
    postReturn(displayRepositories());
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
    $disp = readJsonFile($caPaths['community-templates-allSearchResults']);
    $file = &$disp['community'];
  } else {
    $file = &$GLOBALS['templates'];
  }

  if ( empty($file)) return;

  if ( ! $filter && $categoryRegex === "/NONE/i" ) {
    if ( GetContentHelpers::handleHomeStartupDisplay($file, $maxHomeApps) ) {
      return;
    }
  } else {
    @unlink($caPaths['startupDisplayed']);
  }

  $displayApplications = [];
  $display  = [];
  $searchResults = [];

  foreach ($file as $template) {
    $template['NoInstall'] = $noInstallComment;

    if ( GetContentHelpers::handleSpecialTemplateDisplays($template, $display, $displayFlags) ) {
      continue;
    }

    if ( GetContentHelpers::shouldSkipTemplate($template, $displayFlags, $caSettings) ) {
      continue;
    }

    if ( $categoryRegex && ! preg_match($categoryRegex,$template['Category']) ) {
      continue;
    }
    if ( $categoryRegex === "/spotlight:/i" ) {
      $template['class'] = "spotlightHome";
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

  $o['display'] = "<div class='ca_templatesDisplay'>".display_apps()."</div>";

  postReturn($o);
}

########################################################
# force_update -> forces an update of the applications #
########################################################
function force_update() {
  global $caPaths, $caSettings;

  require_once __DIR__ . '/force_update_helpers.php';

  getGlobals();

  if (!empty($caPaths['localONLY'])) {
    ForceUpdateHelpers::resetTemplatesCache($caPaths, true);
  }

  $lastUpdatedOld = readJsonFile($caPaths['lastUpdated-old']);
  debug("old feed timestamp: ".($lastUpdatedOld['last_updated_timestamp'] ?? ""));

  $latestUpdate = ForceUpdateHelpers::fetchLatestUpdateMetadata($caPaths);

  if (ForceUpdateHelpers::shouldRefreshTemplates($latestUpdate, $lastUpdatedOld)) {
    ForceUpdateHelpers::resetTemplatesCache($caPaths);
  }

  if (!ForceUpdateHelpers::templatesAvailable($caPaths)) {
    if (!DownloadApplicationFeed()) {
      postReturn(ForceUpdateHelpers::buildDownloadFailureResponse($caPaths));
      return;
    }
  }

  getConvertedTemplates();
  moderateTemplates();

  $script = ForceUpdateHelpers::buildUpdateScript($caPaths, $caSettings);

  postReturn(['status' => "ok", 'script' => $script]);
}



####################################################################################
# display_content - displays the templates according to view mode, sort order, etc #
####################################################################################
function display_content() {
  global $caPaths;

  $pageNumber = getPost("pageNumber","1");
  $startup = getPost("startup",false);
  $selectedApps = json_decode(getPost("selected",false),true);
  $o['display'] = "";
  if ( file_exists($caPaths['community-templates-displayed']) || file_exists($caPaths['repositoriesDisplayed']) ) {
    $o['display'] = "<div class='ca_templatesDisplay'>".display_apps($pageNumber,$selectedApps,$startup)."</div>";
  }

  postReturn($o);
}

#####################################################################
# dismiss_warning - dismisses the warning from appearing at startup #
#####################################################################
function dismiss_warning() {
  global $caPaths;

  ca_file_put_contents($caPaths['warningAccepted'],"warning dismissed");
  postReturn(['status'=>"warning dismissed"]);
}
function dismiss_plugin_warning() {
  global $caPaths;

  ca_file_put_contents($caPaths['pluginWarning'],"disclaimer ok");
  postReturn(['status'=>"disclaimed"]);
}



###############################################################
# Displays the list of installed or previously installed apps #
###############################################################
function previous_apps($enableActionCentre=false) {
  global $caPaths, $caSettings;

  require_once __DIR__ . '/previous_apps_helpers.php';
  
  getGlobals();

  $context = PreviousAppsHelpers::resolvePreviousAppsContext($enableActionCentre, $caPaths);
  $installed = $context['installed'];
  $filter = $context['filter'];

  $info = getAllInfo();

  $file = &$GLOBALS['templates'];
  $extraBlacklist = readJsonFile($caPaths['extraBlacklist']) ?: [];
  $extraDeprecated = readJsonFile($caPaths['extraDeprecated']) ?: [];
  $displayed = [];
  $updateCount = 0;

  $dockerRunning = caIsDockerRunning();
  $dockerUpdateStatus = PreviousAppsHelpers::loadDockerUpdateStatus($dockerRunning, $caPaths);

  $displayed = array_merge(
    $displayed,
    PreviousAppsHelpers::collectDockerApplications($dockerRunning, $installed, $filter, $info, $updateCount, $caPaths, $file, $extraBlacklist, $extraDeprecated, $dockerUpdateStatus)
  );

  $displayed = array_merge(
    $displayed,
    PreviousAppsHelpers::collectPluginApplications($installed, $filter, $file, $caPaths, $caSettings, $updateCount)
  );

  if ( $enableActionCentre ) {
    return ! empty($displayed);
  }

  if ( isset($displayed) && is_array($displayed) ) {
    usort($displayed,"mySort");
  }
  $displayedApplications['community'] = $displayed;
  writeJsonFile($caPaths['community-templates-displayed'],$displayedApplications);
  if ( $installed == "action" && empty($displayed) ) {
    postReturn(['status'=>"ok",'script'=>'$(".actionCentre").hide();$(".startupButton").trigger("click");']);
  } else {
    postReturn(['status'=>"ok",'updateCount'=>$updateCount]);
  }
}

####################################################################################
# Removes an app from the previously installed list (ie: deletes the user template #
####################################################################################
function remove_application() {
  $application = realpath(getPost("application",""));
  if ( ! (strpos($application,"/boot/config") === false) ) {
    if ( pathinfo($application,PATHINFO_EXTENSION) == "xml" || pathinfo($application,PATHINFO_EXTENSION) == "plg" )
      @unlink($application);
  }
  postReturn(['status'=>"ok"]);
}

###################################################################################
# Checks for an update still available (to update display) after update installed #
###################################################################################
function updatePLGstatus() {
  global $caPaths;

  $filename = getPost("filename","");
  $displayed = readJsonFile($caPaths['community-templates-displayed']);
  $superCategories = array_keys($displayed);
  foreach ($superCategories as $category) {
    foreach ($displayed[$category] as $template) {
      if ( strpos($template['PluginURL'],$filename) )
        $template['UpdateAvailable'] = checkPluginUpdate($filename);

      $newDisplayed[$category][] = $template;
    }
  }
  writeJsonFile($caPaths['community-templates-displayed'],$newDisplayed);
  postReturn(['status'=>"ok"]);
}

#######################
# Uninstalls a docker #
#######################
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

  postReturn(['status'=>"Uninstalled"]);
}

##################################################
# Pins / Unpins an application for later viewing #
##################################################
function pinApp() {
  global $caPaths;

  $repository = getPost("repository","oops");
  $name = getPost("name","oops");
  $pinnedApps = readJsonFile($caPaths['pinnedV2']);
  if (isset($pinnedApps["$repository&$name"]) )
    $pinnedApps["$repository&$name"] = false;
  else
  $pinnedApps["$repository&$name"] = "$repository&$name";
  $pinnedApps = array_filter($pinnedApps);
  writeJsonFile($caPaths['pinnedV2'],$pinnedApps);
  postReturn(['status' => in_array(true,$pinnedApps)]);
}

######################################
# Gets if any apps are pinned or not #
######################################
function areAppsPinned() {
  global $caPaths;

  postReturn(['status' => in_array(true,readJsonFile($caPaths['pinnedV2']))]);
}

####################################
# Displays the pinned applications #
####################################
function pinnedApps() {
  global $caPaths, $caSettings;

  require_once __DIR__ . '/pinned_apps_helpers.php';

  getGlobals();

  $pinnedApps = array_filter((array)readJsonFile($caPaths['pinnedV2']));
  debug("pinned apps memory usage before: ".round(memory_get_usage()/1048576,2)." MB");
  $templates = &$GLOBALS['templates'];
  debug("pinned apps memory usage after: ".round(memory_get_usage()/1048576,2)." MB");

  PinnedAppsHelpers::clearPinnedCacheFiles($caPaths, [
    'community-templates-allSearchResults',
    'community-templates-catSearchResults',
    'repositoriesDisplayed',
    'startupDisplayed',
    'dockerSearchActive'
  ]);

  $displayed = [];
  $hideIncompatible = ($caSettings['hideIncompatible'] ?? "false") === "true";

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

  writeJsonFile($caPaths['community-templates-displayed'], $displayedApplications);
  postReturn(["status" => "ok", "script" => $script ?? ""]);
}

################################################
# Displays the possible branch tags for an app #
################################################
function displayTags() {
  $leadTemplate = getPost("leadTemplate","oops");
  $rename = getPost("rename","false");
  postReturn(['tags'=>formatTags($leadTemplate,$rename)]);
}

###########################################
# Displays The Statistics For The Appfeed #
###########################################
function statistics() {
  global $caPaths, $caSettings;

  getGlobals();

  if ( ! is_file($caPaths['statistics']) )
    $statistics = download_json($caPaths['statisticsURL'],$caPaths['statistics']);
  else
    $statistics = readJsonFile($caPaths['statistics']);

  download_json($caPaths['moderationURL'],$caPaths['moderation']);
  $statistics['totalModeration'] = count(readJsonFile($caPaths['moderation']));
  $repositories = readJsonFile($caPaths['repositoryList']);
  $templates = &$GLOBALS['templates'];
  pluginDupe();
  $invalidXML = readJsonFile($caPaths['invalidXML_txt']);
  $statistics['blacklist'] = $statistics['plugin'] = $statistics['docker'] = $statistics['private'] = $statistics['totalDeprecated'] = $statistics['totalIncompatible'] = $statistics['official'] = $statistics['invalidXML'] = 0;

  foreach ($templates as $template) {
    if ( ($template['Deprecated']??false) && ! ($template['Blacklist']??false) && ! ($template['BranchID']??false)) $statistics['totalDeprecated']++;

    if ( ! ($template['Compatible']??false) ) $statistics['totalIncompatible']++;

    if ( $template['Blacklist']??false ) $statistics['blacklist']++;

    if ( ($template['Private']??false) && ! ($template['Blacklist']??false)) {
      if ( ! ($caSettings['hideDeprecated'] == 'true' && ($template['Deprecated']??false)) ) {
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
    writeJsonFile($caPaths['fixedTemplates_txt'],$statistics['fixedTemplates']);
  else
    @unlink($caPaths['fixedTemplates_txt']);

  if ( is_file($caPaths['lastUpdated-old']) )
    $appFeedTime = readJsonFile($caPaths['lastUpdated-old']);

  $updateTime = tr(date("F",$appFeedTime['last_updated_timestamp']),0).date(" d, Y @ g:i a",$appFeedTime['last_updated_timestamp']);
  $defaultArray = ['caFixed' => 0,'totalApplications' => 0, 'repository' => 0, 'docker' => 0, 'plugin' => 0, 'invalidXML' => 0, 'blacklist' => 0, 'totalIncompatible' =>0, 'totalDeprecated' => 0, 'totalModeration' => 0, 'private' => 0, 'NoSupport' => 0];
  $statistics = array_merge($defaultArray,$statistics);

  foreach ($statistics as &$stat) {
    if ( ! $stat ) $stat = "0";
  }

  $currentServer = @file_get_contents($caPaths['currentServer']);
  if ( $currentServer != "Primary Server" )
    $currentServer = "<i class='fa fa-exclamation-triangle ca_serverWarning' aria-hidden='true'></i> $currentServer";

  $statistics['invalidXML'] = @count($invalidXML) ?: tr("unknown");
  $statistics['repositories'] = @count($repositories) ?: tr("unknown");
  $statistics['updateTime'] = $updateTime;
  $statistics['currentServer'] = tr($currentServer);
  $statistics['primaryServerUrl'] = $caPaths['application-feed'];
  $statistics['backupServerUrl'] = $caPaths['application-feedBackup'];

  postReturn(['statistics'=>$statistics]);
}

####################################################
# Creates the entries for autocomplete on searches #
####################################################
function populateAutoComplete() {
  global $caPaths, $caSettings;

  require_once __DIR__ . '/populate_autocomplete_helpers.php';

  getGlobals();

  $templates = PopulateAutoCompleteHelpers::waitForTemplates();
  $autoComplete = PopulateAutoCompleteHelpers::buildBaseSuggestions($caPaths);
  $autoComplete = PopulateAutoCompleteHelpers::addTemplateSuggestions($templates, $autoComplete, $caSettings);
  $autoComplete[tr("language")] = tr("Language");

  postReturn(['autocomplete'=>PopulateAutoCompleteHelpers::finalizeSuggestions($autoComplete)]);
}

##########################
# Displays the changelog #
##########################
function caChangeLog() {
  postReturn(["changelog"=>Markdown(ca_plugin("changes","/var/log/plugins/community.applications.plg"))."<br><br>"]);
}

###############################
# Populates the category list #
###############################
function get_categories() {
  global $caPaths, $sortOrder;

  getGlobals();

  $categories = readJsonFile($caPaths['categoryList']);
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
        $cat .= "<li class='subCategory'>";
        foreach($category['Sub'] as $subcategory) {
          $cat .= "<ul class='categoryMenu caMenuItem nonDockerSearch' data-category='{$subcategory['Cat']}'>".$subcategory['Des']."</ul>";
        }
        $cat .= "</li>";
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

##############################
# Get the html for the popup #
##############################
function getPopupDescription() {
  $appNumber = getPost("appPath","");
  postReturn(getPopupDescriptionSkin($appNumber));
}

#################################
# Get the html for a repo popup #
#################################
function getRepoDescription() {
  $repository = html_entity_decode(getPost("repository",""),ENT_QUOTES);
  postReturn(getRepoDescriptionSkin($repository));
}

###########################################
# Creates the XML for a container install #
###########################################
function createXML() {
  global $caPaths, $caSettings;

  getGlobals();

  $dockerSettings = parse_ini_file($caPaths['dockerSettings']);
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

    download_url($caPaths['RepositoryAssets'].str_replace("/","___",explode(":",$template['Repository'])[0]),"","",5);

    if ( $template['OriginalOverview'] ?? false )
      $template['Overview'] = $template['OriginalOverview'];
    if ( $template['OriginalDescription'] ?? false )
      $template['Description'] = $template['OriginalDescription'];

    $template['Icon'] = $template["Icon-{$caSettings['dynamixTheme']}"] ?? ($template['Icon'] ?? "");

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
    $unRaidDisks = parse_ini_file($caPaths['disksINI'],true);

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
    $unRaidVars = parse_ini_file($caPaths['unRaidVars']);
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
            if ( startsWith($config['value'],$caPaths['defaultAppdataPath']) || startsWith($config['@attributes']['Default'],$caPaths['defaultAppdataPath']) ) {
              if ( ! in_array($config['@attributes']['Target'],["/config","/data"]) ) {
                if ( ! ($TSFallBackDir ?? false) ) {
                  $TSFallBackDir  = $config['@attributes']['Target'] ?? "";
                }
              } else {
                $foundTSDir = true;
                $TSFallBackDir = "";
              }
            }
            $config['value'] = str_replace($caPaths['defaultAppdataPath'],$dockerSettings['DOCKER_APP_CONFIG_PATH'],$config['value']);
            $config['@attributes']['Default'] = str_replace($caPaths['defaultAppdataPath'],$dockerSettings['DOCKER_APP_CONFIG_PATH'],$config['@attributes']['Default']);
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
          if (is_file("{$caPaths['dockerManTemplates']}/my-{$template['Name']}.xml") ) {
            $template['Name'] .= "-1";
          } else break;
        }
      }
    }
    for ( ;; ) {
      if ($type == "second" && is_file("{$caPaths['dockerManTemplates']}/my-{$template['Name']}.xml") ) {
        $template['Name'] .= "-1";
      } else break;
    }

    if ( empty($template['Config']) ) // handles extra garbage entry being created on templates that are v1 only
      unset($template['Config']);

    // Add in TSStateDir

    if ( version_compare($caSettings['unRaidVersion'],"7.0.0",">") && isTailScaleInstalled() ) {
      if ( isset($template['Config']) && (! $foundTSDir) && ($TSFallBackDir ?? false) ) {
        $template['Config'][] = ["@attributes"=>["Display"=>"advanced","Description"=>"Fallback container directory for tailscale state information - Added By Community Applications","Default"=>$TSFallBackDir,"Name"=>"TailScale Fallback State Directory","Target"=>"CA_TS_FALLBACK_DIR","Type"=>"Variable"],"value"=>$TSFallBackDir];
      }
    }

    $xml = makeXML($template);
    @mkdir(dirname($xmlFile),0777,true);
    ca_file_put_contents($xmlFile,$xml);
  }
  postReturn(["status"=>"ok","cache"=>$cacheVolume ?? ""]);
}

########################
# Switch to a language #
########################
function switchLanguage() {
  global $caPaths;

  $language = getPost("language","");
  if ( $language == "en_US" )
    $language = "";

  if ( ! is_dir("/usr/local/emhttp/languages/$language") )  {
    postReturn(["error"=>"language $language is not installed"]);
    return;
  }
  $dynamixSettings = @parse_ini_file($caPaths['dynamixSettings'],true);
  $dynamixSettings['display']['locale'] = $language;
  write_ini_file($caPaths['dynamixSettings'],$dynamixSettings);
  postReturn(["status"=> "ok"]);
}

#######################################################
# Delete multiple checked off apps from previous apps #
#######################################################
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

############################################
# Get's the categories present on a search #
############################################
function getCategoriesPresent() {
  global $caPaths;

  if ( is_file($caPaths['community-templates-allSearchResults']) )
    $displayed = readJsonFile($caPaths['community-templates-allSearchResults']);
  else
    $displayed = readJsonFile($caPaths['community-templates-displayed']);

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

##################################
# Set's the favourite repository #
##################################
function toggleFavourite() {
  global $caPaths, $caSettings;

  $repository = html_entity_decode(getPost("repository",""),ENT_QUOTES);
  if ( $caSettings['favourite'] == $repository )
    $repository = "";

  $caSettings['favourite'] = $repository;
  write_ini_file($caPaths['pluginSettings'],$caSettings);
  postReturn(['status'=>"ok",'fav'=>$repository]);
}

####################################
# Returns the favourite repository #
####################################
function getFavourite() {
  global $caSettings;

  postReturn(["favourite"=>$caSettings['favourite']]);
}
##########################
# Changes the sort order #
##########################
function changeSortOrder() {
  global $caPaths, $sortOrder;

  $sortOrder = getPostArray("sortOrder");
  writeJsonFile($caPaths['sortOrder'],$sortOrder);

  if ( is_file($caPaths['community-templates-displayed']) ) {
    $displayed = readJsonFile($caPaths['community-templates-displayed']);
    if ($displayed['community'])
      usort($displayed['community'],"mySort");
    writeJsonFile($caPaths['community-templates-displayed'],$displayed);
  }
  if ( is_file($caPaths['community-templates-allSearchResults']) ) {
    $allSearchResults = readJsonFile($caPaths['community-templates-allSearchResults']);
    if ( $allSearchResults['community'] )
      usort($allSearchResults['community'],"mySort");
    writeJsonFile($caPaths['community-templates-allSearchResults'],$allSearchResults);
  }
  if ( is_file($caPaths['community-templates-catSearchResults']) ) {
    $catSearchResults = readJsonFile($caPaths['community-templates-catSearchResults']);
    if ( $catSearchResults['community'] )
      usort($catSearchResults['community'],"mySort");
    writeJsonFile($caPaths['community-templates-catSearchResults'],$catSearchResults);
  }
  if ( is_file($caPaths['repositoriesDisplayed']) ) {
    $reposDisplayed = readJsonFile($caPaths['repositoriesDisplayed']);
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
    writeJsonFile($caPaths['repositoriesDisplayed'],$reposDisplayed);
  }
  postReturn(['status'=>"ok"]);
}
############################################
# Gets the sort order when restoring state #
############################################
function getSortOrder() {
  global $sortOrder;

  postReturn(["sortBy"=>$sortOrder['sortBy'],"sortDir"=>$sortOrder['sortDir']]);
}

############################################################
# Reset the sort order to default when reloading Apps page #
############################################################
function defaultSortOrder() {
  global $caPaths, $sortOrder;

  $sortOrder['sortBy'] = "Name";
  $sortOrder['sortDir'] = "Up";
  writeJsonFile($caPaths['sortOrder'],$sortOrder);
  postReturn(['status'=>"ok"]);
}

###################################################################
# Checks whether we're on the startup screen when restoring state #
###################################################################
function onStartupScreen() {
  global $caPaths;

  postReturn(['status'=>is_file($caPaths['startupDisplayed'])]);
}

#######################################################################
# convert_docker - called when system adds a container from dockerHub #
#######################################################################
function convert_docker() {
  global $caPaths, $dockerManPaths;

  $dockerID = getPost("ID","");

  $file = readJsonFile($caPaths['dockerSearchResults']);
  $dockerIndex = searchArray($file['results'],"ID",$dockerID);
  $docker = $file['results'][$dockerIndex];
  $docker['Description'] = str_replace("&", "&amp;", $docker['Description']);

  $dockerfile['Name'] = $docker['Name'];
  $dockerfile['Support'] = $docker['DockerHub'];
  $dockerfile['Description'] = $docker['Description']."\n\nConverted By Community Applications   Always verify this template (and values)  against the support page for the container\n\n{$docker['DockerHub']}";
  $dockerfile['Overview'] = $dockerfile['Description'];
  $dockerfile['Registry'] = $docker['DockerHub'];
  $dockerfile['Repository'] = $docker['Repository'];
  $dockerfile['BindTime'] = "true";
  $dockerfile['Privileged'] = "false";
  $dockerfile['Networking']['Mode'] = "bridge";

  $existing_templates = array_diff(scandir($dockerManPaths['templates-user']),[".",".."]);
  foreach ( $existing_templates as $template ) {
    if ( strtolower($dockerfile['Name']) == strtolower(str_replace(["my-",".xml"],["",""],$template)) )
      $dockerfile['Name'] .= "-1";
  }

  $dockerXML = makeXML($dockerfile);

  ca_file_put_contents($caPaths['dockerSearchInstall'],$dockerXML);
  postReturn(['xml'=>$caPaths['dockerSearchInstall']]);
}

#########################################################
# search_dockerhub - returns the results from dockerHub #
#########################################################
function search_dockerhub() {
  global $caPaths;

  $filter     = getPost("filter","");
  $pageNumber = getPost("page","1");

  $filter = str_replace(" ","%20",$filter);
  $filter = str_replace("/","%20",$filter);
  $jsonPage = download_url("https://registry.hub.docker.com/v1/search?q=$filter&page=$pageNumber","",false,-1);
  //$jsonPage = shell_exec("curl -s -X GET 'https://registry.hub.docker.com/v1/search?q=$filter&page=$pageNumber'");
  $pageresults = json_decode($jsonPage,true);
  $num_pages = $pageresults['num_pages'];

  if ($pageresults['num_results'] == 0) {
    $o['display'] = "<div class='ca_NoDockerAppsFound'>".tr("No Matching Applications Found On Docker Hub")."</div>";
    $o['script'] = "$('#dockerSearch').hide();";
    postReturn($o);
    @unlink($caPaths['dockerSearchResults']);
    @unlink($caPaths['dockerSearchActive']);
    return;
  }

  touch($caPaths['dockerSearchActive']);
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

  writeJsonFile($caPaths['dockerSearchResults'],$dockerFile);
  postReturn(['display'=>displaySearchResults($pageNumber)]);
}
##############################################
# Gets the last update issued to a container #
##############################################
function getLastUpdate($ID) {
  getGlobals();

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
######################################
# Changes the max per page displayed #
######################################
function changeMaxPerPage() {
  global $caPaths, $caSettings;

  $max = getPost("max",24);
  if ($caSettings['maxPerPage'] == $max) {
    postReturn(["status"=>"same"]);
  } else {
    $caSettings['maxPerPage'] = $max;
    write_ini_file($caPaths['pluginSettings'],$caSettings);
    postReturn(["status"=>"updated"]);
  }
}
################################################################
# Enables if necessary the action centre                       #
# Basically a duplicate of action centre code in previous apps #
################################################################
function enableActionCentre() {
  global $caPaths;

# wait til check for updates is finished
  for ( $i=0;$i<100;$i++ ) {
    if ( is_file($caPaths['updateRunning']) && file_exists("/proc/".@file_get_contents($caPaths['updateRunning'])) ) {
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
    if ( ! is_file($caPaths['haveTemplates']) ) {
      debug("Action Centre sleeping - no templates yet");
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
  $file = readJsonFile($caPaths['community-templates-info']);
  $extraBlacklist = readJsonFile($caPaths['extraBlacklist']);
  $extraDeprecated = readJsonFile($caPaths['extraDeprecated']);

  if ( caIsDockerRunning() ) {
    $dockerUpdateStatus = readJsonFile($caPaths['dockerUpdateStatus']);
  } else {
    $dockerUpdateStatus = [];
  }

  $info = getAllInfo();
# $info contains all installed containers
# now correlate that to a template;
# this section handles containers that have not been renamed from the appfeed
  if ( caIsDockerRunning() ) {
    $all_files = glob("{$caPaths['dockerManTemplates']}/*.xml");
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
  $installedLanguages = array_diff(scandir($caPaths['languageInstalled']),[".","..","en_US"]);
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

###################################################
# Checks the requirements being met on an upgrade #
###################################################
function checkRequirements() {
  $requiresFile = getPost("requires","");
  if (! $requiresFile || ($requiresFile && is_file($requiresFile) ) ) {
    postReturn(['met'=>true]);
  } else {
    postReturn(['met'=>""]);
  }
}

########################################################
# Saves the list of plugins which are pending installs #
########################################################
function saveMultiPluginPending() {
  global $caPaths;

  $plugin = getPost("plugin","");
  $plugins = array_filter(explode("*",$plugin));
  if ( count($plugins) > 1 ) {
    exec("mkdir -p {$caPaths['pluginPending']}");
    foreach ($plugins as $plg) {
      if (! $plg ) continue;
      $pluginName = basename($plg);
      touch($caPaths['pluginPending'].$pluginName);
    }
  }
  postReturn(['status'=>'ok']);
}

##############################################
# Downloads the stats file in the background #
##############################################
function downloadStatistics() {
  global $caPaths;

  if ( ! is_file($caPaths['statistics']) )
    download_json($caPaths['statisticsURL'],$caPaths['statistics']);
}

###########################################################################
# Checks to see if a plugin installation or update is already in progress #
###########################################################################
function checkPluginInProgress() {
  global $caPaths;

  $pluginsPending = glob("{$caPaths['pluginPending']}/*");

  postReturn(['inProgress'=>empty($pluginsPending)? "" : "true"]);
}

###################################
# Clears any plugin pending flags #
###################################
function clearPluginInstallFlag() {
  global $caPaths;

  $pluginsPending = glob("{$caPaths['pluginPending']}/*");
  array_walk($pluginsPending,function($val,$key) {
    @unlink($val);
  });
  postreturn(['done']);
}

function networkAlreadyCreated() {
}

#######################################
# Clears the startup displayed flag   #
# in case of weird error              #
#######################################
function clearStartUpDisplayed() {
  global $caPaths;

  @unlink($caPaths['startupDisplayed']);
  postReturn(['done']);
}

# Logs Javascript errors being caught #
#######################################
function javascriptError() {
  return;
  global $caPaths, $caSettings;

  debug("******* ERROR **********\n".print_r($_POST,true));
}
?>