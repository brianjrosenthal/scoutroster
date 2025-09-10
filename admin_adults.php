<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/UserManagement.php';
require_once __DIR__.'/lib/Search.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_admin();

$me = current_user();

$msg = null;
$err = null;

$q = trim($_GET['q'] ?? '');
$gLabel = trim($_GET['g'] ?? ''); // grade filter: K,0..5
$g = ($gLabel !== '') ? GradeCalculator::parseGradeLabel($gLabel) : null;
$showAll = !array_key_exists('all', $_GET) || !empty($_GET['all']); // Default to showing all when not specified

// Handle POST (invite/create adult/delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $action = $_POST['action'] ?? '';

  if ($action === 'invite') {
    // Invite an existing adult by id (must have an email and be unverified)
    try {
      $aid = (int)($_POST['adult_id'] ?? 0);
      if ($aid <= 0) throw new Exception('Invalid adult');

      // Delegate invite flow to domain layer
      $sent = UserManagement::sendInvite(UserContext::getLoggedInUserContext(), $aid);
      if ($sent) {
        $msg = 'Invitation sent if eligible.';
      } else {
        $err = 'Adult not eligible for invite.';
      }
    } catch (Throwable $e) {
      $err = 'Failed to send invitation.';
    }
  } elseif ($action === 'delete') {
    try {
      $aid = (int)($_POST['adult_id'] ?? 0);
      if ($aid <= 0) throw new Exception('Invalid adult');
      // prevent self-deletion
      if ($aid === (int)($me['id'] ?? 0)) {
        throw new Exception('You cannot delete your own account.');
      }
      $deleted = UserManagement::delete(UserContext::getLoggedInUserContext(), $aid);
      if ($deleted > 0) {
        $msg = 'Adult deleted.';
      } else {
        $err = 'Adult not found.';
      }
    } catch (Throwable $e) {
      // Likely blocked by FK constraints (RSVPs or other references)
      $err = 'Unable to delete adult. Remove RSVP references first.';
    }
  }
}

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

header_html('Manage Adults');
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Manage Adults</h2>
  <a class="button" href="/admin_adult_add.php">Create Adult</a>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

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
    <label class="inline">
      <input type="hidden" name="all" value="0">
      <input type="checkbox" name="all" value="1" <?= $showAll ? 'checked' : '' ?>> Show all adults
    </label>
  </form>
  <script>
    (function(){
      var form = document.getElementById('filterForm');
      if (!form) return;
      var q = form.querySelector('input[name="q"]');
      var g = form.querySelector('select[name="g"]');
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
    <?= $showAll ? 'Showing all adults.' : 'Showing adults with a BSA membership or with at least one registered scout child.' ?>
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
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($adults as $aid => $A): 
          $adult = $A['adult'];

          // Children summary (same as adults.php)
          $childLines = [];
          foreach ($A['children'] as $c) {
            $gradeLabel = ($c['grade'] < 0 ? 'PreK' : ($c['grade'] === 0 ? 'K' : (int)$c['grade']));
            $childLines[] = h($c['name']).' ('.$gradeLabel.')';
          }
          $childrenSummary = implode('<br>', $childLines);

          // Contact fields (admins always see full details)
          $emails = [];
          if (!empty($adult['email']))  $emails[] = h($adult['email']);
          if (!empty($adult['email2'])) $emails[] = h($adult['email2']);

          $phones = [];
          if (!empty($adult['phone_home'])) $phones[] = h($adult['phone_home']);
          if (!empty($adult['phone_cell'])) $phones[] = h($adult['phone_cell']);

          $verified = !empty($adult['email_verified_at']);
          ?>
          <tr>
            <td><?=h($adult['first_name'].' '.$adult['last_name'])?></td>
            <td class="summary-lines"><?= $childrenSummary ?: '&mdash;' ?></td>
            <td><?= !empty($emails) ? implode('<br>', $emails) : '&mdash;' ?></td>
            <td><?= !empty($phones) ? implode('<br>', $phones) : '&mdash;' ?></td>
            <td class="small">
              <a class="button" href="/adult_edit.php?id=<?= (int)$aid ?>">Edit</a>
              <?php if (!empty($adult['email']) && !$verified): ?>
                <span class="small" style="margin-left:6px;">Not verified</span>
              <?php elseif (empty($adult['email'])): ?>
                <span class="small" style="margin-left:6px;">No email</span>
              <?php else: ?>
                <span class="small" style="margin-left:6px;">Verified</span>
              <?php endif; ?>
              <?php if ((int)$aid !== (int)($me['id'] ?? 0)): ?>
                <form method="post" style="display:inline; margin-left:6px;" onsubmit="return confirm('Delete this adult? This cannot be undone.');">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="adult_id" value="<?= (int)$aid ?>">
                  <button class="button danger">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
