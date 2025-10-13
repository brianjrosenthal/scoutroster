<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_once __DIR__.'/lib/YouthManagement.php';
require_once __DIR__.'/lib/UserManagement.php';
require_once __DIR__.'/lib/Files.php';
require_once __DIR__.'/lib/ParentRelationships.php';
require_once __DIR__.'/lib/PaymentNotifications.php';
require_login();
$me = current_user();
$isAdmin = !empty($me['is_admin']);
$isCubmaster = UserManagement::isCubmaster((int)($me['id'] ?? 0));

$err = null;
$msg = null;

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

$y = YouthManagement::getForEdit(UserContext::getLoggedInUserContext(), $id);
if (!$y) { http_response_code(404); exit('Not found'); }

// Compute current grade from class_of
$currentGrade = GradeCalculator::gradeForClassOf((int)$y['class_of']);

 // Permissions/visibility for Registration & Dues fields
$isParentOfThis = false;
try {
  $isParentOfThis = ParentRelationships::isAdultLinkedToYouth((int)($me['id'] ?? 0), (int)$id);
} catch (Throwable $e) {
  $isParentOfThis = false;
}
$canEditPaidUntil = \UserManagement::isApprover((int)($me['id'] ?? 0));
$canEditRegExpires = $isAdmin;

/** Handle POST (mark paid until via modal) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'mark_paid_until')) {
  require_csrf();
  header('Content-Type: application/json');
  try {
    if (!$canEditPaidUntil) {
      echo json_encode(['ok' => false, 'error' => 'Not authorized']); exit;
    }
    $date = trim((string)($_POST['date_paid_until'] ?? ''));
    if ($date === '') { echo json_encode(['ok' => false, 'error' => 'Date is required']); exit; }
    // Basic Y-m-d validation (only if date is provided)
    if ($date !== '') {
      $dt = DateTime::createFromFormat('Y-m-d', $date);
      if (!$dt || $dt->format('Y-m-d') !== $date) {
        echo json_encode(['ok' => false, 'error' => 'Date must be in YYYY-MM-DD format.']); exit;
      }
    }
    $ok = YouthManagement::update(UserContext::getLoggedInUserContext(), $id, ['date_paid_until' => $date]);
    if ($ok) {
      echo json_encode(['ok' => true]); exit;
    }
    echo json_encode(['ok' => false, 'error' => 'Unable to update.']); exit;
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Operation failed']); exit;
  }
}

/** Handle POST (mark medical forms expiration via modal) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'mark_medical_forms_expiration')) {
  require_csrf();
  header('Content-Type: application/json');
  try {
    if (!$canEditPaidUntil) {
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
    $ok = YouthManagement::update(UserContext::getLoggedInUserContext(), $id, [
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

/** Handle POST (mark notified paid via modal) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'mark_notified_paid')) {
  require_csrf();
  header('Content-Type: application/json');
  try {
    if (!$isCubmaster) {
      echo json_encode(['ok' => false, 'error' => 'Not authorized - Cubmaster only']); exit;
    }
    $method = trim((string)($_POST['payment_method'] ?? ''));
    $comment = trim((string)($_POST['comment'] ?? ''));
    if ($method === '') { echo json_encode(['ok' => false, 'error' => 'Payment method is required']); exit; }
    
    $ctx = UserContext::getLoggedInUserContext();
    $notificationId = PaymentNotifications::create($ctx, $id, $method, $comment !== '' ? $comment : null);
    if ($notificationId > 0) {
      echo json_encode(['ok' => true]); exit;
    }
    echo json_encode(['ok' => false, 'error' => 'Unable to create payment notification.']); exit;
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Operation failed: ' . $e->getMessage()]); exit;
  }
}

/** Handle POST (update dietary preferences via modal) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'update_dietary')) {
  require_csrf();
  header('Content-Type: application/json');
  try {
    // Check permissions - admin or parent of this youth
    $canEdit = $isAdmin || $isParentOfThis;
    if (!$canEdit) {
      echo json_encode(['ok' => false, 'error' => 'Not authorized']); exit;
    }
    
    $ctx = UserContext::getLoggedInUserContext();
    $fields = [
      'dietary_vegetarian' => !empty($_POST['dietary_vegetarian']) ? 1 : 0,
      'dietary_vegan' => !empty($_POST['dietary_vegan']) ? 1 : 0,
      'dietary_lactose_free' => !empty($_POST['dietary_lactose_free']) ? 1 : 0,
      'dietary_no_pork_shellfish' => !empty($_POST['dietary_no_pork_shellfish']) ? 1 : 0,
      'dietary_nut_allergy' => !empty($_POST['dietary_nut_allergy']) ? 1 : 0,
      'dietary_gluten_free' => !empty($_POST['dietary_gluten_free']) ? 1 : 0,
      'dietary_other' => trim($_POST['dietary_other'] ?? '') ?: null,
    ];
    
    $ok = YouthManagement::update($ctx, $id, $fields);
    if ($ok) {
      echo json_encode(['ok' => true]); exit;
    }
    echo json_encode(['ok' => false, 'error' => 'Unable to update dietary preferences.']); exit;
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Operation failed: ' . $e->getMessage()]); exit;
  }
}

// Handle POST (update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  // Required
  $first = trim($_POST['first_name'] ?? '');
  $last  = trim($_POST['last_name'] ?? '');
  $suffix = trim($_POST['suffix'] ?? '');
  $gradeLabel = trim($_POST['grade'] ?? '');
  $street1 = trim($_POST['street1'] ?? '');
  $city    = trim($_POST['city'] ?? '');
  $state   = trim($_POST['state'] ?? '');
  $zip     = trim($_POST['zip'] ?? '');

  // Optional
  $preferred = trim($_POST['preferred_name'] ?? '');
  $gender = $_POST['gender'] ?? null;
  $birthdate = trim($_POST['birthdate'] ?? '');
  $school = trim($_POST['school'] ?? '');
  $shirt = trim($_POST['shirt_size'] ?? '');
  $bsa = trim($_POST['bsa_registration_number'] ?? '');
  $street2 = trim($_POST['street2'] ?? '');
  $sibling = !empty($_POST['sibling']) ? 1 : 0;
  $leftTroop = !empty($_POST['left_troop']) ? 1 : 0;
  $includeInMostEmails = !empty($_POST['include_in_most_emails']) ? 1 : 0;

  // Admin/Approver optional fields
  $regExpires = trim($_POST['bsa_registration_expires_date'] ?? '');
  $paidUntil = trim($_POST['date_paid_until'] ?? '');

  // Validate
  $errors = [];
  if ($first === '') $errors[] = 'First name is required.';
  if ($last === '')  $errors[] = 'Last name is required.';
  $g = GradeCalculator::parseGradeLabel($gradeLabel);
  if ($g === null) $errors[] = 'Grade is required.';

  $allowedGender = ['male','female','non-binary','prefer not to say'];
  if ($gender !== null && $gender !== '' && !in_array($gender, $allowedGender, true)) {
    $gender = null;
  }
  if ($birthdate !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $birthdate);
    if (!$d || $d->format('Y-m-d') !== $birthdate) {
      $errors[] = 'Birthdate must be in YYYY-MM-DD format.';
    }
  }
  if ($regExpires !== '' && !$canEditRegExpires) {
    // ignore non-admin submission for safety
    $regExpires = '';
  }
  if ($regExpires !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $regExpires);
    if (!$d || $d->format('Y-m-d') !== $regExpires) {
      $errors[] = 'Registration Expires must be in YYYY-MM-DD format.';
    }
  }
  if ($paidUntil !== '' && !$canEditPaidUntil) {
    // ignore non-approver submission for safety
    $paidUntil = '';
  }
  if ($paidUntil !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $paidUntil);
    if (!$d || $d->format('Y-m-d') !== $paidUntil) {
      $errors[] = 'Paid Until must be in YYYY-MM-DD format.';
    }
  }

  if (empty($errors)) {
    // Recompute class_of from selected grade
    $currentFifthClassOf = GradeCalculator::schoolYearEndYear();
    $class_of = $currentFifthClassOf + (5 - (int)$g);

    try {
      $ctx = UserContext::getLoggedInUserContext();
      $data = [
        'first_name' => $first,
        'last_name' => $last,
        'suffix' => $suffix,
        'preferred_name' => $preferred,
        'gender' => $gender,
        'birthdate' => $birthdate,
        'school' => $school,
        'shirt_size' => $shirt,
        'street1' => $street1,
        'street2' => $street2,
        'city' => $city,
        'state' => $state,
        'zip' => $zip,
        'sibling' => $sibling,
        'left_troop' => $leftTroop,
        'grade_label' => $gradeLabel,
      ];
      if ($isAdmin) {
        $data['bsa_registration_number'] = ($bsa !== '' ? $bsa : null);
        $data['include_in_most_emails'] = $includeInMostEmails;
      }
      // bsa_registration_expires_date is import-only and not editable here
      // date_paid_until is managed via the "Mark Paid for this year" modal action, not via the main form
      $ok = YouthManagement::update($ctx, $id, $data);
      if ($ok) {
        $msg = 'Success editing youth.';
        // Refresh saved data for display on the same page
        try {
          $y = YouthManagement::getForEdit(UserContext::getLoggedInUserContext(), $id);
        } catch (Throwable $e) {
          // ignore; keep current $y if fetch fails
        }
        // Recompute current grade from the saved class_of
        if (isset($y['class_of'])) {
          $currentGrade = GradeCalculator::gradeForClassOf((int)$y['class_of']);
        }
      } else {
        $err = 'Failed to update youth.';
      }
    } catch (Throwable $e) {
      $err = 'Error updating youth.';
    }
  } else {
    $err = implode(' ', $errors);
  }

  // Reload on error
  $y = array_merge($y, [
    'first_name' => $first,
    'last_name' => $last,
    'suffix' => $suffix,
    'preferred_name' => $preferred !== '' ? $preferred : null,
    'gender' => ($gender !== '' ? $gender : null),
    'birthdate' => ($birthdate !== '' ? $birthdate : null),
    'school' => ($school !== '' ? $school : null),
    'shirt_size' => ($shirt !== '' ? $shirt : null),
    'bsa_registration_number' => ($bsa !== '' ? $bsa : null),
    'street1' => $street1,
    'street2' => ($street2 !== '' ? $street2 : null),
    'city' => $city,
    'state' => $state,
    'zip' => $zip,
    'class_of' => $class_of ?? $y['class_of'],
    'sibling' => $sibling,
    'left_troop' => $leftTroop,
    'bsa_registration_expires_date' => ($regExpires !== '' ? $regExpires : ($y['bsa_registration_expires_date'] ?? null)),
    'date_paid_until' => ($paidUntil !== '' ? $paidUntil : ($y['date_paid_until'] ?? null)),
  ]);
  $currentGrade = $g ?? $currentGrade;
}

  // Determine if dues need to be collected (no paid_until, expired, or expiring within next month)
  $paidUntilRaw = trim((string)($y['date_paid_until'] ?? ''));
  $needsPay = true;
  try {
    if ($paidUntilRaw !== '') {
      $today = new DateTime('today');
      $exp = new DateTime($paidUntilRaw . ' 23:59:59');
      $threshold = (clone $today)->modify('+1 month');
      // If expiration is on or after threshold, dues are not needed
      if ($exp >= $threshold) {
        $needsPay = false;
      }
    }
  } catch (Throwable $e) {
    $needsPay = true;
  }

  // Determine if child might need registration (for Upload application button)
  $needsRegistration = false;
  $bsaReg = trim((string)($y['bsa_registration_number'] ?? ''));
  if ($bsaReg === '' && $currentGrade !== null && $currentGrade >= 0 && $currentGrade <= 5) {
    $needsRegistration = true;
  }

  // Check if user can upload application (parent of this child OR approver)
  $canUploadApplication = ($isParentOfThis || $canEditPaidUntil) && $needsRegistration;

  header_html('Edit Youth');
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Edit Youth: <?= h($y['first_name'] ?? '') ?> <?= h($y['last_name'] ?? '') ?></h2>
  <?php if ($canUploadApplication || ($canEditPaidUntil && $needsPay) || $isCubmaster): ?>
    <div class="actions">
      <?php if ($canUploadApplication): ?>
        <button type="button" class="button" id="btn_upload_app">Upload application</button>
      <?php endif; ?>
      <?php if ($canEditPaidUntil && $needsPay): ?>
        <button type="button" class="button" id="btn_mark_paid">Mark Paid for this year</button>
      <?php endif; ?>
      <?php if ($isCubmaster): ?>
        <button type="button" class="button" id="btn_mark_notified_paid">Mark notified paid</button>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php
  if (isset($_GET['uploaded'])) { $msg = 'Photo uploaded.'; }
  if (isset($_GET['deleted'])) { $msg = 'Photo removed.'; }
  if (isset($_GET['err'])) { $err = 'Photo upload failed.'; }
?>
<?php
  if (isset($_GET['paid'])) { $msg = 'Paid Until updated.'; }
  if (isset($_GET['medical_forms'])) { $msg = 'Medical Forms Expiration updated.'; }
  if (isset($_GET['registered'])) { $msg = 'Thank you for sending in your application and payment.'; }
  if (isset($_GET['notified'])) { $msg = 'Payment notification created successfully. Approvers will be notified to verify the payment.'; }
?>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <h3>Profile Photo</h3>
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <?php
      $yName = trim((string)($y['first_name'] ?? '').' '.(string)($y['last_name'] ?? ''));
      $yInitials = strtoupper((string)substr((string)($y['first_name'] ?? ''),0,1).(string)substr((string)($y['last_name'] ?? ''),0,1));
      $yPhotoUrl = Files::profilePhotoUrl($y['photo_public_file_id'] ?? null);
    ?>
    <a href="/youth_edit.php?id=<?= (int)$id ?>" style="text-decoration:none">
      <?php if ($yPhotoUrl !== ''): ?>
        <img class="avatar" src="<?= h($yPhotoUrl) ?>" alt="<?= h($yName) ?>" style="width:80px;height:80px">
      <?php else: ?>
        <div class="avatar avatar-initials" aria-hidden="true" style="width:80px;height:80px;font-size:20px"><?= h($yInitials) ?></div>
      <?php endif; ?>
    </a>

    <form method="post" action="/upload_photo.php?type=youth&youth_id=<?= (int)$id ?>&return_to=<?= h('/youth_edit.php?id='.(int)$id) ?>" enctype="multipart/form-data" class="stack" style="margin-left:auto;min-width:260px">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label>Upload a new photo
        <input type="file" name="photo" accept="image/*" required>
      </label>
      <div class="actions">
        <button class="button">Upload Photo</button>
      </div>
    </form>
    <?php if ($yPhotoUrl !== ''): ?>
      <form method="post" action="/upload_photo.php?type=youth&youth_id=<?= (int)$id ?>&return_to=<?= h('/youth_edit.php?id='.(int)$id) ?>" onsubmit="return confirm('Remove this photo?');" style="margin-left:12px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <button class="button danger">Remove Photo</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>First name
        <input type="text" name="first_name" value="<?=h($y['first_name'])?>" required>
      </label>
      <label>Last name
        <input type="text" name="last_name" value="<?=h($y['last_name'])?>" required>
      </label>
      <label>Suffix
        <input type="text" name="suffix" value="<?=h($y['suffix'])?>" placeholder="Jr, III">
      </label>
      <label>Preferred name
        <input type="text" name="preferred_name" value="<?=h($y['preferred_name'])?>">
      </label>
      <label>Grade
        <select name="grade" required>
          <?php
            // Support Pre-K (K in 3..1 years), K, and Grades 1..12
            $grades = [-3,-2,-1,0,1,2,3,4,5,6,7,8,9,10,11,12];
            foreach ($grades as $i):
              $lbl = \GradeCalculator::gradeLabel($i);
          ?>
            <option value="<?= h($lbl) ?>" <?= ($currentGrade === $i ? 'selected' : '') ?>>
              <?= h($lbl) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small class="small">class_of will be computed from the selected grade.</small>
      </label>
      <label>Gender
        <select name="gender">
          <?php $genders = ['' => '--','male'=>'male','female'=>'female','non-binary'=>'non-binary','prefer not to say'=>'prefer not to say']; ?>
          <?php foreach ($genders as $val=>$label): ?>
            <option value="<?=h($val)?>" <?= ($y['gender'] === ($val === '' ? null : $val) ? 'selected' : '') ?>><?=h($label)?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Birthdate
        <input type="date" name="birthdate" value="<?=h($y['birthdate'])?>" placeholder="YYYY-MM-DD">
      </label>
      <label>School
        <input type="text" name="school" value="<?=h($y['school'])?>">
      </label>
      <label>Shirt size
        <input type="text" name="shirt_size" value="<?=h($y['shirt_size'])?>">
      </label>
      <?php if ($isAdmin): ?>
        <label>BSA Registration #
          <input type="text" name="bsa_registration_number" value="<?=h($y['bsa_registration_number'])?>">
        </label>
      <?php else: ?>
        <div>
          <div class="small">BSA Registration #</div>
          <div><?= h($y['bsa_registration_number'] ?? '—') ?></div>
        </div>
      <?php endif; ?>
      <label class="inline"><input type="checkbox" name="sibling" value="1" <?= !empty($y['sibling']) ? 'checked' : '' ?>> Sibling</label>
      <label class="inline"><input type="checkbox" name="left_troop" value="1" <?= !empty($y['left_troop']) ? 'checked' : '' ?>> Left Troop</label>
      <?php if ($isAdmin): ?>
        <label class="inline"><input type="checkbox" name="include_in_most_emails" value="1" <?= !empty($y['include_in_most_emails']) ? 'checked' : '' ?>> Include in most emails (Active Lead)</label>
      <?php endif; ?>
    </div>

    <h3>Address</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Street 1
        <input type="text" name="street1" value="<?=h($y['street1'])?>">
      </label>
      <label>Street 2
        <input type="text" name="street2" value="<?=h($y['street2'])?>">
      </label>
      <label>City
        <input type="text" name="city" value="<?=h($y['city'])?>">
      </label>
      <label>State
        <input type="text" name="state" value="<?=h($y['state'])?>">
      </label>
      <label>Zip
        <input type="text" name="zip" value="<?=h($y['zip'])?>">
      </label>
    </div>

    <?php if ($canEditRegExpires || $canEditPaidUntil || $isParentOfThis): ?>
      <h3>Registration & Dues</h3>
      <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
        <div>
          <div class="small">BSA Registration Expires</div>
          <div><?= h($y['bsa_registration_expires_date'] ?? '—') ?></div>
        </div>
        <div>
          <div class="small">Paid Until</div>
          <div>
            <?= h($y['date_paid_until'] ?? '—') ?>
            <?php if ($canEditPaidUntil && !empty($y['date_paid_until'])): ?>
              <a href="#" class="small" id="edit_paid_link" data-current="<?= h($y['date_paid_until']) ?>" style="margin-left:6px;">Edit</a>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <div class="small">Medical Forms Expire</div>
          <div>
            <?php
              $medicalExpiration = $y['medical_forms_expiration_date'] ?? null;
              $inPersonOptIn = !empty($y['medical_form_in_person_opt_in']);
              
              if ($inPersonOptIn && ($medicalExpiration === null || $medicalExpiration === '' || (new DateTime($medicalExpiration) < new DateTime()))) {
                echo 'in person opt in';
              } else {
                echo h($medicalExpiration ?? '—');
              }
            ?>
            <?php if ($canEditPaidUntil): ?>
              <a href="#" class="small" id="edit_medical_forms_link" 
                 data-current="<?= h($y['medical_forms_expiration_date'] ?? '') ?>"
                 data-opt-in="<?= !empty($y['medical_form_in_person_opt_in']) ? '1' : '0' ?>" 
                 style="margin-left:6px;">Edit</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <p class="small">Note: “Paid Until” indicates pack dues status and may not align with BSA registration expiration.</p>
    <?php endif; ?>

    <div class="actions">
      <button class="primary" type="submit">Save</button>
      <a class="button" href="/youth.php">Cancel</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>Dietary Preferences</h3>
  <?php
    $dietaryPrefs = [];
    if (!empty($y['dietary_vegetarian'])) $dietaryPrefs[] = 'Vegetarian';
    if (!empty($y['dietary_vegan'])) $dietaryPrefs[] = 'Vegan';
    if (!empty($y['dietary_lactose_free'])) $dietaryPrefs[] = 'Lactose-Free';
    if (!empty($y['dietary_no_pork_shellfish'])) $dietaryPrefs[] = 'No pork or shellfish';
    if (!empty($y['dietary_nut_allergy'])) $dietaryPrefs[] = 'Nut allergy';
    if (!empty($y['dietary_gluten_free'])) $dietaryPrefs[] = 'Gluten Free';
    if (!empty($y['dietary_other'])) $dietaryPrefs[] = trim($y['dietary_other']);
    
    $canEditDietary = $isAdmin || $isParentOfThis;
  ?>
  <p>
    <strong>Dietary Preferences:</strong> 
    <?= !empty($dietaryPrefs) ? h(implode(', ', $dietaryPrefs)) : 'None' ?>
    <?php if ($canEditDietary): ?>
      <a href="#" id="editDietaryBtn" class="small" style="margin-left: 8px;">edit</a>
    <?php endif; ?>
  </p>
</div>

<div class="card" style="margin-top:16px;">
  <h3 style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
    Parents
    <button type="button" class="button" data-open-parent-modal="yp">Add Parent</button>
  </h3>
  <?php
    $parents = YouthManagement::listParents(UserContext::getLoggedInUserContext(), $id);
  ?>
  <?php if (empty($parents)): ?>
    <p class="small">No parents linked to this child.</p>
  <?php else: ?>
    <table class="list">
      <thead>
        <tr>
          <th>Parent</th>
          <th>Email</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($parents as $p): ?>
          <tr>
            <td><?=h($p['first_name'].' '.$p['last_name'])?></td>
            <td><?=h($p['email'])?></td>
            <td class="small">
              <?php if ($isAdmin): ?>
                <a href="/adult_edit.php?id=<?= (int)$p['id'] ?>" class="button">Go</a>
              <?php endif; ?>
              <form method="post" action="/adult_relationships.php" style="display:inline" onsubmit="return confirm('Remove this parent from this child? (At least one parent must remain)');">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="unlink">
                <input type="hidden" name="adult_id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="youth_id" value="<?= (int)$id ?>">
                <input type="hidden" name="return_to" value="<?=h('/youth_edit.php?id='.(int)$id)?>">
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
  // Paid Until Modal (approvers only)
  if ($canEditPaidUntil):
    // Compute default: next Aug 31st at least two months away
    $now = new DateTime('now');
    $threshold = (clone $now)->modify('+2 months');
    $yr = (int)$now->format('Y');
    $candidate = new DateTime($yr . '-08-31');
    if ($candidate < $threshold) {
      $candidate = new DateTime(($yr + 1) . '-08-31');
    }
    $paidDefault = $candidate->format('Y-m-d');
?>
<div id="paid_modal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content" style="max-width:420px;">
    <button class="close" type="button" id="paid_close" aria-label="Close">&times;</button>
    <h3>Mark Paid for this year</h3>
    <div id="paid_err" class="error small" style="display:none;"></div>
    <form id="paid_form" class="stack" method="post" action="/youth_edit.php?id=<?= (int)$id ?>">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="mark_paid_until">
      <label>Paid Until (YYYY-MM-DD)
        <input type="date" name="date_paid_until" id="paid_date" value="<?= h($paidDefault) ?>" required>
      </label>
      <p class="small">Confirm setting this youth's dues "Paid Until" date.</p>
      <div class="actions">
        <button class="button primary" type="submit">Set Paid Until</button>
        <button class="button" type="button" id="paid_cancel">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script>
(function(){
  var openBtn = document.getElementById('btn_mark_paid');
  var modal = document.getElementById('paid_modal');
  var closeBtn = document.getElementById('paid_close');
  var cancelBtn = document.getElementById('paid_cancel');
  var form = document.getElementById('paid_form');
  var err = document.getElementById('paid_err');

  function showErr(msg){ if(err){ err.style.display=''; err.textContent = msg || 'Operation failed.'; } }
  function clearErr(){ if(err){ err.style.display='none'; err.textContent=''; } }
  function open(){ if(modal){ modal.classList.remove('hidden'); modal.setAttribute('aria-hidden','false'); clearErr(); } }
  function close(){ if(modal){ modal.classList.add('hidden'); modal.setAttribute('aria-hidden','true'); } }

  if (openBtn) openBtn.addEventListener('click', function(e){ e.preventDefault(); open(); });
  var editLink = document.getElementById('edit_paid_link');
  if (editLink) {
    editLink.addEventListener('click', function(e){
      e.preventDefault();
      var cur = this.getAttribute('data-current') || '';
      var dateInp = document.getElementById('paid_date');
      if (dateInp && cur) { dateInp.value = cur; }
      open();
    });
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
            window.location = '/youth_edit.php?id=<?= (int)$id ?>&paid=1';
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

<!-- Medical Forms Expiration Modal (approvers only) -->
<?php if ($canEditPaidUntil): 
    // Compute default: exactly one year from today
    $medicalFormsDefault = (new DateTime('now'))->modify('+1 year')->format('Y-m-d');
?>
<div id="medical_forms_modal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content" style="max-width:420px;">
    <button class="close" type="button" id="medical_forms_close" aria-label="Close">&times;</button>
    <h3>Set Medical Forms Expiration</h3>
    <div id="medical_forms_err" class="error small" style="display:none;"></div>
    <form id="medical_forms_form" class="stack" method="post" action="/youth_edit.php?id=<?= (int)$id ?>">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="mark_medical_forms_expiration">
      <label>Medical Forms Expiration Date (YYYY-MM-DD)
        <input type="date" name="medical_forms_expiration_date" id="medical_forms_date" value="<?= h($medicalFormsDefault) ?>" required>
      </label>
      <label class="inline">
        <input type="checkbox" name="medical_form_in_person_opt_in" id="medical_forms_opt_in" value="1">
        Will bring medical forms to events in person
      </label>
      <p class="small">Set when this youth's medical forms expire. If you check "Will bring medical forms to events in person", this person will be considered as having valid medical forms for event purposes regardless of the expiration date.</p>
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
            window.location = '/youth_edit.php?id=<?= (int)$id ?>&medical_forms=1';
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

<!-- Upload Application Modal -->
<?php if ($canUploadApplication): ?>
  <?php
    // Resolve leader names for the modal content
    $cubmasterName = UserManagement::findLeaderNameByPosition('Cubmaster') ?? '';
    $committeeChairName = UserManagement::findLeaderNameByPosition('Committee Chair') ?? '';
    $cubmasterLabel = $cubmasterName !== '' ? $cubmasterName : 'the Cubmaster';
    $committeeChairLabel = $committeeChairName !== '' ? $committeeChairName : 'the Committee Chair';
  ?>
  <div id="uploadAppModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-content" style="max-width:520px;">
      <button class="close" type="button" id="uploadAppClose" aria-label="Close">&times;</button>
      <h3>How to register your child for Cub Scouts</h3>
      <div id="uploadAppErr" class="error small" style="display:none;"></div>
      <ol>
        <li>
          Fill out this "Youth Application Form" and send it to <?= h($cubmasterLabel) ?> or <?= h($committeeChairLabel) ?>.
          <div><a href="https://filestore.scouting.org/filestore/pdf/524-406.pdf" target="_blank" rel="noopener">https://filestore.scouting.org/filestore/pdf/524-406.pdf</a></div>
        </li>
        <li>
          Pay the dues through any payment option here:
          <div><a href="https://www.scarsdalepack440.com/join" target="_blank" rel="noopener">https://www.scarsdalepack440.com/join</a></div>
        </li>
        <li>
          Buy a uniform. Instructions here:
          <div><a href="https://www.scarsdalepack440.com/uniforms" target="_blank" rel="noopener">https://www.scarsdalepack440.com/uniforms</a></div>
        </li>
      </ol>
      <form id="uploadAppForm" class="stack" method="post" action="/pending_registrations_actions.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="youth_id" value="<?= (int)$id ?>">
        <label>Application (PDF or image)
          <input type="file" name="application" accept="application/pdf,image/*">
        </label>
        <label class="inline">
          <input type="checkbox" name="already_sent" value="1"> I have already sent the application another way
        </label>
        <label>Please tell us how you paid <span style="color:red;">*</span>
          <select name="payment_method" required>
            <option value="">Payment method</option>
            <option value="Zelle">Zelle</option>
            <option value="Paypal">Paypal</option>
            <option value="Check">Check</option>
            <option value="I will pay later">I will pay later</option>
          </select>
        </label>
        <label>Optional comment
          <input type="text" name="comment" placeholder="Any notes for leadership">
        </label>
        <div class="actions">
          <button class="button primary" type="submit">Please process my application</button>
          <button class="button" type="button" id="uploadAppCancel">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <script>
  (function(){
    var uploadAppBtn = document.getElementById('btn_upload_app');
    var uploadAppModal = document.getElementById('uploadAppModal');
    var uploadAppClose = document.getElementById('uploadAppClose');
    var uploadAppCancel = document.getElementById('uploadAppCancel');
    var uploadAppForm = document.getElementById('uploadAppForm');
    var uploadAppErr = document.getElementById('uploadAppErr');

    function showUploadAppErr(msg){ if(uploadAppErr){ uploadAppErr.style.display=''; uploadAppErr.textContent = msg || 'Operation failed.'; } }
    function clearUploadAppErr(){ if(uploadAppErr){ uploadAppErr.style.display='none'; uploadAppErr.textContent=''; } }
    function openUploadApp(){
      if (!uploadAppModal) return;
      clearUploadAppErr();
      uploadAppModal.classList.remove('hidden');
      uploadAppModal.setAttribute('aria-hidden','false');
    }
    function hideUploadApp(){
      if (!uploadAppModal) return;
      uploadAppModal.classList.add('hidden');
      uploadAppModal.setAttribute('aria-hidden','true');
    }

    if (uploadAppBtn) uploadAppBtn.addEventListener('click', function(e){ e.preventDefault(); openUploadApp(); });
    if (uploadAppClose) uploadAppClose.addEventListener('click', function(){ hideUploadApp(); });
    if (uploadAppCancel) uploadAppCancel.addEventListener('click', function(){ hideUploadApp(); });
    if (uploadAppModal) uploadAppModal.addEventListener('click', function(e){ if (e.target === uploadAppModal) hideUploadApp(); });

    if (uploadAppForm) {
      uploadAppForm.addEventListener('submit', function(e){
        e.preventDefault();
        clearUploadAppErr();
        
        // Double-click protection
        var submitBtn = uploadAppForm.querySelector('button[type="submit"]');
        if (submitBtn && submitBtn.disabled) return;
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.textContent = 'Processing application...';
        }
        
        var fd = new FormData(uploadAppForm);
        fetch(uploadAppForm.getAttribute('action') || '/pending_registrations_actions.php', { method:'POST', body: fd, credentials:'same-origin' })
          .then(function(res){ return res.json().catch(function(){ throw new Error('Invalid response'); }); })
          .then(function(json){
            if (json && json.ok) {
              // Redirect with success message
              window.location = '/youth_edit.php?id=<?= (int)$id ?>&registered=1';
            } else {
              // Re-enable button on error
              if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Please process my application';
              }
              showUploadAppErr((json && json.error) ? json.error : 'Operation failed.');
            }
          })
          .catch(function(){ 
            // Re-enable button on error
            if (submitBtn) {
              submitBtn.disabled = false;
              submitBtn.textContent = 'Please process my application';
            }
            showUploadAppErr('Network error.'); 
          });
      });
    }
  })();
  </script>
<?php endif; ?>

<!-- Mark Notified Paid Modal (Cubmaster only) -->
<?php if ($isCubmaster): ?>
<div id="notifiedPaidModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content" style="max-width:520px;">
    <button class="close" type="button" id="notifiedPaidClose" aria-label="Close">&times;</button>
    <h3>Mark Notified Paid</h3>
    <div id="notifiedPaidErr" class="error small" style="display:none;"></div>
    <p>Use this when a parent has notified you that they have paid their dues. This will create a payment notification for approvers to verify.</p>
    <form id="notifiedPaidForm" class="stack" method="post" action="/youth_edit.php?id=<?= (int)$id ?>">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="mark_notified_paid">
      <label>Payment Method <span style="color:red;">*</span>
        <select name="payment_method" required>
          <option value="">-- Select --</option>
          <option value="Zelle">Zelle</option>
          <option value="Paypal">Paypal</option>
          <option value="Venmo">Venmo</option>
          <option value="Check">Check</option>
          <option value="Other">Other</option>
        </select>
      </label>
      <label>Comment (optional)
        <input type="text" name="comment" placeholder="Any additional notes about the payment">
      </label>
      <div class="actions">
        <button class="button primary" type="submit">Mark paid</button>
        <button class="button" type="button" id="notifiedPaidCancel">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  var openBtn = document.getElementById('btn_mark_notified_paid');
  var modal = document.getElementById('notifiedPaidModal');
  var closeBtn = document.getElementById('notifiedPaidClose');
  var cancelBtn = document.getElementById('notifiedPaidCancel');
  var form = document.getElementById('notifiedPaidForm');
  var err = document.getElementById('notifiedPaidErr');

  function showErr(msg){ if(err){ err.style.display=''; err.textContent = msg || 'Operation failed.'; } }
  function clearErr(){ if(err){ err.style.display='none'; err.textContent=''; } }
  function open(){
    if (!modal) return;
    clearErr();
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden','false');
  }
  function hide(){
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden','true');
  }

  if (openBtn) openBtn.addEventListener('click', function(e){ e.preventDefault(); open(); });
  if (closeBtn) closeBtn.addEventListener('click', function(){ hide(); });
  if (cancelBtn) cancelBtn.addEventListener('click', function(){ hide(); });
  if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) hide(); });

  if (form) {
    form.addEventListener('submit', function(e){
      e.preventDefault();
      clearErr();
      
      // Double-click protection
      var submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn && submitBtn.disabled) return;
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Processing...';
      }
      
      var fd = new FormData(form);
      fetch(form.getAttribute('action') || window.location.href, { method:'POST', body: fd, credentials:'same-origin' })
        .then(function(res){ return res.json().catch(function(){ throw new Error('Invalid response'); }); })
        .then(function(json){
          if (json && json.ok) {
            window.location = '/youth_edit.php?id=<?= (int)$id ?>&notified=1';
          } else {
            // Re-enable button on error
            if (submitBtn) {
              submitBtn.disabled = false;
              submitBtn.textContent = 'Mark paid';
            }
            showErr((json && json.error) ? json.error : 'Operation failed.');
          }
        })
        .catch(function(){ 
          // Re-enable button on error
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Mark paid';
          }
          showErr('Network error.'); 
        });
    });
  }
})();
</script>
<?php endif; ?>

<!-- Dietary Preferences Modal -->
<?php if ($canEditDietary): ?>
<div id="dietaryModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content" style="max-width:420px;">
    <button class="close" type="button" id="dietaryModalClose" aria-label="Close">&times;</button>
    <h3>Edit Dietary Preferences</h3>
    <div id="dietaryErr" class="error small" style="display:none;"></div>
    <form id="dietaryForm" class="stack" method="post" action="/youth_edit.php?id=<?= (int)$id ?>">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="update_dietary">
      
      <div class="stack">
        <label class="inline">
          <input type="checkbox" name="dietary_vegetarian" value="1" <?= !empty($y['dietary_vegetarian']) ? 'checked' : '' ?>>
          Vegetarian
        </label>
        <label class="inline">
          <input type="checkbox" name="dietary_vegan" value="1" <?= !empty($y['dietary_vegan']) ? 'checked' : '' ?>>
          Vegan
        </label>
        <label class="inline">
          <input type="checkbox" name="dietary_lactose_free" value="1" <?= !empty($y['dietary_lactose_free']) ? 'checked' : '' ?>>
          Lactose-Free
        </label>
        <label class="inline">
          <input type="checkbox" name="dietary_no_pork_shellfish" value="1" <?= !empty($y['dietary_no_pork_shellfish']) ? 'checked' : '' ?>>
          No pork or shellfish
        </label>
        <label class="inline">
          <input type="checkbox" name="dietary_nut_allergy" value="1" <?= !empty($y['dietary_nut_allergy']) ? 'checked' : '' ?>>
          Nut allergy
        </label>
        <label class="inline">
          <input type="checkbox" name="dietary_gluten_free" value="1" <?= !empty($y['dietary_gluten_free']) ? 'checked' : '' ?>>
          Gluten Free
        </label>
        <label>Other dietary needs:
          <input type="text" name="dietary_other" value="<?= h($y['dietary_other'] ?? '') ?>" placeholder="Please specify">
        </label>
      </div>
      
      <div class="actions">
        <button class="button primary" type="submit">Save</button>
        <button class="button" type="button" id="dietaryCancel">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  var editBtn = document.getElementById('editDietaryBtn');
  var modal = document.getElementById('dietaryModal');
  var closeBtn = document.getElementById('dietaryModalClose');
  var cancelBtn = document.getElementById('dietaryCancel');
  var form = document.getElementById('dietaryForm');
  var err = document.getElementById('dietaryErr');

  function showErr(msg){ if(err){ err.style.display=''; err.textContent = msg || 'Operation failed.'; } }
  function clearErr(){ if(err){ err.style.display='none'; err.textContent=''; } }
  function open(){ if(modal){ modal.classList.remove('hidden'); modal.setAttribute('aria-hidden','false'); clearErr(); } }
  function close(){ if(modal){ modal.classList.add('hidden'); modal.setAttribute('aria-hidden','true'); } }

  if (editBtn) editBtn.addEventListener('click', function(e){ e.preventDefault(); open(); });
  if (closeBtn) closeBtn.addEventListener('click', function(){ close(); });
  if (cancelBtn) cancelBtn.addEventListener('click', function(){ close(); });
  if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) close(); });

  if (form) {
    form.addEventListener('submit', function(e){
      e.preventDefault();
      clearErr();
      
      var fd = new FormData(form);
      // Convert form data to update format for YouthManagement
      var updateData = {};
      updateData.dietary_vegetarian = fd.has('dietary_vegetarian') ? 1 : 0;
      updateData.dietary_vegan = fd.has('dietary_vegan') ? 1 : 0;
      updateData.dietary_lactose_free = fd.has('dietary_lactose_free') ? 1 : 0;
      updateData.dietary_no_pork_shellfish = fd.has('dietary_no_pork_shellfish') ? 1 : 0;
      updateData.dietary_nut_allergy = fd.has('dietary_nut_allergy') ? 1 : 0;
      updateData.dietary_gluten_free = fd.has('dietary_gluten_free') ? 1 : 0;
      updateData.dietary_other = fd.get('dietary_other') || '';
      
      // Create new FormData with proper format
      var submitData = new FormData();
      submitData.set('csrf', fd.get('csrf'));
      submitData.set('action', 'update_dietary');
      for (var key in updateData) {
        submitData.set(key, updateData[key]);
      }
      
      fetch('/youth_edit.php?id=<?= (int)$id ?>', { method:'POST', body: submitData, credentials:'same-origin' })
        .then(function(res){ return res.json().catch(function(){ throw new Error('Invalid server response'); }); })
        .then(function(json){
          if (json && json.ok) {
            window.location.reload();
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

<?php
  require_once __DIR__ . '/partials_parent_modal.php';
  render_parent_modal(['mode' => 'edit', 'youth_id' => (int)$id, 'id_prefix' => 'yp']);
?>

<?php footer_html(); ?>
