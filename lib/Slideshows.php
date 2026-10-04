<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/PhotoStorage.php';
require_once __DIR__ . '/EventPhotos.php';
require_once __DIR__ . '/SlideshowTracks.php';

/**
 * Slideshows: an ordered list of events (sections), each played to a music
 * track. A section plays its event's photos in gallery order, skipping those
 * marked exclude_from_slideshow. Admins create and edit; any logged-in user
 * can play a published slideshow (admins can also preview drafts).
 *
 * computeTiming() is the single source of truth for how long each photo
 * stays up; the editor shows it and the player's manifest carries it.
 */
final class Slideshows {

  public const TITLE_CARD_SECONDS = 4.0;
  public const MIN_SPP = 3.0;
  public const MAX_SPP = 8.0;
  public const DEFAULT_SPP = 5.0;          // no track, or unknown duration
  public const OVERRIDE_MIN = 1.0;
  public const OVERRIDE_MAX = 30.0;
  public const PHOTO_CROSSFADE_SECONDS = 1.0;
  public const MUSIC_FADE_OUT_SECONDS = 2.0;
  public const MUSIC_LOOP_XFADE_SECONDS = 2.0;
  public const SECTION_XFADE_SECONDS = 1.5;
  public const END_CARD_SECONDS = 6.0;

  private static function pdo(): PDO {
    return pdo();
  }

  private static function assertAdmin(?UserContext $ctx): void {
    if (!$ctx || !$ctx->admin) throw new RuntimeException('Admins only.');
  }

  private static function log(?UserContext $ctx, string $action, array $meta): void {
    try { ActivityLog::log($ctx, $action, $meta); } catch (\Throwable $e) {}
  }

  private static function touch(int $slideshowId): void {
    $st = self::pdo()->prepare('UPDATE slideshows SET updated_at = NOW() WHERE id = ?');
    $st->execute([$slideshowId]);
  }

  // ---------------------------------------------------------------------------
  // Reads
  // ---------------------------------------------------------------------------

