<?php
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
 * Shared helper routines for Community Applications (PHP).
 *
 * Template/feed JSON I/O, dockerman and plugin utilities, search and display
 * logic, moderation, statistics, translations wrappers, and UI-oriented helpers
 * used by exec.php, skins, and CLI scripts.
 */

require_once __DIR__ . "/paths.php";

/**
 * Emit the hidden + checkbox <input> pair for a boolean settings toggle in the
 * Settings panel, driven entirely by the shipped default in default.cfg so that
 * changing a default can never desync the form (the bug class where a hardcoded
 * `?? "yes"` / `value='false'` drifted out of step with default.cfg).
 *
 * The default value tells us the vocabulary — true/false vs yes/no. The hidden
 * input carries the "off" value (posted when the box is unchecked); the checkbox
 * carries the "on" value; the box is checked when the live value (falling back
 * to the default when absent) equals the "on" value. So a setting whose default
 * is "yes"/"true" correctly renders checked.
 *
 * @param  string $name       Setting key — matches default.cfg and caSettings.
 * @param  array  $caDefaults Parsed default.cfg (Apps.page builds this).
 * @return string HTML for the two inputs.
 */
function caSettingSwitchInputs(string $name, array $caDefaults): string {
	$default = (string)($caDefaults[$name] ?? "no");
	$isBool  = ($default === "true" || $default === "false");
	$on      = $isBool ? "true"  : "yes";
	$off     = $isBool ? "false" : "no";
	$live    = (string)($GLOBALS['caSettings'][$name] ?? $default);
	$checked = ($live === $on) ? " checked" : "";
	// data-default-on lets the settings panel Default button restore each switch
	// to its default.cfg value client-side without another round trip.
	$defaultOn = ($default === $on) ? "1" : "0";
	$safeName = htmlspecialchars($name, ENT_QUOTES);
	return "<input type='hidden' name='{$safeName}' value='{$off}'>"
		. "<input type='checkbox' class='switch caSettingSwitch' name='{$safeName}' value='{$on}' data-default-on='{$defaultOn}'{$checked}>";
}

/**
 * Populate $GLOBALS['templates'] from the on-disk templates JSON when not already set.
 *
 * Also calls getSettings(). Clears the stat cache so file_exists() is fresh.
 *
 * @return void
 */
function getGlobals() {
	clearstatcache();
	if ( is_file(CA_PATHS['community-templates-info']) ) {
		if ( ! isset($GLOBALS['templates']) ) {
			$GLOBALS['templates'] = readJsonFile(CA_PATHS['community-templates-info']);
		}
	} else {
		$GLOBALS['templates'] = [];
	}
	getSettings();
}

/**
 * Re-populate $GLOBALS['templates'] from the heavyweight full templates JSON.
 *
 * Unlike getGlobals(), reads the file that retains Config/Network/etc. fields.
 * Also calls getSettings().
 *
 * @return void
 */
function getFullGlobals() {
	$GLOBALS['templates'] = readJsonFile(CA_PATHS['community-templates-info-full']);
	getSettings();
}

/**
 * Load CA + Dynamix + Unraid settings into $GLOBALS['caSettings'].
 *
 * Reads /etc/unraid-version, dynamix.cfg, and community.applications.cfg, then
 * sets derived flags: dockerSearch (off when Docker isn't running) and the
 * NoInstalls warning flag when the user hasn't accepted yet.
 *
 * @return void
 */
function getSettings() {

	$unRaidSettings = parse_ini_file("/etc/unraid-version");

	$GLOBALS['caSettings'] = parse_plugin_cfg("community.applications");
	$GLOBALS['caSettings']['dockerSearch']  = "yes";
	$GLOBALS['caSettings']['unRaidVersion'] = $unRaidSettings['version'];
	$GLOBALS['caSettings']['favourite']     = isset($GLOBALS['caSettings']['favourite']) ? str_replace("*","'",$GLOBALS['caSettings']['favourite']) : "";

 // $GLOBALS['caSettings']['maxPerPage']    = (integer)$GLOBALS['caSettings']['maxPerPage'] ?: 12; // Handle possible corruption on file
	//if ( $GLOBALS['caSettings']['maxPerPage'] < 6 ) $GLOBALS['caSettings']['maxPerPage'] = 12;

	if ( ! is_file(CA_PATHS['warningAccepted']) ) {
		$GLOBALS['caSettings']['NoInstalls'] = true;
	}
	if ( ! caIsDockerRunning() ) {
		$GLOBALS['caSettings']['dockerSearch'] = "no";
	}
}

/**
 * Admin mode = dev mode on AND the on-disk admin marker present
 * (/boot/config/plugins/community.applications/admin). Single source of truth
 * for the gate on moderator-only affordances (internal "CA" diff, the per-repo
 * and global Duplicates finders) — previously duplicated inline across exec.php,
 * skin.php, skin_helpers.php, skin.html and diff.php.
 *
 * clearstatcache on the marker so a freshly-created file is picked up without a
 * php-fpm restart; the call is cheap and admin mode is a dev-box-only path.
 *
 * @return bool
 */
function caIsAdmin() {
	if ( ($GLOBALS['caSettings']['dev'] ?? "no") !== "yes" ) return false;
	clearstatcache(true, CA_PATHS['caAdmin']);
	return is_file(CA_PATHS['caAdmin']);
}

/**
 * Whether the home/startup screen has already been rendered this session — i.e.
 * the per-tab startupDisplayed marker exists. Single source of truth for the
 * check so if the marker's storage (file vs. something else) ever changes, only
 * this function needs updating instead of every is_file() call site.
 *
 * @return bool
 */
function caStartupDisplayed() {
	clearstatcache(true, CA_PATHS['startupDisplayed']);
	return is_file(CA_PATHS['startupDisplayed']);
}
/**
 * Persist the in-memory templates array to the slim on-disk JSON cache.
 *
 * Called only from force_update after a fresh DownloadApplicationFeed —
 * no-download cycles intentionally skip the moderate+write step (the
 * cache already reflects the last download's moderation output, and the
 * only thing that could legitimately invalidate it without a fresh
 * feed is an Unraid OS version change which reboots and wipes /tmp).
 *
 * Empty-input branch left in as a safety net for explicit cache-clear
 * callers; not currently exercised by the live code paths.
 *
 * @param  array<int,array<string,mixed>>  $templates
 * @return void
 */
function writeGlobals($templates) {
	if ( ! is_array($templates) || empty($templates) ) {
		@unlink(CA_PATHS['community-templates-info']);
		@unlink(CA_PATHS['community-templates-info-full']);
		unset($GLOBALS['templates']);
		return;
	}
	/* Write the templates as-is to the small cache — no second-pass strip.
	   The server's slim feed already arrives without the heavy fields, and
	   the full-feed fallback path explicitly writes the full cache itself
	   via writeJsonFile() so we don't reiterate the array here. */
	writeJsonFile(CA_PATHS['community-templates-info'], $templates);
	$GLOBALS['templates'] = $templates;
	/* Feed-ready signal: the slim cache just landed on disk, other CA
	   tabs can safely reload to pick up the new content. The background
	   full-feed hydrate writes only the full cache (via writeJsonFile
	   directly, bypassing writeGlobals), so it doesn't re-fire the
	   signal — install-time data hydrates silently. */
	signalFeedReady();
}

/**
 * Mark the templates feed as fully ready: touch the haveTemplates sentinel
 * (gates enableActionCentre's wait loop) so background subscribers can
 * tell when a download has completed.
 *
 * Cross-tab "another browser updated the feed" used to also publish on
 * an nchan channel here, but that was replaced with a polling-style
 * check in exec.php's pre-switch guard (see the `caFeedCheck` block
 * near the top of exec.php). The displayed-{tabId}.json file's
 * existence on disk is the canonical "this tab is still in sync"
 * signal; another tab's DownloadApplicationFeed wipes tempFiles
 * including that file, and the guard catches it on the stale tab's
 * next request.
 */
function signalFeedReady() {
	touch(CA_PATHS['haveTemplates']);
}

/**
 * Create/refresh the per-tab registration marker for the calling tab.
 *
 * Idempotent. Reads tabId from $_POST (the JS `post()` helper stamps it
 * on every request). Validates against the same shape paths.php uses for
 * per-tab cache file suffixes (`^[A-Za-z0-9_-]{8,64}$`) — anything outside
 * that range (empty, ".", "..", overlong, contains `/`) is rejected.
 *
 * Returns true when the marker is provably on disk after the call, false
 * if any step failed (validation, mkdir, touch). Callers that need to
 * acknowledge a successful registration to the client (the `registerTab`
 * action handler) inspect this return so the client doesn't arm
 * caFeedTrackingArmed against a marker that doesn't actually exist.
 *
 * Called from:
 *  - the `registerTab` action handler (initial registration on tab boot)
 *  - the successful exit of force_update() (the calling tab's marker was
 *    wiped along with tempFiles by DownloadApplicationFeed; the re-
 *    register confirms this tab is in sync with the freshly-pulled feed —
 *    other tabs stay unregistered so their next caFeedCheck surfaces
 *    the reload banner)
 *
 * @return bool
 */
function ensureTabRegistered(): bool {
	$tabId = (string)($_POST['tabId'] ?? '');
	if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $tabId)) {
		return false;
	}
	$dir = CA_PATHS['registeredTabs'];
	if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
		return false;
	}
	$path = $dir . '/' . $tabId;
	if (!@touch($path)) {
		return false;
	}
	return is_file($path);
}

/**
 * Copy template rows while stripping bulky keys for the slim on-disk cache.
 *
 * @param array $source
 * @param array<int,string> $keysToRemove
 * @return array
 */
function duplicateArrayWithoutKeys($source, $keysToRemove = []): array {
	$copy = [];
	foreach ($source as $index => $item) {
		// stamp index on the original
		if (is_array($item)) {
			$source[$index]['ArrayIndex'] = $index;
		}
		if (!is_array($item)) {
			$copy[$index] = $item;
			continue;
		}
		$row = $item;
		foreach ($keysToRemove as $key) {
			unset($row[$key]);
		}
		$row['ArrayIndex'] = $index;
		$copy[$index] = $row;
	}
	return $copy;
}
/**
 * Wrapper around Unraid's plugin() that caches attribute reads and sanitizes output.
 *
 * For "method" calls (dump, changes, install, etc.) the response is HTML-stripped
 * and the attribute cache is invalidated. For attribute reads, results are
 * persisted to CA_PATHS['pluginAttributesCache'] so subsequent reads avoid
 * re-parsing the XML.
 *
 * @param  string  $method       Plugin method name or attribute name.
 * @param  string  $plugin_file  Path to the .plg.
 * @param  bool    $dontCache    When true, bypass the attribute cache.
 * @return mixed Cached attribute value, sanitized method output, or false.
 */
function ca_plugin($method, $plugin_file = '',$dontCache = false) {
	static $attributeCache = [];
	static $PLUGIN_METHODS = ['dump', 'changes', 'alert', 'validate', 'check', 'checkall', 'update', 'remove', 'install', 'attributes'];

	if ( in_array($method, $PLUGIN_METHODS) ) {
		// clear the attribute cache if the method is not an attribute (avoids stale data)
		$attributeCache = [];
		dropAttributeCache();

		return strip_tags(html_entity_decode(@plugin($method,$plugin_file)));
	}

	//  If the method is not a method, then it's an attribute.  Populate the attribute cache if it's not already populated and return

	if ( ! is_file(CA_PATHS['pluginAttributesCache']) ) {
		$attributeCache = [];
	}

	if ( ! $dontCache ) {
		if ( empty($attributeCache) && file_exists(CA_PATHS['pluginAttributesCache']) ) {
			$attributeCache = readJsonFile(CA_PATHS['pluginAttributesCache']);
			if ( empty($attributeCache) ) {
				$attributeCache = [];
				dropAttributeCache();
			}
		}
		if ( $plugin_file) {
			$dirty = false;
			if ( is_file($plugin_file) ) {
				if ( !isset($attributeCache[$plugin_file]) ) {
					debug("ca_plugin: adding $plugin_file to the attribute cache");
					$xml = @simplexml_load_file($plugin_file, NULL, LIBXML_NOCDATA);
					if ( $xml ) {
						$attributes = $xml->attributes();
					} else {
						$attributes = false;
					}
					$attributeCache[$plugin_file] = (array)$attributes ?: ["error" => "no attributes present"];
					$dirty = true;
				}
			} else if ( isset($attributeCache[$plugin_file]) ) {
				unset($attributeCache[$plugin_file]);
				$dirty = true;
			}
			if ( $dirty ) {
				writeJsonFile(CA_PATHS['pluginAttributesCache'], $attributeCache);
			}

			// return the cached result if it exists.  If it doesn't return false;;
			return $attributeCache[$plugin_file]['@attributes'][$method]??false;

		} else {
			return strip_tags(html_entity_decode(@plugin($method,$plugin_file)));
		}
	} else {
		return strip_tags(html_entity_decode(@plugin($method,$plugin_file)));
	}
}
/**
 * Delete the on-disk plugin attribute cache file.
 *
 * @return void
 */
function dropAttributeCache() {

	debug("Dropping attribute cache");
	@unlink(CA_PATHS['pluginAttributesCache']);
}
/**
 * Convert a list array into a flag map keyed by value.
 *
 * Example: ["one","two"] with default true -> ["one"=>true,"two"=>true].
 *
 * @param  array<int,string>|mixed  $sourceArray
 * @param  mixed                    $defaultFlag
 * @return array<string,mixed>
 */
function arrayEntriesToObject($sourceArray,$defaultFlag=true) {
	return is_array($sourceArray) ? array_fill_keys($sourceArray,$defaultFlag) : [];
}
/**
 * Determine whether a queued plugin in /tmp/plugins/ is newer than the installed copy.
 *
 * Also honors the queued file's unRAID-min-version attribute so updates flagged
 * for a future OS aren't reported.
 *
 * @param  string  $filename  Plugin filename or path; only the basename matters.
 * @return bool
 */
