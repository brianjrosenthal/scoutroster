<?php
require_once __DIR__.'/partials.php';
require_admin();

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/UserContext.php';
require_once __DIR__ . '/lib/UnsentEmailData.php';
require_once __DIR__ . '/lib/EmailLog.php';

header('Content-Type: application/json');

// Check if user is an approver (key 3 position holder)
$ctx = UserContext::getLoggedInUserContext();
if (!$ctx || !UserManagement::isApprover((int)$ctx->id)) {
  http_response_code(403);
  echo json_encode([
    'success' => false,
    'error' => 'Access restricted to key 3 positions'
  ]);
  exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode([
    'success' => false,
    'error' => 'Method not allowed'
  ]);
  exit;
}

try {
  require_csrf();
  
  $emailId = (int)($_POST['email_id'] ?? 0);
  if ($emailId <= 0) {
    throw new RuntimeException('Invalid email ID');
  }
  
  // Fetch email data
  $emailData = UnsentEmailData::getById($emailId);
  if (!$emailData) {
    throw new RuntimeException('Email not found');
  }
  
  // Get recipient info
  $userId = (int)$emailData['user_id'];
  $user = UserManagement::findFullById($userId);
  if (!$user) {
    throw new RuntimeException('Recipient not found');
  }
  
  $recipientEmail = trim((string)($user['email'] ?? ''));
  $recipientName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
  
  if (!$recipientEmail || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException('Invalid recipient email');
  }
  
  // Send email
  $subject = $emailData['subject'];
  $htmlBody = $emailData['body'];
  
  // No ICS attachment for upcoming events emails (use send_email function)
  $sent = send_email($recipientEmail, $subject, $htmlBody, $recipientName);
  
  if ($sent) {
    // Log email send
    try {
      EmailLog::logEmail(
        $ctx,
        $recipientEmail,
        $subject,
        'upcoming_events',
        0, // No specific event
        $userId
      );
    } catch (Throwable $e) {
      // Log error but don't fail the send
      error_log('Failed to log email: ' . $e->getMessage());
    }
    
    // Delete from unsent_email_data
    try {
      UnsentEmailData::deleteById($emailId);
    } catch (Throwable $e) {
      // Log error but don't fail the send
      error_log('Failed to delete unsent email data: ' . $e->getMessage());
    }
    
    echo json_encode([
      'success' => true,
      'recipient' => $recipientName . ' <' . $recipientEmail . '>'
    ]);
  } else {
    throw new RuntimeException('Failed to send email');
  }
  
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'error' => $e->getMessage(),
    'recipient' => isset($recipientName) && isset($recipientEmail) ? 
      $recipientName . ' <' . $recipientEmail . '>' : 'unknown'
  ]);
}
