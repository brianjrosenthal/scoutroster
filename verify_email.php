<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/lib/UserManagement.php';

$token = $_GET['token'] ?? '';
$token = is_string($token) ? trim($token) : '';
if ($token === '') { header('Location: /login.php?verify_error=1'); exit; }

// Find user by token first (to obtain id/email), then verify and force a password reset step
$u = UserManagement::getByVerifyToken($token);
if (!$u) { header('Location: /login.php?verify_error=1'); exit; }

if (!UserManagement::verifyByToken($token)) {
  header('Location: /login.php?verify_error=1'); exit;
}

// After verifying, generate a password reset token and redirect to reset flow
try {
  $plain = bin2hex(random_bytes(32));          // 64 hex chars
  $hash  = hash('sha256', $plain);
  UserManagement::setPasswordResetToken((int)$u['id'], $hash, 30); // 30-minute validity

  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $resetUrl = $scheme.'://'.$host.'/reset_password.php?email='.urlencode((string)$u['email']).'&token='.urlencode($plain).'&activated=1';
  header('Location: '.$resetUrl); exit;
} catch (Throwable $e) {
  // Fallback: if reset token creation fails, at least mark verified and land on login
  header('Location: /login.php?verified=1'); exit;
}
