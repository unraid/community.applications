<?
########################################
#                                      #
# Community Applications               #
# Copyright 2020-2026, Lime Technology #
# Copyright 2015-2026, Andrew Zawadzki #
#                                      #
# Licensed under GPL-2.0-or-later      #
# SPDX-License-Identifier:             #
#   GPL-2.0-or-later                   #
#                                      #
########################################

/**
 * Dev-mode template-diff backend.
 *
 * Lazy-loaded by exec.php only when the `getTemplateDiff` POST action fires
 * (dev mode + Diff/CA button click). Splitting this out of exec.php keeps the
 * day-to-day request path from parsing ~320 lines of code that 99% of users
 * never trigger.
 *
 * Public entry point: getTemplateDiff(). Everything else here is internal
 * support — XML/JSON renderers, recursive array reorder, application-feed
 * cache helper.
 */

/**
 * Diff the appfeed entry for an app against the upstream XML/.plg, return colorized unified-diff HTML.
 *
 * Only available when dev mode is enabled. Locates the appfeed entry by Path /
 * InstallPath (using the full templates cache so Config/Network survive),
 * fetches the upstream source over caFetchChangelogContents' hardened curl
 * profile, parses it via TypeConverter, strips appfeed-only metadata from both
 * sides, canonicalizes (recursive ksort + pretty JSON), and renders a unified
 * diff with per-line CSS classes.
 */
