<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/Volunteers.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/settings.php';

// Accept POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

require_csrf();

$action  = trim((string)($_POST['action'] ?? ''));
$eventId = (int)($_POST['event_id'] ?? 0);
$roleId  = (int)($_POST['role_id'] ?? 0);
$comment = isset($_POST['comment']) ? trim((string)$_POST['comment']) : null;
$isAjax = !empty($_POST['ajax']);
// Where to send the user after a non-AJAX action: the event page (default) or the volunteer page.
$returnTo = (($_POST['return_to'] ?? '') === 'volunteer') ? 'volunteer' : 'event';

// Determine acting user
$actingUserId = 0;
$redirectUrl = '/event.php?id=' . $eventId; // default

require_once __DIR__ . '/lib/InviteAuth.php';

// Check for invite flow params
$inviteUid = isset($_POST['uid']) ? (int)$_POST['uid'] : 0;
$inviteSig = isset($_POST['sig']) ? (string)$_POST['sig'] : '';

if ($inviteUid > 0 && $inviteSig !== '') {
  // Check if there's a logged-in user and prioritize them over the token
  $me = current_user();
  if ($me && (int)$me['id'] !== (int)$inviteUid) {
    // Prioritize the logged-in user over the email token
    $actingUserId = (int)$me['id'];
    $redirectUrl = $returnTo === 'volunteer' ? InviteAuth::volunteerPageUrl((int)$eventId) : '/event.php?id='.(int)$eventId;
  } else {
    // Validate HMAC
    $err = InviteAuth::validate($inviteUid, $eventId, $inviteSig);
    if ($err !== null) {
      // Fallback to logged-in flow on invalid signature
      if (!$me) {
        // For AJAX, return JSON error; otherwise redirect
        if ($isAjax) {
          header('Content-Type: application/json');
          echo json_encode(['ok' => false, 'error' => $err]);
          exit;
        }
        // Redirect back to invite page with error
        $redirectUrl = '/event_invite.php?uid='.(int)$inviteUid.'&event_id='.(int)$eventId.'&sig='.rawurlencode($inviteSig).'&volunteer_error='.rawurlencode($err);
        header('Location: '.$redirectUrl);
        exit;
      }
      $actingUserId = (int)$me['id'];
    } else {
      // Check token expiration against event end time (or +1h from start if no end)
      $ev = EventManagement::findById((int)$eventId);
      if (!$ev) {
        // For AJAX, return JSON error; otherwise redirect
        if ($isAjax) {
          header('Content-Type: application/json');
          echo json_encode(['ok' => false, 'error' => 'Event not found.']);
          exit;
        }
        $redirectUrl = '/event_invite.php?uid='.(int)$inviteUid.'&event_id='.(int)$eventId.'&sig='.rawurlencode($inviteSig).'&volunteer_error='.rawurlencode('Event not found.');
        header('Location: '.$redirectUrl);
        exit;
      }
      if (InviteAuth::eventHasEnded($ev)) {
        // For AJAX, return JSON error; otherwise redirect
        if ($isAjax) {
          header('Content-Type: application/json');
          echo json_encode(['ok' => false, 'error' => 'This event has ended.']);
          exit;
        }
        $redirectUrl = '/event_invite.php?uid='.(int)$inviteUid.'&event_id='.(int)$eventId.'&sig='.rawurlencode($inviteSig).'&volunteer_error='.rawurlencode('This event has ended.');
        header('Location: '.$redirectUrl);
        exit;
      }

      $actingUserId = (int)$inviteUid;
      $redirectUrl = $returnTo === 'volunteer'
        ? InviteAuth::volunteerPageUrl((int)$eventId, (int)$inviteUid, $inviteSig)
        : InviteAuth::inviteUrl((int)$inviteUid, (int)$eventId, $inviteSig);
    }
  }
} else {
  // Logged-in only
  require_login();
  $me = current_user();
  $actingUserId = (int)$me['id'];
  $redirectUrl = $returnTo === 'volunteer' ? InviteAuth::volunteerPageUrl((int)$eventId) : '/event.php?id='.(int)$eventId;
}

if ($eventId <= 0 || $roleId <= 0 || $actingUserId <= 0) {
  if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
  }
  $redirectUrl .= (str_contains($redirectUrl, '?') ? '&' : '?') . 'volunteer_error=' . rawurlencode('Invalid request.');
  header('Location: '.$redirectUrl);
  exit;
}

// Enforce RSVP YES before volunteering
try {
  if (!Volunteers::userHasYesRsvp($eventId, $actingUserId)) {
    throw new RuntimeException('You must RSVP "Yes" to volunteer.');
  }

  if ($action === 'signup') {
    Volunteers::signup($eventId, $roleId, $actingUserId, $comment);
    $redirectUrl .= (str_contains($redirectUrl, '?') ? '&' : '?') . 'volunteer=1';
  } elseif ($action === 'remove') {
    Volunteers::removeSignup($roleId, $actingUserId);
    $redirectUrl .= (str_contains($redirectUrl, '?') ? '&' : '?') . 'volunteer_removed=1';
  } elseif ($action === 'edit_comment') {
    Volunteers::updateComment($roleId, $actingUserId, $comment);
    $redirectUrl .= (str_contains($redirectUrl, '?') ? '&' : '?') . 'volunteer_comment_updated=1';
  } else {
    throw new RuntimeException('Unknown action.');
  }
} catch (Throwable $e) {
  $msg = $e->getMessage() ?: 'Volunteer action failed.';
  if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
  }
  $redirectUrl .= (str_contains($redirectUrl, '?') ? '&' : '?') . 'volunteer_error=' . rawurlencode($msg);
}

if ($isAjax) {
  // Load necessary dependencies for rendering volunteers section
  require_once __DIR__ . '/lib/EventUIManager.php';
  require_once __DIR__ . '/lib/UserManagement.php';
  require_once __DIR__ . '/lib/Text.php';
  
  $roles = Volunteers::rolesWithCounts($eventId);
  
  // Pre-render descriptions with markdown/link formatting for JavaScript
  foreach ($roles as &$role) {
    $role['description_html'] = !empty($role['description']) ? Text::renderMarkup((string)$role['description']) : '';
  }
  unset($role);
  
  $hasYes = Volunteers::userHasYesRsvp($eventId, $actingUserId);
  
  // Determine if acting user is admin
  $actingUser = UserManagement::findById($actingUserId);
  $isAdmin = !empty($actingUser['is_admin']);
  
  // Determine success message based on action
  $successMessage = null;
  if ($action === 'signup') {
    $successMessage = 'You have been signed up for the role!';
  } elseif ($action === 'remove') {
    $successMessage = 'You have been removed from the role.';
  } elseif ($action === 'edit_comment') {
    $successMessage = 'Your comment has been updated.';
  }
  
  // Render complete volunteers card HTML using direct method call
  $volunteersCardHtml = EventUIManager::renderVolunteersCard(
    $roles, 
    $hasYes, 
    $actingUserId, 
    $eventId, 
    $isAdmin,
    $successMessage,
    $inviteUid,
    $inviteSig
  );
  
  header('Content-Type: application/json');
  echo json_encode([
    'ok' => true,
    'volunteers_card_html' => $volunteersCardHtml,
    'roles' => $roles,  // Add roles data for modal's renderRoles function
    'user_id' => $actingUserId,
    'event_id' => $eventId,
    'csrf' => csrf_token(),
  ]);
  exit;
}
header('Location: ' . $redirectUrl);
exit;
