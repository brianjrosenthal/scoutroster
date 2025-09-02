<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/lib/Text.php';

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

// Load event
$st = pdo()->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
$st->execute([$eventId]);
$event = $st->fetch();
if (!$event) { http_response_code(404); exit('Event not found'); }

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
    $plainToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $plainToken);

    $ins = pdo()->prepare("
      INSERT INTO rsvps_logged_out (event_id, first_name, last_name, email, phone, total_adults, total_kids, comment, token_hash)
      VALUES (?,?,?,?,?,?,?,?,?)
    ");
    $ins->execute([
      (int)$eventId, $firstName, $lastName, $email !== '' ? $email : null,
      $phone !== '' ? $phone : null, (int)$totalAdults, (int)$totalKids,
      $comment !== '' ? $comment : null, $tokenHash
    ]);
    $rsvpId = (int)pdo()->lastInsertId();

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

    @send_email($email, 'Thank you for your RSVP to '.$event['name'], $html);

    $saved = true;
  } catch (Throwable $e) {
    $error = $e->getMessage() ?: 'Failed to save RSVP.';
  }
}

// For display: public RSVP totals
$st = pdo()->prepare("SELECT COALESCE(SUM(total_adults),0) AS a, COALESCE(SUM(total_kids),0) AS k FROM rsvps_logged_out WHERE event_id=?");
$st->execute([$eventId]);
$rowTotals = $st->fetch();
$pubAdults = (int)($rowTotals['a'] ?? 0);
$pubKids   = (int)($rowTotals['k'] ?? 0);

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
  </div>
<?php endif; ?>

<?php if (!$allowPublic): ?>
  <div class="card"><p class="error">Public RSVPs are disabled for this event.</p></div>
<?php elseif (!$eventStarted && !$saved): ?>
<div class="card">
  <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">

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
<?php elseif ($eventStarted): ?>
  <div class="card"><p class="error">This event has already started. RSVPs are no longer accepted.</p></div>
<?php endif; ?>

<?php if ($allowPublic): ?>
<div class="card">
  <?php if (!empty($event['photo_path'])): ?>
    <img src="/<?= h($event['photo_path']) ?>" alt="<?= h($event['name']) ?> image" class="event-hero" width="220">
  <?php endif; ?>
  <p><strong>When:</strong> <?= h(Settings::formatDateTime($event['starts_at'])) ?><?php if (!empty($event['ends_at'])): ?> &ndash; <?= h(Settings::formatDateTime($event['ends_at'])) ?><?php endif; ?></p>
  <?php
    $locName = trim((string)($event['location'] ?? ''));
    $locAddr = trim((string)($event['location_address'] ?? ''));
    if ($locName !== '' || $locAddr !== ''):
  ?>
    <p><strong>Where:</strong>
      <?php if ($locName !== ''): ?>
        <?= h($locName) ?><?php if ($locAddr !== '') echo '<br>'; ?>
      <?php endif; ?>
      <?php if ($locAddr !== ''): ?>
        <?= nl2br(h($locAddr)) ?>
      <?php endif; ?>
    </p>
  <?php endif; ?>
  <?php if (!empty($event['description'])): ?>
    <div><?= Text::renderMarkup((string)$event['description']) ?></div>
  <?php endif; ?>
  <?php if (!empty($event['max_cub_scouts'])): ?><p class="small"><strong>Max Cub Scouts:</strong> <?= (int)$event['max_cub_scouts'] ?></p><?php endif; ?>
</div>
<?php endif; ?>

<?php footer_html(); ?>
