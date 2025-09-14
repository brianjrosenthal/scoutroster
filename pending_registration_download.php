<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/PendingRegistrations.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_login();

/**
 * Secure download for pending registration application file.
 * Input: id = pending_registrations.id
 * Permissions: Approvers (Cubmaster, Committee Chair, Treasurer) only.
 */
$prId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($prId <= 0) { http_response_code(400); exit('Missing id'); }

$me = current_user();
if (!\UserManagement::isApprover((int)($me['id'] ?? 0))) {
  http_response_code(403);
  exit('Forbidden');
}

// Load pending registration
$st = pdo()->prepare("SELECT secure_file_id FROM pending_registrations WHERE id = ? LIMIT 1");
$st->execute([$prId]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit('Not found'); }

$secureFileId = (int)($row['secure_file_id'] ?? 0);
if ($secureFileId <= 0) { http_response_code(404); exit('File missing'); }

// Stream secure file blob
$st2 = pdo()->prepare("SELECT data, content_type, original_filename, byte_length FROM secure_files WHERE id = ? LIMIT 1");
$st2->execute([$secureFileId]);
$blob = $st2->fetch();
if (!$blob) { http_response_code(404); exit('File missing'); }

$data = (string)$blob['data'];
$len = (int)($blob['byte_length'] ?? strlen($data));
$ctype = (string)($blob['content_type'] ?? '');
if ($ctype === '') $ctype = 'application/octet-stream';
$name = (string)($blob['original_filename'] ?? 'application');

header('Content-Type: ' . $ctype);
header('Content-Length: ' . $len);
header('Content-Disposition: inline; filename="' . rawbasename($name) . '"');
header('X-Content-Type-Options: nosniff');
echo $data;
exit;

function rawbasename(string $name): string {
  $name = str_replace('"', '', $name);
  return basename($name);
}
