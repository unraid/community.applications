<?php

class PreviousAppsHelpers {
  public static function clearPreviousAppsCaches() {
    $paths = [
      'community-templates-allSearchResults',
      'community-templates-catSearchResults',
      'repositoriesDisplayed',
      'startupDisplayed',
      'dockerSearchActive'
    ];

    foreach ($paths as $pathKey) {
      if ( isset(CA_PATHS[$pathKey]) ) {
        @unlink(CA_PATHS[$pathKey]);
      }
    }
  }

  public static function resolvePreviousAppsContext($enableActionCentre) {
    if ( $enableActionCentre ) {
      return ['installed' => "action", 'filter' => ""];
    }

    $installed = getPost("installed","");
    $filter = getPost("filter","");
    self::clearPreviousAppsCaches();

    return ['installed' => $installed, 'filter' => $filter];
  }

  public static function loadDockerUpdateStatus($dockerRunning) {
    if ( ! $dockerRunning ) {
      return [];
    }

    $status = readJsonFile(CA_PATHS['dockerUpdateStatus']);

    return $status ?: [];
  }

  public static function collectDockerApplications($dockerRunning, $installed, $filter, $info, &$updateCount, $templates, $extraBlacklist, $extraDeprecated, $dockerUpdateStatus) {
    if ( ! $dockerRunning ) {
      return [];
    }

    if ( $filter && $filter !== "docker" ) {
      return [];
    }

    $allFiles = glob(CA_PATHS['dockerManTemplates']."/*.xml") ?: [];
    $isActionCentre = ($installed === "action");

    if ( $installed === "true" || $isActionCentre ) {
      return self::collectInstalledDockerApplications($allFiles, $info, $templates, $dockerUpdateStatus, $extraBlacklist, $extraDeprecated, $isActionCentre, $updateCount);
    }

    return self::collectLegacyDockerApplications($allFiles, $info, $templates);
  }

  public static function collectPluginApplications($installed, $filter, $templates, $caSettings, &$updateCount) {
    if ( $filter && $filter !== "plugins" ) {
      return [];
    }

    $isActionCentre = ($installed === "action");

    if ( $installed === "true" || $isActionCentre ) {
      return self::collectInstalledPluginApplications($templates, $isActionCentre, $updateCount);
    }

    return self::collectLegacyPluginApplications($templates, $caSettings);
  }

  private static function collectInstalledDockerApplications($allFiles, $info, $templates, $dockerUpdateStatus, $extraBlacklist, $extraDeprecated, $isActionCentre, &$updateCount) {
    $displayed = [];

    foreach ($allFiles as $xmlfile) {
      $template = readXmlFile($xmlfile);
      if ( ! $template ) {
        continue;
      }

      $template['Overview'] = fixDescription($template['Overview']);
      $template['Description'] = $template['Overview'];
      $template['CardDescription'] = $template['Overview'];
      $template['InstallPath'] = $xmlfile;
      $template['UnknownCompatible'] = true;

      $containerID = false;
      $isRunning = false;

      foreach ($info as $installedDocker) {
        if ( $installedDocker['Name'] != $template['Name'] ) {
          continue;
        }

        if ( ! startsWith(str_replace("library/","",$installedDocker['Image']), $template['Repository']) && ! startsWith($installedDocker['Image'],$template['Repository']) ) {
          continue;
        }

        $isRunning = true;
        $searchResult = searchArray($templates,'Repository',$template['Repository']);
        if ( $searchResult === false ) {
          $searchResult = searchArray($templates,'Repository',explode(":",$template['Repository'])[0]);
        }

        if ( $searchResult !== false ) {
          if ( ($template['TemplateURL'] ?? false) ) {
            if ( ($templates[$searchResult]['TemplateURL'] ?? INF) != $template['TemplateURL'] ) {
              $search = searchArray($templates,'TemplateURL',$template['TemplateURL']);
              $searchResult = $search === false ? $searchResult : $search;
            }
          }

          $tempPath = $template['InstallPath'];
          $containerID = $templates[$searchResult]['ID'];
          $tmpOvr = $template['Overview'];
          $template = $templates[$searchResult];
          $template['Name'] = $installedDocker['Name'];
          $template['Overview'] = $tmpOvr;
          $template['CardDescription'] = $tmpOvr;
          $template['InstallPath'] = $tempPath;
          $template['SortName'] = str_replace("-"," ",$template['Name']);
          $template['Repository'] = $installedDocker['Image'];
        }

        break;
      }

      if ( ! $isRunning ) {
        continue;
      }

      $template['Uninstall'] = true;
      $template['ID'] = $containerID;

      if ( $isActionCentre ) {
        $tmpRepo = strpos($template['Repository'],":") ? $template['Repository'] : $template['Repository'].":latest";
        if ( ! strpos($tmpRepo,"/") ) {
          $tmpRepo = "library/$tmpRepo";
        }

        if ( $tmpRepo && (($dockerUpdateStatus[$tmpRepo]['status'] ?? null) == "false") ) {
          $template['actionCentre'] = true;
          $template['updateAvailable'] = true;
          $updateCount++;
        }

        if ( ! $template['Blacklist'] && ! $template['Deprecated'] ) {
          if ( $extraBlacklist[$template['Repository']] ?? false ) {
            $template['Blacklist'] = true;
            $template['ModeratorComment'] = $extraBlacklist[$template['Repository']];
          }
          if ( $extraDeprecated[$template['Repository']] ?? false ) {
            $template['Deprecated'] = true;
            $template['ModeratorComment'] = $extraDeprecated[$template['Deprecated']];
          }
        }

        if ( ! $template['Blacklist'] && ! $template['Deprecated'] && ! ($template['actionCentre'] ?? null) ) {
          continue;
        }
      }

      if ( $isActionCentre ) {
        $template['actionCentre'] = true;
      }

      $displayed[] = $template;
    }

    return $displayed;
  }

