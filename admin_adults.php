<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/mailer.php';
require_once __DIR__.'/lib/UserManagement.php';
require_admin();

$msg = null;
$err = null;

// Handle POST (invite/create adult)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  $first = trim($_POST['first_name'] ?? '');
  $last  = trim($_POST['last_name'] ?? '');
  $email = strtolower(trim($_POST['email'] ?? ''));
  $makeAdmin = !empty($_POST['is_admin']) ? 1 : 0;

  $errors = [];
  if ($first === '') $errors[] = 'First name is required.';
  if ($last === '')  $errors[] = 'Last name is required.';
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';

  if (empty($errors)) {
    try {
      // Create invited user (unverified)
      $token = bin2hex(random_bytes(32));
      $id = UserManagement::createInvited([
        'first_name' => $first,
        'last_name'  => $last,
        'email'      => $email,
      ], $token);

      // If admin checkbox set, elevate role
      if ($makeAdmin) {
        $st = pdo()->prepare("UPDATE users SET is_admin=1 WHERE id=?");
        $st->execute([(int)$id]);
      }

      // Send activation/verification email
      $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
      $verifyUrl = $scheme.'://'.$host.'/verify_email.php?token='.urlencode($token);
      $safeUrl  = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');
      $safeName = htmlspecialchars($first.' '.$last, ENT_QUOTES, 'UTF-8');

      $html = '<p>Hello '.$safeName.',</p>'
            . '<p>You have been invited to activate your account for '.htmlspecialchars(Settings::siteTitle(), ENT_QUOTES, 'UTF-8').'.</p>'
            . '<p>Click the link below to verify your email and set your password:</p>'
            . '<p><a href="'.$safeUrl.'">'.$safeUrl.'</a></p>';

      @send_email($email, 'Activate your '.Settings::siteTitle().' account', $html, $first.' '.$last);

      $msg = 'Adult invited and activation email sent.';
    } catch (Throwable $e) {
      // Possible duplicate email, DB error, etc.
      $err = 'Failed to create/invite adult. Ensure the email is unique.';
    }
  } else {
    $err = implode(' ', $errors);
  }
}

header_html('Add Adult');
?>
<h2>Add Adult</h2>
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
      <label>Email
        <input type="email" name="email" required>
      </label>
      <label class="inline"><input type="checkbox" name="is_admin" value="1"> Make admin</label>
    </div>
    <div class="actions">
      <button class="primary" type="submit">Invite Adult</button>
      <a class="button" href="/adults.php">Back to Adults</a>
    </div>
    <small class="small">An activation email will be sent with a link to verify the address and set a password.</small>
  </form>
</div>

<?php footer_html(); ?>
