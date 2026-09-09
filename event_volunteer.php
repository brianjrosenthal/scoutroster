<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/lib/Volunteers.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/EventUIManager.php';
require_once __DIR__ . '/lib/Text.php';
require_once __DIR__ . '/lib/InviteAuth.php';

/**
 * Post-RSVP volunteer sign-up page.
 *
 *   /event_volunteer.php?event_id=N[&rsvp=1]                 (logged in)
 *   /event_volunteer.php?event_id=N&uid=U&sig=S[&rsvp=1]     (email-invite token, no login required)
 *
 * Replaces the old "Volunteer to help at this event?" modal so long role lists scroll normally.
 */

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($eventId <= 0) { http_response_code(400); exit('Missing event_id'); }

$event = EventManagement::findById($eventId);
if (!$event) { http_response_code(404); exit('Event not found'); }

$fromRsvp = !empty($_GET['rsvp']);

// Determine acting user: a logged-in session wins; otherwise accept a valid invite token; otherwise require login.
$inviteUid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
$inviteSig = isset($_GET['sig']) ? (string)$_GET['sig'] : '';
$me = current_user();
$actingUserId = 0;
$inviteMode = false;

if ($me) {
  $actingUserId = (int)$me['id'];
} elseif ($inviteUid > 0 && $inviteSig !== '') {
  $err = InviteAuth::validate($inviteUid, $eventId, $inviteSig);
  if ($err !== null) {
    header_html('Volunteer');
    echo '<h2>Volunteer</h2>';
    echo '<div class="card"><p class="error">' . h($err) . '</p><a class="button" href="/login.php">Log In</a></div>';
    footer_html();
    exit;
  }
  if (InviteAuth::eventHasEnded($event)) {
    header_html('Volunteer');
    echo '<h2>' . h($event['name']) . '</h2>';
    echo '<div class="card"><p class="error">This event has ended.</p><a class="button" href="/event.php?id=' . (int)$eventId . '">View Event</a></div>';
    footer_html();
    exit;
  }
  $actingUserId = $inviteUid;
  $inviteMode = true;
} else {
  require_login();
  $me = current_user();
  $actingUserId = (int)$me['id'];
}

$backUrl = $inviteMode
  ? InviteAuth::inviteUrl($inviteUid, $eventId, $inviteSig)
  : '/event.php?id=' . (int)$eventId;

$roles = Volunteers::rolesWithCounts($eventId);
if (empty($roles)) {
  // Nothing to volunteer for; go back to the event (never re-emit vol=1).
  header('Location: ' . $backUrl . ($fromRsvp ? '&rsvp=1' : ''));
  exit;
}

$hasYes = Volunteers::userHasYesRsvp($eventId, $actingUserId);

$flash = null;
if (!empty($_GET['volunteer'])) {
  $flash = "You're signed up! Sign up for another role below, or return to the event.";
} elseif (!empty($_GET['volunteer_removed'])) {
  $flash = 'You have been removed from the role.';
}
$error = isset($_GET['volunteer_error']) ? trim((string)$_GET['volunteer_error']) : '';

$when = Settings::formatDateTimeRange((string)$event['starts_at'], !empty($event['ends_at']) ? (string)$event['ends_at'] : null);

header_html($fromRsvp ? 'RSVP Confirmed' : 'Volunteer');
?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
  <h2 style="margin:0;"><?= $fromRsvp ? 'RSVP Confirmed' : 'Volunteer: ' . h($event['name']) ?></h2>
  <a class="button" href="<?= h($backUrl) ?>">Return to Event</a>
</div>

<?php if ($fromRsvp): ?>
  <p class="flash">Your RSVP has been saved.</p>
<?php endif; ?>
<?php if ($flash): ?>
  <p class="flash"><?= h($flash) ?></p>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <p class="error"><?= h($error) ?></p>
<?php endif; ?>

<?php if (!$hasYes): ?>
  <div class="card">
    <p>You must RSVP "Yes" to volunteer for this event.</p>
    <a class="button primary" href="<?= h($backUrl) ?>">Back to Event</a>
  </div>
<?php else: ?>
  <div class="card">
    <?php if ($fromRsvp): ?>
      <h3><?= h($event['name']) ?></h3>
    <?php endif; ?>
    <p class="small" style="margin-top:0;"><strong>When:</strong> <?= h($when) ?></p>
    <p><strong>Please volunteer for a role at the event.</strong> <span class="small">You can sign up for more than one role.</span></p>

    <?= EventUIManager::renderVolunteerRoleList($roles, $actingUserId, $eventId, $inviteMode ? $inviteUid : null, $inviteMode ? $inviteSig : null) ?>

    <div class="actions" style="margin-top:16px;">
      <a class="button" href="<?= h($backUrl) ?>">Return to Event</a>
    </div>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
