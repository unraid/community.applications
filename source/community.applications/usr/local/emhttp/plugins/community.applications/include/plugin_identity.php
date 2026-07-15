<?php
/* Copyright 2026, Lime Technology
 * Licensed under GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Determine whether an installed plugin URL represents an app-feed template.
 *
 * Exact URL equality remains the default identity rule. Community Applications
 * PR previews are a bounded exception: their per-PR URL must remain installed
 * so WebGUI update checks follow the preview, while the Apps page must still
 * recognize the canonical CA template as installed.
 */
function caPluginUrlMatchesTemplate(string $installedUrl, string $templateUrl): bool {
	$installedUrl = strtolower(trim($installedUrl));
	$templateUrl = strtolower(trim($templateUrl));

	if ($installedUrl === $templateUrl) {
		return true;
	}

	$canonicalCaUrl = "https://raw.githubusercontent.com/unraid/community.applications/master/plugins/community.applications.plg";
	if ($templateUrl !== $canonicalCaUrl) {
		return false;
	}

	return preg_match(
		'#^https://raw\.githubusercontent\.com/unraid/community\.applications/pr-previews/pr/[1-9][0-9]*/community\.applications\.plg$#',
		$installedUrl
	) === 1;
}
