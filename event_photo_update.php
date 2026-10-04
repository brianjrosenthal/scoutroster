<?php
// AJAX (POST, JSON): edit a photo's caption or its exclude-from-slideshow flag.
// Allowed for the uploader and admins (EventPhotos::canModify).
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/EventPhotos.php';
require_once __DIR__.'/lib/EventPhotosUI.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST required.']); exit; }
if (!current_user()) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Please sign in again.']); exit; }
require_csrf();

$ctx = UserContext::getLoggedInUserContext();
$photoId = (int)($_POST['photo_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');

try {
  $photo = EventPhotos::findById($photoId);
  if (!$photo) throw new RuntimeException('Photo not found.');
  if (!EventPhotos::canModify($ctx, $photo)) { http_response_code(403); throw new RuntimeException('You can only change photos you uploaded.'); }

  if ($action === 'caption') {
    $photo = EventPhotos::updateCaption($ctx, $photoId, (string)($_POST['caption'] ?? ''));
  } elseif ($action === 'exclude') {
    $photo = EventPhotos::setExcludeFromSlideshow($ctx, $photoId, ((string)($_POST['exclude'] ?? '0')) === '1');
  } else {
    throw new InvalidArgumentException('Unknown action.');
  }
  $photo['urls'] = EventPhotos::urlsFor($photo);
  $index = EventPhotos::positionOf((int)$photo['event_id'], $photoId);
  echo json_encode([
    'ok' => true,
    'photo' => ['id' => $photoId, 'caption' => $photo['caption'], 'exclude_from_slideshow' => (int)$photo['exclude_from_slideshow']],
    'tile_html' => EventPhotosUI::renderTile($photo, true, (bool)$ctx->admin, max(0, $index)),
  ]);
} catch (Throwable $e) {
  if (http_response_code() === 200) http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
