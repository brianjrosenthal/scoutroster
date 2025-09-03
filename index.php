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
      SELECT y.id, y.first_name, y.last_name, y.class_of, y.bsa_registration_number, y.photo_path, y.sibling
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

  <?php
    // Build unified family set (children + co-parents)
    $children = is_array($kids ?? null) ? $kids : [];
    $isAdmin = ((int)($me['is_admin'] ?? 0) === 1);

    // Fetch co-parents and include photo_path for avatar
    $coParents = [];
    try {
      $childIds = array_map(function($k){ return (int)($k['id'] ?? 0); }, $children);
      $childIds = array_values(array_filter($childIds));
      if (!empty($childIds)) {
        $ph = implode(',', array_fill(0, count($childIds), '?'));
        $params = $childIds;
        $params[] = (int)($me['id'] ?? 0);
        $sql = "SELECT u.id, u.first_name, u.last_name, u.photo_path, u.bsa_membership_number,
                       GROUP_CONCAT(DISTINCT alp.position ORDER BY alp.position SEPARATOR ', ') AS positions
                FROM users u
                JOIN parent_relationships pr ON pr.adult_id = u.id
                LEFT JOIN adult_leadership_positions alp ON alp.adult_id = u.id
                WHERE pr.youth_id IN ($ph) AND u.id <> ?
                GROUP BY u.id, u.first_name, u.last_name, u.photo_path, u.bsa_membership_number
                ORDER BY u.last_name, u.first_name";
        $st = pdo()->prepare($sql);
        $st->execute($params);
        $coParents = $st->fetchAll();
      }
    } catch (Throwable $e) {
      $coParents = [];
    }

    // Normalize to a single array for rendering
    $family = [];

    foreach ($children as $c) {
      $family[] = [
        'type' => 'child',
        'first_name' => (string)($c['first_name'] ?? ''),
        'last_name' => (string)($c['last_name'] ?? ''),
        'youth_id' => (int)($c['id'] ?? 0),
        'class_of' => (int)($c['class_of'] ?? 0),
        'bsa_registration_number' => trim((string)($c['bsa_registration_number'] ?? '')),
        'photo_path' => trim((string)($c['photo_path'] ?? '')),
        'sibling' => (int)($c['sibling'] ?? 0),
      ];
    }

    foreach ($coParents as $p) {
      $family[] = [
        'type' => 'parent',
        'first_name' => (string)($p['first_name'] ?? ''),
        'last_name' => (string)($p['last_name'] ?? ''),
        'adult_id' => (int)($p['id'] ?? 0),
        'positions' => trim((string)($p['positions'] ?? '')),
        'bsa_membership_number' => trim((string)($p['bsa_membership_number'] ?? '')),
        'photo_path' => trim((string)($p['photo_path'] ?? '')),
      ];
    }
  ?>

  <?php if (empty($family)): ?>
    <p class="small">No family members on file yet. You can manage family from your <a href="/my_profile.php">My Profile</a> page.</p>
  <?php else: ?>
    <div class="family-grid">
      <?php foreach ($family as $m): ?>
        <?php
          $fname = (string)($m['first_name'] ?? '');
          $lname = (string)($m['last_name'] ?? '');
          $name = trim($fname.' '.$lname);
          $initials = strtoupper((string)substr($fname, 0, 1) . (string)substr($lname, 0, 1));
          $badge = ($m['type'] === 'child') ? 'Child' : 'Parent';
          $badgeClass = ($m['type'] === 'child') ? 'child' : 'parent';

          $editUrl = null; $canEdit = false;
          if ($m['type'] === 'child') {
            $editUrl = '/youth_edit.php?id='.(int)($m['youth_id'] ?? 0);
            $canEdit = true;
          } else {
            if ($isAdmin) {
              $editUrl = '/adult_edit.php?id='.(int)($m['adult_id'] ?? 0);
              $canEdit = true;
            }
          }
        ?>
        <div class="person-card">
          <div class="person-header">
            <div class="person-header-left">
              <?php
                $href = ($m['type'] === 'child')
                  ? '/youth_edit.php?id='.(int)($m['youth_id'] ?? 0)
                  : '/adult_edit.php?id='.(int)($m['adult_id'] ?? 0);
                $mPhoto = trim((string)($m['photo_path'] ?? ''));
              ?>
              <a href="<?= h($href) ?>" class="avatar-link" title="Edit">
                <?php if ($mPhoto !== ''): ?>
                  <img class="avatar" src="<?= h($mPhoto) ?>" alt="<?= h($name) ?>">
                <?php else: ?>
                  <div class="avatar avatar-initials" aria-hidden="true"><?= h($initials) ?></div>
                <?php endif; ?>
              </a>
              <div class="person-name"><?= h($name) ?></div>
            </div>
            <span class="badge <?= h($badgeClass) ?>"><?= h($badge) ?></span>
          </div>

          <div class="person-meta">
            <?php if ($m['type'] === 'child'): ?>
              <?php
                $classOf = (int)($m['class_of'] ?? 0);
                $grade = $classOf > 0 ? GradeCalculator::gradeForClassOf($classOf) : null;
                $gradeLabel = ($grade !== null) ? GradeCalculator::gradeLabel($grade) : null;
                $yReg = trim((string)($m['bsa_registration_number'] ?? ''));
              ?>
              <?php if ($gradeLabel !== null): ?><div>Grade <?= h($gradeLabel) ?></div><?php endif; ?>
              <?php if ($yReg !== ''): ?>
                <div>BSA Registration ID: <?= h($yReg) ?></div>
              <?php elseif (!empty($m['sibling'])): ?>
                <div>Sibling</div>
              <?php else: ?>
                <div>BSA Registration ID: unregistered</div>
              <?php endif; ?>
            <?php else: ?>
              <?php $positions = trim((string)($m['positions'] ?? '')); ?>
              <?php if ($positions !== ''): ?><div>Positions: <?= h($positions) ?></div><?php endif; ?>
              <?php $aReg = trim((string)($m['bsa_membership_number'] ?? '')); ?>
              <div>BSA Registration ID: <?= $aReg !== '' ? h($aReg) : 'N/A' ?></div>
            <?php endif; ?>
          </div>

          <?php if ($canEdit && $editUrl): ?>
          <div class="person-actions">
            <a class="small" href="<?= h($editUrl) ?>">Edit</a>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php
  // Upcoming Events (next 2 months, max 5)
  if (!function_exists('renderEventWhen')) {
    function renderEventWhen(string $startsAt, ?string $endsAt): string {
      $s = strtotime($startsAt);
      if ($s === false) return $startsAt;
      $base = date('F j, Y gA', $s);
      if (!$endsAt) return $base;

      $e = strtotime($endsAt);
      if ($e === false) return $base;

      if (date('Y-m-d', $s) === date('Y-m-d', $e)) {
        return $base . ' (ends at ' . date('gA', $e) . ')';
      }
      return $base . ' (ends on ' . date('F j, Y', $e) . ' at ' . date('gA', $e) . ')';
    }
  }
  if (!function_exists('truncatePlain')) {
    function truncatePlain(string $text, int $limit = 200): string {
      $text = trim((string)$text);
      if ($text === '') return '';
      if (function_exists('mb_strlen')) {
        if (mb_strlen($text, 'UTF-8') <= $limit) return $text;
        $slice = mb_substr($text, 0, $limit, 'UTF-8');
      } else {
        if (strlen($text) <= $limit) return $text;
        $slice = substr($text, 0, $limit);
      }
      return rtrim($slice) . '…';
    }
  }

  try {
    $now = date('Y-m-d H:i:s');
    $in2mo = date('Y-m-d H:i:s', strtotime('+2 months'));
    $stUE = pdo()->prepare("SELECT * FROM events WHERE starts_at >= ? AND starts_at < ? ORDER BY starts_at LIMIT 5");
    $stUE->execute([$now, $in2mo]);
    $homeEvents = $stUE->fetchAll();
  } catch (Throwable $e) {
    $homeEvents = [];
  }
