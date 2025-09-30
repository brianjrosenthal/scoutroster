<?php
// Redirect to the new three-file architecture
// This maintains backward compatibility for any links pointing to admin_event_invite.php

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : (int)($_POST['event_id'] ?? 0);

if ($eventId > 0) {
  // Preserve any query parameters
  $params = $_GET;
  unset($params['event_id']); // We'll add this back explicitly
  
  $queryString = '';
  if (!empty($params)) {
    $queryString = '&' . http_build_query($params);
  }
  
  header('Location: /admin_event_invite_form.php?event_id=' . $eventId . $queryString);
  exit;
} else {
  // No event ID - redirect to events list
  header('Location: /events.php');
  exit;
}
