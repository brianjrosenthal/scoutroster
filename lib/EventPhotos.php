<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/PhotoStorage.php';

/**
 * Photos attached to events. Rows hold only R2 object keys (see PhotoStorage);
 * the bytes never touch this server.
 *
 * Permissions: any logged-in user may upload to any event; the uploader may
 * edit/delete their own photos; admins may edit/delete/reorder anything.
 *
 * Ordering: admin-set sort_order first (NULLs last), then EXIF capture time
 * (NULLs last), then id. ORDER_SQL is the single source of truth so the
 * gallery and the slideshow always agree.
 */
final class EventPhotos {

  public const ORDER_SQL = '(p.sort_order IS NULL), p.sort_order, (p.taken_at IS NULL), p.taken_at, p.id';
  public const CAPTION_MAX = 500;

  private static function pdo(): PDO {
    return pdo();
  }

  private static function log(?UserContext $ctx, string $action, array $meta): void {
    try {
      ActivityLog::log($ctx, $action, $meta);
    } catch (\Throwable $e) {
      // best effort
    }
  }

  private const SELECT = 'SELECT p.*, u.first_name AS uploader_first_name, u.last_name AS uploader_last_name
                          FROM event_photos p
                          LEFT JOIN users u ON u.id = p.uploaded_by_user_id';

  // ---------------------------------------------------------------------------
  // Reads
  // ---------------------------------------------------------------------------

