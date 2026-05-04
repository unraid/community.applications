<?php

class PopulateAutoCompleteHelpers {
	/**
	 * Waits until the global templates array is populated.
	 *
	 * @return array The templates array from $GLOBALS['templates'].
	 */
	public static function waitForTemplates() {
		while (true) {
			$templates = $GLOBALS['templates'] ?? [];
			if (!empty($templates)) {
				return $templates;
			}

			sleep(1);
		}
	}

	/**
	 * Build the base autocomplete suggestions from the category list.
	 *
	 * Each suggestion is the translated category name with any ':' characters removed.
	 *
	 * @return string[] Array of category suggestion strings.
	 */
	public static function buildBaseSuggestions() {
		$categories = (array)readJsonFile(CA_PATHS['categoryList']);

		return array_map(function ($category) {
			return str_replace(":", "", tr($category['Cat']));
		}, $categories);
	}

	/**
	 * Merge suggestions derived from each template into the provided autocomplete map.
	 *
	 * Each template is normalized, skipped if excluded by inclusion rules, and—when included—contributes language, repository, name, author, and extra search-term suggestions to the map.
	 *
	 * @param array $templates List of template data arrays to process.
	 * @param array $autoComplete Existing suggestion map (key => value) to augment.
	 * @return array The updated suggestion map containing entries added from included templates.
	 */
	public static function addTemplateSuggestions($templates, $autoComplete) {
		foreach ($templates as $template) {
			$template = addMissingVars($template);

			if (!self::shouldIncludeTemplate($template)) {
				continue;
			}

			$autoComplete = self::addLanguageSuggestions($template, $autoComplete);
			$autoComplete = self::addRepositorySuggestion($template, $autoComplete);
			$autoComplete = self::addNameSuggestion($template, $autoComplete);
			$autoComplete = self::addAuthorSuggestion($template, $autoComplete);
			$autoComplete = self::addExtraSearchTerms($template, $autoComplete);
		}

		return $autoComplete;
	}

	/**
	 * Produce the final normalized list of autocomplete suggestions.
	 *
	 * Removes duplicate and falsy values from the provided suggestions and returns them reindexed with sequential integer keys.
	 *
	 * @param array $autoComplete Accumulated suggestion values (may contain duplicates or falsy entries).
	 * @return array The unique, truthy suggestions reindexed starting at zero.
	 */
	public static function finalizeSuggestions($autoComplete) {
		return array_values(array_filter(array_unique($autoComplete)));
	}

	/**
	 * Determines whether a template should contribute autocomplete suggestions.
	 *
	 * Evaluates template metadata and excludes templates that are repository templates
	 * or are hidden by blacklist, deprecated-setting, or incompatibility-setting; a
	 * template marked as featured will be included regardless of those hide flags.
	 *
	 * @param array $template Template metadata. Recognized keys: `RepoTemplate`, `Blacklist`, `Deprecated`, `Compatible`, `Featured`.
	 * @return bool `true` if the template should contribute suggestions, `false` otherwise.
	 */
	private static function shouldIncludeTemplate($template) {
		if (!empty($template['RepoTemplate'])) {
			return false;
		}

		$isHidden = !empty($template['Blacklist']);
		$isDeprecatedHidden = !empty($template['Deprecated']) && ($GLOBALS['caSettings']['hideDeprecated'] ?? "false") === "true";
		$isIncompatibleHidden = empty($template['Compatible']) && ($GLOBALS['caSettings']['hideIncompatible'] ?? "false") === "true";
		$isFeatured = !empty($template['Featured']);

		return (!$isHidden && !$isDeprecatedHidden && !$isIncompatibleHidden) || $isFeatured;
	}

	/**
	 * Add language-based autocomplete entries when both `Language` and `LanguageLocal` are present in the template.
	 *
	 * When both keys exist and are non-empty, adds entries keyed by the lowercase form of each value mapping to the original value.
	 *
	 * @param array $template Template data; expects `Language` and `LanguageLocal` keys.
	 * @param array $autoComplete Current suggestions map (string => string).
	 * @return array The updated suggestions map including any added language entries.
	 */
	private static function addLanguageSuggestions($template, $autoComplete) {
		if (!empty($template['Language']) && !empty($template['LanguageLocal'])) {
			$autoComplete[strtolower($template['Language'])] = $template['Language'];
			$autoComplete[strtolower($template['LanguageLocal'])] = $template['LanguageLocal'];
		}

		return $autoComplete;
	}

