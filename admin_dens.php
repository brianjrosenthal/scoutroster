<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_admin();

$msg = null;
$err = null;

// Handle create/delete actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $action = $_POST['action'] ?? '';

  if ($action === 'create') {
    $denName = trim($_POST['den_name'] ?? '');
    $gradeLabel = trim($_POST['grade'] ?? ''); // K,0..5
    $g = GradeCalculator::parseGradeLabel($gradeLabel);
    $errors = [];
    if ($denName === '') $errors[] = 'Den name is required.';
    if ($g === null) $errors[] = 'Grade is required.';
    if (empty($errors)) {
      $currentFifthClassOf = GradeCalculator::schoolYearEndYear();
      $class_of = $currentFifthClassOf + (5 - (int)$g);
      try {
        $st = pdo()->prepare("INSERT INTO dens (den_name, class_of) VALUES (?, ?)");
        $st->execute([$denName, $class_of]);
        $msg = 'Den created.';
      } catch (Throwable $e) {
        $err = 'Failed to create den (may already exist for this grade).';
      }
    } else {
      $err = implode(' ', $errors);
    }
  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      try {
        $st = pdo()->prepare("DELETE FROM dens WHERE id=?");
        $st->execute([$id]);
        $msg = 'Den deleted.';
      } catch (Throwable $e) {
        $err = 'Failed to delete den.';
      }
    }
  }
}

// Query dens with member counts
$dens = [];
try {
  $st = pdo()->query("
    SELECT d.*,
           (SELECT COUNT(*) FROM den_memberships dm WHERE dm.den_id = d.id) AS member_count
    FROM dens d
    ORDER BY d.class_of, d.den_name
  ");
  $dens = $st->fetchAll();
} catch (Throwable $e) {
  $dens = [];
}

header_html('Dens');
?>
<h2>Dens</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <h3>Create Den</h3>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="create">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Den Name
        <input type="text" name="den_name" placeholder="e.g., Lions, Tigers, Wolves" required>
      </label>
      <label>Grade
        <select name="grade" required>
          <?php for($i=0;$i<=5;$i++): $lbl = \GradeCalculator::gradeLabel($i); ?>
            <option value="<?=h($lbl)?>"><?= $i === 0 ? 'K' : $i ?></option>
          <?php endfor; ?>
        </select>
        <small class="small">class_of will be computed based on current school year.</small>
      </label>
    </div>
    <div class="actions">
      <button class="primary">Create Den</button>
    </div>
  </form>
</div>

<div class="card">
  <h3>Existing Dens</h3>
  <?php if (empty($dens)): ?>
    <p class="small">No dens yet.</p>
  <?php else: ?>
    <table class="list">
      <thead>
        <tr>
          <th>Den</th>
          <th>Grade</th>
          <th>Class Of</th>
          <th>Members</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dens as $d): 
          $grade = \GradeCalculator::gradeForClassOf((int)$d['class_of']);
        ?>
          <tr>
            <td><?=h($d['den_name'])?></td>
            <td><?= $grade === 0 ? 'K' : (int)$grade ?></td>
            <td><?=h($d['class_of'])?></td>
            <td><?= (int)$d['member_count'] ?></td>
            <td class="small">
              <a class="button" href="/den_members.php?den_id=<?= (int)$d['id'] ?>">Manage Members</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this den?');">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="button danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
