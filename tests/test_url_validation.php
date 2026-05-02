<?php
/* Tests for the URL validation surface in include/helpers.php:
     - validURL($url)               — used by template/feed-data emit sites
     - caIsPrivateOrLoopbackHost($host)
     - caIsPublicHttpUrl($url)      — stricter variant used in README/changelog sanitizers

   Run on the server (where the live plugin lives):
     php /mnt/user/GitHub/community.applications/tests/test_url_validation.php
*/

require_once __DIR__ . "/lib.php";
require_once "/usr/local/emhttp/plugins/community.applications/include/helpers.php";

echo "=== validURL ===\n";

// Non-URL / scheme rejection
foreach (["", "/Main", "/Main/Dashboard", "Settings/Tailscale", "javascript:alert(1)",
          "data:text/html,<script>", "file:///etc/passwd", "ftp://example.com",
          "ssh://user@host", "mailto:x@y.com", "tel:+15555555555", "vbscript:msgbox(1)",
          "//example.com/path"] as $u) {
	ok("validURL rejects: '{$u}'", !validURL($u));
}

// Loopback / private — validURL also rejects these (it shares the same loopback-host check)
foreach (["http://localhost", "https://127.0.0.1", "http://[::1]/x", "http://0.0.0.0",
          "http://2130706433", "http://0x7f000001"] as $u) {
	ok("validURL rejects loopback: '{$u}'", !validURL($u));
}

// Real public URLs
foreach (["http://example.com", "https://github.com/user/repo",
          "https://docs.example.com/path?q=1#frag", "http://8.8.8.8/"] as $u) {
	ok("validURL accepts: '{$u}'", (bool)validURL($u));
}

// Case-insensitive scheme
ok("validURL accepts uppercase HTTPS://", (bool)validURL("HTTPS://example.com"));

echo "\n=== caIsPrivateOrLoopbackHost — IPv4 private/loopback ===\n";
$private_v4 = [
	"localhost", "127.0.0.1", "127.255.255.254",
	"10.0.0.1", "10.255.255.255",
	"172.16.0.1", "172.31.255.255",
	"192.168.1.1", "169.254.1.1",
	"0.0.0.0", "0",
];
foreach ($private_v4 as $h) ok("private IPv4: {$h}", caIsPrivateOrLoopbackHost($h));

echo "\n=== caIsPrivateOrLoopbackHost — IPv6 private/loopback ===\n";
$private_v6 = [
	"::1", "[::1]", "::", "fc00::1", "fd00::1", "fe80::1",
	"::ffff:127.0.0.1", "::ffff:10.0.0.1", "::ffff:192.168.1.1",
];
foreach ($private_v6 as $h) ok("private IPv6: {$h}", caIsPrivateOrLoopbackHost($h));

echo "\n=== caIsPrivateOrLoopbackHost — encoded IPv4 bypass forms ===\n";
foreach (["2130706433" => "decimal 127.0.0.1",
          "0x7f000001" => "hex 127.0.0.1",
          "0xc0a80101" => "hex 192.168.1.1"] as $h => $note) {
	ok("private encoded ({$note}): {$h}", caIsPrivateOrLoopbackHost($h));
}

echo "\n=== caIsPrivateOrLoopbackHost — public hosts (must NOT be flagged) ===\n";
$public_hosts = [
	"example.com", "github.com",
	"8.8.8.8", "1.1.1.1",
	"172.15.0.1", "172.32.0.1",       // edges of 172.16-31 range
	"192.167.1.1", "192.169.1.1",     // edges of 192.168
	"169.253.1.1", "169.255.1.1",     // edges of 169.254
	"11.0.0.1",                        // adjacent to 10.x
	"126.0.0.1", "128.0.0.1",         // edges of 127.x
	"2001:4860:4860::8888",            // public IPv6 (Google DNS)
];
foreach ($public_hosts as $h) ok("public host: {$h}", !caIsPrivateOrLoopbackHost($h));

echo "\n=== caIsPublicHttpUrl ===\n";

// Schemes other than http(s)
foreach (["", "/Main/Dashboard", "javascript:alert(1)", "data:text/html,<script>",
          "file:///etc/passwd", "ftp://example.com", "mailto:x@y.com",
          "ssh://user@host/cmd", "vnc://host:5900", "rdp://internal-server",
          "smb://fileserver/share"] as $u) {
	ok("rejects non-http(s): '{$u}'", !caIsPublicHttpUrl($u));
}

// Loopback / private
foreach (["http://localhost", "https://127.0.0.1", "http://192.168.1.1/",
          "http://10.0.0.1", "http://[::1]/", "http://[fe80::1]/",
          "http://[fc00::1]/", "http://2130706433/", "http://0x7f000001/"] as $u) {
	ok("rejects private/loopback: '{$u}'", !caIsPublicHttpUrl($u));
}

// Real public URLs
foreach (["http://example.com", "https://github.com/user/repo",
          "https://docs.example.com/path?q=1#frag",
          "https://[2001:4860:4860::8888]/", "http://8.8.8.8/"] as $u) {
	ok("accepts public: '{$u}'", caIsPublicHttpUrl($u));
}

// Case-insensitivity
ok("accepts uppercase HTTPS://", caIsPublicHttpUrl("HTTPS://example.com"));

suite_done();