  private static function collectLegacyDockerApplications($allFiles, $info, $templates) {
    $displayed = [];

    foreach ($allFiles as $xmlfile) {
      $template = readXmlFile($xmlfile);
      if ( ! $template ) {
        continue;
      }

      $template['Overview'] = fixDescription($template['Overview']);
      $template['Description'] = $template['Overview'];
      $template['CardDescription'] = $template['Overview'];
      $template['InstallPath'] = $xmlfile;
      $template['UnknownCompatible'] = true;
      $template['Removable'] = true;

      $isRunning = false;
      foreach ($info as $installedDocker) {
        if ( ! startsWith(str_replace("library/","",$installedDocker['Image']), $template['Repository']) && ! startsWith($installedDocker['Image'],$template['Repository']) ) {
          continue;
        }

        if ( $installedDocker['Name'] == $template['Name'] ) {
          $isRunning = true;
          continue;
        }
      }

      if ( $isRunning ) {
        continue;
      }

      $foundflag = false;
      $testRepo = explode(":",$template['Repository'])[0];

      if ( $template['TemplateURL'] ?? false ) {
        $search = searchArray($templates,'TemplateURL',$template['TemplateURL']);
        if ( $search !== false ) {
          $foundflag = true;

          $tempPath = $template['InstallPath'];
          $tempName = $template['Name'];
          $tempOvr = $template['Overview'];
          $template = $templates[$search];
          $template['Overview'] = $tempOvr;
          $template['Description'] = $tempOvr;
          $template['CardDescription'] = $tempOvr;
          $template['Removable'] = true;
          $template['InstallPath'] = $tempPath;
          $template['Name'] = $tempName;
          $template['SortName'] = str_replace("-"," ",$template['Name']);
        }
      }

      if ( ! $foundflag ) {
        foreach ($templates as $appTemplate) {
          if ( ! startsWith($appTemplate['Repository'],$testRepo) ) {
            continue;
          }

          $tempPath = $template['InstallPath'];
          $tempName = $template['Name'];
          $tempOvr = $template['Overview'];
          $template = $appTemplate;
          $template['Overview'] = $tempOvr;
          $template['Description'] = $tempOvr;
          $template['CardDescription'] = $tempOvr;
          $template['Removable'] = true;
          $template['InstallPath'] = $tempPath;
          $template['Name'] = $tempName;
          $template['SortName'] = str_replace("-"," ",$template['Name']);
          break;
        }
      }

      if ( ! $template['Blacklist'] ) {
        $displayed[] = $template;
      }
    }

    return $displayed;
  }

