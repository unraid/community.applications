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
 * Minimal read-only endpoint: returns the last few lines of the system PHP
 * error log as JSON, e.g. {"log":"...last two lines..."}.
 *
 * Deliberately standalone from exec.php. The "Unfortunately something went
 * wrong" reload banner fires precisely when a POST to exec.php has FAILED (a PHP
 * fatal somewhere in exec.php's include stack, or a stale csrf_token after the
 * server was reset). Routing the log tail back through exec.php would hit the
 * very thing that just broke, so this file loads none of the CA / Unraid stack
 * and is fetched over GET, which carries no csrf_token and so sidesteps the
 * stale-token failure mode. It stays behind emhttp's session auth by virtue of
 * living under /plugins.
 */

ini_set('display_errors', 'Off');
header('Content-Type: application/json');

# Mirrors CA_PATHS['PHPErrorLog']. Hardcoded so this stays dependency-free:
# paths.php only resolves after the full exec.php bootstrap sets its globals.
$logFile = "/var/log/phplog";

# How many trailing lines to return. Clamped so a wedged log can never dump more
# than a couple of lines into the banner.
$lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 2;
if ($lines < 1) $lines = 1;
if ($lines > 10) $lines = 10;

/**
 * Read the last $wanted lines of $file without slurping the whole thing: seek to
 * the end and walk backwards a chunk at a time until we have one more newline
 * than requested (so the oldest returned line is guaranteed complete).
 */
function caTailFile($file, $wanted) {
	if ( ! is_readable($file) ) return "";
	$f = @fopen($file, "rb");
	if ( ! $f ) return "";
	$stat = fstat($f);
	$pos = $stat['size'];
	$buffer = "";
	$chunk = 4096;
	while ( $pos > 0 && substr_count($buffer, "\n") <= $wanted ) {
		$read = ($pos >= $chunk) ? $chunk : $pos;
		$pos -= $read;
		fseek($f, $pos);
		$buffer = fread($f, $read) . $buffer;
	}
	fclose($f);
	$allLines = explode("\n", rtrim($buffer, "\n"));
	return implode("\n", array_slice($allLines, -$wanted));
}

echo json_encode(['log' => caTailFile($logFile, $lines)]);
?>
