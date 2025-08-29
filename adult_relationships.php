<?php
require_once __DIR__.'/partials.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
require_csrf();

$action = $_POST['action'] ?? '';
$adultId = (int)($_POST['adult_id'] ?? 0);
$youthId = (int)($_POST['youth_id'] ?? 0);
$rel = $_POST['relationship'] ?? 'guardian';

if ($adultId <= 0 || $youthId <= 0) { http_response_code(400); exit('Invalid parameters'); }

// Validate adult and youth exist
try {
  $st = pdo()->prepare('SELECT id FROM users WHERE id=? LIMIT 1');
  $st->execute([$adultId]);
  if (!$st->fetch()) { http_response_code(404); exit('Adult not found'); }

  $st = pdo()->prepare('SELECT id FROM youth WHERE id=? LIMIT 1');
  $st->execute([$youthId]);
  if (!$st->fetch()) { http_response_code(404); exit('Youth not found'); }
} catch (Throwable $e) {
  http_response_code(500); exit('Lookup failed');
}

$back = '/adult_edit.php?id='.$adultId;

try {
  if ($action === 'link') {
    if (!in_array($rel, ['father','mother','guardian'], true)) $rel = 'guardian';
    $st = pdo()->prepare('INSERT IGNORE INTO parent_relationships (youth_id, adult_id, relationship) VALUES (?,?,?)');
    $st->execute([$youthId, $adultId, $rel]);
    header('Location: '.$back); exit;
  } elseif ($action === 'unlink') {
    $st = pdo()->prepare('DELETE FROM parent_relationships WHERE youth_id=? AND adult_id=?');
    $st->execute([$youthId, $adultId]);
    header('Location: '.$back); exit;
  } else {
    http_response_code(400); exit('Unknown action');
  }
} catch (Throwable $e) {
  http_response_code(500); exit('Operation failed');
}
