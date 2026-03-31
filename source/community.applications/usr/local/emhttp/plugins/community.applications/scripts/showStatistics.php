<?
########################################
#                                      #
# Community Applications               #
# Copyright 2020-2026, Lime Technology #
# Copyright 2015-2026, Andrew Zawadzki #
#                                      #
# Licenced under GPLv2                 #
#                                      #
########################################

ini_set('memory_limit','256M');  // REQUIRED LINE

$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: "/usr/local/emhttp";

require_once "$docroot/plugins/community.applications/include/paths.php";
require_once "$docroot/plugins/dynamix/include/Wrappers.php";
require_once "$docroot/plugins/dynamix/include/Helpers.php";

$_SERVER['REQUEST_URI'] = "docker/apps";
require_once "$docroot/plugins/dynamix/include/Translations.php";
require_once "$docroot/plugins/community.applications/include/helpers.php";

$caSettings = parse_plugin_cfg("community.applications");

function tr($string,$ret=true) {
  $string =  str_replace('"',"&#34;",str_replace("'","&#39;",_($string)));
  if ( $ret )
    return $string;
  else
    echo $string;
}

?>

<?
$repositories = readJsonFile(CA_PATHS['repositoryList']);
switch ($_GET['arg1']) {
  case 'Repository':
    foreach ($repositories as $name => $repo) {
      $repos[$name] = $repo['url'];
    }
    ksort($repos,SORT_FLAG_CASE | SORT_NATURAL);
    echo "<tt><table>";
    foreach (array_keys($repos) as $repo) {
      echo "<tr><td><span class='ca_bold'>$repo</td><td><a class='popUpLink' href='{$repos[$repo]}' target='_blank'>{$repos[$repo]}</a></td></tr>";
    }
    echo "</table></tt>";
    break;
  case 'Invalid':
    $moderation = json_encode(readJsonFile(CA_PATHS['invalidXML_txt']),JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ( ! $moderation ) {
      echo "<br><br><div class='ca_center'><span class='ca_bold'>".tr("No invalid templates found")."</span></div>";
      return;
    }
    $moderation = str_replace(" ","&nbsp;",$moderation);
    $moderation = str_replace("\n","<br>",$moderation);
    echo "<tt>".tr("These templates are invalid and the application they are referring to is unknown")."<br><br>$moderation";
    break;
  case 'Fixed':
    $json = $moderation = readJsonFile(CA_PATHS['fixedTemplates_txt']);
    if ( ! $moderation ) {
      echo "<br><br><div class='ca_center'><span class='ca_bold'>".tr("No templates were automatically fixed")."</span></div>";
    } else {
      ksort($json,SORT_NATURAL | SORT_FLAG_CASE);
      echo tr("All of these errors found have been fixed automatically")."<br><br>".tr("Note that many of these errors can be avoided by following the directions")." <a href='https://forums.unraid.net/topic/57181-real-docker-faq/#comment-566084' target='_blank'>".tr("HERE")."</a><br><br>";
      foreach (array_keys($json) as $repository) {
        echo "<br><b><span style='font-size:20px;'>$repository</span></b><br>";
        foreach (array_keys($json[$repository]) as $repo) {
          echo "<code>&nbsp;&nbsp;&nbsp;&nbsp;<b><span style='font-size:16px;'>$repo:</span></b><br>";
          foreach ($json[$repository][$repo] as $error) {
            echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;".str_replace(" ","&nbsp;",$error)."<br>";
          }
          echo "</code>";
        }
      }
    }

    $dupeList = readJsonFile(CA_PATHS['pluginDupes']);
    if ($dupeList) {
      $templates = readJsonFile(CA_PATHS['community-templates-info']);
      echo "<br><br><span class='ca_bold'></tt>".tr("The following plugins have duplicated filenames and are not able to be installed simultaneously:")."</span><br><br>";
      foreach (array_keys($dupeList) as $dupe) {
        echo "<span class='ca_bold'>$dupe</span><br>";
        foreach ($templates as $template) {
          if ( basename($template['PluginURL']??"") == $dupe ) {
            echo "<tt>{$template['Author']} - {$template['Name']}<br></tt>";
          }
        }
        echo "<br>";
      }
    }
    $templates = readJsonFile(CA_PATHS['community-templates-info']);
    $dupeRepos = "";
    foreach ($templates as $template) {
      $template['Repository'] = str_replace(":latest","",$template['Repository']);
      $count = 0;
      foreach ($templates as $searchTemplates) {
        if ( $template['Language'] ?? false) continue;
        if ( (str_replace(["lscr.io/","ghcr.io/"],"",$template['Repository']) == str_replace(":latest","",str_replace(["lscr.io/","ghcr.io/"],"",$searchTemplates['Repository'])))  ) {
          if ( ($searchTemplates['BranchName']??false) || ($searchTemplates['Blacklist']??false) || ($searchTemplates['Deprecated']??false) ) {
            continue;
          }
          $count++;
        }
      }
      if ($count > 1 ) {
        $dupeRepos .= "Duplicated Template: {$template['RepoName']} - {$template['Repository']} - {$template['Name']}<br>";
      }
    }
    if ( $dupeRepos ) {
      echo "<br><span class='ca_bold'></tt>".tr("The following docker applications refer to the same docker repository but may have subtle changes in the template to warrant this")."</span><br><br><tt>$dupeRepos";
    }

    break;
  case 'Moderation':
    echo "<br><div class='ca_center'><strong>".tr("If any of these entries are incorrect then contact the moderators of CA to discuss")."</strong></div><br><br>";
    $moderation = json_encode(readJsonFile(CA_PATHS['moderation']),JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $repoComment = "";
    foreach ($repositories as $repo) {
      if ($repo['RepoComment']??false) {
        $repoComment .= "<tr><td>{$repo['name']}</td><td>{$repo['RepoComment']}</td></tr>";
      }
    }
    if ( $repoComment ) {
      echo "<br><div class='ca_center'><strong>".tr("Global Repository Comments:")."</strong><br>".tr("(Applied to all applications)")."</div><br><br><tt><table>$repoComment</table><br><br>";
    }
    if ( ! $moderation ) {
      echo "<br><br><div class='ca_center'><span class='ca_bold'>No moderation entries found</span></div>";
    }
    echo "</tt><div class='ca_center'><strong>".tr("Individual Application Moderation")."</strong></div><br><br>";
    $moderation = str_replace(" ","&nbsp;",$moderation);
    $moderation = str_replace("\n","<br>",$moderation);
    echo "<tt>$moderation";
    break;
}
?>
