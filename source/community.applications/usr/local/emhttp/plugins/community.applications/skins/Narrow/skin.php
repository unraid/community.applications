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

require_once __DIR__.'/skin_helpers.php';

######################################
# Generate the display for the popup #
######################################
function displayPopup($template) {
  global $caSettings;

  $template = is_array($template) ? $template : [];
  extract($template);

  $Repo = $Repo ?? "";
  $Private = $Private ?? false;
  $RepoName = $RepoName ?? $Repo;
  $RepoShort = $RepoShort ?? "";

  if (! $Private) {
    $RepoName = str_replace("' Repository","",str_replace("'s Repository","",$Repo));
    $RepoName = str_replace("Repository","",$RepoName);
  } else {
    $RepoName = str_replace("' Repository","",str_replace("'s Repository","",$RepoName));
    $Repo = $RepoName;
  }
  if ($RepoShort) {
    $RepoName = $RepoShort;
  }

  $actionsContext = is_array($actionsContext ?? null) ? $actionsContext : [];
  $supportContext = is_array($supportContext ?? null) ? $supportContext : [];
  $NoPin = $NoPin ?? false;
  $Blacklist = $Blacklist ?? false;
  $LanguagePack = $LanguagePack ?? "";
  $Language = $Language ?? false;
  $Plugin = $Plugin ?? false;
  $Installed = $Installed ?? false;
  $UpdateAvailable = $UpdateAvailable ?? false;
  $Beta = $Beta ?? false;
  $SortName = $SortName ?? "";
  $pinnedClass = $pinnedClass ?? "";
  $pinnedTitle = $pinnedTitle ?? "";
  $pinnedAlt = $pinnedAlt ?? "";
  $pinned = $pinned ?? "";
  $display_icon = $display_icon ?? "";
  $display_ovr = $display_ovr ?? "";
  $Category = $Category ?? "";
  $Repository = $Repository ?? "";
  $downloads = $downloads ?? 0;
  $stars = $stars ?? "";
  $LastUpdate = $LastUpdate ?? null;
  $installedVersion = $installedVersion ?? null;
  $pluginVersion = $pluginVersion ?? null;
  $Compatible = $Compatible ?? null;
  $UnknownCompatible = $UnknownCompatible ?? null;
  $MinVer = $MinVer ?? "";
  $MaxVer = $MaxVer ?? "";
  $Licence = $Licence ?? ($License ?? "");
  $ProfileIcon = $ProfileIcon ?? "";
  $DonateLink = $DonateLink ?? "";
  $DonateText = $DonateText ?? "";
  $display_changes = $display_changes ?? null;
  $display_changelogMessage = $display_changelogMessage ?? "";
  $downloadtrend = $downloadtrend ?? false;
  $trends = is_array($trends ?? null) ? $trends : [];
  $Requires = $Requires ?? "";
  $RequiresFile = $RequiresFile ?? "";
  $Deprecated = $Deprecated ?? false;
  $VerMessage = $VerMessage ?? "";
  $Blacklist = $Blacklist ?? false;
  $CAComment = $CAComment ?? "";
  $disclaimLineLink = $disclaimLineLink ?? "";
  $disclaimLine1 = $disclaimLine1 ?? "";
  $Featured = $Featured ?? false;
  $UninstallOnly = $UninstallOnly ?? false;
  $RecommendedReason = is_array($RecommendedReason ?? null) ? $RecommendedReason : [];
  $RecommendedWho = $RecommendedWho ?? "";
  $RecommendedDate = $RecommendedDate ?? time();
  $ModeratorComment = $ModeratorComment ?? "";

  $normalizeList = static function ($value) {
    if (empty($value)) {
      return [];
    }
    return is_array($value) ? $value : [$value];
  };

  $Screenshot = $normalizeList($Screenshot ?? []);
  $Photo = $normalizeList($Photo ?? []);
  $Video = $normalizeList($Video ?? []);

  $FirstSeen = $FirstSeen ?? 0;
  $FirstSeen = ($FirstSeen < 1433649600) ? 1433000000 : $FirstSeen;
  $DateAdded = tr(date("M j, Y", $FirstSeen), 0);
  $favRepoClass = (($caSettings['favourite'] ?? null) == $Repo) ? "fav" : "nonfav";

  if ($Requires && ! is_file($RequiresFile ?? "")) {
    $RequiresMessage = "<div class='additionalRequirementsHeader'>".tr("Additional Requirements")."</div><div class='additionalRequirements'>{$template['Requires']}</div>";
  } else {
    $RequiresMessage = "";
  }

  if ($Deprecated) {
    $ModeratorComment .= "<br>".tr("This application template has been deprecated");
  }
  if (! ($Compatible ?? false) && ! ($UnknownCompatible ?? false)) {
    $ModeratorComment .= $VerMessage ?: "<br>".tr("This application is not compatible with your version of Unraid.");
  }
  if ($Blacklist) {
    $ModeratorComment .= "<br>".tr("This application template has been blacklisted.");
  }
  if ($CAComment) {
    $ModeratorComment .= "  $CAComment";
  }
  if ($Language && $LanguagePack !== "en_US") {
    $ModeratorComment .= "<a href='$disclaimLineLink' target='_blank'>$disclaimLine1</a>";
  }
  if ((! ($Compatible ?? false) || ($UninstallOnly ?? false)) && ($Featured ?? false)) {
    $ModeratorComment = "<span class='featuredIncompatible'>".sprintf(tr("%s is incompatible with your OS version.  Please update the OS to proceed"), $Name)."</span>";
  }

  $ModeratorCommentBlock = "";
  if ($ModeratorComment) {
    $ModeratorCommentBlock = "<div class='modComment'><div class='moderatorCommentHeader'> ".tr("Attention:")."</div><div class='moderatorComment'>$ModeratorComment</div></div>";
  }

  $RecommendedBlock = "";
  if ($RecommendedReason) {
    $RecommendedLanguage = $_SESSION['locale'] ?? "en_US";
    if (empty($RecommendedReason[$RecommendedLanguage])) {
      $RecommendedLanguage = "en_US";
    }

    if (! empty($RecommendedReason[$RecommendedLanguage])) {
      preg_match_all("/\/\/(.*?)\\\\/m", $RecommendedReason[$RecommendedLanguage], $searchMatches);
      if (count($searchMatches[1])) {
        foreach ($searchMatches[1] as $searchResult) {
          $RecommendedReason[$RecommendedLanguage] = str_replace("//$searchResult\\\\", "<a style=cursor:pointer; onclick=doSidebarSearch(&quot;$searchResult&quot;);>$searchResult</a>", $RecommendedReason[$RecommendedLanguage]);
        }
      }

      if (! $RecommendedWho) {
        $RecommendedWho = tr("Unraid Staff");
      }

      $RecommendedBlock = "
      <div class='spotlightPopup'>
        <div class='spotlightIconArea ca_center'>
          <div><img class='spotlightIcon' src='".CA_PATHS['SpotlightIcon']."' alt='Spotlight'></img></div>
          <div class='spotlightDate spotlightDateSidebar'>".tr(date("M Y", $RecommendedDate), 0)."</div>
        </div>
        <div class='spotlightInfoArea'>
          <div class='spotlightHeader'></div>
          <div class='spotlightWhy'>".tr("Why we picked it")."</div>
          <div class='spotlightMessage'>{$RecommendedReason[$RecommendedLanguage]}</div>
          <div class='spotlightWho'>- $RecommendedWho</div>
        </div>
      </div>
      ";
    }
  }

  $mediaBlock = "";
  $pictures = $Screenshot ?: $Photo;
  if ($pictures || $Video) {
    $mediaSections = [];
    if ($pictures) {
      foreach ($pictures as $shot) {
        $shot = trim($shot);
        if ($shot === "") {
          continue;
        }
        $mediaSections[] = "<a class='screenshot mfp-image' href='$shot'><img class='screen' src='$shot'></img></a>";
      }
    }
    if ($Video) {
      foreach ($Video as $vid) {
        $vid = trim($vid);
        if ($vid === "") {
          continue;
        }
        $thumbnail = getYoutubeThumbnail($vid);
        $mediaSections[] = "<a class='screenshot mfp-iframe videoPlayOverlay' href='$vid' style='position: relative; display: inline-block;'><img class='screen' src='".trim($thumbnail)."'></a>";
      }
    }
    if ($mediaSections) {
      $mediaBlock = "<div>".implode("", $mediaSections)."</div>";
    }
  }

  $appType = $Plugin ? tr("Plugin") : tr("Docker");
  $appType = $Language ? tr("Language") : $appType;

  $detailsRows = [];
  $detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Application Type")."</td><td class='popupTableRight'>$appType</td></tr>";
  $detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Categories")."</td><td class='popupTableRight'>$Category</td></tr>";
  $detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Added")."</td><td class='popupTableRight'>$DateAdded</td></tr>";

  if (! $Plugin) {
    $downloadText = getDownloads($downloads);
    if ($downloadText) {
      $detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Downloads")."</td><td class='popupTableRight'>$downloadText</td></tr>";
    }
  }

  if (! $Plugin && ! $LanguagePack) {
    $detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Repository")."</td><td class='popupTableRight' style='white-space:nowrap;'>$Repository</td></tr>";
  }
  if ($stars) {
    $detailsRows[] = "<tr><td class='popupTableLeft'>".tr("DockerHub Stars:")."</td><td class='popupTableRight'>$stars <span class='dockerHubStar'></span></td></tr>";
  }
  if (! $Plugin && ! $Language) {
    $tagExplode = explode(":", $Repository);
    $tag = $tagExplode[1] ?? "";
    if (! $tag || strtolower($tag) === "latest") {
      $lastUpdateMsg = $LastUpdate ? tr(date("M j, Y", $LastUpdate), 0) : tr("Unknown");
      $detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Last Update:")."</td><td class='popupTableRight'><span id='template{$template['ID']}'>$lastUpdateMsg <span class='ca_note'><span class='ca_fa-asterisk'></span></span></span></td></tr>";
    }
  }
  if ($Plugin && isset($installedVersion)) {
    $detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Installed Version")."</td><td class='popupTableRight'>$installedVersion</td></tr>";
    if ($installedVersion != $pluginVersion) {
      $detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Upgrade Version")."</td><td class='popupTableRight'>$pluginVersion</td></tr>";
    }
  }
  if ($Plugin && ! isset($installedVersion)) {
    $detailsRows[] = "<tr><td calss='popupTableLeft'>".tr("Current Version")."</td><td class='popupTableRight'>$pluginVersion</td></tr>";
  }

  if ($Plugin || ! ($Compatible ?? null)) {
    if ($MinVer) {
      $detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Min OS")."</td><td class='popupTableRight'>$MinVer</td></tr>";
    }
    if ($MaxVer) {
      $detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Max OS")."</td><td class='popupTableRight'>$MaxVer</td></tr>";
    }
  }

  if ($Licence) {
    if (validURL($Licence)) {
      $Licence = "<img class='licence' src='$Licence' onerror='this.outerHTML=&quot;<a href=$Licence target=_blank>".tr("Click Here")."</a>&quot;;this.onerror=null;' ></img>";
    }
    $detailsRows[] = "<tr><td class='popupTableLeft'>".tr("Licence")."</td><td class='popupTableRight'>$Licence</td></tr>";
  }

  $chartBlock = "";
  if (count($trends) > 1 && $downloadtrend) {
    $chartBlock = "
        <div class='charts chartTitle'>".tr("Trends")."</div>
        <div><span class='charts'>Show: <span class='chartMenu selectedMenu' data-chart='trendChart'>".tr("Trend Per Month")."</span><span class='chartMenu' data-chart='downloadChart'>".tr("Downloads Per Month")."</span><span class='chartMenu' data-chart='totalDownloadChart'>".tr("Total Downloads")."</span></div>
        <div>
          <div><canvas id='trendChart' class='caChart' height=1 width=3></canvas></div>
          <div><canvas id='downloadChart' class='caChart' style='display:none;' height=1 width=3></canvas></div>
          <div><canvas id='totalDownloadChart' class='caChart' style='display:none;' height=1 width=3></canvas></div>
        </div>
    ";
  }

  $changeLogBlock = "";
  if (isset($display_changes)) {
    $changeLogBlock = "
      <div class='changelogTitle'>".tr("Change Log")."</div>
      <div class='changelogMessage'>$display_changelogMessage</div>
      <div class='changelog popup_readmore'>$display_changes</div>
    ";
  }

  $moderationBlock = "";
  $moderation = readJsonFile(CA_PATHS['statistics']);
  $repoKey = str_replace("library/", "", $Repository);
  if (isset($moderation['fixedTemplates'][$Repo][$repoKey])) {
    $errors = array_map(static function ($error) {
      return "<li class='templateErrorsList'>$error</li>";
    }, $moderation['fixedTemplates'][$Repo][$repoKey]);
    if ($errors) {
      $moderationBlock = "<div class='templateErrors'>".tr("Template Errors")."</div>".implode("", $errors);
    }
  }

  $statsNote = "";
  if (! $Plugin && ! $Language) {
    $statsNote = "<div><br><span class='ca_note ca_bold'><span class='ca_fa-asterisk'></span> ".tr("Note: All statistics are only gathered every 30 days")."</span></div>";
  }

  ob_start();
  ?>
  <div class='popup'>
    <div class='popupContent'>
      <div class='ca_popupIconArea'>
        <div class='popupIcon'><?= $display_icon ?></div>
        <div class='popupInfo'>
          <div class='popupName ellipsis'><?= $Name ?></div>
          <?php if (! $Language): ?>
            <div class='popupAuthorMain'><?= $Author ?></div>
          <?php endif; ?>

          <?php if ($actionsContext): ?>
            <?php if (count($actionsContext) === 1): ?>
              <div class='caButton actionsPopup'><span onclick="<?= $actionsContext[0]['action'] ?>"><?= str_replace("ca_red", "", $actionsContext[0]['text']) ?></span></div>
            <?php else: ?>
              <div class='caButton actionsPopup' id='actionsPopup'><?= tr("Actions") ?></div>
            <?php endif; ?>
          <?php endif; ?>

          <?php if (count($supportContext) === 1): ?>
            <div class='caButton supportPopup'><a href='<?= $supportContext[0]['link'] ?>' target='_blank'><span class='<?= $supportContext[0]['icon'] ?>'> <?= $supportContext[0]['text'] ?></span></a></div>
          <?php elseif ($supportContext): ?>
            <div class='caButton supportPopup' id='supportPopup'><span class='ca_fa-support'> <?= tr("Support") ?></span></div>
          <?php endif; ?>

          <?php if ($LanguagePack !== "en_US" && ! $Blacklist && ! $NoPin): ?>
            <div class='caButton pinPopup <?= $pinnedClass ?>' title='<?= $pinnedTitle ?>' data-pinnedalt='<?= $pinnedAlt ?>' data-repository='<?= $Repository ?>' data-name='<?= $SortName ?>'><span><?= $pinned ?></span></div>
          <?php endif; ?>

          <?php if (! caIsDockerRunning() && (! $Plugin && ! $Language)): ?>
            <div class='ca_red'><?= tr("Docker Service Not Enabled - Only Plugins Available To Be Installed Or Managed") ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class='popupDescription popup_readmore'><?= $display_ovr ?></div>
      <?= $RequiresMessage ?>
      <?= $ModeratorCommentBlock ?>
      <?= $RecommendedBlock ?>
      <?= $mediaBlock ?>
      <div>
        <div class='popupInfoSection'>
          <div class='popupInfoLeft'>
            <div class='rightTitle'><?= tr("Details") ?></div>
            <table class='popupTable contents'>
              <?= implode("", $detailsRows) ?>
            </table>
            <?php if (! ($Repo || $Private) && $DonateLink): ?>
              <div class='donateText'><?= $DonateText ?></div>
              <div class='donateDiv'><span class='caButton donate'><a href='<?= $DonateLink ?>' target='_blank'><?= tr("Donate") ?></a></span></div>
            <?php endif; ?>
          </div>
          <?php if ($Repo || $Private): ?>
            <div class='popupInfoLeft'>
              <?php $remoteIconPrefix = startsWith($ProfileIcon, "http") ? "<a class='screenshot mfp-image' href='$ProfileIcon'>" : ""; ?>
              <?php $remoteIconPostfix = $remoteIconPrefix ? "</a>" : ""; ?>
              <div class='popupAuthorTitle'><?= tr("Maintainer") ?></div>
              <div>
                <div class='popupAuthor'><?= $RepoName ?></div>
                <div class='popupAuthorIcon'><?= $remoteIconPrefix ?><img class='popupAuthorIcon' src='<?= $ProfileIcon ?>' alt='Repository Icon'></img><?= $remoteIconPostfix ?></div>
              </div>
              <div class='caButton ca_repoSearchPopUp popupProfile' data-repository='<?= htmlentities($Repo, ENT_QUOTES) ?>'><?= tr("All Apps") ?></div>
              <div class='caButton repoPopup' data-repository='<?= htmlentities($Repo, ENT_QUOTES) ?>'><?= tr("Profile") ?></div>
              <div class='caButton ca_favouriteRepo <?= $favRepoClass ?>' data-repository='<?= htmlentities($Repo, ENT_QUOTES) ?>'><?= tr("Favourite") ?></div>
              <?php if ($DonateLink): ?>
                <div class='donateText'><?= $DonateText ?></div>
                <div class='donateDiv'><span class='caButton donate'><a href='<?= $DonateLink ?>' target='_blank'><?= tr("Donate") ?></a></span></div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <?= $statsNote ?>
      <?= $chartBlock ?>
      <?= $changeLogBlock ?>
      <?= $moderationBlock ?>
      <?php /* Don't show the flags in the popup - being switched to badges and they don't resize due to the refactors
      if ($UpdateAvailable) {
        echo "
          <div class='upgradePopupBackground'>
          <div class='upgradePopupText ca_center'>".tr("UPDATED")."</div></div>
        ";
      } elseif ($Beta) {
        echo "
          <div class='betaPopupBackground'>
          <div class='betaPopupText ca_center'>".tr("BETA")."</div></div>
        ";
      } elseif ($Installed) {
        echo "
          <div class='installedPopup'>
          <div class='installedPopupText ca_center'>".tr("INSTALLED")."</div></div>
        ";
      }
      */ ?>
    </div>
  </div>
  </div>
  <?php

  return ob_get_clean();
}

