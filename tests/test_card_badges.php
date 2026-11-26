<?php
/* Focused regression coverage for the status labels shared by cards and the
   application sidebar. skin_helpers.php only defines functions at load time,
   so a minimal translation stub is sufficient for these pure badge tests. */

function tr($text) {
	return $text;
}

require_once dirname(__DIR__) . "/source/community.applications/usr/local/emhttp/plugins/community.applications/skins/Narrow/skin_helpers.php";

function badgeAssertContains(string $needle, array $badges, string $message): void {
	if (strpos(implode("", $badges), $needle) === false) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function badgeAssertNotContains(string $needle, array $badges, string $message): void {
	if (strpos(implode("", $badges), $needle) !== false) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

$update = caCollectBadges(['UpdateAvailable' => true]);
badgeAssertContains('UPDATE AVAILABLE', $update, 'pending updates use an action-oriented label');
badgeAssertNotContains('>UPDATED<', $update, 'pending updates are not described as already updated');

$unraidOfficial = caCollectBadges(['LTOfficial' => true, 'Official' => true]);
badgeAssertContains('UNRAID OFFICIAL', $unraidOfficial, 'Unraid-maintained apps identify the authority');
badgeAssertNotContains('DOCKER OFFICIAL', $unraidOfficial, 'Unraid official supersedes upstream official');

$dockerOfficial = caCollectBadges(['Official' => true]);
badgeAssertContains('DOCKER OFFICIAL', $dockerOfficial, 'upstream Docker images identify the authority');

$stopped = caCollectBadges(['Installed' => true, 'Running' => false]);
badgeAssertContains('stoppedCardBackground', $stopped, 'stopped containers have a distinct visual state');
badgeAssertContains('STOPPED', $stopped, 'stopped containers expose their runtime state');

echo "card badge assertions passed\n";
