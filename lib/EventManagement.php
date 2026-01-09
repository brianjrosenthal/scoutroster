<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

final class EventManagement {
  private static function pdo(): \PDO {
    return pdo();
  }

  private static function str(?string $v): ?string {
    if ($v === null) return null;
    $v = trim($v);
    return $v === '' ? null : $v;
  }

  private static function nn($v) {
    if (is_string($v)) {
      $v = trim($v);
      return $v === '' ? null : $v;
    }
    return $v;
  }

  private static function boolInt($v): int {
    return !empty($v) ? 1 : 0;
  }

  private static function assertAdmin(?\UserContext $ctx): void {
    if (!$ctx) throw new \RuntimeException('Login required');
    if (!$ctx->admin) throw new \RuntimeException('Admins only');
  }

  // Best-effort logging helper (no extra queries)
  private static function log(string $action, ?int $eventId, array $meta = []): void {
    try {
      $ctx = \UserContext::getLoggedInUserContext();
      if ($eventId !== null && !array_key_exists('event_id', $meta)) {
        $meta['event_id'] = (int)$eventId;
      }
      \ActivityLog::log($ctx, $action, $meta);
    } catch (\Throwable $e) {
      // swallow
    }
  }

  // =========================
  // Reads
  // =========================

  public static function findById(int $id): ?array {
    $st = self::pdo()->prepare('SELECT * FROM events WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public static function findBasicById(int $id): ?array {
    $st = self::pdo()->prepare('SELECT id, name, starts_at FROM events WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public static function listUpcoming(int $limit = 100, ?string $since = null): array {
    $limit = max(1, min(500, (int)$limit));
    if ($since === null || trim($since) === '') {
      $st = self::pdo()->prepare("SELECT * FROM events WHERE starts_at >= NOW() ORDER BY starts_at ASC LIMIT $limit");
      $st->execute();
      return $st->fetchAll() ?: [];
    } else {
      $st = self::pdo()->prepare("SELECT * FROM events WHERE starts_at >= ? ORDER BY starts_at ASC LIMIT $limit");
      $st->execute([trim($since)]);
      return $st->fetchAll() ?: [];
    }
  }

  public static function listPast(int $limit = 100): array {
    $limit = max(1, min(500, (int)$limit));
    $st = self::pdo()->prepare("SELECT * FROM events WHERE starts_at < NOW() ORDER BY starts_at DESC LIMIT $limit");
    $st->execute();
    return $st->fetchAll() ?: [];
  }

  public static function listBetween(string $since, string $until): array {
    $since = trim($since);
    $until = trim($until);
    if ($since === '' || $until === '') return [];
    $st = self::pdo()->prepare("SELECT * FROM events WHERE starts_at BETWEEN ? AND ? ORDER BY starts_at ASC");
    $st->execute([$since, $until]);
    return $st->fetchAll() ?: [];
  }

  // =========================
  // Writes (Admin-only)
  // =========================

  /**
   * Create an event. Data keys:
   * - name (required)
   * - starts_at (required, 'Y-m-d H:i:s')
   * - ends_at, location, location_address, description, evaluation, max_cub_scouts (int|null),
   *   allow_non_user_rsvp (0|1), rsvp_url, rsvp_url_label, google_maps_url
   */
  public static function create(\UserContext $ctx, array $data): int {
    self::assertAdmin($ctx);

    $name = self::str((string)($data['name'] ?? ''));
    $starts_at = self::str((string)($data['starts_at'] ?? ''));
    if (!$name || !$starts_at) {
      throw new \InvalidArgumentException('Name and starts_at are required.');
    }

    $ends_at = self::nn($data['ends_at'] ?? null);
    $location = self::nn($data['location'] ?? null);
    $location_address = self::nn($data['location_address'] ?? null);
    $description = self::nn($data['description'] ?? null);
    $evaluation = self::nn($data['evaluation'] ?? null);
    $max_cub_scouts = isset($data['max_cub_scouts']) && $data['max_cub_scouts'] !== '' ? (int)$data['max_cub_scouts'] : null;
    $allow_non_user_rsvp = self::boolInt($data['allow_non_user_rsvp'] ?? 0);
    $needs_medical_form = self::boolInt($data['needs_medical_form'] ?? 0);
    $rsvp_url = self::nn($data['rsvp_url'] ?? null);
    $rsvp_url_label = self::nn($data['rsvp_url_label'] ?? null);
    $where_string = self::nn($data['where_string'] ?? null);
    $registration_field_data_instructions = self::nn($data['registration_field_data_instructions'] ?? null);
    $google_maps_url = self::nn($data['google_maps_url'] ?? null);

    $sql = "INSERT INTO events
      (name, starts_at, ends_at, location, location_address, description, evaluation, max_cub_scouts, allow_non_user_rsvp, needs_medical_form, rsvp_url, rsvp_url_label, where_string, registration_field_data_instructions, google_maps_url)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $st = self::pdo()->prepare($sql);
    $ok = $st->execute([
      $name, $starts_at, $ends_at, $location, $location_address, $description, $evaluation,
      $max_cub_scouts, $allow_non_user_rsvp, $needs_medical_form, $rsvp_url, $rsvp_url_label, $where_string, $registration_field_data_instructions, $google_maps_url,
    ]);
    if (!$ok) throw new \RuntimeException('Failed to create event.');
    $id = (int)self::pdo()->lastInsertId();

    self::log('event.add', $id, []);
    return $id;
  }

  /**
   * Update an event. Data keys like create(); provide only keys to update.
   * Returns true on success.
   */
  public static function update(\UserContext $ctx, int $id, array $data): bool {
    self::assertAdmin($ctx);

    $allowed = [
      'name','starts_at','ends_at','location','location_address','description','evaluation',
      'max_cub_scouts','allow_non_user_rsvp','needs_medical_form','rsvp_url','rsvp_url_label','where_string','registration_field_data_instructions','google_maps_url'
    ];
    $set = [];
    $params = [];

    foreach ($allowed as $key) {
      if (!array_key_exists($key, $data)) continue;
      if ($key === 'max_cub_scouts') {
        $val = ($data[$key] === '' || $data[$key] === null) ? null : (int)$data[$key];
        $set[] = "$key = ?";
        $params[] = $val;
      } elseif ($key === 'allow_non_user_rsvp' || $key === 'needs_medical_form') {
        $set[] = "$key = ?";
        $params[] = self::boolInt($data[$key] ?? 0);
      } else {
        $set[] = "$key = ?";
        $params[] = self::nn($data[$key]);
      }
    }

    if (empty($set)) return false;
    $params[] = $id;

    $sql = "UPDATE events SET " . implode(', ', $set) . " WHERE id = ?";
    $st = self::pdo()->prepare($sql);
    $ok = $st->execute($params);

    if ($ok) {
      // Build list of updated fields for logging
      $fields = [];
      foreach ($set as $s) {
        $pos = strpos($s, ' = ');
        $fields[] = ($pos !== false) ? substr($s, 0, $pos) : $s;
      }
      self::log('event.edit', $id, ['fields' => $fields]);
    }

    return $ok;
  }

  public static function delete(\UserContext $ctx, int $id): int {
    self::assertAdmin($ctx);
    $st = self::pdo()->prepare('DELETE FROM events WHERE id=?');
    $st->execute([$id]);
    $count = (int)$st->rowCount();
    if ($count > 0) {
      self::log('event.delete', $id, []);
    }
    return $count;
  }

  /**
   * Set or clear event photo_public_file_id and log.
   */
  public static function setPhotoPublicFileId(\UserContext $ctx, int $eventId, ?int $publicFileId): bool {
    self::assertAdmin($ctx);
    $st = self::pdo()->prepare('UPDATE events SET photo_public_file_id = ? WHERE id = ?');
    $ok = $st->execute([$publicFileId, $eventId]);
    if ($ok) {
      if ($publicFileId === null) {
        self::log('event.upload_profile_photo', $eventId, ['deleted' => true]);
      } else {
        self::log('event.upload_profile_photo', $eventId, ['public_file_id' => (int)$publicFileId]);
      }
    }
    return $ok;
  }
}
