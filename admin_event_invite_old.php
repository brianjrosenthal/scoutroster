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
require_once __DIR__ . '/settings.php';

if (!defined('INVITE_HMAC_KEY') || INVITE_HMAC_KEY === '') {
  header_html('Invitations');
  echo '<h2>Invitations</h2><div class="card"><p class="error">INVITE_HMAC_KEY is not configured. Edit config.local.php.</p></div>';
  echo '<div class="card"><a class="button" href="/events.php">Back</a></div>';
  footer_html();
  exit;
}

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : (int)($_POST['event_id'] ?? 0);
if ($eventId <= 0) { http_response_code(400); exit('Missing event_id'); }

// Load event
$event = EventManagement::findById($eventId);
if (!$event) { http_response_code(404); exit('Event not found'); }

// Helpers
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
  try {
    $pdo = pdo();
    $stmt = $pdo->prepare('SELECT answer FROM rsvps WHERE event_id = ? AND created_by_user_id = ? LIMIT 1');
    $stmt->execute([$eventId, $userId]);
    $result = $stmt->fetchColumn();
    return $result ?: null;
  } catch (Throwable $e) {
    return null;
  }
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
      If the button does not work, open this link: <br><a href="'. $safeDeep .'">'. $safeDeep .'</a>
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

$me = current_user();
$defaultOrganizer = trim((string)($me['email'] ?? ''));
$siteTitle = Settings::siteTitle();
$subjectDefault = 'Please RSVP to ' . (string)$event['name'];

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . $host;

// POST handling
$sentResults = null;
$error = null;
$previewRecipients = null;
$previewData = null;

// Handle AJAX request for recipient count
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'count_recipients') {
  header('Content-Type: application/json');
  
  try {
    require_csrf();
    
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
    
    $count = UserManagement::countAdultsWithFilters($filters);
    
    echo json_encode(['success' => true, 'count' => $count]);
    exit;
    
  } catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
  }
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

// Handle preview step
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
  try {
    require_csrf();

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

    // Build filters from new form structure
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

    // Get recipients using new filter system
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

    // Store data in session for GET-based confirmation and sending
    $token = bin2hex(random_bytes(16)); // One-time use token
    $_SESSION['email_send_data'] = [
      'event_id' => $eventId,
      'filters' => $filters,
      'subject' => $subject,
      'organizer' => $organizer,
      'description' => $description,
      'email_type' => $emailType,
      'suppress_policy' => $suppressPolicy,
      'recipients' => $filteredRecipients,
      'suppressed_count' => $suppressedCount,
      'count' => count($filteredRecipients),
      'token' => $token,
      'created_at' => time()
    ];

    // Redirect to GET-based confirmation page
    header('Location: /admin_event_invite.php?event_id=' . $eventId . '&action=confirm&token=' . $token);
    exit;

  } catch (Throwable $e) {
    $error = $e->getMessage() ?: 'Failed to generate recipient list.';
  }
}

// Handle GET-based confirmation page
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'confirm') {
  $token = $_GET['token'] ?? '';
  
  // Validate session and token
  if (!isset($_SESSION['email_send_data']) || $_SESSION['email_send_data']['token'] !== $token) {
    // Invalid or expired session
    $error = 'Session expired or invalid. Please start over.';
  } else {
    // Check session timeout (30 minutes)
    $sessionAge = time() - $_SESSION['email_send_data']['created_at'];
    if ($sessionAge > 1800) { // 30 minutes
      unset($_SESSION['email_send_data']);
      $error = 'Session expired. Please start over.';
    } else {
      // Load data from session for confirmation page
      $previewData = $_SESSION['email_send_data'];
      $previewRecipients = $previewData['recipients'];
    }
  }
}

