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

$rsvpUrl = trim((string)($event['rsvp_url'] ?? ''));
$rsvpLabel = trim((string)($event['rsvp_url_label'] ?? ''));

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

$inviteeRsvp = RSVPManagement::getRSVPForFamilyByAdultID((int)$eventId, (int)$uid);

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
    
    // Redirect with volunteer modal trigger if appropriate
    if (strtolower((string)$answer) === 'yes' && Volunteers::openRolesExist((int)$eventId)) {
      $redirectUrl = '/event_invite.php?uid=' . (int)$uid . '&event_id=' . (int)$eventId . '&sig=' . urlencode($sig) . '&vol=1';
      header('Location: ' . $redirectUrl);
      exit;
    }
  } catch (Throwable $e) {
    $error = $e->getMessage() ?: 'Failed to save RSVP.';
  }

  // On error, keep selections for redisplay
  if ($error) {
    $selectedAdults = $adults;
    $selectedYouth = $youths;
  }
}

// Load RSVP data using EventsUI
require_once __DIR__ . '/lib/EventsUI.php';

// MAYBE names (lists) - still needed for volunteer section
$maybeAdultNames = RSVPManagement::listAdultNamesByAnswer((int)$eventId, 'maybe');
$maybeYouthNames = RSVPManagement::listYouthNamesByAnswer((int)$eventId, 'maybe');
$publicMaybe = RsvpsLoggedOutManagement::listByAnswer((int)$eventId, 'maybe');

/* Volunteer variables for invite flow */
$roles = Volunteers::rolesWithCounts((int)$eventId);
$openVolunteerRoles = Volunteers::openRolesExist((int)$eventId);
$_invAns = RSVPManagement::getAnswerForCreator((int)$eventId, (int)$uid);
$inviteeHasYes = ($_invAns === 'yes');
$lastAnswerYes = isset($answer) ? (strtolower((string)$answer) === 'yes') : false;
$showVolunteerModal = ($inviteeHasYes && $openVolunteerRoles && !empty($_GET['vol']));

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
            <p>Log in to view this event and your RSVP. (You are in the email-authenticated RSVP flow but not fully logged in.  Please login.  If you have never logged in before, just go through the "Forgot my password" flow)</p>
            <a class="button" href="/login.php?next=<?= h(urlencode('/event.php?id='.(int)$eventId)) ?>">Log In</a>
          <?php
        }
      }
    ?>
  </div>
<?php endif; ?>

<?php if (!$saved && $rsvpUrl !== ''): ?>
  <div class="card">
    <?php
      $name = trim(($invitee['first_name'] ?? '').' '.($invitee['last_name'] ?? ''));
      $displayName = $name !== '' ? $name : 'Guest';
    ?>
    <p><strong>Hello <?= h($displayName) ?>!</strong></p>
    <p>RSVPs for this event are handled externally.</p>
    <a class="button primary" target="_blank" rel="noopener" href="<?= h($rsvpUrl) ?>"><?= h($rsvpLabel !== '' ? $rsvpLabel : 'RSVP HERE') ?></a>
  </div>
<?php endif; ?>

<?php if (!$saved && $rsvpUrl === ''): ?>
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

        <?php if ($inviteeRsvp): ?>
        <div style="margin-bottom: 16px;">
          <strong>Change your RSVP:</strong>
          <div style="margin-top: 8px;">
            <?php $currentAnswer = (string)($inviteeRsvp['answer'] ?? 'yes'); ?>
            <label class="inline">
              <input type="radio" name="answer_radio" value="yes" <?= $currentAnswer === 'yes' ? 'checked' : '' ?>>
              Yes
            </label>
            <label class="inline">
              <input type="radio" name="answer_radio" value="maybe" <?= $currentAnswer === 'maybe' ? 'checked' : '' ?>>
              Maybe
            </label>
            <label class="inline">
              <input type="radio" name="answer_radio" value="no" <?= $currentAnswer === 'no' ? 'checked' : '' ?>>
              No
            </label>
          </div>
        </div>
        <?php endif; ?>

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

      // Handle radio button changes in the modal
      const answerRadios = document.querySelectorAll('input[name="answer_radio"]');
      answerRadios.forEach(radio => {
        radio.addEventListener('change', function() {
          if (this.checked) {
            if (answerInput) answerInput.value = this.value;
          }
        });
      });

      if (closeBtn) closeBtn.addEventListener('click', closeModal);
      document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });
      if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });

      <?php if ($error): ?>
        openModal();
      <?php endif; ?>
    })();
  </script>

<?php endif; ?>

<?= EventsUI::renderEventDetailsCard($event) ?>


<?php if ($rsvpUrl === ''): ?>

<?= EventsUI::renderCurrentRsvpsSection((int)$eventId, $event, $rsvpUrl) ?>

