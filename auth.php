<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/UserContext.php';

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
    if ($u) {
      // Seed per-request UserContext so downstream permission checks have a non-null context
      if (class_exists('UserContext')) {
        UserContext::set(new UserContext((int)$u['id'], !empty($u['is_admin'])));
      }
      return $u;
    }
    return null;
  }
  return null;
}

function require_login() {
  if (!current_user()) {
    $req = $_SERVER['REQUEST_URI'] ?? '/index.php';
    if (!is_string($req) || $req === '' || $req[0] !== '/') { $req = '/index.php'; }
    // Avoid redirect loop to login itself
    if (strpos($req, '/login.php') === 0) { $req = '/index.php'; }
    $loc = '/login.php?next=' . urlencode($req);
    header('Location: ' . $loc);
    exit;
  }
}

function require_admin() {
  $u = current_user();
  if (!$u || empty($u['is_admin'])) { http_response_code(403); exit('Admins only'); }
}
