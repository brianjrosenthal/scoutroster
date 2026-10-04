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

  /** Photo transition styles the player knows (value => label for the editor). */
  public const TRANSITIONS = [
    'mix'    => 'Mix: mostly crossfades with occasional variety',
    'random' => 'Random: a different transition for every photo',
    'fade'   => 'Crossfade',
    'slide'  => 'Slide',
    'push'   => 'Push',
    'wipe'   => 'Wipe',
    'iris'   => 'Iris (circle reveal)',
    'zoom'   => 'Zoom through',
    'blur'   => 'Blur dissolve',
    'flip'   => 'Flip',
    'spin'   => 'Spin zoom',
  ];
  public const DEFAULT_TRANSITION = 'mix';

  public static function transitionOf(array $slideshow): string {
    $t = (string)($slideshow['transition'] ?? '');
    return isset(self::TRANSITIONS[$t]) ? $t : self::DEFAULT_TRANSITION;
  }

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
    $trackCache = [];
    foreach ($rows as &$r) {
      $c = $counts[(int)$r['event_id']] ?? ['total' => 0, 'included' => 0];
      $r['photo_count'] = $c['included'];
      $r['photo_total'] = $c['total'];
      $r['title'] = trim((string)($r['title_override'] ?? '')) !== '' ? (string)$r['title_override'] : (string)$r['event_name'];
      $r['cues'] = self::resolveCues($r, $trackCache);
      $r['timing'] = self::computeSectionTiming(
        (int)$r['photo_count'],
        array_map(fn($cue) => ['start_index' => $cue['start_index'], 'duration' => $cue['duration_seconds'], 'has_track' => $cue['track_id'] !== null], $r['cues']),
        $r['seconds_per_photo'] !== null ? (float)$r['seconds_per_photo'] : null
      );
    }
    unset($r);
    return $rows;
  }

  // ---------------------------------------------------------------------------
  // Music cues (several tracks in one section)
  // ---------------------------------------------------------------------------

  public const MAX_CUES = 12;

  /**
   * Parse a section's music_cues JSON into a clean, sorted list of
   * ['track_id' => int, 'start_index' => int]. The first cue always starts at
   * photo 0. Invalid input yields [].
   */
  public static function parseCues($json): array {
    if ($json === null || $json === '') return [];
    $data = is_array($json) ? $json : json_decode((string)$json, true);
    if (!is_array($data)) return [];
    $cues = [];
    foreach ($data as $c) {
      if (!is_array($c)) continue;
      $tid = (int)($c['track_id'] ?? 0);
      $si = max(0, (int)($c['start_index'] ?? 0));
      if ($tid <= 0) continue;
      $cues[$si] = ['track_id' => $tid, 'start_index' => $si]; // later entries at the same index win
    }
    ksort($cues);
    $cues = array_values($cues);
    if ($cues !== []) $cues[0]['start_index'] = 0;
    return array_slice($cues, 0, self::MAX_CUES);
  }

  /**
   * The cues a section plays, with track title/duration attached. A section
   * with music_cues uses them; otherwise its single track_id (or nothing)
   * becomes one cue starting at photo 0.
   * @return array<int,array{track_id:?int,start_index:int,title:?string,duration_seconds:?float}>
   */
  public static function resolveCues(array $section, array &$trackCache = []): array {
    $raw = self::parseCues($section['music_cues'] ?? null);
    if ($raw === []) {
      if ($section['track_id'] === null) return [['track_id' => null, 'start_index' => 0, 'title' => null, 'duration_seconds' => null]];
      $raw = [['track_id' => (int)$section['track_id'], 'start_index' => 0]];
    }
    $out = [];
    foreach ($raw as $c) {
      $tid = (int)$c['track_id'];
      if (!array_key_exists($tid, $trackCache)) $trackCache[$tid] = SlideshowTracks::findById($tid);
      $t = $trackCache[$tid];
      if (!$t) continue; // track deleted since
      $out[] = ['track_id' => $tid, 'start_index' => (int)$c['start_index'], 'title' => (string)$t['title'],
                'duration_seconds' => $t['duration_seconds'] !== null ? (float)$t['duration_seconds'] : null];
    }
    if ($out === []) return [['track_id' => null, 'start_index' => 0, 'title' => null, 'duration_seconds' => null]];
    $out[0]['start_index'] = 0;
    return $out;
  }

  /** Whether a section row is in multi-track mode. */
  public static function hasCues(array $section): bool {
    return count(self::parseCues($section['music_cues'] ?? null)) > 0;
  }

  /**
   * Admin: set several tracks for a section. $cues = [['track_id'=>, 'start_index'=>], ...].
   * track_id is mirrored to the first cue.
   */
  public static function setSectionCues(UserContext $ctx, int $sectionId, array $cues): bool {
    self::assertAdmin($ctx);
    $s = self::findSection($sectionId);
    if (!$s) throw new RuntimeException('Section not found.');
    $clean = self::parseCues($cues);
    if ($clean === []) throw new InvalidArgumentException('Choose at least one track.');
    foreach ($clean as $c) {
      if (!SlideshowTracks::findById((int)$c['track_id'])) throw new InvalidArgumentException('Track not found.');
    }
    $st = self::pdo()->prepare('UPDATE slideshow_sections SET music_cues = ?, track_id = ? WHERE id = ?');
    $st->execute([json_encode($clean), (int)$clean[0]['track_id'], $sectionId]);
    self::touch((int)$s['slideshow_id']);
    self::log($ctx, 'slideshow.section.set_cues', ['section_id' => $sectionId, 'cues' => $clean]);
    return true;
  }

  /** Admin: back to a single track (the first cue's track is kept). */
  public static function clearSectionCues(UserContext $ctx, int $sectionId): bool {
    self::assertAdmin($ctx);
    $s = self::findSection($sectionId);
    if (!$s) throw new RuntimeException('Section not found.');
    $st = self::pdo()->prepare('UPDATE slideshow_sections SET music_cues = NULL WHERE id = ?');
    $st->execute([$sectionId]);
    self::touch((int)$s['slideshow_id']);
    self::log($ctx, 'slideshow.section.clear_cues', ['section_id' => $sectionId]);
    return true;
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
  /**
   * Timing for a whole section whose photos may be covered by several tracks
   * in turn. $segments = [['start_index'=>int,'duration'=>?float,'has_track'=>bool], ...]
   * sorted by start_index with the first at 0 (a section with no music is one
   * segment with has_track=false). Each segment spreads its track over its
   * own photos using the same rules as computeTiming(); the title card belongs
   * to the first segment; an override applies to every photo.
   *
   * @return array{
   *   section_seconds:float, seconds_per_photo:float, photo_starts:float[],
   *   cues:array<int,array{start_index:int,start_at:float,segment_seconds:float,photo_count:int,
   *                        seconds_per_photo:float,music_mode:string,fade_out_at:?float,loop_at:?float}>
   * }
   */
  public static function computeSectionTiming(int $photoCount, array $segments, ?float $override): array {
    $n = max(0, $photoCount);
    if ($segments === []) $segments = [['start_index' => 0, 'duration' => null, 'has_track' => false]];
    usort($segments, fn($a, $b) => $a['start_index'] <=> $b['start_index']);
    $segments[0]['start_index'] = 0;
    // Drop segments that start beyond the photos or duplicate a start.
    $clean = [];
    foreach ($segments as $seg) {
      $si = max(0, (int)$seg['start_index']);
      if ($si >= $n && $n > 0) continue;
      if ($clean !== [] && $si <= end($clean)['start_index']) continue;
      $clean[] = ['start_index' => $si, 'duration' => $seg['duration'] ?? null, 'has_track' => !empty($seg['has_track'])];
    }
    if ($clean === []) $clean = [['start_index' => 0, 'duration' => null, 'has_track' => false]];

    $cues = [];
    $photoStarts = [];
    $at = 0.0;
    $spp0 = null;
    foreach ($clean as $k => $seg) {
      $end = isset($clean[$k + 1]) ? (int)$clean[$k + 1]['start_index'] : $n;
      $count = max(0, $end - $seg['start_index']);
      $t = self::computeTiming($count, $seg['duration'], $override, $seg['has_track'], $k === 0);
      if ($spp0 === null) $spp0 = $t['seconds_per_photo'];
      $title = $k === 0 ? self::TITLE_CARD_SECONDS : 0.0;
      for ($i = 0; $i < $count; $i++) {
        $photoStarts[] = round($at + $title + $i * $t['seconds_per_photo'], 2);
      }
      $cues[] = [
        'start_index'       => $seg['start_index'],
        'start_at'          => round($at, 2),
        'segment_seconds'   => $t['section_seconds'],
        'photo_count'       => $count,
        'seconds_per_photo' => $t['seconds_per_photo'],
        'music_mode'        => $t['music_mode'],
        'fade_out_at'       => $t['fade_out_at'] !== null ? round($at + $t['fade_out_at'], 2) : null,
        'loop_at'           => $t['loop_at'],
      ];
      $at += $t['section_seconds'];
    }
    return [
      'section_seconds'   => round($at, 2),
      'seconds_per_photo' => $spp0 ?? self::DEFAULT_SPP,
      'photo_starts'      => $photoStarts,
      'cues'              => $cues,
    ];
  }

  public static function computeTiming(int $photoCount, ?float $trackDuration, ?float $override, bool $hasTrack = true, bool $withTitleCard = true): array {
    $n = max(0, $photoCount);
    $titleCard = $withTitleCard ? self::TITLE_CARD_SECONDS : 0.0;
    if ($override !== null) {
      $spp = max(self::OVERRIDE_MIN, min(self::OVERRIDE_MAX, $override));
    } elseif ($trackDuration !== null && $trackDuration > 0 && $n > 0) {
      $spp = max(self::MIN_SPP, min(self::MAX_SPP, ($trackDuration - $titleCard) / $n));
    } else {
      $spp = self::DEFAULT_SPP;
    }
    $spp = round($spp, 2);
    $sectionSeconds = $n > 0 ? $titleCard + $n * $spp : 0.0;

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
    if (array_key_exists('transition', $data)) {
      $t = (string)$data['transition'];
      if (!isset(self::TRANSITIONS[$t])) throw new InvalidArgumentException('Unknown transition.');
      $sets[] = 'transition = ?'; $params[] = $t;
    }
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
    $tid = $trackId !== null && $trackId > 0 ? $trackId : null;
    $cues = self::parseCues($s['music_cues'] ?? null);
    if ($cues !== [] && $tid !== null) {
      $cues[0]['track_id'] = $tid; // keep the first cue in step with the dropdown
      $st = self::pdo()->prepare('UPDATE slideshow_sections SET track_id = ?, music_cues = ? WHERE id = ?');
      $st->execute([$tid, json_encode($cues), $sectionId]);
    } else {
      $st = self::pdo()->prepare('UPDATE slideshow_sections SET track_id = ?, music_cues = NULL WHERE id = ?');
      $st->execute([$tid, $sectionId]);
    }
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
      $timing = $s['timing'];
      $trackInfo = function (?int $tid) use ($now) {
        if ($tid === null) return null;
        $t = SlideshowTracks::findById($tid);
        if (!$t) return null;
        return [
          'id' => (int)$t['id'], 'title' => (string)$t['title'],
          'url' => SlideshowTracks::urlFor($t, $now), 'content_type' => (string)$t['content_type'],
          'duration_seconds' => $t['duration_seconds'] !== null ? (float)$t['duration_seconds'] : null,
        ];
      };
      $cues = [];
      foreach ($timing['cues'] as $k => $cue) {
        $src = $s['cues'][$k] ?? null;
        $cues[] = $cue + ['track' => $trackInfo($src['track_id'] ?? null)];
      }
      $track = $cues[0]['track'] ?? null;
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
        'photo_starts' => $timing['photo_starts'],
        'cues' => $cues,
        'photos' => $list,
      ];
      $total += (float)$timing['section_seconds'];
    }
    return [
      'ok' => true,
      'id' => (int)$show['id'],
      'title' => (string)$show['title'],
      'description' => $show['description'],
      'transition' => self::transitionOf($show),
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
