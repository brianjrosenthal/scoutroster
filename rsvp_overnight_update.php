<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/RSVPManagement.php';
require_login();

/**
 * Admin action from the camping roster: mark an RSVP party as (not) staying overnight.
 * POST csrf, event_id, rsvp_id, value=1|0  ->  redirects back to the roster.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
require_csrf();

$me = current_user();
if (empty($me['is_admin'])) { http_response_code(403); exit('Admins only'); }

$eventId = (int)($_POST['event_id'] ?? 0);
$rsvpId = (int)($_POST['rsvp_id'] ?? 0);
$value = !empty($_POST['value']);
if ($eventId <= 0 || $rsvpId <= 0) { http_response_code(400); exit('Invalid request'); }

RSVPManagement::setNotStayingOvernight($eventId, $rsvpId, $value);
header('Location: /event_ranger_roster.php?event_id=' . $eventId);
exit;
