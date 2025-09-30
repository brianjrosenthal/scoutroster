<?php
require_once __DIR__.'/partials.php';
require_admin();

require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/EventUIManager.php';
require_once __DIR__ . '/lib/UnsentEmailData.php';
require_once __DIR__ . '/lib/UserContext.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  header('Location: /admin_event_invite_form.php');
  exit;
}

require_csrf();

$eventId = (int)($_POST['event_id'] ?? 0);
if ($eventId <= 0) { http_response_code(400); exit('Missing event_id'); }

// Get the email IDs that were created in the preview step
$emailIds = $_POST['email_ids'] ?? [];
if (!is_array($emailIds)) {
  $emailIds = explode(',', (string)$emailIds);
}
$emailIds = array_filter(array_map('intval', $emailIds));

if (empty($emailIds)) {
  http_response_code(400);
  exit('No email IDs provided');
}

// Load event
$event = EventManagement::findById($eventId);
if (!$event) { http_response_code(404); exit('Event not found'); }

// Get additional form data
$subject = trim((string)($_POST['subject'] ?? ''));
$organizer = trim((string)($_POST['organizer'] ?? ''));
$suppressedCount = (int)($_POST['suppressed_count'] ?? 0);
$suppressPolicy = $_POST['suppress_policy'] ?? 'last_24_hours';

// Get user context for class method calls
$ctx = \UserContext::getLoggedInUserContext();
if (!$ctx) {
  http_response_code(500);
  exit('User context required');
}

// Build the emails-to-send array by looking up each email ID
$emailsToSend = [];
foreach ($emailIds as $emailId) {
  $emailData = UnsentEmailData::findWithUserById($emailId);
  if ($emailData) {
    // Email still exists and hasn't been sent yet
    $name = trim(($emailData['first_name'] ?? '') . ' ' . ($emailData['last_name'] ?? ''));
    $displayName = $name ?: 'Unknown';
    
    $emailsToSend[] = [
      'id' => $emailId,
      'to' => $emailData['email'],
      'to_name' => $displayName
    ];
  }
  // If email doesn't exist, it was likely already sent - we'll skip it
}

if (empty($emailsToSend)) {
  // All emails were already processed - redirect with success message
  header('Location: /admin_event_invite_form.php?event_id=' . $eventId . '&success=' . urlencode('All invitations were already sent.'));
  exit;
}

// Helper function to detect environment
function detectEnvironment($host) {
  $host = strtolower($host);
  if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false || strpos($host, '.local') !== false) {
    return ['type' => 'local', 'label' => '🏠 LOCAL DEVELOPMENT', 'class' => 'env-local'];
  }
  if (strpos($host, 'dev.') === 0 || strpos($host, 'staging.') === 0 || strpos($host, 'test.') === 0) {
    return ['type' => 'dev', 'label' => '⚠️ DEVELOPMENT SITE', 'class' => 'env-dev'];
  }
  return ['type' => 'prod', 'label' => '✅ PRODUCTION SITE', 'class' => 'env-prod'];
}

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . $host;
$env = detectEnvironment($host);

$me = current_user();
$isAdmin = !empty($me['is_admin']);

header_html('Sending Email Invitations');
?>

