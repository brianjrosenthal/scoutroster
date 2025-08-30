<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_once __DIR__.'/lib/YouthManagement.php';
require_admin();

$msg = null;
$err = null;

// Handle POST (create)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  // Required fields
  $first = trim($_POST['first_name'] ?? '');
  $last  = trim($_POST['last_name'] ?? '');
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

  if (empty($errors)) {
    // Compute class_of from grade
    $currentFifthClassOf = GradeCalculator::schoolYearEndYear();
    $class_of = $currentFifthClassOf + (5 - (int)$g);

    try {
      $ctx = UserContext::getLoggedInUserContext();
      $id = YouthManagement::create($ctx, [
        'first_name' => $first,
        'last_name' => $last,
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
      ]);
      header('Location: /youth.php'); exit;
    } catch (Throwable $e) {
      $err = 'Error creating youth.';
    }
  } else {
    $err = implode(' ', $errors);
  }
}

header_html('Add Youth');
?>
<h2>Add Youth</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>First name
        <input type="text" name="first_name" required>
      </label>
      <label>Last name
        <input type="text" name="last_name" required>
      </label>
      <label>Preferred name
        <input type="text" name="preferred_name">
      </label>
      <label>Grade
        <select name="grade" required>
          <?php for($i=0;$i<=5;$i++): $lbl = \GradeCalculator::gradeLabel($i); ?>
            <option value="<?=h($lbl)?>"><?= $i === 0 ? 'K' : $i ?></option>
          <?php endfor; ?>
        </select>
        <small class="small">class_of will be computed from the grade based on the current school year.</small>
      </label>
      <label>Gender
        <select name="gender">
          <option value="">--</option>
          <option value="male">male</option>
          <option value="female">female</option>
          <option value="non-binary">non-binary</option>
          <option value="prefer not to say">prefer not to say</option>
        </select>
      </label>
      <label>Birthdate
        <input type="date" name="birthdate" placeholder="YYYY-MM-DD">
      </label>
      <label>School
        <input type="text" name="school">
      </label>
      <label>Shirt size
        <input type="text" name="shirt_size">
      </label>
      <label>BSA Registration #
        <input type="text" name="bsa_registration_number">
      </label>
      <label class="inline"><input type="checkbox" name="sibling" value="1"> Sibling</label>
    </div>

    <h3>Address</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Street 1
        <input type="text" name="street1">
      </label>
      <label>Street 2
        <input type="text" name="street2">
      </label>
      <label>City
        <input type="text" name="city">
      </label>
      <label>State
        <input type="text" name="state">
      </label>
      <label>Zip
        <input type="text" name="zip">
      </label>
    </div>

    <div class="actions">
      <button class="primary" type="submit">Create</button>
      <a class="button" href="/youth.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
