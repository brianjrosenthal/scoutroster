<?php
/**
 * Test utility to delete a test event via PHP
 * Usage: php delete-test-event.php <event_id>
 * Returns: JSON with success or error
 */

// Include the application configuration and classes
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/EventManagement.php';
require_once __DIR__ . '/../../lib/UserContext.php';
require_once __DIR__ . '/../../lib/UserManagement.php';

// Get event ID from command line argument
if ($argc < 2) {
    echo json_encode(['error' => 'Event ID required. Usage: php delete-test-event.php <event_id>']);
    exit(1);
}

$eventId = (int)$argv[1];
if ($eventId <= 0) {
    echo json_encode(['error' => 'Valid event ID required']);
    exit(1);
}

try {
    // Find an admin user to delete the event as
    $pdo = pdo();
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE is_admin = 1 LIMIT 1");
    $stmt->execute();
    $admin = $stmt->fetch();
    
    if (!$admin) {
        echo json_encode(['error' => 'No admin user found']);
        exit(1);
    }
    
    // Create a UserContext for the admin
    $ctx = new UserContext(
        (int)$admin['id'],
        $admin['email'],
        true, // isAdmin
        false // isImpersonating (not used here)
    );
    
    // Check if event exists and is a test event (safety check)
    $event = EventManagement::findById($eventId);
    if (!$event) {
        echo json_encode(['error' => 'Event not found']);
        exit(1);
    }
    
    // Safety check: only delete events that start with TEST_EVENT_
    if (!str_starts_with($event['name'], 'TEST_EVENT_')) {
        echo json_encode(['error' => 'Can only delete test events (name must start with TEST_EVENT_)']);
        exit(1);
    }
    
    // Delete the event
    $deletedCount = EventManagement::delete($ctx, $eventId);
    
    // Return success
    echo json_encode([
        'success' => true,
        'deleted_count' => $deletedCount,
        'event_id' => $eventId,
        'event_name' => $event['name']
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit(1);
}
