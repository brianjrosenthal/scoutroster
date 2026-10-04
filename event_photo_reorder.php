<?php
// AJAX (POST, JSON, admin): set a manual photo order for an event
// (action=set, ids=comma-separated photo ids in the new order) or go back to
// chronological order (action=reset).
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/EventPhotos.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST required.']); exit; }
$u = current_user();
if (!$u) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Please sign in again.']); exit; }
if (empty($u['is_admin'])) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admins only.']); exit; }
require_csrf();

$ctx = UserContext::getLoggedInUserContext();
$eventId = (int)($_POST['event_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');

try {
  if ($action === 'set') {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))), static fn($i) => $i > 0));
    EventPhotos::reorder($ctx, $eventId, $ids);
    echo json_encode(['ok' => true, 'manual' => true]);
  } elseif ($action === 'reset') {
    EventPhotos::resetOrder($ctx, $eventId);
    echo json_encode(['ok' => true, 'manual' => false]);
  } elseif ($action === 'refresh_dates') {
    $n = EventPhotos::refreshEstimatedDates($ctx, $eventId);
    echo json_encode(['ok' => true, 'updated' => $n]);
  } else {
    throw new InvalidArgumentException('Unknown action.');
  }
} catch (Throwable $e) {
  http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
