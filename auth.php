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
  
  // If no session, check for remember token
  if (!empty($_COOKIE['remember_token'])) {
    $u = validate_remember_token($_COOKIE['remember_token']);
    if ($u) {
      // Auto-login: create new session
      session_regenerate_id(true);
      $_SESSION['uid'] = $u['id'];
      $_SESSION['is_admin'] = !empty($u['is_admin']) ? 1 : 0;
      $_SESSION['last_activity'] = time();
      // Note: Don't set public_computer flag for remember token logins
      
      // Seed per-request UserContext
      if (class_exists('UserContext')) {
        UserContext::set(new UserContext((int)$u['id'], !empty($u['is_admin'])));
      }
      return $u;
    }
  }
  
  return null;
}

function validate_remember_token($cookie_value) {
  if (!defined('REMEMBER_TOKEN_KEY') || REMEMBER_TOKEN_KEY === '') {
    return null; // Remember tokens disabled if no key configured
  }
  
  $parts = explode('|', $cookie_value, 2);
  if (count($parts) !== 2) {
    return null; // Invalid format
  }
  
  list($user_id, $provided_token) = $parts;
  if (!is_numeric($user_id)) {
    return null; // Invalid user ID
  }
  
  $u = UserManagement::findFullById((int)$user_id);
  if (!$u) {
    return null; // User not found
  }
  
  // Generate expected token based on user ID and current password hash
  $expected_token = hash_hmac('sha256', $user_id . '|' . $u['password_hash'], REMEMBER_TOKEN_KEY);
  
  if (hash_equals($expected_token, $provided_token)) {
    return $u; // Valid token
  }
  
  return null; // Invalid token
}

function create_remember_token($user_id, $password_hash) {
  if (!defined('REMEMBER_TOKEN_KEY') || REMEMBER_TOKEN_KEY === '') {
    return null; // Remember tokens disabled if no key configured
  }
  
  $token = hash_hmac('sha256', $user_id . '|' . $password_hash, REMEMBER_TOKEN_KEY);
  return $user_id . '|' . $token;
}

function clear_remember_token() {
  if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', true, true); // Expire the cookie
  }
}

function clear_all_remember_tokens_for_user($user_id) {
  // Since we're using password hash-based tokens, when the password changes,
  // all existing tokens automatically become invalid. We just need to clear
  // the current user's cookie if they're the one changing their password.
  $current_user = current_user();
  if ($current_user && (int)$current_user['id'] === (int)$user_id) {
    clear_remember_token();
  }
}

function current_user_optional() {
  return current_user(); // Returns null if not logged in, doesn't redirect
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
