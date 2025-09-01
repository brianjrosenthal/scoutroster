<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/Reimbursements.php';
require_login();

// Input
$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($fileId <= 0) { http_response_code(400); exit('Missing id'); }

$ctx = UserContext::getLoggedInUserContext();

// Load file and owning request; authorize viewer
$st = pdo()->prepare("SELECT f.*, r.created_by, r.status, r.id AS req_id
                      FROM reimbursement_request_files f
                      JOIN reimbursement_requests r ON r.id = f.reimbursement_request_id
                      WHERE f.id = ? LIMIT 1");
$st->execute([$fileId]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit('Not found'); }

// Authorization: creator, approver, or admin
$req = [
  'id' => (int)$row['req_id'],
  'created_by' => (int)$row['created_by'],
  'status' => (string)$row['status'],
];
if (!Reimbursements::canView($ctx, $req)) { http_response_code(403); exit('Forbidden'); }

// Resolve path and stream
$storedRel = (string)$row['stored_path'];
// Sanity check: ensure it stays within uploads/reimbursements directory
$base = realpath(__DIR__ . '/uploads/reimbursements');
$abs  = realpath(__DIR__ . '/' . $storedRel);
if ($base === false || $abs === false || strpos($abs, $base) !== 0) {
  http_response_code(404); exit('File not accessible');
}

if (!is_file($abs)) { http_response_code(404); exit('File missing'); }

$orig = $row['original_filename'] ?: basename($abs);
$mime = 'application/octet-stream';
$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
if ($ext === 'pdf') $mime = 'application/pdf';
elseif (in_array($ext, ['jpg','jpeg'])) $mime = 'image/jpeg';
elseif ($ext === 'png') $mime = 'image/png';
elseif ($ext === 'webp') $mime = 'image/webp';
elseif ($ext === 'heic') $mime = 'image/heic';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: inline; filename="' . rawbasename($orig) . '"');
header('X-Content-Type-Options: nosniff');
readfile($abs);
exit;

// Helper: rawbasename preserving multibyte while escaping quotes
function rawbasename($name) {
  $name = str_replace('"', '', $name);
  return $name;
}
