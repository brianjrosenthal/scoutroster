<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_once __DIR__.'/lib/YouthManagement.php';
require_once __DIR__.'/lib/UserManagement.php';
require_login();

$u = current_user();

$msg = null;
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  if (!empty($u['is_admin'])) {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
      try {
        $yid = (int)($_POST['youth_id'] ?? 0);
        if ($yid <= 0) throw new Exception('Invalid youth id');
        YouthManagement::delete(UserContext::getLoggedInUserContext(), $yid);
        $msg = 'Youth deleted.';
      } catch (Throwable $e) {
        $err = 'Unable to delete youth.';
      }
    }
  } else {
    $err = 'Admins only.';
  }
}

// Inputs
$q = trim($_GET['q'] ?? '');
$gLabel = trim($_GET['g'] ?? ''); // grade filter: K,0,1..5
$g = $gLabel !== '' ? GradeCalculator::parseGradeLabel($gLabel) : null;
$includeUnreg = !empty($_GET['include_unreg']);

// Compute class_of target for grade filter, if provided
$classOfFilter = null;
if ($g !== null) {
  // class_of = currentFifthClassOf + (5 - grade)
  $currentFifthClassOf = GradeCalculator::schoolYearEndYear();
  $classOfFilter = $currentFifthClassOf + (5 - $g);
}

$ctx = UserContext::getLoggedInUserContext();
$rows = YouthManagement::searchRoster($ctx, $q, $g, $includeUnreg);
$youthIds = array_map(static function($r){ return (int)$r['id']; }, $rows);
$parentsByYouth = !empty($youthIds) ? UserManagement::listParentsForYouthIds($ctx, $youthIds) : [];

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
<?php if (!empty($msg)): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if (!empty($err)): ?><p class="error"><?=h($err)?></p><?php endif; ?>

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
    <label class="inline"><input type="checkbox" name="include_unreg" value="1" <?= $includeUnreg ? 'checked' : '' ?>> Include unregistered youth</label>
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
          <th>Adult(s)</th>
          <?php if (!empty($u['is_admin'])): ?><th>Actions</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($list as $y): ?>
          <tr>
            <td>
              <?php
                $fullName = trim(($y['first_name'] ?? ''));
                if (!empty($y['preferred_name'])) { $fullName .= ' ("'.($y['preferred_name']).'")'; }
                $fullName .= ' '.($y['last_name'] ?? '');
                if (!empty($y['suffix'])) { $fullName .= ', '.($y['suffix']); }
                echo h(trim($fullName));
              ?>
            </td>
            <td class="small">
              <?php
                $plist = $parentsByYouth[(int)$y['id']] ?? [];
                $parentStrs = [];
                foreach ($plist as $p) {
                  $pname = trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? ''));
                  $phone = !empty($p['phone_cell']) ? $p['phone_cell'] : ($p['phone_home'] ?? '');
                  $contact = [];
                  if (!empty($phone)) $contact[] = h($phone);
                  if (!empty($p['email'])) $contact[] = h($p['email']);
                  $parentStrs[] = h($pname) . (empty($contact) ? '' : ' ('.implode(', ', $contact).')');
                }
                echo !empty($parentStrs) ? implode('<br>', $parentStrs) : '';
              ?>
            </td>
            <?php if (!empty($u['is_admin'])): ?>
              <td class="small">
                <a class="button" href="/youth_edit.php?id=<?= (int)$y['id'] ?>">Edit</a>
                <form method="post" style="display:inline; margin-left:6px;" onsubmit="return confirm('Delete this youth? This cannot be undone.');">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="youth_id" value="<?= (int)$y['id'] ?>">
                  <button class="button danger">Delete</button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>

<?php if (!empty($u['is_admin'])): ?>
  <div class="card">
    <div class="actions">
      <a class="button" href="/admin_import_upload.php">Bulk Import</a>
    </div>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