function checkPluginUpdate($filename) {

	$filename = basename($filename);
	if ( ! is_file("/var/log/plugins/$filename") ) return false;
	$upgradeVersion = (is_file("/tmp/plugins/$filename")) ? ca_plugin("version","/tmp/plugins/$filename") : "0";
	$installedVersion = $upgradeVersion ? ca_plugin("version","/var/log/plugins/$filename") : 0;

	if ( $installedVersion < $upgradeVersion ) {
		$unRaid = ca_plugin("unRAID","/tmp/plugins/$filename");
		return ( $unRaid === false || version_compare($GLOBALS['caSettings']['unRaidVersion'],$unRaid,">=") ) ? true : false;
	}
	return false;
}
/**
 * Return a unique temp filename under CA_PATHS['tempFiles'] (CA-Temp-XXXXXX).
 *
 * Creates the file as a side effect of tempnam(); caller is responsible for
 * cleanup.
 *
 * @return string Path to the new temp file.
 */
function randomFile() {

	return tempnam(CA_PATHS['tempFiles'],"CA-Temp-");
}
/**
 * Read a CA data file. Returns $default if the file is missing or unreadable.
 *
 * Files are PHP-serialized (faster than JSON for these caches). Falls back to
 * json_decode for legacy serialized-as-JSON caches and for genuinely-JSON files
 * read through here (eg. docker's unraid-update-status.json); the fallback is
 * logged so persistently-JSON callers are visible.
 *
 * @param string $filename
 * @param array $default
 * @return mixed
 */
function readJsonFile($filename, $default = []) {
	// AJAX requests carry the action name; other callers (CLI / cron scripts,
	// plugin-install scripts, page renders) don't, so fall back to the running
	// script's basename instead of "Unknown" to identify the calling process.
	$caller = $GLOBALS['action'] ?? $_POST['action'] ?? (basename($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? "") ?: "unknown");
	debug( "$caller - Read JSON file $filename");

	if ( ! is_file($filename) ) {
		debug("$filename not found");
		return $default;
	}

	$contents = @file_get_contents($filename);
	// allowed_classes => false: never instantiate objects from a cache file
	// (guards against PHP object injection if a cache is tampered with); our
	// caches are plain arrays. The b:0; check avoids treating a legitimately
	// serialized false as a failure and falling through to json_decode.
	$json = @unserialize($contents, ['allowed_classes' => false]);
	if ( $json === false && trim((string)$contents) !== 'b:0;' ) {
		debug("$caller - $filename is not serialized, falling back to json_decode");
		$json = json_decode($contents, true);
	}
	if ( $json === null || $json === false ) {
		debug("JSON Read Error ($filename)");
		return $default;
	}

	debug("Memory Usage:".round(memory_get_usage()/1048576,2)." MB");
	return $json;
}

/**
 * Return whether the Docker daemon appears to be running (cached).
 *
 * @return bool
 */
function caIsDockerRunning() {
	static $dockerRunning = null;

	if ($dockerRunning !== null) {
		return $dockerRunning;
	}

	$pidFile = "/var/run/dockerd.pid";
	if (!is_file($pidFile)) {
		return $dockerRunning = false;
	}

	$pid = trim(@file_get_contents($pidFile));
	if ($pid === "") {
		return $dockerRunning = false;
	}

	if (!is_dir("/proc/$pid")) {
		return $dockerRunning = false;
	}

	return $dockerRunning = true;
}

/**
 * Persist an array to disk as pretty JSON.
 *
 * @param string $filename
 * @param array $jsonArray
 * @return void
 */
