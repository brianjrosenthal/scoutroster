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
$showAll = $isAdmin ? (isset($_GET['all']) ? (!empty($_GET['all'])) : true) : false;

// Compute class_of to filter by child grade (if provided)
$classOfFilter = null;
if ($g !== null) {
  $currentFifthClassOf = GradeCalculator::schoolYearEndYear();
  $classOfFilter = $currentFifthClassOf + (5 - $g);
}


 // Query adults and any of their children - moved to UserManagement
$filters = [
  'q' => ($q !== '' ? $q : null),
  'class_of' => UserManagement::computeClassOfFromGradeLabel($gLabel),
  'registered_only' => !$showAll,
];
$adultsList = UserManagement::listAdultsWithChildren($filters);

// Re-index by adult id to preserve existing rendering code
$adults = [];
foreach ($adultsList as $entry) {
  $aid = (int)($entry['adult']['id'] ?? 0);
  if ($aid > 0) {
    $adults[$aid] = $entry;
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
    <a class="button" href="/admin_adult_add.php">Add Adult</a>
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
      <?= $showAll ? 'Showing all adults.' : 'Showing adults with a BSA membership or with at least one registered scout child, or with children who have pending registrations, payment notifications, or current dues payments.' ?>
    <?php else: ?>
      Showing adults with a BSA membership or with at least one registered scout child, or with children who have pending registrations, payment notifications, or current dues payments.
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
          <th>Children (grade)</th>
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
            $g = (int)($c['grade'] ?? -999);
            $gLabel = ($g < 0) ? 'Pre K' : ($g === 0 ? 'K' : (string)$g);
            $childLines[] = h($c['name']).' ('.$gLabel.')';
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
            <?php
              $posStr = trim((string)($adult['positions'] ?? ''));
              $nameStr = trim((string)($adult['first_name'] ?? '').' '.(string)($adult['last_name'] ?? ''));
              if ($posStr !== '') { $nameStr .= '('.h($posStr) .')'; }
            ?>
            <td><?= $nameStr ?></td>
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
