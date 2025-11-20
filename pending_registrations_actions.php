<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/PendingRegistrations.php';
require_once __DIR__ . '/lib/YouthManagement.php';
require_once __DIR__ . '/lib/Files.php';
require_once __DIR__ . '/lib/PaymentNotifications.php'; // reuse leaderRecipients()
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
    // Parent creates a pending registration for a youth
    $youthId = (int)($_POST['youth_id'] ?? 0);
    $alreadySent = isset($_POST['already_sent']) && $_POST['already_sent'] === '1';
    $comment = trim((string)($_POST['comment'] ?? ''));
    $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));

    if ($youthId <= 0) respond_json(false, 'Invalid youth');
    if ($paymentMethod === '') respond_json(false, 'Payment method is required');
    
    // Validate payment method is one of the allowed values
    $allowedMethods = ['Zelle', 'Paypal', 'Check', 'I will pay later'];
    if (!in_array($paymentMethod, $allowedMethods, true)) {
      respond_json(false, 'Invalid payment method');
    }

    // Optional file upload
    $secureFileId = null;
    $hasUpload = isset($_FILES['application']) && is_array($_FILES['application']) && (int)($_FILES['application']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

    if (!$hasUpload && !$alreadySent) {
      respond_json(false, 'Please upload an application file or confirm that you already sent it another way.');
    }

    try {
      if ($hasUpload) {
        $tmp = (string)$_FILES['application']['tmp_name'];
        if (!is_uploaded_file($tmp)) { respond_json(false, 'Upload failed.'); }
        $data = @file_get_contents($tmp);
        if ($data === false || $data === '') { respond_json(false, 'Empty file.'); }
        $ctype = (string)($_FILES['application']['type'] ?? null);
        $oname = (string)($_FILES['application']['name'] ?? null);
        $secureFileId = Files::insertSecureFile($data, $ctype ?: null, $oname ?: null, (int)$ctx->id);
      }

      $id = PendingRegistrations::create($ctx, $youthId, $secureFileId, $comment !== '' ? $comment : null, $paymentMethod);

      // Email leadership (Cubmaster, Committee Chair, Treasurer)
      try {
        $recips = PaymentNotifications::leaderRecipients();
        if (!empty($recips)) {
          $me = current_user();
          $y = YouthManagement::findBasicById((int)$youthId);
          $childName = trim((string)($y['first_name'] ?? '') . ' ' . (string)($y['last_name'] ?? ''));
          $personName = trim((string)($me['first_name'] ?? '') . ' ' . (string)($me['last_name'] ?? ''));

          $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
          $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
          $adminUrl = $scheme.'://'.$host.'/admin/pending_registrations.php';

          $safePerson = htmlspecialchars($personName, ENT_QUOTES, 'UTF-8');
          $safeChild  = htmlspecialchars($childName,  ENT_QUOTES, 'UTF-8');
          $safeUrl    = htmlspecialchars($adminUrl,  ENT_QUOTES, 'UTF-8');
          $safeComment = $comment !== '' ? nl2br(htmlspecialchars($comment, ENT_QUOTES, 'UTF-8')) : '';

          $safePaymentMethod = htmlspecialchars($paymentMethod, ENT_QUOTES, 'UTF-8');
          
          $subject = 'Cub Scouts: Pending registration for ' . $childName;
          $html = '<p><strong>'.$safePerson.'</strong> submitted a pending registration for <strong>'.$safeChild.'</strong>.</p>';
          $html .= '<p><strong>Payment Method:</strong> '.$safePaymentMethod.'</p>';
          if ($safeComment !== '') {
            $html .= '<p><strong>Parent Comment:</strong><br>'.$safeComment.'</p>';
          }
          if ($secureFileId) {
            $html .= '<p>An application file was uploaded and can be downloaded from the admin page.</p>';
          } elseif ($alreadySent) {
            $html .= '<p>Parent indicated they have already sent the application form another way.</p>';
          }
          $html .= '<p><a href="'.$safeUrl.'">Open Pending Registrations</a></p>';

          foreach ($recips as $r) {
            $to = (string)($r['email'] ?? '');
            if ($to === '') continue;
            $name = trim((string)($r['first_name'] ?? '').' '.(string)($r['last_name'] ?? ''));
            @send_email($to, $subject, $html, $name ?: $to);
          }
        }
      } catch (Throwable $e) {
        // Ignore email failures
      }

      respond_json(true, null, ['id' => $id]);
    } catch (Throwable $e) {
      respond_json(false, 'Unable to submit pending registration: ' . $e->getMessage());
    }
  } elseif ($action === 'mark_paid' || $action === 'unmark_paid') {
    if (!\UserManagement::isApprover((int)$ctx->id)) respond_json(false, 'Forbidden');
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) respond_json(false, 'Invalid id');

    try {
      if ($action === 'mark_paid') {
        // Require a paid-until date and update youth record as part of marking paid
        $paidUntil = trim((string)($_POST['date_paid_until'] ?? ''));
        if ($paidUntil === '') respond_json(false, 'Date is required');

        // Validate Y-m-d
        $dt = DateTime::createFromFormat('Y-m-d', $paidUntil);
        if (!$dt || $dt->format('Y-m-d') !== $paidUntil) respond_json(false, 'Invalid date format');

        // Load pending registration to get youth_id
        $row = PendingRegistrations::findById($id);
        if (!$row) respond_json(false, 'Not found');

        $youthId = (int)($row['youth_id'] ?? 0);
        if ($youthId <= 0) respond_json(false, 'Invalid youth');

        // Update youth paid until (approver-only validated above)
        YouthManagement::update($ctx, $youthId, ['date_paid_until' => $paidUntil]);

        // Mark pending registration as paid
        PendingRegistrations::markPaid($ctx, $id, true);
        respond_json(true);
      } else {
        // Unmark paid: clear youth's paid-until and toggle payment_status back to not_paid
        $row = PendingRegistrations::findById($id);
        if (!$row) respond_json(false, 'Not found');

        $youthId = (int)($row['youth_id'] ?? 0);
        if ($youthId <= 0) respond_json(false, 'Invalid youth');

        // Clear youth paid until (approver-only validated above)
        YouthManagement::update($ctx, $youthId, ['date_paid_until' => null]);

        PendingRegistrations::markPaid($ctx, $id, false);
        respond_json(true);
      }
    } catch (Throwable $e) {
      respond_json(false, 'Unable to update payment status.');
    }
  } elseif ($action === 'mark_processed') {
    if (!\UserManagement::isApprover((int)$ctx->id)) respond_json(false, 'Forbidden');
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) respond_json(false, 'Invalid id');
    try {
      PendingRegistrations::markProcessed($ctx, $id);
      respond_json(true);
    } catch (Throwable $e) {
      respond_json(false, 'Unable to mark processed.');
    }
  } elseif ($action === 'delete') {
    if (!\UserManagement::isApprover((int)$ctx->id)) respond_json(false, 'Forbidden');
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) respond_json(false, 'Invalid id');
    try {
      PendingRegistrations::delete($ctx, $id);
      respond_json(true);
    } catch (Throwable $e) {
      respond_json(false, 'Unable to delete.');
    }
  } else {
    respond_json(false, 'Unknown action');
  }
} catch (Throwable $e) {
  respond_json(false, 'Operation failed: ' . $e->getMessage());
}
