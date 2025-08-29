<?php
// config.php - bootstrap for Cub Scouts app
if (file_exists(__DIR__ . '/config.local.php')) {
  require __DIR__ . '/config.local.php';
} else {
  echo 'Needs config.local.php file';
}

session_start();

/**
 * Shared PDO connection
 */
function pdo() {
  static $pdo;
  if (!$pdo) {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }
  return $pdo;
}

/**
 * CSRF helpers
 */
function csrf_token() {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
  return $_SESSION['csrf'];
}
function require_csrf() {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
    if (!$ok) { http_response_code(400); exit('Bad CSRF'); }
  }
}
