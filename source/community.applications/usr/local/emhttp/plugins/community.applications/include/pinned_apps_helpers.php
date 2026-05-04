<?php

class PinnedAppsHelpers {
	/**
	 * Delete cached files associated with the given cache key or keys.
	 *
	 * @param string|string[] $cacheKeys One cache key or an array of cache keys; keys not present in CA_PATHS are ignored.
	 */
	public static function clearPinnedCacheFiles($cacheKeys) {
		foreach ((array)$cacheKeys as $key) {
			if (!empty(CA_PATHS[$key])) {
				@unlink(CA_PATHS[$key]);
			}
		}
	}

	/**
	 * Locate a pinned template in a list of templates using a "repository&sortName" key.
	 *
	 * Parses the `$pinned` string into `repository` and `sortName`, searches `$templates` for a matching `Repository`,
	 * skips entries that are blacklisted, have a differing `SortName`, or (when `$hideIncompatible` is true) are incompatible,
	 * and returns the first template that satisfies all checks.
	 *
	 * @param array &$templates List of template records to search; modified only to support indexed searching.
	 * @param string $pinned A string in the form "repository&sortName" identifying the pinned template.
	 * @param bool $hideIncompatible When true, ignore templates whose `Compatible` field is empty.
	 * @return array|null The matching template record if found, or `null` if no suitable template exists.
	 */
	public static function findPinnedTemplate(&$templates, $pinned, $hideIncompatible) {
		$search = explode("&", $pinned);
		if (count($search) < 2) {
			return null;
		}

		list($repository, $sortName) = $search;
		$startIndex = 0;

		while (true) {
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

