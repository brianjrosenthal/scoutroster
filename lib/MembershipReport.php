<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/GradeCalculator.php';
require_once __DIR__ . '/../settings.php';

class MembershipReport {
  private static function pdo(): PDO {
    return pdo();
  }

  /**
   * Calculate the next June 1st cutoff date based on current date.
   * If we're before June 1st this year, use June 1st this year.
   * If we're on or after June 1st this year, use June 1st next year.
   * 
   * @return string Date in 'Y-m-d' format
   */
  private static function getNextJune1st(): string {
    $now = new DateTime('now', new DateTimeZone(Settings::timezoneId()));
    $currentYear = (int)$now->format('Y');
    $juneFirst = new DateTime($currentYear . '-06-01', new DateTimeZone(Settings::timezoneId()));
    
    // If current date is on or after June 1st this year, use next year
    if ($now >= $juneFirst) {
      $juneFirst->modify('+1 year');
    }
    
    return $juneFirst->format('Y-m-d');
  }

  /**
   * Calculate the last June 1st date (start of current membership year).
   * 
   * @return string Date in 'Y-m-d' format
   */
  private static function getLastJune1st(): string {
    $now = new DateTime('now', new DateTimeZone(Settings::timezoneId()));
    $currentYear = (int)$now->format('Y');
    $juneFirst = new DateTime($currentYear . '-06-01', new DateTimeZone(Settings::timezoneId()));
    
    // If current date is before June 1st this year, use last year
    if ($now < $juneFirst) {
      $juneFirst->modify('-1 year');
    }
    
    return $juneFirst->format('Y-m-d');
  }

  /**
   * Get all youth who qualify as members based on the membership criteria.
   * 
   * Membership criteria (any of these):
   * 1. Has BSA Registration ID and expiration date after next June 1st
   * 2. Has non-deleted payment notification created since last June 1st
   * 3. Has non-deleted pending registration created since last June 1st
   * 4. Has BSA Registration ID and paid_until date after next June 1st
   * 
   * @return array Array of youth with membership details
   */
  public static function getMembers(): array {
    $nextJune1st = self::getNextJune1st();
    $lastJune1st = self::getLastJune1st();

    $sql = "
      SELECT DISTINCT
        y.id,
        y.first_name,
        y.last_name,
        y.preferred_name,
        y.class_of,
        y.bsa_registration_number,
        y.bsa_registration_expires_date,
        y.date_paid_until,
        y.left_troop,
        -- Check if they have recent payment notification
        (SELECT COUNT(*) FROM payment_notifications_from_users pn 
         WHERE pn.youth_id = y.id 
           AND pn.status != 'deleted' 
           AND pn.created_at >= :last_june_1st_pn) as has_payment_notification,
        -- Check if they have recent pending registration
        (SELECT COUNT(*) FROM pending_registrations pr 
         WHERE pr.youth_id = y.id 
           AND pr.status != 'deleted' 
           AND pr.created_at >= :last_june_1st_pr) as has_pending_registration
      FROM youth y
      WHERE y.left_troop = 0
        AND (
          -- Criterion 1: BSA reg number and expiration after next June 1st
          (y.bsa_registration_number IS NOT NULL 
           AND y.bsa_registration_number != '' 
           AND y.bsa_registration_expires_date > :next_june_1st_1)
          OR
          -- Criterion 2: Payment notification since last June 1st
          EXISTS (
            SELECT 1 FROM payment_notifications_from_users pn2
            WHERE pn2.youth_id = y.id 
              AND pn2.status != 'deleted'
              AND pn2.created_at >= :last_june_1st_2
          )
          OR
          -- Criterion 3: Pending registration since last June 1st
          EXISTS (
            SELECT 1 FROM pending_registrations pr2
            WHERE pr2.youth_id = y.id 
              AND pr2.status != 'deleted'
              AND pr2.created_at >= :last_june_1st_3
          )
          OR
          -- Criterion 4: BSA reg number and paid_until after next June 1st
          (y.bsa_registration_number IS NOT NULL 
           AND y.bsa_registration_number != '' 
           AND y.date_paid_until > :next_june_1st_2)
        )
      ORDER BY y.class_of DESC, y.last_name, y.first_name
    ";

    $stmt = self::pdo()->prepare($sql);
    $stmt->execute([
      ':next_june_1st_1' => $nextJune1st,
      ':next_june_1st_2' => $nextJune1st,
      ':last_june_1st_pn' => $lastJune1st,
      ':last_june_1st_pr' => $lastJune1st,
      ':last_june_1st_2' => $lastJune1st,
      ':last_june_1st_3' => $lastJune1st,
    ]);

    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate grade and status for each member
    foreach ($members as &$member) {
      $member['grade'] = GradeCalculator::gradeForClassOf((int)$member['class_of']);
      $member['grade_label'] = GradeCalculator::gradeLabel($member['grade']);
      $member['status'] = self::getMemberStatus($member, $nextJune1st);
      $member['display_name'] = $member['preferred_name'] ?: $member['first_name'];
    }

    return $members;
  }

