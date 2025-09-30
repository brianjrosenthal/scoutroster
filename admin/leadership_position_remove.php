<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LeadershipManagement.php';

require_login();
require_csrf();

$u = current_user();
if (empty($u['is_admin'])) {
    http_response_code(403);
    exit('Admin access required');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$positionId = (int)($_POST['position_id'] ?? 0);
$redirect = trim($_POST['redirect'] ?? '/admin/leadership_positions.php');

// Validate redirect URL to prevent open redirect
if (!str_starts_with($redirect, '/')) {
    $redirect = '/admin/leadership_positions.php';
}

$ctx = UserContext::getLoggedInUserContext();

try {
    if ($positionId <= 0) {
        throw new InvalidArgumentException('Invalid position ID');
    }

    // Get position info before deleting for success message
    $position = LeadershipManagement::getPackPosition($positionId);
    if (!$position) {
        throw new InvalidArgumentException('Position not found');
    }

    $positionName = $position['name'];

    // Delete the position (this will cascade delete all assignments)
    $deleted = LeadershipManagement::deletePackPosition($ctx, $positionId);

    if ($deleted) {
        $successMsg = "Successfully deleted the '$positionName' position and all its assignments.";
        header('Location: ' . $redirect . '?success=' . urlencode($successMsg));
        exit;
    } else {
        throw new RuntimeException('Failed to delete position');
    }

} catch (InvalidArgumentException $e) {
    $errorMsg = $e->getMessage();
    header('Location: ' . $redirect . '?error=' . urlencode($errorMsg));
    exit;
} catch (RuntimeException $e) {
    $errorMsg = $e->getMessage();
    header('Location: ' . $redirect . '?error=' . urlencode($errorMsg));
    exit;
} catch (Throwable $e) {
    error_log('Error in admin/leadership_position_remove.php: ' . $e->getMessage());
    $errorMsg = 'An unexpected error occurred while deleting the position.';
    header('Location: ' . $redirect . '?error=' . urlencode($errorMsg));
    exit;
}
