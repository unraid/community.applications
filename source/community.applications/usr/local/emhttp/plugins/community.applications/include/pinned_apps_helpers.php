<?php

class PinnedAppsHelpers {
	/**
	 * Unlink the cache files identified by the given CA_PATHS keys.
	 *
	 * Iterates the supplied keys and removes the on-disk file for each path that
	 * is present in CA_PATHS. Side effect: deletes files via @unlink().
	 *
	 * @param  array<int,string>|string  $cacheKeys  CA_PATHS keys whose files should be removed.
	 * @return void
	 */
	public static function clearPinnedCacheFiles($cacheKeys) {
		foreach ((array)$cacheKeys as $key) {
			if (!empty(CA_PATHS[$key])) {
				@unlink(CA_PATHS[$key]);
			}
		}
	}

	/**
	 * Locate the template entry matching a pinned "repository&sortName" identifier.
	 *
	 * Resolves a pinned app id into its concrete template, skipping blacklisted
	 * duplicates, mismatched SortName entries, and (when requested) incompatible
	 * apps. Falls back to a `library/`-stripped repository lookup when the
	 * initial search fails.
	 *
	 * @param  array<int,array<string,mixed>>  $templates         Template feed (passed by reference for memory).
	 * @param  string                          $pinned            "Repository&SortName" identifier.
	 * @param  bool                            $hideIncompatible  When true, skip templates that aren't compatible.
	 * @return array<string,mixed>|null Matching template, or null when nothing matches.
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

