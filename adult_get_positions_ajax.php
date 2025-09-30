<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/LeadershipManagement.php';

require_login();
require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

header('Content-Type: application/json');

$adultId = (int)($_POST['adult_id'] ?? 0);
$me = current_user();
$ctx = UserContext::getLoggedInUserContext();

// Check permissions: admin can edit everything; adult can manage their own leadership positions
$canEdit = !empty($me['is_admin']) || ((int)($me['id'] ?? 0) === $adultId);

try {
    if (!$canEdit) {
        throw new RuntimeException('Not authorized');
    }
    
    if ($adultId <= 0) {
        throw new InvalidArgumentException('Invalid adult ID');
    }
    
    // Get pack positions
    $packPositions = LeadershipManagement::listAdultPackPositions($adultId);
    
    // Get den leader assignments
    $denAssignments = LeadershipManagement::listAdultDenLeaderAssignments($adultId);
    
    echo json_encode([
        'ok' => true,
        'pack_positions' => $packPositions,
        'den_assignments' => $denAssignments
    ]);
    
} catch (InvalidArgumentException $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Error in adult_get_positions_ajax.php: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'An unexpected error occurred']);
}