<!-- Event Volunteers -->
<?php
require_once __DIR__ . '/lib/EventUIManager.php';

// Check for volunteer error/success messages from query params
$volunteerError = isset($_GET['volunteer_error']) ? (string)$_GET['volunteer_error'] : null;
$volunteerSuccess = null;
if (isset($_GET['volunteer']) && $_GET['volunteer'] == '1') {
  $volunteerSuccess = 'You have been signed up for the role!';
} elseif (isset($_GET['volunteer_removed']) && $_GET['volunteer_removed'] == '1') {
  $volunteerSuccess = 'You have been removed from the role.';
}

// Render using EventUIManager
if (empty($roles)) {
  echo '<div class="card" id="volunteersCard">';
  echo '<h3>Event Volunteers</h3>';
  if ($volunteerSuccess) {
    echo '<div class="flash" style="margin-bottom:16px;">' . h($volunteerSuccess) . '</div>';
  }
  if ($volunteerError) {
    echo '<div class="error" style="margin-bottom:16px;">' . h($volunteerError) . '</div>';
  }
  echo '<p class="small">No volunteer roles have been defined for this event.</p>';
  if ((bool)$invitee) {
    echo '<p class="small">If you are willing to help, check back later; roles may be added by the organizers.</p>';
  }
  echo '</div>';
} else {
  echo EventUIManager::renderVolunteersCard($roles, $inviteeHasYes, (int)$uid, (int)$eventId, false, $volunteerSuccess);
}
?>

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
        <button class="button" id="volunteerMaybeLater">Back to Event</button>
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
      if (laterBtn) laterBtn.addEventListener('click', function(e){ 
        e.preventDefault(); 
        closeModal(); 
        // Redirect to clean URL without vol parameter
        const cleanUrl = '/event_invite.php?uid=<?= (int)$uid ?>&event_id=<?= (int)$eventId ?>&sig=<?= urlencode($sig) ?>';
        window.location.href = cleanUrl;
      });
      document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });
      <?php if ($showVolunteerModal): ?>
        openModal();
      <?php endif; ?>

      // AJAX handling for volunteer actions to keep modal open and refresh roles
      const rolesWrap = modal ? modal.querySelector('.modal-content') : null;

      function esc(s) {
        return String(s).replace(/[&<>"']/g, function(c){
          return {'&':'&','<':'<','>':'>','"':'"', "'":'&#39;'}[c];
        });
      }

      function renderRoles(json) {
        if (!rolesWrap) return;
        var roles = json.roles || [];
        var uid = parseInt(json.user_id, 10);
        var eventId = parseInt(json.event_id, 10);
        var csrf = json.csrf || '';
        var sig = '<?= h($sig) ?>';
        
        // Find the content area after the h3
        var h3 = rolesWrap.querySelector('h3');
        if (!h3) return;
        
        // Remove all content after h3 except actions div
        var actionsDiv = rolesWrap.querySelector('.actions');
        var currentNode = h3.nextSibling;
        while (currentNode && currentNode !== actionsDiv) {
          var nextNode = currentNode.nextSibling;
          if (currentNode.nodeType === 1 && !currentNode.classList.contains('actions')) {
            currentNode.remove();
          } else if (currentNode.nodeType === 3) {
            currentNode.remove();
          }
          currentNode = nextNode;
        }
        
        // Build new roles HTML
        var html = '';
        for (var i=0;i<roles.length;i++) {
          var r = roles[i] || {};
          var volunteers = r.volunteers || [];
          var signed = false;
          for (var j=0;j<volunteers.length;j++) {
            var v = volunteers[j] || {};
            if (parseInt(v.user_id, 10) === uid) { signed = true; break; }
          }
          var open = parseInt(r.open_count, 10) || 0;
          var unlimited = !!r.is_unlimited;
          
          html += '<div class="role" style="margin-bottom:8px;">'
                +   '<div>'
                +     '<strong>'+esc(r.title||'')+'</strong> '
                +     (unlimited ? '<span class="remaining">(no limit)</span>' : (open > 0 ? '<span class="remaining">('+open+' people still needed)</span>' : '<span class="filled">Filled</span>'))
                +   '</div>';
          
          if (volunteers.length > 0) {
            html += '<ul style="margin:6px 0 0 16px;">';
            for (var k=0;k<volunteers.length;k++) {
              var vn = volunteers[k] || {};
              html += '<li>'+esc(vn.name||'')+'</li>';
            }
            html += '</ul>';
          } else {
            html += '<p class="small" style="margin:4px 0 0 0;">No one yet.</p>';
          }
          
          html += '<form method="post" action="/volunteer_actions.php" class="inline" style="margin-top:6px;">'
                +   '<input type="hidden" name="csrf" value="'+esc(csrf)+'">'
                +   '<input type="hidden" name="event_id" value="'+eventId+'">'
                +   '<input type="hidden" name="role_id" value="'+esc(r.id)+'">'
                +   '<input type="hidden" name="uid" value="'+uid+'">'
                +   '<input type="hidden" name="sig" value="'+esc(sig)+'">';
          
          if (signed) {
            html += '<input type="hidden" name="action" value="remove">'
                  + '<button class="button">Cancel</button>';
          } else if (unlimited || open > 0) {
            html += '<input type="hidden" name="action" value="signup">'
                  + '<button class="button primary">Sign up</button>';
          } else {
            html += '<button class="button" disabled>Filled</button>';
          }
          
          html += '</form></div>';
        }
        
        // Insert new content before actions div
        var tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        while (tempDiv.firstChild) {
          rolesWrap.insertBefore(tempDiv.firstChild, actionsDiv);
        }
      }

      function showError(msg) {
        if (!rolesWrap) return;
        const p = document.createElement('p');
        p.className = 'error small';
        p.textContent = msg || 'Action failed.';
        const h3 = rolesWrap.querySelector('h3');
        if (h3 && h3.nextSibling) {
          rolesWrap.insertBefore(p, h3.nextSibling);
        } else {
          rolesWrap.appendChild(p);
        }
      }

      if (modal) {
        modal.addEventListener('submit', function(e){
          const form = e.target.closest('form');
          if (!form || form.getAttribute('action') !== '/volunteer_actions.php') return;
          e.preventDefault();
          const fd = new FormData(form);
          fd.set('ajax','1');
          fetch('/volunteer_actions.php', { method:'POST', body: fd, credentials:'same-origin' })
            .then(function(res){ return res.json(); })
            .then(function(json){
              if (json && json.ok) { renderRoles(json); }
              else { showError((json && json.error) ? json.error : 'Action failed.'); }
            })
            .catch(function(){ showError('Network error.'); });
        });
      }

      if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
    })();
  </script>
