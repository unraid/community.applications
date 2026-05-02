<?php
/* End-to-end tests for the README/changelog markdown sanitizer pipeline.

   Replicates the inline anchor/img sanitizer logic from include/exec.php's
   caDownloadAndRenderReadme / caDownloadAndRenderTemplateChanges so the test
   harness can call it directly with synthetic input.

   Validates:
     - strip_tags() removes raw HTML before markdown sees it
     - markdown links / images get rebuilt with positive attribute whitelist
       (href/src + alt/title only, target/rel forced)
     - href / src must pass caIsPublicHttpUrl, else wrapper stripped / image removed
     - title-attribute injection attempts (quote-break, onclick text inside
       title) are sealed by htmlspecialchars(ENT_QUOTES) on emit
*/

require_once __DIR__ . "/lib.php";
require_once "/usr/local/emhttp/plugins/dynamix/include/MarkdownExtra.inc.php";
require_once "/usr/local/emhttp/plugins/community.applications/include/helpers.php";

function ca_render_markdown(string $raw): string {
	$cleaned = strip_tags($raw);
	$html = Markdown($cleaned);
	$html = preg_replace_callback(
		"/<a\\b([^>]*)>(.*?)<\\/a>/is",
		static function ($m) {
			$attrs = $m[1]; $inner = $m[2];
			if (preg_match("/\\bhref\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))/i", $attrs, $h)) {
				$href = ($h[2] ?? "") ?: (($h[3] ?? "") ?: ($h[4] ?? ""));
				if (caIsPublicHttpUrl($href)) {
					$safeHref = htmlspecialchars($href, ENT_QUOTES);
					$titleHtml = "";
					if (preg_match("/\\btitle\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))/i", $attrs, $t)) {
						$title = ($t[2] ?? "") ?: (($t[3] ?? "") ?: ($t[4] ?? ""));
						if ($title !== "") $titleHtml = " title='".htmlspecialchars($title, ENT_QUOTES)."'";
					}
					return "<a href='{$safeHref}' target='_blank' rel='noopener noreferrer'{$titleHtml}>{$inner}</a>";
				}
			}
			return $inner;
		},
		$html
	);
	$html = preg_replace_callback(
		"/<(img)\\b([^>]*)>/i",
		static function ($m) {
			$attrs = $m[2];
			if (preg_match("/\\bsrc\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))/i", $attrs, $s)) {
				$src = ($s[2] ?? "") ?: (($s[3] ?? "") ?: ($s[4] ?? ""));
				if (!caIsPublicHttpUrl($src)) return "";
				$safeSrc = htmlspecialchars($src, ENT_QUOTES);
				$alt = "image";
				if (preg_match("/\\balt\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))/i", $attrs, $a)) {
					$altRaw = ($a[2] ?? "") ?: (($a[3] ?? "") ?: ($a[4] ?? ""));
					if ($altRaw !== "") $alt = $altRaw;
				}
				$titleHtml = "";
				if (preg_match("/\\btitle\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))/i", $attrs, $t)) {
					$title = ($t[2] ?? "") ?: (($t[3] ?? "") ?: ($t[4] ?? ""));
					if ($title !== "") $titleHtml = " title='".htmlspecialchars($title, ENT_QUOTES)."'";
				}
				return "<img src='{$safeSrc}' alt='".htmlspecialchars($alt, ENT_QUOTES)."'{$titleHtml}>";
			}
			return "";
		},
		$html
	);
	return trim((string)$html);
}

/* Use DOMDocument to inspect the actual emitted attributes on <a> /<img> —
   substring matches can't tell "onclick" inside a title value from a real
   onclick attribute. */
function ca_anchor_attrs(string $html): array {
	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML("<!DOCTYPE html><html><body>{$html}</body></html>", LIBXML_NONET);
	libxml_clear_errors();
	$result = [];
	foreach ($dom->getElementsByTagName("a") as $a) {
		$attrs = [];
		foreach ($a->attributes as $name => $node) $attrs[strtolower($name)] = $node->nodeValue;
		$result[] = $attrs;
	}
	return $result;
}

echo "=== Legitimate markdown ===\n";

