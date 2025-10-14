<?php
require_once __DIR__.'/partials.php';
require_admin();

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/EventUIManager.php';
require_once __DIR__ . '/lib/GradeCalculator.php';
require_once __DIR__ . '/lib/Text.php';
require_once __DIR__ . '/lib/EventInvitationTracking.php';
require_once __DIR__ . '/lib/UnsentEmailData.php';
require_once __DIR__ . '/lib/RSVPManagement.php';
require_once __DIR__ . '/lib/UserContext.php';
require_once __DIR__ . '/lib/EmailPreviewUI.php';
require_once __DIR__ . '/settings.php';

if (!defined('INVITE_HMAC_KEY') || INVITE_HMAC_KEY === '') {
  header_html('Invitations');
  echo '<h2>Invitations</h2><div class="card"><p class="error">INVITE_HMAC_KEY is not configured. Edit config.local.php.</p></div>';
  echo '<div class="card"><a class="button" href="/events.php">Back</a></div>';
  footer_html();
  exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  header('Location: /admin_event_invite_form.php');
  exit;
}

require_csrf();

$eventId = (int)($_POST['event_id'] ?? 0);
if ($eventId <= 0) { http_response_code(400); exit('Missing event_id'); }

// Load event
$event = EventManagement::findById($eventId);
if (!$event) { http_response_code(404); exit('Event not found'); }

// Helper functions
function generateSubjectLine(string $emailType, array $event, string $originalSubject, string $defaultSubject): string {
  // If user has customized the subject (not using default), keep their custom subject
  if ($originalSubject !== $defaultSubject) {
    return $originalSubject;
  }
  
  $eventName = (string)$event['name'];
  $quotedEventName = '"' . $eventName . '"';
  
  // Format the date/time
  $startsAt = (string)$event['starts_at'];
  try {
    $dt = new DateTime($startsAt);
    $dayOfWeek = $dt->format('D'); // Mon, Tue, etc.
    $monthDay = $dt->format('n/j'); // 10/7
    $time = $dt->format('g:i A'); // 3:00 PM
    $dateTimeStr = $dayOfWeek . ' ' . $monthDay . ' at ' . $time;
  } catch (Throwable $e) {
    // Fallback if date parsing fails
    $dateTimeStr = $startsAt;
  }
  
  if ($emailType === 'invitation') {
    return "You're invited to " . $quotedEventName . ', ' . $dateTimeStr;
  }
  
  if ($emailType === 'reminder') {
    return 'Reminder: ' . $quotedEventName . ', ' . $dateTimeStr;
  }
  
  // Default case (emailType === 'none')
  return $originalSubject;
}

function getRsvpStatus(int $eventId, int $userId): ?string {
  return RSVPManagement::getAnswerForUserEvent($eventId, $userId);
}

function getEmailIntroduction(string $emailType, int $eventId, int $userId): string {
  if ($emailType === 'none') {
    return '';
  }
  
  if ($emailType === 'invitation') {
    return "You're Invited to...";
  }
  
  if ($emailType === 'reminder') {
    // Check if user has RSVP'd to this event
    $rsvpStatus = getRsvpStatus($eventId, $userId);
    return $rsvpStatus ? 'Reminder:' : 'Reminder to RSVP for...';
  }
  
  return '';
}

function generateRsvpButtonHtml(int $eventId, int $userId, string $deepLink): string {
  $safeDeep = htmlspecialchars($deepLink, ENT_QUOTES, 'UTF-8');
  $rsvpStatus = getRsvpStatus($eventId, $userId);
  
  if ($rsvpStatus) {
    // User has RSVP'd - show bold text status + separate "View Details" button
    $statusText = ucfirst($rsvpStatus); // 'yes' -> 'Yes', 'maybe' -> 'Maybe', 'no' -> 'No'
    return '<p style="margin:0 0 10px;color:#222;font-size:16px;font-weight:bold;">You RSVP\'d '. htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') .'</p>
            <p style="margin:0 0 16px;">
              <a href="'. $safeDeep .'" style="display:inline-block;background:#0b5ed7;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;">View Details</a>
            </p>';
  } else {
    // User hasn't RSVP'd - show standard button
    return '<p style="margin:0 0 16px;">
      <a href="'. $safeDeep .'" style="display:inline-block;background:#0b5ed7;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;">View & RSVP</a>
    </p>';
  }
}

