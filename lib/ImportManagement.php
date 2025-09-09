<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/YouthManagement.php';
require_once __DIR__ . '/UserManagement.php';
require_once __DIR__ . '/GradeCalculator.php';

class ImportManagement {
  // ================
  // CSV PARSING
  // ================
  public static function parseCsvToRows(string $csvText, bool $hasHeader = true, string $delimiter = ','): array {
    $csvText = str_replace("\r\n", "\n", $csvText);
    $csvText = str_replace("\r", "\n", $csvText);
    $lines = preg_split('/\n+/', trim($csvText));
    $rows = [];
    foreach ($lines as $line) {
      if ($line === '') continue;
      $rows[] = str_getcsv($line, $delimiter, "\"", "\\");
    }
    if (empty($rows)) return ['headers' => [], 'rows' => []];

    if ($hasHeader) {
      $headers = array_map(static function ($h) {
        return trim((string)$h);
      }, array_shift($rows));
    } else {
      $maxCols = 0;
      foreach ($rows as $r) $maxCols = max($maxCols, count($r));
      $headers = [];
      for ($i = 0; $i < $maxCols; $i++) $headers[] = 'Column '.($i+1);
    }

    // Normalize rows to header count
    $normRows = [];
    foreach ($rows as $r) {
      $r = array_map(static fn($v) => is_string($v) ? trim($v) : $v, $r);
      if (count($r) < count($headers)) {
        $r = array_pad($r, count($headers), '');
      } elseif (count($r) > count($headers)) {
        $r = array_slice($r, 0, count($headers));
      }
      $normRows[] = $r;
    }
    return ['headers' => $headers, 'rows' => $normRows];
  }

  // ================
  // NORMALIZATION
  // ================
  public static function normalizeGender(?string $g): ?string {
    if ($g === null) return null;
    $val = strtolower(trim($g));
    if ($val === '') return null;
    // Accept M/MALE and F/FEMALE as requested
    if ($val === 'm' || $val === 'male') return 'male';
    if ($val === 'f' || $val === 'female') return 'female';
    // Accept stored enums case-insensitive
    $allowed = ['male','female','non-binary','prefer not to say'];
    foreach ($allowed as $a) {
      if ($val === strtolower($a)) return $a;
    }
    return null; // unknown -> null (we'll note in validation)
  }

  public static function parseGradeToClassOf(?string $gradeLabel): ?int {
    if ($gradeLabel === null) return null;
    $lbl = trim($gradeLabel);
    if ($lbl === '') return null;
    $g = \GradeCalculator::parseGradeLabel($lbl);
    if ($g === null) return null;
    $currentFifthClassOf = \GradeCalculator::schoolYearEndYear();
    return $currentFifthClassOf + (5 - (int)$g);
  }

  // Map a single CSV row (numeric array) using the provided header->destination map to structured arrays
  public static function normalizeRow(array $headers, array $row, array $map): array {
    $youth = [
      'first_name' => null, 'last_name' => null, 'grade_label' => null, 'class_of' => null,
      'bsa_registration_number' => null, 'gender' => null,
      'street1' => null, 'city' => null, 'state' => null, 'zip' => null,
    ];
    $p1 = ['first_name'=>null,'last_name'=>null,'bsa_id'=>null,'email'=>null,'phone'=>null];
    $p2 = ['first_name'=>null,'last_name'=>null,'bsa_id'=>null,'email'=>null,'phone'=>null];

    $headerCount = count($headers);
    for ($i=0; $i<$headerCount; $i++) {
      $h = $headers[$i] ?? '';
      $dest = $map[$h] ?? 'ignore';
      $val = isset($row[$i]) ? trim((string)$row[$i]) : '';
      if ($val === '') continue;

      switch ($dest) {
        case 'youth_first_name': $youth['first_name'] = $val; break;
        case 'youth_last_name': $youth['last_name'] = $val; break;
        case 'youth_grade': $youth['grade_label'] = $val; break;
        case 'youth_bsa_id': $youth['bsa_registration_number'] = $val; break;
        case 'youth_gender': $youth['gender'] = self::normalizeGender($val); break;
        case 'youth_street1': $youth['street1'] = $val; break;
        case 'youth_city': $youth['city'] = $val; break;
        case 'youth_state': $youth['state'] = $val; break;
        case 'youth_zip': $youth['zip'] = $val; break;

        case 'p1_first_name': $p1['first_name'] = $val; break;
        case 'p1_last_name': $p1['last_name'] = $val; break;
        case 'p1_bsa_id': $p1['bsa_id'] = $val; break;
        case 'p1_email': $p1['email'] = strtolower($val); break;
        case 'p1_phone': $p1['phone'] = $val; break;

        case 'p2_first_name': $p2['first_name'] = $val; break;
        case 'p2_last_name': $p2['last_name'] = $val; break;
        case 'p2_bsa_id': $p2['bsa_id'] = $val; break;
        case 'p2_email': $p2['email'] = strtolower($val); break;
        case 'p2_phone': $p2['phone'] = $val; break;

        case 'ignore':
        default:
          // skip
          break;
      }
    }

    // Compute class_of if grade provided
    $youth['class_of'] = self::parseGradeToClassOf($youth['grade_label']);

    return ['youth' => $youth, 'p1' => $p1, 'p2' => $p2];
  }