// Handle GET-based real-time email sending
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'send') {
  $token = $_GET['token'] ?? '';
  
  // Validate session and token
  if (!isset($_SESSION['email_send_data']) || $_SESSION['email_send_data']['token'] !== $token) {
    // Invalid or already used session
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h2>Error: Invalid or expired session</h2>';
    echo '<p>The send request is invalid or has already been used. Please <a href="/admin_event_invite.php?event_id=' . (int)$eventId . '">start over</a>.</p>';
    exit;
  }
  
  // Clear session data immediately to prevent reuse
  $sendData = $_SESSION['email_send_data'];
  unset($_SESSION['email_send_data']);
  
  // Disable all output buffering for real-time streaming
  while (ob_get_level()) {
    ob_end_clean();
  }
  
  // Send headers for real-time streaming
  header('Content-Type: text/html; charset=UTF-8');
  header('Cache-Control: no-cache, must-revalidate, no-store');
  header('Pragma: no-cache');
  header('Expires: 0');
  header('X-Accel-Buffering: no'); // Disable Nginx buffering
  header('Connection: keep-alive');
  
  // Send initial HTML with padding to trigger browser display
  echo str_repeat(' ', 1024); // 1KB padding for browser compatibility
  echo '<!DOCTYPE html><html><head><title>Sending Invitations</title>';
  echo '<style>body{font-family:system-ui,sans-serif;margin:20px;} .progress{margin:8px 0;} .success{color:#28a745;} .error{color:#dc3545;} .summary{background:#f8f9fa;padding:15px;border-radius:5px;margin-top:20px;}</style>';
  echo '</head><body>';
  echo '<h2>Sending Event Invitations</h2>';
  
  // Show debug mode indicator if active
  if (defined('EMAIL_DEBUG_MODE') && EMAIL_DEBUG_MODE === true) {
    echo '<div class="debug-notice" style="background: #fff3cd; color: #856404; padding: 12px; margin-bottom: 15px; border-radius: 5px; border: 1px solid #ffeaa7;">';
    echo '🐛 <strong>DEBUG MODE ACTIVE</strong> - Emails are being simulated, not actually sent. Each email will take 2 seconds with occasional simulated failures.';
    echo '</div>';
  }
  
  echo '<div class="progress-container">';
  flush();
  
  try {
    // Extract data from session
    $subject = $sendData['subject'];
    $organizer = $sendData['organizer'];
    $description = $sendData['description'];
    $emailType = $sendData['email_type'];
    $filteredRecipients = $sendData['recipients'];
    $suppressedCount = $sendData['suppressed_count'];

    if (empty($filteredRecipients)) {
      throw new RuntimeException('No recipients to send to.');
    }

    // Build ICS (same for all recipients)
    $tzId = Settings::timezoneId();
    $eventUrl = $baseUrl . '/event.php?id=' . (int)$eventId;

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

    // Calendar links (UTC Z strings)
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

    // Email HTML template
    $whenText = Settings::formatDateTimeRange((string)$event['starts_at'], !empty($event['ends_at']) ? (string)$event['ends_at'] : null);
    $locName = trim((string)($event['location'] ?? ''));
    $locAddr = trim((string)($event['location_address'] ?? ''));
    $locCombined = ($locName !== '' && $locAddr !== '') ? ($locName . "\n" . $locAddr) : ($locAddr !== '' ? $locAddr : $locName);
    $whereHtml = $locCombined !== '' ? nl2br(htmlspecialchars($locCombined, ENT_QUOTES, 'UTF-8')) : '';

    // Real-time email sending with tracking
    $sent = 0;
    $fail = 0;
    $skipped = 0;
    
    echo '<p>Starting to send ' . count($filteredRecipients) . ' invitations...</p>';
    flush();
    
    foreach ($filteredRecipients as $index => $r) {
      $uid = (int)$r['id'];
      $email = trim((string)$r['email']);
      $name  = trim(((string)($r['first_name'] ?? '')).' '.((string)($r['last_name'] ?? '')));
      $displayName = $name ?: 'Unknown';
      
      // Output real-time progress with better formatting
      echo '<div class="progress">';
      echo '<strong>' . ($index + 1) . '/' . count($filteredRecipients) . ':</strong> ';
      echo 'Sending to ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
      echo ' &lt;' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '&gt;... ';
      flush();
      
      $sig = invite_signature_build($uid, $eventId);
      $deepLink = $baseUrl . '/event_invite.php?uid=' . $uid . '&event_id=' . $eventId . '&sig=' . $sig;

      // Use shared email template function
      $html = generateEmailHTML($event, $siteTitle, $baseUrl, $deepLink, $whenText, $whereHtml, $description, $googleLink, $outlookLink, $icsDownloadLink, $emailType, $eventId, $uid, true);

      // Use the detailed email function to get specific error information
      $result = send_email_with_ics_detailed($email, $subject, $html, $icsContent, 'event_'.$eventId.'.ics', $name !== '' ? $name : $email);
      
      if ($result['success']) { 
        $sent++;
        echo '<span class="success">✓ sent successfully</span>';
        
        // Record the invitation in tracking table (skip in debug mode since no real email was sent)
        if (!defined('EMAIL_DEBUG_MODE') || EMAIL_DEBUG_MODE !== true) {
          try {
            EventInvitationTracking::recordInvitationSent($eventId, $uid);
          } catch (Throwable $trackingError) {
            // Don't fail the whole process if tracking fails, but log it
            error_log("Failed to record invitation tracking for user $uid, event $eventId: " . $trackingError->getMessage());
          }
        }
      } else {
        $fail++;
        $errorMsg = $result['error'] ?? 'Unknown error';
        echo '<span class="error">✗ failed: ' . htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') . '</span>';
        
        // Log the specific error to the error log as well
        error_log("Email send failed to $email for event $eventId: $errorMsg");
      }
      
      echo '</div>';
      flush();
      
      // Add a small delay to make the progress visible (optional)
      usleep(100000); // 0.1 second delay
    }

    // Final summary with styling
    echo '</div>'; // Close progress-container
    echo '<div class="summary">';
    echo '<h3>📧 Email Sending Complete!</h3>';
    echo '<p><strong>Total recipients:</strong> ' . count($filteredRecipients) . '</p>';
    echo '<p><strong>Successfully sent:</strong> <span class="success">' . $sent . '</span></p>';
    if ($fail > 0) {
      echo '<p><strong>Failed:</strong> <span class="error">' . $fail . '</span></p>';
    }
    if ($suppressedCount > 0) {
      echo '<p><strong>Suppressed (already invited):</strong> ' . $suppressedCount . '</p>';
    }
    echo '<div style="margin-top: 20px;">';
    echo '<a href="/admin_event_invite.php?event_id=' . (int)$eventId . '" class="button" style="margin-right: 10px;">← Back to Event Invitations</a>';
    echo '<a href="/event.php?id=' . (int)$eventId . '" class="button">Back to Event</a>';
    echo '</div>';
    echo '</div>';
    echo '</body></html>';
    
    flush();
    exit; // Exit here to prevent normal page rendering
    
  } catch (Throwable $e) {
    echo '</div>'; // Close progress-container
    echo '<div class="summary">';
    echo '<h3>❌ Error</h3>';
    echo '<p class="error">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<div style="margin-top: 20px;">';
    echo '<a href="/admin_event_invite.php?event_id=' . (int)$eventId . '" class="button">← Back to Event Invitations</a>';
    echo '</div>';
    echo '</div>';
    echo '</body></html>';
    flush();
    exit;
  }
}

