<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/GradeCalculator.php';
require_once __DIR__ . '/Search.php';

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
      throw new RuntimeException('Forbidden');
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

  // =========================
  // Reads / Queries
  // =========================

  // Roster search for logged-in users (admins and non-admins)
  public static function searchRoster(UserContext $ctx, ?string $q, ?int $grade, bool $includeUnregistered = false): array {
    self::assertLogin($ctx);

    $params = [];
    $sql = "SELECT y.*, dm.den_id, d.den_name
            FROM youth y
            LEFT JOIN den_memberships dm ON dm.youth_id = y.id
            LEFT JOIN dens d ON d.id = dm.den_id
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
      $sql .= " AND y.bsa_registration_number IS NOT NULL AND y.bsa_registration_number <> ''";
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
    $pps = self::pdo()->prepare("SELECT u.id,u.first_name,u.last_name,u.email, pr.relationship
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

    $st = self::pdo()->prepare("INSERT INTO youth
      (first_name,last_name,suffix,preferred_name,gender,birthdate,school,shirt_size,bsa_registration_number,
       street1,street2,city,state,zip,class_of,sibling)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $ok = $st->execute([
      $first, $last, $suffix, $preferred_name, $gender, $birthdate, $school, $shirt_size, $bsa,
      $street1, $street2, $city, $state, $zip, $class_of, $sibling
    ]);
    if (!$ok) throw new RuntimeException('Failed to create youth.');
    return (int)self::pdo()->lastInsertId();
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
      // Grade/class_of recomputation
      if (array_key_exists('grade', $data) || array_key_exists('grade_label', $data)) {
        $grade = self::parseGradeFromData($data);
        $class_of = self::computeClassOfFromGrade($grade);
        $set[] = "class_of = ?";
        $params[] = $class_of;
      }
    }

    if (empty($set)) return false;
    $params[] = $id;

    $sql = "UPDATE youth SET " . implode(', ', $set) . " WHERE id = ?";
    $st = self::pdo()->prepare($sql);
    return $st->execute($params);
  }

  public static function delete(UserContext $ctx, int $id): int {
    self::assertAdmin($ctx);
    $st = self::pdo()->prepare('DELETE FROM youth WHERE id=?');
    $st->execute([$id]);
    return (int)$st->rowCount();
  }
}
