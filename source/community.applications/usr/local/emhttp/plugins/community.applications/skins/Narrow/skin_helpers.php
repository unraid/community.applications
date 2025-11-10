<?PHP
########################################
#                                      #
# Community Applications               #
# Copyright 2020-2025, Lime Technology #
# Copyright 2015-2025, Andrew Zawadzki #
#                                      #
# Licenced under GPLv2                 #
#                                      #
########################################

############################################################################
# function #  Convert CA markup tokens into clickable sidebar search links #
############################################################################
function caApplySidebarSearchLinks($text) {
  if (!is_string($text) || trim($text) === "") {
    return $text;
  }

  preg_match_all("/\/\/(.*?)&#92;/m", $text, $searchMatches);
  if (!count($searchMatches[1])) {
    return $text;
  }

  foreach ($searchMatches[1] as $searchResult) {
    $text = str_replace("//$searchResult&#92;","<a style=cursor:pointer; onclick=doSidebarSearch(&quot;$searchResult&quot;);>$searchResult</a>",$text);
  }

  return $text;
}

function caFormatOverview(array $template) {
  $overview = $template['Overview'] ?? "";
  if ($overview) {
    $ovr = $template['OriginalOverview'] ?: $overview;
  } else {
    $ovr = $template['OriginalDescription'] ?: ($template['Description'] ?? "");
  }

  $ovr = html_entity_decode($ovr);
  $ovr = str_replace(["[","]"],["<",">"],$ovr);
  $ovr = str_replace("\n","<br>",$ovr);
  $ovr = str_replace("    ","&nbsp;&nbsp;&nbsp;&nbsp;",$ovr);
  $ovr = trim($ovr);

  $ovr = markdown(strip_tags($ovr,"<br>"));
  return strip_tags($ovr,"<br>");
}

function caFormatTemplateChanges(array &$template, array $caPaths) {
  if ($template['Plugin']) {
    $templateURL = $template['PluginURL'];
    download_url($templateURL,$caPaths['pluginTempDownload'],"",5);
    $template['Changes'] = @ca_plugin("changes",$caPaths['pluginTempDownload']) ?: $template['Changes'];
    $template['pluginVersion'] = @ca_plugin("version",$caPaths['pluginTempDownload']) ?: $template['pluginVersion'];
  } else {
    if (!$template['Changes'] && $template['ChangeLogPresent']) {
      $templateURL = $template['caTemplateURL'] ?: $template['TemplateURL'];
      download_url($templateURL,$caPaths['pluginTempDownload'],"",5);
      $xml = readXmlFile($caPaths['pluginTempDownload']);
      if ($xml) {
        $template['Changes'] = $xml['Changes'];
      }
    }
  }

  $changes = $template['Changes'] ?: "";
  $changes = str_replace("    ","&nbsp;&nbsp;&nbsp;&nbsp;",$changes);
  $changes = str_replace(["[","]"],["<",">"],$changes);
  $changes = Markdown(strip_tags($changes,"<br>"));

  $template['Changes'] = $changes;
  if (trim($changes)) {
    $template['display_changes'] = trim($changes);
  }
}

################################################################################
# Collect docker state used by popups (running containers and update metadata) #
################################################################################
function caInitializeDockerState($DockerClient, array $caPaths, array &$caSettings) {
  $info = [];

  if (caIsDockerRunning()) {
    $infoTmp = getAllInfo();
    foreach ($infoTmp as $container) {
      $info[$container['Name']] = $container;
    }
    $dockerRunning = $DockerClient->getDockerContainers();
    $dockerUpdateStatus = readJsonFile($caPaths['dockerUpdateStatus'], []);
    $dockerUpdateStatus = is_array($dockerUpdateStatus) ? $dockerUpdateStatus : [];
  } else {
    $dockerRunning = [];
    $dockerUpdateStatus = [];
  }

  return [$info, $dockerRunning, $dockerUpdateStatus];
}

####################################################################################
# Locate a template entry based on an app identifier within the displayed listings #
####################################################################################
function caLocateTemplate(array $displayed, $appNumber) {
  $index = searchArray($displayed['community'] ?? [],"InstallPath",$appNumber);

  if ($index === false) {
    $ind = $index;
    while (true) {
      if ($ind !== false) {
        if (isset($displayed[$ind])) {
          $template = $displayed[$ind];
          if ($template['Name'] == ($displayed['community'][$ind]['Name'] ?? "")) {
            $index = $ind;
            break;
          }
        }
      }
      $ind = searchArray($displayed['community'] ?? [],"Path",$appNumber,$ind+1);
      if ($ind === false) {
        return [null, false];
      }
    }
  }

  if ($index !== false) {
    return [$displayed['community'][$index], $index];
  }

  return [null, false];
}

##########################################################################
# Determine selection status and identifiers for docker/plugin templates #
##########################################################################
function caResolveSelectionState(array &$template, array $dockerRunning) {
  $selected = null;
  $name = null;
  $pluginName = null;

  if (!$template['Plugin']) {
    if (!strpos($template['Repository'],"/")) {
      $template['Repository'] = "library/{$template['Repository']}";
    }
    foreach ($dockerRunning as $testDocker) {
      $repoMatch = ($template['Repository'] == $testDocker['Image']) || ("{$template['Repository']}:latest" == $testDocker['Image']);
      $nameMatch = ($template['Name'] == $testDocker['Name']);
      if ($repoMatch && $nameMatch) {
        $selected = true;
        $name = $testDocker['Name'];
        break;
      }
    }
  } else {
    $pluginName = basename($template['PluginURL']);
  }

  return [$selected, $name, $pluginName];
}

######################################################################
# Normalize and format the Additional Requirements field for display #
######################################################################
function caNormalizeRequiresField($requires) {
  if (!$requires) {
    return $requires;
  }

  $requires = str_replace(["\r","\n","&#xD;"],["","<br>",""],trim($requires));
  $requires = Markdown(strip_tags($requires,"<br>"));
  return caApplySidebarSearchLinks($requires);
}

##################################################################
# Build the Support button context for a template card or popup  #
##################################################################
function caBuildSupportContext(array $template, array $allRepositories, array $caSettings) {
  $supportContext = [];

  if ($template['ReadMe']) {
    $supportContext[] = ["icon"=>"ca_fa-readme","link"=>$template['ReadMe'],"text"=>tr("Read Me First")];
  }
  if ($template['Project']) {
    $supportContext[] = ["icon"=>"ca_fa-project","link"=>$template['Project'],"text"=>tr("Project")];
  }
  if ($template['Discord']) {
    $supportContext[] = ["icon"=>"ca_discord","link"=>$template['Discord'],"text"=>tr("Discord")];
  } elseif (isset($template['Repo']) && isset($allRepositories[$template['Repo']]['Discord'])) {
    $supportContext[] = ["icon"=>"ca_discord","link"=>$allRepositories[$template['Repo']]['Discord'],"text"=>tr("Discord")];
  }
  if ($template['Facebook']) {
    $supportContext[] = ["icon"=>"ca_facebook","link"=>$template['Facebook'],"text"=>tr("Facebook")];
  }
  if ($template['Reddit']) {
    $supportContext[] = ["icon"=>"ca_reddit","link"=>$template['Reddit'],"text"=>tr("Reddit")];
  }
  if ($template['Support']) {
    $supportContext[] = ["icon"=>"ca_fa-support","link"=>$template['Support'],"text"=>$template['SupportLanguage'] ?: tr("Support Forum")];
  }
  if ($template['Registry']) {
    $supportContext[] = ["icon"=>"ca_fa-docker","link"=>$template['Registry'],"text"=>tr("Registry")];
  }
  if ($caSettings['dev'] == "yes") {
    $supportContext[] = ["icon"=>"ca_fa-template","link"=>$template['caTemplateURL'] ?: ($template['TemplateURL'] ?? ""), "text"=>tr("Application Template")];
  }

  return $supportContext;
}

