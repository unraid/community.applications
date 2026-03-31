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

function ca_moderation_value($value) {
  if (is_bool($value)) {
    return $value ? tr("Yes") : tr("No");
  }
  if (is_array($value)) {
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
    return htmlspecialchars($encoded ?: "", ENT_QUOTES);
  }
  if ($value === null) {
    return "";
  }
  $text = (string)$value;
  return nl2br(htmlspecialchars($text, ENT_QUOTES));
}

?>

<?
$repositories = readJsonFile(CA_PATHS['repositoryList']);
echo "<div class='ca_center caLogoIcon'></div>";
echo "<div class='ca_center ca_settingsTitle'>".tr("Community Applications")."</div>";
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
    $invalidTemplates = readJsonFile(CA_PATHS['invalidXML_txt']);
    if ( ! is_array($invalidTemplates) || ! count($invalidTemplates) ) {
      echo "<br><br><div class='ca_center'><span class='ca_bold'>".tr("No invalid templates found")."</span></div>";
      return;
    }
    ksort($invalidTemplates,SORT_NATURAL | SORT_FLAG_CASE);
    echo "<div class='ca_moderationList'>";
    echo "<div class='ca_moderationItem'><div class='ca_moderationTitle'>".tr("These templates are invalid and the application they are referring to is unknown")."</div></div>";
    foreach ($invalidTemplates as $template => $errors) {
      $title = (string)$template;
      $details = "";
      if (is_array($errors)) {
        $templatePath = $errors['TemplatePath'] ?? $errors['templatePath'] ?? $errors['templatepath'] ?? null;
        if ($templatePath) {
          $title = (string)$templatePath;
        }
        $errorList = $errors['errors'] ?? $errors['Errors'] ?? null;
        if (is_array($errorList) && count($errorList)) {
          $details .= "<div class='ca_moderationRule'><span class='ca_bold'>errors:</span></div>";
          foreach ($errorList as $errorEntry) {
            $details .= "<div class='ca_moderationRule ca_moderationSubRule'>".ca_moderation_value($errorEntry)."</div>";
          }
        }
        foreach ($errors as $key => $value) {
          $keyLower = strtolower((string)$key);
          if ($keyLower === "templatepath" || $keyLower === "errors" || $keyLower === "firstseen") {
            continue;
          }
          if (is_int($key)) {
            $details .= "<div class='ca_moderationRule'>".ca_moderation_value($value)."</div>";
          } else {
            $safeKey = htmlspecialchars((string)$key, ENT_QUOTES);
            $details .= "<div class='ca_moderationRule'><span class='ca_bold'>$safeKey:</span> ".ca_moderation_value($value)."</div>";
          }
        }
      } else {
        $details = "<div class='ca_moderationRule'>".ca_moderation_value($errors)."</div>";
      }
      if (!$details) {
        $details = "<div class='ca_moderationRule'>&mdash;</div>";
      }
      $title = htmlspecialchars($title, ENT_QUOTES);
      echo "<div class='ca_moderationItem'><div class='ca_moderationTitle'>$title</div><div class='ca_moderationDetails'>$details</div></div>";
    }
    echo "</div>";
    break;
  case 'Fixed':
    $json = $moderation = readJsonFile(CA_PATHS['fixedTemplates_txt']);
    if ( ! $moderation ) {
      echo "<br><br><div class='ca_center'><span class='ca_bold'>".tr("No templates were automatically fixed")."</span></div>";
    } else {
      ksort($json,SORT_NATURAL | SORT_FLAG_CASE);
      echo tr("All of these errors found have been fixed automatically")."<br><br>".tr("Note that many of these errors can be avoided by following the directions")." <a href='https://forums.unraid.net/topic/57181-real-docker-faq/#comment-566084' target='_blank'>".tr("HERE")."</a><br><br>";
      echo "<div class='ca_moderationList'>";
      foreach (array_keys($json) as $repository) {
        $safeRepository = htmlspecialchars((string)$repository, ENT_QUOTES);
        echo "<div class='ca_moderationItem'>";
        echo "<div class='ca_moderationTitle'>$safeRepository</div>";
        echo "<div class='ca_moderationDetails'>";
        foreach (array_keys($json[$repository]) as $repo) {
          $safeRepo = htmlspecialchars((string)$repo, ENT_QUOTES);
          echo "<div class='ca_moderationRule'><span class='ca_bold'>$safeRepo</span></div>";
          foreach ($json[$repository][$repo] as $error) {
            echo "<div class='ca_moderationRule ca_moderationSubRule'>".htmlspecialchars((string)$error, ENT_QUOTES)."</div>";
          }
        }
        echo "</div></div>";
      }
      echo "</div>";
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
    $moderation = readJsonFile(CA_PATHS['moderation']);
    $repoComment = "";
    foreach ($repositories as $repo) {
      if ($repo['RepoComment']??false) {
        $repoName = htmlspecialchars($repo['name'] ?? "", ENT_QUOTES);
        $repoText = nl2br(htmlspecialchars($repo['RepoComment'], ENT_QUOTES));
        $repoComment .= "<div class='ca_moderationItem'><div class='ca_moderationTitle'>$repoName</div><div class='ca_moderationDetails'><div class='ca_moderationRule'>$repoText</div></div></div>";
      }
    }
    if ( $repoComment ) {
      echo "<br><div class='ca_center'><strong>".tr("Global Repository Comments:")."</strong><br>".tr("(Applied to all applications)")."</div><br><br><div class='ca_moderationList'>$repoComment</div><br>";
    }
    if ( ! is_array($moderation) || ! count($moderation) ) {
      echo "<br><br><div class='ca_center'><span class='ca_bold'>No moderation entries found</span></div>";
      break;
    }
    ksort($moderation,SORT_NATURAL | SORT_FLAG_CASE);
    echo "<div class='ca_center'><strong>".tr("Individual Application Moderation")."</strong></div>";
    echo "<div class='ca_center' style='margin-top:.5rem;'>".tr("Total entries:")." ".count($moderation)."</div><br>";
    echo "<div class='ca_moderationList'>";
    foreach ($moderation as $item => $rules) {
      $safeItem = htmlspecialchars((string)$item, ENT_QUOTES);
      $itemHtml = $safeItem;
      $ruleHtml = "";
      if (is_array($rules)) {
        foreach ($rules as $rule => $value) {
          if (strcasecmp((string)$rule, "ModeratorComment") === 0) {
            $ruleHtml .= "<div class='ca_moderationRule'><span class='ca_bold'>".tr("Moderator Comment").":</span> ".ca_moderation_value($value)."</div>";
            continue;
          }
          $ruleName = htmlspecialchars((string)$rule, ENT_QUOTES);
          $ruleHtml .= "<div class='ca_moderationRule'><span class='ca_bold'>$ruleName:</span> ".ca_moderation_value($value)."</div>";
        }
      } else {
        $ruleHtml = "<div class='ca_moderationRule'>".ca_moderation_value($rules)."</div>";
      }
      if (!$ruleHtml) {
        $ruleHtml = "<div class='ca_moderationRule'>&mdash;</div>";
      }
      echo "<div class='ca_moderationItem'><div class='ca_moderationTitle'>$itemHtml</div><div class='ca_moderationDetails'>$ruleHtml</div></div>";
    }
    echo "</div>";
    break;
}
?>
