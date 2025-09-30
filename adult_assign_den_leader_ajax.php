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
    $grade = (int)($_POST['grade'] ?? -1);
    
    if ($adultId <= 0) {
        throw new InvalidArgumentException('Invalid adult ID');
    }
    
    if ($grade < 0 || $grade > 5) {
        throw new InvalidArgumentException('Grade must be between K (0) and 5');
    }
    
    $ctx = UserContext::getLoggedInUserContext();
    
    // Assign the den leader position
    LeadershipManagement::assignDenLeader($ctx, $adultId, $grade);
    
    // Return updated den leader assignments for this adult
    $denAssignments = LeadershipManagement::listAdultDenLeaderAssignments($adultId);
    
    $response = [
        'ok' => true,
        'den_assignments' => $denAssignments,
        'message' => 'Den leader assignment added successfully'
    ];
    
} catch (InvalidArgumentException $e) {
    $response = ['ok' => false, 'error' => $e->getMessage()];
} catch (RuntimeException $e) {
    $response = ['ok' => false, 'error' => $e->getMessage()];
} catch (Throwable $e) {
    error_log('Error in adult_assign_den_leader_ajax.php: ' . $e->getMessage());
    $response = ['ok' => false, 'error' => 'An error occurred while assigning den leader'];
}

echo json_encode($response);
