<?php
class GetContentHelpers {
  public static function normalizeMaxHomeApps($maxHomeApps) {
    if ($maxHomeApps == 0) {
      return 4;
    }

    if ($maxHomeApps < 3) {
      return 2;
    }

    return $maxHomeApps;
  }

  public static function resolveCategoryContext($category) {
    $context = [
      'categoryString'      => $category,
      'categoryRegex'       => false,
      'displayBlacklisted'  => false,
      'displayDeprecated'   => false,
      'displayIncompatible' => false,
      'displayPrivates'     => false,
      'noInstallComment'    => "",
      'action'              => null
    ];

    switch ($category) {
      case "PRIVATE":
        $context['categoryString'] = false;
        $context['displayPrivates'] = true;
        break;
      case "DEPRECATED":
        $context['categoryString'] = false;
        $context['displayDeprecated'] = true;
        $context['noInstallComment'] = tr("Deprecated Applications are able to still be installed if you have previously had them installed. New installations of these applications are blocked unless you enable Display Deprecated Applications within CA's General Settings")."<br><br>";
        break;
      case "BLACKLIST":
        $context['categoryString'] = false;
        $context['displayBlacklisted'] = true;
        $context['noInstallComment'] = tr("The following applications are blacklisted.  CA will never allow you to install or reinstall these applications")."<br><br>";
        break;
      case "INCOMPATIBLE":
        $context['categoryString'] = false;
        $context['displayIncompatible'] = true;
        $context['noInstallComment'] = tr("While highly not recommended to do, incompatible applications can be installed by enabling Display Incompatible Applications within CA's General Settings")."<br><br>";
        break;
      case "repos":
        $context['action'] = 'repos';
        return $context;
      case "":
        $context['categoryString'] = false;
        break;
    }

    if ($context['categoryString']) {
      $context['categoryRegex'] = "/{$context['categoryString']}/i";
    }

    return $context;
  }

