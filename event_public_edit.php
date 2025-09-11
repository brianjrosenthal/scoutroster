<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/EventManagement.php';

// No login required

if (!defined('INVITE_HMAC_KEY') || INVITE_HMAC_KEY === '') {
  http_response_code(500);
  echo 'Public RSVP system not configured.';
  exit;
}

function b64url_encode(string $bin): string {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
$token = isset($_GET['token']) ? (string)$_GET['token'] : (string)($_POST['token'] ?? '');
$sig = isset($_GET['sig']) ? (string)$_GET['sig'] : (string)($_POST['sig'] ?? '');

if ($id <= 0 || $token === '' || $sig === '') { http_response_code(400); exit('Invalid link'); }

// Validate signature and token hash
$expectedSig = b64url_encode(hash_hmac('sha256', $id . ':' . $token, INVITE_HMAC_KEY, true));
if (!hash_equals($expectedSig, $sig)) { http_response_code(403); exit('Invalid link'); }

// Load RSVP
$st = pdo()->prepare("SELECT * FROM rsvps_logged_out WHERE id=? LIMIT 1");
$st->execute([$id]);
$rsvp = $st->fetch();
if (!$rsvp) { http_response_code(404); exit('RSVP not found'); }

// Verify token hash
if (!hash_equals((string)$rsvp['token_hash'], hash('sha256', $token))) {
  http_response_code(403); exit('Invalid token');
}

/* Load event */
$eventId = (int)$rsvp['event_id'];
$event = EventManagement::findById($eventId);
if (!$event) { http_response_code(404); exit('Event not found'); }

// Disallow after event starts
$disallowEdit = false;
try {
  $tz = new DateTimeZone(Settings::timezoneId());
  $startsAt = new DateTime($event['starts_at'], $tz);
  $nowTz = new DateTime('now', $tz);
  if ($nowTz >= $startsAt) { $disallowEdit = true; }
} catch (Throwable $e) {
  // allow on parse failures
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    require_csrf();
    $action = $_POST['action'] ?? 'save';
    if ($disallowEdit) {
      throw new RuntimeException('This event has already started. Editing is no longer allowed.');
    }

    if ($action === 'delete') {
      $del = pdo()->prepare("DELETE FROM rsvps_logged_out WHERE id=?");
      $del->execute([$id]);
      header_html('RSVP Deleted');
      echo '<h2>RSVP Deleted</h2>';
      echo '<div class="card"><p class="flash">Your RSVP has been deleted.</p></div>';
      echo '<div class="card"><a class="button" href="/event_public.php?event_id='.(int)$eventId.'">Back to event</a></div>';
      footer_html(); exit;
    }

    // Save changes
    $totalAdults = (int)($_POST['total_adults'] ?? 0);
    $totalKids   = (int)($_POST['total_kids'] ?? 0);
    $comment     = trim((string)($_POST['comment'] ?? ''));
    $answer      = strtolower(trim((string)($_POST['answer'] ?? '')));
    if ($totalAdults < 0) $totalAdults = 0;
    if ($totalKids < 0) $totalKids = 0;
    if (!in_array($answer, ['yes','maybe','no'], true)) {
      $answer = (string)($rsvp['answer'] ?? 'yes');
    }

    $upd = pdo()->prepare("UPDATE rsvps_logged_out SET total_adults=?, total_kids=?, answer=?, comment=? WHERE id=?");
    $upd->execute([$totalAdults, $totalKids, $answer, ($comment !== '' ? $comment : null), $id]);

    // Reload current values
    $st = pdo()->prepare("SELECT * FROM rsvps_logged_out WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $rsvp = $st->fetch();
    $success = 'Your RSVP has been updated.';
  } catch (Throwable $e) {
    $error = $e->getMessage() ?: 'Failed to update RSVP.';
  }
}

header_html('Edit RSVP');
?>
<h2>Edit RSVP - <?= h($event['name']) ?></h2>
<?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<?php if ($success): ?><p class="flash"><?= h($success) ?></p><?php endif; ?>

<div class="card">
  <p><strong>Name:</strong> <?= h(trim(($rsvp['first_name'] ?? '').' '.($rsvp['last_name'] ?? ''))) ?></p>
  <p><strong>Email:</strong> <?= h($rsvp['email'] ?? '') ?><?php if (!empty($rsvp['phone'])): ?> &nbsp;&nbsp; | &nbsp;&nbsp; <strong>Phone:</strong> <?= h($rsvp['phone']) ?><?php endif; ?></p>
</div>

<?php if ($disallowEdit): ?>
  <div class="card"><p class="error">This event has already started. Editing is no longer allowed.</p></div>
<?php else: ?>
<div class="card">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="token" value="<?= h($token) ?>">
    <input type="hidden" name="sig" value="<?= h($sig) ?>">

    <label>Your response</label>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:start;margin-bottom:8px;">
      <label class="inline"><input type="radio" name="answer" value="yes" <?= ((($rsvp['answer'] ?? 'yes') === 'yes') ? 'checked' : '') ?>> Yes</label>
      <label class="inline"><input type="radio" name="answer" value="maybe" <?= ((($rsvp['answer'] ?? 'yes') === 'maybe') ? 'checked' : '') ?>> Maybe</label>
      <label class="inline"><input type="radio" name="answer" value="no" <?= ((($rsvp['answer'] ?? 'yes') === 'no') ? 'checked' : '') ?>> No</label>
    </div>

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;align-items:start;">
      <label>Total Adults
        <input type="number" name="total_adults" value="<?= (int)($rsvp['total_adults'] ?? 0) ?>" min="0" style="max-width:130px;">
      </label>
      <label>Total Kids
        <input type="number" name="total_kids" value="<?= (int)($rsvp['total_kids'] ?? 0) ?>" min="0" style="max-width:130px;">
      </label>
    </div>

    <label>Comment
      <textarea name="comment" rows="3"><?= h((string)($rsvp['comment'] ?? '')) ?></textarea>
    </label>

    <div class="actions">
      <button class="primary" name="action" value="save">Save changes</button>
      <button class="button danger" name="action" value="delete" onclick="return confirm('Delete your RSVP?');">Delete my RSVP</button>
      <a class="button" href="/event_public.php?event_id=<?= (int)$eventId ?>">Back to event</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>Event Details</h3>
  <p><strong>When:</strong> <?= h(Settings::formatDateTime($event['starts_at'])) ?><?php if (!empty($event['ends_at'])): ?> – <?= h(Settings::formatDateTime($event['ends_at'])) ?><?php endif; ?></p>
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
</div>

<?php footer_html(); ?>