?>

<?php if (!empty($homeEvents)): ?>
<div class="card" style="margin-top:16px;">
  <h3>Upcoming Events</h3>
  <div class="grid">
    <?php foreach ($homeEvents as $e): ?>
      <div class="card">
        <h3><a href="/event.php?id=<?= (int)$e['id'] ?>"><?= h($e['name']) ?></a></h3>
        <?php if (!empty($e['photo_path'])): ?>
          <img src="/<?= h($e['photo_path']) ?>" alt="<?= h($e['name']) ?> image" class="event-thumb" width="180">
        <?php endif; ?>
        <p><strong>When:</strong> <?= h(renderEventWhen($e['starts_at'], $e['ends_at'] ?? null)) ?></p>
        <?php
          if (!empty($e['location'])):
            $locAddr = trim((string)($e['location_address'] ?? ''));
            $mapsUrl = trim((string)($e['google_maps_url'] ?? ''));
            $mapHref = $mapsUrl !== '' ? $mapsUrl : ($locAddr !== '' ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($locAddr) : '');
        ?>
          <p><strong>Where:</strong> <?= h($e['location']) ?><?php if ($mapHref !== ''): ?> <a class="small" href="<?= h($mapHref) ?>" target="_blank" rel="noopener">map</a><?php endif; ?></p>
        <?php endif; ?>
        <?php if (!empty($e['description'])): ?>
          <p><?= nl2br(h(truncatePlain((string)$e['description'], 200))) ?></p>
        <?php endif; ?>
        <?php if (!empty($e['max_cub_scouts'])): ?><p class="small"><strong>Max Cub Scouts:</strong> <?= (int)$e['max_cub_scouts'] ?></p><?php endif; ?>

        <?php
          $eviteUrl = trim((string)($e['evite_rsvp_url'] ?? ''));
          if ($eviteUrl !== ''):
        ?>
          <p><a class="button primary" target="_blank" rel="noopener" href="<?= h($eviteUrl) ?>">RSVP TO EVITE</a></p>
        <?php else: ?>
          <?php
            // Show current RSVP summary if user has one; otherwise show RSVP CTA
            $st2 = pdo()->prepare("SELECT id, answer, n_guests, created_by_user_id FROM rsvps WHERE event_id=? AND created_by_user_id=? LIMIT 1");
            $st2->execute([(int)$e['id'], (int)$me['id']]);
            $my = $st2->fetch();

            if (!$my) {
              $st2 = pdo()->prepare("
                SELECT r.id, r.answer, r.n_guests, r.created_by_user_id
                FROM rsvps r
                JOIN rsvp_members rm ON rm.rsvp_id = r.id AND rm.event_id = r.event_id
                WHERE r.event_id = ? AND rm.participant_type='adult' AND rm.adult_id=?
                LIMIT 1
              ");
              $st2->execute([(int)$e['id'], (int)$me['id']]);
              $my = $st2->fetch();
            }

            if ($my):
              $rsvpId = (int)$my['id'];
              $ad = 0; $kids = 0;
              $q = pdo()->prepare("SELECT COUNT(*) AS c FROM rsvp_members WHERE rsvp_id=? AND participant_type='adult'");
              $q->execute([$rsvpId]); $ad = (int)($q->fetch()['c'] ?? 0);
              $q = pdo()->prepare("SELECT COUNT(*) AS c FROM rsvp_members WHERE rsvp_id=? AND participant_type='youth'");
              $q->execute([$rsvpId]); $kids = (int)($q->fetch()['c'] ?? 0);
              $guests = (int)($my['n_guests'] ?? 0);

              $byText = '';
              $creatorId = (int)($my['created_by_user_id'] ?? 0);
              if ($creatorId && $creatorId !== (int)$me['id']) {
                $stc = pdo()->prepare("SELECT first_name, last_name FROM users WHERE id=?");
                $stc->execute([$creatorId]);
                if ($cn = $stc->fetch()) {
                  $byText = ' <span class="small">(by '.h(trim((string)($cn['first_name'] ?? '').' '.(string)($cn['last_name'] ?? ''))).')</span>';
                }
              }
          ?>
            <p class="small">
              You RSVP’d <?= h(ucfirst((string)$my['answer'])) ?> for
              <?= (int)$ad ?> adult<?= $ad === 1 ? '' : 's' ?> and
              <?= (int)$kids ?> kid<?= $kids === 1 ? '' : 's' ?>
              <?= $guests > 0 ? ', and '.(int)$guests.' other guest'.($guests === 1 ? '' : 's') : '' ?>.
              <?= $byText ?>
            </p>
            <p><a class="button" href="/event.php?id=<?= (int)$e['id'] ?>">Edit</a></p>
          <?php else: ?>
            <p><a class="button primary" href="/event.php?id=<?= (int)$e['id'] ?>">RSVP</a></p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

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
