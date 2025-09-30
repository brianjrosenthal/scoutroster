<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/LeadershipManagement.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

require_login();
require_csrf();

$response = ['ok' => false, 'error' => 'Unknown error'];

try {
    $adultId = (int)($_POST['adult_id'] ?? 0);
    $positionId = (int)($_POST['position_id'] ?? 0);
    
    if ($adultId <= 0) {
        throw new InvalidArgumentException('Invalid adult ID');
    }
    
    if ($positionId <= 0) {
        throw new InvalidArgumentException('Invalid position ID');
    }
    
    $ctx = UserContext::getLoggedInUserContext();
    
    // Assign the position
    LeadershipManagement::assignPackPosition($ctx, $adultId, $positionId);
    
    // Return updated positions for this adult
    $positions = LeadershipManagement::listAdultPackPositions($adultId);
    
    $response = [
        'ok' => true,
        'positions' => $positions,
        'message' => 'Position assigned successfully'
    ];
    
} catch (InvalidArgumentException $e) {
    $response = ['ok' => false, 'error' => $e->getMessage()];
} catch (RuntimeException $e) {
    $response = ['ok' => false, 'error' => $e->getMessage()];
} catch (Throwable $e) {
    error_log('Error in adult_assign_leadership_position_ajax.php: ' . $e->getMessage());
    $response = ['ok' => false, 'error' => 'An error occurred while assigning the position'];
}

echo json_encode($response);
