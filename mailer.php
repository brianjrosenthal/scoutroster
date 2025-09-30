<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/EmailLog.php';
require_once __DIR__ . '/lib/UserContext.php';

/**
 * Send an HTML email via SMTP (supports SSL 465 and STARTTLS 587).
 * Returns true on success, false on failure.
 */
function send_smtp_mail(string $toEmail, string $toName, string $subject, string $html): bool {
  // Basic guardrails
  if (!defined('SMTP_HOST') || !defined('SMTP_PORT') || !defined('SMTP_USER') || !defined('SMTP_PASS')) {
    return false;
  }

  $host = SMTP_HOST;
  $port = (int)SMTP_PORT;
  $secure = defined('SMTP_SECURE') ? strtolower(SMTP_SECURE) : 'tls';
  $fromEmail = defined('SMTP_FROM_EMAIL') && SMTP_FROM_EMAIL ? SMTP_FROM_EMAIL : SMTP_USER;
  $fromName  = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : (defined('APP_NAME') ? APP_NAME : 'Cub Scouts');

  $timeout = 20;
  $transport = ($secure === 'ssl') ? "ssl://$host" : $host;
  $fp = @stream_socket_client("$transport:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
  if (!$fp) return false;

  stream_set_timeout($fp, $timeout);

  $expect = function(array $codes) use ($fp): bool {
    $line = '';
    do {
      $line = fgets($fp, 515);
      if ($line === false) return false;
      $code = (int)substr($line, 0, 3);
      $more = isset($line[3]) && $line[3] === '-';
    } while ($more);
    return in_array($code, $codes, true);
  };

  $send = function(string $cmd) use ($fp): bool {
    return fwrite($fp, $cmd . "\r\n") !== false;
  };

  if (!$expect([220])) { fclose($fp); return false; }

  $ehloName = $_SERVER['SERVER_NAME'] ?? 'localhost';
  if (!$send("EHLO " . $ehloName)) { fclose($fp); return false; }
  if (!$expect([250])) { fclose($fp); return false; }

  if ($secure === 'tls') {
    if (!$send("STARTTLS")) { fclose($fp); return false; }
    if (!$expect([220])) { fclose($fp); return false; }
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
    if (!$send("EHLO " . $ehloName)) { fclose($fp); return false; }
    if (!$expect([250])) { fclose($fp); return false; }
  }

  // AUTH LOGIN
  if (!$send("AUTH LOGIN")) { fclose($fp); return false; }
  if (!$expect([334])) { fclose($fp); return false; }
  if (!$send(base64_encode(SMTP_USER))) { fclose($fp); return false; }
  if (!$expect([334])) { fclose($fp); return false; }
  if (!$send(base64_encode(SMTP_PASS))) { fclose($fp); return false; }
  if (!$expect([235])) { fclose($fp); return false; }

  // Envelope
  if (!$send("MAIL FROM:<$fromEmail>")) { fclose($fp); return false; }
  if (!$expect([250])) { fclose($fp); return false; }
  if (!$send("RCPT TO:<$toEmail>")) { fclose($fp); return false; }
  if (!$expect([250,251])) { fclose($fp); return false; }
  if (!$send("DATA")) { fclose($fp); return false; }
  if (!$expect([354])) { fclose($fp); return false; }

  // Headers
  $date = date('r');
  $headers = [];
  $headers[] = "Date: $date";
  $headers[] = "From: ".mb_encode_mimeheader($fromName)." <{$fromEmail}>";
  $headers[] = "To: ".mb_encode_mimeheader($toName)." <{$toEmail}>";
  $headers[] = "Subject: ".mb_encode_mimeheader($subject);
  $headers[] = "MIME-Version: 1.0";
  $headers[] = "Content-Type: text/html; charset=UTF-8";
  $headers[] = "Content-Transfer-Encoding: 8bit";

  // Normalize newlines and dot-stuffing
  $body = preg_replace("/\r\n|\r|\n/", "\r\n", $html);
  $body = preg_replace("/^\./m", "..", $body);

  $data = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
  if (!$send($data)) { fclose($fp); return false; }
  if (!$expect([250])) { fclose($fp); return false; }

  $send("QUIT");
  fclose($fp);
  return true;
}

/**
 * Convenience wrapper. Returns true/false.
 */
function send_email(string $to, string $subject, string $html, string $toName = ''): bool {
  if ($toName === '') $toName = $to;
  
  $success = send_smtp_mail($to, $toName, $subject, $html);
  
  // Log the email attempt
  try {
    $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
    EmailLog::log($ctx, $to, $toName, $subject, $html, $success, $success ? null : 'SMTP send failed');
  } catch (\Throwable $e) {
    // Don't let logging errors break email flow
  }
  
  return $success;
}

/**
 * Debug mode email simulation - simulates email sending with realistic delays and occasional failures.
 * Returns same format as real email functions: array with 'success' and 'error' keys.
 */
function send_email_debug_mode(
  string $toEmail,
  string $subject,
  string $html,
  string $icsContent,
  string $icsFilename = 'invite.ics',
  string $toName = ''
): array {
  if ($toName === '') $toName = $toEmail;
  
  // Simulate realistic email sending delay (2 seconds)
  sleep(2);
  
  // Simulate occasional failures (10% failure rate for testing error handling)
  $success = (rand(1, 10) > 1);
  $error = $success ? null : 'Debug mode: Simulated SMTP timeout';
  
  // Still log the email attempt with debug indicator
  $logBody = $html . "\n\n[ICS Attachment: " . $icsFilename . "] [DEBUG MODE - NOT ACTUALLY SENT]";
  try {
    $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
    EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, $success, $error);
  } catch (\Throwable $e) {
    // Don't let logging errors break email flow
  }
  
  return ['success' => $success, 'error' => $error];
}

