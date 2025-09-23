<?php
require_once __DIR__.'/partials.php';
require_admin();

require_once __DIR__ . '/lib/UserManagement.php';

// Set JSON content type
header('Content-Type: application/json');

try {
    // Get search query
    $query = trim($_GET['q'] ?? '');
    
    if ($query === '') {
        echo json_encode([]);
        exit;
    }
    
    // Minimum query length
    if (strlen($query) < 2) {
        echo json_encode([]);
        exit;
    }
    
    // Search for adults
    $adults = UserManagement::searchAdults($query, 20);
    
    // Format response for typeahead
    $results = [];
    foreach ($adults as $adult) {
        $results[] = [
            'id' => (int)$adult['id'],
            'first_name' => (string)($adult['first_name'] ?? ''),
            'last_name' => (string)($adult['last_name'] ?? ''),
            'email' => (string)($adult['email'] ?? '')
        ];
    }
    
    echo json_encode($results);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Search failed']);
}
