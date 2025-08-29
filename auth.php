<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/UserManagement.php';

function current_user() {
  if (!empty($_SESSION['uid'])) {
    // Optional public computer inactivity timeout (30 minutes)
    $isPublic = !empty($_SESSION['public_computer']);
    if ($isPublic) {
      $last = $_SESSION['last_activity'] ?? 0;
      $now = time();
      $timeout = 1800; // 30 minutes
      if ($last && ($now - (int)$last) > $timeout) {
        unset($_SESSION['uid'], $_SESSION['last_activity'], $_SESSION['public_computer']);
        if (session_status() === PHP_SESSION_ACTIVE) {
          session_regenerate_id(true);
        }
        return null;
      }
      $_SESSION['last_activity'] = $now;
    }

    $u = UserManagement::findFullById((int)$_SESSION['uid']);
    return $u ?: null;
  }
  return null;
}

function require_login() {
  if (!current_user()) { header('Location: /login.php'); exit; }
}

function require_admin() {
  $u = current_user();
  if (!$u || empty($u['is_admin'])) { http_response_code(403); exit('Admins only'); }
}
