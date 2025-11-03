<?php
require_once __DIR__ . '/partials.php';
require_login();
require_once __DIR__ . '/lib/Volunteers.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/UserManagement.php';

// Only Key 3 (approvers) can use this
$me = current_user();
if (!UserManagement::isApprover((int)$me['id'])) {
  http_response_code(403);
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'Access denied. Only Key 3 leaders can sign up others.']);
  exit;
}

// Accept POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
  exit;
}

require_csrf();

$eventId = (int)($_POST['event_id'] ?? 0);
$roleId  = (int)($_POST['role_id'] ?? 0);
$userId  = (int)($_POST['user_id'] ?? 0);
$comment = isset($_POST['comment']) ? trim((string)$_POST['comment']) : null;

if ($eventId <= 0 || $roleId <= 0 || $userId <= 0) {
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
  exit;
}

// Verify event exists
$event = EventManagement::findById($eventId);
if (!$event) {
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'Event not found.']);
  exit;
}

// Verify user exists
$user = UserManagement::findById($userId);
if (!$user) {
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'User not found.']);
  exit;
}

try {
  Volunteers::adminSignup($eventId, $roleId, $userId, $comment);
  
  header('Content-Type: application/json');
  echo json_encode([
    'ok' => true,
    'message' => 'Signup successful.',
    'roles' => Volunteers::rolesWithCounts($eventId),
    'event_id' => $eventId,
    'csrf' => csrf_token(),
  ]);
} catch (Throwable $e) {
  $msg = $e->getMessage() ?: 'Admin signup failed.';
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => $msg]);
}
