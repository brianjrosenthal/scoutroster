<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/Recommendations.php';
require_admin();

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
$sf = trim($_GET['s'] ?? 'new_active'); // new_active|new|active|joined|unsubscribed

try {
    // Get all recommendations matching the filters (no limit)
    $emails = Recommendations::exportEmails(['q' => $q, 'status' => $sf]);
    
    // Remove duplicates and sort alphabetically
    $emails = array_unique($emails);
    sort($emails);
    
    echo json_encode([
        'success' => true,
        'emails' => $emails
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch emails: ' . $e->getMessage()]);
}
