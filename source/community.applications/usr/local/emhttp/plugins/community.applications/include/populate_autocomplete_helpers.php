<?php

class PopulateAutoCompleteHelpers {
	/**
	 * Waits until the global templates list becomes non-empty and returns it.
	 *
	 * Continuously polls $GLOBALS['templates'] and returns its value once a non-empty array is present.
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
	 * Builds a list of base autocomplete suggestions from the configured category list.
	 *
	 * Each suggestion is derived from a category's `Cat` value, translated and with `:` characters removed.
	 *
	 * @return string[] An array of suggestion strings derived from category `Cat` values.
	 */
	public static function buildBaseSuggestions() {
		$categories = (array)readJsonFile(CA_PATHS['categoryList']);

		return array_map(function ($category) {
			return str_replace(":", "", tr($category['Cat']));
		}, $categories);
	}

	/**
	 * Merge suggestions derived from each template into an existing autocomplete map.
	 *
	 * Iterates over provided templates, normalizes each one, skips templates excluded
	 * by inclusion rules, and augments the autocomplete map with language, repository,
	 * name, author, and extra-term suggestions.
	 *
	 * @param array $templates List of template metadata arrays.
	 * @param array $autoComplete Associative autocomplete map to augment (suggestionKey => suggestionValue).
	 * @return array The updated autocomplete map with suggestions added from templates.
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
	 * Produce a compact list of suggestion entries from accumulated autocomplete values.
	 *
	 * @param array $autoComplete Accumulated suggestions which may contain duplicates or empty values.
	 * @return array An array of suggestions with duplicates and empty values removed and numeric indexing restored.
	 */
	public static function finalizeSuggestions($autoComplete) {
		return array_values(array_filter(array_unique($autoComplete)));
	}

	/**
	 * Determine whether a template should be included in autocomplete suggestions.
	 *
	 * Considers template metadata and global settings:
	 * - Excludes templates flagged as repository templates.
	 * - Treats templates with a non-empty `Blacklist` as hidden.
	 * - Treats templates with a non-empty `Deprecated` as hidden when `caSettings.hideDeprecated` is `"true"`.
	 * - Treats templates with an empty `Compatible` as hidden when `caSettings.hideIncompatible` is `"true"`.
	 * - Templates marked `Featured` are included regardless of hidden flags.
	 *
	 * @param array $template Associative array of template metadata.
	 * @return bool `true` if the template should be included, `false` otherwise.
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
	 * Add language-based autocomplete entries when both `Language` and `LanguageLocal` are present.
	 *
	 * If both language fields exist and are non-empty, this adds two entries to the suggestions
	 * map: the lowercase `Language` key mapping to the original `Language` value, and the
	 * lowercase `LanguageLocal` key mapping to the original `LanguageLocal` value.
	 *
	 * @param array $template Template metadata; expects `Language` and `LanguageLocal` keys.
	 * @param array $autoComplete Current autocomplete suggestions map (key => value).
	 * @return array The updated autocomplete suggestions map.
	 */
	private static function addLanguageSuggestions($template, $autoComplete) {
		if (!empty($template['Language']) && !empty($template['LanguageLocal'])) {
			$autoComplete[strtolower($template['Language'])] = $template['Language'];
			$autoComplete[strtolower($template['LanguageLocal'])] = $template['LanguageLocal'];
		}

		return $autoComplete;
	}

	/ **
	 * Adds the template's repository name to the autocomplete map when language information is missing.
	 *
	 * If either `Language` or `LanguageLocal` is empty and `Repo` is present, the repository name is added
	 * as both the key and value in the autocomplete map.
	 *
	 * @param array $template Template metadata array.
	 * @param array $autoComplete Current autocomplete map (string => string).
	 * @return array The updated autocomplete map, possibly containing the repository suggestion.
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
	 * Add name-based autocomplete suggestions derived from a template's SortName.
	 *
	 * If the template provides a non-empty `SortName`, the lowercase trimmed value is added
	 * as a suggestion key/value. Additionally, variants with common vendor prefixes
	 * ("dynamix ", "ca ", "binhex ", "activ ") removed are added when they differ and are non-empty.
	 *
	 * @param array $template Template data; uses the `SortName` field if present.
	 * @param array $autoComplete Current suggestion map (key => value) to augment.
	 * @return array The augmented suggestion map containing the name and any stripped variants.
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
	 * Adds an author-based suggestion to the autocomplete map when appropriate.
	 *
	 * If the template provides an Author, this will add a lowercase author key
	 * mapping to the original Author name unless either "<author>'s repository"
	 * or "<author>' repository" keys are already present in the suggestions.
	 *
	 * @param array $template Template metadata; the `Author` field is used.
	 * @param array $autoComplete Current autocomplete suggestions as an associative array of keys to values.
	 * @return array The updated autocomplete suggestions array.
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
	 * Adds template-provided extra search terms into the autocomplete map.
	 *
	 * Decodes any "%20" sequences to spaces, lowercases each term, and stores it
	 * as a key => value pair in the autocomplete array.
	 *
	 * @param array $template Template data; expects 'ExtraSearchTerms' as a space-separated string.
	 * @param array $autoComplete Existing autocomplete suggestions map.
	 * @return array The updated autocomplete suggestions map including the extra terms.
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
	 * Removes the given prefix from a string if the string starts with that prefix.
	 *
	 * @param string $value The input string.
	 * @param string $prefix The prefix to remove.
	 * @return string The string with the prefix removed when present, otherwise the original string.
	 */
	private static function stripPrefix($value, $prefix) {
		if (startsWith($value, $prefix)) {
			return substr($value, strlen($prefix));
		}

		return $value;
	}
}