  // ================
  // LOOKUPS
  // ================
  private static function pdo(): PDO { return pdo(); }

  private static function findYouthIdByBsa(string $bsa): ?int {
    $st = self::pdo()->prepare('SELECT id FROM youth WHERE bsa_registration_number = ? LIMIT 1');
    $st->execute([$bsa]);
    $r = $st->fetch();
    return $r ? (int)$r['id'] : null;
  }

  private static function findYouthIdByName(string $first, string $last): ?int {
    $st = self::pdo()->prepare('SELECT id FROM youth WHERE LOWER(first_name)=LOWER(?) AND LOWER(last_name)=LOWER(?) LIMIT 1');
    $st->execute([$first, $last]);
    $r = $st->fetch();
    return $r ? (int)$r['id'] : null;
  }

  private static function findAdultIdByBsa(string $bsa): ?int {
    $st = self::pdo()->prepare('SELECT id FROM users WHERE bsa_membership_number = ? LIMIT 1');
    $st->execute([$bsa]);
    $r = $st->fetch();
    return $r ? (int)$r['id'] : null;
  }

  private static function findAdultIdByEmail(string $email): ?int {
    return UserManagement::findIdByEmail($email);
  }

  private static function findAdultIdByName(string $first, string $last): ?int {
    $st = self::pdo()->prepare('SELECT id FROM users WHERE LOWER(first_name)=LOWER(?) AND LOWER(last_name)=LOWER(?) LIMIT 1');
    $st->execute([$first, $last]);
    $r = $st->fetch();
    return $r ? (int)$r['id'] : null;
  }

  // ================
  // VALIDATION
  // ================
  public static function validateStructuredRows(array $structuredRows): array {
    $results = [];
    foreach ($structuredRows as $idx => $r) {
      $msgs = [];
      $ok = true;

      $y = $r['youth'];
      $p1 = $r['p1'];
      $p2 = $r['p2'];

      // Youth grade required
      if (empty($y['grade_label'])) {
        $ok = false;
        $msgs[] = 'Error: Youth Grade is required.';
      } elseif ($y['class_of'] === null) {
        $ok = false;
        $msgs[] = 'Error: Youth Grade is invalid (must be K or 0..5).';
      }

      // Youth name or BSA logic
      $youthHasName = !empty($y['first_name']) && !empty($y['last_name']);
      $youthHasBsa = !empty($y['bsa_registration_number']);

      $youthMatchedId = null;
      if ($youthHasBsa) {
        $youthMatchedId = self::findYouthIdByBsa($y['bsa_registration_number']);
      }
      if (!$youthMatchedId && $youthHasName) {
        $youthMatchedId = self::findYouthIdByName($y['first_name'], $y['last_name']);
      }

      if (!$youthHasName) {
        // Missing name: allowed only if BSA is present AND exists (edit only)
        if ($youthHasBsa && $youthMatchedId) {
          $msgs[] = 'Youth: editing by BSA (name missing).';
        } else {
          $ok = false;
          $msgs[] = 'Error: Youth First Name and Last Name are required unless editing by existing BSA.';
        }
      }

      // Gender normalization info
      if (!empty($y['gender'])) {
        $allowed = ['male','female','non-binary','prefer not to say'];
        if (!in_array($y['gender'], $allowed, true)) {
          $msgs[] = 'Note: Unrecognized gender value ignored.';
          $y['gender'] = null;
        }
      }

      // At least one parent with identifying info
      $parentIdent = function(array $p): bool {
        if (!empty($p['bsa_id'])) return true;
        if (!empty($p['email'])) return true;
        if (!empty($p['first_name']) && !empty($p['last_name'])) return true;
        return false;
      };
      if (!$parentIdent($p1) && !$parentIdent($p2)) {
        $ok = false;
        $msgs[] = 'Error: At least one parent must be provided with identifying info (name or email or BSA ID).';
      }

      // Validate emails
      foreach (['p1','p2'] as $pk) {
        $e = $r[$pk]['email'] ?? null;
        if ($e !== null && $e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) {
          $ok = false;
          $msgs[] = 'Error: Invalid email for '.strtoupper($pk).'.';
        }
      }

      $results[] = [
        'ok' => $ok,
        'messages' => $msgs,
        'row' => $r,
      ];
    }
    return $results;
  }