// Handle old POST send for backwards compatibility (can be removed later)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
  // Disable all output buffering for real-time streaming
  while (ob_get_level()) {
    ob_end_clean();
  }
  
  // Send headers for real-time streaming
  header('Content-Type: text/html; charset=UTF-8');
  header('Cache-Control: no-cache, must-revalidate, no-store');
  header('Pragma: no-cache');
  header('Expires: 0');
  header('X-Accel-Buffering: no'); // Disable Nginx buffering
  header('Connection: keep-alive');
  
  // Send initial HTML with padding to trigger browser display
  echo str_repeat(' ', 1024); // 1KB padding for browser compatibility
  echo '<!DOCTYPE html><html><head><title>Sending Invitations</title>';
  echo '<style>body{font-family:system-ui,sans-serif;margin:20px;} .progress{margin:8px 0;} .success{color:#28a745;} .error{color:#dc3545;} .summary{background:#f8f9fa;padding:15px;border-radius:5px;margin-top:20px;}</style>';
  echo '</head><body>';
  echo '<h2>Sending Event Invitations</h2>';
  
  // Show debug mode indicator if active
  if (defined('EMAIL_DEBUG_MODE') && EMAIL_DEBUG_MODE === true) {
    echo '<div class="debug-notice" style="background: #fff3cd; color: #856404; padding: 12px; margin-bottom: 15px; border-radius: 5px; border: 1px solid #ffeaa7;">';
    echo '🐛 <strong>DEBUG MODE ACTIVE</strong> - Emails are being simulated, not actually sent. Each email will take 2 seconds with occasional simulated failures.';
    echo '</div>';
  }
  
  flush();
  
  try {
    require_csrf();

    $subject = trim((string)($_POST['subject'] ?? $subjectDefault));
    $organizer = trim((string)($_POST['organizer'] ?? $defaultOrganizer));
    $description = trim((string)($_POST['description'] ?? ''));
    $emailType = $_POST['email_type'] ?? 'none';
    $suppressPolicy = $_POST['suppress_policy'] ?? 'last_24_hours';

    if ($subject === '') $subject = $subjectDefault;
    
    // Generate dynamic subject line based on email type
    $subject = generateSubjectLine($emailType, $event, $subject, $subjectDefault);
    if ($organizer !== '' && !filter_var($organizer, FILTER_VALIDATE_EMAIL)) {
      throw new RuntimeException('Organizer email is invalid.');
    }

    // Build filters from form data (same as preview)
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

    // Get recipients using new filter system
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

    // Build ICS (same for all recipients)
    $tzId = Settings::timezoneId();
    $eventUrl = $baseUrl . '/event.php?id=' . (int)$eventId;

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

    // Calendar links (UTC Z strings)
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

    // Email HTML template
    $whenText = Settings::formatDateTimeRange((string)$event['starts_at'], !empty($event['ends_at']) ? (string)$event['ends_at'] : null);
    $locName = trim((string)($event['location'] ?? ''));
    $locAddr = trim((string)($event['location_address'] ?? ''));
    $locCombined = ($locName !== '' && $locAddr !== '') ? ($locName . "\n" . $locAddr) : ($locAddr !== '' ? $locAddr : $locName);
    $whereHtml = $locCombined !== '' ? nl2br(htmlspecialchars($locCombined, ENT_QUOTES, 'UTF-8')) : '';

    // Real-time email sending with tracking
    $sent = 0;
    $fail = 0;
    $skipped = 0;
    
    echo '<p>Starting to send ' . count($filteredRecipients) . ' invitations...</p>';
    flush();
    
    foreach ($filteredRecipients as $index => $r) {
      $uid = (int)$r['id'];
      $email = trim((string)$r['email']);
      $name  = trim(((string)($r['first_name'] ?? '')).' '.((string)($r['last_name'] ?? '')));
      $displayName = $name ?: 'Unknown';
      
      // Output real-time progress with better formatting
      echo '<div class="progress">';
      echo '<strong>' . ($index + 1) . '/' . count($filteredRecipients) . ':</strong> ';
      echo 'Sending to ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
      echo ' &lt;' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '&gt;... ';
      flush();
      
      $sig = invite_signature_build($uid, $eventId);
      $deepLink = $baseUrl . '/event_invite.php?uid=' . $uid . '&event_id=' . $eventId . '&sig=' . $sig;

      // Use shared email template function
      $html = generateEmailHTML($event, $siteTitle, $baseUrl, $deepLink, $whenText, $whereHtml, $description, $googleLink, $outlookLink, $icsDownloadLink, $emailType, $eventId, $uid, true);

      // Use the detailed email function to get specific error information
      $result = send_email_with_ics_detailed($email, $subject, $html, $icsContent, 'event_'.$eventId.'.ics', $name !== '' ? $name : $email);
      
      if ($result['success']) { 
        $sent++;
        echo '<span class="success">✓ sent successfully</span>';
        
        // Record the invitation in tracking table (skip in debug mode since no real email was sent)
        if (!defined('EMAIL_DEBUG_MODE') || EMAIL_DEBUG_MODE !== true) {
          try {
            EventInvitationTracking::recordInvitationSent($eventId, $uid);
          } catch (Throwable $trackingError) {
            // Don't fail the whole process if tracking fails, but log it
            error_log("Failed to record invitation tracking for user $uid, event $eventId: " . $trackingError->getMessage());
          }
        }
      } else {
        $fail++;
        $errorMsg = $result['error'] ?? 'Unknown error';
        echo '<span class="error">✗ failed: ' . htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') . '</span>';
        
        // Log the specific error to the error log as well
        error_log("Email send failed to $email for event $eventId: $errorMsg");
      }
      
      echo '</div>';
      flush();
      
      // Add a small delay to make the progress visible (optional)
      usleep(100000); // 0.1 second delay
    }

    // Final summary with styling
    echo '<div class="summary">';
    echo '<h3>📧 Email Sending Complete!</h3>';
    echo '<p><strong>Total recipients:</strong> ' . count($filteredRecipients) . '</p>';
    echo '<p><strong>Successfully sent:</strong> <span class="success">' . $sent . '</span></p>';
    if ($fail > 0) {
      echo '<p><strong>Failed:</strong> <span class="error">' . $fail . '</span></p>';
    }
    if ($suppressedCount > 0) {
      echo '<p><strong>Suppressed (already invited):</strong> ' . $suppressedCount . '</p>';
    }
    echo '<div style="margin-top: 20px;">';
    echo '<a href="/admin_event_invite.php?event_id=' . (int)$eventId . '" class="button" style="margin-right: 10px;">← Back to Event Invitations</a>';
    echo '<a href="/event.php?id=' . (int)$eventId . '" class="button">Back to Event</a>';
    echo '</div>';
    echo '</div>';
    echo '</body></html>';
    
    flush();
    exit; // Exit here to prevent normal page rendering
    
  } catch (Throwable $e) {
    ob_clean(); // Clear any output buffer
    $error = $e->getMessage() ?: 'Failed to send invitations.';
    // Don't exit here, let normal error handling take over
  }
}

