<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

final class Files {
  // Build URL for a public file id (events, profile photos)
  // Caches allowed public types (images, pdf) as static files in /cache/public/<first>/<md5(id)>.ext
  public static function publicFileUrl(int $publicFileId): string {
    if ($publicFileId <= 0) return '';

    try {
      // Fetch metadata to determine if cacheable and derive extension
      $st = pdo()->prepare("SELECT content_type, original_filename FROM public_files WHERE id = ? LIMIT 1");
      $st->execute([$publicFileId]);
      $meta = $st->fetch();
      if (!$meta) return '';

      $ctype = strtolower(trim((string)($meta['content_type'] ?? '')));
      $orig  = (string)($meta['original_filename'] ?? '');
      $ext = self::extForPublic($ctype, $orig);

      // If not a well-known cacheable type, return dynamic endpoint
      if ($ext === null) {
        return '/public_file_download.php?id=' . $publicFileId;
      }

      $hash = md5((string)$publicFileId);
      $dirKey = substr($hash, 0, 1);
      $baseDir = self::cachePublicBaseDir();
      $baseUrl = self::cachePublicBaseUrl();
      $targetDir = $baseDir . '/' . $dirKey;
      $filename = $hash . $ext;
      $path = $targetDir . '/' . $filename;
      $url  = $baseUrl . '/' . $dirKey . '/' . $filename;

      // If already cached, return the static URL
      if (is_file($path)) {
        return $url;
      }

      // Ensure directory exists
      if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
      }

      // Load blob data and write atomically
      $st2 = pdo()->prepare("SELECT data FROM public_files WHERE id = ? LIMIT 1");
      $st2->execute([$publicFileId]);
      $row = $st2->fetch();
      if (!$row) {
        return '/public_file_download.php?id=' . $publicFileId;
      }

      $data = (string)$row['data'];
      $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
      $ok = @file_put_contents($tmp, $data, LOCK_EX);
      if ($ok === false) {
        @unlink($tmp);
        return '/public_file_download.php?id=' . $publicFileId;
      }
      @chmod($tmp, 0644);
      if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return '/public_file_download.php?id=' . $publicFileId;
      }

      return $url;
    } catch (\Throwable $e) {
      // On any error, return dynamic endpoint
      return '/public_file_download.php?id=' . $publicFileId;
    }
  }

  // Build URL for a reimbursement attachment (secure; uses file row id)
  public static function reimbursementFileUrl(int $reimbursementFileRowId): string {
    return '/secure_file_download.php?id=' . $reimbursementFileRowId;
  }

  // Profile photo URL (DB-backed only)
  public static function profilePhotoUrl(?int $publicFileId): string {
    if ($publicFileId && $publicFileId > 0) {
      return self::publicFileUrl($publicFileId);
    }
    return '';
  }

  // Event photo URL (DB-backed only)
  public static function eventPhotoUrl(?int $publicFileId): string {
    return self::profilePhotoUrl($publicFileId);
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

  // ===== Public cache helpers =====

  // Filesystem base for public cache (web-accessible). Example: /path/to/project/cache/public
  private static function cachePublicBaseDir(): string {
    return dirname(__DIR__) . '/cache/public';
  }

  // URL base for public cache. Example: /cache/public
  private static function cachePublicBaseUrl(): string {
    return '/cache/public';
  }

  // Determine extension for cacheable public files. Returns one of (.jpg,.png,.webp,.gif,.pdf) or null if not cacheable.
  private static function extForPublic(?string $ctype, ?string $original): ?string {
    $ctype = strtolower(trim((string)($ctype ?? '')));

    // Map known, allowed content types
    $mapCtype = [
      'image/jpeg' => '.jpg',
      'image/png'  => '.png',
      'image/webp' => '.webp',
      'image/gif'  => '.gif',
      'application/pdf' => '.pdf',
    ];
    if ($ctype !== '' && isset($mapCtype[$ctype])) {
      return $mapCtype[$ctype];
    }

    // If content type is unknown, allow based on known filename extensions
    $ext = strtolower((string)pathinfo((string)($original ?? ''), PATHINFO_EXTENSION));
    if ($ext === '') return null;

    $mapExt = [
      'jpg' => '.jpg',
      'jpeg' => '.jpg',
      'png' => '.png',
      'webp' => '.webp',
      'gif' => '.gif',
      'pdf' => '.pdf',
    ];
    return $mapExt[$ext] ?? null;
  }
}
