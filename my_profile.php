<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/UserManagement.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_login();

$me = current_user();
$isAdmin = !empty($me['is_admin']);

$err = null;
$msg = null;

// Helpers
function nn($v) { $v = is_string($v) ? trim($v) : $v; return ($v === '' ? null : $v); }

// Handle POST actions:
// - update_adult
// - update_child
// - add_child
// - add_parent
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  if ($action === 'update_adult') {
    $first = trim($_POST['first_name'] ?? '');
    $last  = trim($_POST['last_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $preferred_name = nn($_POST['preferred_name'] ?? '');
    $street1 = nn($_POST['street1'] ?? '');
    $street2 = nn($_POST['street2'] ?? '');
    $city    = nn($_POST['city'] ?? '');
    $state   = nn($_POST['state'] ?? '');
    $zip     = nn($_POST['zip'] ?? '');
    $email2  = nn($_POST['email2'] ?? '');
    $phone_home = nn($_POST['phone_home'] ?? '');
    $phone_cell = nn($_POST['phone_cell'] ?? '');
    $shirt_size = nn($_POST['shirt_size'] ?? '');
    $suppress_email_directory = !empty($_POST['suppress_email_directory']) ? 1 : 0;
    $suppress_phone_directory = !empty($_POST['suppress_phone_directory']) ? 1 : 0;

    // Admin-only fields
    $bsa_membership_number = $isAdmin ? nn($_POST['bsa_membership_number'] ?? '') : $me['bsa_membership_number'];
    $bsa_registration_expires_on = $isAdmin ? nn($_POST['bsa_registration_expires_on'] ?? '') : $me['bsa_registration_expires_on'];
    $safeguarding_training_completed_on = $isAdmin ? nn($_POST['safeguarding_training_completed_on'] ?? '') : $me['safeguarding_training_completed_on'];

    $errors = [];
    if ($first === '') $errors[] = 'First name is required.';
    if ($last === '')  $errors[] = 'Last name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';

    // Date validation if admin provided them
    if ($isAdmin) {
      foreach (['bsa_registration_expires_on' => $bsa_registration_expires_on, 'safeguarding_training_completed_on' => $safeguarding_training_completed_on] as $k=>$v) {
        if ($v !== null) {
          $d = DateTime::createFromFormat('Y-m-d', $v);
          if (!$d || $d->format('Y-m-d') !== $v) $errors[] = ucfirst(str_replace('_',' ', $k)).' must be YYYY-MM-DD.';
        }
      }
    }

    if (empty($errors)) {
      try {
        $ok = UserManagement::updateProfile(UserContext::getLoggedInUserContext(), (int)$me['id'], [
          'first_name' => $first,
          'last_name'  => $last,
          'email'      => $email,
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
          // Admin-only fields have been normalized above to retain existing values when not admin
          'bsa_membership_number' => $bsa_membership_number,
          'bsa_registration_expires_on' => $bsa_registration_expires_on,
          'safeguarding_training_completed_on' => $safeguarding_training_completed_on,
        ], false);
        if ($ok) {
          $msg = 'Profile updated.';
          // Refresh $me
          $me = UserManagement::findFullById((int)$me['id']);
        } else {
          $err = 'Failed to update profile.';
        }
      } catch (Throwable $e) {
        $err = 'Error updating profile.';
      }
    } else {
      $err = implode(' ', $errors);
    }
  } elseif ($action === 'add_child') {
    // Add a new youth (sibling flow)
    require_once __DIR__.'/lib/GradeCalculator.php';
    $first = trim($_POST['first_name'] ?? '');
    $last  = trim($_POST['last_name'] ?? '');
    $suffix = nn($_POST['suffix'] ?? '');
    $gradeLabel = trim($_POST['grade'] ?? ''); // K,0..5
    $street1 = trim($_POST['street1'] ?? '');
    $city    = trim($_POST['city'] ?? '');
    $state   = trim($_POST['state'] ?? '');
    $zip     = trim($_POST['zip'] ?? '');

    $preferred = nn($_POST['preferred_name'] ?? '');
    $gender = $_POST['gender'] ?? null;
    $birthdate = nn($_POST['birthdate'] ?? '');
    $school = nn($_POST['school'] ?? '');
    $shirt = nn($_POST['shirt_size'] ?? '');
    $street2 = nn($_POST['street2'] ?? '');

    $errors = [];
    if ($first === '') $errors[] = 'Child first name is required.';
    if ($last === '')  $errors[] = 'Child last name is required.';
    $g = \GradeCalculator::parseGradeLabel($gradeLabel);
    if ($g === null) $errors[] = 'Child grade is required.';

    $allowedGender = ['male','female','non-binary','prefer not to say'];
    if ($gender !== null && $gender !== '' && !in_array($gender, $allowedGender, true)) $gender = null;
    if ($birthdate !== null) {
      $d = DateTime::createFromFormat('Y-m-d', $birthdate);
      if (!$d || $d->format('Y-m-d') !== $birthdate) $errors[] = 'Child birthdate must be YYYY-MM-DD.';
    }

    if (empty($errors)) {
      try {
        $currentFifthClassOf = \GradeCalculator::schoolYearEndYear();
        $class_of = $currentFifthClassOf + (5 - (int)$g);

        $st = pdo()->prepare("INSERT INTO youth
          (first_name,last_name,suffix,preferred_name,gender,birthdate,school,shirt_size,
           street1,street2,city,state,zip,class_of,sibling)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([
          $first, $last, $suffix, $preferred, ($gender !== '' ? $gender : null), $birthdate, $school, $shirt,
          ($street1 !== '' ? $street1 : null), $street2, ($city !== '' ? $city : null), ($state !== '' ? $state : null), ($zip !== '' ? $zip : null), $class_of, 1
        ]);
        $yid = (int)pdo()->lastInsertId();

        // Link parent relationship
        pdo()->prepare("INSERT INTO parent_relationships (youth_id, adult_id, relationship) VALUES (?,?,?)")
            ->execute([$yid, (int)$me['id'], 'guardian']);

        $msg = 'Child added.';
      } catch (Throwable $e) {
        $err = 'Failed to add child.';
      }
    } else {
      $err = implode(' ', $errors);
    }
  } elseif ($action === 'update_child') {
    // Update minimal child fields allowed for non-admin (no scouting fields)
    $yid = (int)($_POST['youth_id'] ?? 0);
    if ($yid > 0) {
      // Ensure this youth belongs to current user
      $st = pdo()->prepare("SELECT y.* FROM youth y JOIN parent_relationships pr ON pr.youth_id=y.id WHERE y.id=? AND pr.adult_id=? LIMIT 1");
      $st->execute([$yid, (int)$me['id']]);
      $y = $st->fetch();
      if ($y) {
        $first = trim($_POST['first_name'] ?? '');
        $last  = trim($_POST['last_name'] ?? '');
        $suffix = nn($_POST['suffix'] ?? '');
        $preferred = nn($_POST['preferred_name'] ?? '');
        $gender = $_POST['gender'] ?? null;
        $birthdate = nn($_POST['birthdate'] ?? '');
        $school = nn($_POST['school'] ?? '');
        $shirt = nn($_POST['shirt_size'] ?? '');
        $street1 = trim($_POST['street1'] ?? '');
        $street2 = nn($_POST['street2'] ?? '');
        $city    = trim($_POST['city'] ?? '');
        $state   = trim($_POST['state'] ?? '');
        $zip     = trim($_POST['zip'] ?? '');

        $errors = [];
        if ($first === '' || $last === '') $errors[] = 'Child first and last name are required.';
        $allowedGender = ['male','female','non-binary','prefer not to say'];
        if ($gender !== null && $gender !== '' && !in_array($gender, $allowedGender, true)) $gender = null;
        if ($birthdate !== null) {
          $d = DateTime::createFromFormat('Y-m-d', $birthdate);
          if (!$d || $d->format('Y-m-d') !== $birthdate) $errors[] = 'Child birthdate must be YYYY-MM-DD.';
        }

        if (empty($errors)) {
          try {
            $st = pdo()->prepare("UPDATE youth SET
              first_name=?, last_name=?, suffix=?, preferred_name=?, gender=?, birthdate=?, school=?, shirt_size=?,
              street1=?, street2=?, city=?, state=?, zip=? WHERE id=?");
            $ok = $st->execute([
              $first, $last, $suffix, $preferred, ($gender !== '' ? $gender : null), $birthdate, $school, $shirt,
              ($street1 !== '' ? $street1 : null), $street2, ($city !== '' ? $city : null), ($state !== '' ? $state : null), ($zip !== '' ? $zip : null), $yid
            ]);
            if ($ok) $msg = 'Child updated.'; else $err = 'Failed to update child.';
          } catch (Throwable $e) {
            $err = 'Error updating child.';
          }
        } else {
          $err = implode(' ', $errors);
        }
      } else {
        $err = 'Not authorized for this child.';
      }
    }
  } elseif ($action === 'add_parent') {
    // Link another parent to a child (existing or invite)
    $yid = (int)($_POST['youth_id'] ?? 0);
    $email = strtolower(trim($_POST['email'] ?? ''));
    $rel = $_POST['relationship'] ?? 'guardian';
    $first = trim($_POST['first_name'] ?? '');
    $last  = trim($_POST['last_name'] ?? '');

    // Ensure this youth belongs to current user
    $st = pdo()->prepare("SELECT y.id FROM youth y JOIN parent_relationships pr ON pr.youth_id=y.id WHERE y.id=? AND pr.adult_id=? LIMIT 1");
    $st->execute([$yid, (int)$me['id']]);
    $y = $st->fetch();
    if (!$y) { $err = 'Not authorized for this child.'; }
    else {
      if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Valid email is required for the other parent.';
      } else {
        try {
          // Find or create adult by email
          $aid = UserManagement::findIdByEmail($email);
          if (!$aid) {
            // Create invited adult (non-admin)
            $token = bin2hex(random_bytes(32));
            if ($first === '' || $last === '') {
              // If no names provided, use placeholders
              $nameParts = explode('@', $email, 2);
              $first = $first !== '' ? $first : ucfirst($nameParts[0]);
              $last = $last !== '' ? $last : 'Parent';
            }
            $aid = \UserManagement::createInvited([
              'first_name' => $first,
              'last_name'  => $last,
              'email'      => $email,
            ], $token);

            // Send activation email
            require_once __DIR__.'/mailer.php';
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $verifyUrl = $scheme.'://'.$host.'/verify_email.php?token='.urlencode($token);
            $safeUrl = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');
            $safeName = htmlspecialchars($first.' '.$last, ENT_QUOTES, 'UTF-8');
            $html = '<p>Hello '.$safeName.',</p>'
                  . '<p>You have been invited to join '.htmlspecialchars(Settings::siteTitle(), ENT_QUOTES, 'UTF-8').'.</p>'
                  . '<p>Click the link to activate your account: <a href="'.$safeUrl.'">'.$safeUrl.'</a></p>';
            @send_email($email, 'Activate your '.Settings::siteTitle().' account', $html, $first.' '.$last);
          }

          // Add relationship if not exists
          pdo()->prepare("INSERT IGNORE INTO parent_relationships (youth_id, adult_id, relationship) VALUES (?,?,?)")
              ->execute([$yid, $aid, in_array($rel, ['father','mother','guardian'], true) ? $rel : 'guardian']);

          $msg = 'Parent linked.';
        } catch (Throwable $e) {
          $err = 'Failed to link parent.';
        }
      }
    }
  }
}

 // Refresh $me after potential update
$me = UserManagement::findFullById((int)$me['id']);

// Load my children
$st = pdo()->prepare("
  SELECT y.*
  FROM parent_relationships pr
  JOIN youth y ON y.id = pr.youth_id
  WHERE pr.adult_id = ?
  ORDER BY y.last_name, y.first_name
");
$st->execute([(int)$me['id']]);
$children = $st->fetchAll();

$addChildSelectedGradeLabel = \GradeCalculator::gradeLabel(0); // Default to Kindergarten
$showAddChild = ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_child' && !empty($err));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_child') {
  $addChildSelectedGradeLabel = trim($_POST['grade'] ?? $addChildSelectedGradeLabel);
}
header_html('My Profile');
?>
<h2>My Profile</h2>
<?php
  // Surface messages from upload_photo redirect
  if (isset($_GET['uploaded'])) { $msg = 'Photo uploaded.'; }
  if (isset($_GET['deleted'])) { $msg = 'Photo removed.'; }
  if (isset($_GET['err'])) { $err = 'Photo upload failed.'; }
?>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <h3>Profile Photo</h3>
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <?php
      $meName = trim((string)($me['first_name'] ?? '').' '.(string)($me['last_name'] ?? ''));
      $meInitials = strtoupper((string)substr((string)($me['first_name'] ?? ''),0,1).(string)substr((string)($me['last_name'] ?? ''),0,1));
      $mePhoto = trim((string)($me['photo_path'] ?? ''));
    ?>
    <?php if ($mePhoto !== ''): ?>
      <img class="avatar" src="<?= h($mePhoto) ?>" alt="<?= h($meName) ?>" style="width:80px;height:80px">
    <?php else: ?>
      <div class="avatar avatar-initials" aria-hidden="true" style="width:80px;height:80px;font-size:20px"><?= h($meInitials) ?></div>
    <?php endif; ?>

    <form method="post" action="/upload_photo.php?type=adult&adult_id=<?= (int)$me['id'] ?>&return_to=/my_profile.php" enctype="multipart/form-data" class="stack" style="margin-left:auto;min-width:260px">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label>Upload a new photo
        <input type="file" name="photo" accept="image/*" required>
      </label>
      <div class="actions">
        <button class="button">Upload Photo</button>
      </div>
    </form>
    <?php if ($mePhoto !== ''): ?>
      <form method="post" action="/upload_photo.php?type=adult&adult_id=<?= (int)$me['id'] ?>&return_to=/my_profile.php" onsubmit="return confirm('Remove this photo?');" style="margin-left:12px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <button class="button danger">Remove Photo</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h3>Adult Information</h3>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="update_adult">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>First name
        <input type="text" name="first_name" value="<?=h($me['first_name'])?>" required>
      </label>
      <label>Last name
        <input type="text" name="last_name" value="<?=h($me['last_name'])?>" required>
      </label>
      <label>Email
        <input type="email" name="email" value="<?=h($me['email'])?>" required>
      </label>
      <label>Preferred name
        <input type="text" name="preferred_name" value="<?=h($me['preferred_name'])?>">
      </label>
      <label>Street 1
        <input type="text" name="street1" value="<?=h($me['street1'])?>">
      </label>
      <label>Street 2
        <input type="text" name="street2" value="<?=h($me['street2'])?>">
      </label>
      <label>City
        <input type="text" name="city" value="<?=h($me['city'])?>">
      </label>
      <label>State
        <input type="text" name="state" value="<?=h($me['state'])?>">
      </label>
      <label>Zip
        <input type="text" name="zip" value="<?=h($me['zip'])?>">
      </label>
      <label>Secondary Email
        <input type="email" name="email2" value="<?=h($me['email2'])?>">
      </label>
      <label>Home Phone
        <input type="text" name="phone_home" value="<?=h($me['phone_home'])?>">
      </label>
      <label>Cell Phone
        <input type="text" name="phone_cell" value="<?=h($me['phone_cell'])?>">
      </label>
      <label>Shirt Size
        <input type="text" name="shirt_size" value="<?=h($me['shirt_size'])?>">
      </label>

      <label class="inline">
        <input type="hidden" name="suppress_email_directory" value="0">
        <input type="checkbox" name="suppress_email_directory" value="1" <?= !empty($me['suppress_email_directory']) ? 'checked' : '' ?>>
        Suppress email from the directory
        <div class="small">This hides your email from non-admin users. Administrators can still see it.</div>
      </label>

      <label class="inline">
        <input type="hidden" name="suppress_phone_directory" value="0">
        <input type="checkbox" name="suppress_phone_directory" value="1" <?= !empty($me['suppress_phone_directory']) ? 'checked' : '' ?>>
        Suppress phone number from the directory
        <div class="small">This hides your phone numbers from non-admin users. Administrators can still see them.</div>
      </label>
    </div>

    <details>
      <summary>Scouting information (admin editable)</summary>
      <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:8px;">
        <label>BSA Membership #
          <input type="text" name="bsa_membership_number" value="<?=h($me['bsa_membership_number'])?>" <?= $isAdmin ? '' : 'disabled' ?>>
        </label>
        <label>BSA Registration Expires On
          <input type="date" name="bsa_registration_expires_on" value="<?=h($me['bsa_registration_expires_on'])?>" <?= $isAdmin ? '' : 'disabled' ?>>
        </label>
        <label>Safeguarding Training Completed On
          <input type="date" name="safeguarding_training_completed_on" value="<?=h($me['safeguarding_training_completed_on'])?>" <?= $isAdmin ? '' : 'disabled' ?>>
        </label>
      </div>
      <?php if (!$isAdmin): ?><small class="small">Contact an admin to update these fields.</small><?php endif; ?>
    </details>

    <div class="actions">
      <button class="primary">Save Profile</button>
      <a class="button" href="/change_password.php">Change Password</a>
    </div>
  </form>
</div>


<div class="card">
  <h3>My Children</h3>

  <?php if (empty($children)): ?>
    <p class="small">No children linked to your account.</p>
  <?php else: ?>
    <?php foreach ($children as $c): ?>
      <details style="margin-bottom:10px;">
        <summary><?=h(trim($c['first_name'].' '.$c['last_name'].(!empty($c['suffix']) ? ' '.$c['suffix'] : '')))?></summary>
        <form method="post" class="stack" style="margin-top:8px;">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="update_child">
          <input type="hidden" name="youth_id" value="<?= (int)$c['id'] ?>">
          <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
            <label>First name
              <input type="text" name="first_name" value="<?=h($c['first_name'])?>" required>
            </label>
            <label>Last name
              <input type="text" name="last_name" value="<?=h($c['last_name'])?>" required>
            </label>
            <label>Suffix
              <input type="text" name="suffix" value="<?=h($c['suffix'])?>" placeholder="Jr, III">
            </label>
            <label>Preferred name
              <input type="text" name="preferred_name" value="<?=h($c['preferred_name'])?>">
            </label>
            <label>Gender
              <?php $genders = ['' => '--','male'=>'male','female'=>'female','non-binary'=>'non-binary','prefer not to say'=>'prefer not to say']; ?>
              <select name="gender">
                <?php foreach ($genders as $val=>$label): ?>
                  <option value="<?=h($val)?>" <?= ($c['gender'] === ($val === '' ? null : $val) ? 'selected' : '') ?>><?=h($label)?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Birthdate
              <input type="date" name="birthdate" value="<?=h($c['birthdate'])?>" placeholder="YYYY-MM-DD">
            </label>
            <label>School
              <input type="text" name="school" value="<?=h($c['school'])?>">
            </label>
            <label>Shirt size
              <input type="text" name="shirt_size" value="<?=h($c['shirt_size'])?>">
            </label>
            <label>Street 1
              <input type="text" name="street1" value="<?=h($c['street1'])?>">
            </label>
            <label>Street 2
              <input type="text" name="street2" value="<?=h($c['street2'])?>">
            </label>
            <label>City
              <input type="text" name="city" value="<?=h($c['city'])?>">
            </label>
            <label>State
              <input type="text" name="state" value="<?=h($c['state'])?>">
            </label>
            <label>Zip
              <input type="text" name="zip" value="<?=h($c['zip'])?>">
            </label>
          </div>
          <div class="actions">
            <button class="primary">Save Child</button>
            <a class="button" href="/youth_edit.php?id=<?= (int)$c['id'] ?>">Open Full Edit (Admin)</a>
          </div>
        </form>

        <div class="card" style="margin-top:8px;">
          <h4>Current Parents/Guardians</h4>
          <?php
            $pps = pdo()->prepare("SELECT u.id,u.first_name,u.last_name,u.email, pr.relationship FROM parent_relationships pr JOIN users u ON u.id=pr.adult_id WHERE pr.youth_id=? ORDER BY u.last_name,u.first_name");
            $pps->execute([(int)$c['id']]);
            $parents = $pps->fetchAll();
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

        <div class="stack" style="margin-top:8px;">
          <form method="post" class="stack" style="border-top:1px solid #eee;padding-top:8px;">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="add_parent">
            <input type="hidden" name="youth_id" value="<?= (int)$c['id'] ?>">
            <h4>Add Another Parent/Guardian</h4>
            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
              <label>Email (existing user or invite)
                <input type="email" name="email" required>
              </label>
              <label>Relationship
                <select name="relationship">
                  <option value="father">father</option>
                  <option value="mother">mother</option>
                  <option value="guardian">guardian</option>
                </select>
              </label>
              <label>First name (if inviting)
                <input type="text" name="first_name">
              </label>
              <label>Last name (if inviting)
                <input type="text" name="last_name">
              </label>
            </div>
            <div class="actions">
              <button class="primary">Add Parent</button>
            </div>
          </form>
        </div>
      </details>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Add Child</h3>
  <div class="actions"><button type="button" class="button" id="addChildToggleBtn">Add Child</button></div>
  <form id="addChildForm" method="post" class="stack" style="<?= $showAddChild ? '' : 'display:none' ?>">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="add_child">
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
            // Order: K, 1..12, then Pre-K (K in 1/2/3 years)
            $order = [0,1,2,3,4,5,6,7,8,9,10,11,12,-1,-2,-3];
            foreach ($order as $gi):
              $lbl = \GradeCalculator::gradeLabel($gi);
          ?>
            <option value="<?= h($lbl) ?>" <?= ($addChildSelectedGradeLabel === $lbl ? 'selected' : '') ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
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
    </div>

    <h4>Address</h4>
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
      <button class="primary">Add Child</button>
    </div>
  </form>
</div>
<script>
  (function(){
    var btn = document.getElementById('addChildToggleBtn');
    var f = document.getElementById('addChildForm');
    if (btn && f) {
      btn.addEventListener('click', function(){
        if (f.style.display === 'none' || f.style.display === '') {
          f.style.display = '';
        } else {
          f.style.display = 'none';
        }
      });
    }
  })();
</script>

<?php footer_html(); ?>
