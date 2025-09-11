<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/EventManagement.php';

// ICS download for a single event
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($eventId <= 0) { http_response_code(400); echo 'Missing event_id'; exit; }

/* Load event */
$e = EventManagement::findById($eventId);
if (!$e) { http_response_code(404); echo 'Event not found'; exit; }

$tzId = Settings::timezoneId();
$siteTitle = Settings::siteTitle();

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$eventUrl = $scheme . '://' . $host . '/event.php?id=' . (int)$eventId;

// Optional organizer email from query (validated)
$organizerEmail = trim((string)($_GET['organizer'] ?? ''));
if ($organizerEmail !== '' && !filter_var($organizerEmail, FILTER_VALIDATE_EMAIL)) {
  $organizerEmail = '';
}

function ics_escape_text(string $s): string {
  // Escape backslash, comma, semicolon, and newlines per RFC 5545
  $s = str_replace('\\', '\\\\', $s);
  $s = str_replace(["\r\n", "\r", "\n"], '\\n', $s);
  $s = str_replace(',', '\,', $s);
  $s = str_replace(';', '\;', $s);
  return $s;
}
function ics_fmt_dt_utc(string $sqlDateTime, string $tzId): string {
  try {
    $dt = new DateTime($sqlDateTime, new DateTimeZone($tzId));
  } catch (Throwable $e) {
    // Fallback: parse as server default
    $dt = new DateTime($sqlDateTime);
  }
  $dt->setTimezone(new DateTimeZone('UTC'));
  return $dt->format('Ymd\THis\Z');
}

$lines = [];
$lines[] = 'BEGIN:VCALENDAR';
$lines[] = 'PRODID:-//' . ics_escape_text($siteTitle) . '//EN';
$lines[] = 'VERSION:2.0';
$lines[] = 'CALSCALE:GREGORIAN';
$lines[] = 'METHOD:PUBLISH';
$lines[] = 'BEGIN:VEVENT';
$lines[] = 'UID:event-' . (int)$eventId . '@' . $host;
$lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
$lines[] = 'SUMMARY:' . ics_escape_text((string)$e['name']);
$locName = trim((string)($e['location'] ?? ''));
$locAddr = trim((string)($e['location_address'] ?? ''));
$locCombined = '';
if ($locName !== '' && $locAddr !== '') {
  $locCombined = $locName . "\n" . $locAddr;
} elseif ($locAddr !== '') {
  $locCombined = $locAddr;
} elseif ($locName !== '') {
  $locCombined = $locName;
}
if ($locCombined !== '') {
  $lines[] = 'LOCATION:' . ics_escape_text($locCombined);
}
$desc = trim((string)($e['description'] ?? ''));
$descWithUrl = $desc !== '' ? ($desc . "\n\n" . $eventUrl) : $eventUrl;
$lines[] = 'DESCRIPTION:' . ics_escape_text($descWithUrl);

// Times (convert from configured timezone to UTC Z times for portability)
$startsAt = (string)$e['starts_at'];
$lines[] = 'DTSTART:' . ics_fmt_dt_utc($startsAt, $tzId);
if (!empty($e['ends_at'])) {
  $lines[] = 'DTEND:' . ics_fmt_dt_utc((string)$e['ends_at'], $tzId);
}
$lines[] = 'URL:' . $eventUrl;
if ($organizerEmail !== '') {
  $lines[] = 'ORGANIZER;CN=' . ics_escape_text($siteTitle) . ':mailto:' . $organizerEmail;
}
$lines[] = 'STATUS:CONFIRMED';
$lines[] = 'END:VEVENT';
$lines[] = 'END:VCALENDAR';

$filenameSafe = 'event_' . (int)$eventId . '.ics';

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filenameSafe . '"');
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo implode("\r\n", $lines);
