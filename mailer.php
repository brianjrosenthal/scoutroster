<?php
require_once __DIR__ . '/config.php';

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
  return send_smtp_mail($to, $toName, $subject, $html);
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
  return true;
}
