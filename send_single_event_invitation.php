<?php
require_once __DIR__.'/partials.php';
require_admin();

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/EventInvitationTracking.php';
require_once __DIR__ . '/lib/UnsentEmailData.php';
require_once __DIR__ . '/lib/UserContext.php';
require_once __DIR__ . '/settings.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Set JSON response headers
header('Content-Type: application/json');

try {
    require_csrf();
    
    // Get the unsent_email_id parameter
    $unsentEmailId = isset($_POST['unsent_email_id']) ? (int)$_POST['unsent_email_id'] : 0;
    if ($unsentEmailId <= 0) {
        throw new RuntimeException('Invalid unsent_email_id');
    }
    
    // Get user context for class method calls
    $ctx = \UserContext::getLoggedInUserContext();
    if (!$ctx) {
        throw new RuntimeException('User context required');
    }
    
    // Look up the email data using class method
    $emailData = UnsentEmailData::findWithUserById($unsentEmailId);
    if (!$emailData) {
        // Use a specific error code for already processed emails to help JavaScript distinguish this case
        echo json_encode(['success' => false, 'error' => 'ALREADY_PROCESSED', 'error_message' => 'Email already sent or processed']);
        exit;
    }
    
    $eventId = (int)$emailData['event_id'];
    $userId = (int)$emailData['user_id'];
    $toEmail = trim($emailData['email']);
    $toName = trim(($emailData['first_name'] ?? '') . ' ' . ($emailData['last_name'] ?? ''));
    if ($toName === ' ') $toName = $toEmail;
    $subject = $emailData['subject'];
    $htmlBody = $emailData['body'];
    $icsContent = $emailData['ics_content'] ?? '';
    
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid email address: ' . $toEmail);
    }
    
    // Send the email with or without ICS attachment
    if ($icsContent) {
        $result = send_email_with_ics_detailed($toEmail, $subject, $htmlBody, $icsContent, 'event_'.$eventId.'.ics', $toName);
    } else {
        // Fallback to regular email if no ICS content
        $success = send_email($toEmail, $subject, $htmlBody, $toName);
        $result = ['success' => $success, 'error' => $success ? null : 'Email send failed'];
    }
    
    // Update the database with the result using class methods
    if ($result['success']) {
        UnsentEmailData::markAsSent($ctx, $unsentEmailId);
        
        // Record invitation tracking for event invitations
        try {
            EventInvitationTracking::recordInvitationSent($eventId, $userId);
        } catch (Throwable $trackingError) {
            // Don't fail the whole process if tracking fails, but log it
            error_log("Failed to record invitation tracking for user $userId, event $eventId: " . $trackingError->getMessage());
        }
        
        echo json_encode(['success' => true, 'error' => null]);
    } else {
        $error = $result['error'] ?? 'Unknown error';
        UnsentEmailData::markAsFailed($ctx, $unsentEmailId, $error);
        
        echo json_encode(['success' => false, 'error' => $error]);
    }
    
} catch (Throwable $e) {
    // Log the error
    error_log("send_single_event_invitation.php error: " . $e->getMessage());
    
    // If we have an unsent_email_id and context, try to update it as failed using class method
    if (!empty($unsentEmailId) && isset($ctx)) {
        try {
            UnsentEmailData::markAsFailed($ctx, $unsentEmailId, $e->getMessage());
        } catch (Throwable $updateError) {
            // Don't let database errors break the response
            error_log("Failed to update unsent_email_data: " . $updateError->getMessage());
        }
    }
    
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