function writeJsonFile($filename,$jsonArray) {
	debug(($_POST['action']??'Unknown')." - Write JSON File $filename");
	$result = ca_file_put_contents($filename,serialize($jsonArray));
	debug("Memory Usage:".round(memory_get_usage()/1048576,2)." MB");

	// The plugin script needs a templates.json in JSON format to update support URLs on plugins
	// If we're writing $templates, then save templates.json but filtered only for plugins to save space
	if ( $filename == CA_PATHS['community-templates-info'] ) {
		// array_values re-indexes to a flat JSON array - post_plugin_checks reads
		// this with an index loop ($db[$i]), so the keys must be sequential.
		ca_file_put_contents(CA_PATHS['community-templates-info-old'],json_encode(array_values(array_map(function($t) {
			return ["PluginURL"=>$t['PluginURL']??null,"Support"=>$t['Support']??null];
		},array_filter($jsonArray, function($t1) {
			return $t1['Plugin']??false;
		}))),JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
	}
}

/**
 * Atomic file write via temp file rename; sets $GLOBALS['script'] alert on failure.
 *
 * @param string $filename
 * @param string $data
 * @param int $flags
 * @return int|false Bytes written or false
 */
function ca_file_put_contents($filename,$data,$flags=0) {
	$result = @file_put_contents($filename."~",$data,$flags);

	/* A false result usually means the parent directory doesn't exist yet.
	   Create the path and try once more before surfacing the error. */
	if ( $result === false ) {
		debug("Failed to write to $filename - creating ".dirname($filename)." and retrying");
		@unlink($filename."~");
		@mkdir(dirname($filename), 0777, true);
		$result = @file_put_contents($filename."~",$data,$flags);
	}

	if ( $result === strlen($data) ) {
		@rename($filename."~",$filename);
	}

	if ( $result === false ) {
		@unlink($filename."~");
		debug("Failed to write to $filename");
		$GLOBALS['script'] = "alert('Failed to write to ".htmlentities($filename,ENT_QUOTES)."');";
	}
	return ($result === strlen($data)) ? strlen($data) : false;
}

/**
 * Download a URL with cURL; optional proxy, progress publish, and flock when caching to $path.
 *
 * @param string $url
 * @param string $path If non-empty, response body is written here
 * @param int $timeout Seconds; 0 uses libcurl default
 * @return string|false Response body or false on failure
 */
function download_url($url, $path = "", $timeout = 0, $userAgent = "") {
	static $proxycfg = null;

	// Serialize concurrent downloads of the same URL to the same $path.
	// The previous JSON-based lock had race conditions (read/modify/write) and could leak locks
	// if $url was rewritten (e.g. proxy fallback). Use an OS-level flock instead.
	$lockHandle = null;
	$lockPath = "";
	$originalUrl = $url;
	if ($path) {
		@mkdir(CA_PATHS['downloadLocksDir'], 0777, true);
		$lockPath = CA_PATHS['downloadLocksDir'] . "/download_lock_" . hash("sha256", $originalUrl) . ".lock";
		$lockHandle = @fopen($lockPath, "c");
		if ($lockHandle) {
			// If we can grab the lock immediately, we're the downloader and may overwrite $path.
			// If another process is already downloading, wait for it to finish and then return its cached result.
			if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
				debug("DOWNLOAD waiting for lock $originalUrl");
				@flock($lockHandle, LOCK_EX);
				clearstatcache();
				if (is_file($path) && @filesize($path) > 0) {
					debug("DOWNLOAD returning cached $originalUrl");
					$cached = @file_get_contents($path);
					@flock($lockHandle, LOCK_UN);
					@fclose($lockHandle);
					return $cached;
				}
				// Cache still missing/empty even after waiting: fall through and attempt download under the lock.
			}
		}
	}

	if ($proxycfg === null) {
		$proxycfg = ((! getenv("http_proxy")) && is_file("/boot/config/plugins/community.applications/proxy.cfg")) ? @parse_ini_file("/boot/config/plugins/community.applications/proxy.cfg") : false;
	}

	try {
		debug("DOWNLOAD starting $url\n");
		$startTime = time();
		$curl_options = [
		    CURLOPT_ENCODING=>"",
			CURLOPT_FRESH_CONNECT=>true,
			CURLOPT_RETURNTRANSFER=>true,
			CURLOPT_FOLLOWLOCATION=>true,
			CURLOPT_FAILONERROR=>true,
			CURLOPT_NOPROGRESS=>false,
			CURLOPT_URL=>$url,
			CURLOPT_PROGRESSFUNCTION=>"testProgress"

		];


		if ( $timeout > 0 ) {
			$curl_options[CURLOPT_TIMEOUT] = $timeout;
			$curl_options[CURLOPT_CONNECTTIMEOUT] = $timeout;
		}

		if ( $userAgent !== "" ) {
			$curl_options[CURLOPT_USERAGENT] = $userAgent;
		}

		if ( $proxycfg ) {
			$curl_options[CURLOPT_PROXYPORT] = intval($proxycfg['port']);
			$curl_options[CURLOPT_HTTPPROXYTUNNEL] = intval($proxycfg['tunnel']);
			$curl_options[CURLOPT_PROXY] = $proxycfg['proxy'];
		}

		$ch = curl_init();
		curl_setopt_array($ch,$curl_options);

		$out = curl_exec($ch);

		if ( curl_errno($ch) == 23 ) {
			debug("cURL error 23.  Switching encoding to deflate");

			// curl_close is NOP in php 8+ and issues a warning in 8.5+
			if ( PHP_MAJOR_VERSION < 8 ) {
				call_user_func('curl_close', $ch);
			}

			$curl_options[CURLOPT_ENCODING] = "deflate";
			$ch = curl_init();
			curl_setopt_array($ch,$curl_options);
			$out = curl_exec($ch);
		}

		if ( curl_error($ch) && startsWith($url,CA_PATHS['pluginProxy']) ) {
			debug("Proxy error.  (cURL error: ".curl_error($ch).") Switching to direct download - $url");
			$url = str_replace(CA_PATHS['pluginProxy'],"",$url);
			$curl_options[CURLOPT_URL] = $url;
			if ( PHP_MAJOR_VERSION < 8 ) {
				call_user_func('curl_close', $ch);
			}
			sleep(3);
			$ch = curl_init();
			curl_setopt_array($ch,$curl_options);
			$out = curl_exec($ch);
		}
		if ( $path ) {
			ca_file_put_contents($path,$out);
		}
		if ( $out === false ) {
			debug("cURL error: ".curl_error($ch));
			@unlink($path);
		}
		if ( PHP_MAJOR_VERSION < 8 ) {
			call_user_func('curl_close', $ch);
		}

		ca_publish("ca_downloadProgress","");
		$totalTime = time() - $startTime;
		debug("DOWNLOAD $url Time: $totalTime  RESULT: ".($out ? "true" : "false"));

		return $out ?: false;
	} finally {
		if ($lockHandle) {
			@flock($lockHandle, LOCK_UN);
			@fclose($lockHandle);
		}
	}
}

/**
 * Format a byte count as a short human-readable string (B through EB).
 *
 * @param int|float|string $bytes
 * @return string
 */
function MakeReadable($bytes) {
	if (!is_numeric($bytes) || $bytes < 0) {
		return "";
	}

	if ($bytes == 0) {
		return "0B";
	}

	$units = ['B','kB','MB','GB','TB','PB','EB'];
	$precision = [0,0,1,1,3,3,3];

	$i = (int)floor(log($bytes, 1024));
	$i = max(0, min($i, count($units) - 1));

	return round($bytes / pow(1024, $i), $precision[$i]).$units[$i];
}

/**
 * cURL progress callback: publishes download progress to the UI channel.
 *
 * @param resource $ch
 * @return int
 */
function testProgress($ch,$download_total,$download_current,$upload_total,$upload_current) {
	$testProgress = curl_getinfo($ch);

	if ( $download_total > 0 ) {
		$percentage = intval($download_current / $download_total * 100);
		ca_publish("ca_downloadProgress",basename($testProgress['url'])." - ".MakeReadable($download_current)." of ".MakeReadable($download_total)." at ".MakeReadable($testProgress['speed_download'])."/s ($percentage%)");
	} else {
		ca_publish("ca_downloadProgress",basename($testProgress['url'])." - ".MakeReadable($download_current)." at ".MakeReadable($testProgress['speed_download'])."/s");
	}
}

/**
 * Publish to nchan: uses publish_noDupe when available.
 *
 * @param string $endpoint
 * @param string $message
 * @return void
 */
function ca_publish($endpoint,$message) {
	if ( ! function_exists("publish_noDupe") ) {
		publish($endpoint,$message);
	} else {
		publish_noDupe($endpoint,$message);
	}
}

/**
 * Fetch JSON from URL, decode to array, optionally write via writeJsonFile.
 *
 * @param string $url
 * @param string $path   Optional path for decoded array
 * @param int    $timeout
 * @param bool   $shared When true (default), forwards $path to download_url so
 *                       its flock-based serializer engages — a second concurrent
 *                       call for the same URL blocks until the first finishes
 *                       and then returns the cached bytes. Legacy callers that
 *                       use a unique per-request temp path (eg. the primary
 *                       applicationFeed download) want false to keep the old
 *                       "each request gets its own download" behavior.
 * @return array|false
 */
function download_json($url,$path="",$timeout=0,$shared=true) {
	// download the URL, but don't save it yet
	$result = download_url($url, $shared ? $path : "", $timeout);
	if ( $result === false ) {
		if ( $shared ) @unlink($path);
		return false;
	}
	/* For $shared=false callers (DownloadApplicationFeed's per-request
	   tempfile), stash the raw bytes on $path so the legacy failure path
	   (buildDownloadFailureResponse / appFeedDownloadError) can inspect a
	   partial / malformed response. download_url already wrote the bytes
	   when $shared=true (path forwarded through). */
	if ( ! $shared && $path ) {
		ca_file_put_contents($path, $result);
	}
	// Decode and validate before caching — json_decode returns null for
	// malformed/non-JSON responses (eg. an HTML error page). Without this
	// guard a transient bad response would persist as a literal `null` cache
	// entry and silently poison every later read.
	$ret = json_decode($result,true);
	if ( ! is_array($ret) ) {
		debug("JSON decode error downloading $url: ".json_last_error_msg());
		/* Only nuke the cache file on shared-mode failures — for
		   $shared=false (caller-managed tempfile) leave the raw response
		   on $path so the legacy debug path keeps something to inspect. */
		if ( $shared ) @unlink($path);
		return false;
	}
	if ( $path ) {
		writeJsonFile($path,$ret);
	}
	return $ret;
}

/**
 * @param string $setting POST key
 * @param mixed $default
 * @return mixed
 */
function getPost($setting,$default) {
	return isset($_POST[$setting]) ? urldecode(($_POST[$setting])) : $default;
}

/**
 * Raw POST value for an array-shaped field (no urldecode).
 *
 * @param string $setting POST key
 * @return mixed
 */
function getPostArray($setting) {
	return $_POST[$setting];
}

/**
 * Return var_dump output as a string (for logging/debug).
 *
 * @param mixed $mixed
 * @return string
 */
function var_dump_ret($mixed = null) {
	ob_start();
	var_dump($mixed);
	$content = ob_get_contents();
	ob_end_clean();
	return $content;
}
/**
 * Determine whether $haystack begins with $needle.
 *
 * When $needle is an array, returns true when any candidate matches.
 *
 * @param  string                       $haystack
 * @param  string|array<int,string>     $needle
 * @return bool
 */
function startsWith($haystack, $needle) {
	if ( is_array($needle) ) {
		foreach ($needle as $need) {
			if ( startsWith($haystack,$need) )
				return true;
		}
		return false;
	}
	if ( !is_string($haystack) || ! is_string($needle) ) return false;
	return $needle === "" || strripos($haystack, $needle, -strlen($haystack)) !== FALSE;
}
/**
 * Determine whether $string ends with $endString.
 *
 * When $endString is an array, returns true when any candidate matches.
 *
 * @param  string                       $string
 * @param  string|array<int,string>     $endString
 * @return bool
 */
function endsWith($string, $endString) {
	if ( is_array($endString) ) {
		foreach ($endString as $end) {
			if (endsWith($string,$end) )
				return true;
		}
		return false;
	}
	$len = strlen($endString);
	if ($len == 0) {
		return true;
	}
	return (substr($string, -$len) === $endString);
}
/**
 * Replace only the first occurrence of $needle in $haystack with $replace.
 *
 * @param  string  $haystack
 * @param  string  $needle
 * @param  string  $replace
 * @return string
 */
function first_str_replace($haystack, $needle, $replace) {
	$pos = strpos($haystack, $needle);
	return ($pos !== false) ? substr_replace($haystack, $replace, $pos, strlen($needle)) : $haystack;
}
/**
 * Replace only the last occurrence of $needle in $haystack with $replace.
 *
 * @param  string  $haystack
 * @param  string  $needle
 * @param  string  $replace
 * @return string
 */
function last_str_replace($haystack, $needle, $replace) {
	$pos = strrpos($haystack, $needle);
	return ($pos !== false) ? substr_replace($haystack, $replace, $pos, strlen($needle)) : $haystack;
}
/**
 * usort() comparator that honors global $sortOrder (sortBy + sortDir).
 *
 * Numeric comparisons are used for downloads / trendDelta; everything else
 * falls back to case-insensitive string compare. "Name" is mapped to
 * "SortName" so " - " normalized titles sort correctly.
 *
 * @param  array<string,mixed>  $a
 * @param  array<string,mixed>  $b
 * @return int
 */
function mySort($a, $b) {
	global $sortOrder;

	$a['trendDelta'] = $a['trendDelta'] ?? null;
	$b['trendDelta'] = $b['trendDelta'] ?? null;
	if ( $sortOrder['sortBy'] == "Name" )
		$sortOrder['sortBy'] = "SortName";

	if ( $sortOrder['sortBy'] == "lastMonthDownloads" ) {
		/* Most Popular Plugins ranks by the previous full calendar month's
		   install count, read live from each template's pluginStats map (MM
		   to monthly count) instead of a precomputed field. This lets the
		   SHOW MORE category view sort identically to the home section without
		   the field having to be baked onto every template first. The month is
		   resolved once and reused across the comparator calls. */
		static $prevMonth = null;
		if ( $prevMonth === null )
			$prevMonth = date("m", strtotime("first day of previous month"));
		$c = (int)($a['pluginStats'][$prevMonth] ?? 0);
		$d = (int)($b['pluginStats'][$prevMonth] ?? 0);
	} else if ( $sortOrder['sortBy'] != "downloads" && $sortOrder['sortBy'] != "trendDelta" && $sortOrder['sortBy'] != "trending") {
		$c = strtolower($a[$sortOrder['sortBy']] ?? "");
		$d = strtolower($b[$sortOrder['sortBy']] ?? "");
	} else {
		$c = $a[$sortOrder['sortBy']]??"";
		$d = $b[$sortOrder['sortBy']]??"";
	}

	$return1 = ($sortOrder['sortDir'] == "Down") ? -1 : 1;
	$return2 = ($sortOrder['sortDir'] == "Down") ? 1 : -1;

	if ( ! is_numeric($c) ) {
		$c = strtolower($c ?? "");
		$d = strtolower($d ?? "");
	}
	if ($c > $d) return $return1;
	else if ($c < $d) return $return2;
	else return 0;
}

/**
 * Sort comparator: favourite repository first (RepoName).
 *
 * @param array<string,mixed> $a
 * @param array<string,mixed> $b
 * @return int
 */
function repositorySort($a,$b) {

	if ( $a['RepoName'] == $GLOBALS['caSettings']['favourite'] ) return -1;
	if ( $b['RepoName'] == $GLOBALS['caSettings']['favourite'] ) return 1;
	return 0;
}

/**
 * Sort comparator: favourite repository first (Repo field).
 *
 * @param array<string,mixed> $a
 * @param array<string,mixed> $b
 * @return int
 */
function favouriteSort($a,$b) {

	if ( $a['Repo'] == $GLOBALS['caSettings']['favourite'] ) return -1;
	if ( $b['Repo'] == $GLOBALS['caSettings']['favourite'] ) return 1;
	return 0;
}
/**
 * Locate the first index in $array whose [$key] equals $value.
 *
 * Returns the index, or `false` when not found. Iteration starts at
 * $startingIndex which lets callers resume past previously matched rows.
 *
 * When $caseInsensitive is true the match is a lowercased string compare
 * (both sides cast to string), useful for things like TemplateURL lookups.
 * Otherwise the comparison is the loose == used historically.
 *
 * @param  array<int|string,array<string,mixed>>  $array
 * @param  string                                 $key
 * @param  mixed                                  $value
 * @param  int                                    $startingIndex
 * @param  bool                                   $caseInsensitive
 * @return int|string|false
 */
function searchArray($array,$key,$value,$startingIndex=0,$caseInsensitive=false) {
	if (is_array($array) && count($array) ) {
		$needle = $caseInsensitive ? strtolower((string)$value) : null;
		foreach ($array as $i => $item) {
			if ( $i < $startingIndex ) {
				continue;
			}
			$itemValue = $item[$key] ?? null;
			if ( $caseInsensitive ) {
				if ( strtolower((string)$itemValue) === $needle ) {
					return $i;
				}
			} else if ( $itemValue == $value ) {
				return $i;
			}
		}
	}
	return false;
}
/**
 * Synthesize docker-style trend arrays for plugin templates from `pluginStats`.
 *
 * pluginStats is a calendar-year map of MM => monthly install count plus
 * `T` (lifetime total). Dockers ship pre-baked `trends` / `trendsDate` /
 * `downloadtrend`; plugins don't, so we compute equivalents here so the
 * sidebar's Trend / Downloads-Per-Month / Total-Downloads charts (gated only
 * on those fields being populated) light up for plugins too.
 *
 * Strategy: walk a rolling window of up to 11 completed months ending at the
 * previous month (11 not 12 — the MM keys are year-agnostic, so going a full
 * 12 back would alias to the current partial month's key). Anchor the latest
 * cumulative on `T - currentMonthInstalls` and walk backwards subtracting
 * each month's installs. Pre-window history (anything older than the window)
 * collapses into the first point so the cumulative chart reflects real
 * lifetime growth. Iteration stops once we cross FirstSeen.
 *
 * @param  array<string,mixed>  $template  Mutated in place.
 * @return void
 */
function computePluginTrendsFromStats(&$template) {
	if ( ! ($template['PluginURL'] ?? false) ) return;
	if ( ! is_array($template['pluginStats'] ?? null) ) return;

	$stats = $template['pluginStats'];
	$total = (int)($stats['T'] ?? 0);
	if ( $total <= 0 ) return;

	$currentYear  = (int)date('Y');
	$currentMonth = (int)date('n');
	$firstSeen    = (int)($template['FirstSeen'] ?? 0);
	$firstSeenYear  = $firstSeen ? (int)date('Y', $firstSeen) : 0;
	$firstSeenMonth = $firstSeen ? (int)date('n', $firstSeen) : 0;

	/* pluginStats uses MM-only keys, so the same key is reused every year.
	   We can safely walk up to 11 months back from the last completed month
	   — going 12 back would collide with the current (partial) month's key.
	   Current month itself is excluded (partial data). */
	$points = []; // [['year'=>Y,'month'=>M], ...] oldest-last
	for ( $i = 1; $i <= 11; $i++ ) {
		$ts = mktime(0, 0, 0, $currentMonth - $i, 1, $currentYear);
		$y = (int)date('Y', $ts);
		$m = (int)date('n', $ts);
		if ( $firstSeen && ($y < $firstSeenYear || ($y === $firstSeenYear && $m < $firstSeenMonth)) ) {
			break; // earlier than FirstSeen — stop walking back
		}
		$points[] = ['year' => $y, 'month' => $m];
	}
	if ( count($points) < 2 ) return;
	$points = array_reverse($points); // chronological

	$monthly = [];
	$dates   = [];
	foreach ( $points as $p ) {
		$monthly[] = (int)($stats[sprintf('%02d', $p['month'])] ?? 0);
		$dates[]   = mktime(0, 0, 0, $p['month'], 1, $p['year']);
	}

	/* T includes the current (excluded) partial month — back it out so the
	   final cumulative anchors on end-of-previous-month. */
	$currentMonthInstalls = (int)($stats[sprintf('%02d', $currentMonth)] ?? 0);
	$anchor = max(0, $total - $currentMonthInstalls);

	$cumulative = array_fill(0, count($monthly), 0);
	$running    = $anchor;
	for ( $i = count($monthly) - 1; $i >= 0; $i-- ) {
		$cumulative[$i] = max(0, $running);
		$running       -= $monthly[$i];
	}

	$trends = [];
	foreach ( $monthly as $i => $cnt ) {
		$cum      = $cumulative[$i];
		$trends[] = $cum > 0 ? round(($cnt / $cum) * 100, 3) : 0;
	}

	$template['trends']        = $trends;
	$template['trendsDate']    = $dates;
	$template['downloadtrend'] = $cumulative;
	$template['trending']      = end($trends);
}

/**
 * Repair common template authoring mistakes so the rest of the pipeline can trust the row.
 *
 * Fixes default MinVer, derives Date/BrandNewApp, normalizes Deprecated /
 * Blacklist into real booleans, applies DeprecatedMaxVer, and clears
 * boilerplate "Container Path:" descriptions from Config entries. Also
 * synthesizes plugin-side trend arrays from pluginStats so the sidebar
 * charts work for plugins (see computePluginTrendsFromStats).
 *
 * @param  array<string,mixed>  $template
 * @return array<string,mixed>
 */
function fixTemplates($template) {
	computePluginTrendsFromStats($template);

	if ( ! $template['MinVer'] ) $template['MinVer'] = ($template['Plugin']??false) ? "6.1" : "6.0";
	if ( ! ($template['Date']??null) ) $template['Date'] = (is_numeric($template['DateInstalled']??null)) ? $template['DateInstalled'] : 0;
	$template['Date'] = max($template['Date']??null,$template['FirstSeen']??null);
	if ($template['Date'] == 1) $template['Date'] = null;
	$firstSeen = $template['FirstSeen'] ?? null;
	if ( $firstSeen !== null && ($template['Date'] == $firstSeen) && ($firstSeen >= 1538357652) ) {# 1538357652 is when the new appfeed first started
		$template['BrandNewApp'] = true;
		$template['Date'] = null;
	}

	# fix where template author includes <Blacklist> or <Deprecated> entries in template (CA used booleans, but appfeed winds up saying "FALSE" which equates to be true
	$template['Deprecated'] = filter_var($template['Deprecated']??null,FILTER_VALIDATE_BOOLEAN);
	$template['Blacklist'] = filter_var($template['Blacklist']??null,FILTER_VALIDATE_BOOLEAN);

	if ( ($template['DeprecatedMaxVer']??null) && version_compare($GLOBALS['caSettings']['unRaidVersion'],$template['DeprecatedMaxVer'],">") )
		$template['Deprecated'] = true;

	if ( $template['Config']??null ) {
		if ( $template['Config']['@attributes'] ?? false ) {
			if (preg_match("/^(Container Path:|Container Port:|Container Label:|Container Variable:|Container Device:)/",$template['Config']['@attributes']['Description']??"") ) {
				$template['Config']['@attributes']['Description'] = "";
			}
		} else {
			if (is_array($template['Config'])) {
				foreach ($template['Config'] as &$config) {
					if (preg_match("/^(Container Path:|Container Port:|Container Label:|Container Variable:|Container Device:)/",$config['@attributes']['Description']??"") ) {
						$config['@attributes']['Description'] = "";
					}
				}
			}
		}
	}
	return $template;
}
/**
 * Build a dockerMan-compatible XML string from a CA template array.
 *
 * Promotes Overview into Description, normalizes Network/Config attributes,
 * sanitizes Requires links, and delegates to Array2XML.
 *
 * @param  array<string,mixed>  $template
 * @return string XML document.
 */
function makeXML($template) {
	# ensure its a v2 template if the Config entries exist
	if ( isset($template['Config']) && ! isset($template['@attributes']) )
		$template['@attributes'] = ["version"=>2];

	if ($template['Overview']) $template['Description'] = $template['Overview'];

	fixAttributes($template,"Network");
	fixAttributes($template,"Config");

# Sanitize the Requires entry if there is any CA links within it
	if ($template['Requires'] ?? false) {
		preg_match_all("/\/\/(.*?)&#92;/m",$template['Requires'],$searchMatches);

		if ( isset($searchMatches[1]) && count($searchMatches[1]) ) {
			foreach ($searchMatches[1] as $searchResult) {
				$template['Requires'] = str_replace("//$searchResult\\\\",$searchResult,$template['Requires']);
			}
		}
	}
	$Array2XML = new Array2XML();
	$xml = $Array2XML->createXML("Container",$template);
	return $xml->saveXML();
}
/**
 * Reshape appfeed-style Network/Config entries into the Array2XML form (in place).
 *
 * Single-entry @attributes/value pairs get wrapped into a list; existing list
 * entries are pivoted to @attributes/@value.
 *
 * @param  array<string,mixed>  $template
 * @param  string               $attribute  Key to fix ("Network" or "Config").
 * @return void
 */
function fixAttributes(&$template,$attribute) {
	if ( ! isset($template[$attribute]) ) return;
	if ( ! is_array($template[$attribute]) ) return;
	if ( isset($template[$attribute]['@attributes']) ) {
		$template[$attribute][0]['@attributes'] = $template[$attribute]['@attributes'];
		if ( $template[$attribute]['value'])
			$template[$attribute][0]['value'] = $template[$attribute]['value'];

		unset($template[$attribute]['@attributes']);
		unset($template[$attribute]['value']);
	}

	if ( $template[$attribute] ) {
		foreach ($template[$attribute] as $tempArray)
			$tempArray2[] = isset($tempArray['value']) ? ['@attributes'=>$tempArray['@attributes'],'@value'=>$tempArray['value']] : ['@attributes'=>$tempArray['@attributes']];
		$template[$attribute] = $tempArray2;
	}
}
/**
 * Test a template's MinVer/MaxVer/IncompatibleVersion against the running Unraid version.
 *
 * Reads $GLOBALS['caSettings']['unRaidVersion']. Returns true when the app is
 * compatible with the running OS.
 *
 * @param  array<string,mixed>  $template
 * @return bool
 */
function versionCheck($template) {

	if ( $template['IncompatibleVersion']??null ) {
		if ( ! is_array($template['IncompatibleVersion']) ) {
			$incompatible[] = $template['IncompatibleVersion'];
		} else {
			$incompatible = $template['IncompatibleVersion'];
		}
		foreach ($incompatible as $ver) {
			if ( $ver == $template['pluginVersion'] ) return false;
		}
	}

	if ( ($template['MinVer']??null) && ( version_compare($template['MinVer'],$GLOBALS['caSettings']['unRaidVersion']) > 0 ) ) return false;
	if ( ($template['MaxVer']??null) && ( version_compare($template['MaxVer'],$GLOBALS['caSettings']['unRaidVersion']) < 0 ) ) return false;
	return true;
}

/**
 * Recursively strip risky XML-like markup from template string fields (in place).
 *
 * @param array<string,mixed> $template
 * @return void
 */
function removeXMLtags(&$template) {
	foreach ($template as $key => &$element) {
		if ( is_array($element) ) {
			removeXMLtags($element);
			continue;
		}

		$value = (string)($element ?? "");

		/* Normalize the multi line display encoding the feed bakes into text
		   fields so it does not surface downstream as a literal br or nbsp:
		   break tags become newlines, carriage returns (including the ones the
		   feed pads line ends with) are dropped, the non breaking spaces it uses
		   for indentation collapse to ordinary spaces, and per line leading
		   indentation is removed. This only converts breaks and collapses
		   whitespace. It never unescapes content or introduces markup, so the
		   tag stripping below is unaffected and clean fields keep their
		   existing entity escaping. */
		$value = preg_replace('#<br\s*/?>#i', "\n", $value) ?? $value;
		$value = str_replace(["\r", "&#xD;", "&#13;", "&#x0D;"], "", $value);
		$value = str_replace(["&nbsp;", "&#160;", "&#xA0;"], " ", $value);
		$value = preg_replace('/^[ \t]+/m', "", $value) ?? $value;

		/* Existing tag strip pass, preserved: when decoding the value reveals
		   real tags, remove their angle brackets so nothing can execute. */
		$decoded = str_replace("<br>", "\n", htmlspecialchars_decode($value));
		if ( trim($decoded) !== trim(strip_tags($decoded)) ) {
			$element = str_replace(["<", ">"], ["", ""], $decoded);
		} else {
			$element = $value;
		}
	}
}
/**
 * Read a dockerMan/CA template XML into an array and apply CA-specific fixups.
 *
 * Strips dangerous markup, fills in missing keys, derives Author / DockerHubName /
 * SortName, and (unless $generic) increments global $statistics counters.
 *
 * @param  string  $xmlfile
 * @param  bool    $generic  When true, return the raw parsed array without CA fixups.
 * @param  bool    $stats    When true, tally plugin/docker counts in $statistics.
 * @return array<string,mixed>|false False when the file is missing or unparseable.
 */
function readXmlFile($xmlfile,$generic=false,$stats=true) {
	global $statistics;

	if ( ! $xmlfile || ! is_file($xmlfile) ) return false;
	$xml = file_get_contents($xmlfile);
	$o = TypeConverter::xmlToArray($xml,TypeConverter::XML_GROUP);
	if ( ! $o || ! is_array($o) ) return false;
	removeXMLtags($o);
	$o = addMissingVars($o);
	if ( ! $o ) return false;
	if ( $generic ) return $o;

	# Fix some errors in templates prior to continuing

	$o['Path']          = $xmlfile;
	$o['Author']        = getAuthor($o);
	$o['DockerHubName'] = strtolower($o['Name']);
	$o['Base']          = $o['BaseImage'] ?? "";
	$o['SortAuthor']    = $o['Author'];
	$o['SortName']      = $o['Name'];
	$o['Forum']         = $Repo['forum'] ?? "";
# configure the config attributes to same format as appfeed
# handle the case where there is only a single <Config> entry

	if ( isset($o['Config']['@attributes']) )
		$o['Config'] = ['@attributes'=>$o['Config']['@attributes'],'value'=>$o['Config']['value']];

	if ( $stats) {
		$statistics['plugin'] = $statistics['plugin'] ?? 0;
		$statistics['docker'] = $statistics['docker'] ?? 0;
		if ( $o['Plugin'] ) {
			$o['Author']     = $o['PluginAuthor'];
			$o['Repository'] = $o['PluginURL'];
			$o['SortAuthor'] = $o['Author'];
			$o['SortName']   = $o['Name'];
			$statistics['plugin']++;
		} else
			$statistics['docker']++;
	}
	return $o;
}
/**
 * Reapply moderation (Compatible/Featured/Deprecated/UninstallOnly) onto the in-memory templates.
 *
 * Moderation can be refreshed independently of the appfeed, so this is invoked
 * outside DownloadApplicationFeed() to keep $GLOBALS['templates'] current.
 *
 * @return void
 */
function moderateTemplates() {

	$templates = &$GLOBALS['templates'];

	if ( ! $templates ) return;
	foreach ($templates as $template) {
		$template['Compatible'] = versionCheck($template);
		if ( ($template['MaxVer']??null) && version_compare($template['MaxVer'],$GLOBALS['caSettings']['unRaidVersion']) < 0 )
			$template['Featured'] = false;
		if ( $template['CAMinVer'] ?? false ) {
			$template['UninstallOnly'] = version_compare($template['CAMinVer'],$GLOBALS['caSettings']['unRaidVersion'],">=");
		}

		if ( ($template["DeprecatedMaxVer"]??null) && version_compare($GLOBALS['caSettings']['unRaidVersion'],$template["DeprecatedMaxVer"],">") )
			$template['Deprecated'] = true;

		$template['ModeratorComment'] = $template['CaComment'] ?? ($template['ModeratorComment']??null);
		$o[] = $template;
	}
	pluginDupe();
	$GLOBALS['templates'] = $o;

}
/**
 * Validate that $URL is a plausibly clickable public-internet http(s) URL.
 *
 * Rejects non-http(s) schemes, loopback IPv4/IPv6 (including bracketed,
 * mapped-v6, and decimal/hex bypass forms) so templates can't smuggle in
 * file://, javascript: or local-GUI redirects.
 *
 * @param  string  $URL
 * @return bool
 */
function validURL($URL) {
	/* filter_var alone accepts ftp:/file:/etc., so additionally require an
	   http(s) scheme — every place this is called is rendering a clickable
	   link from template-supplied data, and we don't want a malicious
	   template slipping in javascript:, file://, or scheme-less local paths
	   like /Main/Dashboard. Loopback hosts (localhost, 127.x, ::1, 0.0.0.0,
	   plus the decimal/hex/octal IPv4 bypasses browsers still resolve) are
	   also rejected so a template can't aim a click at the user's own GUI
	   through a "real" http URL. */
	if (!filter_var($URL, FILTER_VALIDATE_URL)) return false;
	if (!preg_match('/^https?:\/\//i', (string)$URL)) return false;
	$host = strtolower((string)parse_url((string)$URL, PHP_URL_HOST));
	if ($host === "") return false;
	/* Strip surrounding brackets from IPv6 literals so the comparisons below
	   work for both "[::1]" and "::1". */
	if ($host[0] === "[" && substr($host, -1) === "]") {
		$host = substr($host, 1, -1);
	}
	if ($host === "localhost" || $host === "0" || $host === "0.0.0.0" || $host === "::1" || $host === "0:0:0:0:0:0:0:1") return false;
	if (preg_match('/^127(?:\.\d{1,3}){3}$/', $host)) return false;
	/* IPv4-mapped IPv6 loopback: ::ffff:127.x.x.x */
	if (preg_match('/^::ffff:127(?:\.\d{1,3}){3}$/', $host)) return false;
	/* Decimal-encoded IPv4: a single integer the browser still resolves —
	   2130706432..2147483647 covers the 127.0.0.0/8 range. */
	if (ctype_digit($host)) {
		$asInt = (int)$host;
		if ($asInt === 0) return false;
		if ($asInt >= 2130706432 && $asInt <= 2147483647) return false;
	}
	/* Hex-encoded IPv4 (0x7f000001 etc.) */
	if (preg_match('/^0x[0-9a-f]+$/i', $host)) {
		$asInt = hexdec(substr($host, 2));
		if ($asInt === 0) return false;
		if ($asInt >= 0x7f000000 && $asInt <= 0x7fffffff) return false;
	}
	return true;
}

/**
 * Test whether a host string resolves to a private or loopback address.
 *
 * Used by the README/changelog sanitizers, which need a stricter policy than
 * validURL — every link/image must point to the public internet, not LAN
 * hosts, link-local, ULA, etc. Decimal- and hex-encoded IPv4 bypasses are
 * normalized to dotted quads first; IPv6 ULA / link-local / IPv4-mapped
 * loopback are all recognized.
 *
 * @param  string  $host
 * @return bool
 */
function caIsPrivateOrLoopbackHost(string $host): bool {
	$host = strtolower(trim($host));
	if ($host === "") return true;
	/* Strip surrounding brackets from IPv6 literals. */
	if ($host[0] === "[" && substr($host, -1) === "]") {
		$host = substr($host, 1, -1);
	}
	/* Trivial hostname matches and the unspecified address. */
	if (in_array($host, ["localhost", "0", "0.0.0.0", "::", "0:0:0:0:0:0:0:0"], true)) return true;

	/* Normalize decimal- and hex-encoded IPv4 (browser-accepted bypass forms)
	   to a dotted quad so the byte-range checks below cover them. */
	$normalized = $host;
	if (ctype_digit($host)) {
		$n = (int)$host;
		$normalized = sprintf("%d.%d.%d.%d", ($n >> 24) & 0xFF, ($n >> 16) & 0xFF, ($n >> 8) & 0xFF, $n & 0xFF);
	} elseif (preg_match('/^0x[0-9a-f]{1,8}$/i', $host)) {
		$n = (int)hexdec(substr($host, 2));
		$normalized = sprintf("%d.%d.%d.%d", ($n >> 24) & 0xFF, ($n >> 16) & 0xFF, ($n >> 8) & 0xFF, $n & 0xFF);
	}

	if (filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
		$parts = explode(".", $normalized);
		$b = [(int)$parts[0], (int)$parts[1], (int)$parts[2], (int)$parts[3]];
		if ($b[0] === 0)   return true;                                  // 0.0.0.0/8
		if ($b[0] === 10)  return true;                                  // 10.0.0.0/8
		if ($b[0] === 127) return true;                                  // 127.0.0.0/8 loopback
		if ($b[0] === 169 && $b[1] === 254) return true;                 // 169.254.0.0/16 link-local
		if ($b[0] === 172 && $b[1] >= 16 && $b[1] <= 31) return true;    // 172.16.0.0/12
		if ($b[0] === 192 && $b[1] === 168) return true;                 // 192.168.0.0/16
		return false;
	}

	if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
		$packed = @inet_pton($host);
		if ($packed === false || strlen($packed) !== 16) return true;
		if ($packed === inet_pton("::1")) return true;                   // loopback
		if ($packed === inet_pton("::"))  return true;                   // unspecified
		$b0 = ord($packed[0]); $b1 = ord($packed[1]);
		if (($b0 & 0xFE) === 0xFC) return true;                          // fc00::/7 ULA
		if ($b0 === 0xFE && ($b1 & 0xC0) === 0x80) return true;          // fe80::/10 link-local
		/* IPv4-mapped (::ffff:0:0/96): pull out the embedded v4 and recurse. */
		if (substr($packed, 0, 10) === str_repeat("\x00", 10) && substr($packed, 10, 2) === "\xFF\xFF") {
			$v4 = sprintf("%d.%d.%d.%d", ord($packed[12]), ord($packed[13]), ord($packed[14]), ord($packed[15]));
			return caIsPrivateOrLoopbackHost($v4);
		}
		return false;
	}

	/* mDNS (.local) and explicit "internal" pseudo-TLDs resolve to LAN hosts
	   without any DNS lookup — same threat surface as RFC1918 IPs above.
	   `(^|\.)name$` anchors so both `foo.local` and the bare `local` /
	   `internal` / etc. hostnames are blocked — a typed `http://internal/`
	   would otherwise reach a hosts-file or search-domain alias. */
	if (preg_match('/(^|\.)local$/', $host)) return true;
	if (preg_match('/(^|\.)(internal|intranet|lan|home|corp|private)$/', $host)) return true;

	/* Domain name — without a DNS lookup we can't know where it points, so
	   accept it. DNS-rebinding-style attacks are a different threat model. */
	return false;
}