##############################################################################
# Prepare trend data/markup for templates with download and usage statistics #
##############################################################################
function caPrepareTrendVisuals(array &$template, &$templateDescription) {
    $chartLabel = "";
    $downloadLabel = "";
    $down = [];
    $totalDown = [];

    if ($template['trending']) {
      $allApps = &$GLOBALS['templates'];
      $allTrends = array_unique(array_column($allApps,"trending"));
      rsort($allTrends);
      $trendRank = array_search($template['trending'],$allTrends);
      if ($trendRank !== false) {
        $trendRank += 1;
      }
    }

    $template['Category'] = categoryList($template['Category'],true);
    $template['Icon'] = $template['Icon'] ? $template['Icon'] : "/plugins/dynamix.docker.manager/images/question.png";

    if ($template['Overview']) {
      $ovr = $template['OriginalOverview'] ?: $template['Overview'];
    }
    if (!isset($ovr)) {
      $ovr = $template['OriginalDescription'] ?: $template['Description'];
    }

    if (is_array($template['trends']) && (count($template['trends']) > 1)) {
      if ($template['downloadtrend']) {
        $templateDescription .= "<div><canvas id='trendChart{$template['ID']}' class='caChart' height=1 width=3></canvas></div>";
        $templateDescription .= "<div><canvas id='downloadChart{$template['ID']}' class='caChart' height=1 width=3></canvas></div>";
        $templateDescription .= "<div><canvas id='totalDownloadChart{$template['ID']}' class='caChart' height=1 width=3></canvas></div>";
      }
    }

    if (!isset($template['Language']) || !$template['Language']) {
      $changeLogMessage = "Note: not all ";
      $changeLogMessage .= $template['PluginURL'] || $template['Language'] ? "authors" : "maintainers";
      $changeLogMessage .= " keep up to date on change logs<br>";
      $template['display_changelogMessage'] = tr($changeLogMessage);
    }

    if (isset($template['trendsDate'])) {
      array_walk($template['trendsDate'],function (&$entry) {
        $entry = tr(date("M",$entry),0).date(" j",$entry);
      });
    }

    if (is_array($template['trends'])) {
      if (count($template['trends']) < count($template['downloadtrend'])) {
        array_shift($template['downloadtrend']);
      }

      $chartLabel = $template['trendsDate'];
      if (is_array($template['downloadtrend'])) {
        $minDownload = intval(((100 - $template['trends'][0]) / 100) * ($template['downloadtrend'][0]));
        foreach ($template['downloadtrend'] as $download) {
          $totalDown[] = $download;
          $down[] = intval($download - $minDownload);
          $minDownload = $download;
        }
        $downloadLabel = $template['trendsDate'];
      }
    }

    return [
      'chartLabel' => $chartLabel ?? "",
      'downloadLabel' => $downloadLabel ?? "",
      'down' => $down ?? [],
      'totalDown' => $totalDown ?? []
    ];
  }

#########################################################################
# Resolve pinned/unpinned state for templates based on user preferences #
#########################################################################
function caResolvePinnedState(array &$template, array $pinnedApps) {
    if ($pinnedApps["{$template['Repository']}&{$template['SortName']}"] ?? false) {
      $template['pinned'] = tr("Unpin App");
      $template['pinnedAlt'] = tr("Pin App");
      $template['pinnedTitle'] = tr("Click to unpin this application");
      $template['pinnedClass'] = "pinned";
    } else {
      $template['pinned'] = tr("Pin App");
      $template['pinnedAlt'] = tr("Unpin App");
      $template['pinnedTitle'] = tr("Click to pin this application");
      $template['pinnedClass'] = "unpinned";
    }
  }

############################################################################
# Retrieve language pack metadata and load translation files when required #
############################################################################
function caPrepareLanguagePack(array &$template, array $caPaths, array &$language) {
    if (!$template['Language']) {
      return null;
    }

    $countryCode = $template['LanguageDefault'] ? "en_US" : $template['LanguagePack'];
    if ($countryCode !== "en_US") {
      if (!is_file("{$caPaths['tempFiles']}/CA_language-$countryCode")) {
        download_url("{$caPaths['CA_languageBase']}$countryCode","{$caPaths['tempFiles']}/CA_language-$countryCode");
      }
      $language = is_file("{$caPaths['tempFiles']}/CA_language-$countryCode") ? @parse_lang_file("{$caPaths['tempFiles']}/CA_language-$countryCode") : [];
    } else {
      $language = [];
    }

    return $countryCode;
  }

#######################################################################
# Build the context menu for template actions (install/update/manage) #
#######################################################################
function caBuildActionsContext(array &$template, array &$caSettings, array $info, array $dockerRunning, array $dockerUpdateStatus, $selected, $name, $pluginName, array $caPaths) {
    $actionsContext = [];

    if ($template['Language']) {
      return $actionsContext;
    }

    if ($template['NoInstall'] || ($caSettings['NoInstalls'] ?? false)) {
      return $actionsContext;
    }

    if (!$template['Plugin']) {
      if (caIsDockerRunning()) {
        if ($selected) {
          if (($info[$name]['url'] ?? false) && ($info[$name]['running'] ?? false)) {
            $actionsContext[] = ["icon"=>"ca_fa-globe","text"=>tr("WebUI"),"action"=>"openNewWindow('{$info[$name]['url']}','_blank');"];
            if ($info[$name]['TSurl'] ?? false) {
              $actionsContext[] = ["icon"=>"ca_fa-globe","text"=>tr("Tailescale WebUI"),"action"=>"openNewWindow('{$info[$name]['TSurl']}','_blank');"];
            }
          }
          $tmpRepo = strpos($template['Repository'],":") ? $template['Repository'] : $template['Repository'].":latest";
          $tmpRepo = strpos($tmpRepo,"/") ? $tmpRepo : "library/$tmpRepo";
          if ($dockerUpdateStatus[$tmpRepo]['status'] == "false") {
            $template['UpdateAvailable'] = true;
            $actionsContext[] = ["icon"=>"ca_fa-update","text"=>tr("Update"),"action"=>"updateDocker('$name');"];
          } else {
            $template['UpdateAvailable'] = false;
          }
          if ($caSettings['defaultReinstall'] == "true" && !$template['Blacklist'] && $template['ID'] !== false) {
            if ($template['BranchID'] ?? false) {
              $actionsContext[] = ["icon"=>"ca_fa-install","text"=>tr("Install second instance"),"action"=>"displayTags('{$template['ID']}',true,'','".portsUsed($template)."');"];
            } else {
              $actionsContext[] = ["icon"=>"ca_fa-install","text"=>tr("Install second instance"),"action"=>"popupInstallXML('".addslashes($template['Path'])."','second','','".portsUsed($template)."');"];
            }
          }
          if (is_file($info[$name]['template'])) {
            $actionsContext[] = ["icon"=>"ca_fa-edit","text"=>tr("Edit"),"action"=>"popupInstallXML('".addslashes($info[$name]['template'])."','edit');"];
          }
          $actionsContext[] = ["divider"=>true];
          if ($info[$name]['template']) {
            $actionsContext[] = ["icon"=>"ca_fa-delete","text"=>"<span class='ca_red'>".tr("Uninstall")."</span>","action"=>"uninstallDocker('".addslashes($info[$name]['template'])."','{$template['Name']}');"];
            $template['Installed'] = true;
          }
        } elseif (!$template['Blacklist']) {
          if ($template['InstallPath']) {
            $userTemplate = readXmlFile($template['InstallPath'],false,false);
            if (!$template['Blacklist']) {
              $actionsContext[] = ["icon"=>"ca_fa-install","text"=>tr("Reinstall"),"action"=>"popupInstallXML('".addslashes($template['InstallPath'])."','user','','".portsUsed($userTemplate)."');"];
              $actionsContext[] = ["divider"=>true];
            }
            $actionsContext[] = ["icon"=>"ca_fa-delete","text"=>"<span class='ca_red'>".tr("Remove from Previous Apps")."</span>","action"=>"removeApp('{$template['InstallPath']}','{$template['Name']}');"];
          } else {
            if (!$template['Blacklist']) {
              if ($template['Compatible'] || $caSettings['hideIncompatible'] !== "true") {
                if (!$template['Deprecated'] || $caSettings['hideDeprecated'] !== "true") {
                  if (!isset($template['BranchID'])) {
                    $actionsContext[] = ["icon"=>"ca_fa-install","text"=>tr("Install"),"action"=>"popupInstallXML('".addslashes($template['Path'])."','default','','".portsUsed($template)."');"];
                  } else {
                    $actionsContext[] = ["icon"=>"ca_fa-install","text"=>tr("Install"),"action"=>"displayTags('{$template['ID']}',false,'','".portsUsed($template)."');"];
                  }
                }
              }
            }
          }
        }
      }
    } else {
      if (checkInstalledPlugin($template)) {
        $template['Installed'] = true;
        $template['installedVersion'] = ca_plugin("version","/var/log/plugins/$pluginName");
        if ($template['installedVersion'] != $template['pluginVersion'] || (is_file("/tmp/plugins/$pluginName") && $template['installedVersion'] != ca_plugin("version","/tmp/plugins/$pluginName"))) {
          if (is_file($caPaths['pluginTempDownload'])) {
            @copy($caPaths['pluginTempDownload'],"/tmp/plugins/$pluginName");
            $template['UpdateAvailable'] = true;
            $actionsContext[] = ["icon"=>"ca_fa-update","text"=>tr("Update"),"action"=>"installPlugin('$pluginName',true);"];
          }
        } else {
          $template['UpdateAvailable'] = false;
        }

        $pluginSettings = ($pluginName == "community.applications.plg") ? "ca_settings" : ca_plugin("launch","/var/log/plugins/$pluginName");
        if ($pluginSettings) {
          $actionsContext[] = ["icon"=>"ca_fa-pluginSettings","text"=>tr("Settings"),"action"=>"openNewWindow('/Apps/$pluginSettings');"];
        }
        if ($pluginName != "community.applications.plg") {
          if (!empty($actionsContext)) {
            $actionsContext[] = ["divider"=>true];
          }
          $actionsContext[] = ["icon"=>"ca_fa-delete","text"=>"<span class='ca_red'>".tr("Uninstall")."</span>","action"=>"uninstallApp('/var/log/plugins/$pluginName','".str_replace(" ","&nbsp;",$template['Name'])."');"];
        }
      } elseif (!$template['Blacklist']) {
        if (($template['Compatible'] || $caSettings['hideIncompatible'] !== "true") && !($template['UninstallOnly'] ?? false)) {
          if (!$template['Deprecated'] || $caSettings['hideDeprecated'] !== "true" || ($template['Deprecated'] && $template['InstallPath'])) {
            if (($template['RequiresFile'] && is_file($template['RequiresFile'])) || !$template['RequiresFile']) {
              $buttonTitle = $template['InstallPath'] ? tr("Reinstall") : tr("Install");
              $isDeprecated = $template['Deprecated'] ? "&deprecated" : "";
              $isDeprecated = $template['Compatible'] ? "&incompatible" : "";
              $actionsContext[] = ["icon"=>"ca_fa-install","text"=>$buttonTitle,"action"=>"installPlugin('{$template['PluginURL']}$isDeprecated');"];
            }
          }
        }
        if ($template['InstallPath']) {
          if (!empty($actionsContext)) {
            $actionsContext[] = ["divider"=>true];
          }
          $actionsContext[] = ["icon"=>"ca_fa-delete","text"=>"<span class='ca_red'>".tr("Remove from Previous Apps")."</span>","action"=>"removeApp('{$template['InstallPath']}','$pluginName');"];
        }
      }
      if (is_file($caPaths['pluginPending'].$pluginName)) {
        $actionsContext = [["text"=>tr("Pending")]];
      }
    }

    return $actionsContext;
  }

