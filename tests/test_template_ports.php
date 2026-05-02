<?php
/* Tests for portsUsed() and adjustTemplatePorts() — both touch the
   $template['Config'] structure that templates use to declare host/container
   port mappings. */

require_once __DIR__ . "/lib.php";
require_once "/usr/local/emhttp/plugins/community.applications/include/helpers.php";

echo "=== portsUsed ===\n";

// Non-bridge / no Config → []
eq("no template → []",
   "[]",
   portsUsed(null));

eq("template without Config → []",
   "[]",
   portsUsed(["Network" => "bridge"]));

eq("non-bridge network → []",
   "[]",
   portsUsed(["Network" => "host", "Config" => [["@attributes" => ["Type" => "Port", "Default" => "8080"]]]]));

// Single-port (bridge)
$tpl = [
	"Network" => "bridge",
	"Config" => [
		["@attributes" => ["Type" => "Port", "Default" => "8080"]],
	],
];
eq("single port from Default", "[8080]", portsUsed($tpl));

// Multiple ports — Default values
$tpl = [
	"Network" => "bridge",
	"Config" => [
		["@attributes" => ["Type" => "Port", "Default" => "8080"]],
		["@attributes" => ["Type" => "Port", "Default" => "8443"]],
		["@attributes" => ["Type" => "Variable", "Default" => "X"]],   // ignored
		["@attributes" => ["Type" => "Path",     "Default" => "/foo"]], // ignored
		["@attributes" => ["Type" => "Port",     "Default" => "9000"]],
	],
];
eq("multiple Port entries, non-Port entries skipped",
   "[8080,8443,9000]",
   portsUsed($tpl));

// User override via "value" — wins over Default
$tpl = [
	"Network" => "bridge",
	"Config" => [
		["@attributes" => ["Type" => "Port", "Default" => "8080"], "value" => "32400"],
	],
];
eq("'value' overrides Default",
   "[32400]",
   portsUsed($tpl));

// Single Config (single port) presented as @attributes-on-the-Config-itself
$tpl = [
	"Network" => "bridge",
	"Config" => ["@attributes" => ["Type" => "Port", "Default" => "8080"]],
];
eq("Config with @attributes directly (single-port form)",
   "[8080]",
   portsUsed($tpl));

echo "\n=== adjustTemplatePorts ===\n";

// No Config → no-op
$tpl = ["Network" => "bridge"];
adjustTemplatePorts($tpl, [80, 443]);
ok("no Config → no-op (template unchanged)", $tpl === ["Network" => "bridge"]);

// Non-bridge → no-op even with conflicting ports
$tpl = [
	"Network" => "host",
	"Config" => [["@attributes" => ["Type" => "Port", "Default" => "8080"], "value" => "8080"]],
];
$snapshot = $tpl;
adjustTemplatePorts($tpl, [8080]);
ok("non-bridge → no-op", $tpl === $snapshot);

// Default port not in use → unchanged
$tpl = [
	"Network" => "bridge",
	"Config" => [["@attributes" => ["Type" => "Port", "Default" => "8080"], "value" => "8080"]],
];
adjustTemplatePorts($tpl, [80, 443]);
eq("port 8080 not taken → stays 8080",
   "8080",
   $tpl["Config"][0]["value"]);

// Default port already in use → bumped
$tpl = [
	"Network" => "bridge",
	"Config" => [["@attributes" => ["Type" => "Port", "Default" => "8080"], "value" => "8080"]],
];
adjustTemplatePorts($tpl, [8080]);
eq("port 8080 taken → bumped to 8081",
   "8081",
   $tpl["Config"][0]["value"]);

// Cluster of taken ports → finds first free
$tpl = [
	"Network" => "bridge",
	"Config" => [["@attributes" => ["Type" => "Port", "Default" => "8080"], "value" => "8080"]],
];
adjustTemplatePorts($tpl, [8080, 8081, 8082, 8083]);
eq("8080-8083 taken → bumped to 8084",
   "8084",
   $tpl["Config"][0]["value"]);

// Multiple ports in same template — second port shouldn't collide with the
// first port we already placed
$tpl = [
	"Network" => "bridge",
	"Config" => [
		["@attributes" => ["Type" => "Port", "Default" => "8080"], "value" => "8080"],
		["@attributes" => ["Type" => "Port", "Default" => "8081"], "value" => "8081"],
	],
];
adjustTemplatePorts($tpl, [8080]);
/* First port: 8080 taken → bumped to 8081. Second port: Default 8081, but
   we just used 8081 for the first one → bump to 8082. */
eq("first port bumped to 8081",  "8081", $tpl["Config"][0]["value"]);
eq("second port bumped past first's new value",
   "8082",
   $tpl["Config"][1]["value"]);

// Non-Port Config entries left untouched
$tpl = [
	"Network" => "bridge",
	"Config" => [
		["@attributes" => ["Type" => "Variable", "Target" => "TZ", "Default" => "UTC"], "value" => "UTC"],
		["@attributes" => ["Type" => "Port",     "Default" => "8080"], "value" => "8080"],
	],
];
adjustTemplatePorts($tpl, [8080]);
eq("Variable entry untouched",  "UTC",  $tpl["Config"][0]["value"]);
eq("Port entry bumped",         "8081", $tpl["Config"][1]["value"]);

// Out-of-range ports: ignored
$tpl = [
	"Network" => "bridge",
	"Config" => [
		["@attributes" => ["Type" => "Port", "Default" => "0"],     "value" => "0"],
		["@attributes" => ["Type" => "Port", "Default" => "70000"], "value" => "70000"],
	],
];
$snapshot = $tpl;
adjustTemplatePorts($tpl, [8080]);
ok("port 0 left untouched",        $tpl["Config"][0]["value"] === "0");
ok("port > 65535 left untouched",  $tpl["Config"][1]["value"] === "70000");

suite_done();