  public static function handleHomeStartupDisplay(array &$file, $maxHomeApps) {
    global $caSettings;

    getConvertedTemplates();  // Only scan for private XMLs when going HOME

    ca_file_put_contents(CA_PATHS['startupDisplayed'],"startup");

    if (count($file) <= 200) {
      return false;
    }

    $startupTypes = [
      [
        "type"=>"onlynew",
        "text1"=>tr("Recently Added"),
        "text2"=>tr("Check out these newly added applications from our awesome community"),
        "cat"=>"All",
        "sortby"=>"FirstSeen",
        "sortdir"=>"Down"
      ],
      [
        "type"=>"spotlight",
        "text1"=>tr("Spotlight Apps"),
        "text2"=>tr("Each month we highlight some of the amazing work from our community"),
        "cat"=>"spotlight:",
        "sortby"=> "RecommendedDate",
        "sortdir"=> "Down",
      ],
      [
        "type"=>"trending",
        "text1"=>tr("Top Trending Apps"),
        "text2"=>tr("Check out these up and coming apps"),
        "cat"=>"All",
        "sortby"=>"topTrending",
        "sortdir"=>"Down"
      ],
      [
        "type"=>"topperforming",
        "text1"=>tr("Top New Installs"),
        "text2"=>tr("These apps have the highest percentage of new installs"),
        "cat"=>"All",
        "sortby"=>"topPerforming",
        "sortdir"=>"Down"
      ],
      [
        "type"=>"topPlugins",
        "text1"=>tr("Most Popular Plugins"),
        "text2"=>tr("The most popular plugins installed by other Unraid users"),
        "cat"=>"plugins:",
        "sortby"=>"downloads",
        "sortdir"=>"Down"
      ],
      [
        "type"=>"random",
        "text1"=>tr("Random Apps"),
        "text2"=>tr("An assortment of randomly chosen apps"),
        "cat"=>"All",
        "sortby"=>"random",
        "sortdir"=>"Down"
      ]
    ];

    if ($caSettings['featuredDisable'] !== "yes") {
      array_unshift($startupTypes,
        [
          "type"=>"featured",
          "text1"=>tr("Featured Applications"),
          "text2"=>"",
          "sortby"=>"Name",
          "sortdir"=>"Up"
        ]
      );
    }

    $o = ['display' => ""];
    $maxHomeApps = self::normalizeMaxHomeApps($maxHomeApps);

    foreach ($startupTypes as $type) {
      $displayApplications = ['community' => []];

      $display = [];
      $homeCount = 0;

      $caSettings['startup'] = $type['type'];
      $appsOfDay = appOfDay($file);

      if ( ! $appsOfDay || empty($appsOfDay) )
        continue;

      for ($i=0;$i<$caSettings['maxPerPage'];$i++) {
        if ( ! isset($appsOfDay[$i])) continue;
        $file[$appsOfDay[$i]]['NewApp'] = ($caSettings['startup'] != "random");
        $spot = $file[$appsOfDay[$i]];
        $spot['homeScreen'] = true;
        $displayApplications['community'][] = $spot;
        $display[] = $spot;
        $homeCount++;
        if ( $homeCount >= $maxHomeApps ) break;
      }
      if ( $displayApplications['community'] ) {
        $o['display'] .= "<div class='ca_homeTemplatesHeader'>{$type['text1']}</div>";
        $o['display'] .= "<div class='ca_homeTemplatesLine2'>{$type['text2']} ";
        if ( $type['cat'] ?? false )
          $o['display'] .= "<span class='homeMore' data-des='{$type['text1']}' data-category='{$type['cat']}' data-sortby='{$type['sortby']}' data-sortdir='{$type['sortdir']}'>".tr("SHOW MORE");
        $o['display'] .= "</div>";
        $homeClass = "caHomeSpotlight";

        $o['display'] .= "<div class='ca_homeTemplates home{$type['type']} $homeClass'>".my_display_apps($display,"1")."</div>";
        $o['script'] = "$('#templateSortButtons,#sortButtons,.maxPerPage').hide();";

      } else {
        switch ($caSettings['startup']) {
          case "onlynew":
            $startupType = "New"; break;
          case "new":
            $startupType = "Updated"; break;
          case "trending":
            $startupType = "Top Performing"; break;
          case "topPlugins":
            $startupType = "Top Plugins"; break;
          case "random":
            $startupType = "Random"; break;
          case "upandcoming":
            $startupType = "Trending"; break;
          case "featured":
            $startupType = "Featured"; break;
        }

        $o['display'] .=  "<br><div class='ca_center'><font size='4' color='purple'><span class='ca_bold'>".sprintf(tr("An error occurred.  Could not find any %s Apps"),$startupType)."</span></font><br><br>";
        $o['script'] = "$('#templateSortButtons,#sortButtons,.maxPerPage').hide();";

        writeJsonFile(CA_PATHS['community-templates-displayed'],$displayApplications);
        postReturn($o);
        return true;
      }
    }
    @unlink(CA_PATHS['community-templates-allSearchResults']);
    @unlink(CA_PATHS['community-templates-catSearchResults']);
    writeJsonFile(CA_PATHS['community-templates-displayed'],$displayApplications);
    postReturn($o);
    return true;
  }

  public static function handleSpecialTemplateDisplays($template, &$display, $flags) {
    if ($flags['displayBlacklisted']) {
      if ($template['Blacklist']) {
        $display[] = $template;
      }

      return true;
    }

    if ($flags['displayIncompatible']) {
      if ( ! $template['Compatible']) {
        $display[] = $template;
      }

      return true;
    }

    if ($flags['displayDeprecated']) {
      if ( $template['Deprecated'] && ! $template['Blacklist']) {
        if ( ! ($template['BranchID']??false) ) {
          $display[] = $template;
        }
      }

      return true;
    }

    return false;
  }

