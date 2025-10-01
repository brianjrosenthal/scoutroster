<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/LeadershipManagement.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/GradeCalculator.php';

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
    
    // Check if user is an admin
    if (!$ctx || !$ctx->admin) {
        throw new RuntimeException('Admins only');
    }
    
    // Get adult details for response message
    $adult = UserManagement::findById($adultId);
    if (!$adult) {
        throw new InvalidArgumentException('Adult not found');
    }
    
    $adultName = trim($adult['first_name'] . ' ' . $adult['last_name']);
    $gradeLabel = GradeCalculator::gradeLabel($grade);
    
    // Assign the den leader position using LeadershipManagement
    LeadershipManagement::assignDenLeader($ctx, $adultId, $grade);
    
    $response = [
        'ok' => true,
        'message' => "$adultName has been assigned as den leader for $gradeLabel",
        'adult_id' => $adultId,
        'grade' => $grade
    ];
    
} catch (InvalidArgumentException $e) {
    $response = ['ok' => false, 'error' => $e->getMessage()];
} catch (RuntimeException $e) {
    $response = ['ok' => false, 'error' => $e->getMessage()];
} catch (Throwable $e) {
    error_log('Error in den_leader_assign_by_grade_ajax.php: ' . $e->getMessage());
    $response = ['ok' => false, 'error' => 'An error occurred while assigning den leader'];
}

echo json_encode($response);