/**
 * Build a stable on-disk cache filename for a downloaded source URL.
 *
 * GitHub-hosted URLs (github.com, raw.githubusercontent.com,
 * objects.githubusercontent.com, codeload.github.com) get an "owner-repo-suffix"
 * shape so the .plg, template XML, and README for one repo land next to each
 * other on disk and the dev can eyeball what's cached. Non-GitHub URLs fall
 * back to a hash prefix so different hosts can't collide on the same suffix.
 *
 * `$suffixOverride` lets callers fix the trailing segment (eg. "readme.md" for
 * fallback `main`/`master` README URLs that would otherwise both cache as
 * `README.md` from the basename and step on each other). Everything is
 * normalized to a filename-safe charset — no slashes, no traversal.
 *
 * @param  string  $url
 * @param  string  $suffixOverride Optional fixed trailing segment; falls back to URL basename
 * @return string  Cache filename, or "" if the URL couldn't be parsed
 */
function caCacheKeyForUrl(string $url, string $suffixOverride = ""): string {
	$parts = @parse_url($url);
	if (!is_array($parts)) return "";
	$host = strtolower((string)($parts['host'] ?? ""));
	$path = (string)($parts['path'] ?? "");
	$query = (string)($parts['query'] ?? "");
	$segments = array_values(array_filter(explode("/", trim($path, "/"))));

	$clean = static function (string $s): string {
		$out = preg_replace('/[^A-Za-z0-9._-]/', '_', $s);
		return is_string($out) ? $out : "";
	};

	$suffix = $suffixOverride !== "" ? $suffixOverride : (basename($path) ?: "data");
	$suffix = $clean($suffix);
	if ($suffix === "" || $suffix === "." || $suffix === "..") $suffix = "data";

	$githubHosts = [
		"github.com", "www.github.com",
		"raw.githubusercontent.com", "www.raw.githubusercontent.com",
		"objects.githubusercontent.com", "codeload.github.com",
	];
	if (in_array($host, $githubHosts, true) && count($segments) >= 2) {
		$owner = $clean($segments[0]);
		$repo  = $clean($segments[1]);
		if ($owner !== "" && $repo !== "") {
			/* Include the query string when present so URLs that differ only by
			   `?v=…` / `?ref=…` etc. don't collide on the same cache file.
			   Hashed (not appended raw) so the resulting filename stays bounded
			   and filename-safe regardless of query length. */
			$qHash = $query !== "" ? "-" . substr(hash("sha256", $query), 0, 8) : "";
			return $owner . "-" . $repo . $qHash . "-" . $suffix;
		}
	}

	return substr(hash("sha256", $host . $path . "?" . $query), 0, 16) . "-" . $suffix;
}

