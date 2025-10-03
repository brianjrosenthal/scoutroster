<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/UserManagement.php';
require_once __DIR__ . '/YouthManagement.php';

class ScoutingOrgImport {
  // Field mapping destinations
  public const DEST_IGNORE = 'ignore';
  public const DEST_BSA_REG_NUM = 'bsa_registration_number';
  public const DEST_FIRST_NAME = 'first_name';
  public const DEST_LAST_NAME = 'last_name';
  public const DEST_POSITION_NAME = 'position_name';
  public const DEST_SAFEGUARD_COMPLETED = 'safeguarding_training_completed_on';
  public const DEST_SAFEGUARD_EXPIRES = 'safeguarding_training_expires_on';
  public const DEST_STREET1 = 'street1';
  public const DEST_CITY = 'city';
  public const DEST_STATE = 'state';
  public const DEST_ZIP = 'zip';
  public const DEST_EMAIL = 'email';
  public const DEST_PHONE_CELL = 'phone_cell';
  public const DEST_BSA_REG_EXPIRES = 'bsa_registration_expires_on';

  private static function pdo(): PDO { return pdo(); }

  /**
   * Parse Scouting.org roster CSV with special header detection
   */
  public static function parseCsvToRows(string $csvText, string $delimiter = "\t"): array {
    $csvText = str_replace("\r\n", "\n", $csvText);
    $csvText = str_replace("\r", "\n", $csvText);
    $lines = preg_split('/\n+/', trim($csvText));
    
    $headers = null;
    $rows = [];
    $foundHeader = false;
    
    foreach ($lines as $line) {
      if ($line === '') continue;
      
      // Look for the header line starting with ..memberid
      if (!$foundHeader) {
        if (strpos($line, '..memberid') === 0) {
          $headers = str_getcsv($line, $delimiter, "\"", "\\");
          $headers = array_map(static function ($h) {
            return trim((string)$h);
          }, $headers);
          $foundHeader = true;
        }
        continue;
      }
      
      // Process data rows after header found
      $row = str_getcsv($line, $delimiter, "\"", "\\");
      $row = array_map(static fn($v) => is_string($v) ? trim($v) : $v, $row);
      
      // Normalize row to header count
      if (count($row) < count($headers)) {
        $row = array_pad($row, count($headers), '');
      } elseif (count($row) > count($headers)) {
        $row = array_slice($row, 0, count($headers));
      }
      
      $rows[] = $row;
    }
    
    if (!$foundHeader || empty($headers)) {
      throw new Exception('Header line starting with "..memberid" not found in CSV file');
    }
    
    return ['headers' => $headers, 'rows' => $rows];
  }

  /**
   * Get default field mappings for Scouting.org headers
   */
  public static function getDefaultMappings(): array {
    return [
      '..memberid' => self::DEST_BSA_REG_NUM,
      'firstname' => self::DEST_FIRST_NAME,
      'lastname' => self::DEST_LAST_NAME,
      'positionname' => self::DEST_POSITION_NAME,
      'stryptcompletiondate' => self::DEST_SAFEGUARD_COMPLETED,
      'stryptexpirationdate' => self::DEST_SAFEGUARD_EXPIRES,
      'streetaddress' => self::DEST_STREET1,
      'city' => self::DEST_CITY,
      'statecode' => self::DEST_STATE,
      'zip9' => self::DEST_ZIP,
      'primaryemail' => self::DEST_EMAIL,
      'primaryphone' => self::DEST_PHONE_CELL,
      'expirydtstr' => self::DEST_BSA_REG_EXPIRES,
    ];
  }

  /**
   * Get available mapping destinations
   */
  public static function getMappingDestinations(): array {
    return [
      self::DEST_IGNORE => 'Ignore',
      self::DEST_BSA_REG_NUM => 'BSA Registration Number',
      self::DEST_FIRST_NAME => 'First Name',
      self::DEST_LAST_NAME => 'Last Name',
      self::DEST_POSITION_NAME => 'Position Name',
      self::DEST_SAFEGUARD_COMPLETED => 'Safeguarding Training Completed',
      self::DEST_SAFEGUARD_EXPIRES => 'Safeguarding Training Expires',
      self::DEST_STREET1 => 'Street Address',
      self::DEST_CITY => 'City',
      self::DEST_STATE => 'State',
      self::DEST_ZIP => 'ZIP Code',
      self::DEST_EMAIL => 'Email',
      self::DEST_PHONE_CELL => 'Cell Phone',
      self::DEST_BSA_REG_EXPIRES => 'BSA Registration Expires',
    ];
  }