##############################################################################
# Build action contexts for language pack templates within the card renderer #
##############################################################################
function caBuildLanguageActions(array &$template, array $caPaths, ?string $countryCode, array $actionsContext) {
    if (!$template['Language']) {
      return $actionsContext;
    }

    $dynamixSettings = @parse_ini_file($caPaths['dynamixSettings'],true);
    $currentLanguage = $dynamixSettings['display']['locale'] ?? "en_US";
    $installedLanguages = array_diff(scandir("/usr/local/emhttp/languages"),[".",".."]);
    $installedLanguages = array_filter($installedLanguages,function ($v) {
      return is_dir("/usr/local/emhttp/languages/$v");
    });
    $installedLanguages[] = "en_US";
    $currentLanguage = (is_dir("/usr/local/emhttp/languages/$currentLanguage")) ? $currentLanguage : "en_US";

    if (in_array($countryCode,$installedLanguages)) {
      if ($currentLanguage != $countryCode) {
        $actionsContext[] = ["icon"=>"ca_fa-switchto","text"=>$template['SwitchLanguage'],"action"=>"CAswitchLanguage('$countryCode');"];
      }
    } else {
      $actionsContext[] = ["icon"=>"ca_fa-install","text"=>$template['InstallLanguage'],"action"=>"installLanguage('{$template['TemplateURL']}','$countryCode');"];
    }

    if (file_exists("/var/log/plugins/lang-$countryCode.xml")) {
      if (languageCheck($template)) {
        $template['UpdateAvailable'] = true;
        $actionsContext[] = ["icon"=>"ca_fa-update","text"=>$template['UpdateLanguage'],"action"=>"updateLanguage('$countryCode');"];
      }
      if ($currentLanguage != $countryCode) {
        if (!empty($actionsContext)) {
          $actionsContext[] = ["divider"=>true];
        }
        $actionsContext[] = ["icon"=>"ca_fa-delete","text"=>"<span class='ca_red'>".tr("Remove Language Pack")."</span>","action"=>"removeLanguage('$countryCode');"];
      }
    }

    if ($countryCode !== "en_US") {
      $template['Changes'] = "<center><a href='https://github.com/unraid/lang-$countryCode/commits/master' target='_blank'>".tr("Click here to view the language changelog")."</a></center>";
    } else {
      unset($template['Changes']);
    }

    if (file_exists($caPaths['pluginPending'].$template['LanguagePack']) || file_exists("{$caPaths['pluginPending']}lang-{$template['LanguagePack']}.xml")) {
      $actionsContext = [["text"=>tr("Pending")]];
    }

    return $actionsContext;
  }



########################################################################
# Assemble docker-related context (warnings, info caches) for listings #
########################################################################
function caDockerContext(array &$caSettings, array $caPaths): array {
  $dockerUpdateStatus = readJsonFile($caPaths['dockerUpdateStatus']);

  if ( caIsDockerRunning() ) {
    $info = getAllInfo();
    $dockerUpdateStatus = readJsonFile($caPaths['dockerUpdateStatus']);
  } else {
    $info = [];
    $dockerUpdateStatus = [];
  }

  $dockerWarningFlag = (! caIsDockerRunning() && ! ($caSettings['NoInstalls'] ?? false)) ? "true" : "false";
  $dockerNotEnabled = $dockerWarningFlag;

  if ($dockerNotEnabled === "true") {
    $unRaidVars = parse_ini_file($caPaths['unRaidVars']);
    $dockerVars = parse_ini_file($caPaths['docker_cfg']);

    if ($unRaidVars['mdState'] === "STARTED" && $dockerVars['DOCKER_ENABLED'] !== "yes") {
      $dockerNotEnabled = 1; // Array started, docker not enabled
    }
    if ($unRaidVars['mdState'] === "STARTED" && $dockerVars['DOCKER_ENABLED'] === "yes") {
      $dockerNotEnabled = 2; // Docker failed to start
    }
    if ($unRaidVars['mdState'] !== "STARTED") {
      $dockerNotEnabled = 3; // Array not started
    }
  }

  $displayHeader = "<script>addDockerWarning($dockerNotEnabled);var dockerNotEnabled = $dockerWarningFlag;</script>";

  return [
    'info'               => $info,
    'dockerUpdateStatus' => $dockerUpdateStatus,
    'dockerNotEnabled'   => $dockerNotEnabled,
    'dockerWarningFlag'  => $dockerWarningFlag,
    'displayHeader'      => $displayHeader
  ];
}

#####################################################################
# Normalize the structure of the multi-select payload used in CA UI #
#####################################################################
function caNormalizeSelectedApps($selectedApps): array {
  if (! $selectedApps) {
    $selectedApps = [];
  }

  $selectedApps['docker'] = $selectedApps['docker'] ?? [];
  $selectedApps['plugin'] = $selectedApps['plugin'] ?? [];

  $checkedOffApps = arrayEntriesToObject(@array_merge(@array_values($selectedApps['docker']), @array_values($selectedApps['plugin'])));

  return [$selectedApps, $checkedOffApps];
}

###################################################################
# Slice the current page worth of templates from the full listing #
###################################################################
function caSliceDisplayedTemplates(array $file, int $pageNumber, array $caSettings): array {
  $startingApp = ($pageNumber - 1) * $caSettings['maxPerPage'] + 1;
  $startingAppCounter = 0;
  $displayedTemplates = [];

  foreach ($file as $template) {
    $startingAppCounter++;
    if ($startingAppCounter < $startingApp) {
      continue;
    }
    $displayedTemplates[] = $template;
  }

  return $displayedTemplates;
}

#############################################################################
# Apply moderation overrides (blacklist/deprecation comments) to a template #
#############################################################################
function caApplyModerationOverrides(array $template, array $extraBlacklist, array $extraDeprecated): array {
  if (! $template['RepositoryTemplate']) {
    if (! $template['Blacklist'] && isset($extraBlacklist[$template['Repository']])) {
      $template['Blacklist'] = true;
      $template['ModeratorComment'] = $extraBlacklist[$template['Repository']];
    }

    if (! $template['Deprecated'] && isset($extraDeprecated[$template['Repository']])) {
      $template['Deprecated'] = true;
      $template['ModeratorComment'] = $extraDeprecated[$template['Repository']];
    }
  }

  return $template;
}

###########################################################################
# Inject clickable search links into strings that contain //term\ markers #
###########################################################################
function caAddSearchLinks(?string $text): ?string {
  if (! $text) {
    return $text;
  }

  preg_match_all("/\/\/(.*?)&#92;/m", $text, $searchMatches);
  if (count($searchMatches[1])) {
    foreach ($searchMatches[1] as $searchResult) {
      $text = str_replace("//$searchResult&#92;", "<a style=cursor:pointer; onclick=doSidebarSearch(&quot;$searchResult&quot;);>$searchResult</a>", $text);
    }
  }

  return $text;
}

