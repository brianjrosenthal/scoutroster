<?php
// AJAX (POST, JSON, admin): a presigned URL to PUT one music file into R2.
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/SlideshowTracks.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST required.']); exit; }
$u = current_user();
if (!$u) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Please sign in again.']); exit; }
if (empty($u['is_admin'])) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admins only.']); exit; }
require_csrf();

try {
  $ctx = UserContext::getLoggedInUserContext();
  $grant = SlideshowTracks::presign($ctx, (string)($_POST['content_type'] ?? ''), (int)($_POST['byte_length'] ?? 0));
  echo json_encode(['ok' => true] + $grant);
} catch (InvalidArgumentException $e) {
  http_response_code(400); echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
  http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