$me = current_user();
$isAdmin = !empty($me['is_admin']);

header_html('Send Event Invitations');
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;">Send Invitations: <?= h($event['name']) ?></h2>
  <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <a class="button" href="/event.php?id=<?= (int)$eventId ?>">Back to Event</a>
    <?= EventUIManager::renderAdminMenu((int)$eventId, 'invite') ?>
  </div>
</div>

<?php if ($previewRecipients): ?>
  <!-- Confirmation Page -->
  <?php $env = detectEnvironment($host); ?>
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
  
  <div class="card">
    <h3>⚠️ Confirm Email Send</h3>
    <div style="background-color: #f8f9fa; padding: 16px; border-radius: 6px; margin-bottom: 16px;">
      <p><strong>You are about to send:</strong></p>
      <ul style="margin: 8px 0;">
        <li><strong>Subject:</strong> <?= h($previewData['subject']) ?></li>
        <li><strong>To:</strong> <?= (int)$previewData['count'] ?> recipients</li>
        <?php if ($previewData['suppressed_count'] > 0): ?>
          <li><strong>Suppressed (already invited):</strong> <?= (int)$previewData['suppressed_count'] ?> recipients</li>
          <li><strong>Suppression policy:</strong> 
            <?php 
            $policy = $previewData['suppress_policy'] ?? 'last_24_hours';
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
    
    <h4>Recipients (<?= count($previewRecipients) ?>)</h4>
    <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">
      <table class="recipients-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($previewRecipients as $recipient): ?>
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
      <a class="primary button" href="/admin_event_invite.php?event_id=<?= (int)$eventId ?>&action=send&token=<?= h($previewData['token']) ?>" style="background-color: #dc2626; color: white; text-decoration: none;" id="confirmSendBtn">Confirm & Send <?= (int)$previewData['count'] ?> Invitations</a>
      <a class="button" href="/admin_event_invite.php?event_id=<?= (int)$eventId ?>">← Go Back to Edit</a>
    </div>
  </div>
