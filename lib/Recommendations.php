<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/Search.php';
require_once __DIR__ . '/ActivityLog.php';

final class Recommendations {
  private static function pdo(): \PDO { return pdo(); }

  private static function logAction(string $action, array $meta = []): void {
    try {
      $ctx = \UserContext::getLoggedInUserContext();
      \ActivityLog::log($ctx, $action, $meta);
    } catch (\Throwable $e) {
      // best-effort; swallow
    }
  }

  private static function assertAdmin(?\UserContext $ctx): void {
    if (!$ctx || !$ctx->admin) {
      throw new \RuntimeException('Admins only');
    }
  }

  // =========================
  // Reads
  // =========================

  // filters: ['q' => string, 'status' => one of new|active|joined|unsubscribed|new_active]
  public static function list(array $filters = []): array {
    $q = isset($filters['q']) ? trim((string)$filters['q']) : '';
    $status = isset($filters['status']) ? (string)$filters['status'] : 'new_active';

    $params = [];
    $sql = "
      SELECT r.*,
             u.first_name AS submit_first,
             u.last_name  AS submit_last
      FROM recommendations r
      JOIN users u ON u.id = r.created_by_user_id
      WHERE 1=1
    ";

    if ($q !== '') {
      $tokens = \Search::tokenize($q);
      $sql .= \Search::buildAndLikeClause(
        ['r.parent_name','r.child_name','r.email','r.phone'],
        $tokens,
        $params
      );
    }

    switch ($status) {
      case 'new':
        $sql .= " AND r.status = 'new'";
        break;
      case 'active':
        $sql .= " AND r.status = 'active'";
        break;
      case 'joined':
        $sql .= " AND r.status = 'joined'";
        break;
      case 'unsubscribed':
        $sql .= " AND r.status = 'unsubscribed'";
        break;
      case 'new_active':
      default:
        $sql .= " AND r.status IN ('new','active')";
        break;
    }

    $sql .= " ORDER BY r.created_at DESC";

    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll() ?: [];
  }

