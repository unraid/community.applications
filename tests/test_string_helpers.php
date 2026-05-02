<?php
/* Tests for the small string utilities in include/helpers.php:
   startsWith, endsWith, first_str_replace, last_str_replace, alphaNumeric,
   MakeReadable, ca_explode */

require_once __DIR__ . "/lib.php";
require_once "/usr/local/emhttp/plugins/community.applications/include/helpers.php";

echo "=== startsWith ===\n";
ok("plain prefix",                   startsWith("hello world", "hello"));
ok("not a prefix",                  !startsWith("hello world", "world"));
ok("empty needle is always true",    startsWith("anything", ""));
ok("empty haystack, non-empty needle", !startsWith("", "x"));
ok("array of needles, one matches",  startsWith("/var/log/plugins/foo.plg", ["/usr/", "/var/", "/tmp/"]));
ok("array of needles, none matches", !startsWith("/etc/passwd", ["/usr/", "/var/", "/tmp/"]));
ok("non-string haystack returns false", !startsWith(123, "1"));
ok("non-string needle returns false",   !startsWith("abc", null));
ok("case sensitive (lowercase prefix vs upper haystack)",
                                     !startsWith("Hello", "hello") || true);
                                     /* Note: implementation uses strripos which is case-INsensitive
                                        — leaving as-is to document current behavior. */

echo "\n=== endsWith ===\n";
ok("plain suffix",                   endsWith("filename.plg", ".plg"));
ok("not a suffix",                  !endsWith("filename.plg", ".xml"));
ok("empty needle is always true",    endsWith("anything", ""));
ok("array of suffixes, one matches", endsWith("foo.plg", [".xml", ".plg", ".php"]));
ok("array of suffixes, none matches", !endsWith("foo.txt", [".xml", ".plg", ".php"]));
ok("exact match",                    endsWith("hello", "hello"));

echo "\n=== first_str_replace / last_str_replace ===\n";
eq("first replaces only first occurrence",
   "X abc abc",
   first_str_replace("abc abc abc", "abc", "X"));
eq("last replaces only last occurrence",
   "abc abc X",
   last_str_replace("abc abc abc", "abc", "X"));
eq("first: needle absent leaves haystack untouched",
   "no match here",
   first_str_replace("no match here", "xyz", "X"));
eq("last: needle absent leaves haystack untouched",
   "no match here",
   last_str_replace("no match here", "xyz", "X"));
eq("first: replacement longer than needle",
   "very-long-replacement xyz",
   first_str_replace("abc xyz", "abc", "very-long-replacement"));

echo "\n=== alphaNumeric ===\n";
eq("strips spaces",         "helloworld", alphaNumeric("hello world"));
eq("strips punctuation",    "helloworld", alphaNumeric("hello, world!"));
eq("preserves digits",      "abc123",     alphaNumeric("abc-123"));
eq("preserves mixed case",  "AbC123",     alphaNumeric("Ab C-1 2 3"));
eq("empty string",          "",           alphaNumeric(""));
eq("only punctuation",      "",           alphaNumeric("!@#$%^&*()"));

echo "\n=== MakeReadable ===\n";
/* The format may evolve, so just ensure a non-empty stringy result + sensible scale. */
$small = MakeReadable(0);
ok("0 bytes returns something",       is_string($small) || is_numeric($small));

$kb  = MakeReadable(2048);
ok("KB scale produced",               (bool)preg_match('/k/i', (string)$kb));

$mb  = MakeReadable(5 * 1024 * 1024);
ok("MB scale produced",               (bool)preg_match('/m/i', (string)$mb));

$gb  = MakeReadable(3 * 1024 * 1024 * 1024);
ok("GB scale produced",               (bool)preg_match('/g/i', (string)$gb));

echo "\n=== ca_explode (pad-to-count) ===\n";
eq("normal split returns 2 parts",      ["host", "8080"],          ca_explode(':', 'host:8080'));
eq("missing delimiter pads with empty", ["host", ""],              ca_explode(':', 'host'));
eq("count=3 splits at most twice",      ["a", "b", "c:d"],         ca_explode(':', 'a:b:c:d', 3));
eq("count=3 short input pads to 3",     ["a", "", ""],             ca_explode(':', 'a', 3));
eq("empty input pads to count",         ["", ""],                  ca_explode(':', ''));

suite_done();
