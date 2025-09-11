<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/Reimbursements.php';
require_once __DIR__ . '/lib/Files.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_login();

$ctx = UserContext::getLoggedInUserContext();
$me = current_user();
$isAdmin = !empty($me['is_admin']);
$isApprover = Reimbursements::isApprover($ctx);

$err = null;
$msg = null;

$oldTitle = '';
$oldDescription = '';
$oldPaymentDetails = '';
$oldAmount = '';

// Handle create (title/description + optional file)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
  require_csrf();
  try {
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? '')) ?: null;
    $paymentDetails = trim((string)($_POST['payment_details'] ?? '')) ?: null;
    $amount = trim((string)($_POST['amount'] ?? '')) ?: null;

    // Preserve submitted values on error for redisplay
    $oldTitle = $title;
    $oldDescription = (string)($_POST['description'] ?? '');
    $oldPaymentDetails = (string)($_POST['payment_details'] ?? '');
    $oldAmount = (string)($_POST['amount'] ?? '');

    $newId = Reimbursements::create($ctx, $title, $description, $paymentDetails, $amount);

    // Optional file (store securely in DB)
    if (!empty($_FILES['file']) && is_array($_FILES['file']) && empty($_FILES['file']['error'])) {
      $f = $_FILES['file'];
      $tmp = $f['tmp_name'] ?? '';
      $name = $f['name'] ?? 'file';
      if (is_uploaded_file($tmp)) {
        // Allow images and PDFs (common receipts)
        $allowedExt = ['pdf','jpg','jpeg','png','heic','webp'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
          $err = 'Unsupported file type. Allowed: pdf, jpg, jpeg, png, heic, webp.';
        } else {
          $data = @file_get_contents($tmp);
          if ($data === false) {
            $err = 'Failed to read uploaded file.';
          } else {
            // Best-effort content type
            $mime = 'application/octet-stream';
            if (function_exists('finfo_open')) {
              $finfo = finfo_open(FILEINFO_MIME_TYPE);
              if ($finfo) {
                $mt = finfo_file($finfo, $tmp);
                if (is_string($mt) && $mt !== '') $mime = $mt;
                finfo_close($finfo);
              }
            }
            try {
              $secureId = Files::insertSecureFile($data, $mime, $name, (int)$me['id']);
              Reimbursements::recordSecureFile($ctx, (int)$newId, (int)$secureId, $name, null);
            } catch (Throwable $e) {
              $err = 'Failed to store uploaded file.';
            }
          }
        }
      }
    }

    header('Location: /reimbursement_view.php?id=' . (int)$newId);
    exit;
  } catch (Throwable $e) {
    $err = $e->getMessage() ?: 'Unable to create reimbursement request.';
  }
}

// Listing (mine by default, ?all=1 if admin/approver)
$includeAll = (!empty($_GET['all']) && ($isAdmin || $isApprover));
$rows = Reimbursements::listMine($ctx, $includeAll);

header_html('Expense Reimbursements');
?>
<h2>Expense Reimbursements</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<?php
  // Display intended email recipients for new submissions
  $recips = Reimbursements::listApproverRecipients();
  $names = [];
  foreach ($recips as $r) {
    $n = trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? ''));
    $e = (string)($r['email'] ?? '');
    $names[] = $n . ($e !== '' ? ' <'.$e.'>' : '');
  }
?>
<p class="small">Requests will be sent to: <?= !empty($names) ? h(implode(', ', $names)) : '(none configured yet)' ?></p>

<div class="card">
  <div class="actions">
    <?php if ($isAdmin || $isApprover): ?>
      <?php if ($includeAll): ?>
        <a class="button" href="/reimbursements.php">View my reimbursements</a>
      <?php else: ?>
        <a class="button" href="/reimbursements.php?all=1">View all reimbursements</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if (empty($rows)): ?>
    <p class="small"><?= $includeAll ? 'No reimbursements found.' : 'You have no reimbursements yet.' ?></p>
  <?php else: ?>
    <table class="list">
      <thead>
        <tr>
          <th>Title</th>
          <th>Status</th>
          <th>Owner</th>
          <th>Last Updated</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?=h($r['title'])?></td>
            <td><?=h($r['status'])?></td>
            <td>
              <?php
                if ($includeAll) {
                  $ownerName = UserManagement::getFullName((int)$r['created_by']) ?? '';
                  echo h($ownerName);
                } else {
                  echo 'Me';
                }
              ?>
            </td>
            <td><?=h($r['last_modified_at'])?></td>
            <td class="small">
              <a class="button" href="/reimbursement_view.php?id=<?= (int)$r['id'] ?>">View</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:16px;">
  <h3>Submit a new reimbursement</h3>
  <form method="post" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="create">
    <label>Title
      <input type="text" name="title" value="<?= h($oldTitle) ?>" required maxlength="255">
    </label>
    <label>Description (optional)
      <textarea name="description" rows="4"><?= h($oldDescription) ?></textarea>
    </label>
    <label>Payment Details (optional, no bank account numbers please)
      <textarea name="payment_details" rows="3" maxlength="500" placeholder="e.g., by check; via Zelle (email or phone); via PayPal (email)"><?= h($oldPaymentDetails) ?></textarea>
      <span class="small">No bank account information please.</span>
    </label>
    <label>Amount (optional)
      <input type="number" name="amount" value="<?= h($oldAmount) ?>" step="0.01" min="0" placeholder="0.00">
      <span class="small">Enter the total amount to be reimbursed.</span>
    </label>
    <label>Attach a file (optional)
      <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.heic,.webp">
    </label>
    <div class="actions">
      <button class="primary">Submit Request</button>
    </div>
    <p class="small">Allowed file types: pdf, jpg, jpeg, png, heic, webp. Max size 15 MB.</p>
  </form>
</div>

<?php footer_html(); ?>
