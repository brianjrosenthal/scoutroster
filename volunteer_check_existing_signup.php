<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/UserManagement.php';

// Accept POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// Require logged-in user
require_login();
require_csrf();

$me = current_user();
$actingUserId = (int)($me['id'] ?? 0);

// Check if user has Key 3 permissions
$isKey3 = false;
try {
    $stPos = pdo()->prepare("SELECT LOWER(alp.name) AS p 
                             FROM adult_leadership_position_assignments alpa
                             JOIN adult_leadership_positions alp ON alp.id = alpa.adult_leadership_position_id
                             WHERE alpa.adult_id = ?");
    $stPos->execute([$actingUserId]);
    $rowsPos = $stPos->fetchAll();
    if (is_array($rowsPos)) {
        foreach ($rowsPos as $pr) {
            $p = trim((string)($pr['p'] ?? ''));
            if ($p === 'cubmaster' || $p === 'treasurer' || $p === 'committee chair') { 
                $isKey3 = true;
                break; 
            }
        }
    }
} catch (Throwable $e) {
    $isKey3 = false;
}

if (!$isKey3) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to check signups.']);
    exit;
}

// Get parameters
$eventId = (int)($_POST['event_id'] ?? 0);
$roleId = (int)($_POST['role_id'] ?? 0);
$userId = (int)($_POST['user_id'] ?? 0);

if ($eventId <= 0 || $roleId <= 0 || $userId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid request parameters.']);
    exit;
}

// Check if signup exists
try {
    $st = pdo()->prepare("
        SELECT comment 
        FROM volunteer_signups 
        WHERE event_id = ? AND role_id = ? AND user_id = ?
        LIMIT 1
    ");
    $st->execute([$eventId, $roleId, $userId]);
    $signup = $st->fetch();
    
    if ($signup) {
        // Signup exists
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'exists' => true,
            'comment' => (string)($signup['comment'] ?? '')
        ]);
    } else {
        // No signup exists
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'exists' => false,
            'comment' => ''
        ]);
    }
    exit;
} catch (Throwable $e) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Failed to check signup: ' . $e->getMessage()]);
    exit;
}
