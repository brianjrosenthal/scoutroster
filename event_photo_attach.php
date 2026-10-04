<?php
// AJAX (POST, JSON): records a photo whose three renditions the browser has
// PUT to R2. The server recomputes the keys from the stem, HEADs each object
// to verify type and size, and only then inserts the row. Returns the rendered
// tile and where it belongs in the gallery order.
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/PhotoStorage.php';
require_once __DIR__.'/lib/EventPhotos.php';
require_once __DIR__.'/lib/EventPhotosUI.php';

header('Content-Type: application/json');

function attach_fail(string $message, int $status = 400): void {
  http_response_code($status);
  echo json_encode(['ok' => false, 'error' => $message]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') attach_fail('POST required.', 405);
if (!current_user()) attach_fail('Please sign in again.', 401);
require_csrf();

$ctx = UserContext::getLoggedInUserContext();
$eventId = (int)($_POST['event_id'] ?? 0);
$stem = strtolower(trim((string)($_POST['stem'] ?? '')));
$contentType = (string)($_POST['content_type'] ?? '');

$takenAt = trim((string)($_POST['taken_at'] ?? ''));
$source = (string)($_POST['taken_at_source'] ?? 'upload');
if ($takenAt !== '') {
  $dt = DateTime::createFromFormat('Y-m-d H:i:s', $takenAt);
  $ok = $dt && $dt->format('Y-m-d H:i:s') === $takenAt
     && $dt->getTimestamp() >= strtotime('1990-01-01') && $dt->getTimestamp() <= time() + 86400;
  if (!$ok) { $takenAt = ''; $source = 'upload'; }
}

try {
  $photo = EventPhotos::attachUploaded($ctx, $eventId, $stem, $contentType, [
    'width'  => (int)($_POST['width'] ?? 0),
    'height' => (int)($_POST['height'] ?? 0),
    'sha256' => (string)($_POST['sha256'] ?? ''),
    'taken_at' => $takenAt !== '' ? $takenAt : null,
    'taken_at_source' => $source,
    'caption' => $_POST['caption'] ?? null,
  ]);
  $photo['urls'] = EventPhotos::urlsFor($photo);
  $index = EventPhotos::positionOf($eventId, (int)$photo['id']);
  echo json_encode([
    'ok' => true,
    'photo' => [
      'id' => (int)$photo['id'], 'event_id' => (int)$photo['event_id'],
      'caption' => $photo['caption'], 'exclude_from_slideshow' => (int)$photo['exclude_from_slideshow'],
      'taken_at' => $photo['taken_at'], 'taken_at_source' => $photo['taken_at_source'],
      'width' => (int)$photo['width'], 'height' => (int)$photo['height'], 'urls' => $photo['urls'],
    ],
    'tile_html' => EventPhotosUI::renderTile($photo, true, (bool)$ctx->admin, max(0, $index)),
    'index' => $index,
    'count' => EventPhotos::countForEvent($eventId),
  ]);
} catch (InvalidArgumentException $e) {
  attach_fail($e->getMessage(), 400);
} catch (Throwable $e) {
  attach_fail($e->getMessage(), 500);
}
