<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/GradeCalculator.php';
require_once __DIR__ . '/Search.php';
require_once __DIR__ . '/UserManagement.php';
require_once __DIR__ . '/ActivityLog.php';

class YouthManagement {
  private static function pdo(): PDO {
    return pdo();
  }

  private static function str(string $v): string {
    return trim($v);
  }

  // Normalize empty string to NULL, leave non-strings as-is
  private static function nn($v) {
    if (is_string($v)) {
      $v = trim($v);
      return ($v === '' ? null : $v);
    }
    return $v;
  }

  private static function boolInt($v): int {
    return !empty($v) ? 1 : 0;
  }

  // Activity logging helper – do not perform extra queries, just log what's provided.
  private static function log(string $action, ?int $youthId, array $details = []): void {
    try {
      $ctx = \UserContext::getLoggedInUserContext();
      $meta = $details;
      if ($youthId !== null && !array_key_exists('youth_id', $meta)) {
        $meta['youth_id'] = (int)$youthId;
      }
      \ActivityLog::log($ctx, (string)$action, (array)$meta);
    } catch (\Throwable $e) {
      // best effort only
    }
  }

  private static function sanitizeGender(?string $gender): ?string {
    if ($gender === null) return null;
    $gender = trim($gender);
    if ($gender === '') return null;
    $allowed = ['male','female','non-binary','prefer not to say'];
    return in_array($gender, $allowed, true) ? $gender : null;
  }

