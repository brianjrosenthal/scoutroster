<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Search.php';
require_once __DIR__ . '/GradeCalculator.php';
require_once __DIR__ . '/UserManagement.php';

class MailingListManagement {
  private static function pdo(): PDO {
    return pdo();
  }

  /**
   * Build filters array:
   * - q: ?string
   * - class_of: ?int
   * - registered: 'all' | 'all_inactive' | 'yes' | 'no' | 'registered_plus_leads'
   */
  public static function normalizeFilters(array $filters): array {
    $out = [
      'q' => null,
      'class_of' => null,
      'registered' => 'registered_plus_leads',  // Changed default
    ];
    if (isset($filters['q'])) {
      $q = trim((string)$filters['q']);
      $out['q'] = ($q === '' ? null : $q);
    }
    if (array_key_exists('class_of', $filters)) {
      $co = $filters['class_of'];
      $out['class_of'] = ($co === null ? null : (int)$co);
    }
    if (!empty($filters['grade_label']) && $out['class_of'] === null) {
      $out['class_of'] = \UserManagement::computeClassOfFromGradeLabel((string)$filters['grade_label']);
    }
    if (isset($filters['registered'])) {
      $r = strtolower(trim((string)$filters['registered']));
      if (in_array($r, ['yes', 'no', 'all_inactive', 'registered_plus_leads', 'all'], true)) {
        $out['registered'] = $r;
      } else {
        $out['registered'] = 'registered_plus_leads';  // Changed default
      }
    }
    return $out;
  }

  /**
   * Get IDs of youth who are considered "registered" using the same logic as youth.php
   */
  private static function getRegisteredYouthIds(array $filters): array {
    $f = self::normalizeFilters($filters);
    $params = [];
    
    $sql = "SELECT DISTINCT y.id FROM youth y WHERE 1=1";
    
    // Apply search filter if provided
    if (!empty($f['q'])) {
      $tokens = \Search::tokenize($f['q']);
      $sql .= \Search::buildAndLikeClause(
        ['y.first_name','y.last_name','y.preferred_name','y.school'],
        $tokens,
        $params
      );
    }
    
    // Apply grade filter if provided
    if ($f['class_of'] !== null) {
      $sql .= " AND y.class_of = ?";
      $params[] = (int)$f['class_of'];
    }
    
    // Apply the exact same registration logic as youth.php
    $sql .= " AND y.left_troop = 0";
    $sql .= " AND ("
          . " (y.bsa_registration_number IS NOT NULL AND y.bsa_registration_number <> '')"
          . " OR (y.date_paid_until IS NOT NULL AND y.date_paid_until >= CURDATE())"
          . " OR EXISTS (SELECT 1 FROM pending_registrations pr WHERE pr.youth_id = y.id AND pr.status <> 'deleted')"
          . " OR EXISTS (SELECT 1 FROM payment_notifications_from_users pn WHERE pn.youth_id = y.id AND pn.status <> 'deleted')"
          . " OR (y.sibling = 1 AND EXISTS ("
          . "   SELECT 1 FROM youth y2 "
          . "   JOIN parent_relationships pr1 ON pr1.youth_id = y.id "
          . "   JOIN parent_relationships pr2 ON pr2.adult_id = pr1.adult_id "
          . "   WHERE pr2.youth_id = y2.id "
          . "   AND y2.id != y.id "
          . "   AND y2.left_troop = 0 "
          . "   AND ((y2.bsa_registration_number IS NOT NULL AND y2.bsa_registration_number <> '') "
          . "        OR (y2.date_paid_until IS NOT NULL AND y2.date_paid_until >= CURDATE())"
          . "        OR EXISTS (SELECT 1 FROM pending_registrations pr3 WHERE pr3.youth_id = y2.id AND pr3.status <> 'deleted')"
          . "        OR EXISTS (SELECT 1 FROM payment_notifications_from_users pn2 WHERE pn2.youth_id = y2.id AND pn2.status <> 'deleted'))"
          . " ))"
          . ")";
    
    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    return array_column($st->fetchAll(), 'id');
  }