  /**
   * Normalize a date string flexibly
   */
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

    // Fallback to strtotime parsing
    $ts = strtotime($s);
    if ($ts !== false) {
      return date('Y-m-d', $ts);
    }

    return null;
  }

  /**
   * Normalize a single CSV row using the field mapping
   */
  public static function normalizeRow(array $headers, array $row, array $map): array {
    $data = [];
    
    foreach ($headers as $i => $header) {
      $dest = $map[$header] ?? self::DEST_IGNORE;
      $val = isset($row[$i]) ? trim((string)$row[$i]) : '';
      
      if ($val === '' || $dest === self::DEST_IGNORE) continue;
      
      // Store the normalized value
      $data[$dest] = $val;
    }
    
    // Determine if this is Adult or Youth based on position_name
    $positionName = $data[self::DEST_POSITION_NAME] ?? '';
    $isYouth = (strtolower($positionName) === 'youth member');
    
    return [
      'type' => $isYouth ? 'youth' : 'adult',
      'data' => $data
    ];
  }

  /**
   * Find adult by BSA membership number
   */
  private static function findAdultByBsa(string $bsa): ?array {
    $st = self::pdo()->prepare('SELECT id, first_name, last_name, email, phone_cell, street1, city, state, zip, bsa_membership_number FROM users WHERE bsa_membership_number = ? LIMIT 1');
    $st->execute([$bsa]);
    return $st->fetch() ?: null;
  }

  /**
   * Find adult by email
   */
  private static function findAdultByEmail(string $email): ?array {
    $st = self::pdo()->prepare('SELECT id, first_name, last_name, email, phone_cell, street1, city, state, zip, bsa_membership_number FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    return $st->fetch() ?: null;
  }

  /**
   * Find adult by first and last name
   */
  private static function findAdultByName(string $first, string $last): ?array {
    $st = self::pdo()->prepare('SELECT id, first_name, last_name, email, phone_cell, street1, city, state, zip, bsa_membership_number FROM users WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) LIMIT 1');
    $st->execute([$first, $last]);
    return $st->fetch() ?: null;
  }

  /**
   * Find youth by BSA registration number
   */
  private static function findYouthByBsa(string $bsa): ?array {
    $st = self::pdo()->prepare('SELECT id, first_name, last_name, bsa_registration_number FROM youth WHERE bsa_registration_number = ? LIMIT 1');
    $st->execute([$bsa]);
    return $st->fetch() ?: null;
  }

  /**
   * Find youth by first and last name
   */
  private static function findYouthByName(string $first, string $last): ?array {
    $st = self::pdo()->prepare('SELECT id, first_name, last_name, bsa_registration_number FROM youth WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) LIMIT 1');
    $st->execute([$first, $last]);
    return $st->fetch() ?: null;
  }

  /**
   * Validate normalized rows and generate change preview
   */
  public static function validateAndPreview(array $normalizedRows): array {
    $results = [];
    
    foreach ($normalizedRows as $idx => $row) {
      $type = $row['type'];
      $data = $row['data'];
      $messages = [];
      $changes = [];
      $notices = [];
      $matched = null;
      
      $bsaNum = $data[self::DEST_BSA_REG_NUM] ?? '';
      $firstName = $data[self::DEST_FIRST_NAME] ?? '';
      $lastName = $data[self::DEST_LAST_NAME] ?? '';
      $email = $data[self::DEST_EMAIL] ?? '';
      
      if ($type === 'adult') {
        // Adult matching logic
        if ($bsaNum) {
          $matched = self::findAdultByBsa($bsaNum);
        }
        if (!$matched && $email) {
          $matched = self::findAdultByEmail($email);
        }
        if (!$matched && $firstName && $lastName) {
          $matched = self::findAdultByName($firstName, $lastName);
        }
        
        if ($matched) {
          // Generate changes for adults
          $adultId = (int)$matched['id'];
          
          // Always update these fields
          $alwaysUpdate = [
            self::DEST_BSA_REG_NUM => 'bsa_membership_number',
            self::DEST_BSA_REG_EXPIRES => 'bsa_registration_expires_on',
            self::DEST_SAFEGUARD_COMPLETED => 'safeguarding_training_completed_on',
            self::DEST_SAFEGUARD_EXPIRES => 'safeguarding_training_expires_on',
          ];
          
          foreach ($alwaysUpdate as $srcField => $dbField) {
            $newValue = $data[$srcField] ?? null;
            if ($newValue) {
              // Special handling for BSA membership number - only if empty
              if ($srcField === self::DEST_BSA_REG_NUM && !empty($matched['bsa_membership_number'])) {
                continue;
              }
              
              // Normalize dates
              if (in_array($srcField, [self::DEST_BSA_REG_EXPIRES, self::DEST_SAFEGUARD_COMPLETED, self::DEST_SAFEGUARD_EXPIRES])) {
                $newValue = self::normalizeDateFlexible($newValue);
                if (!$newValue) continue;
              }
              
              $changes[] = [
                'field_name' => $dbField,
                'new_value' => $newValue
              ];
            }
          }
          
          // Update only if empty in DB
          $updateIfEmpty = [
            self::DEST_STREET1 => 'street1',
            self::DEST_CITY => 'city',
            self::DEST_STATE => 'state',
            self::DEST_ZIP => 'zip',
            self::DEST_PHONE_CELL => 'phone_cell',
          ];
          
          foreach ($updateIfEmpty as $srcField => $dbField) {
            $newValue = $data[$srcField] ?? null;
            $oldValue = $matched[$dbField] ?? null;
            if ($newValue && empty($oldValue)) {
              $changes[] = [
                'field_name' => $dbField,
                'new_value' => $newValue
              ];
            }
          }
          
          // Check for discrepancies (notices only)
          $discrepancyFields = [
            self::DEST_FIRST_NAME => 'first_name',
            self::DEST_LAST_NAME => 'last_name',
            self::DEST_EMAIL => 'email',
            self::DEST_PHONE_CELL => 'phone_cell',
            self::DEST_STREET1 => 'street1',
            self::DEST_CITY => 'city',
            self::DEST_STATE => 'state',
            self::DEST_ZIP => 'zip',
          ];
          
          foreach ($discrepancyFields as $srcField => $dbField) {
            $newValue = $data[$srcField] ?? null;
            $oldValue = $matched[$dbField] ?? null;
            if ($newValue && $oldValue && strtolower(trim($newValue)) !== strtolower(trim($oldValue))) {
              $notices[] = "Discrepancy in {$dbField}: DB has '{$oldValue}' but import has '{$newValue}'";
            }
          }
        } else {
          $messages[] = 'No matching adult found in database';
        }
        
      } else {
        // Youth matching logic
        if ($bsaNum) {
          $matched = self::findYouthByBsa($bsaNum);
        }
        if (!$matched && $firstName && $lastName) {
          $matched = self::findYouthByName($firstName, $lastName);
        }
        
        if ($matched) {
          $youthId = (int)$matched['id'];
          
          // Update BSA membership number if empty
          if ($bsaNum && empty($matched['bsa_registration_number'])) {
            $changes[] = [
              'field_name' => 'bsa_registration_number',
              'new_value' => $bsaNum
            ];
          }
          
          // Always update registration expires date
          $expiresDate = $data[self::DEST_BSA_REG_EXPIRES] ?? null;
          if ($expiresDate) {
            $normalizedDate = self::normalizeDateFlexible($expiresDate);
            if ($normalizedDate) {
              $changes[] = [
                'field_name' => 'bsa_registration_expires_date',
                'new_value' => $normalizedDate
              ];
            }
          }
          
          // Check for discrepancies in names
          if ($firstName && strtolower(trim($firstName)) !== strtolower(trim($matched['first_name'] ?? ''))) {
            $notices[] = "First name mismatch: DB has '{$matched['first_name']}' but import has '{$firstName}'";
          }
          if ($lastName && strtolower(trim($lastName)) !== strtolower(trim($matched['last_name'] ?? ''))) {
            $notices[] = "Last name mismatch: DB has '{$matched['last_name']}' but import has '{$lastName}'";
          }
        } else {
          $messages[] = 'No matching youth found in database';
        }
      }
      
      $results[] = [
        'row_index' => $idx,
        'type' => $type,
        'data' => $data,
        'matched' => $matched,
        'changes' => $changes,
        'notices' => $notices,
        'messages' => $messages,
      ];
    }
    
    return $results;
  }

  /**
   * Log a field change to the audit table
   */
  private static function logFieldChange(?int $createdBy, string $type, ?int $adultId, ?int $youthId, string $fieldName, ?string $oldValue, ?string $newValue): void {
    if ($createdBy === null) {
      // Skip logging if no valid user context - this shouldn't happen in normal operation
      return;
    }
    $st = self::pdo()->prepare('INSERT INTO scouting_org_field_changes (created_by, type, adult_id, youth_id, field_name, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$createdBy, $type, $adultId, $youthId, $fieldName, $oldValue, $newValue]);
  }

  /**
   * Commit the validated changes to the database
   */
  public static function commit(array $validatedRows, UserContext $ctx, callable $logger): array {
    $updated = 0;
    $skipped = 0;
    $errors = 0;
    
    foreach ($validatedRows as $i => $row) {
      $rowNo = $i + 1;
      $type = $row['type'];
      $matched = $row['matched'];
      $changes = $row['changes'];
      $data = $row['data'];
      
      if (!$matched || empty($changes)) {
        $skipped++;
        $logger("Row #{$rowNo}: Skipped - " . ($matched ? 'no changes needed' : 'no match found'));
        continue;
      }
      
      try {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        
        if ($type === 'adult') {
          $adultId = (int)$matched['id'];
          
          // Get current values for logging
          $st = $pdo->prepare('SELECT bsa_membership_number, bsa_registration_expires_on, safeguarding_training_completed_on, safeguarding_training_expires_on, street1, city, state, zip, phone_cell FROM users WHERE id = ?');
          $st->execute([$adultId]);
          $current = $st->fetch();
          
          // Apply each change
          foreach ($changes as $change) {
            $fieldName = $change['field_name'];
            $newValue = $change['new_value'];
            $oldValue = $current[$fieldName] ?? null;
            
            $updateSt = $pdo->prepare("UPDATE users SET {$fieldName} = ? WHERE id = ?");
            $updateSt->execute([$newValue, $adultId]);
            
            self::logFieldChange($ctx->id, 'adult', $adultId, null, $fieldName, $oldValue, $newValue);
          }
          
          $logger("Row #{$rowNo}: Updated adult ID {$adultId} - " . count($changes) . " field(s) changed");
          
        } else {
          $youthId = (int)$matched['id'];
          
          // Get current values for logging
          $st = $pdo->prepare('SELECT bsa_registration_number, bsa_registration_expires_date FROM youth WHERE id = ?');
          $st->execute([$youthId]);
          $current = $st->fetch();
          
          // Apply each change
          foreach ($changes as $change) {
            $fieldName = $change['field_name'];
            $newValue = $change['new_value'];
            $oldValue = $current[$fieldName] ?? null;
            
            $updateSt = $pdo->prepare("UPDATE youth SET {$fieldName} = ? WHERE id = ?");
            $updateSt->execute([$newValue, $youthId]);
            
            self::logFieldChange($ctx->id, 'youth', null, $youthId, $fieldName, $oldValue, $newValue);
          }
          
          $logger("Row #{$rowNo}: Updated youth ID {$youthId} - " . count($changes) . " field(s) changed");
        }
        
        $pdo->commit();
        $updated++;
        
      } catch (Exception $e) {
        $pdo->rollBack();
        $errors++;
        $logger("Row #{$rowNo}: Error - " . $e->getMessage());
      }
    }
    
    return [
      'updated' => $updated,
      'skipped' => $skipped,
      'errors' => $errors
    ];
  }
}
