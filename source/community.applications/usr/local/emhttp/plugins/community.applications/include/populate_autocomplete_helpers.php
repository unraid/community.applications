<?php

class PopulateAutoCompleteHelpers {
	/**
	 * Block until $GLOBALS['templates'] is populated, then return it.
	 *
	 * Side effect: sleeps in a 1-second loop while waiting for another request
	 * to finish populating the global templates array.
	 *
	 * @return array<int,array<string,mixed>> The populated templates array.
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
	 * Build the initial autocomplete seed list from the cached category list.
	 *
	 * Reads CA_PATHS['categoryList'] from disk and maps each category through
	 * the translation layer, stripping trailing colons.
	 *
	 * @return array<int,string> Translated category names.
	 */
	public static function buildBaseSuggestions() {
		$categories = (array)readJsonFile(CA_PATHS['categoryList']);

		return array_map(function ($category) {
			return str_replace(":", "", tr($category['Cat']));
		}, $categories);
	}

	/**
	 * Append per-template suggestions (language, repo, name, author, extras) to the autocomplete map.
	 *
	 * @param  array<int,array<string,mixed>>  $templates     Application feed templates.
	 * @param  array<string,string>            $autoComplete  Existing keyed suggestion map.
	 * @return array<string,string> Updated suggestion map.
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
	 * De-duplicate, drop empty entries, and reset numeric indexing on the suggestion map.
	 *
	 * @param  array<string,string>  $autoComplete  Suggestion map.
	 * @return array<int,string> Indexed list of suggestion strings.
	 */
	public static function finalizeSuggestions($autoComplete) {
		return array_values(array_filter(array_unique($autoComplete)));
	}

	/**
	 * Decide whether a template should contribute autocomplete entries.
	 *
	 * Featured templates always win. Otherwise excludes RepoTemplate rows,
	 * blacklisted apps, hide-when-deprecated apps (per caSettings), and
	 * hide-when-incompatible apps (per caSettings). Reads global $caSettings.
	 *
	 * @param  array<string,mixed>  $template
	 * @return bool
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
	 * Add Language / LanguageLocal entries to the autocomplete map (both lowercased keys).
	 *
	 * @param  array<string,mixed>   $template
	 * @param  array<string,string>  $autoComplete
	 * @return array<string,string>
	 */
	private static function addLanguageSuggestions($template, $autoComplete) {
		if (!empty($template['Language']) && !empty($template['LanguageLocal'])) {
			$autoComplete[strtolower($template['Language'])] = $template['Language'];
			$autoComplete[strtolower($template['LanguageLocal'])] = $template['LanguageLocal'];
		}

		return $autoComplete;
	}

	/**
	 * Add the template's Repo as an autocomplete entry when no language data is present.
	 *
	 * @param  array<string,mixed>   $template
	 * @param  array<string,string>  $autoComplete
	 * @return array<string,string>
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
	 * Add the template's SortName plus common-prefix-stripped variants ("dynamix ", "ca ", "binhex ", "activ ").
	 *
	 * @param  array<string,mixed>   $template
	 * @param  array<string,string>  $autoComplete
	 * @return array<string,string>
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
	 * Add the template Author as an autocomplete entry, unless a "<author>'s repository" variant already exists.
	 *
	 * @param  array<string,mixed>   $template
	 * @param  array<string,string>  $autoComplete
	 * @return array<string,string>
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
	 * Split the template's ExtraSearchTerms (space-delimited, %20 decoded) into lowercase suggestion entries.
	 *
	 * @param  array<string,mixed>   $template
	 * @param  array<string,string>  $autoComplete
	 * @return array<string,string>
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
	 * Return $value with $prefix removed when present, otherwise return $value unchanged.
	 *
	 * @param  string  $value
	 * @param  string  $prefix
	 * @return string
	 */
	private static function stripPrefix($value, $prefix) {
		if (startsWith($value, $prefix)) {
			return substr($value, strlen($prefix));
		}

		return $value;
	}
}

