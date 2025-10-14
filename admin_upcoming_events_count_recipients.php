<?php
require_once __DIR__.'/partials.php';
require_admin();

require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/UserContext.php';
require_once __DIR__ . '/lib/EventInvitationTracking.php';

header('Content-Type: application/json');

// Check if user is an approver (key 3 position holder)
$ctx = UserContext::getLoggedInUserContext();
if (!$ctx || !UserManagement::isApprover((int)$ctx->id)) {
  http_response_code(403);
  echo json_encode([
    'success' => false,
    'error' => 'Access restricted to key 3 positions'
  ]);
  exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode([
    'success' => false,
    'error' => 'Method not allowed'
  ]);
  exit;
}

try {
  require_csrf();
  
  // Build filters from form data (no RSVP filter, no event_id for upcoming events)
  $filters = [
    'registration_status' => $_POST['registration_status'] ?? 'all',
    'grades' => $_POST['grades'] ?? [],
    'rsvp_status' => 'all', // Always 'all' for upcoming events
    'event_id' => 0, // Not specific to one event
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

  // Get suppression policy
  $suppressPolicy = $_POST['suppress_policy'] ?? 'last_24_hours';
  
  // Dedupe by email and skip blanks, apply suppression policy
  $byEmail = [];
  $count = 0;
  foreach ($recipients as $r) {
    $email = strtolower(trim((string)($r['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
    if (isset($byEmail[$email])) continue;
    
    // Check suppression policy (NULL event_id for upcoming events digest)
    $userId = (int)$r['id'];
    if (EventInvitationTracking::shouldSuppressInvitation(null, $userId, $suppressPolicy)) {
      continue; // Skip this user
    }
    
    $byEmail[$email] = true;
    $count++;
  }
  
  echo json_encode([
    'success' => true,
    'count' => $count
  ]);
  
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'error' => $e->getMessage()
  ]);
}