##########################################################################
# Prepare moderator/CA comments and requirements for use in cards/popups #
##########################################################################
function caPrepareTemplateComments(array $template): array {
  $template['ModeratorComment'] = caAddSearchLinks($template['ModeratorComment'] ?? "");
  $template['CAComment'] = caAddSearchLinks($template['CAComment'] ?? "");

  $installComment = $template['ModeratorComment'] ? "<span class=ca_bold>{$template['ModeratorComment']}</span>" : ($template['CAComment'] ?? "");

  if ($template['Requires']) {
    $template['Requires'] = markdown(strip_tags(str_replace(["\r", "\n", "&#xD;", "'"], ["", "<br>", "", "&#39;"], trim($template['Requires'])), "<br>"));
    $template['Requires'] = caAddSearchLinks($template['Requires']);
    $installComment = tr("This application has additional requirements")."<br>{$template['Requires']}<br>$installComment";
  }

  $installComment = str_replace("\n", "", $installComment ?: "");

  return [$template, $installComment];
}

#############################################################################
# Build action contexts and flags for docker templates when rendering cards #
#############################################################################
function caProcessDockerTemplate(array $template, array $info, array $dockerUpdateStatus, array $caSettings, array $caPaths, string $installComment): array {
  $actionsContext = [];
  $selected = false;
  $name = "";

  if (caIsDockerRunning()) {
    foreach ($info as $testDocker) {
      $tmpRepo = strpos($template['Repository'], ":") ? $template['Repository'] : "{$template['Repository']}:latest";
      $tmpRepo = strpos($tmpRepo, "/") ? $tmpRepo : "library/$tmpRepo";
      if ((($tmpRepo == $testDocker['Image'] && $template['Name'] == $testDocker['Name']) || "{$tmpRepo}:latest" == $testDocker['Image']) && ($template['Name'] == $testDocker['Name'])) {
        $selected = true;
        $name = $testDocker['Name'];
        break;
      }
    }

    $template['Installed'] = $selected;
    if ($selected) {
      $ind = searchArray($info, "Name", $name);
      if ($info[$ind]['url'] && $info[$ind]['running']) {
        $actionsContext[] = ["icon" => "ca_fa-globe", "text" => tr("WebUI"), "action" => "openNewWindow('{$info[$ind]['url']}','_blank');"];
        if ($info[$ind]['TSurl'] ?? false) {
          $actionsContext[] = ["icon" => "ca_fa-globe", "text" => tr("Tailscale WebUI"), "action" => "openNewWindow('{$info[$ind]['TSurl']}','_blank');"];
        }
      }

      $tmpRepo = strpos($template['Repository'], ":") ? $template['Repository'] : "{$template['Repository']}:latest";
      $tmpRepo = strpos($tmpRepo, "/") ? $tmpRepo : "library/$tmpRepo";

      if (isset($dockerUpdateStatus[$tmpRepo]) && $dockerUpdateStatus[$tmpRepo]['status'] == "false") {
        $template['UpdateAvailable'] = true;
        $actionsContext[] = ["icon" => "ca_fa-update", "text" => tr("Update"), "action" => "updateDocker('$name');"];
      } else {
        $template['UpdateAvailable'] = false;
      }

      if ($caSettings['defaultReinstall'] == "true" && ! $template['Blacklist']) {
        if ($template['ID'] !== false) {
          if ($template['BranchID'] ?? false) {
            $actionsContext[] = ["icon" => "ca_fa-install", "text" => tr("Install second instance"), "action" => "displayTags('{$template['ID']}',true,'".str_replace(" ", "&#32;", htmlspecialchars($installComment, ENT_QUOTES))."','".portsUsed($template)."');"];
          } else {
            $actionsContext[] = ["icon" => "ca_fa-install", "text" => tr("Install second instance"), "action" => "popupInstallXML('".addslashes($template['Path'])."','second','".str_replace(" ", "&#32;", htmlspecialchars($installComment, ENT_QUOTES))."','".portsUsed($template)."');"];
          }
        }
      }

      if (is_file($info[$ind]['template'])) {
        $actionsContext[] = ["icon" => "ca_fa-edit", "text" => tr("Edit"), "action" => "popupInstallXML('".addslashes($info[$ind]['template'])."','edit');"];
      }

      $actionsContext[] = ["divider" => true];
      if ($info[$ind]['template']) {
        $actionsContext[] = ["icon" => "ca_fa-delete", "text" => tr("Uninstall"), "action" => "uninstallDocker('".addslashes($info[$ind]['template'])."','{$template['Name']}');"];
      }

      if ($template['DonateLink']) {
        $actionsContext[] = ["divider" => true];
        $actionsContext[] = ["icon" => "ca_fa-money", "text" => tr("Donate"), "action" => "openNewWindow('".addslashes($template['DonateLink'])."','_blank');"];
      }
    } elseif (! ($template['Blacklist'] ?? false) || ! ($template['Compatible'] ?? false)) {
      if ($template['InstallPath']) {
        $userTemplate = readXmlFile($template['InstallPath'], false, false);
        if (! $template['Blacklist']) {
          $actionsContext[] = ["icon" => "ca_fa-install", "text" => tr("Reinstall"), "action" => "popupInstallXML('".addslashes($template['InstallPath'])."','user','','".portsUsed($userTemplate)."');"];
          $actionsContext[] = ["divider" => true];
        }
        $actionsContext[] = ["icon" => "ca_fa-delete", "text" => tr("Remove from Previous Apps"), "alternate" => tr("Remove"), "action" => "removeApp('{$template['InstallPath']}','{$template['Name']}');"];
      } else {
        if (! ($template['BranchID'] ?? null)) {
          if (is_file("{$caPaths['dockerManTemplates']}/my-{$template['Name']}.xml")) {
            $test = readXmlFile("{$caPaths['dockerManTemplates']}/my-{$template['Name']}.xml", true);
            if ($template['Repository'] == $test['Repository']) {
              $userTemplate = readXmlFile($template['InstallPath'], false, false);
              $actionsContext[] = ["icon" => "ca_fa-install", "text" => "<span class='ca_red'>".tr("Reinstall From Previous Apps")."</span>", "action" => "popupInstallXML('".addslashes("{$caPaths['dockerManTemplates']}/my-{$template['Name']}").".xml','user','','".portsUsed($userTemplate)."');"];
              $actionsContext[] = ["divider" => true];
            }
          }
          $installCommentSanitized = str_replace("'", "&apos;", $installComment);
          $actionsContext[] = ["icon" => "ca_fa-install", "text" => tr("Install"), "action" => "popupInstallXML('".addslashes($template['Path'])."','default','".str_replace(" ", "&#32;", htmlspecialchars($installCommentSanitized, ENT_QUOTES))."','".portsUsed($template)."');"];
        } else {
          $actionsContext[] = ["icon" => "ca_fa-install", "text" => tr("Install"), "action" => "displayTags('{$template['ID']}',false,'".str_replace(" ", "&#32;", htmlspecialchars($installComment, ENT_QUOTES))."','".portsUsed($template)."');"];
        }
      }
    }
  }

  return [$template, $actionsContext];
}

