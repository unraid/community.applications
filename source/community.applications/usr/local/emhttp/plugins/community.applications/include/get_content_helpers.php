<?php
class GetContentHelpers {
	/**
	 * Clamp the home-screen "max apps" preference to the supported range.
	 *
	 * Returns 4 when the value is 0 (unset), 2 when the value is below 3
	 * (the minimum useful row), otherwise the value unchanged.
	 *
	 * @param  int|string  $maxHomeApps
	 * @return int
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
	 * Translate a raw category POST value into a display-context struct.
	 *
	 * Handles the special categories (PRIVATE, DEPRECATED, BLACKLIST,
	 * INCOMPATIBLE, repos, empty) and computes the regex / display-flag /
	 * no-install-comment combination for them. For ordinary categories the
	 * regex is built from the original string.
	 *
	 * @param  string|false  $category  Raw POST category.
	 * @return array{categoryString:string|false,categoryRegex:string|false,displayBlacklisted:bool,displayDeprecated:bool,displayIncompatible:bool,displayPrivates:bool,noInstallComment:string,action:?string}
	 */
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
	 * Render the multi-row Home startup screen (Featured / Spotlight / Trending / etc).
	 *
	 * Iterates the configured "startup types", calls appOfDay() per type, builds
	 * the HTML for each row, writes the per-tab community-templates-displayed
	 * cache, and calls postReturn() to flush JSON to the browser. Returns true
	 * when the caller should stop further processing (response already sent).
	 *
	 * Side effects: mutates $GLOBALS['caSettings']['startup'] and ['maxPerPage'],
	 * touches CA_PATHS['startupDisplayed'], writes CA_PATHS['community-templates-displayed'],
	 * unlinks search-result caches, and calls postReturn() which echoes JSON.
	 *
	 * @param  array<int,array<string,mixed>>  $file         Templates list (by ref for memory).
	 * @param  int|string                      $maxHomeApps  Per-row cap from POST.
	 * @return bool True when the response was sent and the caller should bail; false when too few templates to render.
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

				$o['display'] .= "<div class='ca_homeTemplates home{$type['type']} $homeClass'>".my_display_apps($display,"1",false,false,false,false)."</div>";
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
	 * Handle blacklisted / incompatible / deprecated special-category display modes.
	 *
	 * When any "display only X" flag is set, this function decides whether the
	 * template matches that special bucket and pushes it into $display by ref.
	 *
	 * @param  array<string,mixed>             $template
	 * @param  array<int,array<string,mixed>>  $display  Out-list, appended by reference.
	 * @param  array<string,bool>              $flags    displayBlacklisted/displayDeprecated/displayIncompatible.
	 * @return bool True when a special category was matched and the caller should skip normal handling.
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
	 * Apply visibility filters (deprecated/incompatible/blacklist/private/featured) for normal listings.
	 *
	 * Returns true when the template should be excluded from the display set.
	 * Reads $GLOBALS['caSettings'] for hide preferences.
	 *
	 * @param  array<string,mixed>  $template
	 * @param  array<string,bool>   $flags
	 * @return bool
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
	 * Score a single template against the user's search filter and bucket it.
	 *
	 * Buckets are: fullNameHit, officialHit, nameHit, favNameHit, anyHit,
	 * extraHit (latter for ExtraSearchTerms). The translated-category column is
	 * computed in-place for downstream display. Reads
	 * $GLOBALS['caSettings']['favourite'].
	 *
	 * @param  array<string,mixed>                       $template
	 * @param  string                                    $filter         Raw filter text (may contain "/" or " Repository").
	 * @param  array<string,array<int,array<string,mixed>>>  $searchResults  Buckets, appended by reference.
	 * @return void
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
	 * Sort each search-result bucket via mySort and apply favouriteSort to nameHit when applicable.
	 *
	 * Initializes empty buckets so downstream array_merge can rely on them.
	 * Reads $GLOBALS['caSettings']['favourite'].
	 *
	 * @param  array<string,array<int,array<string,mixed>>>  $searchResults  By reference.
	 * @param  string                                        $filter
	 * @return void
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
	 * Persist the displayed-applications JSON to the appropriate per-tab cache file(s).
	 *
	 * - No filter -> writes community-templates-displayed and unlinks the
	 *   search-result caches.
	 * - Filter without category -> writes both allSearchResults and
	 *   catSearchResults.
	 * - Filter with category   -> writes only catSearchResults.
	 *
	 * @param  string|false                                     $categoryRegex
	 * @param  string|false                                     $filter
	 * @param  array<string,array<int,array<string,mixed>>>     $displayApplications
	 * @return void
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