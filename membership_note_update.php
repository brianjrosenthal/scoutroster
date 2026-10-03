<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/UserContext.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/YouthManagement.php';
require_login();

/**
 * AJAX: save a roster note (membership_info_note or training_note) for an adult or youth (admins only).
 * POST csrf, type=adult|youth, id, field=membership_info_note|training_note (default membership_info_note), note
 *   ->  JSON {ok, field, note}
 */
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}
require_csrf();

$me = current_user();
if (empty($me['is_admin'])) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Admins only']);
  exit;
}

$type = (string)($_POST['type'] ?? '');
$id = (int)($_POST['id'] ?? 0);
$field = (string)($_POST['field'] ?? 'membership_info_note');
$note = trim((string)($_POST['note'] ?? ''));
if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);
if ($id <= 0 || !in_array($type, ['adult', 'youth'], true) || !in_array($field, ['membership_info_note', 'training_note'], true)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid request']);
  exit;
}

try {
  $ctx = UserContext::getLoggedInUserContext();
  if ($type === 'adult') {
    UserManagement::updateProfile($ctx, $id, [$field => $note], true);
  } else {
    YouthManagement::update($ctx, $id, [$field => $note]);
  }
  echo json_encode(['ok' => true, 'field' => $field, 'note' => $note]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage() ?: 'Failed to save note']);
}
