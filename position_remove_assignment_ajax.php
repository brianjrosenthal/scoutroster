<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/LeadershipManagement.php';
require_once __DIR__ . '/lib/UserManagement.php';

require_login();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

header('Content-Type: application/json');

$positionId = (int)($_POST['position_id'] ?? 0);
$adultId = (int)($_POST['adult_id'] ?? 0);
$ctx = UserContext::getLoggedInUserContext();

try {
    // Check if user is an approver
    if (!UserManagement::isApprover((int)$ctx->id)) {
        throw new RuntimeException('Not authorized - approvers only');
    }
    
    if ($positionId <= 0) {
        throw new InvalidArgumentException('Invalid position ID');
    }
    
    if ($adultId <= 0) {
        throw new InvalidArgumentException('Invalid adult ID');
    }
    
    // Get position and adult names for logging/messages
    $position = LeadershipManagement::getPackPosition($positionId);
    if (!$position) {
        throw new InvalidArgumentException('Position not found');
    }
    
    $adult = UserManagement::findById($adultId);
    if (!$adult) {
        throw new InvalidArgumentException('Adult not found');
    }
    
    $adultName = trim($adult['first_name'] . ' ' . $adult['last_name']);
    
    // Remove the position assignment using LeadershipManagement
    LeadershipManagement::removePackPosition($ctx, $adultId, $positionId);
    
    echo json_encode([
        'ok' => true,
        'message' => "$adultName has been removed from {$position['name']}"
    ]);
    
} catch (InvalidArgumentException $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Error in position_remove_assignment_ajax.php: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'An unexpected error occurred']);
}
