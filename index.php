<?php
require_once __DIR__.'/partials.php';
require_login();

$announcement = Settings::get('announcement', '');
$siteTitle = Settings::siteTitle();

require_once __DIR__ . '/lib/Reimbursements.php';
require_once __DIR__ . '/lib/GradeCalculator.php';
$ctx = UserContext::getLoggedInUserContext();
$isApprover = Reimbursements::isApprover($ctx);
$pending = [];
if ($isApprover) {
  $pending = Reimbursements::listPendingForApprover(5);
}

header_html('Home');
?>
<?php if (trim($announcement) !== ''): ?>
  <p class="announcement"><?=h($announcement)?></p>
<?php endif; ?>

<div class="card">
  <h2>Welcome to <?=h($siteTitle)?></h2>
  <p class="small">Use the navigation above to view rosters, events, and your profile.</p>
</div>

<?php
  // Register your child section logic:
  // Show if user has at least one K–5 unregistered child AND no registered children at all.
  $me = current_user();
  $showRegisterSection = false;
  try {
    $st = pdo()->prepare("
      SELECT y.id, y.first_name, y.last_name, y.class_of, y.bsa_registration_number
      FROM parent_relationships pr
      JOIN youth y ON y.id = pr.youth_id
      WHERE pr.adult_id = ?
      ORDER BY y.last_name, y.first_name
    ");
    $st->execute([(int)$me['id']]);
    $kids = $st->fetchAll();

    $hasAnyRegistered = false;
    $hasUnregisteredKto5 = false;

    foreach ($kids as $k) {
      $reg = trim((string)($k['bsa_registration_number'] ?? ''));
      if ($reg !== '') {
        $hasAnyRegistered = true;
      } else {
        $classOf = (int)($k['class_of'] ?? 0);
        if ($classOf > 0) {
          $grade = GradeCalculator::gradeForClassOf($classOf);
          if ($grade >= 0 && $grade <= 5) {
            $hasUnregisteredKto5 = true;
          }
        }
      }
    }

    $showRegisterSection = ($hasUnregisteredKto5 && !$hasAnyRegistered);
  } catch (Throwable $e) {
    $showRegisterSection = false; // fail safe: do not show if query fails
  }

  // Resolve leader names
  $cubmasterName = '';
  $committeeChairName = '';
  try {
    $st = pdo()->prepare("SELECT u.first_name, u.last_name
                          FROM adult_leadership_positions alp
                          JOIN users u ON u.id = alp.adult_id
                          WHERE LOWER(alp.position) = 'cubmaster'
                          LIMIT 1");
    $st->execute();
    if ($r = $st->fetch()) {
      $cubmasterName = trim((string)($r['first_name'] ?? '').' '.(string)($r['last_name'] ?? ''));
    }
    $st = pdo()->prepare("SELECT u.first_name, u.last_name
                          FROM adult_leadership_positions alp
                          JOIN users u ON u.id = alp.adult_id
                          WHERE LOWER(alp.position) = 'committee chair'
                          LIMIT 1");
    $st->execute();
    if ($r = $st->fetch()) {
      $committeeChairName = trim((string)($r['first_name'] ?? '').' '.(string)($r['last_name'] ?? ''));
    }
  } catch (Throwable $e) {
    // ignore and use fallbacks
  }
  $cubmasterLabel = $cubmasterName !== '' ? $cubmasterName : 'the Cubmaster';
  $committeeChairLabel = $committeeChairName !== '' ? $committeeChairName : 'the Committee Chair';
?>

<?php if ($showRegisterSection): ?>
<div class="card" style="margin-top:16px;">
  <h3>How to register your child for Cub Scouts</h3>
  <ol>
    <li>
      Fill out this “Youth Application Form” and send it to <?= h($cubmasterLabel) ?> or <?= h($committeeChairLabel) ?>
      <div><a href="https://filestore.scouting.org/filestore/pdf/524-406.pdf" target="_blank" rel="noopener">https://filestore.scouting.org/filestore/pdf/524-406.pdf</a></div>
    </li>
    <li>
      Pay the dues through any payment option here:
      <div><a href="https://www.scarsdalepack440.com/join" target="_blank" rel="noopener">https://www.scarsdalepack440.com/join</a></div>
    </li>
    <li>
      Buy a uniform. Instructions here:
      <div><a href="https://www.scarsdalepack440.com/uniforms" target="_blank" rel="noopener">https://www.scarsdalepack440.com/uniforms</a></div>
    </li>
  </ol>
</div>
<?php endif; ?>

<?php if ($isApprover && !empty($pending)): ?>
<div class="card" style="margin-top:16px;">
  <h3>Pending Reimbursement Requests</h3>
  <table class="list">
    <thead>
      <tr>
        <th>Title</th>
        <th>Submitted By</th>
        <th>Last Modified</th>
        <th>Status</th>
        <th>Latest Note</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($pending as $r): ?>
        <tr>
          <td><a href="/reimbursement_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a></td>
          <td><?= h($r['submitter_name'] ?? '') ?></td>
          <td><?= h($r['last_modified_at']) ?></td>
          <td><?= h($r['status']) ?></td>
          <td class="small" style="max-width:360px; white-space:pre-wrap;"><?= h($r['latest_note']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="actions" style="margin-top:8px;">
    <a class="button" href="/reimbursements.php?all=1">View all reimbursements</a>
  </div>
</div>
<?php endif; ?>

<?php footer_html(); ?>
