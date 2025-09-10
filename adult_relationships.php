<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/UserManagement.php';
require_once __DIR__.'/lib/YouthManagement.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_once __DIR__.'/lib/ActivityLog.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
require_csrf();

$ajax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
if (!function_exists('respond_json')) {
  function respond_json(bool $ok, ?string $error = null): void {
    header('Content-Type: application/json');
    $out = ['ok' => $ok];
    if ($error !== null && $error !== '') $out['error'] = $error;
    echo json_encode($out);
    exit;
  }
}

$action = $_POST['action'] ?? '';
$adultId = (int)($_POST['adult_id'] ?? 0);
$youthId = (int)($_POST['youth_id'] ?? 0);

$me = current_user();
$isAdmin = !empty($me['is_admin']);

if ($adultId <= 0 && $action !== 'create_and_link' && $action !== 'create_adult_and_link') { 
  if ($ajax) { respond_json(false, 'Invalid adult'); }
  http_response_code(400); exit('Invalid parameters'); 
}
if ($action !== 'create_and_link' && $youthId <= 0) { 
  if ($ajax) { respond_json(false, 'Invalid youth'); }
  http_response_code(400); exit('Invalid parameters'); 
}

 // Validate adult and youth exist
try {
  if ($action !== 'create_and_link' && $action !== 'create_adult_and_link') {
    $a = UserManagement::findById($adultId);
    if (!$a) { if ($ajax) { respond_json(false, 'Adult not found'); } http_response_code(404); exit('Adult not found'); }
  }

  if ($action !== 'create_and_link') {
    $st = pdo()->prepare('SELECT id FROM youth WHERE id=? LIMIT 1');
    $st->execute([$youthId]);
    if (!$st->fetch()) { if ($ajax) { respond_json(false, 'Youth not found'); } http_response_code(404); exit('Youth not found'); }
  }
} catch (Throwable $e) {
  if ($ajax) { respond_json(false, 'Lookup failed'); }
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
if (!$isAdmin && $action !== 'create_and_link') {
  $st = pdo()->prepare('SELECT 1 FROM parent_relationships WHERE youth_id=? AND adult_id=? LIMIT 1');
  $st->execute([$youthId, (int)$me['id']]);
  if (!$st->fetchColumn()) { 
    if ($ajax) { respond_json(false, 'Not authorized'); }
    http_response_code(403); exit('Not authorized'); 
  }
}

try {
  if ($action === 'link') {
    $st = pdo()->prepare('INSERT IGNORE INTO parent_relationships (youth_id, adult_id) VALUES (?, ?)');
    $st->execute([$youthId, $adultId]);
    if ((int)$st->rowCount() > 0) {
      ActivityLog::log(UserContext::getLoggedInUserContext(), 'user.child_link_add', [
        'parent_id' => (int)$adultId,
        'youth_id' => (int)$youthId,
      ]);
    }
    if ($ajax) { respond_json(true, null); }
    header('Location: '.$back); exit;
  } elseif ($action === 'unlink') {
    // Enforce at least one parent remains
    $cnt = pdo()->prepare('SELECT COUNT(*) FROM parent_relationships WHERE youth_id=?');
    $cnt->execute([$youthId]);
    $n = (int)$cnt->fetchColumn();
    if ($n <= 1) { http_response_code(400); exit('Cannot remove the last parent'); }

    $st = pdo()->prepare('DELETE FROM parent_relationships WHERE youth_id=? AND adult_id=?');
    $st->execute([$youthId, $adultId]);
    if ((int)$st->rowCount() > 0) {
      ActivityLog::log(UserContext::getLoggedInUserContext(), 'user.child_link_remove', [
        'parent_id' => (int)$adultId,
        'youth_id' => (int)$youthId,
      ]);
    }
    if ($ajax) { respond_json(true, null); }
    header('Location: '.$back); exit;
  } elseif ($action === 'create_and_link') {
    // Admin-only
    if (!$isAdmin) { if ($ajax) { respond_json(false, 'Admins only'); } http_response_code(403); exit('Admins only'); }
    // Minimal child fields
    $first = trim((string)($_POST['first_name'] ?? ''));
    $last  = trim((string)($_POST['last_name'] ?? ''));
    $suffix = trim((string)($_POST['suffix'] ?? ''));
    $preferred = trim((string)($_POST['preferred_name'] ?? ''));
    $gradeLabel = trim((string)($_POST['grade'] ?? ''));
    $school = trim((string)($_POST['school'] ?? ''));
    $sibling = !empty($_POST['sibling']) ? 1 : 0;

    $errors = [];
    if ($first === '') $errors[] = 'First name is required.';
    if ($last === '')  $errors[] = 'Last name is required.';
    $g = \GradeCalculator::parseGradeLabel($gradeLabel);
    if ($g === null) $errors[] = 'Grade is required.';
    if (!empty($errors)) {
      $msg = implode(' ', $errors);
      if ($ajax) { respond_json(false, $msg); }
      http_response_code(400); exit($msg);
    }

    $ctx = UserContext::getLoggedInUserContext();
    $pdoTx = pdo();
    $pdoTx->beginTransaction();
    try {
      $data = [
        'first_name' => $first,
        'last_name' => $last,
        'suffix' => $suffix,
        'preferred_name' => $preferred,
        'grade_label' => $gradeLabel,
        'school' => $school,
        'sibling' => $sibling,
      ];
      // Create youth
      $newYid = YouthManagement::create($ctx, $data);

      // Link to adult
      $st = $pdoTx->prepare('INSERT INTO parent_relationships (youth_id, adult_id) VALUES (?, ?)');
      $st->execute([$newYid, $adultId]);

      $pdoTx->commit();
      ActivityLog::log($ctx, 'user.child_link_add', [
        'parent_id' => (int)$adultId,
        'youth_id' => (int)$newYid,
      ]);
      if ($ajax) { respond_json(true, null); }
      header('Location: '.$back); exit;
    } catch (Throwable $ex) {
      if ($pdoTx->inTransaction()) $pdoTx->rollBack();
      if ($ajax) { respond_json(false, 'Operation failed'); }
      http_response_code(500); exit('Operation failed');
    }
  } elseif ($action === 'create_adult_and_link') {
    // Admin-only: create a new adult and link to an existing youth
    if (!$isAdmin) { if ($ajax) { respond_json(false, 'Admins only'); } http_response_code(403); exit('Admins only'); }
    if ($youthId <= 0) { if ($ajax) { respond_json(false, 'Invalid youth'); } http_response_code(400); exit('Invalid parameters'); }

    // Validate youth exists
    $stY = pdo()->prepare('SELECT id FROM youth WHERE id=? LIMIT 1');
    $stY->execute([$youthId]);
    if (!$stY->fetch()) { if ($ajax) { respond_json(false, 'Youth not found'); } http_response_code(404); exit('Youth not found'); }

    // Adult fields
    $first = trim((string)($_POST['first_name'] ?? ''));
    $last  = trim((string)($_POST['last_name'] ?? ''));
    $preferred_name = trim((string)($_POST['preferred_name'] ?? ''));
    $rawEmail = trim((string)($_POST['email'] ?? ''));
    $email = ($rawEmail === '' ? null : strtolower($rawEmail));
    $phone_home = trim((string)($_POST['phone_home'] ?? ''));
    $phone_cell = trim((string)($_POST['phone_cell'] ?? ''));

    $errors = [];
    if ($first === '') $errors[] = 'First name is required.';
    if ($last === '')  $errors[] = 'Last name is required.';
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Email is invalid.';
    }
    if ($email !== null) {
      if (UserManagement::emailExists($email)) {
        $errors[] = 'Email already in use.';
      }
    }
    if (!empty($errors)) {
      $msg = implode(' ', $errors);
      if ($ajax) { respond_json(false, $msg); }
      http_response_code(400); exit($msg);
    }

    try {
      $ctx = UserContext::getLoggedInUserContext();
      $newAdultId = UserManagement::createAdultWithDetails($ctx, [
        'first_name' => $first,
        'last_name' => $last,
        'email' => $email,
        'is_admin' => 0,
        'preferred_name' => ($preferred_name === '' ? null : $preferred_name),
        'phone_home' => ($phone_home === '' ? null : $phone_home),
        'phone_cell' => ($phone_cell === '' ? null : $phone_cell),
      ]);

      $st = pdo()->prepare('INSERT IGNORE INTO parent_relationships (youth_id, adult_id) VALUES (?, ?)');
      $st->execute([$youthId, (int)$newAdultId]);

      if ((int)$st->rowCount() > 0) {
        ActivityLog::log($ctx, 'user.child_link_add', [
          'parent_id' => (int)$newAdultId,
          'youth_id' => (int)$youthId,
        ]);
      }
      if ($ajax) { respond_json(true, null); }
      header('Location: '.$back); exit;
    } catch (Throwable $ex) {
      if ($ajax) { respond_json(false, 'Operation failed'); }
      http_response_code(500); exit('Operation failed');
    }
  } else {
    if ($ajax) { respond_json(false, 'Unknown action'); }
    http_response_code(400); exit('Unknown action');
  }
} catch (Throwable $e) {
  if ($ajax) { respond_json(false, 'Operation failed'); }
  http_response_code(500); exit('Operation failed');
}
