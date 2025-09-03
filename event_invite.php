<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/Text.php'; // for safe description rendering
require_once __DIR__ . '/lib/Volunteers.php';

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

$eviteUrl = trim((string)($event['evite_rsvp_url'] ?? ''));

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

    // RSVP answer comes from the launching button; keep existing if not provided
    $answer = strtolower(trim((string)($_POST['answer'] ?? '')));
    if (!in_array($answer, ['yes','maybe','no'], true)) {
      // load existing if present
      $answer = (string)($inviteeRsvp['answer'] ?? 'yes');
    }

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
      $st = pdo()->prepare("UPDATE rsvps SET comments=?, n_guests=?, answer=? WHERE id=?");
      $st->execute([$comments !== '' ? $comments : null, $nGuests, $answer, (int)$inviteeRsvp['id']]);
      $rsvpId = (int)$inviteeRsvp['id'];
    } else {
      $st = pdo()->prepare("INSERT INTO rsvps (event_id, created_by_user_id, comments, n_guests, answer) VALUES (?,?,?,?,?)");
      $st->execute([$eventId, (int)$uid, $comments !== '' ? $comments : null, $nGuests, $answer]);
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

/**
 * For display: RSVP totals and lists
 * - YES totals drive primary counts
 * - MAYBE totals listed separately and appended in parentheses
 */
// YES guests
$st = pdo()->prepare("SELECT COALESCE(SUM(n_guests),0) AS g FROM rsvps WHERE event_id=? AND answer='yes'");
$st->execute([$eventId]);
$guestsTotal = (int)($st->fetch()['g'] ?? 0);

// YES members (names)
$st = pdo()->prepare("
  SELECT rm.participant_type, rm.youth_id, rm.adult_id,
         y.first_name AS yfn, y.last_name AS yln,
         u.first_name AS afn, u.last_name AS aln
  FROM rsvp_members rm
  JOIN rsvps r ON r.id = rm.rsvp_id AND r.answer='yes'
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

// MAYBE totals (adults, youth, guests) from logged-in RSVPs
$st = pdo()->prepare("
  SELECT COUNT(DISTINCT CASE WHEN rm.participant_type='adult' THEN rm.adult_id END) AS a,
         COUNT(DISTINCT CASE WHEN rm.participant_type='youth' THEN rm.youth_id END) AS y
  FROM rsvp_members rm
  JOIN rsvps r ON r.id = rm.rsvp_id AND r.answer='maybe'
  WHERE rm.event_id = ?
");
$st->execute([$eventId]);
$_maybeIn = $st->fetch();
$maybeAdultsIn = (int)($_maybeIn['a'] ?? 0);
$maybeYouthIn  = (int)($_maybeIn['y'] ?? 0);
$st = pdo()->prepare("SELECT COALESCE(SUM(n_guests),0) AS g FROM rsvps WHERE event_id=? AND answer='maybe'");
$st->execute([$eventId]);
$maybeGuestsIn = (int)($st->fetch()['g'] ?? 0);

// Public MAYBE totals
$st = pdo()->prepare("SELECT COALESCE(SUM(total_adults),0) AS a, COALESCE(SUM(total_kids),0) AS k FROM rsvps_logged_out WHERE event_id=? AND answer='maybe'");
$st->execute([$eventId]);
$_pubMaybeTotals = $st->fetch();
$pubAdultsMaybe = (int)($_pubMaybeTotals['a'] ?? 0);
$pubKidsMaybe = (int)($_pubMaybeTotals['k'] ?? 0);

// Combine MAYBE totals
$maybeAdultsTotal = $maybeAdultsIn + $pubAdultsMaybe;
$maybeYouthTotal  = $maybeYouthIn  + $pubKidsMaybe;
$maybeGuestsTotal = $maybeGuestsIn;

// MAYBE names (lists)
$maybeAdultNames = [];
$st = pdo()->prepare("
  SELECT DISTINCT u.last_name AS ln, u.first_name AS fn
  FROM rsvp_members rm
  JOIN rsvps r ON r.id = rm.rsvp_id AND r.answer='maybe'
  JOIN users u ON u.id = rm.adult_id
  WHERE rm.event_id = ? AND rm.participant_type='adult' AND rm.adult_id IS NOT NULL
  ORDER BY u.last_name, u.first_name
");
$st->execute([$eventId]);
foreach ($st->fetchAll() as $r) { $maybeAdultNames[] = trim(($r['ln'] ?? '').', '.($r['fn'] ?? '')); }

$maybeYouthNames = [];
$st = pdo()->prepare("
  SELECT DISTINCT y.last_name AS ln, y.first_name AS fn
  FROM rsvp_members rm
  JOIN rsvps r ON r.id = rm.rsvp_id AND r.answer='maybe'
  JOIN youth y ON y.id = rm.youth_id
  WHERE rm.event_id = ? AND rm.participant_type='youth' AND rm.youth_id IS NOT NULL
  ORDER BY y.last_name, y.first_name
");
$st->execute([$eventId]);
foreach ($st->fetchAll() as $r) { $maybeYouthNames[] = trim(($r['ln'] ?? '').', '.($r['fn'] ?? '')); }

$publicMaybe = [];
$st = pdo()->prepare("
  SELECT first_name, last_name, total_adults, total_kids, comment
  FROM rsvps_logged_out
  WHERE event_id=? AND answer='maybe'
  ORDER BY last_name, first_name, id
");
$st->execute([$eventId]);
$publicMaybe = $st->fetchAll();

/* Volunteer variables for invite flow */
$roles = Volunteers::rolesWithCounts((int)$eventId);
$openVolunteerRoles = Volunteers::openRolesExist((int)$eventId);
$st = pdo()->prepare("SELECT answer FROM rsvps WHERE event_id=? AND created_by_user_id=? LIMIT 1");
$st->execute([$eventId, (int)$uid]);
$_invAns = $st->fetch();
$inviteeHasYes = (bool)($_invAns && strtolower((string)($_invAns['answer'] ?? '')) === 'yes');
$lastAnswerYes = isset($answer) ? (strtolower((string)$answer) === 'yes') : false;
$showVolunteerModal = ($saved && $lastAnswerYes && $openVolunteerRoles);

header_html('Event Invite');
?>
<h2>RSVP: <?= h($event['name']) ?></h2>

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

<?php if (!$saved && $eviteUrl !== ''): ?>
  <div class="card">
    <?php
      $name = trim(($invitee['first_name'] ?? '').' '.($invitee['last_name'] ?? ''));
      $displayName = $name !== '' ? $name : 'Guest';
    ?>
    <p><strong>Hello <?= h($displayName) ?>!</strong></p>
    <p>RSVPs for this event are handled on Evite.</p>
    <a class="button primary" target="_blank" rel="noopener" href="<?= h($eviteUrl) ?>">RSVP TO EVITE</a>
  </div>
<?php endif; ?>

<?php if (!$saved && $eviteUrl === ''): ?>
  <div class="card">
    <?php
      $name = trim(($invitee['first_name'] ?? '').' '.($invitee['last_name'] ?? ''));
      $displayName = $name !== '' ? $name : 'Guest';
    ?>
    <p><strong>Hello <?= h($displayName) ?>!</strong></p>
    <?php if ($inviteeRsvp): ?>
      <p>You have an RSVP on file. You can edit your selections below.</p>
      <div class="actions"><button class="button" id="inviteEditBtn">Edit</button></div>
    <?php else: ?>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-start;">
        <strong>RSVP:</strong>
        <button class="primary" id="inviteYesBtn">Yes</button>
        <button id="inviteMaybeBtn">Maybe</button>
        <button id="inviteNoBtn">No</button>
      </div>
    <?php endif; ?>
  </div>

  <!-- Modal RSVP form (moved from inline) -->
  <div id="inviteModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-content">
      <button class="close" type="button" id="inviteModalClose" aria-label="Close">&times;</button>
      <h3>RSVP to <?= h($event['name']) ?></h3>
      <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
      <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="uid" value="<?= (int)$uid ?>">
        <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
        <input type="hidden" name="sig" value="<?= h($sig) ?>">
        <input type="hidden" name="answer" id="inviteAnswerInput" value="<?= h((string)($inviteeRsvp['answer'] ?? 'yes')) ?>">

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
  </div>

  <script>
    (function(){
      const modal = document.getElementById('inviteModal');
      const closeBtn = document.getElementById('inviteModalClose');
      const yesBtn = document.getElementById('inviteYesBtn');
      const maybeBtn = document.getElementById('inviteMaybeBtn');
      const noBtn = document.getElementById('inviteNoBtn');
      const editBtn = document.getElementById('inviteEditBtn');
      const answerInput = document.getElementById('inviteAnswerInput');

      const openModal = () => { if (modal) { modal.classList.remove('hidden'); modal.setAttribute('aria-hidden','false'); } };
      const closeModal = () => { if (modal) { modal.classList.add('hidden'); modal.setAttribute('aria-hidden','true'); } };

      if (yesBtn) yesBtn.addEventListener('click', function(e){ e.preventDefault(); if (answerInput) answerInput.value='yes'; openModal(); });
      if (maybeBtn) maybeBtn.addEventListener('click', function(e){ e.preventDefault(); if (answerInput) answerInput.value='maybe'; openModal(); });
      if (noBtn) noBtn.addEventListener('click', function(e){ e.preventDefault(); if (answerInput) answerInput.value='no'; openModal(); });
      if (editBtn) editBtn.addEventListener('click', function(e){ e.preventDefault(); openModal(); });

      <?php if ($error): ?>
        openModal();
      <?php endif; ?>
    })();
  </script>

<?php endif; ?>
<?php if (false): ?>
  <div class="card">
    <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
    <?php
      $name = trim(($invitee['first_name'] ?? '').' '.($invitee['last_name'] ?? ''));
      $displayName = $name !== '' ? $name : 'Guest';
    ?>
    <p><strong>Hello <?= h($displayName) ?>!</strong>  Please select who will attend:</p>
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


<?php if ($eviteUrl === ''): ?>

<!-- Event Volunteers -->
<div class="card">
  <h3>Event Volunteers</h3>
  <?php if (empty($roles)): ?>
    <p class="small">No volunteer roles have been defined for this event.</p>
    <?php if ((bool)$invitee): // invitee exists by definition ?>
      <p class="small">If you are willing to help, check back later; roles may be added by the organizers.</p>
    <?php endif; ?>
  <?php else: ?>
    <div class="volunteers">
      <?php foreach ($roles as $r): ?>
        <div class="role" style="margin-bottom:10px;">
          <div>
            <strong><?= h($r['title']) ?></strong>
            <?php if ((int)$r['open_count'] > 0): ?>
              <span class="remaining small">(<?= (int)$r['open_count'] ?> people still needed)</span>
            <?php else: ?>
              <span class="filled small">Filled</span>
            <?php endif; ?>
          </div>

          <?php if (!empty($r['volunteers'])): ?>
            <ul style="margin:6px 0 0 16px;">
              <?php foreach ($r['volunteers'] as $v): ?>
                <li><?= h($v['name']) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="small" style="margin:4px 0 0 0;">No one yet.</p>
          <?php endif; ?>

          <?php if ($inviteeHasYes): ?>
            <?php
              $amIn = false;
              foreach ($r['volunteers'] as $v) { if ((int)$v['user_id'] === (int)$uid) { $amIn = true; break; } }
            ?>
            <form method="post" action="/volunteer_actions.php" class="inline" style="margin-top:6px;">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
              <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="uid" value="<?= (int)$uid ?>">
              <input type="hidden" name="sig" value="<?= h($sig) ?>">
              <?php if ($amIn): ?>
                <input type="hidden" name="action" value="remove">
                <button class="button">Cancel</button>
              <?php elseif ((int)$r['open_count'] > 0): ?>
                <input type="hidden" name="action" value="signup">
                <button class="button primary">Sign up</button>
              <?php else: ?>
                <button class="button" disabled>Filled</button>
              <?php endif; ?>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($openVolunteerRoles && $inviteeHasYes): ?>
  <!-- Volunteer prompt modal (invite flow) -->
  <div id="volunteerModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-content">
      <button class="close" type="button" id="volunteerModalClose" aria-label="Close">&times;</button>
      <h3>Volunteer to help at this event?</h3>
      <?php foreach ($roles as $r): ?>
        <div class="role" style="margin-bottom:8px;">
          <div>
            <strong><?= h($r['title']) ?></strong>
            <?php if ((int)$r['open_count'] > 0): ?>
              <span class="remaining small">(<?= (int)$r['open_count'] ?> people still needed)</span>
            <?php else: ?>
              <span class="filled small">Filled</span>
            <?php endif; ?>
          </div>
          <?php
            $amIn = false;
            foreach ($r['volunteers'] as $v) { if ((int)$v['user_id'] === (int)$uid) { $amIn = true; break; } }
          ?>
          <form method="post" action="/volunteer_actions.php" class="inline" style="margin-top:6px;">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
            <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="uid" value="<?= (int)$uid ?>">
            <input type="hidden" name="sig" value="<?= h($sig) ?>">
            <?php if ($amIn): ?>
              <input type="hidden" name="action" value="remove">
              <button class="button">Cancel</button>
            <?php elseif ((int)$r['open_count'] > 0): ?>
              <input type="hidden" name="action" value="signup">
              <button class="button primary">Sign up</button>
            <?php else: ?>
              <button class="button" disabled>Filled</button>
            <?php endif; ?>
          </form>
        </div>
      <?php endforeach; ?>

      <div class="actions" style="margin-top:10px;">
        <button class="button" id="volunteerMaybeLater">Maybe later</button>
      </div>
    </div>
  </div>
  <script>
    (function(){
      const modal = document.getElementById('volunteerModal');
      const closeBtn = document.getElementById('volunteerModalClose');
      const laterBtn = document.getElementById('volunteerMaybeLater');
      const openModal = () => { if (modal) { modal.classList.remove('hidden'); modal.setAttribute('aria-hidden','false'); } };
      const closeModal = () => { if (modal) { modal.classList.add('hidden'); modal.setAttribute('aria-hidden','true'); } };
      if (closeBtn) closeBtn.addEventListener('click', closeModal);
      if (laterBtn) laterBtn.addEventListener('click', function(e){ e.preventDefault(); closeModal(); });
      <?php if ($showVolunteerModal): ?>
        openModal();
      <?php endif; ?>
      if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
    })();
  </script>
<?php endif; ?>

<?php endif; ?>

<div class="card">
  <h3>Current RSVPs</h3>
  <p class="small">
    Adults: <?= (int)count($adultNames) ?> &nbsp;&nbsp; | &nbsp;&nbsp;
    Cub Scouts: <?= (int)count($youthNames) ?><?= !empty($event['max_cub_scouts']) ? ' / '.(int)$event['max_cub_scouts'] : '' ?> &nbsp;&nbsp; | &nbsp;&nbsp;
    Other Guests: <?= (int)$guestsTotal ?>
    <?php if ($maybeAdultsTotal + $maybeYouthTotal + $maybeGuestsTotal > 0): ?>
      &nbsp;&nbsp; | &nbsp;&nbsp; <em>(<?= (int)$maybeAdultsTotal ?> adults, <?= (int)$maybeYouthTotal ?> cub scouts, and <?= (int)$maybeGuestsTotal ?> other guests RSVP’d maybe)</em>
    <?php endif; ?>
  </p>

  <?php if (!empty($maybeAdultNames) || !empty($maybeYouthNames) || !empty($publicMaybe)): ?>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">
      <div>
        <h4>Adults <em>(Maybe)</em></h4>
        <?php if (empty($maybeAdultNames)): ?>
          <p class="small">No adults marked maybe.</p>
        <?php else: ?>
          <ul><?php foreach ($maybeAdultNames as $n): ?><li><?= h($n) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
      <div>
        <h4>Cub Scouts <em>(Maybe)</em></h4>
        <?php if (empty($maybeYouthNames)): ?>
          <p class="small">No cub scouts marked maybe.</p>
        <?php else: ?>
          <ul><?php foreach ($maybeYouthNames as $n): ?><li><?= h($n) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
      <div>
        <h4>Public RSVPs <em>(Maybe)</em></h4>
        <?php if (empty($publicMaybe)): ?>
          <p class="small">No public maybe RSVPs.</p>
        <?php else: ?>
          <ul>
            <?php foreach ($publicMaybe as $pr): ?>
              <li>
                <?= h(trim(($pr['last_name'] ?? '').', '.($pr['first_name'] ?? ''))) ?>
                — <?= (int)($pr['total_adults'] ?? 0) ?> adult<?= ((int)($pr['total_adults'] ?? 0) === 1 ? '' : 's') ?>,
                <?= (int)($pr['total_kids'] ?? 0) ?> kid<?= ((int)($pr['total_kids'] ?? 0) === 1 ? '' : 's') ?>
                <?php $pc = trim((string)($pr['comment'] ?? '')); if ($pc !== ''): ?>
                  <div class="small" style="font-style:italic;"><?= nl2br(h($pc)) ?></div>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
