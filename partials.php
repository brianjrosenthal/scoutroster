<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/lib/UserContext.php';
UserContext::bootstrapFromSession();

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function header_html(string $title) {
  $u = current_user();
  $cur = basename($_SERVER['SCRIPT_NAME'] ?? '');
  $link = function(string $path, string $label) use ($cur) {
    $active = ($cur === basename($path));
    $a = '<a href="'.h($path).'">'.h($label).'</a>';
    return $active ? '<strong>'.$a.'</strong>' : $a;
  };

  // Build nav
  $nav = '';
  if ($u) {
    $nav .= $link('/index.php','Home').' | ';
    $nav .= $link('/youth.php','Youth').' | ';
    $nav .= $link('/adults.php','Adults').' | ';
    $nav .= $link('/events.php','Events').' | ';
    $nav .= $link('/my_profile.php','My Profile').' | ';
    if (!empty($u['is_admin'])) {
      $nav .= $link('/admin_youth.php','Manage Youth').' | ';
      $nav .= $link('/admin_adults.php','Manage Adults').' | ';
      $nav .= $link('/admin_dens.php','Dens').' | ';
      $nav .= $link('/admin_events.php','Manage Events').' | ';
      $nav .= $link('/admin_mailing_list.php','Mailing List').' | ';
      $nav .= $link('/admin_settings.php','Settings').' | ';
    }
    $nav .= $link('/change_password.php','Change Password').' | '.$link('/logout.php','Log out');
  } else {
    $nav .= $link('/login.php','Login');
  }

  $siteTitle = Settings::siteTitle();

  echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>'.h($title).' - '.h($siteTitle).'</title>';

  // cache-busted CSS
  $cssPath = __DIR__.'/styles.css';
  $cssVer = @filemtime($cssPath);
  if (!$cssVer) { $cssVer = date('Ymd'); }
  echo '<link rel="stylesheet" href="/styles.css?v='.h($cssVer).'">';
  echo '</head><body>';
  echo '<header><h1><a href="/index.php">'.h($siteTitle).'</a></h1><nav>'.$nav.'</nav></header>';
  echo '<main>';
}

function footer_html() {
  // cache-busted JS
  $jsPath = __DIR__.'/main.js';
  $jsVer = @filemtime($jsPath);
  if (!$jsVer) { $jsVer = date('Ymd'); }
  echo '</main><script src="/main.js?v='.h($jsVer).'"></script></body></html>';
}