	/**
	 * Adds the template's repository name to suggestions when language information is missing.
	 *
	 * If either `Language` or `LanguageLocal` is empty and `Repo` is present, inserts the repository
	 * value into the autocomplete map using the repository string as both key and value.
	 *
	 * @param array $template Template metadata array (may contain keys like 'Language', 'LanguageLocal', 'Repo').
	 * @param array $autoComplete Current autocomplete map (string keys to suggestion values).
	 * @return array The updated autocomplete map including the repository suggestion when applicable.
	 */
	private static function addRepositorySuggestion($template, $autoComplete) {
		if (empty($template['Language']) || empty($template['LanguageLocal'])) {
			if (!empty($template['Repo'])) {
				$autoComplete[$template['Repo']] = $template['Repo'];
			}
		}

		return $autoComplete;
	}

	/**
	 * Add a lowercased SortName entry and common prefix-stripped variants to the autocomplete map.
	 *
	 * If the template provides a non-empty `SortName`, this adds an entry keyed by the trimmed, lowercased
	 * `SortName`. It also adds entries for any variants produced by removing the prefixes "dynamix ",
	 * "ca ", "binhex ", and "activ " when those removals produce a different non-empty value.
	 *
	 * @param array $template Template data; expected to contain `SortName` when present.
	 * @param array $autoComplete Associative map of suggestion keys to suggestion values to be extended.
	 * @return array The updated autocomplete map with added name-based suggestions.
	 */
	private static function addNameSuggestion($template, $autoComplete) {
		$nameKey = trim(strtolower($template['SortName'] ?? ""));
		if ($nameKey === "") {
			return $autoComplete;
		}

		$autoComplete[$nameKey] = $nameKey;

		foreach (["dynamix ", "ca ", "binhex ", "activ "] as $prefix) {
			$stripped = self::stripPrefix($nameKey, $prefix);
			if ($stripped !== $nameKey && $stripped !== "") {
				$autoComplete[$stripped] = $stripped;
			}
		}

		return $autoComplete;
	}

	/**
	 * Add an author-based suggestion to the autocomplete map when appropriate.
	 *
	 * If the template contains an Author and neither "<author>'s repository" nor
	 * "<author>' repository" keys already exist in the map, this adds a lowercase
	 * author key with the original author string as its value.
	 *
	 * @param array $template Template data; expects an 'Author' entry when present.
	 * @param array $autoComplete Current autocomplete map (keys => display values).
	 * @return array The updated autocomplete map.
	 */
	private static function addAuthorSuggestion($template, $autoComplete) {
		if (empty($template['Author'])) {
			return $autoComplete;
		}

		$authorKey = strtolower($template['Author']);
		$possessiveKey = "{$authorKey}'s repository";
		$altKey = "{$authorKey}' repository";

		if (!isset($autoComplete[$possessiveKey]) && !isset($autoComplete[$altKey])) {
			$autoComplete[$authorKey] = $template['Author'];
		}

		return $autoComplete;
	}

	/**
	 * Adds space-separated extra search terms from a template into the autocomplete map.
	 *
	 * Each term from `ExtraSearchTerms` has literal "%20" decoded to a space, is lowercased,
	 * and is inserted into `$autoComplete` as both key and value.
	 *
	 * @param array $template Template data; expects an `ExtraSearchTerms` string of space-separated terms.
	 * @param array $autoComplete Associative map of autocomplete suggestions to update.
	 * @return array The updated autocomplete map including normalized extra search terms.
	 */
	private static function addExtraSearchTerms($template, $autoComplete) {
		if (empty($template['ExtraSearchTerms'])) {
			return $autoComplete;
		}

		foreach (explode(" ", $template['ExtraSearchTerms']) as $searchTerm) {
			$searchTerm = str_replace("%20", " ", $searchTerm);
			$autoComplete[strtolower($searchTerm)] = strtolower($searchTerm);
		}

		return $autoComplete;
	}

	/**
	 * Remove the given prefix from a string if the string starts with that prefix.
	 *
	 * @param string $value The input string to process.
	 * @param string $prefix The prefix to remove.
	 * @return string The resulting string with the prefix removed if it was present, otherwise the original string.
	 */
	private static function stripPrefix($value, $prefix) {
		if (startsWith($value, $prefix)) {
			return substr($value, strlen($prefix));
		}

		return $value;
	}
}