function getTemplateDiff() {
	if (($GLOBALS['caSettings']['dev'] ?? null) !== "yes") {
		postReturn(["ok"=>false, "message"=>tr("Dev mode is not enabled")]);
		return;
	}
	$appPath = trim((string)getPost("appPath", ""));
	$mode    = trim((string)getPost("mode", "feed"));
	if ($mode !== "feed" && $mode !== "internal") $mode = "feed";
	if ($appPath === "") {
		postReturn(["ok"=>false, "message"=>tr("Missing app path")]);
		return;
	}
	/* Internal mode is gated by the admin marker file — both client and server
	   check it so the response can't be coaxed open without the file. */
	if ($mode === "internal" && !is_file(CA_PATHS['caAdmin'])) {
		postReturn(["ok"=>false, "message"=>tr("Internal diff is not available")]);
		return;
	}

	/* The slim templates cache (loaded by getGlobals() at request start) is
	   enough to find the entry's TemplateURL / PluginURL — used to match
	   against the freshly-downloaded applicationFeed.json. */
	$templates = $GLOBALS['templates'] ?? [];
	if (!is_array($templates) || !$templates) {
		postReturn(["ok"=>false, "message"=>tr("Appfeed not available")]);
		return;
	}
	$localEntry = null;
	foreach ($templates as $t) {
		if (!is_array($t)) continue;
		if (($t['Path'] ?? null) === $appPath || ($t['InstallPath'] ?? null) === $appPath) {
			$localEntry = $t;
			break;
		}
	}
	if (!$localEntry) {
		postReturn(["ok"=>false, "message"=>tr("App not found in appfeed")]);
		return;
	}

	$isPlugin   = !empty($localEntry['Plugin']);
	$matchField = $isPlugin ? "PluginURL" : "TemplateURL";
	$matchUrl   = (string)($localEntry[$matchField] ?? "");
	if ($matchUrl === "") {
		postReturn(["ok"=>false, "message"=>tr("No {$matchField} available for this app")]);
		return;
	}

	/* Both modes need the appfeed entry — download (or reuse cached) the
	   applicationFeed.json and locate the matching entry by TemplateURL /
	   PluginURL. See caGetCachedApplicationFeed for the lock / cache flow. */
	$applicationFeed = caGetCachedApplicationFeed();
	if (!is_array($applicationFeed)) {
		postReturn(["ok"=>false, "message"=>tr("Could not download application feed")]);
		return;
	}
	$appfeedEntry = null;
	foreach ($applicationFeed['applist'] as $candidate) {
		if (!is_array($candidate)) continue;
		if ((string)($candidate[$matchField] ?? "") === $matchUrl) {
			$appfeedEntry = $candidate;
			break;
		}
	}
	if (!$appfeedEntry) {
		postReturn(["ok"=>false, "message"=>tr("Could not find this app in the downloaded application feed")]);
		return;
	}

	$appName = (string)($localEntry['Name'] ?? "");
	$rootName = $isPlugin ? "PLUGIN" : "Container";

	if ($mode === "feed") {
		/* LEFT  = upstream source XML (what the maintainer published)
		   RIGHT = appfeed entry (server's view), reordered to source key order.
		   SSRF gate: caIsPublicHttpUrl (helpers.php) rejects private /
		   link-local / loopback hosts on top of validURL's https-only check,
		   so a feed-controlled TemplateURL can't make us fetch an internal
		   endpoint. */
		if (!caIsPublicHttpUrl($matchUrl)) {
			postReturn(["ok"=>false, "message"=>tr("Source URL is not allowed")]);
			return;
		}
		$raw = caFetchChangelogContents($matchUrl);
		if ($raw === "" || trim($raw) === "") {
			postReturn(["ok"=>false, "message"=>tr("Could not download source from")." ".htmlspecialchars($matchUrl, ENT_QUOTES)]);
			return;
		}
		$sourceArr = @TypeConverter::xmlToArray($raw, TypeConverter::XML_GROUP);
		if (!is_array($sourceArr) || empty($sourceArr)) {
			postReturn(["ok"=>false, "message"=>tr("Could not parse source XML")]);
			return;
		}
		$detectedRoot = caDetectSourceRoot($raw);
		if ($detectedRoot !== "") $rootName = $detectedRoot;
		/* TypeConverter::xmlToArray returns the root element's *children* and
		   silently drops the root's own attributes (e.g. <Container version="2">
		   loses version). Re-read them via simplexml and inject as @attributes
		   so the source side keeps them through the Array2XML round-trip. */
		$rootAttrs = caExtractRootAttributes($raw);
		if (!empty($rootAttrs)) {
			$sourceArr['@attributes'] = $rootAttrs;
		}
		$leftArr  = $sourceArr;
		$rightArr = caReorderByReference($appfeedEntry, $sourceArr);
		/* URL becomes the clickable left-column header. Escape for both the
		   href and the visible text. */
		$safeUrl    = htmlspecialchars($matchUrl, ENT_QUOTES);
		$leftLabel  = "<a href='{$safeUrl}' target='_blank' rel='noopener noreferrer'>{$safeUrl}</a>";
		$rightLabel = htmlspecialchars(tr("appfeed"), ENT_QUOTES);
		$title      = trim($appName . " " . tr("Template Diff"));
	} else {
		/* internal mode:
		   LEFT  = appfeed entry (server's view)
		   RIGHT = CA's internal templates_full.json entry, reordered to the
		           appfeed's key order so the CA-pipeline additions surface at
		           the bottom of the right column. */
		getFullGlobals();
		$fullTemplates = $GLOBALS['templates'] ?? [];
		$internalEntry = null;
		foreach ($fullTemplates as $t) {
			if (!is_array($t)) continue;
			if (($t['Path'] ?? null) === $appPath || ($t['InstallPath'] ?? null) === $appPath) {
				$internalEntry = $t;
				break;
			}
		}
		if (!$internalEntry) {
			postReturn(["ok"=>false, "message"=>tr("App not found in internal templates cache")]);
			return;
		}
		$leftArr    = $appfeedEntry;
		$rightArr   = caReorderByReference($internalEntry, $appfeedEntry);
		$leftLabel  = htmlspecialchars(tr("appfeed"), ENT_QUOTES);
		$rightLabel = htmlspecialchars(tr("internal"), ENT_QUOTES);
		$title      = trim($appName . " " . tr("Internal Diff"));
	}

	if ($mode === "internal") {
		/* Internal diff: both sides are already PHP arrays — render as
		   pretty-printed JSON with 2-space indentation. XML round-trip would
		   only obscure differences in fields the appfeed pipeline added (some
		   of which aren't valid XML tag names anyway). */
		$leftOut  = caRenderAsJson($leftArr);
		$rightOut = caRenderAsJson($rightArr);
	} else {
		$leftOut  = caRenderFeedAsXml($leftArr,  $rootName, $isPlugin);
		$rightOut = caRenderFeedAsXml($rightArr, $rootName, $isPlugin);
		/* Collapse empty elements like <MyPath></MyPath> to <MyPath/> on both
		   sides — Array2XML's saveXML() always emits the long form, and so do
		   some upstream XMLs. */
		$leftOut  = caCollapseEmptyTags($leftOut);
		$rightOut = caCollapseEmptyTags($rightOut);
	}
	if ($leftOut === "" || $rightOut === "") {
		postReturn(["ok"=>false, "message"=>tr("Could not render diff")]);
		return;
	}

	postReturn([
		"ok"         => true,
		"mode"       => $mode,
		"left"       => $leftOut,
		"right"      => $rightOut,
		"leftLabel"  => $leftLabel,
		"rightLabel" => $rightLabel,
		"title"      => $title,
		"identical"  => (trim($leftOut) === trim($rightOut)),
	]);
}

