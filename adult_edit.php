<?php
require_once __DIR__.'/partials.php';
require_admin();

$err = null;
$msg = null;

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

// Load current record
$st = pdo()->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
$st->execute([$id]);
$u = $st->fetch();
if (!$u) { http_response_code(404); exit('Not found'); }

// Helper to normalize empty to null
$nn = function($v) {
  $v = is_string($v) ? trim($v) : $v;
  return ($v === '' ? null : $v);
};

// Handle POST (update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (!empty($errors)) $err = implode(' ', $errors);
  }

  if (!$err) {
    try {
      $sql = "UPDATE users SET
        first_name=?, last_name=?, email=?, is_admin=?,
        preferred_name=?, street1=?, street2=?, city=?, state=?, zip=?,
        email2=?, phone_home=?, phone_cell=?, shirt_size=?, photo_path=?,
        bsa_membership_number=?, bsa_registration_expires_on=?, safeguarding_training_completed_on=?,
        emergency_contact1_name=?, emergency_contact1_phone=?, emergency_contact2_name=?, emergency_contact2_phone=?
        WHERE id=?";
      $ok = pdo()->prepare($sql)->execute([
        $first, $last, $email, $is_admin,
        $preferred_name, $street1, $street2, $city, $state, $zip,
        $email2, $phone_home, $phone_cell, $shirt_size, $photo_path,
        $bsa_membership_number, $bsa_registration_expires_on, $safeguarding_training_completed_on,
        $em1_name, $em1_phone, $em2_name, $em2_phone,
        $id
      ]);
      if ($ok) {
        header('Location: /adults.php'); exit;
      } else {
        $err = 'Failed to update adult.';
      }
    } catch (Throwable $e) {
      // Likely unique email violation or other DB error
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

header_html('Edit Adult');
?>
<h2>Edit Adult</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" class="stack">
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
        <input type="email" name="email" value="<?=h($u['email'])?>" required>
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
      <button class="primary" type="submit">Save</button>
      <a class="button" href="/adults.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