  // Ensure date is valid Y-m-d when provided; return null if blank
  private static function validateDateYmd($date): ?string {
    $date = self::nn($date);
    if ($date === null) return null;
    if (!is_string($date)) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
      throw new InvalidArgumentException('Date must be in YYYY-MM-DD format.');
    }
    return $date;
  }

  private static function assertLogin(?UserContext $ctx): void {
    if (!$ctx) { throw new RuntimeException('Login required'); }
  }

  private static function assertAdmin(?UserContext $ctx): void {
    self::assertLogin($ctx);
    if (!$ctx->admin) { throw new RuntimeException('Admins only'); }
  }

  // Allow admin OR a linked parent of the youth
  private static function assertAdminOrParent(?UserContext $ctx, int $youthId): void {
    self::assertLogin($ctx);
    if ($ctx->admin) return;
    $st = self::pdo()->prepare('SELECT 1 FROM parent_relationships WHERE youth_id=? AND adult_id=? LIMIT 1');
    $st->execute([$youthId, (int)$ctx->id]);
    if (!$st->fetchColumn()) {
      throw new RuntimeException('Forbidden - assertAdminOrParent');
    }
  }

  // Parse grade from either 'grade' (int) or 'grade_label' (K,0..5)
  private static function parseGradeFromData(array $data): int {
    if (array_key_exists('grade', $data) && $data['grade'] !== null && $data['grade'] !== '') {
      $g = (int)$data['grade'];
      if ($g < 0 || $g > 5) throw new InvalidArgumentException('Invalid grade');
      return $g;
    }
    $label = $data['grade_label'] ?? null;
    if ($label === null) {
      throw new InvalidArgumentException('Grade is required.');
    }
    $g = \GradeCalculator::parseGradeLabel((string)$label);
    if ($g === null) throw new InvalidArgumentException('Grade is required.');
    return (int)$g;
  }

  private static function computeClassOfFromGrade(int $grade): int {
    $currentFifthClassOf = \GradeCalculator::schoolYearEndYear();
    return $currentFifthClassOf + (5 - $grade);
  }

  // Lightweight permission helper for youth photo uploads:
  // Admins OR linked parents may upload a youth's photo.
  public static function canUploadYouthPhoto(?UserContext $ctx, int $youthId): bool {
    if (!$ctx) return false;
    if ($ctx->admin) return true;
    $st = self::pdo()->prepare('SELECT 1 FROM parent_relationships WHERE youth_id=? AND adult_id=? LIMIT 1');
    $st->execute([$youthId, (int)$ctx->id]);
    return (bool)$st->fetchColumn();
  }

  // =========================
  // Lightweight helpers
  // =========================
  public static function existsById(int $youthId): bool {
    $st = self::pdo()->prepare('SELECT 1 FROM youth WHERE id=? LIMIT 1');
    $st->execute([$youthId]);
    return (bool)$st->fetchColumn();
  }

  public static function findBasicById(int $youthId): ?array {
    $st = self::pdo()->prepare('SELECT id, first_name, last_name FROM youth WHERE id=? LIMIT 1');
    $st->execute([$youthId]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public static function listAllBasic(): array {
    $st = self::pdo()->query('SELECT id, first_name, last_name FROM youth ORDER BY last_name, first_name');
    return $st->fetchAll() ?: [];
  }

  // =========================
  // Reads / Queries
  // =========================

  // Roster search for logged-in users (admins and non-admins)
  public static function searchRoster(UserContext $ctx, ?string $q, ?int $grade, bool $includeUnregistered = false): array {
    self::assertLogin($ctx);

    $params = [];
    $sql = "SELECT y.*
            FROM youth y
            WHERE 1=1";

    if ($q !== null && trim($q) !== '') {
      $tokens = \Search::tokenize($q);
      $sql .= \Search::buildAndLikeClause(
        ['y.first_name','y.last_name','y.preferred_name','y.school'],
        $tokens,
        $params
      );
    }
    if ($grade !== null) {
      $classOfFilter = self::computeClassOfFromGrade((int)$grade);
      $sql .= " AND y.class_of = ?";
      $params[] = $classOfFilter;
    }

    if (!$includeUnregistered) {
      $sql .= " AND ("
            . " (y.bsa_registration_number IS NOT NULL AND y.bsa_registration_number <> '')"
            . " OR (y.date_paid_until IS NOT NULL AND y.date_paid_until >= CURDATE())"
            . " OR (y.sibling = 1 AND EXISTS ("
            . "   SELECT 1 FROM youth y2 "
            . "   JOIN parent_relationships pr1 ON pr1.youth_id = y.id "
            . "   JOIN parent_relationships pr2 ON pr2.adult_id = pr1.adult_id "
            . "   WHERE pr2.youth_id = y2.id "
            . "   AND y2.id != y.id "
            . "   AND ((y2.bsa_registration_number IS NOT NULL AND y2.bsa_registration_number <> '') "
            . "        OR (y2.date_paid_until IS NOT NULL AND y2.date_paid_until >= CURDATE()))"
            . " ))"
            . ")";
    }

    $sql .= " ORDER BY y.last_name, y.first_name";

    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
  }

  public static function getForEdit(UserContext $ctx, int $id): ?array {
    self::assertAdminOrParent($ctx, $id);
    $st = self::pdo()->prepare('SELECT * FROM youth WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public static function listParents(UserContext $ctx, int $youthId): array {
    self::assertAdminOrParent($ctx, $youthId);
    $pps = self::pdo()->prepare("SELECT u.id,u.first_name,u.last_name,u.email
                                 FROM parent_relationships pr
                                 JOIN users u ON u.id=pr.adult_id
                                 WHERE pr.youth_id=?
                                 ORDER BY u.last_name,u.first_name");
    $pps->execute([$youthId]);
    return $pps->fetchAll();
  }

  // =========================
  // Writes
  // =========================

  /**
   * Set or clear a youth's profile photo file id.
   * Requires that the actor is admin or a linked parent.
   */
  public static function setPhotoPublicFileId(UserContext $ctx, int $youthId, ?int $publicFileId): bool {
    // Permission
    if (!self::canUploadYouthPhoto($ctx, $youthId)) {
      throw new RuntimeException('Forbidden - setPhotoPublicField');
    }
    $st = self::pdo()->prepare('UPDATE youth SET photo_public_file_id = ? WHERE id = ?');
    $ok = $st->execute([$publicFileId, $youthId]);
    if ($ok) {
      if ($publicFileId === null) {
        self::log('youth.upload_profile_photo', $youthId, ['deleted' => true]);
      } else {
        self::log('youth.upload_profile_photo', $youthId, ['public_file_id' => (int)$publicFileId]);
      }
    }
    return $ok;
  }

  /**
   * Parent self-service: create a youth record and link to the parent.
   * - Uses 'grade_label' or 'grade' in $data to compute class_of.
   * - Sibling flag is set to 1 for this flow.
   */
  public static function createByParent(UserContext $ctx, array $data): int {
    self::assertLogin($ctx);

    $first = self::str((string)($data['first_name'] ?? ''));
    $last  = self::str((string)($data['last_name'] ?? ''));
    if ($first === '' || $last === '') {
      throw new InvalidArgumentException('First and last name are required.');
    }

    // Accept either grade or grade_label
    $grade = self::parseGradeFromData($data);
    $class_of = self::computeClassOfFromGrade($grade);

    $suffix = self::nn($data['suffix'] ?? null);
    $preferred_name = self::nn($data['preferred_name'] ?? null);
    $gender = self::sanitizeGender(self::nn($data['gender'] ?? null));
    $birthdate = self::validateDateYmd($data['birthdate'] ?? null);
    $school = self::nn($data['school'] ?? null);
    $shirt_size = self::nn($data['shirt_size'] ?? null);

    $street1 = self::nn($data['street1'] ?? null);
    $street2 = self::nn($data['street2'] ?? null);
    $city    = self::nn($data['city'] ?? null);
    $state   = self::nn($data['state'] ?? null);
    $zip     = self::nn($data['zip'] ?? null);

    $st = self::pdo()->prepare("INSERT INTO youth
      (first_name,last_name,suffix,preferred_name,gender,birthdate,school,shirt_size,
       street1,street2,city,state,zip,class_of,sibling)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
    $ok = $st->execute([
      $first, $last, $suffix, $preferred_name, $gender, $birthdate, $school, $shirt_size,
      $street1, $street2, $city, $state, $zip, $class_of
    ]);
    if (!$ok) throw new RuntimeException('Failed to create youth.');

    $newId = (int)self::pdo()->lastInsertId();

    // Link parent
    self::pdo()->prepare('INSERT IGNORE INTO parent_relationships (youth_id, adult_id) VALUES (?, ?)')
      ->execute([$newId, (int)$ctx->id]);

    self::log('youth.add', $newId, ['first_name' => $first, 'last_name' => $last]);
    return $newId;
  }

  public static function create(UserContext $ctx, array $data): int {
    self::assertAdmin($ctx);

    $first = self::str((string)($data['first_name'] ?? ''));
    $last  = self::str((string)($data['last_name'] ?? ''));
    if ($first === '' || $last === '') {
      throw new InvalidArgumentException('First and last name are required.');
    }

    $grade = self::parseGradeFromData($data);
    $class_of = self::computeClassOfFromGrade($grade);

    $preferred_name = self::nn($data['preferred_name'] ?? null);
    $gender = self::sanitizeGender(self::nn($data['gender'] ?? null));
    $birthdate = self::validateDateYmd($data['birthdate'] ?? null);
    $school = self::nn($data['school'] ?? null);
    $shirt_size = self::nn($data['shirt_size'] ?? null);
    $bsa = self::nn($data['bsa_registration_number'] ?? null);
    $street1 = self::nn($data['street1'] ?? null);
    $street2 = self::nn($data['street2'] ?? null);
    $city    = self::nn($data['city'] ?? null);
    $state   = self::nn($data['state'] ?? null);
    $zip     = self::nn($data['zip'] ?? null);
    $sibling = self::boolInt($data['sibling'] ?? 0);
    $suffix  = self::nn($data['suffix'] ?? null);

    // Enforce: grades outside K–5 (i.e., Pre-K or 6–12) require sibling=true
    if ($grade < 0 || $grade > 5) {
      if ($sibling !== 1) {
        throw new InvalidArgumentException('You can only add Pre-K or grades 6–12 if the youth is marked as a sibling.');
      }
    }

    $st = self::pdo()->prepare("INSERT INTO youth
      (first_name,last_name,suffix,preferred_name,gender,birthdate,school,shirt_size,bsa_registration_number,
       street1,street2,city,state,zip,class_of,sibling)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $ok = $st->execute([
      $first, $last, $suffix, $preferred_name, $gender, $birthdate, $school, $shirt_size, $bsa,
      $street1, $street2, $city, $state, $zip, $class_of, $sibling
    ]);
    if (!$ok) throw new RuntimeException('Failed to create youth.');
    $newId = (int)self::pdo()->lastInsertId();

    // Log youth creation (admin-created)
    self::log('youth.add', $newId, ['first_name' => $first, 'last_name' => $last]);


    // Optional post-insert updates for admin-only fields
    try {
      // Registration expires (admin may set on create)
      $regExpires = self::validateDateYmd($data['bsa_registration_expires_date'] ?? null);
      if ($regExpires !== null) {
        $up = self::pdo()->prepare('UPDATE youth SET bsa_registration_expires_date = ? WHERE id = ?');
        $up->execute([$regExpires, $newId]);
        // Log registration expiry update
        self::log('youth.update_registration_expiry', $newId, ['expires' => $regExpires]);
      }
      // Paid until (only for Cubmaster/Committee Chair/Treasurer)
      $paidUntil = self::validateDateYmd($data['date_paid_until'] ?? null);
      if ($paidUntil !== null && \UserManagement::isApprover((int)$ctx->id)) {
        $up2 = self::pdo()->prepare('UPDATE youth SET date_paid_until = ? WHERE id = ?');
        $up2->execute([$paidUntil, $newId]);
        // Log mark paid
        self::log('youth.mark_paid', $newId, ['paid_until' => $paidUntil]);
      }
    } catch (Throwable $e) {
      // Swallow optional update errors; base create succeeded
    }

    return $newId;
  }

  public static function update(UserContext $ctx, int $id, array $data): bool {
    self::assertAdminOrParent($ctx, $id);

    // Common fields parents can update
    $allowedCommon = [
      'first_name','last_name','suffix','preferred_name','gender','birthdate','school','shirt_size',
      'street1','street2','city','state','zip','sibling'
    ];
    // Admin-only fields
    $allowedAdmin = ['bsa_registration_number','grade','grade_label'];

    $isAdmin = $ctx->admin;

    // Validate base requireds if present
    if (array_key_exists('first_name', $data)) {
      $data['first_name'] = self::str((string)$data['first_name']);
      if ($data['first_name'] === '') throw new InvalidArgumentException('First name is required.');
    }
    if (array_key_exists('last_name', $data)) {
      $data['last_name'] = self::str((string)$data['last_name']);
      if ($data['last_name'] === '') throw new InvalidArgumentException('Last name is required.');
    }

    $set = [];
    $params = [];

    // Apply common fields
    foreach ($allowedCommon as $key) {
      if (!array_key_exists($key, $data)) continue;

      if ($key === 'gender') {
        $val = self::sanitizeGender(self::nn($data[$key]));
      } elseif ($key === 'birthdate') {
        $val = self::validateDateYmd($data[$key] ?? null);
      } elseif ($key === 'sibling') {
        $val = self::boolInt($data[$key] ?? 0);
      } else {
        $val = self::nn($data[$key]);
      }
      $set[] = "$key = ?";
      $params[] = $val;
    }

    // Admin-only updates
    if ($isAdmin) {
      if (array_key_exists('bsa_registration_number', $data)) {
        $val = self::nn($data['bsa_registration_number']);
        $set[] = "bsa_registration_number = ?";
        $params[] = $val;
      }
      if (array_key_exists('bsa_registration_expires_date', $data)) {
        $val = self::validateDateYmd($data['bsa_registration_expires_date'] ?? null);
        $set[] = "bsa_registration_expires_date = ?";
        $params[] = $val;
      }
      // Grade/class_of recomputation
      if (array_key_exists('grade', $data) || array_key_exists('grade_label', $data)) {
        $grade = self::parseGradeFromData($data);
        $class_of = self::computeClassOfFromGrade($grade);
        $set[] = "class_of = ?";
        $params[] = $class_of;
      }
    }

    // Paid-until: approver-only (Cubmaster/Committee Chair/Treasurer)
    if (array_key_exists('date_paid_until', $data)) {
      if (!\UserManagement::isApprover((int)$ctx->id)) {
        throw new InvalidArgumentException('Forbidden - not approver');
      }
      $val = self::validateDateYmd($data['date_paid_until'] ?? null);
      $set[] = "date_paid_until = ?";
      $params[] = $val;
    }

    if (empty($set)) return false;
    $params[] = $id;

    $sql = "UPDATE youth SET " . implode(', ', $set) . " WHERE id = ?";
    $st = self::pdo()->prepare($sql);
    $ok = $st->execute($params);
    if ($ok) {
      // Extract field names from "$key = ?" tokens
      $fieldNames = [];
      foreach ($set as $s) {
        $pos = strpos($s, ' = ');
        $fieldNames[] = ($pos !== false) ? substr($s, 0, $pos) : $s;
      }
      self::log('youth.edit', $id, ['fields' => $fieldNames]);
      if (in_array('date_paid_until', $fieldNames, true)) {
        self::log('youth.mark_paid', $id, ['paid_until' => $data['date_paid_until'] ?? null]);
      }
    }
    return $ok;
  }

  public static function delete(UserContext $ctx, int $id): int {
    self::assertAdmin($ctx);
    $st = self::pdo()->prepare('DELETE FROM youth WHERE id=?');
    $st->execute([$id]);
    $count = (int)$st->rowCount();
    if ($count > 0) {
      self::log('youth.delete', $id, []);
    }
    return $count;
  }

  /**
   * Update registration expiry by BSA number (used by imports).
   * Admin-only. Returns number of rows updated.
   */
  public static function updateRegistrationExpiryByBsa(UserContext $ctx, string $bsa, string $expiresYmd): int {
    self::assertAdmin($ctx);
    if ($bsa === '') throw new InvalidArgumentException('BSA number is required.');
    $expires = self::validateDateYmd($expiresYmd);
    $st = self::pdo()->prepare('UPDATE youth SET bsa_registration_expires_date = ? WHERE bsa_registration_number = ?');
    $st->execute([$expires, $bsa]);
    $count = (int)$st->rowCount();
    if ($count > 0) {
      self::log('youth.update_registration_expiry', null, [
        'bsa_registration_number' => $bsa,
        'expires' => $expires,
      ]);
    }
    return $count;
  }

  /**
   * List youths with a valid BSA Registration ID for renewal review, optionally filtered by status and grade.
   * filters:
   *   - status: 'needs' | 'renewed' | 'all' (default 'all')
   *   - grade_label: 'K','1',..'5' (optional)
   *   - grade: int (0..5) alternative to grade_label
   */
  public static function listForRenewals(UserContext $ctx, array $filters = []): array {
    self::assertLogin($ctx);

    $status = isset($filters['status']) ? trim((string)$filters['status']) : 'all';
    $gradeLabel = isset($filters['grade_label']) ? (string)$filters['grade_label'] : '';
    $grade = null;
    if ($gradeLabel !== '') {
      $g = \GradeCalculator::parseGradeLabel($gradeLabel);
      if ($g !== null) { $grade = (int)$g; }
    } elseif (array_key_exists('grade', $filters) && $filters['grade'] !== null && $filters['grade'] !== '') {
      $grade = (int)$filters['grade'];
    }

    $params = [];
    $sql = "SELECT id, first_name, last_name, preferred_name, suffix, class_of, bsa_registration_number, date_paid_until
            FROM youth
            WHERE (bsa_registration_number IS NOT NULL AND bsa_registration_number <> '')";

    if ($status === 'needs') {
      $sql .= " AND (date_paid_until IS NULL OR date_paid_until < CURDATE())";
    } elseif ($status === 'needs_no_siblings') {
      $sql .= " AND (date_paid_until IS NULL OR date_paid_until < CURDATE()) AND sibling = 0";
    } elseif ($status === 'renewed') {
      $sql .= " AND (date_paid_until IS NOT NULL AND date_paid_until >= CURDATE())";
    }

    if ($grade !== null) {
      // Filter by class_of derived from grade
      $classOf = self::computeClassOfFromGrade((int)$grade);
      $sql .= " AND class_of = ?";
      $params[] = $classOf;
    }

    $sql .= " ORDER BY last_name, first_name";

    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll() ?: [];
  }
}
