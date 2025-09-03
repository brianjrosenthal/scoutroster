<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/UserManagement.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_login();

$err = null;
$msg = null;

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

// Permissions: admin can edit everything; adult can manage their own leadership positions
$me = current_user();
$canEditAll = !empty($me['is_admin']);
$canEditLeadership = $canEditAll || ((int)($me['id'] ?? 0) === (int)$id);

// Allow co-parents to upload photo only
$canUploadPhoto = false;
try {
  if ($canEditAll || ((int)($me['id'] ?? 0) === (int)$id)) {
    $canUploadPhoto = true;
  } else {
    $st = pdo()->prepare("
      SELECT 1
      FROM parent_relationships pr1
      JOIN parent_relationships pr2 ON pr1.youth_id = pr2.youth_id
      WHERE pr1.adult_id = ? AND pr2.adult_id = ?
      LIMIT 1
    ");
    $st->execute([(int)($me['id'] ?? 0), (int)$id]);
    $canUploadPhoto = (bool)$st->fetchColumn();
  }
} catch (Throwable $e) {
  $canUploadPhoto = false;
}

if (!$canEditLeadership && !$canUploadPhoto) { http_response_code(403); exit('Forbidden'); }

 // Load current record
$u = UserManagement::findFullById($id);
if (!$u) { http_response_code(404); exit('Not found'); }

// Helper to normalize empty to null
$nn = function($v) {
  $v = is_string($v) ? trim($v) : $v;
  return ($v === '' ? null : $v);
};

 // Handle POST (leadership positions)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['lp_add','lp_remove'], true)) {
  require_csrf();
  $act = $_POST['action'];
  try {
    if (!$canEditLeadership) { throw new RuntimeException('Forbidden'); }
    if ($act === 'lp_add') {
      $ptype = trim((string)($_POST['position_type'] ?? ''));
      $pos = '';
      if ($ptype === '' ) {
        throw new InvalidArgumentException('Please select a position.');
      }
      if ($ptype === 'Other') {
        $other = trim((string)($_POST['position_other'] ?? ''));
        if ($other === '') {
          throw new InvalidArgumentException('Please enter a title for Other.');
        }
        $pos = $other;
      } elseif ($ptype === 'Den Leader' || $ptype === 'Assistant Den Leader') {
        $gLbl = trim((string)($_POST['grade'] ?? ''));
        // Normalize to K or 0..5 label
        $g = GradeCalculator::parseGradeLabel($gLbl);
        if ($g === null) {
          throw new InvalidArgumentException('Please select a valid grade for '.$ptype.'.');
        }
        $display = GradeCalculator::gradeLabel((int)$g);
        $pos = $ptype.' (Grade '.$display.')';
      } else {
        // Fixed titles
        $allowed = [
          'Cubmaster','Committee Chair','Treasurer','Assistant Cubmaster','Social Chair','Safety Chair',
          'Den Leader','Assistant Den Leader'
        ];
        if (!in_array($ptype, $allowed, true)) {
          throw new InvalidArgumentException('Invalid position.');
        }
        $pos = $ptype;
      }
      UserManagement::addLeadershipPosition(UserContext::getLoggedInUserContext(), $id, $pos);
      $msg = 'Leadership position added.';
    } elseif ($act === 'lp_remove') {
      $lpId = (int)($_POST['leadership_id'] ?? 0);
      UserManagement::removeLeadershipPosition(UserContext::getLoggedInUserContext(), $id, $lpId);
      $msg = 'Leadership position removed.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage() ?: 'Unable to update leadership positions.';
  }
}

// Handle POST (update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'save_profile')) {
  require_csrf();

  // Required base fields
  $first = trim($_POST['first_name'] ?? '');
  $last  = trim($_POST['last_name'] ?? '');
  $email = strtolower(trim($_POST['email'] ?? ''));

  // Optional personal info
  $preferred_name = $nn($_POST['preferred_name'] ?? '');
  $street1 = $nn($_POST['street1'] ?? '');
  $street2 = $nn($_POST['street2'] ?? '');
  $city    = $nn($_POST['city'] ?? '');
  $state   = $nn($_POST['state'] ?? '');
  $zip     = $nn($_POST['zip'] ?? '');
  $email2  = $nn($_POST['email2'] ?? '');
  $phone_home = $nn($_POST['phone_home'] ?? '');
  $phone_cell = $nn($_POST['phone_cell'] ?? '');
  $shirt_size = $nn($_POST['shirt_size'] ?? '');
  $photo_path = $nn($_POST['photo_path'] ?? '');

  // Scouting info (admin-editable)
  $bsa_membership_number = $nn($_POST['bsa_membership_number'] ?? '');
  $bsa_registration_expires_on = $nn($_POST['bsa_registration_expires_on'] ?? '');
  $safeguarding_training_completed_on = $nn($_POST['safeguarding_training_completed_on'] ?? '');

  // Validate dates format (YYYY-MM-DD) or null
  $dateFields = [
    'bsa_registration_expires_on' => $bsa_registration_expires_on,
    'safeguarding_training_completed_on' => $safeguarding_training_completed_on,
  ];
  foreach ($dateFields as $k => $v) {
    if ($v !== null) {
      $d = DateTime::createFromFormat('Y-m-d', $v);
      if (!$d || $d->format('Y-m-d') !== $v) {
        $err = ucfirst(str_replace('_', ' ', $k)).' must be in YYYY-MM-DD format.';
        break;
      }
    }
  }

  // Medical/emergency contacts
  $em1_name  = $nn($_POST['emergency_contact1_name'] ?? '');
  $em1_phone = $nn($_POST['emergency_contact1_phone'] ?? '');
  $em2_name  = $nn($_POST['emergency_contact2_name'] ?? '');
  $em2_phone = $nn($_POST['emergency_contact2_phone'] ?? '');

  // Role
  $is_admin = !empty($_POST['is_admin']) ? 1 : 0;

  // Validate requireds
  if (!$err) {
    $errors = [];
    if ($first === '') $errors[] = 'First name is required.';
    if ($last === '')  $errors[] = 'Last name is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is invalid.';
    if (!empty($errors)) $err = implode(' ', $errors);
  }

  if (!$err) {
    try {
      $ok = UserManagement::updateProfile(UserContext::getLoggedInUserContext(), $id, [
        'first_name' => $first,
        'last_name'  => $last,
        'email'      => $email,
        'is_admin'   => $is_admin,
        'preferred_name' => $preferred_name,
        'street1' => $street1,
        'street2' => $street2,
        'city' => $city,
        'state' => $state,
        'zip' => $zip,
        'email2' => $email2,
        'phone_home' => $phone_home,
        'phone_cell' => $phone_cell,
        'shirt_size' => $shirt_size,
        'photo_path' => $photo_path,
        'bsa_membership_number' => $bsa_membership_number,
        'bsa_registration_expires_on' => $bsa_registration_expires_on,
        'safeguarding_training_completed_on' => $safeguarding_training_completed_on,
        'emergency_contact1_name' => $em1_name,
        'emergency_contact1_phone' => $em1_phone,
        'emergency_contact2_name' => $em2_name,
        'emergency_contact2_phone' => $em2_phone,
      ], true);
      if ($ok) {
        header('Location: /adults.php'); exit;
      } else {
        $err = 'Failed to update adult.';
      }
    } catch (Throwable $e) {
      $err = 'Error updating adult. Ensure the email is unique.';
    }
  }

  // Merge for redisplay on error
  $u = array_merge($u, [
    'first_name' => $first,
    'last_name' => $last,
    'email' => $email,
    'is_admin' => $is_admin,
    'preferred_name' => $preferred_name,
    'street1' => $street1,
    'street2' => $street2,
    'city' => $city,
    'state' => $state,
    'zip' => $zip,
    'email2' => $email2,
    'phone_home' => $phone_home,
    'phone_cell' => $phone_cell,
    'shirt_size' => $shirt_size,
    'photo_path' => $photo_path,
    'bsa_membership_number' => $bsa_membership_number,
    'bsa_registration_expires_on' => $bsa_registration_expires_on,
    'safeguarding_training_completed_on' => $safeguarding_training_completed_on,
    'emergency_contact1_name' => $em1_name,
    'emergency_contact1_phone' => $em1_phone,
    'emergency_contact2_name' => $em2_name,
    'emergency_contact2_phone' => $em2_phone,
  ]);
}

