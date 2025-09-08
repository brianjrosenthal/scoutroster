<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/Reimbursements.php';
require_login();

// Secure file download for reimbursement attachments by file row id (reimbursement_request_files.id)
$fileRowId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($fileRowId <= 0) { http_response_code(400); exit('Missing id'); }

$ctx = UserContext::getLoggedInUserContext();

// Load file row and parent request for authorization
$st = pdo()->prepare("SELECT f.*, r.id AS req_id, r.created_by, r.status
                      FROM reimbursement_request_files f
                      JOIN reimbursement_requests r ON r.id = f.reimbursement_request_id
                      WHERE f.id = ? LIMIT 1");
$st->execute([$fileRowId]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit('Not found'); }

// Authorization using existing Reimbursements permission model
$req = [
  'id' => (int)$row['req_id'],
  'created_by' => (int)$row['created_by'],
  'status' => (string)$row['status'],
];
if (!Reimbursements::canView($ctx, $req)) { http_response_code(403); exit('Forbidden'); }

// Prefer DB blob if present, else fallback to legacy filesystem path
$secureFileId = isset($row['secure_file_id']) ? (int)$row['secure_file_id'] : 0;

if ($secureFileId > 0) {
  $st2 = pdo()->prepare("SELECT data, content_type, original_filename, byte_length FROM secure_files WHERE id = ? LIMIT 1");
  $st2->execute([$secureFileId]);
  $blob = $st2->fetch();
  if (!$blob) { http_response_code(404); exit('File missing'); }

  $data = (string)$blob['data'];
  $len = (int)($blob['byte_length'] ?? strlen($data));
  $ctype = (string)($blob['content_type'] ?? '');
  if ($ctype === '') $ctype = 'application/octet-stream';
  $name = (string)($blob['original_filename'] ?? $row['original_filename'] ?? 'file');

  header('Content-Type: ' . $ctype);
  header('Content-Length: ' . $len);
  header('Content-Disposition: inline; filename="' . rawbasename($name) . '"');
  header('X-Content-Type-Options: nosniff');
  echo $data;
  exit;
}

// Legacy fallback: stored_path on disk
$storedRel = (string)($row['stored_path'] ?? '');
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
function rawbasename(string $name): string {
  $name = str_replace('"', '', $name);
  return basename($name);
}