<?php else: ?>
  <!-- Initial Form -->
  <div class="card">
    <p class="small" style="margin-top:0;">Compose and send personalized RSVP invitations for this event. Each email includes a one-click RSVP link and an attached calendar invite (.ics).</p>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="preview">
      <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">

      <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>

    <fieldset>
      <legend>Filter adults by:</legend>
      <?php
        $regStatus = $_POST['registration_status'] ?? 'all';
        $selectedGrades = $_POST['grades'] ?? [];
        $rsvpStatus = $_POST['rsvp_status'] ?? 'all';
        $specificAdultIds = $_POST['specific_adult_ids'] ?? [];
        
        // Normalize arrays from form data
        if (is_string($selectedGrades)) {
          $selectedGrades = explode(',', $selectedGrades);
        }
        $selectedGrades = array_filter(array_map('intval', (array)$selectedGrades));
        
        if (is_string($specificAdultIds)) {
          $specificAdultIds = explode(',', $specificAdultIds);
        }
        $specificAdultIds = array_filter(array_map('intval', (array)$specificAdultIds));
      ?>
      
      <div style="margin-bottom: 16px;">
        <label><strong>Registration status:</strong></label>
        <div style="margin-left: 16px;">
          <label class="inline"><input type="radio" name="registration_status" value="all" <?= $regStatus==='all'?'checked':'' ?>> All</label>
          <label class="inline"><input type="radio" name="registration_status" value="registered" <?= $regStatus==='registered'?'checked':'' ?>> Registered only</label>
          <label class="inline"><input type="radio" name="registration_status" value="unregistered" <?= $regStatus==='unregistered'?'checked':'' ?>> Unregistered only</label>
        </div>
      </div>

      <div style="margin-bottom: 16px;">
        <label><strong>Grade:</strong></label>
        <div style="margin-left: 16px;">
          <label style="display: inline-block;"><input type="checkbox" name="grades[]" value="0" <?= in_array(0, $selectedGrades)?'checked':'' ?>> K</label><?php for($i=1;$i<=5;$i++): ?><label style="display: inline-block;"><input type="checkbox" name="grades[]" value="<?= $i ?>" <?= in_array($i, $selectedGrades)?'checked':'' ?>> <?= $i ?></label><?php endfor; ?>
          <br><span class="small">(Select multiple grades to include families with children in any of those grades)</span>
        </div>
      </div>

      <div style="margin-bottom: 16px;">
        <label><strong>RSVP'd:</strong></label>
        <div style="margin-left: 16px;">
          <label class="inline"><input type="radio" name="rsvp_status" value="all" <?= $rsvpStatus==='all'?'checked':'' ?>> All</label>
          <label class="inline"><input type="radio" name="rsvp_status" value="not_rsvped" <?= $rsvpStatus==='not_rsvped'?'checked':'' ?>> People who have not RSVP'd</label>
        </div>
      </div>

      <div style="margin-bottom: 16px;">
        <label><strong>Specific adults:</strong></label>
        <div style="margin-left: 16px;">
          <div id="specificAdultsContainer">
            <?php if (!empty($specificAdultIds)): ?>
              <?php foreach ($specificAdultIds as $adultId): ?>
                <?php 
                  $adult = UserManagement::findBasicForEmailingById($adultId);
                  if ($adult):
                    $name = trim(($adult['first_name'] ?? '') . ' ' . ($adult['last_name'] ?? ''));
                    $displayName = $name ?: 'User #' . $adultId;
                    if (!empty($adult['email'])) $displayName .= ' <' . $adult['email'] . '>';
                ?>
                  <div class="selected-adult" data-adult-id="<?= $adultId ?>">
                    <span><?= h($displayName) ?></span>
                    <button type="button" class="remove-adult" style="margin-left: 8px; padding: 2px 6px; font-size: 12px;">×</button>
                    <input type="hidden" name="specific_adult_ids[]" value="<?= $adultId ?>">
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div class="typeahead" style="margin-top: 8px;">
            <input type="text" id="adultTypeahead" placeholder="Type name or email to add specific adults" autocomplete="off">
            <div id="adultTypeaheadResults" class="typeahead-results" role="listbox" style="display:none;"></div>
          </div>
          <span class="small">Add specific adults to include regardless of other filters</span>
        </div>
      </div>

      <div style="margin-bottom: 16px;">
        <label><strong>Suppress duplicate policy:</strong></label>
        <div style="margin-left: 16px;">
          <?php $suppressPolicy = $_POST['suppress_policy'] ?? 'last_24_hours'; ?>
          <label class="inline"><input type="radio" name="suppress_policy" value="last_24_hours" <?= $suppressPolicy==='last_24_hours'?'checked':'' ?>> Don't send if invited in last 24 hours</label>
          <label class="inline"><input type="radio" name="suppress_policy" value="ever_invited" <?= $suppressPolicy==='ever_invited'?'checked':'' ?>> Don't send if ever invited</label>
          <label class="inline"><input type="radio" name="suppress_policy" value="none" <?= $suppressPolicy==='none'?'checked':'' ?>> No suppression policy</label>
          <span class="small">Choose how to handle users who have already been invited to this event</span>
        </div>
      </div>
    </fieldset>

    <div style="margin-bottom: 16px;">
      <label><strong>Email Type:</strong></label>
      <div style="margin-left: 16px;">
        <?php $emailType = $_POST['email_type'] ?? 'none'; ?>
        <label class="inline"><input type="radio" name="email_type" value="none" <?= $emailType==='none'?'checked':'' ?>> No introduction (default)</label>
        <label class="inline"><input type="radio" name="email_type" value="invitation" <?= $emailType==='invitation'?'checked':'' ?>> Initial invitation ("You're Invited to...")</label>
        <label class="inline"><input type="radio" name="email_type" value="reminder" <?= $emailType==='reminder'?'checked':'' ?>> Reminder (context-aware based on RSVP status)</label>
        <span class="small">Choose the type of introduction text to appear above the event title</span>
      </div>
    </div>

    <label>Subject
      <input type="text" name="subject" value="<?= h($_POST['subject'] ?? $subjectDefault) ?>">
    </label>

    <label>Organizer Email (optional, used in calendar invite)
      <input type="email" name="organizer" value="<?= h($_POST['organizer'] ?? $defaultOrganizer) ?>">
    </label>

    <?php
      // Default body content with markdown formatting
      $defaultBody = '';
      if (!empty($event['description'])) {
        $defaultBody = '**Description:** ' . trim((string)$event['description']);
      }
      $currentBody = $_POST['description'] ?? $defaultBody;
    ?>
    <label>Email Body (appears below the When/Where box)
      <textarea name="description" rows="6" placeholder="Enter custom body content for this invitation..."><?= h($currentBody) ?></textarea>
      <span class="small">This content will appear in the email body below the When/Where information. Supports markdown formatting (e.g., **bold**, *italic*).</span>
    </label>

    <div class="actions">
      <button class="primary" id="previewRecipientsBtn">Preview Recipients (<span id="recipientCount">0</span>)</button>
      <a class="button" href="/event.php?id=<?= (int)$eventId ?>">Back to Event</a>
      <a class="button" href="/events.php">Manage Events</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($sentResults): ?>
  <div class="card">
    <h3>Results</h3>
    <p>Recipients: <?= (int)$sentResults['count'] ?> &nbsp;&nbsp; Sent: <?= (int)$sentResults['sent'] ?> &nbsp;&nbsp; Failed: <?= (int)$sentResults['failed'] ?></p>
    <details>
      <summary class="small">Show recipient list</summary>
      <ul class="small">
        <?php foreach ($sentResults['recipients'] as $addr): ?>
          <li><?= h($addr) ?></li>
        <?php endforeach; ?>
      </ul>
    </details>
  </div>
