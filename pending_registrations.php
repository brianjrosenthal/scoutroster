<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/lib/PendingRegistrations.php';
require_once __DIR__ . '/lib/YouthManagement.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_login();

$me = current_user();
$ctx = UserContext::getLoggedInUserContext();

if (!\UserManagement::isApprover((int)($me['id'] ?? 0))) {
  http_response_code(403);
  exit('Forbidden');
}

$status = trim($_GET['status'] ?? 'needing_action'); // needing_action|completed|deleted
$page = max(1, (int)($_GET['p'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$data = PendingRegistrations::list($ctx, ['status' => $status], $limit, $offset);
$total = (int)($data['total'] ?? 0);
$rows = (array)($data['rows'] ?? []);
$hasPrev = $page > 1;
$hasNext = ($offset + $limit) < $total;

function hq($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

header_html('Pending Registrations');
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Pending Registrations</h2>
</div>

<div class="card">
  <form id="filterForm" method="get" class="stack">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Status
        <select name="status">
          <option value="needing_action" <?= $status==='needing_action'?'selected':'' ?>>Needing Action</option>
          <option value="completed" <?= $status==='completed'?'selected':'' ?>>Completed</option>
          <option value="deleted" <?= $status==='deleted'?'selected':'' ?>>Deleted</option>
        </select>
      </label>
    </div>
  </form>
  <script>
    (function(){
      var f=document.getElementById('filterForm');
      if(!f) return;
      var s=f.querySelector('select[name="status"]');
      function submitNow(){ if(typeof f.requestSubmit==='function') f.requestSubmit(); else f.submit(); }
      if(s){ s.addEventListener('change', function(){ var p=document.createElement('input'); p.type='hidden'; p.name='p'; p.value='1'; f.appendChild(p); submitNow(); }); }
    })();
  </script>
</div>

<div class="card">
  <?php if ($total <= 0): ?>
    <p class="small">No pending registrations found.</p>
  <?php else: ?>
    <table class="list">
      <thead>
        <tr>
          <th>Youth</th>
          <th>Submitted By</th>
          <th>File</th>
          <th>Payment</th>
          <th>Status</th>
          <th>Created</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $prId = (int)($r['id'] ?? 0);
          $youthId = (int)($r['youth_id'] ?? 0);
          $yName = trim((string)($r['youth_first'] ?? '').' '.(string)($r['youth_last'] ?? ''));
          $byName = trim((string)($r['by_first'] ?? '').' '.(string)($r['by_last'] ?? ''));
          $secureFileId = (int)($r['secure_file_id'] ?? 0);
          $st = (string)($r['status'] ?? 'new'); // new|processed|deleted
          $pstatus = (string)($r['payment_status'] ?? 'not_paid'); // not_paid|paid
          $createdAt = (string)($r['created_at'] ?? '');
          $isDeleted = ($st === 'deleted');
          $isProcessed = ($st === 'processed');
          $needsAction = ($st === 'new');
          $canTogglePaid = !$isDeleted;
          $canProcess = !$isDeleted && !$isProcessed;
          $canDelete = !$isDeleted;
          $fileLink = $secureFileId > 0 ? ('/pending_registration_download.php?id='.(int)$prId) : '';
        ?>
        <tr>
          <td><a href="/youth_edit.php?id=<?= (int)$youthId ?>"><?= hq($yName) ?></a></td>
          <td><?= hq($byName) ?></td>
          <td>
            <?php if ($fileLink !== ''): ?>
              <a class="button" href="<?= hq($fileLink) ?>" target="_blank" rel="noopener">Download</a>
            <?php else: ?>
              <span class="small">&mdash;</span>
            <?php endif; ?>
          </td>
          <td><?= hq($pstatus === 'paid' ? 'Paid' : 'Not Paid') ?></td>
          <td><?= hq(ucfirst($st)) ?></td>
          <td class="small"><?= hq(Settings::formatDateTime($createdAt)) ?></td>
          <td class="small">
            <?php if ($canTogglePaid): ?>
              <?php if ($pstatus === 'paid'): ?>
                <button class="button" data-action="unmark_paid" data-id="<?= (int)$prId ?>">Unmark Paid</button>
              <?php else: ?>
                <button class="button" data-action="mark_paid" data-id="<?= (int)$prId ?>">Mark Paid</button>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($canProcess): ?>
              <button class="button" data-action="mark_processed" data-id="<?= (int)$prId ?>">Mark Processed</button>
            <?php endif; ?>
            <?php if ($canDelete): ?>
              <button class="button danger" data-action="delete" data-id="<?= (int)$prId ?>">Delete</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php if (trim((string)($r['comment'] ?? '')) !== ''): ?>
          <tr>
            <td colspan="7" class="small" style="white-space:pre-wrap; color:#555;">
              <strong>Parent Comment:</strong> <?= hq((string)$r['comment']) ?>
            </td>
          </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="actions" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:8px;">
      <div class="small">Showing <?= (int)min($limit, max(0, $total - $offset)) ?> of <?= (int)$total ?> (page <?= (int)$page ?>)</div>
      <div>
        <?php
          $qsBase = '?'.http_build_query(['status'=>$status]);
          $prevHref = $qsBase.'&p='.max(1,$page-1);
          $nextHref = $qsBase.'&p='.($page+1);
        ?>
        <a class="button <?= $hasPrev?'':'disabled' ?>" href="<?= $hasPrev ? hq($prevHref) : '#' ?>" <?= $hasPrev ? '' : 'tabindex="-1" aria-disabled="true"' ?>>Prev</a>
        <a class="button <?= $hasNext?'':'disabled' ?>" href="<?= $hasNext ? hq($nextHref) : '#' ?>" <?= $hasNext ? '' : 'tabindex="-1" aria-disabled="true"' ?>>Next</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  function postAction(action, id){
    var fd = new FormData();
    fd.append('csrf', '<?= h(csrf_token()) ?>');
    fd.append('action', action);
    fd.append('id', id);
    return fetch('/pending_registrations_actions.php', { method:'POST', body: fd, credentials:'same-origin' })
      .then(function(res){ return res.json().catch(function(){ throw new Error('Invalid response'); }); });
  }

  function onClick(e){
    var btn = e.target.closest('button[data-action]');
    if (!btn) return;
    e.preventDefault();
    var action = btn.getAttribute('data-action');
    var id = btn.getAttribute('data-id');
    if (!action || !id) return;
    if (action === 'delete' && !confirm('Delete this pending registration?')) return;
    postAction(action, id)
      .then(function(json){
        if (json && json.ok) {
          var usp = new URLSearchParams(window.location.search);
          window.location = window.location.pathname + '?' + usp.toString();
        } else {
          alert((json && json.error) ? json.error : 'Operation failed.');
        }
      })
      .catch(function(){ alert('Network error.'); });
  }

  document.addEventListener('click', onClick);
})();
</script>

<?php footer_html(); ?>
