<?php
// AJAX (POST, JSON): delete a photo (R2 objects first, then the row).
// Allowed for the uploader and admins.
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/EventPhotos.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST required.']); exit; }
if (!current_user()) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Please sign in again.']); exit; }
require_csrf();

$ctx = UserContext::getLoggedInUserContext();
$photoId = (int)($_POST['photo_id'] ?? 0);

try {
  $photo = EventPhotos::findById($photoId);
  if (!$photo) throw new RuntimeException('Photo not found.');
  if (!EventPhotos::canModify($ctx, $photo)) { http_response_code(403); throw new RuntimeException('You can only delete photos you uploaded.'); }
  EventPhotos::delete($ctx, $photoId);
  echo json_encode(['ok' => true, 'count' => EventPhotos::countForEvent((int)$photo['event_id'])]);
} catch (Throwable $e) {
  if (http_response_code() === 200) http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
