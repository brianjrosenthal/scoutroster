<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_once __DIR__.'/lib/YouthManagement.php';
require_login();

$u = current_user();

// Inputs
$q = trim($_GET['q'] ?? '');
$gLabel = trim($_GET['g'] ?? ''); // grade filter: K,0,1..5
$g = $gLabel !== '' ? GradeCalculator::parseGradeLabel($gLabel) : null;

// Compute class_of target for grade filter, if provided
$classOfFilter = null;
if ($g !== null) {
  // class_of = currentFifthClassOf + (5 - grade)
  $currentFifthClassOf = GradeCalculator::schoolYearEndYear();
  $classOfFilter = $currentFifthClassOf + (5 - $g);
}

$ctx = UserContext::getLoggedInUserContext();
$rows = YouthManagement::searchRoster($ctx, $q, $g);

// Group by grade
$byGrade = []; // grade int => list
foreach ($rows as $r) {
  $grade = GradeCalculator::gradeForClassOf((int)$r['class_of']);
  if (!isset($byGrade[$grade])) $byGrade[$grade] = [];
  $byGrade[$grade][] = $r;
}

// Sort grades K..5 (0..5 ascending)
ksort($byGrade);

header_html('Youth Roster');
?>
<h2>Youth Roster</h2>

<div class="card">
  <form method="get" class="stack">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Search
        <input type="text" name="q" value="<?=h($q)?>" placeholder="Name, school">
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
      <a class="button" href="/youth.php">Reset</a>
      <?php if (!empty($u['is_admin'])): ?>
        <a class="button" href="/admin_youth.php">Add Youth</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if (empty($rows)): ?>
  <p class="small">No youth found.</p>
<?php endif; ?>

<?php foreach ($byGrade as $grade => $list): ?>
  <div class="card">
    <h3>Grade <?= $grade === 0 ? 'K' : (int)$grade ?></h3>
    <table class="list">
      <thead>
        <tr>
          <th>Name</th>
          <th>Preferred</th>
          <th>Den</th>
          <th>School</th>
          <th>Registered</th>
          <?php if (!empty($u['is_admin'])): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($list as $y): ?>
          <tr>
            <td><?=h($y['first_name'].' '.$y['last_name'])?></td>
            <td><?=h($y['preferred_name'])?></td>
            <td><?=h($y['den_name'] ?? '')?></td>
            <td><?=h($y['school'])?></td>
            <td><?= !empty($y['bsa_registration_number']) ? '<span class="badge success">Yes</span>' : '' ?></td>
            <?php if (!empty($u['is_admin'])): ?>
              <td class="small"><a class="button" href="/youth_edit.php?id=<?= (int)$y['id'] ?>">Edit</a></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>

<?php footer_html(); ?>
