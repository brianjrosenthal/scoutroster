<?php
require_once __DIR__.'/../partials.php';
require_once __DIR__.'/../settings.php';
require_once __DIR__.'/../lib/PaymentNotifications.php';
require_once __DIR__.'/../lib/YouthManagement.php';
require_once __DIR__.'/../lib/UserManagement.php';
require_login();

$me = current_user();
$ctx = UserContext::getLoggedInUserContext();

// Visible to Treasurer and Cubmaster (and optionally admins if they also have approver role)
if (!\UserManagement::isApprover((int)($me['id'] ?? 0))) {
  http_response_code(403);
  exit('Forbidden');
}

// Check for verification success message
$msg = '';
if (!empty($_GET['verified']) && !empty($_GET['youth_id'])) {
  $youthId = (int)$_GET['youth_id'];
  try {
    $youth = YouthManagement::findBasicById($youthId);
    if ($youth) {
      $youthName = trim(($youth['first_name'] ?? '') . ' ' . ($youth['last_name'] ?? ''));
      $msg = "Payment Notification about {$youthName} marked as verified and paid_until date set.";
    }
  } catch (Throwable $e) {
    // Ignore errors in flash message generation
  }
}

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? 'new'); // new|verified|deleted
$page = max(1, (int)($_GET['p'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$data = PaymentNotifications::list($ctx, ['q' => $q, 'status' => $status], $limit, $offset);
$total = (int)($data['total'] ?? 0);
$rows = (array)($data['rows'] ?? []);
$hasPrev = $page > 1;
$hasNext = ($offset + $limit) < $total;

function hq($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

header_html('Payment Notifications');
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Payment Notifications from Users</h2>
</div>

<?php if ($msg): ?><p class="flash"><?= h($msg) ?></p><?php endif; ?>

<div class="card">
  <form id="filterForm" method="get" class="stack">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Search
        <input type="text" name="q" value="<?= hq($q) ?>" placeholder="Youth first/last, Marked by first/last">
      </label>
      <label>Status
        <select name="status">
          <option value="new" <?= $status==='new'?'selected':'' ?>>New</option>
          <option value="verified" <?= $status==='verified'?'selected':'' ?>>Verified</option>
          <option value="deleted" <?= $status==='deleted'?'selected':'' ?>>Deleted</option>
        </select>
      </label>
    </div>
  </form>
  <script>
    (function(){
      var f=document.getElementById('filterForm');
      if(!f) return;
      var q=f.querySelector('input[name="q"]');
      var s=f.querySelector('select[name="status"]');
      var t;
      function submitNow(){ if(typeof f.requestSubmit==='function') f.requestSubmit(); else f.submit(); }
      if(q){ q.addEventListener('input', function(){ if(t) clearTimeout(t); t=setTimeout(function(){ f.querySelector('input[name="p"]') && (f.querySelector('input[name="p"]').value='1'); submitNow(); }, 600); }); }
      if(s){ s.addEventListener('change', function(){ var p=document.createElement('input'); p.type='hidden'; p.name='p'; p.value='1'; f.appendChild(p); submitNow(); }); }
    })();
  </script>
</div>

<div class="card">
  <?php if ($total <= 0): ?>
    <p class="small">No payment notifications found.</p>
  <?php else: ?>
    <?php
      // Show actions column if there are any 'new' items OR when viewing the 'verified' tab (allow delete there).
      $hasAnyActions = ($status === 'verified');
      if (!$hasAnyActions) {
        foreach ($rows as $cr) {
          $cst = (string)($cr['status'] ?? 'new');
          if ($cst === 'new') { $hasAnyActions = true; break; }
        }
      }
    ?>
    <table class="list">
      <thead>
        <tr>
          <th>Youth</th>
          <th>Marked By</th>
          <th>Payment Method</th>
          <th>Status</th>
          <th>Created</th>
          <?php if ($hasAnyActions): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $youthId = (int)($r['youth_id'] ?? 0);
          $pnId = (int)($r['id'] ?? 0);
          $yName = trim((string)($r['youth_first'] ?? '').' '.(string)($r['youth_last'] ?? ''));
          $byName = trim((string)($r['by_first'] ?? '').' '.(string)($r['by_last'] ?? ''));
          $method = (string)($r['payment_method'] ?? '');
          $st = (string)($r['status'] ?? 'new');
          $createdAt = (string)($r['created_at'] ?? '');
          $comment = (string)($r['comment'] ?? '');
          $title = $comment !== '' ? ' title="'.hq($comment).'"' : '';
        ?>
        <tr<?= $title ?>>
          <td><a href="/youth_edit.php?id=<?= (int)$youthId ?>"><?= hq($yName) ?></a></td>
          <td><?= hq($byName) ?></td>
          <td><?= hq($method) ?></td>
          <td><?= hq(ucfirst($st)) ?></td>
          <td class="small"><?= hq(Settings::formatDateTime($createdAt)) ?></td>
          <?php if ($hasAnyActions): ?>
          <td class="small">
            <?php if ($st === 'new'): ?>
              <button class="button verify-btn" data-id="<?= (int)$pnId ?>" data-youth-id="<?= (int)$youthId ?>">Verify</button>
              <button class="button danger delete-btn" data-id="<?= (int)$pnId ?>">Delete</button>
            <?php elseif ($status === 'verified' && $st === 'verified'): ?>
              <button class="button danger delete-btn" data-id="<?= (int)$pnId ?>">Delete</button>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="actions" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:8px;">
      <div class="small">Showing <?= (int)min($limit, max(0, $total - $offset)) ?> of <?= (int)$total ?> (page <?= (int)$page ?>)</div>
      <div>
        <?php
          $qsBase = '?'.http_build_query(['q'=>$q,'status'=>$status]);
          $prevHref = $qsBase.'&p='.max(1,$page-1);
          $nextHref = $qsBase.'&p='.($page+1);
        ?>
        <a class="button <?= $hasPrev?'':'disabled' ?>" href="<?= $hasPrev ? hq($prevHref) : '#' ?>" <?= $hasPrev ? '' : 'tabindex="-1" aria-disabled="true"' ?>>Prev</a>
        <a class="button <?= $hasNext?'':'disabled' ?>" href="<?= $hasNext ? hq($nextHref) : '#' ?>" <?= $hasNext ? '' : 'tabindex="-1" aria-disabled="true"' ?>>Next</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Verify Modal (set Paid Until) -->
<div id="pnVerifyModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content" style="max-width:420px;">
    <button class="close" type="button" id="pnVerifyClose" aria-label="Close">&times;</button>
    <h3>Verify Payment</h3>
    <div id="pnVerifyErr" class="error small" style="display:none;"></div>
    <form id="pnVerifyForm" class="stack" method="post" action="/payment_notifications_actions.php">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="verify">
      <input type="hidden" name="id" id="pnVerifyId" value="">
      <label>Paid Until (YYYY-MM-DD)
        <input type="date" name="date_paid_until" id="pnVerifyDate" required>
      </label>
      <p class="small">Confirm setting this youth's dues "Paid Until" date.</p>
      <div class="actions">
        <button class="button primary" type="submit">Set Paid Until</button>
        <button class="button" type="button" id="pnVerifyCancel">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  function computeDefaultPaidYmd(){
    // next Aug 31 at least two months away
    var now = new Date();
    var thresh = new Date(now.getFullYear(), now.getMonth()+2, now.getDate());
    var candidate = new Date(now.getFullYear(), 7, 31); // Aug=7, day=31
    if (candidate < thresh) {
      candidate = new Date(now.getFullYear()+1, 7, 31);
    }
    var y = candidate.getFullYear();
    var m = (candidate.getMonth()+1).toString().padStart(2,'0');
    var d = candidate.getDate().toString().padStart(2,'0');
    return y + '-' + m + '-' + d;
  }

  var verifyModal = document.getElementById('pnVerifyModal');
  var verifyErr = document.getElementById('pnVerifyErr');
  var verifyClose = document.getElementById('pnVerifyClose');
  var verifyCancel = document.getElementById('pnVerifyCancel');
  var verifyForm = document.getElementById('pnVerifyForm');
  var verifyId = document.getElementById('pnVerifyId');
  var verifyDate = document.getElementById('pnVerifyDate');

  function vShowErr(msg){ if(verifyErr){ verifyErr.style.display=''; verifyErr.textContent = msg || 'Operation failed.'; } }
  function vClearErr(){ if(verifyErr){ verifyErr.style.display='none'; verifyErr.textContent=''; } }

  function vOpen(id){
    if (!verifyModal) return;
    vClearErr();
    if (verifyId) verifyId.value = id || '';
    if (verifyDate) verifyDate.value = computeDefaultPaidYmd();
    verifyModal.classList.remove('hidden');
    verifyModal.setAttribute('aria-hidden','false');
  }
  function vHide(){
    if (!verifyModal) return;
    verifyModal.classList.add('hidden');
    verifyModal.setAttribute('aria-hidden','true');
  }

  var verBtns = document.querySelectorAll('.verify-btn');
  for (var i=0;i<verBtns.length;i++){
    verBtns[i].addEventListener('click', function(e){
      e.preventDefault();
      var id = this.getAttribute('data-id');
      vOpen(id);
    });
  }

  if (verifyClose) verifyClose.addEventListener('click', function(){ vHide(); });
  if (verifyCancel) verifyCancel.addEventListener('click', function(){ vHide(); });
  if (verifyModal) verifyModal.addEventListener('click', function(e){ if (e.target === verifyModal) vHide(); });

  if (verifyForm) {
    verifyForm.addEventListener('submit', function(e){
      e.preventDefault();
      vClearErr();
      var fd = new FormData(verifyForm);
      fetch(verifyForm.getAttribute('action') || '/payment_notifications_actions.php', { method:'POST', body: fd, credentials:'same-origin' })
        .then(function(res){ return res.json().catch(function(){ throw new Error('Invalid response'); }); })
        .then(function(json){
          if (json && json.ok) {
            if (json.redirect) {
              // Use the redirect URL provided by the server
              window.location = json.redirect;
            } else {
              // Fallback: reload keeping filters
              var usp = new URLSearchParams(window.location.search);
              window.location = window.location.pathname + '?' + usp.toString();
            }
          } else {
            vShowErr((json && json.error) ? json.error : 'Operation failed.');
          }
        })
        .catch(function(){ vShowErr('Network error.'); });
    });
  }

  // Delete
  var delBtns = document.querySelectorAll('.delete-btn');
  function delConfirm(id){
    if (!id) return;
    if (!confirm('Do you really want to delete this payment notification?')) return;
    var fd = new FormData();
    fd.append('csrf', '<?= h(csrf_token()) ?>');
    fd.append('action', 'delete');
    fd.append('id', id);
    fetch('/payment_notifications_actions.php', { method:'POST', body: fd, credentials:'same-origin' })
      .then(function(res){ return res.json().catch(function(){ throw new Error('Invalid response'); }); })
      .then(function(json){
        if (json && json.ok) {
          // Reload keeping filters
          var usp = new URLSearchParams(window.location.search);
          window.location = window.location.pathname + '?' + usp.toString();
        } else {
          alert((json && json.error) ? json.error : 'Operation failed.');
        }
      })
      .catch(function(){ alert('Network error.'); });
  }
  for (var j=0;j<delBtns.length;j++){
    delBtns[j].addEventListener('click', function(e){
      e.preventDefault();
      var id = this.getAttribute('data-id');
      delConfirm(id);
    });
  }

})();
</script>

<?php footer_html(); ?>
