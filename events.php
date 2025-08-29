<?php
require_once __DIR__.'/partials.php';
require_login();

$u = current_user();
$isAdmin = !empty($u['is_admin']);

$now = date('Y-m-d H:i:s');
$st = pdo()->prepare("SELECT * FROM events WHERE starts_at >= ? ORDER BY starts_at");
$st->execute([$now]);
$events = $st->fetchAll();

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
        <p><strong>When:</strong> <?=h(Settings::formatDateTime($e['starts_at']))?><?php if(!empty($e['ends_at'])): ?> &ndash; <?=h(Settings::formatDateTime($e['ends_at']))?><?php endif; ?></p>
        <?php if (!empty($e['location'])): ?><p><strong>Where:</strong> <?=h($e['location'])?></p><?php endif; ?>
        <?php if (!empty($e['description'])): ?><p><?=nl2br(h($e['description']))?></p><?php endif; ?>
        <?php if (!empty($e['max_cub_scouts'])): ?><p class="small"><strong>Max Cub Scouts:</strong> <?= (int)$e['max_cub_scouts'] ?></p><?php endif; ?>
        <p><a class="button" href="/event.php?id=<?= (int)$e['id'] ?>">View / RSVP</a></p>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
