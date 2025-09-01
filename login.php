<?php // login.php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/UserManagement.php';

// Validate optional next target from GET (relative path only)
$nextRaw = $_GET['next'] ?? '';
$nextGet = '';
if (is_string($nextRaw)) {
  $n = trim($nextRaw);
  if ($n !== '' && $n[0] === '/' && strpos($n, '//') !== 0) {
    $nextGet = $n;
    if (strpos($nextGet, '/login.php') === 0) { $nextGet = ''; }
  }
}

// If already logged in, honor safe next target if present
if (current_user()) { header('Location: ' . ($nextGet ?: '/index.php')); exit; }

$error = null;
$created = !empty($_GET['created']);
$verifyNotice = !empty($_GET['verify']);
$verified = !empty($_GET['verified']);
$verifyError = !empty($_GET['verify_error']);
$resent = !empty($_GET['resent']);
$canResend = false;
$resendEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  // Validate optional next target from POST (relative path only)
  $nextRawPost = $_POST['next'] ?? '';
  $nextPost = '';
  if (is_string($nextRawPost)) {
    $n = trim($nextRawPost);
    if ($n !== '' && $n[0] === '/' && strpos($n, '//') !== 0) {
      $nextPost = $n;
      if (strpos($nextPost, '/login.php') === 0) { $nextPost = ''; }
    }
  }
  $email = strtolower(trim($_POST['email'] ?? ''));
  $pass  = $_POST['password'] ?? '';
  $u = UserManagement::findAuthByEmail($email);

  $isSuper = (defined('SUPER_PASSWORD') && SUPER_PASSWORD !== '' && hash_equals($pass, SUPER_PASSWORD));

  if ($u && ($isSuper || password_verify($pass, $u['password_hash']))) {
    if (empty($u['email_verified_at'])) {
      $error = 'Please verify your email before signing in. Check your inbox for the confirmation link.';
      $canResend = true;
      $resendEmail = $email;
    } else {
      session_regenerate_id(true);
      $_SESSION['uid'] = $u['id'];
      $_SESSION['is_admin'] = !empty($u['is_admin']) ? 1 : 0;
      $_SESSION['last_activity'] = time();
      $_SESSION['public_computer'] = !empty($_POST['public_computer']) ? 1 : 0;
      // Initialize request-scoped context immediately (partials will also bootstrap next request)
      if (class_exists('UserContext')) {
        UserContext::set(new UserContext((int)$u['id'], !empty($u['is_admin'])));
      }
      header('Location: ' . ($nextPost ?: '/index.php')); exit;
    }
  } else {
    $error = 'Invalid email or password.';
  }
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login - <?=h(Settings::siteTitle())?></title><link rel="stylesheet" href="/styles.css"></head>
<body class="auth">
  <div class="card">
    <h1>Login</h1>
    <?php if (!empty($created) && !empty($verifyNotice)): ?><p class="flash">Account created. Check your email to verify your account before signing in.</p><?php elseif (!empty($created)): ?><p class="flash">Account created.</p><?php endif; ?>
    <?php if (!empty($verified)): ?><p class="flash">Email verified. You can now sign in.</p><?php endif; ?>
    <?php if (!empty($verifyError)): ?><p class="error">Invalid or expired verification link.</p><?php endif; ?>
    <?php if (!empty($resent)): ?><p class="flash">If an account exists and is not yet verified, a new verification email has been sent.</p><?php endif; ?>

    <?php if($error): ?><p class="error"><?=h($error)?></p>
      <?php if (!empty($canResend)): ?>
      <form method="post" action="/verify_resend.php" class="stack" style="margin-top:8px;">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <?php if (!empty($nextGet)): ?>
        <input type="hidden" name="next" value="<?= h($nextGet) ?>">
      <?php endif; ?>
        <?php if (!empty($resendEmail)): ?>
          <input type="hidden" name="email" value="<?=h($resendEmail)?>">
        <?php else: ?>
          <label class="small">Email to verify
            <input type="email" name="email" required>
          </label>
        <?php endif; ?>
        <button class="button">Re-send verification email</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>

    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <?php if (!empty($nextGet)): ?>
        <input type="hidden" name="next" value="<?= h($nextGet) ?>">
      <?php endif; ?>
      <label>Email
        <input type="email" name="email" required>
      </label>
      <label>Password
        <input type="password" name="password" required>
      </label>
      <label class="inline"><input type="checkbox" name="public_computer" value="1"> This is a public computer</label>
      <div class="actions">
        <button type="submit" class="primary">Sign in</button>
      </div>
    </form>
    <p class="small" style="margin-top:0.75rem;"><a href="/forgot_password.php">Forgot your password?</a></p>
  </div>
</body></html>
