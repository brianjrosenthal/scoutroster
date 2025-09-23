<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

final class EventInvitationTracking {
  private static function pdo(): \PDO {
    return pdo();
  }

  /**
   * Record that an invitation was sent to a user for an event.
   * Inserts a new record or increments the count if one already exists.
   * 
   * @param int $eventId The event ID
   * @param int $userId The user ID
   * @throws \Exception If database operation fails
   */
  public static function recordInvitationSent(int $eventId, int $userId): void {
    $sql = "INSERT INTO event_invitations_sent (event_id, user_id, n, last_sent_at, created_at)
            VALUES (:event_id, :user_id, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
              n = n + 1,
              last_sent_at = NOW()";
    
    $stmt = self::pdo()->prepare($sql);
    $stmt->bindValue(':event_id', $eventId, \PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
    
    if (!$stmt->execute()) {
      throw new \Exception('Failed to record invitation sent');
    }
  }

  /**
   * Get the number of invitations sent to a user for an event.
   * 
   * @param int $eventId The event ID
   * @param int $userId The user ID
   * @return int The number of invitations sent (0 if none)
   */
  public static function getInvitationCount(int $eventId, int $userId): int {
    $sql = "SELECT n FROM event_invitations_sent 
            WHERE event_id = :event_id AND user_id = :user_id";
    
    $stmt = self::pdo()->prepare($sql);
    $stmt->bindValue(':event_id', $eventId, \PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
    $stmt->execute();
    
    $result = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $result ? (int)$result['n'] : 0;
  }

  /**
   * Get the timestamp when the last invitation was sent to a user for an event.
   * 
   * @param int $eventId The event ID
   * @param int $userId The user ID
   * @return string|null The last sent timestamp (Y-m-d H:i:s format) or null if none
   */
  public static function getLastSentAt(int $eventId, int $userId): ?string {
    $sql = "SELECT last_sent_at FROM event_invitations_sent 
            WHERE event_id = :event_id AND user_id = :user_id";
    
    $stmt = self::pdo()->prepare($sql);
    $stmt->bindValue(':event_id', $eventId, \PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
    $stmt->execute();
    
    $result = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $result ? (string)$result['last_sent_at'] : null;
  }

  /**
   * Check if an invitation should be suppressed based on the policy.
   * 
   * @param int $eventId The event ID
   * @param int $userId The user ID
   * @param string $policy The suppression policy: 'last_24_hours', 'ever_invited', or 'none'
   * @return bool True if the invitation should be suppressed, false otherwise
   */
  public static function shouldSuppressInvitation(int $eventId, int $userId, string $policy): bool {
    switch ($policy) {
      case 'last_24_hours':
        $lastSent = self::getLastSentAt($eventId, $userId);
        if ($lastSent === null) {
          return false; // Never sent, don't suppress
        }
        
        // Check if last sent was within 24 hours
        $lastSentTime = new \DateTime($lastSent);
        $now = new \DateTime();
        $diff = $now->getTimestamp() - $lastSentTime->getTimestamp();
        return $diff < (24 * 60 * 60); // 24 hours in seconds
        
      case 'ever_invited':
        $count = self::getInvitationCount($eventId, $userId);
        return $count > 0;
        
      case 'none':
      default:
        return false; // No suppression
    }
  }

  /**
   * Get invitation statistics for an event.
   * 
   * @param int $eventId The event ID
   * @return array Array with keys: total_invitations, unique_users, most_recent_sent
   */
  public static function getEventInvitationStats(int $eventId): array {
    $sql = "SELECT 
              COUNT(*) as unique_users,
              SUM(n) as total_invitations,
              MAX(last_sent_at) as most_recent_sent
            FROM event_invitations_sent 
            WHERE event_id = :event_id";
    
    $stmt = self::pdo()->prepare($sql);
    $stmt->bindValue(':event_id', $eventId, \PDO::PARAM_INT);
    $stmt->execute();
    
    $result = $stmt->fetch(\PDO::FETCH_ASSOC);
    
    return [
      'unique_users' => (int)($result['unique_users'] ?? 0),
      'total_invitations' => (int)($result['total_invitations'] ?? 0),
      'most_recent_sent' => $result['most_recent_sent']
    ];
  }

  /**
   * Get a list of users who have been invited to an event with their invitation details.
   * 
   * @param int $eventId The event ID
   * @param int $limit Maximum number of results to return
   * @param int $offset Offset for pagination
   * @return array Array of invitation records with user details
   */
  public static function getEventInvitationHistory(int $eventId, int $limit = 50, int $offset = 0): array {
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);
    
    $sql = "SELECT 
              eis.user_id,
              eis.n,
              eis.last_sent_at,
              eis.created_at,
              u.first_name,
              u.last_name,
              u.email
            FROM event_invitations_sent eis
            JOIN users u ON eis.user_id = u.id
            WHERE eis.event_id = :event_id
            ORDER BY eis.last_sent_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = self::pdo()->prepare($sql);
    $stmt->bindValue(':event_id', $eventId, \PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
  }

  /**
   * Filter a list of user IDs based on suppression policy.
   * Returns only the user IDs that should receive invitations.
   * 
   * @param int $eventId The event ID
   * @param array $userIds Array of user IDs to filter
   * @param string $policy The suppression policy
   * @return array Array of user IDs that should receive invitations
   */
  public static function filterUsersBySuppressionPolicy(int $eventId, array $userIds, string $policy): array {
    if ($policy === 'none' || empty($userIds)) {
      return $userIds; // No filtering needed
    }
    
    $filtered = [];
    foreach ($userIds as $userId) {
      if (!self::shouldSuppressInvitation($eventId, (int)$userId, $policy)) {
        $filtered[] = $userId;
      }
    }
    
    return $filtered;
  }
}
