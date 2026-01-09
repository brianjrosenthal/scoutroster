<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/EventManagement.php';
require_once __DIR__ . '/../lib/EventRegistrationFieldDataManagement.php';
require_admin();

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($eventId <= 0) {
  http_response_code(400);
  exit('Missing event_id');
}

// Load event
$event = EventManagement::findById($eventId);
if (!$event) {
  http_response_code(404);
  exit('Event not found');
}

// Get registration data for this event
$data = EventRegistrationFieldDataManagement::getRegistrationDataForEvent($eventId);
$participants = $data['participants'];
$fields = $data['fields'];

// Generate filename
$eventName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $event['name']);
$date = date('Y-m-d');
$filename = "{$eventName}_Registration_Data_{$date}.csv";

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Open output stream
$output = fopen('php://output', 'w');

// Write UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write header row
$headers = ['Last Name', 'First Name', 'Phone', 'Email'];
foreach ($fields as $field) {
  $headers[] = $field['name'];
}
fputcsv($output, $headers);

// Write data rows
foreach ($participants as $participant) {
  $row = [
    $participant['last_name'],
    $participant['first_name'],
    $participant['phone'],
    $participant['email']
  ];
  
  foreach ($fields as $field) {
    $fieldId = (int)$field['id'];
    $row[] = $participant['field_data'][$fieldId] ?? '';
  }
  
  fputcsv($output, $row);
}

fclose($output);
exit;