function display_apps($pageNumber=1,$selectedApps=false,$startup=false) {

  $filesToCheck = [
    CA_PATHS['repositoriesDisplayed'] ?? null,
    CA_PATHS['community-templates-catSearchResults'] ?? null,
    CA_PATHS['community-templates-displayed'] ?? null
  ];

  $file = [];
  foreach ($filesToCheck as $path) {
    if ($path && is_file($path)) {
      $file = readJsonFile($path);
      break;
    }
  }

  $communityApplications = [];
  if (!empty($file['community']) && is_array($file['community'])) {
    $communityApplications = $file['community'];
  }
  $totalApplications = count($communityApplications);

  $display = ( $totalApplications ) ? my_display_apps($communityApplications,$pageNumber,$selectedApps,$startup) : "<div class='ca_NoAppsFound'>".tr("No Matching Applications Found")."</div><script>$('.multi_installDiv').hide();hideSortIcons();</script>";

  return $display;
}

function my_display_apps($file,$pageNumber=1,$selectedApps=false,$startup=false) {
  global $caSettings;

  $repositories = readJsonFile(CA_PATHS['repositoryList']);
  $extraBlacklist = readJsonFile(CA_PATHS['extraBlacklist']);
  $extraDeprecated = readJsonFile(CA_PATHS['extraDeprecated']);
  $pinnedApps = readJsonFile(CA_PATHS['pinnedV2']);

  $ct = "";
  $count = 0;

  $dockerContext = caDockerContext($caSettings);
  $displayHeader = $dockerContext['displayHeader'];

  [$selectedApps, $checkedOffApps] = caNormalizeSelectedApps($selectedApps);
  $displayedTemplates = caSliceDisplayedTemplates($file, $pageNumber, $caSettings);

  foreach ($displayedTemplates as $template) {
    $template = addMissingVars($template);
    $template = caApplyModerationOverrides($template, $extraBlacklist, $extraDeprecated);

    if ($template['RepositoryTemplate']) {
      $template['Icon'] = $template['icon'] ?? "/plugins/dynamix.docker.manager/images/question.png";

      if (! isset($template['bio'])) {
        $template['CardDescription'] = tr("No description present");
      } else {
        $template['bio'] = strip_tags(markdown($template['bio']));
        $template['Description'] = $template['bio'];
      }
      $template['display_dockerName'] = $template['RepoName'];
      $template['ca_fav'] = $caSettings['favourite'] && ($caSettings['favourite'] == $template['RepoName']);

      $ct .= displayCard($template);
      $count++;
      if ($count == $caSettings['maxPerPage']) {
        break;
      }
      continue;
    }

    [$template, $installComment] = caPrepareTemplateComments($template);

    $actionsContext = [];
    $canInstall = ! $template['NoInstall'] && ! ($caSettings['NoInstalls'] ?? false);

    if (! $template['Language']) {
      if (! $template['Plugin']) {
        if ($canInstall) {
          [$template, $actionsContext] = caProcessDockerTemplate($template, $dockerContext['info'], $dockerContext['dockerUpdateStatus'], $caSettings, $installComment);
        }
      } else {
        if ($canInstall) {
          [$template, $actionsContext] = caProcessPluginTemplate($template, $caSettings, $installComment);
        } else {
          $template['Installed'] = checkInstalledPlugin($template);
        }
      }
    }

    if ($template['Language']) {
      [$template, $actionsContext] = caProcessLanguageTemplate($template, $caSettings, $actionsContext);
    }

    $template['actionsContext'] = $actionsContext;

    $template['ca_fav'] = $caSettings['favourite'] && ($caSettings['favourite'] == $template['RepoName']);
    if (strpos($template['Repository'], "/") === false) {
      $template['Pinned'] = $pinnedApps["library/{$template['Repository']}&{$template['SortName']}"] ?? false;
    } else {
      $template['Pinned'] = $pinnedApps["{$template['Repository']}&{$template['SortName']}"] ?? false;
    }

    if (isset($template['Repo'])) {
      $template['Twitter'] = $template['Twitter'] ?? ($repositories[$template['Repo']]['Twitter'] ?? null);
      $template['Reddit'] = $template['Reddit'] ?? ($repositories[$template['Repo']]['Reddit'] ?? null);
      $template['Facebook'] = $template['Facebook'] ?? ($repositories[$template['Repo']]['Facebook'] ?? null);
      $template['Discord'] = $template['Discord'] ?? ($repositories[$template['RepoName']]['Discord'] ?? null);
    } else {
      $template['Twitter'] = $template['Twitter'] ?? null;
      $template['Reddit'] = $template['Reddit'] ?? null;
      $template['Facebook'] = $template['Facebook'] ?? null;
      $template['Discord'] = $template['Discord'] ?? null;
    }

    $previousAppName = $template['Plugin'] ? $template['PluginURL'] : $template['Name'];
    if (isset($checkedOffApps[$previousAppName])) {
      $template['checked'] = $checkedOffApps[$previousAppName] ? "checked" : "";
    }

    if (! $template['Plugin']) {
      $tmpRepo = $template['Repository'];
      if (! strpos($tmpRepo, "/")) {
        $tmpRepo = "library/$tmpRepo";
      }
      foreach ($dockerContext['info'] as $testDocker) {
        if (($tmpRepo == $testDocker['Image'] || "$tmpRepo:latest" == $testDocker['Image']) && ($template['Name'] == $testDocker['Name'])) {
          $template['Installed'] = true;
          break;
        }
      }
    } else {
      $pluginName = basename($template['PluginURL']);
      $template['Installed'] = checkInstalledPlugin($template);
    }

    if ($template['Language']) {
      $template['Installed'] = is_dir(CA_PATHS['languageInstalled']."{$template['LanguagePack']}") && ! $template['Uninstall'];
    }

    if (startsWith($template['Repository'], ["library/", "registry.hub.docker.com/library/"]) || strpos($template['Repository'], "/") === false) {
      $template['Official'] = true;
    }

    $ct .= displayCard($template);
    $count++;
    if ($count == $caSettings['maxPerPage']) {
      break;
    }
  }

  $ct .= getPageNavigation($pageNumber, count($file), false, true);

  if (! $count) {
    $displayHeader .= "<div class='ca_NoAppsFound'>".tr("No Matching Applications Found")."</div><script>hideSortIcons();</script>";
  }

  if ($count == 1 && ! isset($template['homeScreen']) && $pageNumber == 1) {
    if ($template['RepositoryTemplate']) {
      $displayHeader .= "<script>showRepoPopup('".htmlentities($template['RepoName'], ENT_QUOTES)."');</script>";
    } else {
      if ($template['InstallPath']) {
        $template['Path'] = $template['InstallPath'];
      }
      $displayHeader .= "<script>showSidebarApp('{$template['Path']}','{$template['Name']}');</script>";
    }
  }

  $displayHeader .= "<script>changeMax({$caSettings['maxPerPage']});</script>";

  return "$displayHeader$ct";
}