#############################################################################
# Build action contexts and state for plugin templates when rendering cards #
#############################################################################
function caProcessPluginTemplate(array $template, array $caPaths, array $caSettings, string $installComment): array {
  $actionsContext = [];
  $pluginName = basename($template['PluginURL']);
  $template['Installed'] = checkInstalledPlugin($template);

  if ($template['Installed'])  {
    $pluginInstalledVersion = ca_plugin("version", "/var/log/plugins/$pluginName");
    if (file_exists("/tmp/plugins/$pluginName")) {
      $tmpPluginVersion = ca_plugin("version", "/tmp/plugins/$pluginName");
      if ($tmpPluginVersion && strcmp($template['pluginVersion'], $tmpPluginVersion) < 0) {
        $template['pluginVersion'] = $tmpPluginVersion;
      }
    }
    $template['pluginVersion'] = ca_plugin("version", "/tmp/plugins/$pluginName");

    if ((strcmp($pluginInstalledVersion, $template['pluginVersion']) < 0 || $template['UpdateAvailable']) && $template['Name'] !== "Community Applications" && (! ($template['UninstallOnly'] ?? false))) {
      @copy($caPaths['pluginTempDownload'], "/tmp/plugins/$pluginName");
      $template['UpdateAvailable'] = true;
      $actionsContext[] = ["icon" => "ca_fa-update", "text" => tr("Update"), "action" => "installPlugin('$pluginName',true,'','{$template['RequiresFile']}');"];
    } else {
      if (! $template['UpdateAvailable']) {
        $template['UpdateAvailable'] = false;
      }
    }
    $pluginSettings = ($pluginName == "community.applications.plg") ? "ca_settings" : ca_plugin("launch", "/var/log/plugins/$pluginName");
    if ($pluginSettings) {
      $actionsContext[] = ["icon" => "ca_fa-pluginSettings", "text" => tr("Settings"), "action" => "openNewWindow('/Apps/$pluginSettings');"];
    }

    if ($pluginName != "community.applications.plg") {
      if (! empty($actionsContext)) {
        $actionsContext[] = ["divider" => true];
      }
      $actionsContext[] = ["icon" => "ca_fa-delete", "text" => tr("Uninstall"), "action" => "uninstallApp('/var/log/plugins/$pluginName','".str_replace(" ", "&#32;", $template['Name'])."');"];
    }
    if ($template['DonateLink']) {
      $actionsContext[] = ["divider" => true];
      $actionsContext[] = ["icon" => "ca_fa-money", "text" => tr("Donate"), "action" => "openNewWindow('".addslashes($template['DonateLink'])."','_blank');"];
    }
  } elseif (! $template['Blacklist'] || ! $template['Compatible']) {
    $buttonTitle = $template['InstallPath'] ? tr("Reinstall") : tr("Install");
    if (! $template['InstallPath']) {
      $installComment = $template['CAComment'];
      if (! $installComment && $template['Requires']) {
        preg_match_all("/\/\/(.*?)\\\\/m", $template['Requires'], $searchMatches);
        if (count($searchMatches[1])) {
          foreach ($searchMatches[1] as $searchResult) {
            $template['Requires'] = str_replace("//$searchResult\\\\", $searchResult, $template['Requires']);
          }
        }
        $installComment = tr("This application has additional requirements")."<br>".markdown($template['Requires']);
      }
    }
    $isDeprecated = $template['Deprecated'] ? "&deprecated" : "";
    $isDeprecated = $template['Compatible'] ? "&incompatible" : $isDeprecated;

    $updateFlag = false;
    $requiresText = "";
    if ($template['RequiresFile'] && ! is_file($template['RequiresFile'])) {
      $requiresText = "AnythingHere";
      $updateFlag = true;
    } else {
      $installComment = $template['RequiresFile'] ? "" : $installComment;
    }
    if (! ($template['UninstallOnly'] ?? false)) {
      if ($template['Compatible']) {
        $actionsContext[] = ["icon" => "ca_fa-install", "text" => $buttonTitle, "action" => "installPlugin('{$template['PluginURL']}$isDeprecated','$updateFlag','".str_replace([" ", "\n"], ["&#32;", ""], htmlspecialchars(($installComment ?? ""), ENT_QUOTES))."','$requiresText');"];
      }
    }
    if ($template['InstallPath']) {
      if (! empty($actionsContext)) {
        $actionsContext[] = ["divider" => true];
      }
      $actionsContext[] = ["icon" => "ca_fa-delete", "text" => tr("Remove from Previous Apps"), "action" => "removeApp('{$template['InstallPath']}','$pluginName');"];
    }
  }

  if (file_exists($caPaths['pluginPending'].$pluginName)) {
    $actionsContext = [];
    $actionsContext[] = ["text" => tr("Pending")];
  }

  return [$template, $actionsContext];
}

##############################################################################
##############################################################################
function caProcessLanguageTemplate(array $template, array $caPaths, array $caSettings, array $actionsContext): array {
  $countryCode = $template['LanguageDefault'] ? "en_US" : $template['LanguagePack'];
  $dynamixSettings = @parse_ini_file($caPaths['dynamixSettings'], true);
  $currentLanguage = $dynamixSettings['display']['locale'] ?? "en_US";
  $installedLanguages = array_diff(scandir("/usr/local/emhttp/languages"), [".", ".."]);
  $installedLanguages = array_filter($installedLanguages, function ($v) {
    return is_dir("/usr/local/emhttp/languages/$v");
  });
  $installedLanguages[] = "en_US";
  $currentLanguage = (is_dir("/usr/local/emhttp/languages/$currentLanguage")) ? $currentLanguage : "en_US";

  if (in_array($countryCode, $installedLanguages)) {
    if ($currentLanguage != $countryCode) {
      $actionsContext[] = ["icon" => "ca_fa-switchto", "text" => $template['SwitchLanguage'], "action" => "CAswitchLanguage('$countryCode');"];
    }
  } else {
    $actionsContext[] = ["icon" => "ca_fa-install", "text" => tr("Install"), "action" => "installLanguage('{$template['TemplateURL']}','$countryCode');"];
  }

  if (file_exists("/var/log/plugins/lang-$countryCode.xml")) {
    $template['Installed'] = true;
    if (languageCheck($template)) {
      $template['UpdateAvailable'] = true;
      $actionsContext[] = ["icon" => "ca_fa-update", "text" => $template['UpdateLanguage'], "action" => "updateLanguage('$countryCode');"];
    }
    if ($currentLanguage != $countryCode) {
      if (! empty($actionsContext)) {
        $actionsContext[] = ["divider" => true];
      }
      $actionsContext[] = ["icon" => "ca_fa-delete", "text" => tr("Remove Language Pack"), "action" => "removeLanguage('$countryCode');"];
    }
  }

  if (file_exists($caPaths['pluginPending'].$template['LanguagePack']) || file_exists("{$caPaths['pluginPending']}lang-{$template['LanguagePack']}.xml")) {
    $actionsContext = [];
    $actionsContext[] = ["text" => tr("Pending")];
  }

  $template['Installed'] = is_dir("{$caPaths['languageInstalled']}{$template['LanguagePack']}") && ! $template['Uninstall'];

  return [$template, $actionsContext];
}

function getPageNavigation($pageNumber,$totalApps,$dockerSearch,$displayCount = true) {
  global $caSettings;

  $pageFunction = $dockerSearch ? "dockerSearch" : "changePage";
  $swipeCommands = [];

  if ( $dockerSearch ) {
    $caSettings['maxPerPage'] = 25;
    $swipeCommands[] = "$('.maxPerPage').hide();";
  } else {
    $swipeCommands[] = "$('.maxPerPage').show();";
  }

  if ( $caSettings['maxPerPage'] < 0 ) {
    return;
  }

  $maxPerPage = max(1, (int)$caSettings['maxPerPage']);
  $totalPages = (int)ceil($totalApps / $maxPerPage);

  if ( ($dockerSearch && $totalApps <= 25) || ($totalApps < 2) ) {
    return "<script>data.currentpage = 1;$('.maxPerPage').hide();</script>";
  }

  if ( $totalPages <= 1 ) {
    $toggle = ( $totalApps >= 25 ) ? "$('.maxPerPage').show();" : "$('.maxPerPage').hide();";
    return "<script>data.currentpage = 1;$toggle</script>";
  }

  $startApp = ($pageNumber - 1) * $maxPerPage + 1;
  $endApp = min($pageNumber * $maxPerPage, $totalApps);

  $navigation = [];
  $navigation[] = "</div><div class='navigationSection'><div class='navigationArea ca_center'>";

  if ( $displayCount ) {
    $navigation[] = "<span class='pageNavigation'>".sprintf(tr("Displaying %s - %s (of %s)"),$startApp,$endApp,$totalApps)."</span><br>";
  }

  $navigation[] = "<div class='pageNavigation'>";

  $previousPage = $pageNumber - 1;
  $navigation[] = ( $pageNumber == 1 )
    ? "<span class='pageLeft pageNumber pageNavNoClick'></span>"
    : "<span class='pageLeft pageNumber' onclick='$pageFunction(&quot;$previousPage&quot;)'></span>";

  $swipeCommands[] = "data.prevpage = $previousPage;";

  $startingPage = max(1, $pageNumber - 5);
  if ( $startingPage >= 3 ) {
    $navigation[] = "<a class='pageNumber' onclick='$pageFunction(&quot;1&quot;);'>1</a><span class='pageDots'></span>";
  } else {
    $startingPage = 1;
  }

  $endingPage = min($totalPages, $pageNumber + 5);

  for ( $i = $startingPage; $i <= $endingPage; $i++ ) {
    $navigation[] = ( $i == $pageNumber )
      ? "<span class='pageNumber pageSelected'>$i</span>"
      : "<a class='pageNumber' onclick='$pageFunction(&quot;$i&quot;);'>$i</a>";
  }

  if ( $endingPage != $totalPages ) {
    if ( ($totalPages - $pageNumber) > 6 ) {
      $navigation[] = "<span class='pageDots'></span>";
    }

    if ( ($totalPages - $pageNumber) > 5 ) {
      $navigation[] = "<a class='pageNumber' onclick='$pageFunction(&quot;$totalPages&quot;);'>$totalPages</a>";
    }
  }

  $nextPage = $pageNumber + 1;
  $navigation[] = ( $pageNumber < $totalPages )
    ? "<span class='pageNumber pageRight' onclick='$pageFunction(&quot;$nextPage&quot;);'></span>"
    : "<span class='pageRight pageNumber pageNavNoClick'></span>";

  $swipeCommands[] = ( $pageNumber < $totalPages ) ? "data.nextpage = $nextPage;" : "data.nextpage = 0;";

  $navigation[] = "</div></div></div><script>data.currentpage = $pageNumber;</script>";

  return implode("", $navigation)."<script>".implode("", $swipeCommands)."</script>";
}


