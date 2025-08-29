<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/mailer.php';
require_once __DIR__.'/lib/UserManagement.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /login.php'); exit; }
require_csrf();

$email = strtolower(trim($_POST['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  header('Location: /login.php?resent=1'); exit;
}

try {
  $u = UserManagement::findByEmail($email);

  if ($u && empty($u['email_verified_at'])) {
    $token = bin2hex(random_bytes(32));
    UserManagement::setEmailVerifyToken((int)$u['id'], $token);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $verifyUrl = $scheme.'://'.$host.'/verify_email.php?token='.urlencode($token);

    $name = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? ''));
    $safeName = htmlspecialchars($name !== '' ? $name : $email, ENT_QUOTES, 'UTF-8');
    $safeUrl  = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');

    $html = '<p>Hello '.$safeName.',</p>'
          . '<p>Click the link below to verify your email and activate your account for '.htmlspecialchars(Settings::siteTitle(), ENT_QUOTES, 'UTF-8').':</p>'
          . '<p><a href="'.$safeUrl.'">'.$safeUrl.'</a></p>'
          . '<p>If you did not request this, you can ignore this email.</p>';

    @send_email($email, 'Confirm your '.Settings::siteTitle().' account', $html, $safeName);
  }
} catch (Throwable $e) {
  // swallow to avoid enumeration
}

header('Location: /login.php?resent=1'); exit;