  // ================
  // COMMIT
  // ================
  public static function commit(array $validatedRows, UserContext $ctx, callable $logger): void {
    foreach ($validatedRows as $i => $vr) {
      $rowNo = $i + 1;
      if (!$vr['ok']) {
        $logger("Row #$rowNo: Skipped due to validation errors");
        continue;
      }
      $r = $vr['row'];
      $y = $r['youth'];
      $p1 = $r['p1'];
      $p2 = $r['p2'];

      // Upsert Youth
      $youthId = null;
      if (!empty($y['bsa_registration_number'])) {
        $youthId = self::findYouthIdByBsa($y['bsa_registration_number']);
      }
      if ($youthId === null && !empty($y['first_name']) && !empty($y['last_name'])) {
        $youthId = self::findYouthIdByName($y['first_name'], $y['last_name']);
      }

      $youthFields = [
        'first_name' => $y['first_name'],
        'last_name' => $y['last_name'],
        'bsa_registration_number' => $y['bsa_registration_number'],
        'street1' => $y['street1'],
        'city' => $y['city'],
        'state' => $y['state'],
        'zip' => $y['zip'],
        'gender' => $y['gender'],
      ];

      // Only non-empty
      $applyNonEmpty = function(array $fields): array {
        $out = [];
        foreach ($fields as $k=>$v) {
          if ($v === null) continue;
          if (is_string($v) && trim($v) === '') continue;
          $out[$k] = $v;
        }
        return $out;
      };

      if ($youthId) {
        $upd = $applyNonEmpty($youthFields);
        // update class_of if present (based on grade)
        if ($y['class_of'] !== null) {
          $upd['class_of'] = $y['class_of'];
        }
        if (!empty($upd)) {
          YouthManagement::update($ctx, $youthId, $upd);
          $logger("Row #$rowNo: Youth '".($y['first_name'] ?? '')." ".($y['last_name'] ?? '')."' updated (id=$youthId)");
        } else {
          $logger("Row #$rowNo: Youth '".($y['first_name'] ?? '')." ".($y['last_name'] ?? '')."' no changes (id=$youthId)");
        }
      } else {
        // Create needs first/last + class_of; validation guaranteed presence
        $data = $applyNonEmpty($youthFields);
        $data['grade_label'] = $y['grade_label']; // YouthManagement::create computes class_of from grade
        $newId = YouthManagement::create($ctx, $data);
        $youthId = $newId;
        $logger("Row #$rowNo: Youth '".($y['first_name'] ?? '')." ".($y['last_name'] ?? '')."' created (id=$youthId)");
      }

      // Upsert Parent helper
      $upsertAdult = function(array $p) use ($ctx, $applyNonEmpty, $logger, $rowNo): ?int {
        if (empty($p['first_name']) && empty($p['last_name']) && empty($p['email']) && empty($p['bsa_id'])) {
          return null;
        }

        // Lookup order: bsa -> email -> name
        $aid = null;
        if (!empty($p['bsa_id'])) $aid = ImportManagement::findAdultIdByBsa($p['bsa_id']);
        if ($aid === null && !empty($p['email'])) $aid = ImportManagement::findAdultIdByEmail($p['email']);
        if ($aid === null && !empty($p['first_name']) && !empty($p['last_name'])) {
          $aid = ImportManagement::findAdultIdByName($p['first_name'], $p['last_name']);
        }

        $fields = [
          'first_name' => $p['first_name'] ?? null,
          'last_name' => $p['last_name'] ?? null,
          'email' => $p['email'] ?? null,
          'phone_cell' => $p['phone'] ?? null,
          'bsa_membership_number' => $p['bsa_id'] ?? null,
        ];
        $fields = $applyNonEmpty($fields);

        if ($aid) {
          if (!empty($fields)) {
            UserManagement::updateProfile($ctx, $aid, $fields, false);
            $logger("Row #$rowNo: Adult '".($p['first_name'] ?? '')." ".($p['last_name'] ?? '')."' updated (id=$aid)");
          } else {
            $logger("Row #$rowNo: Adult '".($p['first_name'] ?? '')." ".($p['last_name'] ?? '')."' no changes (id=$aid)");
          }
          return $aid;
        } else {
          // Create minimal adult and then update fields
          $createData = [
            'first_name' => $p['first_name'] ?? 'Parent',
            'last_name' => $p['last_name'] ?? 'Unknown',
            'email' => $p['email'] ?? null,
            'is_admin' => 0,
          ];
          $newAid = UserManagement::createAdultRecord($ctx, $createData);
          if (!empty($fields)) {
            UserManagement::updateProfile($ctx, $newAid, $fields, false);
          }
          $logger("Row #$rowNo: Adult '".($p['first_name'] ?? '')." ".($p['last_name'] ?? '')."' created (id=$newAid)");
          return $newAid;
        }
      };

      $p1Id = $upsertAdult($p1);
      $p2Id = $upsertAdult($p2);

      // Link relationships
      if ($p1Id) {
        self::ensureParentRelationship($youthId, $p1Id);
        $logger("Row #$rowNo: Linked Parent 1 to youth");
      }
      if ($p2Id) {
        self::ensureParentRelationship($youthId, $p2Id);
        $logger("Row #$rowNo: Linked Parent 2 to youth");
      }
    }
  }

  private static function ensureParentRelationship(int $youthId, int $adultId): void {
    // INSERT IGNORE-like behavior
    $st = self::pdo()->prepare('INSERT IGNORE INTO parent_relationships (youth_id, adult_id) VALUES (?, ?)');
    $st->execute([$youthId, $adultId]);
  }
}
