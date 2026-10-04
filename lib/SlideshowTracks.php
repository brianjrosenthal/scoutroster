<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/PhotoStorage.php';

/**
 * Music tracks for slideshows. Objects live in the photo bucket under
 * audio/{stem}.{ext}; they are not tied to one slideshow so a track can be
 * reused by any section. Admin only.
 *
 * Upload follows the photo pattern: presign() hands out a PUT grant (no row
 * yet), the browser PUTs the file, attach() HEADs the object and records it.
 */
final class SlideshowTracks {

  public const MIN_DURATION = 1.0;
  public const MAX_DURATION = 3600.0;

  private static function pdo(): PDO {
    return pdo();
  }

  private static function assertAdmin(?UserContext $ctx): void {
    if (!$ctx || !$ctx->admin) throw new RuntimeException('Admins only.');
  }

  private static function log(?UserContext $ctx, string $action, array $meta): void {
    try { ActivityLog::log($ctx, $action, $meta); } catch (\Throwable $e) {}
  }

  // ---------------------------------------------------------------------------
  // Reads
  // ---------------------------------------------------------------------------

  /** All tracks with usage_count (sections referencing them), by title. */
  public static function listAll(): array {
    return self::pdo()->query('SELECT t.*, (SELECT COUNT(*) FROM slideshow_sections s WHERE s.track_id = t.id) AS usage_count
                               FROM slideshow_tracks t ORDER BY t.title, t.id')->fetchAll() ?: [];
  }

  public static function findById(int $id): ?array {
    $st = self::pdo()->prepare('SELECT * FROM slideshow_tracks WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public static function usageCount(int $trackId): int {
    $st = self::pdo()->prepare('SELECT COUNT(*) FROM slideshow_sections WHERE track_id = ?');
    $st->execute([$trackId]);
    return (int)$st->fetchColumn();
  }

  public static function urlFor(array $track, ?int $now = null): string {
    return PhotoStorage::urlFor((string)$track['object_key'], $now);
  }

  /** Every object key recorded (for the admin orphan check). */
  public static function allRecordedKeys(): array {
    return array_map('strval', array_column(self::pdo()->query('SELECT object_key FROM slideshow_tracks')->fetchAll() ?: [], 'object_key'));
  }

  /** "3:32" for a duration in seconds, or '' when unknown. */
  public static function formatDuration($seconds): string {
    if ($seconds === null || $seconds === '') return '';
    $s = (int)round((float)$seconds);
    return intdiv($s, 60) . ':' . sprintf('%02d', $s % 60);
  }

  // ---------------------------------------------------------------------------
  // Writes
  // ---------------------------------------------------------------------------

  /**
   * A PUT grant for one music file. No row is written until attach().
   * @return array{key:string,url:string,headers:array<string,string>,expires_in:int}
   */
  public static function presign(UserContext $ctx, string $contentType, int $byteLength): array {
    self::assertAdmin($ctx);
    if (!PhotoStorage::isConfigured()) throw new RuntimeException('Photo storage is not configured.');
    $type = PhotoStorage::normalizeContentType($contentType);
    if (PhotoStorage::kindOf($type) !== 'audio') {
      throw new InvalidArgumentException('Unsupported audio type "' . $contentType . '". Please use an MP3 or M4A file.');
    }
    if ($byteLength <= 0) throw new InvalidArgumentException('The music file is empty.');
    if ($byteLength > PhotoStorage::maxBytes('audio')) {
      throw new InvalidArgumentException('That file is larger than the ' . PhotoStorage::humanBytes(PhotoStorage::maxBytes('audio')) . ' limit.');
    }
    $key = PhotoStorage::audioKey($type);
    $grant = PhotoStorage::presignUploadFor($key, $type);
    self::log($ctx, 'slideshow_track.presign', ['object_key' => $key, 'size_bytes' => $byteLength]);
    return ['key' => $key] + $grant;
  }

  /** Verify the uploaded object and record the track. Returns the new id. */
  public static function attach(UserContext $ctx, string $key, string $title, string $contentType, ?float $durationSeconds): int {
    self::assertAdmin($ctx);
    if (!PhotoStorage::isAudioKey($key)) throw new InvalidArgumentException('Malformed upload reference.');
    $st = self::pdo()->prepare('SELECT 1 FROM slideshow_tracks WHERE object_key = ? LIMIT 1');
    $st->execute([$key]);
    if ($st->fetchColumn()) throw new InvalidArgumentException('That upload was already recorded.');

    $declared = PhotoStorage::normalizeContentType($contentType);
    $head = PhotoStorage::verifyUploadedObject($key, 'audio', null, PhotoStorage::maxBytes('audio'));
    $type = $head['content_type'];
    if (PhotoStorage::kindOf($declared) === 'audio' && $declared !== $type) {
      // R2 echoes the Content-Type the browser sent; if they disagree keep what storage has.
      $declared = $type;
    }
    $title = trim($title);
    if ($title === '') $title = 'Untitled track';
    $title = mb_substr($title, 0, 255);
    $duration = null;
    if ($durationSeconds !== null && is_finite($durationSeconds) && $durationSeconds >= self::MIN_DURATION && $durationSeconds <= self::MAX_DURATION) {
      $duration = round($durationSeconds, 2);
    }
    $ins = self::pdo()->prepare('INSERT INTO slideshow_tracks (title, object_key, content_type, byte_length, duration_seconds, uploaded_by_user_id, created_at)
                                 VALUES (?,?,?,?,?,?,NOW())');
    $ins->execute([$title, $key, $type, (int)$head['size'], $duration, (int)$ctx->id]);
    $id = (int)self::pdo()->lastInsertId();
    self::log($ctx, 'slideshow_track.attach', ['track_id' => $id, 'object_key' => $key, 'bytes' => (int)$head['size'], 'duration' => $duration]);
    return $id;
  }

  public static function rename(UserContext $ctx, int $trackId, string $title): bool {
    self::assertAdmin($ctx);
    $title = mb_substr(trim($title), 0, 255);
    if ($title === '') throw new InvalidArgumentException('Title is required.');
    $st = self::pdo()->prepare('UPDATE slideshow_tracks SET title = ? WHERE id = ?');
    $st->execute([$title, $trackId]);
    self::log($ctx, 'slideshow_track.rename', ['track_id' => $trackId]);
    return $st->rowCount() > 0;
  }

  /** Refuses while any section uses the track; otherwise row first, then the object. */
  public static function delete(UserContext $ctx, int $trackId): bool {
    self::assertAdmin($ctx);
    $track = self::findById($trackId);
    if (!$track) return false;
    $uses = self::usageCount($trackId);
    if ($uses > 0) {
      throw new RuntimeException('This track is used by ' . $uses . ' slideshow section' . ($uses === 1 ? '' : 's') . '. Choose different music there first.');
    }
    $st = self::pdo()->prepare('DELETE FROM slideshow_tracks WHERE id = ?');
    $st->execute([$trackId]);
    try {
      PhotoStorage::deleteObjects([(string)$track['object_key']]);
    } catch (\Throwable $e) {
      // Row is gone; the object will show as an orphan on Admin -> Photo Storage.
    }
    self::log($ctx, 'slideshow_track.delete', ['track_id' => $trackId, 'object_key' => $track['object_key']]);
    return true;
  }
}
