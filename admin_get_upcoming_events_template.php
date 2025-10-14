<?php
require_once __DIR__.'/partials.php';
require_admin();

require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/EmailSnippets.php';
require_once __DIR__ . '/lib/UserContext.php';

header('Content-Type: application/json');

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
        // Format date/time using the same logic as EmailSnippets
        $dateTime = EmailSnippets::formatEventDateTime($event['starts_at'], $event['ends_at'] ?? null);
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
