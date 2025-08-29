<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/lib/UserManagement.php';

$token = $_GET['token'] ?? '';
$token = is_string($token) ? trim($token) : '';

if ($token === '') {
  header('Location: /login.php?verify_error=1'); exit;
}

if (!UserManagement::verifyByToken($token)) {
  header('Location: /login.php?verify_error=1'); exit;
}

header('Location: /login.php?verified=1'); exit;
