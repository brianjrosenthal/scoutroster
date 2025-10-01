<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/Reimbursements.php';
require_once __DIR__ . '/lib/Files.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/EventManagement.php';
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
$oldCreatedById = '';
$oldCreatedByLabel = '';
$oldEventId = '';
$oldPaymentMethod = '';

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
    $eventId = (int)($_POST['event_id'] ?? 0);
    $oldEventId = $eventId > 0 ? (string)$eventId : '';
    $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
    $oldPaymentMethod = $paymentMethod;

    // Approver on-behalf (optional)
    $createdById = ($isApprover ? (int)($_POST['created_by_user_id'] ?? 0) : 0);
    if ($createdById > 0) {
      $oldCreatedById = (string)$createdById;
      $oldCreatedByLabel = UserManagement::getFullName((int)$createdById) ?? '';
    }

    $newId = Reimbursements::create($ctx, $title, $description, $paymentDetails, $amount, $createdById ?: null, $eventId > 0 ? $eventId : null, $paymentMethod !== '' ? $paymentMethod : null);

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

// Listing (mine by default, ?all=1 if approver only)
$includeAll = (!empty($_GET['all']) && $isApprover);
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
    <?php if ($isApprover): ?>
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
  <form method="post" enctype="multipart/form-data" class="stack" id="newReimbursementForm">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="create">
    <?php if ($isApprover): ?>
      <label>Submit on behalf of (optional)
        <input type="hidden" name="created_by_user_id" id="created_by_user_id" value="<?= h($oldCreatedById) ?>">
        <input
          type="text"
          id="created_by_search"
          placeholder="Type to search adults by name or email"
          autocomplete="off"
          role="combobox"
          aria-expanded="false"
          aria-owns="created_by_results_list"
          aria-autocomplete="list"
          value="<?= h($oldCreatedByLabel) ?>">
        <div id="created_by_results" class="typeahead-results" role="listbox" style="position:relative;">
          <div id="created_by_results_list" class="list" style="position:absolute; z-index:1000; background:#fff; border:1px solid #ccc; max-height:200px; overflow:auto; width:100%; display:none;"></div>
        </div>
      </label>
    <?php endif; ?>

    <label>Event (optional)
      <select name="event_id">
        <option value="">— None —</option>
        <?php
          $since = date('Y-m-d H:i:s', strtotime('-6 months'));
          $until = date('Y-m-d H:i:s', strtotime('+6 months'));
          $events = \EventManagement::listBetween($since, $until);
          foreach ($events as $ev) {
            $id = (int)$ev['id'];
            $dt = $ev['starts_at'] ?? '';
            $label = ($dt ? date('Y-m-d H:i', strtotime($dt)) . ' — ' : '') . ($ev['name'] ?? '');
            $sel = ($oldEventId !== '' && (int)$oldEventId === $id) ? ' selected' : '';
            echo '<option value="'.h((string)$id).'"'.$sel.'>'.h($label).'</option>';
          }
        ?>
      </select>
      <span class="small">Link this reimbursement to an event.</span>
    </label>

    <label>Title
      <input type="text" name="title" value="<?= h($oldTitle) ?>" required maxlength="255">
    </label>
    <label>Description (optional)
      <textarea name="description" rows="4"><?= h($oldDescription) ?></textarea>
    </label>

    <label>Amount (optional)
      <input type="number" name="amount" value="<?= h($oldAmount) ?>" step="0.01" min="0" placeholder="0.00">
      <span class="small">Enter the total amount to be reimbursed.</span>
    </label>

    <label>Payment Method (optional)
      <select name="payment_method">
        <option value="">— Select —</option>
        <?php
          $pmOpts = ['Zelle','Check','Donation Letter Only'];
          foreach ($pmOpts as $opt) {
            $sel = ($oldPaymentMethod !== '' && $oldPaymentMethod === $opt) ? ' selected' : '';
            echo '<option value="'.h($opt).'"'.$sel.'>'.h($opt).'</option>';
          }
        ?>
      </select>
    </label>

    <label>Payment Details (optional, no bank account numbers please)
      <textarea name="payment_details" rows="3" maxlength="500" placeholder="e.g., by check; via Zelle (email or phone); via PayPal (email)"><?= h($oldPaymentDetails) ?></textarea>
      <span class="small">No bank account information please.</span>
    </label>

    <label>Attach a file (optional)
      <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.heic,.webp">
    </label>
    <div class="actions">
      <button class="primary" id="submitReimbursementBtn">Submit Request</button>
    </div>
    <p class="small">Allowed file types: pdf, jpg, jpeg, png, heic, webp. Max size 15 MB.</p>
  </form>

  <?php if ($isApprover): ?>
  <script>
    (function(){
      function debounce(fn, wait) {
        let t = null;
        return function() {
          const ctx = this, args = arguments;
          clearTimeout(t);
          t = setTimeout(function(){ fn.apply(ctx, args); }, wait);
        };
      }
      const input = document.getElementById('created_by_search');
      const hidden = document.getElementById('created_by_user_id');
      const listWrap = document.getElementById('created_by_results');
      const list = document.getElementById('created_by_results_list');
      let items = [];
      let open = false;

      function close() {
        if (!list) return;
        list.style.display = 'none';
        if (input) input.setAttribute('aria-expanded', 'false');
        open = false;
      }
      function openList() {
        if (!list || items.length === 0) { close(); return; }
        list.style.display = '';
        if (input) input.setAttribute('aria-expanded', 'true');
        open = true;
      }
      function render() {
        if (!list) return;
        list.innerHTML = '';
        const frag = document.createDocumentFragment();
        items.forEach(function(it){
          const div = document.createElement('div');
          div.setAttribute('role', 'option');
          div.style.padding = '6px 8px';
          div.style.cursor = 'pointer';
          div.textContent = it.label;
          div.addEventListener('mousedown', function(e){ e.preventDefault(); });
          div.addEventListener('click', function(){
            if (hidden) hidden.value = it.id;
            if (input) input.value = it.label;
            close();
          });
          frag.appendChild(div);
        });
        list.appendChild(frag);
        openList();
      }

      const doSearch = debounce(function(){
        const q = (input && input.value ? input.value.trim() : '');
        if (q.length < 1) { items = []; render(); return; }
        fetch('/admin_adult_search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(json){
            if (!json || !json.ok) { items = []; render(); return; }
            const arr = json.items || [];
            items = arr.map(function(it){
              const label = (it.last_name ? it.last_name : '') + ', ' + (it.first_name ? it.first_name : '') + (it.email ? (' <' + it.email + '>') : '');
              return { id: it.id, label: label.trim() };
            });
            render();
          })
          .catch(function(){ items = []; render(); });
      }, 200);

      if (input) {
        input.addEventListener('input', function(){
          if (hidden && input && input.value.trim() === '') { hidden.value = ''; }
          doSearch();
        });
        input.addEventListener('keydown', function(e){
          if (!open && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            openList();
          } else if (e.key === 'Escape') {
            close();
          }
        });
        input.addEventListener('blur', function(){ setTimeout(close, 120); });
      }
    })();
  </script>
  <?php endif; ?>
</div>

<script>
(function(){
  // Add double-click protection to reimbursement submission form
  var reimbursementForm = document.getElementById('newReimbursementForm');
  var submitBtn = document.getElementById('submitReimbursementBtn');
  
  if (reimbursementForm && submitBtn) {
    reimbursementForm.addEventListener('submit', function(e) {
      if (submitBtn.disabled) {
        e.preventDefault();
        return;
      }
      submitBtn.disabled = true;
      submitBtn.textContent = 'Submitting...';
    });
  }
})();
</script>

<?php footer_html(); ?>
