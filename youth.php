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
$onlyRegSib = array_key_exists('only_reg_sib', $_GET) ? (!empty($_GET['only_reg_sib'])) : true;
$includeUnreg = !$onlyRegSib;

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

header_html('Youth Roster');
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Youth Roster</h2>
  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
    <a class="button" href="/recommend.php">Recommend a friend!</a>
    <?php if (!empty($u['is_admin'])): ?>
      <a class="button" href="/admin_youth.php">Add Youth</a>
    <?php endif; ?>
  </div>
</div>
<?php if (!empty($msg)): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if (!empty($err)): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form id="filterForm" method="get" class="stack">
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
    <label class="inline">
      <input type="hidden" name="only_reg_sib" value="0">
      <input type="checkbox" name="only_reg_sib" value="1" <?= $onlyRegSib ? 'checked' : '' ?>> Include only registered members and siblings
    </label>
  </form>
  <script>
    (function(){
      var form = document.getElementById('filterForm');
      if (!form) return;
      var q = form.querySelector('input[name="q"]');
      var g = form.querySelector('select[name="g"]');
      var only = form.querySelector('input[type="checkbox"][name="only_reg_sib"]');
      var t;
      function submitNow() {
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
      }
      if (q) {
        q.addEventListener('input', function(){
          if (t) clearTimeout(t);
          t = setTimeout(submitNow, 600);
        });
      }
      if (g) g.addEventListener('change', submitNow);
      if (only) only.addEventListener('change', submitNow);
    })();
  </script>
</div>

<?php if (empty($rows)): ?>
  <p class="small">No youth found.</p>
<?php endif; ?>

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
            <td>
              <?php
                $plist = $parentsByYouth[(int)$y['id']] ?? [];
                $parentStrs = [];
                $isAdminView = !empty($u['is_admin']);
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
                  if ($showPhone && !empty($rawPhone)) $contact[] = h($rawPhone);
                  if ($showEmail && !empty($rawEmail)) $contact[] = h($rawEmail);
                  if ($isAdminView && !empty($p['adult_id'])) {
                    $line = '<a style="text-decoration: none; color: #1a1a1a;" href="/adult_edit.php?id='.(int)$p['adult_id'].'">'.h($pname).'</a>';
                  } else {
                    $line = h($pname);
                  }
                  // Insert leadership positions (line break), before phone/email
                  $posStr = trim((string)($p['positions'] ?? ''));
                  if ($posStr !== '') {
                    $line .= '<br>' . h($posStr);
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
