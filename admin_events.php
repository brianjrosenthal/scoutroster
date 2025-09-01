<?php
require_once __DIR__.'/partials.php';
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
  $st = pdo()->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
  $st->execute([$editingId]);
  $editing = $st->fetch();
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
    $max_cub_scouts = trim($_POST['max_cub_scouts'] ?? '');

    $errors = [];
    if ($name === '') $errors[] = 'Name is required.';
    if ($starts_at === null) $errors[] = 'Valid start date/time is required.';

    if (empty($errors)) {
      try {
        $eventId = $id > 0 ? $id : 0;
        if ($id > 0) {
          $st = pdo()->prepare("UPDATE events SET name=?, starts_at=?, ends_at=?, location=?, location_address=?, description=?, max_cub_scouts=? WHERE id=?");
          $ok = $st->execute([
            $name, $starts_at, ($ends_at ?: null),
            ($location !== '' ? $location : null),
            ($location_address !== '' ? $location_address : null),
            ($description !== '' ? $description : null),
            ($max_cub_scouts !== '' ? (int)$max_cub_scouts : null),
            $id
          ]);
        } else {
          $st = pdo()->prepare("INSERT INTO events (name, starts_at, ends_at, location, location_address, description, max_cub_scouts) VALUES (?,?,?,?,?,?,?)");
          $ok = $st->execute([
            $name, $starts_at, ($ends_at ?: null),
            ($location !== '' ? $location : null),
            ($location_address !== '' ? $location_address : null),
            ($description !== '' ? $description : null),
            ($max_cub_scouts !== '' ? (int)$max_cub_scouts : null),
          ]);
          if ($ok) { $eventId = (int)pdo()->lastInsertId(); }
        }

        if ($ok) {
          // Optional event image upload
          if (!empty($_FILES['photo']) && is_array($_FILES['photo']) && empty($_FILES['photo']['error'])) {
            $f = $_FILES['photo'];
            $tmp = $f['tmp_name'] ?? '';
            $name = $f['name'] ?? 'photo';
            if (is_uploaded_file($tmp)) {
              $allowedExt = ['jpg','jpeg','png','webp','heic'];
              $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
              if (in_array($ext, $allowedExt, true)) {
                $destDir = __DIR__ . '/uploads/events/' . (int)$eventId;
                if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }
                $rand = bin2hex(random_bytes(12));
                $storedRel = 'uploads/events/' . (int)$eventId . '/' . $rand . '.' . $ext;
                $storedAbs = __DIR__ . '/' . $storedRel;
                if (@move_uploaded_file($tmp, $storedAbs)) {
                  $up = pdo()->prepare("UPDATE events SET photo_path=? WHERE id=?");
                  $up->execute([$storedRel, (int)$eventId]);
                }
              }
            }
          }
          header('Location: /admin_events.php'); exit;
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
        'max_cub_scouts' => ($max_cub_scouts !== '' ? (int)$max_cub_scouts : null),
      ];
    }
  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      try {
        $st = pdo()->prepare("DELETE FROM events WHERE id=?");
        $st->execute([$id]);
        header('Location: /admin_events.php'); exit;
      } catch (Throwable $e) {
        $err = 'Failed to delete event.';
      }
    }
  }
}

// Load list of events (future and past for management)
$events = [];
$st = pdo()->query("SELECT * FROM events ORDER BY starts_at DESC");
$events = $st->fetchAll();

$showEditor = ($editingId > 0) || (($_GET['show'] ?? '') === 'add') || (($_POST['action'] ?? '') === 'save' && !empty($err));

header_html('Manage Events');
?>
<h2>Manage Events</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>
<?php if (!$showEditor): ?>
  <p><a class="button primary" href="/admin_events.php?show=add">Add Event</a></p>
<?php endif; ?>

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
      <label>Max Cub Scouts
        <input type="number" name="max_cub_scouts" min="0" value="<?=h($editing['max_cub_scouts'] ?? '')?>">
      </label>
    </div>
    <label>Location
      <input type="text" name="location" value="<?=h($editing['location'] ?? '')?>">
    </label>
    <label>Location Address
      <textarea name="location_address" rows="3" placeholder="Street&#10;City, State ZIP"><?=h($editing['location_address'] ?? '')?></textarea>
    </label>
    <label>Description
      <textarea name="description" rows="4"><?=h($editing['description'] ?? '')?></textarea>
    </label>
    <p class="small">Formatting: Use <code>[label](https://example.com)</code> for links, or paste a full URL (http/https) to auto-link. New lines are preserved.</p>
    <?php if (!empty($editing['photo_path'])): ?>
      <div class="small">Current Image:<br>
        <img src="/<?= h($editing['photo_path']) ?>" alt="Event image" width="180" style="height:auto;border-radius:8px;">
      </div>
    <?php endif; ?>
    <label>Event Image (optional)
      <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp,.heic">
    </label>
    <div class="actions">
      <button class="primary" type="submit"><?= $editing ? 'Save' : 'Create' ?></button>
      <a class="button" href="/admin_events.php">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>All Events</h3>
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