  public static function findById(int $id): ?array {
    $st = self::pdo()->prepare('SELECT * FROM slideshows WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public static function listAll(): array {
    return self::pdo()->query('SELECT s.*, (SELECT COUNT(*) FROM slideshow_sections x WHERE x.slideshow_id = s.id) AS section_count
                               FROM slideshows s ORDER BY s.updated_at DESC, s.id DESC')->fetchAll() ?: [];
  }

  public static function listPublished(): array {
    return self::pdo()->query('SELECT s.*, (SELECT COUNT(*) FROM slideshow_sections x WHERE x.slideshow_id = s.id) AS section_count
                               FROM slideshows s WHERE s.is_published = 1 ORDER BY s.created_at DESC, s.id DESC')->fetchAll() ?: [];
  }

  public static function listVisible(?UserContext $ctx): array {
    return ($ctx && $ctx->admin) ? self::listAll() : self::listPublished();
  }

  public static function canView(?UserContext $ctx, array $slideshow): bool {
    if (!$ctx) return false;
    return !empty($slideshow['is_published']) || $ctx->admin;
  }

  public static function findSection(int $sectionId): ?array {
    $st = self::pdo()->prepare('SELECT * FROM slideshow_sections WHERE id = ? LIMIT 1');
    $st->execute([$sectionId]);
    $row = $st->fetch();
    return $row ?: null;
  }

  /**
   * Sections of a slideshow in play order, joined with event, track and photo
   * count, each with 'timing' => computeTiming(...).
   */
  public static function listSections(int $slideshowId): array {
    $st = self::pdo()->prepare('SELECT s.*, e.name AS event_name, e.starts_at AS event_starts_at,
                                       t.title AS track_title, t.duration_seconds AS track_duration_seconds
                                FROM slideshow_sections s
                                JOIN events e ON e.id = s.event_id
                                LEFT JOIN slideshow_tracks t ON t.id = s.track_id
                                WHERE s.slideshow_id = ? ORDER BY s.sort_order, s.id');
    $st->execute([$slideshowId]);
    $rows = $st->fetchAll() ?: [];
    $counts = EventPhotos::countsByEvent(array_column($rows, 'event_id'));
    foreach ($rows as &$r) {
      $c = $counts[(int)$r['event_id']] ?? ['total' => 0, 'included' => 0];
      $r['photo_count'] = $c['included'];
      $r['photo_total'] = $c['total'];
      $r['title'] = trim((string)($r['title_override'] ?? '')) !== '' ? (string)$r['title_override'] : (string)$r['event_name'];
      $r['timing'] = self::computeTiming(
        (int)$r['photo_count'],
        $r['track_duration_seconds'] !== null ? (float)$r['track_duration_seconds'] : null,
        $r['seconds_per_photo'] !== null ? (float)$r['seconds_per_photo'] : null,
        $r['track_id'] !== null
      );
    }
    unset($r);
    return $rows;
  }

  /** Total runtime in seconds for a list from listSections(), including the end card. */
  public static function totalSeconds(array $sections): float {
    $t = 0.0;
    foreach ($sections as $s) $t += (float)($s['timing']['section_seconds'] ?? 0);
    return $t + self::END_CARD_SECONDS;
  }

  public static function formatSeconds(float $seconds): string {
    $s = (int)round($seconds);
    return intdiv($s, 60) . ':' . sprintf('%02d', $s % 60);
  }

  // ---------------------------------------------------------------------------
  // Timing
  // ---------------------------------------------------------------------------

  /**
   * How a section plays.
   *  - seconds_per_photo: the override (clamped 1..30), else the track length
   *    minus the title card spread over the photos (clamped 3..8), else 5.
   *  - section_seconds: title card + photos. The music starts under the card.
   *  - music_mode: 'none' (no track), 'once' (track outlasts the photos; it
   *    fades out over the last 2 s), or 'loop' (too many photos for the track,
   *    or unknown duration: the track restarts with a 2 s crossfade at loop_at,
   *    null meaning "when it ends").
   * @return array{seconds_per_photo:float,section_seconds:float,music_mode:string,fade_out_at:?float,loop_at:?float}
   */
  public static function computeTiming(int $photoCount, ?float $trackDuration, ?float $override, bool $hasTrack = true): array {
    $n = max(0, $photoCount);
    if ($override !== null) {
      $spp = max(self::OVERRIDE_MIN, min(self::OVERRIDE_MAX, $override));
    } elseif ($trackDuration !== null && $trackDuration > 0 && $n > 0) {
      $spp = max(self::MIN_SPP, min(self::MAX_SPP, ($trackDuration - self::TITLE_CARD_SECONDS) / $n));
    } else {
      $spp = self::DEFAULT_SPP;
    }
    $spp = round($spp, 2);
    $sectionSeconds = $n > 0 ? self::TITLE_CARD_SECONDS + $n * $spp : 0.0;

    if (!$hasTrack) {
      $mode = 'none'; $fadeOutAt = null; $loopAt = null;
    } elseif ($trackDuration === null || $trackDuration <= 0) {
      $mode = 'loop'; $fadeOutAt = max(0.0, $sectionSeconds - self::MUSIC_FADE_OUT_SECONDS); $loopAt = null;
    } elseif ($sectionSeconds <= $trackDuration) {
      $mode = 'once'; $fadeOutAt = max(0.0, $sectionSeconds - self::MUSIC_FADE_OUT_SECONDS); $loopAt = null;
    } else {
      $mode = 'loop'; $fadeOutAt = max(0.0, $sectionSeconds - self::MUSIC_FADE_OUT_SECONDS);
      $loopAt = max(1.0, $trackDuration - self::MUSIC_LOOP_XFADE_SECONDS);
    }
    return [
      'seconds_per_photo' => $spp,
      'section_seconds'   => round($sectionSeconds, 2),
      'music_mode'        => $mode,
      'fade_out_at'       => $fadeOutAt !== null ? round($fadeOutAt, 2) : null,
      'loop_at'           => $loopAt !== null ? round($loopAt, 2) : null,
    ];
  }

  // ---------------------------------------------------------------------------
  // Writes (admin)
  // ---------------------------------------------------------------------------

  public static function create(UserContext $ctx, string $title, ?string $description = null): int {
    self::assertAdmin($ctx);
    $title = mb_substr(trim($title), 0, 255);
    if ($title === '') throw new InvalidArgumentException('Title is required.');
    $st = self::pdo()->prepare('INSERT INTO slideshows (title, description, is_published, created_by_user_id, created_at, updated_at) VALUES (?,?,0,?,NOW(),NOW())');
    $st->execute([$title, self::nn($description), (int)$ctx->id]);
    $id = (int)self::pdo()->lastInsertId();
    self::log($ctx, 'slideshow.create', ['slideshow_id' => $id]);
    return $id;
  }

  private static function nn($v): ?string {
    if ($v === null) return null;
    $v = trim((string)$v);
    return $v === '' ? null : $v;
  }

  /** $data keys: title, description, is_published. */
  public static function update(UserContext $ctx, int $id, array $data): bool {
    self::assertAdmin($ctx);
    $sets = []; $params = [];
    if (array_key_exists('title', $data)) {
      $t = mb_substr(trim((string)$data['title']), 0, 255);
      if ($t === '') throw new InvalidArgumentException('Title is required.');
      $sets[] = 'title = ?'; $params[] = $t;
    }
    if (array_key_exists('description', $data)) { $sets[] = 'description = ?'; $params[] = self::nn($data['description']); }
    if (array_key_exists('is_published', $data)) { $sets[] = 'is_published = ?'; $params[] = !empty($data['is_published']) ? 1 : 0; }
    if ($sets === []) return false;
    $sets[] = 'updated_at = NOW()';
    $params[] = $id;
    $st = self::pdo()->prepare('UPDATE slideshows SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $st->execute($params);
    self::log($ctx, 'slideshow.update', ['slideshow_id' => $id, 'fields' => array_keys($data)]);
    return true;
  }

  public static function delete(UserContext $ctx, int $id): bool {
    self::assertAdmin($ctx);
    $st = self::pdo()->prepare('DELETE FROM slideshows WHERE id = ?');
    $st->execute([$id]);
    self::log($ctx, 'slideshow.delete', ['slideshow_id' => $id]);
    return $st->rowCount() > 0;
  }

  public static function addSection(UserContext $ctx, int $slideshowId, int $eventId): int {
    self::assertAdmin($ctx);
    if (!self::findById($slideshowId)) throw new RuntimeException('Slideshow not found.');
    $chk = self::pdo()->prepare('SELECT 1 FROM events WHERE id = ?');
    $chk->execute([$eventId]);
    if (!$chk->fetchColumn()) throw new InvalidArgumentException('Event not found.');
    $dup = self::pdo()->prepare('SELECT 1 FROM slideshow_sections WHERE slideshow_id = ? AND event_id = ?');
    $dup->execute([$slideshowId, $eventId]);
    if ($dup->fetchColumn()) throw new InvalidArgumentException('That event is already in this slideshow.');
    $mx = self::pdo()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM slideshow_sections WHERE slideshow_id = ?');
    $mx->execute([$slideshowId]);
    $next = (int)$mx->fetchColumn() + 1;
    $st = self::pdo()->prepare('INSERT INTO slideshow_sections (slideshow_id, event_id, sort_order, created_at) VALUES (?,?,?,NOW())');
    $st->execute([$slideshowId, $eventId, $next]);
    $id = (int)self::pdo()->lastInsertId();
    self::touch($slideshowId);
    self::log($ctx, 'slideshow.section.add', ['slideshow_id' => $slideshowId, 'section_id' => $id, 'event_id' => $eventId]);
    return $id;
  }

  public static function removeSection(UserContext $ctx, int $sectionId): bool {
    self::assertAdmin($ctx);
    $s = self::findSection($sectionId);
    if (!$s) return false;
    $st = self::pdo()->prepare('DELETE FROM slideshow_sections WHERE id = ?');
    $st->execute([$sectionId]);
    self::renumber((int)$s['slideshow_id']);
    self::touch((int)$s['slideshow_id']);
    self::log($ctx, 'slideshow.section.remove', ['slideshow_id' => (int)$s['slideshow_id'], 'section_id' => $sectionId]);
    return true;
  }

  private static function renumber(int $slideshowId): void {
    $st = self::pdo()->prepare('SELECT id FROM slideshow_sections WHERE slideshow_id = ? ORDER BY sort_order, id');
    $st->execute([$slideshowId]);
    $up = self::pdo()->prepare('UPDATE slideshow_sections SET sort_order = ? WHERE id = ?');
    $n = 1;
    foreach ($st->fetchAll() ?: [] as $r) $up->execute([$n++, (int)$r['id']]);
  }

  /** Swap with the neighbour in $direction ('up' | 'down'). */
  public static function moveSection(UserContext $ctx, int $sectionId, string $direction): bool {
    self::assertAdmin($ctx);
    $s = self::findSection($sectionId);
    if (!$s) return false;
    $slideshowId = (int)$s['slideshow_id'];
    self::renumber($slideshowId);
    $s = self::findSection($sectionId);
    $target = (int)$s['sort_order'] + ($direction === 'up' ? -1 : 1);
    $nb = self::pdo()->prepare('SELECT id FROM slideshow_sections WHERE slideshow_id = ? AND sort_order = ? LIMIT 1');
    $nb->execute([$slideshowId, $target]);
    $other = $nb->fetchColumn();
    if (!$other) return false;
    $pdo = self::pdo();
    $pdo->beginTransaction();
    try {
      $up = $pdo->prepare('UPDATE slideshow_sections SET sort_order = ? WHERE id = ?');
      $up->execute([$target, $sectionId]);
      $up->execute([(int)$s['sort_order'], (int)$other]);
      $pdo->commit();
    } catch (\Throwable $e) {
      $pdo->rollBack();
      throw $e;
    }
    self::touch($slideshowId);
    self::log($ctx, 'slideshow.section.reorder', ['slideshow_id' => $slideshowId, 'section_id' => $sectionId, 'direction' => $direction]);
    return true;
  }

  public static function setSectionTrack(UserContext $ctx, int $sectionId, ?int $trackId): bool {
    self::assertAdmin($ctx);
    $s = self::findSection($sectionId);
    if (!$s) throw new RuntimeException('Section not found.');
    if ($trackId !== null && $trackId > 0 && !SlideshowTracks::findById($trackId)) {
      throw new InvalidArgumentException('Track not found.');
    }
    $st = self::pdo()->prepare('UPDATE slideshow_sections SET track_id = ? WHERE id = ?');
    $st->execute([$trackId !== null && $trackId > 0 ? $trackId : null, $sectionId]);
    self::touch((int)$s['slideshow_id']);
    self::log($ctx, 'slideshow.section.set_track', ['section_id' => $sectionId, 'track_id' => $trackId]);
    return true;
  }

  public static function setSectionOptions(UserContext $ctx, int $sectionId, ?float $secondsPerPhoto, ?string $titleOverride): bool {
    self::assertAdmin($ctx);
    $s = self::findSection($sectionId);
    if (!$s) throw new RuntimeException('Section not found.');
    $spp = null;
    if ($secondsPerPhoto !== null && $secondsPerPhoto > 0) {
      $spp = round(max(self::OVERRIDE_MIN, min(self::OVERRIDE_MAX, $secondsPerPhoto)), 2);
    }
    $title = self::nn($titleOverride);
    if ($title !== null) $title = mb_substr($title, 0, 255);
    $st = self::pdo()->prepare('UPDATE slideshow_sections SET seconds_per_photo = ?, title_override = ? WHERE id = ?');
    $st->execute([$spp, $title, $sectionId]);
    self::touch((int)$s['slideshow_id']);
    self::log($ctx, 'slideshow.section.set_options', ['section_id' => $sectionId, 'seconds_per_photo' => $spp]);
    return true;
  }

  // ---------------------------------------------------------------------------
  // Manifest for the player
  // ---------------------------------------------------------------------------

  /**
   * Everything the player needs, with presigned URLs for photos and tracks.
   * Sections with no included photos are omitted.
   */
  public static function buildManifest(?UserContext $ctx, int $slideshowId): array {
    $show = self::findById($slideshowId);
    if (!$show) throw new RuntimeException('Slideshow not found.');
    if (!self::canView($ctx, $show)) throw new RuntimeException('Not allowed');
    if (!PhotoStorage::isConfigured()) throw new RuntimeException('Photo storage is not configured.');

    $now = time();
    $sections = [];
    $total = 0.0;
    foreach (self::listSections($slideshowId) as $s) {
      $photos = EventPhotos::listForSlideshow((int)$s['event_id']);
      if ($photos === []) continue;
      $track = null;
      if ($s['track_id'] !== null) {
        $t = SlideshowTracks::findById((int)$s['track_id']);
        if ($t) {
          $track = [
            'id' => (int)$t['id'], 'title' => (string)$t['title'],
            'url' => SlideshowTracks::urlFor($t, $now), 'content_type' => (string)$t['content_type'],
            'duration_seconds' => $t['duration_seconds'] !== null ? (float)$t['duration_seconds'] : null,
          ];
        }
      }
      $timing = $s['timing'];
      $list = [];
      foreach ($photos as $p) {
        $list[] = [
          'id' => (int)$p['id'],
          'url' => PhotoStorage::urlFor((string)$p['display_key'], $now),
          'w' => (int)$p['width'], 'h' => (int)$p['height'],
          'caption' => $p['caption'] !== null && $p['caption'] !== '' ? (string)$p['caption'] : null,
        ];
      }
      $ts = strtotime((string)$s['event_starts_at']);
      $sections[] = [
        'section_id' => (int)$s['id'], 'event_id' => (int)$s['event_id'],
        'title' => (string)$s['title'],
        'date' => $ts ? date('F j, Y', $ts) : '',
        'track' => $track,
        'seconds_per_photo' => $timing['seconds_per_photo'],
        'section_seconds' => $timing['section_seconds'],
        'music_mode' => $timing['music_mode'],
        'fade_out_at' => $timing['fade_out_at'],
        'loop_at' => $timing['loop_at'],
        'photos' => $list,
      ];
      $total += (float)$timing['section_seconds'];
    }
    return [
      'ok' => true,
      'id' => (int)$show['id'],
      'title' => (string)$show['title'],
      'description' => $show['description'],
      'constants' => [
        'title_card_seconds' => self::TITLE_CARD_SECONDS,
        'photo_crossfade_seconds' => self::PHOTO_CROSSFADE_SECONDS,
        'music_fade_out_seconds' => self::MUSIC_FADE_OUT_SECONDS,
        'music_loop_xfade_seconds' => self::MUSIC_LOOP_XFADE_SECONDS,
        'section_xfade_seconds' => self::SECTION_XFADE_SECONDS,
        'end_card_seconds' => self::END_CARD_SECONDS,
      ],
      'sections' => $sections,
      'end_card' => ['title' => 'Thanks for a great year!', 'subtitle' => (string)$show['title']],
      'total_seconds' => round($total + self::END_CARD_SECONDS, 2),
    ];
  }
}
