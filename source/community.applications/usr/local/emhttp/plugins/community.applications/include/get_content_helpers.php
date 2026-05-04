<?php
class GetContentHelpers {
	/**
	 * Normalize the configured maximum number of apps to display on the home page.
	 *
	 * @param int $maxHomeApps The configured maximum number of home apps.
	 * @return int 4 when $maxHomeApps is 0, 2 when $maxHomeApps is less than 3, otherwise $maxHomeApps.
	 */
	public static function normalizeMaxHomeApps($maxHomeApps) {
		if ($maxHomeApps == 0) {
			return 4;
		}

		if ($maxHomeApps < 3) {
			return 2;
		}

		return $maxHomeApps;
	}

	/**
	 * Builds a context array that describes how a category value should control filtering and display.
	 *
	 * @param string $category Category identifier or name. Special values:
	 *                        - "PRIVATE": show private templates.
	 *                        - "DEPRECATED": show deprecated templates and set a no-install message.
	 *                        - "BLACKLIST": show blacklisted templates and set a no-install message.
	 *                        - "INCOMPATIBLE": show incompatible templates and set a no-install message.
	 *                        - "repos": sets the action to "repos" and returns immediately.
	 *                        - "" (empty string): disables category matching.
	 * @return array{
	 *     categoryString: string|false,
	 *     categoryRegex: string|false,
	 *     displayBlacklisted: bool,
	 *     displayDeprecated: bool,
	 *     displayIncompatible: bool,
	 *     displayPrivates: bool,
	 *     noInstallComment: string,
	 *     action: string|null
	 * } Context mapping used to control which templates are displayed and whether category regex matching applies.
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

	/**
	 * Build and emit the homepage startup sections (featured, recent, trending, etc.) and cache their JSON output.
	 *
	 * Processes the provided templates to assemble multiple startup sections, marks selected templates for the home view
	 * (sets `NewApp` and `homeScreen` on selected entries), writes startup-marker and cached JSON files, and sends the
	 * final display payload via postReturn(). If no templates are available for a startup section an error payload is
	 * written and posted for that section.
	 *
	 * @param array &$file Array of template records (by-reference). Selected entries will be modified with `NewApp` and `homeScreen`.
	 * @param int $maxHomeApps Requested maximum number of apps to show on the home sections; this value is normalized before use.
	 * @return bool `true` if a display payload was posted (or an early error payload was posted), `false` if processing was skipped due to insufficient templates.
	 */
	public static function handleHomeStartupDisplay(array &$file, $maxHomeApps) {

	 // getConvertedTemplates();  // Only scan for private XMLs when going HOME

		ca_file_put_contents(CA_PATHS['startupDisplayed'],"startup");

		if (count($file) <= 200) {
			return false;
		}
		$GLOBALS['caSettings']['maxPerPage'] = 10;
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

		if ($GLOBALS['caSettings']['featuredDisable'] !== "yes") {
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

			$GLOBALS['caSettings']['startup'] = $type['type'];
			$appsOfDay = appOfDay($file);

			if ( ! $appsOfDay || empty($appsOfDay) )
				continue;

			for ($i=0;$i<$GLOBALS['caSettings']['maxPerPage'];$i++) {
				if ( ! isset($appsOfDay[$i])) continue;
				$file[$appsOfDay[$i]]['NewApp'] = ($GLOBALS['caSettings']['startup'] != "random");
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
					$o['display'] .= "<span class='homeMore' data-des='{$type['text1']}' data-category='{$type['cat']}' data-sortby='{$type['sortby']}' data-sortdir='{$type['sortdir']}'>".tr("SHOW MORE")."</span>";
				$o['display'] .= "</div>";
				$homeClass = "caHomeSpotlight";

				$o['display'] .= "<div class='ca_homeTemplates home{$type['type']} $homeClass'>".my_display_apps($display,"1")."</div>";
				$o['script'] = "$('#templateSortButtons,#sortButtons').hide();";

			} else {
				switch ($GLOBALS['caSettings']['startup']) {
					case "onlynew":
						$startupType = "New"; break;
					case "new":
						$startupType = "Updated"; break;
					case "trending":
						$startupType = "Trending"; break;
					case "topPlugins":
						$startupType = "Top Plugins"; break;
					case "random":
						$startupType = "Random"; break;
					case "upandcoming":
						$startupType = "Trending"; break;
					case "featured":
						$startupType = "Featured"; break;
					case "spotlight":
						$startupType = "Spotlight"; break;
					case "topperforming":
						$startupType = "Top Performing"; break;
				}

				$o['display'] .=  "<br><div class='ca_center'><font size='4' color='purple'><span class='ca_bold'>".sprintf(tr("An error occurred.  Could not find any %s Apps"),$startupType)."</span></font><br><br></div>";
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

	/**
	 * Conditionally append a template to the display list when a special-category display flag is active.
	 *
	 * When `displayBlacklisted` is active, appends the template if its `Blacklist` flag is truthy.
	 * When `displayIncompatible` is active, appends the template if its `Compatible` flag is falsy.
	 * When `displayDeprecated` is active, appends the template if its `Deprecated` flag is truthy, its `Blacklist` flag is falsy, and its `BranchID` is falsy or absent.
	 *
	 * @param array $template Associative array of template metadata.
	 * @param array &$display Reference to the array that will receive the template when matched.
	 * @param array $flags Associative flags controlling special display modes (`displayBlacklisted`, `displayIncompatible`, `displayDeprecated`).
	 * @return bool `true` when a special-category display flag was handled (no further normal processing should occur), `false` otherwise.
	 */
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

	/**
	 * Determine whether a template should be excluded from display based on global settings and provided display flags.
	 *
	 * Checks consider deprecation, explicit display flags, displayability, compatibility, blacklist status, and private-repo visibility.
	 *
	 * @param array $template Associative template data containing keys used for decision: `Deprecated`, `Displayable`, `Compatible`, `Featured`, `Blacklist`, and `Private`.
	 * @param array $flags Associative flags that influence inclusion: `displayDeprecated`, `displayIncompatible`, and `displayPrivates`.
	 * @return bool `true` if the template should be excluded from results, `false` otherwise.
	 */
	public static function shouldSkipTemplate($template, $flags) {
		if ( ($GLOBALS['caSettings']['hideDeprecated'] == "true") && ($template['Deprecated'] && ! $flags['displayDeprecated']) ) return true;
		if ( $flags['displayDeprecated'] && ! $template['Deprecated'] ) return true;
		if ( ! $template['Displayable'] ) return true;
		if ( $GLOBALS['caSettings']['hideIncompatible'] == "true" && ! $template['Compatible'] && ! $flags['displayIncompatible']  && ! ($template['Featured']??false) ) return true;
		if ( $template['Blacklist'] ) return true;
		if ( $flags['displayPrivates'] && ! $template['Private'] ) return true;

		return false;
	}

	/**
	 * Assigns a template into the appropriate search-results bucket based on how the provided filter matches the template.
	 *
	 * Builds a translated category string from the template's Category field, then evaluates prioritized match rules and appends the template to one of these buckets in $searchResults: `favNameHit`, `fullNameHit`, `officialHit`, `nameHit`, `anyHit`, or `extraHit`. If the filter ends with " Repository" but does not equal the template's RepoName, the function returns without adding.
	 *
	 * @param array $template The template record to evaluate; fields used include `Category`, `SortName`, `RepoShort`, `Repository`, `RepoName`, `Language`, `LanguageLocal`, `LTOfficial`, `Official`, `Name`, `Author`, `Overview`, and `ExtraSearchTerms`.
	 * @param string $filter The search filter string used to match template fields.
	 * @param array[] &$searchResults Reference to the associative array of result buckets; the function will append the template into one of its buckets.
	 */
	public static function handleFilteredTemplate($template, $filter, &$searchResults) {

		$template['translatedCategories'] = "";
		foreach (explode(" ",$template['Category']) as $trCat) {
			$template['translatedCategories'] .= tr($trCat)." ";
		}

		if ( endsWith($filter," Repository") && $template['RepoName'] !== $filter) {
			return;
		}

		if ( filterMatch($filter,[$template['SortName']]) && $GLOBALS['caSettings']['favourite'] == $template['RepoName']) {
			$searchResults['favNameHit'][] = $template;
			return;
		}

		if ( strpos($filter,"/") !== false && filterMatch($filter,[$template['Repository']]) ) {
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
			if ( $template['RepoName'] == ($GLOBALS['caSettings']['favourite']??null) ) {
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

	/**
	 * Ensure search-result buckets exist and sort each bucket, with optional favourite-based resorting for name hits.
	 *
	 * Initializes missing buckets (fullNameHit, officialHit, nameHit, favNameHit, anyHit, extraHit) as empty arrays and sorts existing buckets using `mySort`. If a non-repository filter is provided and a favourite repository is configured, further sorts the `nameHit` bucket using `favouriteSort`.
	 *
	 * @param array &$searchResults Associative array of search-result buckets to sort; buckets are modified in place.
	 * @param string $filter The active filter string used to determine whether favourite-based resorting of `nameHit` applies (skips favourite resorting when the filter contains " Repository").
	 */
	public static function sortSearchResultsBuckets(&$searchResults, $filter) {

		$buckets = ['fullNameHit','officialHit','nameHit','favNameHit','anyHit','extraHit'];
		foreach ($buckets as $bucket) {
			if ( isset($searchResults[$bucket]) ) {
				usort($searchResults[$bucket],"mySort");
			} else {
				$searchResults[$bucket] = [];
			}
		}

		if ( isset($searchResults['nameHit']) && strpos($filter," Repository") === false ) {
			if ( $GLOBALS['caSettings']['favourite'] && $GLOBALS['caSettings']['favourite'] !== "none" ) {
				usort($searchResults['nameHit'],"favouriteSort");
			}
		}
	}

	/**
	 * Cache rendered display applications to the appropriate JSON files based on filter and category context.
	 *
	 * When no filter is provided, writes $displayApplications to the "community-templates-displayed" path
	 * and removes any existing all/category search-result caches. When a filter is provided but no category
	 * regex is present, writes the results to both the all-search and category-search paths. When both a
	 * filter and category regex are provided, writes the results only to the category-search path.
	 *
	 * @param false|string $categoryRegex A case-insensitive category regex pattern or false when not used.
	 * @param string|null $filter The current search filter; falsy when no filter is applied.
	 * @param array $displayApplications The display data to serialize and write to cache files.
	 */
	public static function cacheDisplayApplications($categoryRegex, $filter, $displayApplications) {

		if ( ! $filter ) {
			writeJsonFile(CA_PATHS['community-templates-displayed'],$displayApplications);

			@unlink(CA_PATHS['community-templates-allSearchResults']);
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