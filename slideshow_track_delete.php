<?php
// POST (admin): delete a music track that no section uses. Accepts a plain
// form POST (redirects back to slideshows.php) or JSON when requested.
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/SlideshowTracks.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
require_csrf();

$wantsJson = strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
$trackId = (int)($_POST['track_id'] ?? 0);
$msg = null; $err = null;
try {
  $ctx = UserContext::getLoggedInUserContext();
  $ok = SlideshowTracks::delete($ctx, $trackId);
  $msg = $ok ? 'Track deleted.' : 'Track not found.';
} catch (Throwable $e) {
  $err = $e->getMessage();
}
if ($wantsJson) {
  header('Content-Type: application/json');
  echo json_encode(['ok' => $err === null, 'error' => $err, 'message' => $msg]);
  exit;
}
header('Location: /slideshows.php?' . http_build_query($err !== null ? ['err' => $err] : ['msg' => $msg]));
exit;
