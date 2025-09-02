<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/Text.php'; // for safe description rendering

// No login required for invite landing

if (!defined('INVITE_HMAC_KEY') || INVITE_HMAC_KEY === '') {
  http_response_code(500);
  echo 'Invite system not configured.';
  exit;
}

function b64url_encode(string $bin): string {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function b64url_decode(string $str): string {
  $pad = strlen($str) % 4;
  if ($pad > 0) $str .= str_repeat('=', 4 - $pad);
  return base64_decode(strtr($str, '-_', '+/')) ?: '';
}
function invite_signature(int $uid, int $eventId): string {
  $payload = $uid . ':' . $eventId;
  return b64url_encode(hash_hmac('sha256', $payload, INVITE_HMAC_KEY, true));
}
function validate_invite(int $uid, int $eventId, string $sig): ?string {
  if ($uid <= 0 || $eventId <= 0 || $sig === '') return 'Invalid link';
  $expected = invite_signature($uid, $eventId);
  if (!hash_equals($expected, $sig)) return 'Invalid link';
  return null;
}

$uid = isset($_GET['uid']) ? (int)$_GET['uid'] : (int)($_POST['uid'] ?? 0);
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : (int)($_POST['event_id'] ?? 0);
$sig = isset($_GET['sig']) ? (string)$_GET['sig'] : (string)($_POST['sig'] ?? '');

$validationError = validate_invite($uid, $eventId, $sig);
if ($validationError) {
  header_html('Event Invite');
  echo '<h2>Event Invite</h2>';
  echo '<div class="card"><p class="error">'.h($validationError).'</p></div>';
  echo '<div class="card"><a class="button" href="/events.php">View Events</a></div>';
  footer_html();
  exit;
}

// Load event
$st = pdo()->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
$st->execute([$eventId]);
$event = $st->fetch();
if (!$event) {
  header_html('Event Invite');
  echo '<h2>Event Invite</h2>';
  echo '<div class="card"><p class="error">Event not found.</p></div>';
  echo '<div class="card"><a class="button" href="/events.php">View Events</a></div>';
  footer_html();
  exit;
}

// Disallow after event starts
try {
  $tz = new DateTimeZone(Settings::timezoneId());
  $startsAt = new DateTime($event['starts_at'], $tz);
  $nowTz = new DateTime('now', $tz);
  if ($nowTz >= $startsAt) {
    header_html('Event Invite');
    echo '<h2>'.h($event['name']).'</h2>';
    echo '<div class="card"><p class="error">This event has already started.</p></div>';
    echo '<div class="card"><a class="button" href="/event.php?id='.(int)$eventId.'">View Event</a></div>';
    footer_html();
    exit;
  }
} catch (Throwable $e) {
  // If parsing fails, allow but it's unlikely
}

// Load target adult (invitee)
$st = pdo()->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
$st->execute([$uid]);
$invitee = $st->fetch();
if (!$invitee) {
  header_html('Event Invite');
  echo '<h2>Event Invite</h2>';
  echo '<div class="card"><p class="error">The invited user was not found.</p></div>';
  echo '<div class="card"><a class="button" href="/events.php">View Events</a></div>';
  footer_html();
  exit;
}

// Build selectable participants for the invitee

// Invitee's children
$st = pdo()->prepare("
  SELECT y.*
  FROM parent_relationships pr
  JOIN youth y ON y.id = pr.youth_id
  WHERE pr.adult_id = ?
  ORDER BY y.last_name, y.first_name
");
$st->execute([(int)$uid]);
$children = $st->fetchAll();
$childIdsAllowed = array_map(fn($r)=> (int)$r['id'], $children);
$childIdsAllowedSet = array_flip($childIdsAllowed);

// Other adult parents of the invitee's children (co-parents), plus the invitee
$st = pdo()->prepare("
  SELECT DISTINCT u2.*
  FROM parent_relationships pr1
  JOIN parent_relationships pr2 ON pr1.youth_id = pr2.youth_id
  JOIN users u2 ON u2.id = pr2.adult_id
  WHERE pr1.adult_id = ? AND pr2.adult_id <> ?
  ORDER BY u2.last_name, u2.first_name
");
$st->execute([(int)$uid, (int)$uid]);
$coParents = $st->fetchAll();
$adultIdsAllowed = [(int)$uid];
foreach ($coParents as $cp) { $adultIdsAllowed[] = (int)$cp['id']; }
$adultIdsAllowed = array_values(array_unique($adultIdsAllowed));
$adultIdsAllowedSet = array_flip($adultIdsAllowed);

// Load existing RSVP group created by invitee (one-per-event per creator)
$st = pdo()->prepare("SELECT * FROM rsvps WHERE event_id=? AND created_by_user_id=? LIMIT 1");
$st->execute([$eventId, (int)$uid]);
$inviteeRsvp = $st->fetch();

$selectedAdults = [];
$selectedYouth = [];
$comments = '';
$nGuests = 0;

if ($inviteeRsvp) {
  $comments = (string)($inviteeRsvp['comments'] ?? '');
  $nGuests = (int)($inviteeRsvp['n_guests'] ?? 0);
  $st = pdo()->prepare("SELECT participant_type, youth_id, adult_id FROM rsvp_members WHERE rsvp_id=? ORDER BY id");
  $st->execute([(int)$inviteeRsvp['id']]);
  foreach ($st->fetchAll() as $rm) {
    if ($rm['participant_type'] === 'adult' && !empty($rm['adult_id'])) $selectedAdults[] = (int)$rm['adult_id'];
    if ($rm['participant_type'] === 'youth' && !empty($rm['youth_id'])) $selectedYouth[] = (int)$rm['youth_id'];
  }
  $selectedAdults = array_values(array_unique($selectedAdults));
  $selectedYouth  = array_values(array_unique($selectedYouth));
}

// Handle POST save (no login, but require invite signature and CSRF)
$error = null;
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    require_csrf();

    // Re-validate HMAC and expiry
    $validationError = validate_invite($uid, $eventId, $sig);
    if ($validationError) throw new RuntimeException($validationError);

    $adults = $_POST['adults'] ?? [];
    $youths = $_POST['youth'] ?? [];
    $comments = trim($_POST['comments'] ?? '');
    $nGuests = (int)($_POST['n_guests'] ?? 0);
    if ($nGuests < 0) $nGuests = 0;

    // Normalize to ints
    $adults = array_values(array_unique(array_map('intval', (array)$adults)));
    $youths = array_values(array_unique(array_map('intval', (array)$youths)));

    // Validate allowed sets (invitee + co-parents, and their children)
    foreach ($adults as $aid) {
      if (!isset($adultIdsAllowedSet[$aid])) { throw new RuntimeException('Invalid adult selection.'); }
    }
    foreach ($youths as $yid) {
      if (!isset($childIdsAllowedSet[$yid])) { throw new RuntimeException('Invalid youth selection.'); }
    }

    // Enforce event youth cap if set
    if (!empty($event['max_cub_scouts'])) {
      $max = (int)$event['max_cub_scouts'];
      $st = pdo()->prepare("SELECT COUNT(*) AS c FROM rsvp_members WHERE event_id=? AND participant_type='youth'");
      $st->execute([$eventId]);
      $row = $st->fetch();
      $currentYouth = (int)($row['c'] ?? 0);

      $myCurrentYouth = 0;
      if ($inviteeRsvp) {
        $st = pdo()->prepare("SELECT COUNT(*) AS c FROM rsvp_members WHERE rsvp_id=? AND participant_type='youth'");
        $st->execute([(int)$inviteeRsvp['id']]);
        $r = $st->fetch();
        $myCurrentYouth = (int)($r['c'] ?? 0);
      }

      $newTotalYouth = $currentYouth - $myCurrentYouth + count($youths);
      if ($newTotalYouth > $max) {
        throw new RuntimeException('This event has reached its maximum number of Cub Scouts.');
      }
    }

    // Save RSVP on behalf of invitee
    if ($inviteeRsvp) {
      $st = pdo()->prepare("UPDATE rsvps SET comments=?, n_guests=? WHERE id=?");
      $st->execute([$comments !== '' ? $comments : null, $nGuests, (int)$inviteeRsvp['id']]);
      $rsvpId = (int)$inviteeRsvp['id'];
    } else {
      $st = pdo()->prepare("INSERT INTO rsvps (event_id, created_by_user_id, comments, n_guests) VALUES (?,?,?,?)");
      $st->execute([$eventId, (int)$uid, $comments !== '' ? $comments : null, $nGuests]);
      $rsvpId = (int)pdo()->lastInsertId();
    }

    // Replace members
    pdo()->prepare("DELETE FROM rsvp_members WHERE rsvp_id=?")->execute([$rsvpId]);

    $ins = pdo()->prepare("INSERT INTO rsvp_members (rsvp_id, event_id, participant_type, youth_id, adult_id) VALUES (?,?,?,?,?)");
    foreach ($adults as $aid) {
      $ins->execute([$rsvpId, $eventId, 'adult', null, $aid]);
    }
    foreach ($youths as $yid) {
      $ins->execute([$rsvpId, $eventId, 'youth', $yid, null]);
    }

    $saved = true;
  } catch (Throwable $e) {
    $error = $e->getMessage() ?: 'Failed to save RSVP.';
  }

  // On error, keep selections for redisplay
  if ($error) {
    $selectedAdults = $adults;
    $selectedYouth = $youths;
  }
}

// For display: RSVP totals
$st = pdo()->prepare("SELECT SUM(n_guests) AS g FROM rsvps WHERE event_id=?");
$st->execute([$eventId]);
$guestsTotal = (int)($st->fetch()['g'] ?? 0);
$st = pdo()->prepare("
  SELECT rm.participant_type, rm.youth_id, rm.adult_id,
         y.first_name AS yfn, y.last_name AS yln,
         u.first_name AS afn, u.last_name AS aln
  FROM rsvp_members rm
  LEFT JOIN youth y ON y.id = rm.youth_id
  LEFT JOIN users u ON u.id = rm.adult_id
  WHERE rm.event_id = ?
  ORDER BY y.last_name, y.first_name, u.last_name, u.first_name
");
$st->execute([$eventId]);
$allMembers = $st->fetchAll();
$youthNames = [];
$adultNames = [];
foreach ($allMembers as $row) {
  if ($row['participant_type'] === 'youth' && !empty($row['youth_id'])) {
    $youthNames[] = trim(($row['yln'] ?? '').', '.($row['yfn'] ?? ''));
  } elseif ($row['participant_type'] === 'adult' && !empty($row['adult_id'])) {
    $adultNames[] = trim(($row['aln'] ?? '').', '.($row['afn'] ?? ''));
  }
}
sort($youthNames);
sort($adultNames);

header_html('Event Invite');
?>
<h2><?= h($event['name']) ?></h2>

<?php if ($saved): ?>
  <div class="card">
    <p class="flash">Thank you! Your RSVP has been saved.</p>
    <?php
      $isLoggedIn = (bool)current_user();
      if (!$isLoggedIn) {
        $inviteeEmail = trim((string)($invitee['email'] ?? ''));
        $isVerified = !empty($invitee['email_verified_at']);
        if ($inviteeEmail !== '' && !$isVerified) {
          ?>
            <p><strong>Would you like to activate your account with Pack 440? If so, click "Send Activation Email" below to set a password for this site.</strong></p>
            <form method="post" action="/verify_resend.php" class="inline">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="email" value="<?= h(strtolower($inviteeEmail)) ?>">
              <button class="button">Send Activation Email</button>
            </form>
          <?php
        } else {
          ?>
            <p>You may log in to view this event and your RSVP.</p>
            <a class="button" href="/login.php?next=<?= h(urlencode('/event.php?id='.(int)$eventId)) ?>">Log In</a>
          <?php
        }
      }
    ?>
  </div>
<?php endif; ?>

<?php if (!$saved): ?>
  <div class="card">
    <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
    <?php
      $name = trim(($invitee['first_name'] ?? '').' '.($invitee['last_name'] ?? ''));
      $displayName = $name !== '' ? $name : 'Guest';
    ?>
    <p><strong>Hello <?= h($displayName) ?>!</strong>  Please RSVP by selecting who will attend:</p>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="uid" value="<?= (int)$uid ?>">
      <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
      <input type="hidden" name="sig" value="<?= h($sig) ?>">

      <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;align-items:start;">
        <div>
          <h3>Adults</h3>
          <label class="inline"><input type="checkbox" name="adults[]" value="<?= (int)$uid ?>" <?= in_array((int)$uid, $selectedAdults, true) ? 'checked' : '' ?>> <?= h($name !== '' ? $name : 'You') ?></label>
          <?php foreach ($coParents as $a): ?>
            <?php $aname = trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')); ?>
            <label class="inline"><input type="checkbox" name="adults[]" value="<?= (int)$a['id'] ?>" <?= in_array((int)$a['id'], $selectedAdults, true) ? 'checked' : '' ?>> <?= h($aname) ?></label>
          <?php endforeach; ?>
        </div>

        <div>
          <h3>Children</h3>
          <?php if (empty($children)): ?>
            <p class="small">No children on file for you.</p>
          <?php else: ?>
            <?php foreach ($children as $c): ?>
              <?php $cname = trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? '')); ?>
              <label class="inline"><input type="checkbox" name="youth[]" value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $selectedYouth, true) ? 'checked' : '' ?>> <?= h($cname) ?></label>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div>
          <h3>Guests</h3>
          <label class="inline">Other guests
            <input type="number" name="n_guests" value="<?= (int)$nGuests ?>" min="0" style="max-width:130px;">
          </label>
        </div>
      </div>

      <h3>Comments</h3>
      <label>
        <textarea name="comments" rows="3"><?= h($comments) ?></textarea>
      </label>

      <div class="actions">
        <button class="primary">Save RSVP</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <?php if (!empty($event['photo_path'])): ?>
    <img src="/<?= h($event['photo_path']) ?>" alt="<?= h($event['name']) ?> image" class="event-hero" width="220">
  <?php endif; ?>
  <p><strong>When:</strong> <?=h(Settings::formatDateTime($event['starts_at']))?><?php if(!empty($event['ends_at'])): ?> &ndash; <?=h(Settings::formatDateTime($event['ends_at']))?><?php endif; ?></p>
  <?php if (!empty($event['location'])): ?><p><strong>Where:</strong> <?=h($event['location'])?></p><?php endif; ?>
  <?php if (!empty($event['description'])): ?>
    <div><?= Text::renderMarkup((string)$event['description']) ?></div>
  <?php endif; ?>
  <?php if (!empty($event['max_cub_scouts'])): ?><p class="small"><strong>Max Cub Scouts:</strong> <?= (int)$event['max_cub_scouts'] ?></p><?php endif; ?>
</div>


<div class="card">
  <h3>Current RSVPs</h3>
  <p class="small">
    Adults: <?= (int)count($adultNames) ?> &nbsp;&nbsp; | &nbsp;&nbsp;
    Cub Scouts: <?= (int)count($youthNames) ?><?= !empty($event['max_cub_scouts']) ? ' / '.(int)$event['max_cub_scouts'] : '' ?> &nbsp;&nbsp; | &nbsp;&nbsp;
    Other Guests: <?= (int)$guestsTotal ?>
  </p>
</div>

<?php footer_html(); ?>
