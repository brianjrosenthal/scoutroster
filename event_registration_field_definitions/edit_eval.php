<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/EventManagement.php';
require_once __DIR__ . '/../lib/EventRegistrationFieldDefinitionManagement.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

require_csrf();

$ctx = UserContext::getLoggedInUserContext();
if (!$ctx || !$ctx->admin) {
  http_response_code(403);
  exit('Admin access required');
}

// Get field definition ID
$fieldId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($fieldId <= 0) {
  header('Location: /events.php');
  exit;
}

// Load field definition
$field = EventRegistrationFieldDefinitionManagement::findById($fieldId);
if (!$field) {
  header('Location: /events.php');
  exit;
}

$eventId = (int)$field['event_id'];

// Verify event exists
$event = EventManagement::findById($eventId);
if (!$event) {
  header('Location: /events.php');
  exit;
}

// Collect form data
$name = trim($_POST['name'] ?? '');
$scope = trim($_POST['scope'] ?? '');
$fieldType = trim($_POST['field_type'] ?? '');
$required = isset($_POST['required']) ? 1 : 0;
$optionList = trim($_POST['option_list'] ?? '');
$sequenceNumber = isset($_POST['sequence_number']) ? (int)$_POST['sequence_number'] : 0;

// Validate
$errors = [];

if ($name === '') {
  $errors[] = 'Field name is required.';
}

if ($scope === '') {
  $errors[] = 'Scope is required.';
} elseif (!in_array($scope, ['per_person', 'per_youth', 'per_family'])) {
  $errors[] = 'Invalid scope value.';
}

if ($fieldType === '') {
  $errors[] = 'Field type is required.';
} elseif (!in_array($fieldType, ['text', 'select', 'boolean'])) {
  $errors[] = 'Invalid field type value.';
}

// Validate option_list for select fields
if ($fieldType === 'select') {
  if ($optionList === '') {
    $errors[] = 'Options are required for select fields.';
  } else {
    // Try to decode JSON
    $decoded = json_decode($optionList, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      $errors[] = 'Options must be valid JSON: ' . json_last_error_msg();
    } elseif (!is_array($decoded)) {
      $errors[] = 'Options must be a JSON array.';
    } elseif (empty($decoded)) {
      $errors[] = 'Options cannot be empty for select fields.';
    }
  }
}

if (!empty($errors)) {
  $errMsg = implode(' ', $errors);
  header('Location: /event_registration_field_definitions/list.php?event_id=' . (int)$eventId . '&err=' . urlencode($errMsg));
  exit;
}

// Update field definition
try {
  $data = [
    'name' => $name,
    'scope' => $scope,
    'field_type' => $fieldType,
    'required' => $required,
    'option_list' => $optionList !== '' ? $optionList : null,
    'sequence_number' => $sequenceNumber,
  ];

  $ok = EventRegistrationFieldDefinitionManagement::update($ctx, $fieldId, $data);

  if ($ok) {
    header('Location: /event_registration_field_definitions/list.php?event_id=' . (int)$eventId . '&msg=' . urlencode('Field definition updated successfully.'));
  } else {
    header('Location: /event_registration_field_definitions/list.php?event_id=' . (int)$eventId . '&err=' . urlencode('No changes were made.'));
  }
  exit;
} catch (Exception $e) {
  $errMsg = 'Failed to update field definition: ' . $e->getMessage();
  header('Location: /event_registration_field_definitions/list.php?event_id=' . (int)$eventId . '&err=' . urlencode($errMsg));
  exit;
}