######################################
# Generate the display for the popup #
######################################
function getPopupDescriptionSkin($appNumber) {
  global $caSettings, $language, $DockerClient;

  getGlobals();

  $allRepositories = readJsonFile(CA_PATHS['repositoryList'], []);
  $allRepositories = is_array($allRepositories) ? $allRepositories : [];
  $extraBlacklist = readJsonFile(CA_PATHS['extraBlacklist'], []);
  $extraBlacklist = is_array($extraBlacklist) ? $extraBlacklist : [];
  $extraDeprecated = readJsonFile(CA_PATHS['extraDeprecated'], []);
  $extraDeprecated = is_array($extraDeprecated) ? $extraDeprecated : [];
  $pinnedApps = readJsonFile(CA_PATHS['pinnedV2'], []);
  $pinnedApps = is_array($pinnedApps) ? $pinnedApps : [];

  $templateDescription = "";

  [$info, $dockerRunning, $dockerUpdateStatus] = caInitializeDockerState($DockerClient, $caSettings);

  if (!is_file(CA_PATHS['warningAccepted'])) {
    $caSettings['NoInstalls'] = true;
  }

  $displayedPath = is_file(CA_PATHS['community-templates-allSearchResults'])
    ? CA_PATHS['community-templates-allSearchResults']
    : CA_PATHS['community-templates-displayed'];
  $displayed = readJsonFile($displayedPath, []);
  $displayed = is_array($displayed) ? $displayed : [];

  [$template, $index] = caLocateTemplate($displayed, $appNumber);

  if (!$template) {
    $file = &$GLOBALS['templates'];
    $index = searchArray($file,"Path",$appNumber);
    if ($index === false) {
      echo json_encode(["description"=>tr("Something really wrong happened.  Reloading the Apps tab will probably fix the problem")]);
      return;
    }
    $template = $file[$index];
  }

  $template = addMissingVars($template);

  if (!$template['Blacklist'] && isset($extraBlacklist[$template['Repository']])) {
    $template['Blacklist'] = true;
    $template['ModeratorComment'] = $extraBlacklist[$template['Repository']];
  }

  if (!$template['Deprecated'] && isset($extraDeprecated[$template['Repository']])) {
    $template['Deprecated'] = true;
    $template['ModeratorComment'] = $extraDeprecated[$template['Repository']];
  }

  $template['Profile'] = $allRepositories[$template['RepoName']]['profile'] ?? "";
  $template['ProfileIcon'] = $allRepositories[$template['RepoName']]['icon'] ?? "";

  $countryCode = caPrepareLanguagePack($template, $language);

  $donatelink = $template['DonateLink'];
  if ($donatelink) {
    $donatetext = $template['DonateText'];
  }

  [$selected, $name, $pluginName] = caResolveSelectionState($template, $dockerRunning);

  $template['display_ovr'] = caFormatOverview($template);

  caFormatTemplateChanges($template);

  $template['Icon'] = $template['Icon'] ?: "/plugins/dynamix.docker.manager/images/question.png";
  if ($template['IconFA']) {
    $template['IconFA'] = $template['IconFA'] ?: $template['Icon'];
    $templateIcon = startsWith($template['IconFA'],"icon-") ? "{$template['IconFA']} unraidIcon" : "fa fa-{$template['IconFA']}";
    $template['display_icon'] = "<i class='$templateIcon popupIcon'></i>";
  } else {
    $template['Icon'] = $template["Icon-{$caSettings['dynamixTheme']}"] ?? $template['Icon'];
    $template['display_icon'] = "<img class='popupIcon screenshot' href='{$template['Icon']}' src='{$template['Icon']}' alt='Application Icon'>";
  }

  $template['ModeratorComment'] = caApplySidebarSearchLinks($template['ModeratorComment']);
  $template['CAComment'] = caApplySidebarSearchLinks($template['CAComment']);
  $template['Requires'] = caNormalizeRequiresField($template['Requires']);

  $actionsContext = caBuildActionsContext($template, $caSettings, $info, $dockerRunning, $dockerUpdateStatus, $selected, $name ?? null, $pluginName ?? null);

  if ($template['Language']) {
    $actionsContext = caBuildLanguageActions($template, $countryCode, $actionsContext);
  }

  $supportContext = caBuildSupportContext($template, $allRepositories, $caSettings);

  $trendContext = caPrepareTrendVisuals($template, $templateDescription);
  $chartLabel = $trendContext['chartLabel'] ?? "";
  $downloadLabel = $trendContext['downloadLabel'] ?? "";
  $down = $trendContext['down'] ?? [];
  $totalDown = $trendContext['totalDown'] ?? [];

  caResolvePinnedState($template, $pinnedApps);

  $template['actionsContext'] = $actionsContext;
  $template['supportContext'] = $supportContext;

  @unlink(CA_PATHS['pluginTempDownload']);

  return [
    "description"=>displayPopup($template),
    "trendData"=>$template['trends'],
    "trendLabel"=>$chartLabel ?: "",
    "downloadtrend"=>$down ?: "",
    "downloadLabel"=>$downloadLabel ?: "",
    "totaldown"=>$totalDown ?: "",
    "totaldownLabel"=>$downloadLabel ?: "",
    "supportContext"=>$supportContext,
    "actionsContext"=>$actionsContext,
    "ID"=>$template['ID'] ?? false
  ];
}

