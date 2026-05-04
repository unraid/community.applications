<?php

class PinnedAppsHelpers {
	/**
	 * Removes pinned cache files for the given cache keys by deleting the files referenced in CA_PATHS.
	 *
	 * @param string|array $cacheKeys One cache key or an array of cache keys whose corresponding CA_PATHS entries should be deleted if present.
	 */
	public static function clearPinnedCacheFiles($cacheKeys) {
		foreach ((array)$cacheKeys as $key) {
			if (!empty(CA_PATHS[$key])) {
				@unlink(CA_PATHS[$key]);
			}
		}
	}

	/**
	 * Locate a pinned app template matching a repository and sort name from a templates list.
	 *
	 * Accepts a `$pinned` string in the form `"repository&sortName"`. Searches `$templates` for an entry whose
	 * `Repository` equals `repository` (also tries without a leading `library/` prefix) and whose `SortName` equals
	 * `sortName`, skipping entries with a non-empty `Blacklist`. When `$hideIncompatible` is true, entries with an empty
	 * `Compatible` field are ignored. Returns the first matching template or `null` if none is found or `$pinned` is invalid.
	 *
	 * @param array $templates Array of associative template records (each record may contain keys like `Repository`, `SortName`, `Blacklist`, `Compatible`).
	 * @param string $pinned A string in the form "repository&sortName".
	 * @param bool $hideIncompatible When true, exclude templates with an empty `Compatible` field.
	 * @return array|null The matching template associative array, or `null` if no match is found.
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