<style>
  .env-warning {
    padding: 12px 16px;
    margin-bottom: 20px;
    border-radius: 6px;
    text-align: center;
    font-weight: bold;
    font-size: 16px;
  }
  .env-prod { background-color: #d4edda; color: #155724; border: 2px solid #c3e6cb; }
  .env-dev { background-color: #f8d7da; color: #721c24; border: 2px solid #f5c6cb; }
  .env-local { background-color: #fff3cd; color: #856404; border: 2px solid #ffeaa7; }
  
  .progress-table {
    width: 100%;
    border-collapse: collapse;
    margin: 16px 0;
    font-size: 14px;
  }
  .progress-table th,
  .progress-table td {
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
    vertical-align: top;
  }
  .progress-table th {
    background-color: #f8f9fa;
    font-weight: bold;
    position: sticky;
    top: 0;
  }
  .progress-table tbody tr:hover {
    background-color: #f8f9fa;
  }
  .status-pending { color: #6c757d; }
  .status-sending { color: #007bff; }
  .status-success { color: #28a745; font-weight: bold; }
  .status-failed { color: #dc3545; font-weight: bold; }
  .status-retrying { color: #fd7e14; }
  .error-details {
    color: #dc3545;
    font-size: 12px;
    margin-top: 4px;
    padding: 4px 8px;
    background-color: #f8d7da;
    border-radius: 4px;
  }
</style>

<div class="env-warning <?= h($env['class']) ?>">
  SENDING FROM: <?= h($baseUrl) ?> - <?= h($env['label']) ?>
</div>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;">Sending Invitations: <?= h($event['name']) ?></h2>
</div>

<div class="card">
  <h3>📧 Email Sending Progress</h3>
  
  <div style="background-color: #f8f9fa; padding: 16px; border-radius: 6px; margin-bottom: 16px;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
      <div><strong>Subject:</strong> <?= h($subject) ?></div>
      <div><strong>Total Recipients:</strong> <?= count($emailsToSend) ?></div>
      <?php if ($suppressedCount > 0): ?>
        <div><strong>Suppressed:</strong> <?= (int)$suppressedCount ?></div>
      <?php endif; ?>
      <?php if (!empty($organizer)): ?>
        <div><strong>Organizer:</strong> <?= h($organizer) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (defined('EMAIL_DEBUG_MODE') && EMAIL_DEBUG_MODE === true): ?>
    <div style="background: #fff3cd; color: #856404; padding: 12px; margin-bottom: 15px; border-radius: 5px; border: 1px solid #ffeaa7;">
      🐛 <strong>DEBUG MODE ACTIVE</strong> - Emails are being simulated, not actually sent. Each email will take 2 seconds with occasional simulated failures.
    </div>
  <?php endif; ?>

  <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">
    <table class="progress-table">
      <thead>
        <tr>
          <th style="width: 60px;">#</th>
          <th>Name</th>
          <th>Email</th>
          <th style="width: 120px;">Status</th>
        </tr>
      </thead>
      <tbody id="emailProgressTable">
        <?php foreach ($emailsToSend as $index => $email): ?>
          <tr id="email-row-<?= $index ?>">
            <td><?= $index + 1 ?></td>
            <td><?= h($email['to_name']) ?></td>
            <td><?= h($email['to']) ?></td>
            <td id="status-<?= $index ?>" class="status-pending">Pending</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div id="completionSummary" style="display: none; background-color: #f8f9fa; padding: 16px; border-radius: 6px; margin-top: 16px;">
    <h4>📧 Email Sending Complete!</h4>
    <div id="summaryStats"></div>
    <div style="margin-top: 16px;">
      <a href="/admin_event_invite_form.php?event_id=<?= (int)$eventId ?>" class="button" style="margin-right: 10px;">← Send More Invitations</a>
      <a href="/event.php?id=<?= (int)$eventId ?>" class="button">Back to Event</a>
    </div>
  </div>
</div>

<?php if ($isAdmin): ?>
  <?= EventUIManager::renderAdminModals((int)$eventId) ?>
  <?= EventUIManager::renderAdminMenuScript((int)$eventId) ?>
<?php endif; ?>

<script>
// Email sending coordination
const emailsToSend = <?= json_encode($emailsToSend) ?>;
const csrfToken = <?= json_encode(csrf_token()) ?>;
const suppressedCount = <?= (int)$suppressedCount ?>;
let currentIndex = 0;
let sentCount = 0;
let failedCount = 0;

// Status update functions
function updateProgressRow(index, status, errorMessage = null) {
  const statusCell = document.getElementById(`status-${index}`);
  const row = document.getElementById(`email-row-${index}`);
  
  if (!statusCell || !row) return;
  
  // Remove old classes
  statusCell.className = statusCell.className.replace(/status-\w+/g, '');
  
  switch (status) {
    case 'sending':
      statusCell.className += ' status-sending';
      statusCell.innerHTML = '⏳ Sending...';
      row.style.backgroundColor = '#e7f3ff';
      break;
    case 'success':
      statusCell.className += ' status-success';
      statusCell.innerHTML = '✅ Sent';
      row.style.backgroundColor = '#d4edda';
      sentCount++;
      break;
    case 'failed':
      statusCell.className += ' status-failed';
      statusCell.innerHTML = '❌ Failed';
      row.style.backgroundColor = '#f8d7da';
      if (errorMessage) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'error-details';
        errorDiv.textContent = errorMessage;
        statusCell.appendChild(errorDiv);
      }
      failedCount++;
      break;
    case 'retrying':
      statusCell.className += ' status-retrying';
      statusCell.innerHTML = '🔄 Retrying in 60s...';
      row.style.backgroundColor = '#fff3cd';
      break;
  }
}

// Check if error is SMTP authentication error
function isAuthError(errorMessage) {
  if (!errorMessage) return false;
  const authErrorPatterns = [
    'authentication failed',
    'auth login',
    'username authentication failed',
    'password authentication failed',
    'auth',
    '535'
  ];
  const lowerError = errorMessage.toLowerCase();
  return authErrorPatterns.some(pattern => lowerError.includes(pattern));
}

// Send next email in queue
async function sendNextEmail() {
  if (currentIndex >= emailsToSend.length) {
    showCompletionSummary();
    return;
  }
  
  const email = emailsToSend[currentIndex];
  updateProgressRow(currentIndex, 'sending');
  
  try {
    const formData = new FormData();
    formData.append('csrf', csrfToken);
    formData.append('unsent_email_id', email.id);
    
    const response = await fetch('send_single_event_invitation.php', {
      method: 'POST',
      body: formData
    });
    
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    
    const result = await response.json();
    
    if (result.success) {
      updateProgressRow(currentIndex, 'success');
      currentIndex++;
      // Small delay between successful sends
      setTimeout(sendNextEmail, 100);
    } else {
      const error = result.error || 'Unknown error';
      
      if (error === 'ALREADY_PROCESSED') {
        // Email was already processed (likely from a page refresh) - treat as success
        updateProgressRow(currentIndex, 'success');
        currentIndex++;
        setTimeout(sendNextEmail, 100);
      } else if (isAuthError(error)) {
        // SMTP auth error - wait 60 seconds and retry
        updateProgressRow(currentIndex, 'retrying');
        setTimeout(() => {
          sendNextEmail(); // Retry same email
        }, 60000);
        return;
      } else {
        // Other error - mark as failed and continue
        updateProgressRow(currentIndex, 'failed', error);
        currentIndex++;
        setTimeout(sendNextEmail, 100);
      }
    }
  } catch (error) {
    updateProgressRow(currentIndex, 'failed', error.message);
    currentIndex++;
    setTimeout(sendNextEmail, 100);
  }
}

// Show completion summary
function showCompletionSummary() {
  const summaryDiv = document.getElementById('completionSummary');
  const statsDiv = document.getElementById('summaryStats');
  
  if (summaryDiv && statsDiv) {
    let statsHtml = `<p><strong>Total recipients:</strong> ${emailsToSend.length}</p>`;
    statsHtml += `<p><strong>Successfully sent:</strong> <span class="status-success">${sentCount}</span></p>`;
    if (failedCount > 0) {
      statsHtml += `<p><strong>Failed:</strong> <span class="status-failed">${failedCount}</span></p>`;
    }
    if (suppressedCount > 0) {
      statsHtml += `<p><strong>Suppressed (already invited):</strong> ${suppressedCount}</p>`;
    }
    
    statsDiv.innerHTML = statsHtml;
    summaryDiv.style.display = 'block';
  }
}

// Start sending emails when page loads
document.addEventListener('DOMContentLoaded', function() {
  if (emailsToSend.length > 0) {
    // Add a brief delay to let the UI settle
    setTimeout(sendNextEmail, 500);
  } else {
    showCompletionSummary();
  }
});

// Warn user before leaving page while emails are being sent
window.addEventListener('beforeunload', function(e) {
  if (currentIndex < emailsToSend.length && currentIndex > 0) {
    e.preventDefault();
    e.returnValue = 'Emails are still being sent. Are you sure you want to leave?';
    return e.returnValue;
  }
});
</script>

<?php footer_html(); ?>
