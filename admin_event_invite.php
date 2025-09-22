<?php
require_once __DIR__.'/partials.php';
require_admin();

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/EventUIManager.php';
require_once __DIR__ . '/lib/GradeCalculator.php';
require_once __DIR__ . '/lib/Text.php';
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

    $audience = $_POST['audience'] ?? 'all_adults';
    $gradeLbl = trim((string)($_POST['grade'] ?? ''));
    $oneAdultId = (int)($_POST['adult_id'] ?? 0);
    $subject = trim((string)($_POST['subject'] ?? $subjectDefault));
    $organizer = trim((string)($_POST['organizer'] ?? $defaultOrganizer));

    if ($subject === '') $subject = $subjectDefault;
    if ($organizer !== '' && !filter_var($organizer, FILTER_VALIDATE_EMAIL)) {
      throw new RuntimeException('Organizer email is invalid.');
    }

    // Build recipients (same logic as send)
    $recipients = [];
    if ($audience === 'all_adults') {
      $recipients = UserManagement::listAdultsWithAnyEmail();
    } elseif ($audience === 'registered_families') {
      $recipients = UserManagement::listAdultsWithRegisteredChildrenEmails();
    } elseif ($audience === 'by_grade') {
      $classOf = UserManagement::computeClassOfFromGradeLabel($gradeLbl);
      if ($classOf === null) throw new RuntimeException('Grade is required for "families by grade".');
      $recipients = UserManagement::listAdultsByChildClassOfEmails((int)$classOf);
    } elseif ($audience === 'one_adult') {
      if ($oneAdultId <= 0) throw new RuntimeException('Please choose an adult.');
      $row = UserManagement::findBasicForEmailingById($oneAdultId);
      if ($row && !empty($row['email'])) $recipients = [$row];
    } else {
      throw new RuntimeException('Invalid audience.');
    }

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

    // Store preview data
    $previewRecipients = $finalRecipients;
    $previewData = [
      'audience' => $audience,
      'grade' => $gradeLbl,
      'adult_id' => $oneAdultId,
      'subject' => $subject,
      'organizer' => $organizer,
      'count' => count($finalRecipients)
    ];

  } catch (Throwable $e) {
    $error = $e->getMessage() ?: 'Failed to generate recipient list.';
  }
}

