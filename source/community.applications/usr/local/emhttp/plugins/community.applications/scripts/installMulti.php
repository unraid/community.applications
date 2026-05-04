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
?>
<style>
.logLine{color:black !important;font-size:12px !important;}
body{font-size:12px !important;}
</style>
<?
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: "/usr/local/emhttp";

$_SERVER['REQUEST_URI'] = "docker/apps";
@require_once "$docroot/plugins/dynamix/include/Translations.php";

require_once "$docroot/plugins/community.applications/include/paths.php";
require_once "$docroot/plugins/dynamix/include/Wrappers.php";
require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php";

$unRaidVersion = parse_ini_file(CA_PATHS['unRaidVersion']);

/**
 * Translate a string and escape single and double quotes for safe HTML output.
 *
 * If a global translation function `_` exists, the input is translated and both single (`'`) and double (`"`) quotes are replaced with HTML entities. When `$ret` is true the processed string is returned; otherwise it is echoed.
 *
 * @param string $string The text to translate and escape.
 * @param bool $ret If true, return the processed string; if false, echo it.
 * @return string The translated and escaped string when `$ret` is true.
 */
function tr($string,$ret=true) {
	if ( function_exists("_") )
		$string =  str_replace('"',"&#34;",str_replace("'","&#39;",_($string)));
	if ( $ret )
		return $string;
	else
		echo $string;
}

/**
 * Check whether a string starts with a given substring (case-insensitive).
 *
 * @param string $haystack The string to test.
 * @param string $needle The prefix to check for.
 * @return bool `true` if `$haystack` starts with `$needle` (case-insensitive), `false` otherwise.
 */
function startsWith($haystack, $needle) {
	return $needle === "" || strripos($haystack, $needle, -strlen($haystack)) !== FALSE;
}

$exeFile = "/usr/local/emhttp/plugins/dynamix.docker.manager/include/CreateDocker.php";

$javascript = file_get_contents("/usr/local/emhttp/plugins/dynamix/javascript/dynamix.js");
echo "<script>$javascript</script>";

if ( $_GET['docker'] ) {
	echo "<div id='output'>";
	$rawDockers = explode(",",$_GET['docker']);
	$dockers = [];
	foreach ($rawDockers as $d) {
		$d = trim($d);
		if ($d === "" || !preg_match('/^[A-Za-z0-9_.-]+$/', $d)) continue;
		$dockers[] = $d;
	}
	echo sprintf(tr("Installing docker applications %s"),htmlspecialchars(implode(", ",$dockers), ENT_QUOTES, 'UTF-8'))."<br>";
	$_GET['updateContainer'] = true;
	$_GET['ct'] = $dockers;
	$_GET['communityApplications'] = true;
	$_GET['mute'] = false;
	@include($exeFile); # under new GUI, this line returns a duplicated session_start() error.
	echo "</div>";
?>

<script>
$("input,#output").hide();

var cursor = "";
function addLog(logLine) {
	var scrollTop = (window.pageYOffset !== undefined) ? window.pageYOffset : (document.documentElement || document.body.parentNode).scrollTop;
	var clientHeight = (document.documentElement || document.body.parentNode).clientHeight;
	var scrollHeight = (document.documentElement || document.body.parentNode).scrollHeight;
	var isScrolledToBottom = scrollHeight - clientHeight <= scrollTop + 1;
	if (logLine.slice(-1) == "\n") {
		document.body.innerHTML = document.body.innerHTML.slice(0,cursor) + logLine.slice(0,-1) + "<br>";
		lastLine = document.body.innerHTML.length;
		cursor = lastLine;
	}
	else if (logLine.slice(-1) == "\r") {
		document.body.innerHTML = document.body.innerHTML.slice(0,cursor) + logLine.slice(0,-1);
		cursor = lastLine;
	}
	else if (logLine.slice(-1) == "\b") {
		if (logLine.length > 1)
			document.body.innerHTML = document.body.innerHTML.slice(0,cursor) + logLine.slice(0,-1);
		cursor += logLine.length-2;
	}
	else {
		document.body.innerHTML += logLine;
		cursor += logLine.length;
	}
	if (isScrolledToBottom) {
		window.scrollTo(0,document.body.scrollHeight);
	}
}
function addCloseButton() {
	addLog("<p class='centered'><button class='logLine' type='button' onclick='" + (top.Shadowbox ? "top.Shadowbox" : "window") + ".close()'><?=tr("Done");?></button></p>");
}
</script>
<?
	$failFlag = false;
	foreach ($dockers as $docker) {
		$dockerSafe = htmlspecialchars($docker, ENT_QUOTES, 'UTF-8');
		echo sprintf(tr("Starting %s"),"<span class='ca_bold'>$dockerSafe</span>")."<br>";
		unset($output);
		exec("docker start ".escapeshellarg($docker)." 2>&1",$output,$retval);
		if ($retval) {
			$failFlag = true;
			echo sprintf(tr("%s failed to start.  You should install it by itself to fix the errors"),"<span class='ca_bold'>$dockerSafe</span>")."<br>";
			foreach ($output as $line) {
				echo "<tt>".htmlspecialchars($line, ENT_QUOTES, 'UTF-8')."</tt><br>";
			}
			echo "<br>";
		}
	}
	if ( ! is_file("/var/lib/docker/unraid-autostart") ) {
		echo "<br>".tr("Setting installed applications to autostart")."<br>";
		$autostartFile = array();

		foreach ($dockers as $docker) {
			$autostart[$docker] = true;
		}
		$autostartFile = implode("\n",array_keys($autostart));
		file_put_contents("/var/lib/docker/unraid-autostart",$autostartFile);
	}

	echo "<br>".tr("Downloading docker icons")."<br>";
	$DockerTemplates->getAllInfo();
	exec("$docroot/plugins/dynamix.docker.manager/scripts/dockerupdate check nonotify > /dev/null 2>&1");

	if ( $failFlag || !$_GET['plugin']) {
		echo "<br>".tr("Docker Application Installation finished")."<br><script>addCloseButton();</script>";
	} else {
		echo "<script>top.Shadowbox.close();</script>";
	}
	@unlink("/tmp/community.applications/tempFiles/newCreateDocker.php");
}
?>