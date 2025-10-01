<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/lib/Text.php';
require_once __DIR__ . '/lib/Volunteers.php';
require_once __DIR__ . '/lib/Files.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/RsvpsLoggedOutManagement.php';

// No login required

if (!defined('INVITE_HMAC_KEY') || INVITE_HMAC_KEY === '') {
  http_response_code(500);
  echo 'Public RSVP system not configured.';
  exit;
}

function b64url_encode(string $bin): string {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : (int)($_POST['event_id'] ?? 0);
if ($eventId <= 0) { http_response_code(400); exit('Missing event_id'); }

// If user is logged in, redirect to the full event page
if (current_user()) {
    $redirectUrl = '/event.php?id=' . $eventId;
    // Preserve any additional query parameters except event_id
    $queryParams = $_GET;
    unset($queryParams['event_id']); // Remove since we're using 'id' in event.php
    if (!empty($queryParams)) {
        $redirectUrl .= '&' . http_build_query($queryParams);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

/* Load event */
$event = EventManagement::findById($eventId);
if (!$event) { http_response_code(404); exit('Event not found'); }

$rsvpUrl = trim((string)($event['rsvp_url'] ?? ''));
$rsvpLabel = trim((string)($event['rsvp_url_label'] ?? ''));

# Public RSVP flag and event start checks
$allowPublic = (int)($event['allow_non_user_rsvp'] ?? 1) === 1;

$eventStarted = false;
try {
  $tz = new DateTimeZone(Settings::timezoneId());
  $startsAt = new DateTime($event['starts_at'], $tz);
  $nowTz = new DateTime('now', $tz);
  if ($nowTz >= $startsAt) { $eventStarted = true; }
} catch (Throwable $e) {
  // allow if parse fails
}

// Legacy combined flag (kept for compatibility if referenced below)
$disallowRsvp = $eventStarted || !$allowPublic;

// Inputs/state
$error = null;
$saved = false;

$nameInput = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$totalAdults = isset($_POST['total_adults']) ? (int)$_POST['total_adults'] : 0;
$totalKids   = isset($_POST['total_kids']) ? (int)$_POST['total_kids'] : 0;
$comment     = trim((string)($_POST['comment'] ?? ''));
$answer      = strtolower(trim((string)($_POST['answer'] ?? '')));
if (!in_array($answer, ['yes','maybe','no'], true)) { $answer = 'yes'; }

if ($totalAdults < 0) $totalAdults = 0;
if ($totalKids < 0) $totalKids = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$eventStarted && $allowPublic) {
  try {
    require_csrf();

    // Validate name -> split into first/last (require at least 2 tokens)
    $tokens = preg_split('/\s+/', $nameInput);
    $tokens = array_values(array_filter($tokens, fn($t) => $t !== ''));
    if (count($tokens) < 2) {
      throw new RuntimeException('Please enter your first and last name.');
    }
    $firstName = array_shift($tokens);
    $lastName = implode(' ', $tokens);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new RuntimeException('A valid email is required.');
    }

    // Insert RSVP and generate edit token/signature
    $res = RsvpsLoggedOutManagement::create(
      (int)$eventId,
      (string)$firstName,
      (string)$lastName,
      (string)$email,
      ($phone !== '' ? (string)$phone : null),
      (int)$totalAdults,
      (int)$totalKids,
      (string)$answer,
      ($comment !== '' ? (string)$comment : null),
      null
    );
    $rsvpId = (int)($res['id'] ?? 0);
    $plainToken = (string)($res['plain_token'] ?? '');

    // Build event details and edit link
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $editSig = b64url_encode(hash_hmac('sha256', $rsvpId . ':' . $plainToken, INVITE_HMAC_KEY, true));
    $editUrl = $scheme.'://'.$host.'/event_public_edit.php?id='.$rsvpId.'&token='.$plainToken.'&sig='.$editSig;

    // Compose email
    $siteTitle = Settings::siteTitle();
    $whenText = Settings::formatDateTime((string)$event['starts_at']);
    if (!empty($event['ends_at'])) {
      $whenText .= ' – ' . Settings::formatDateTime((string)$event['ends_at']);
    }
    $locName = trim((string)($event['location'] ?? ''));
    $locAddr = trim((string)($event['location_address'] ?? ''));
    $locCombined = ($locName !== '' && $locAddr !== '') ? ($locName . "\n" . $locAddr) : ($locAddr !== '' ? $locAddr : $locName);
    $whereHtml = $locCombined !== '' ? nl2br(htmlspecialchars($locCombined, ENT_QUOTES, 'UTF-8')) : '';
    $safeEvent = htmlspecialchars((string)$event['name'], ENT_QUOTES, 'UTF-8');
    $safeWhen  = htmlspecialchars($whenText, ENT_QUOTES, 'UTF-8');
    $safeEdit  = htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8');

    $html = '
      <div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:640px;margin:0 auto;padding:16px;color:#222;">
        <h2 style="margin:0 0 12px;">Thank you for your RSVP to '.$safeEvent.'</h2>
        <div style="border:1px solid #ddd;border-radius:8px;padding:12px;margin:0 0 16px;background:#fafafa;">
          <div><strong>When:</strong> '.$safeWhen.'</div>'.
          ($whereHtml !== '' ? '<div><strong>Where:</strong> '.$whereHtml.'</div>' : '') .'
          <div><strong>Your RSVP:</strong> '.(int)$totalAdults.' adult(s), '.(int)$totalKids.' kid(s)'.($comment!==''? ' — '.htmlspecialchars($comment, ENT_QUOTES, 'UTF-8'): '').'</div>
        </div>
        <p>You can edit or delete your RSVP using the link below:</p>
        <p><a href="'.$safeEdit.'">'.$safeEdit.'</a></p>
      </div>';

    $subj = 'Thank you for your RSVP ('.ucfirst($answer).') to '.$event['name'];
    @send_email($email, $subj, $html);

    $saved = true;
  } catch (Throwable $e) {
    $error = $e->getMessage() ?: 'Failed to save RSVP.';
  }
}

// Load RSVP data using EventsUI
require_once __DIR__ . '/lib/EventsUI.php';

$openVolunteerRolesPublic = Volunteers::openRolesExist((int)$eventId);

header_html('Event - Public RSVP');
?>
<?php if ($allowPublic): ?>
  <h2><?= h($event['name']) ?></h2>
<?php else: ?>
  <h2>Public RSVP</h2>
<?php endif; ?>

<?php if ($saved): ?>
  <div class="card">
    <p class="flash">Thank you! Your RSVP has been recorded. A confirmation email with an edit link has been sent to <?= h($email) ?>.</p>
    <?php if ($openVolunteerRolesPublic): ?>
      <p class="small" style="margin-top:8px;">Want to volunteer? If you have an account, log in to volunteer for a role.</p>
      <a class="button" href="/login.php?next=<?= h(urlencode('/event.php?id='.(int)$eventId.'&vol=1')) ?>">Log In</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$saved && $rsvpUrl !== ''): ?>
  <?= EventsUI::renderExternalRsvpCard($rsvpUrl, $rsvpLabel) ?>