######################################################################
# Summarize repository statistics (counts/downloads) for repo popups #
######################################################################
function caSummarizeRepositoryTemplates(array $templates, string $repository, array $settings): array {
  $totals = [
    'apps' => 0,
    'languages' => 0,
    'plugins' => 0,
    'docker' => 0,
    'downloads' => 0,
    'downloadDockerCount' => 0,
    'avgDownloads' => 0,
  ];

  foreach ($templates as $template) {
    if (($template['RepoName'] ?? null) !== $repository) {
      continue;
    }
    if (isset($template['BranchID'])) {
      continue;
    }
    if (!empty($template['Blacklist'])) {
      continue;
    }
    if (!empty($template['Deprecated']) && (($settings['hideDeprecated'] ?? "false") !== "false")) {
      continue;
    }
    if (empty($template['Compatible']) && (($settings['hideIncompatible'] ?? "false") !== "false")) {
      continue;
    }

    if (!empty($template['Registry'])) {
      $totals['docker']++;
      if (!empty($template['downloads'])) {
        $totals['downloads'] += $template['downloads'];
        $totals['downloadDockerCount']++;
      }
    }

    if (!empty($template['PluginURL'])) {
      $totals['plugins']++;
    }

    if (!empty($template['Language'])) {
      $totals['languages']++;
    }

    $totals['apps']++;
  }

  if ($totals['downloadDockerCount'] && $totals['downloads']) {
    $totals['avgDownloads'] = intval($totals['downloads'] / $totals['downloadDockerCount']);
  }

  return $totals;
}

##################################################################
# Build the donation section for repository popups               #
##################################################################
function caBuildRepoDonationSection(array $repo): string {
  if (empty($repo['DonateLink'])) {
    return "";
  }

  $donateText = $repo['DonateText'] ?? "";
  $donateLabel = tr("Donate");

  return "
      <div class='donateArea'>
        <div class='repoDonateText'>{$donateText}</div>
        <a class='caButton donate' href='{$repo['DonateLink']}' target='_blank'>$donateLabel</a>
      </div>
  ";
}

##################################################################
# Build the media (photos/videos) section for repository popups  #
##################################################################
function caBuildRepoMediaSection(array $repo): string {
  $hasPhoto = !empty($repo['Photo']);
  $hasVideo = !empty($repo['Video']);

  if (! $hasPhoto && ! $hasVideo) {
    return "";
  }

  $mediaHtml = "<div>";

  if ($hasPhoto) {
    $photos = is_array($repo['Photo']) ? $repo['Photo'] : [$repo['Photo']];
    foreach ($photos as $shot) {
      $shot = trim($shot);
      if ($shot === "") {
        continue;
      }
      $mediaHtml .= "<a class='screenshot' href='{$shot}'><img class='screen' src='{$shot}' onerror='this.style.display=&quot;none&quot;'></img></a>";
    }
  }

  if ($hasVideo) {
    $videos = is_array($repo['Video']) ? $repo['Video'] : [$repo['Video']];
    foreach ($videos as $vid) {
      $vid = trim($vid);
      if ($vid === "") {
        continue;
      }
      $thumbnail = getYoutubeThumbnail($vid);
      $mediaHtml .= "<a class='screenshot mfp-iframe videoPlayOverlay' href='{$vid}' style='position: relative; display: inline-block;'><img class='screen' src='".trim($thumbnail)."'></a>";
    }
  }

  $mediaHtml .= "</div>";

  return $mediaHtml;
}

##################################################################
# Build social/project link buttons for repository popups        #
##################################################################
function caBuildRepoLinkSection(array $repo): string {
  $definitions = [
    'WebPage' => ['class' => 'ca_webpage', 'label' => "Web Page"],
    'Forum' => ['class' => 'ca_forum', 'label' => "Forum"],
    'profile' => ['class' => 'ca_profile', 'label' => "Forum Profile"],
    'Facebook' => ['class' => 'ca_facebook', 'label' => "Facebook"],
    'Reddit' => ['class' => 'ca_reddit', 'label' => "Reddit"],
    'Twitter' => ['class' => 'ca_twitter', 'label' => "Twitter"],
    'Discord' => ['class' => 'ca_discord_popup', 'label' => "Discord"],
  ];

  $links = "";
  foreach ($definitions as $key => $definition) {
    if (empty($repo[$key])) {
      continue;
    }
    $label = tr($definition['label']);
    $links .= "<a class='appIconsPopUp {$definition['class']}' href='{$repo[$key]}' target='_blank'> {$label}</a>";
  }

  return "<div class='repoLinkArea'>{$links}</div>";
}

##################################################################
# Build the statistics table shown within repository popups      #
##################################################################
function caBuildRepoStatsSection(array $repo, array $totals, array $settings): string {
  $rows = [];

  if (($repo['FirstSeen'] ?? 0) > 1) {
    $rows[] = "<tr><td class='repoLeft'>".tr("Added to CA")."</td><td class='repoRight'>".date("F j, Y", $repo['FirstSeen'])."</td></tr>";
  }

  $rows[] = "<tr><td class='repoLeft'>".tr("Total Docker Applications")."</td><td class='repoRight'>{$totals['docker']}</td></tr>";
  $rows[] = "<tr><td class='repoLeft'>".tr("Total Plugin Applications")."</td><td class='repoRight'>{$totals['plugins']}</td></tr>";

  if (array_key_exists('languages', $totals)) {
    $rows[] = "<tr><td class='repoLeft'>".tr("Total Languages")."</td><td class='repoRight'>{$totals['languages']}</td></tr>";
  }

  if (($settings['dev'] ?? null) === "yes" && !empty($repo['url'])) {
    $rows[] = "<tr><td class='repoLeft'><a class='popUpLink' href='{$repo['url']}' target='_blank'>".tr("Repository URL")."</a></td></tr>";
  }

  $rows[] = "<tr><td class='repoLeft'>".tr("Total Applications")."</td><td class='repoRight'>{$totals['apps']}</td></tr>";

  if ($totals['downloadDockerCount'] && $totals['downloads']) {
    $rows[] = "<tr><td class='repoLeft'>".tr("Total Known Downloads")."</td><td class='repoRight'>".number_format($totals['downloads'])."</td></tr>";
    $rows[] = "<tr><td class='repoLeft'>".tr("Average Downloads Per App")."</td><td class='repoRight'>".number_format($totals['avgDownloads'])."</td></tr>";
  }

  $rowsHtml = implode("", $rows);

  return "
    <div class='repoStats'>Statistics</div>
      <table class='repoTable'>
        {$rowsHtml}
      </table>
  ";
}

##################################################################
# Render pagination controls for Docker Hub search results       #
##################################################################
function dockerNavigate($num_pages, $pageNumber) {
  return getPageNavigation($pageNumber,$num_pages * 25, true);
}

#################################################################################
# Attempt to find a template matching a repository name (with :latest fallback) #
#################################################################################
function findTemplateMatch(array $templates, string $repository) {
  $templateIndex = searchArray($templates, "Repository", $repository);

  if ($templateIndex === false) {
    $templateIndex = searchArray($templates, "Repository", "{$repository}.latest");
  }

  return $templateIndex;
}

##################################################################
# Enrich a Docker Hub search result with CA metadata/actions     #
##################################################################
function buildDockerHubResult(array $result, array $templates, bool $installsDisabled): array {
  $result['Icon'] = $result['Icon'] ?? "/plugins/dynamix.docker.manager/images/question.png";
  $result['Category'] = $result['Category'] ?? "Docker&nbsp;Hub&nbsp;Search";
  $result['Description'] = $result['Description'] ?: tr("No description present");
  $result['Compatible'] = true;
  $result['display_dockerName'] = "<a class='ca_applicationName ellipsis' style='cursor:pointer;' onclick='mySearch(this.innerText);' title='".tr("Search for similar containers")."'>{$result['Name']}</a>";
  $result['similarSearch'] = $result['Name'];

  if ($installsDisabled) {
    unset($result['actionsContext']);
  } else {
    $result['actionsContext'] = [["icon" => "ca_fa-install", "text" => tr("Install"), "action" => "dockerConvert({$result['ID']});"]];
  }

  $templateIndex = findTemplateMatch($templates, $result['Repository']);

  if ($templateIndex !== false && ! $templates[$templateIndex]['Deprecated'] && ! $templates[$templateIndex]['Blacklist']) {
    $result['caTemplateExists'] = true;
    $result['Icon'] = $templates[$templateIndex]['Icon'];
    $result['Description'] = $templates[$templateIndex]['Overview'] ?: $templates[$templateIndex]['Description'];
    unset($result['IconFA']);
    $result['ID'] = $templates[$templateIndex]['ID'];
    $result['actionsContext'] = [["icon" => "ca_fa-template", "text" => tr("Show Template"), "action" => "doSearch(false,'{$templates[$templateIndex]['Repository']}');"]];
  }

  return $result;
}

