#!/usr/bin/php -q
<?PHP
/* Live stats publisher for a single docker container, plus host counters.
 *
 * Invocation: caLiveStats.php <containerName>
 *
 * Model: one publisher process per container being watched. When the CA
 * sidebar opens on running container "plex", exec.php's
 * startLiveStatsPublisher action spawns this script with "plex" as argv[1].
 * It then publishes a JSON snapshot once a second to nchan channel
 * stats_plex; the sidebar's NchanSubscriber is listening on /sub/stats_plex.
 *
 * Two sidebars open on the same container -> still one publisher process
 * (pgrep dedupes the spawn by script-path + container-arg). Two sidebars
 * open on different containers -> two lightweight publishers, each doing
 * one Docker socket call per tick. This trades a small per-publisher
 * fixed cost (one process, one publish curl per second) for an O(1)
 * per-tick Docker API cost regardless of how many containers exist on
 * the host — which matters because the Docker stats endpoint is
 * per-container and there's no bulk variant.
 *
 * Lifecycle: publish() with abort=true / abortTime=10 self-terminates the
 * process ~10 seconds after the last subscriber disconnects, calling
 * removeNChanScript() to clean up /var/run/nchan.pid. A new sidebar that
 * arrives during the grace window finds the channel still being published
 * to and just attaches; if it arrives after, exec.php's spawn endpoint
 * relaunches the script.
 *
 * Payload shape is the FLAT legacy single-container result (cpu, memPerc,
 * memMb, ...) so the JS can hand the parsed message straight to
 * processSnapshot without any reshaping.
 */

$docroot = '/usr/local/emhttp';
require_once "$docroot/plugins/dynamix/include/publish.php";
require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php";

/* argv[1] is the container name. Validate it against Docker's own naming
   rules before doing anything else — this string lands in a channel name
   and gets passed straight to the Docker API, so a malformed value must
   not propagate. */
$containerName = $argv[1] ?? '';
if ($containerName === '' || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,254}$/', $containerName)) {
	fwrite(STDERR, "caLiveStats: invalid container name argument\n");
	exit(1);
}

$endpoint  = 'stats_' . $containerName;
$abortTime = 10;

/* Per-channel pidfile lock — belt-and-suspenders deduplication on top of
   the pgrep check in exec.php's startLiveStatsPublisher. Two near-
   simultaneous spawn requests can both clear pgrep before either's PID
   shows up in /proc; whichever loses the race here exits silently while
   the winner keeps publishing. A stale pidfile from a hard-killed prior
   instance is recovered automatically (posix_kill with signal 0 doesn't
   actually send a signal, just probes whether the PID is alive). */
$pidfile = "/var/run/caLiveStats_{$containerName}.pid";
if (is_file($pidfile)) {
	$existingPid = (int)trim((string)@file_get_contents($pidfile));
	if ($existingPid > 0 && posix_kill($existingPid, 0)) {
		exit(0);
	}
	/* Stale pidfile — previous publisher died without cleanup. Fall
	   through and claim the endpoint. */
}
@file_put_contents($pidfile, getmypid() . "\n");
register_shutdown_function(function() use ($pidfile) {
	/* Only clear the pidfile if we still own it. If a brand-new
	   instance has already taken over (e.g. our exit raced with the
	   next spawn), removing its pidfile would let yet another
	   instance start. */
	$owner = (int)trim((string)@file_get_contents($pidfile));
	if ($owner === getmypid()) @unlink($pidfile);
});

$DockerClient = new DockerClient();

function caReadHostStats() {
	$out = [
		'cpuBusy'    => 0,
		'cpuTotal'   => 0,
		'loadAvg'    => [0.0, 0.0, 0.0],
		'memUsedMb'  => 0.0,
		'memTotalMb' => 0.0,
		'netRxBytes' => 0,
		'netTxBytes' => 0,
		'netMaxBps'  => 0,
	];

	$procStat = @file_get_contents('/proc/stat');
	if (is_string($procStat)) {
		$line  = strtok($procStat, "\n");
		$parts = preg_split('/\s+/', trim((string)$line));
		if (is_array($parts) && count($parts) >= 5 && $parts[0] === 'cpu') {
			$user   = (float)($parts[1] ?? 0);
			$nice   = (float)($parts[2] ?? 0);
			$system = (float)($parts[3] ?? 0);
			$idle   = (float)($parts[4] ?? 0);
			$iowait = (float)($parts[5] ?? 0);
			$irq    = (float)($parts[6] ?? 0);
			$softirq= (float)($parts[7] ?? 0);
			$steal  = (float)($parts[8] ?? 0);
			$out['cpuTotal'] = (int)($user + $nice + $system + $idle + $iowait + $irq + $softirq + $steal);
			$out['cpuBusy']  = (int)($out['cpuTotal'] - $idle - $iowait);
		}
	}

	/* /proc/loadavg: "1m 5m 15m running/total last_pid". Cheap to read,
	   updates roughly every 5s in the kernel. Shown under the host CPU
	   gauge as raw 1/5/15 minute load averages. */
	$loadavg = @file_get_contents('/proc/loadavg');
	if (is_string($loadavg)) {
		$parts = preg_split('/\s+/', trim($loadavg));
		if (is_array($parts) && count($parts) >= 3) {
			$out['loadAvg'] = [(float)$parts[0], (float)$parts[1], (float)$parts[2]];
		}
	}

	$meminfo = @file_get_contents('/proc/meminfo');
	if (is_string($meminfo)) {
		if (preg_match('/^MemTotal:\s+(\d+)/m', $meminfo, $m))     $out['memTotalMb'] = round((float)$m[1] / 1024.0, 1);
		if (preg_match('/^MemAvailable:\s+(\d+)/m', $meminfo, $m)) $out['memUsedMb']  = round(max(0.0, $out['memTotalMb'] - ((float)$m[1] / 1024.0)), 1);
	}

	$netdev = @file_get_contents('/proc/net/dev');
	if (is_string($netdev)) {
		foreach (explode("\n", $netdev) as $line) {
			if (strpos($line, ':') === false) continue;
			[$iface, $rest] = explode(':', $line, 2);
			$iface = trim($iface);
			if ($iface === '' || $iface === 'lo') continue;
			$parts = preg_split('/\s+/', trim($rest));
			if (!is_array($parts) || count($parts) < 9) continue;
			$out['netRxBytes'] += (int)$parts[0];
			$out['netTxBytes'] += (int)$parts[8];
		}
	}

	$speedFiles = glob('/sys/class/net/*/speed');
	if (is_array($speedFiles)) {
		foreach ($speedFiles as $speedPath) {
			$ifaceDir = dirname($speedPath);
			$iface    = basename($ifaceDir);
			if ($iface === 'lo') continue;
			if (preg_match('/^(docker|veth|br-|virbr|tun|tap|wg|kube|cni|flannel|nerdctl)/', $iface)) continue;
			$carrier = trim((string)@file_get_contents($ifaceDir . '/carrier'));
			if ($carrier !== '1') continue;
			$mbps = (int)@file_get_contents($speedPath);
			if ($mbps <= 0) continue;
			$bps = $mbps * 125000;
			if ($bps > $out['netMaxBps']) $out['netMaxBps'] = $bps;
		}
	}

	return $out;
}