  private static function collectInstalledPluginApplications($templates, $isActionCentre, &$updateCount) {
    $displayed = [];

    foreach ($templates as $template) {
      if ( ! ($template['Plugin'] ?? null) ) {
        continue;
      }

      $filename = pathinfo($template['Repository'],PATHINFO_BASENAME);
      if ( ! checkInstalledPlugin($template) ) {
        continue;
      }

      $template['InstallPath'] = "/var/log/plugins/$filename";
      $template['Uninstall'] = true;

      if ( $isActionCentre && $template['PluginURL'] && $template['Name'] !== "Community Applications" ) {
        if ( ca_plugin("pluginURL","/var/log/plugins/$filename") !== $template['PluginURL'] ) {
          continue;
        }

        $installedVersion = ca_plugin("version","/var/log/plugins/$filename");
        if ( ( strcmp($installedVersion,$template['pluginVersion']) < 0 || ($template['UpdateAvailable'] ?? null) ) ) {
          $template['actionCentre'] = true;
          $template['UpdateAvailable'] = true;
          $updateCount++;
        }

        if ( is_file("/tmp/plugins/$filename") && strcmp($installedVersion,ca_plugin("version","/tmp/plugins/$filename")) < 0 ) {
          $template['actionCentre'] = true;
          $template['UpdateAvailable'] = true;
          $updateCount++;
        }
      }

      if ( $isActionCentre && ! $template['Blacklist'] && ! $template['Deprecated'] && $template['Compatible'] && ! ($template['actionCentre'] ?? null) ) {
        continue;
      }

      if ( $isActionCentre ) {
        $template['actionCentre'] = true;
      }

      $displayed[] = $template;
    }

    $displayed = array_merge($displayed, self::collectInstalledLanguagePacks($templates, $isActionCentre, $updateCount));

    return $displayed;
  }

  private static function collectInstalledLanguagePacks($templates, $isActionCentre, &$updateCount) {
    $displayed = [];
    $languagesDir = CA_PATHS['languageInstalled'] ?? null;

    if ( ! $languagesDir || ! is_dir($languagesDir) ) {
      return $displayed;
    }

    $installedLanguages = array_diff(scandir($languagesDir) ?: [],[".","..","en_US"]);

    foreach ($installedLanguages as $language) {
      $index = searchArray($templates,"LanguagePack",$language);
      if ( $index === false ) {
        continue;
      }

      $languageTemplate = $templates[$index];
      $languageTemplate['Uninstall'] = true;

      if ( $isActionCentre ) {
        $languageTemplate['actionCentre'] = true;
        if ( ! languageCheck($languageTemplate) ) {
          continue;
        }

        $languageTemplate['Updated'] = true;
        $updateCount++;
      }

      $displayed[] = $languageTemplate;
    }

    return $displayed;
  }

  private static function collectLegacyPluginApplications($templates, $caSettings) {
    $displayed = [];
    $alreadySeen = [];

    $sources = [
      "/boot/config/plugins-error/*.plg",
      "/boot/config/plugins-removed/*.plg"
    ];

    $allPlugs = [];
    foreach ($sources as $pattern) {
      $results = glob($pattern) ?: [];
      $allPlugs = array_merge($allPlugs, $results);
    }

    foreach ($allPlugs as $oldplug) {
      foreach ($templates as $template) {
        if ( basename($oldplug) != basename($template['Repository']) ) {
          continue;
        }

        if ( file_exists("/boot/config/plugins/".basename($oldplug)) ) {
          continue;
        }

        if ( $template['Blacklist'] || ( ($caSettings['hideIncompatible'] == "true") && ( ! $template['Compatible'] ) ) ) {
          continue;
        }

        $oldPlugURL = trim(ca_plugin("pluginURL",$oldplug));
        if ( ! $oldPlugURL ) {
          continue;
        }

        if ( strtolower(trim($template['PluginURL'])) != strtolower(trim($oldPlugURL)) ) {
          continue;
        }

        $template['Removable'] = true;
        $template['InstallPath'] = $oldplug;
        if ( isset($alreadySeen[$oldPlugURL]) ) {
          continue;
        }

        $alreadySeen[$oldPlugURL] = true;
        $displayed[] = $template;
        break;
      }
    }

    return $displayed;
  }
}
?>