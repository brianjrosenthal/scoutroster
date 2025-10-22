<?php
require_once __DIR__.'/partials.php';
require_admin();

require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/UserContext.php';
require_once __DIR__ . '/lib/UnsentEmailData.php';
require_once __DIR__ . '/lib/EventInvitationTracking.php';
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
  // Replace {link_event_X} tokens with personalized links
  $content = replaceEventLinkTokens($content, $userId, $baseUrl);
  
  // Apply basic markup formatting and return the content directly
  return Text::renderMarkup($content);
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

  // Get suppression policy
  $suppressPolicy = $_POST['suppress_policy'] ?? 'last_24_hours';

  // Dedupe by email and skip blanks, apply suppression policy
  $byEmail = [];
  $finalRecipients = [];
  foreach ($recipients as $r) {
    $email = strtolower(trim((string)($r['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
    if (isset($byEmail[$email])) continue;
    
    // Check suppression policy (NULL event_id for upcoming events digest)
    $userId = (int)$r['id'];
    if (EventInvitationTracking::shouldSuppressInvitation(null, $userId, $suppressPolicy)) {
      continue; // Skip this user
    }
    
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

    // Insert into database - no event_id (use NULL), no ICS attachment
    $unsentEmailId = UnsentEmailData::create($ctx, null, $uid, $subject, $html, null);
    $emailIds[] = $unsentEmailId;
  }

  // Calculate suppressed count for display
  $totalBeforeSuppression = count($recipients);
  $suppressedCount = $totalBeforeSuppression - count($finalRecipients);

  // Prepare preview data
  $previewData = [
    'recipients' => $finalRecipients,
    'subject' => $subject,
    'description' => $description,
    'emailIds' => $emailIds,
    'count' => count($finalRecipients),
    'suppressedCount' => $suppressedCount
  ];

  // Store data for POST back to form
  $backParams = [
    'registration_status' => $filters['registration_status'],
    'suppress_policy' => $suppressPolicy,
    'subject' => $subject,
    'description' => $description,
    'grades' => $filters['grades'],
    'specific_adult_ids' => $filters['specific_adult_ids']
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
    <form method="post" action="/admin_upcoming_events_form.php" style="display: inline; margin: 0;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="registration_status" value="<?= h($backParams['registration_status']) ?>">
      <input type="hidden" name="suppress_policy" value="<?= h($backParams['suppress_policy']) ?>">
      <input type="hidden" name="subject" value="<?= h($backParams['subject']) ?>">
      <input type="hidden" name="description" value="<?= h($backParams['description']) ?>">
      <?php foreach ($backParams['grades'] as $grade): ?>
        <input type="hidden" name="grades[]" value="<?= (int)$grade ?>">
      <?php endforeach; ?>
      <?php foreach ($backParams['specific_adult_ids'] as $adultId): ?>
        <input type="hidden" name="specific_adult_ids[]" value="<?= (int)$adultId ?>">
      <?php endforeach; ?>
      <button type="submit" class="button">← Edit Settings</button>
    </form>
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
    <form method="post" action="/admin_upcoming_events_send_emails.php" style="display: inline; margin: 0;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="subject" value="<?= h($previewData['subject']) ?>">
      <input type="hidden" name="suppressed_count" value="<?= (int)$previewData['suppressedCount'] ?>">
      <input type="hidden" name="suppress_policy" value="<?= h($backParams['suppress_policy']) ?>">
      <?php foreach ($previewData['emailIds'] as $emailId): ?>
        <input type="hidden" name="email_ids[]" value="<?= (int)$emailId ?>">
      <?php endforeach; ?>
      <button type="submit" class="primary button" style="background-color: #dc2626; color: white;">
        Send <?= (int)$previewData['count'] ?> Emails Now
      </button>
    </form>
    <form method="post" action="/admin_upcoming_events_form.php" style="display: inline; margin: 0;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="registration_status" value="<?= h($backParams['registration_status']) ?>">
      <input type="hidden" name="suppress_policy" value="<?= h($backParams['suppress_policy']) ?>">
      <input type="hidden" name="subject" value="<?= h($backParams['subject']) ?>">
      <input type="hidden" name="description" value="<?= h($backParams['description']) ?>">
      <?php foreach ($backParams['grades'] as $grade): ?>
        <input type="hidden" name="grades[]" value="<?= (int)$grade ?>">
      <?php endforeach; ?>
      <?php foreach ($backParams['specific_adult_ids'] as $adultId): ?>
        <input type="hidden" name="specific_adult_ids[]" value="<?= (int)$adultId ?>">
      <?php endforeach; ?>
      <button type="submit" class="button">← Go Back to Edit</button>
    </form>
  </div>
</div>

<div class="card">
  <h3>Email Preview</h3>
  <div id="emailPreviewContent" style="border: 1px solid #ddd; border-radius: 4px; padding: 16px; background: #f8f9fa;">
    <?php
      // First replace {link_event_X} tokens with actual URLs (before markdown processing)
      $previewText = preg_replace_callback('/\{link_event_(\d+)\}/', function($matches) use ($baseUrl) {
        $eventId = $matches[1];
        return $baseUrl . '/event.php?id=' . $eventId;
      }, $previewData['description']);
      
      // Then apply markdown formatting (which will convert [rsvp](http://...) to links)
      $previewHtml = Text::renderMarkup($previewText);
      
      // Output the HTML directly (it's already safe)
      echo $previewHtml;
    ?>
  </div>
</div>


<?php footer_html(); ?>
