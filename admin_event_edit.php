<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/Files.php';
require_once __DIR__.'/lib/EventManagement.php';
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
          'needs_medical_form' => $needs_medical_form,
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
        'evite_rsvp_url' => ($evite_rsvp_url !== '' ? $evite_rsvp_url : null),
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
      <?php if ($isAdmin): ?>
        <div style="position: relative;">
          <button class="button" id="adminLinksBtn" style="display: flex; align-items: center; gap: 4px;">
            Admin Links
            <span style="font-size: 12px;">▼</span>
          </button>
          <div id="adminLinksDropdown" style="
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            z-index: 1000;
            min-width: 180px;
            margin-top: 4px;
          ">
            <a href="/admin_event_edit.php?id=<?= (int)$editing['id'] ?>" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee; background-color: #f5f5f5;">Edit Event</a>
            <a href="/event_public.php?event_id=<?= (int)$editing['id'] ?>" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Public RSVP Link</a>
            <a href="/admin_event_invite.php?event_id=<?= (int)$editing['id'] ?>" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Invite</a>
            <a href="#" id="adminCopyEmailsBtn" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Copy Emails</a>
            <a href="#" id="adminManageRsvpBtn" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Manage RSVPs</a>
            <a href="#" id="adminExportAttendeesBtn" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Export Attendees</a>
            <a href="/event_dietary_needs.php?id=<?= (int)$editing['id'] ?>" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Dietary Needs</a>
            <?php
              // Show Event Compliance only for Cubmaster, Treasurer, or Committee Chair
              $showCompliance = false;
              try {
                $stPos = pdo()->prepare("SELECT LOWER(position) AS p FROM adult_leadership_positions WHERE adult_id=?");
                $stPos->execute([(int)($me['id'] ?? 0)]);
                $rowsPos = $stPos->fetchAll();
                if (is_array($rowsPos)) {
                  foreach ($rowsPos as $pr) {
                    $p = trim((string)($pr['p'] ?? ''));
                    if ($p === 'cubmaster' || $p === 'treasurer' || $p === 'committee chair') { 
                      $showCompliance = true; 
                      break; 
                    }
                  }
                }
              } catch (Throwable $e) {
                $showCompliance = false;
              }
              if ($showCompliance): ?>
                <a href="/event_compliance.php?id=<?= (int)$editing['id'] ?>" style="display: block; padding: 8px 12px; text-decoration: none; color: #333;">Event Compliance</a>
              <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <a class="button" href="/admin_events.php">Back to Events</a>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  // Admin Links Dropdown
  const adminLinksBtn = document.getElementById('adminLinksBtn');
  const adminLinksDropdown = document.getElementById('adminLinksDropdown');
  
  if (adminLinksBtn && adminLinksDropdown) {
    adminLinksBtn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      const isVisible = adminLinksDropdown.style.display === 'block';
      adminLinksDropdown.style.display = isVisible ? 'none' : 'block';
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
      if (!adminLinksBtn.contains(e.target) && !adminLinksDropdown.contains(e.target)) {
        adminLinksDropdown.style.display = 'none';
      }
    });
    
    // Close dropdown when pressing Escape
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        adminLinksDropdown.style.display = 'none';
      }
    });
    
    // Add hover effects
    const dropdownLinks = adminLinksDropdown.querySelectorAll('a');
    dropdownLinks.forEach(link => {
      link.addEventListener('mouseenter', function() {
        this.style.backgroundColor = '#f5f5f5';
      });
      link.addEventListener('mouseleave', function() {
        this.style.backgroundColor = 'white';
      });
    });
  }
})();
</script>

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
    <label>Evite RSVP Link
      <input type="url" name="evite_rsvp_url" value="<?= h($editing['evite_rsvp_url'] ?? '') ?>" placeholder="https://www.evite.com/...">
    </label>
    <p class="small">If provided, internal RSVP buttons are replaced by an "RSVP TO EVITE" button across logged-in, invite, and public pages.</p>
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
      <a class="button" href="<?= $editing ? '/event.php?id='.(int)$editing['id'] : '/admin_events.php' ?>">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
