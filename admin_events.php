<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/EventManagement.php';
require_admin();

$msg = null;
$err = null;

// Handle delete only
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $action = $_POST['action'] ?? '';

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      try {
        $ctx = UserContext::getLoggedInUserContext();
        EventManagement::delete($ctx, $id);
        header('Location: /events.php'); exit;
      } catch (Throwable $e) {
        $err = 'Failed to delete event.';
      }
    }
  }
}

/**
 * Load events by view:
 * - upcoming (default): starts_at >= NOW(), ordered ascending (closest first)
 * - past: starts_at < NOW(), ordered descending (most recent first)
 */
$view = $_GET['view'] ?? 'upcoming';
if ($view !== 'past') $view = 'upcoming';

$events = [];
if ($view === 'upcoming') {
  $events = EventManagement::listUpcoming(500);
} else {
  $events = EventManagement::listPast(500);
}

header_html('Manage Events');
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Manage Events</h2>
  <div class="actions">
    <a class="button primary" href="/admin_event_edit.php">Add Event</a>
  </div>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <h3><?= $view === 'past' ? 'Previous Events' : 'Upcoming Events' ?></h3>
    <div class="actions">
      <?php if ($view === 'past'): ?>
        <a class="button" href="/events.php">View Upcoming</a>
      <?php else: ?>
        <a class="button" href="/events.php?view=past">View Previous</a>
      <?php endif; ?>
    </div>
  </div>
  <?php if (empty($events)): ?>
    <p class="small">No events yet.</p>
  <?php else: ?>
    <table class="list">
      <thead>
        <tr>
          <th>Name</th>
          <th>Starts</th>
          <th>Ends</th>
          <th>Location</th>
          <th>Max</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($events as $e): ?>
          <tr>
            <td><?=h($e['name'])?></td>
            <td><?=h(Settings::formatDateTime($e['starts_at']))?></td>
            <td><?=h(Settings::formatDateTime($e['ends_at']))?></td>
            <td><?=h($e['location'])?></td>
            <td><?= $e['max_cub_scouts'] !== null ? (int)$e['max_cub_scouts'] : '' ?></td>
            <td class="small">
              <a class="button" href="/event.php?id=<?= (int)$e['id'] ?>">View</a>
              <a class="button" href="/admin_event_edit.php?id=<?= (int)$e['id'] ?>">Edit</a>
              <a class="button" href="/admin_event_invite.php?event_id=<?= (int)$e['id'] ?>">Invite</a>
              <a class="button" href="/admin_event_volunteers.php?event_id=<?= (int)$e['id'] ?>">Volunteers</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this event?');">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                <button class="button danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