/**
 * Cached wrapper for the sidebar's source-URL downloads (README, .plg, template
 * XML, and the dev-mode Plugin/Template modal). First call for a given URL
 * fetches over download_url() and writes the body to
 * CA_PATHS['templates-community']/$cacheName; later calls — from any code path
 * that asks for the same URL — short-circuit on the on-disk copy.
 *
 * Uses download_url() rather than a custom curl so corp-proxy users
 * (proxy.cfg) and the existing per-URL flock all keep working — concurrent
 * tabs racing the same .plg dedupe through download_url's lock and only one
 * actually hits the network. The 30s timeout caps stalled fetches; default
 * is libcurl-unbounded which we don't want on the sidebar's lazy-load path.
 *
 * Cache directory is wiped wholesale by DownloadApplicationFeed() (the
 * `rm -rf $tempFiles` step on appfeed refresh), so no explicit TTL or
 * invalidation is needed — stale-data risk caps at one feed refresh interval.
 *
 * @param  string  $url        Absolute https URL
 * @param  string  $cacheName  Result of caCacheKeyForUrl()
 * @return string Cached or freshly-fetched body, "" on failure
 */
function caFetchCachedSource(string $url, string $cacheName): string {
	if ($url === "" || $cacheName === "") return "";
	/* Defense in depth — caCacheKeyForUrl already sanitizes, but reject any
	   cacheName that could escape templates-community before touching the FS. */
	if (strpbrk($cacheName, "/\\") !== false || strpos($cacheName, "..") !== false) return "";

	$dir  = CA_PATHS['templates-community'];
	$path = $dir . "/" . $cacheName;

	if (is_file($path)) {
		$cached = @file_get_contents($path);
		if (is_string($cached) && $cached !== "") return $cached;
	}

	@mkdir($dir, 0777, true);
	$content = download_url($url, $path, 30);
	if (!is_string($content) || $content === "" || trim($content) === "") {
		/* download_url() already cleans up $path on failure (helpers.php
		   download_url unlinks when $out === false). Belt-and-suspenders. */
		@unlink($path);
		return "";
	}
	return $content;
}

/**
 * Stricter sibling of validURL: requires http(s) and a publicly routable host.
 *
 * Used in README/changelog sanitization where any pointer at a LAN host is
 * considered hostile.
 *
 * @param  string  $url
 * @return bool
 */
function caIsPublicHttpUrl(string $url): bool {
	if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
	if (!preg_match('/^https?:\/\//i', $url)) return false;
	$host = (string)parse_url($url, PHP_URL_HOST);
	if ($host === "") return false;
	return !caIsPrivateOrLoopbackHost($host);
}

/**
 * Test whether a filter string matches one or more candidate strings.
 *
 * In exact mode every filter word must match at least one candidate; in
 * non-exact mode any word match returns true.
 *
 * @param  string             $filter       Space-separated search terms.
 * @param  array<int,?string> $searchArray  Candidate strings.
 * @param  bool               $exact        When true, all words must match.
 * @return bool
 */
function filterMatch($filter,$searchArray,$exact=true) {
	$filterwords = explode(" ",$filter);
	$foundword = null;
	foreach ( $filterwords as $testfilter) {
		if ( ! trim($testfilter) ) continue;
		foreach ($searchArray as $search) {
			if ( ! $search ) continue;
			if ( stripos($search,$testfilter) !== false ) {
				$foundword++;
				break;
			}
		}
	}
	return $exact ? ($foundword == count($filterwords)) : ($foundword > 0);
}
/**
 * Compute and persist the set of plugin .plg filenames that occur more than once.
 *
 * Walks $GLOBALS['templates'] looking for plugin templates whose Repository
 * basename collides, and writes the dupe list to CA_PATHS['pluginDupes'] for
 * the Moderation view to surface.
 *
 * @return void
 */
function pluginDupe() {

	$pluginList = [];
	$dupeList = [];
	foreach ($GLOBALS['templates'] as $template) {
		if ( ($template['Plugin']??null) ) {
			if ( ! isset($pluginList[basename($template['Repository'])]) )
				$pluginList[basename($template['Repository'])] = 0;
			$pluginList[basename($template['Repository'])]++;
		}
	}
	foreach (array_keys($pluginList) as $plugin) {
		if ( $pluginList[$plugin] > 1 )
			$dupeList[$plugin] = 1;
	}
	writeJsonFile(CA_PATHS['pluginDupes'],$dupeList);
}
/**
 * Determine whether a plugin template's .plg file is installed and matches its PluginURL.
 *
 * @param  array<string,mixed>  $template
 * @return bool
 */
function checkInstalledPlugin($template) {

	$pluginName = basename($template['PluginURL']);
	if ( ! file_exists("/var/log/plugins/$pluginName") ) return false;

	if ( isset($template['hideFromCA']) ) return false;
	return strtolower(trim(ca_plugin("pluginURL","/var/log/plugins/$pluginName"))) == strtolower(trim($template['PluginURL']));
}

/**
 * Strip every non-alphanumeric character from $string.
 *
 * @param  string  $string
 * @return string
 */
function alphaNumeric($string) {
	/* Cast through `(string)` so a null caller doesn't trip the PHP 8.1+
	   deprecation `Passing null to parameter #3 ($subject) of type
	   array|string is deprecated`. */
	return preg_replace("/[^a-zA-Z0-9]+/", "", (string)($string ?? ""));
}

/**
 * Resolve the displayed author for a template (plugin or container).
 *
 * Plugin templates use PluginAuthor; otherwise the namespace part of the
 * Repository is extracted (after stripping common registry prefixes).
 *
 * @param  array<string,mixed>  $template
 * @return string
 */
function getAuthor($template) {
	/* Plugin templates carry the author in PluginAuthor, but some legacy
	   feed entries omit the field — return empty rather than emitting an
	   undefined-index warning on every popup render. */
	if ( isset($template['PluginURL']) ) return (string)($template['PluginAuthor'] ?? "");

	if ( isset($template['Author']) ) return strip_tags($template['Author']);
	$template['Repository'] = str_replace(["lscr.io/","ghcr.io/","registry.hub.docker.com/","library/"],"",$template['Repository']);
	$repoEntry = explode("/",$template['Repository']);
	if (count($repoEntry) < 2)
		$repoEntry[] = "";

	return strip_tags(explode(":",$repoEntry[count($repoEntry)-2])[0]);
}
/**
 * Format a template's category string for display (translated, truncated to 2 items by default).
 *
 * @param  string  $cat     Comma/colon/space-separated raw categories.
 * @param  bool    $popUp   When true, show the entire list; otherwise top-2 plus "and N more".
 * @return string
 */
function categoryList($cat,$popUp = false) {
	$cat = str_replace([":,",": "," "],",",$cat);
	$cat = rtrim($cat,": ");
	$all_cat = explode(",",$cat);
	foreach ($all_cat as $trcat)
		$all_categories[] = tr($trcat);

	$categoryList = $popUp ? $all_categories : array_slice($all_categories,0,2);

	if ( count($all_categories) > count($categoryList) ) {
		$excess = count($all_categories) - count($categoryList);
		$categoryList[] = " ".sprintf(tr("and %s more"),$excess);
	}
	return rtrim(implode(", ",$categoryList),", ");
}
/**
 * Truncate a comma-separated language author list to the first two entries plus "and N more".
 *
 * @param  string  $authors
 * @return string
 */
function languageAuthorList($authors) {
	$newAuthor = "";
	$allAuthors = explode(",",$authors);
	if ( count($allAuthors) > 3 ) {
		$newAuthors = array_slice($allAuthors,0,2);
		foreach ($newAuthors as $author) {
			$newAuthor .= trim($author).", ";
		}
		$excess = count($allAuthors) -2;
		$authors = rtrim($newAuthor,", ")." ".sprintf(tr("and %s more"),$excess);
	}
	return $authors;
}
/**
 * Translate a precise download count into a coarse "More than 1,000" display string.
 *
 * Returns "" for tiny counts unless $lowFlag is true, in which case the raw
 * count is returned verbatim.
 *
 * @param  int|float|string  $downloads
 * @param  bool              $lowFlag
 * @return string
 */
function getDownloads($downloads,$lowFlag=false) {
	$downloadCount = ["10000000000","5000000000","1000000000","500000000","100000000","50000000","25000000","10000000","5000000","2500000","1000000","500000","250000","100000","50000","25000","10000","5000","1000","500","100"];
	foreach ($downloadCount as $downloadtmp) {
		if ($downloads > $downloadtmp) {
			return sprintf(tr("More than %s"),number_format($downloadtmp));
		}
	}
	return ($lowFlag) ? $downloads : "";
}
/**
 * Stop a running Docker container by ID via the global DockerClient.
 *
 * @param  string  $id
 * @return void
 */
function myStopContainer($id) {
	global $DockerClient;

	$DockerClient->stopContainer($id);
}
/**
 * Sanitize a template's [br]/[b]/<span> markup down to plain text plus minimal HTML.
 *
 * Pre-processes [br]/[b] codes and HTML entities, strips remaining tags, and
 * returns the trimmed result. Used to clean Previously Installed descriptions.
 *
 * @param  string|mixed  $Description
 * @return string
 */
function fixDescription($Description) {
	if ( !is_string($Description) ) {
		return "";
	}

	$patterns = [
		"#\[br\s*\]#i"   => "{}",
		"#\[b[\\\]*\s*\]#i" => "||",
		'#\[([^\]]*)\]#' => '<$1>',
		"#<span[^>]*>#si" => "",
		"#</span>#si"     => "",
		"#<[^>]*>#i"     => "",
	];

	$Description = preg_replace(
		array_keys($patterns),
		array_values($patterns),
		$Description
	);

	if ( $Description === null ) {
		return "";
	}

	$Description = strtr($Description, [
		"{}"    => "<br>",
		"||"    => "<b>",
		"&lt;"  => "<",
		"&gt;"  => ">",
	]);

	return trim(strip_tags($Description));
}
/**
 * Render the branch-tag <table> rows for the tag picker dialog.
 *
 * Builds a row per branch from $GLOBALS['templates'][$leadTemplate]['BranchID'],
 * HTML-escaping every user-controlled fragment.
 *
 * @param  int|string  $leadTemplate  Parent template ID.
 * @param  string      $rename        "true" -> use the rename install path.
 * @return string HTML <table> fragment.
 */
