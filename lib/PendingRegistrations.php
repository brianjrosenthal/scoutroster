<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/UserManagement.php';
require_once __DIR__ . '/ActivityLog.php';

class PendingRegistrations {
  private static function pdo(): PDO { return pdo(); }

  private static function assertLogin(?UserContext $ctx): void {
    if (!$ctx) { throw new RuntimeException('Login required'); }
  }

  private static function assertApprover(?UserContext $ctx): void {
    self::assertLogin($ctx);
    if (!\UserManagement::isApprover((int)$ctx->id)) {
      throw new RuntimeException('Forbidden: not approver');
    }
  }

  private static function assertParentOfYouthOrApprover(?UserContext $ctx, int $youthId): void {
    self::assertLogin($ctx);
    if (\UserManagement::isApprover((int)$ctx->id)) { 
      return; 
    }

    $st = self::pdo()->prepare('SELECT 1 FROM parent_relationships WHERE youth_id=? AND adult_id=? LIMIT 1');
    $st->execute([(int)$youthId, (int)$ctx->id]);
    if (!$st->fetchColumn()) { throw new RuntimeException('Forbidden: not parent of youth or approver'); }
  }

  public static function create(UserContext $ctx, int $youthId, ?int $secureFileId, ?string $comment, ?string $paymentMethod = null): int {
    self::assertParentOfYouthOrApprover($ctx, $youthId);
    $cmt = trim((string)($comment ?? ''));
    if ($cmt === '') $cmt = null;
    
    $pm = trim((string)($paymentMethod ?? ''));
    if ($pm === '') $pm = null;

    $st = self::pdo()->prepare("
      INSERT INTO pending_registrations (youth_id, created_by, secure_file_id, comment, payment_method, status, payment_status, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, 'new', 'not_paid', NOW(), NOW())
    ");
    $ok = $st->execute([(int)$youthId, (int)$ctx->id, $secureFileId ?: null, $cmt, $pm]);
    if (!$ok) { throw new RuntimeException('Failed to save pending registration'); }
    $id = (int)self::pdo()->lastInsertId();

    // Activity log: parent submitted a pending registration
    try {
      \ActivityLog::log($ctx, 'pr.create', [
        'pending_registration_id' => $id,
        'youth_id' => (int)$youthId,
        'has_file' => $secureFileId ? true : false,
        'has_comment' => $cmt !== null,
        'payment_method' => $pm,
      ]);
    } catch (\Throwable $e) {
      // swallow
    }

    return $id;
  }

  // filters: ['status' => 'needing_action'|'completed'|'deleted']
  public static function list(UserContext $ctx, array $filters, int $limit = 20, int $offset = 0): array {
    self::assertApprover($ctx);

    $statusKey = isset($filters['status']) ? trim((string)$filters['status']) : 'needing_action';
    $where = [];
    $params = [];

    if ($statusKey === 'completed') {
      $where[] = "pr.status = 'processed'";
    } elseif ($statusKey === 'deleted') {
      $where[] = "pr.status = 'deleted'";
    } else {
      // needing_action (default)
      $where[] = "pr.status = 'new'";
      $statusKey = 'needing_action';
    }

    $whereSql = implode(' AND ', $where);
    $limit = max(1, min(200, (int)$limit));
    $offset = max(0, (int)$offset);

    $stCount = self::pdo()->prepare("
      SELECT COUNT(*) FROM pending_registrations pr
      JOIN youth y ON y.id = pr.youth_id
      JOIN users u ON u.id = pr.created_by
      WHERE $whereSql
    ");
    $stCount->execute($params);
    $total = (int)($stCount->fetchColumn() ?? 0);

    $st = self::pdo()->prepare("
      SELECT pr.*, y.first_name AS youth_first, y.last_name AS youth_last,
             u.first_name AS by_first, u.last_name AS by_last
      FROM pending_registrations pr
      JOIN youth y ON y.id = pr.youth_id
      JOIN users u ON u.id = pr.created_by
      WHERE $whereSql
      ORDER BY pr.created_at DESC, pr.id DESC
      LIMIT $limit OFFSET $offset
    ");
    $st->execute($params);
    $rows = $st->fetchAll();

    return [
      'total' => $total,
      'rows' => $rows ?: [],
      'limit' => $limit,
      'offset' => $offset,
      'status' => $statusKey,
    ];
  }

  public static function markPaid(UserContext $ctx, int $id, bool $paid): void {
    self::assertApprover($ctx);
    $st = self::pdo()->prepare("UPDATE pending_registrations SET payment_status = ?, updated_at = NOW() WHERE id = ?");
    $st->execute([$paid ? 'paid' : 'not_paid', (int)$id]);

    // Activity log: approver toggled payment status
    try {
      \ActivityLog::log($ctx, 'pr.mark_paid', [
        'pending_registration_id' => (int)$id,
        'paid' => (bool)$paid,
      ]);
    } catch (\Throwable $e) {
      // swallow
    }
  }

  public static function markProcessed(UserContext $ctx, int $id): void {
    self::assertApprover($ctx);
    $st = self::pdo()->prepare("UPDATE pending_registrations SET status = 'processed', updated_at = NOW() WHERE id = ?");
    $st->execute([(int)$id]);

    // Activity log: approver marked processed
    try {
      \ActivityLog::log($ctx, 'pr.mark_processed', [
        'pending_registration_id' => (int)$id,
      ]);
    } catch (\Throwable $e) {
      // swallow
    }
  }

  public static function delete(UserContext $ctx, int $id): void {
    self::assertApprover($ctx);
    $st = self::pdo()->prepare("UPDATE pending_registrations SET status = 'deleted', updated_at = NOW() WHERE id = ?");
    $st->execute([(int)$id]);

    // Activity log: approver deleted pending registration
    try {
      \ActivityLog::log($ctx, 'pr.delete', [
        'pending_registration_id' => (int)$id,
      ]);
    } catch (\Throwable $e) {
      // swallow
    }
  }

  public static function findById(int $id): ?array {
    $st = self::pdo()->prepare("SELECT * FROM pending_registrations WHERE id = ? LIMIT 1");
    $st->execute([(int)$id]);
    $r = $st->fetch();
    return $r ?: null;
  }

  // For homepage indicator: any new pending registration for this youth?
  public static function hasNewForYouth(int $youthId): bool {
    $st = self::pdo()->prepare("SELECT 1 FROM pending_registrations WHERE youth_id = ? AND status = 'new' LIMIT 1");
    $st->execute([(int)$youthId]);
    return (bool)$st->fetchColumn();
  }
}
