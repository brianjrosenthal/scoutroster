<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

final class RSVPManagement {
  private static function pdo(): \PDO {
    return pdo();
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
  // Reads / Helpers
  // =========================

  /**
   * Find the RSVP "group" for an event associated with an adult user:
   * 1) Prefer the group created by the user
   * 2) Else any group where the user is included as an adult member
   */
  public static function findMyRsvpForEvent(int $eventId, int $adultUserId): ?array {
    $eventId = (int)$eventId; $adultUserId = (int)$adultUserId;
    if ($eventId <= 0 || $adultUserId <= 0) return null;

    $st = self::pdo()->prepare("SELECT * FROM rsvps WHERE event_id=? AND created_by_user_id=? LIMIT 1");
    $st->execute([$eventId, $adultUserId]);
    $row = $st->fetch();
    if ($row) return $row;

    $st = self::pdo()->prepare("
      SELECT r.*
      FROM rsvps r
      JOIN rsvp_members rm ON rm.rsvp_id = r.id AND rm.event_id = r.event_id
      WHERE r.event_id = ? AND rm.participant_type='adult' AND rm.adult_id=?
      LIMIT 1
    ");
    $st->execute([$eventId, $adultUserId]);
    $row = $st->fetch();
    return $row ?: null;
  }

  /**
   * Find the family RSVP for an event by adult ID.
   * Gets all children of the adult, then all co-parents of these children,
   * and looks for existing RSVPs created by any of these co-parents.
   * If multiple exist, prefers the one created by the original adult.
   */
  public static function getRSVPForFamilyByAdultID(int $eventId, int $adultId): ?array {
    require_once __DIR__ . '/ParentRelationships.php';
    
    $eventId = (int)$eventId; 
    $adultId = (int)$adultId;
    if ($eventId <= 0 || $adultId <= 0) return null;

    // Get all children of this adult
    $children = ParentRelationships::listChildrenForAdult($adultId);
    
    // Get all co-parents of these children
    $coParents = ParentRelationships::listCoParentsForAdult($adultId);
    
    // Build list of all potential RSVP creators (original adult + co-parents)
    $potentialCreators = [$adultId];
    foreach ($coParents as $coParent) {
      $potentialCreators[] = (int)$coParent['id'];
    }
    
    if (empty($potentialCreators)) return null;
    
    // Look for RSVPs created by any of these adults
    $placeholders = str_repeat('?,', count($potentialCreators) - 1) . '?';
    $st = self::pdo()->prepare("
      SELECT * FROM rsvps 
      WHERE event_id = ? AND created_by_user_id IN ($placeholders)
      ORDER BY CASE WHEN created_by_user_id = ? THEN 0 ELSE 1 END
      LIMIT 1
    ");
    
    $params = [$eventId];
    $params = array_merge($params, $potentialCreators);
    $params[] = $adultId; // For the ORDER BY preference
    
    $st->execute($params);
    $row = $st->fetch();
    return $row ?: null;
  }

  /**
   * Find the family RSVP for an event by youth ID.
   * Gets all parents of the youth and looks for existing RSVPs 
   * created by any of these parents.
   */
  public static function getRSVPForFamilyByYouthID(int $eventId, int $youthId): ?array {
    require_once __DIR__ . '/ParentRelationships.php';
    
    $eventId = (int)$eventId; 
    $youthId = (int)$youthId;
    if ($eventId <= 0 || $youthId <= 0) return null;

    // Get all parents of this youth
    $parents = ParentRelationships::listParentsForChild($youthId);
    
    if (empty($parents)) return null;
    
    // Build list of potential RSVP creators (all parents)
    $potentialCreators = [];
    foreach ($parents as $parent) {
      $potentialCreators[] = (int)$parent['id'];
    }
    
    if (empty($potentialCreators)) return null;
    
    // Look for RSVPs created by any of these parents
    $placeholders = str_repeat('?,', count($potentialCreators) - 1) . '?';
    $st = self::pdo()->prepare("
      SELECT * FROM rsvps 
      WHERE event_id = ? AND created_by_user_id IN ($placeholders)
      LIMIT 1
    ");
    
    $params = [$eventId];
    $params = array_merge($params, $potentialCreators);
    
    $st->execute($params);
    $row = $st->fetch();
    return $row ?: null;
  }

  /**
   * Return member IDs by type for a given RSVP group.
   * Returns: ['adult_ids' => int[], 'youth_ids' => int[]]
   */
  public static function getMemberIdsByType(int $rsvpId): array {
    $rsvpId = (int)$rsvpId;
    if ($rsvpId <= 0) return ['adult_ids' => [], 'youth_ids' => []];

    $st = self::pdo()->prepare("SELECT participant_type, youth_id, adult_id FROM rsvp_members WHERE rsvp_id=? ORDER BY id");
    $st->execute([$rsvpId]);
    $adults = [];
    $youths = [];
    foreach ($st->fetchAll() as $rm) {
      if (($rm['participant_type'] ?? null) === 'adult' && !empty($rm['adult_id'])) {
        $adults[] = (int)$rm['adult_id'];
      } elseif (($rm['participant_type'] ?? null) === 'youth' && !empty($rm['youth_id'])) {
        $youths[] = (int)$rm['youth_id'];
      }
    }
    $adults = array_values(array_unique($adults));
    $youths = array_values(array_unique($youths));
    return ['adult_ids' => $adults, 'youth_ids' => $youths];
  }

  public static function countYouthForEvent(int $eventId): int {
    $st = self::pdo()->prepare("SELECT COUNT(*) AS c FROM rsvp_members WHERE event_id=? AND participant_type='youth'");
    $st->execute([$eventId]);
    $row = $st->fetch();
    return (int)($row['c'] ?? 0);
  }

  /**
   * Get RSVP counts for an event (only "yes" responses).
   * Returns array with 'adults' and 'youth' keys, or null if no RSVPs.
   */
  public static function getYesRsvpCounts(int $eventId): ?array {
    // Count adults who RSVP'd yes
    $stAdults = self::pdo()->prepare(
      "SELECT COUNT(DISTINCT rm.adult_id) AS c 
       FROM rsvp_members rm 
       JOIN rsvps r ON r.id = rm.rsvp_id 
       WHERE rm.event_id = ? AND rm.participant_type = 'adult' AND r.answer = 'yes'"
    );
    $stAdults->execute([$eventId]);
    $rowAdults = $stAdults->fetch();
    $adultsCount = (int)($rowAdults['c'] ?? 0);

    // Count youth who RSVP'd yes
    $stYouth = self::pdo()->prepare(
      "SELECT COUNT(DISTINCT rm.youth_id) AS c 
       FROM rsvp_members rm 
       JOIN rsvps r ON r.id = rm.rsvp_id 
       WHERE rm.event_id = ? AND rm.participant_type = 'youth' AND r.answer = 'yes'"
    );
    $stYouth->execute([$eventId]);
    $rowYouth = $stYouth->fetch();
    $youthCount = (int)($rowYouth['c'] ?? 0);

    // Return null if no RSVPs at all
    if ($adultsCount === 0 && $youthCount === 0) {
      // Check if there are any RSVPs for this event
      $stCheck = self::pdo()->prepare("SELECT COUNT(*) AS c FROM rsvps WHERE event_id = ?");
      $stCheck->execute([$eventId]);
      $rowCheck = $stCheck->fetch();
      if ((int)($rowCheck['c'] ?? 0) === 0) {
        return null;
      }
    }

    return [
      'adults' => $adultsCount,
      'youth' => $youthCount
    ];
  }

  public static function countYouthForRsvp(int $rsvpId): int {
    $st = self::pdo()->prepare("SELECT COUNT(*) AS c FROM rsvp_members WHERE rsvp_id=? AND participant_type='youth'");
    $st->execute([(int)$rsvpId]);
    $row = $st->fetch();
    return (int)($row['c'] ?? 0);
  }

  // =========================
  // Aggregates and additional helpers
  // =========================

  /**
   * Find RSVP created by a specific adult for an event (creator-match only).
   */
  public static function findCreatorRsvpForEvent(int $eventId, int $creatorAdultId): ?array {
    $st = self::pdo()->prepare("SELECT * FROM rsvps WHERE event_id=? AND created_by_user_id=? LIMIT 1");
    $st->execute([(int)$eventId, (int)$creatorAdultId]);
    $row = $st->fetch();
    return $row ?: null;
  }

  /**
   * Get the answer for a creator's RSVP (yes/maybe/no) or null if none.
   */
  public static function getAnswerForCreator(int $eventId, int $creatorAdultId): ?string {
    $st = self::pdo()->prepare("SELECT answer FROM rsvps WHERE event_id=? AND created_by_user_id=? LIMIT 1");
    $st->execute([(int)$eventId, (int)$creatorAdultId]);
    $row = $st->fetch();
    if (!$row) return null;
    $ans = strtolower(trim((string)($row['answer'] ?? '')));
    return in_array($ans, ['yes','maybe','no'], true) ? $ans : null;
  }

  /**
   * Get the RSVP answer for a user for an event (yes/maybe/no) or null if none.
   * This is the standard method to replace the getRsvpStatus() function.
   * 
   * @param int $eventId Event ID
   * @param int $userId User ID
   * @return string|null RSVP answer ('yes', 'maybe', 'no') or null if no RSVP found
   */
  public static function getAnswerForUserEvent(int $eventId, int $userId): ?string {
    if ($eventId <= 0 || $userId <= 0) return null;
    
    $st = self::pdo()->prepare("SELECT answer FROM rsvps WHERE event_id=? AND created_by_user_id=? LIMIT 1");
    $st->execute([$eventId, $userId]);
    $row = $st->fetch();
    if (!$row) return null;
    
    $ans = strtolower(trim((string)($row['answer'] ?? '')));
    return in_array($ans, ['yes','maybe','no'], true) ? $ans : null;
  }

  /**
   * Sum n_guests across RSVPs for an event by answer.
   */
  public static function sumGuestsByAnswer(int $eventId, string $answer): int {
    $ans = strtolower(trim($answer));
    if (!in_array($ans, ['yes','maybe','no'], true)) return 0;
    $st = self::pdo()->prepare("SELECT COALESCE(SUM(n_guests),0) AS g FROM rsvps WHERE event_id=? AND answer=?");
    $st->execute([(int)$eventId, $ans]);
    $row = $st->fetch();
    return (int)($row['g'] ?? 0);
  }

  /**
   * List adult names "Last, First" for an event filtered by RSVP answer.
   * Returns sorted unique strings.
   */
  public static function listAdultNamesByAnswer(int $eventId, string $answer): array {
    $ans = strtolower(trim($answer));
    if (!in_array($ans, ['yes','maybe','no'], true)) return [];
    $st = self::pdo()->prepare("
      SELECT DISTINCT u.last_name AS ln, u.first_name AS fn
      FROM rsvp_members rm
      JOIN rsvps r ON r.id = rm.rsvp_id AND r.answer = ?
      JOIN users u ON u.id = rm.adult_id
      WHERE rm.event_id = ? AND rm.participant_type='adult' AND rm.adult_id IS NOT NULL
    ");
    $st->execute([$ans, (int)$eventId]);
    $names = [];
    foreach ($st->fetchAll() as $r) {
      $ln = trim((string)($r['ln'] ?? '')); $fn = trim((string)($r['fn'] ?? ''));
      $names[] = trim($ln . ', ' . $fn, ' ,');
    }
    $names = array_values(array_unique($names));
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
  }

  /**
   * List youth names "Last, First" for an event filtered by RSVP answer.
   * Returns sorted unique strings.
   */
  public static function listYouthNamesByAnswer(int $eventId, string $answer): array {
    $ans = strtolower(trim($answer));
    if (!in_array($ans, ['yes','maybe','no'], true)) return [];
    $st = self::pdo()->prepare("
      SELECT DISTINCT y.last_name AS ln, y.first_name AS fn
      FROM rsvp_members rm
      JOIN rsvps r ON r.id = rm.rsvp_id AND r.answer = ?
      JOIN youth y ON y.id = rm.youth_id
      WHERE rm.event_id = ? AND rm.participant_type='youth' AND rm.youth_id IS NOT NULL
    ");
    $st->execute([$ans, (int)$eventId]);
    $names = [];
    foreach ($st->fetchAll() as $r) {
      $ln = trim((string)($r['ln'] ?? '')); $fn = trim((string)($r['fn'] ?? ''));
      $names[] = trim($ln . ', ' . $fn, ' ,');
    }
    $names = array_values(array_unique($names));
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
  }

  /**
   * Count distinct adult and youth participants for an event filtered by RSVP answer.
   * Returns: ['adults' => int, 'youth' => int]
   */
  public static function countDistinctParticipantsByAnswer(int $eventId, string $answer): array {
    $ans = strtolower(trim($answer));
    if (!in_array($ans, ['yes','maybe','no'], true)) return ['adults' => 0, 'youth' => 0];
    $st = self::pdo()->prepare("
      SELECT
        COUNT(DISTINCT CASE WHEN rm.participant_type='adult' THEN rm.adult_id END) AS a,
        COUNT(DISTINCT CASE WHEN rm.participant_type='youth' THEN rm.youth_id END) AS y
      FROM rsvp_members rm
      JOIN rsvps r ON r.id = rm.rsvp_id AND r.answer = ?
      WHERE rm.event_id = ?
    ");
    $st->execute([$ans, (int)$eventId]);
    $row = $st->fetch() ?: [];
    return [
      'adults' => (int)($row['a'] ?? 0),
      'youth'  => (int)($row['y'] ?? 0),
    ];
  }

  // =========================
  // Additional summaries and utilities
  // =========================

  /**
   * Return counts of adults and youth in a given RSVP.
   * Returns: ['adults' => int, 'youth' => int]
   */
  public static function getMemberCountsForRsvp(int $rsvpId): array {
    $rsvpId = (int)$rsvpId;
    if ($rsvpId <= 0) return ['adults' => 0, 'youth' => 0];
    $st = self::pdo()->prepare("
      SELECT
        SUM(CASE WHEN participant_type='adult' THEN 1 ELSE 0 END) AS a,
        SUM(CASE WHEN participant_type='youth' THEN 1 ELSE 0 END) AS y
      FROM rsvp_members
      WHERE rsvp_id = ?
    ");
    $st->execute([$rsvpId]);
    $row = $st->fetch() ?: [];
    return ['adults' => (int)($row['a'] ?? 0), 'youth' => (int)($row['y'] ?? 0)];
  }

  /**
   * Get a concise RSVP summary for a user on an event (creator-preferred; else membership).
   * Returns null if none found.
   * Returns keys: id, answer, n_guests, created_by_user_id, adult_count, youth_count
   */
  public static function getRsvpSummaryForUserEvent(int $eventId, int $adultUserId): ?array {
    $r = self::findMyRsvpForEvent((int)$eventId, (int)$adultUserId);
    if (!$r) return null;
    $counts = self::getMemberCountsForRsvp((int)$r['id']);
    return [
      'id' => (int)$r['id'],
      'answer' => (string)$r['answer'],
      'n_guests' => (int)$r['n_guests'],
      'created_by_user_id' => (int)$r['created_by_user_id'],
      'adult_count' => (int)($counts['adults'] ?? 0),
      'youth_count' => (int)($counts['youth'] ?? 0),
    ];
  }

  /**
   * Map created_by_user_id => comments (non-empty) for an event's RSVPs.
   */
  public static function getCommentsByCreatorForEvent(int $eventId): array {
    $st = self::pdo()->prepare("SELECT created_by_user_id, comments FROM rsvps WHERE event_id=?");
    $st->execute([(int)$eventId]);
    $map = [];
    foreach ($st->fetchAll() as $rv) {
      $aid = (int)($rv['created_by_user_id'] ?? 0);
      $c = trim((string)($rv['comments'] ?? ''));
      if ($aid > 0 && $c !== '') { $map[$aid] = $c; }
    }
    return $map;
  }

  /**
   * Map adult_id => comments for an event's RSVPs, but expand comments to all parents
   * in the same family. This ensures that if one parent RSVPs with comments, all parents
   * in that family will see those comments.
   * 
   * This function is intentionally verbose and may include redundant comments to ensure
   * no parent misses important information from their family's RSVPs.
   */
  public static function getCommentsByParentForEvent(int $eventId): array {
    $eventId = (int)$eventId;
    if ($eventId <= 0) return [];

    // Start with all RSVPs that have comments for this event
    $st = self::pdo()->prepare("SELECT id, created_by_user_id, comments FROM rsvps WHERE event_id=? AND comments IS NOT NULL AND TRIM(comments) != ''");
    $st->execute([$eventId]);
    $rsvpsWithComments = $st->fetchAll();
    
    if (empty($rsvpsWithComments)) {
      return [];
    }

    $commentsByParent = [];

    foreach ($rsvpsWithComments as $rsvp) {
      $rsvpId = (int)$rsvp['id'];
      $creatorId = (int)$rsvp['created_by_user_id'];
      $comments = trim((string)$rsvp['comments']);
      
      if ($comments === '') continue;

      // Find all adults in this RSVP group
      $stMembers = self::pdo()->prepare("
        SELECT DISTINCT adult_id 
        FROM rsvp_members 
        WHERE rsvp_id = ? AND participant_type = 'adult' AND adult_id IS NOT NULL
      ");
      $stMembers->execute([$rsvpId]);
      $rsvpAdults = [];
      foreach ($stMembers->fetchAll() as $member) {
        $adultId = (int)$member['adult_id'];
        if ($adultId > 0) {
          $rsvpAdults[] = $adultId;
        }
      }

      // Always include the creator, even if they're not explicitly in rsvp_members
      if (!in_array($creatorId, $rsvpAdults)) {
        $rsvpAdults[] = $creatorId;
      }

      // For each adult in this RSVP, find all other parents who share children with them
      $allRelatedParents = [];
      foreach ($rsvpAdults as $adultId) {
        $allRelatedParents[] = $adultId;
        
        // Find all youth this adult is a parent of
        $stYouth = self::pdo()->prepare("
          SELECT DISTINCT youth_id 
          FROM parent_relationships 
          WHERE adult_id = ?
        ");
        $stYouth->execute([$adultId]);
        $youthIds = [];
        foreach ($stYouth->fetchAll() as $youth) {
          $youthIds[] = (int)$youth['youth_id'];
        }

        // For each youth, find all other parents
        if (!empty($youthIds)) {
          $placeholders = str_repeat('?,', count($youthIds) - 1) . '?';
          $stOtherParents = self::pdo()->prepare("
            SELECT DISTINCT adult_id 
            FROM parent_relationships 
            WHERE youth_id IN ($placeholders) AND adult_id != ?
          ");
          $stOtherParents->execute(array_merge($youthIds, [$adultId]));
          foreach ($stOtherParents->fetchAll() as $parent) {
            $parentId = (int)$parent['adult_id'];
            if ($parentId > 0 && !in_array($parentId, $allRelatedParents)) {
              $allRelatedParents[] = $parentId;
            }
          }
        }
      }

      // Add comments for all related parents
      foreach ($allRelatedParents as $parentId) {
        if ($parentId > 0) {
          // If this parent already has comments, append with a separator
          if (isset($commentsByParent[$parentId])) {
            $existingComments = trim($commentsByParent[$parentId]);
            if ($existingComments !== $comments) {
              $commentsByParent[$parentId] = $existingComments . "\n\n" . $comments;
            }
          } else {
            $commentsByParent[$parentId] = $comments;
          }
        }
      }
    }

    return $commentsByParent;
  }

  /**
   * Adult entries for an event by answer: array of ['id' => adult_id, 'name' => 'Last, First']
   * Sorted case-insensitively.
   */
  public static function listAdultEntriesByAnswer(int $eventId, string $answer): array {
    $ans = strtolower(trim($answer));
    if (!in_array($ans, ['yes','maybe','no'], true)) return [];
    $st = self::pdo()->prepare("
      SELECT DISTINCT u.id AS adult_id, u.last_name AS ln, u.first_name AS fn
      FROM rsvp_members rm
      JOIN rsvps r ON r.id = rm.rsvp_id AND r.answer = ?
      JOIN users u ON u.id = rm.adult_id
      WHERE rm.event_id = ? AND rm.participant_type='adult' AND rm.adult_id IS NOT NULL
    ");
    $st->execute([$ans, (int)$eventId]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
      $ln = trim((string)($r['ln'] ?? '')); $fn = trim((string)($r['fn'] ?? ''));
      $rows[] = ['id' => (int)$r['adult_id'], 'name' => trim($ln . ', ' . $fn, ' ,')];
    }
    usort($rows, function($a,$b){ return strcasecmp((string)$a['name'], (string)$b['name']); });
    return $rows;
  }

  /**
   * For a specific RSVP, return display names per type:
   * - adults: ['First Last', ...] (optionally exclude one adult id)
   * - youth:  ['First Last', ...]
   */
  public static function getRsvpMemberDisplayNames(int $rsvpId, ?int $excludeAdultId = null): array {
    $rsvpId = (int)$rsvpId;
    if ($rsvpId <= 0) return ['adults' => [], 'youth' => []];

    $st = self::pdo()->prepare("
      SELECT rm.participant_type, rm.youth_id, rm.adult_id,
             y.first_name AS yfn, y.last_name AS yln,
             u.first_name AS afn, u.last_name AS aln
      FROM rsvp_members rm
      LEFT JOIN youth y ON y.id = rm.youth_id
      LEFT JOIN users u ON u.id = rm.adult_id
      WHERE rm.rsvp_id = ?
      ORDER BY rm.id
    ");
    $st->execute([$rsvpId]);
    $adults = [];
    $youths = [];
    $exclude = $excludeAdultId ? (int)$excludeAdultId : null;
    foreach ($st->fetchAll() as $row) {
      if (($row['participant_type'] ?? '') === 'youth' && !empty($row['youth_id'])) {
        $name = trim((string)($row['yfn'] ?? '') . ' ' . (string)($row['yln'] ?? ''));
        if ($name !== '') $youths[] = $name;
      } elseif (($row['participant_type'] ?? '') === 'adult' && !empty($row['adult_id'])) {
        $aid = (int)$row['adult_id'];
        if ($exclude !== null && $aid === $exclude) continue;
        $name = trim((string)($row['afn'] ?? '') . ' ' . (string)($row['aln'] ?? ''));
        if ($name !== '') $adults[] = $name;
      }
    }
    return ['adults' => $adults, 'youth' => $youths];
  }

  /**
   * List event ids where the given adult has a YES RSVP (creator or included as adult member).
   */
  public static function listEventIdsWithYesRsvpForUser(int $adultUserId): array {
    $adultUserId = (int)$adultUserId;
    if ($adultUserId <= 0) return [];
    $st = self::pdo()->prepare("
      SELECT DISTINCT e_id FROM (
        SELECT r1.event_id AS e_id
        FROM rsvps r1
        WHERE r1.answer = 'yes' AND r1.created_by_user_id = ?
        UNION
        SELECT r2.event_id AS e_id
        FROM rsvps r2
        JOIN rsvp_members rm ON rm.rsvp_id = r2.id AND rm.event_id = r2.event_id
        WHERE r2.answer = 'yes' AND rm.participant_type='adult' AND rm.adult_id = ?
      ) t
      ORDER BY e_id
    ");
    $st->execute([$adultUserId, $adultUserId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
      $eid = (int)($r['e_id'] ?? 0);
      if ($eid > 0) $out[] = $eid;
    }
    return $out;
  }

  /**
   * List youth IDs who RSVP'd with a specific answer for an event.
   * Returns array of youth IDs.
   */
  public static function listYouthIdsByAnswer(int $eventId, string $answer): array {
    $ans = strtolower(trim($answer));
    if (!in_array($ans, ['yes','maybe','no'], true)) return [];
    $st = self::pdo()->prepare("
      SELECT DISTINCT rm.youth_id
      FROM rsvp_members rm
      JOIN rsvps r ON r.id = rm.rsvp_id AND r.answer = ?
      WHERE rm.event_id = ? AND rm.participant_type = 'youth' AND rm.youth_id IS NOT NULL
    ");
    $st->execute([$ans, (int)$eventId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
      $yid = (int)($r['youth_id'] ?? 0);
      if ($yid > 0) $out[] = $yid;
    }
    return $out;
  }

  // =========================
  // Writes
  // =========================

  /**
   * Upsert the "family" RSVP for an event. Creates or updates the RSVP group and replaces members.
   * Enforces youth capacity if events.max_cub_scouts is set (> 0).
   *
   * @return int rsvp_id
   * @throws InvalidArgumentException on invalid inputs
   * @throws RuntimeException on business rule violation (e.g., capacity) or DB error
   */
  public static function setFamilyRSVP(?\UserContext $ctx, int $creatorAdultId, int $eventId, string $answer, array $adultIds, array $youthIds, ?string $comments, int $nGuests, ?int $enteredBy = null): int {
    $eventId = (int)$eventId;
    if ($eventId <= 0) throw new \InvalidArgumentException('Invalid event.');
    $ans = strtolower(trim((string)$answer));
    if (!in_array($ans, ['yes','maybe','no'], true)) {
      throw new \InvalidArgumentException('Invalid RSVP answer.');
    }
    $nGuests = (int)$nGuests;
    if ($nGuests < 0) $nGuests = 0;

    // Normalize IDs
    $adultIds = array_values(array_unique(array_filter(array_map(static function($v) {
      $n = (int)$v; return $n > 0 ? $n : null;
    }, (array)$adultIds))));
    $youthIds = array_values(array_unique(array_filter(array_map(static function($v) {
      $n = (int)$v; return $n > 0 ? $n : null;
    }, (array)$youthIds))));

    // Fetch event cap (if any)
    $stEv = self::pdo()->prepare("SELECT max_cub_scouts FROM events WHERE id=? LIMIT 1");
    $stEv->execute([$eventId]);
    $ev = $stEv->fetch();
    if (!$ev) throw new \RuntimeException('Event not found.');
    $cap = isset($ev['max_cub_scouts']) && $ev['max_cub_scouts'] !== null && $ev['max_cub_scouts'] !== '' ? (int)$ev['max_cub_scouts'] : null;

    $db = self::pdo();
    try {
      $db->beginTransaction();

      // Determine current RSVP group for this user using family-aware lookup
      // This ensures we find RSVPs created by any family member (co-parents who share children)
      $myRsvp = self::getRSVPForFamilyByAdultID($eventId, (int)$creatorAdultId);

      $rsvpId = $myRsvp ? (int)$myRsvp['id'] : 0;

      // Capacity check (event-level youth cap): allow replacing within same RSVP without inflating totals
      if ($cap !== null && $cap > 0) {
        $currentYouth = self::countYouthForEvent($eventId);
        $myCurrentYouth = $rsvpId > 0 ? self::countYouthForRsvp($rsvpId) : 0;
        $newTotalYouth = $currentYouth - $myCurrentYouth + count($youthIds);
        if ($newTotalYouth > $cap) {
          throw new \RuntimeException('This event has reached its maximum number of Cub Scouts.');
        }
      }

      // Upsert RSVP
      if ($rsvpId > 0) {
        $up = $db->prepare("UPDATE rsvps SET comments=?, n_guests=?, answer=?, entered_by=? WHERE id=?");
        $up->execute([$comments !== null && trim($comments) !== '' ? $comments : null, $nGuests, $ans, $enteredBy, $rsvpId]);
      } else {
        $ins = $db->prepare("INSERT INTO rsvps (event_id, created_by_user_id, entered_by, comments, n_guests, answer) VALUES (?,?,?,?,?,?)");
        $ins->execute([$eventId, (int)$creatorAdultId, $enteredBy, $comments !== null && trim($comments) !== '' ? $comments : null, $nGuests, $ans]);
        $rsvpId = (int)$db->lastInsertId();
      }

      // Replace members
      $db->prepare("DELETE FROM rsvp_members WHERE rsvp_id=?")->execute([$rsvpId]);

      if (!empty($adultIds)) {
        $insA = $db->prepare("INSERT INTO rsvp_members (rsvp_id, event_id, participant_type, youth_id, adult_id) VALUES (?,?,?,?,?)");
        foreach ($adultIds as $aid) {
          $insA->execute([$rsvpId, $eventId, 'adult', null, (int)$aid]);
        }
      }
      if (!empty($youthIds)) {
        $insY = $db->prepare("INSERT INTO rsvp_members (rsvp_id, event_id, participant_type, youth_id, adult_id) VALUES (?,?,?,?,?)");
        foreach ($youthIds as $yid) {
          $insY->execute([$rsvpId, $eventId, 'youth', (int)$yid, null]);
        }
      }

      $db->commit();

      // Activity log
      self::log('rsvp.set_family', $eventId, [
        'rsvp_id' => (int)$rsvpId,
        'answer' => (string)$ans,
        'n_guests' => (int)$nGuests,
        'adult_count' => count($adultIds),
        'youth_count' => count($youthIds),
      ]);

      return $rsvpId;
    } catch (\Throwable $e) {
      if ($db->inTransaction()) $db->rollBack();
      // Re-throw as RuntimeException for callers
      if ($e instanceof \RuntimeException) throw $e;
      throw new \RuntimeException($e->getMessage(), (int)$e->getCode(), $e);
    }
  }
}
