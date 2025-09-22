<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/Text.php'; // for safe description rendering
require_once __DIR__ . '/lib/Volunteers.php';
require_once __DIR__ . '/lib/Files.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/RSVPManagement.php';
require_once __DIR__ . '/lib/RsvpsLoggedOutManagement.php';
require_once __DIR__ . '/lib/ParentRelationships.php';

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
  echo '<div class="card"><p class="error">You have clicked on a link to view an event, but the link is invalid. Click on the button to log in, or if you do not yet have an account but are a member of Pack 440, go through the "Forgot Password" flow to set up your account.</p></div>';
  echo '<div class="card"><a class="button" href="/login.php">Log In</a> <a class="button" href="/forgot_password.php">Forgot Password</a></div>';
  footer_html();
  exit;
}

// Check for user mismatch and prioritize logged-in user
$currentUser = current_user();
if ($currentUser && (int)$currentUser['id'] !== (int)$uid) {
  // Prioritize the logged-in user over the email token
  $uid = (int)$currentUser['id'];
  // Continue processing as the logged-in user
}

/* Load event */
$event = EventManagement::findById($eventId);
if (!$event) {
  header_html('Event Invite');
  echo '<h2>Event Invite</h2>';
  echo '<div class="card"><p class="error">Event not found.</p></div>';
  echo '<div class="card"><a class="button" href="/events.php">View Events</a></div>';
  footer_html();
  exit;
}

$eviteUrl = trim((string)($event['evite_rsvp_url'] ?? ''));

 // Disallow after event ends (token invalid after event)
try {
  $tz = new DateTimeZone(Settings::timezoneId());
  if (!empty($event['ends_at'])) {
    $endRef = new DateTime($event['ends_at'], $tz);
  } else {
    $endRef = new DateTime($event['starts_at'], $tz);
    $endRef->modify('+1 hour');
  }
  $nowTz = new DateTime('now', $tz);
  if ($nowTz >= $endRef) {
    header_html('Event Invite');
    echo '<h2>'.h($event['name']).'</h2>';
    echo '<div class="card"><p class="error">This event has ended.</p></div>';
    echo '<div class="card"><a class="button" href="/event.php?id='.(int)$eventId.'">View Event</a></div>';
    footer_html();
    exit;
  }
} catch (Throwable $e) {
  // If parsing fails, allow but it's unlikely
}

 // Load target adult (invitee)
$invitee = UserManagement::findFullById((int)$uid);
if (!$invitee) {
  header_html('Event Invite');
  echo '<h2>Event Invite</h2>';
  echo '<div class="card"><p class="error">The invited user was not found.</p></div>';
  echo '<div class="card"><a class="button" href="/events.php">View Events</a></div>';
  footer_html();
  exit;
}

// Set global variables for email token authentication display in header
global $emailTokenAuth, $emailTokenUserName;
$emailTokenAuth = true;
$emailTokenUserName = trim(($invitee['first_name'] ?? '') . ' ' . ($invitee['last_name'] ?? ''));

// Build selectable participants for the invitee

 // Invitee's children
$children = ParentRelationships::listChildrenForAdult((int)$uid);
$childIdsAllowed = array_map(fn($r)=> (int)$r['id'], $children);
$childIdsAllowedSet = array_flip($childIdsAllowed);

 // Other adult parents of the invitee's children (co-parents), plus the invitee
$coParents = ParentRelationships::listCoParentsForAdult((int)$uid);
$adultIdsAllowed = [(int)$uid];
foreach ($coParents as $cp) { $adultIdsAllowed[] = (int)$cp['id']; }
$adultIdsAllowed = array_values(array_unique($adultIdsAllowed));
$adultIdsAllowedSet = array_flip($adultIdsAllowed);

$inviteeRsvp = RSVPManagement::findCreatorRsvpForEvent((int)$eventId, (int)$uid);

$selectedAdults = [];
$selectedYouth = [];
$comments = '';
$nGuests = 0;

if ($inviteeRsvp) {
  $comments = (string)($inviteeRsvp['comments'] ?? '');
  $nGuests = (int)($inviteeRsvp['n_guests'] ?? 0);
  $ids = RSVPManagement::getMemberIdsByType((int)$inviteeRsvp['id']);
  $selectedAdults = $ids['adult_ids'] ?? [];
  $selectedYouth  = $ids['youth_ids'] ?? [];
}

