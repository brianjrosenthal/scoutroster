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

$me = current_user();
header_html('Home');
?>
<?php if (trim($announcement) !== ''): ?>
  <p class="announcement"><?=h($announcement)?></p>
<?php endif; ?>

<div class="card">
  <h2>Welcome back, <?= h($me['first_name'] ?? '') ?> to <?= h($siteTitle) ?></h2>
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

<div class="card" style="margin-top:16px;">
  <h3>My Family</h3>

  <h4>Children</h4>
  <?php
    // Use the previously fetched $kids, if available
    $children = is_array($kids ?? null) ? $kids : [];
  ?>
  <?php if (empty($children)): ?>
    <p class="small">No children on file. You can add a child from your <a href="/my_profile.php">My Profile</a> page.</p>
  <?php else: ?>
    <ul>
      <?php foreach ($children as $c): ?>
        <?php
          $fullName = trim((string)($c['first_name'] ?? '').' '.(string)($c['last_name'] ?? ''));
          $classOf = (int)($c['class_of'] ?? 0);
          $grade = $classOf > 0 ? GradeCalculator::gradeForClassOf($classOf) : null;
          $gradeLabel = $grade !== null ? GradeCalculator::gradeLabel($grade) : '';
          $yReg = trim((string)($c['bsa_registration_number'] ?? ''));
        ?>
        <li>
          <strong><?= h($fullName) ?></strong>
          <?php if ($gradeLabel !== ''): ?>
            <span class="small"> — Grade <?= h($gradeLabel) ?></span>
          <?php endif; ?>
          <?php if ($yReg !== ''): ?>
            <div class="small">BSA Registration ID: <?= h($yReg) ?></div>
          <?php else: ?>
            <div class="small">BSA Registration ID: unregistered</div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h4 style="margin-top:12px;">Co-Parents</h4>
  <?php
    $coParents = [];
    try {
      $childIds = array_map(function($k){ return (int)($k['id'] ?? 0); }, $children);
      $childIds = array_values(array_filter($childIds));
      if (!empty($childIds)) {
        $ph = implode(',', array_fill(0, count($childIds), '?'));
        $params = $childIds;
        $params[] = (int)($me['id'] ?? 0);
        $sql = "SELECT u.id, u.first_name, u.last_name, u.bsa_membership_number,
                       GROUP_CONCAT(DISTINCT alp.position ORDER BY alp.position SEPARATOR ', ') AS positions
                FROM users u
                JOIN parent_relationships pr ON pr.adult_id = u.id
                LEFT JOIN adult_leadership_positions alp ON alp.adult_id = u.id
                WHERE pr.youth_id IN ($ph) AND u.id <> ?
                GROUP BY u.id, u.first_name, u.last_name, u.bsa_membership_number
                ORDER BY u.last_name, u.first_name";
        $st = pdo()->prepare($sql);
        $st->execute($params);
        $coParents = $st->fetchAll();
      }
    } catch (Throwable $e) {
      $coParents = [];
    }
  ?>
  <?php if (empty($coParents)): ?>
    <p class="small">No co-parents on file.</p>
  <?php else: ?>
    <ul>
      <?php foreach ($coParents as $p): ?>
        <?php
          $pName = trim((string)($p['first_name'] ?? '').' '.(string)($p['last_name'] ?? ''));
          $positions = trim((string)($p['positions'] ?? ''));
          $aReg = trim((string)($p['bsa_membership_number'] ?? ''));
        ?>
        <li>
          <strong><?= h($pName) ?></strong>
          <?php if ($positions !== ''): ?>
            <div class="small">Positions: <?= h($positions) ?></div>
          <?php endif; ?>
          <?php if ($aReg !== ''): ?>
            <div class="small">BSA Registration ID: <?= h($aReg) ?></div>
          <?php else: ?>
            <div class="small">BSA Registration ID: N/A</div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

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
