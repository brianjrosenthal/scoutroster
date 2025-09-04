<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/lib/Application.php';
Application::init();


function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function header_html(string $title) {
  $u = current_user();
  $cur = basename($_SERVER['SCRIPT_NAME'] ?? '');
  $link = function(string $path, string $label) use ($cur) {
    $active = ($cur === basename($path));
    $a = '<a href="'.h($path).'">'.h($label).'</a>';
    return $active ? '<strong>'.$a.'</strong>' : $a;
  };

  // Build nav (left/right groups). Remove Change Password. Move My Profile to the far right next to Logout.
  $navLeft = [];
  $navRight = [];
  if ($u) {
    $navLeft[] = $link('/index.php','Home');
    $navLeft[] = $link('/youth.php','Youth');
    $navLeft[] = $link('/adults.php','Adults');
    $navLeft[] = $link('/reimbursements.php','Reimbursements');
    $navLeft[] = $link('/events.php','Events');
    if (!empty($u['is_admin'])) {
      $navLeft[] = '<a href="#" id="adminToggle">Admin</a>';
    }
    // Add small avatar in the top-right nav linking to My Profile
    $initials = strtoupper(substr((string)($u['first_name'] ?? ''),0,1).substr((string)($u['last_name'] ?? ''),0,1));
    $photo = trim((string)($u['photo_path'] ?? ''));
    $avatar = $photo !== ''
      ? '<img class="nav-avatar" src="'.h($photo).'" alt="'.h(trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? ''))).'" />'
      : '<span class="nav-avatar nav-avatar-initials" aria-hidden="true">'.h($initials).'</span>';
    $navRight[] = $link('/my_profile.php','My Profile');
    $navRight[] = $link('/logout.php','Log out');
    $navRight[] = '<a href="/my_profile.php" class="nav-avatar-link" title="My Profile">'.$avatar.'</a>';
    
  } else {
    $navLeft[] = $link('/login.php','Login');
  }
  $navHtml = '<span class="nav-left">'.implode(' ', $navLeft).'</span>'
           . '<span class="nav-right">'.implode(' ', $navRight).'</span>';

  $siteTitle = Settings::siteTitle();

  echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>'.h($title).' - '.h($siteTitle).'</title>';

  // cache-busted CSS
  $cssPath = __DIR__.'/styles.css';
  $cssVer = @filemtime($cssPath);
  if (!$cssVer) { $cssVer = date('Ymd'); }
  echo '<link rel="stylesheet" href="/styles.css?v='.h($cssVer).'">';
  echo '</head><body>';
  echo '<header><h1><a href="/index.php">'.h($siteTitle).'</a></h1><nav>'.$navHtml.'</nav></header>';

  // Admin bar (second row) toggled by the "Admin" link
  if ($u && !empty($u['is_admin'])) {
    echo '<div id="adminBar" class="admin-bar hidden">';
    echo $link('/admin_events.php','Manage Events');
    echo $link('/admin_adults.php','Manage Adults');
    echo $link('/admin_import_upload.php','Import Members');
    echo $link('/admin_mailing_list.php','Mailing List');
    echo $link('/admin_recommendations.php','Recommendations');
    echo $link('/admin_settings.php','Settings');
    echo '</div>';
    echo '<script>document.addEventListener("DOMContentLoaded",function(){var t=document.getElementById("adminToggle");var b=document.getElementById("adminBar");if(t&&b){t.addEventListener("click",function(e){e.preventDefault();b.classList.toggle("hidden");});}});</script>';
  }

  echo '<main>';
}

function footer_html() {
  // cache-busted JS
  $jsPath = __DIR__.'/main.js';
  $jsVer = @filemtime($jsPath);
  if (!$jsVer) { $jsVer = date('Ymd'); }
  echo '</main><script src="/main.js?v='.h($jsVer).'"></script></body></html>';
}
