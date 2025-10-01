<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/LeadershipManagement.php';

require_admin(); // Admin-only feature

// Set JSON content type
header('Content-Type: application/json');

try {
    // Get all leadership email addresses
    $emails = LeadershipManagement::getAllLeaderEmailsForExport();
    
    echo json_encode([
        'ok' => true,
        'emails' => $emails,
        'count' => count($emails)
    ]);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to fetch leadership emails'
    ]);
}
