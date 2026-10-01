<?php
require_once __DIR__.'/partials.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/EventUIManager.php';
require_once __DIR__ . '/lib/RsvpsLoggedOutManagement.php';
require_once __DIR__ . '/lib/GradeCalculator.php';
require_login();

$me = current_user();
$isAdmin = !empty($me['is_admin']);

// Same access rule as Event Compliance: Cubmaster, Treasurer, or Committee Chair
$hasRequiredRole = false;
if ($isAdmin) {
  try {
    $stPos = pdo()->prepare("SELECT LOWER(alp.name) AS p
                             FROM adult_leadership_position_assignments alpa
                             JOIN adult_leadership_positions alp ON alp.id = alpa.adult_leadership_position_id
                             WHERE alpa.adult_id = ?");
    $stPos->execute([(int)($me['id'] ?? 0)]);
    foreach ($stPos->fetchAll() as $pr) {
      $p = trim((string)($pr['p'] ?? ''));
      if ($p === 'cubmaster' || $p === 'treasurer' || $p === 'committee chair') { $hasRequiredRole = true; break; }
    }
  } catch (Throwable $e) {
    $hasRequiredRole = false;
  }
}
if (!$hasRequiredRole) {
  http_response_code(403);
  exit('Access denied. This page is only available to Cubmaster, Treasurer, or Committee Chair.');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing event id'); }

$e = EventManagement::findById($id);
if (!$e) { http_response_code(404); exit('Event not found'); }

/**
 * Youth who RSVP'd "yes", bucketed by current grade:
 *   Pre-K (all pre-schoolers), K, 1, 2, 3, 4, 5, 6+ (all older kids).
 */
$st = pdo()->prepare("
  SELECT DISTINCT y.id, y.first_name, y.last_name, y.class_of
  FROM rsvps r
  JOIN rsvp_members rm ON rm.rsvp_id = r.id AND rm.event_id = r.event_id
  JOIN youth y ON y.id = rm.youth_id
  WHERE r.event_id = ? AND r.answer = 'yes' AND rm.participant_type = 'youth'
");
$st->execute([$id]);
$youthRows = $st->fetchAll();

$bucketOrder = ['prek', '0', '1', '2', '3', '4', '5', '6plus'];
$bucketLabels = [
  'prek'  => 'Pre-K',
  '0'     => 'Kindergarten',
  '1'     => '1st Grade',
  '2'     => '2nd Grade',
  '3'     => '3rd Grade',
  '4'     => '4th Grade',
  '5'     => '5th Grade',
  '6plus' => '6th Grade and up',
];
$buckets = array_fill_keys($bucketOrder, []);

$now = new DateTime('now', new DateTimeZone(Settings::timezoneId()));
foreach ($youthRows as $y) {
  $grade = GradeCalculator::gradeForClassOf((int)$y['class_of'], clone $now);
  if ($grade < 0) {
    $key = 'prek';
  } elseif ($grade >= 6) {
    $key = '6plus';
  } else {
    $key = (string)$grade;
  }
  $buckets[$key][] = trim((string)$y['first_name'] . ' ' . (string)$y['last_name']);
}
foreach ($buckets as &$names) { sort($names, SORT_NATURAL | SORT_FLAG_CASE); }
unset($names);
$youthTotal = count($youthRows);

// Logged-out (public) RSVPs record a kid count but no grades
$publicYes = RsvpsLoggedOutManagement::totalsByAnswer($id, 'yes');
$publicKids = (int)($publicYes['kids'] ?? 0);

header_html('Grade Breakdown');
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;">Grade Breakdown: <?=h($e['name'])?></h2>
  <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <a class="button" href="/event.php?id=<?= (int)$e['id'] ?>">Back to Event</a>
    <?= EventUIManager::renderAdminMenu((int)$e['id'], 'grades') ?>
  </div>
</div>

<div class="card">
  <h3>Youth Attending by Grade</h3>
  <p class="small">Counts all youth on RSVPs marked "Yes". Grades are computed from each scout's class year as of today.</p>

  <?php if ($youthTotal === 0 && $publicKids === 0): ?>
    <p>No youth have RSVP'd yes for this event yet.</p>
  <?php else: ?>
    <div style="overflow-x: auto;">
      <table class="list" style="margin-top: 12px;">
        <thead>
          <tr style="background-color: #f5f5f5;">
            <th style="text-align: left; white-space: nowrap;">Grade</th>
            <th style="text-align: right; white-space: nowrap;">Count</th>
            <th style="text-align: left;">Names</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bucketOrder as $key): $names = $buckets[$key]; ?>
            <tr>
              <td style="white-space: nowrap;"><strong><?= h($bucketLabels[$key]) ?></strong></td>
              <td style="text-align: right; font-variant-numeric: tabular-nums;"><?= count($names) ?></td>
              <td>
                <?php if (empty($names)): ?>
                  <span class="small" style="font-style: italic;">—</span>
                <?php else: ?>
                  <?= h(implode(', ', $names)) ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($publicKids > 0): ?>
            <tr>
              <td style="white-space: nowrap;"><strong>Unknown</strong> <span class="small">(public RSVPs)</span></td>
              <td style="text-align: right; font-variant-numeric: tabular-nums;"><?= $publicKids ?></td>
              <td><span class="small" style="font-style: italic;">Kids counted on logged-out RSVPs; no names or grades recorded.</span></td>
            </tr>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr style="border-top: 2px solid #ddd;">
            <td><strong>Total youth</strong></td>
            <td style="text-align: right; font-variant-numeric: tabular-nums;"><strong><?= $youthTotal + $publicKids ?></strong></td>
            <td class="small"><?php if ($publicKids > 0): ?><?= $youthTotal ?> with grades + <?= $publicKids ?> from public RSVPs<?php endif; ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<?= EventUIManager::renderAdminModals((int)$e['id']) ?>
<?= EventUIManager::renderAdminMenuScript((int)$e['id']) ?>

<?php footer_html(); ?>