##################################################################
# Resolve the CA app type class/title for a template card        #
##################################################################
function caResolveAppType(array $template): array {
  $repositoryTemplate = !empty($template['RepositoryTemplate']);
  $category = $template['Category'] ?? "";

  if ($repositoryTemplate) {
    $appType = "appRepository";
  } else {
    $appType = !empty($template['Plugin']) ? "appPlugin" : "appDocker";

    if (!empty($template['Language'])) {
      $appType = "appLanguage";
    } elseif (!empty($template['Plugin']) && strpos($category, "Drivers") !== false) {
      $appType = "appDriver";
    }
  }

  switch ($appType) {
    case "appPlugin":
      $typeTitle = tr("This application is a plugin");
      break;
    case "appDocker":
      $typeTitle = tr("This application is a docker container");
      break;
    case "appLanguage":
      $typeTitle = tr("This is a language pack");
      break;
    case "appDriver":
      $typeTitle = tr("This application is a driver (plugin)");
      break;
    default:
      $typeTitle = "";
      break;
  }

  return [$appType, $typeTitle];
}

#######################################################################
# Normalize category labels used in cards (strip additional metadata) #
#######################################################################
function caNormalizeCategory(?string $category): string {
  if (!$category) {
    return "";
  }

  $category = explode(" ", $category)[0] ?? "";
  $category = explode(":", $category)[0] ?? "";

  return $category;
}

##################################################################
# Determine the author/maintainer label for a template card      #
##################################################################
function caResolveAuthor(array $template, string $repoName): string {
  if (!empty($template['DockerHub'])) {
    return $template['Author'] ?? "";
  }

  if (!empty($template['Plugin'])) {
    $author = $template['Author'] ?? "";
  } else {
    $author = $template['RepoShort'] ?? $repoName;
  }

  $author = $author ?? "";

  if ($author === $repoName) {
    if (strpos($author, "' Repository") !== false) {
      $author = sprintf(tr("%s's Repository"), str_replace("' Repository", "", $author));
    } elseif (strpos($author, "'s Repository") !== false) {
      $author = sprintf(tr("%s's Repository"), str_replace("'s Repository", "", $author));
    } elseif (strpos($author, " Repository") !== false) {
      $author = sprintf(tr("%s Repository"), str_replace(" Repository", "", $author));
    }
  }

  return $author;
}

##################################################################
# Build support button context for template cards (non-repo)     #
##################################################################
function caBuildSupportContextForApplication(array $template): array {
  $context = [];

  if (!empty($template['ReadMe'])) {
    $context[] = ["icon" => "ca_fa-readme", "link" => $template['ReadMe'], "text" => tr("Read Me First")];
  }
  if (!empty($template['Project'])) {
    $context[] = ["icon" => "ca_fa-project", "link" => $template['Project'], "text" => tr("Project")];
  }
  if (!empty($template['Discord'])) {
    $context[] = ["icon" => "ca_discord", "link" => $template['Discord'], "text" => tr("Discord")];
  }
  if (!empty($template['Support'])) {
    $context[] = [
      "icon" => "ca_fa-support",
      "link" => $template['Support'],
      "text" => $template['SupportLanguage'] ?: tr("Support Forum")
    ];
  }
  if (!empty($template['Registry'])) {
    $context[] = ["icon" => "docker", "link" => $template['Registry'], "text" => tr("Registry")];
  }

  return $context;
}

#######################################################################
# Build repository card overrides/context when rendering repo entries #
#######################################################################
function caBuildRepositoryContext(array $template, string $repoName, string $author): array {
  $supportContext = [];

  if (!empty($template['profile'])) {
    $supportContext[] = ["icon" => "ca_profile", "link" => $template['profile'], "text" => tr("Profile")];
  }
  if (!empty($template['Forum'])) {
    $supportContext[] = ["icon" => "ca_forum", "link" => $template['Forum'], "text" => tr("Forum")];
  }
  if (!empty($template['Twitter'])) {
    $supportContext[] = ["icon" => "ca_twitter", "link" => $template['Twitter'], "text" => tr("Twitter")];
  }
  if (!empty($template['Reddit'])) {
    $supportContext[] = ["icon" => "ca_reddit", "link" => $template['Reddit'], "text" => tr("Reddit")];
  }
  if (!empty($template['Facebook'])) {
    $supportContext[] = ["icon" => "ca_facebook", "link" => $template['Facebook'], "text" => tr("Facebook")];
  }
  if (!empty($template['WebPage'])) {
    $supportContext[] = ["icon" => "ca_webpage", "link" => $template['WebPage'], "text" => tr("Web Page")];
  }

  $name = str_replace(["' Repository", "'s Repository", " Repository"], "", html_entity_decode($author, ENT_QUOTES));
  $name = str_replace(["&apos;s", "'s"], "", $name);

  $fieldsToClear = [
    "Path",
    "author",
    "Repository",
    "Plugin",
    "IconFA",
    "ModeratorComment",
    "RecommendedDate",
    "UpdateAvailable",
    "Blacklist",
    "Official",
    "Trusted",
    "Pinned",
    "Deprecated",
    "Removable",
    "CAComment",
    "Installed",
    "Uninstalled",
    "Uninstall",
    "fav",
    "Beta",
    "Requires",
    "caTemplateExists",
    "actionCentre",
    "Overview",
    "imageNoClick"
  ];

  $overrides = array_fill_keys($fieldsToClear, "");
  $overrides['Name'] = $name;

  return [
    "holderClass" => "repositoryCard",
    "cardClass" => "ca_repoinfo",
    "id" => str_replace(" ", "", $repoName),
    "supportContext" => $supportContext,
    "actionsContext" => [],
    "name" => $name,
    "author" => "",
    "overrides" => $overrides
  ];
}

########################################################################
# Build the base card container, actions, and navigation footer markup #
########################################################################
function caBuildBottomLineSection(
  array $template,
  string $cardClass,
  ?string $popupType,
  string $holderClass,
  string $class,
  string $name,
  string $repoName
): array {
  $bottomClass = "ca_bottomLineSpotLight";
  $card = "";

  if (!empty($template['DockerHub'])) {
    $backgroundClickable = "dockerCardBackground";
    $cardStart = "
      <div class='dockerHubHolder {$class} {$popupType}'>";
    $card .= "
      <div class='ca_bottomLine {$bottomClass}'>
      <div class='caButton infoButton_docker ca_href' data-href='{$template['DockerHub']}'>".tr("Docker Hub")."</div>
      <div class='caButton actionsButton similarSearch' data-search='".($template['similarSearch'] ?? "")."'>".tr("Similar")."</div>";
  } else {
    $backgroundClickable = "ca_backgroundClickable";
    $dataPluginURL = empty($template['PluginURL']) ? "" : "data-pluginurl='{$template['PluginURL']}'";
    $cardStart = "
      <div class='ca_holder {$class} {$popupType} {$holderClass}' data-apppath='".($template['Path'] ?? "")."' data-appname='{$name}' data-repository='".htmlentities($repoName, ENT_QUOTES)."' {$dataPluginURL}>";
    $card .= "
      <div class='ca_bottomLine {$bottomClass}'>
      <div class='caButton infoButton {$cardClass}'>".tr("Info")."</div>
    ";
  }

  return [$cardStart, $card, $backgroundClickable];
}

##############################################################################
# Render the Support button(s) for a template card depending on context size #
##############################################################################
function caRenderSupportButtons(array $supportContext, string $name, string $id): string {
  if (empty($supportContext)) {
    return "";
  }

  if (count($supportContext) === 1) {
    $context = $supportContext[0];

    if ($context['text'] ?? "" === tr("Support Forum")) {
      $context['text'] = tr("Support");
    }

    return "<div class='caButton supportButton'><span class='ca_href' data-href='{$context['link']}' data-target='_blank'>{$context['text']}</span></div>";
  }

  $sanitizedName = preg_replace("/[^a-zA-Z0-9]+/", "", $name).$id;

  return "
      <div class='caButton supportButton supportButtonCardContext' id='support{$sanitizedName}' data-context='".json_encode($supportContext)."'>".tr("Support")."</div>
    ";
}

##################################################################
# Render the Actions button/menu for a template card             #
##################################################################
function caRenderActionsButtons(array $actionsContext, string $pluginUrl, string $languagePack, string $name, string $id): string {
  if (empty($actionsContext)) {
    return "";
  }

  if (count($actionsContext) === 1 && ($actionsContext[0]['text'] ?? "") === tr("Install")) {
    $dispText = $actionsContext[0]['alternate'] ?? $actionsContext[0]['text'];

    return "<div class='caButton actionsButton' data-pluginURL='{$pluginUrl}' data-languagePack='{$languagePack}' onclick={$actionsContext[0]['action']}>{$dispText}</div>";
  }

  $sanitizedName = preg_replace("/[^a-zA-Z0-9]+/", "", $name).$id;

  return "<div class='caButton actionsButton actionsButtonContext' data-pluginURL='{$pluginUrl}' data-languagePack='{$languagePack}' id='actions{$sanitizedName}' data-context='".json_encode($actionsContext, JSON_HEX_QUOT | JSON_HEX_APOS)."'>".tr("Actions")."</div>";
}

