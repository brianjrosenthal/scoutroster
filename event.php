<?php
require_once __DIR__.'/partials.php';
require_login();

$me = current_user();
$isAdmin = !empty($me['is_admin']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

// Load event
$st = pdo()->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
$st->execute([$id]);
$e = $st->fetch();
if (!$e) { http_response_code(404); exit('Event not found'); }

// Flash after RSVP save
$flashSaved = !empty($_GET['rsvp']);

// Load my RSVP (if any)
$st = pdo()->prepare("SELECT * FROM rsvps WHERE event_id=? AND created_by_user_id=? LIMIT 1");
$st->execute([$id, (int)$me['id']]);
$myRsvp = $st->fetch();

// Build my RSVP summary
$mySummaryParts = [];
$myGuestsCount = 0;
if ($myRsvp) {
  $myGuestsCount = (int)($myRsvp['n_guests'] ?? 0);
  $st = pdo()->prepare("
    SELECT rm.participant_type, rm.youth_id, rm.adult_id,
           y.first_name AS yfn, y.last_name AS yln,
           u.first_name AS afn, u.last_name AS aln
    FROM rsvp_members rm
    LEFT JOIN youth y ON y.id = rm.youth_id
    LEFT JOIN users u ON u.id = rm.adult_id
    WHERE rm.rsvp_id = ?
    ORDER BY rm.id
  ");
  $st->execute([(int)$myRsvp['id']]);
  foreach ($st->fetchAll() as $row) {
    if ($row['participant_type'] === 'youth' && !empty($row['youth_id'])) {
      $mySummaryParts[] = trim(($row['yfn'] ?? '').' '.($row['yln'] ?? ''));
    } elseif ($row['participant_type'] === 'adult' && !empty($row['adult_id'])) {
      // Hide own name? The UX text says “… with Andrew, Jessica, and 2 guests”
      // We'll include adults other than me by name; 'You' is implicit.
      if ((int)$row['adult_id'] !== (int)$me['id']) {
        $mySummaryParts[] = trim(($row['afn'] ?? '').' '.($row['aln'] ?? ''));
      }
    }
  }
}

// Load overall RSVP lists
$st = pdo()->prepare("
  SELECT rm.participant_type, rm.youth_id, rm.adult_id,
         y.first_name AS yfn, y.last_name AS yln,
         u.first_name AS afn, u.last_name AS aln
  FROM rsvp_members rm
  LEFT JOIN youth y ON y.id = rm.youth_id
  LEFT JOIN users u ON u.id = rm.adult_id
  WHERE rm.event_id = ?
  ORDER BY y.last_name, y.first_name, u.last_name, u.first_name
");
$st->execute([$id]);
$allMembers = $st->fetchAll();

$youthNames = [];
$adultNames = [];
foreach ($allMembers as $row) {
  if ($row['participant_type'] === 'youth' && !empty($row['youth_id'])) {
    $youthNames[] = trim(($row['yln'] ?? '').', '.($row['yfn'] ?? ''));
  } elseif ($row['participant_type'] === 'adult' && !empty($row['adult_id'])) {
    $adultNames[] = trim(($row['aln'] ?? '').', '.($row['afn'] ?? ''));
  }
}
sort($youthNames);
sort($adultNames);

// Sum guest count
$st = pdo()->prepare("SELECT SUM(n_guests) AS g FROM rsvps WHERE event_id=?");
$st->execute([$id]);
$guestsTotal = (int)($st->fetch()['g'] ?? 0);

// Counts
$youthCount = count($youthNames);
$adultCount = count($adultNames);

header_html('Event');
?>
<h2><?=h($e['name'])?></h2>

<?php if ($flashSaved): ?>
  <p class="flash">Your RSVP has been saved.</p>
<?php endif; ?>

<div class="card">
  <p><strong>When:</strong> <?=h(Settings::formatDateTime($e['starts_at']))?><?php if(!empty($e['ends_at'])): ?> &ndash; <?=h(Settings::formatDateTime($e['ends_at']))?><?php endif; ?></p>
  <?php if (!empty($e['location'])): ?><p><strong>Where:</strong> <?=h($e['location'])?></p><?php endif; ?>
  <?php if (!empty($e['description'])): ?><p><?=nl2br(h($e['description']))?></p><?php endif; ?>
  <?php if (!empty($e['max_cub_scouts'])): ?><p class="small"><strong>Max Cub Scouts:</strong> <?= (int)$e['max_cub_scouts'] ?></p><?php endif; ?>
  <div class="actions">
    <a class="button" href="/events.php">Back to Events</a>
    <?php if ($isAdmin): ?>
      <a class="button" href="/admin_events.php?id=<?= (int)$e['id'] ?>">Edit Event</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h3>Your RSVP</h3>
  <?php if ($myRsvp): ?>
    <p>
      You are attending<?= !empty($mySummaryParts) ? ' with '.h(implode(', ', $mySummaryParts)) : '' ?>
      <?= $myGuestsCount > 0 ? ' and '.(int)$myGuestsCount.' guest'.($myGuestsCount === 1 ? '' : 's') : '' ?>.
      <a href="/rsvp_edit.php?event_id=<?= (int)$e['id'] ?>">(edit)</a>
    </p>
  <?php else: ?>
    <p>You have not RSVPed yet. <a class="button" href="/rsvp_edit.php?event_id=<?= (int)$e['id'] ?>">RSVP now</a></p>
  <?php endif; ?>
</div>

<div class="card">
  <h3>RSVPs</h3>
  <p class="small">
    Adults: <?= (int)$adultCount ?> &nbsp;&nbsp; | &nbsp;&nbsp;
    Cub Scouts: <?= (int)$youthCount ?><?= !empty($e['max_cub_scouts']) ? ' / '.(int)$e['max_cub_scouts'] : '' ?> &nbsp;&nbsp; | &nbsp;&nbsp;
    Other Guests: <?= (int)$guestsTotal ?>
  </p>

  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">
    <div>
      <h4>Adults</h4>
      <?php if (empty($adultNames)): ?>
        <p class="small">No adults yet.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($adultNames as $n): ?><li><?=h($n)?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <div>
      <h4>Cub Scouts</h4>
      <?php if (empty($youthNames)): ?>
        <p class="small">No cub scouts yet.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($youthNames as $n): ?><li><?=h($n)?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php footer_html(); ?>
