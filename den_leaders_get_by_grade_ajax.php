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
    $grade = (int)($_POST['grade'] ?? -1);
    
    if ($grade < 0 || $grade > 5) {
        throw new InvalidArgumentException('Grade must be between K (0) and 5');
    }
    
    $ctx = UserContext::getLoggedInUserContext();
    
    // Check if user is an admin
    if (!$ctx || !$ctx->admin) {
        throw new RuntimeException('Admins only');
    }
    
    // Convert grade to class_of for database query
    $classOf = GradeCalculator::schoolYearEndYear() + (5 - $grade);
    
    // Get den leaders for this grade
    $sql = "SELECT 
                u.id as adult_id,
                u.first_name,
                u.last_name,
                CONCAT(u.first_name, ' ', u.last_name) as name
            FROM adult_den_leader_assignments adla
            JOIN users u ON adla.adult_id = u.id
            WHERE adla.class_of = ?
            ORDER BY u.last_name, u.first_name";
    
    $st = pdo()->prepare($sql);
    $st->execute([$classOf]);
    $leaders = $st->fetchAll();
    
    $response = [
        'ok' => true,
        'leaders' => $leaders,
        'grade' => $grade,
        'class_of' => $classOf
    ];
    
} catch (InvalidArgumentException $e) {
    $response = ['ok' => false, 'error' => $e->getMessage()];
} catch (RuntimeException $e) {
    $response = ['ok' => false, 'error' => $e->getMessage()];
} catch (Throwable $e) {
    error_log('Error in den_leaders_get_by_grade_ajax.php: ' . $e->getMessage());
    $response = ['ok' => false, 'error' => 'An error occurred while loading den leaders'];
}

echo json_encode($response);
