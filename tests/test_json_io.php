<?php
/* Tests for readJsonFile() / ca_file_put_contents() — the on-disk persistence
   helpers used throughout the plugin (cache files, settings, etc.). Uses
   tempnam() so the tests don't touch any real CA paths.

   readJsonFile auto-detects format: it tries unserialize() first, falls back
   to json_decode(). writeJsonFile prefers serialize() when humanReadable is
   off (the production default).

   We only exercise readJsonFile + ca_file_put_contents directly — writeJsonFile
   has a side effect of writing a sibling templates.json when the filename
   matches CA_PATHS['community-templates-info'], which we don't want to
   trigger in tests. */

require_once __DIR__ . "/lib.php";
require_once "/usr/local/emhttp/plugins/community.applications/include/helpers.php";

/* readJsonFile() and ca_file_put_contents() both rely on $GLOBALS['action']
   for debug() — give it a placeholder so debug() doesn't spam stdout. */
$GLOBALS['action'] = 'unit_test';

echo "=== readJsonFile ===\n";

// Missing file → default
$missing = "/tmp/__ca_test_does_not_exist_" . uniqid() . ".dat";
@unlink($missing);
eq("missing file returns default ([])",       [], readJsonFile($missing));
eq("missing file returns custom default",     ["x" => 1], readJsonFile($missing, ["x" => 1]));

// Serialized payload (production default)
$path_ser = tempnam(sys_get_temp_dir(), "ca_ser_");
file_put_contents($path_ser, serialize(["foo" => 1, "bar" => [2, 3]]));
eq("reads serialized payload",
   ["foo" => 1, "bar" => [2, 3]],
   readJsonFile($path_ser));
@unlink($path_ser);

// JSON payload (humanReadable mode)
$path_json = tempnam(sys_get_temp_dir(), "ca_json_");
file_put_contents($path_json, json_encode(["a" => "b", "c" => [1, 2, 3]]));
eq("reads JSON payload",
   ["a" => "b", "c" => [1, 2, 3]],
   readJsonFile($path_json));
@unlink($path_json);

// Garbage → default (graceful failure)
$path_garbage = tempnam(sys_get_temp_dir(), "ca_garbage_");
file_put_contents($path_garbage, "this is not json or serialized");
eq("garbage payload returns default",
   [],
   readJsonFile($path_garbage));
eq("garbage payload returns custom default",
   ["fallback" => true],
   readJsonFile($path_garbage, ["fallback" => true]));
@unlink($path_garbage);

// Empty file → default
$path_empty = tempnam(sys_get_temp_dir(), "ca_empty_");
file_put_contents($path_empty, "");
eq("empty file returns default", [], readJsonFile($path_empty));
@unlink($path_empty);

// JSON null specifically — readJsonFile treats null as "couldn't read", returns default
$path_null = tempnam(sys_get_temp_dir(), "ca_null_");
file_put_contents($path_null, "null");
eq("JSON null returns default ([] in this run)", [], readJsonFile($path_null));
@unlink($path_null);

echo "\n=== ca_file_put_contents (atomic write via .~ rename) ===\n";

// Round-trip: write serialized then read back via readJsonFile
$path_rt = tempnam(sys_get_temp_dir(), "ca_rt_");
$payload = ["roundtrip" => true, "list" => [1, 2, 3], "nested" => ["k" => "v"]];
ca_file_put_contents($path_rt, serialize($payload));
eq("write+read serialized round-trip", $payload, readJsonFile($path_rt));
@unlink($path_rt);

// Round-trip: write JSON
$path_rt2 = tempnam(sys_get_temp_dir(), "ca_rt2_");
ca_file_put_contents($path_rt2, json_encode($payload));
eq("write+read JSON round-trip", $payload, readJsonFile($path_rt2));
@unlink($path_rt2);

// ca_file_put_contents writes to filename + "~" then renames. Verify the temp
// is gone after a successful write.
$path_atomic = tempnam(sys_get_temp_dir(), "ca_atomic_");
ca_file_put_contents($path_atomic, "hello");
ok("post-write: temp .~ file is gone",  !is_file($path_atomic . "~"));
ok("post-write: target file exists",    is_file($path_atomic));
eq("contents written correctly",        "hello", file_get_contents($path_atomic));
@unlink($path_atomic);

suite_done();
