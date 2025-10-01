<?php
/**
 * Test utility to create a test event via PHP
 * Usage: php create-test-event.php
 * Returns: JSON with event_id or error
 */

// Include the application configuration and classes
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/EventManagement.php';
require_once __DIR__ . '/../../lib/UserContext.php';
require_once __DIR__ . '/../../lib/UserManagement.php';

try {
    // Find an admin user to create the event as
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
    
    // Generate unique test event data
    $timestamp = time();
    $eventData = [
        'name' => 'TEST_EVENT_' . $timestamp,
        'starts_at' => date('Y-m-d H:i:s', strtotime('+3 days')), // 3 days from now
        'ends_at' => date('Y-m-d H:i:s', strtotime('+3 days +2 hours')), // 2 hours later
        'location' => 'Test Location for Automated Testing',
        'location_address' => '123 Test Street, Test City, NY 12345',
        'description' => 'This is a test event created by automated testing. It should be cleaned up automatically.',
        'allow_non_user_rsvp' => 1, // Enable public RSVP
        'needs_medical_form' => 0,
        'evite_rsvp_url' => null, // No Evite URL so we see internal RSVP buttons
        'google_maps_url' => null,
        'max_cub_scouts' => null
    ];
    
    // Create the event
    $eventId = EventManagement::create($ctx, $eventData);
    
    // Return success with event ID
    echo json_encode([
        'success' => true,
        'event_id' => $eventId,
        'event_name' => $eventData['name'],
        'starts_at' => $eventData['starts_at']
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit(1);
}
