<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

final class EventRegistrationFieldDefinitionManagement {
  private static function pdo(): \PDO {
    return pdo();
  }

  private static function str(?string $v): ?string {
    if ($v === null) return null;
    $v = trim($v);
    return $v === '' ? null : $v;
  }

  private static function boolInt($v): int {
    return !empty($v) ? 1 : 0;
  }

  private static function assertAdmin(?\UserContext $ctx): void {
    if (!$ctx) throw new \RuntimeException('Login required');
    if (!$ctx->admin) throw new \RuntimeException('Admins only');
  }

  private static function log(string $action, ?int $fieldDefId, array $meta = []): void {
    try {
      $ctx = \UserContext::getLoggedInUserContext();
      if ($fieldDefId !== null && !array_key_exists('field_def_id', $meta)) {
        $meta['field_def_id'] = (int)$fieldDefId;
      }
      \ActivityLog::log($ctx, $action, $meta);
    } catch (\Throwable $e) {
      // swallow
    }
  }

  /**
   * Validate and parse option_list JSON for select fields
   * 
   * @param string|null $optionList JSON string
   * @return string|null Validated JSON string or null
   * @throws \InvalidArgumentException if JSON is invalid
   */
  private static function validateOptionList(?string $optionList): ?string {
    if ($optionList === null || trim($optionList) === '') {
      return null;
    }

    $optionList = trim($optionList);
    $decoded = json_decode($optionList, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \InvalidArgumentException('Option list must be valid JSON: ' . json_last_error_msg());
    }

    if (!is_array($decoded)) {
      throw new \InvalidArgumentException('Option list must be a JSON array');
    }

    if (empty($decoded)) {
      throw new \InvalidArgumentException('Option list cannot be empty for select fields');
    }

    // Re-encode to ensure consistent formatting
    return json_encode($decoded);
  }

  /**
   * Validate scope value
   */
  private static function validateScope(string $scope): void {
    $validScopes = ['per_person', 'per_youth', 'per_family'];
    if (!in_array($scope, $validScopes, true)) {
      throw new \InvalidArgumentException('Invalid scope. Must be one of: ' . implode(', ', $validScopes));
    }
  }

  /**
   * Validate field_type value
   */
  private static function validateFieldType(string $fieldType): void {
    $validTypes = ['text', 'select', 'boolean', 'numeric'];
    if (!in_array($fieldType, $validTypes, true)) {
      throw new \InvalidArgumentException('Invalid field type. Must be one of: ' . implode(', ', $validTypes));
    }
  }

  // =========================
  // Reads
  // =========================

  /**
   * Get all field definitions for an event, ordered by sequence_number
   */
  public static function listForEvent(int $eventId): array {
    $st = self::pdo()->prepare(
      'SELECT * FROM event_registration_field_definitions 
       WHERE event_id = ? 
       ORDER BY sequence_number ASC, id ASC'
    );
    $st->execute([$eventId]);
    return $st->fetchAll() ?: [];
  }