/** Load linked children for relationship management */
$stChildren = pdo()->prepare("
  SELECT y.*, dm.den_id, d.den_name
  FROM parent_relationships pr
  JOIN youth y ON y.id = pr.youth_id
  LEFT JOIN den_memberships dm ON dm.youth_id = y.id
  LEFT JOIN dens d ON d.id = dm.den_id
  WHERE pr.adult_id = ?
  ORDER BY y.last_name, y.first_name
");
$stChildren->execute([$id]);
$linkedChildren = $stChildren->fetchAll();

header_html('Edit Adult');
?>
<h2>Edit Adult</h2>
<?php
  if (isset($_GET['uploaded'])) { $msg = 'Photo uploaded.'; }
  if (isset($_GET['deleted'])) { $msg = 'Photo removed.'; }
  if (isset($_GET['err'])) { $err = 'Photo upload failed.'; }
?>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<?php if (!$canEditAll && ((int)($me['id'] ?? 0) !== (int)$id) && $canUploadPhoto): ?>
<div class="card">
  <h3>Profile Photo</h3>
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <?php
      $aName = trim((string)($u['first_name'] ?? '').' '.(string)($u['last_name'] ?? ''));
      $aInitials = strtoupper((string)substr((string)($u['first_name'] ?? ''),0,1).(string)substr((string)($u['last_name'] ?? ''),0,1));
      $aPhoto = trim((string)($u['photo_path'] ?? ''));
    ?>
    <?php if ($aPhoto !== ''): ?>
      <img class="avatar" src="<?= h($aPhoto) ?>" alt="<?= h($aName) ?>" style="width:80px;height:80px">
    <?php else: ?>
      <div class="avatar avatar-initials" aria-hidden="true" style="width:80px;height:80px;font-size:20px"><?= h($aInitials) ?></div>
    <?php endif; ?>

    <form method="post" action="/upload_photo.php?type=adult&adult_id=<?= (int)$id ?>&return_to=<?= h('/adult_edit.php?id='.(int)$id) ?>" enctype="multipart/form-data" class="stack" style="margin-left:auto;min-width:260px">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label>Upload a new photo
        <input type="file" name="photo" accept="image/*" required>
      </label>
      <div class="actions">
        <button class="button">Upload Photo</button>
      </div>
    </form>
    <?php if ($aPhoto !== ''): ?>
      <form method="post" action="/upload_photo.php?type=adult&adult_id=<?= (int)$id ?>&return_to=<?= h('/adult_edit.php?id='.(int)$id) ?>" onsubmit="return confirm('Remove this photo?');" style="margin-left:12px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <button class="button danger">Remove Photo</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php footer_html(); return; ?>
<?php endif; ?>

<div class="card">
  <h3>Profile Photo</h3>
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <?php
      $aNameFull = trim((string)($u['first_name'] ?? '').' '.(string)($u['last_name'] ?? ''));
      $aInitialsFull = strtoupper((string)substr((string)($u['first_name'] ?? ''),0,1).(string)substr((string)($u['last_name'] ?? ''),0,1));
      $aPhotoFull = trim((string)($u['photo_path'] ?? ''));
    ?>
    <?php if ($aPhotoFull !== ''): ?>
      <img class="avatar" src="<?= h($aPhotoFull) ?>" alt="<?= h($aNameFull) ?>" style="width:80px;height:80px">
    <?php else: ?>
      <div class="avatar avatar-initials" aria-hidden="true" style="width:80px;height:80px;font-size:20px"><?= h($aInitialsFull) ?></div>
    <?php endif; ?>

    <form method="post" action="/upload_photo.php?type=adult&adult_id=<?= (int)$id ?>&return_to=<?= h('/adult_edit.php?id='.(int)$id) ?>" enctype="multipart/form-data" class="stack" style="margin-left:auto;min-width:260px">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label>Upload a new photo
        <input type="file" name="photo" accept="image/*" required>
      </label>
      <div class="actions">
        <button class="button">Upload Photo</button>
      </div>
    </form>
    <?php if ($aPhotoFull !== ''): ?>
      <form method="post" action="/upload_photo.php?type=adult&adult_id=<?= (int)$id ?>&return_to=<?= h('/adult_edit.php?id='.(int)$id) ?>" onsubmit="return confirm('Remove this photo?');" style="margin-left:12px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <button class="button danger">Remove Photo</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <form method="post" class="stack">
    <input type="hidden" name="action" value="save_profile">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <h3>Basic</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>First name
        <input type="text" name="first_name" value="<?=h($u['first_name'])?>" required>
      </label>
      <label>Last name
        <input type="text" name="last_name" value="<?=h($u['last_name'])?>" required>
      </label>
      <label>Email
        <input type="email" name="email" value="<?=h($u['email'])?>">
      </label>
      <label>Preferred name
        <input type="text" name="preferred_name" value="<?=h($u['preferred_name'])?>">
      </label>
      <label class="inline"><input type="checkbox" name="is_admin" value="1" <?= !empty($u['is_admin']) ? 'checked' : '' ?>> Admin</label>
    </div>

    <h3>Address</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Street 1
        <input type="text" name="street1" value="<?=h($u['street1'])?>">
      </label>
      <label>Street 2
        <input type="text" name="street2" value="<?=h($u['street2'])?>">
      </label>
      <label>City
        <input type="text" name="city" value="<?=h($u['city'])?>">
      </label>
      <label>State
        <input type="text" name="state" value="<?=h($u['state'])?>">
      </label>
      <label>Zip
        <input type="text" name="zip" value="<?=h($u['zip'])?>">
      </label>
    </div>

    <h3>Contact</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Secondary Email
        <input type="email" name="email2" value="<?=h($u['email2'])?>">
      </label>
      <label>Home Phone
        <input type="text" name="phone_home" value="<?=h($u['phone_home'])?>">
      </label>
      <label>Cell Phone
        <input type="text" name="phone_cell" value="<?=h($u['phone_cell'])?>">
      </label>
      <label>Shirt Size
        <input type="text" name="shirt_size" value="<?=h($u['shirt_size'])?>">
      </label>
      <label>Photo Path
        <input type="text" name="photo_path" value="<?=h($u['photo_path'])?>">
      </label>
    </div>

    <h3>Scouting</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>BSA Membership #
        <input type="text" name="bsa_membership_number" value="<?=h($u['bsa_membership_number'])?>">
      </label>
      <label>BSA Registration Expires On
        <input type="date" name="bsa_registration_expires_on" value="<?=h($u['bsa_registration_expires_on'])?>" placeholder="YYYY-MM-DD">
      </label>
      <label>Safeguarding Training Completed On
        <input type="date" name="safeguarding_training_completed_on" value="<?=h($u['safeguarding_training_completed_on'])?>" placeholder="YYYY-MM-DD">
      </label>
    </div>

    <h3>Emergency Contacts</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Emergency Contact 1 Name
        <input type="text" name="emergency_contact1_name" value="<?=h($u['emergency_contact1_name'])?>">
      </label>
      <label>Emergency Contact 1 Phone
        <input type="text" name="emergency_contact1_phone" value="<?=h($u['emergency_contact1_phone'])?>">
      </label>
      <label>Emergency Contact 2 Name
        <input type="text" name="emergency_contact2_name" value="<?=h($u['emergency_contact2_name'])?>">
      </label>
      <label>Emergency Contact 2 Phone
        <input type="text" name="emergency_contact2_phone" value="<?=h($u['emergency_contact2_phone'])?>">
      </label>
    </div>

    <div class="actions">
      <?php if ($canEditAll): ?>
        <button class="primary" type="submit">Save</button>
      <?php else: ?>
        <span class="small">Only admins can edit profile fields.</span>
      <?php endif; ?>
      <a class="button" href="/adults.php">Cancel</a>
    </div>
  </form>
</div>

<div class="card" style="margin-top:16px;">
  <h3>Medical Forms</h3>
  <?php
    $stMF = pdo()->prepare("SELECT id, original_filename, uploaded_at FROM medical_forms WHERE adult_id=? ORDER BY uploaded_at DESC");
    $stMF->execute([$id]);
    $adultForms = $stMF->fetchAll();
  ?>
  <?php if (empty($adultForms)): ?>
    <p class="small">No medical forms on file.</p>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($adultForms as $mf): ?>
        <li>
          <a href="/medical_download.php?id=<?= (int)$mf['id'] ?>" target="_blank" rel="noopener noreferrer"><?=h($mf['original_filename'] ?? 'Medical Form')?></a>
          <span class="small">uploaded <?=h($mf['uploaded_at'])?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <div class="actions">
    <a class="button" href="/upload_medical.php?type=adult&adult_id=<?= (int)$id ?>&return_to=<?=h('/adult_edit.php?id='.(int)$id)?>">Upload Medical Form</a>
  </div>
</div>

<div class="card" style="margin-top:16px;">
  <h3>Parent Relationships</h3>

  <?php if (empty($linkedChildren)): ?>
    <p class="small">No children linked to this adult.</p>
  <?php else: ?>
    <table class="list">
      <thead>
        <tr>
          <th>Child</th>
          <th>Den</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($linkedChildren as $c): ?>
          <tr>
            <td><?=h($c['first_name'].' '.$c['last_name'])?></td>
            <td><?=h($c['den_name'] ?? '')?></td>
            <td class="small">
              <form method="post" action="/adult_relationships.php" style="display:inline" onsubmit="return confirm('Unlink this child from this adult?');">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="unlink">
                <input type="hidden" name="adult_id" value="<?= (int)$id ?>">
                <input type="hidden" name="youth_id" value="<?= (int)$c['id'] ?>">
                <button class="button danger">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:16px;">
  <h3>Link a Child to this Adult</h3>
  <form method="post" action="/adult_relationships.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="link">
    <input type="hidden" name="adult_id" value="<?= (int)$id ?>">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
      <label>Child
        <select name="youth_id" required>
          <?php
          $allY = pdo()->query("SELECT id, first_name, last_name FROM youth ORDER BY last_name, first_name")->fetchAll();
          foreach ($allY as $yy) {
            echo '<option value="'.(int)$yy['id'].'">'.h($yy['last_name'].', '.$yy['first_name']).'</option>';
          }
          ?>
        </select>
      </label>
      <label>Relationship
        <select name="relationship">
          <option value="father">father</option>
          <option value="mother">mother</option>
          <option value="guardian">guardian</option>
        </select>
      </label>
    </div>
    <div class="actions">
      <button class="primary">Link Child</button>
    </div>
  </form>
</div>

<div class="card" style="margin-top:16px;">
  <h3>Leadership Positions</h3>
  <?php
    try {
      $positions = UserManagement::listLeadershipPositions(UserContext::getLoggedInUserContext(), $id);
    } catch (Throwable $e) {
      $positions = [];
      if (!$err) $err = 'Unable to load leadership positions.';
    }
  ?>
  <?php if (empty($positions)): ?>
    <p class="small">No leadership positions.</p>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($positions as $p): ?>
        <li>
          <?= h($p['position']) ?>
          <?php if ($canEditLeadership): ?>
            <form method="post" style="display:inline; margin-left:8px;" onsubmit="return confirm('Remove this position?');">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="lp_remove">
              <input type="hidden" name="leadership_id" value="<?= (int)$p['id'] ?>">
              <button class="button danger">Remove</button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($canEditLeadership): ?>
    <form method="post" class="stack" style="margin-top:8px;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="lp_add">
      <label>Position
        <select name="position_type" id="position_type">
          <option value="">-- Select --</option>
          <option value="Cubmaster">Cubmaster</option>
          <option value="Assistant Cubmaster">Assistant Cubmaster</option>
          <option value="Committee Chair">Committee Chair</option>
          <option value="Treasurer">Treasurer</option>
          <option value="Social Chair">Social Chair</option>
          <option value="Safety Chair">Safety Chair</option>
          <option value="Den Leader">Den Leader</option>
          <option value="Assistant Den Leader">Assistant Den Leader</option>
          <option value="Other">Other</option>
        </select>
      </label>

      <div id="lp_grade_wrap" style="display:none; margin-top:8px;">
        <label>Grade
          <select name="grade" id="lp_grade_select">
            <option value="">-- Select Grade --</option>
            <?php for ($i=0; $i<=5; $i++): $lbl = GradeCalculator::gradeLabel($i); ?>
              <option value="<?= h($lbl) ?>"><?= $i === 0 ? 'K' : $i ?></option>
            <?php endfor; ?>
          </select>
        </label>
      </div>

      <div id="lp_other_wrap" style="display:none; margin-top:8px;">
        <label>Other title
          <input type="text" name="position_other" maxlength="255" placeholder="Enter title">
        </label>
      </div>

      <div class="actions" style="margin-top:8px;">
        <button class="button">Add Position</button>
      </div>
      <p class="small">Duplicates are not allowed for the same adult.</p>
    </form>
    <script>
      (function(){
        const typeSel = document.getElementById('position_type');
        const gradeWrap = document.getElementById('lp_grade_wrap');
        const otherWrap = document.getElementById('lp_other_wrap');
        function update() {
          const v = (typeSel && typeSel.value) || '';
          const needsGrade = (v === 'Den Leader' || v === 'Assistant Den Leader');
          const needsOther = (v === 'Other');
          if (gradeWrap) gradeWrap.style.display = needsGrade ? '' : 'none';
          if (otherWrap) otherWrap.style.display = needsOther ? '' : 'none';
        }
        if (typeSel) {
          typeSel.addEventListener('change', update);
          update();
        }
      })();
    </script>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
