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
.logLine{color:black !important;}
</style>
<?
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: "/usr/local/emhttp";

$_SERVER['REQUEST_URI'] = "docker/apps";
@require_once "$docroot/plugins/dynamix/include/Translations.php";

require_once "$docroot/plugins/community.applications/include/paths.php";
require_once "$docroot/plugins/dynamix/include/Wrappers.php";
require_once "$docroot/plugins/community.applications/include/helpers.php";

$caSettings = parse_plugin_cfg("community.applications");
$unRaidVersion = parse_ini_file(CA_PATHS['unRaidVersion']);
$unRaid69 = version_compare($unRaidVersion['version'],"6.9.9","<=");
$exeFile = "/usr/local/emhttp/plugins/dynamix.docker.manager/include/CreateDocker.php";

$javascript = file_get_contents("/usr/local/emhttp/plugins/dynamix/javascript/dynamix.js");
echo "<script>$javascript</script>";

/* Repository keys ("user/repo" or "library/name") are stable across paged
   DockerHub search results; the older per-page integer ID would silently
   resolve to the wrong cached entry once the user paged past the first set. */
$repo = isset($_GET['repo']) ? trim((string)$_GET['repo']) : "";
if ($repo !== "") {
	$file = @readJsonFile(CA_PATHS['dockerSearchResults']);
	$docker = null;
	if (is_array($file) && !empty($file['results'])) {
		foreach ($file['results'] as $r) {
			if (is_array($r) && (string)($r['Repository'] ?? '') === $repo) {
				$docker = $r;
				break;
			}
		}
	}
	if (!is_array($docker)) {
		$nameParts = explode('/', $repo);
		$shortName = (string)end($nameParts);
		$docker = [
			'Repository'  => $repo,
			'Name'        => $shortName,
			'Description' => "",
			'DockerHub'   => (strpos($repo, '/') === false || strpos($repo, 'library/') === 0)
				? "https://hub.docker.com/_/{$shortName}/"
				: "https://hub.docker.com/r/{$repo}/",
		];
	}
	/* Cached entries from the docker-hub search may not include Name (or may
	   carry the same fallback we just used) — derive it from the repo if
	   missing so the post-test write at line below isn't left with an empty
	   container name. */
	if (empty($docker['Name'])) {
		$nameParts = explode('/', $repo);
		$docker['Name'] = (string)end($nameParts);
	}
	/* Prefer the description forwarded by the click handler over whatever the
	   per-tab cache happens to hold — the user's seen description is the
	   authoritative one. */
	$clientDescription = isset($_GET['description']) ? trim((string)$_GET['description']) : "";
	if ($clientDescription !== "") {
		$docker['Description'] = $clientDescription;
	}
	$docker['Description'] = str_replace("&", "&amp;", (string)($docker['Description'] ?? ""));

	$dockerfile = [];
	$dockerfile['Name'] = "CA_TEST_CONTAINER_DOCKERHUB";
	$dockerfile['Description'] = $docker['Description']."\n\nConverted By Community Applications   Always verify this template (and values)  against the support page for the container\n\n{$docker['DockerHub']}";
	$dockerfile['Overview'] = $dockerfile['Description'];
	$dockerfile['Registry'] = $docker['DockerHub'];
	$dockerfile['Repository'] = $docker['Repository'];
	$dockerfile['BindTime'] = "true";
	$dockerfile['Privileged'] = "false";
	$dockerfile['Networking']['Mode'] = "bridge";
	$dockerXML = makeXML($dockerfile);
	file_put_contents("/boot/config/plugins/dockerMan/templates-user/my-CA_TEST_CONTAINER_DOCKERHUB.xml",$dockerXML);


	echo "<div id='output'>";
	$dockers = ["CA_TEST_CONTAINER_DOCKERHUB"];
	echo sprintf(tr("Installing test container"),htmlspecialchars(str_replace(",",", ",$_GET['docker'] ?? ""), ENT_QUOTES, 'UTF-8'))."<br>";
	$_GET['updateContainer'] = true;
	$_GET['ct'] = $dockers;
	$_GET['communityApplications'] = true;
	$_GET['mute'] = false;
	@include($exeFile); # under new GUI, this line returns a duplicated session_start() error.
	echo "</div>";
?>

<script>
$("button").hide();
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
	addLog("<p class='centered'><button class='logLine' type='button' onclick='" + (parent.Shadowbox ? "parent.Shadowbox" : "window") + ".close()'><?=tr("Done");?></button></p>");
}
</script>
<?
	$output = shell_exec("docker inspect CA_TEST_CONTAINER_DOCKERHUB");
	echo "<br>".tr("Removing test installation")."<br>";
	exec("docker rm CA_TEST_CONTAINER_DOCKERHUB");

	exec("docker rmi ".escapeshellarg($docker['Repository']));
	@unlink("/boot/config/plugins/dockerMan/templates-user/my-CA_TEST_CONTAINER_DOCKERHUB.xml");

	$json = json_decode($output,true);
	if ( $json ) {
		$paths = isset($json[0]['Mounts']) ? $json[0]['Mounts'] : [];
		$ports = isset($json[0]['Config']['ExposedPorts']) ? $json[0]['Config']['ExposedPorts'] : [];
		$vars = isset($json[0]['Config']['Env']) ? $json[0]['Config']['Env'] : [];

		$count = 1;
		$Config = [];
		foreach ($paths as $path) {
			$p = ["Name"=>"Container Path $count",'Type'=>"Path","Target"=>$path['Destination'],"Default"=>"","Mode"=>"rw","Display"=>"always","Required"=>"false","Mask"=>"false"];
			if ( $unRaid69 ) $p['Description'] = "Container Path: {$path['Destination']}";
			$Config[]['@attributes'] = $p;
			$count++;
		}
		$count = 1;
		foreach ($ports as $port => $name) {
			$pp = explode("/",$port);
			$p = ["Name"=>"Container Port $count",'Type'=>"Port","Target"=>$pp[0],"Default"=>$pp[0],"Mode"=>$pp[1],"Display"=>"always","Required"=>"false","Mask"=>"false","Description"=>""];
			if ( $unRaid69 ) $p['Description'] = "Container Port: {$pp[0]}";
			$Config[]['@attributes'] = $p;
			$count++;
		}
		$textvars = "";
		foreach ($vars as $var) {
			$textvars .= "$var\n";
		}
		$testvars = @parse_ini_string($textvars) ?: [];
		$defaultvars = ["HOST_HOSTNAME","HOST_OS","HOST_CONTAINERNAME","TZ","PATH"];
		$count = 1;
		foreach ($testvars as $var => $varcont) {
			if ( in_array($var,$defaultvars) )
				continue;

			$p = ["Name"=>"Container Variable $count",'Target'=>$var,"Type"=>"Variable","Default"=>$varcont,"Description"=>"","Required"=>"false","Mask"=>"false","Display"=>"always"];
			if ( $unRaid69 ) $p['Description'] = "Container Variable: $var";
			$Config[]['@attributes'] = $p;
			$count++;
		}
		$Config[]['@attributes'] = ["Name"=>"Community Applications Conversion",'Target'=>"Community_Applications_Conversion","Type"=>"Variable","Default"=>"true","Description"=>"","Required"=>"false","Mask"=>"false","Display"=>"always"];

		if ( !empty($Config) )
			$dockerfile['Config'] = $Config;
	} else {
		$error = tr("An error occurred - Could not determine configuration");
	}
	$dockerfile['Name'] = $docker['Name'];

	$existing_templates = array_diff(scandir($dockerManPaths['templates-user']),[".",".."]);
	foreach ( $existing_templates as $template ) {
		if ( strtolower($dockerfile['Name']) == strtolower(str_replace(["my-",".xml"],["",""],$template)) )
			$dockerfile['Name'] .= "-1";
	}

	file_put_contents(CA_PATHS['dockerSearchInstall'],makeXML($dockerfile));
}
?>
<script>
	<? if ( $json ):?>
		window.parent.location = "/Apps/AddContainer?xmlTemplate=default:<?=CA_PATHS['dockerSearchInstall']?>";

	<? else:?>
		alert("<?=$error?>");
		window.parent.location = "/Apps/AddContainer?xmlTemplate=default:<?=CA_PATHS['dockerSearchInstall']?>";

	<? endif;?>
</script>