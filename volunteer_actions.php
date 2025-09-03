<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/Volunteers.php';

// Accept POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

require_csrf();

$action  = trim((string)($_POST['action'] ?? ''));
$eventId = (int)($_POST['event_id'] ?? 0);
$roleId  = (int)($_POST['role_id'] ?? 0);
$isAjax = !empty($_POST['ajax']);

// Determine acting user
$actingUserId = 0;
$redirectUrl = '/event.php?id=' . $eventId; // default

// Invite HMAC helpers (copied from event_invite.php)
if (!function_exists('b64url_encode')) {
  function b64url_encode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
  }
}
if (!function_exists('b64url_decode')) {
  function b64url_decode(string $str): string {
    $pad = strlen($str) % 4;
    if ($pad > 0) $str .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($str, '-_', '+/')) ?: '';
  }
}
if (!function_exists('invite_signature')) {
  function invite_signature(int $uid, int $eventId): string {
    if (!defined('INVITE_HMAC_KEY') || INVITE_HMAC_KEY === '') return '';
    $payload = $uid . ':' . $eventId;
    return b64url_encode(hash_hmac('sha256', $payload, INVITE_HMAC_KEY, true));
  }
}
if (!function_exists('validate_invite_sig')) {
  function validate_invite_sig(int $uid, int $eventId, string $sig): ?string {
    if (!defined('INVITE_HMAC_KEY') || INVITE_HMAC_KEY === '') return 'Invite system not configured.';
    if ($uid <= 0 || $eventId <= 0 || $sig === '') return 'Invalid link';
    $expected = invite_signature($uid, $eventId);
    if (!hash_equals($expected, $sig)) return 'Invalid link';
    return null;
  }
}

// Check for invite flow params
$inviteUid = isset($_POST['uid']) ? (int)$_POST['uid'] : 0;
$inviteSig = isset($_POST['sig']) ? (string)$_POST['sig'] : '';

if ($inviteUid > 0 && $inviteSig !== '') {
  // Validate HMAC
  $err = validate_invite_sig($inviteUid, $eventId, $inviteSig);
  if ($err !== null) {
    // Fallback to logged-in flow on invalid signature
    $me = current_user();
    if (!$me) {
      // Redirect back to invite page with error
      $redirectUrl = '/event_invite.php?uid='.(int)$inviteUid.'&event_id='.(int)$eventId.'&sig='.rawurlencode($inviteSig).'&volunteer_error='.rawurlencode($err);
      header('Location: '.$redirectUrl);
      exit;
    }
    $actingUserId = (int)$me['id'];
  } else {
    $actingUserId = (int)$inviteUid;
    $redirectUrl = '/event_invite.php?uid='.(int)$inviteUid.'&event_id='.(int)$eventId.'&sig='.rawurlencode($inviteSig);
  }
} else {
  // Logged-in only
  require_login();
  $me = current_user();
  $actingUserId = (int)$me['id'];
  $redirectUrl = '/event.php?id='.(int)$eventId;
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
    Volunteers::signup($eventId, $roleId, $actingUserId);
    $redirectUrl .= (str_contains($redirectUrl, '?') ? '&' : '?') . 'volunteer=1';
  } elseif ($action === 'remove') {
    Volunteers::removeSignup($roleId, $actingUserId);
    $redirectUrl .= (str_contains($redirectUrl, '?') ? '&' : '?') . 'volunteer_removed=1';
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
  header('Content-Type: application/json');
  echo json_encode([
    'ok' => true,
    'roles' => Volunteers::rolesWithCounts($eventId),
    'user_id' => $actingUserId,
    'event_id' => $eventId,
    'csrf' => csrf_token(),
  ]);
  exit;
}
header('Location: ' . $redirectUrl);
exit;
