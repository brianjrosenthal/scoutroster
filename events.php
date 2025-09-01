<?php
require_once __DIR__.'/partials.php';
require_login();

$u = current_user();
$isAdmin = !empty($u['is_admin']);

$now = date('Y-m-d H:i:s');
$st = pdo()->prepare("SELECT * FROM events WHERE starts_at >= ? ORDER BY starts_at");
$st->execute([$now]);
$events = $st->fetchAll();

// Helper to render start/end per spec
function renderEventWhen(string $startsAt, ?string $endsAt): string {
  $s = strtotime($startsAt);
  if ($s === false) return $startsAt;
  $base = date('F j, Y gA', $s);
  if (!$endsAt) return $base;

  $e = strtotime($endsAt);
  if ($e === false) return $base;

  if (date('Y-m-d', $s) === date('Y-m-d', $e)) {
    // Same-day end time
    return $base . ' (ends at ' . date('gA', $e) . ')';
  }
  // Different-day end time
  return $base . ' (ends on ' . date('F j, Y', $e) . ' at ' . date('gA', $e) . ')';
}

/**
 * Truncate plain text to a maximum length with an ellipsis.
 */
function truncatePlain(string $text, int $limit = 200): string {
  $text = trim((string)$text);
  if ($text === '') return '';
  $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
  if ($len <= $limit) return $text;
  $slice = function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
  return rtrim($slice) . '…';
}

header_html('Upcoming Events');
?>
<h2>Upcoming Events</h2>

<?php if ($isAdmin): ?>
  <p><a class="button" href="/admin_events.php">Add Event</a></p>
<?php endif; ?>

<div class="card">
  <p class="small">
    Subscribe to the pack calendar:
    <a href="/calendar_feed.php">/calendar_feed.php</a> (add this URL to Google/Apple Calendar)
  </p>
</div>

<?php if (empty($events)): ?>
  <p class="small">No upcoming events.</p>
<?php else: ?>
  <div class="grid">
    <?php foreach ($events as $e): ?>
      <div class="card">
        <h3><a href="/event.php?id=<?= (int)$e['id'] ?>"><?=h($e['name'])?></a></h3>
        <p><strong>When:</strong> <?= h(renderEventWhen($e['starts_at'], $e['ends_at'] ?? null)) ?></p>
        <?php if (!empty($e['location'])): ?><p><strong>Where:</strong> <?=h($e['location'])?></p><?php endif; ?>
        <?php if (!empty($e['description'])): ?>
          <p><?= nl2br(h(truncatePlain($e['description'], 200))) ?></p>
        <?php endif; ?>
        <?php if (!empty($e['max_cub_scouts'])): ?><p class="small"><strong>Max Cub Scouts:</strong> <?= (int)$e['max_cub_scouts'] ?></p><?php endif; ?>
        <p><a class="button" href="/event.php?id=<?= (int)$e['id'] ?>">View / RSVP</a></p>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
