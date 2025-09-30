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
$ctx = UserContext::getLoggedInUserContext();

try {
    // Check if user is an approver
    if (!UserManagement::isApprover((int)$ctx->id)) {
        throw new RuntimeException('Not authorized - approvers only');
    }
    
    if ($positionId <= 0) {
        throw new InvalidArgumentException('Invalid position ID');
    }
    
    // Get current assignments for this position
    $sql = "SELECT u.id as adult_id, u.first_name, u.last_name, alpa.created_at
            FROM adult_leadership_position_assignments alpa
            JOIN users u ON u.id = alpa.adult_id
            WHERE alpa.adult_leadership_position_id = ?
            ORDER BY u.last_name, u.first_name";
    
    $st = pdo()->prepare($sql);
    $st->execute([$positionId]);
    $assignments = $st->fetchAll();
    
    // Format for display
    $formattedAssignments = [];
    foreach ($assignments as $assignment) {
        $formattedAssignments[] = [
            'adult_id' => (int)$assignment['adult_id'],
            'name' => trim($assignment['first_name'] . ' ' . $assignment['last_name']),
            'created_at' => $assignment['created_at']
        ];
    }
    
    echo json_encode([
        'ok' => true,
        'assignments' => $formattedAssignments
    ]);
    
} catch (InvalidArgumentException $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Error in position_get_assignments_ajax.php: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'An unexpected error occurred']);
}
