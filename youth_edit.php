<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_once __DIR__.'/lib/YouthManagement.php';
require_once __DIR__.'/lib/UserManagement.php';
require_once __DIR__.'/lib/Files.php';
require_login();
$me = current_user();
$isAdmin = !empty($me['is_admin']);

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
  $stChk = pdo()->prepare('SELECT 1 FROM parent_relationships WHERE youth_id=? AND adult_id=? LIMIT 1');
  $stChk->execute([(int)$id, (int)($me['id'] ?? 0)]);
  $isParentOfThis = (bool)$stChk->fetchColumn();
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
    // Basic Y-m-d validation
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
      echo json_encode(['ok' => false, 'error' => 'Date must be in YYYY-MM-DD format.']); exit;
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
        'bsa_registration_number' => $bsa,
        'street1' => $street1,
        'street2' => $street2,
        'city' => $city,
        'state' => $state,
        'zip' => $zip,
        'sibling' => $sibling,
        'grade_label' => $gradeLabel,
      ];
      if ($canEditRegExpires) {
        $data['bsa_registration_expires_date'] = ($regExpires !== '' ? $regExpires : null);
      }
      if ($canEditPaidUntil) {
        $data['date_paid_until'] = ($paidUntil !== '' ? $paidUntil : null);
      }
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
    'bsa_registration_expires_date' => ($regExpires !== '' ? $regExpires : ($y['bsa_registration_expires_date'] ?? null)),
    'date_paid_until' => ($paidUntil !== '' ? $paidUntil : ($y['date_paid_until'] ?? null)),
  ]);
  $currentGrade = $g ?? $currentGrade;
}

header_html('Edit Youth');
?>
<h2>Edit Youth</h2>
<?php
  if (isset($_GET['uploaded'])) { $msg = 'Photo uploaded.'; }
  if (isset($_GET['deleted'])) { $msg = 'Photo removed.'; }
  if (isset($_GET['err'])) { $err = 'Photo upload failed.'; }
?>
<?php
  if (isset($_GET['paid'])) { $msg = 'Paid Until updated.'; }
?>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>
<?php if ($canEditPaidUntil): ?>
<div class="actions" style="margin:8px 0;">
  <button type="button" class="button" id="btn_mark_paid">Mark Paid for this year</button>
</div>
<?php endif; ?>

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
      <label>BSA Registration #
        <input type="text" name="bsa_registration_number" value="<?=h($y['bsa_registration_number'])?>">
      </label>
      <label class="inline"><input type="checkbox" name="sibling" value="1" <?= !empty($y['sibling']) ? 'checked' : '' ?>> Sibling</label>
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
        <?php if ($canEditRegExpires): ?>
          <label>BSA Registration Expires
            <input type="date" name="bsa_registration_expires_date" value="<?= h($y['bsa_registration_expires_date'] ?? '') ?>" placeholder="YYYY-MM-DD">
          </label>
        <?php elseif ($isParentOfThis): ?>
          <div>
            <div class="small">BSA Registration Expires</div>
            <div><?= h($y['bsa_registration_expires_date'] ?? '—') ?></div>
          </div>
        <?php endif; ?>

        <?php if ($canEditPaidUntil): ?>
          <label>Paid Until
            <input type="date" name="date_paid_until" value="<?= h($y['date_paid_until'] ?? '') ?>" placeholder="YYYY-MM-DD">
          </label>
        <?php elseif ($isParentOfThis): ?>
          <div>
            <div class="small">Paid Until</div>
            <div><?= h($y['date_paid_until'] ?? '—') ?></div>
          </div>
        <?php endif; ?>
      </div>
      <p class="small">Note: “Paid Until” indicates pack dues status and may not align with BSA registration expiration.</p>
    <?php endif; ?>

    <div class="actions">
      <button class="primary" type="submit">Save</button>
      <a class="button" href="/youth.php">Cancel</a>
    </div>
  </form>
</div>

<div class="card" style="margin-top:16px;">
  <h3 style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
    Parents / Guardians
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

<?php
  require_once __DIR__ . '/partials_parent_modal.php';
  render_parent_modal(['mode' => 'edit', 'youth_id' => (int)$id, 'id_prefix' => 'yp']);
?>

<?php footer_html(); ?>