  public static function getDetail(int $id): ?array {
    $sql = "SELECT r.*,
                   u1.first_name AS submit_first, u1.last_name AS submit_last,
                   u2.first_name AS reached_first, u2.last_name AS reached_last
            FROM recommendations r
            JOIN users u1 ON u1.id = r.created_by_user_id
            LEFT JOIN users u2 ON u2.id = r.reached_out_by_user_id
            WHERE r.id = ?
            LIMIT 1";
    $st = self::pdo()->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public static function listComments(int $recommendationId): array {
    $st = self::pdo()->prepare("
      SELECT rc.*, u.first_name, u.last_name
      FROM recommendation_comments rc
      JOIN users u ON u.id = rc.created_by_user_id
      WHERE rc.recommendation_id=?
      ORDER BY rc.created_at DESC, rc.id DESC
    ");
    $st->execute([$recommendationId]);
    return $st->fetchAll() ?: [];
  }

  // =========================
  // Writes (UserContext required)
  // =========================

  // data: parent_name (required), child_name (required), email?, phone?, grade?, notes?
  public static function create(\UserContext $ctx, array $data): int {
    if (!$ctx) throw new \RuntimeException('Login required');

    $parent = trim((string)($data['parent_name'] ?? ''));
    $child  = trim((string)($data['child_name'] ?? ''));
    $email  = trim((string)($data['email'] ?? ''));
    $phone  = trim((string)($data['phone'] ?? ''));
    $grade  = trim((string)($data['grade'] ?? ''));
    $notes  = trim((string)($data['notes'] ?? ''));

    if ($parent === '' || $child === '') {
      throw new \InvalidArgumentException('Parent and child name are required.');
    }
    $gradeVal = in_array($grade, ['K','1','2','3','4','5'], true) ? $grade : null;

    $st = self::pdo()->prepare("
      INSERT INTO recommendations
        (parent_name, child_name, email, phone, grade, notes, created_by_user_id, created_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $st->execute([
      $parent,
      $child,
      ($email !== '' ? $email : null),
      ($phone !== '' ? $phone : null),
      $gradeVal,
      ($notes !== '' ? $notes : null),
      (int)$ctx->id,
    ]);
    $newId = (int)self::pdo()->lastInsertId();

    self::logAction('recommendation.create', [
      'recommendation_id' => $newId,
      'parent_name' => $parent,
      'child_name' => $child,
      'email_present' => $email !== '',
      'phone_present' => $phone !== '',
      'grade' => $gradeVal,
    ]);

    return $newId;
  }

  // Optional admin edit support (not yet wired to UI)
  public static function update(\UserContext $ctx, int $id, array $fields): bool {
    self::assertAdmin($ctx);
    $allowed = ['parent_name','child_name','email','phone','grade','notes'];
    $set = [];
    $params = [];
    foreach ($allowed as $k) {
      if (!array_key_exists($k, $fields)) continue;
      $v = $fields[$k];
      if (is_string($v)) {
        $v = trim($v);
        if ($v === '') $v = null;
      }
      if ($k === 'grade' && $v !== null && !in_array($v, ['K','1','2','3','4','5'], true)) {
        $v = null;
      }
      $set[] = "$k = ?";
      $params[] = $v;
    }
    if (empty($set)) return false;
    $params[] = $id;

    $sql = "UPDATE recommendations SET " . implode(', ', $set) . " WHERE id = ?";
    $st = self::pdo()->prepare($sql);
    $ok = $st->execute($params);
    if ($ok) {
      self::logAction('recommendation.edit', [
        'recommendation_id' => $id,
        'fields' => array_keys(array_intersect_key(array_flip($allowed), $fields)),
      ]);
    }
    return $ok;
  }

  public static function markReachedOut(\UserContext $ctx, int $id): void {
    self::assertAdmin($ctx);

    // Load current status
    $st = self::pdo()->prepare("SELECT status FROM recommendations WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) throw new \RuntimeException('Not found');
    $from = (string)$row['status'];
    if ($from !== 'new') return; // no-op if precondition unmet

    $st = self::pdo()->prepare("UPDATE recommendations SET status='active', reached_out_at=NOW(), reached_out_by_user_id=? WHERE id=? AND status='new'");
    $st->execute([(int)$ctx->id, $id]);

    if ($st->rowCount() > 0) {
      self::logAction('recommendation.status_change', [
        'recommendation_id' => $id,
        'from' => 'new',
        'to' => 'active',
      ]);
    }
  }

  public static function markJoined(\UserContext $ctx, int $id): void {
    self::assertAdmin($ctx);

    $st = self::pdo()->prepare("SELECT status FROM recommendations WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) throw new \RuntimeException('Not found');
    $from = (string)$row['status'];
    if ($from !== 'active') return;

    $st = self::pdo()->prepare("UPDATE recommendations SET status='joined' WHERE id=? AND status='active'");
    $st->execute([$id]);
    if ($st->rowCount() > 0) {
      self::logAction('recommendation.status_change', [
        'recommendation_id' => $id,
        'from' => 'active',
        'to' => 'joined',
      ]);
    }
  }

  public static function unsubscribe(\UserContext $ctx, int $id): void {
    self::assertAdmin($ctx);

    $st = self::pdo()->prepare("SELECT status FROM recommendations WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) throw new \RuntimeException('Not found');
    $from = (string)$row['status'];
    if (!in_array($from, ['new','active'], true)) return;

    $st = self::pdo()->prepare("UPDATE recommendations SET status='unsubscribed' WHERE id=? AND status IN ('new','active')");
    $st->execute([$id]);
    if ($st->rowCount() > 0) {
      self::logAction('recommendation.status_change', [
        'recommendation_id' => $id,
        'from' => $from,
        'to' => 'unsubscribed',
      ]);
    }
  }

  public static function addComment(\UserContext $ctx, int $id, string $text): void {
    if (!$ctx) throw new \RuntimeException('Login required');
    $text = trim($text);
    if ($text === '') throw new \InvalidArgumentException('Comment cannot be empty.');
    $st = self::pdo()->prepare("INSERT INTO recommendation_comments (recommendation_id, created_by_user_id, text, created_at) VALUES (?,?,?,NOW())");
    $st->execute([$id, (int)$ctx->id, $text]);

    self::logAction('recommendation.add_comment', [
      'recommendation_id' => $id,
    ]);
  }
}