  /**
   * Find a field definition by ID
   */
  public static function findById(int $id): ?array {
    $st = self::pdo()->prepare('SELECT * FROM event_registration_field_definitions WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  /**
   * Get the highest sequence number for an event (for defaulting new fields)
   */
  public static function getMaxSequenceNumber(int $eventId): int {
    $st = self::pdo()->prepare(
      'SELECT COALESCE(MAX(sequence_number), 0) AS max_seq 
       FROM event_registration_field_definitions 
       WHERE event_id = ?'
    );
    $st->execute([$eventId]);
    $row = $st->fetch();
    return (int)($row['max_seq'] ?? 0);
  }

  // =========================
  // Writes (Admin-only)
  // =========================

  /**
   * Create a new field definition
   * 
   * Data keys:
   * - event_id (required, int)
   * - scope (required, 'per_person'|'per_youth'|'per_family')
   * - name (required, string)
   * - field_type (required, 'text'|'select'|'boolean')
   * - required (bool, default false)
   * - option_list (string, JSON array for select fields)
   * - sequence_number (int, default 0)
   */
  public static function create(\UserContext $ctx, array $data): int {
    self::assertAdmin($ctx);

    // Required fields
    $eventId = isset($data['event_id']) ? (int)$data['event_id'] : 0;
    $scope = self::str((string)($data['scope'] ?? ''));
    $name = self::str((string)($data['name'] ?? ''));
    $fieldType = self::str((string)($data['field_type'] ?? ''));

    if ($eventId <= 0) {
      throw new \InvalidArgumentException('Valid event_id is required.');
    }
    if (!$name) {
      throw new \InvalidArgumentException('Name is required.');
    }
    if (!$scope) {
      throw new \InvalidArgumentException('Scope is required.');
    }
    if (!$fieldType) {
      throw new \InvalidArgumentException('Field type is required.');
    }

    // Validate enums
    self::validateScope($scope);
    self::validateFieldType($fieldType);

    // Optional fields
    $required = self::boolInt($data['required'] ?? 0);
    $sequenceNumber = isset($data['sequence_number']) ? (int)$data['sequence_number'] : 0;
    
    // Handle option_list
    $optionList = self::str($data['option_list'] ?? null);
    
    // Validate option_list is required for select fields
    if ($fieldType === 'select') {
      if ($optionList === null) {
        throw new \InvalidArgumentException('Option list is required for select fields.');
      }
      $optionList = self::validateOptionList($optionList);
    } else {
      // Non-select fields shouldn't have option_list
      $optionList = null;
    }

    $sql = "INSERT INTO event_registration_field_definitions
      (event_id, scope, name, field_type, required, option_list, sequence_number, created_by)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $st = self::pdo()->prepare($sql);
    $ok = $st->execute([
      $eventId, $scope, $name, $fieldType, $required, $optionList, $sequenceNumber, $ctx->id
    ]);
    
    if (!$ok) {
      throw new \RuntimeException('Failed to create event registration field definition.');
    }
    
    $id = (int)self::pdo()->lastInsertId();
    
    self::log('event_registration_field_def.create', $id, [
      'event_id' => $eventId,
      'name' => $name,
      'field_type' => $fieldType,
      'scope' => $scope
    ]);
    
    return $id;
  }

  /**
   * Update an existing field definition
   * 
   * Data keys (provide only keys to update):
   * - name, scope, field_type, required, option_list, sequence_number
   */
  public static function update(\UserContext $ctx, int $id, array $data): bool {
    self::assertAdmin($ctx);

    $allowed = ['name', 'scope', 'field_type', 'required', 'option_list', 'sequence_number'];
    $set = [];
    $params = [];

    // First, get the current field to check field_type for option_list validation
    $current = self::findById($id);
    if (!$current) {
      throw new \RuntimeException('Field definition not found.');
    }
    
    $newFieldType = $data['field_type'] ?? $current['field_type'];

    foreach ($allowed as $key) {
      if (!array_key_exists($key, $data)) continue;

      if ($key === 'required') {
        $set[] = "$key = ?";
        $params[] = self::boolInt($data[$key] ?? 0);
      } elseif ($key === 'sequence_number') {
        $set[] = "$key = ?";
        $params[] = (int)($data[$key] ?? 0);
      } elseif ($key === 'scope') {
        $scope = self::str((string)$data[$key]);
        if ($scope) {
          self::validateScope($scope);
          $set[] = "$key = ?";
          $params[] = $scope;
        }
      } elseif ($key === 'field_type') {
        $fieldType = self::str((string)$data[$key]);
        if ($fieldType) {
          self::validateFieldType($fieldType);
          $set[] = "$key = ?";
          $params[] = $fieldType;
        }
      } elseif ($key === 'option_list') {
        $optionList = self::str($data[$key]);
        
        // If field is (or will be) select type, validate option_list
        if ($newFieldType === 'select') {
          if ($optionList === null && !isset($set[array_search('field_type = ?', $set)])) {
            // Only validate if we're not changing field_type away from select
            if ($current['field_type'] === 'select') {
              throw new \InvalidArgumentException('Option list is required for select fields.');
            }
          } elseif ($optionList !== null) {
            $optionList = self::validateOptionList($optionList);
          }
        } else {
          // Non-select fields should have null option_list
          $optionList = null;
        }
        
        $set[] = "$key = ?";
        $params[] = $optionList;
      } else {
        $set[] = "$key = ?";
        $params[] = self::str($data[$key]);
      }
    }

    if (empty($set)) {
      return false;
    }

    $params[] = $id;

    $sql = "UPDATE event_registration_field_definitions SET " . implode(', ', $set) . " WHERE id = ?";
    $st = self::pdo()->prepare($sql);
    $ok = $st->execute($params);

    if ($ok) {
      $fields = [];
      foreach ($set as $s) {
        $pos = strpos($s, ' = ');
        $fields[] = ($pos !== false) ? substr($s, 0, $pos) : $s;
      }
      self::log('event_registration_field_def.update', $id, ['fields' => $fields]);
    }

    return $ok;
  }

  /**
   * Delete a field definition
   */
  public static function delete(\UserContext $ctx, int $id): int {
    self::assertAdmin($ctx);
    
    // Get field info for logging before deletion
    $field = self::findById($id);
    
    $st = self::pdo()->prepare('DELETE FROM event_registration_field_definitions WHERE id = ?');
    $st->execute([$id]);
    $count = (int)$st->rowCount();
    
    if ($count > 0 && $field) {
      self::log('event_registration_field_def.delete', $id, [
        'event_id' => (int)$field['event_id'],
        'name' => $field['name']
      ]);
    }
    
    return $count;
  }
}