  public static function shouldSkipTemplate($template, $flags, $caSettings) {
    if ( ($caSettings['hideDeprecated'] == "true") && ($template['Deprecated'] && ! $flags['displayDeprecated']) ) return true;
    if ( $flags['displayDeprecated'] && ! $template['Deprecated'] ) return true;
    if ( ! $template['Displayable'] ) return true;
    if ( $caSettings['hideIncompatible'] == "true" && ! $template['Compatible'] && ! $flags['displayIncompatible']  && ! ($template['Featured']??false) ) return true;
    if ( $template['Blacklist'] ) return true;
    if ( $flags['displayPrivates'] && ! $template['Private'] ) return true;

    return false;
  }

  public static function handleFilteredTemplate($template, $filter, &$searchResults) {
    global $caSettings;

    $template['translatedCategories'] = "";
    foreach (explode(" ",$template['Category']) as $trCat) {
      $template['translatedCategories'] .= tr($trCat)." ";
    }

    if ( endsWith($filter," Repository") && $template['RepoName'] !== $filter) {
      return;
    }

    if ( filterMatch($filter,[$template['SortName']]) && $caSettings['favourite'] == $template['RepoName']) {
      $searchResults['favNameHit'][] = $template;
      return;
    }
    
    if ( strpos($filter,"/") && filterMatch($filter,[$template['Repository']]) ) {
      $searchResults['nameHit'][] = $template;
      return;
    }

    if ( filterMatch($filter,[$template['SortName']??null,$template['RepoShort']??null,$template['Language']??null,$template['LanguageLocal']??null]) ) {
      if ( ($template['LTOfficial']??false) || ($template['Official']??false) ) {
        $searchResults['officialHit'][] = $template;
        return;
      } else {
        if ( $template['Official']??false) {
          $searchResults['officialHit'][] = $template;
          return;
        } else {
          if ( strtolower(trim($template['Name'])) == strtolower(trim($filter)) ) {
            $searchResults['fullNameHit'][] = $template;
            return;
          }
          $searchResults['nameHit'][] = $template;
          return;
        }
      }
    }

    if ( filterMatch($filter,[$template['Author']??null,$template['RepoName']??null,$template['Overview']??null,$template['translatedCategories']??null]) ) {
      if ( $template['RepoName'] == ($caSettings['favourite']??null) ) {
        $searchResults['nameHit'][] = $template;
      } else {
        $searchResults['anyHit'][] = $template;   
      }
      return;
    } 

    if ( filterMatch($filter,[$template['ExtraSearchTerms']??null],false) ) {
      debug("extraHit: ".$template['Name']);
      $searchResults['extraHit'][] = $template;
    }
  }

  public static function sortSearchResultsBuckets(&$searchResults, $filter) {
    global $caSettings;

    $buckets = ['fullNameHit','officialHit','nameHit','favNameHit','anyHit','extraHit'];
    foreach ($buckets as $bucket) {
      if ( isset($searchResults[$bucket]) ) {
        usort($searchResults[$bucket],"mySort");
      } else {
        $searchResults[$bucket] = [];
      }
    }

    if ( isset($searchResults['nameHit']) && ! strpos($filter," Repository") ) {
      if ( $caSettings['favourite'] && $caSettings['favourite'] !== "none" ) {
        usort($searchResults['nameHit'],"favouriteSort");
      }
    }
  }

  public static function cacheDisplayApplications($categoryRegex, $filter, $displayApplications) {

    if ( ! $filter ) {
      writeJsonFile(CA_PATHS['community-templates-displayed'],$displayApplications);

      @unlink(CA_PATHS['community-templates-allsearchResults']);
      @unlink(CA_PATHS['community-templates-catSearchResults']);

      return;
    }

    if ( ! $categoryRegex ) {
      writeJsonFile(CA_PATHS['community-templates-allSearchResults'],$displayApplications);
      writeJsonFile(CA_PATHS['community-templates-catSearchResults'],$displayApplications);

      return;
    }

    writeJsonFile(CA_PATHS['community-templates-catSearchResults'],$displayApplications);
  }
}
?>