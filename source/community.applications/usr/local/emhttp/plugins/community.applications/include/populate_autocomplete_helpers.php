<?php

class PopulateAutoCompleteHelpers {
  public static function waitForTemplates() {
    while (true) {
      $templates = $GLOBALS['templates'] ?? [];
      if (!empty($templates)) {
        return $templates;
      }

      sleep(1);
    }
  }

  public static function buildBaseSuggestions($caPaths) {
    $categories = (array)readJsonFile($caPaths['categoryList']);

    return array_map(function ($category) {
      return str_replace(":", "", tr($category['Cat']));
    }, $categories);
  }

  public static function addTemplateSuggestions($templates, $autoComplete, $caSettings) {
    foreach ($templates as $template) {
      $template = addMissingVars($template);

      if (!self::shouldIncludeTemplate($template, $caSettings)) {
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

  public static function finalizeSuggestions($autoComplete) {
    return array_values(array_filter(array_unique($autoComplete)));
  }

  private static function shouldIncludeTemplate($template, $caSettings) {
    if (!empty($template['RepoTemplate'])) {
      return false;
    }

    $isHidden = !empty($template['Blacklist']);
    $isDeprecatedHidden = !empty($template['Deprecated']) && ($caSettings['hideDeprecated'] ?? "false") === "true";
    $isIncompatibleHidden = empty($template['Compatible']) && ($caSettings['hideIncompatible'] ?? "false") === "true";
    $isFeatured = !empty($template['Featured']);

    return (!$isHidden && !$isDeprecatedHidden && !$isIncompatibleHidden) || $isFeatured;
  }

  private static function addLanguageSuggestions($template, $autoComplete) {
    if (!empty($template['Language']) && !empty($template['LanguageLocal'])) {
      $autoComplete[strtolower($template['Language'])] = $template['Language'];
      $autoComplete[strtolower($template['LanguageLocal'])] = $template['LanguageLocal'];
    }

    return $autoComplete;
  }

  private static function addRepositorySuggestion($template, $autoComplete) {
    if (empty($template['Language']) || empty($template['LanguageLocal'])) {
      if (!empty($template['Repo'])) {
        $autoComplete[$template['Repo']] = $template['Repo'];
      }
    }

    return $autoComplete;
  }

  private static function addNameSuggestion($template, $autoComplete) {
    $nameKey = trim(strtolower($template['SortName'] ?? ""));
    if ($nameKey === "") {
      return $autoComplete;
    }

    $autoComplete[$nameKey] = $nameKey;
    $autoComplete[$nameKey] = self::stripPrefix($autoComplete[$nameKey], "dynamix ");
    $autoComplete[$nameKey] = self::stripPrefix($autoComplete[$nameKey], "ca ");
    $autoComplete[$nameKey] = self::stripPrefix($autoComplete[$nameKey], "binhex ");
    $autoComplete[$nameKey] = self::stripPrefix($autoComplete[$nameKey], "activ ");

    return $autoComplete;
  }

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

  private static function stripPrefix($value, $prefix) {
    if (startsWith($value, $prefix)) {
      return str_replace($prefix, "", $value);
    }

    return $value;
  }
}

