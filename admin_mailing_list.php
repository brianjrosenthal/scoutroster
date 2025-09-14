<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_once __DIR__.'/lib/Search.php';
require_once __DIR__.'/lib/MailingListManagement.php';
require_admin();

// Inputs
$q = trim($_GET['q'] ?? '');
$gLabel = trim($_GET['g'] ?? ''); // Grade of child: K,0..5
$g = ($gLabel !== '') ? GradeCalculator::parseGradeLabel($gLabel) : null;
$registered = trim($_GET['registered'] ?? 'all'); // 'all' | 'yes' | 'no'
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

// Compute class_of for grade filter
$classOfFilter = null;
if ($g !== null) {
  $currentFifthClassOf = GradeCalculator::schoolYearEndYear();
  $classOfFilter = $currentFifthClassOf + (5 - $g);
}


/** Build merged contacts via domain class */
$filters = [
  'q' => $q,
  'grade_label' => $gLabel,
  'registered' => $registered,
];
$contactsSorted = MailingListManagement::mergedContacts($filters);

 // CSV export (delegated)
if ($export) {
  MailingListManagement::streamCsv($filters);
}

header_html('Mailing List');
?>
<h2>Mailing List</h2>

<div class="card">
  <form method="get" class="stack">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Search
        <input type="text" name="q" value="<?=h($q)?>" placeholder="Adult name, email, or child name">
      </label>
      <label>Grade of child
        <select name="g">
          <option value="">All</option>
          <?php for($i=0;$i<=5;$i++): $lbl = GradeCalculator::gradeLabel($i); ?>
            <option value="<?=h($lbl)?>" <?= ($gLabel === $lbl ? 'selected' : '') ?>>
              <?= $i === 0 ? 'K' : $i ?>
            </option>
          <?php endfor; ?>
        </select>
      </label>
      <label>Registered (child has BSA ID)
        <select name="registered">
          <option value="all" <?= $registered==='all' ? 'selected' : '' ?>>All</option>
          <option value="yes" <?= $registered==='yes' ? 'selected' : '' ?>>Yes</option>
          <option value="no"  <?= $registered==='no'  ? 'selected' : '' ?>>No</option>
        </select>
      </label>
    </div>
    <div class="actions">
      <button class="primary">Filter</button>
      <a class="button" href="/admin_mailing_list.php">Reset</a>
      <a class="button" href="/admin_mailing_list.php?<?=http_build_query(array_merge($_GET, ['export'=>'csv']))?>">Export (Evite CSV)</a>
      <button type="button" class="button" id="copyEmailsBtn">Copy emails</button>
      <span id="copyEmailsStatus" class="small" style="display:none;margin-left:8px;"></span>
    </div>
  </form>
</div>

<script>
(function(){
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
  <?php if (empty($contactsSorted)): ?>
    <p class="small">No matching contacts.</p>
  <?php else: ?>
    <?php
      $emailsMap = [];
      foreach ($contactsSorted as $c) {
        $e = trim((string)($c['email'] ?? ''));
        if ($e !== '') { $emailsMap[$e] = true; }
      }
      $emailList = implode("\n", array_keys($emailsMap));
    ?>
    <textarea id="emailsPayload" readonly style="position:absolute;left:-9999px;top:-9999px;"><?= h($emailList) ?></textarea>
    <table class="list">
      <thead>
        <tr>
          <th>Adult</th>
          <th>Email</th>
          <th>Grade</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($contactsSorted as $c): ?>
          <tr>
            <td><?= h((string)($c['name'] ?? '')) ?></td>
            <td><?= h((string)($c['email'] ?? '')) ?></td>
            <?php $grades = (array)($c['grades'] ?? []); ?>
            <td><?= h(!empty($grades) ? implode(', ', $grades) : '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="small" style="margin-top:8px;"><?= count($contactsSorted) ?> contacts listed.</p>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
