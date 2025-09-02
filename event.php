<?php
require_once __DIR__.'/partials.php';
require_login();

require_once __DIR__ . '/lib/Text.php';

$me = current_user();
$isAdmin = !empty($me['is_admin']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

// Load event
$st = pdo()->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
$st->execute([$id]);
$e = $st->fetch();
if (!$e) { http_response_code(404); exit('Event not found'); }
$allowPublic = ((int)($e['allow_non_user_rsvp'] ?? 1) === 1);

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

// Public RSVPs (logged-out)
$st = pdo()->prepare("SELECT first_name, last_name, total_adults, total_kids, comment FROM rsvps_logged_out WHERE event_id=? ORDER BY last_name, first_name, id");
$st->execute([$id]);
$publicRsvps = $st->fetchAll();

$st = pdo()->prepare("SELECT COALESCE(SUM(total_adults),0) AS a, COALESCE(SUM(total_kids),0) AS k FROM rsvps_logged_out WHERE event_id=?");
$st->execute([$id]);
$_pubTotals = $st->fetch();
$pubAdults = (int)($_pubTotals['a'] ?? 0);
$pubKids = (int)($_pubTotals['k'] ?? 0);

$youthNames = [];
$adultEntries = [];
foreach ($allMembers as $row) {
  if ($row['participant_type'] === 'youth' && !empty($row['youth_id'])) {
    $youthNames[] = trim(($row['yln'] ?? '').', '.($row['yfn'] ?? ''));
  } elseif ($row['participant_type'] === 'adult' && !empty($row['adult_id'])) {
    $adultEntries[] = [
      'id' => (int)$row['adult_id'],
      'name' => trim(($row['aln'] ?? '').', '.($row['afn'] ?? ''))
    ];
  }
}
// Map RSVP comments by the adult who created them for this event
$st = pdo()->prepare("SELECT created_by_user_id, comments FROM rsvps WHERE event_id=?");
$st->execute([$id]);
$rsvpCommentsByAdult = [];
foreach ($st->fetchAll() as $rv) {
  $aid = (int)($rv['created_by_user_id'] ?? 0);
  $c = trim((string)($rv['comments'] ?? ''));
  if ($aid > 0 && $c !== '') { $rsvpCommentsByAdult[$aid] = $c; }
}

sort($youthNames);
usort($adultEntries, function($a,$b){ return strcmp($a['name'], $b['name']); });

// Sum guest count
$st = pdo()->prepare("SELECT SUM(n_guests) AS g FROM rsvps WHERE event_id=?");
$st->execute([$id]);
$guestsTotal = (int)($st->fetch()['g'] ?? 0);

// Counts
$youthCount = count($youthNames);
$adultCount = count($adultEntries);
$adultCountCombined = $adultCount + $pubAdults;
$youthCountCombined = $youthCount + $pubKids;

header_html('Event');
?>
<h2><?=h($e['name'])?></h2>

<?php if ($flashSaved): ?>
  <p class="flash">Your RSVP has been saved.</p>
<?php endif; ?>

<div class="card">
  <?php if (!empty($e['photo_path'])): ?>
    <img src="/<?= h($e['photo_path']) ?>" alt="<?= h($e['name']) ?> image" class="event-hero" width="220">
  <?php endif; ?>
  <p><strong>When:</strong> <?=h(Settings::formatDateTime($e['starts_at']))?><?php if(!empty($e['ends_at'])): ?> &ndash; <?=h(Settings::formatDateTime($e['ends_at']))?><?php endif; ?></p>
  <?php
    $locName = trim((string)($e['location'] ?? ''));
    $locAddr = trim((string)($e['location_address'] ?? ''));
    if ($locName !== '' || $locAddr !== ''):
  ?>
    <p><strong>Where:</strong>
      <?php if ($locName !== ''): ?>
        <?= h($locName) ?><?php if ($locAddr !== '') echo '<br>'; ?>
      <?php endif; ?>
      <?php if ($locAddr !== ''): ?>
        <?= nl2br(h($locAddr)) ?>
      <?php endif; ?>
    </p>
  <?php endif; ?>
  <?php if (!empty($e['description'])): ?>
    <div><?= Text::renderMarkup((string)$e['description']) ?></div>
  <?php endif; ?>
  <?php if (!empty($e['max_cub_scouts'])): ?><p class="small"><strong>Max Cub Scouts:</strong> <?= (int)$e['max_cub_scouts'] ?></p><?php endif; ?>
  <div class="actions">
    <a class="button" href="/events.php">Back to Events</a>
    <?php if ($isAdmin): ?>
      <a class="button" href="/admin_events.php?id=<?= (int)$e['id'] ?>">Edit Event</a>
      <?php if ($allowPublic): ?>
        <a class="button" href="/event_public.php?event_id=<?= (int)$e['id'] ?>">Public RSVP Link</a>
      <?php else: ?>
        <span class="small" style="margin-left:8px;opacity:0.75;">Public RSVP disabled</span>
      <?php endif; ?>
      <a class="button" href="/admin_event_invite.php?event_id=<?= (int)$e['id'] ?>">Invite</a>
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
    Adults: <?= (int)$adultCountCombined ?> &nbsp;&nbsp; | &nbsp;&nbsp;
    Cub Scouts: <?= (int)$youthCountCombined ?><?= !empty($e['max_cub_scouts']) ? ' / '.(int)$e['max_cub_scouts'] : '' ?> &nbsp;&nbsp; | &nbsp;&nbsp;
    Other Guests: <?= (int)$guestsTotal ?>
  </p>

  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">
    <div>
      <h4>Adults</h4>
      <?php if (empty($adultEntries)): ?>
        <p class="small">No adults yet.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($adultEntries as $a): ?>
            <li>
              <?= h($a['name']) ?>
              <?php if (!empty($rsvpCommentsByAdult[(int)$a['id']] ?? '')): ?>
                <div class="small" style="font-style:italic;"><?= nl2br(h($rsvpCommentsByAdult[(int)$a['id']])) ?></div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
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
    <div>
      <h4>Public RSVPs</h4>
      <?php if (empty($publicRsvps)): ?>
        <p class="small">No public RSVPs yet.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($publicRsvps as $pr): ?>
            <li>
              <?= h(trim(($pr['last_name'] ?? '').', '.($pr['first_name'] ?? ''))) ?>
              — <?= (int)($pr['total_adults'] ?? 0) ?> adult<?= ((int)($pr['total_adults'] ?? 0) === 1 ? '' : 's') ?>,
              <?= (int)($pr['total_kids'] ?? 0) ?> kid<?= ((int)($pr['total_kids'] ?? 0) === 1 ? '' : 's') ?>
              <?php $pc = trim((string)($pr['comment'] ?? '')); if ($pc !== ''): ?>
                <div class="small" style="font-style:italic;"><?= nl2br(h($pc)) ?></div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php footer_html(); ?>
