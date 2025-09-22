<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/EventManagement.php';
require_admin();

// Only handle POST requests for deletion
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

require_csrf();

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if ($action !== 'delete' || $id <= 0) {
    http_response_code(400);
    exit('Invalid request');
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    EventManagement::delete($ctx, $id);
    
    // Redirect back to the referring page or admin_events.php
    $referer = $_SERVER['HTTP_REFERER'] ?? '/admin_events.php';
    
    // Parse the referer to determine appropriate redirect
    $redirectUrl = '/admin_events.php';
    if (strpos($referer, 'admin_event_edit.php') !== false) {
        $redirectUrl = '/admin_events.php';
    } elseif (strpos($referer, 'event.php') !== false) {
        $redirectUrl = '/admin_events.php';
    } elseif (strpos($referer, 'admin_event_volunteers.php') !== false) {
        $redirectUrl = '/admin_events.php';
    } elseif (strpos($referer, 'admin_event_invite.php') !== false) {
        $redirectUrl = '/admin_events.php';
    } elseif (strpos($referer, 'event_dietary_needs.php') !== false) {
        $redirectUrl = '/admin_events.php';
    } elseif (strpos($referer, 'event_compliance.php') !== false) {
        $redirectUrl = '/admin_events.php';
    }
    
    header('Location: ' . $redirectUrl . '?deleted=1');
    exit;
    
} catch (Throwable $e) {
    // Redirect back with error
    $referer = $_SERVER['HTTP_REFERER'] ?? '/admin_events.php';
    $separator = strpos($referer, '?') !== false ? '&' : '?';
    header('Location: ' . $referer . $separator . 'error=' . urlencode('Failed to delete event.'));
    exit;
}