function formatTags($leadTemplate,$rename="false") {

	$templates = &$GLOBALS['templates'];
	if ( ! isset($templates[$leadTemplate]) ) {
		return tr("Something really went wrong here");
	}

	$template = $templates[$leadTemplate];
	$childTemplates = $template['BranchID'] ?? null;

	if ( ! is_array($childTemplates) ) {
		return tr("Something really went wrong here");
	}

	$type = $rename === "true" ? "second" : "default";
	$branchDefault = $template['BranchDefault'] ?? null;
	$defaultTag = $branchDefault ? $branchDefault : "latest";

	/* Defense-in-depth: escape path and label on emit so a stray quote in feed
	   data can't break the data-xml attribute or inject markup into the cell.
	   $description is treated as pre-built HTML — caller is responsible for
	   escaping any user-controlled fragments inside it (the only such fragment
	   today is the default-tag span built with $safeDefaultTag below). */
	$buildRow = function($path, $label, $descriptionHtml) use ($type) {
		$safePath = htmlspecialchars((string)$path, ENT_QUOTES, 'UTF-8');
		$safeLabel = htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8');
		return "<tr>"
			. "<td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</td>"
			. "<td><span class='xmlInstall ca_normal' role='button' tabindex='0' data-type='$type' data-xml='$safePath'>$safeLabel</span></td>"
			. "<td><span class='xmlInstall ca_normal' role='button' tabindex='0' data-type='$type' data-xml='$safePath'>$descriptionHtml</span></td>"
			. "</tr>";
	};

	$rows = [];
	$safeDefaultTag = htmlspecialchars((string)$defaultTag, ENT_QUOTES, 'UTF-8');
	$rows[] = $buildRow(
		$template['Path'],
		"Default",
		tr("Install Using The Template's Default Tag") . " (<span class='ca_bold'>:$safeDefaultTag</span>)"
	);

	$defaultTagDescription = $template['DefaultTagDescription'] ?? null;
	if ( $defaultTagDescription && ! is_array($defaultTagDescription) ) {
		$safeDesc = htmlspecialchars((string)$defaultTagDescription, ENT_QUOTES, 'UTF-8');
		$rows[] = "<tr><td></td><td></td><td>$safeDesc</td></tr>";
	}

	foreach ($childTemplates as $child) {
		if ( ! isset($templates[$child]) ) {
			continue;
		}

		$childTemplate = $templates[$child];
		$rows[] = $buildRow(
			$childTemplate['Path'] ?? "",
			$childTemplate['BranchName'] ?? "",
			htmlspecialchars((string)($childTemplate['BranchDescription'] ?? ""), ENT_QUOTES, 'UTF-8')
		);
	}

	return "<table class='caBranchChooser'>" . implode("", $rows) . "</table>";
}
/**
 * Echo the POST-response payload as JSON (or raw string) and flush.
 *
 * For arrays, sets the Content-Type header and merges $GLOBALS['script'] into
 * the payload. For non-arrays the value is echoed verbatim. Always flushes.
 *
 * @param  array<string,mixed>|string  $retArray
 * @return void
 */
function postReturn($retArray) {
	if (is_array($retArray)) {
		if ( isset($GLOBALS['script']) )
			$retArray['globalScript'] = $GLOBALS['script'];

		ob_start();
		ob_clean();
		header_remove();
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode($retArray);
	}	else
		echo $retArray;
	flush();
	debug("POST RETURN ({$_POST['action']})");
	debug("POST RETURN Memory Usage:".round(memory_get_usage()/1048576,2)." MB");
}
####################################
# Translation backwards compatible #
####################################
if ( ! function_exists("tr") ) {
	/**
	 * Backwards-compatible translation wrapper.
	 *
	 * Calls gettext-style _() and escapes embedded quotes for HTML-attribute
	 * safety. Falls back to the original string when no translation exists.
	 *
	 * @param  string  $string
	 * @param  int     $options  Passed through to the underlying translator.
	 * @return string
	 */
	function tr($string,$options=-1) {
		$translated = _($string,$options);
		if ( ! trim($translated) )
			$translated = $string;

		if ( $translated == $string )
			return $string;

		$translated =  str_replace(['"',"'"],["&#34;","&#39;"],$translated);

		return $translated;
	}
}
/**
 * Determine whether an installed language pack has an update available.
 *
 * Compares the installed lang-<code>.xml Version against the feed template's
 * Version and against any /tmp/plugins/ stage file.
 *
 * @param  array<string,mixed>  $template
 * @return bool
 */
function languageCheck($template) {

	if ( ! $template['LanguageURL'] ) return false;

	$countryCode = $template['LanguagePack'];
	$installedLanguage = CA_PATHS['installedLanguages']."/lang-$countryCode.xml";
	$dynamixUpdate = CA_PATHS['dynamixUpdates']."/lang-$countryCode.xml";
	if ( ! is_file($installedLanguage) )
		return false;

	$OSupdates = readXmlFile($dynamixUpdate,true);   // Because the OS might check for an update before the feed
	if ( ! $OSupdates ) {
		$OSupdates = [];
		$OSupdates['Version'] = "1900.01.01";
	}

	$xmlFile = readXmlFile($installedLanguage,true);

	if ( !$xmlFile['Version'] ) return false;
	return (strcmp($template['Version'],$xmlFile['Version']) > 0) || (strcmp($OSupdates['Version'],$xmlFile['Version']) > 0);
}
/**
 * Serialize an associative array (with optional one-level sections) to INI on disk.
 *
 * Values are written wrapped in double quotes. Sections are emitted as
 * [section] headers. Uses ca_file_put_contents() with LOCK_EX.
 *
 * @param  string                                      $file
 * @param  array<string,string|array<string,string>>   $array
 * @return void
 */
function write_ini_file($file,$array) {
	$res = [];
	foreach($array as $key => $val) {
		if(is_array($val)) {
			$res[] = "[$key]";
			foreach($val as $skey => $sval)
				$res[] = $skey.'="'.$sval.'"';
		}
		else
			$res[] = $key.'="'.$val.'"';
	}
	ca_file_put_contents($file,implode("\r\n", $res),LOCK_EX);
}
/**
 * Read (and cache) the list of currently installed containers with their template info.
 *
 * On first call (or when $force is true) re-queries DockerTemplates +
 * DockerClient and persists to CA_PATHS['info']. Subsequent calls return the
 * cached JSON.
 *
 * @param  bool  $force  Bypass the on-disk cache and re-query.
 * @return array<int,array<string,mixed>>
 */
/**
 * Delete the on-disk getAllInfo() cache file. Call at every point where the
 * container fleet (or any field getAllInfo merges in — running state, url,
 * tailscale url, template path) is about to change or is suspected to have
 * changed: install / uninstall / update, edit, language switch, returning
 * from a CA child page, save/restore-state, init paths without a feed update,
 * etc. The next getAllInfo() call rebuilds from live state.
 *
 * @return void
 */
function caDropInfoCache(): void {
	@unlink(CA_PATHS['info']);
}

function getAllInfo($force=false) {
	global $DockerTemplates, $DockerClient;

	$containers = readJsonFile(CA_PATHS['info']);

	if ( $force || ! $containers || empty($containers) ) {
		if ( caIsDockerRunning() ) {
			$info = $DockerTemplates->getAllInfo(false,true,true);
			$containers = $DockerClient->getDockerContainers();
			foreach ($containers as &$container) {
				$container['running'] = $info[$container['Name']]['running'] ?? null;
				$container['url'] = $info[$container['Name']]['url'] ?? null;
				$container['TSurl'] = $info[$container['Name']]['TSurl'] ?? null;
				$container['template'] = $info[$container['Name']]['template'] ?? null;
			}
		}
		debug("Forced info update");
		writeJsonFile(CA_PATHS['info'],$containers);
	} else {
		debug("Cached info update");
	}
	return $containers;
}

/**
 * Append a timestamped debug line to the CA log file.
 *
 * Just creates the log dir/file if missing and appends the line. The
 * environment metadata header lives in its own file now (see caWriteDebugInfo),
 * which the debugging zip bundles alongside the log.
 *
 * @param  string  $str
 * @return void
 */
function debug($str) {

	if ( ! is_file(CA_PATHS['logging']) ) {
		@mkdir(CA_PATHS['CA_logs']);
		touch(CA_PATHS['logging']);
	}
	@file_put_contents(CA_PATHS['logging'],date('Y-m-d H:i:s')."  $str\n",FILE_APPEND); //don't run through CA wrapper as this is non-critical
}

/**
 * Write the CA environment snapshot (version, Unraid version, ca.md5 check,
 * locale, settings, php-error flag) to its own file (CA_PATHS['caInfo']).
 *
 * Called when the debugging zip is built so the snapshot is always current and
 * the running log stays clean of the boilerplate header.
 *
 * @return void
 */
function caWriteDebugInfo() {
	if ( ! isset($GLOBALS['caSettings']) )
		getSettings();

	@mkdir(CA_PATHS['CA_logs']);

	$caVersion = ca_plugin("version","/var/log/plugins/community.applications.plg");
	$lingo     = $_SESSION['locale'] ?? "en_US";
	$phpErrors = @parse_ini_file(CA_PATHS['phpErrorSettings']);

	$info  = "Community Applications Version: $caVersion\n";
	$info .= "Unraid version: {$GLOBALS['caSettings']['unRaidVersion']}\n";
	$info .= "MD5's: \n".shell_exec("cd /usr/local/emhttp/plugins/community.applications && md5sum -c ca.md5")."\n";
	$info .= "Language: $lingo\n";
	$info .= "Settings:\n".print_r($GLOBALS['caSettings'],true)."\n";
	if (boolval($phpErrors['display_errors']??false))
		$info .= "PHP errors set to be displayed!\n";

	ca_file_put_contents(CA_PATHS['caInfo'],$info);
}

/**
 * Normalize a port-config Mode (or a docker Ports Type) to a transport protocol.
 *
 * Returns "udp" only for an explicit udp, otherwise "tcp". Host-port conflicts
 * are per protocol, so tcp/8080 and udp/8080 are distinct bindings.
 *
 * @param  mixed $mode
 * @return string  "tcp" or "udp"
 */
function caPortProto($mode): string {
	return strtolower(trim((string)$mode)) === "udp" ? "udp" : "tcp";
}

/**
 * Build a lookup of taken host bindings keyed as "port/proto".
 *
 * Accepts a list whose entries are already "port/proto" (from getPortsInUse /
 * getStoppedBridgePorts) or bare port numbers (defaulted to tcp).
 *
 * @param  array<int,int|string> $portsInUse
 * @return array<string,bool>
 */
function caBuildTakenPorts(array $portsInUse): array {
	$taken = [];
	foreach ($portsInUse as $p) {
		$p = (string)$p;
		if ($p === "") continue;
		if (strpos($p, "/") === false) $p = ((int)$p)."/tcp";
		$taken[$p] = true;
	}
	return $taken;
}

/**
 * Bump any host port in a bridge-network template that's already in use to a free port.
 *
 * Edits $template in place. Treats $portsInUse (and any port assigned earlier
 * within this same template) as "taken", per protocol; searches for a free port
 * (wrapping past 65535 to 1) or gives up after the full range. No-op for
 * non-bridge networks since host ports don't apply there.
 *
 * @param  array<string,mixed>     $template     Modified by reference.
 * @param  array<int,int|string>   $portsInUse   Bindings already taken ("port/proto" or bare port).
 * @return array<int,array{from:int,to:int,label:string}>  Each host port that was remapped, old -> new.
 */
function adjustTemplatePorts(array &$template, array $portsInUse): array {
	$changes = [];
	if (!isset($template['Config'])) return $changes;
	if (($template['Network'] ?? "") !== "bridge") return $changes;

	if (isset($template['Config']['@attributes'])) {
		$template['Config'] = ['@attributes' => $template['Config']];
	}
	if (!is_array($template['Config'])) return $changes;

	$taken = caBuildTakenPorts($portsInUse);

	foreach ($template['Config'] as &$config) {
		if (!is_array($config) || ($config['@attributes']['Type'] ?? null) !== 'Port') continue;
		$current = (int)($config['value'] ?: ($config['@attributes']['Default'] ?? 0));
		if ($current <= 0 || $current > 65535) continue;
		$proto = caPortProto($config['@attributes']['Mode'] ?? "");
		if (!isset($taken[$current."/".$proto])) {
			$taken[$current."/".$proto] = true;
			continue;
		}
		// Conflict for this protocol: find the next free host port, wrapping past
		// 65535 back to 1 so a busy upper range still resolves before giving up.
		$candidate = $current;
		$found = false;
		for ($i = 0; $i < 65535; $i++) {
			$candidate = ($candidate % 65535) + 1;
			if (!isset($taken[$candidate."/".$proto])) { $found = true; break; }
		}
		if (!$found) continue;
		$taken[$candidate."/".$proto] = true;
		$config['value'] = (string)$candidate;
		// Carry the port's human label so the notice can name what moved. Prefer
		// the config Description, then its Name, then the container Target.
		$label = $config['@attributes']['Description']
			?: ($config['@attributes']['Name'] ?? "")
			?: ($config['@attributes']['Target'] ?? "");
		$changes[] = ['from' => $current, 'to' => $candidate, 'label' => (string)$label];
	}
	unset($config);
	return $changes;
}

/**
 * Detect (read-only) host ports in a bridge template that are already taken.
 *
 * Mirrors adjustTemplatePorts' matching but changes nothing - used on the
 * reinstall path, where we warn about conflicts but must not rewrite the user's
 * saved template. No-op for non-bridge networks.
 *
 * @param  array<string,mixed>     $template
 * @param  array<int,int|string>   $portsInUse
 * @return array<int,array{port:int,label:string}>  Conflicting ports + label.
 */
function findTemplatePortConflicts(array $template, array $portsInUse): array {
	$conflicts = [];
	if (!isset($template['Config'])) return $conflicts;
	if (($template['Network'] ?? "") !== "bridge") return $conflicts;

	if (isset($template['Config']['@attributes'])) {
		$template['Config'] = ['@attributes' => $template['Config']];
	}
	if (!is_array($template['Config'])) return $conflicts;

	$taken = caBuildTakenPorts($portsInUse);

	foreach ($template['Config'] as $config) {
		if (!is_array($config) || ($config['@attributes']['Type'] ?? null) !== 'Port') continue;
		$current = (int)($config['value'] ?: ($config['@attributes']['Default'] ?? 0));
		if ($current <= 0 || $current > 65535) continue;
		$proto = caPortProto($config['@attributes']['Mode'] ?? "");
		if (!isset($taken[$current."/".$proto])) continue;
		$label = $config['@attributes']['Description']
			?: ($config['@attributes']['Name'] ?? "")
			?: ($config['@attributes']['Target'] ?? "");
		$conflicts[] = ['port' => $current, 'label' => (string)$label];
	}
	return $conflicts;
}