<?php endif; ?>

<div class="card">
  <h3>Email Preview</h3>
  
  <div style="margin-bottom: 16px; padding: 12px; background-color: #f8f9fa; border-radius: 6px;">
    <label><strong>Preview as:</strong></label>
    <div style="margin-left: 16px; margin-top: 8px;">
      <label class="inline">
        <input type="radio" name="preview_rsvp_status" value="never" checked> Never RSVP'd
      </label>
      <label class="inline">
        <input type="radio" name="preview_rsvp_status" value="yes"> RSVP'd previously Yes
      </label>
    </div>
  </div>
  
  <div id="emailPreviewContent">
    <?php
      // Generate preview using the same template as actual emails
      $whenText = Settings::formatDateTimeRange((string)$event['starts_at'], !empty($event['ends_at']) ? (string)$event['ends_at'] : null);
      $locName = trim((string)($event['location'] ?? ''));
      $locAddr = trim((string)($event['location_address'] ?? ''));
      $locCombined = ($locName !== '' && $locAddr !== '') ? ($locName . "\n" . $locAddr) : ($locAddr !== '' ? $locAddr : $locName);
      $whereHtml = $locCombined !== '' ? nl2br(htmlspecialchars($locCombined, ENT_QUOTES, 'UTF-8')) : '';
      
      $previewDescription = $previewData ? $previewData['description'] : '';
      $previewEmailType = $previewData ? $previewData['email_type'] : 'none';
      
      // Use placeholder links for preview
      $previewDeepLink = $baseUrl . '/event.php?id=' . (int)$eventId;
      $googleLink = '#';
      $outlookLink = '#';
      $icsDownloadLink = '#';
      
      // Generate preview HTML without calendar links (default: never RSVP'd)
      $previewHtml = generateEmailHTML($event, $siteTitle, $baseUrl, $previewDeepLink, $whenText, $whereHtml, $previewDescription, $googleLink, $outlookLink, $icsDownloadLink, $previewEmailType, $eventId, 0, false);
      
      echo $previewHtml;
    ?>
  </div>
</div>

<?php if ($isAdmin): ?>
  <?= EventUIManager::renderAdminModals((int)$eventId) ?>
  <?= EventUIManager::renderAdminMenuScript((int)$eventId) ?>
<?php endif; ?>

<style>
.selected-adult {
    display: inline-block;
    background: #e9ecef;
    border: 1px solid #ced4da;
    border-radius: 4px;
    padding: 4px 8px;
    margin: 2px;
    font-size: 14px;
}
.selected-adult .remove-adult {
    background: none;
    border: none;
    color: #dc3545;
    cursor: pointer;
    font-weight: bold;
}
.selected-adult .remove-adult:hover {
    color: #a71e2a;
}
.typeahead-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #ced4da;
    border-top: none;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
}
.typeahead-result {
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 1px solid #e9ecef;
}
.typeahead-result:hover {
    background: #f8f9fa;
}
.typeahead-result:last-child {
    border-bottom: none;
}
.typeahead {
    position: relative;
}
</style>