#####################################
# Generate the display for the repo #
#####################################
function getRepoDescriptionSkin($repository) {
  global $caSettings;

  getGlobals();

  $repositories = readJsonFile(CA_PATHS['repositoryList']);
  $templates = &$GLOBALS['templates'];

  $repo = $repositories[$repository] ?? [];
  $iconUrl = $repo['icon'] ?? null;
  $iconPrefix = $iconUrl ? "<a class='screenshot mfp-image' href='{$iconUrl}'>" : "";
  $iconPostfix = $iconUrl ? "</a>" : "";
  $repoIcon = $iconUrl ?: "/plugins/dynamix.docker.manager/images/question.png";
  $repoBio = isset($repo['bio']) ? markdown($repo['bio']) : "<br><center>".tr("No description present");
  $favRepoClass = ($caSettings['favourite'] == $repository) ? "fav" : "nonfav";
  $encodedRepository = htmlentities($repository, ENT_QUOTES);

  $totals = caSummarizeRepositoryTemplates($templates, $repository, $caSettings);

  $donationSection = caBuildRepoDonationSection($repo);
  $mediaSection = caBuildRepoMediaSection($repo);
  $linksSection = caBuildRepoLinkSection($repo);
  $statsSection = caBuildRepoStatsSection($repo, $totals, $caSettings);

  $seeAllAppsLabel = tr("See All Apps");
  $favouriteLabel = tr("Favourite");
  $closeLabel = tr("CLOSE");
  $backLabel = tr("BACK");
  $repoBio = strip_tags($repoBio);

  $popupContent = "
    <div class='popupContent'>
      <div class='ca_popupIconArea'>
        <div class='popupIcon'>
          $iconPrefix<img class='popupIcon' src='{$repoIcon}'>$iconPostfix
        </div>
        <div class='popupInfo'>
          <div class='popupName ellipsis'>$repository</div>
          <div class='caButton ca_repoSearchPopUp popupProfile' data-repository='{$encodedRepository}'>$seeAllAppsLabel</div>
          <div class='caButton ca_favouriteRepo $favRepoClass' data-repository='{$encodedRepository}'>$favouriteLabel</div>
        </div>
      </div>
      <div class='popupRepoDescription'>$repoBio</div>
      $donationSection
      $mediaSection
    </div>
    <div class='repoLinks'>
      $linksSection
      $statsSection
    </div>
  ";

  $popup = "<div class='popup'>$popupContent</div>";

  return ["description"=>$popup];
}

