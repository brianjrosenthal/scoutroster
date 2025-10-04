<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_login();

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

require_csrf();

$me = current_user();
$adultId = (int)($_POST['adult_id'] ?? 0);

if ($adultId <= 0) {
    http_response_code(400);
    exit('Invalid adult ID');
}

try {
    // Check permissions: only approvers can delete
    if (!\UserManagement::isApprover((int)($me['id'] ?? 0))) {
        throw new RuntimeException('Not authorized - approvers only.');
    }
    
    // Prevent self-deletion
    if ($adultId === (int)($me['id'] ?? 0)) {
        throw new RuntimeException('You cannot delete your own account.');
    }
    
    // Attempt to delete the adult
    $deleted = UserManagement::delete(UserContext::getLoggedInUserContext(), $adultId);
    
    if ($deleted > 0) {
        // Success - redirect to adults list with success message
        header('Location: /adults.php?deleted=1');
        exit;
    } else {
        throw new RuntimeException('Adult not found.');
    }
    
} catch (Throwable $e) {
    // Handle specific error types with meaningful messages
    $errorMessage = $e->getMessage();
    
    // Check if it's likely a foreign key constraint error
    if (strpos($errorMessage, 'FOREIGN KEY') !== false || 
        strpos($errorMessage, 'foreign key') !== false ||
        strpos($errorMessage, 'Cannot delete') !== false) {
        $errorMessage = 'Cannot delete adult: they have RSVPs or other references in the system. Remove those references first.';
    }
    
    // Redirect back to edit page with specific error message
    $encodedError = urlencode($errorMessage);
    header("Location: /adult_edit.php?id={$adultId}&err={$encodedError}");
    exit;
}