// Handle POST save (no login, but require invite signature and CSRF)
$error = null;
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    require_csrf();

    // Re-validate HMAC and expiry using original token values
    $originalUid = isset($_POST['uid']) ? (int)$_POST['uid'] : $uid;
    $validationError = validate_invite($originalUid, $eventId, $sig);
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
      $currentYouth = RSVPManagement::countYouthForEvent((int)$eventId);
      $myCurrentYouth = $inviteeRsvp ? RSVPManagement::countYouthForRsvp((int)$inviteeRsvp['id']) : 0;
      $newTotalYouth = $currentYouth - $myCurrentYouth + count($youths);
      if ($newTotalYouth > $max) {
        throw new RuntimeException('This event has reached its maximum number of Cub Scouts.');
      }
    }

    // Save RSVP on behalf of invitee
    RSVPManagement::setFamilyRSVP(null, (int)$uid, (int)$eventId, (string)$answer, (array)$adults, (array)$youths, ($comments !== '' ? $comments : null), (int)$nGuests);
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
$guestsTotal = RSVPManagement::sumGuestsByAnswer((int)$eventId, 'yes');

$adultEntries = RSVPManagement::listAdultEntriesByAnswer((int)$eventId, 'yes');
$youthNames = RSVPManagement::listYouthNamesByAnswer((int)$eventId, 'yes');

// Public RSVPs (logged-out) - list YES only
$publicRsvps = RsvpsLoggedOutManagement::listByAnswer((int)$eventId, 'yes');

// Public YES totals
$_pubYesTotals = RsvpsLoggedOutManagement::totalsByAnswer((int)$eventId, 'yes');
$pubAdultsYes = (int)($_pubYesTotals['adults'] ?? 0);
$pubKidsYes = (int)($_pubYesTotals['kids'] ?? 0);

$rsvpCommentsByAdult = RSVPManagement::getCommentsByParentForEvent((int)$eventId);
sort($youthNames);
usort($adultEntries, function($a,$b){ return strcmp($a['name'], $b['name']); });

// Counts (YES)
$youthCount = count($youthNames);
$adultCount = count($adultEntries);
$adultCountCombined = $adultCount + $pubAdultsYes;
$youthCountCombined = $youthCount + $pubKidsYes;

$_maybeCounts = RSVPManagement::countDistinctParticipantsByAnswer((int)$eventId, 'maybe');
$maybeAdultsIn = (int)($_maybeCounts['adults'] ?? 0);
$maybeYouthIn  = (int)($_maybeCounts['youth'] ?? 0);
$maybeGuestsIn = RSVPManagement::sumGuestsByAnswer((int)$eventId, 'maybe');

// Public MAYBE totals
$_pubMaybeTotals = RsvpsLoggedOutManagement::totalsByAnswer((int)$eventId, 'maybe');
$pubAdultsMaybe = (int)($_pubMaybeTotals['adults'] ?? 0);
$pubKidsMaybe = (int)($_pubMaybeTotals['kids'] ?? 0);

// Combine MAYBE totals
$maybeAdultsTotal = $maybeAdultsIn + $pubAdultsMaybe;
$maybeYouthTotal  = $maybeYouthIn  + $pubKidsMaybe;
$maybeGuestsTotal = $maybeGuestsIn;

// MAYBE names (lists)
$maybeAdultNames = RSVPManagement::listAdultNamesByAnswer((int)$eventId, 'maybe');

$maybeYouthNames = RSVPManagement::listYouthNamesByAnswer((int)$eventId, 'maybe');

$publicMaybe = [];
$publicMaybe = RsvpsLoggedOutManagement::listByAnswer((int)$eventId, 'maybe');

/* Volunteer variables for invite flow */
$roles = Volunteers::rolesWithCounts((int)$eventId);
$openVolunteerRoles = Volunteers::openRolesExist((int)$eventId);
$_invAns = RSVPManagement::getAnswerForCreator((int)$eventId, (int)$uid);
$inviteeHasYes = ($_invAns === 'yes');
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

