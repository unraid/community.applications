<?php

class PinnedAppsHelpers {
  public static function clearPinnedCacheFiles($caPaths, $cacheKeys) {
    foreach ((array)$cacheKeys as $key) {
      if (!empty($caPaths[$key])) {
        @unlink($caPaths[$key]);
      }
    }
  }

  public static function findPinnedTemplate(&$templates, $pinned, $hideIncompatible) {
    $search = explode("&", $pinned);
    if (count($search) < 2) {
      return null;
    }

    list($repository, $sortName) = $search;
    $startIndex = 0;

    for ($i = 0; $i < 10; $i++) {
      $index = searchArray($templates, "Repository", $repository, $startIndex);
      if ($index === false && strpos($repository, "library/") !== false) {
        $index = searchArray($templates, "Repository", str_replace("library/", "", $repository), $startIndex);
      }

      if ($index === false) {
        break;
      }

      $template = $templates[$index];

      if (!empty($template['Blacklist'])) { #This handles things like duplicated templates
        $startIndex = $index + 1;
        continue;
      }

      if (($template['SortName'] ?? null) !== $sortName) {
        $startIndex = $index + 1;
        continue;
      }

      if ($hideIncompatible && empty($template['Compatible'])) {
        $startIndex = $index + 1;
        continue;
      }

      return $template;
    }

    return null;
  }
}

