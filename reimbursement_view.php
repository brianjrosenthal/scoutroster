<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/Reimbursements.php';
require_once __DIR__ . '/lib/Files.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_login();

$ctx = UserContext::getLoggedInUserContext();
$me = current_user();

$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

$err = null;
$msg = null;

// Fetch request with permission check (for GET display as well)
try {
  $req = Reimbursements::getWithAuth($ctx, $id);
} catch (Throwable $e) {
  http_response_code(403);
  exit('Forbidden');
}
$isApprover = Reimbursements::isApprover($ctx);
$isOwner = ((int)$req['created_by'] === (int)($me['id'] ?? 0));

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $action = $_POST['action'] ?? '';

  try {
    if ($action === 'comment') {
      $text = (string)($_POST['comment_text'] ?? '');
      Reimbursements::addComment($ctx, $id, $text);
      $msg = 'Comment added.';
    } elseif ($action === 'status') {
      $newStatus = (string)($_POST['new_status'] ?? '');
      $comment = (string)($_POST['comment_text'] ?? '');
      Reimbursements::changeStatus($ctx, $id, $newStatus, $comment);
      $msg = 'Status updated.';
      // Refresh req after change
      $req = Reimbursements::getWithAuth($ctx, $id);
    } elseif ($action === 'upload_file') {
      if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        throw new InvalidArgumentException('No file uploaded.');
      }
      $f = $_FILES['file'];
      if (!empty($f['error'])) {
        throw new RuntimeException('Upload failed.');
      }
      $tmp = $f['tmp_name'] ?? '';
      $name = $f['name'] ?? 'file';
      $desc = trim((string)($_POST['file_description'] ?? '')) ?: null;
      if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid upload.');
      }
      // Allow images and PDFs
      $allowedExt = ['pdf','jpg','jpeg','png','heic','webp'];
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('Unsupported file type. Allowed: pdf, jpg, jpeg, png, heic, webp.');
      }
      // Load bytes and detect mime best-effort
      $data = @file_get_contents($tmp);
      if ($data === false) {
        throw new RuntimeException('Failed to read uploaded file.');
      }
      $mime = 'application/octet-stream';
      if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
          $mt = finfo_file($finfo, $tmp);
          if (is_string($mt) && $mt !== '') $mime = $mt;
          finfo_close($finfo);
        }
      }
      // Insert into secure_files and link to reimbursement_request_files
      try {
        $secureId = Files::insertSecureFile($data, $mime, $name, (int)($me['id'] ?? 0));
        Reimbursements::recordSecureFile($ctx, $id, (int)$secureId, $name, $desc);
      } catch (Throwable $e) {
        throw new RuntimeException('Failed to store uploaded file.');
      }
      $msg = 'File uploaded.';
    } elseif ($action === 'update_payment') {
      // Only creator can update; Reimbursements will enforce
      $pd = (string)($_POST['payment_details'] ?? '');
      Reimbursements::updatePaymentDetails($ctx, $id, $pd);
      $msg = 'Payment details updated.';
      // Refresh req after change
      $req = Reimbursements::getWithAuth($ctx, $id);
    } elseif ($action === 'update_amount') {
      // Only creator and only when status is "more_info_requested"; enforced in lib
      $amount = (string)($_POST['amount'] ?? '');
      Reimbursements::updateAmount($ctx, $id, $amount);
      $msg = 'Amount updated.';
      // Refresh req after change
      $req = Reimbursements::getWithAuth($ctx, $id);
    } elseif ($action === 'update_event') {
      $ev = (string)($_POST['event_id'] ?? '');
      $eventId = ($ev !== '') ? (int)$ev : null;
      Reimbursements::updateEventId($ctx, $id, $eventId);
      $msg = 'Event updated.';
      // Refresh req after change
      $req = Reimbursements::getWithAuth($ctx, $id);
    } elseif ($action === 'update_payment_method') {
      $pm = (string)($_POST['payment_method'] ?? '');
      Reimbursements::updatePaymentMethod($ctx, $id, $pm !== '' ? $pm : null);
      $msg = 'Payment method updated.';
      $req = Reimbursements::getWithAuth($ctx, $id);
    } elseif ($action === 'send_donation_letter') {
      $body = (string)($_POST['donation_letter_body'] ?? '');
      Reimbursements::sendDonationLetter($ctx, $id, $body);
      $msg = 'Donation letter sent.';
      $req = Reimbursements::getWithAuth($ctx, $id);
    }
  } catch (Throwable $e) {
    $err = $e->getMessage() ?: 'Operation failed.';
  }
}

