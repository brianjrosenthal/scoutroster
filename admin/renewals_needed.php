<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserContext.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_admin();

// Require approver permissions (Key 3 leaders: Cubmaster, Committee Chair, Treasurer)
$ctx = UserContext::getLoggedInUserContext();
if (!$ctx || !UserManagement::isApprover($ctx->id)) {
    http_response_code(403);
    header_html('BSA Renewals Needed - Access Denied');
    echo '<div class="card">';
    echo '<h2>Access Denied</h2>';
    echo '<p class="error">This section is only available to Key 3 leaders (Cubmaster, Committee Chair, and Treasurer).</p>';
    echo '<p><a class="button" href="/index.php">Return to Home</a></p>';
    echo '</div>';
    footer_html();
    exit;
}

function hq($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// Inputs
$type = trim((string)($_GET['type'] ?? 'both')); // adults|youth|both
if (!in_array($type, ['adults','youth','both'], true)) { $type = 'both'; }

$status = trim((string)($_GET['status'] ?? 'both')); // expired|expiring|both
if (!in_array($status, ['expired','expiring','both'], true)) { $status = 'both'; }

// Build the query
$queries = [];
$params = [];

$todayPlus30 = date('Y-m-d', strtotime('+30 days'));
$today = date('Y-m-d');

if ($type === 'adults' || $type === 'both') {
    $whereConditions = [
        "bsa_membership_number IS NOT NULL",
        "bsa_membership_number != ''"
    ];
    
    if ($status === 'expired') {
        $whereConditions[] = "bsa_registration_expires_on < ?";
        $params[] = $today;
    } elseif ($status === 'expiring') {
        $whereConditions[] = "bsa_registration_expires_on >= ?";
        $whereConditions[] = "bsa_registration_expires_on <= ?";
        $params[] = $today;
        $params[] = $todayPlus30;
    } else { // both
        $whereConditions[] = "bsa_registration_expires_on <= ?";
        $params[] = $todayPlus30;
    }
    
    $queries[] = "SELECT 'Adult' as type, id, first_name, last_name, bsa_membership_number as bsa_id, 
                         bsa_registration_expires_on as expires_date, email, phone_cell
                  FROM users 
                  WHERE " . implode(' AND ', $whereConditions);
}

if ($type === 'youth' || $type === 'both') {
    $whereConditions = [
        "bsa_registration_number IS NOT NULL",
        "bsa_registration_number != ''",
        "(left_troop IS NULL OR left_troop = 0)"
    ];
    
    if ($status === 'expired') {
        $whereConditions[] = "bsa_registration_expires_date < ?";
        $params[] = $today;
    } elseif ($status === 'expiring') {
        $whereConditions[] = "bsa_registration_expires_date >= ?";
        $whereConditions[] = "bsa_registration_expires_date <= ?";
        $params[] = $today;
        $params[] = $todayPlus30;
    } else { // both
        $whereConditions[] = "bsa_registration_expires_date <= ?";
        $params[] = $todayPlus30;
    }
    
    $queries[] = "SELECT 'Youth' as type, id, first_name, last_name, bsa_registration_number as bsa_id, 
                         bsa_registration_expires_date as expires_date, NULL as email, NULL as phone_cell
                  FROM youth 
                  WHERE " . implode(' AND ', $whereConditions);
}

$finalQuery = implode(' UNION ALL ', $queries) . ' ORDER BY expires_date ASC, type, last_name, first_name';

// Execute query
try {
    $st = pdo()->prepare($finalQuery);
    $st->execute($params);
    $rows = $st->fetchAll();
} catch (Exception $e) {
    $rows = [];
    $queryError = $e->getMessage();
}

// Build emails for copy functionality
$emailsMap = [];
foreach ($rows as $row) {
    $email = trim((string)($row['email'] ?? ''));
    if ($email !== '') {
        $emailsMap[$email] = true;
    }
}
$emailList = implode("\n", array_keys($emailsMap));

// Group results
$expired = [];
$expiringSoon = [];
foreach ($rows as $row) {
    $expiresDate = $row['expires_date'];
    if ($expiresDate < $today) {
        $expired[] = $row;
    } else {
        $expiringSoon[] = $row;
    }
}

header_html('BSA Renewals Needed');
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>BSA Renewals Needed</h2>
</div>

<div class="card">
  <p class="small">Report showing adults and youth with BSA registration that expires within the next month or has already expired.</p>
  
  <form id="filterForm" method="get" class="stack">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Type
        <select name="type">
          <option value="both" <?= $type === 'both' ? 'selected' : '' ?>>Adults and Youth</option>
          <option value="adults" <?= $type === 'adults' ? 'selected' : '' ?>>Adults Only</option>
          <option value="youth" <?= $type === 'youth' ? 'selected' : '' ?>>Youth Only</option>
        </select>
      </label>
      <label>Status
        <select name="status">
          <option value="both" <?= $status === 'both' ? 'selected' : '' ?>>Expired and Expiring</option>
          <option value="expired" <?= $status === 'expired' ? 'selected' : '' ?>>Expired Only</option>
          <option value="expiring" <?= $status === 'expiring' ? 'selected' : '' ?>>Expiring Soon</option>
        </select>
      </label>
    </div>
    <div class="actions">
      <button class="primary">Filter</button>
      <a class="button" href="/admin/renewals_needed.php">Reset</a>
      <?php if (!empty($emailList)): ?>
        <button type="button" class="button" id="copyEmailsBtn">Copy Emails</button>
        <span id="copyEmailsStatus" class="small" style="display:none;margin-left:8px;"></span>
      <?php endif; ?>
    </div>
  </form>
</div>

<script>
(function(){
  var form = document.getElementById('filterForm');
  if (form) {
    var typeSelect = form.querySelector('select[name="type"]');
    var statusSelect = form.querySelector('select[name="status"]');
    function submitNow() {
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.submit();
    }
    if (typeSelect) typeSelect.addEventListener('change', submitNow);
    if (statusSelect) statusSelect.addEventListener('change', submitNow);
  }

  var btn = document.getElementById('copyEmailsBtn');
  var status = document.getElementById('copyEmailsStatus');

  function show(msg, ok){
    if (!status) return;
    status.style.display = '';
    status.style.color = ok ? '#060' : '#c00';
    status.textContent = msg;
    setTimeout(function(){ status.style.display='none'; }, 2000);
  }
  function copyText(text){
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    } else {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      ta.style.top = '-9999px';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      try {
        var ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok ? Promise.resolve() : Promise.reject(new Error('execCommand copy failed'));
      } catch (e) {
        document.body.removeChild(ta);
        return Promise.reject(e);
      }
    }
  }
  if (btn) {
    btn.addEventListener('click', function(e){
      e.preventDefault();
      var payloadEl = document.getElementById('emailsPayload');
      var text = payloadEl ? (payloadEl.value || payloadEl.textContent || '') : '';
      copyText(text || '')
        .then(function(){ show('Emails copied.', true); })
        .catch(function(){ show('Copy failed.', false); });
    });
  }
})();
</script>

