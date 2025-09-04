<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

class ImportRegDates {
  // Destinations used in mapping UI
  public const DEST_IGNORE = 'ignore';
  public const DEST_BSA    = 'youth_bsa_registration_number';
  public const DEST_FIRST  = 'youth_first_name';
  public const DEST_LAST   = 'youth_last_name';
  public const DEST_EXPIRES= 'youth_registration_expires_date';

  private static function pdo(): PDO { return pdo(); }

  public static function normalizeDateFlexible(?string $s): ?string {
    if ($s === null) return null;
    $s = trim($s);
    if ($s === '') return null;

    // Try strict YYYY-MM-DD
    $dt = DateTime::createFromFormat('Y-m-d', $s);
    if ($dt && $dt->format('Y-m-d') === $s) {
      return $s;
    }

    // Try common US formats
    $formats = ['m/d/Y', 'n/j/Y', 'm/d/y', 'n/j/y'];
    foreach ($formats as $fmt) {
      $dt = DateTime::createFromFormat($fmt, $s);
      if ($dt && $dt->format($fmt) === $s) {
        return $dt->format('Y-m-d');
      }
    }

    // Fallback to strtotime parsing (best-effort)
    $ts = strtotime($s);
    if ($ts !== false) {
      return date('Y-m-d', $ts);
    }

    // Could not parse
    return null;
  }

  private static function findYouthByBsa(string $bsa): ?array {
    $st = self::pdo()->prepare('SELECT id, first_name, last_name FROM youth WHERE bsa_registration_number = ? LIMIT 1');
    $st->execute([$bsa]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public static function validate(array $headers, array $rows, array $map): array {
    // map: header => destination
    $results = [];
    foreach ($rows as $idx => $r) {
      $messages = [];
      $warnings = [];
      $ok = true;

      $bsa = '';
      $first = '';
      $last = '';
      $expiresRaw = '';

      foreach ($headers as $i => $h) {
        $dest = $map[$h] ?? self::DEST_IGNORE;
        $val = isset($r[$i]) ? trim((string)$r[$i]) : '';
        if ($val === '') continue;

        switch ($dest) {
          case self::DEST_BSA:     $bsa = $val; break;
          case self::DEST_FIRST:   $first = $val; break;
          case self::DEST_LAST:    $last = $val; break;
          case self::DEST_EXPIRES: $expiresRaw = $val; break;
          case self::DEST_IGNORE:
          default:
            break;
        }
      }

      if ($bsa === '') {
        $ok = false;
        $messages[] = 'Error: Missing BSA Registration Number.';
      }
      $expires = null;
      if ($expiresRaw === '') {
        $ok = false;
        $messages[] = 'Error: Missing Registration Expires Date.';
      } else {
        $expires = self::normalizeDateFlexible($expiresRaw);
        if ($expires === null) {
          $ok = false;
          $messages[] = 'Error: Invalid Registration Expires Date (expect YYYY-MM-DD or parseable date).';
        }
      }

      $youth = null;
      if ($bsa !== '') {
        $youth = self::findYouthByBsa($bsa);
        if (!$youth) {
          $ok = false;
          $messages[] = 'Error: No youth found with that BSA Registration Number.';
        } else {
          // Name mismatch warning only (if both provided)
          if ($first !== '' || $last !== '') {
            $dbFirst = strtolower(trim((string)($youth['first_name'] ?? '')));
            $dbLast  = strtolower(trim((string)($youth['last_name'] ?? '')));
            if ($first !== '' && strtolower($first) !== $dbFirst) {
              $warnings[] = 'Warning: First name mismatch with database.';
            }
            if ($last !== '' && strtolower($last) !== $dbLast) {
              $warnings[] = 'Warning: Last name mismatch with database.';
            }
          }
        }
      }

      $results[] = [
        'ok' => $ok,
        'messages' => $messages,
        'warnings' => $warnings,
        'row_index' => $idx,
        'normalized' => [
          'bsa' => $bsa,
          'first' => $first,
          'last' => $last,
          'expires' => $expires,
          'youth' => $youth,
        ],
      ];
    }
    return $results;
  }

  public static function commit(array $validated, callable $logger): array {
    $updated = 0;
    $skipped = 0;
    foreach ($validated as $i => $vr) {
      $rowNo = $i + 1;
      if (!$vr['ok']) {
        $skipped++;
        $logger("Row #$rowNo: Skipped due to validation errors.");
        continue;
      }
      $n = $vr['normalized'] ?? [];
      $bsa = (string)($n['bsa'] ?? '');
      $expires = (string)($n['expires'] ?? '');
      try {
        $st = self::pdo()->prepare('UPDATE youth SET bsa_registration_expires_date = ? WHERE bsa_registration_number = ?');
        $st->execute([$expires, $bsa]);
        $count = (int)$st->rowCount();
        if ($count > 0) {
          $updated += $count;
          $logger("Row #$rowNo: Updated BSA $bsa expires to $expires.");
        } else {
          $skipped++;
          $logger("Row #$rowNo: No row updated for BSA $bsa (already set or missing).");
        }
      } catch (Throwable $e) {
        $skipped++;
        $logger("Row #$rowNo: Error updating BSA $bsa.");
      }
    }
    return ['updated' => $updated, 'skipped' => $skipped];
  }
}
