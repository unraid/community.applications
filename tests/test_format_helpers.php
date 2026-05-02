<?php
/* Tests for the small formatting helpers in include/helpers.php:
   getDownloads, languageAuthorList, categoryList, plain, var_dump_ret,
   arrayEntriesToObject.

   These don't need the full $GLOBALS fixture; tr() is stubbed in lib.php to
   return its input verbatim, which is enough for assertions. */

require_once __DIR__ . "/lib.php";
require_once "/usr/local/emhttp/plugins/community.applications/include/helpers.php";

echo "=== getDownloads (rounds to nearest declared bracket) ===\n";

ok("15M downloads → 'More than 10,000,000'",
   strpos(getDownloads(15_000_000), "10,000,000") !== false);

ok("1.5M downloads → 'More than 1,000,000'",
   strpos(getDownloads(1_500_000), "1,000,000") !== false);

ok("5500 downloads → 'More than 5,000'",
   strpos(getDownloads(5500), "5,000") !== false);

ok("125 downloads → 'More than 100'",
   strpos(getDownloads(125), "100") !== false);

eq("low count without flag → empty string",   "",   getDownloads(50));
eq("low count with lowFlag → returns count",  50,   getDownloads(50, true));
eq("zero downloads, no flag → empty",         "",   getDownloads(0));
eq("zero downloads, with flag → 0",           0,    getDownloads(0, true));

echo "\n=== languageAuthorList (3 or fewer kept verbatim, 4+ truncated) ===\n";

eq("single author kept verbatim",
   "John Doe",
   languageAuthorList("John Doe"));

eq("three authors kept verbatim (count <= 3)",
   "Author1, Author2, Author3",
   languageAuthorList("Author1, Author2, Author3"));

$out = languageAuthorList("A1, A2, A3, A4, A5");
ok("five authors → first two + 'and 3 more' suffix",
   strpos($out, "A1") !== false &&
   strpos($out, "A2") !== false &&
   strpos($out, "more") !== false &&
   strpos($out, "A4") === false,
   $out);

echo "\n=== categoryList (≤ 2 categories shown by default + 'and N more') ===\n";

eq("single category passes through",
   "Media",
   categoryList("Media"));

$out = categoryList("Media,Server,Docker,Network");
ok("4 categories → first 2 + 'and 2 more' suffix (popUp=false)",
   strpos($out, "Media")  !== false &&
   strpos($out, "Server") !== false &&
   strpos($out, "more")   !== false &&
   strpos($out, "Docker") === false,
   $out);

$out = categoryList("Media,Server,Docker,Network", true);
ok("popUp=true shows ALL categories",
   strpos($out, "Media")   !== false &&
   strpos($out, "Server")  !== false &&
   strpos($out, "Docker")  !== false &&
   strpos($out, "Network") !== false &&
   strpos($out, "more")    === false,
   $out);

$out = categoryList("Media:Server: Docker");
ok("colon/space separator forms normalized to comma",
   strpos($out, "Media")  !== false &&
   strpos($out, "Server") !== false,
   $out);

echo "\n=== plain (strips brackets from IPv6-literal form) ===\n";

eq("IPv6 brackets removed",  "::1",          plain("[::1]"));
eq("IPv4 unchanged",         "192.168.1.1",  plain("192.168.1.1"));
eq("bracketed IPv4 unwrapped","192.168.1.1", plain("[192.168.1.1]"));
eq("empty input",            "",             plain(""));

echo "\n=== var_dump_ret (captures var_dump into a string) ===\n";

ok("dumping array returns a string",  is_string(var_dump_ret(["a", "b"])));
ok("dumping array string contains element", strpos(var_dump_ret(["foo" => "bar"]), "foo") !== false);
ok("dumping null returns a string mentioning NULL",
   strpos(strtoupper(var_dump_ret(null)), "NULL") !== false);
ok("default arg (no input) returns a string",   is_string(var_dump_ret()));

echo "\n=== arrayEntriesToObject (array_fill_keys helper) ===\n";

eq("flips values to keys with default flag = true",
   ["foo" => true, "bar" => true, "baz" => true],
   arrayEntriesToObject(["foo", "bar", "baz"]));

eq("flips with custom default value",
   ["foo" => false, "bar" => false],
   arrayEntriesToObject(["foo", "bar"], false));

eq("flips with arbitrary default",
   ["a" => 42, "b" => 42],
   arrayEntriesToObject(["a", "b"], 42));

eq("non-array input returns []",
   [],
   arrayEntriesToObject("not an array"));

eq("null input returns []",
   [],
   arrayEntriesToObject(null));

eq("empty array input returns empty array",
   [],
   arrayEntriesToObject([]));

suite_done();