/**
 * Return all host bindings a bridge template would publish, as "port/proto".
 *
 * Used by the multi-install batch check to reserve an accepted app's ports so
 * later apps in the same batch are tested against it. Empty for non-bridge.
 *
 * @param  array<string,mixed> $template
 * @return array<int,string>
 */
function getTemplateBridgePorts(array $template): array {
	$ports = [];
	if (!isset($template['Config'])) return $ports;
	if (($template['Network'] ?? "") !== "bridge") return $ports;

	if (isset($template['Config']['@attributes'])) {
		$template['Config'] = ['@attributes' => $template['Config']];
	}
	if (!is_array($template['Config'])) return $ports;

	foreach ($template['Config'] as $config) {
		if (!is_array($config) || ($config['@attributes']['Type'] ?? null) !== 'Port') continue;
		$p = (int)($config['value'] ?: ($config['@attributes']['Default'] ?? 0));
		if ($p > 0 && $p <= 65535) $ports[] = $p."/".caPortProto($config['@attributes']['Mode'] ?? "");
	}
	return $ports;
}

/**
 * Host ports reserved by installed-but-stopped bridge containers.
 *
 * getPortsInUse() only sees live listeners (lsof), so a container that's
 * installed but not currently running won't show up - yet its mapped host port
 * would collide the moment it starts. Pull those PublicPorts from the docker
 * info list (getAllInfo()) so port auto-adjust steers clear of them too. Only
 * bridge-network containers publish host ports.
 *
 * @param  array<int,array<string,mixed>> $allInfo  Output of getAllInfo().
 * @return array<int,string|int>
 */
function getStoppedBridgePorts(array $allInfo): array {
	$ports = [];
	foreach ($allInfo as $container) {
		if ($container['Running'] ?? false) continue;
		if (strtolower((string)($container['NetworkMode'] ?? "")) !== "bridge") continue;
		$containerPorts = $container['Ports'] ?? [];
		if (!is_array($containerPorts)) continue;
		foreach ($containerPorts as $portInfo) {
			if (!is_array($portInfo)) continue;
			$pub = $portInfo['PublicPort'] ?? null;
			if ($pub !== null && $pub !== "") $ports[] = ((int)$pub)."/".caPortProto($portInfo['Type'] ?? "");
		}
	}
	return $ports;
}

/**
 * Return the list of TCP/UDP ports currently in LISTEN state on routable interfaces.
 *
 * Shells out to `lsof -Pni`, filters out 127.0.0.1 / [::1], and (when the
 * server is configured for management bind) restricts to the configured IPs.
 *
 * @return array<int,string|int>
 */
function getPortsInUse() {
	global $var;

	$addr = null;
	if ( !$var )
		$var = parse_ini_file(CA_PATHS['unRaidVars']);

	$portsInUse = [];
	exec("lsof -Pni|awk '/LISTEN/ && \$9!~/127.0.0.1/ && \$9!~/\\[::1\\]/{print \$9}'|sort -u", $output);

	$bind = $var['BIND_MGT']=='yes';
	$list = is_array($addr) ? array_merge(['*'],$addr) : ['*',$addr];

	foreach ($output as $line) {
		[$ip, $port] = ca_explode(':', $line);
		if ( ! is_numeric($port) ) continue;
		if ( $bind && ! in_array(plain($ip),$list) ) continue;
		// lsof LISTEN sockets are TCP; tag the protocol so tcp/udp pairs on the
		// same host port are treated as distinct bindings downstream.
		$key = ((int)$port)."/tcp";
		if ( ! in_array($key,$portsInUse) ) $portsInUse[] = $key;
	}

	return $portsInUse;
}

/**
 * explode() padded to $count parts (missing segments become empty strings).
 *
 * @param string $split
 * @param string $text
 * @param int $count
 * @return array{0?:string,1?:string}
 */
function ca_explode($split,$text,$count=2) {
	return array_pad(explode($split,$text,$count),$count,'');
}

/**
 * Strip IPv6 brackets from address strings for comparisons.
 *
 * @param string $ip
 * @return string
 */
function plain($ip) {
	return str_replace(['[',']'],'',$ip);
}

/**
 * Return true when either the tailscale or tailscale-preview plugin is installed.
 *
 * @return bool
 */
function isTailScaleInstalled() {
	return is_file("/var/log/plugins/tailscale-preview.plg") || is_file("/var/log/plugins/tailscale.plg");
}

/**
 * Heuristic: false if system date is more than ~30 days before the CA plugin release date.
 *
 * Used when diagnosing failed feed downloads (often bad clock).
 *
 * @return bool
 */
function checkServerDate() {
	$currentDate = strtotime(date("Y-m-d"));
	$caVersion = preg_replace("/[^0-9.]/","",plugin("version","/var/log/plugins/community.applications.plg"));
	if ( ! $caVersion )
		return true;
	$caVersion = str_replace(".","-",$caVersion);
	$caVersion = strtotime($caVersion);

	if ( ($caVersion - $currentDate) > 2592000 ) # 30 Days
		return false;
	else
		return true;
}

/**
 * Convert a youtu.be / youtube.com watch URL into the default thumbnail URL.
 *
 * Returns the original URL untouched if neither pattern matches.
 *
 * @param  string  $url
 * @return string
 */
