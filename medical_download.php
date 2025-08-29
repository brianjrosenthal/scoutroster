<?php
require_once __DIR__.'/partials.php';
require_login();

$me = current_user();
$isAdmin = !empty($me['is_admin']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

// Load medical form record
$st = pdo()->prepare("SELECT * FROM medical_forms WHERE id=? LIMIT 1");
$st->execute([$id]);
$mf = $st->fetch();
if (!$mf) { http_response_code(404); exit('Not found'); }

// Authorization:
// - Admins: allowed
// - Adult type: owner adult_id === current user id
// - Youth type: current user must be a parent of the youth
$authorized = false;
if ($isAdmin) {
  $authorized = true;
} elseif ($mf['type'] === 'adult') {
  $authorized = ((int)$mf['adult_id'] === (int)$me['id']);
} elseif ($mf['type'] === 'youth' && !empty($mf['youth_id'])) {
  $st = pdo()->prepare("SELECT 1 FROM parent_relationships WHERE youth_id=? AND adult_id=? LIMIT 1");
  $st->execute([(int)$mf['youth_id'], (int)$me['id']]);
  $authorized = (bool)$st->fetchColumn();
}
if (!$authorized) { http_response_code(403); exit('Not authorized'); }

// Path safety checks
$rel = (string)($mf['file_path'] ?? '');
if ($rel === '' || strpos($rel, 'uploads/medical/') !== 0) {
  http_response_code(404); exit('File missing');
}
$abs = __DIR__ . '/' . $rel;
if (!is_file($abs)) { http_response_code(404); exit('File missing'); }

// Stream PDF
$filename = (string)($mf['original_filename'] ?? 'medical.pdf');
$filename = $filename !== '' ? $filename : 'medical.pdf';

$filesize = filesize($abs);
$mime = 'application/pdf';

header('Content-Type: '.$mime);
header('Content-Length: ' . ($filesize !== false ? (string)$filesize : ''));
header('Content-Disposition: inline; filename="'.rawbasename($filename).'"');
header('X-Content-Type-Options: nosniff');

// Output the file
$fp = fopen($abs, 'rb');
if ($fp) {
  fpassthru($fp);
  fclose($fp);
  exit;
} else {
  http_response_code(500); exit('Unable to open file');
}

// Helper for safe basename preserving utf-8
function rawbasename(string $path): string {
  $b = basename($path);
  // Prevent header injection
  return str_replace(['"', "\r", "\n"], ['_', '', ''], $b);
}
