<?php
// AJAX (POST, JSON): hands the browser presigned URLs to PUT the three
// renditions of ONE photo (original, 2048px display, 400px thumb) straight
// into R2. The secret key never leaves the server; each URL authorizes exactly
// one object key for PhotoStorage::UPLOAD_URL_TTL seconds. No row is written
// until event_photo_attach.php verifies the objects.
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/PhotoStorage.php';
require_once __DIR__.'/lib/EventPhotos.php';
require_once __DIR__.'/lib/ActivityLog.php';

header('Content-Type: application/json');

function presign_fail(string $message, int $status = 400): void {
  http_response_code($status);
  echo json_encode(['ok' => false, 'error' => $message]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') presign_fail('POST required.', 405);
if (!current_user()) presign_fail('Please sign in again.', 401);
require_csrf();
if (!PhotoStorage::isConfigured()) presign_fail('Photo storage is not configured.', 503);

$ctx = UserContext::getLoggedInUserContext();
$eventId = (int)($_POST['event_id'] ?? 0);
$contentType = PhotoStorage::normalizeContentType((string)($_POST['content_type'] ?? ''));
$size = (int)($_POST['size'] ?? 0);
$sha = strtolower(trim((string)($_POST['sha256'] ?? '')));

if (!EventPhotos::canUpload($ctx, $eventId)) presign_fail('Event not found.', 404);
if (PhotoStorage::kindOf($contentType) !== 'image') {
  presign_fail('Unsupported image type "' . $contentType . '". Please use a JPEG, PNG, WebP or GIF file.');
}
if ($size <= 0) presign_fail('The file is empty.');
if ($size > PhotoStorage::maxBytes('image')) {
  presign_fail('That photo is larger than the ' . PhotoStorage::humanBytes(PhotoStorage::maxBytes('image')) . ' limit.', 413);
}

if ($sha !== '' && ($dup = EventPhotos::findDuplicate($eventId, $sha)) !== null) {
  echo json_encode(['ok' => true, 'duplicate' => true, 'photo_id' => (int)$dup['id']]);
  exit;
}

try {
  $stem = PhotoStorage::newStem();
  $keys = PhotoStorage::photoKeys($eventId, $stem, $contentType);
  $grants = [
    'original' => ['key' => $keys['original']] + PhotoStorage::presignUploadFor($keys['original'], $contentType),
    'display'  => ['key' => $keys['display']]  + PhotoStorage::presignUploadFor($keys['display'], 'image/jpeg'),
    'thumb'    => ['key' => $keys['thumb']]    + PhotoStorage::presignUploadFor($keys['thumb'], 'image/jpeg'),
  ];
  ActivityLog::log($ctx, 'event_photo.presign', ['event_id' => $eventId, 'stem' => $stem, 'size_bytes' => $size]);
  echo json_encode(['ok' => true, 'stem' => $stem, 'expires_in' => PhotoStorage::UPLOAD_URL_TTL, 'grants' => $grants]);
} catch (Throwable $e) {
  presign_fail('Could not prepare the upload: ' . $e->getMessage(), 500);
}
