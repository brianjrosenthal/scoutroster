<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/mailer.php';
require_once __DIR__.'/lib/UserManagement.php';

if (current_user()) { header('Location: /index.php'); exit; }

$sent = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $email = strtolower(trim($_POST['email'] ?? ''));
  if ($email !== '') {
    try {
      $u = UserManagement::findByEmail($email);

      // Always behave the same to avoid enumeration
      if ($u) {
        $token = bin2hex(random_bytes(32)); // 64 hex chars
        $hash  = hash('sha256', $token);

        // Set 30-minute validity
        UserManagement::setPasswordResetToken((int)$u['id'], $hash, 30);

        // Build reset URL
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $resetUrl = $scheme.'://'.$host.'/reset_password.php?email='.urlencode($email).'&token='.urlencode($token);

        $html = '<p>You requested to reset your password for '.h(Settings::siteTitle()).'.</p>'
              . '<p>Click the link below to choose a new password. This link will expire in 30 minutes.</p>'
              . '<p><a href="'.h($resetUrl).'">'.h($resetUrl).'</a></p>'
              . '<p>If you did not request this, you can ignore this email.</p>';

        // Best-effort email; do not expose success/failure
        @send_email($email, 'Password reset for '.Settings::siteTitle(), $html, ($u['first_name'].' '.$u['last_name']));
      }

      $sent = true;
    } catch (Throwable $e) {
      // Do not leak details
      $sent = true;
    }
  } else {
    $error = 'Please enter your email.';
  }
}

header_html('Forgot Password');
?>
<h2>Forgot your password?</h2>
<div class="card">
  <?php if ($sent): ?>
    <p class="flash">If that email exists, we sent a password reset link. Please check your inbox.</p>
    <p><a class="button" href="/login.php">Back to login</a></p>
  <?php else: ?>
    <?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <label>Email
        <input type="email" name="email" required>
      </label>
      <div class="actions">
        <button class="primary">Send reset link</button>
        <a class="button" href="/login.php">Cancel</a>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php footer_html(); ?>
