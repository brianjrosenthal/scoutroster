<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/Files.php';
require_once __DIR__.'/lib/EventManagement.php';
require_admin();

$msg = null;
$err = null;

// Helpers to map between SQL DATETIME and input[type=datetime-local] value
function to_datetime_local_value(?string $sql): string {
  if (!$sql) return '';
  $t = strtotime($sql);
  if ($t === false) return '';
  return date('Y-m-d\TH:i', $t);
}
function from_datetime_local_value(?string $v): ?string {
  if (!$v) return null;
  // Expect 'YYYY-MM-DDTHH:MM'
  $t = strtotime($v);
  if ($t === false) return null;
  return date('Y-m-d H:i:s', $t);
}

// Load event for edit
$editingId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editing = null;
if ($editingId > 0) {
  $editing = EventManagement::findById($editingId);
  if (!$editing) { $editingId = 0; }
}

// Handle create/update/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $action = $_POST['action'] ?? '';

  if ($action === 'save') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name = trim($_POST['name'] ?? '');
    $starts_at = from_datetime_local_value($_POST['starts_at'] ?? '');
    $ends_at   = from_datetime_local_value($_POST['ends_at'] ?? '');
    $location  = trim($_POST['location'] ?? '');
    $location_address = trim($_POST['location_address'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $allow_non_user_rsvp = isset($_POST['allow_non_user_rsvp']) ? 1 : 0;
    $evite_rsvp_url = trim($_POST['evite_rsvp_url'] ?? '');
    $google_maps_url = trim($_POST['google_maps_url'] ?? '');

    $errors = [];
    if ($name === '') $errors[] = 'Name is required.';
    if ($starts_at === null) $errors[] = 'Valid start date/time is required.';

    if (empty($errors)) {
      try {
        $eventId = $id > 0 ? $id : 0;
        $ctx = UserContext::getLoggedInUserContext();
        $data = [
          'name' => $name,
          'starts_at' => $starts_at,
          'ends_at' => ($ends_at ?: null),
          'location' => ($location !== '' ? $location : null),
          'location_address' => ($location_address !== '' ? $location_address : null),
          'description' => ($description !== '' ? $description : null),
          'allow_non_user_rsvp' => $allow_non_user_rsvp,
          'evite_rsvp_url' => ($evite_rsvp_url !== '' ? $evite_rsvp_url : null),
          'google_maps_url' => ($google_maps_url !== '' ? $google_maps_url : null),
        ];
        if ($id > 0) {
          $ok = EventManagement::update($ctx, $id, $data);
          $eventId = $id;
        } else {
          $eventId = EventManagement::create($ctx, $data);
          $ok = $eventId > 0;
        }

        if ($ok) {
          // Optional event image upload -> store in DB (public_files)
          if (!empty($_FILES['photo']) && is_array($_FILES['photo']) && empty($_FILES['photo']['error'])) {
            $f = $_FILES['photo'];
            $tmp = $f['tmp_name'] ?? '';
            $name = $f['name'] ?? 'photo';
            if (is_uploaded_file($tmp)) {
              $allowedExt = ['jpg','jpeg','png','webp','heic'];
              $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
              if (in_array($ext, $allowedExt, true)) {
                $mime = 'application/octet-stream';
                if (function_exists('finfo_open')) {
                  $finfo = finfo_open(FILEINFO_MIME_TYPE);
                  if ($finfo) {
                    $mt = finfo_file($finfo, $tmp);
                    if (is_string($mt) && $mt !== '') $mime = $mt;
                    finfo_close($finfo);
                  }
                }
                $data = @file_get_contents($tmp);
                if ($data !== false) {
                  try {
                    $publicId = Files::insertPublicFile($data, $mime, $name, (int)($ctx->id));
                    EventManagement::setPhotoPublicFileId($ctx, (int)$eventId, (int)$publicId);
                  } catch (Throwable $e) {
                    // swallow; leave without image if failed
                  }
                }
              }
            }
          }
          header('Location: /admin_events.php?id=' . (int)$eventId); exit;
        } else {
          $err = ($id > 0) ? 'Failed to update event.' : 'Failed to create event.';
        }
      } catch (Throwable $e) {
        $err = 'Error saving event.';
      }
    } else {
      $err = implode(' ', $errors);
      // Rebuild $editing from POST for redisplay
      $editingId = (int)($_POST['id'] ?? 0);
      $editing = [
        'id' => $editingId,
        'name' => $name,
        'starts_at' => $starts_at,
        'ends_at' => $ends_at,
        'location' => $location,
        'location_address' => $location_address,
        'description' => $description,
        'allow_non_user_rsvp' => $allow_non_user_rsvp,
        'evite_rsvp_url' => ($evite_rsvp_url !== '' ? $evite_rsvp_url : null),
        'google_maps_url' => ($google_maps_url !== '' ? $google_maps_url : null),
      ];
    }
  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      try {
        $ctx = UserContext::getLoggedInUserContext();
        EventManagement::delete($ctx, $id);
        header('Location: /admin_events.php'); exit;
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

$showEditor = ($editingId > 0) || (($_GET['show'] ?? '') === 'add') || (($_POST['action'] ?? '') === 'save' && !empty($err));

header_html('Manage Events');
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Manage Events</h2>
  <?php if (!$showEditor): ?>
    <div class="actions">
      <a class="button primary" href="/admin_events.php?show=add">Add Event</a>
    </div>
  <?php endif; ?>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<?php if ($showEditor): ?>
<div class="card">
  <form method="post" class="stack" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <label>Name
      <input type="text" name="name" value="<?=h($editing['name'] ?? '')?>" required>
    </label>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Starts at
        <input type="datetime-local" name="starts_at" value="<?=h(to_datetime_local_value($editing['starts_at'] ?? null))?>" required>
      </label>
      <label>Ends at
        <input type="datetime-local" name="ends_at" value="<?=h(to_datetime_local_value($editing['ends_at'] ?? null))?>">
      </label>
    </div>
    <label>
      <input type="checkbox" name="allow_non_user_rsvp" <?php $v = $editing['allow_non_user_rsvp'] ?? 1; echo ((int)$v === 1 ? 'checked' : ''); ?>>
      Allow public RSVP (non-user)
    </label>
    <label>Location
      <input type="text" name="location" value="<?=h($editing['location'] ?? '')?>">
    </label>
    <label>Location Address
      <textarea name="location_address" rows="3" placeholder="Street&#10;City, State ZIP"><?=h($editing['location_address'] ?? '')?></textarea>
    </label>
    <label>Google Maps Link Override
      <input type="url" name="google_maps_url" value="<?= h($editing['google_maps_url'] ?? '') ?>" placeholder="https://maps.google.com/...">
    </label>
    <p class="small">If provided, the “map” link uses this URL instead of the auto-generated address search.</p>
    <label>Description
      <textarea name="description" rows="4"><?=h($editing['description'] ?? '')?></textarea>
    </label>
    <label>Evite RSVP Link
      <input type="url" name="evite_rsvp_url" value="<?= h($editing['evite_rsvp_url'] ?? '') ?>" placeholder="https://www.evite.com/...">
    </label>
    <p class="small">If provided, internal RSVP buttons are replaced by an “RSVP TO EVITE” button across logged-in, invite, and public pages.</p>
    <p class="small">Formatting: Use <code>[label](https://example.com)</code> for links, or paste a full URL (http/https) to auto-link. New lines are preserved.</p>
    <?php
      $imgUrl = '';
      if ($editing) {
        $imgUrl = Files::eventPhotoUrl($editing['photo_public_file_id'] ?? null);
      }
    ?>
    <?php if ($imgUrl !== ''): ?>
      <div class="small">Current Image:<br>
        <img src="<?= h($imgUrl) ?>" alt="Event image" width="180" style="height:auto;border-radius:8px;">
      </div>
    <?php endif; ?>
    <label>Event Image (optional)
      <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp,.heic">
    </label>
    <div class="actions">
      <button class="primary" type="submit"><?= $editing ? 'Save' : 'Create' ?></button>
      <?php if (!empty($editing['id'])): ?>
        <a class="button" href="/admin_event_volunteers.php?event_id=<?= (int)$editing['id'] ?>">Manage Volunteers</a>
      <?php endif; ?>
      <a class="button" href="/admin_events.php">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <h3><?= $view === 'past' ? 'Previous Events' : 'Upcoming Events' ?></h3>
    <div class="actions">
      <?php if ($view === 'past'): ?>
        <a class="button" href="/admin_events.php">View Upcoming</a>
      <?php else: ?>
        <a class="button" href="/admin_events.php?view=past">View Previous</a>
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
              <a class="button" href="/admin_events.php?id=<?= (int)$e['id'] ?>">Edit</a>
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