function getYoutubeThumbnail($url) {
	// Handle youtu.be short URLs
	if (preg_match('/https:\/\/youtu\.be\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
		return 'https://img.youtube.com/vi/' . $matches[1] . '/default.jpg';
	}

	// Handle youtube.com/watch?v= URLs
	if (preg_match('/https:\/\/(?:www\.)?youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/', $url, $matches)) {
		return 'https://img.youtube.com/vi/' . $matches[1] . '/default.jpg';
	}

	// Return original URL if no match found
	return $url;
}


/**
 * Fill missing-but-expected keys on a template row with null (for PHP 8 strict array access).
 *
 * Reads from a static $requiredVars list so the cost is paid once per process.
 *
 * @param  array<string,mixed>|mixed  $o
 * @return array<string,mixed>|mixed Unchanged when $o is not an array.
 */
function addMissingVars($o) {
	if (!is_array($o)) {
		return $o;
	}

	// Static array to avoid recreation on each call
	static $requiredVars = [
		'Category', 'CategoryList', 'CABlacklist', 'Blacklist', 'MinVer', 'MaxVer', 'UpdateMinVer',
		'Plugin', 'PluginURL', 'Date', 'Branch', 'OriginalOverview',
		'DateInstalled', 'Config', 'trending', 'CAComment', 'ModeratorComment', 'DeprecatedMaxVer',
		'downloads', 'FirstSeen', 'OriginalDescription', 'Deprecated', 'RecommendedRaw', 'Language',
		'RequiresFile', 'Requires', 'trends', 'Description', 'Overview', 'Repository', 'Tag',
		'CaComment', 'IncompatibleVersion', 'Private', 'BranchName', 'display', 'RepositoryTemplate',
		'bio', 'NoInstall', 'Twitter', 'Discord', 'Reddit', 'Facebook', 'ReadMe',
		'actionCentre', 'SupportLanguage', 'DockerHub', 'Official', 'Removable', 'IconFA',
		'imageNoClick', 'RecommendedDate', 'UpdateAvailable', 'Installed', 'Uninstall',
		'caTemplateExists', 'Support', 'Beta', 'Project', 'Trusted', 'InstallPath', 'LanguagePack',
		'trendDelta', 'RepoTemplate', 'ExtraSearchTerms', 'Icon', 'LanguageDefault',
		'translatedCategories', 'RepoShort', 'LanguageLocal', 'ExtraPriority', 'Registry',
		'caTemplateURL', 'Changes', 'ChangeLogPresent', 'Photo', 'Screenshot', 'Video',
		'RecommendedReason', 'stars', 'LanguageURL', 'LastUpdate', 'RecommendedWho', 'RepoName',
		'SortName', 'ca_fav', 'Pinned'
	];

	// Use array_fill_keys for better performance than foreach loop
	$missingVars = array_diff_key(array_fill_keys($requiredVars, null), $o);

	return $o + $missingVars;
}

/* ============================================================================
   Docker Hub Registry API helpers — fetch image config (ports / volumes / env)
   directly from the v2 registry instead of doing a test `docker pull` + inspect.

   Three-call chain per image (token + manifest + config blob); each tier is
   cached so subsequent installs of the same image hit zero network calls.
   See `convert_docker` in exec.php for the consumer.
   ============================================================================ */

/**
 * Read the user's saved Docker Hub credentials (if any) from
 * `/root/.docker/config.json` and return them as a pre-encoded
 * `base64("user:password")` string suitable for an HTTP Basic header.
 *
 * Mirrors the lookup `dynamix.docker.manager`'s DockerClient::getRegistryAuth()
 * does — Docker stores Hub creds under the legacy `https://index.docker.io/v1/`
 * key inside `auths`. Returns null if the config file is missing, malformed,
 * has no Hub auth entry, or the user is on a credential-helper setup
 * (`credsStore` / `credHelpers`) instead of an inline `auth` blob.
 *
 * Authenticating to the token endpoint with the user's creds gets us a
 * higher Docker Hub pull-rate ceiling (200 manifest pulls / 6h vs the
 * 100/6h anonymous tier) and lets us reach any private repos the user
 * has access to.
 *
 * @return string|null  base64-encoded "user:password" or null.
 */
/**
 * Apply CA's proxy.cfg settings to a curl_setopt_array option bag, if
 * the user has one configured. Mirrors what `download_url()` does — the
 * three Docker Hub helpers below open their own cURL handles so they
 * need the same proxy plumbing or they'd silently bypass it.
 *
 * Honors the `http_proxy` env var as an override (same precedence
 * download_url uses): if env is set, the cfg file is ignored so
 * shell-level routing wins.
 *
 * @param array<int,mixed> &$opts  curl option bag to augment in-place.
 */
function caApplyProxyCfg(array &$opts): void {
	static $proxycfg = null;
	if ($proxycfg === null) {
		$proxycfg = ((!getenv("http_proxy")) && is_file("/boot/config/plugins/community.applications/proxy.cfg"))
			? @parse_ini_file("/boot/config/plugins/community.applications/proxy.cfg")
			: false;
	}
	if (!$proxycfg) return;
	$opts[CURLOPT_PROXY]            = $proxycfg['proxy']           ?? '';
	$opts[CURLOPT_PROXYPORT]        = intval($proxycfg['port']     ?? 0);
	$opts[CURLOPT_HTTPPROXYTUNNEL]  = intval($proxycfg['tunnel']   ?? 0);
}

function caGetDockerHubAuthBlob(): ?string {
	$configPath = '/root/.docker/config.json';
	if (!is_file($configPath)) return null;
	$cfg = json_decode((string)@file_get_contents($configPath), true);
	if (!is_array($cfg)) return null;
	$auth = $cfg['auths']['https://index.docker.io/v1/']['auth'] ?? null;
	if (!is_string($auth) || $auth === '') return null;
	/* Sanity check it's actually `user:password` shape (base64'd) — guards
	   against weird/corrupt config files smuggling junk into our header. */
	$decoded = base64_decode($auth, true);
	if ($decoded === false || strpos($decoded, ':') === false) return null;
	return $auth;
}

/**
 * Cached anonymous-pull token for the Docker Hub registry.
 *
 * Docker Hub's auth service issues scope-bound tokens — one per
 * `repository:{ns}/{repo}:pull` scope. Cached per-scope under tempFiles
 * with the issued expiry so repeated installs from the same repo
 * within the token's lifetime don't burn another auth roundtrip.
 *
 * @param string $repo  e.g. "library/nginx" or "linuxserver/sonarr"
 * @return string|null  Bearer token, or null on auth failure.
 */
function caGetDockerHubToken(string $repo): ?string {
	/* Caching intentionally disabled during dev — testing the auto-config
	   flow needs fresh hits each time. Re-enable by caching to
	   `tempFiles/dockerHubToken-{sha256(repo)}.json` with `expires_at`
	   set from the response's `expires_in` (Docker Hub returns ~5 min).
	   If we re-enable caching, the cache key should also include a hash
	   of the auth blob — a token issued for an authenticated user is NOT
	   interchangeable with one issued anonymously (different rate-limit
	   pools, potentially different scope grants). */
	$url     = 'https://auth.docker.io/token?service=registry.docker.io&scope='.urlencode("repository:$repo:pull");
	$headers = [];
	/* If the user has Docker Hub creds saved (via `docker login` on the
	   server), authenticate the token request with HTTP Basic. Token
	   endpoint accepts the same `user:pass` auth pair the registry
	   `/v2/` endpoints do. Falls back to anonymous when no creds — the
	   server then returns an anon token with the lower rate limit. */
	$authBlob = caGetDockerHubAuthBlob();
	if ($authBlob !== null) {
		$headers[] = 'Authorization: Basic '.$authBlob;
	}
	$opts = [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT        => 20,
		CURLOPT_HTTPHEADER     => $headers,
	];
	caApplyProxyCfg($opts);
	$ch = curl_init($url);
	curl_setopt_array($ch, $opts);
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($code !== 200 || !is_string($body) || $body === '') return null;

	$json = json_decode($body, true);
	if (!is_array($json) || empty($json['token'])) return null;
	return (string)$json['token'];
}

/**
 * Authenticated GET against the Docker Hub v2 registry. Negotiates the
 * Accept header for image manifests + config blobs and decodes JSON.
 *
 * @param string $url
 * @param string $token   Bearer token from caGetDockerHubToken.
 * @param array<int,string> $accept  MIME types to send in Accept.
 * @return array<string,mixed>|null  Decoded JSON body, or null on failure.
 */
function caRegistryGet(string $url, string $token, array $accept): ?array {
	$opts = [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HTTPHEADER     => [
			'Authorization: Bearer '.$token,
			'Accept: '.implode(', ', $accept),
		],
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT        => 30,
	];
	caApplyProxyCfg($opts);
	$ch = curl_init($url);
	curl_setopt_array($ch, $opts);
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($code !== 200 || !is_string($body) || $body === '') return null;
	$json = json_decode($body, true);
	return is_array($json) ? $json : null;
}

/**
 * Fetch and return the OCI image config for a Docker Hub image without
 * pulling any layers. Two-stage: GET the manifest for the requested
 * tag, follow into the platform-specific manifest if it's a multi-arch
 * manifest list, then GET the referenced config blob.
 *
 * The config blob contains:
 *   - config.ExposedPorts — what convert_docker maps to Port entries
 *   - config.Volumes      — declared mount points → Path entries
 *   - config.Env          — default env vars → Variable entries
 *
 * Cached per (repo, tag, arch) in tempFiles for an hour. Image configs
 * are content-addressed (by SHA), so a cached result stays valid until
 * the tag itself gets repointed upstream — the 1h TTL is just a safety
 * cap for that case.
 *
 * @param string  $repo          e.g. "library/nginx", "linuxserver/sonarr"
 * @param string  $tag           defaults to "latest"
 * @param string  $arch          Docker arch name ("amd64", "arm64", "arm/v7").
 *                               Defaults to whatever this Unraid box is.
 * @param string &$resolvedTag   OUT param — receives the tag we ended up
 *                               fetching (same as `$tag` unless the `:latest`
 *                               fallback kicked in). Empty string when the
 *                               function returns null. Used by `convert_docker`
 *                               to write the explicit `repo:tag` back into the
 *                               generated XML so the subsequent `docker pull`
 *                               doesn't 404 against a tag that doesn't exist.
 * @return array<string,mixed>|null  Parsed image config, or null on failure.
 */
function caFetchDockerImageConfig(string $repo, string $tag = 'latest', string $arch = '', string &$resolvedTag = ''): ?array {
	$resolvedTag = '';
	if (strpos($repo, '/') === false) {
		/* Official images live under "library/" on the registry even
		   though the GUI label drops it. */
		$repo = "library/$repo";
	}
	if ($arch === '') {
		$archMap = ['x86_64' => 'amd64', 'aarch64' => 'arm64', 'armv7l' => 'arm/v7'];
		$arch    = $archMap[php_uname('m')] ?? 'amd64';
	}

	/* Caching intentionally disabled during dev — testing the auto-config
	   flow needs fresh hits. Re-enable by writing the parsed config to
	   `tempFiles/dockerImageConfig-{sha256(repo:tag:arch)}.json` and
	   reading it back below if mtime < 1h ago. Config blobs are
	   content-addressed (by digest), so cached results stay valid until
	   the tag itself gets repointed upstream. */
	$token = caGetDockerHubToken($repo);
	if ($token === null) return null;

	$manifestAccept = [
		'application/vnd.docker.distribution.manifest.v2+json',
		'application/vnd.oci.image.manifest.v1+json',
		'application/vnd.docker.distribution.manifest.list.v2+json',
		'application/vnd.oci.image.index.v1+json',
	];

	$manifest = caRegistryGet("https://registry-1.docker.io/v2/$repo/manifests/$tag", $token, $manifestAccept);

	/* `:latest` doesn't exist on every repo — image authors sometimes ship
	   only dated/semver tags (`v1.2.3`, `2024-09-01`, `nightly`, etc.).
	   When the requested tag is `latest` and the manifest fetch fails,
	   fall back to the Hub API's most-recently-updated tag and try that.
	   For any other explicitly-requested tag, treat the failure as final
	   — the caller asked for a specific version, don't substitute one. */
	if (!is_array($manifest) && $tag === 'latest') {
		/* Pass `$arch` so the fallback walks Hub's tag list and stops on
		   the first one that ships the requested architecture, instead
		   of grabbing the globally-freshest tag (which can be a
		   cross-arch / non-linux build and would fail arch validation
		   after a wasted manifest + config blob round-trip). */
		$altTag = caGetMostRecentDockerHubTag($repo, $arch);
		if ($altTag !== null && $altTag !== $tag) {
			$tag      = $altTag;
			$manifest = caRegistryGet("https://registry-1.docker.io/v2/$repo/manifests/$tag", $token, $manifestAccept);
		}
	}
	if (!is_array($manifest)) return null;

	/* Manifest list → follow into the per-arch manifest. The list entries
	   carry a `platform: { architecture, os, variant? }` shape; pick the
	   one matching our arch on linux. */
	if (!empty($manifest['manifests']) && is_array($manifest['manifests'])) {
		$chosen = null;
		foreach ($manifest['manifests'] as $entry) {
			$platform = $entry['platform'] ?? [];
			if (($platform['os'] ?? '') !== 'linux') continue;
			if (($platform['architecture'] ?? '') === $arch) {
				$chosen = $entry;
				break;
			}
		}
		if ($chosen === null || empty($chosen['digest'])) return null;
		$manifest = caRegistryGet("https://registry-1.docker.io/v2/$repo/manifests/".$chosen['digest'], $token, $manifestAccept);
		if (!is_array($manifest)) return null;
	}

	$configDigest = (string)($manifest['config']['digest'] ?? '');
	if ($configDigest === '') return null;

	$config = caRegistryGet(
		"https://registry-1.docker.io/v2/$repo/blobs/$configDigest",
		$token,
		['application/json', 'application/vnd.oci.image.config.v1+json']
	);
	if (!is_array($config)) return null;

	/* For multi-arch images the manifest-list filter above already gates
	   on `platform.architecture`. For single-arch images though the
	   manifest carries no platform info — the architecture lives ONLY
	   in the config blob (`architecture: amd64` top-level field). Verify
	   here so a single-arch i386 / arm64 / etc. image doesn't slip
	   through and seed an Add Container dialog with the wrong arch's
	   ports/env. */
	if (!empty($config['architecture']) && (string)$config['architecture'] !== $arch) {
		return null;
	}

	$resolvedTag = $tag;
	return $config;
}

/**
 * Most-recently-updated tag for a Docker Hub repo, via the Hub web API
 * (NOT the registry — different host, different endpoint, no auth needed
 * for public repos). Used as a fallback when `:latest` doesn't exist
 * upstream.
 *
 * Hub returns tags ordered by `last_updated` descending by default;
 * `page_size=10` is plenty for picking the freshest one and stays under
 * the small-payload bar.
 *
 * @param string $repo  e.g. "linuxserver/sonarr", "library/nginx"
 * @return string|null  Tag name (e.g. "v1.2.3"), or null on lookup failure.
 */
function caGetMostRecentDockerHubTag(string $repo, string $arch = ''): ?string {
	$url  = "https://hub.docker.com/v2/repositories/$repo/tags/?page_size=25&ordering=last_updated";
	$opts = [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT        => 20,
	];
	caApplyProxyCfg($opts);
	$ch = curl_init($url);
	curl_setopt_array($ch, $opts);
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($code !== 200 || !is_string($body) || $body === '') return null;
	$json = json_decode($body, true);
	if (!is_array($json) || empty($json['results']) || !is_array($json['results'])) return null;

	foreach ($json['results'] as $entry) {
		$name = (string)($entry['name'] ?? '');
		if ($name === '') continue;
		/* Without an arch hint, the freshest tag wins (legacy behavior). */
		if ($arch === '') return $name;
		/* With an arch hint, walk the tag's per-image platform breakdown
		   (Docker Hub's tag endpoint already carries this metadata) and
		   keep walking the result list until we find a tag that ships
		   the requested arch on linux. Stops us picking a cross-arch
		   tag and then failing arch validation in caFetchDockerImageConfig
		   after a wasted manifest+config fetch round-trip. */
		$images = is_array($entry['images'] ?? null) ? $entry['images'] : [];
		foreach ($images as $img) {
			if ((string)($img['os'] ?? '') !== 'linux') continue;
			if ((string)($img['architecture'] ?? '') === $arch) return $name;
		}
	}
	return null;
}

/**
 * Translate an OCI image config (from `caFetchDockerImageConfig`) into
 * the Config attribute-bag shape that the dockerMan template XML uses
 * — one entry per declared port / mount point / env var, plus the CA
 * marker variable.
 *
 * Skips Docker's automatically-set env vars (HOST_HOSTNAME, HOST_OS,
 * HOST_CONTAINERNAME, TZ, PATH) — they aren't useful to surface in the
 * Add Container dialog.
 *
 * @param array<string,mixed> $imageConfig  Parsed config blob from the registry.
 * @return array<int,array<string,array<string,string>>>  Suitable for $dockerfile['Config'].
 */
function caBuildXmlConfigFromImageConfig(array $imageConfig): array {
	$cfg    = $imageConfig['config'] ?? [];
	$ports  = is_array($cfg['ExposedPorts'] ?? null) ? $cfg['ExposedPorts'] : [];
	$vols   = is_array($cfg['Volumes']      ?? null) ? $cfg['Volumes']      : [];
	$envArr = is_array($cfg['Env']          ?? null) ? $cfg['Env']          : [];

	$Config       = [];
	$defaultvars  = ['HOST_HOSTNAME', 'HOST_OS', 'HOST_CONTAINERNAME', 'TZ', 'PATH'];

	/* Strip characters that aren't legal in XML 1.0 attribute values
	   (NUL, most control chars). Tab / LF / CR are kept since they're
	   the only control chars XML allows. setAttribute() throws on the
	   forbidden ones — silently dropping them keeps a single bogus byte
	   in upstream image config from blowing up the entire install. Also
	   caps value length to a sane ceiling so a maintainer can't paste a
	   megabyte of JSON into an env var and have us round-trip it. */
	$cleanAttr = static function ($v): string {
		$s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$v);
		return mb_substr($s, 0, 4096);
	};

	$portCount = 1;
	foreach (array_keys($ports) as $port) {
		$pp   = explode('/', (string)$port);
		$num  = $pp[0];
		$mode = strtolower((string)($pp[1] ?? 'tcp'));
		/* Validate strictly — Docker spec says port is 1-65535 integer
		   and mode is tcp/udp. Anything else is garbage from a hostile
		   or malformed image config; skip the entry instead of trying
		   to coerce. */
		if (!preg_match('/^\d{1,5}$/', $num)) continue;
		$portNum = (int)$num;
		if ($portNum < 1 || $portNum > 65535) continue;
		if ($mode !== 'tcp' && $mode !== 'udp') $mode = 'tcp';
		$Config[]['@attributes'] = [
			'Name'        => "Container Port $portCount",
			'Type'        => 'Port',
			'Target'      => (string)$portNum,
			'Default'     => (string)$portNum,
			'Mode'        => $mode,
			'Display'     => 'always',
			'Required'    => 'false',
			'Mask'        => 'false',
			'Description' => "Container Port: $portNum",
		];
		$portCount++;
	}

	$pathCount = 1;
	foreach (array_keys($vols) as $vol) {
		$volStr = $cleanAttr($vol);
		/* Skip non-absolute paths and anything carrying shell or
		   path-traversal metacharacters — Docker volumes are always
		   absolute container-side paths, no shell expansion in scope,
		   so the conservative whitelist is fine here. */
		if ($volStr === '' || $volStr[0] !== '/') continue;
		if (preg_match('/[`$;&|<>"\'\\\\\\s]/', $volStr)) continue;
		if (strpos($volStr, '..') !== false) continue;
		$Config[]['@attributes'] = [
			'Name'        => "Container Path $pathCount",
			'Type'        => 'Path',
			'Target'      => $volStr,
			'Default'     => '',
			'Mode'        => 'rw',
			'Display'     => 'always',
			'Required'    => 'false',
			'Mask'        => 'false',
			'Description' => "Container Path: $volStr",
		];
		$pathCount++;
	}

	$varCount = 1;
	foreach ($envArr as $entry) {
		$entry = (string)$entry;
		$eq    = strpos($entry, '=');
		if ($eq === false) continue;
		$name = substr($entry, 0, $eq);
		$val  = substr($entry, $eq + 1);
		/* POSIX env-var name rule — `[A-Z_a-z][A-Z0-9_a-z]*`. Reject
		   anything else outright: legit images don't ship non-conforming
		   names, and accepting them gives a hostile maintainer free
		   rein to stuff arbitrary content into the dockerMan template.
		   Value (right of `=`) stays arbitrary — bash $-expansion etc.
		   is a legitimate runtime concern inside the container, not a
		   host-side injection here. Just strip control bytes + cap
		   length so the XML attribute is well-formed. */
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) continue;
		if (in_array($name, $defaultvars, true)) continue;
		$Config[]['@attributes'] = [
			'Name'        => "Container Variable $varCount",
			'Target'      => $name,
			'Type'        => 'Variable',
			'Default'     => $cleanAttr($val),
			'Description' => "Container Variable: $name",
			'Required'    => 'false',
			'Mask'        => 'false',
			'Display'     => 'always',
		];
		$varCount++;
	}

	/* CA marker — same one the test-install path used to drop on the
	   template so it shows up in Action Centre as a CA-converted entry. */
	$Config[]['@attributes'] = [
		'Name'        => 'Community Applications Conversion',
		'Target'      => 'Community_Applications_Conversion',
		'Type'        => 'Variable',
		'Default'     => 'true',
		'Description' => '',
		'Required'    => 'false',
		'Mask'        => 'false',
		'Display'     => 'always',
	];

	return $Config;
}

/**
 * Lazy-load the bundled XML <-> array libraries (TypeConverter / Array2XML /
 * XML2Array). spl_autoload_register only fires when one of these class names
 * is first referenced, so the ~800-line third-party block lives in its own
 * file and stays out of every PHP request that doesn't touch XML conversion.
 */
spl_autoload_register(static function ($class) {
	if ($class === "TypeConverter" || $class === "Array2XML" || $class === "XML2Array") {
		require_once __DIR__ . "/xml_libs.php";
	}
});

?>