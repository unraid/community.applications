<?php
class GetContentHelpers {
	/**
	 * Normalize a requested maximum number of home applications.
	 *
	 * @param int $maxHomeApps The requested maximum number of home apps.
	 * @return int Normalized maximum: `4` when `$maxHomeApps` is `0`, `2` when `$maxHomeApps` is less than `3`, otherwise the original `$maxHomeApps`.
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
	 * Build context describing how community templates should be filtered and displayed for a given category.
	 *
	 * @param string $category Category identifier (e.g. "PRIVATE", "DEPRECATED", "BLACKLIST", "INCOMPATIBLE", "repos", or an empty string).
	 * @return array Associative context with the following keys:
	 *               - `categoryString` (string|false): category token used for regex matching, or false when disabled.
	 *               - `categoryRegex` (string|false): case-insensitive regex string to match the category, or false when not applicable.
	 *               - `displayBlacklisted` (bool): allow displaying blacklisted templates.
	 *               - `displayDeprecated` (bool): allow displaying deprecated templates.
	 *               - `displayIncompatible` (bool): allow displaying incompatible templates.
	 *               - `displayPrivates` (bool): allow displaying private templates.
	 *               - `noInstallComment` (string): localized HTML message explaining install restrictions when relevant.
	 *               - `action` (string|null): special action identifier (set to 'repos' for the "repos" category), or null otherwise.
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
	 * Prepare and output home startup sections of community applications based on available templates and the requested home-app limit.
	 *
	 * Builds several predefined startup sections (e.g., featured, recently added, trending, random), selects up to a normalized number of apps per section, marks selected items (e.g., `NewApp`, `homeScreen`), assembles the HTML display payload, writes startup/display JSON files, and posts the resulting payload for rendering. If no templates are available for a startup section, writes an error payload and returns early.
	 *
	 * @param array &$file Array of available templates, keyed by template identifier; entries for selected items will be mutated (fields like `NewApp` and `homeScreen` are set).
	 * @param int $maxHomeApps Requested maximum number of apps to show on the home screen (will be normalized).
	 * @return bool `true` if the display payload was processed and posted, `false` if `$file` contains 200 or fewer items (no startup display performed).
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
	 * Apply category-specific display rules and optionally append the template to the display list.
	 *
	 * Evaluates flags for showing blacklisted, incompatible, or deprecated templates; when a flag is active
	 * and the template matches that category, the template is appended to `$display`.
	 *
	 * @param array $template Template data array (expected keys include 'Blacklist', 'Compatible', 'Deprecated', 'BranchID').
	 * @param array &$display Reference to the array collecting templates to display; may be modified by this function.
	 * @param array $flags Associative flags controlling special displays (expects boolean-like keys: 'displayBlacklisted', 'displayIncompatible', 'displayDeprecated').
	 * @return bool `true` if one of the special display flags was processed (regardless of whether the template was added), `false` if no special display flag applied.
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
	 * Determines whether a template should be excluded from display based on global settings and provided flags.
	 *
	 * Evaluates template metadata (deprecated, displayable, compatible, blacklist, featured, private) together
	 * with global hide settings and the provided display flags to decide exclusion.
	 *
	 * @param array $template Template metadata used to evaluate display eligibility.
	 * @param array $flags Flags controlling category visibility (e.g., `displayDeprecated`, `displayIncompatible`, `displayPrivates`).
	 * @return bool `true` if the template should be skipped (excluded) from display, `false` otherwise.
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
	 * Classifies a template into search-result buckets based on a textual filter.
	 *
	 * Builds a translated categories string from the template's `Category` field, then tests the provided
	 * `$filter` against multiple template fields and places the template into one appropriate bucket
	 * within `$searchResults` (for example: `favNameHit`, `nameHit`, `fullNameHit`, `officialHit`, `anyHit`, `extraHit`).
	 * If `$filter` ends with " Repository" and the template's `RepoName` does not match the filter, the function
	 * returns without modifying `$searchResults`.
	 *
	 * @param array $template Associative array of template metadata (e.g. Name, RepoName, SortName, Category, Author, Overview, Official, LTOfficial, ExtraSearchTerms, Repository, RepoShort, Language, LanguageLocal).
	 * @param string $filter The search filter text to match against template fields.
	 * @param array &$searchResults Reference to an associative array of result buckets that will be modified by this function.
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
	 * Sorts search-result buckets and ensures each expected bucket exists.
	 *
	 * Ensures the buckets `fullNameHit`, `officialHit`, `nameHit`, `favNameHit`, `anyHit`, and `extraHit`
	 * exist in `$searchResults` as arrays, applies the standard sorting order to each bucket, and,
	 * when `$filter` does not contain " Repository" and a favourite repository is configured,
	 * applies favourite-priority sorting to the `nameHit` bucket.
	 *
	 * @param array &$searchResults Associative array of search-result buckets to sort and normalize.
	 * @param string $filter The active search filter string (used to decide favourite re-sorting).
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
	 * Persist display results to the appropriate community-templates JSON cache files.
	 *
	 * If no filter is provided, writes $displayApplications to `community-templates-displayed`
	 * and removes any existing `community-templates-allSearchResults` and
	 * `community-templates-catSearchResults` files.
	 * If a filter is provided but no category regex is specified, writes $displayApplications
	 * to both `community-templates-allSearchResults` and `community-templates-catSearchResults`.
	 * If both a filter and a category regex are provided, writes $displayApplications only to
	 * `community-templates-catSearchResults`.
	 *
	 * @param mixed $categoryRegex Category regex string or falsy value indicating no category filtering.
	 * @param string|false $filter Search filter string or falsy value when no filter is applied.
	 * @param array $displayApplications The display payload to serialize to JSON.
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