function caReadContainerStats(DockerClient $DockerClient, string $name) {
	$stats = $DockerClient->getDockerJSON("/containers/" . rawurlencode($name) . "/stats?stream=false");
	if (!is_array($stats) || empty($stats['cpu_stats'])) return null;

	$cpuDelta    = (float)(($stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0) - ($stats['precpu_stats']['cpu_usage']['total_usage'] ?? 0));
	$systemDelta = (float)(($stats['cpu_stats']['system_cpu_usage'] ?? 0) - ($stats['precpu_stats']['system_cpu_usage'] ?? 0));
	$onlineCpus  = (int)($stats['cpu_stats']['online_cpus'] ?? count($stats['cpu_stats']['cpu_usage']['percpu_usage'] ?? []));
	if ($onlineCpus < 1) $onlineCpus = 1;
	$cpu = ($systemDelta > 0 && $cpuDelta >= 0) ? ($cpuDelta / $systemDelta) * 100.0 : 0.0;

	$memUsage = (float)($stats['memory_stats']['usage'] ?? 0);
	$memCache = (float)($stats['memory_stats']['stats']['cache'] ?? 0);
	$memLimit = (float)($stats['memory_stats']['limit'] ?? 0);
	$memNet   = max(0.0, $memUsage - $memCache);
	$memPerc  = $memLimit > 0 ? ($memNet / $memLimit) * 100.0 : 0.0;

	$rxBytes = 0; $txBytes = 0;
	$networks = $stats['networks'] ?? [];
	if (is_array($networks)) {
		foreach ($networks as $iface) {
			$rxBytes += (float)($iface['rx_bytes'] ?? 0);
			$txBytes += (float)($iface['tx_bytes'] ?? 0);
		}
	}

	return [
		'cpu'        => round($cpu, 2),
		'memPerc'    => round($memPerc, 2),
		'memMb'      => round($memNet / 1048576, 1),
		'memLimitMb' => round($memLimit / 1048576, 1),
		'netRxBytes' => (int)$rxBytes,
		'netTxBytes' => (int)$txBytes,
		'cpus'       => $onlineCpus,
	];
}

while (true) {
	$start = microtime(true);

	$snapshot = caReadContainerStats($DockerClient, $containerName);

	if ($snapshot === null) {
		/* Container stopped or got removed. Publish one "not ok" sentinel
		   so any still-attached subscribers see the stopped state and tear
		   down, then exit — there's no point staying alive for a target
		   that doesn't exist. No removeNChanScript() needed: we never
		   registered in /var/run/nchan.pid in the first place (see the
		   comment in exec.php::startLiveStatsPublisher). */
		publish($endpoint, json_encode(['ok' => false, 'tsMs' => (int)(microtime(true) * 1000)]), 1, false);
		exit(0);
	}

	$host = caReadHostStats();

	/* Flat payload matching the legacy single-container result shape so
	   the JS handler can use it without reshaping. */
	$payload = array_merge($snapshot, [
		'ok'              => true,
		'tsMs'            => (int)(microtime(true) * 1000),
		'hostCpuBusy'     => $host['cpuBusy'],
		'hostCpuTotal'    => $host['cpuTotal'],
		'hostLoadAvg'     => $host['loadAvg'],
		'hostMemUsedMb'   => $host['memUsedMb'],
		'hostMemTotalMb'  => $host['memTotalMb'],
		'hostNetRxBytes'  => $host['netRxBytes'],
		'hostNetTxBytes'  => $host['netTxBytes'],
		'hostNetMaxBps'   => $host['netMaxBps'],
	]);

	publish($endpoint, json_encode($payload), 1, true, $abortTime);

	/* Account for everything that happened this tick — Docker socket
	   roundtrip, /proc reads, and the publish() curl into nchan — so the
	   total cycle stays ~1s wall-clock. If a tick exceeds a full second,
	   $sleepUs lands at 0 and the next iteration starts immediately. */
	$sleepUs = (int)((1.0 - (microtime(true) - $start)) * 1000000);
	if ($sleepUs > 0) usleep($sleepUs);
}
?>
