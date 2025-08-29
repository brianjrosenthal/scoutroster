<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_admin();

$err = null;
$msg = null;

$denId = isset($_GET['den_id']) ? (int)$_GET['den_id'] : 0;
if ($denId <= 0) { http_response_code(400); exit('Missing den_id'); }

// Load den
$st = pdo()->prepare("SELECT * FROM dens WHERE id=? LIMIT 1");
$st->execute([$denId]);
$den = $st->fetch();
if (!$den) { http_response_code(404); exit('Den not found'); }
$denGrade = GradeCalculator::gradeForClassOf((int)$den['class_of']);

// Handle POST actions add/remove
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $action = $_POST['action'] ?? '';
  if ($action === 'add') {
    $youthId = (int)($_POST['youth_id'] ?? 0);
    if ($youthId > 0) {
      try {
        // Ensure youth exists and class_of matches den class_of
        $st = pdo()->prepare("SELECT id, class_of FROM youth WHERE id=? LIMIT 1");
        $st->execute([$youthId]);
        $y = $st->fetch();
        if (!$y) throw new Exception('Youth not found');
        if ((int)$y['class_of'] !== (int)$den['class_of']) throw new Exception('Youth grade does not match den grade');

        // Upsert membership: unique on youth_id
        // First delete any existing membership
        pdo()->prepare("DELETE FROM den_memberships WHERE youth_id=?")->execute([$youthId]);
        // Insert new
        pdo()->prepare("INSERT INTO den_memberships (youth_id, den_id) VALUES (?,?)")->execute([$youthId, $denId]);

        $msg = 'Member added to den.';
      } catch (Throwable $e) {
        $err = 'Failed to add member: '.$e->getMessage();
      }
    }
  } elseif ($action === 'remove') {
    $youthId = (int)($_POST['youth_id'] ?? 0);
    if ($youthId > 0) {
      try {
        pdo()->prepare("DELETE FROM den_memberships WHERE youth_id=? AND den_id=?")->execute([$youthId, $denId]);
        $msg = 'Member removed.';
      } catch (Throwable $e) {
        $err = 'Failed to remove member.';
      }
    }
  }
}

// Members in the den
$members = [];
$st = pdo()->prepare("
  SELECT y.*
  FROM den_memberships dm
  JOIN youth y ON y.id = dm.youth_id
  WHERE dm.den_id = ?
  ORDER BY y.last_name, y.first_name
");
$st->execute([$denId]);
$members = $st->fetchAll();

// Eligible youth for this den (same class_of) who are not currently members
$eligible = [];
$st = pdo()->prepare("
  SELECT y.*
  FROM youth y
  LEFT JOIN den_memberships dm ON dm.youth_id = y.id
  WHERE y.class_of = ?
    AND (dm.youth_id IS NULL OR dm.den_id <> ?)
  ORDER BY y.last_name, y.first_name
");
$st->execute([(int)$den['class_of'], $denId]);
$eligible = $st->fetchAll();

header_html('Den Members');
?>
<h2>Manage Members - <?=h($den['den_name'])?> (Grade <?= $denGrade === 0 ? 'K' : (int)$denGrade ?>)</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <h3>Current Members</h3>
  <?php if (empty($members)): ?>
    <p class="small">No members in this den yet.</p>
  <?php else: ?>
    <table class="list">
      <thead>
        <tr>
          <th>Name</th>
          <th>Preferred</th>
          <th>School</th>
          <th>Registered</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($members as $m): ?>
          <tr>
            <td><?=h($m['first_name'].' '.$m['last_name'])?></td>
            <td><?=h($m['preferred_name'])?></td>
            <td><?=h($m['school'])?></td>
            <td><?= !empty($m['bsa_registration_number']) ? '<span class="badge success">Yes</span>' : '' ?></td>
            <td class="small">
              <form method="post" style="display:inline" onsubmit="return confirm('Remove from this den?');">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="youth_id" value="<?= (int)$m['id'] ?>">
                <button class="button danger">Remove</button>
              </form>
              <a class="button" href="/youth_edit.php?id=<?= (int)$m['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Add Member</h3>
  <?php if (empty($eligible)): ?>
    <p class="small">No eligible youth found for this den.</p>
  <?php else: ?>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="add">
      <label>Youth
        <select name="youth_id" required>
          <?php foreach ($eligible as $e): ?>
            <option value="<?= (int)$e['id'] ?>">
              <?=h($e['last_name'].', '.$e['first_name'].($e['preferred_name'] ? ' ('.$e['preferred_name'].')' : ''))?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="actions">
        <button class="primary">Add to Den</button>
        <a class="button" href="/admin_dens.php">Back to Dens</a>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
