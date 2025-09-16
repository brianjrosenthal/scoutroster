<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

class ParentRelationships {
  private static function pdo(): PDO {
    return pdo();
  }

  // =========================
  // Queries
  // =========================

  // All children (youth rows) linked to an adult
  public static function listChildrenForAdult(int $adultId): array {
    $st = self::pdo()->prepare("
      SELECT y.*
      FROM parent_relationships pr
      JOIN youth y ON y.id = pr.youth_id
      WHERE pr.adult_id = ?
      ORDER BY y.last_name, y.first_name
    ");
    $st->execute([(int)$adultId]);
    return $st->fetchAll() ?: [];
  }

  // Distinct co-parents (adult user rows) for an adult's children (excludes the adult)
  public static function listCoParentsForAdult(int $adultId): array {
    $st = self::pdo()->prepare("
      SELECT DISTINCT u2.*
      FROM parent_relationships pr1
      JOIN parent_relationships pr2 ON pr1.youth_id = pr2.youth_id
      JOIN users u2 ON u2.id = pr2.adult_id
      WHERE pr1.adult_id = ? AND pr2.adult_id <> ?
      ORDER BY u2.last_name, u2.first_name
    ");
    $st->execute([(int)$adultId, (int)$adultId]);
    return $st->fetchAll() ?: [];
  }

  // Whether an adult is linked to a youth
  public static function isAdultLinkedToYouth(int $adultId, int $youthId): bool {
    $st = self::pdo()->prepare('SELECT 1 FROM parent_relationships WHERE youth_id=? AND adult_id=? LIMIT 1');
    $st->execute([(int)$youthId, (int)$adultId]);
    return (bool)$st->fetchColumn();
  }

  // Count number of parents linked to a youth
  public static function countParents(int $youthId): int {
    $st = self::pdo()->prepare('SELECT COUNT(*) FROM parent_relationships WHERE youth_id=?');
    $st->execute([(int)$youthId]);
    return (int)$st->fetchColumn();
  }

  // All parents (adult user rows) linked to a youth
  public static function listParentsForChild(int $youthId): array {
    $st = self::pdo()->prepare("
      SELECT u.*
      FROM parent_relationships pr
      JOIN users u ON u.id = pr.adult_id
      WHERE pr.youth_id = ?
      ORDER BY u.last_name, u.first_name
    ");
    $st->execute([(int)$youthId]);
    return $st->fetchAll() ?: [];
  }

  // =========================
  // Mutations (require UserContext)
  // =========================

  /**
   * Link a youth and an adult.
   * Auth: admin OR a parent already linked to the youth.
   * ignoreDuplicates: true uses INSERT IGNORE, false uses INSERT (will error on duplicate).
   * Returns true if a row was inserted (i.e., link became effective).
   */
  public static function link(?UserContext $ctx, int $youthId, int $adultId, bool $ignoreDuplicates = true): bool {
    self::assertAdminOrParent($ctx, $youthId);

    $sql = $ignoreDuplicates
      ? 'INSERT IGNORE INTO parent_relationships (youth_id, adult_id) VALUES (?, ?)'
      : 'INSERT INTO parent_relationships (youth_id, adult_id) VALUES (?, ?)';

    $st = self::pdo()->prepare($sql);
    $ok = $st->execute([(int)$youthId, (int)$adultId]);
    if (!$ok) return false;

    $inserted = ((int)$st->rowCount() > 0);
    if ($inserted) {
      self::log('user.child_link_add', (int)$youthId, (int)$adultId);
    }
    return $inserted;
  }

  /**
   * Unlink a youth and an adult.
   * Auth: admin OR a parent already linked to the youth.
   * enforceAtLeastOneParent: when true, throws if trying to remove last parent.
   * Returns true if a row was deleted (i.e., link was removed).
   */
  public static function unlink(?UserContext $ctx, int $youthId, int $adultId, bool $enforceAtLeastOneParent = true): bool {
    self::assertAdminOrParent($ctx, $youthId);

    if ($enforceAtLeastOneParent) {
      $count = self::countParents((int)$youthId);
      if ($count <= 1) {
        throw new RuntimeException('Cannot remove the last parent');
      }
    }

    $st = self::pdo()->prepare('DELETE FROM parent_relationships WHERE youth_id=? AND adult_id=?');
    $st->execute([(int)$youthId, (int)$adultId]);
    $deleted = ((int)$st->rowCount() > 0);
    if ($deleted) {
      self::log('user.child_link_remove', (int)$youthId, (int)$adultId);
    }
    return $deleted;
  }

  // =========================
  // Internals
  // =========================

  private static function assertLogin(?UserContext $ctx): void {
    if (!$ctx) { throw new RuntimeException('Login required'); }
  }

  // Allow admin OR a linked parent of the youth
  private static function assertAdminOrParent(?UserContext $ctx, int $youthId): void {
    self::assertLogin($ctx);
    if ($ctx->admin) return;
    if (!self::isAdultLinkedToYouth((int)$ctx->id, (int)$youthId)) {
      throw new RuntimeException('Not authorized');
    }
  }

  private static function log(string $action, int $youthId, int $adultId): void {
    try {
      $ctx = \UserContext::getLoggedInUserContext();
      \ActivityLog::log($ctx, $action, [
        'youth_id'  => (int)$youthId,
        'parent_id' => (int)$adultId,
      ]);
    } catch (\Throwable $e) {
      // best-effort logging
    }
  }
}