<?php endif; ?>

<?php if (!$saved && $rsvpUrl === ''): ?>
  <?php if (!$allowPublic): ?>
    <div class="card"><p class="error">Public RSVPs are disabled for this event.</p></div>
  <?php elseif ($eventStarted): ?>
    <div class="card"><p class="error">This event has already started. RSVPs are no longer accepted.</p></div>
  <?php else: ?>
    <div class="card">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-start;">
        <strong>RSVP:</strong>
        <button class="primary" id="rsvpYesBtn">Yes</button>
        <button id="rsvpMaybeBtn">Maybe</button>
        <button id="rsvpNoBtn">No</button>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?= EventsUI::renderEventDetailsCard($event) ?>

<?= EventsUI::renderCurrentRsvpsSection((int)$eventId, $event, $rsvpUrl) ?>

<?php if ($allowPublic && !$eventStarted && !$saved && $rsvpUrl === ''): ?>
  <div id="rsvpModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-content">
      <button class="close" type="button" id="rsvpModalClose" aria-label="Close">&times;</button>
      <h3>RSVP to <?= h($event['name']) ?></h3>
      <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
      <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
        <input type="hidden" name="answer" value="<?= h($answer) ?>">

        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;align-items:start;">
          <div>
            <label>First and Last Name (required)
              <input type="text" name="name" value="<?= h($nameInput) ?>" required>
            </label>
            <label>Email (required)
              <input type="email" name="email" value="<?= h($email) ?>" required>
            </label>
            <label>Phone (optional)
              <input type="text" name="phone" value="<?= h($phone) ?>">
            </label>
          </div>
          <div>
            <label>Total Adults
              <input type="number" name="total_adults" value="<?= (int)$totalAdults ?>" min="0" style="max-width:130px;">
            </label>
            <label>Total Kids
              <input type="number" name="total_kids" value="<?= (int)$totalKids ?>" min="0" style="max-width:130px;">
            </label>
          </div>
        </div>

        <label>Leave a comment (optional)
          <textarea name="comment" rows="3"><?= h($comment) ?></textarea>
        </label>

        <div class="actions">
          <button class="primary">RSVP</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function(){
      const modal = document.getElementById('rsvpModal');
      const closeBtn = document.getElementById('rsvpModalClose');
      const yesBtn = document.getElementById('rsvpYesBtn');
      const maybeBtn = document.getElementById('rsvpMaybeBtn');
      const noBtn = document.getElementById('rsvpNoBtn');
      const answerInput = document.querySelector('#rsvpModal form input[name="answer"]');
      
      const openModal = () => { if (modal) { modal.classList.remove('hidden'); modal.setAttribute('aria-hidden','false'); } };
      const closeModal = () => { if (modal) { modal.classList.add('hidden'); modal.setAttribute('aria-hidden','true'); } };

      if (yesBtn) yesBtn.addEventListener('click', function(e){ e.preventDefault(); if (answerInput) answerInput.value = 'yes'; openModal(); });
      if (maybeBtn) maybeBtn.addEventListener('click', function(e){ e.preventDefault(); if (answerInput) answerInput.value = 'maybe'; openModal(); });
      if (noBtn) noBtn.addEventListener('click', function(e){ e.preventDefault(); if (answerInput) answerInput.value = 'no'; openModal(); });

      if (closeBtn) closeBtn.addEventListener('click', closeModal);
      document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });
      if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });

      <?php if ($error): ?>
        openModal();
      <?php endif; ?>
    })();
  </script>
<?php endif; ?>

<?php footer_html(); ?>