/**
 * Pretty-print a PHP array as JSON with 2-space indentation. json_encode's
 * JSON_PRETTY_PRINT hard-codes 4-space indent, so re-shrink the leading
 * 4-space groups on each line down to 2 — keeps Diff columns narrow.
 */
function caRenderAsJson(array $entry): string {
	$json = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if (!is_string($json)) return "";
	return preg_replace_callback('/^(    )+/m', static function ($m) {
		return str_repeat("  ", intdiv(strlen($m[0]), 4));
	}, $json);
}

/**
 * Fetch (or reuse cached) applicationFeed.json for the dev-mode Diff buttons.
 * Returns the decoded array on success or null on any failure / invalid JSON.
 * Uses download_url's flock-based serializer so concurrent Diff clicks share
 * a single download instead of stacking up.
 */
function caGetCachedApplicationFeed(): ?array {
	$cachePath = CA_PATHS['diffFeedCache'];
	if (is_file($cachePath)) {
		$decoded = json_decode((string)@file_get_contents($cachePath), true);
		/* Require a non-empty applist — DownloadApplicationFeed validates the
		   same way (exec.php:251). An empty applist from a transient bad
		   primary response would otherwise poison the cache and break every
		   subsequent Diff click with "app not found". */
		if (is_array($decoded) && !empty($decoded['applist'] ?? null) && is_array($decoded['applist'])) {
			return $decoded;
		}
		@unlink($cachePath);
	}
	/* 10-minute cURL timeout — the ca_downloadProgress nchan stream lets
	   the user see (and Abort) a long-running fetch, but the timeout caps
	   the worst case if the connection silently stalls. download_json
	   forwards $path to download_url so the flock-based serializer engages. */
	$applicationFeed = download_json(CA_PATHS['application-feed'], $cachePath, 600);
	$ok = is_array($applicationFeed)
		&& is_array($applicationFeed['applist'] ?? null)
		&& !empty($applicationFeed['applist']);
	if (!$ok) {
		/* Primary returned malformed / empty — mirror DownloadApplicationFeed
		   and fall through to the GitHub mirror so a flaky CDN doesn't take
		   the Diff feature offline. */
		@unlink($cachePath);
		$applicationFeed = download_json(CA_PATHS['pluginProxy'] . CA_PATHS['application-feedBackup'], $cachePath, 600);
		$ok = is_array($applicationFeed)
			&& is_array($applicationFeed['applist'] ?? null)
			&& !empty($applicationFeed['applist']);
	}
	if (!$ok) {
		@unlink($cachePath);
		return null;
	}
	return $applicationFeed;
}

/**
 * Peek at a raw XML document and return the root element name, or "" if it
 * isn't parseable. Used so the rendered feed XML can reuse whatever root the
 * upstream chose ("Container" / "PLUGIN" / anything else) instead of hard-coding.
 */
function caDetectSourceRoot(string $raw): string {
	if ($raw === "") return "";
	$xml = @simplexml_load_string($raw, "SimpleXMLElement", LIBXML_NONET | LIBXML_NOCDATA);
	return $xml ? (string)$xml->getName() : "";
}

/**
 * Pull attribute name=>value pairs off the root element of a raw XML string.
 * Returns [] when the document doesn't parse or the root has no attributes.
 *
 * TypeConverter::xmlToArray with XML_GROUP only returns the root's children —
 * any attributes on the root element itself are silently dropped. Use this to
 * recover them and stamp them back as @attributes before round-tripping
 * through Array2XML, otherwise things like <Container version="2"> become
 * <Container> and the diff falsely "removes" the version attribute.
 *
 * @return array<string,string>
 */