  /**
   * Determine the status of a member: "active" or "processing"
   * 
   * Active: Both paid_until AND bsa_registration_expires are after next June 1st
   * Processing: Either expires before next June 1st BUT has payment notification or pending registration
   * 
   * @param array $member Member data
   * @param string $nextJune1st Next June 1st date in Y-m-d format
   * @return string "active" or "processing"
   */
  private static function getMemberStatus(array $member, string $nextJune1st): string {
    $hasPaidUntilValid = !empty($member['date_paid_until']) && $member['date_paid_until'] > $nextJune1st;
    $hasBsaRegValid = !empty($member['bsa_registration_expires_date']) && $member['bsa_registration_expires_date'] > $nextJune1st;
    
    // Active if both are valid
    if ($hasPaidUntilValid && $hasBsaRegValid) {
      return 'active';
    }
    
    // Otherwise it's processing (they qualified as member but not fully active yet)
    return 'processing';
  }

  /**
   * Get membership counts overall and by grade.
   * 
   * @return array Array with 'total' and 'by_grade' keys
   */
  public static function getMembershipCounts(): array {
    $members = self::getMembers();
    $counts = [
      'total' => count($members),
      'by_grade' => []
    ];

    foreach ($members as $member) {
      $gradeLabel = $member['grade_label'];
      if (!isset($counts['by_grade'][$gradeLabel])) {
        $counts['by_grade'][$gradeLabel] = 0;
      }
      $counts['by_grade'][$gradeLabel]++;
    }

    return $counts;
  }

  /**
   * Get members organized by grade.
   * 
   * @return array Associative array keyed by grade label
   */
  public static function getMembersByGrade(): array {
    $members = self::getMembers();
    $byGrade = [];

    foreach ($members as $member) {
      $gradeLabel = $member['grade_label'];
      if (!isset($byGrade[$gradeLabel])) {
        $byGrade[$gradeLabel] = [];
      }
      $byGrade[$gradeLabel][] = $member;
    }

    return $byGrade;
  }

  /**
   * Export membership report to CSV format.
   * 
   * @return string CSV content
   */
  public static function exportToCSV(): string {
    $members = self::getMembers();
    
    $output = fopen('php://temp', 'r+');
    
    // Write header
    fputcsv($output, [
      'Grade',
      'Name',
      'BSA Registration ID',
      'BSA Registration Expires',
      'Paid Until',
      'Status'
    ]);
    
    // Write data rows
    foreach ($members as $member) {
      $name = trim($member['display_name'] . ' ' . $member['last_name']);
      $bsaId = !empty($member['bsa_registration_number']) ? $member['bsa_registration_number'] : 'pending';
      
      fputcsv($output, [
        $member['grade_label'],
        $name,
        $bsaId,
        $member['bsa_registration_expires_date'] ?? '',
        $member['date_paid_until'] ?? '',
        ucfirst($member['status'])
      ]);
    }
    
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    
    return $csv;
  }
}
