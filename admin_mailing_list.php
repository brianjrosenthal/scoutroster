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
    </div>
  </form>
</div>

<div class="card">
  <?php if (empty($contactsSorted)): ?>
    <p class="small">No matching contacts.</p>
  <?php else: ?>
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
