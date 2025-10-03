<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GradeCalculator.php';
require_once __DIR__ . '/../lib/YouthManagement.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_login();

$me = current_user();
$ctx = UserContext::getLoggedInUserContext();

if (!\UserManagement::isApprover((int)($me['id'] ?? 0))) {
  http_response_code(403);
  exit('Forbidden');
}

function hq($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// Inputs
$status = trim((string)($_GET['status'] ?? 'all')); // all|notification_needed|action_needed|processing_needed
if (!in_array($status, ['all','notification_needed','action_needed','processing_needed'], true)) { $status = 'all'; }

$gLabel = trim((string)($_GET['g'] ?? '')); // Grade filter: K,0..5
$g = $gLabel !== '' ? GradeCalculator::parseGradeLabel($gLabel) : null;

$filters = [
  'status' => $status,
  'grade_label' => $gLabel,
];

$rows = YouthManagement::listForRenewals($ctx, $filters);
$youthIds = array_map(static function($r){ return (int)$r['id']; }, $rows);
$parentsByYouth = !empty($youthIds) ? UserManagement::listParentsForYouthIds($ctx, $youthIds) : [];

// Build emails payload (deduped) for Copy Emails
$emailsMap = [];
foreach ($youthIds as $yid) {
  $plist = $parentsByYouth[$yid] ?? [];
  foreach ($plist as $p) {
    $e = trim((string)($p['email'] ?? ''));
    if ($e !== '') { $emailsMap[$e] = true; }
  }
}
$emailList = implode("\n", array_keys($emailsMap));

/**
 * Group by grade buckets:
 * - 'pre' bucket for any grade < 0
 * - string "0".."5" buckets for K..5
 * - numeric string > 5 buckets for older siblings
 */
$byGrade = []; // key => list (key 'pre' or '0','1',... as strings)
foreach ($rows as $r) {
  $gcalc = GradeCalculator::gradeForClassOf((int)$r['class_of']);
  $key = ($gcalc < 0) ? 'pre' : (string)$gcalc;
  if (!isset($byGrade[$key])) $byGrade[$key] = [];
  $byGrade[$key][] = $r;
}

// Build ordered keys: Pre-K, grades 0..5, then >5 ascending
$orderedKeys = [];
if (isset($byGrade['pre'])) $orderedKeys[] = 'pre';
for ($i = 0; $i <= 5; $i++) {
  $k = (string)$i;
  if (isset($byGrade[$k])) $orderedKeys[] = $k;
}
$other = [];
foreach ($byGrade as $k => $_) {
  if ($k === 'pre') continue;
  $ki = (int)$k;
  if ($ki > 5) $other[] = $ki;
}
sort($other);
foreach ($other as $ki) {
  $orderedKeys[] = (string)$ki;
}

header_html('Registration Renewals');
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Registration Renewals</h2>
</div>

<div class="card">
  <h3>How to Use This Page</h3>
  <p>This page helps treasurers and committee chairs manage Scout registrations and renewals. Use the <strong>Status</strong> filter below to focus on specific tasks:</p>
  
  <div style="margin: 16px 0;">
    <h4 style="margin-bottom: 8px; color: #2c5aa0;">Status Filter Options:</h4>
    <ul style="margin-left: 20px; line-height: 1.6;">
      <li><strong>"All"</strong> - Shows everyone who needs some kind of action taken (registrations expiring soon, pending payments, or pending registrations)</li>
      <li><strong>"Notification of family needed"</strong> - Shows families whose BSA registrations are expired or expiring soon, but who haven't submitted any payments or registration forms yet. These families need to be contacted about renewing.</li>
      <li><strong>"Action needed to Scouting.org"</strong> - Shows Scouts whose BSA registrations are not current AND who have submitted payment notifications or registration forms. You need to log into Scouting.org and process their renewals.</li>
      <li><strong>"Processing Needed"</strong> - Shows all pending payments and registration forms that need to be reviewed and processed, regardless of their current registration status.</li>
    </ul>
  </div>
  
  <p><strong>Key:</strong> Expiration dates are <span style="color:#c00; font-weight:bold;">red if expired</span> or <span style="color:#e67e22; font-weight:bold;">orange if expiring soon</span>. Click on "pending payment" or "pending registration" links in the last column to go directly to those admin pages.</p>
</div>

<div class="card">
  <form id="filterForm" method="get" class="stack">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Status
        <select name="status">
          <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
          <option value="notification_needed" <?= $status === 'notification_needed' ? 'selected' : '' ?>>Notification of family needed</option>
          <option value="action_needed" <?= $status === 'action_needed' ? 'selected' : '' ?>>Action needed to Scouting.org</option>
          <option value="processing_needed" <?= $status === 'processing_needed' ? 'selected' : '' ?>>Processing Needed</option>
        </select>
      </label>
      <label>Grade
        <select name="g">
          <option value="">All</option>
          <?php for($i=0;$i<=5;$i++): $lbl = GradeCalculator::gradeLabel($i); ?>
            <option value="<?=h($lbl)?>" <?= ($gLabel === $lbl ? 'selected' : '') ?>>
              <?= $i === 0 ? 'K' : $i ?>
            </option>
          <?php endfor; ?>
        </select>
      </label>
    </div>
    <div class="actions">
      <button class="primary">Filter</button>
      <a class="button" href="/admin/registration_renewals.php">Reset</a>
      <button type="button" class="button" id="copyEmailsBtn">Copy emails</button>
      <span id="copyEmailsStatus" class="small" style="display:none;margin-left:8px;"></span>
    </div>
  </form>
</div>

<script>
(function(){
  var form = document.getElementById('filterForm');
  if (form) {
    var st = form.querySelector('select[name="status"]');
    var g  = form.querySelector('select[name="g"]');
    function submitNow() {
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.submit();
    }
    if (st) st.addEventListener('change', submitNow);
    if (g)  g.addEventListener('change', submitNow);
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

<div class="card">
  <?php if (empty($rows)): ?>
    <p class="small">No matching youths.</p>
  <?php else: ?>
    <textarea id="emailsPayload" readonly style="position:absolute;left:-9999px;top:-9999px;"><?= hq($emailList) ?></textarea>

    <?php foreach ($orderedKeys as $gradeKey): $list = $byGrade[$gradeKey]; ?>
      <div class="card">
        <h3>
          <?php if ($gradeKey === 'pre'): ?>
            Pre-K
          <?php else: ?>
            <?php $gi = (int)$gradeKey; ?>
            Grade <?= $gi === 0 ? 'K' : $gi ?><?= $gi > 5 ? ' (Older Siblings)' : '' ?>
          <?php endif; ?>
        </h3>
        <table class="list">
          <thead>
            <tr>
              <th>Youth Name</th>
              <th>Adult(s)</th>
              <th>Grade</th>
              <th>BSA Membership ID</th>
              <th>Expiration Date</th>
              <th>Paid Until Date</th>
              <th>Pending Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($list as $y): ?>
            <tr>
              <td>
                <?php
                  $fullName = trim((string)($y['first_name'] ?? ''));
                  if (!empty($y['preferred_name'])) { $fullName .= ' ("'.(string)$y['preferred_name'].'")'; }
                  $fullName .= ' '.(string)($y['last_name'] ?? '');
                  if (!empty($y['suffix'])) { $fullName .= ', '.(string)$y['suffix']; }
                  echo hq(trim($fullName));
                ?>
              </td>
              <td>
                <?php
                  $plist = $parentsByYouth[(int)$y['id']] ?? [];
                  $parentStrs = [];
                  $isAdminView = !empty($me['is_admin']);
                  // Order parents so that those with leadership positions appear first (stable within groups)
                  if (is_array($plist) && count($plist) > 1) {
                    $withPos = [];
                    $withoutPos = [];
                    foreach ($plist as $pp) {
                      $posStrTmp = trim((string)($pp['positions'] ?? ''));
                      if ($posStrTmp !== '') { $withPos[] = $pp; } else { $withoutPos[] = $pp; }
                    }
                    $plist = array_merge($withPos, $withoutPos);
                  }
                  foreach ($plist as $p) {
                    $pname = trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? ''));
                    $rawPhone = !empty($p['phone_cell']) ? $p['phone_cell'] : ($p['phone_home'] ?? '');
                    $rawEmail = $p['email'] ?? '';
                    $showPhone = $isAdminView || empty($p['suppress_phone_directory']);
                    $showEmail = $isAdminView || empty($p['suppress_email_directory']);
                    $contact = [];
                    if ($showPhone && !empty($rawPhone)) $contact[] = hq($rawPhone);
                    if ($showEmail && !empty($rawEmail)) $contact[] = hq($rawEmail);
                    $line = hq($pname);
                    // Insert leadership positions (line break), before phone/email
                    $posStr = trim((string)($p['positions'] ?? ''));
                    if ($posStr !== '') {
                      $line .= '<br>' . hq($posStr);
                    }
                    if (!empty($contact)) {
                      $line .= '<br>'.implode(', ', $contact);
                    } else {
                      if ((!empty($rawPhone) || !empty($rawEmail)) && !$isAdminView && (!empty($p['suppress_phone_directory']) || !empty($p['suppress_email_directory']))) {
                        $line .= ' <span class="small">(Hidden by user preference)</span>';
                      }
                    }
                    $parentStrs[] = $line;
                  }
                  echo !empty($parentStrs) ? implode('<br><br>', $parentStrs) : '';
                ?>
              </td>
              <td>
                <?php
                  $gcalc = GradeCalculator::gradeForClassOf((int)$y['class_of']);
                  echo $gcalc === 0 ? 'K' : (int)$gcalc;
                ?>
              </td>
              <td>
                <?php
                  // BSA Membership ID
                  $bsaId = (string)($y['bsa_registration_number'] ?? '');
                  echo $bsaId !== '' ? hq($bsaId) : '<span style="color:#999">&mdash;</span>';
                ?>
              </td>
              <td>
                <?php
                  // Expiration Date with color coding
                  $expiresDate = (string)($y['bsa_registration_expires_date'] ?? '');
                  $expirationStatus = (int)($y['expiration_status'] ?? 0);
                  $today = date('Y-m-d');
                  
                  if ($expiresDate === '') {
                    echo '<span style="color:#999">&mdash;</span>';
                  } else {
                    if ($expirationStatus === 1) {
                      // Expired - red
                      echo '<span style="color:#c00;font-weight:bold;">'.hq($expiresDate).'</span>';
                    } elseif ($expirationStatus === 2) {
                      // Expiring soon - orange
                      echo '<span style="color:#e67e22;font-weight:bold;">'.hq($expiresDate).'</span>';
                    } else {
                      // Current - normal
                      echo '<span>'.hq($expiresDate).'</span>';
                    }
                  }
                ?>
              </td>
              <td>
                <?php
                  // Paid Until Date
                  $paid = (string)($y['date_paid_until'] ?? '');
                  $today = date('Y-m-d');
                  $isCurrent = ($paid !== '' && $paid >= $today);
                  
                  if ($paid === '') {
                    echo '<span style="color:#999">&mdash;</span>';
                  } else {
                    $style = $isCurrent ? '' : ' style="color:#c00"';
                    echo '<span'.$style.'>'.hq($paid).'</span>';
                  }
                ?>
              </td>
              <td>
                <?php
                  // Pending Actions with links
                  $hasPendingPayment = !empty($y['has_pending_payment']);
                  $hasPendingRegistration = !empty($y['has_pending_registration']);
                  $actions = [];
                  
                  if ($hasPendingPayment) {
                    $actions[] = '<a href="/admin/payment_notifications.php" style="color:#e67e22;">pending payment</a>';
                  }
                  if ($hasPendingRegistration) {
                    $actions[] = '<a href="/admin/pending_registrations.php" style="color:#3498db;">pending registration</a>';
                  }
                  
                  if (!empty($actions)) {
                    echo implode('<br>', $actions);
                  } else {
                    echo '<span style="color:#999">&mdash;</span>';
                  }
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
