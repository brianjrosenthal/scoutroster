<?php
require_once __DIR__.'/partials.php';
require_admin();

$msg = null;
$err = null;

// Helper: normalize empty string to NULL
function nn($v) { $v = is_string($v) ? trim($v) : $v; return ($v === '' ? null : $v); }

// For repopulating form after errors
$form = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  // Capture raw POST into $form for re-population on error
  $form = $_POST;

  // Required
  $first = trim($_POST['first_name'] ?? '');
  $last  = trim($_POST['last_name'] ?? '');

  // Email is optional (nullable schema); coerce blank to NULL
  $rawEmail = trim($_POST['email'] ?? '');
  $email = ($rawEmail === '') ? null : strtolower($rawEmail);

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
  // If email provided, validate format
  if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email is invalid.';
  }
  // Validate dates (if provided)
  foreach ([
    'bsa_registration_expires_on' => $bsa_registration_expires_on,
    'safeguarding_training_completed_on' => $safeguarding_training_completed_on
  ] as $k => $v) {
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
        VALUES
        (:first_name, :last_name, :email, :password_hash, :is_admin,
         :preferred_name, :street1, :street2, :city, :state, :zip,
         :email2, :phone_home, :phone_cell, :shirt_size, :photo_path,
         :bsa_no, :bsa_exp, :safe_done,
         :em1_name, :em1_phone, :em2_name, :em2_phone,
         NULL, NULL, NULL, NULL)";

      $stmt = pdo()->prepare($sql);

      $stmt->bindValue(':first_name', $first, PDO::PARAM_STR);
      $stmt->bindValue(':last_name',  $last,  PDO::PARAM_STR);
      if ($email === null) $stmt->bindValue(':email', null, PDO::PARAM_NULL);
      else $stmt->bindValue(':email', $email, PDO::PARAM_STR);
      $stmt->bindValue(':password_hash', $hash, PDO::PARAM_STR);
      $stmt->bindValue(':is_admin', $is_admin, PDO::PARAM_INT);

      // Personal/contact
      $stmt->bindValue(':preferred_name', $preferred_name, $preferred_name === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $stmt->bindValue(':street1', $street1, $street1 === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $stmt->bindValue(':street2', $street2, $street2 === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $stmt->bindValue(':city', $city, $city === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $stmt->bindValue(':state', $state, $state === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $stmt->bindValue(':zip', $zip, $zip === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $stmt->bindValue(':email2', $email2, $email2 === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $stmt->bindValue(':phone_home', $phone_home, $phone_home === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $stmt->bindValue(':phone_cell', $phone_cell, $phone_cell === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $stmt->bindValue(':shirt_size', $shirt_size, $shirt_size === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $stmt->bindValue(':photo_path', $photo_path, $photo_path === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

      // Scouting
      $stmt->bindValue(':bsa_no',  $bsa_membership_number, $bsa_membership_number === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $stmt->bindValue(':bsa_exp', $bsa_registration_expires_on, $bsa_registration_expires_on === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $stmt->bindValue(':safe_done', $safeguarding_training_completed_on, $safeguarding_training_completed_on === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

      // Emergency
      $stmt->bindValue(':em1_name',  $em1_name,  $em1_name === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $stmt->bindValue(':em1_phone', $em1_phone, $em1_phone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $stmt->bindValue(':em2_name',  $em2_name,  $em2_name === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
      $stmt->bindValue(':em2_phone', $em2_phone, $em2_phone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

      $ok = $stmt->execute();

      if ($ok) {
        header('Location: /admin_adults.php?created=1'); exit;
      }
      $err = 'Failed to create adult.';
    } catch (Throwable $e) {
      // Likely duplicate email (if not null) or other constraint
      $err = 'Error creating adult. Ensure the email (if provided) is unique';
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
        <input type="text" name="first_name" value="<?=h($form['first_name'] ?? '')?>" required>
      </label>
      <label>Last name
        <input type="text" name="last_name" value="<?=h($form['last_name'] ?? '')?>" required>
      </label>
      <label>Email (optional)
        <input type="email" name="email" value="<?=h($form['email'] ?? '')?>">
      </label>
      <label>Preferred name
        <input type="text" name="preferred_name" value="<?=h($form['preferred_name'] ?? '')?>">
      </label>
      <label class="inline"><input type="checkbox" name="is_admin" value="1" <?= !empty($form['is_admin']) ? 'checked' : '' ?>> Admin</label>
    </div>

    <h3>Address</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Street 1
        <input type="text" name="street1" value="<?=h($form['street1'] ?? '')?>">
      </label>
      <label>Street 2
        <input type="text" name="street2" value="<?=h($form['street2'] ?? '')?>">
      </label>
      <label>City
        <input type="text" name="city" value="<?=h($form['city'] ?? '')?>">
      </label>
      <label>State
        <input type="text" name="state" value="<?=h($form['state'] ?? '')?>">
      </label>
      <label>Zip
        <input type="text" name="zip" value="<?=h($form['zip'] ?? '')?>">
      </label>
    </div>

    <h3>Contact</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Secondary Email
        <input type="email" name="email2" value="<?=h($form['email2'] ?? '')?>">
      </label>
      <label>Home Phone
        <input type="text" name="phone_home" value="<?=h($form['phone_home'] ?? '')?>">
      </label>
      <label>Cell Phone
        <input type="text" name="phone_cell" value="<?=h($form['phone_cell'] ?? '')?>">
      </label>
      <label>Shirt Size
        <input type="text" name="shirt_size" value="<?=h($form['shirt_size'] ?? '')?>">
      </label>
      <label>Photo Path
        <input type="text" name="photo_path" value="<?=h($form['photo_path'] ?? '')?>">
      </label>
    </div>

    <h3>Scouting</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>BSA Membership #
        <input type="text" name="bsa_membership_number" value="<?=h($form['bsa_membership_number'] ?? '')?>">
      </label>
      <label>BSA Registration Expires On
        <input type="date" name="bsa_registration_expires_on" value="<?=h($form['bsa_registration_expires_on'] ?? '')?>" placeholder="YYYY-MM-DD">
      </label>
      <label>Safeguarding Training Completed On
        <input type="date" name="safeguarding_training_completed_on" value="<?=h($form['safeguarding_training_completed_on'] ?? '')?>" placeholder="YYYY-MM-DD">
      </label>
    </div>

    <h3>Emergency Contacts</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Emergency Contact 1 Name
        <input type="text" name="emergency_contact1_name" value="<?=h($form['emergency_contact1_name'] ?? '')?>">
      </label>
      <label>Emergency Contact 1 Phone
        <input type="text" name="emergency_contact1_phone" value="<?=h($form['emergency_contact1_phone'] ?? '')?>">
      </label>
      <label>Emergency Contact 2 Name
        <input type="text" name="emergency_contact2_name" value="<?=h($form['emergency_contact2_name'] ?? '')?>">
      </label>
      <label>Emergency Contact 2 Phone
        <input type="text" name="emergency_contact2_phone" value="<?=h($form['emergency_contact2_phone'] ?? '')?>">
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