/**
 * Enhanced version of send_email_with_ics that returns detailed error information.
 * Returns array with 'success' (bool) and 'error' (string|null) keys.
 */
function send_email_with_ics_detailed(
  string $toEmail,
  string $subject,
  string $html,
  string $icsContent,
  string $icsFilename = 'invite.ics',
  string $toName = ''
): array {
  // Check if debug mode is enabled
  if (defined('EMAIL_DEBUG_MODE') && EMAIL_DEBUG_MODE === true) {
    return send_email_debug_mode($toEmail, $subject, $html, $icsContent, $icsFilename, $toName);
  }
  
  if ($toName === '') $toName = $toEmail;
  
  $logBody = $html . "\n\n[ICS Attachment: " . $icsFilename . "]";

  if (!defined('SMTP_HOST') || !defined('SMTP_PORT') || !defined('SMTP_USER') || !defined('SMTP_PASS')) {
    $error = 'SMTP configuration missing (SMTP_HOST, SMTP_PORT, SMTP_USER, or SMTP_PASS not defined)';
    // Log the failed attempt
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {
      // Don't let logging errors break email flow
    }
    return ['success' => false, 'error' => $error];
  }

  $host = SMTP_HOST;
  $port = (int)SMTP_PORT;
  $secure = defined('SMTP_SECURE') ? strtolower(SMTP_SECURE) : 'tls';
  $fromEmail = defined('SMTP_FROM_EMAIL') && SMTP_FROM_EMAIL ? SMTP_FROM_EMAIL : SMTP_USER;
  $fromName  = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : (defined('APP_NAME') ? APP_NAME : 'Cub Scouts');

  $timeout = 20;
  $transport = ($secure === 'ssl') ? "ssl://$host" : $host;
  $fp = @stream_socket_client("$transport:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
  if (!$fp) {
    $error = "Failed to connect to SMTP server $host:$port - $errstr (errno: $errno)";
    // Log the failed attempt
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {
      // Don't let logging errors break email flow
    }
    return ['success' => false, 'error' => $error];
  }

  stream_set_timeout($fp, $timeout);

  $lastResponse = '';
  $expect = function(array $codes) use ($fp, &$lastResponse): bool {
    $line = '';
    do {
      $line = fgets($fp, 515);
      if ($line === false) {
        $lastResponse = 'Connection lost or timeout';
        return false;
      }
      $lastResponse = trim($line);
      $code = (int)substr($line, 0, 3);
      $more = isset($line[3]) && $line[3] === '-';
    } while ($more);
    return in_array($code, $codes, true);
  };

  $send = function(string $cmd) use ($fp): bool {
    return fwrite($fp, $cmd . "\r\n") !== false;
  };

  if (!$expect([220])) { 
    fclose($fp); 
    $error = "SMTP greeting failed: $lastResponse";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }

  $ehloName = $_SERVER['SERVER_NAME'] ?? 'localhost';
  if (!$send("EHLO " . $ehloName)) { 
    fclose($fp); 
    $error = "Failed to send EHLO command";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }
  if (!$expect([250])) { 
    fclose($fp); 
    $error = "EHLO failed: $lastResponse";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }

  if ($secure === 'tls') {
    if (!$send("STARTTLS")) { 
      fclose($fp); 
      $error = "Failed to send STARTTLS command";
      try {
        $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
        EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
      } catch (\Throwable $e) {}
      return ['success' => false, 'error' => $error];
    }
    if (!$expect([220])) { 
      fclose($fp); 
      $error = "STARTTLS failed: $lastResponse";
      try {
        $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
        EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
      } catch (\Throwable $e) {}
      return ['success' => false, 'error' => $error];
    }
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { 
      fclose($fp); 
      $error = "Failed to enable TLS encryption";
      try {
        $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
        EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
      } catch (\Throwable $e) {}
      return ['success' => false, 'error' => $error];
    }
    if (!$send("EHLO " . $ehloName)) { 
      fclose($fp); 
      $error = "Failed to send EHLO after STARTTLS";
      try {
        $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
        EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
      } catch (\Throwable $e) {}
      return ['success' => false, 'error' => $error];
    }
    if (!$expect([250])) { 
      fclose($fp); 
      $error = "EHLO after STARTTLS failed: $lastResponse";
      try {
        $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
        EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
      } catch (\Throwable $e) {}
      return ['success' => false, 'error' => $error];
    }
  }

  // AUTH LOGIN
  if (!$send("AUTH LOGIN")) { 
    fclose($fp); 
    $error = "Failed to send AUTH LOGIN command";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }
  if (!$expect([334])) { 
    fclose($fp); 
    $error = "AUTH LOGIN failed: $lastResponse";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }
  if (!$send(base64_encode(SMTP_USER))) { 
    fclose($fp); 
    $error = "Failed to send username";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }
  if (!$expect([334])) { 
    fclose($fp); 
    $error = "Username authentication failed: $lastResponse";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }
  if (!$send(base64_encode(SMTP_PASS))) { 
    fclose($fp); 
    $error = "Failed to send password";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }
  if (!$expect([235])) { 
    fclose($fp); 
    $error = "Password authentication failed: $lastResponse";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }

  // Envelope
  if (!$send("MAIL FROM:<$fromEmail>")) { 
    fclose($fp); 
    $error = "Failed to send MAIL FROM command";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }
  if (!$expect([250])) { 
    fclose($fp); 
    $error = "MAIL FROM failed: $lastResponse";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }
  if (!$send("RCPT TO:<$toEmail>")) { 
    fclose($fp); 
    $error = "Failed to send RCPT TO command";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }
  if (!$expect([250,251])) { 
    fclose($fp); 
    $error = "RCPT TO failed: $lastResponse";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }
  if (!$send("DATA")) { 
    fclose($fp); 
    $error = "Failed to send DATA command";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }
  if (!$expect([354])) { 
    fclose($fp); 
    $error = "DATA command failed: $lastResponse";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }

  // Multipart/mixed body with HTML and ICS attachment
  $date = date('r');
  $boundary = '=_mime_' . bin2hex(random_bytes(12));

  $headers = [];
  $headers[] = "Date: $date";
  $headers[] = "From: ".mb_encode_mimeheader($fromName)." <{$fromEmail}>";
  $headers[] = "To: ".mb_encode_mimeheader($toName)." <{$toEmail}>";
  $headers[] = "Subject: ".mb_encode_mimeheader($subject);
  $headers[] = "MIME-Version: 1.0";
  $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";

  // Normalize and dot-stuff for SMTP transmission later
  $htmlBody = preg_replace("/\r\n|\r|\n/", "\r\n", $html);
  $icsData  = preg_replace("/\r\n|\r|\n/", "\r\n", $icsContent);

  $mixedBody  = "--$boundary\r\n";
  $mixedBody .= "Content-Type: text/html; charset=UTF-8\r\n";
  $mixedBody .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
  $mixedBody .= $htmlBody . "\r\n";
  $mixedBody .= "--$boundary\r\n";
  $mixedBody .= "Content-Type: text/calendar; method=REQUEST; name=\"".addslashes($icsFilename)."\"; charset=UTF-8\r\n";
  $mixedBody .= "Content-Transfer-Encoding: 8bit\r\n";
  $mixedBody .= "Content-Disposition: attachment; filename=\"".addslashes($icsFilename)."\"\r\n\r\n";
  $mixedBody .= $icsData . "\r\n";
  $mixedBody .= "--$boundary--\r\n";

  // Dot-stuffing for DATA
  $mixedBody = preg_replace("/^\./m", "..", $mixedBody);

  $data = implode("\r\n", $headers) . "\r\n\r\n" . $mixedBody . "\r\n.";
  if (!$send($data)) { 
    fclose($fp); 
    $error = "Failed to send email data";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }
  if (!$expect([250])) { 
    fclose($fp); 
    $error = "Email data transmission failed: $lastResponse";
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, $error);
    } catch (\Throwable $e) {}
    return ['success' => false, 'error' => $error];
  }

  $send("QUIT");
  fclose($fp);
  
  // Log the successful attempt
  try {
    $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
    EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, true, null);
  } catch (\Throwable $e) {
    // Don't let logging errors break email flow
  }
  
  return ['success' => true, 'error' => null];
}

