<?php
// GET (JSON): the manifest the player consumes for one slideshow.
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/Slideshows.php';
require_login();

header('Content-Type: application/json');
header('Cache-Control: private, no-store');

$id = (int)($_GET['id'] ?? 0);
$ctx = UserContext::getLoggedInUserContext();
$show = Slideshows::findById($id);
if (!$show) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Slideshow not found.']); exit; }
if (!Slideshows::canView($ctx, $show)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'This slideshow is not published yet.']); exit; }
if (!PhotoStorage::isConfigured()) { http_response_code(503); echo json_encode(['ok' => false, 'error' => 'Photo storage is not configured.']); exit; }
try {
  echo json_encode(Slideshows::buildManifest($ctx, $id), JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
