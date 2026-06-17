<?php
/* Tests for community.applications.plg — PLUGIN element attributes.
   Covers the PR that dropped the unused `launch` entity and launch= attribute:
     - Removed: <!ENTITY launch "Apps">
     - Removed: launch="&launch;" from the <PLUGIN> tag

   These tests verify:
     1. The `launch` entity is absent from the DOCTYPE declarations.
     2. The PLUGIN element carries no `launch` attribute.
     3. Every attribute that was NOT removed is still present with the right values.
     4. The file is well-formed XML (DOMDocument can parse it without errors).
     5. Regression / boundary cases for the entity block.
*/

require_once __DIR__ . '/lib.php';

// ---------------------------------------------------------------------------
// Resolve the PLG file path — repo-relative first, then bail out.
// ---------------------------------------------------------------------------
$plgPath = dirname(__DIR__) . '/plugins/community.applications.plg';
if (!is_file($plgPath)) {
	echo "SKIP: PLG file not found at {$plgPath}\n";
	exit(0);
}

$raw = file_get_contents($plgPath);
if ($raw === false) {
	echo "SKIP: Could not read PLG file at {$plgPath}\n";
	exit(0);
}

echo "=== community.applications.plg — PLUGIN element attributes ===\n\n";

// ---------------------------------------------------------------------------
// 1. Raw-text checks: absence of the removed `launch` entity / attribute
// ---------------------------------------------------------------------------

echo "-- 1. Removed `launch` entity and attribute --\n";

not_ok(
	'DOCTYPE no longer declares a "launch" entity',
	preg_match('/<!ENTITY\s+launch\b/', $raw)
);

not_ok(
	'PLUGIN element no longer carries a launch= attribute',
	preg_match('/<PLUGIN\b[^>]*\blaunch\s*=/', $raw)
);

// The literal string 'Apps' may appear elsewhere (e.g. CHANGES text), so we
// only assert the entity *definition* is gone, not the word itself.
not_ok(
	'No ENTITY definition for the value "Apps" in the DOCTYPE block',
	preg_match('/<!ENTITY\s+launch\s+"Apps"/', $raw)
);

// ---------------------------------------------------------------------------
// 2. Well-formed XML — DOMDocument must parse without critical errors
// ---------------------------------------------------------------------------

echo "\n-- 2. XML well-formedness --\n";

// The PLG file uses DOCTYPE entities (&name;, &author;, etc.).  DOMDocument
// resolves them when LIBXML_DTDLOAD | LIBXML_NONET are set.  We suppress
// warnings during load and check the return value.
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$loaded = $dom->loadXML(
	$raw,
	LIBXML_DTDLOAD | LIBXML_NONET | LIBXML_COMPACT
);
$xmlErrors = libxml_get_errors();
libxml_clear_errors();
libxml_use_internal_errors(false);

// Filter to fatal / error level only; warnings about network DTDs are common.
$fatalErrors = array_filter($xmlErrors, fn($e) => $e->level === LIBXML_ERR_FATAL);

ok(
	'DOMDocument::loadXML() returns true (file is well-formed)',
	$loaded
);

ok(
	'No fatal XML parse errors',
	count($fatalErrors) === 0
);

// ---------------------------------------------------------------------------
// 3. Required attributes retained on the PLUGIN element
// ---------------------------------------------------------------------------

echo "\n-- 3. Required PLUGIN attributes still present --\n";

