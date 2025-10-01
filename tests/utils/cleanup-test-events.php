<?php
/**
 * Test utility to cleanup all test events
 * Deletes all events with "Test Event" in their name
 * Usage: php cleanup-test-events.php
 * Returns: JSON with cleanup results
 */

// Include the application configuration and classes
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/EventManagement.php';
require_once __DIR__ . '/../../lib/UserContext.php';
require_once __DIR__ . '/../../lib/UserManagement.php';

try {
    // Find an admin user to delete events as
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
    
    // Find all events that contain "Test Event" in their name
    $stmt = $pdo->prepare("SELECT id, name FROM events WHERE name LIKE '%Test Event%' OR name LIKE '%TEST_EVENT_%'");
    $stmt->execute();
    $testEvents = $stmt->fetchAll();
    
    $deletedEvents = [];
    $deletedCount = 0;
    
    if (empty($testEvents)) {
        echo json_encode([
            'success' => true,
            'message' => 'No test events found to cleanup',
            'deleted_count' => 0,
            'deleted_events' => []
        ]);
        exit(0);
    }
    
    // Delete each test event
    foreach ($testEvents as $event) {
        try {
            $eventId = (int)$event['id'];
            $eventName = $event['name'];
            
            // Delete the event using EventManagement
            $result = EventManagement::delete($ctx, $eventId);
            
            if ($result > 0) {
                $deletedEvents[] = [
                    'id' => $eventId,
                    'name' => $eventName
                ];
                $deletedCount++;
            }
            
        } catch (Exception $e) {
            // Log individual event deletion failures but continue with others
            error_log("Failed to delete event {$event['id']} ({$event['name']}): " . $e->getMessage());
        }
    }
    
    // Return success with cleanup results
    echo json_encode([
        'success' => true,
        'message' => "Successfully cleaned up {$deletedCount} test events",
        'deleted_count' => $deletedCount,
        'deleted_events' => $deletedEvents,
        'found_count' => count($testEvents)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit(1);
}
