<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/EventManagement.php';

// This is a public page - no login required
// But we'll show logged-in navigation if user is authenticated
$user = current_user_optional();

// Show all events since the most recent August 1st (start of the scouting year)
$augYear = (int)date('n') >= 8 ? (int)date('Y') : (int)date('Y') - 1;
$since = sprintf('%04d-08-01 00:00:00', $augYear);
$events = EventManagement::listUpcoming(500, $since);

/**
 * Format time smartly: hide :00 minutes, show non-zero minutes
 * Example: 6:00PM becomes 6PM, 6:30PM stays 6:30PM
 */
function formatTime(int $timestamp): string {
  $formatted = date('g:iA', $timestamp);
  // Remove :00 from times on the hour
  return preg_replace('/:00(AM|PM)/', '$1', $formatted);
}

// Helper to render start/end per spec
function renderEventWhen(string $startsAt, ?string $endsAt): string {
  $s = strtotime($startsAt);
  if ($s === false) return $startsAt;
  
  $dateStr = date('l, F j, Y', $s);
  $startTime = formatTime($s);
  
  if (!$endsAt) {
    return $dateStr . ' ' . $startTime;
  }

  $e = strtotime($endsAt);
  if ($e === false) {
    return $dateStr . ' ' . $startTime;
  }

  $endTime = formatTime($e);
  
  if (date('Y-m-d', $s) === date('Y-m-d', $e)) {
    // Same-day event: show as "Date StartTime - EndTime"
    return $dateStr . ' ' . $startTime . ' - ' . $endTime;
  }
  
  // Different-day event: show full end date
  $endDateStr = date('l, F j, Y', $e);
  return $dateStr . ' ' . $startTime . ' - ' . $endDateStr . ' ' . $endTime;
}

header_html('Pack Calendar', $user);
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;">Pack Calendar</h2>
  <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <a class="button" href="https://www.scarsdalepack440.com" target="_blank" rel="noopener">Main Website</a>
  </div>
</div>

<?php if (empty($events)): ?>
  <div class="card">
    <p>No events.</p>
  </div>
<?php else: ?>
  <div class="card">
    <table class="events-list">
      <thead>
        <tr>
          <th>Date & Time</th>
          <th>Event</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($events as $e): ?>
          <tr>
            <td><?= h(renderEventWhen($e['starts_at'], $e['ends_at'] ?? null)) ?></td>
            <td><?= h($e['name']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
