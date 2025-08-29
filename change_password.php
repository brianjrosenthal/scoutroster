<?php
require_once __DIR__.'/partials.php';
require_login();

require_once __DIR__.'/lib/UserManagement.php';
$pdo = pdo();
$u = current_user();
$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $current = $_POST['current_password'] ?? '';
  $new = $_POST['new_password'] ?? '';
  $confirm = $_POST['confirm_password'] ?? '';

  if ($new !== $confirm) {
    $err = 'New password and confirmation do not match.';
  } elseif (strlen($new) < 8) {
    $err = 'New password must be at least 8 characters.';
  } elseif (!password_verify($current, $u['password_hash'])) {
    $err = 'Current password is incorrect.';
  } else {
    try {
      UserManagement::changePassword((int)$u['id'], $new);
      session_regenerate_id(true);
      $msg = 'Password updated.';
    } catch (Throwable $e) {
      $err = 'Failed to update password.';
    }
  }
}

header_html('Change Password');
?>
<h2>Change Password</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <label>Current password
      <input type="password" name="current_password" required>
    </label>
    <label>New password
      <input type="password" name="new_password" required minlength="8">
    </label>
    <label>Confirm new password
      <input type="password" name="confirm_password" required minlength="8">
    </label>
    <div class="actions">
      <button type="submit" class="primary">Update Password</button>
      <a class="button" href="/index.php">Cancel</a>
    </div>
  </form>
</div>
<?php footer_html(); ?>
