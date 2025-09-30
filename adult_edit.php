<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/UserManagement.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_once __DIR__.'/lib/Files.php';
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
$canUploadPhoto = UserManagement::canUploadAdultPhoto(UserContext::getLoggedInUserContext(), $id);

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
        $gradeInt = null;
        if ($gLbl !== '') {
          $g = GradeCalculator::parseGradeLabel($gLbl);
          if ($g === null || (int)$g < 0 || (int)$g > 5) {
            throw new InvalidArgumentException('Please select a valid grade (K–5) for '.$ptype.'.');
          }
          $gradeInt = (int)$g;
        }
        $pos = $ptype;
      } else {
        // Fixed titles
        $allowed = [
          'Cubmaster','Committee Chair','Treasurer','Assistant Cubmaster','Social Chair','Safety Chair'
        ];
        if (!in_array($ptype, $allowed, true)) {
          throw new InvalidArgumentException('Invalid position.');
        }
        $pos = $ptype;
        $gradeInt = null;
      }
      UserManagement::addLeadershipPosition(UserContext::getLoggedInUserContext(), $id, $pos, $gradeInt);
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

/** Handle POST (invite activation email) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'invite')) {
  require_csrf();
  try {
    if (!$canEditAll) { throw new RuntimeException('Admins only.'); }
    $sent = UserManagement::sendInvite(UserContext::getLoggedInUserContext(), $id);
    if ($sent) {
      $msg = 'Invitation sent if eligible.';
    } else {
      $err = 'Adult not eligible for invite.';
    }
  } catch (Throwable $e) {
    $err = 'Failed to send invitation.';
  }
}

/** Handle POST (update dietary preferences via modal) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'update_dietary_preferences')) {
  require_csrf();
  header('Content-Type: application/json');
  try {
    // Check permissions: admin or self
    if (!$canEditAll && ((int)($me['id'] ?? 0) !== (int)$id)) {
      echo json_encode(['ok' => false, 'error' => 'Not authorized']); exit;
    }
    
    $fields = [
      'dietary_vegetarian' => !empty($_POST['dietary_vegetarian']) ? 1 : 0,
      'dietary_vegan' => !empty($_POST['dietary_vegan']) ? 1 : 0,
      'dietary_lactose_free' => !empty($_POST['dietary_lactose_free']) ? 1 : 0,
      'dietary_no_pork_shellfish' => !empty($_POST['dietary_no_pork_shellfish']) ? 1 : 0,
      'dietary_nut_allergy' => !empty($_POST['dietary_nut_allergy']) ? 1 : 0,
      'dietary_other' => trim($_POST['dietary_other'] ?? '') ?: null,
    ];
    
    $ok = UserManagement::updateProfile(UserContext::getLoggedInUserContext(), $id, $fields, false);
    if ($ok) {
      echo json_encode(['ok' => true]); exit;
    }
    echo json_encode(['ok' => false, 'error' => 'Unable to update dietary preferences.']); exit;
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Operation failed']); exit;
  }
}

/** Handle POST (mark medical forms expiration via modal) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'mark_medical_forms_expiration')) {
  require_csrf();
  header('Content-Type: application/json');
  try {
    if (!\UserManagement::isApprover((int)($me['id'] ?? 0))) {
      echo json_encode(['ok' => false, 'error' => 'Not authorized']); exit;
    }
    $date = trim((string)($_POST['medical_forms_expiration_date'] ?? ''));
    $inPersonOptIn = !empty($_POST['medical_form_in_person_opt_in']) ? 1 : 0;
    
    // Date is only required if not opting for in-person forms
    if (!$inPersonOptIn && $date === '') { 
      echo json_encode(['ok' => false, 'error' => 'Date is required when not bringing forms in person']); 
      exit; 
    }
    // Basic Y-m-d validation (only if date is provided)
    if ($date !== '') {
      $dt = DateTime::createFromFormat('Y-m-d', $date);
      if (!$dt || $dt->format('Y-m-d') !== $date) {
        echo json_encode(['ok' => false, 'error' => 'Date must be in YYYY-MM-DD format.']); exit;
      }
    }
    $ok = UserManagement::updateProfile(UserContext::getLoggedInUserContext(), $id, [
      'medical_forms_expiration_date' => $date,
      'medical_form_in_person_opt_in' => $inPersonOptIn
    ]);
    if ($ok) {
      echo json_encode(['ok' => true]); exit;
    }
    echo json_encode(['ok' => false, 'error' => 'Unable to update.']); exit;
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Operation failed']); exit;
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
  $suppress_email_directory = !empty($_POST['suppress_email_directory']) ? 1 : 0;
  $suppress_phone_directory = !empty($_POST['suppress_phone_directory']) ? 1 : 0;

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
        'suppress_email_directory' => $suppress_email_directory,
        'suppress_phone_directory' => $suppress_phone_directory,
        'bsa_membership_number' => $bsa_membership_number,
        'bsa_registration_expires_on' => $bsa_registration_expires_on,
        'safeguarding_training_completed_on' => $safeguarding_training_completed_on,
        'emergency_contact1_name' => $em1_name,
        'emergency_contact1_phone' => $em1_phone,
        'emergency_contact2_name' => $em2_name,
        'emergency_contact2_phone' => $em2_phone,
      ], true);
      if ($ok) {
        header('Location: /adult_edit.php?id='.(int)$id.'&saved=1'); exit;
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
    'suppress_email_directory' => $suppress_email_directory,
    'suppress_phone_directory' => $suppress_phone_directory,
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
$linkedChildren = UserManagement::listChildrenForAdult($id);

header_html('Edit Adult');
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Edit Adult</h2>
  <?php if ($canEditAll && !empty($u['email']) && empty($u['email_verified_at'])): ?>
    <form method="post" class="inline" id="inviteForm">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="invite">
      <button class="button" id="inviteBtn">Invite user to activate account</button>
    </form>
  <?php endif; ?>
</div>
<?php
  if (isset($_GET['uploaded'])) { $msg = 'Photo uploaded.'; }
  if (isset($_GET['deleted'])) { $msg = 'Photo removed.'; }
  if (isset($_GET['saved'])) { $msg = 'Profile updated.'; }
  if (isset($_GET['medical_forms'])) { $msg = 'Medical Forms Expiration updated.'; }
  if (isset($_GET['child_added'])) { $msg = 'Child added.'; }
  if (isset($_GET['created'])) { $msg = trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? '')) . ' created successfully.'; }
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
      $aPhotoUrl = Files::profilePhotoUrl($u['photo_public_file_id'] ?? null);
    ?>
    <?php if ($aPhotoUrl !== ''): ?>
      <img class="avatar" src="<?= h($aPhotoUrl) ?>" alt="<?= h($aName) ?>" style="width:80px;height:80px">
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
    <?php if ($aPhotoUrl !== ''): ?>
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
      $aPhotoUrlFull = Files::profilePhotoUrl($u['photo_public_file_id'] ?? null);
    ?>
    <?php if ($aPhotoUrlFull !== ''): ?>
      <img class="avatar" src="<?= h($aPhotoUrlFull) ?>" alt="<?= h($aNameFull) ?>" style="width:80px;height:80px">
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
    <?php if ($aPhotoUrlFull !== ''): ?>
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
      <label class="inline">
        <input type="hidden" name="suppress_email_directory" value="0">
        <input type="checkbox" name="suppress_email_directory" value="1" <?= !empty($u['suppress_email_directory']) ? 'checked' : '' ?>>
        Suppress email from the directory
        <div class="small">This hides the adult's email from non-admin users. Administrators can still see it.</div>
      </label>

      <label class="inline">
        <input type="hidden" name="suppress_phone_directory" value="0">
        <input type="checkbox" name="suppress_phone_directory" value="1" <?= !empty($u['suppress_phone_directory']) ? 'checked' : '' ?>>
        Suppress phone number from the directory
        <div class="small">This hides the adult's phone numbers from non-admin users. Administrators can still see them.</div>
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
      <div>
        <div class="small">Medical Forms Expire</div>
        <div>
          <?php
            $medicalExpiration = $u['medical_forms_expiration_date'] ?? null;
            $inPersonOptIn = !empty($u['medical_form_in_person_opt_in']);
            
            if ($inPersonOptIn && ($medicalExpiration === null || $medicalExpiration === '' || (new DateTime($medicalExpiration) < new DateTime()))) {
              echo 'in person opt in';
            } else {
              echo h($medicalExpiration ?? '—');
            }
          ?>
          <?php if (\UserManagement::isApprover((int)($me['id'] ?? 0))): ?>
            <a href="#" class="small" id="edit_medical_forms_link" 
               data-current="<?= h($u['medical_forms_expiration_date'] ?? '') ?>"
               data-opt-in="<?= !empty($u['medical_form_in_person_opt_in']) ? '1' : '0' ?>" 
               style="margin-left:6px;">Edit</a>
          <?php endif; ?>
        </div>
      </div>
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

    <h3>Dietary Preferences</h3>
    <?php
      // Build dietary preferences display
      $dietaryPrefs = [];
      if (!empty($u['dietary_vegetarian'])) $dietaryPrefs[] = 'Vegetarian';
      if (!empty($u['dietary_vegan'])) $dietaryPrefs[] = 'Vegan';
      if (!empty($u['dietary_lactose_free'])) $dietaryPrefs[] = 'Lactose-Free';
      if (!empty($u['dietary_no_pork_shellfish'])) $dietaryPrefs[] = 'No pork or shellfish';
      if (!empty($u['dietary_nut_allergy'])) $dietaryPrefs[] = 'Nut allergy';
      if (!empty($u['dietary_other'])) $dietaryPrefs[] = trim($u['dietary_other']);
      
      $dietaryDisplay = empty($dietaryPrefs) ? 'None' : implode(', ', $dietaryPrefs);
      $canEditDietary = $canEditAll || ((int)($me['id'] ?? 0) === (int)$id);
    ?>
    <p><strong>Dietary Preferences:</strong> <?= h($dietaryDisplay) ?>
      <?php if ($canEditDietary): ?>
        <a href="#" id="editDietaryPrefsLink" class="small" style="margin-left:6px;">edit</a>
      <?php endif; ?>
    </p>

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
  <h3 style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
    Children
    <button type="button" class="button" data-open-child-modal="ac">Add Child</button>
  </h3>

  <?php if (empty($linkedChildren)): ?>
    <p class="small">No children linked to this adult.</p>
  <?php else: ?>
    <table class="list">
      <thead>
        <tr>
          <th>Child</th>
          <th>Grade</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($linkedChildren as $c): ?>
          <tr>
            <td><?=h($c['first_name'].' '.$c['last_name'])?></td>
            <?php $gi = GradeCalculator::gradeForClassOf((int)$c['class_of']); $gl = GradeCalculator::gradeLabel((int)$gi); ?>
            <td><?= h($gl) ?></td>
            <td class="small">
              <a class="button" href="/youth_edit.php?id=<?= (int)$c['id'] ?>">Edit</a>
              <form method="post" action="/adult_relationships.php" style="display:inline;margin-left:8px" onsubmit="return confirm('Unlink this child from this adult?');">
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

<?php
  require_once __DIR__ . '/partials_child_modal.php';
  render_child_modal(['mode' => 'edit', 'adult_id' => (int)$id, 'id_prefix' => 'ac']);
?>

<div class="card" style="margin-top:16px;">
  <h3 style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
    Leadership Positions
    <?php if ($canEditLeadership): ?>
      <button type="button" class="button" id="editLeadershipBtn">Edit</button>
    <?php endif; ?>
  </h3>
  
  <?php
    require_once __DIR__.'/lib/LeadershipManagement.php';
    try {
      $allPositions = LeadershipManagement::listAdultAllPositions($id);
    } catch (Throwable $e) {
      $allPositions = [];
      if (!$err) $err = 'Unable to load leadership positions.';
    }
  ?>
  
  <?php if (empty($allPositions)): ?>
    <p class="small">No leadership positions.</p>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($allPositions as $pos): ?>
        <li><?= h($pos['display_name']) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>


<!-- Medical Forms Expiration Modal (approvers only) -->
<?php if (\UserManagement::isApprover((int)($me['id'] ?? 0))): 
    // Compute default: exactly one year from today
    $medicalFormsDefault = (new DateTime('now'))->modify('+1 year')->format('Y-m-d');
?>
<div id="medical_forms_modal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content" style="max-width:420px;">
    <button class="close" type="button" id="medical_forms_close" aria-label="Close">&times;</button>
    <h3>Set Medical Forms Expiration</h3>
    <div id="medical_forms_err" class="error small" style="display:none;"></div>
    <form id="medical_forms_form" class="stack" method="post" action="/adult_edit.php?id=<?= (int)$id ?>">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="mark_medical_forms_expiration">
      <label>Medical Forms Expiration Date (YYYY-MM-DD)
        <input type="date" name="medical_forms_expiration_date" id="medical_forms_date" value="<?= h($medicalFormsDefault) ?>" required>
      </label>
      <label class="inline">
        <input type="checkbox" name="medical_form_in_person_opt_in" id="medical_forms_opt_in" value="1">
        Will bring medical forms to events in person
      </label>
      <p class="small">Set when this adult's medical forms expire. If you check "Will bring medical forms to events in person", this person will be considered as having valid medical forms for event purposes regardless of the expiration date.</p>
      <div class="actions">
        <button class="button primary" type="submit">Set Expiration Date</button>
        <button class="button" type="button" id="medical_forms_cancel">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script>
(function(){
  var modal = document.getElementById('medical_forms_modal');
  var closeBtn = document.getElementById('medical_forms_close');
  var cancelBtn = document.getElementById('medical_forms_cancel');
  var form = document.getElementById('medical_forms_form');
  var err = document.getElementById('medical_forms_err');

  function showErr(msg){ if(err){ err.style.display=''; err.textContent = msg || 'Operation failed.'; } }
  function clearErr(){ if(err){ err.style.display='none'; err.textContent=''; } }
  function open(){ if(modal){ modal.classList.remove('hidden'); modal.setAttribute('aria-hidden','false'); clearErr(); } }
  function close(){ if(modal){ modal.classList.add('hidden'); modal.setAttribute('aria-hidden','true'); } }

  var editLink = document.getElementById('edit_medical_forms_link');
  if (editLink) {
    editLink.addEventListener('click', function(e){
      e.preventDefault();
      var cur = this.getAttribute('data-current') || '';
      var optIn = this.getAttribute('data-opt-in') || '0';
      var dateInp = document.getElementById('medical_forms_date');
      var optInCheckbox = document.getElementById('medical_forms_opt_in');
      
      if (dateInp) {
        if (cur) {
          dateInp.value = cur;
        } else {
          // If no current date, use the default (one year from today)
          dateInp.value = '<?= h($medicalFormsDefault) ?>';
        }
      }
      
      if (optInCheckbox) {
        optInCheckbox.checked = (optIn === '1');
        // Reset storedDateValue when modal opens to ensure proper behavior
        storedDateValue = null;
        // Toggle required attribute and clear date field based on checkbox state
        toggleDateRequired();
      }
      
      open();
    });
  }

  // Store the original date value when checkbox is first checked
  var storedDateValue = null;

  // Function to toggle the required attribute and reset date field
  function toggleDateRequired() {
    var dateInp = document.getElementById('medical_forms_date');
    var optInCheckbox = document.getElementById('medical_forms_opt_in');
    
    if (dateInp && optInCheckbox) {
      if (optInCheckbox.checked) {
        // Store current date value before clearing it
        if (dateInp.value && storedDateValue === null) {
          storedDateValue = dateInp.value;
        }
        // Clear the date field and remove required attribute
        dateInp.value = '';
        dateInp.removeAttribute('required');
      } else {
        // Restore the stored date value or use default
        if (storedDateValue !== null) {
          dateInp.value = storedDateValue;
          storedDateValue = null; // Reset stored value
        } else {
          // Use the default one year from today if no stored value
          dateInp.value = '<?= h($medicalFormsDefault) ?>';
        }
        dateInp.setAttribute('required', 'required');
      }
    }
  }

  // Listen for checkbox changes
  var optInCheckbox = document.getElementById('medical_forms_opt_in');
  if (optInCheckbox) {
    optInCheckbox.addEventListener('change', toggleDateRequired);
  }
  if (closeBtn) closeBtn.addEventListener('click', function(){ close(); });
  if (cancelBtn) cancelBtn.addEventListener('click', function(){ close(); });
  if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) close(); });

  if (form) {
    form.addEventListener('submit', function(e){
      e.preventDefault();
      clearErr();
      var fd = new FormData(form);
      fetch(form.getAttribute('action') || window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json().catch(function(){ throw new Error('Invalid server response'); }); })
        .then(function(json){
          if (json && json.ok) {
            window.location = '/adult_edit.php?id=<?= (int)$id ?>&medical_forms=1';
          } else {
            showErr((json && json.error) ? json.error : 'Operation failed.');
          }
        })
        .catch(function(){ showErr('Network error.'); });
    });
  }
})();
</script>
<?php endif; ?>

<!-- Dietary Preferences Modal -->
<?php $canEditDietaryModal = $canEditAll || ((int)($me['id'] ?? 0) === (int)$id); ?>
<?php if ($canEditDietaryModal): ?>
<div id="dietaryPrefsModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content">
    <button class="close" type="button" id="dietaryPrefsModalClose" aria-label="Close">&times;</button>
    <h3>Edit Dietary Preferences</h3>
    <div id="dietaryPrefsErr" class="error small" style="display:none;"></div>
    <form id="dietaryPrefsForm" class="stack" method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="update_dietary_preferences">
      
      <label class="inline">
        <input type="checkbox" name="dietary_vegetarian" value="1" <?= !empty($u['dietary_vegetarian']) ? 'checked' : '' ?>>
        Vegetarian
      </label>
      
      <label class="inline">
        <input type="checkbox" name="dietary_vegan" value="1" <?= !empty($u['dietary_vegan']) ? 'checked' : '' ?>>
        Vegan
      </label>
      
      <label class="inline">
        <input type="checkbox" name="dietary_lactose_free" value="1" <?= !empty($u['dietary_lactose_free']) ? 'checked' : '' ?>>
        Lactose-Free
      </label>
      
      <label class="inline">
        <input type="checkbox" name="dietary_no_pork_shellfish" value="1" <?= !empty($u['dietary_no_pork_shellfish']) ? 'checked' : '' ?>>
        No pork or shellfish
      </label>
      
      <label class="inline">
        <input type="checkbox" name="dietary_nut_allergy" value="1" <?= !empty($u['dietary_nut_allergy']) ? 'checked' : '' ?>>
        Nut allergy
      </label>
      
      <label>Other:
        <input type="text" name="dietary_other" value="<?= h($u['dietary_other'] ?? '') ?>" placeholder="Describe other dietary needs">
      </label>
      
      <div class="actions">
        <button class="button primary" type="submit">Save Dietary Preferences</button>
        <button class="button" type="button" id="dietaryPrefsCancel">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Leadership Positions Modal -->
<?php if ($canEditLeadership): ?>
<div id="leadershipModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content" style="max-width:600px;">
    <button class="close" type="button" id="leadershipModalClose" aria-label="Close">&times;</button>
    <h3>Edit Leadership Positions</h3>
    <div id="leadershipErr" class="error small" style="display:none;"></div>
    <div id="leadershipSuccess" class="flash small" style="display:none;"></div>
    
    <!-- Pack Leadership Positions Section -->
    <div class="stack" style="margin-bottom:24px;">
      <h4>Pack Leadership Positions</h4>
      <div id="packPositionsList">
        <!-- Will be populated via JavaScript -->
      </div>
      
      <div class="grid" style="grid-template-columns:1fr auto;gap:8px;align-items:end;">
        <label>Assign New Position
          <select id="packPositionSelect">
            <option value="">-- Select Position --</option>
            <?php
              try {
                $availablePositions = LeadershipManagement::listPackPositions();
                foreach ($availablePositions as $pos) {
                  echo '<option value="' . (int)$pos['id'] . '">' . h($pos['name']) . '</option>';
                }
              } catch (Throwable $e) {
                // Ignore errors in modal
              }
            ?>
          </select>
        </label>
        <button type="button" class="button" id="assignPackPositionBtn">Assign</button>
      </div>
    </div>
    
    <!-- Den Leader Positions Section -->
    <div class="stack">
      <h4>Den Leader Positions</h4>
      <div id="denLeadersList">
        <!-- Will be populated via JavaScript -->
      </div>
      
      <div class="grid" style="grid-template-columns:1fr auto;gap:8px;align-items:end;">
        <label>Assign New Grade
          <select id="denLeaderGradeSelect">
            <option value="">-- Select Grade --</option>
            <?php for ($i = 0; $i <= 5; $i++): ?>
              <option value="<?= $i ?>"><?= $i === 0 ? 'K' : $i ?></option>
            <?php endfor; ?>
          </select>
        </label>
        <button type="button" class="button" id="assignDenLeaderBtn">Assign</button>
      </div>
    </div>
    
    <div class="actions" style="margin-top:24px;">
      <button class="button" type="button" id="leadershipModalCancel">Close</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  // Add double-click protection to invite form
  var inviteForm = document.getElementById('inviteForm');
  var inviteBtn = document.getElementById('inviteBtn');
  
  if (inviteForm && inviteBtn) {
    inviteForm.addEventListener('submit', function(e) {
      if (inviteBtn.disabled) {
        e.preventDefault();
        return;
      }
      inviteBtn.disabled = true;
      inviteBtn.textContent = 'Sending invitation...';
    });
  }

  // Leadership Positions Modal
  var leadershipModal = document.getElementById('leadershipModal');
  var leadershipEditBtn = document.getElementById('editLeadershipBtn');
  var leadershipCloseBtn = document.getElementById('leadershipModalClose');
  var leadershipCancelBtn = document.getElementById('leadershipModalCancel');
  var leadershipErr = document.getElementById('leadershipErr');
  var leadershipSuccess = document.getElementById('leadershipSuccess');
  var adultId = <?= (int)$id ?>;
  
  function showLeadershipErr(msg){ 
    if(leadershipErr){ 
      leadershipErr.style.display=''; 
      leadershipErr.textContent = msg || 'Operation failed.'; 
    } 
    if(leadershipSuccess){ leadershipSuccess.style.display='none'; }
  }
  
  function showLeadershipSuccess(msg){ 
    if(leadershipSuccess){ 
      leadershipSuccess.style.display=''; 
      leadershipSuccess.textContent = msg || 'Success'; 
    } 
    if(leadershipErr){ leadershipErr.style.display='none'; }
  }
  
  function clearLeadershipMessages(){ 
    if(leadershipErr){ leadershipErr.style.display='none'; leadershipErr.textContent=''; } 
    if(leadershipSuccess){ leadershipSuccess.style.display='none'; leadershipSuccess.textContent=''; }
  }
  
  function openLeadershipModal(){ 
    if(leadershipModal){ 
      leadershipModal.classList.remove('hidden'); 
      leadershipModal.setAttribute('aria-hidden','false'); 
      clearLeadershipMessages(); 
      loadCurrentPositions();
    } 
  }
  
  function closeLeadershipModal(){ 
    if(leadershipModal){ 
      leadershipModal.classList.add('hidden'); 
      leadershipModal.setAttribute('aria-hidden','true'); 
      // Reload the page to refresh the positions display
      window.location.reload();
    } 
  }
  
  function loadCurrentPositions() {
    // This would ideally be loaded via AJAX, but for simplicity we'll use the page data
    // In a production system, you'd want a dedicated AJAX endpoint to get current positions
    loadPackPositions();
    loadDenLeaderPositions();
  }
  
  function loadPackPositions() {
    var packPositionsList = document.getElementById('packPositionsList');
    if (packPositionsList) {
      packPositionsList.innerHTML = '<p class="small">Loading positions...</p>';
    }
    
    // Fetch current positions via AJAX
    var formData = new FormData();
    formData.append('csrf', '<?= h(csrf_token()) ?>');
    formData.append('adult_id', adultId);
    
    fetch('/adult_get_positions_ajax.php', { 
      method: 'POST', 
      body: formData, 
      credentials: 'same-origin' 
    })
    .then(function(r){ return r.json(); })
    .then(function(json){
      if (json && json.ok) {
        updatePackPositionsDisplay(json.pack_positions || []);
        updateDenLeadersDisplay(json.den_assignments || []);
      } else {
        if (packPositionsList) {
          packPositionsList.innerHTML = '<p class="error small">Failed to load positions</p>';
        }
        var denLeadersList = document.getElementById('denLeadersList');
        if (denLeadersList) {
          denLeadersList.innerHTML = '<p class="error small">Failed to load positions</p>';
        }
      }
    })
    .catch(function(){
      if (packPositionsList) {
        packPositionsList.innerHTML = '<p class="error small">Network error loading positions</p>';
      }
      var denLeadersList = document.getElementById('denLeadersList');
      if (denLeadersList) {
        denLeadersList.innerHTML = '<p class="error small">Network error loading positions</p>';
      }
    });
  }
  
  function loadDenLeaderPositions() {
    // This is now handled by loadPackPositions() to avoid duplicate AJAX calls
    var denLeadersList = document.getElementById('denLeadersList');
    if (denLeadersList) {
      denLeadersList.innerHTML = '<p class="small">Loading positions...</p>';
    }
  }
  
  function updatePackPositionsDisplay(positions) {
    var packPositionsList = document.getElementById('packPositionsList');
    if (!packPositionsList) return;
    
    if (!positions || positions.length === 0) {
      packPositionsList.innerHTML = '<p class="small">No pack leadership positions.</p>';
      return;
    }
    
    var html = '<ul class="list">';
    positions.forEach(function(pos) {
      html += '<li>' + escapeHtml(pos.name) + ' <button type="button" class="button danger small" onclick="removePackPosition(' + pos.id + ')">Remove</button></li>';
    });
    html += '</ul>';
    packPositionsList.innerHTML = html;
  }
  
  function updateDenLeadersDisplay(assignments) {
    var denLeadersList = document.getElementById('denLeadersList');
    if (!denLeadersList) return;
    
    if (!assignments || assignments.length === 0) {
      denLeadersList.innerHTML = '<p class="small">No den leader assignments.</p>';
      return;
    }
    
    var html = '<ul class="list">';
    assignments.forEach(function(assignment) {
      var gradeLabel = assignment.grade === 0 ? 'K' : assignment.grade;
      html += '<li>Den Leader Grade ' + gradeLabel + ' <button type="button" class="button danger small" onclick="removeDenLeader(' + assignment.grade + ')">Remove</button></li>';
    });
    html += '</ul>';
    denLeadersList.innerHTML = html;
  }
  
  function escapeHtml(text) {
    var map = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
  }
  
  // Global functions for remove buttons
  window.removePackPosition = function(positionId) {
    if (!confirm('Remove this leadership position?')) return;
    
    var formData = new FormData();
    formData.append('csrf', '<?= h(csrf_token()) ?>');
    formData.append('adult_id', adultId);
    formData.append('position_id', positionId);
    
    fetch('/adult_remove_leadership_position_ajax.php', { 
      method: 'POST', 
      body: formData, 
      credentials: 'same-origin' 
    })
    .then(function(r){ return r.json(); })
    .then(function(json){
      if (json && json.ok) {
        showLeadershipSuccess(json.message || 'Position removed successfully');
        updatePackPositionsDisplay(json.positions || []);
      } else {
        showLeadershipErr((json && json.error) ? json.error : 'Failed to remove position.');
      }
    })
    .catch(function(){ showLeadershipErr('Network error.'); });
  };
  
  window.removeDenLeader = function(grade) {
    var gradeLabel = grade === 0 ? 'K' : grade;
    if (!confirm('Remove den leader assignment for grade ' + gradeLabel + '?')) return;
    
    var formData = new FormData();
    formData.append('csrf', '<?= h(csrf_token()) ?>');
    formData.append('adult_id', adultId);
    formData.append('grade', grade);
    
    fetch('/adult_remove_den_leader_ajax.php', { 
      method: 'POST', 
      body: formData, 
      credentials: 'same-origin' 
    })
    .then(function(r){ return r.json(); })
    .then(function(json){
      if (json && json.ok) {
        showLeadershipSuccess(json.message || 'Den leader assignment removed successfully');
        updateDenLeadersDisplay(json.den_assignments || []);
      } else {
        showLeadershipErr((json && json.error) ? json.error : 'Failed to remove den leader assignment.');
      }
    })
    .catch(function(){ showLeadershipErr('Network error.'); });
  };
  
  // Event listeners
  if (leadershipEditBtn) {
    leadershipEditBtn.addEventListener('click', function(e){
      e.preventDefault();
      openLeadershipModal();
    });
  }
  
  if (leadershipCloseBtn) leadershipCloseBtn.addEventListener('click', closeLeadershipModal);
  if (leadershipCancelBtn) leadershipCancelBtn.addEventListener('click', closeLeadershipModal);
  if (leadershipModal) leadershipModal.addEventListener('click', function(e){ if (e.target === leadershipModal) closeLeadershipModal(); });
  
  // Assign pack position
  var assignPackPositionBtn = document.getElementById('assignPackPositionBtn');
  if (assignPackPositionBtn) {
    assignPackPositionBtn.addEventListener('click', function() {
      var select = document.getElementById('packPositionSelect');
      var positionId = select ? select.value : '';
      
      if (!positionId) {
        showLeadershipErr('Please select a position.');
        return;
      }
      
      var formData = new FormData();
      formData.append('csrf', '<?= h(csrf_token()) ?>');
      formData.append('adult_id', adultId);
      formData.append('position_id', positionId);
      
      fetch('/adult_assign_leadership_position_ajax.php', { 
        method: 'POST', 
        body: formData, 
        credentials: 'same-origin' 
      })
      .then(function(r){ return r.json(); })
      .then(function(json){
        if (json && json.ok) {
          showLeadershipSuccess(json.message || 'Position assigned successfully');
          updatePackPositionsDisplay(json.positions || []);
          if (select) select.value = '';
        } else {
          showLeadershipErr((json && json.error) ? json.error : 'Failed to assign position.');
        }
      })
      .catch(function(){ showLeadershipErr('Network error.'); });
    });
  }
  
  // Assign den leader
  var assignDenLeaderBtn = document.getElementById('assignDenLeaderBtn');
  if (assignDenLeaderBtn) {
    assignDenLeaderBtn.addEventListener('click', function() {
      var select = document.getElementById('denLeaderGradeSelect');
      var grade = select ? select.value : '';
      
      if (grade === '') {
        showLeadershipErr('Please select a grade.');
        return;
      }
      
      var formData = new FormData();
      formData.append('csrf', '<?= h(csrf_token()) ?>');
      formData.append('adult_id', adultId);
      formData.append('grade', grade);
      
      fetch('/adult_assign_den_leader_ajax.php', { 
        method: 'POST', 
        body: formData, 
        credentials: 'same-origin' 
      })
      .then(function(r){ return r.json(); })
      .then(function(json){
        if (json && json.ok) {
          showLeadershipSuccess(json.message || 'Den leader assigned successfully');
          updateDenLeadersDisplay(json.den_assignments || []);
          if (select) select.value = '';
        } else {
          showLeadershipErr((json && json.error) ? json.error : 'Failed to assign den leader.');
        }
      })
      .catch(function(){ showLeadershipErr('Network error.'); });
    });
  }

  // Dietary Preferences Modal
  var dietaryModal = document.getElementById('dietaryPrefsModal');
  var dietaryCloseBtn = document.getElementById('dietaryPrefsModalClose');
  var dietaryCancelBtn = document.getElementById('dietaryPrefsCancel');
  var dietaryForm = document.getElementById('dietaryPrefsForm');
  var dietaryErr = document.getElementById('dietaryPrefsErr');
  var dietaryEditLink = document.getElementById('editDietaryPrefsLink');

  function showDietaryErr(msg){ if(dietaryErr){ dietaryErr.style.display=''; dietaryErr.textContent = msg || 'Operation failed.'; } }
  function clearDietaryErr(){ if(dietaryErr){ dietaryErr.style.display='none'; dietaryErr.textContent=''; } }
  function openDietaryModal(){ if(dietaryModal){ dietaryModal.classList.remove('hidden'); dietaryModal.setAttribute('aria-hidden','false'); clearDietaryErr(); } }
  function closeDietaryModal(){ if(dietaryModal){ dietaryModal.classList.add('hidden'); dietaryModal.setAttribute('aria-hidden','true'); } }

  if (dietaryEditLink) {
    dietaryEditLink.addEventListener('click', function(e){
      e.preventDefault();
      openDietaryModal();
    });
  }

  if (dietaryCloseBtn) dietaryCloseBtn.addEventListener('click', function(){ closeDietaryModal(); });
  if (dietaryCancelBtn) dietaryCancelBtn.addEventListener('click', function(){ closeDietaryModal(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeDietaryModal(); });
  if (dietaryModal) dietaryModal.addEventListener('click', function(e){ if (e.target === dietaryModal) closeDietaryModal(); });

  if (dietaryForm) {
    dietaryForm.addEventListener('submit', function(e){
      e.preventDefault();
      clearDietaryErr();
      var fd = new FormData(dietaryForm);
      fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json().catch(function(){ throw new Error('Invalid server response'); }); })
        .then(function(json){
          if (json && json.ok) {
            window.location.reload();
          } else {
            showDietaryErr((json && json.error) ? json.error : 'Operation failed.');
          }
        })
        .catch(function(){ showDietaryErr('Network error.'); });
    });
  }
})();
</script>

<?php footer_html(); ?>
