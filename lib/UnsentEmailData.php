<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

final class UnsentEmailData {
  private static function pdo(): \PDO {
    return pdo();
  }

  // Best-effort logging helper
  private static function log(string $action, ?int $emailId, array $meta = []): void {
    try {
      $ctx = \UserContext::getLoggedInUserContext();
      if ($emailId !== null && !array_key_exists('email_id', $meta)) {
        $meta['email_id'] = (int)$emailId;
      }
      \ActivityLog::log($ctx, $action, $meta);
    } catch (\Throwable $e) {
      // Best-effort logging; never disrupt the main flow
    }
  }

  /**
   * Create a new unsent email record.
   * 
   * @param UserContext $ctx User context for audit logging
   * @param int $eventId Event ID this email is for
   * @param int $userId User ID this email is being sent to
   * @param string $subject Email subject line
   * @param string $body Email HTML body content
   * @param string|null $icsContent Optional ICS calendar attachment content
   * @return int The ID of the created email record
   * @throws InvalidArgumentException on invalid parameters
   * @throws RuntimeException on database errors
   */
  public static function create(
    \UserContext $ctx, 
    int $eventId, 
    int $userId, 
    string $subject, 
    string $body, 
    ?string $icsContent = null
  ): int {
    if ($eventId <= 0) {
      throw new \InvalidArgumentException('Invalid event ID');
    }
    if ($userId <= 0) {
      throw new \InvalidArgumentException('Invalid user ID');
    }
    if (trim($subject) === '') {
      throw new \InvalidArgumentException('Subject cannot be empty');
    }
    if (trim($body) === '') {
      throw new \InvalidArgumentException('Body cannot be empty');
    }

    $stmt = self::pdo()->prepare('
      INSERT INTO unsent_email_data (event_id, user_id, subject, body, ics_content, sent_by)
      VALUES (?, ?, ?, ?, ?, ?)
    ');
    
    $success = $stmt->execute([
      $eventId, 
      $userId, 
      $subject, 
      $body, 
      $icsContent, 
      $ctx->id
    ]);
    
    if (!$success) {
      throw new \RuntimeException('Failed to create email record');
    }
    
    $emailId = (int)self::pdo()->lastInsertId();
    
    self::log('email.create_unsent', $emailId, [
      'event_id' => $eventId,
      'user_id' => $userId,
      'has_ics' => $icsContent !== null
    ]);
    
    return $emailId;
  }

  /**
   * Find an unsent email record with user information.
   * 
   * @param int $id Email record ID
   * @return array|null Email data with user info, or null if not found/already processed
   */
  public static function findWithUserById(int $id): ?array {
    if ($id <= 0) return null;
    
    $stmt = self::pdo()->prepare('
      SELECT ued.*, u.email, u.first_name, u.last_name 
      FROM unsent_email_data ued 
      JOIN users u ON ued.user_id = u.id 
      WHERE ued.id = ? AND ued.sent_status = ""
    ');
    
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
  }

  /**
   * Mark an email record as successfully sent.
   * 
   * @param UserContext $ctx User context for audit logging
   * @param int $id Email record ID
   * @return bool True if updated successfully
   * @throws RuntimeException on database errors
   */
  public static function markAsSent(\UserContext $ctx, int $id): bool {
    if ($id <= 0) {
      throw new \InvalidArgumentException('Invalid email ID');
    }

    $stmt = self::pdo()->prepare('
      UPDATE unsent_email_data 
      SET sent_status = "sent", sent_at = NOW(), error = NULL 
      WHERE id = ?
    ');
    
    $success = $stmt->execute([$id]);
    
    if ($success && $stmt->rowCount() > 0) {
      self::log('email.mark_sent', $id);
      return true;
    }
    
    return false;
  }

  /**
   * Mark an email record as failed with error message.
   * 
   * @param UserContext $ctx User context for audit logging
   * @param int $id Email record ID
   * @param string $error Error message describing the failure
   * @return bool True if updated successfully
   * @throws RuntimeException on database errors
   */
  public static function markAsFailed(\UserContext $ctx, int $id, string $error): bool {
    if ($id <= 0) {
      throw new \InvalidArgumentException('Invalid email ID');
    }
    if (trim($error) === '') {
      throw new \InvalidArgumentException('Error message cannot be empty');
    }

    $stmt = self::pdo()->prepare('
      UPDATE unsent_email_data 
      SET sent_status = "failed", error = ? 
      WHERE id = ?
    ');
    
    $success = $stmt->execute([$error, $id]);
    
    if ($success && $stmt->rowCount() > 0) {
      self::log('email.mark_failed', $id, ['error' => $error]);
      return true;
    }
    
    return false;
  }

  /**
   * Get basic statistics about unsent emails for an event.
   * 
   * @param int $eventId Event ID
   * @return array Statistics with keys: total, pending, sent, failed
   */
  public static function getStatsForEvent(int $eventId): array {
    if ($eventId <= 0) return ['total' => 0, 'pending' => 0, 'sent' => 0, 'failed' => 0];
    
    $stmt = self::pdo()->prepare('
      SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN sent_status = "" THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN sent_status = "sent" THEN 1 ELSE 0 END) as sent,
        SUM(CASE WHEN sent_status = "failed" THEN 1 ELSE 0 END) as failed
      FROM unsent_email_data 
      WHERE event_id = ?
    ');
    
    $stmt->execute([$eventId]);
    $row = $stmt->fetch();
    
    return [
      'total' => (int)($row['total'] ?? 0),
      'pending' => (int)($row['pending'] ?? 0),
      'sent' => (int)($row['sent'] ?? 0),
      'failed' => (int)($row['failed'] ?? 0)
    ];
  }

  /**
   * Clean up old processed email records (optional maintenance method).
   * 
   * @param UserContext $ctx User context for audit logging
   * @param int $daysOld Delete records older than this many days (default: 30)
   * @return int Number of records deleted
   */
  public static function cleanupOldRecords(\UserContext $ctx, int $daysOld = 30): int {
    if (!$ctx->admin) {
      throw new \RuntimeException('Admin access required for cleanup operations');
    }
    
    if ($daysOld < 7) {
      throw new \InvalidArgumentException('Cannot delete records less than 7 days old');
    }

    $stmt = self::pdo()->prepare('
      DELETE FROM unsent_email_data 
      WHERE sent_status IN ("sent", "failed") 
        AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ');
    
    $stmt->execute([$daysOld]);
    $deletedCount = $stmt->rowCount();
    
    if ($deletedCount > 0) {
      self::log('email.cleanup_old', null, [
        'days_old' => $daysOld,
        'deleted_count' => $deletedCount
      ]);
    }
    
    return $deletedCount;
  }
}
