<?php
/* Tests for fixDescription() — converts the legacy [tag] markup that lives in
   template Description fields into safe HTML. Recently de-greedied to stop
   <span>...<span>... patterns from collapsing across the whole description. */

require_once __DIR__ . "/lib.php";
require_once "/usr/local/emhttp/plugins/community.applications/include/helpers.php";

echo "=== fixDescription ===\n";

// Non-string input
eq("null input → empty string",   "", fixDescription(null));
eq("array input → empty string",  "", fixDescription(["x"]));
eq("integer input → empty string","", fixDescription(123));

// Plain text passes through trimmed
eq("plain text trimmed",          "hello world", fixDescription("  hello world  "));

// [br] / [BR] become <br>, then strip_tags keeps the actual <br>... wait,
// strip_tags WITHOUT a whitelist removes <br> too. So the final output has
// the <br>'s removed. Document this: fixDescription is meant to extract the
// *text* of a description, not preserve formatting tags.
$out = fixDescription("Line one[br]Line two");
ok("[br] handled (does not leave literal '[br]')",  strpos($out, "[br]") === false, $out);

// [b] / [/b] — the regex maps [b] specifically, but strip_tags removes the
// resulting <b> too. Just verify the bracket form doesn't leak through as text.
$out = fixDescription("This is [b]important[/b] really");
ok("[b]/[/b] handled",  strpos($out, "[b]") === false && strpos($out, "[/b]") === false, $out);

// Generic [tag] becomes <tag> then gets strip_tagged out.
$out = fixDescription("Click [a href=https://example.com]here[/a] for info");
ok("inline anchor brackets removed entirely (text remains)",
   strpos($out, "here") !== false && strpos($out, "[a") === false && strpos($out, "<a") === false,
   $out);

// Span tags removed
$out = fixDescription("Some <span class='foo'>highlighted</span> text");
eq("span tags scrubbed, text remains",
   "Some highlighted text",
   $out);

// The de-greedying fix: multiple <span>...<span> shouldn't collapse the entire
// description into nothing.
$out = fixDescription("First <span>A</span> middle <span>B</span> end");
ok("multiple span pairs each scrubbed independently (de-greedied)",
   strpos($out, "First")  !== false &&
   strpos($out, "middle") !== false &&
   strpos($out, "end")    !== false &&
   strpos($out, "<span")  === false &&
   strpos($out, "</span>") === false,
   $out);

// HTML entity decoding in the bracket-form
$out = fixDescription("a &lt;b&gt; c");
ok("&lt;/&gt; entities normalize to literal text and get scrubbed",
   /* strtr converts to <,> then strip_tags strips the resulting tag */
   strpos($out, "a") !== false && strpos($out, "c") !== false,
   $out);

// Pure raw HTML: tags get stripped but the function does NOT remove the
// content between tags (it's a tag scrubber, not a sanitizer; the README/
// changelog pipeline is the place that strips content between tags). The
// description ends up as plain text — safe because there are no tags left.
$out = fixDescription("<script>alert(1)</script>after");
ok("raw <script> tags removed (content remains as plain text — no tags survive)",
   strpos($out, "<script") === false &&
   strpos($out, "</script>") === false &&
   strpos($out, "after") !== false,
   $out);

// Empty string
eq("empty string → empty string",  "", fixDescription(""));

// Whitespace-only
eq("whitespace-only → empty",      "", fixDescription("   \t\n  "));

suite_done();
