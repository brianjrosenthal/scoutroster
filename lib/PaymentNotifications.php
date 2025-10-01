<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/Search.php';
require_once __DIR__ . '/YouthManagement.php';
require_once __DIR__ . '/UserManagement.php';
require_once __DIR__ . '/ActivityLog.php';

final class PaymentNotifications {
  private static function pdo(): PDO { return pdo(); }

  // Only Cubmaster/Committee Chair/Treasurer qualify as "approver" for this feature
  private static function assertApprover(?UserContext $ctx): void {
    if (!$ctx) { throw new RuntimeException('Login required'); }
    if (!\UserManagement::isApprover((int)$ctx->id)) {
      throw new RuntimeException('Forbidden: not approver');
    }
  }

  // Ensure the given youth is linked to current user (parent)
  private static function assertParentOfYouthOrApprover(?UserContext $ctx, int $youthId): void {
    if (!$ctx) { throw new RuntimeException('Login required'); }
    if (\UserManagement::isApprover((int)$ctx->id)) {
      return;
    }
    $st = self::pdo()->prepare('SELECT 1 FROM parent_relationships WHERE youth_id=? AND adult_id=? LIMIT 1');
    $st->execute([$youthId, (int)$ctx->id]);
    if (!$st->fetchColumn()) { throw new RuntimeException('Forbidden: not parent of youth or approver'); }
  }

