<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_once __DIR__.'/lib/YouthManagement.php';
require_once __DIR__.'/lib/UserManagement.php';
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

// Handle POST (update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  // Required
  $first = trim($_POST['first_name'] ?? '');
  $last  = trim($_POST['last_name'] ?? '');
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

  if (empty($errors)) {
    // Recompute class_of from selected grade
    $currentFifthClassOf = GradeCalculator::schoolYearEndYear();
    $class_of = $currentFifthClassOf + (5 - (int)$g);

    try {
      $ctx = UserContext::getLoggedInUserContext();
      $ok = YouthManagement::update($ctx, $id, [
        'first_name' => $first,
        'last_name' => $last,
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
      ]);
      if ($ok) {
        header('Location: /youth.php'); exit;
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
  ]);
  $currentGrade = $g ?? $currentGrade;
}

header_html('Edit Youth');
?>
<h2>Edit Youth</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

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
      <label>Preferred name
        <input type="text" name="preferred_name" value="<?=h($y['preferred_name'])?>">
      </label>
      <label>Grade
        <select name="grade" required>
          <?php for($i=0;$i<=5;$i++): $lbl = \GradeCalculator::gradeLabel($i); ?>
            <option value="<?=h($lbl)?>" <?= ($currentGrade === $i ? 'selected' : '') ?>>
              <?= $i === 0 ? 'K' : $i ?>
            </option>
          <?php endfor; ?>
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

    <div class="actions">
      <button class="primary" type="submit">Save</button>
      <a class="button" href="/youth.php">Cancel</a>
    </div>
  </form>
</div>

<div class="card" style="margin-top:16px;">
  <h3>Parents / Guardians</h3>
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
          <th>Relationship</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($parents as $p): ?>
          <tr>
            <td><?=h($p['first_name'].' '.$p['last_name'])?></td>
            <td><?=h($p['email'])?></td>
            <td><?=h($p['relationship'])?></td>
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

<div class="card" style="margin-top:16px;">
  <h3>Link an Existing Adult as Parent/Guardian</h3>
  <form method="post" action="/adult_relationships.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="link">
    <input type="hidden" name="youth_id" value="<?= (int)$id ?>">
    <input type="hidden" name="return_to" value="<?=h('/youth_edit.php?id='.(int)$id)?>">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
      <label>Adult
        <select name="adult_id" required>
          <?php
          $adults = UserManagement::listAllForSelect();
          foreach ($adults as $a) {
            $label = trim(($a['last_name'] ?? '').', '.($a['first_name'] ?? '') . (empty($a['email']) ? '' : ' <'.$a['email'].'>'));
            echo '<option value="'.(int)$a['id'].'">'.$label.'</option>';
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
      <button class="primary">Link Adult</button>
    </div>
  </form>
</div>

<?php footer_html(); ?>