/**
 * Send a multipart/mixed email with an HTML body and an iCalendar (.ics) attachment.
 * Returns true on success, false on failure.
 */
function send_email_with_ics(
  string $toEmail,
  string $subject,
  string $html,
  string $icsContent,
  string $icsFilename = 'invite.ics',
  string $toName = ''
): bool {
  if ($toName === '') $toName = $toEmail;
  
  $logBody = $html . "\n\n[ICS Attachment: " . $icsFilename . "]";

  if (!defined('SMTP_HOST') || !defined('SMTP_PORT') || !defined('SMTP_USER') || !defined('SMTP_PASS')) {
    // Log the failed attempt
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, 'SMTP configuration missing');
    } catch (\Throwable $e) {
      // Don't let logging errors break email flow
    }
    return false;
  }

  $host = SMTP_HOST;
  $port = (int)SMTP_PORT;
  $secure = defined('SMTP_SECURE') ? strtolower(SMTP_SECURE) : 'tls';
  $fromEmail = defined('SMTP_FROM_EMAIL') && SMTP_FROM_EMAIL ? SMTP_FROM_EMAIL : SMTP_USER;
  $fromName  = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : (defined('APP_NAME') ? APP_NAME : 'Cub Scouts');

  $timeout = 20;
  $transport = ($secure === 'ssl') ? "ssl://$host" : $host;
  $fp = @stream_socket_client("$transport:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
  if (!$fp) {
    // Log the failed attempt
    try {
      $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
      EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, false, 'Failed to connect to SMTP server');
    } catch (\Throwable $e) {
      // Don't let logging errors break email flow
    }
    return false;
  }

  stream_set_timeout($fp, $timeout);

  $expect = function(array $codes) use ($fp): bool {
    $line = '';
    do {
      $line = fgets($fp, 515);
      if ($line === false) return false;
      $code = (int)substr($line, 0, 3);
      $more = isset($line[3]) && $line[3] === '-';
    } while ($more);
    return in_array($code, $codes, true);
  };

  $send = function(string $cmd) use ($fp): bool {
    return fwrite($fp, $cmd . "\r\n") !== false;
  };

  if (!$expect([220])) { fclose($fp); return false; }

  $ehloName = $_SERVER['SERVER_NAME'] ?? 'localhost';
  if (!$send("EHLO " . $ehloName)) { fclose($fp); return false; }
  if (!$expect([250])) { fclose($fp); return false; }

  if ($secure === 'tls') {
    if (!$send("STARTTLS")) { fclose($fp); return false; }
    if (!$expect([220])) { fclose($fp); return false; }
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
    if (!$send("EHLO " . $ehloName)) { fclose($fp); return false; }
    if (!$expect([250])) { fclose($fp); return false; }
  }

  // AUTH LOGIN
  if (!$send("AUTH LOGIN")) { fclose($fp); return false; }
  if (!$expect([334])) { fclose($fp); return false; }
  if (!$send(base64_encode(SMTP_USER))) { fclose($fp); return false; }
  if (!$expect([334])) { fclose($fp); return false; }
  if (!$send(base64_encode(SMTP_PASS))) { fclose($fp); return false; }
  if (!$expect([235])) { fclose($fp); return false; }

  // Envelope
  if (!$send("MAIL FROM:<$fromEmail>")) { fclose($fp); return false; }
  if (!$expect([250])) { fclose($fp); return false; }
  if (!$send("RCPT TO:<$toEmail>")) { fclose($fp); return false; }
  if (!$expect([250,251])) { fclose($fp); return false; }
  if (!$send("DATA")) { fclose($fp); return false; }
  if (!$expect([354])) { fclose($fp); return false; }

  // Multipart/mixed body with HTML and ICS attachment
  $date = date('r');
  $boundary = '=_mime_' . bin2hex(random_bytes(12));

  $headers = [];
  $headers[] = "Date: $date";
  $headers[] = "From: ".mb_encode_mimeheader($fromName)." <{$fromEmail}>";
  $headers[] = "To: ".mb_encode_mimeheader($toName)." <{$toEmail}>";
  $headers[] = "Subject: ".mb_encode_mimeheader($subject);
  $headers[] = "MIME-Version: 1.0";
  $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";

  // Normalize and dot-stuff for SMTP transmission later
  $htmlBody = preg_replace("/\r\n|\r|\n/", "\r\n", $html);
  $icsData  = preg_replace("/\r\n|\r|\n/", "\r\n", $icsContent);

  $mixedBody  = "--$boundary\r\n";
  $mixedBody .= "Content-Type: text/html; charset=UTF-8\r\n";
  $mixedBody .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
  $mixedBody .= $htmlBody . "\r\n";
  $mixedBody .= "--$boundary\r\n";
  $mixedBody .= "Content-Type: text/calendar; method=REQUEST; name=\"".addslashes($icsFilename)."\"; charset=UTF-8\r\n";
  $mixedBody .= "Content-Transfer-Encoding: 8bit\r\n";
  $mixedBody .= "Content-Disposition: attachment; filename=\"".addslashes($icsFilename)."\"\r\n\r\n";
  $mixedBody .= $icsData . "\r\n";
  $mixedBody .= "--$boundary--\r\n";

  // Dot-stuffing for DATA
  $mixedBody = preg_replace("/^\./m", "..", $mixedBody);

  $data = implode("\r\n", $headers) . "\r\n\r\n" . $mixedBody . "\r\n.";
  if (!$send($data)) { fclose($fp); return false; }
  if (!$expect([250])) { fclose($fp); return false; }

  $send("QUIT");
  fclose($fp);
  
  $success = true;
  
  // Log the email attempt
  try {
    $ctx = class_exists('UserContext') ? UserContext::getLoggedInUserContext() : null;
    EmailLog::log($ctx, $toEmail, $toName, $subject, $logBody, $success, null);
  } catch (\Throwable $e) {
    // Don't let logging errors break email flow
  }
  
  return true;
}