<div class="card">
  <?php
    $heroUrl = Files::eventPhotoUrl($event['photo_public_file_id'] ?? null);
    if ($heroUrl !== ''):
  ?>
    <img src="<?= h($heroUrl) ?>" alt="<?= h($event['name']) ?> image" class="event-hero" width="220">
  <?php endif; ?>
  <p><strong>When:</strong> <?= h(Settings::formatDateTimeRange($event['starts_at'], !empty($event['ends_at']) ? $event['ends_at'] : null)) ?></p>
  <?php
    $locName = trim((string)($event['location'] ?? ''));
    $locAddr = trim((string)($event['location_address'] ?? ''));
    $mapsUrl = trim((string)($event['google_maps_url'] ?? ''));
    $mapHref = $mapsUrl !== '' ? $mapsUrl : ($locAddr !== '' ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($locAddr) : '');
    if ($locName !== '' || $locAddr !== ''):
  ?>
    <p><strong>Where:</strong>
      <?php if ($locName !== ''): ?>
        <?= h($locName) ?><?php if ($mapHref !== ''): ?>
          <a class="small" href="<?= h($mapHref) ?>" target="_blank" rel="noopener">map</a><br>
        <?php endif; ?>
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


<?php if ($eviteUrl === ''): ?>

<!-- Current RSVPs -->
<div class="card">
  <h3>Current RSVPs</h3>
  <p class="small">
    Adults: <?= (int)$adultCountCombined ?> &nbsp;&nbsp; | &nbsp;&nbsp;
    Cub Scouts: <?= (int)$youthCountCombined ?><?= !empty($event['max_cub_scouts']) ? ' / '.(int)$event['max_cub_scouts'] : '' ?> &nbsp;&nbsp; | &nbsp;&nbsp;
    Other Guests: <?= (int)$guestsTotal ?>
    <?php if ($maybeAdultsTotal + $maybeYouthTotal + $maybeGuestsTotal > 0): ?>
      &nbsp;&nbsp; | &nbsp;&nbsp; <em>(<?= (int)$maybeAdultsTotal ?> adults, <?= (int)$maybeYouthTotal ?> cub scouts, and <?= (int)$maybeGuestsTotal ?> other guests RSVP'd maybe)</em>
    <?php endif; ?>
  </p>

  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">
    <div>
      <h4>Adults</h4>
      <?php if (empty($adultEntries)): ?>
        <p class="small">No adults yet.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($adultEntries as $a): ?>
            <li>
              <?= h($a['name']) ?>
              <?php if (!empty($rsvpCommentsByAdult[(int)$a['id']] ?? '')): ?>
                <div class="small" style="font-style:italic;"><?= nl2br(h($rsvpCommentsByAdult[(int)$a['id']])) ?></div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <div>
      <h4>Cub Scouts</h4>
      <?php if (empty($youthNames)): ?>
        <p class="small">No cub scouts yet.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($youthNames as $n): ?><li><?=h($n)?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <div>
      <h4>Public RSVPs</h4>
      <?php if (empty($publicRsvps)): ?>
        <p class="small">No public RSVPs yet.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($publicRsvps as $pr): ?>
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
</div>

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
            <?php if (!empty($r['is_unlimited'])): ?>
              <span class="remaining small">(no limit)</span>
            <?php elseif ((int)$r['open_count'] > 0): ?>
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
              <?php elseif (!empty($r['is_unlimited']) || (int)$r['open_count'] > 0): ?>
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
            <?php if (!empty($r['is_unlimited'])): ?>
              <span class="remaining">(no limit)</span>
            <?php elseif ((int)$r['open_count'] > 0): ?>
              <span class="remaining">(<?= (int)$r['open_count'] ?> people still needed)</span>
            <?php else: ?>
              <span class="filled">Filled</span>
            <?php endif; ?>
          </div>
          <?php
            $amIn = false;
            foreach ($r['volunteers'] as $v) { if ((int)$v['user_id'] === (int)$uid) { $amIn = true; break; } }
          ?>
          <?php if (!empty($r['volunteers'])): ?>
            <ul style="margin:6px 0 0 16px;">
              <?php foreach ($r['volunteers'] as $v): ?>
                <li><?= h($v['name']) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="small" style="margin:4px 0 0 0;">No one yet.</p>
          <?php endif; ?>
          <form method="post" action="/volunteer_actions.php" class="inline" style="margin-top:6px;">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
            <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="uid" value="<?= (int)$uid ?>">
            <input type="hidden" name="sig" value="<?= h($sig) ?>">
            <?php if ($amIn): ?>
              <input type="hidden" name="action" value="remove">
              <button class="button">Cancel</button>
            <?php elseif (!empty($r['is_unlimited']) || (int)$r['open_count'] > 0): ?>
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

<?php footer_html(); ?>
