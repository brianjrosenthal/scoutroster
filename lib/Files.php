<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

final class Files {
  // Build URL for a public file id (events, profile photos)
  public static function publicFileUrl(int $publicFileId): string {
    return '/public_file_download.php?id=' . $publicFileId;
  }

  // Build URL for a reimbursement attachment (secure; uses file row id)
  public static function reimbursementFileUrl(int $reimbursementFileRowId): string {
    return '/secure_file_download.php?id=' . $reimbursementFileRowId;
  }

  // Profile photo URL with legacy fallback
  public static function profilePhotoUrl(?int $publicFileId, ?string $legacyPath): string {
    if ($publicFileId && $publicFileId > 0) {
      return self::publicFileUrl($publicFileId);
    }
    $legacyPath = is_string($legacyPath) ? trim($legacyPath) : '';
    if ($legacyPath !== '') {
      // Ensure leading slash for legacy stored relative paths like "uploads/..."
      if ($legacyPath[0] !== '/') return '/' . $legacyPath;
      return $legacyPath;
    }
    return '';
  }

  // Event photo URL with legacy fallback
  public static function eventPhotoUrl(?int $publicFileId, ?string $legacyPath): string {
    return self::profilePhotoUrl($publicFileId, $legacyPath);
  }

  // Helper: insert a public file row and return new id
  public static function insertPublicFile(string $data, ?string $contentType, ?string $originalFilename, ?int $createdByUserId): int {
    $sha = hash('sha256', $data);
    $len = strlen($data);
    $st = pdo()->prepare("
      INSERT INTO public_files (data, content_type, original_filename, byte_length, sha256, created_by_user_id, created_at)
      VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $st->execute([$data, $contentType, $originalFilename, $len, $sha, $createdByUserId]);
    return (int)pdo()->lastInsertId();
  }

  // Helper: insert a secure file row and return new id
  public static function insertSecureFile(string $data, ?string $contentType, ?string $originalFilename, ?int $createdByUserId): int {
    $sha = hash('sha256', $data);
    $len = strlen($data);
    $st = pdo()->prepare("
      INSERT INTO secure_files (data, content_type, original_filename, byte_length, sha256, created_by_user_id, created_at)
      VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $st->execute([$data, $contentType, $originalFilename, $len, $sha, $createdByUserId]);
    return (int)pdo()->lastInsertId();
  }
}