<script>
(function() {
    // Handle both preview and confirmation buttons
    const previewBtn = document.getElementById('previewRecipientsBtn');
    const confirmBtn = document.getElementById('confirmSendBtn');
    const recipientCountSpan = document.getElementById('recipientCount');
    const form = previewBtn ? previewBtn.closest('form') : null;
    let isSubmitting = false;
    let countTimeout = null;

    // Dynamic subject line updating based on email type
    const emailTypeRadios = document.querySelectorAll('input[name="email_type"]');
    const subjectField = document.querySelector('input[name="subject"]');
    const defaultSubject = '<?= addslashes($subjectDefault) ?>';
    const eventName = '<?= addslashes((string)$event['name']) ?>';
    const eventDateTime = '<?= addslashes(date('D n/j \\a\\t g:i A', strtotime((string)$event['starts_at']))) ?>';
    
    function updateSubjectField() {
        if (!subjectField) return;
        
        const selectedType = document.querySelector('input[name="email_type"]:checked')?.value || 'none';
        let newSubject = defaultSubject;
        
        if (selectedType === 'invitation') {
            newSubject = `You're invited to "${eventName}", ${eventDateTime}`;
        } else if (selectedType === 'reminder') {
            newSubject = `Reminder: "${eventName}", ${eventDateTime}`;
        }
        
        // Only update if the current subject matches a generated pattern or is the default
        const currentSubject = subjectField.value.trim();
        const isDefaultSubject = currentSubject === defaultSubject;
        const isGeneratedSubject = currentSubject.startsWith("You're invited to \"") || 
                                 currentSubject.startsWith("Reminder: \"");
        
        if (isDefaultSubject || isGeneratedSubject) {
            subjectField.value = newSubject;
        }
    }
    
    // Add event listeners to email type radio buttons
    emailTypeRadios.forEach(radio => {
        radio.addEventListener('change', updateSubjectField);
    });
    
    // Initialize subject on page load
    updateSubjectField();

    // Real-time recipient counting
    function updateRecipientCount() {
        if (!form || !recipientCountSpan) return;
        
        clearTimeout(countTimeout);
        countTimeout = setTimeout(() => {
            const formData = new FormData(form);
            formData.set('action', 'count_recipients');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    recipientCountSpan.textContent = data.count;
                } else {
                    console.error('Error counting recipients:', data.error);
                    recipientCountSpan.textContent = '?';
                }
            })
            .catch(error => {
                console.error('Error counting recipients:', error);
                recipientCountSpan.textContent = '?';
            });
        }, 300); // Debounce for 300ms
    }

    // Add event listeners to all filter inputs
    if (form) {
        const filterInputs = form.querySelectorAll('input[name="registration_status"], input[name="grades[]"], input[name="rsvp_status"]');
        filterInputs.forEach(input => {
            input.addEventListener('change', updateRecipientCount);
        });
        
        // Initial count
        updateRecipientCount();
    }

    // Specific adults management
    const adultTypeahead = document.getElementById('adultTypeahead');
    const adultResults = document.getElementById('adultTypeaheadResults');
    const adultsContainer = document.getElementById('specificAdultsContainer');
    let searchTimeout = null;

    if (adultTypeahead && adultResults && adultsContainer) {
        // Typeahead search
        adultTypeahead.addEventListener('input', function() {
            const query = this.value.trim();
            
            clearTimeout(searchTimeout);
            
            if (query.length < 2) {
                adultResults.style.display = 'none';
                return;
            }
            
            searchTimeout = setTimeout(() => {
                fetch('/ajax_search_adults.php?q=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        adultResults.innerHTML = '';
                        
                        if (data.length === 0) {
                            adultResults.innerHTML = '<div class="typeahead-result">No adults found</div>';
                        } else {
                            data.forEach(adult => {
                                const div = document.createElement('div');
                                div.className = 'typeahead-result';
                                div.textContent = adult.last_name + ', ' + adult.first_name + (adult.email ? ' <' + adult.email + '>' : '');
                                div.dataset.adultId = adult.id;
                                div.dataset.adultName = adult.first_name + ' ' + adult.last_name;
                                div.dataset.adultEmail = adult.email || '';
                                
                                div.addEventListener('click', function() {
                                    addSpecificAdult(adult.id, adult.first_name + ' ' + adult.last_name, adult.email);
                                    adultTypeahead.value = '';
                                    adultResults.style.display = 'none';
                                });
                                
                                adultResults.appendChild(div);
                            });
                        }
                        
                        adultResults.style.display = 'block';
                    })
                    .catch(error => {
                        console.error('Error searching adults:', error);
                        adultResults.innerHTML = '<div class="typeahead-result">Error searching</div>';
                        adultResults.style.display = 'block';
                    });
            }, 300);
        });

        // Hide results when clicking outside
        document.addEventListener('click', function(e) {
            if (!adultTypeahead.contains(e.target) && !adultResults.contains(e.target)) {
                adultResults.style.display = 'none';
            }
        });

        // Add specific adult
        function addSpecificAdult(id, name, email) {
            // Check if already added
            if (adultsContainer.querySelector(`[data-adult-id="${id}"]`)) {
                return;
            }

            const displayName = name + (email ? ' <' + email + '>' : '');
            
            const div = document.createElement('div');
            div.className = 'selected-adult';
            div.dataset.adultId = id;
            div.innerHTML = `
                <span>${displayName}</span>
                <button type="button" class="remove-adult" style="margin-left: 8px; padding: 2px 6px; font-size: 12px;">×</button>
                <input type="hidden" name="specific_adult_ids[]" value="${id}">
            `;
            
            div.querySelector('.remove-adult').addEventListener('click', function() {
                div.remove();
                updateRecipientCount(); // Update count when removing adult
            });
            
            adultsContainer.appendChild(div);
            updateRecipientCount(); // Update count when adding adult
        }

        // Handle existing remove buttons
        adultsContainer.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-adult')) {
                e.target.closest('.selected-adult').remove();
                updateRecipientCount();
            }
        });
    }
    
    // Preview button handler
    if (previewBtn) {
        const previewForm = previewBtn.closest('form');
        if (previewForm) {
            previewForm.addEventListener('submit', function(e) {
                if (isSubmitting) {
                    e.preventDefault();
                    return false;
                }
                
                isSubmitting = true;
                previewBtn.disabled = true;
                previewBtn.textContent = 'Loading Recipients...';
                previewBtn.style.opacity = '0.6';
                previewBtn.style.cursor = 'not-allowed';
                
                const loadingSpinner = document.createElement('span');
                loadingSpinner.innerHTML = ' ⏳';
                loadingSpinner.style.marginLeft = '4px';
                previewBtn.appendChild(loadingSpinner);
                
                return true;
            });
            
            previewBtn.addEventListener('click', function(e) {
                if (isSubmitting) {
                    e.preventDefault();
                    return false;
                }
            });
        }
    }
    
    // Confirmation button handler
    if (confirmBtn) {
        const confirmForm = confirmBtn.closest('form');
        if (confirmForm) {
            confirmForm.addEventListener('submit', function(e) {
                if (isSubmitting) {
                    e.preventDefault();
                    return false;
                }
                
                isSubmitting = true;
                confirmBtn.disabled = true;
                confirmBtn.textContent = 'Sending Invitations...';
                confirmBtn.style.opacity = '0.6';
                confirmBtn.style.cursor = 'not-allowed';
                
                const loadingSpinner = document.createElement('span');
                loadingSpinner.innerHTML = ' ⏳';
                loadingSpinner.style.marginLeft = '4px';
                confirmBtn.appendChild(loadingSpinner);
                
                return true;
            });
            
            confirmBtn.addEventListener('click', function(e) {
                if (isSubmitting) {
                    e.preventDefault();
                    return false;
                }
            });
        }
    }
    
    // Email preview RSVP status handling
    const previewRsvpRadios = document.querySelectorAll('input[name="preview_rsvp_status"]');
    const previewContent = document.getElementById('emailPreviewContent');
    
    function updateEmailPreview() {
        if (!previewContent) return;
        
        const selectedRsvpStatus = document.querySelector('input[name="preview_rsvp_status"]:checked')?.value || 'never';
        
        // Create a mock preview based on the selected RSVP status
        const eventName = '<?= addslashes((string)$event['name']) ?>';
        const siteTitle = '<?= addslashes($siteTitle) ?>';
        const baseUrl = '<?= addslashes($baseUrl) ?>';
        const eventId = <?= (int)$eventId ?>;
        
        // Get current form values for preview - handle both form page and preview page scenarios
        const currentEmailType = document.querySelector('input[name="email_type"]:checked')?.value || 'none';
        
        // Check if we're on the preview page (previewData exists) or the form page
        <?php if ($previewData): ?>
        // We're on the preview page - use PHP previewData
        const currentDescription = <?= json_encode($previewData['description']) ?>;
        const currentEmailTypeFromData = <?= json_encode($previewData['email_type']) ?>;
        // Use the email type from preview data since form fields don't exist on preview page
        const effectiveEmailType = currentEmailTypeFromData;
        <?php else: ?>
        // We're on the form page - get from textarea
        const currentDescription = document.querySelector('textarea[name="description"]')?.value || '';
        const effectiveEmailType = currentEmailType;
        <?php endif; ?>
        
        // Generate introduction text based on email type and mock RSVP status
        let introText = '';
        if (effectiveEmailType === 'invitation') {
            introText = "You're Invited to...";
        } else if (effectiveEmailType === 'reminder') {
            introText = selectedRsvpStatus === 'yes' ? 'Reminder:' : 'Reminder to RSVP for...';
        }
        
        // Generate button HTML based on mock RSVP status
        let buttonHtml = '';
        if (selectedRsvpStatus === 'yes') {
            buttonHtml = `<p style="margin:0 0 10px;color:#222;font-size:16px;font-weight:bold;">You RSVP'd Yes</p>
                         <p style="margin:0 0 16px;">
                           <a href="${baseUrl}/event.php?id=${eventId}" style="display:inline-block;background:#0b5ed7;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;">View Details</a>
                         </p>`;
        } else {
            buttonHtml = `<p style="margin:0 0 16px;">
                           <a href="${baseUrl}/event.php?id=${eventId}" style="display:inline-block;background:#0b5ed7;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;">View & RSVP</a>
                         </p>`;
        }
        
        // Build the complete preview HTML
        const introHtml = introText ? `<p style="margin:0 0 8px;color:#666;font-size:14px;">${introText}</p>` : '';
        const descriptionHtml = currentDescription ? `<div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:0 0 16px;background:#fff;">
                                                        <div>${currentDescription.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>').replace(/\*(.*?)\*/g, '<em>$1</em>')}</div>
                                                      </div>` : '';
        
        const previewHtml = `
        <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:640px;margin:0 auto;padding:16px;color:#222;">
          <div style="text-align:center;">
            ${introHtml}
            <h2 style="margin:0 0 8px;">${eventName}</h2>
            <p style="margin:0 0 16px;color:#444;">${siteTitle}</p>
            ${buttonHtml}
          </div>
          <div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:0 0 16px;background:#fafafa;">
            <div><strong>When:</strong> <?= h(Settings::formatDateTimeRange((string)$event['starts_at'], !empty($event['ends_at']) ? (string)$event['ends_at'] : null)) ?></div>
            <?php if (!empty($event['location'])): ?>
            <div><strong>Where:</strong> <?= h((string)$event['location']) ?></div>
            <?php endif; ?>
          </div>
          ${descriptionHtml}
          <p style="font-size:12px;color:#666;text-align:center;margin:12px 0 0;">
            If the button does not work, open this link: <br><a href="${baseUrl}/event.php?id=${eventId}">${baseUrl}/event.php?id=${eventId}</a>
          </p>
        </div>`;
        
        previewContent.innerHTML = previewHtml;
    }
    
    // Add event listeners to preview RSVP radio buttons
    previewRsvpRadios.forEach(radio => {
        radio.addEventListener('change', updateEmailPreview);
    });
    
    // Add event listeners to form fields that affect preview
    emailTypeRadios.forEach(radio => {
        radio.addEventListener('change', updateEmailPreview);
    });
    
    const descriptionField = document.querySelector('textarea[name="description"]');
    if (descriptionField) {
        descriptionField.addEventListener('input', updateEmailPreview);
    }
    
    // Initialize preview
    updateEmailPreview();

    // Re-enable button if there's an error (page doesn't redirect)
    <?php if ($error): ?>
    setTimeout(function() {
        if (previewBtn) {
            isSubmitting = false;
            previewBtn.disabled = false;
            previewBtn.textContent = 'Preview Recipients (' + (recipientCountSpan ? recipientCountSpan.textContent : '0') + ')';
            previewBtn.style.opacity = '1';
            previewBtn.style.cursor = 'pointer';
            
            const spinner = previewBtn.querySelector('span');
            if (spinner) {
                spinner.remove();
            }
        }
        if (confirmBtn) {
            isSubmitting = false;
            confirmBtn.disabled = false;
            confirmBtn.textContent = confirmBtn.textContent.replace('Sending Invitations...', 'Confirm & Send');
            confirmBtn.style.opacity = '1';
            confirmBtn.style.cursor = 'pointer';
            
            const spinner = confirmBtn.querySelector('span');
            if (spinner) {
                spinner.remove();
            }
        }
    }, 100);
    <?php endif; ?>
})();
</script>

<?php footer_html(); ?>
