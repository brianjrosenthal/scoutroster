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

// Get field definition ID and event ID
$fieldId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$eventId = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;

if ($fieldId <= 0 || $eventId <= 0) {
  header('Location: /events.php');
  exit;
}

// Verify field exists and belongs to this event
$field = EventRegistrationFieldDefinitionManagement::findById($fieldId);
if (!$field || (int)$field['event_id'] !== $eventId) {
  header('Location: /event_registration_field_definitions/list.php?event_id=' . (int)$eventId . '&err=' . urlencode('Field definition not found.'));
  exit;
}

// Delete field definition
try {
  $count = EventRegistrationFieldDefinitionManagement::delete($ctx, $fieldId);

  if ($count > 0) {
    header('Location: /event_registration_field_definitions/list.php?event_id=' . (int)$eventId . '&msg=' . urlencode('Field definition deleted successfully.'));
  } else {
    header('Location: /event_registration_field_definitions/list.php?event_id=' . (int)$eventId . '&err=' . urlencode('Field definition could not be deleted.'));
  }
  exit;
} catch (Exception $e) {
  $errMsg = 'Failed to delete field definition: ' . $e->getMessage();
  header('Location: /event_registration_field_definitions/list.php?event_id=' . (int)$eventId . '&err=' . urlencode($errMsg));
  exit;
}
