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
 * Minimal read-only endpoint: bundles Community Applications' logs into a zip
 * and streams it back as a download (Content-Disposition: attachment).
 *
 * Standalone from exec.php for the same reason as errorlog.php: this is wired to
 * the "Download logs" button on the fatal reload banner, which only appears
 * because a POST to exec.php just FAILED (a PHP fatal in its include stack, or a
 * stale csrf_token after a server reset). Routing the download back through
 * exec.php's downloadDebugging action would hit the very thing that broke, so
 * this loads none of the CA / Unraid stack and is fetched over GET (no
 * csrf_token). It stays behind emhttp's session auth by living under /plugins.
 *
 * Mirrors what exec.php's downloadDebugging() bundles, minus the fresh env
 * snapshot (that needs the full bootstrap) — whatever ca.txt already exists on
 * disk is included as-is; the always-current ca_log.txt and phplog are the
 * important parts.
 */

ini_set('display_errors', 'Off');

# Hardcoded so this stays dependency-free (paths.php only resolves after the full
# exec.php bootstrap). These mirror CA_PATHS['caInfo'], ['logging'], ['PHPErrorLog'].
$caInfo   = "/tmp/CA_logs/ca.txt";
$caLog    = "/tmp/CA_logs/ca_log.txt";
$phpErrorLog = "/var/log/phplog";

$stamp   = date("Ymd-Hi");
$zipName = "CA-Logging-$stamp.zip";

# Per-pid scratch so concurrent downloads never clobber each other. The temp dir
# gives phplog a friendly name inside the zip once `zip -j` junks the path.
$pid    = getmypid();
$tmpDir = "/tmp/ca-logdl-$pid";
$tmpZip = "/tmp/ca-logdl-$pid.zip";
@mkdir($tmpDir, 0777, true);
@unlink($tmpZip);

$files = array_filter([$caInfo, $caLog], 'is_file');

$phpDest = "$tmpDir/phplog.txt";
if ( @copy($phpErrorLog, $phpDest) ) {
	$files[] = $phpDest;
}

if ( $files ) {
	$args = implode(" ", array_map('escapeshellarg', $files));
	exec("zip -qlj ".escapeshellarg($tmpZip)." ".$args);
}

@unlink($phpDest);
@rmdir($tmpDir);

if ( ! is_file($tmpZip) || filesize($tmpZip) === 0 ) {
	http_response_code(500);
	header('Content-Type: application/json');
	echo json_encode(['error' => 'no logs available']);
	exit;
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="'.$zipName.'"');
header('Content-Length: '.filesize($tmpZip));
header('Cache-Control: no-store');
readfile($tmpZip);
@unlink($tmpZip);