##############################################################
# function that actually displays the results from dockerHub #
##############################################################
function displaySearchResults($pageNumber) {
  global $caSettings;

  getGlobals();

  $searchData = readJsonFile(CA_PATHS['dockerSearchResults']);
  $numPages = $searchData['num_pages'] ?? 0;
  $results = $searchData['results'] ?? [];
  $templates = &$GLOBALS['templates'];
  $caSettings['NoInstalls'] = !is_file(CA_PATHS['warningAccepted']);

  $cards = array_map(
    function ($result) use ($templates, $caSettings) {
      $preparedResult = buildDockerHubResult($result, $templates, $caSettings['NoInstalls']);
      return displayCard($preparedResult);
    },
    $results
  );

  $cardsHtml = implode("", $cards);

  return "<div class='ca_templatesDisplay'>{$cardsHtml}</div>".dockerNavigate($numPages, $pageNumber);
}

###########################
# Generate the app's card #
###########################
function displayCard($template) {

  if (!is_array($template)) {
    return "";
  }

  if (!empty($template['RepositoryTemplate'])) {
    $template['DockerHub'] = false;
  }

  if (!empty($template['DockerHub'])) {
    $popupType = null;
  } else {
    $popupType = !empty($template['RepositoryTemplate']) ? "ca_repoPopup" : "ca_appPopup";

    if (empty($template['RepositoryTemplate']) && !empty($template['Language'])) {
      $template['Category'] = "";
    }
  }

  $class = "spotlightHome";
  $repoName = $template['RepoName'] ?? "";
  [$appType, $typeTitle] = caResolveAppType($template);

  if (!empty($template['InstallPath'])) {
    $template['Path'] = $template['InstallPath'];
  }

  $template['Category'] = caNormalizeCategory($template['Category'] ?? "");
  $author = caResolveAuthor($template, $repoName);

  $id = $template['ID'] ?? "";
  $holderClass = "";
  $cardClass = "ca_appPopup";
  $actionsContext = $template['actionsContext'] ?? [];
  $name = $template['Name'] ?? "";

  if (!empty($template['RepositoryTemplate'])) {
    $repositoryContext = caBuildRepositoryContext($template, $repoName, $author);
    $holderClass = $repositoryContext['holderClass'];
    $cardClass = $repositoryContext['cardClass'];
    $id = $repositoryContext['id'];
    $supportContext = $repositoryContext['supportContext'];
    $actionsContext = $repositoryContext['actionsContext'];
    $name = $repositoryContext['name'];
    $author = $repositoryContext['author'];
    $template = array_merge($template, $repositoryContext['overrides']);
  } else {
    $supportContext = caBuildSupportContextForApplication($template);
  }

  [$cardStart, $card, $backgroundClickable] = caBuildBottomLineSection($template, $cardClass, $popupType, $holderClass, $class, $name, $repoName);

  $card .= caRenderSupportButtons($supportContext, $name, (string) $id);
  $card .= caRenderActionsButtons($actionsContext, $template['PluginURL'] ?? "", $template['LanguagePack'] ?? "", $name, (string) $id);
  $card .= "<span class='{$appType}' title='".htmlentities($typeTitle)."'></span>";
  $card .= caRenderFavouriteSpan($template, $repoName, !empty($template['RepositoryTemplate']));
  $card .= caRenderPinnedSpan($template);

  $type = caResolveCheckboxType($appType);
  $previousAppName = !empty($template['Plugin']) ? ($template['PluginURL'] ?? "") : $name;
  $card .= caRenderCheckbox($template, $previousAppName, $name, $type);

  $card .= "</div>";
  $card .= "<div class='{$cardClass} {$backgroundClickable}'>";
  $card .= "<div class='ca_iconArea'>";
  $card .= caBuildIconMarkup($template, !empty($template['DockerHub']));
  $card .= "</div>";
  $card .= caBuildApplicationHeader($template, $name, $author, $template['Category'], !empty($template['Official']));
  $card .= "</div>";

  $overview = caNormalizeOverview($template, $name);
  $descClass = !empty($template['RepositoryTemplate']) ? "cardDescriptionRepo" : "cardDescription";
  $card .= "<div class='{$descClass} {$backgroundClickable}'><div class='cardDesc'>{$overview}</div></div>";

  if (!empty($template['RecommendedDate'])) {
    $card .= "
      <div class='homespotlightIconArea ca_center''>
        <div><img class='spotlightIcon' src='".CA_PATHS['SpotlightIcon']."' alt='Spotlight'></img></div>
        <div class='spotlightDate'>".tr(date("M Y", $template['RecommendedDate']), 0)."</div>
      </div>
    ";
  }

  if (!empty($template['Installed']) || !empty($template['Uninstall'])) {
    $flagTextStart = tr("Installed")."<br>";
    $flagTextEnd = "";
  } else {
    $flagTextStart = "&nbsp;";
    $flagTextEnd = "&nbsp;";
  }

  $cardFlag = caBuildCardFlag($template, $flagTextStart, $flagTextEnd);

  $cardEnd = "</div>";
  $cardFinish = "<div>{$cardFlag} {$cardStart} {$card} {$cardEnd}</div>";

  return str_replace(["\t", "\n"], "", $cardFinish);
}
?>
