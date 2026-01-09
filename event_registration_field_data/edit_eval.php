<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/EventManagement.php';
require_once __DIR__ . '/../lib/EventRegistrationFieldDefinitionManagement.php';
require_once __DIR__ . '/../lib/EventRegistrationFieldDataManagement.php';
require_once __DIR__ . '/../lib/ParentRelationships.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

require_csrf();

$me = current_user();
$isAdmin = !empty($me['is_admin']);
$eventId = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;

if ($eventId <= 0) {
  header('Location: /events.php');
  exit;
}

// Verify event exists
$event = EventManagement::findById($eventId);
if (!$event) {
  header('Location: /events.php');
  exit;
}

try {
  // First, collect all participant IDs being edited to check authorization
  $participantsBeingEdited = [];
  foreach ($_POST as $key => $value) {
    if (!str_starts_with($key, 'field_')) {
      continue;
    }
    
    $parts = explode('_', $key);
    if (count($parts) !== 4) {
      continue;
    }
    
    list($prefix, $fieldDefId, $participantType, $participantId) = $parts;
    $participantId = (int)$participantId;
    
    if (!in_array($participantType, ['youth', 'adult'], true)) {
      continue;
    }
    
    $key = $participantType . '_' . $participantId;
    if (!isset($participantsBeingEdited[$key])) {
      $participantsBeingEdited[$key] = [
        'type' => $participantType,
        'id' => $participantId
      ];
    }
  }
  
  // Check authorization: admin OR all participants are in user's family
  if (!$isAdmin) {
    $userId = (int)$me['id'];
    
    // Get user's family members
    $myChildren = ParentRelationships::listChildrenForAdult($userId);
    $myChildIds = array_map(function($child) {
      return (int)$child['id'];
    }, $myChildren);
    
    $coParents = ParentRelationships::listCoParentsForAdult($userId);
    $coParentIds = array_map(function($parent) {
      return (int)$parent['id'];
    }, $coParents);
    
    // Check if all participants being edited are authorized
    foreach ($participantsBeingEdited as $participant) {
      $isAuthorized = false;
      
      if ($participant['type'] === 'adult') {
        // Allow if it's the user themselves or a co-parent
        if ($participant['id'] === $userId || in_array($participant['id'], $coParentIds)) {
          $isAuthorized = true;
        }
      } elseif ($participant['type'] === 'youth') {
        // Allow if it's one of the user's children
        if (in_array($participant['id'], $myChildIds)) {
          $isAuthorized = true;
        }
      }
      
      if (!$isAuthorized) {
        $errMsg = 'You are not authorized to edit this registration data.';
        header('Location: /event_registration_field_data/edit.php?event_id=' . $eventId . '&err=' . urlencode($errMsg));
        exit;
      }
    }
  }
  
  // Process all field inputs
  // Field inputs are named: field_{fieldDefId}_{participantType}_{participantId}
  foreach ($_POST as $key => $value) {
    if (!str_starts_with($key, 'field_')) {
      continue;
    }
    
    // Parse the field name
    $parts = explode('_', $key);
    if (count($parts) !== 4) {
      continue;
    }
    
    list($prefix, $fieldDefId, $participantType, $participantId) = $parts;
    $fieldDefId = (int)$fieldDefId;
    $participantId = (int)$participantId;
    
    if (!in_array($participantType, ['youth', 'adult'], true)) {
      continue;
    }
    
    // Verify field definition exists
    $fieldDef = EventRegistrationFieldDefinitionManagement::findById($fieldDefId);
    if (!$fieldDef) {
      continue;
    }
    
    // Verify field belongs to this event
    if ((int)$fieldDef['event_id'] !== $eventId) {
      continue;
    }
    
    // Handle checkbox (boolean) fields - they won't be in $_POST if unchecked
    if ($fieldDef['field_type'] === 'boolean') {
      $value = isset($_POST[$key]) ? '1' : '0';
    } else {
      $value = trim((string)$value);
    }
    
    // Validate numeric fields
    if ($fieldDef['field_type'] === 'numeric' && $value !== '') {
      if (!is_numeric($value)) {
        $fieldName = $fieldDef['name'];
        throw new \RuntimeException("Field '{$fieldName}' must be numeric.");
      }
    }
    
    // Validate required fields
    if ((int)$fieldDef['required'] === 1 && $value === '') {
      $fieldName = $fieldDef['name'];
      throw new \RuntimeException("Field '{$fieldName}' is required.");
    }
    
    // Save the data (empty string stored as NULL for non-required fields)
    $valueToStore = ($value === '' ? null : $value);
    EventRegistrationFieldDataManagement::saveFieldData(
      $fieldDefId,
      $participantType,
      $participantId,
      $valueToStore
    );
  }
  
  // Success - redirect back to event page
  header('Location: /event.php?id=' . $eventId . '&msg=' . urlencode('Registration data saved successfully.'));
  exit;
  
} catch (\Exception $e) {
  $errMsg = 'Failed to save registration data: ' . $e->getMessage();
  header('Location: /event_registration_field_data/edit.php?event_id=' . $eventId . '&err=' . urlencode($errMsg));
  exit;
}
