<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_once __DIR__.'/lib/Search.php';
require_login();

$me = current_user();
$isAdmin = !empty($me['is_admin']);

// Inputs
$q = trim($_GET['q'] ?? '');
$gLabel = trim($_GET['g'] ?? ''); // grade filter: K,0..5
$g = ($gLabel !== '') ? GradeCalculator::parseGradeLabel($gLabel) : null;
$showAll = $isAdmin ? (!empty($_GET['all'])) : false;

// Compute class_of to filter by child grade (if provided)
$classOfFilter = null;
if ($g !== null) {
  $currentFifthClassOf = GradeCalculator::schoolYearEndYear();
  $classOfFilter = $currentFifthClassOf + (5 - $g);
}


// Query adults who have at least one registered child
$params = [];
$sql = "
  SELECT 
    u.*,
    y.id         AS child_id,
    y.first_name AS child_first_name,
    y.last_name  AS child_last_name,
    y.class_of   AS child_class_of,
    dm.den_id    AS child_den_id,
    d.den_name   AS child_den_name
  FROM users u
  LEFT JOIN parent_relationships pr ON pr.adult_id = u.id
  LEFT JOIN youth y ON y.id = pr.youth_id
  LEFT JOIN den_memberships dm ON dm.youth_id = y.id
  LEFT JOIN dens d ON d.id = dm.den_id
  WHERE 1=1
";

if ($q !== '') {
  $tokens = Search::tokenize($q);
  $sql .= Search::buildAndLikeClause(
    ['u.first_name','u.last_name','u.email','y.first_name','y.last_name'],
    $tokens,
    $params
  );
}
if ($classOfFilter !== null) {
  $sql .= " AND y.class_of = ?";
  $params[] = $classOfFilter;
}
if (!$showAll) {
  $sql .= " AND ((u.bsa_membership_number IS NOT NULL AND u.bsa_membership_number <> '') OR (y.bsa_registration_number IS NOT NULL AND y.bsa_registration_number <> ''))";
}

// Order by adult then child
$sql .= " ORDER BY u.last_name, u.first_name, y.last_name, y.first_name";

$st = pdo()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// Group rows by adult
$adults = []; // id => ['adult' => u, 'children' => [...], 'den_ids' => set]
foreach ($rows as $r) {
  $aid = (int)$r['id'];
  if (!isset($adults[$aid])) {
    // Copy adult fields
    $adults[$aid] = [
      'adult' => [
        'id' => $aid,
        'first_name' => $r['first_name'],
        'last_name' => $r['last_name'],
        'email' => $r['email'],
        'email2' => $r['email2'] ?? null,
        'phone_home' => $r['phone_home'] ?? null,
        'phone_cell' => $r['phone_cell'] ?? null,
      'suppress_email_directory' => (int)($r['suppress_email_directory'] ?? 0),
      'suppress_phone_directory' => (int)($r['suppress_phone_directory'] ?? 0),
      ],
      'children' => [],
      'den_ids' => [],
    ];
  }
  if (!empty($r['child_id'])) {
    $childGrade = GradeCalculator::gradeForClassOf((int)$r['child_class_of']);
    $adults[$aid]['children'][] = [
      'id' => (int)$r['child_id'],
      'name' => trim(($r['child_first_name'] ?? '').' '.($r['child_last_name'] ?? '')),
      'class_of' => (int)$r['child_class_of'],
      'grade' => $childGrade,
      'den_id' => $r['child_den_id'] ? (int)$r['child_den_id'] : null,
      'den_name' => $r['child_den_name'] ?? null,
    ];
    if (!empty($r['child_den_id'])) {
      $adults[$aid]['den_ids'][(int)$r['child_den_id']] = true;
    }
  }
}

/**
 * Contact visibility is handled per-field using user suppression preferences.
 */

header_html('Adults Roster');
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Adults Roster</h2>
  <?php if ($isAdmin): ?>
    <a class="button" href="/admin_adults.php">Add Adult</a>
  <?php endif; ?>
</div>

<div class="card">
  <form id="filterForm" method="get" class="stack">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Search
        <input type="text" name="q" value="<?=h($q)?>" placeholder="Adult or child name, email">
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
    </div>
    <?php if ($isAdmin): ?>
      <label class="inline">
        <input type="hidden" name="all" value="0">
        <input type="checkbox" name="all" value="1" <?= $showAll ? 'checked' : '' ?>> Show all adults
      </label>
    <?php endif; ?>
  </form>
  <script>
    (function(){
      var form = document.getElementById('filterForm');
      if (!form) return;
      var q = form.querySelector('input[name="q"]');
      var g = form.querySelector('select[name="g"]');
      // Target the checkbox (not the hidden input) so change triggers auto-submit
      var all = form.querySelector('input[type="checkbox"][name="all"]');
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
      if (all) all.addEventListener('change', submitNow);
    })();
  </script>
  <small class="small">
    <?php if ($isAdmin): ?>
      <?= $showAll ? 'Showing all adults.' : 'Showing adults with a BSA membership or with at least one registered scout child.' ?>
    <?php else: ?>
      Showing adults with a BSA membership or with at least one registered scout child.
    <?php endif; ?>
  </small>
</div>

<?php if (empty($adults)): ?>
  <p class="small">No adults found.</p>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead>
        <tr>
          <th>Adult</th>
          <th>Children (grade, den)</th>
          <th>Email(s)</th>
          <th>Phone(s)</th>
          <?php if ($isAdmin): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($adults as $aid => $A): 
          $adult = $A['adult'];

          // Children summary
          $childLines = [];
          foreach ($A['children'] as $c) {
            $childLines[] = h($c['name']).' ('.($c['grade'] === 0 ? 'K' : (int)$c['grade']).($c['den_name'] ? ', '.h($c['den_name']) : '').')';
          }
          $childrenSummary = implode('; ', $childLines);

          // Contact fields
          $hasEmail = !empty($adult['email']) || !empty($adult['email2']);
          $hideEmail = !$isAdmin && !empty($adult['suppress_email_directory']);
          $emails = [];
          if (!$hideEmail) {
            if (!empty($adult['email']))  $emails[] = h($adult['email']);
            if (!empty($adult['email2'])) $emails[] = h($adult['email2']);
          }

          $hasPhone = !empty($adult['phone_home']) || !empty($adult['phone_cell']);
          $hidePhone = !$isAdmin && !empty($adult['suppress_phone_directory']);
          $phones = [];
          if (!$hidePhone) {
            if (!empty($adult['phone_home'])) $phones[] = 'Home: '.h($adult['phone_home']);
            if (!empty($adult['phone_cell'])) $phones[] = 'Cell: '.h($adult['phone_cell']);
          }

          ?>
          <tr>
            <td><?=h($adult['first_name'].' '.$adult['last_name'])?></td>
            <td class="summary-lines"><?= $childrenSummary ?: '&mdash;' ?></td>
            <td><?= !empty($emails) ? implode('<br>', $emails) : ($hasEmail ? '<span class="small">Hidden by user preference</span>' : '&mdash;') ?></td>
            <td><?= !empty($phones) ? implode('<br>', $phones) : ($hasPhone ? '<span class="small">Hidden by user preference</span>' : '&mdash;') ?></td>
            <?php if ($isAdmin): ?>
              <td class="small"><a class="button" href="/adult_edit.php?id=<?= (int)$aid ?>">Edit</a></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