// Load associated data for display
$files = [];
$comments = [];
try {
  $files = Reimbursements::fetchFiles($ctx, $id);
  $comments = Reimbursements::fetchComments($ctx, $id);
} catch (Throwable $e) {
  $files = [];
  $comments = [];
  if (!$err) $err = 'Unable to load related data.';
}

$allowed = Reimbursements::allowedTransitionsFor($ctx, $req);
$canUpload = Reimbursements::canUploadFiles($ctx, $req);

header_html('Reimbursement Details');
?>
<h2>Reimbursement: <?= h($req['title']) ?></h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <h3>Details</h3>
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
    <div>
      <strong>Created by:</strong>
      <?php
        require_once __DIR__ . '/lib/UserManagement.php';
        $creatorId = (int)($req['created_by'] ?? 0);
        $entererId = (int)($req['entered_by'] ?? 0);
        $creatorName = \UserManagement::getFullName($creatorId) ?? '';
        $enteredName = \UserManagement::getFullName($entererId) ?? '';
        $createdAt = (string)($req['created_at'] ?? '');
        $createdText = '—';
        if ($creatorName !== '' || $createdAt !== '') {
          $createdText = trim($creatorName) !== '' ? "{$creatorName} at {$createdAt}" : "at {$createdAt}";
        }
        if ($entererId > 0 && $entererId !== $creatorId && $enteredName !== '') {
          $createdText .= ", entered by {$enteredName}";
        }
        echo h($createdText);
      ?>
    </div>

    <div>
      <strong>Status:</strong>
      <?= h($req['status']) ?>
      <?php if (!empty($allowed)): ?> — <a href="#" id="linkEditStatus">edit</a><?php endif; ?>
      <?php if (!empty($req['comment_from_last_status_change'])): ?>
        <br><span class="small">"<?= nl2br(h($req['comment_from_last_status_change'])) ?>"</span>
      <?php endif; ?>
    </div>

    <div>
      <strong>Event:</strong>
      <?php
        $eid = (int)($req['event_id'] ?? 0);
        $ev = null;
        if ($eid > 0) {
          $ev = \EventManagement::findBasicById($eid);
        }
        if ($ev) {
          $dt = $ev['starts_at'] ?? null;
          $label = ($dt ? date('Y-m-d', strtotime($dt)).' ' : '') . ($ev['name'] ?? '');
          echo h($label);
          echo ' — <a href="/event.php?id='.(int)$ev['id'].'">view</a>';
        } else {
          echo '—';
        }
      ?>
      <?php if ($isOwner || $isApprover): ?> — <a href="#" id="linkChangeEvent">change</a><?php endif; ?>
    </div>

    <div>
      <strong>Amount:</strong>
      <?php
        $amt = $req['amount'] ?? null;
        $amtDisplay = ($amt !== null && $amt !== '') ? ('$' . number_format((float)$amt, 2)) : '—';
      ?>
      <?= h($amtDisplay) ?>
      <?php if ($isOwner && in_array((string)$req['status'], ['submitted','resubmitted','more_info_requested'], true)): ?>
        — <a href="#" id="linkEditAmount">edit</a>
      <?php endif; ?>
    </div>

    <?php if (!empty($req['description'])): ?>
      <div>
        <strong>Description</strong>
        <div class="small" style="white-space:pre-wrap;"><?= h($req['description']) ?></div>
      </div>
    <?php endif; ?>

    <div>
      <strong>Payment Method:</strong>
      <?php $pmCur = (string)($req['payment_method'] ?? ''); ?>
      <?= h($pmCur) ?: '—' ?>
      <?php if ($isOwner || $isApprover): ?> — <a href="#" id="linkEditPaymentMethod">edit</a><?php endif; ?>
      <?php if ($isApprover && $pmCur === 'Donation Letter Only'): ?>
        — <a href="#" id="linkDonationLetter">send letter</a>
      <?php endif; ?>
    </div>

    <div>
      <strong>Payment Details:</strong>
      <?php $pd = trim((string)($req['payment_details'] ?? '')); ?>
      <?php if ($pd !== ''): ?>
        <span class="small" style="white-space:pre-wrap;"><?= h($pd) ?></span>
      <?php else: ?>
        —
      <?php endif; ?>
      <?php if ($isOwner): ?> — <a href="#" id="linkEditPaymentDetails">edit</a><?php endif; ?>
    </div>

    <div><strong>Last updated:</strong> <?= h($req['last_modified_at']) ?></div>
  </div>
