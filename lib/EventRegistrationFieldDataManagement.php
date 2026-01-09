<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/EventRegistrationFieldDefinitionManagement.php';
require_once __DIR__ . '/RSVPManagement.php';

final class EventRegistrationFieldDataManagement {
  private static function pdo(): \PDO {
    return pdo();
  }

  /**
   * Save or update field data for a participant
   * 
   * @param int $fieldDefId Field definition ID
   * @param string $participantType 'youth' or 'adult'
   * @param int $participantId Youth ID or User ID
   * @param string|null $value The value to store
   * @return bool Success
   */
  public static function saveFieldData(int $fieldDefId, string $participantType, int $participantId, ?string $value): bool {
    if (!in_array($participantType, ['youth', 'adult'], true)) {
      throw new \InvalidArgumentException('Invalid participant type');
    }

    // Use INSERT ... ON DUPLICATE KEY UPDATE for upsert behavior
    $sql = "INSERT INTO event_registration_field_data 
      (event_registration_field_definition_id, participant_type, participant_id, value)
      VALUES (?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP";
    
    $st = self::pdo()->prepare($sql);
    return $st->execute([$fieldDefId, $participantType, $participantId, $value]);
  }

  /**
   * Get all field data for a list of participants
   * 
   * @param array $participants Array of ['type' => 'youth'|'adult', 'id' => int]
   * @return array Indexed by "type_id" => value
   */
  public static function getFieldDataForParticipants(array $participants): array {
    if (empty($participants)) {
      return [];
    }

    $conditions = [];
    $params = [];
    
    foreach ($participants as $p) {
      $type = $p['type'] ?? '';
      $id = (int)($p['id'] ?? 0);
      
      if (!in_array($type, ['youth', 'adult'], true) || $id <= 0) {
        continue;
      }
      
      $conditions[] = '(participant_type = ? AND participant_id = ?)';
      $params[] = $type;
      $params[] = $id;
    }
    
    if (empty($conditions)) {
      return [];
    }
    
    $sql = "SELECT event_registration_field_definition_id, participant_type, participant_id, value
            FROM event_registration_field_data
            WHERE " . implode(' OR ', $conditions);
    
    $st = self::pdo()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    
    // Index by field_def_id_type_id for easy lookup
    $result = [];
    foreach ($rows as $row) {
      $key = $row['event_registration_field_definition_id'] . '_' . $row['participant_type'] . '_' . $row['participant_id'];
      $result[$key] = $row['value'];
    }
    
    return $result;
  }

  /**
   * Check if all required fields are filled for participants of an event
   * 
   * @param int $eventId Event ID
   * @param array $participants Array of ['type' => 'youth'|'adult', 'id' => int]
   * @return array ['complete' => bool, 'missing' => array of field names]
   */
  public static function checkRequiredFieldsComplete(int $eventId, array $participants): array {
    // Get all required field definitions for this event
    $allFields = EventRegistrationFieldDefinitionManagement::listForEvent($eventId);
    $requiredFields = array_filter($allFields, function($f) {
      return (int)$f['required'] === 1;
    });
    
    if (empty($requiredFields)) {
      return ['complete' => true, 'missing' => []];
    }
    
    // Get existing data for these participants
    $existingData = self::getFieldDataForParticipants($participants);
    
    $missing = [];
    
    foreach ($requiredFields as $field) {
      $fieldId = (int)$field['id'];
      $scope = $field['scope'];
      
      // Check which participants need to provide this field
      $relevantParticipants = [];
      if ($scope === 'per_person') {
        $relevantParticipants = $participants;
      } elseif ($scope === 'per_youth') {
        $relevantParticipants = array_filter($participants, function($p) {
          return $p['type'] === 'youth';
        });
      }
      // Note: per_family not yet implemented
      
      // Check if all relevant participants have provided data
      foreach ($relevantParticipants as $p) {
        $key = $fieldId . '_' . $p['type'] . '_' . $p['id'];
        $value = $existingData[$key] ?? null;
        
        // Consider empty string as missing
        if ($value === null || trim($value) === '') {
          $missing[] = $field['name'];
          break; // Only report field once even if multiple participants missing
        }
      }
    }
    
    return [
      'complete' => empty($missing),
      'missing' => array_unique($missing)
    ];
  }

  /**
   * Get completion status for a user's RSVP to an event
   * Checks if all required fields are filled for participants who RSVP'd yes
   * 
   * @param int $eventId Event ID
   * @param int $userId User ID (to find their RSVP)
   * @return array ['complete' => bool, 'missing' => array of field names, 'hasFields' => bool]
   */
  public static function getCompletionStatusForUserRSVP(int $eventId, int $userId): array {
    // Check if event has any registration fields
    $fields = EventRegistrationFieldDefinitionManagement::listForEvent($eventId);
    if (empty($fields)) {
      return ['complete' => true, 'missing' => [], 'hasFields' => false];
    }
    
    // Get user's RSVP
    $rsvp = RSVPManagement::getRSVPForFamilyByAdultID($eventId, $userId);
    if (!$rsvp || strtolower((string)($rsvp['answer'] ?? '')) !== 'yes') {
      return ['complete' => true, 'missing' => [], 'hasFields' => true];
    }
    
    // Get participants from RSVP
    $rsvpId = (int)$rsvp['id'];
    $memberIds = RSVPManagement::getMemberIdsByType($rsvpId);
    
    $participants = [];
    foreach ($memberIds['adult_ids'] ?? [] as $adultId) {
      $participants[] = ['type' => 'adult', 'id' => $adultId];
    }
    foreach ($memberIds['youth_ids'] ?? [] as $youthId) {
      $participants[] = ['type' => 'youth', 'id' => $youthId];
    }
    
    if (empty($participants)) {
      return ['complete' => true, 'missing' => [], 'hasFields' => true];
    }
    
    $status = self::checkRequiredFieldsComplete($eventId, $participants);
    $status['hasFields'] = true;
    
    return $status;
  }

  /**
   * Delete all field data for a participant
   * 
   * @param string $participantType 'youth' or 'adult'
   * @param int $participantId Youth ID or User ID
   * @return int Number of rows deleted
   */
  public static function deleteForParticipant(string $participantType, int $participantId): int {
    if (!in_array($participantType, ['youth', 'adult'], true)) {
      throw new \InvalidArgumentException('Invalid participant type');
    }
    
    $sql = "DELETE FROM event_registration_field_data 
            WHERE participant_type = ? AND participant_id = ?";
    
    $st = self::pdo()->prepare($sql);
    $st->execute([$participantType, $participantId]);
    
    return (int)$st->rowCount();
  }

  /**
   * Check if any registration data exists for an event
   * 
   * @param int $eventId Event ID
   * @return bool True if any data exists
   */
  public static function hasDataForEvent(int $eventId): bool {
    $sql = "SELECT 1 FROM event_registration_field_data erfd
            INNER JOIN event_registration_field_definitions erfd_def ON erfd.event_registration_field_definition_id = erfd_def.id
            WHERE erfd_def.event_id = ?
            LIMIT 1";
    
    $st = self::pdo()->prepare($sql);
    $st->execute([$eventId]);
    
    return $st->fetch() !== false;
  }

  /**
   * Get all registration data for an event (for admin viewing)
   * Returns participants who RSVP'd Yes with their registration field data
   * 
   * @param int $eventId Event ID
   * @return array ['participants' => array, 'fields' => array]
   */
  public static function getRegistrationDataForEvent(int $eventId): array {
    require_once __DIR__ . '/UserManagement.php';
    require_once __DIR__ . '/YouthManagement.php';
    
    // Get field definitions for this event
    $fieldDefs = EventRegistrationFieldDefinitionManagement::listForEvent($eventId);
    if (empty($fieldDefs)) {
      return ['participants' => [], 'fields' => []];
    }
    
    // Get all Yes RSVPs for this event directly from database
    $sql = "SELECT id FROM rsvps WHERE event_id = ? AND LOWER(answer) = 'yes'";
    $st = self::pdo()->prepare($sql);
    $st->execute([$eventId]);
    $rsvps = $st->fetchAll();
    
    $participants = [];
    
    foreach ($rsvps as $rsvp) {
      $rsvpId = (int)$rsvp['id'];
      $memberIds = RSVPManagement::getMemberIdsByType($rsvpId);
      
      // Process adults
      foreach ($memberIds['adult_ids'] ?? [] as $adultId) {
        $adult = UserManagement::findFullById($adultId);
        if ($adult) {
          $participants[] = [
            'type' => 'adult',
            'id' => $adultId,
            'rsvp_id' => $rsvpId,
            'last_name' => $adult['last_name'] ?? '',
            'first_name' => $adult['first_name'] ?? '',
            'phone' => $adult['phone_cell'] ?? $adult['phone_home'] ?? '',
            'email' => $adult['email'] ?? ''
          ];
        }
      }
      
      // Process youth
      foreach ($memberIds['youth_ids'] ?? [] as $youthId) {
        $youth = YouthManagement::findBasicById($youthId);
        if ($youth) {
          $participants[] = [
            'type' => 'youth',
            'id' => $youthId,
            'rsvp_id' => $rsvpId,
            'last_name' => $youth['last_name'] ?? '',
            'first_name' => $youth['first_name'] ?? '',
            'phone' => '',
            'email' => ''
          ];
        }
      }
    }
    
    // Get all field data for these participants
    $participantList = array_map(function($p) {
      return ['type' => $p['type'], 'id' => $p['id']];
    }, $participants);
    
    $fieldData = self::getFieldDataForParticipants($participantList);
    
    // Add field data to each participant
    foreach ($participants as &$participant) {
      $participant['field_data'] = [];
      foreach ($fieldDefs as $field) {
        $fieldId = (int)$field['id'];
        $key = $fieldId . '_' . $participant['type'] . '_' . $participant['id'];
        $participant['field_data'][$fieldId] = $fieldData[$key] ?? '';
      }
    }
    
    return [
      'participants' => $participants,
      'fields' => $fieldDefs
    ];
  }
}
