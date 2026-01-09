<?php
require_once __DIR__.'/partials.php';
require_admin();

require_once __DIR__ . '/lib/UserManagement.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

// Set JSON response headers
header('Content-Type: application/json');

try {
  require_csrf();
  
  $eventId = (int)($_POST['event_id'] ?? 0);
  if ($eventId <= 0) {
    throw new RuntimeException('Missing or invalid event_id');
  }
  
  $filters = [
    'registration_status' => $_POST['registration_status'] ?? 'all',
    'grades' => $_POST['grades'] ?? [],
    'rsvp_status' => $_POST['rsvp_status'] ?? 'all',
    'event_id' => $eventId,
    'specific_adult_ids' => $_POST['specific_adult_ids'] ?? []
  ];
  
  // Normalize grades array
  if (is_string($filters['grades'])) {
    $filters['grades'] = explode(',', $filters['grades']);
  }
  $filters['grades'] = array_filter(array_map('intval', (array)$filters['grades']));
  
  // Normalize specific adult IDs
  if (is_string($filters['specific_adult_ids'])) {
    $filters['specific_adult_ids'] = explode(',', $filters['specific_adult_ids']);
  }
  $filters['specific_adult_ids'] = array_filter(array_map('intval', (array)$filters['specific_adult_ids']));
  
  // Get recipients using filter system
  $recipients = UserManagement::listAdultsWithFilters($filters);
  
  // Dedupe by email and skip blanks
  $byEmail = [];
  $emails = [];
  foreach ($recipients as $r) {
    $email = strtolower(trim((string)($r['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
    if (isset($byEmail[$email])) continue;
    $byEmail[$email] = true;
    // Use original case from database
    $emails[] = trim((string)$r['email']);
  }
  
  echo json_encode([
    'success' => true,
    'emails' => $emails,
    'count' => count($emails)
  ]);
  
} catch (Throwable $e) {
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
