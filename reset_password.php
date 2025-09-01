<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/UserManagement.php';

if (current_user()) { header('Location: /index.php'); exit; }

$pdo = pdo();
$mode = 'verify'; // verify link, then allow reset form
$error = null;
$success = null;
$email = strtolower(trim($_GET['email'] ?? $_POST['email'] ?? ''));
$token = $_GET['token'] ?? $_POST['token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $new = $_POST['new_password'] ?? '';
  $confirm = $_POST['confirm_password'] ?? '';
  if ($new !== $confirm) {
    $error = 'New password and confirmation do not match.';
  } elseif (strlen($new) < 8) {
    $error = 'New password must be at least 8 characters.';
  } else {
    // Re-validate token on POST
    if ($email === '' || $token === '') {
      $error = 'Invalid reset link.';
    } else {
      $u = UserManagement::getResetStateByEmail($email);
      if (!$u || empty($u['password_reset_token_hash']) || empty($u['password_reset_expires_at'])) {
        $error = 'Invalid or expired reset link.';
      } else {
        $goodTime = (strtotime($u['password_reset_expires_at']) !== false) && (strtotime($u['password_reset_expires_at']) >= time());
        $match = hash_equals($u['password_reset_token_hash'], hash('sha256', $token));
        if (!$goodTime || !$match) {
          $error = 'Invalid or expired reset link.';
        } else {
          try {
            UserManagement::finalizePasswordReset((int)$u['id'], $new);
            // Also verify the email so the account becomes activated via the reset flow
            try {
              UserManagement::markVerifiedNow((int)$u['id']);
            } catch (Throwable $ve) {
              // Non-fatal: if verification update fails, still allow login with new password
            }
            $success = 'Your password has been reset and your email has been verified. You can now sign in.';
            $mode = 'done';
          } catch (Throwable $e) {
            $error = 'Failed to reset password. Please try again.';
          }
        }
      }
    }
  }
} else {
  // GET: validate token, then show form if valid
  if ($email === '' || $token === '') {
    $error = 'Invalid reset link.';
  } else {
    $u = UserManagement::getResetStateByEmail($email);
    if (!$u || empty($u['password_reset_token_hash']) || empty($u['password_reset_expires_at'])) {
      $error = 'Invalid or expired reset link.';
    } else {
      $goodTime = (strtotime($u['password_reset_expires_at']) !== false) && (strtotime($u['password_reset_expires_at']) >= time());
      $match = hash_equals($u['password_reset_token_hash'], hash('sha256', $token));
      if (!$goodTime || !$match) {
        $error = 'Invalid or expired reset link.';
      }
    }
  }
}

header_html('Reset Password');
?>
<h2>Reset Password</h2>
<?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>
<?php if ($success): ?><p class="flash"><?=h($success)?></p><?php endif; ?>

<div class="card">
  <?php if ($mode === 'done'): ?>
    <p><a class="button" href="/login.php">Go to login</a></p>
  <?php elseif (!$error): ?>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="email" value="<?=h($email)?>">
      <input type="hidden" name="token" value="<?=h($token)?>">
      <label>New password
        <input type="password" name="new_password" required minlength="8">
      </label>
      <label>Confirm new password
        <input type="password" name="confirm_password" required minlength="8">
      </label>
      <div class="actions">
        <button class="primary">Set new password</button>
        <a class="button" href="/login.php">Cancel</a>
      </div>
    </form>
  <?php else: ?>
    <p><a class="button" href="/forgot_password.php">Request a new reset link</a></p>
  <?php endif; ?>
</div>
<?php footer_html(); ?>
