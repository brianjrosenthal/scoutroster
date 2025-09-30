<?php

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/Files.php';

/**
 * ApplicationUI - Centralized UI rendering for the application
 * 
 * Handles the rendering of common HTML elements like headers and footers
 * with navigation, authentication, and admin functionality.
 */
class ApplicationUI {
    
    /**
     * Renders the HTML header including navigation, admin bar, and user menus
     * 
     * @param string $title Page title to display in the browser tab and header
     */
    public static function renderHeader(string $title): void {
        $u = current_user();
        $cur = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $link = function(string $path, string $label) use ($cur) {
            $active = ($cur === basename($path));
            $a = '<a href="'.h($path).'">'.h($label).'</a>';
            return $active ? '<strong>'.$a.'</strong>' : $a;
        };

        // Check for email token authentication context (set by event_invite.php)
        global $emailTokenAuth, $emailTokenUserName;
        $isEmailTokenAuth = !empty($emailTokenAuth);
        $tokenUserName = $emailTokenUserName ?? '';

        // Build nav (left/right groups). Remove Change Password. Move My Profile to the far right next to Logout.
        $navLeft = [];
        $navRight = [];
        if ($u) {
            $navLeft[] = $link('/index.php','Home');
            $navLeft[] = $link('/youth.php','Youth');
            $navLeft[] = $link('/adults.php','Adults');
            $navLeft[] = $link('/events.php','Events');
            if (!empty($u['is_admin'])) {
                $navLeft[] = '<a href="#" id="adminToggle">Admin</a>';
            }
            // Add small avatar in the top-right nav linking to My Profile
            $initials = strtoupper(substr((string)($u['first_name'] ?? ''),0,1).substr((string)($u['last_name'] ?? ''),0,1));
            $photoUrl = Files::profilePhotoUrl($u['photo_public_file_id'] ?? null);
            $avatar = $photoUrl !== ''
                ? '<img class="nav-avatar" src="'.h($photoUrl).'" alt="'.h(trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? ''))).'" />'
                : '<span class="nav-avatar nav-avatar-initials" aria-hidden="true">'.h($initials).'</span>';
            $navRight[] = '<div class="nav-avatar-wrap">'
                        . '<a href="#" id="avatarToggle" class="nav-avatar-link" aria-expanded="false" title="Account">'.$avatar.'</a>'
                        . '<div id="avatarMenu" class="avatar-menu hidden" role="menu" aria-hidden="true">'
                        .   '<a href="/my_profile.php" role="menuitem">My Profile</a>'
                        .   '<a href="/leadership.php" role="menuitem">Pack Leadership</a>'
                        .   '<a href="/reimbursements.php" role="menuitem">Reimbursements</a>'
                        .   '<a href="/forms.php" role="menuitem">Forms and Links</a>'
                        .   '<a href="/logout.php" role="menuitem">Logout</a>'
                        . '</div>'
                        . '</div>';
            
        } else {
            $navLeft[] = $link('/login.php','Login');
            // Show email token authentication status if present
            if ($isEmailTokenAuth && $tokenUserName !== '') {
                $navRight[] = '<span class="email-auth-status">Authenticated via email as '.h($tokenUserName).'</span>';
            }
        }
        $navHtml = '<span class="nav-left">'.implode(' ', $navLeft).'</span>'
                 . '<span class="nav-right">'.implode(' ', $navRight).'</span>';

        $siteTitle = Settings::siteTitle();

        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>'.h($title).' - '.h($siteTitle).'</title>';

        // cache-busted CSS
        $cssPath = __DIR__.'/../styles.css';
        $cssVer = @filemtime($cssPath);
        if (!$cssVer) { $cssVer = date('Ymd'); }
        echo '<link rel="stylesheet" href="/styles.css?v='.h($cssVer).'">';
        echo '</head><body>';
        echo '<header><h1><a href="/index.php">'.h($siteTitle).'</a></h1><nav>'.$navHtml.'</nav></header>';

        // Admin bar (second row) toggled by the "Admin" link
        if ($u && !empty($u['is_admin'])) {
            echo '<div id="adminBar" class="admin-bar hidden">';
            echo $link('/admin_adults.php','Manage Adults');
            echo $link('/admin_imports.php','Import Data');
            echo $link('/admin_mailing_list.php','Mailing List');
            echo $link('/admin/leadership_positions.php','Positions');
            echo $link('/admin_activity_log.php','Activity Log');
            // Show "Payment notifs" only for Cubmaster or Treasurer, before Recommendations
            $showPaymentNotifs = false;
            try {
                $stPos = pdo()->prepare("SELECT LOWER(alp.name) AS p 
                                        FROM adult_leadership_position_assignments alpa
                                        JOIN adult_leadership_positions alp ON alp.id = alpa.adult_leadership_position_id
                                        WHERE alpa.adult_id=?");
                $stPos->execute([(int)($u['id'] ?? 0)]);
                $rowsPos = $stPos->fetchAll();
                if (is_array($rowsPos)) {
                    foreach ($rowsPos as $pr) {
                        $p = trim((string)($pr['p'] ?? ''));
                        if ($p === 'cubmaster' || $p === 'treasurer') { $showPaymentNotifs = true; break; }
                    }
                }
            } catch (Throwable $e) {
                $showPaymentNotifs = false;
            }
            if ($showPaymentNotifs) {
                echo $link('/payment_notifications_from_users.php','Payment notifs');
            }
            // Pending Registrations visible to approvers (Cubmaster, Committee Chair, Treasurer)
            $showPendingRegs = false;
            try {
                $stPos2 = pdo()->prepare("SELECT LOWER(alp.name) AS p 
                                         FROM adult_leadership_position_assignments alpa
                                         JOIN adult_leadership_positions alp ON alp.id = alpa.adult_leadership_position_id
                                         WHERE alpa.adult_id=?");
                $stPos2->execute([(int)($u['id'] ?? 0)]);
                $rowsPos2 = $stPos2->fetchAll();
                if (is_array($rowsPos2)) {
                    foreach ($rowsPos2 as $pr2) {
                        $p2 = trim((string)($pr2['p'] ?? ''));
                        if ($p2 === 'cubmaster' || $p2 === 'committee chair' || $p2 === 'treasurer') { $showPendingRegs = true; break; }
                    }
                }
            } catch (Throwable $e) {
                $showPendingRegs = false;
            }
            if ($showPendingRegs) {
                echo $link('/pending_registrations.php','Pending Registrations');
            }
            // Registration Renewals visible to approvers (Cubmaster, Committee Chair, Treasurer)
            $showRenewals = false;
            try {
                $stPos3 = pdo()->prepare("SELECT LOWER(alp.name) AS p 
                                         FROM adult_leadership_position_assignments alpa
                                         JOIN adult_leadership_positions alp ON alp.id = alpa.adult_leadership_position_id
                                         WHERE alpa.adult_id=?");
                $stPos3->execute([(int)($u['id'] ?? 0)]);
                $rowsPos3 = $stPos3->fetchAll();
                if (is_array($rowsPos3)) {
                    foreach ($rowsPos3 as $pr3) {
                        $p3 = trim((string)($pr3['p'] ?? ''));
                        if ($p3 === 'cubmaster' || $p3 === 'committee chair' || $p3 === 'treasurer') { $showRenewals = true; break; }
                    }
                }
            } catch (Throwable $e) {
                $showRenewals = false;
            }
            if ($showRenewals) {
                echo $link('/registration_renewals.php','Registration Renewals');
            }
            echo $link('/admin_recommendations.php','Recommendations');
            echo $link('/admin_settings.php','Settings');
            echo '</div>';
            echo '<script>document.addEventListener("DOMContentLoaded",function(){var t=document.getElementById("adminToggle");var b=document.getElementById("adminBar");if(t&&b){t.addEventListener("click",function(e){e.preventDefault();b.classList.toggle("hidden");});}});</script>';
        }

        // Avatar dropdown script
        if ($u) {
            echo '<script>document.addEventListener("DOMContentLoaded",function(){var at=document.getElementById("avatarToggle");var m=document.getElementById("avatarMenu");function hide(){if(m){m.classList.add("hidden");m.setAttribute("aria-hidden","true");}if(at){at.setAttribute("aria-expanded","false");}}function toggle(e){e.preventDefault();if(!m)return;var isHidden=m.classList.contains("hidden");if(isHidden){m.classList.remove("hidden");m.setAttribute("aria-hidden","false");if(at)at.setAttribute("aria-expanded","true");}else{hide();}}if(at)at.addEventListener("click",toggle);document.addEventListener("click",function(e){if(!m||!at)return;var wrap=at.closest(".nav-avatar-wrap");if(wrap&&wrap.contains(e.target))return;hide();});document.addEventListener("keydown",function(e){if(e.key==="Escape")hide();});});</script>';
        }
        echo '<main>';
    }

    /**
     * Renders the HTML footer including cache-busted JavaScript
     */
    public static function renderFooter(): void {
        // cache-busted JS
        $jsPath = __DIR__.'/../main.js';
        $jsVer = @filemtime($jsPath);
        if (!$jsVer) { $jsVer = date('Ymd'); }
        echo '</main><script src="/main.js?v='.h($jsVer).'"></script></body></html>';
    }
}
