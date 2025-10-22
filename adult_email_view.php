<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/UserManagement.php';
require_once __DIR__.'/lib/EmailLog.php';

require_login();

$me = current_user();

// Permission check: Only Key 3 positions (Cubmaster, Committee Chair, Treasurer) can access
if (!\UserManagement::isApprover((int)($me['id'] ?? 0))) {
  http_response_code(403);
  exit('Access restricted to key 3 positions (Cubmaster, Committee Chair, Treasurer)');
}

// Get email ID
$emailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($emailId <= 0) {
  http_response_code(400);
  exit('Invalid email ID');
}

// Get return context (email address to return to)
$returnEmail = isset($_GET['return_email']) ? trim((string)$_GET['return_email']) : '';

// Retrieve the email
$email = EmailLog::getEmailById($emailId);

if (!$email) {
  http_response_code(404);
  exit('Email not found');
}

// Security: Validate that this email belongs to the expected recipient if return_email is provided
if ($returnEmail !== '' && strtolower($email['to_email']) !== strtolower($returnEmail)) {
  http_response_code(403);
  exit('Access denied');
}

// Get user info for display
$toEmail = $email['to_email'];
$recipient = UserManagement::findByEmail($toEmail);
$recipientName = $recipient ? trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? '')) : $email['to_name'] ?? 'Unknown';

header_html('View Email');
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
  <h2>Email Details</h2>
  <?php if ($returnEmail !== ''): ?>
    <a href="/adult_email_history.php?email=<?= urlencode($returnEmail) ?>" class="button">← Back to Email History</a>
  <?php else: ?>
    <a href="/adult_email_history.php" class="button">← Back to Email History</a>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Email Information</h3>
  
  <div class="grid" style="grid-template-columns:150px 1fr;gap:12px;margin-bottom:20px;">
    <div><strong>Date Sent:</strong></div>
    <div><?= h(date('l, F j, Y \a\t g:i A', strtotime($email['created_at']))) ?></div>
    
    <div><strong>Recipient:</strong></div>
    <div>
      <?= h($recipientName) ?>
      <?php if ($recipient): ?>
        <a href="/adult_edit.php?id=<?= (int)$recipient['id'] ?>" class="small" style="margin-left:8px;">View Profile</a>
      <?php endif; ?>
    </div>
    
    <div><strong>Email Address:</strong></div>
    <div><?= h($email['to_email']) ?></div>
    
    <?php if (!empty($email['cc_email'])): ?>
      <div><strong>CC:</strong></div>
      <div><?= h($email['cc_email']) ?></div>
    <?php endif; ?>
    
    <div><strong>Subject:</strong></div>
    <div><strong><?= h($email['subject']) ?></strong></div>
    
    <div><strong>Status:</strong></div>
    <div>
      <?php if (!empty($email['success'])): ?>
        <span style="color:#28a745;font-weight:bold;">✓ Successfully Sent</span>
      <?php else: ?>
        <span style="color:#dc3545;font-weight:bold;">✗ Failed to Send</span>
        <?php if (!empty($email['error_message'])): ?>
          <div style="margin-top:8px;padding:8px;background:#f8d7da;border-radius:4px;">
            <strong>Error:</strong> <?= h($email['error_message']) ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    
    <?php if (!empty($email['sent_by_user_id'])): ?>
      <?php $sender = UserManagement::findById((int)$email['sent_by_user_id']); ?>
      <?php if ($sender): ?>
        <div><strong>Sent By:</strong></div>
        <div>
          <?= h(trim(($sender['first_name'] ?? '') . ' ' . ($sender['last_name'] ?? ''))) ?>
          <a href="/adult_edit.php?id=<?= (int)$sender['id'] ?>" class="small" style="margin-left:8px;">View Profile</a>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:20px;">
  <h3>Email Content</h3>
  <div style="border:1px solid #ddd;border-radius:4px;padding:20px;background:#f8f9fa;overflow-x:auto;">
    <?php 
      // Output the HTML content directly (it's already HTML from the email)
      // The email body is stored as HTML in the database
      echo $email['body_html']; 
    ?>
  </div>
</div>

<div style="margin-top:20px;">
  <?php if ($returnEmail !== ''): ?>
    <a href="/adult_email_history.php?email=<?= urlencode($returnEmail) ?>" class="button">← Back to Email History</a>
  <?php else: ?>
    <a href="/adult_email_history.php" class="button">← Back to Email History</a>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