if ($loaded) {
	$plugins = $dom->getElementsByTagName('PLUGIN');
	ok('Exactly one <PLUGIN> element exists', $plugins->length === 1);

	if ($plugins->length > 0) {
		$plugin = $plugins->item(0);

		// name — resolved from &name; entity → "community.applications"
		eq(
			'name attribute resolves to "community.applications"',
			'community.applications',
			$plugin->getAttribute('name')
		);

		// author — resolved from &author; entity → "Lime Technology"
		eq(
			'author attribute resolves to "Lime Technology"',
			'Lime Technology',
			$plugin->getAttribute('author')
		);

		// version — resolved from &version; entity, non-empty
		ok(
			'version attribute is non-empty',
			$plugin->getAttribute('version') !== ''
		);

		// pluginURL — resolved from &pluginURL; entity, must be a https URL
		ok(
			'pluginURL attribute is a non-empty HTTPS URL',
			strpos($plugin->getAttribute('pluginURL'), 'https://') === 0
		);

		// min — minimum Unraid version required
		eq(
			'min attribute is "6.12.0"',
			'6.12.0',
			$plugin->getAttribute('min')
		);

		// support — must be a non-empty URL
		ok(
			'support attribute is non-empty',
			$plugin->getAttribute('support') !== ''
		);

		// icon — must be "users"
		eq(
			'icon attribute is "users"',
			'users',
			$plugin->getAttribute('icon')
		);

		// launch must be absent (empty string returned when attribute missing)
		eq(
			'launch attribute is absent (getAttribute returns empty string)',
			'',
			$plugin->getAttribute('launch')
		);

		ok(
			'PLUGIN element has no "launch" attribute node',
			!$plugin->hasAttribute('launch')
		);
	}
} else {
	// If loadXML failed, skip DOM-level assertions but keep the failure visible.
	echo "  SKIP: DOM assertions skipped because loadXML failed.\n";
}

// ---------------------------------------------------------------------------
// 4. Remaining DOCTYPE entities are still declared
// ---------------------------------------------------------------------------

echo "\n-- 4. Retained DOCTYPE entity declarations --\n";

$requiredEntities = ['name', 'author', 'version', 'md5', 'plugdir', 'github', 'pluginURL'];
foreach ($requiredEntities as $entity) {
	ok(
		"DOCTYPE still declares entity \"{$entity}\"",
		preg_match('/<!ENTITY\s+' . preg_quote($entity, '/') . '\b/', $raw) === 1
	);
}

// ---------------------------------------------------------------------------
// 5. Regression: verify the exact PLUGIN opening tag no longer contains "launch"
// ---------------------------------------------------------------------------

echo "\n-- 5. Regression: exact PLUGIN tag content --\n";

// Extract the opening PLUGIN tag (everything from <PLUGIN to the closing >)
if (preg_match('/<PLUGIN\b[^>]*>/s', $raw, $m)) {
	$pluginTag = $m[0];

	not_ok(
		'Extracted PLUGIN tag does not contain the word "launch"',
		strpos($pluginTag, 'launch') !== false
	);

	ok(
		'Extracted PLUGIN tag contains "pluginURL="',
		strpos($pluginTag, 'pluginURL=') !== false
	);

	ok(
		'Extracted PLUGIN tag contains "icon="',
		strpos($pluginTag, 'icon=') !== false
	);

	ok(
		'Extracted PLUGIN tag contains "min="',
		strpos($pluginTag, 'min=') !== false
	);
} else {
	eq('Could not extract PLUGIN tag — file structure unexpected', true, false);
}

// ---------------------------------------------------------------------------
// 6. Boundary: entity block is well-delimited (DOCTYPE block closes before PLUGIN)
// ---------------------------------------------------------------------------

echo "\n-- 6. Boundary: DOCTYPE block closes before <PLUGIN> element --\n";

$doctypeEnd = strpos($raw, ']>');
$pluginStart = strpos($raw, '<PLUGIN');

ok(
	'DOCTYPE closing "]>" appears before the <PLUGIN> element',
	$doctypeEnd !== false && $pluginStart !== false && $doctypeEnd < $pluginStart
);

not_ok(
	'The string "launch" does not appear anywhere inside the DOCTYPE block',
	strpos(substr($raw, 0, $doctypeEnd + 2), 'launch') !== false
);

// ---------------------------------------------------------------------------

suite_done();