  public static function findById(int $id): ?array {
    $st = self::pdo()->prepare(self::SELECT . ' WHERE p.id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  /** Every photo of an event in gallery order. */
  public static function listForEvent(int $eventId): array {
    $st = self::pdo()->prepare(self::SELECT . ' WHERE p.event_id = ? ORDER BY ' . self::ORDER_SQL);
    $st->execute([$eventId]);
    return $st->fetchAll() ?: [];
  }

  /** The photos a slideshow plays for an event: not excluded, gallery order. */
  public static function listForSlideshow(int $eventId): array {
    $st = self::pdo()->prepare(self::SELECT . ' WHERE p.event_id = ? AND p.exclude_from_slideshow = 0 ORDER BY ' . self::ORDER_SQL);
    $st->execute([$eventId]);
    return $st->fetchAll() ?: [];
  }

  public static function countForEvent(int $eventId): int {
    $st = self::pdo()->prepare('SELECT COUNT(*) FROM event_photos WHERE event_id = ?');
    $st->execute([$eventId]);
    return (int)$st->fetchColumn();
  }

  /**
   * Photo counts for many events at once.
   * @param int[] $eventIds
   * @return array<int,array{total:int,included:int}>
   */
  public static function countsByEvent(array $eventIds): array {
    $ids = array_values(array_unique(array_map('intval', $eventIds)));
    if ($ids === []) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = self::pdo()->prepare("SELECT event_id, COUNT(*) AS total, SUM(exclude_from_slideshow = 0) AS included
                                FROM event_photos WHERE event_id IN ($in) GROUP BY event_id");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() ?: [] as $r) {
      $out[(int)$r['event_id']] = ['total' => (int)$r['total'], 'included' => (int)$r['included']];
    }
    return $out;
  }

  /**
   * Events that have at least one photo, newest first, each with photo_count
   * and a 'cover' row (the first photo in gallery order).
   */
  public static function listEventsWithPhotos(): array {
    $st = self::pdo()->query('SELECT e.*, COUNT(p.id) AS photo_count
                              FROM events e JOIN event_photos p ON p.event_id = e.id
                              GROUP BY e.id ORDER BY e.starts_at DESC');
    $events = $st->fetchAll() ?: [];
    foreach ($events as &$e) {
      $c = self::pdo()->prepare(self::SELECT . ' WHERE p.event_id = ? ORDER BY ' . self::ORDER_SQL . ' LIMIT 1');
      $c->execute([(int)$e['id']]);
      $e['cover'] = $c->fetch() ?: null;
    }
    unset($e);
    return $events;
  }

  public static function findDuplicate(int $eventId, string $sha256): ?array {
    $sha = strtolower(trim($sha256));
    if (!preg_match('/^[0-9a-f]{64}$/', $sha)) return null;
    $st = self::pdo()->prepare(self::SELECT . ' WHERE p.event_id = ? AND p.sha256 = ? LIMIT 1');
    $st->execute([$eventId, $sha]);
    $row = $st->fetch();
    return $row ?: null;
  }

  /** 0-based position of a photo in its event's gallery order (-1 if absent). */
  public static function positionOf(int $eventId, int $photoId): int {
    $i = 0;
    foreach (self::listForEvent($eventId) as $p) {
      if ((int)$p['id'] === $photoId) return $i;
      $i++;
    }
    return -1;
  }

  public static function hasManualOrder(int $eventId): bool {
    $st = self::pdo()->prepare('SELECT 1 FROM event_photos WHERE event_id = ? AND sort_order IS NOT NULL LIMIT 1');
    $st->execute([$eventId]);
    return (bool)$st->fetchColumn();
  }

  /** Every object key the database references (for the admin orphan check). */
  public static function allRecordedKeys(): array {
    $keys = [];
    foreach (self::pdo()->query('SELECT original_key, display_key, thumb_key FROM event_photos')->fetchAll() ?: [] as $r) {
      $keys[] = (string)$r['original_key'];
      $keys[] = (string)$r['display_key'];
      $keys[] = (string)$r['thumb_key'];
    }
    return $keys;
  }

  // ---------------------------------------------------------------------------
  // Permissions
  // ---------------------------------------------------------------------------

  public static function canUpload(?UserContext $ctx, int $eventId): bool {
    if (!$ctx || $eventId <= 0) return false;
    $st = self::pdo()->prepare('SELECT 1 FROM events WHERE id = ? LIMIT 1');
    $st->execute([$eventId]);
    return (bool)$st->fetchColumn();
  }

  /** Admins, or the uploader, may edit metadata and delete. */
  public static function canModify(?UserContext $ctx, array $photo): bool {
    if (!$ctx) return false;
    if ($ctx->admin) return true;
    $owner = (int)($photo['uploaded_by_user_id'] ?? 0);
    return $owner > 0 && $owner === (int)$ctx->id;
  }

  private static function assertCanModify(?UserContext $ctx, array $photo): void {
    if (!self::canModify($ctx, $photo)) {
      throw new RuntimeException('You can only change photos you uploaded.');
    }
  }

  private static function assertAdmin(?UserContext $ctx): void {
    if (!$ctx || !$ctx->admin) {
      throw new RuntimeException('Admins only.');
    }
  }

  private static function requirePhoto(int $photoId): array {
    $photo = self::findById($photoId);
    if (!$photo) throw new RuntimeException('Photo not found.');
    return $photo;
  }

  // ---------------------------------------------------------------------------
  // Writes
  // ---------------------------------------------------------------------------

  /**
   * Record a photo whose three renditions the browser has already PUT to R2.
   * Keys are recomputed from the stem, every object is verified by HEAD, and
   * on any failure all three objects are deleted before the error propagates.
   *
   * $meta keys: width, height, sha256|null, taken_at|null ('Y-m-d H:i:s'),
   * taken_at_source ('exif'|'file'|'upload'), caption|null.
   */
  public static function attachUploaded(UserContext $ctx, int $eventId, string $stem, string $contentType, array $meta): array {
    if (!self::canUpload($ctx, $eventId)) {
      throw new RuntimeException('Event not found.');
    }
    if (!PhotoStorage::isConfigured()) {
      throw new RuntimeException('Photo storage is not configured.');
    }
    $type = PhotoStorage::normalizeContentType($contentType);
    $keys = PhotoStorage::photoKeys($eventId, $stem, $type);

    try {
      $orig = PhotoStorage::verifyUploadedObject($keys['original'], 'image', $type, PhotoStorage::maxBytes('image'));
      PhotoStorage::verifyUploadedObject($keys['display'], 'image', 'image/jpeg', PhotoStorage::RENDITION_MAX_BYTES['display']);
      PhotoStorage::verifyUploadedObject($keys['thumb'], 'image', 'image/jpeg', PhotoStorage::RENDITION_MAX_BYTES['thumb']);
    } catch (\Throwable $e) {
      try { PhotoStorage::deleteObjects(array_values($keys)); } catch (\Throwable $ignored) {}
      throw $e;
    }

    $width = max(1, (int)($meta['width'] ?? 0));
    $height = max(1, (int)($meta['height'] ?? 0));
    $sha = strtolower(trim((string)($meta['sha256'] ?? '')));
    if (!preg_match('/^[0-9a-f]{64}$/', $sha)) $sha = null;
    $takenAt = $meta['taken_at'] ?? null;
    $source = (string)($meta['taken_at_source'] ?? 'upload');
    if (!in_array($source, ['exif', 'file', 'upload'], true)) $source = 'upload';
    if ($takenAt === null || $takenAt === '') {
      $takenAt = date('Y-m-d H:i:s');
      $source = 'upload';
    }
    $caption = self::cleanCaption($meta['caption'] ?? null);

    $st = self::pdo()->prepare('INSERT INTO event_photos
        (event_id, uploaded_by_user_id, original_key, display_key, thumb_key, content_type, byte_length,
         width, height, sha256, taken_at, taken_at_source, caption, exclude_from_slideshow, sort_order, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,NULL,NOW())');
    $st->execute([
      $eventId, (int)$ctx->id, $keys['original'], $keys['display'], $keys['thumb'], $type, (int)$orig['size'],
      $width, $height, $sha, $takenAt, $source, $caption,
    ]);
    $id = (int)self::pdo()->lastInsertId();
    self::log($ctx, 'event_photo.upload', ['event_id' => $eventId, 'photo_id' => $id, 'bytes' => (int)$orig['size'], 'taken_at_source' => $source]);
    return self::findById($id);
  }

  private static function cleanCaption($caption): ?string {
    if ($caption === null) return null;
    $c = trim((string)$caption);
    if ($c === '') return null;
    return mb_substr($c, 0, self::CAPTION_MAX);
  }

  public static function updateCaption(UserContext $ctx, int $photoId, ?string $caption): array {
    $photo = self::requirePhoto($photoId);
    self::assertCanModify($ctx, $photo);
    $st = self::pdo()->prepare('UPDATE event_photos SET caption = ? WHERE id = ?');
    $st->execute([self::cleanCaption($caption), $photoId]);
    self::log($ctx, 'event_photo.caption', ['photo_id' => $photoId, 'event_id' => (int)$photo['event_id']]);
    return self::findById($photoId);
  }

  /**
   * Set the capture date by hand ('Y-m-d H:i:s'); null restores the upload
   * time. Marks the source 'manual' so it no longer reads as estimated.
   */
  public static function setTakenAt(UserContext $ctx, int $photoId, ?string $takenAt): array {
    $photo = self::requirePhoto($photoId);
    self::assertCanModify($ctx, $photo);
    if ($takenAt !== null && $takenAt !== '') {
      $dt = DateTime::createFromFormat('Y-m-d H:i:s', $takenAt);
      $ok = $dt && $dt->format('Y-m-d H:i:s') === $takenAt
         && $dt->getTimestamp() >= strtotime('1990-01-01') && $dt->getTimestamp() <= time() + 86400;
      if (!$ok) throw new InvalidArgumentException('Please enter a valid date and time.');
      $st = self::pdo()->prepare("UPDATE event_photos SET taken_at = ?, taken_at_source = 'manual' WHERE id = ?");
      $st->execute([$takenAt, $photoId]);
    } else {
      $st = self::pdo()->prepare("UPDATE event_photos SET taken_at = created_at, taken_at_source = 'upload' WHERE id = ?");
      $st->execute([$photoId]);
    }
    self::log($ctx, 'event_photo.taken_at', ['photo_id' => $photoId, 'event_id' => (int)$photo['event_id'], 'taken_at' => $takenAt]);
    return self::findById($photoId);
  }

  public static function setExcludeFromSlideshow(UserContext $ctx, int $photoId, bool $exclude): array {
    $photo = self::requirePhoto($photoId);
    self::assertCanModify($ctx, $photo);
    $st = self::pdo()->prepare('UPDATE event_photos SET exclude_from_slideshow = ? WHERE id = ?');
    $st->execute([$exclude ? 1 : 0, $photoId]);
    self::log($ctx, 'event_photo.exclude', ['photo_id' => $photoId, 'event_id' => (int)$photo['event_id'], 'exclude' => $exclude]);
    return self::findById($photoId);
  }

  /** Objects first, then the row: if R2 refuses, the row stays and the user can retry. */
  public static function delete(UserContext $ctx, int $photoId): void {
    $photo = self::requirePhoto($photoId);
    self::assertCanModify($ctx, $photo);
    if (!PhotoStorage::isConfigured()) {
      throw new RuntimeException('Photo storage is not configured; cannot delete the photo files.');
    }
    PhotoStorage::deleteObjects([(string)$photo['original_key'], (string)$photo['display_key'], (string)$photo['thumb_key']]);
    $st = self::pdo()->prepare('DELETE FROM event_photos WHERE id = ?');
    $st->execute([$photoId]);
    self::log($ctx, 'event_photo.delete', ['photo_id' => $photoId, 'event_id' => (int)$photo['event_id']]);
  }

  /** Admin: remove every photo of an event (used before the event itself is deleted). */
  public static function deleteAllForEvent(UserContext $ctx, int $eventId): int {
    self::assertAdmin($ctx);
    $st = self::pdo()->prepare('SELECT id, original_key, display_key, thumb_key FROM event_photos WHERE event_id = ?');
    $st->execute([$eventId]);
    $rows = $st->fetchAll() ?: [];
    if ($rows === []) return 0;
    if (!PhotoStorage::isConfigured()) {
      throw new RuntimeException('Photo storage is not configured; cannot remove the ' . count($rows) . ' photo(s) of this event.');
    }
    $keys = [];
    foreach ($rows as $r) {
      $keys[] = (string)$r['original_key'];
      $keys[] = (string)$r['display_key'];
      $keys[] = (string)$r['thumb_key'];
    }
    PhotoStorage::deleteObjects($keys);
    $del = self::pdo()->prepare('DELETE FROM event_photos WHERE event_id = ?');
    $del->execute([$eventId]);
    self::log($ctx, 'event_photo.delete_all', ['event_id' => $eventId, 'count' => count($rows)]);
    return count($rows);
  }

  /**
   * Admin: set a manual order. $orderedIds must be exactly the event's photo ids.
   * @param int[] $orderedIds
   */
  public static function reorder(UserContext $ctx, int $eventId, array $orderedIds): void {
    self::assertAdmin($ctx);
    $ids = array_values(array_map('intval', $orderedIds));
    $st = self::pdo()->prepare('SELECT id FROM event_photos WHERE event_id = ?');
    $st->execute([$eventId]);
    $existing = array_map('intval', array_column($st->fetchAll() ?: [], 'id'));
    $a = $ids; $b = $existing;
    sort($a); sort($b);
    if ($a !== $b || count($ids) !== count(array_unique($ids))) {
      throw new InvalidArgumentException('The photo list is out of date. Reload the page and try again.');
    }
    $pdo = self::pdo();
    $pdo->beginTransaction();
    try {
      $up = $pdo->prepare('UPDATE event_photos SET sort_order = ? WHERE id = ? AND event_id = ?');
      $n = 1;
      foreach ($ids as $id) {
        $up->execute([$n++, $id, $eventId]);
      }
      $pdo->commit();
    } catch (\Throwable $e) {
      $pdo->rollBack();
      throw $e;
    }
    self::log($ctx, 'event_photo.reorder', ['event_id' => $eventId, 'count' => count($ids)]);
  }

  /**
   * Admin: give every photo whose date is estimated (not from EXIF or a
   * person) a date that matches its current position in the gallery, so a
   * later "reset to chronological" keeps it where it was put. Each run of
   * estimated photos is spread evenly between the dated photos around it;
   * runs before the first dated photo step back a minute each, runs after
   * the last step forward. Returns how many photos were updated.
   */
  public static function refreshEstimatedDates(UserContext $ctx, int $eventId): int {
    self::assertAdmin($ctx);
    $photos = self::listForEvent($eventId);
    $isAnchor = fn($p) => in_array((string)$p['taken_at_source'], ['exif', 'manual'], true) && !empty($p['taken_at']);
    $anchorIdx = [];
    foreach ($photos as $i => $p) if ($isAnchor($p)) $anchorIdx[] = $i;
    if ($anchorIdx === []) return 0;

    $updates = []; // id => 'Y-m-d H:i:s'
    $n = count($photos);
    $assign = function (int $from, int $to, callable $timeFor) use ($photos, &$updates) {
      $k = $to - $from + 1;
      for ($j = 0; $j < $k; $j++) {
        $p = $photos[$from + $j];
        $updates[(int)$p['id']] = date('Y-m-d H:i:s', (int)round($timeFor($j, $k)));
      }
    };
    // Before the first anchor.
    $first = $anchorIdx[0];
    if ($first > 0) {
      $ta = strtotime((string)$photos[$first]['taken_at']);
      $assign(0, $first - 1, fn($j, $k) => $ta - ($k - $j) * 60);
    }
    // Between anchors.
    for ($a = 0; $a + 1 < count($anchorIdx); $a++) {
      $i1 = $anchorIdx[$a]; $i2 = $anchorIdx[$a + 1];
      if ($i2 - $i1 < 2) continue;
      $ta = strtotime((string)$photos[$i1]['taken_at']);
      $tb = strtotime((string)$photos[$i2]['taken_at']);
      $k = $i2 - $i1 - 1;
      if ($tb - $ta >= $k + 1) {
        $assign($i1 + 1, $i2 - 1, fn($j) => $ta + ($tb - $ta) * ($j + 1) / ($k + 1));
      } else {
        // Anchors out of order or too close (a dated photo was moved by hand): step by seconds.
        $assign($i1 + 1, $i2 - 1, fn($j) => $ta + $j + 1);
      }
    }
    // After the last anchor.
    $last = end($anchorIdx);
    if ($last < $n - 1) {
      $tb = strtotime((string)$photos[$last]['taken_at']);
      $assign($last + 1, $n - 1, fn($j) => $tb + ($j + 1) * 60);
    }
    if ($updates === []) return 0;
    $pdo = self::pdo();
    $pdo->beginTransaction();
    try {
      $up = $pdo->prepare("UPDATE event_photos SET taken_at = ?, taken_at_source = 'file' WHERE id = ? AND event_id = ?");
      foreach ($updates as $id => $t) $up->execute([$t, $id, $eventId]);
      $pdo->commit();
    } catch (\Throwable $e) {
      $pdo->rollBack();
      throw $e;
    }
    self::log($ctx, 'event_photo.refresh_estimated_dates', ['event_id' => $eventId, 'count' => count($updates)]);
    return count($updates);
  }

  /** Admin: back to chronological order. */
  public static function resetOrder(UserContext $ctx, int $eventId): void {
    self::assertAdmin($ctx);
    $st = self::pdo()->prepare('UPDATE event_photos SET sort_order = NULL WHERE event_id = ?');
    $st->execute([$eventId]);
    self::log($ctx, 'event_photo.reset_order', ['event_id' => $eventId]);
  }

  // ---------------------------------------------------------------------------
  // URLs
  // ---------------------------------------------------------------------------

  /** @return array{original:string,display:string,thumb:string} */
  public static function urlsFor(array $photo, ?int $now = null): array {
    return [
      'original' => PhotoStorage::urlFor((string)$photo['original_key'], $now),
      'display'  => PhotoStorage::urlFor((string)$photo['display_key'], $now),
      'thumb'    => PhotoStorage::urlFor((string)$photo['thumb_key'], $now),
    ];
  }

  /** Adds a 'urls' key to every row; one $now so a whole page shares one window. */
  public static function withUrls(array $photos, ?int $now = null): array {
    $now = $now ?? time();
    foreach ($photos as &$p) {
      $p['urls'] = self::urlsFor($p, $now);
    }
    unset($p);
    return $photos;
  }

  /** "Jane Smith" or '' for the uploader of a row from the SELECT above. */
  public static function uploaderName(array $photo): string {
    return trim((string)($photo['uploader_first_name'] ?? '') . ' ' . (string)($photo['uploader_last_name'] ?? ''));
  }
}
