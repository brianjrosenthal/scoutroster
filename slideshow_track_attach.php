<?php
// AJAX (POST, JSON, admin): record an uploaded music file and optionally
// assign it to a slideshow section.
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/SlideshowTracks.php';
require_once __DIR__.'/lib/Slideshows.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST required.']); exit; }
$u = current_user();
if (!$u) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Please sign in again.']); exit; }
if (empty($u['is_admin'])) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admins only.']); exit; }
require_csrf();

try {
  $ctx = UserContext::getLoggedInUserContext();
  $durationRaw = trim((string)($_POST['duration_seconds'] ?? ''));
  $duration = ($durationRaw !== '' && is_numeric($durationRaw)) ? (float)$durationRaw : null;
  $trackId = SlideshowTracks::attach($ctx, (string)($_POST['key'] ?? ''), (string)($_POST['title'] ?? ''), (string)($_POST['content_type'] ?? ''), $duration);
  $track = SlideshowTracks::findById($trackId);
  $section = null;
  $sectionId = (int)($_POST['section_id'] ?? 0);
  if ($sectionId > 0) {
    Slideshows::setSectionTrack($ctx, $sectionId, $trackId);
    $s = Slideshows::findSection($sectionId);
    if ($s) {
      foreach (Slideshows::listSections((int)$s['slideshow_id']) as $row) {
        if ((int)$row['id'] === $sectionId) { $section = ['id' => $sectionId, 'timing' => $row['timing']]; break; }
      }
    }
  }
  echo json_encode([
    'ok' => true,
    'track' => ['id' => $trackId, 'title' => $track['title'], 'duration_seconds' => $track['duration_seconds'] !== null ? (float)$track['duration_seconds'] : null],
    'section' => $section,
  ]);
} catch (InvalidArgumentException $e) {
  http_response_code(400); echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
  http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