function caExtractRootAttributes(string $raw): array {
	if ($raw === "") return [];
	$xml = @simplexml_load_string($raw, "SimpleXMLElement", LIBXML_NONET | LIBXML_NOCDATA);
	if (!$xml) return [];
	$out = [];
	foreach ($xml->attributes() as $name => $value) {
		$out[(string)$name] = (string)$value;
	}
	return $out;
}

/**
 * Convert an appfeed entry (or parsed source array) into a pretty-printed XML
 * string by running it through the Array2XML library directly. The only
 * pre-processing is a non-destructive value/@value promotion (Array2XML's
 * text-content convention) — no fixAttributes, no Config/Network rewriting,
 * no denylist. Everything in the array is preserved so genuine differences
 * surface in the diff.
 *
 * Returns "" on conversion error (e.g. an XML-illegal tag name).
 */
function caRenderFeedAsXml(array $entry, string $rootName, bool $isPlugin): string {
	caNormalizeForArray2XML($entry);
	try {
		$doc = (new Array2XML())->createXML($rootName, $entry);
		$out = ($doc instanceof DOMDocument) ? $doc->saveXML() : "";
		return is_string($out) ? $out : "";
	} catch (Throwable $e) {
		return "";
	}
}

/**
 * Collapse `<Tag></Tag>` (optionally with attributes, optionally with
 * whitespace between the open/close) into self-closing `<Tag/>` form for
 * display. Pure textual transform — only touches well-formed paired empties,
 * leaves elements with content alone.
 */
function caCollapseEmptyTags(string $xml): string {
	$out = preg_replace(
		'/<([A-Za-z_][\w.\-:]*)((?:\s+[^<>]*?)?)>\s*<\/\1\s*>/',
		'<$1$2/>',
		$xml
	);
	return is_string($out) ? $out : $xml;
}

/**
 * Promote appfeed "value" keys to Array2XML's "@value" shape so paired
 * (@attributes,value) nodes serialize as text-bearing elements rather than as
 * extra <value> children. Acts in place, recursing.
 */
function caNormalizeForArray2XML(array &$arr): void {
	if (array_key_exists('@attributes', $arr) && array_key_exists('value', $arr) && !array_key_exists('@value', $arr)) {
		$arr['@value'] = $arr['value'];
		unset($arr['value']);
	}
	foreach ($arr as &$v) {
		if (is_array($v)) caNormalizeForArray2XML($v);
	}
}

/**
 * Reorder $subject so its keys appear in the same order they appear in
 * $reference, walking arrays recursively. Keys present in $subject but absent
 * from $reference get appended at the end of their parent so nothing is
 * dropped. For numerically-indexed lists, recurse element-by-element to the
 * common prefix and pass extras through verbatim.
 *
 * @param mixed $subject
 * @param mixed $reference
 * @return mixed The reordered structure (or $subject unchanged when it isn't an array)
 */
function caReorderByReference($subject, $reference) {
	if (!is_array($subject) || !is_array($reference)) return $subject;

	$subjectIsList   = array_is_list($subject);
	$referenceIsList = array_is_list($reference);
	if ($subjectIsList && $referenceIsList) {
		$out = [];
		$n   = max(count($subject), count($reference));
		for ($i = 0; $i < $n; $i++) {
			if (array_key_exists($i, $subject) && array_key_exists($i, $reference)) {
				$out[] = caReorderByReference($subject[$i], $reference[$i]);
			} elseif (array_key_exists($i, $subject)) {
				$out[] = $subject[$i];
			}
		}
		return $out;
	}

	$out = [];
	foreach ($reference as $k => $refVal) {
		if (array_key_exists($k, $subject)) {
			$out[$k] = caReorderByReference($subject[$k], $refVal);
		}
	}
	/* Subject-only keys (no counterpart in the reference) — sort them
	   alphabetically before appending so the trailing "extras" block in the
	   feed diff is easy to scan. */
	$extras = [];
	foreach ($subject as $k => $subVal) {
		if (!array_key_exists($k, $out)) {
			$extras[$k] = $subVal;
		}
	}
	ksort($extras);
	foreach ($extras as $k => $v) {
		$out[$k] = $v;
	}
	return $out;
}

/* Line-diff / side-by-side rendering moved to helpers.js (caComputeLineOps +
   caRenderDiff). PHP just returns the two XML strings via getTemplateDiff. */
