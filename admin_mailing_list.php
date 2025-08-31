<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/GradeCalculator.php';
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

// Base query: include all adults; left join to children for filtering
$params = [];
$sqlBase = "
  FROM users u
  LEFT JOIN parent_relationships pr ON pr.adult_id = u.id
  LEFT JOIN youth y ON y.id = pr.youth_id
  WHERE 1=1
";

// Search across adult name/email and child name
if ($q !== '') {
  $sqlBase .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR y.first_name LIKE ? OR y.last_name LIKE ?)";
  $like = '%'.$q.'%';
  array_push($params, $like, $like, $like, $like, $like);
}
// Grade filter
if ($classOfFilter !== null) {
  $sqlBase .= " AND y.class_of = ?";
  $params[] = $classOfFilter;
}
// Registered filter (by child registration)
if ($registered === 'yes') {
  $sqlBase .= " AND y.bsa_registration_number IS NOT NULL";
} elseif ($registered === 'no') {
  $sqlBase .= " AND (y.id IS NOT NULL AND y.bsa_registration_number IS NULL)";
}

// For listing/export, select distinct adults
$sqlSelectDistinctAdults = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email ".$sqlBase." ORDER BY u.last_name, u.first_name";

 // Fetch adults
$st = pdo()->prepare($sqlSelectDistinctAdults);
$st->execute($params);
$adults = $st->fetchAll();

// Build grade map for on-screen display (not used in CSV export)
$gradesByAdult = [];
if (!empty($adults)) {
  $adultIds = array_column($adults, 'id');
  $placeholders = implode(',', array_fill(0, count($adultIds), '?'));
  // Reuse the same filters as listing, and restrict to these adult ids
  $sqlGrades = "SELECT u.id AS adult_id, y.class_of " . $sqlBase . " AND y.id IS NOT NULL AND u.id IN ($placeholders)";
  $stg = pdo()->prepare($sqlGrades);
  $stg->execute(array_merge($params, $adultIds));
  while ($r = $stg->fetch()) {
    $aid = (int)$r['adult_id'];
    $gradeInt = GradeCalculator::gradeForClassOf((int)$r['class_of']);
    $label = GradeCalculator::gradeLabel($gradeInt);
    if (!isset($gradesByAdult[$aid])) $gradesByAdult[$aid] = [];
    if (!in_array($label, $gradesByAdult[$aid], true)) $gradesByAdult[$aid][] = $label;
  }
  // Sort grades K(0) .. 5 for nicer display
  foreach ($gradesByAdult as $aid => $labels) {
    usort($labels, function($a, $b) {
      $toNum = function($lbl){ return ($lbl === 'K') ? 0 : (int)$lbl; };
      return $toNum($a) <=> $toNum($b);
    });
    $gradesByAdult[$aid] = $labels;
  }
}

// CSV export
if ($export) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="mailing_list.csv"');
  $out = fopen('php://output', 'w');
  // Evite format: name,email (no header usually, but we'll include a simple header)
  fputcsv($out, ['name', 'email']);
  foreach ($adults as $a) {
    if (!empty($a['email'])) {
      $name = trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? ''));
      fputcsv($out, [$name, $a['email']]);
    }
  }
  fclose($out);
  exit;
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
  <?php if (empty($adults)): ?>
    <p class="small">No matching adults.</p>
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
        <?php foreach ($adults as $a): ?>
          <tr>
            <td><?=h(($a['first_name'] ?? '').' '.($a['last_name'] ?? ''))?></td>
            <td><?=h($a['email'] ?? '')?></td>
            <?php $grades = $gradesByAdult[(int)($a['id'] ?? 0)] ?? []; ?>
            <td><?= h(!empty($grades) ? implode(', ', $grades) : '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="small" style="margin-top:8px;"><?= count($adults) ?> adults listed. Only rows with an email will be included in CSV export.</p>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
