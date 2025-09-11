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
   * - registered: 'all' | 'yes' | 'no'
   */
  public static function normalizeFilters(array $filters): array {
    $out = [
      'q' => null,
      'class_of' => null,
      'registered' => 'all',
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
      if ($r === 'yes' || $r === 'no') {
        $out['registered'] = $r;
      } else {
        $out['registered'] = 'all';
      }
    }
    return $out;
  }

  /**
   * Return distinct adults [id, first_name, last_name, email] obeying filters.
   */
  public static function searchAdults(array $filters): array {
    $f = self::normalizeFilters($filters);

    $params = [];
    $sqlBase = "
      FROM users u
      LEFT JOIN parent_relationships pr ON pr.adult_id = u.id
      LEFT JOIN youth y ON y.id = pr.youth_id
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

    if ($f['registered'] === 'yes') {
      $sqlBase .= " AND y.bsa_registration_number IS NOT NULL";
    } elseif ($f['registered'] === 'no') {
      $sqlBase .= " AND (y.id IS NOT NULL AND y.bsa_registration_number IS NULL)";
    }

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

    $params = [];
    $sqlBase = "
      FROM users u
      LEFT JOIN parent_relationships pr ON pr.adult_id = u.id
      LEFT JOIN youth y ON y.id = pr.youth_id
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
    if ($f['registered'] === 'yes') {
      $sqlBase .= " AND y.bsa_registration_number IS NOT NULL";
    } elseif ($f['registered'] === 'no') {
      $sqlBase .= " AND (y.id IS NOT NULL AND y.bsa_registration_number IS NULL)";
    }

    $ph = implode(',', array_fill(0, count($adultIds), '?'));
    $sql = "SELECT u.id AS adult_id, y.class_of " . $sqlBase . " AND y.id IS NOT NULL AND u.id IN ($ph)";
    $params = array_merge($params, $adultIds);

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

    // Recommendations (apply 'q' filter and grade if provided)
    $recParams = [];
    $recSql = "SELECT r.parent_name, r.child_name, r.email FROM recommendations r WHERE r.email IS NOT NULL AND r.email <> ''";
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
      if (isset($contacts[$emailKey])) continue; // prefer adult
      $name = trim((string)($r['parent_name'] ?? ''));
      if ($name === '') {
        $nmChild = trim((string)($r['child_name'] ?? ''));
        $name = ($nmChild !== '') ? $nmChild : $emailRaw;
      }
      $contacts[$emailKey] = [
        'name' => $name,
        'email' => $emailRaw,
        'grades' => [],
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