  /**
   * Get adults who are parents of registered youth
   */
  private static function getParentsOfRegisteredYouth(array $filters): array {
    $registeredYouthIds = self::getRegisteredYouthIds($filters);
    if (empty($registeredYouthIds)) {
      return [];
    }
    
    $f = self::normalizeFilters($filters);
    $params = [];
    
    $placeholders = implode(',', array_fill(0, count($registeredYouthIds), '?'));
    $sql = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email 
            FROM users u
            JOIN parent_relationships pr ON pr.adult_id = u.id
            WHERE pr.youth_id IN ($placeholders)";
    $params = array_merge($params, $registeredYouthIds);
    
    // Apply adult search filter if provided
    if (!empty($f['q'])) {
      $tokens = \Search::tokenize($f['q']);
      $sql .= \Search::buildAndLikeClause(
        ['u.first_name','u.last_name','u.email'],
        $tokens,
        $params
      );
    }
    
    $sql .= " ORDER BY u.last_name, u.first_name";
    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll() ?: [];
  }

  /**
   * Get adults who are parents of youth marked with include_in_most_emails = 1
   */
  private static function getParentsOfActiveLeads(array $filters): array {
    $f = self::normalizeFilters($filters);
    $params = [];
    
    $sql = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email 
            FROM users u
            JOIN parent_relationships pr ON pr.adult_id = u.id
            JOIN youth y ON y.id = pr.youth_id
            WHERE y.include_in_most_emails = 1
              AND y.left_troop = 0";
    
    // Apply search filter if provided
    if (!empty($f['q'])) {
      $tokens = \Search::tokenize($f['q']);
      $sql .= \Search::buildAndLikeClause(
        ['u.first_name','u.last_name','u.email'],
        $tokens,
        $params
      );
    }
    
    // Apply grade filter if provided
    if ($f['class_of'] !== null) {
      $sql .= " AND y.class_of = ?";
      $params[] = (int)$f['class_of'];
    }
    
    $sql .= " ORDER BY u.last_name, u.first_name";
    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll() ?: [];
  }

  /**
   * Filter out unsubscribed adults from a list of adults.
   * Returns only adults who are not unsubscribed from emails.
   */
  private static function filterUnsubscribedAdults(array $adults): array {
    if (empty($adults)) {
      return [];
    }
    
    // Get user IDs
    $userIds = array_column($adults, 'id');
    if (empty($userIds)) {
      return [];
    }
    
    // Get unsubscribe status for all users
    $unsubscribeStatus = \UserManagement::getUnsubscribeStatusForUsers($userIds);
    
    // Filter out unsubscribed users
    return array_filter($adults, function($adult) use ($unsubscribeStatus) {
      $userId = (int)$adult['id'];
      return empty($unsubscribeStatus[$userId]);
    });
  }

  /**
   * Return distinct adults [id, first_name, last_name, email] obeying filters.
   */
  public static function searchAdults(array $filters): array {
    $f = self::normalizeFilters($filters);

    if ($f['registered'] === 'yes') {
      // Return only parents of registered youth
      $adults = self::getParentsOfRegisteredYouth($filters);
      return self::filterUnsubscribedAdults($adults);
    }
    
    if ($f['registered'] === 'registered_plus_leads') {
      // Return parents of registered youth PLUS parents of active leads
      $registeredParents = self::getParentsOfRegisteredYouth($filters);
      $activeLeadParents = self::getParentsOfActiveLeads($filters);
      
      // Merge and deduplicate by user ID
      $adultsById = [];
      foreach ($registeredParents as $adult) {
        $adultsById[(int)$adult['id']] = $adult;
      }
      foreach ($activeLeadParents as $adult) {
        $adultsById[(int)$adult['id']] = $adult;
      }
      
      $adults = array_values($adultsById);
      return self::filterUnsubscribedAdults($adults);
    }
    
    if ($f['registered'] === 'no') {
      // Get all adults first, then subtract registered parents
      $allAdults = self::getAllAdultsWithFilters($filters);
      $registeredParents = self::getParentsOfRegisteredYouth($filters);
      
      // Create lookup of registered parent IDs
      $registeredIds = array_column($registeredParents, 'id');
      $registeredLookup = array_flip($registeredIds);
      
      // Filter out registered parents and unsubscribed adults
      $filteredAdults = array_filter($allAdults, function($adult) use ($registeredLookup) {
        return !isset($registeredLookup[$adult['id']]);
      });
      return self::filterUnsubscribedAdults($filteredAdults);
    }
    
    // For 'all' and 'all_inactive'
    $adults = self::getAllAdultsWithFilters($filters);
    return self::filterUnsubscribedAdults($adults);
  }
  