  public static function create(UserContext $ctx, int $youthId, string $method, ?string $comment = null): int {
    self::assertParentOfYouthOrApprover($ctx, $youthId);
    $valid = ['Paypal','Zelle','Venmo','Check','Other'];
    if (!in_array($method, $valid, true)) {
      throw new InvalidArgumentException('Invalid payment method.');
    }
    $cmt = trim((string)($comment ?? ''));
    if ($cmt === '') $cmt = null;

    $st = self::pdo()->prepare("
      INSERT INTO payment_notifications_from_users (youth_id, created_by, payment_method, comment, status, created_at)
      VALUES (?, ?, ?, ?, 'new', NOW())
    ");
    $ok = $st->execute([$youthId, (int)$ctx->id, $method, $cmt]);
    if (!$ok) { throw new RuntimeException('Failed to save notification.'); }
    $id = (int)self::pdo()->lastInsertId();

    // Activity log: parent submitted a payment notification
    try {
      \ActivityLog::log($ctx, 'pn.create', [
        'notification_id' => $id,
        'youth_id' => (int)$youthId,
        'method' => (string)$method,
        'has_comment' => $cmt !== null,
      ]);
    } catch (\Throwable $e) {
      // swallow
    }

    return $id;
  }

  // Filters: ['q' => string, 'status' => 'new'|'verified'|'deleted', 'limit' => int, 'offset' => int]
  public static function list(UserContext $ctx, array $filters, int $limit = 20, int $offset = 0): array {
    self::assertApprover($ctx);

    $params = [];
    $where = ['1=1'];

    // Status filter
    $status = isset($filters['status']) ? trim((string)$filters['status']) : 'new';
    if (!in_array($status, ['new','verified','deleted'], true)) {
      $status = 'new';
    }
    $where[] = 'pn.status = ?';
    $params[] = $status;

    // Tokenized text search across youth/adult names
    $q = isset($filters['q']) ? trim((string)$filters['q']) : '';
    $tokens = [];
    if ($q !== '') {
      $tokens = \Search::tokenize($q);
      foreach ($tokens as $tok) {
        $where[] = "(LOWER(y.first_name) LIKE ? OR LOWER(y.last_name) LIKE ? OR LOWER(u.first_name) LIKE ? OR LOWER(u.last_name) LIKE ?)";
        $like = '%' . mb_strtolower($tok, 'UTF-8') . '%';
        array_push($params, $like, $like, $like, $like);
      }
    }

    $whereSql = implode(' AND ', $where);
    $limit = max(1, min(100, (int)$limit));
    $offset = max(0, (int)$offset);

    // Total count
    $stCount = self::pdo()->prepare("
      SELECT COUNT(*) AS c
      FROM payment_notifications_from_users pn
      JOIN youth y ON y.id = pn.youth_id
      JOIN users u ON u.id = pn.created_by
      WHERE $whereSql
    ");
    $stCount->execute($params);
    $total = (int)($stCount->fetchColumn() ?? 0);

    // Page rows
    $sql = "
      SELECT pn.*, y.first_name AS youth_first, y.last_name AS youth_last,
             u.first_name AS by_first, u.last_name AS by_last
      FROM payment_notifications_from_users pn
      JOIN youth y ON y.id = pn.youth_id
      JOIN users u ON u.id = pn.created_by
      WHERE $whereSql
      ORDER BY pn.created_at DESC, pn.id DESC
      LIMIT $limit OFFSET $offset
    ";
    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    return [
      'total' => $total,
      'rows' => $rows ?: [],
      'limit' => $limit,
      'offset' => $offset,
      'status' => $status,
      'q' => $q,
    ];
  }

  public static function verify(UserContext $ctx, int $id, string $paidUntilYmd): void {
    self::assertApprover($ctx);
    // Validate notification
    $row = self::findById($id);
    if (!$row) throw new RuntimeException('Not found');
    // Update youth "paid until" (YouthManagement enforces approver)
    YouthManagement::update($ctx, (int)$row['youth_id'], ['date_paid_until' => $paidUntilYmd]);
    // Mark verified
    $st = self::pdo()->prepare("UPDATE payment_notifications_from_users SET status='verified' WHERE id=?");
    $st->execute([$id]);

    // Activity log: approver verified the payment notification
    try {
      \ActivityLog::log($ctx, 'pn.verify', [
        'notification_id' => (int)$id,
        'youth_id' => (int)($row['youth_id'] ?? 0),
        'paid_until' => (string)$paidUntilYmd,
      ]);
    } catch (\Throwable $e) {
      // swallow
    }
  }

  public static function delete(UserContext $ctx, int $id): void {
    self::assertApprover($ctx);
    $row = self::findById($id);
    if (!$row) throw new RuntimeException('Not found');
    $st = self::pdo()->prepare("UPDATE payment_notifications_from_users SET status='deleted' WHERE id=?");
    $st->execute([$id]);

    // Activity log: approver deleted the payment notification
    try {
      \ActivityLog::log($ctx, 'pn.delete', [
        'notification_id' => (int)$id,
        'youth_id' => (int)($row['youth_id'] ?? 0),
      ]);
    } catch (\Throwable $e) {
      // swallow
    }
  }

  public static function findById(int $id): ?array {
    $st = self::pdo()->prepare("SELECT * FROM payment_notifications_from_users WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
  }

  // Check if there is any recent (< days) not-deleted notification for this youth.
  // Note: registration applications are now tracked in pending_registrations;
  // this method is only used for renewal processing indicators.
  public static function hasRecentActiveForYouth(int $youthId, bool $ignoredNewApplicationFlag = false, int $days = 30): bool {
    $sql = "SELECT 1
            FROM payment_notifications_from_users
            WHERE youth_id = ?
              AND status = 'new' 
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            LIMIT 1";
    $st = self::pdo()->prepare($sql);
    $st->execute([(int)$youthId, (int)$days]);
    return (bool)$st->fetchColumn();
  }

  // Helper to fetch leadership recipients (Cubmaster/Committee Chair/Treasurer with emails)
  public static function leaderRecipients(): array {
    $st = self::pdo()->prepare("SELECT DISTINCT u.email, u.first_name, u.last_name
                                FROM adult_leadership_positions alp
                                JOIN adult_leadership_position_assignments alpa ON alp.id = alpa.adult_leadership_position_id
                                JOIN users u ON u.id = alpa.adult_id
                                WHERE alp.name IN ('Cubmaster','Committee Chair','Treasurer')
                                  AND u.email IS NOT NULL AND u.email <> ''");
    $st->execute();
    return $st->fetchAll() ?: [];
  }
}