<?php if (isset($queryError)): ?>
<div class="card">
  <p class="error">Query error: <?= hq($queryError) ?></p>
</div>
<?php elseif (empty($rows)): ?>
<div class="card">
  <p class="small">No records found matching the current filters. Great news - everyone's registration is current!</p>
</div>
<?php else: ?>
<textarea id="emailsPayload" readonly style="position:absolute;left:-9999px;top:-9999px;"><?= hq($emailList) ?></textarea>

<div class="card">
  <p><strong>Summary:</strong> <?= count($rows) ?> total records • <?= count($expired) ?> expired • <?= count($expiringSoon) ?> expiring within 30 days</p>
</div>

<?php if (!empty($expired)): ?>
<div class="card">
  <h3 style="color: #c00;">Expired Registrations (<?= count($expired) ?>)</h3>
  <table class="list">
    <thead>
      <tr>
        <th>Type</th>
        <th>Name</th>
        <th>BSA ID</th>
        <th>Expired Date</th>
        <th>Days Overdue</th>
        <th>Contact</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($expired as $row): ?>
      <?php
        $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $daysOverdue = (new DateTime($today))->diff(new DateTime($row['expires_date']))->days;
        $contact = [];
        if (!empty($row['email'])) $contact[] = hq($row['email']);
        if (!empty($row['phone_cell'])) $contact[] = hq($row['phone_cell']);
        $contactStr = implode('<br>', $contact);
      ?>
      <tr>
        <td><strong><?= hq($row['type']) ?></strong></td>
        <td><?= hq($fullName) ?></td>
        <td><?= hq($row['bsa_id']) ?></td>
        <td style="color: #c00;"><?= hq($row['expires_date']) ?></td>
        <td style="color: #c00;"><?= (int)$daysOverdue ?></td>
        <td><?= $contactStr ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if (!empty($expiringSoon)): ?>
<div class="card">
  <h3 style="color: #f0ad4e;">Expiring Within 30 Days (<?= count($expiringSoon) ?>)</h3>
  <table class="list">
    <thead>
      <tr>
        <th>Type</th>
        <th>Name</th>
        <th>BSA ID</th>
        <th>Expires Date</th>
        <th>Days Remaining</th>
        <th>Contact</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($expiringSoon as $row): ?>
      <?php
        $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $daysRemaining = (new DateTime($row['expires_date']))->diff(new DateTime($today))->days;
        $contact = [];
        if (!empty($row['email'])) $contact[] = hq($row['email']);
        if (!empty($row['phone_cell'])) $contact[] = hq($row['phone_cell']);
        $contactStr = implode('<br>', $contact);
      ?>
      <tr>
        <td><strong><?= hq($row['type']) ?></strong></td>
        <td><?= hq($fullName) ?></td>
        <td><?= hq($row['bsa_id']) ?></td>
        <td style="color: #f0ad4e;"><?= hq($row['expires_date']) ?></td>
        <td style="color: #f0ad4e;"><?= (int)$daysRemaining ?></td>
        <td><?= $contactStr ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php endif; ?>

<?php footer_html(); ?>
