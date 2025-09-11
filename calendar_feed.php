<?php
// Public ICS feed for events (read-only, no auth required)
require_once __DIR__.'/settings.php';
require_once __DIR__.'/config.php';
require_once __DIR__.'/lib/EventManagement.php';

// Helpers
function ics_escape($s) {
  $s = str_replace(["\\", ";", ","], ["\\\\", "\\;", "\\,"], $s ?? '');
  $s = preg_replace("/\r\n|\r|\n/", "\\n", $s);
  return $s;
}
function ics_fold($line) {
  // Simple line folding at 75 octets per iCalendar spec
  $out = '';
  $len = strlen($line);
  $pos = 0;
  $max = 75;
  while ($pos < $len) {
    $chunk = substr($line, $pos, $max);
    $out .= ($pos === 0 ? $chunk : "\r\n " . $chunk);
    $pos += $max;
  }
  return $out;
}
function ics_line($k, $v) {
  return ics_fold($k . ':' . $v) . "\r\n";
}

// Load upcoming events (next 365 days)
$now = date('Y-m-d H:i:s');
$until = date('Y-m-d H:i:s', time() + 365*24*60*60);
$events = EventManagement::listBetween($now, $until);

// Host for UIDs/URLs
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme.'://'.$host;

// Calendar headers
$title = Settings::siteTitle();
$prodId = '-//'.ics_escape($title).'//EN';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="pack_events.ics"');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo ics_line("PRODID", $prodId);
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo ics_line("X-WR-CALNAME", ics_escape($title." Events"));
echo ics_line("X-WR-TIMEZONE", Settings::timezoneId());

// Emit events (use UTC Zulu times to avoid VTIMEZONE complexity)
foreach ($events as $e) {
  $uid = ((int)$e['id']).'@'.$host;
  $url = $baseUrl.'/event.php?id='.(int)$e['id'];

  // Treat DB timestamps as UTC
  $dtStart = null;
  $dtEnd = null;
  try {
    if (!empty($e['starts_at'])) {
      $d = new DateTime($e['starts_at'], new DateTimeZone('UTC'));
      $dtStart = $d->format('Ymd\THis\Z');
    }
    if (!empty($e['ends_at'])) {
      $d2 = new DateTime($e['ends_at'], new DateTimeZone('UTC'));
      $dtEnd = $d2->format('Ymd\THis\Z');
    }
  } catch (Throwable $ex) {
    // skip malformed dates
    continue;
  }

  echo "BEGIN:VEVENT\r\n";
  echo ics_line("UID", $uid);
  echo ics_line("DTSTAMP", gmdate('Ymd\THis\Z'));
  if ($dtStart) echo ics_line("DTSTART", $dtStart);
  if ($dtEnd)   echo ics_line("DTEND", $dtEnd);
  echo ics_line("SUMMARY", ics_escape($e['name'] ?? 'Event'));
  if (!empty($e['location'])) {
    echo ics_line("LOCATION", ics_escape($e['location']));
  }
  if (!empty($e['description'])) {
    echo ics_line("DESCRIPTION", ics_escape($e['description']));
  }
  echo ics_line("URL", $url);
  echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";
