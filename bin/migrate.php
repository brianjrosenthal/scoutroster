#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Simple migration runner for Cub Scouts app.
 * - Applies .sql files in db_migrations/ in natural-sorted order
 * - Tracks applied files in applied_migrations table
 * - Runs inside a transaction per migration
 *
 * Usage:
 *   php cub_scouts/bin/migrate.php
 *
 * Requires:
 *   cub_scouts/config.local.php configured with DB credentials
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';

if (php_sapi_name() !== 'cli') {
  header('Content-Type: text/plain; charset=utf-8');
  echo "Run this script from the command line: php cub_scouts/bin/migrate.php\n";
  exit(1);
}

function splitSqlStatements(string $sql): array {
  // Naive splitter safe for our simple CREATE/ALTER/INSERT migrations (no DELIMITER blocks)
  $lines = preg_split("/\\r?\\n/", $sql);
  $stmts = [];
  $current = '';
  foreach ($lines as $line) {
    // Skip single-line comments starting with --
    if (preg_match('/^\\s*--/', $line)) {
      continue;
    }
    $current .= $line . "\n";
    // Statement ends at a semicolon at end of trimmed line
    if (preg_match('/;\\s*$/', trim($line))) {
      $stmts[] = $current;
      $current = '';
    }
  }
  if (trim($current) !== '') {
    $stmts[] = $current;
  }
  return $stmts;
}

try {
  $pdo = pdo();
} catch (Throwable $e) {
  fwrite(STDERR, "Failed to connect to database. Check cub_scouts/config.local.php\nError: " . $e->getMessage() . "\n");
  exit(2);
}

echo "Connected to MySQL.\n";

// Track applied migrations
$pdo->exec("
  CREATE TABLE IF NOT EXISTS applied_migrations (
    filename VARCHAR(255) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB
");

$applied = $pdo->query("SELECT filename FROM applied_migrations")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$appliedSet = array_fill_keys($applied, true);

// Find .sql files
$dir = $root . '/db_migrations';
$files = glob($dir . '/*.sql') ?: [];
natsort($files);
$files = array_values($files);

$pending = [];
foreach ($files as $path) {
  $base = basename($path);
  if (!isset($appliedSet[$base])) {
    $pending[] = $path;
  }
}

if (empty($pending)) {
  echo "No pending migrations.\n";
  exit(0);
}

echo "Pending migrations:\n";
foreach ($pending as $p) {
  echo "  - " . basename($p) . "\n";
}
echo "\n";

foreach ($pending as $path) {
  $base = basename($path);
  echo "Applying $base ... ";
  $sql = file_get_contents($path);
  if ($sql === false) {
    fwrite(STDERR, "FAILED to read file.\n");
    exit(3);
  }

  try {
    $pdo->beginTransaction();

    $statements = splitSqlStatements($sql);
    foreach ($statements as $stmt) {
      $trim = trim($stmt);
      if ($trim === '') continue;
      $pdo->exec($trim);
    }

    $ins = $pdo->prepare("INSERT INTO applied_migrations (filename) VALUES (?)");
    $ins->execute([$base]);

    $pdo->commit();
    echo "done.\n";
  } catch (Throwable $e) {
    $pdo->rollBack();
    echo "FAILED.\n";
    fwrite(STDERR, "Error applying $base: " . $e->getMessage() . "\n");
    exit(4);
  }
}

echo "\nAll pending migrations applied successfully.\n";