$out = ca_render_markdown("Visit [docs](https://docs.example.com) for info");
ok("legit link kept with text",
   strpos($out, "<a href='https://docs.example.com'") !== false &&
   strpos($out, ">docs</a>") !== false,
   $out);

$out = ca_render_markdown("[doc](https://example.com \"my tooltip\")");
ok("title attribute preserved", strpos($out, "title='my tooltip'") !== false, $out);

$out = ca_render_markdown("![pic](https://example.com/img.png \"caption\")");
ok("img with title preserved", strpos($out, "<img") !== false && strpos($out, "title='caption'") !== false, $out);

$out = ca_render_markdown("**bold** and *italic* and `code`");
ok("plain markdown formatting works",
   strpos($out, "<strong>bold</strong>") !== false &&
   strpos($out, "<em>italic</em>") !== false &&
   strpos($out, "<code>code</code>") !== false,
   $out);

$out = ca_render_markdown("# Heading\n\nText here.");
ok("heading rendered", strpos($out, "<h1>") !== false, $out);

$out = ca_render_markdown("- one\n- two\n- three");
ok("list rendered", strpos($out, "<ul>") !== false && substr_count($out, "<li>") === 3, $out);

echo "\n=== Relative / scheme-less / loopback URLs ===\n";

foreach ([
	["[click](/Main/Dashboard)",                "absolute internal path"],
	["[click](javascript:alert(1))",            "javascript: scheme"],
	["[click](http://localhost/admin)",         "localhost"],
	["[click](http://192.168.1.1/x)",           "private LAN IPv4"],
	["[click](http://10.0.0.5/x)",              "10/8 LAN"],
	["[click](http://[::1]/x)",                 "IPv6 loopback"],
	["[click](http://[fc00::1]/x)",             "IPv6 ULA"],
	["[click](https://2130706433/x)",           "decimal-encoded loopback"],
	["[click](https://0x7f000001/x)",           "hex-encoded loopback"],
] as [$src, $note]) {
	$out = ca_render_markdown($src);
	ok("link stripped ({$note}): {$src}",
	   strpos($out, "<a") === false && strpos($out, "click") !== false,
	   $out);
}

echo "\n=== Image gating ===\n";

$out = ca_render_markdown("![pic](/local/img.png)");
ok("relative img removed entirely", strpos($out, "<img") === false, $out);

$out = ca_render_markdown("![pic](http://127.0.0.1/img.png)");
ok("loopback img removed", strpos($out, "<img") === false, $out);

echo "\n=== Raw HTML stripped before markdown ===\n";

$out = ca_render_markdown("Some <script>alert('xss')</script> text");
ok("raw <script> removed", strpos($out, "script") === false, $out);

$out = ca_render_markdown("<a href='javascript:alert(1)' onclick='alert(2)'>Click</a>");
ok("raw <a> with onclick removed", strpos($out, "javascript") === false && strpos($out, "onclick") === false, $out);

$out = ca_render_markdown("<img src=x onerror=alert(1)>");
ok("raw <img onerror> removed", strpos($out, "onerror") === false && strpos($out, "alert") === false, $out);

echo "\n=== Title-attribute injection attempts (DOM-verified) ===\n";

$out = ca_render_markdown('[a](https://example.com "x onclick=alert(1) y")');
$anchors = ca_anchor_attrs($out);
ok("'onclick' in title text doesn't become a real onclick attr",
   count($anchors) === 1 && !isset($anchors[0]["onclick"]),
   json_encode($anchors[0] ?? []));

$out = ca_render_markdown("[a](https://example.com \"x' onclick=alert(1) y='\")");
$anchors = ca_anchor_attrs($out);
ok("title quote-break attempt sealed (no onclick attr)",
   count($anchors) === 1 && !isset($anchors[0]["onclick"]),
   json_encode($anchors[0] ?? []));

$out = ca_render_markdown('[a](https://example.com" onclick="alert(1))');
$anchors = ca_anchor_attrs($out);
/* Markdown won't parse the malformed bracket+url as a link, so the text falls
   through as a <p>. The literal " onclick=" substring may appear in the
   paragraph's text content, but never as an actual <a> attribute. Verify via
   DOM, not substring. */
ok("URL-side injection attempt blocked (no <a> emitted)", count($anchors) === 0, $out);

suite_done();
