<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_once __DIR__.'/lib/YouthManagement.php';
require_once __DIR__.'/lib/UserManagement.php';
require_admin();

$msg = null;
$err = null;
$canEditPaidUntil = \UserManagement::isApprover((int)(current_user()['id'] ?? 0));

// Handle POST (create)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  // Required fields
  $first = trim($_POST['first_name'] ?? '');
  $last  = trim($_POST['last_name'] ?? '');
  $suffix = trim($_POST['suffix'] ?? '');
  $gradeLabel = trim($_POST['grade'] ?? ''); // K,0..5
  $street1 = trim($_POST['street1'] ?? '');
  $city    = trim($_POST['city'] ?? '');
  $state   = trim($_POST['state'] ?? '');
  $zip     = trim($_POST['zip'] ?? '');

  // Optional fields
  $preferred = trim($_POST['preferred_name'] ?? '');
  $gender = $_POST['gender'] ?? null; // enum or null
  $birthdate = trim($_POST['birthdate'] ?? '');
  $school = trim($_POST['school'] ?? '');
  $shirt = trim($_POST['shirt_size'] ?? '');
  $bsa = trim($_POST['bsa_registration_number'] ?? '');
  $street2 = trim($_POST['street2'] ?? '');
  $sibling = !empty($_POST['sibling']) ? 1 : 0;

  // Registration & Dues (admin/approver controlled)
  $regExpires = trim($_POST['bsa_registration_expires_date'] ?? '');
  $paidUntil  = trim($_POST['date_paid_until'] ?? '');

  // Validate required fields
  $errors = [];
  if ($first === '') $errors[] = 'First name is required.';
  if ($last === '')  $errors[] = 'Last name is required.';
  $g = GradeCalculator::parseGradeLabel($gradeLabel);
  if ($g === null) $errors[] = 'Grade is required.';

  // Normalize/validate enums and dates
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
  if ($regExpires !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $regExpires);
    if (!$d || $d->format('Y-m-d') !== $regExpires) {
      $errors[] = 'Registration Expires must be in YYYY-MM-DD format.';
    }
  }
  if ($paidUntil !== '') {
    if (!$canEditPaidUntil) {
      // ignore for safety; non-approvers cannot set this
      $paidUntil = '';
    } else {
      $d = DateTime::createFromFormat('Y-m-d', $paidUntil);
      if (!$d || $d->format('Y-m-d') !== $paidUntil) {
        $errors[] = 'Paid Until must be in YYYY-MM-DD format.';
      }
    }
  }

  $adultId = (int)($_POST['adult_id'] ?? 0);
$adultId2 = (int)($_POST['adult_id2'] ?? 0);
$relationship = 'parent';
if ($adultId <= 0) { $errors[] = 'Link to Adult is required.'; }
if ($adultId2 === $adultId) { $adultId2 = 0; }

if (empty($errors)) {
    // Compute class_of from grade
    $currentFifthClassOf = GradeCalculator::schoolYearEndYear();
    $class_of = $currentFifthClassOf + (5 - (int)$g);

    try {
      $ctx = UserContext::getLoggedInUserContext();
      $pdo = pdo();
      $pdo->beginTransaction();

      $createData = [
        'first_name' => $first,
        'last_name' => $last,
        'suffix' => $suffix,
        'grade_label' => $gradeLabel,
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
      ];
      if ($regExpires !== '') {
        $createData['bsa_registration_expires_date'] = $regExpires;
      }
      if ($canEditPaidUntil && $paidUntil !== '') {
        $createData['date_paid_until'] = $paidUntil;
      }

      // Create youth
      $id = YouthManagement::create($ctx, $createData);

      // Ensure adult exists
      $stA = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
      $stA->execute([$adultId]);
      if (!$stA->fetchColumn()) {
        throw new RuntimeException('Selected adult not found.');
      }
      if ($adultId2 > 0) {
        $stA->execute([$adultId2]);
        if (!$stA->fetchColumn()) {
          throw new RuntimeException('Selected second adult not found.');
        }
      }

      // Link youth to adult(s)
      $stRel = $pdo->prepare('INSERT INTO parent_relationships (youth_id, adult_id, relationship) VALUES (?, ?, ?)');
      $stRel->execute([$id, $adultId, $relationship]);
      if ($adultId2 > 0) {
        $stRel->execute([$id, $adultId2, $relationship]);
      }

      $pdo->commit();
      header('Location: /youth.php'); exit;
    } catch (Throwable $e) {
      if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
      $err = 'Error creating youth.';
    }
  } else {
    $err = implode(' ', $errors);
  }
}

$selectedGradeLabel = \GradeCalculator::gradeLabel(0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $selectedGradeLabel = trim($_POST['grade'] ?? $selectedGradeLabel);
}
header_html('Add Youth');
?>
<h2>Add Youth</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <h3>Select parent(s)</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Parent
        <select name="adult_id" required>
          <?php
            $allAdults = pdo()->query("SELECT id, first_name, last_name FROM users ORDER BY last_name, first_name")->fetchAll();
            $selAdult = isset($_POST['adult_id']) ? (int)$_POST['adult_id'] : 0;
            foreach ($allAdults as $a) {
              $aid = (int)($a['id'] ?? 0);
              $an = trim((string)($a['last_name'] ?? '') . ', ' . (string)($a['first_name'] ?? ''));
              $sel = ($selAdult === $aid) ? ' selected' : '';
              echo '<option value="'.$aid.'"'.$sel.'">'.h($an).'</option>';
            }
          ?>
        </select>
      </label>
      <label>Parent 2
        <select name="adult_id2">
          <?php
            $selAdult2 = isset($_POST['adult_id2']) ? (int)$_POST['adult_id2'] : 0;
            echo '<option value="0">None</option>';
            foreach ($allAdults as $a) {
              $aid2 = (int)($a['id'] ?? 0);
              $an2 = trim((string)($a['last_name'] ?? '') . ', ' . (string)($a['first_name'] ?? ''));
              $sel2 = ($selAdult2 === $aid2) ? ' selected' : '';
              echo '<option value="'.$aid2.'"'.$sel2.'">'.h($an2).'</option>';
            }
          ?>
        </select>
      </label>
    </div>

    <h3>Child</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>First name
        <input type="text" name="first_name" required>
      </label>
      <label>Last name
        <input type="text" name="last_name" required>
      </label>
      <label>Suffix
        <input type="text" name="suffix" placeholder="Jr, III">
      </label>
      <label>Preferred name
        <input type="text" name="preferred_name">
      </label>
      <label>Grade
        <select name="grade" required>
          <?php
            $grades = [-3,-2,-1,0,1,2,3,4,5,6,7,8,9,10,11,12];
            foreach ($grades as $i):
              $lbl = \GradeCalculator::gradeLabel($i);
          ?>
            <option value="<?= h($lbl) ?>" <?= ($selectedGradeLabel === $lbl ? 'selected' : '') ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>School
        <input type="text" name="school">
      </label>
      <label class="inline"><input type="checkbox" name="sibling" value="1"> Sibling</label>
    </div>




    <div class="actions">
      <button class="primary" type="submit">Create</button>
      <a class="button" href="/youth.php">Cancel</a>
    </div>
  </form>
</div>


<?php footer_html(); ?>
