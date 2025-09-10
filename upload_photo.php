<?php
// upload_photo.php - Handles profile photo uploads for adults and youth.
// Usage (POST, multipart/form-data):
//   /upload_photo.php?type=adult&adult_id=123&return_to=/my_profile.php
//   /upload_photo.php?type=youth&youth_id=456&return_to=/youth_edit.php?id=456

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/Files.php';
require_once __DIR__ . '/lib/ActivityLog.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/YouthManagement.php';

function redirect_back(string $returnTo, array $params = []): void {
  // Basic allowlist: require leading slash to avoid offsite redirects
  if ($returnTo === '' || $returnTo[0] !== '/') $returnTo = '/index.php';
  if (!empty($params)) {
    $sep = (strpos($returnTo, '?') === false) ? '?' : '&';
    $returnTo .= $sep . http_build_query($params);
  }
  header('Location: ' . $returnTo);
  exit;
}




require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

require_csrf();

$action = strtolower(trim((string)($_POST['action'] ?? 'upload')));

$u = current_user();
$isAdmin = !empty($u['is_admin']);
$currentId = (int)($u['id'] ?? 0);

$type = strtolower(trim((string)($_GET['type'] ?? '')));
$adultId = (int)($_GET['adult_id'] ?? 0);
$youthId = (int)($_GET['youth_id'] ?? 0);

$returnTo = (string)($_GET['return_to'] ?? '/index.php');
if ($returnTo === '' || $returnTo[0] !== '/') $returnTo = '/index.php';

// Validate target + permissions
if ($type !== 'adult' && $type !== 'youth') {
  redirect_back($returnTo, ['err' => 'invalid_type']);
}

if ($type === 'adult') {
  if ($adultId <= 0) redirect_back($returnTo, ['err' => 'missing_adult_id']);
  if (!UserManagement::canUploadAdultPhoto(UserContext::getLoggedInUserContext(), $adultId)) {
    http_response_code(403);
    exit('Forbidden');
  }
} else {
  if ($youthId <= 0) redirect_back($returnTo, ['err' => 'missing_youth_id']);
  if (!YouthManagement::canUploadYouthPhoto(UserContext::getLoggedInUserContext(), $youthId)) {
    http_response_code(403);
    exit('Forbidden');
  }
}

if ($action === 'delete') {
  // Delete existing photo reference (with permission checks already performed above)
  try {
    if ($type === 'adult') {
      $up = pdo()->prepare("UPDATE users SET photo_public_file_id = NULL WHERE id=?");
      $up->execute([$adultId]);
    } else {
      $up = pdo()->prepare("UPDATE youth SET photo_public_file_id = NULL WHERE id=?");
      $up->execute([$youthId]);
    }
  } catch (Throwable $e) {
    redirect_back($returnTo, ['err' => 'db_failed']);
  }
  // Activity log for adult profile photo delete
  if ($type === 'adult') {
    ActivityLog::log(UserContext::getLoggedInUserContext(), 'user.upload_profile_photo', [
      'target_user_id' => (int)$adultId,
      'deleted' => true,
    ]);
  }
  redirect_back($returnTo, ['deleted' => 1]);
  exit;
}

// Validate file
if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
  redirect_back($returnTo, ['err' => 'missing_file']);
}

$err = (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
  redirect_back($returnTo, ['err' => 'upload_error_' . $err]);
}

$tmp = (string)$_FILES['photo']['tmp_name'];
$size = (int)($_FILES['photo']['size'] ?? 0);
if ($size <= 0) redirect_back($returnTo, ['err' => 'empty_file']);
if ($size > 8 * 1024 * 1024) redirect_back($returnTo, ['err' => 'too_large']); // 8MB

// Mime type detection
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($tmp);

$allowed = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/webp' => 'webp',
];
if (!array_key_exists($mime, $allowed)) {
  redirect_back($returnTo, ['err' => 'invalid_type']);
}

// Verify it's an image
$imgInfo = @getimagesize($tmp);
if ($imgInfo === false) {
  redirect_back($returnTo, ['err' => 'not_image']);
}

$ext = $allowed[$mime];

// Store in DB-backed public_files and update reference
$origName = (string)($_FILES['photo']['name'] ?? ('profile.' . $ext));
$data = @file_get_contents($tmp);
if ($data === false) {
  redirect_back($returnTo, ['err' => 'save_failed']);
}
try {
  $publicId = Files::insertPublicFile($data, $mime, $origName, $currentId);
  if ($type === 'adult') {
    $st = pdo()->prepare("UPDATE users SET photo_public_file_id = ? WHERE id = ?");
    $st->execute([$publicId, $adultId]);
  } else {
    $st = pdo()->prepare("UPDATE youth SET photo_public_file_id = ? WHERE id = ?");
    $st->execute([$publicId, $youthId]);
  }
} catch (Throwable $e) {
  redirect_back($returnTo, ['err' => 'db_failed']);
}
// Activity log for adult profile photo upload
if ($type === 'adult') {
  ActivityLog::log(UserContext::getLoggedInUserContext(), 'user.upload_profile_photo', [
    'target_user_id' => (int)$adultId,
    'public_file_id' => (int)$publicId,
  ]);
}
redirect_back($returnTo, ['uploaded' => 1]);
