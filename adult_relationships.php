<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/UserManagement.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
require_csrf();

$action = $_POST['action'] ?? '';
$adultId = (int)($_POST['adult_id'] ?? 0);
$youthId = (int)($_POST['youth_id'] ?? 0);
$rel = $_POST['relationship'] ?? 'guardian';

$me = current_user();
$isAdmin = !empty($me['is_admin']);

if ($adultId <= 0 || $youthId <= 0) { http_response_code(400); exit('Invalid parameters'); }

// Validate adult and youth exist
try {
  $a = UserManagement::findById($adultId);
  if (!$a) { http_response_code(404); exit('Adult not found'); }

  $st = pdo()->prepare('SELECT id FROM youth WHERE id=? LIMIT 1');
  $st->execute([$youthId]);
  if (!$st->fetch()) { http_response_code(404); exit('Youth not found'); }
} catch (Throwable $e) {
  http_response_code(500); exit('Lookup failed');
}

$returnTo = isset($_POST['return_to']) ? (string)$_POST['return_to'] : '';
$back = '/adult_edit.php?id='.$adultId;
// Safe redirect targets only
if (is_string($returnTo) && strlen($returnTo) > 0) {
  if (strpos($returnTo, '/my_profile.php') === 0 || strpos($returnTo, '/youth_edit.php') === 0 || strpos($returnTo, '/adult_edit.php') === 0) {
    $back = $returnTo;
  }
}

// Authorization: admins OR parents of the youth may manage relationships
if (!$isAdmin) {
  $st = pdo()->prepare('SELECT 1 FROM parent_relationships WHERE youth_id=? AND adult_id=? LIMIT 1');
  $st->execute([$youthId, (int)$me['id']]);
  if (!$st->fetchColumn()) { http_response_code(403); exit('Not authorized'); }
}

try {
  if ($action === 'link') {
    if (!in_array($rel, ['father','mother','guardian'], true)) $rel = 'guardian';
    $st = pdo()->prepare('INSERT IGNORE INTO parent_relationships (youth_id, adult_id, relationship) VALUES (?,?,?)');
    $st->execute([$youthId, $adultId, $rel]);
    header('Location: '.$back); exit;
  } elseif ($action === 'unlink') {
    // Enforce at least one parent remains
    $cnt = pdo()->prepare('SELECT COUNT(*) FROM parent_relationships WHERE youth_id=?');
    $cnt->execute([$youthId]);
    $n = (int)$cnt->fetchColumn();
    if ($n <= 1) { http_response_code(400); exit('Cannot remove the last parent'); }

    $st = pdo()->prepare('DELETE FROM parent_relationships WHERE youth_id=? AND adult_id=?');
    $st->execute([$youthId, $adultId]);
    header('Location: '.$back); exit;
  } else {
    http_response_code(400); exit('Unknown action');
  }
} catch (Throwable $e) {
  http_response_code(500); exit('Operation failed');
}
