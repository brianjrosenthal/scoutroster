<?php
require_once __DIR__.'/partials.php';
require_admin();

require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/UserContext.php';

header('Content-Type: application/json');

// Check if user is an approver (key 3 position holder)
$ctx = UserContext::getLoggedInUserContext();
if (!$ctx || !UserManagement::isApprover((int)$ctx->id)) {
  http_response_code(403);
  echo json_encode([
    'success' => false,
    'error' => 'Access restricted to key 3 positions (Cubmaster, Committee Chair, Treasurer)'
  ]);
  exit;
}

try {
  // Get upcoming events for the next 3 months
  $threeMonthsFromNow = date('Y-m-d H:i:s', strtotime('+3 months'));
  $events = EventManagement::listBetween(date('Y-m-d H:i:s'), $threeMonthsFromNow);
  
  if (empty($events)) {
    echo json_encode([
      'success' => true,
      'template' => "Sharing a list of upcoming events with RSVP links to make it easy to RSVP:\n\nNo upcoming events in the next 3 months."
    ]);
    exit;
  }
  
  // Build the template with real events
  $lines = ["Sharing a list of upcoming events with RSVP links to make it easy to RSVP:", ""];
  
  $counter = 1;
  foreach ($events as $event) {
    // Format date/time
    $dateTime = formatEventDateTime($event['starts_at'], $event['ends_at'] ?? null);
    $name = $event['name'];
    $location = !empty($event['location']) ? ', at ' . $event['location'] : '';
    $eventId = (int)$event['id'];
    
    $lines[] = "{$counter}. {$dateTime} - {$name}{$location} [RSVP Link]({link_event_{$eventId}})";
    $lines[] = "";
    $counter++;
  }
  
  $template = implode("\n", $lines);
  
  echo json_encode([
    'success' => true,
    'template' => $template
  ]);
  
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'error' => $e->getMessage()
  ]);
}

/**
 * Format a date/time range according to specific rules
 */
function formatEventDateTime(string $startsAt, ?string $endsAt): string {
  $startDT = new DateTime($startsAt);
  
  // Format start date: "October 23, 2025"
  $dateStr = $startDT->format('F j');
  
  // Format start time
  $startHour = (int)$startDT->format('G');
  $startMin = (int)$startDT->format('i');
  $startAmPm = $startDT->format('a');
  
  $startTimeStr = $startHour > 12 ? ($startHour - 12) : ($startHour == 0 ? 12 : $startHour);
  if ($startMin > 0) {
    $startTimeStr .= ':' . str_pad((string)$startMin, 2, '0', STR_PAD_LEFT);
  }
  
  // If no end time, just return start
  if (!$endsAt || trim($endsAt) === '') {
    return $dateStr . ', ' . $startTimeStr . $startAmPm;
  }
  
  $endDT = new DateTime($endsAt);
  
  // Check if same day
  $sameDay = $startDT->format('Y-m-d') === $endDT->format('Y-m-d');
  
  // Format end time
  $endHour = (int)$endDT->format('G');
  $endMin = (int)$endDT->format('i');
  $endAmPm = $endDT->format('a');
  
  $endTimeStr = $endHour > 12 ? ($endHour - 12) : ($endHour == 0 ? 12 : $endHour);
  if ($endMin > 0) {
    $endTimeStr .= ':' . str_pad((string)$endMin, 2, '0', STR_PAD_LEFT);
  }
  
  if ($sameDay) {
    // Same day: "October 23, 3-4:30pm" or "October 23, 11am-1pm"
    if ($startAmPm === $endAmPm) {
      // Same am/pm: omit from start time
      return $dateStr . ', ' . $startTimeStr . '-' . $endTimeStr . $endAmPm;
    } else {
      // Different am/pm: include both
      return $dateStr . ', ' . $startTimeStr . $startAmPm . '-' . $endTimeStr . $endAmPm;
    }
  } else {
    // Different days: "November 7, 1pm - November 8, 9am"
    $endDateStr = $endDT->format('F j');
    return $dateStr . ', ' . $startTimeStr . $startAmPm . ' - ' . $endDateStr . ', ' . $endTimeStr . $endAmPm;
  }
}