  /**
   * Get all adults with basic filters (search, grade) but no registration filtering
   */
  private static function getAllAdultsWithFilters(array $filters): array {
    $f = self::normalizeFilters($filters);

    $params = [];
    $sqlBase = "
      FROM users u
      LEFT JOIN parent_relationships pr ON pr.adult_id = u.id
      LEFT JOIN youth y ON y.id = pr.youth_id AND y.left_troop = 0
      WHERE 1=1
    ";

    if (!empty($f['q'])) {
      $tokens = \Search::tokenize($f['q']);
      $sqlBase .= \Search::buildAndLikeClause(
        ['u.first_name','u.last_name','u.email','y.first_name','y.last_name'],
        $tokens,
        $params
      );
    }

    if ($f['class_of'] !== null) {
      $sqlBase .= " AND y.class_of = ?";
      $params[] = (int)$f['class_of'];
    }

    // Handle "left_troop" filtering logic
    if ($f['registered'] === 'all') {
      // For 'all', exclude parents if ALL of their children have left the troop
      $sqlBase .= " AND (pr.adult_id IS NULL OR EXISTS (
        SELECT 1 FROM parent_relationships pr2 
        JOIN youth y2 ON y2.id = pr2.youth_id 
        WHERE pr2.adult_id = u.id AND y2.left_troop = 0
      ))";
    }
    // For 'all_inactive', we include everyone but still exclude youth who have left

    $sql = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email " . $sqlBase . " ORDER BY u.last_name, u.first_name";
    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll() ?: [];
  }

  /**
   * Return map adult_id => [grade labels], filtered consistently with searchAdults and restricted to provided adult ids.
   */
  public static function gradesByAdult(array $adultIds, array $filters): array {
    $adultIds = array_values(array_unique(array_filter(array_map(static function ($v) {
      $n = (int)$v; return $n > 0 ? $n : null;
    }, $adultIds))));
    if (empty($adultIds)) return [];

    $f = self::normalizeFilters($filters);

    // For registered filtering, we need to use the same logic as searchAdults
    if ($f['registered'] === 'yes') {
      // Only get grades for youth who are registered according to our new definition
      $registeredYouthIds = self::getRegisteredYouthIds($filters);
      if (empty($registeredYouthIds)) return [];
      
      $params = [];
      $youthPlaceholders = implode(',', array_fill(0, count($registeredYouthIds), '?'));
      $adultPlaceholders = implode(',', array_fill(0, count($adultIds), '?'));
      
      $sql = "SELECT u.id AS adult_id, y.class_of 
              FROM users u
              JOIN parent_relationships pr ON pr.adult_id = u.id
              JOIN youth y ON y.id = pr.youth_id
              WHERE y.id IN ($youthPlaceholders) AND u.id IN ($adultPlaceholders)";
      $params = array_merge($registeredYouthIds, $adultIds);
      
      // Apply additional filters
      if (!empty($f['q'])) {
        $tokens = \Search::tokenize($f['q']);
        $sql .= \Search::buildAndLikeClause(
          ['u.first_name','u.last_name','u.email','y.first_name','y.last_name'],
          $tokens,
          $params
        );
      }
      if ($f['class_of'] !== null) {
        $sql .= " AND y.class_of = ?";
        $params[] = (int)$f['class_of'];
      }
      
    } else {
      // For 'no', 'all', 'all_inactive' - use the original logic but consistent with new approach
      $params = [];
      $sqlBase = "
        FROM users u
        LEFT JOIN parent_relationships pr ON pr.adult_id = u.id
        LEFT JOIN youth y ON y.id = pr.youth_id AND y.left_troop = 0
        WHERE 1=1
      ";

      if (!empty($f['q'])) {
        $tokens = \Search::tokenize($f['q']);
        $sqlBase .= \Search::buildAndLikeClause(
          ['u.first_name','u.last_name','u.email','y.first_name','y.last_name'],
          $tokens,
          $params
        );
      }
      if ($f['class_of'] !== null) {
        $sqlBase .= " AND y.class_of = ?";
        $params[] = (int)$f['class_of'];
      }
      
      // For registered 'no', we would need to exclude registered youth, but this is complex
      // For now, keep simpler logic for grade display
      if ($f['registered'] === 'no') {
        // This is complex to implement properly, so for grade display purposes
        // we'll just show all grades for the filtered adults
      }

      $ph = implode(',', array_fill(0, count($adultIds), '?'));
      $sql = "SELECT u.id AS adult_id, y.class_of " . $sqlBase . " AND y.id IS NOT NULL AND u.id IN ($ph)";
      $params = array_merge($params, $adultIds);
    }

    $st = self::pdo()->prepare($sql);
    $st->execute($params);

    $out = [];
    while ($r = $st->fetch()) {
      $aid = (int)$r['adult_id'];
      $classOf = (int)($r['class_of'] ?? 0);
      $gi = \GradeCalculator::gradeForClassOf($classOf);
      $label = \GradeCalculator::gradeLabel((int)$gi);
      if (!isset($out[$aid])) $out[$aid] = [];
      if (!in_array($label, $out[$aid], true)) $out[$aid][] = $label;
    }

    // Sort K(0) .. 5
    foreach ($out as $aid => $labels) {
      usort($labels, static function ($a, $b) {
        $toNum = static function ($lbl) { return ($lbl === 'K') ? 0 : (int)$lbl; };
        return $toNum($a) <=> $toNum($b);
      });
      $out[$aid] = $labels;
    }

    return $out;
  }

  /**
   * Merge adults and recommendations into a unified contact list:
   * Each item: ['name' => string, 'email' => string, 'grades' => string[], 'source' => 'adult'|'rec']
   * Dedup by email (prefer adults).
   */
  public static function mergedContacts(array $filters): array {
    $f = self::normalizeFilters($filters);

    // Adults
    $adults = self::searchAdults($f);
    $adultIds = array_column($adults, 'id');
    $gradesMap = self::gradesByAdult($adultIds, $f);

    $contacts = []; // email-lc => item
    foreach ($adults as $a) {
      $emailRaw = (string)($a['email'] ?? '');
      $emailKey = strtolower(trim($emailRaw));
      if ($emailKey === '' || !filter_var($emailKey, FILTER_VALIDATE_EMAIL)) continue;
      $name = trim((string)($a['first_name'] ?? '') . ' ' . (string)($a['last_name'] ?? ''));
      $aid = (int)($a['id'] ?? 0);
      if (!isset($contacts[$emailKey])) {
        $contacts[$emailKey] = [
          'name' => $name,
          'email' => $emailRaw,
          'grades' => $gradesMap[$aid] ?? [],
          'source' => 'adult',
        ];
      }
    }

    // For registered filtering, we need special handling of recommendations
    $excludeRecommendationEmails = [];
    if ($f['registered'] === 'yes') {
      // For "Yes", exclude recommendations that have the same email as any adult (to avoid duplicates)
      foreach ($adults as $a) {
        $emailKey = strtolower(trim((string)($a['email'] ?? '')));
        if ($emailKey !== '') {
          $excludeRecommendationEmails[$emailKey] = true;
        }
      }
    } elseif ($f['registered'] === 'no') {
      // For "No", exclude recommendations that have the same email as registered parents
      $registeredParents = self::getParentsOfRegisteredYouth($filters);
      foreach ($registeredParents as $a) {
        $emailKey = strtolower(trim((string)($a['email'] ?? '')));
        if ($emailKey !== '') {
          $excludeRecommendationEmails[$emailKey] = true;
        }
      }
    } else {
      // For "All", exclude recommendations that match any existing adult
      foreach ($adults as $a) {
        $emailKey = strtolower(trim((string)($a['email'] ?? '')));
        if ($emailKey !== '') {
          $excludeRecommendationEmails[$emailKey] = true;
        }
      }
    }

    // Recommendations (apply 'q' filter and grade if provided)
    $recParams = [];
    $recSql = "SELECT r.parent_name, r.child_name, r.email, r.grade FROM recommendations r WHERE r.email IS NOT NULL AND r.email <> ''";
    if (!empty($f['q'])) {
      $tokens = \Search::tokenize($f['q']);
      $recSql .= \Search::buildAndLikeClause(['r.parent_name','r.child_name','r.email'], $tokens, $recParams);
    }
    // If a grade filter is active (via class_of), map it to a grade label (K..5) and filter recommendations
    if ($f['class_of'] !== null) {
      $gInt = \GradeCalculator::gradeForClassOf((int)$f['class_of']);
      $gLbl = \GradeCalculator::gradeLabel((int)$gInt);
      $recSql .= " AND r.grade = ?";
      $recParams[] = $gLbl;
    }
    $stRec = self::pdo()->prepare($recSql);
    $stRec->execute($recParams);
    $recs = $stRec->fetchAll() ?: [];

    foreach ($recs as $r) {
      $emailRaw = (string)($r['email'] ?? '');
      $emailKey = strtolower(trim($emailRaw));
      if ($emailKey === '' || !filter_var($emailKey, FILTER_VALIDATE_EMAIL)) continue;
      if (isset($excludeRecommendationEmails[$emailKey])) continue; // exclude based on registration logic
      if (isset($contacts[$emailKey])) continue; // prefer adult (shouldn't happen with proper exclusion)
      
      $name = trim((string)($r['parent_name'] ?? ''));
      if ($name === '') {
        $nmChild = trim((string)($r['child_name'] ?? ''));
        $name = ($nmChild !== '') ? $nmChild : $emailRaw;
      }
      $gradeLabel = trim((string)($r['grade'] ?? ''));
      $contacts[$emailKey] = [
        'name' => $name,
        'email' => $emailRaw,
        'grades' => ($gradeLabel !== '' ? [$gradeLabel] : []),
        'source' => 'rec',
      ];
    }

    // Sort by name (ci), then email
    $list = array_values($contacts);
    usort($list, static function ($a, $b) {
      $na = strtolower((string)($a['name'] ?? ''));
      $nb = strtolower((string)($b['name'] ?? ''));
      if ($na === $nb) {
        return strcasecmp((string)($a['email'] ?? ''), (string)($b['email'] ?? ''));
      }
      return $na <=> $nb;
    });

    return $list;
  }

  /**
   * Stream a CSV of name,email to output and exit.
   */
  public static function streamCsv(array $filters): void {
    $contacts = self::mergedContacts($filters);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mailing_list.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'email']);
    foreach ($contacts as $c) {
      $name = (string)($c['name'] ?? '');
      $email = (string)($c['email'] ?? '');
      if ($email !== '') {
        fputcsv($out, [$name, $email]);
      }
    }
    fclose($out);
    exit;
  }
}