</div>

<?php
// Build Donation Letter modal content conditionally (approver + Donation Letter Only)
if ($isApprover && (string)($req['payment_method'] ?? '') === 'Donation Letter Only'):
  require_once __DIR__ . '/lib/UserManagement.php';
  $submitter = \UserManagement::findBasicForEmailingById((int)($req['created_by'] ?? 0)) ?? [];
  $first = trim((string)($submitter['first_name'] ?? ''));
  $last  = trim((string)($submitter['last_name'] ?? ''));
  $desc = trim((string)($req['description'] ?? ''));
  if ($desc === '') { $desc = 'supplies'; }
  $eventLine = '';
  $eidDef = (int)($req['event_id'] ?? 0);
  if ($eidDef > 0) {
    $evDef = \EventManagement::findBasicById($eidDef);
    if ($evDef) {
      $ename = (string)($evDef['name'] ?? '');
      $dt = $evDef['starts_at'] ?? null;
      $edate = $dt ? date('Y-m-d', strtotime($dt)) : '';
      if ($ename !== '' || $edate !== '') {
        $eventLine = ' for ' . ($ename !== '' ? $ename : '') . ($edate !== '' ? ' on ' . $edate : '');
      }
    }
  }
  $approverName = \UserManagement::getFullName((int)($me['id'] ?? 0)) ?? '';
  $title = \Reimbursements::getLeadershipTitleForUser((int)($me['id'] ?? 0));
  $amountVal = $req['amount'] ?? null;
  $amountDisplay = ($amountVal !== null && $amountVal !== '') ? number_format((float)$amountVal, 2) : '0.00';
  $letterDate = date('Y-m-d');
  $defaultBody =
"Dear {$first} {$last} -

Thank you for your contribution of \${$amountDisplay} made on {$letterDate}. Instead of requesting reimbursement for {$desc} purchased on behalf of Pack 440{$eventLine}, you elected to treat this amount as a charitable contribution.  No goods or services were provided in exchange for this contribution.

Best,
{$approverName}
Pack 440 {$title}
EIN 13-2750608 (Greater Hudson Valley Council)
";
?>
<div id="donationModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:2000;">
  <div style="background:#fff; max-width:600px; margin:60px auto; padding:16px; border-radius:4px;">
    <h4>Donation Letter</h4>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="action" value="send_donation_letter">
      <label>Message
        <textarea name="donation_letter_body" rows="12"><?= h($defaultBody) ?></textarea>
      </label>
      <div class="actions">
        <button class="primary">Send</button>
        <button type="button" id="closeDonationModal" class="button">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Status Change Modal -->
