<?php
class GetContentHelpers {
	/**
	 * Clamp the home-screen "max apps" preference to the supported range.
	 *
	 * Returns 4 when the value is missing / non-positive (old client or direct
	 * call), otherwise the integer value unchanged.
	 *
	 * @param  int|string  $maxHomeApps
	 * @return int
	 */
	public static function normalizeMaxHomeApps($maxHomeApps) {
		/* maxHomeApps is the home row capacity sent by the client. A missing or
		   non-positive value (old client / direct call) falls back to a sane
		   default; anything 1 or higher is honoured as-is so a narrow row can
		   legitimately show a single app. */
		if ((int)$maxHomeApps < 1) {
			return 4;
		}

		return (int)$maxHomeApps;
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
			case "duplicates":
				/* Dev + admin only — the menu item is gated server-side at
				   render time, but get_content() re-checks before doing any
				   work. categoryString stays false so no regex matching runs
				   downstream. */
				$context['categoryString'] = false;
				$context['action'] = 'duplicates';
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
		$GLOBALS['caSettings']['maxPerPage'] = max(10, self::normalizeMaxHomeApps($maxHomeApps));
		/* The non-Featured Home rows live in caHomeSections() (include/helpers.php)
		   so the left-menu Home submenu in skin.html renders from the same list. */
		$startupTypes = caHomeSections();

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

			$hasMore = (bool)($type['cat'] ?? false);
			/* Cat sections overlay SHOW MORE on their last card. Render at least
			   two there (one real card plus the overlaid one) so a genuine app
			   always shows even when only a single card fits the row - the Show
			   More then lands on the 2nd card. Other sections just fill the row. */
			$appsToShow = $hasMore ? max(2, $maxHomeApps) : $maxHomeApps;

			for ($i=0;$i<$GLOBALS['caSettings']['maxPerPage'];$i++) {
				if ( ! isset($appsOfDay[$i])) continue;
				$file[$appsOfDay[$i]]['NewApp'] = ($GLOBALS['caSettings']['startup'] != "random");
				$spot = $file[$appsOfDay[$i]];
				$spot['homeScreen'] = true;
				$displayApplications['community'][] = $spot;
				$display[] = $spot;
				$homeCount++;
				if ( $homeCount >= $appsToShow ) break;
			}
			if ( $displayApplications['community'] ) {
				/* Sections that link to a full category turn their last visible
				   card into the Show More affordance: the card still renders (just
				   dimmed) with a SHOW MORE label overlaid on top. The flag rides on
				   the template through to displayCard, which draws the overlay. Only
				   do this when another real card sits beside it, so a section never
				   shows nothing but a Show More. */
				if ( $hasMore && count($display) >= 2 ) {
					$lastIdx = count($display) - 1;
					$display[$lastIdx]['homeShowMore'] = [
						'cat'     => $type['cat'],
						'sortby'  => $type['sortby'],
						'sortdir' => $type['sortdir'],
						'des'     => $type['text1'],
					];
				}

				$o['display'] .= "<div class='ca_homeTemplatesHeader'>{$type['text1']}</div>";
				$o['display'] .= "<div class='ca_homeTemplatesLine2'>{$type['text2']}</div>";
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

		/* "Limit search results" setting (Settings panel, default off) —
		   when enabled, only Name and Author/RepoName count; Overview text,
		   category translations, language metadata, maintainer-supplied
		   ExtraSearchTerms, AND the docker-hub Repository path are excluded.
		   Repository is dropped on purpose: a term like "test" would
		   otherwise pull in every container tagged ":latest" (or anything
		   with the word in its image path) and undo the narrowing the user
		   asked for. Slash-path filters ("owner/container") still hit
		   Repository via the dedicated branch above. Hoisted ahead of the
		   SortName/RepoShort/Language block so that block can respect the
		   same flag (Language / LanguageLocal are unambiguously non-name
		   fields — they'd otherwise still match even when the user asked
		   to narrow the search). */
		$limitToName = ($GLOBALS['caSettings']['searchLimitToName'] ?? "no") === "yes";

		$nameLikeFields = $limitToName
			? [$template['SortName']??null, $template['RepoName']??null]
			: [$template['SortName']??null, $template['RepoShort']??null, $template['Language']??null, $template['LanguageLocal']??null];

		if ( filterMatch($filter,$nameLikeFields) ) {
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

		$anyHitFields = $limitToName
			? [$template['Author']??null, $template['RepoName']??null]
			: [$template['Author']??null, $template['RepoName']??null, $template['Repository']??null, $template['Overview']??null, $template['translatedCategories']??null];

		if ( filterMatch($filter,$anyHitFields) ) {
			if ( $template['RepoName'] == ($GLOBALS['caSettings']['favourite']??null) ) {
				$searchResults['nameHit'][] = $template;
			} else {
				$searchResults['anyHit'][] = $template;
			}
			return;
		}

		if ( ! $limitToName && filterMatch($filter,[$template['ExtraSearchTerms']??null],false) ) {
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

	/* Relevance section order for merged search results. handleFilteredTemplate
	   buckets every match by how strongly it matched (official, full name, name,
	   and so on). The merged list always lays the buckets out in this order so
	   the most relevant sections stay on top no matter which sort the user picks.
	   Kept as one source of truth so the initial search merge and the later
	   per-section re-sort (resortSearchSections) can never drift apart. */
	const SEARCH_SECTION_ORDER = ['officialHit','fullNameHit','nameHit','favNameHit','anyHit','extraHit'];

	/**
	 * Merge the sorted relevance buckets into the flat community list plus a
	 * parallel section index (name + size, in merge order).
	 *
	 * The section index is what lets changeSortOrder slice the flat list back
	 * into its sections and re-sort each one independently, without having to
	 * re-run the whole search. The community order is identical to the legacy
	 * inline merge so every existing reader is unaffected.
	 *
	 * @param  array<string,array<int,array<string,mixed>>>  $searchResults
	 * @return array{community:array<int,array<string,mixed>>,searchSections:array<int,array{name:string,size:int}>}
	 */
	public static function mergeSearchResults($searchResults) {

		$community = [];
		$sections  = [];
		foreach (self::SEARCH_SECTION_ORDER as $name) {
			$bucket = $searchResults[$name] ?? [];
			$sections[] = ['name'=>$name, 'size'=>count($bucket)];
			if ( $bucket ) {
				$community = array_merge($community,$bucket);
			}
		}
		return ['community'=>$community, 'searchSections'=>$sections];
	}

	/**
	 * Re-sort a cached search result set one relevance section at a time.
	 *
	 * Slices the flat community list back into its buckets using the stored
	 * section index, applies the current sort to each section on its own (so
	 * favourites still float to the top of nameHit), then re-merges in the same
	 * relevance order. The sections themselves never move; only the order within
	 * each section changes. Falls back to a flat sort when the cache predates the
	 * section index so a legacy cache still resorts sanely. mySort reads the
	 * freshly written global sort; the stored filter drives the favouriteSort
	 * decision exactly as the original search did.
	 *
	 * @param  array<string,mixed>  $cache  Search cache dict, mutated by reference.
	 * @return void
	 */
	public static function resortSearchSections(&$cache) {

		$community = $cache['community'] ?? [];
		if ( ! is_array($community) || ! $community ) {
			return;
		}

		$sections = $cache['searchSections'] ?? null;
		if ( ! is_array($sections) || ! $sections ) {
			usort($community,"mySort");
			$cache['community'] = $community;
			return;
		}

		$filter = $cache['filter'] ?? "";
		$searchResults = [];
		$offset = 0;
		foreach ($sections as $section) {
			$name = $section['name'] ?? null;
			$size = (int)($section['size'] ?? 0);
			if ( ! $name ) {
				continue;
			}
			$searchResults[$name] = array_slice($community,$offset,$size);
			$offset += $size;
		}

		self::sortSearchResultsBuckets($searchResults,$filter);
		$merged = self::mergeSearchResults($searchResults);
		$cache['community']      = $merged['community'];
		$cache['searchSections'] = $merged['searchSections'];
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