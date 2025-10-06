<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/Files.php';
require_once __DIR__.'/lib/EventManagement.php';
require_once __DIR__.'/lib/EventUIManager.php';
require_admin();

$me = current_user();
$isAdmin = !empty($me['is_admin']);

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
$eventForNav = null;
if ($editingId > 0) {
  $editing = EventManagement::findById($editingId);
  $eventForNav = $editing;
  if (!$editing) { $editingId = 0; }
}

// Set default dates for new events (Sunday after next Sunday, 3pm-5pm)
$defaultStartsAt = '';
$defaultEndsAt = '';
if ($editingId === 0) {
  $now = new DateTime();
  
  // Find next Sunday
  $nextSunday = clone $now;
  $daysUntilSunday = (7 - $now->format('w')) % 7;
  if ($daysUntilSunday === 0) {
    // If today is Sunday, get next Sunday
    $daysUntilSunday = 7;
  }
  $nextSunday->add(new DateInterval('P' . $daysUntilSunday . 'D'));
  
  // Add 7 more days to get "the Sunday after next Sunday"
  $targetSunday = clone $nextSunday;
  $targetSunday->add(new DateInterval('P7D'));
  
  // Set times: 3pm start, 5pm end
  $startDateTime = clone $targetSunday;
  $startDateTime->setTime(15, 0, 0); // 3pm
  
  $endDateTime = clone $targetSunday;
  $endDateTime->setTime(17, 0, 0); // 5pm
  
  $defaultStartsAt = $startDateTime->format('Y-m-d H:i:s');
  $defaultEndsAt = $endDateTime->format('Y-m-d H:i:s');
}

// Handle create/update
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
    $needs_medical_form = isset($_POST['needs_medical_form']) ? 1 : 0;
    $rsvp_url = trim($_POST['rsvp_url'] ?? '');
    $rsvp_url_label = trim($_POST['rsvp_url_label'] ?? '');
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
          'needs_medical_form' => $needs_medical_form,
          'rsvp_url' => ($rsvp_url !== '' ? $rsvp_url : null),
          'rsvp_url_label' => ($rsvp_url_label !== '' ? $rsvp_url_label : null),
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
          header('Location: /event.php?id=' . (int)$eventId); exit;
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
        'needs_medical_form' => $needs_medical_form,
        'rsvp_url' => ($rsvp_url !== '' ? $rsvp_url : null),
        'rsvp_url_label' => ($rsvp_url_label !== '' ? $rsvp_url_label : null),
        'google_maps_url' => ($google_maps_url !== '' ? $google_maps_url : null),
      ];
    }
  }
}

$pageTitle = $editing ? 'Edit Event' : 'Add Event';
header_html($pageTitle);
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;"><?= $editing ? h($editing['name'] ?? 'Edit Event') : 'Add Event' ?></h2>
  <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <?php if ($editing): ?>
      <a class="button" href="/event.php?id=<?= (int)$editing['id'] ?>">Back to Event</a>
      <?= EventUIManager::renderAdminMenu((int)$editing['id'], 'edit') ?>
    <?php else: ?>
      <a class="button" href="/events.php">Back to Events</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

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
        <input type="datetime-local" name="starts_at" value="<?=h(to_datetime_local_value($editing['starts_at'] ?? $defaultStartsAt))?>" required>
      </label>
      <label>Ends at
        <input type="datetime-local" name="ends_at" value="<?=h(to_datetime_local_value($editing['ends_at'] ?? $defaultEndsAt))?>">
      </label>
    </div>
    <label>
      <input type="checkbox" name="allow_non_user_rsvp" <?php $v = $editing['allow_non_user_rsvp'] ?? 1; echo ((int)$v === 1 ? 'checked' : ''); ?>>
      Allow public RSVP (non-user)
    </label>
    <label>
      <input type="checkbox" name="needs_medical_form" <?php $v = $editing['needs_medical_form'] ?? 0; echo ((int)$v === 1 ? 'checked' : ''); ?>>
      Needs Medical Form
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
    <p class="small">If provided, the "map" link uses this URL instead of the auto-generated address search.</p>
    <label>Description
      <textarea name="description" rows="4"><?=h($editing['description'] ?? '')?></textarea>
    </label>
    <label>External RSVP URL
      <input type="url" name="rsvp_url" value="<?= h($editing['rsvp_url'] ?? '') ?>" placeholder="https://www.evite.com/... or https://facebook.com/events/...">
    </label>
    <label>RSVP Button Label
      <input type="text" name="rsvp_url_label" value="<?= h($editing['rsvp_url_label'] ?? '') ?>" placeholder="RSVP Here" maxlength="100">
    </label>
    <p class="small">If External RSVP URL is provided, internal RSVP buttons are replaced by a button with your custom label (or "RSVP Here" if no label is specified) across logged-in, invite, and public pages.</p>
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
      <a class="button" href="<?= $editing ? '/event.php?id='.(int)$editing['id'] : '/events.php' ?>">Cancel</a>
    </div>
  </form>
</div>

<?php if ($editing): ?>
  <?= EventUIManager::renderAdminModals((int)$editing['id']) ?>
  <?= EventUIManager::renderAdminMenuScript((int)$editing['id']) ?>
<?php endif; ?>

<script>
(function() {
  const startsAtInput = document.querySelector('input[name="starts_at"]');
  const endsAtInput = document.querySelector('input[name="ends_at"]');
  
  if (!startsAtInput || !endsAtInput) return;
  
  startsAtInput.addEventListener('change', function() {
    const startsAtValue = startsAtInput.value;
    const endsAtValue = endsAtInput.value;
    
    if (!startsAtValue) return;
    
    // Parse the starts_at datetime
    const startsAt = new Date(startsAtValue);
    if (isNaN(startsAt.getTime())) return;
    
    let shouldUpdate = false;
    
    // Check if ends_at is empty
    if (!endsAtValue) {
      shouldUpdate = true;
    } else {
      const endsAt = new Date(endsAtValue);
      
      // Check if ends_at is before the new starts_at
      if (endsAt < startsAt) {
        shouldUpdate = true;
      } else {
        // Check if ends_at is more than 1 week ahead of starts_at
        const oneWeekInMs = 7 * 24 * 60 * 60 * 1000;
        const timeDiff = endsAt.getTime() - startsAt.getTime();
        if (timeDiff > oneWeekInMs) {
          shouldUpdate = true;
        }
      }
    }
    
    if (shouldUpdate) {
      // Calculate new ends_at: starts_at + 1.5 hours (90 minutes)
      const newEndsAt = new Date(startsAt.getTime() + (90 * 60 * 1000));
      
      // Format as datetime-local value: YYYY-MM-DDTHH:MM
      const year = newEndsAt.getFullYear();
      const month = String(newEndsAt.getMonth() + 1).padStart(2, '0');
      const day = String(newEndsAt.getDate()).padStart(2, '0');
      const hours = String(newEndsAt.getHours()).padStart(2, '0');
      const minutes = String(newEndsAt.getMinutes()).padStart(2, '0');
      
      endsAtInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
    }
  });
})();
</script>

<?php footer_html(); ?>
