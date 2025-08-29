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
  $action = $_POST['action'] ?? '';

  if ($action === 'invite') {
    // Invite an existing adult by id (must have an email and be unverified)
    try {
      $aid = (int)($_POST['adult_id'] ?? 0);
      if ($aid <= 0) throw new Exception('Invalid adult');

      // Load adult
      $st = pdo()->prepare("SELECT id, first_name, last_name, email, email_verified_at FROM users WHERE id=? LIMIT 1");
      $st->execute([$aid]);
      $a = $st->fetch();
      if (!$a || empty($a['email']) || !empty($a['email_verified_at'])) {
        throw new Exception('Adult not eligible for invite.');
      }

      $token = bin2hex(random_bytes(32));
      UserManagement::setEmailVerifyToken((int)$a['id'], $token);

      $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
      $verifyUrl = $scheme.'://'.$host.'/verify_email.php?token='.urlencode($token);

      $safeName = htmlspecialchars(trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')), ENT_QUOTES, 'UTF-8');
      $safeUrl  = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');
      $html = '<p>Hello '.($safeName ?: htmlspecialchars($a['email'], ENT_QUOTES, 'UTF-8')).',</p>'
            . '<p>Please verify your email to activate your account for '.htmlspecialchars(Settings::siteTitle(), ENT_QUOTES, 'UTF-8').'.</p>'
            . '<p><a href="'.$safeUrl.'">'.$safeUrl.'</a></p>'
            . '<p>After verifying, you will be prompted to set your password.</p>';

      @send_email((string)$a['email'], 'Activate your '.Settings::siteTitle().' account', $html, $safeName ?: (string)$a['email']);
      $msg = 'Invitation sent if eligible.';
    } catch (Throwable $e) {
      $err = 'Failed to send invitation.';
    }
  } elseif ($action === 'delete') {
    try {
      $aid = (int)($_POST['adult_id'] ?? 0);
      if ($aid <= 0) throw new Exception('Invalid adult');
      // prevent self-deletion
      $me = current_user();
      if ($aid === (int)($me['id'] ?? 0)) {
        throw new Exception('You cannot delete your own account.');
      }
      $st = pdo()->prepare('DELETE FROM users WHERE id=?');
      $st->execute([$aid]);
      if ($st->rowCount() > 0) {
        $msg = 'Adult deleted.';
      } else {
        $err = 'Adult not found.';
      }
    } catch (Throwable $e) {
      // Likely blocked by FK constraints (RSVPs or other references)
      $err = 'Unable to delete adult. Remove RSVP references first.';
    }
  } else {
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
} // end invite/legacy branch
} // end POST handler

header_html('Manage Adults');
?>
<h2>Manage Adults</h2>
<p><a class="button" href="/admin_adult_add.php">Add Adult</a></p>
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

<div class="card">
  <h3>All Adults</h3>
  <?php $all = pdo()->query("SELECT id, first_name, last_name, email, is_admin, email_verified_at FROM users ORDER BY last_name, first_name")->fetchAll(); ?>
  <?php if (empty($all)): ?>
    <p class="small">No adults found.</p>
  <?php else: ?>
    <table class="list">
      <thead>
        <tr>
          <th>Adult</th>
          <th>Email</th>
          <th>Role</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($all as $a): ?>
          <tr>
            <td><?=h($a['first_name'].' '.$a['last_name'])?></td>
            <td><?=h($a['email'])?></td>
            <td><?= !empty($a['is_admin']) ? 'Admin' : '' ?></td>
            <td class="small">
              <a class="button" href="/adult_edit.php?id=<?= (int)$a['id'] ?>">Edit</a>
              <?php $verified = !empty($a['email_verified_at']); ?>
              <?php if (!empty($a['email']) && !$verified): ?>
                <form method="post" style="display:inline; margin-left:6px;">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="invite">
                  <input type="hidden" name="adult_id" value="<?= (int)$a['id'] ?>">
                  <button class="button">Invite</button>
                </form>
              <?php elseif (empty($a['email'])): ?>
                <span class="small" style="margin-left:6px;">No email</span>
              <?php else: ?>
                <span class="small" style="margin-left:6px;">Verified</span>
              <?php endif; ?>
              <?php if ((int)$a['id'] !== (int)(current_user()['id'] ?? 0)): ?>
                <form method="post" style="display:inline; margin-left:6px;" onsubmit="return confirm('Delete this adult? This cannot be undone.');">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="adult_id" value="<?= (int)$a['id'] ?>">
                  <button class="button danger">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
