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
 * Single source of truth for the log file paths used by the standalone
 * errorlog.php / downloadlog.php endpoints, so the two never drift apart.
 *
 * These mirror the matching CA_PATHS entries but are defined here, NOT pulled
 * from paths.php: paths.php only resolves after the full exec.php bootstrap, and
 * the whole point of those endpoints is to keep working when exec.php is broken.
 * Keep these values in sync with paths.php by hand.
 */

$CA_LOG_PATHS = [
	'phpError' => "/var/log/phplog",         // CA_PATHS['PHPErrorLog']
	'caInfo'   => "/tmp/CA_logs/ca.txt",      // CA_PATHS['caInfo']
	'caLog'    => "/tmp/CA_logs/ca_log.txt",  // CA_PATHS['logging']
];
?>