###################################################################
# Render the favourite indicator span used on template/repo cards #
###################################################################
function caRenderFavouriteSpan(array $template, string $repoName, bool $repositoryTemplate): string {
  $repositoryAttr = str_replace("'", "", $repoName);

  if (!empty($template['ca_fav'])) {
    $favText = $repositoryTemplate ? tr("This is your favourite repository") : tr("This application is from your favourite repository");
    return "<span class='favCardBackground favCardBackgroundShow' data-repository='{$repositoryAttr}' title='".htmlentities($favText)."'></span>";
  }

  return "<span class='favCardBackground favCardBackgroundHide' data-repository='{$repositoryAttr}'></span>";
}

##################################################################
# Render the pinned indicator span for template cards            #
##################################################################
function caRenderPinnedSpan(array $template): string {
  $repository = $template['Repository'] ?? "";
  $pindata = (strpos($repository, "/") !== false) ? $repository : "library/{$repository}";
  $sortName = $template['SortName'] ?? "";
  $pinStyle = !empty($template['Pinned']) ? "" : "display:none;";

  return "<span class='pinnedCard' title='".htmlentities(tr("This application is pinned for later viewing"))."' data-pindata='{$pindata}{$sortName}' style='{$pinStyle}'></span>";
}

##############################################################################
# Resolve the multi-select checkbox type (docker/plugin/language) for a card #
##############################################################################
function caResolveCheckboxType(string $appType): string {
  switch ($appType) {
    case "appDocker":
      return "docker";
    case "appPlugin":
    case "appDriver":
      return "plugin";
    case "appLanguage":
      return "language";
    default:
      return "";
  }
}

#######################################################################
# Render the multi-select checkbox used for bulk install/update flows #
#######################################################################
function caRenderCheckbox(array $template, string $previousAppName, string $name, string $type): string {
  $checked = $template['checked'] ?? "";

  if (!empty($template['Removable']) && empty($template['DockerInfo']) && empty($template['Installed']) && empty($template['Blacklist'])) {
    return "<input class='ca_multiselect' title='".tr("Check off to select multiple reinstalls")."' type='checkbox' data-name='{$previousAppName}' data-humanName='{$name}' data-type='{$type}' data-deletepath='".($template['InstallPath'] ?? "")."' {$checked}>";
  }

  if (!empty($template['actionCentre']) && !empty($template['UpdateAvailable'])) {
    return "<input class='ca_multiselect' title='".tr("Check off to select multiple updates")."' type='checkbox' data-name='{$previousAppName}' data-humanName='{$name}' data-type='{$type}' data-language='".($template['LanguagePack'] ?? "")."' {$checked}>";
  }

  return "";
}

##################################################################
# Render the icon (image/font-awesome) for a template card       #
##################################################################
function caBuildIconMarkup(array $template, bool $dockerHub): string {
  $imageNoClick = $dockerHub ? "noClick" : ($template['imageNoClick'] ?? "");

  if (empty($template['IconFA'])) {
    return "
      <img class='ca_displayIcon {$imageNoClick}' src='".($template['Icon'] ?? "")."' alt='Application Icon'></img>
    ";
  }

  $displayIcon = $template['IconFA'] ?: ($template['Icon'] ?? "");
  $displayIconClass = startsWith($displayIcon, "icon-") ? $displayIcon : "fa fa-{$displayIcon}";

  return "<i class='ca_appPopup {$displayIconClass} displayIcon {$imageNoClick}'></i>";
}

#######################################################################
# Build the header section (name/author/category) for a template card #
#######################################################################
function caBuildApplicationHeader(array $template, string $name, string $author, string $category, bool $official): string {
  $header = "
    <div class='ca_applicationName ellipsis'>{$name}
  ";

  if (!empty($template['CAComment']) || !empty($template['ModeratorComment']) || !empty($template['Requires'])) {
    $commentIcon = "";
    $warning = "";

    if (!empty($template['CAComment']) || !empty($template['ModeratorComment'])) {
      $commentIcon = "ca_fa-comment";
      $warning = tr("Click info to see the notes regarding this application");
    }

    if (!empty($template['Requires'])) {
      if (!empty($template['RequiresFile']) && !is_file($template['RequiresFile'])) {
        $commentIcon = "ca_fa-additional";
        $warning = tr("This application has additional requirements");
      }
    }

    $header .= "&nbsp;<span class='{$commentIcon} cardWarning' title='".htmlentities($warning, ENT_QUOTES)."'></span>";
  }

  $authorDisplay = $official ? tr("Official Container") : $author;

  $header .= "
        </div>
        <div class='ca_author ellipsis'>{$authorDisplay}</div>
        <div class='cardCategory'>{$category}</div>
  ";

  return $header;
}

#####################################################################
# Normalize overview/description copy for display in template cards #
#####################################################################
function caNormalizeOverview(array $template, string $name): string {
  $overview = $template['Overview'] ?? ($template['Description'] ?? "");

  if (! $overview) {
    $overview = tr("No description present");
  }

  $normalized = html_entity_decode($overview);
  $normalized = trim($normalized);
  $normalized = str_replace(["[", "]"], ["<", ">"], $normalized);
  $normalized = str_replace("\n", "<br>", $normalized);
  $normalized = markdown(strip_tags($normalized, "<br>"));
  $normalized = str_replace("\n", "<br>", $normalized);
  $overview = strip_tags(str_replace("<br>", " ", $normalized));

  $featured = $template['Featured'] ?? null;
  $uninstallOnly = $template['UninstallOnly'] ?? false;

  if ($uninstallOnly && $featured && is_file("/var/log/plugins/".basename($template['PluginURL'] ?? ""))) {
    $overview = "<span class='featuredIncompatible'>".sprintf(tr("%s is incompatible with your OS version.  Either uninstall %s or update the OS"), $name, $name)."</span>&nbsp;&nbsp;{$overview}";
  } elseif ( ((!($template['Compatible'] ?? null)) || $uninstallOnly) && $featured) {
    $overview = "<span class='featuredIncompatible'>".sprintf(tr("%s is incompatible with your OS version.  Please update the OS to proceed"), $name)."</span>&nbsp;&nbsp;{$overview}";
  }

  return $overview;
}

###############################################################################
# Build the status flag/banner (installed, updated, etc.) for a template card #
###############################################################################
function caBuildCardFlag(array $template, string $flagTextStart, string $flagTextEnd): string {
  if (!empty($template['UpdateAvailable'])) {
    return "
      <div class='betaCardBackground'>
        <div class='installedCardText ca_center'>".tr("UPDATED")."</div>
      </div>";
  }

  if ((!empty($template['Installed']) || !empty($template['Uninstall'])) && empty($template['actionCentre'])) {
    return "
      <div class='installedCardBackground'>
        <div class='installedCardText ca_center'>&nbsp;&nbsp;".tr("INSTALLED")."&nbsp;&nbsp;</div>
      </div>";
  }

  if (!empty($template['Blacklist'])) {
    return "
      <div class='warningCardBackground'>
        <div class='installedCardText ca_center' title='".tr("This application template / has been blacklisted")."'>".tr("Blacklisted")."{$flagTextEnd}</div>
      </div>
    ";
  }

  if (!empty($template['caTemplateExists'])) {
    return "
      <div class='greenCardBackground'>
        <div class='installedCardText ca_center' title='".tr("Template already exists in Apps")."'>".tr("Template")."</div>
      </div>
    ";
  }

  if (isset($template['Compatible']) && ! $template['Compatible']) {
    $verMsg = $template['VerMessage'] ?? tr("This application is not compatible with your version of Unraid");

    return "
      <div class='warningCardBackground'>
        <div class='installedCardText ca_center' title='{$verMsg}'>{$flagTextStart}".tr("Incompatible")."{$flagTextEnd}</div>
      </div>
    ";
  }

  if (!empty($template['Deprecated'])) {
    return "
      <div class='warningCardBackground'>
        <div class='installedCardText ca_center' title='".tr("This application template has been deprecated")."'>".tr("Deprecated")."{$flagTextEnd}</div>
      </div>
    ";
  }

  if (!empty($template['Official'])) {
    return "
      <div class='officialCardBackground'>
        <div class='installedCardText ca_center' title='".tr("This is an official container")."'>".tr("OFFICIAL")."</div>
      </div>
    ";
  }

  if (!empty($template['LTOfficial'])) {
    return "
      <div class='LTOfficialCardBackground'>
        <div class='installedCardText ca_center' title='".tr("This is an offical plugin")."'>".tr("LIMETECH")."</div>
      </div>
    ";
  }

  if (!empty($template['Beta'])) {
    return "
      <div class='betaCardBackground'>
        <div class='installedCardText ca_center'>".tr("BETA")."</div>
      </div>
    ";
  }

  if (!empty($template['Trusted'])) {
    return "
      <div class='spotlightCardBackground'>
        <div class='installedCardText ca_center' title='".tr("This container is digitally signed")."'>".tr("Digitally Signed")."</div>
      </div>
    ";
  }

  return "";
}
