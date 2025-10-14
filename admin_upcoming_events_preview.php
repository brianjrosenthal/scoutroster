<?php
require_once __DIR__.'/partials.php';
require_admin();

require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/UserContext.php';
require_once __DIR__ . '/lib/UnsentEmailData.php';
require_once __DIR__ . '/lib/Text.php';
require_once __DIR__ . '/settings.php';

if (!defined('INVITE_HMAC_KEY') || INVITE_HMAC_KEY === '') {
  header_html('Upcoming Events Email');
  echo '<h2>Upcoming Events Email</h2><div class="card"><p class="error">INVITE_HMAC_KEY is not configured. Edit config.local.php.</p></div>';
  footer_html();
  exit;
}

// Check if user is an approver (key 3 position holder)
$ctx = UserContext::getLoggedInUserContext();
if (!$ctx || !UserManagement::isApprover((int)$ctx->id)) {
  http_response_code(403);
  exit('Access restricted to key 3 positions (Cubmaster, Committee Chair, Treasurer)');
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  header('Location: /admin_upcoming_events_form.php');
  exit;
}

require_csrf();

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . $host;
$siteTitle = Settings::siteTitle();

// Helper functions
function b64url_encode_str(string $bin): string {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function invite_signature_build(int $uid, int $eventId): string {
  $payload = $uid . ':' . $eventId;
  return b64url_encode_str(hash_hmac('sha256', $payload, INVITE_HMAC_KEY, true));
}

function replaceEventLinkTokens(string $content, int $userId, string $baseUrl): string {
  $pattern = '/\{link_event_(\d+)\}/';
  
  $replacedContent = preg_replace_callback($pattern, function($matches) use ($userId, $baseUrl) {
    $eventId = (int)$matches[1];
    $sig = invite_signature_build($userId, $eventId);
    $link = $baseUrl . '/event_invite.php?uid=' . $userId . '&event_id=' . $eventId . '&sig=' . $sig;
    return $link;
  }, $content);
  
  return $replacedContent;
}

function generateUpcomingEventsEmailHTML(string $content, string $siteTitle, int $userId, string $baseUrl): string {
  $safeSite = htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8');
  
  // Replace {link_event_X} tokens with personalized links
  $content = replaceEventLinkTokens($content, $userId, $baseUrl);
  
  return '
  <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:640px;margin:0 auto;padding:16px;color:#222;">
    <div style="text-align:center;">
      <h2 style="margin:0 0 8px;">Upcoming Events</h2>
      <p style="margin:0 0 16px;color:#444;">'. $safeSite .'</p>
    </div>
    <div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:0 0 16px;background:#fff;">
      <div>'. Text::renderMarkup($content) .'</div>
    </div>
    <p style="font-size:12px;color:#666;text-align:center;margin:12px 0 0;">
      Click the RSVP links above to respond to each event.
    </p>
  </div>';
}

try {
  $subject = trim((string)($_POST['subject'] ?? 'Cub Scout Upcoming Events'));
  $description = trim((string)($_POST['description'] ?? ''));
  
  if ($subject === '') {
    $subject = 'Cub Scout Upcoming Events';
  }
  
  if ($description === '') {
    throw new RuntimeException('Email body is required.');
  }

  // Build filters from form data (no RSVP filter for upcoming events)
  $filters = [
    'registration_status' => $_POST['registration_status'] ?? 'all',
    'grades' => $_POST['grades'] ?? [],
    'rsvp_status' => 'all', // Always 'all' for upcoming events
    'event_id' => 0, // Not specific to one event
    'specific_adult_ids' => $_POST['specific_adult_ids'] ?? []
  ];

  // Normalize grades array
  if (is_string($filters['grades'])) {
    $filters['grades'] = explode(',', $filters['grades']);
  }
  $filters['grades'] = array_filter(array_map('intval', (array)$filters['grades']));

  // Normalize specific adult IDs
  if (is_string($filters['specific_adult_ids'])) {
    $filters['specific_adult_ids'] = explode(',', $filters['specific_adult_ids']);
  }
  $filters['specific_adult_ids'] = array_filter(array_map('intval', (array)$filters['specific_adult_ids']));

  // Get recipients using filter system
  $recipients = UserManagement::listAdultsWithFilters($filters);

  // Dedupe by email and skip blanks
  $byEmail = [];
  $finalRecipients = [];
  foreach ($recipients as $r) {
    $email = strtolower(trim((string)($r['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
    if (isset($byEmail[$email])) continue;
    $byEmail[$email] = true;
    $finalRecipients[] = $r;
  }
  
  if (empty($finalRecipients)) {
    throw new RuntimeException('No recipients with valid email addresses.');
  }

  // Create unsent_email_data entries
  $emailIds = [];
  foreach ($finalRecipients as $r) {
    $uid = (int)$r['id'];
    $email = trim((string)$r['email']);
    
    // Generate email HTML for this specific recipient with personalized links
    $html = generateUpcomingEventsEmailHTML($description, $siteTitle, $uid, $baseUrl);

    // Insert into database - no event_id (use 0), no ICS attachment
    $unsentEmailId = UnsentEmailData::create($ctx, 0, $uid, $subject, $html, null);
    $emailIds[] = $unsentEmailId;
  }

  // Prepare preview data
  $previewData = [
    'recipients' => $finalRecipients,
    'subject' => $subject,
    'description' => $description,
    'emailIds' => $emailIds,
    'count' => count($finalRecipients)
  ];

} catch (Throwable $e) {
  $error = $e->getMessage() ?: 'Failed to prepare emails.';
  header('Location: /admin_upcoming_events_form.php?error=' . urlencode($error));
  exit;
}

header_html('Preview Upcoming Events Email');
?>

<style>
  .recipients-table {
    width: 100%;
    border-collapse: collapse;
    margin: 16px 0;
  }
  .recipients-table th,
  .recipients-table td {
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
  }
  .recipients-table th {
    background-color: #f8f9fa;
    font-weight: bold;
  }
  .recipients-table tbody tr:hover {
    background-color: #f8f9fa;
  }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;">Preview Upcoming Events Email</h2>
  <div style="display: flex; gap: 8px;">
    <a class="button" href="/admin_upcoming_events_form.php">← Edit Settings</a>
    <a class="button" href="/events.php">Back to Events</a>
  </div>
</div>

<div class="card">
  <h3>⚠️ Confirm Email Send</h3>
  <div style="background-color: #f8f9fa; padding: 16px; border-radius: 6px; margin-bottom: 16px;">
    <p><strong>You are about to send:</strong></p>
    <ul style="margin: 8px 0;">
      <li><strong>Subject:</strong> <?= h($previewData['subject']) ?></li>
      <li><strong>To:</strong> <?= (int)$previewData['count'] ?> recipients</li>
    </ul>
  </div>
  
  <h4>Recipients (<?= count($previewData['recipients']) ?>)</h4>
  <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">
    <table class="recipients-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($previewData['recipients'] as $recipient): ?>
          <tr>
            <td>
              <?php 
                $name = trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? ''));
                echo h($name ?: 'Unknown');
              ?>
            </td>
            <td><?= h($recipient['email']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  
  <div class="actions" style="margin-top: 20px;">
    <button type="button" id="sendEmailsBtn" class="primary button" style="background-color: #dc2626; color: white;">
      Send <?= (int)$previewData['count'] ?> Emails Now
    </button>
    <a class="button" href="/admin_upcoming_events_form.php">← Go Back to Edit</a>
  </div>
  
  <div id="sendProgress" style="display:none; margin-top: 20px;">
    <h4>Sending Progress</h4>
    <div style="background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin-bottom: 12px;">
      <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
        <span>Sent: <strong id="sentCount">0</strong> / <?= (int)$previewData['count'] ?></span>
        <span>Errors: <strong id="errorCount">0</strong></span>
      </div>
      <div style="background: #e9ecef; height: 24px; border-radius: 4px; overflow: hidden;">
        <div id="progressBar" style="background: #28a745; height: 100%; width: 0%; transition: width 0.3s;"></div>
      </div>
    </div>
    <div id="progressMessages" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px; padding: 12px; font-family: monospace; font-size: 12px;">
    </div>
  </div>
</div>

<div class="card">
  <h3>Email Preview</h3>
  <div id="emailPreviewContent">
    <?php
      // Generate preview (with placeholder links, not personalized)
      $previewContent = preg_replace_callback('/\{link_event_(\d+)\}/', function($matches) use ($baseUrl) {
        $eventId = $matches[1];
        return '<a href="' . htmlspecialchars($baseUrl . '/event.php?id=' . $eventId, ENT_QUOTES, 'UTF-8') . '" style="color:#0b5ed7;text-decoration:none;">RSVP Link</a>';
      }, $previewData['description']);
      
      $safeSite = htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8');
    ?>
    <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:640px;margin:0 auto;padding:16px;color:#222;">
      <div style="text-align:center;">
        <h2 style="margin:0 0 8px;">Upcoming Events</h2>
        <p style="margin:0 0 16px;color:#444;"><?= $safeSite ?></p>
      </div>
      <div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:0 0 16px;background:#fff;">
        <div><?= Text::renderMarkup($previewContent) ?></div>
      </div>
      <p style="font-size:12px;color:#666;text-align:center;margin:12px 0 0;">
        Click the RSVP links above to respond to each event.
      </p>
    </div>
  </div>
</div>

<script>
(function() {
  const sendBtn = document.getElementById('sendEmailsBtn');
  const sendProgress = document.getElementById('sendProgress');
  const sentCountEl = document.getElementById('sentCount');
  const errorCountEl = document.getElementById('errorCount');
  const progressBar = document.getElementById('progressBar');
  const progressMessages = document.getElementById('progressMessages');
  
  const emailIds = <?= json_encode($previewData['emailIds']) ?>;
  const totalEmails = emailIds.length;
  let sentCount = 0;
  let errorCount = 0;
  
  function addMessage(message, isError = false) {
    const p = document.createElement('p');
    p.style.margin = '4px 0';
    p.style.color = isError ? '#dc3545' : '#28a745';
    p.textContent = message;
    progressMessages.appendChild(p);
    progressMessages.scrollTop = progressMessages.scrollHeight;
  }
  
  function updateProgress() {
    sentCountEl.textContent = sentCount;
    errorCountEl.textContent = errorCount;
    const percentage = ((sentCount + errorCount) / totalEmails) * 100;
    progressBar.style.width = percentage + '%';
  }
  
  async function sendEmail(emailId) {
    const formData = new FormData();
    formData.append('csrf', '<?= h(csrf_token()) ?>');
    formData.append('email_id', emailId);
    
    try {
      const response = await fetch('/admin_upcoming_events_send.php', {
        method: 'POST',
        body: formData
      });
      
      const data = await response.json();
      
      if (data.success) {
        sentCount++;
        addMessage('✓ Sent to ' + data.recipient);
      } else {
        errorCount++;
        addMessage('✗ Error sending to ' + (data.recipient || 'unknown') + ': ' + (data.error || 'Unknown error'), true);
      }
    } catch (error) {
      errorCount++;
      addMessage('✗ Network error: ' + error.message, true);
    }
    
    updateProgress();
  }
  
  async function sendAllEmails() {
    sendBtn.disabled = true;
    sendBtn.textContent = 'Sending...';
    sendProgress.style.display = 'block';
    
    addMessage('Starting to send ' + totalEmails + ' emails...');
    
    for (const emailId of emailIds) {
      await sendEmail(emailId);
    }
    
    addMessage('---');
    addMessage('Sending complete! Sent: ' + sentCount + ', Errors: ' + errorCount);
    sendBtn.textContent = 'Sending Complete';
    
    if (errorCount === 0) {
      setTimeout(() => {
        window.location.href = '/events.php';
      }, 2000);
    }
  }
  
  if (sendBtn) {
    sendBtn.addEventListener('click', function() {
      if (confirm('Are you sure you want to send ' + totalEmails + ' emails?')) {
        sendAllEmails();
      }
    });
  }
})();
</script>

<?php footer_html(); ?>