<?php endif; ?>

<?php endif; ?>

<?php if ($inviteeHasYes): ?>
<script>
(function(){
  // AJAX handling for remove links
  document.addEventListener('click', function(e){
    const removeLink = e.target.closest('a.volunteer-remove-link');
    if (!removeLink) return;
    
    const form = removeLink.closest('form');
    if (!form || form.getAttribute('action') !== '/volunteer_actions.php') return;
    if (!form.querySelector('input[name="action"][value="remove"]')) return;
    
    // Skip if this is in the volunteer modal (handled separately)
    if (form.closest('#volunteerModal')) return;
    
    e.preventDefault();
    e.stopPropagation();
    
    const fd = new FormData(form);
    fd.set('ajax', '1');
    
    fetch('/volunteer_actions.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    })
    .then(function(res){
      if (!res.ok) throw new Error('Server error: ' + res.status);
      return res.json();
    })
    .then(function(json){
      if (json && json.ok) {
        // Replace entire volunteers card with updated HTML (includes success message from server)
        if (json.volunteers_card_html) {
          const volunteersCard = document.getElementById('volunteersCard');
          if (volunteersCard) {
            volunteersCard.outerHTML = json.volunteers_card_html;
          }
        }
      } else {
        alert((json && json.error) ? json.error : 'Volunteer action failed.');
      }
    })
    .catch(function(err){
      alert(err.message || 'Network error.');
    });
  });
  
  // AJAX handling for signup buttons
  document.addEventListener('click', function(e){
    const btn = e.target.closest('button');
    if (!btn) return;
    
    const form = btn.closest('form');
    if (!form || form.getAttribute('action') !== '/volunteer_actions.php') return;
    if (!form.querySelector('input[name="action"][value="signup"]')) return;
    
    // Skip if this is in the volunteer modal (handled separately)
    if (form.closest('#volunteerModal')) return;
    
    e.preventDefault();
    e.stopPropagation();
    
    const fd = new FormData(form);
    fd.set('ajax', '1');
    
    fetch('/volunteer_actions.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    })
    .then(function(res){
      if (!res.ok) throw new Error('Server error: ' + res.status);
      return res.json();
    })
    .then(function(json){
      if (json && json.ok) {
        // Replace entire volunteers card with updated HTML (includes success message from server)
        if (json.volunteers_card_html) {
          const volunteersCard = document.getElementById('volunteersCard');
          if (volunteersCard) {
            volunteersCard.outerHTML = json.volunteers_card_html;
          }
        }
      } else {
        alert((json && json.error) ? json.error : 'Volunteer action failed.');
      }
    })
    .catch(function(err){
      alert(err.message || 'Network error.');
    });
  });
})();
</script>
<?php endif; ?>

<?php footer_html(); ?>
