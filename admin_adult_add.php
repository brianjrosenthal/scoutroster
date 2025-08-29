<?php
require_once __DIR__.'/partials.php';
require_admin();

$msg = null;
$err = null;

function nn($v) { $v = is_string($v) ? trim($v) : $v; return ($v === '' ? null : $v); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  // Required
  $first = trim($_POST['first_name'] ?? '');
  $last  = trim($_POST['last_name'] ?? '');
  // Email is optional (nullable schema)
  $email = nn(strtolower(trim($_POST['email'] ?? '')));
  $is_admin = !empty($_POST['is_admin']) ? 1 : 0;

  // Optional personal/contact
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
  $photo_path = nn($_POST['photo_path'] ?? '');

  // Optional scouting (admin-editable)
  $bsa_membership_number = nn($_POST['bsa_membership_number'] ?? '');
  $bsa_registration_expires_on = nn($_POST['bsa_registration_expires_on'] ?? '');
  $safeguarding_training_completed_on = nn($_POST['safeguarding_training_completed_on'] ?? '');

  // Optional emergency
  $em1_name  = nn($_POST['emergency_contact1_name'] ?? '');
  $em1_phone = nn($_POST['emergency_contact1_phone'] ?? '');
  $em2_name  = nn($_POST['emergency_contact2_name'] ?? '');
  $em2_phone = nn($_POST['emergency_contact2_phone'] ?? '');

  $errors = [];
  if ($first === '') $errors[] = 'First name is required.';
  if ($last === '')  $errors[] = 'Last name is required.';
  // If email provided, validate
  if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email is invalid.';
  }
  // Validate dates
  foreach (['bsa_registration_expires_on' => $bsa_registration_expires_on, 'safeguarding_training_completed_on' => $safeguarding_training_completed_on] as $k => $v) {
    if ($v !== null) {
      $d = DateTime::createFromFormat('Y-m-d', $v);
      if (!$d || $d->format('Y-m-d') !== $v) {
        $errors[] = ucfirst(str_replace('_',' ', $k)).' must be YYYY-MM-DD.';
      }
    }
  }

  if (empty($errors)) {
    try {
      // Generate a random unknown password so the account cannot be used until invited and reset
      $rand = bin2hex(random_bytes(18));
      $hash = password_hash($rand, PASSWORD_DEFAULT);

      $sql = "INSERT INTO users
        (first_name, last_name, email, password_hash, is_admin,
         preferred_name, street1, street2, city, state, zip,
         email2, phone_home, phone_cell, shirt_size, photo_path,
         bsa_membership_number, bsa_registration_expires_on, safeguarding_training_completed_on,
         emergency_contact1_name, emergency_contact1_phone, emergency_contact2_name, emergency_contact2_phone,
         email_verify_token, email_verified_at, password_reset_token_hash, password_reset_expires_at)
        VALUES (?,?,?,?,?,
                ?,?,?,?,?,?,
                ?,?,?,?,?,?,
                ?,?,?,?,
                NULL, NULL, NULL, NULL)";
      $ok = pdo()->prepare($sql)->execute([
        $first, $last, $email, $hash, $is_admin,
        $preferred_name, $street1, $street2, $city, $state, $zip,
        $email2, $phone_home, $phone_cell, $shirt_size, $photo_path,
        $bsa_membership_number, $bsa_registration_expires_on, $safeguarding_training_completed_on,
        $em1_name, $em1_phone, $em2_name, $em2_phone
      ]);
      if ($ok) {
        header('Location: /admin_adults.php?created=1'); exit;
      }
      $err = 'Failed to create adult.';
    } catch (Throwable $e) {
      // Likely duplicate email (if not null) or other constraint
      $err = 'Error creating adult. Ensure the email (if provided) is unique.';
    }
  } else {
    $err = implode(' ', $errors);
  }
}

header_html('Add Adult (No Login)');
?>
<h2>Add Adult</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <h3>Basic</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>First name
        <input type="text" name="first_name" required>
      </label>
      <label>Last name
        <input type="text" name="last_name" required>
      </label>
      <label>Email (optional)
        <input type="email" name="email">
      </label>
      <label>Preferred name
        <input type="text" name="preferred_name">
      </label>
      <label class="inline"><input type="checkbox" name="is_admin" value="1"> Admin</label>
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

    <h3>Contact</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Secondary Email
        <input type="email" name="email2">
      </label>
      <label>Home Phone
        <input type="text" name="phone_home">
      </label>
      <label>Cell Phone
        <input type="text" name="phone_cell">
      </label>
      <label>Shirt Size
        <input type="text" name="shirt_size">
      </label>
      <label>Photo Path
        <input type="text" name="photo_path">
      </label>
    </div>

    <h3>Scouting</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>BSA Membership #
        <input type="text" name="bsa_membership_number">
      </label>
      <label>BSA Registration Expires On
        <input type="date" name="bsa_registration_expires_on" placeholder="YYYY-MM-DD">
      </label>
      <label>Safeguarding Training Completed On
        <input type="date" name="safeguarding_training_completed_on" placeholder="YYYY-MM-DD">
      </label>
    </div>

    <h3>Emergency Contacts</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Emergency Contact 1 Name
        <input type="text" name="emergency_contact1_name">
      </label>
      <label>Emergency Contact 1 Phone
        <input type="text" name="emergency_contact1_phone">
      </label>
      <label>Emergency Contact 2 Name
        <input type="text" name="emergency_contact2_name">
      </label>
      <label>Emergency Contact 2 Phone
        <input type="text" name="emergency_contact2_phone">
      </label>
    </div>

    <div class="actions">
      <button class="primary" type="submit">Create</button>
      <a class="button" href="/admin_adults.php">Cancel</a>
    </div>
    <small class="small">Note: This creates an adult record without a login. Use the Invite action later to let them activate their account and set a password.</small>
  </form>
</div>

<?php footer_html(); ?>
