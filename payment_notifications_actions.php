<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/PaymentNotifications.php';
require_once __DIR__ . '/lib/YouthManagement.php';
require_once __DIR__ . '/mailer.php';
require_login();

header('Content-Type: application/json');

function respond_json($ok, $error = null, $extra = []) {
  $out = array_merge(['ok' => (bool)$ok], $extra);
  if (!$ok && $error !== null) $out['error'] = (string)$error;
  echo json_encode($out);
  exit;
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond_json(false, 'Method Not Allowed');
  }
  require_csrf();

  $action = $_POST['action'] ?? '';
  $ctx = UserContext::getLoggedInUserContext();

  if ($action === 'create') {
    // Parent reports payment for a youth (creates notification and emails leadership)
    $youthId = (int)($_POST['youth_id'] ?? 0);
    $method  = trim((string)($_POST['payment_method'] ?? ''));
    $comment = trim((string)($_POST['comment'] ?? ''));

    if ($youthId <= 0) respond_json(false, 'Invalid youth');

    try {
      $id = PaymentNotifications::create($ctx, $youthId, $method, $comment !== '' ? $comment : null);

      // Send email to leadership (Cubmaster, Committee Chair, Treasurer) similar to existing flow
      try {
        $recips = PaymentNotifications::leaderRecipients();

        if (!empty($recips)) {
          // Load parent and youth names
          $me = current_user();
          // Ensure youth is real and parent-linked (PaymentNotifications::create already verified)
          $y = YouthManagement::findBasicById((int)$youthId);
          $childName = trim((string)($y['first_name'] ?? '') . ' ' . (string)($y['last_name'] ?? ''));

          $personName = trim((string)($me['first_name'] ?? '') . ' ' . (string)($me['last_name'] ?? ''));

          $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
          $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
          $editUrl = $scheme.'://'.$host.'/youth_edit.php?id='.(int)$youthId;

          $safePerson = htmlspecialchars($personName, ENT_QUOTES, 'UTF-8');
          $safeChild  = htmlspecialchars($childName,  ENT_QUOTES, 'UTF-8');
          $safeUrl    = htmlspecialchars($editUrl,   ENT_QUOTES, 'UTF-8');
          $safeMethod = htmlspecialchars($method,    ENT_QUOTES, 'UTF-8');
          $safeComment = $comment !== '' ? nl2br(htmlspecialchars($comment, ENT_QUOTES, 'UTF-8')) : '';

          $subject = 'Cub Scouts: ' . ($personName ?: 'A parent') . ' paid dues for ' . $childName;

          $html = '<p>This is an automated notification that <strong>'.$safePerson.'</strong> marked that the dues have been paid for <strong>'.$safeChild.'</strong>.</p>'
                . '<p><strong>Payment Method:</strong> '.$safeMethod.'</p>';
          if ($safeComment !== '') {
            $html .= '<p><strong>Comment:</strong><br>'.$safeComment.'</p>';
          }
          $html .= '<p><a href="'.$safeUrl.'">Click here to edit the youth profile</a> and mark the dues as paid until next calendar year.</p>';

          foreach ($recips as $r) {
            $to = (string)($r['email'] ?? '');
            if ($to === '') continue;
            $name = trim((string)($r['first_name'] ?? '').' '.(string)($r['last_name'] ?? ''));
            @send_email($to, $subject, $html, $name ?: $to);
          }
        }
      } catch (Throwable $e) {
        // Email best-effort; do not block success
      }

      respond_json(true, null, ['id' => $id]);
    } catch (Throwable $e) {
      respond_json(false, 'Unable to submit notification.');
    }
  } elseif ($action === 'verify') {
    // Approver marks a notification verified and sets paid-until date on the youth
    if (!\UserManagement::isApprover((int)$ctx->id)) respond_json(false, 'Forbidden');

    $id = (int)($_POST['id'] ?? 0);
    $paid = trim((string)($_POST['date_paid_until'] ?? ''));
    if ($id <= 0) respond_json(false, 'Invalid id');
    if ($paid === '') respond_json(false, 'Date is required');

    $dt = DateTime::createFromFormat('Y-m-d', $paid);
    if (!$dt || $dt->format('Y-m-d') !== $paid) respond_json(false, 'Invalid date format');

    try {
      PaymentNotifications::verify($ctx, $id, $paid);
      respond_json(true);
    } catch (Throwable $e) {
      respond_json(false, 'Unable to verify.');
    }
  } elseif ($action === 'delete') {
    // Approver marks a notification deleted
    if (!\UserManagement::isApprover((int)$ctx->id)) respond_json(false, 'Forbidden');

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) respond_json(false, 'Invalid id');

    try {
      PaymentNotifications::delete($ctx, $id);
      respond_json(true);
    } catch (Throwable $e) {
      respond_json(false, 'Unable to delete.');
    }
  } else {
    respond_json(false, 'Unknown action');
  }
} catch (Throwable $e) {
  respond_json(false, 'Operation failed');
}