// Handle actual send
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
  try {
    require_csrf();

    $audience = $_POST['audience'] ?? 'all_adults';
    $gradeLbl = trim((string)($_POST['grade'] ?? ''));
    $oneAdultId = (int)($_POST['adult_id'] ?? 0);
    $subject = trim((string)($_POST['subject'] ?? $subjectDefault));
    $organizer = trim((string)($_POST['organizer'] ?? $defaultOrganizer));

    if ($subject === '') $subject = $subjectDefault;
    if ($organizer !== '' && !filter_var($organizer, FILTER_VALIDATE_EMAIL)) {
      throw new RuntimeException('Organizer email is invalid.');
    }

    // Build recipients (moved to domain methods)
    $recipients = []; // [ ['id'=>int,'email'=>str,'first_name'=>..., 'last_name'=>...], ... ]
    if ($audience === 'all_adults') {
      $recipients = UserManagement::listAdultsWithAnyEmail();
    } elseif ($audience === 'registered_families') {
      $recipients = UserManagement::listAdultsWithRegisteredChildrenEmails();
    } elseif ($audience === 'by_grade') {
      $classOf = UserManagement::computeClassOfFromGradeLabel($gradeLbl);
      if ($classOf === null) throw new RuntimeException('Grade is required for "families by grade".');
      $recipients = UserManagement::listAdultsByChildClassOfEmails((int)$classOf);
    } elseif ($audience === 'one_adult') {
      if ($oneAdultId <= 0) throw new RuntimeException('Please choose an adult.');
      $row = UserManagement::findBasicForEmailingById($oneAdultId);
      if ($row && !empty($row['email'])) $recipients = [$row];
    } else {
      throw new RuntimeException('Invalid audience.');
    }

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

    $sent = 0;
    $fail = 0;
    $details = [];

    foreach ($finalRecipients as $r) {
      $uid = (int)$r['id'];
      $email = trim((string)$r['email']);
      $name  = trim(((string)($r['first_name'] ?? '')).' '.((string)($r['last_name'] ?? '')));
      $sig = invite_signature_build($uid, $eventId);
      $deepLink = $baseUrl . '/event_invite.php?uid=' . $uid . '&event_id=' . $eventId . '&sig=' . $sig;

      $safeSite = htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8');
      $safeEvent = htmlspecialchars((string)$event['name'], ENT_QUOTES, 'UTF-8');
      $safeWhen = htmlspecialchars($whenText, ENT_QUOTES, 'UTF-8');
      // whereHtml already safely escaped with nl2br(htmlspecialchars(...))
      $safeDeep = htmlspecialchars($deepLink, ENT_QUOTES, 'UTF-8');
      $safeGoogle = htmlspecialchars($googleLink, ENT_QUOTES, 'UTF-8');
      $safeOutlook = htmlspecialchars($outlookLink, ENT_QUOTES, 'UTF-8');
      $safeIcs = htmlspecialchars($icsDownloadLink, ENT_QUOTES, 'UTF-8');

      $html = '
  <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:640px;margin:0 auto;padding:16px;color:#222;">
    <div style="text-align:center;">
      <h2 style="margin:0 0 8px;">'. $safeEvent .'</h2>
      <p style="margin:0 0 16px;color:#444;">'. htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8') .'</p>
      <p style="margin:0 0 16px;">
        <a href="'. $safeDeep .'" style="display:inline-block;background:#0b5ed7;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;">View & RSVP</a>
      </p>
    </div>
    <div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:0 0 16px;background:#fafafa;">
      <div><strong>When:</strong> '. $safeWhen .'</div>'.
      ($whereHtml !== '' ? '<div><strong>Where:</strong> '. $whereHtml .'</div>' : '') .'
    </div>'
    . ($desc !== '' ? ('<div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:0 0 16px;background:#fff;">
      <div><strong>Description:</strong></div>
      <div>'. Text::renderMarkup($desc) .'</div>
    </div>') : '')
    . '<div style="text-align:center;margin:0 0 12px;">
      <a href="'. $safeGoogle .'" style="margin:0 6px;display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#0b5ed7;">Add to Google</a>
      <a href="'. $safeOutlook .'" style="margin:0 6px;display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#0b5ed7;">Add to Outlook</a>
      <a href="'. $safeIcs .'" style="margin:0 6px;display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#0b5ed7;">Download .ics</a>
    </div>
    <p style="font-size:12px;color:#666;text-align:center;margin:12px 0 0;">
      If the button does not work, open this link: <br><a href="'. $safeDeep .'">'. $safeDeep .'</a>
    </p>
  </div>';

      $ok = @send_email_with_ics($email, $subject, $html, $icsContent, 'event_'.$eventId.'.ics', $name !== '' ? $name : $email);
      if ($ok) { $sent++; $details[] = $email; } else { $fail++; $details[] = $email.' (failed)'; }
    }

    $sentResults = [
      'count' => count($finalRecipients),
      'sent'  => $sent,
      'failed'=> $fail,
      'recipients' => $details
    ];
  } catch (Throwable $e) {
    $error = $e->getMessage() ?: 'Failed to send invitations.';
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
    
    <form method="post" style="margin-top: 20px;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="send">
      <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
      <input type="hidden" name="audience" value="<?= h($previewData['audience']) ?>">
      <input type="hidden" name="grade" value="<?= h($previewData['grade']) ?>">
      <input type="hidden" name="adult_id" value="<?= (int)$previewData['adult_id'] ?>">
      <input type="hidden" name="subject" value="<?= h($previewData['subject']) ?>">
      <input type="hidden" name="organizer" value="<?= h($previewData['organizer']) ?>">
      
      <div class="actions">
        <button class="primary" id="confirmSendBtn" style="background-color: #dc2626;">Confirm & Send <?= (int)$previewData['count'] ?> Invitations</button>
        <a class="button" href="/admin_event_invite.php?event_id=<?= (int)$eventId ?>">← Go Back to Edit</a>
      </div>
    </form>
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
      <legend>Audience</legend>
      <?php
        $aud = $_POST['audience'] ?? ($_GET['audience'] ?? 'all_adults');
        $gradeSel = $_POST['grade'] ?? ($_GET['grade'] ?? '');
        $adultSel = (int)($_POST['adult_id'] ?? 0);
      ?>
      <label class="inline"><input type="radio" name="audience" value="all_adults" <?= $aud==='all_adults'?'checked':'' ?>> All adults</label>
      <label class="inline"><input type="radio" name="audience" value="registered_families" <?= $aud==='registered_families'?'checked':'' ?>> Registered families (child has BSA ID)</label>
      <label class="inline"><input type="radio" name="audience" value="by_grade" <?= $aud==='by_grade'?'checked':'' ?>> Families by grade</label>
      <div>
        <select name="grade">
          <option value="">Select grade</option>
          <?php for($i=0;$i<=5;$i++): $lbl = GradeCalculator::gradeLabel($i); ?>
            <option value="<?= h($lbl) ?>" <?= ($gradeSel===$lbl?'selected':'') ?>>
              <?= $i===0?'K':$i ?>
            </option>
          <?php endfor; ?>
        </select>
        <span class="small">(Only used when selecting "Families by grade")</span>
      </div>
      <label class="inline"><input type="radio" name="audience" value="one_adult" <?= $aud==='one_adult'?'checked':'' ?>> One adult</label>
      <div>
        <?php
          $prefill = '';
          if ($adultSel > 0) {
            $sel = UserManagement::findBasicForEmailingById((int)$adultSel);
            if ($sel) {
              $ln = trim((string)($sel['last_name'] ?? ''));
              $fn = trim((string)($sel['first_name'] ?? ''));
              $email = trim((string)($sel['email'] ?? ''));
              $prefill = ($ln !== '' || $fn !== '') ? ($ln.', '.$fn) : ('User #'.(int)($sel['id'] ?? 0));
              if ($email !== '') $prefill .= ' <'.$email.'>';
            }
          }
        ?>
        <div class="typeahead">
          <input type="text" id="userTypeahead" placeholder="Type name or email" autocomplete="off" value="<?= h($prefill) ?>">
          <input type="hidden" id="userId" name="adult_id" value="<?= (int)$adultSel ?>">
          <div id="userTypeaheadResults" class="typeahead-results" role="listbox" style="display:none;"></div>
          <button class="button" type="button" id="clearUserBtn">Clear</button>
        </div>
        <span class="small">(Only used when selecting "One adult")</span>
      </div>
    </fieldset>

    <label>Subject
      <input type="text" name="subject" value="<?= h($_POST['subject'] ?? $subjectDefault) ?>">
    </label>

    <label>Organizer Email (optional, used in calendar invite)
      <input type="email" name="organizer" value="<?= h($_POST['organizer'] ?? $defaultOrganizer) ?>">
    </label>


    <div class="actions">
      <button class="primary" id="previewRecipientsBtn">Preview Recipients</button>
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
  <h3>Event Details</h3>
  <p><strong>When:</strong> <?= h(Settings::formatDateTimeRange((string)$event['starts_at'], !empty($event['ends_at']) ? (string)$event['ends_at'] : null)) ?></p>
  <?php if (!empty($event['location'])): ?><p><strong>Where:</strong> <?= h((string)$event['location']) ?></p><?php endif; ?>
  <?php if (!empty($event['description'])): ?><p><strong>Description:</strong> <?= Text::renderMarkup(trim((string)$event['description'])) ?></p><?php endif; ?>
</div>

<?php if ($isAdmin): ?>
  <?= EventUIManager::renderAdminModals((int)$eventId) ?>
  <?= EventUIManager::renderAdminMenuScript((int)$eventId) ?>
<?php endif; ?>

<script>
(function() {
    // Handle both preview and confirmation buttons
    const previewBtn = document.getElementById('previewRecipientsBtn');
    const confirmBtn = document.getElementById('confirmSendBtn');
    let isSubmitting = false;
    
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
    
    // Re-enable button if there's an error (page doesn't redirect)
    <?php if ($error): ?>
    setTimeout(function() {
        if (previewBtn) {
            isSubmitting = false;
            previewBtn.disabled = false;
            previewBtn.textContent = 'Preview Recipients';
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