<div id="statusModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:2000;">
  <div style="background:#fff; max-width:600px; margin:60px auto; padding:16px; border-radius:4px;">
    <h4>Change Status</h4>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="action" value="status">
      <label>New status
        <select name="new_status" required>
          <option value="">-- Select --</option>
          <?php foreach ($allowed as $s): ?>
            <option value="<?= h($s) ?>"><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Comment (required)
        <textarea name="comment_text" rows="3" required placeholder="Explain your action..."></textarea>
      </label>
      <div class="actions">
        <button class="primary">Submit</button>
        <button type="button" id="closeStatusModal" class="button">Cancel</button>
      </div>
      <p class="small">A comment is required for all status changes. It will be recorded with the new status.</p>
    </form>
  </div>
</div>

<!-- Amount Edit Modal -->
<div id="amountModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:2000;">
  <div style="background:#fff; max-width:480px; margin:60px auto; padding:16px; border-radius:4px;">
    <h4>Edit Amount</h4>
    <?php
      $amt = $req['amount'] ?? null;
      $amtVal = ($amt !== null && $amt !== '') ? number_format((float)$amt, 2, '.', '') : '';
    ?>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="action" value="update_amount">
      <label>Amount
        <input type="number" name="amount" step="0.01" min="0" value="<?= h($amtVal) ?>" placeholder="0.00" required>
      </label>
      <div class="actions">
        <button class="primary">Save</button>
        <button type="button" id="closeAmountModal" class="button">Cancel</button>
      </div>
      <p class="small">Amount can be edited while the request is in “submitted”, “resubmitted”, or “more_info_requested”.</p>
    </form>
  </div>
</div>

<!-- Change Event Modal -->
<div id="changeEventModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:2000;">
  <div style="background:#fff; max-width:520px; margin:60px auto; padding:16px; border-radius:4px;">
    <h4>Change Event</h4>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="action" value="update_event">
      <label>Event (optional)
        <select name="event_id">
          <option value="">— None —</option>
          <?php
            $currentEid = (int)($req['event_id'] ?? 0);
            $since = date('Y-m-d 00:00:00', strtotime('-1 year'));
            $until = date('Y-m-d 23:59:59', strtotime('+1 year'));
            $events = \EventManagement::listBetween($since, $until);
            $haveCurrent = false;
            foreach ($events as $ev2) {
              $id2 = (int)($ev2['id'] ?? 0);
              if ($id2 === $currentEid) $haveCurrent = true;
              $dt = $ev2['starts_at'] ?? '';
              // Label should be date only
              $label = ($dt ? date('Y-m-d', strtotime($dt)) . ' ' : '') . ($ev2['name'] ?? '');
              $sel = ($currentEid > 0 && $currentEid === $id2) ? ' selected' : '';
              echo '<option value="'.h((string)$id2).'"'.$sel.'>'.h($label).'</option>';
            }
            if ($currentEid > 0 && !$haveCurrent) {
              $cev = \EventManagement::findBasicById($currentEid);
              if ($cev) {
                $dt = $cev['starts_at'] ?? '';
                $label = ($dt ? date('Y-m-d', strtotime($dt)) . ' ' : '') . ($cev['name'] ?? '');
                echo '<option value="'.h((string)$currentEid).'" selected>'.h($label).'</option>';
              }
            }
          ?>
        </select>
      </label>
      <div class="actions">
        <button class="primary">Save</button>
        <button type="button" id="closeChangeEventModal" class="button">Cancel</button>
      </div>
      <p class="small">Choose an event to associate with this reimbursement, or clear to set none.</p>
    </form>
  </div>
</div>

<!-- Payment Method Modal -->
<div id="paymentMethodModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:2000;">
  <div style="background:#fff; max-width:480px; margin:60px auto; padding:16px; border-radius:4px;">
    <h4>Edit Payment Method</h4>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="action" value="update_payment_method">
      <label>Payment Method (optional)
        <select name="payment_method">
          <option value="">— Select —</option>
          <?php
            $pmOpts = ['Zelle','Check','Donation Letter Only'];
            $curPm = (string)($req['payment_method'] ?? '');
            foreach ($pmOpts as $opt) {
              $sel = ($curPm !== '' && $curPm === $opt) ? ' selected' : '';
              echo '<option value="'.h($opt).'"'.$sel.'>'.h($opt).'</option>';
            }
          ?>
        </select>
      </label>
      <div class="actions">
        <button class="primary">Save</button>
        <button type="button" id="closePaymentMethodModal" class="button">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Payment Details Modal -->