// Helper function to generate unsubscribe link
function generateUnsubscribeLink(int $userId, string $baseUrl): string {
  if (!defined('INVITE_HMAC_KEY') || INVITE_HMAC_KEY === '') {
    return '';
  }
  
  // Encrypt user ID and timestamp
  $timestamp = (string)time();
  
  // Simple XOR encryption for demo - in production, use proper encryption
  $encryptData = function($data, $key) {
    $keyLen = strlen($key);
    $encrypted = '';
    for ($i = 0; $i < strlen($data); $i++) {
      $encrypted .= chr(ord($data[$i]) ^ ord($key[$i % $keyLen]));
    }
    return base64_encode($encrypted);
  };
  
  $encryptedUserId = $encryptData((string)$userId, INVITE_HMAC_KEY);
  $encryptedTimestamp = $encryptData($timestamp, INVITE_HMAC_KEY);
  
  // Generate signature
  $signature = hash_hmac('sha256', $encryptedUserId . $encryptedTimestamp, INVITE_HMAC_KEY);
  
  // Build URL
  $params = http_build_query([
    'uid' => $encryptedUserId,
    'ts' => $encryptedTimestamp,
    'sig' => $signature
  ]);
  
  return $baseUrl . '/unsubscribe.php?' . $params;
}

function generateEmailHTML(array $event, string $siteTitle, string $baseUrl, string $deepLink, string $whenText, string $whereHtml, string $description, string $googleLink, string $outlookLink, string $icsDownloadLink, string $emailType = 'none', int $eventId = 0, int $userId = 0, bool $includeCalendarLinks = true): string {
  $safeSite = htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8');
  $safeEvent = htmlspecialchars((string)$event['name'], ENT_QUOTES, 'UTF-8');
  $safeWhen = htmlspecialchars($whenText, ENT_QUOTES, 'UTF-8');
  $safeDeep = htmlspecialchars($deepLink, ENT_QUOTES, 'UTF-8');
  $safeGoogle = htmlspecialchars($googleLink, ENT_QUOTES, 'UTF-8');
  $safeOutlook = htmlspecialchars($outlookLink, ENT_QUOTES, 'UTF-8');
  $safeIcs = htmlspecialchars($icsDownloadLink, ENT_QUOTES, 'UTF-8');
  
  // Get introduction text
  $introduction = getEmailIntroduction($emailType, $eventId, $userId);
  $introHtml = '';
  if ($introduction !== '') {
    $safeIntro = htmlspecialchars($introduction, ENT_QUOTES, 'UTF-8');
    $introHtml = '<p style="margin:0 0 8px;color:#666;font-size:14px;">'. $safeIntro .'</p>';
  }
  
  $calendarLinksHtml = '';
  if ($includeCalendarLinks) {
    $calendarLinksHtml = '<div style="text-align:center;margin:0 0 12px;">
      <a href="'. $safeGoogle .'" style="margin:0 6px;display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#0b5ed7;">Add to Google</a>
      <a href="'. $safeOutlook .'" style="margin:0 6px;display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#0b5ed7;">Add to Outlook</a>
      <a href="'. $safeIcs .'" style="margin:0 6px;display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#0b5ed7;">Download .ics</a>
    </div>';
  }
  
  // Generate context-aware button HTML
  $buttonHtml = generateRsvpButtonHtml($eventId, $userId, $deepLink);
  
  // Generate unsubscribe link
  $unsubscribeHtml = '';
  if ($userId > 0) {
    $unsubscribeLink = generateUnsubscribeLink($userId, $baseUrl);
    if ($unsubscribeLink !== '') {
      $safeUnsubscribe = htmlspecialchars($unsubscribeLink, ENT_QUOTES, 'UTF-8');
      $unsubscribeHtml = '<br><a href="'. $safeUnsubscribe .'" style="color:#999;font-size:10px;text-decoration:none;">Unsubscribe</a>';
    }
  }
  
  return '
  <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:640px;margin:0 auto;padding:16px;color:#222;">
    <div style="text-align:center;">
      '. $introHtml .'
      <h2 style="margin:0 0 8px;">'. $safeEvent .'</h2>
      <p style="margin:0 0 16px;color:#444;">'. $safeSite .'</p>
      '. $buttonHtml .'
    </div>
    <div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:0 0 16px;background:#fafafa;">
      <div><strong>When:</strong> '. $safeWhen .'</div>'.
      ($whereHtml !== '' ? '<div><strong>Where:</strong> '. $whereHtml .'</div>' : '') .'
    </div>'
    . ($description !== '' ? ('<div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:0 0 16px;background:#fff;">
      <div>'. Text::renderMarkup($description) .'</div>
    </div>') : '')
    . $calendarLinksHtml
    . '<p style="font-size:12px;color:#666;text-align:center;margin:12px 0 0;">
      If the button does not work, open this link: <br><a href="'. $safeDeep .'">'. $safeDeep .'</a>'. $unsubscribeHtml .'
    </p>
  </div>';
}

function b64url_encode_str(string $bin): string {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function invite_signature_build(int $uid, int $eventId): string {
  $payload = $uid . ':' . $eventId;
  return b64url_encode_str(hash_hmac('sha256', $payload, INVITE_HMAC_KEY, true));
}
function ics_escape_text(string $s): string {
  $s = str_replace('\\', '\\\\', $s);
  $s = str_replace(["\r\n", "\r", "\n"], '\\n', $s);
  $s = str_replace(',', '\,', $s);
  $s = str_replace(';', '\;', $s);
  return $s;
}
function dt_to_utc_str(string $sqlDateTime, string $tzId): string {
  try {
    $dt = new DateTime($sqlDateTime, new DateTimeZone($tzId));
  } catch (Throwable $e) {
    $dt = new DateTime($sqlDateTime);
  }
  $dt->setTimezone(new DateTimeZone('UTC'));
  return $dt->format('Ymd\THis\Z');
}
function dt_to_utc_iso(string $sqlDateTime, string $tzId): string {
  try {
    $dt = new DateTime($sqlDateTime, new DateTimeZone($tzId));
  } catch (Throwable $e) {
    $dt = new DateTime($sqlDateTime);
  }
  $dt->setTimezone(new DateTimeZone('UTC'));
  return $dt->format('Y-m-d\TH:i:s\Z');
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

$me = current_user();
$defaultOrganizer = trim((string)($me['email'] ?? ''));
$siteTitle = Settings::siteTitle();
$subjectDefault = 'Please RSVP to ' . (string)$event['name'];

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . $host;

try {
  $subject = trim((string)($_POST['subject'] ?? $subjectDefault));
  $organizer = trim((string)($_POST['organizer'] ?? $defaultOrganizer));
  $description = trim((string)($_POST['description'] ?? ''));
  $emailType = $_POST['email_type'] ?? 'none';

  if ($subject === '') $subject = $subjectDefault;
  
  // Generate dynamic subject line based on email type
  $subject = generateSubjectLine($emailType, $event, $subject, $subjectDefault);
  if ($organizer !== '' && !filter_var($organizer, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException('Organizer email is invalid.');
  }

  // Build filters from form data
  $filters = [
    'registration_status' => $_POST['registration_status'] ?? 'all',
    'grades' => $_POST['grades'] ?? [],
    'rsvp_status' => $_POST['rsvp_status'] ?? 'all',
    'event_id' => $eventId,
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

  // Apply suppression policy
  $suppressPolicy = $_POST['suppress_policy'] ?? 'last_24_hours';
  $suppressedCount = 0;
  $filteredRecipients = [];
  
  foreach ($finalRecipients as $r) {
    $userId = (int)$r['id'];
    if (EventInvitationTracking::shouldSuppressInvitation($eventId, $userId, $suppressPolicy)) {
      $suppressedCount++;
    } else {
      $filteredRecipients[] = $r;
    }
  }

  if (empty($filteredRecipients)) {
    throw new RuntimeException('No recipients after applying suppression policy. All users have already been invited according to your selected policy.');
  }

  // Generate all email content and ICS data
  $tzId = Settings::timezoneId();
  $eventUrl = $baseUrl . '/event.php?id=' . (int)$eventId;

  // Build ICS (same for all recipients)
  $icsLines = [];
  $icsLines[] = 'BEGIN:VCALENDAR';
  $icsLines[] = 'PRODID:-//' . ics_escape_text($siteTitle) . '//EN';
  $icsLines[] = 'VERSION:2.0';
  $icsLines[] = 'CALSCALE:GREGORIAN';
  $icsLines[] = 'METHOD:REQUEST';
  $icsLines[] = 'BEGIN:VEVENT';
  $icsLines[] = 'UID:event-' . (int)$eventId . '@' . $host;
  $icsLines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
  $icsLines[] = 'SUMMARY:' . ics_escape_text((string)$event['name']);
  $locName = trim((string)($event['location'] ?? ''));
  $locAddr = trim((string)($event['location_address'] ?? ''));
  $locCombined = ($locName !== '' && $locAddr !== '') ? ($locName . "\n" . $locAddr) : ($locAddr !== '' ? $locAddr : $locName);
  if ($locCombined !== '') {
    $icsLines[] = 'LOCATION:' . ics_escape_text($locCombined);
  }
  $desc = trim((string)($event['description'] ?? ''));
  $descWithUrl = $desc !== '' ? ($desc . "\n\n" . $eventUrl) : $eventUrl;
  $icsLines[] = 'DESCRIPTION:' . ics_escape_text($descWithUrl);
  $icsLines[] = 'DTSTART:' . dt_to_utc_str((string)$event['starts_at'], $tzId);
  if (!empty($event['ends_at'])) {
    $icsLines[] = 'DTEND:' . dt_to_utc_str((string)$event['ends_at'], $tzId);
  }
  $icsLines[] = 'URL:' . $eventUrl;
  if ($organizer !== '') {
    $icsLines[] = 'ORGANIZER;CN=' . ics_escape_text($siteTitle) . ':mailto:' . $organizer;
  }
  $icsLines[] = 'STATUS:CONFIRMED';
  $icsLines[] = 'END:VEVENT';
  $icsLines[] = 'END:VCALENDAR';
  $icsContent = implode("\r\n", $icsLines);

  // Calendar links and email template data
  $startZ = dt_to_utc_str((string)$event['starts_at'], $tzId);
  $hasEnd = !empty($event['ends_at']);
  $endZ = $hasEnd ? dt_to_utc_str((string)$event['ends_at'], $tzId) : null;

  // Fallback end for calendar links if no end (1 hour)
  if (!$endZ) {
    try {
      $dt = new DateTime((string)$event['starts_at'], new DateTimeZone($tzId));
    } catch (Throwable $e) {
      $dt = new DateTime((string)$event['starts_at']);
    }
    $dt->modify('+1 hour')->setTimezone(new DateTimeZone('UTC'));
    $endZ = $dt->format('Ymd\THis\Z');
  }

  $locParam = $locCombined !== '' ? preg_replace("/\r\n|\r|\n/", ', ', $locCombined) : '';
  $googleLink = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
    . '&text=' . rawurlencode((string)$event['name'])
    . '&dates=' . rawurlencode($startZ . '/' . $endZ)
    . '&details=' . rawurlencode($descWithUrl)
    . '&location=' . rawurlencode($locParam);
  $outlookLink = 'https://outlook.live.com/calendar/0/deeplink/compose?path=/calendar/action/compose&rru=addevent'
    . '&subject=' . rawurlencode((string)$event['name'])
    . '&startdt=' . rawurlencode(dt_to_utc_iso((string)$event['starts_at'], $tzId))
    . '&enddt=' . rawurlencode(dt_to_utc_iso($hasEnd ? (string)$event['ends_at'] : (string)$event['starts_at'], $tzId))
    . '&body=' . rawurlencode($descWithUrl)
    . '&location=' . rawurlencode($locParam);
  $icsDownloadLink = $baseUrl . '/event_ics.php?event_id='.(int)$eventId . ($organizer !== '' ? ('&organizer='.rawurlencode($organizer)) : '');

  // Email HTML template data
  $whenText = Settings::formatDateTimeRange((string)$event['starts_at'], !empty($event['ends_at']) ? (string)$event['ends_at'] : null);
  $whereHtml = $locCombined !== '' ? nl2br(htmlspecialchars($locCombined, ENT_QUOTES, 'UTF-8')) : '';

  // Get user context for class method calls
  $ctx = \UserContext::getLoggedInUserContext();
  if (!$ctx) {
    throw new RuntimeException('User context required');
  }

  // *** THIS IS THE KEY STEP: Create unsent_email_data entries here in preview step ***
  $emailIds = [];
  foreach ($filteredRecipients as $r) {
    $uid = (int)$r['id'];
    $email = trim((string)$r['email']);
    $name  = trim(((string)($r['first_name'] ?? '')).' '.((string)($r['last_name'] ?? '')));
    
    $sig = invite_signature_build($uid, $eventId);
    $deepLink = $baseUrl . '/event_invite.php?uid=' . $uid . '&event_id=' . $eventId . '&sig=' . $sig;

    // Generate email HTML for this specific recipient
    $html = generateEmailHTML($event, $siteTitle, $baseUrl, $deepLink, $whenText, $whereHtml, $description, $googleLink, $outlookLink, $icsDownloadLink, $emailType, $eventId, $uid, true);

    // Insert into database using class method - THIS CREATES THE UNSENT EMAIL DATA
    $unsentEmailId = UnsentEmailData::create($ctx, $eventId, $uid, $subject, $html, $icsContent);

    // Store the ID for passing to send page
    $emailIds[] = $unsentEmailId;
  }

  // Prepare preview data
  $previewData = [
    'event' => $event,
    'recipients' => $filteredRecipients,
    'suppressedCount' => $suppressedCount,
    'subject' => $subject,
    'organizer' => $organizer,
    'description' => $description,
    'emailType' => $emailType,
    'suppressPolicy' => $suppressPolicy,
    'emailIds' => $emailIds, // These are the IDs we'll pass to the send page
    'count' => count($filteredRecipients)
  ];

} catch (Throwable $e) {
  // Redirect back to form with error
  $error = $e->getMessage() ?: 'Failed to prepare invitations.';
  header('Location: /admin_event_invite_form.php?event_id=' . $eventId . '&error=' . urlencode($error));
  exit;
}

$env = detectEnvironment($host);
$me = current_user();
$isAdmin = !empty($me['is_admin']);

header_html('Preview Email Invitations');
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

<div class="env-warning <?= h($env['class']) ?>">
  SENDING FROM: <?= h($baseUrl) ?> - <?= h($env['label']) ?>
</div>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;">Preview Invitations: <?= h($previewData['event']['name']) ?></h2>
  <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <a class="button" href="/admin_event_invite_form.php?event_id=<?= (int)$eventId ?>">← Edit Settings</a>
    <a class="button" href="/event.php?id=<?= (int)$eventId ?>">Back to Event</a>
    <?= EventUIManager::renderAdminMenu((int)$eventId, 'invite') ?>
  </div>
</div>

<div class="card">
  <h3>⚠️ Confirm Email Send</h3>
  <div style="background-color: #f8f9fa; padding: 16px; border-radius: 6px; margin-bottom: 16px;">
    <p><strong>You are about to send:</strong></p>
    <ul style="margin: 8px 0;">
      <li><strong>Subject:</strong> <?= h($previewData['subject']) ?></li>
      <li><strong>To:</strong> <?= (int)$previewData['count'] ?> recipients</li>
      <?php if ($previewData['suppressedCount'] > 0): ?>
        <li><strong>Suppressed (already invited):</strong> <?= (int)$previewData['suppressedCount'] ?> recipients</li>
        <li><strong>Suppression policy:</strong> 
          <?php 
          $policy = $previewData['suppressPolicy'] ?? 'last_24_hours';
          if ($policy === 'last_24_hours') echo 'Don\'t send if invited in last 24 hours';
          elseif ($policy === 'ever_invited') echo 'Don\'t send if ever invited';
          else echo 'No suppression policy';
          ?>
        </li>
      <?php endif; ?>
      <?php if (!empty($previewData['organizer'])): ?>
        <li><strong>Organizer:</strong> <?= h($previewData['organizer']) ?></li>
      <?php endif; ?>
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
    <form method="post" action="admin_event_invite_send_emails.php" style="display: inline;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
      <?php foreach ($previewData['emailIds'] as $emailId): ?>
        <input type="hidden" name="email_ids[]" value="<?= (int)$emailId ?>">
      <?php endforeach; ?>
      <input type="hidden" name="subject" value="<?= h($previewData['subject']) ?>">
      <input type="hidden" name="organizer" value="<?= h($previewData['organizer']) ?>">
      <input type="hidden" name="suppressed_count" value="<?= (int)$previewData['suppressedCount'] ?>">
      <input type="hidden" name="suppress_policy" value="<?= h($previewData['suppressPolicy']) ?>">
      <button type="submit" class="primary button" style="background-color: #dc2626; color: white;">
        Send <?= (int)$previewData['count'] ?> Invitations Now
      </button>
    </form>
    <a class="button" href="/admin_event_invite_form.php?event_id=<?= (int)$eventId ?>">← Go Back to Edit</a>
  </div>
</div>

<?php 
  // Use shared EmailPreviewUI for consistent preview across all pages
  EmailPreviewUI::renderEmailPreview($event, $eventId, $baseUrl, $siteTitle, $previewData['description'], $previewData['emailType']);
?>

<?php if ($isAdmin): ?>
  <?= EventUIManager::renderAdminModals((int)$eventId) ?>
  <?= EventUIManager::renderAdminMenuScript((int)$eventId) ?>
<?php endif; ?>

<?php footer_html(); ?>
