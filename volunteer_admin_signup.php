<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/Volunteers.php';
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
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to sign up others.']);
    exit;
}

// Get parameters
$eventId = (int)($_POST['event_id'] ?? 0);
$roleId = (int)($_POST['role_id'] ?? 0);
$userId = (int)($_POST['user_id'] ?? 0);
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : 'signup';
$comment = isset($_POST['comment']) ? trim((string)$_POST['comment']) : null;

if ($eventId <= 0 || $roleId <= 0 || $userId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid request parameters.']);
    exit;
}

if (!in_array($action, ['signup', 'remove'], true)) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid action.']);
    exit;
}

// Attempt to perform the requested action
try {
    if ($action === 'remove') {
        // Remove the user's signup
        Volunteers::removeSignup($roleId, $userId);
        $actionMessage = ' has been removed from the role.';
    } else {
        // Sign up the user
        Volunteers::adminSignup($eventId, $roleId, $userId, $comment);
        $actionMessage = ' has been signed up for the role.';
    }
    
    // Get updated volunteer data
    $roles = Volunteers::rolesWithCounts($eventId);
    
    // Pre-render descriptions with markdown/link formatting for JavaScript
    require_once __DIR__ . '/lib/Text.php';
    foreach ($roles as &$role) {
        $role['description_html'] = !empty($role['description']) ? Text::renderMarkup((string)$role['description']) : '';
    }
    unset($role);
    
    // Get the signed-up user's name
    $signedUpUser = UserManagement::findById($userId);
    $signedUpUserName = trim((string)($signedUpUser['first_name'] ?? '') . ' ' . (string)($signedUpUser['last_name'] ?? ''));
    
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'message' => $signedUpUserName . $actionMessage,
        'roles' => $roles,
        'user_id' => $actingUserId,
        'event_id' => $eventId,
        'csrf' => csrf_token(),
    ]);
    exit;
} catch (Throwable $e) {
    $msg = $e->getMessage() ?: 'Failed to sign up user.';
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