<div id="paymentDetailsModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:2000;">
  <div style="background:#fff; max-width:600px; margin:60px auto; padding:16px; border-radius:4px;">
    <h4>Edit Payment Details</h4>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="action" value="update_payment">
      <label>Payment Details (optional)
        <textarea name="payment_details" rows="4" maxlength="500" placeholder="e.g., by check; via Zelle (email or phone); via PayPal (email)"><?= h((string)($req['payment_details'] ?? '')) ?></textarea>
      </label>
      <p class="small">No bank account information please. Do not include long account numbers.</p>
      <div class="actions">
        <button class="primary">Save</button>
        <button type="button" id="closePaymentDetailsModal" class="button">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
  (function(){
    function wire(linkId, modalId, closeId) {
      var link = document.getElementById(linkId);
      var modal = document.getElementById(modalId);
      if (link && modal) {
        link.addEventListener('click', function(e){ e.preventDefault(); modal.style.display = 'block'; });
        if (closeId) {
          var closeBtn = document.getElementById(closeId);
          if (closeBtn) closeBtn.addEventListener('click', function(){ modal.style.display = 'none'; });
        }
        modal.addEventListener('click', function(e){
          if (e.target === modal) { modal.style.display = 'none'; }
        });
      }
    }
    wire('linkEditStatus', 'statusModal', 'closeStatusModal');
    wire('linkEditAmount', 'amountModal', 'closeAmountModal');
    wire('linkChangeEvent', 'changeEventModal', 'closeChangeEventModal');
    wire('linkEditPaymentMethod', 'paymentMethodModal', 'closePaymentMethodModal');
    wire('linkEditPaymentDetails', 'paymentDetailsModal', 'closePaymentDetailsModal');
    wire('linkDonationLetter', 'donationModal', 'closeDonationModal');
  })();
</script>

<div class="card" style="margin-top:16px;">
  <h3>Files</h3>
  <?php if (empty($files)): ?>
    <p class="small">No files uploaded.</p>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($files as $f): ?>
        <li>
          <a href="/secure_file_download.php?id=<?= (int)$f['id'] ?>"><?= h($f['original_filename']) ?></a>
          <span class="small">uploaded by <?= h(trim(($f['first_name'] ?? '').' '.($f['last_name'] ?? ''))) ?> at <?= h($f['created_at']) ?></span>
          <?php if (!empty($f['description'])): ?>
            <div class="small"><?= h($f['description']) ?></div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($canUpload): ?>
    <form method="post" enctype="multipart/form-data" class="stack" style="margin-top:8px;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="action" value="upload_file">
      <label>Attach a file
        <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.heic,.webp" required>
      </label>
      <label class="small">Description (optional)
        <input type="text" name="file_description" maxlength="255">
      </label>
      <div class="actions"><button class="button">Upload</button></div>
      <p class="small">Allowed: pdf, jpg, jpeg, png, heic, webp. Max 15 MB recommended.</p>
    </form>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:16px;">
  <h3>Comments</h3>
  <?php if (empty($comments)): ?>
    <p class="small">No comments yet.</p>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($comments as $c): ?>
        <li>
          <div class="small">
            <strong><?= h(trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? ''))) ?></strong>
            at <?= h($c['created_at']) ?>
            <?php if (!empty($c['status_changed_to'])): ?>
              — <em>Status changed to <?= h($c['status_changed_to']) ?></em>
            <?php endif; ?>
          </div>
          <div style="white-space:pre-wrap;"><?= h($c['comment_text']) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <form method="post" class="stack" style="margin-top:8px;">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="action" value="comment">
    <label>Add a comment
      <textarea name="comment_text" rows="3" required></textarea>
    </label>
    <div class="actions"><button class="button">Post Comment</button></div>
  </form>
</div>

<?php footer_html(); ?>
