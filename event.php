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

/**
 * Build selectable participants for modal (logged-in editor)
 * - Children of current user
 * - Co-parents (other adults linked to same children) + self
 */
$st = pdo()->prepare("
  SELECT y.*
  FROM parent_relationships pr
  JOIN youth y ON y.id = pr.youth_id
  WHERE pr.adult_id = ?
  ORDER BY y.last_name, y.first_name
");
$st->execute([(int)$me['id']]);
$myChildren = $st->fetchAll();

$st = pdo()->prepare("
  SELECT DISTINCT u2.*
  FROM parent_relationships pr1
  JOIN parent_relationships pr2 ON pr1.youth_id = pr2.youth_id
  JOIN users u2 ON u2.id = pr2.adult_id
  WHERE pr1.adult_id = ? AND pr2.adult_id <> ?
  ORDER BY u2.last_name, u2.first_name
");
$st->execute([(int)$me['id'], (int)$me['id']]);
$coParents = $st->fetchAll();

/**
 * Load overall RSVP lists (YES only)
 */
$st = pdo()->prepare("
  SELECT rm.participant_type, rm.youth_id, rm.adult_id,
         y.first_name AS yfn, y.last_name AS yln,
         u.first_name AS afn, u.last_name AS aln
  FROM rsvp_members rm
  JOIN rsvps r ON r.id = rm.rsvp_id AND r.answer = 'yes'
  LEFT JOIN youth y ON y.id = rm.youth_id
  LEFT JOIN users u ON u.id = rm.adult_id
  WHERE rm.event_id = ?
  ORDER BY y.last_name, y.first_name, u.last_name, u.first_name
");
$st->execute([$id]);
$allMembers = $st->fetchAll();

// Public RSVPs (logged-out) - list YES only
$st = pdo()->prepare("SELECT first_name, last_name, total_adults, total_kids, comment FROM rsvps_logged_out WHERE event_id=? AND answer='yes' ORDER BY last_name, first_name, id");
$st->execute([$id]);
$publicRsvps = $st->fetchAll();

// Public YES totals
$st = pdo()->prepare("SELECT COALESCE(SUM(total_adults),0) AS a, COALESCE(SUM(total_kids),0) AS k FROM rsvps_logged_out WHERE event_id=? AND answer='yes'");
$st->execute([$id]);
$_pubYesTotals = $st->fetch();
$pubAdultsYes = (int)($_pubYesTotals['a'] ?? 0);
$pubKidsYes = (int)($_pubYesTotals['k'] ?? 0);

// Public MAYBE totals
$st = pdo()->prepare("SELECT COALESCE(SUM(total_adults),0) AS a, COALESCE(SUM(total_kids),0) AS k FROM rsvps_logged_out WHERE event_id=? AND answer='maybe'");
$st->execute([$id]);
$_pubMaybeTotals = $st->fetch();
$pubAdultsMaybe = (int)($_pubMaybeTotals['a'] ?? 0);
$pubKidsMaybe = (int)($_pubMaybeTotals['k'] ?? 0);

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

// Sum guest count (YES only)
$st = pdo()->prepare("SELECT COALESCE(SUM(n_guests),0) AS g FROM rsvps WHERE event_id=? AND answer='yes'");
$st->execute([$id]);
$guestsTotal = (int)($st->fetch()['g'] ?? 0);

// Counts (YES)
$youthCount = count($youthNames);
$adultCount = count($adultEntries);
$adultCountCombined = $adultCount + $pubAdultsYes;
$youthCountCombined = $youthCount + $pubKidsYes;

// MAYBE totals (adults, youth, guests)
$st = pdo()->prepare("
  SELECT COUNT(DISTINCT CASE WHEN rm.participant_type='adult' THEN rm.adult_id END) AS a,
         COUNT(DISTINCT CASE WHEN rm.participant_type='youth' THEN rm.youth_id END) AS y
  FROM rsvp_members rm
  JOIN rsvps r ON r.id = rm.rsvp_id
  WHERE rm.event_id = ? AND r.answer = 'maybe'
");
$st->execute([$id]);
$_maybeIn = $st->fetch();
$maybeAdultsIn = (int)($_maybeIn['a'] ?? 0);
$maybeYouthIn  = (int)($_maybeIn['y'] ?? 0);

$maybeAdultsTotal = $maybeAdultsIn + $pubAdultsMaybe;
$maybeYouthTotal  = $maybeYouthIn + $pubKidsMaybe;

$st = pdo()->prepare("SELECT COALESCE(SUM(n_guests),0) AS g FROM rsvps WHERE event_id=? AND answer='maybe'");
$st->execute([$id]);
$maybeGuestsTotal = (int)($st->fetch()['g'] ?? 0);

header_html('Event');
?>
<h2><?=h($e['name'])?></h2>

<?php if ($flashSaved): ?>
  <p class="flash">Your RSVP has been saved.</p>
<?php endif; ?>

<?php
$myAnswer = strtolower((string)($myRsvp['answer'] ?? 'yes'));
if (!in_array($myAnswer, ['yes','maybe','no'], true)) $myAnswer = 'yes';
?>
<div class="card">
  <?php if ($myRsvp): ?>
    <p>
      You RSVP’d <?= h(ucfirst($myAnswer)) ?><?= !empty($mySummaryParts) ? ' with '.h(implode(', ', $mySummaryParts)) : '' ?>
      <?= $myGuestsCount > 0 ? ' and '.(int)$myGuestsCount.' guest'.($myGuestsCount === 1 ? '' : 's') : '' ?>.
      <a class="button" id="rsvpEditBtn">Edit</a>
    </p>
  <?php else: ?>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <strong>RSVP:</strong>
      <button class="primary" id="rsvpYesBtn">Yes</button>
      <button id="rsvpMaybeBtn" class="primary">Maybe</button>
      <button id="rsvpNoBtn">No</button>
    </div>
  <?php endif; ?>
</div>

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
      <?php endif; ?>
      <a class="button" href="/admin_event_invite.php?event_id=<?= (int)$e['id'] ?>">Invite</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h3>RSVPs</h3>
  <p class="small">
    Adults: <?= (int)$adultCountCombined ?> &nbsp;&nbsp; | &nbsp;&nbsp;
    Cub Scouts: <?= (int)$youthCountCombined ?><?= !empty($e['max_cub_scouts']) ? ' / '.(int)$e['max_cub_scouts'] : '' ?> &nbsp;&nbsp; | &nbsp;&nbsp;
    Other Guests: <?= (int)$guestsTotal ?>
    <?php if ($maybeAdultsTotal + $maybeYouthTotal + $maybeGuestsTotal > 0): ?>
      &nbsp;&nbsp; | &nbsp;&nbsp; <em>(<?= (int)$maybeAdultsTotal ?> adults, <?= (int)$maybeYouthTotal ?> cub scouts, and <?= (int)$maybeGuestsTotal ?> other guests RSVP’d maybe)</em>
    <?php endif; ?>
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

<!-- RSVP modal (posts to rsvp_edit.php) -->
<div id="rsvpModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content">
    <button class="close" type="button" id="rsvpModalClose" aria-label="Close">&times;</button>
    <h3>RSVP <strong id="rsvpAnswerHeading"><?= h(strtoupper($myAnswer)) ?></strong> to <?= h($e['name']) ?></h3>
    <form method="post" class="stack" action="/rsvp_edit.php">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
      <input type="hidden" name="answer" id="rsvpAnswerInput" value="<?= h($myAnswer) ?>">

      <h4>Adults</h4>
      <label class="inline"><input type="checkbox" name="adults[]" value="<?= (int)$me['id'] ?>"
        <?= $myRsvp ? (in_array((int)$me['id'], array_map(fn($row)=> (int)$row['id'], [['id'=>$me['id']]]), true) ? '' : '') : '' ?>
      > You (<?= h($me['first_name'].' '.$me['last_name']) ?>)</label>
      <?php foreach ($coParents as $a): ?>
        <?php $aid = (int)$a['id']; $an = trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')); ?>
        <label class="inline"><input type="checkbox" name="adults[]" value="<?= $aid ?>"
          <?php
            // preselect if present in my RSVP
            $selAdults = [];
            if ($myRsvp) {
              $st = pdo()->prepare("SELECT adult_id FROM rsvp_members WHERE rsvp_id=? AND participant_type='adult'");
              $st->execute([(int)$myRsvp['id']]);
              foreach ($st->fetchAll() as $ra) { if (!empty($ra['adult_id'])) $selAdults[] = (int)$ra['adult_id']; }
            }
            echo in_array($aid, $selAdults, true) ? 'checked' : '';
          ?>
        > <?= h($an) ?></label>
      <?php endforeach; ?>

      <h4>Children</h4>
      <?php
        $selYouth = [];
        if ($myRsvp) {
          $st = pdo()->prepare("SELECT youth_id FROM rsvp_members WHERE rsvp_id=? AND participant_type='youth'");
          $st->execute([(int)$myRsvp['id']]);
          foreach ($st->fetchAll() as $ry) { if (!empty($ry['youth_id'])) $selYouth[] = (int)$ry['youth_id']; }
        }
      ?>
      <?php if (empty($myChildren)): ?>
        <p class="small">You have no children on file.</p>
      <?php else: ?>
        <?php foreach ($myChildren as $c): ?>
          <?php $cid = (int)$c['id']; $cn = trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? '')); ?>
          <label class="inline"><input type="checkbox" name="youth[]" value="<?= $cid ?>" <?= in_array($cid, $selYouth, true) ? 'checked' : '' ?>> <?= h($cn) ?></label>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;align-items:start;">
        <label>Number of other guests
          <input type="number" name="n_guests" value="<?= (int)($myRsvp['n_guests'] ?? 0) ?>" min="0">
        </label>
      </div>

      <label>Comments
        <textarea name="comments" rows="3"><?= h((string)($myRsvp['comments'] ?? '')) ?></textarea>
      </label>

      <div class="actions">
        <button class="primary">Save RSVP</button>
      </div>
    </form>
  </div>
</div>

<script>
  (function(){
    const modal = document.getElementById('rsvpModal');
    const closeBtn = document.getElementById('rsvpModalClose');
    const yesBtn = document.getElementById('rsvpYesBtn');
    const maybeBtn = document.getElementById('rsvpMaybeBtn');
    const noBtn = document.getElementById('rsvpNoBtn');
    const editBtn = document.getElementById('rsvpEditBtn');
    const answerInput = document.getElementById('rsvpAnswerInput');
    const heading = document.getElementById('rsvpAnswerHeading');

    const openModal = () => { if (modal) { modal.classList.remove('hidden'); modal.setAttribute('aria-hidden','false'); } };
    const closeModal = () => { if (modal) { modal.classList.add('hidden'); modal.setAttribute('aria-hidden','true'); } };

    if (yesBtn) yesBtn.addEventListener('click', function(e){ e.preventDefault(); if (answerInput) answerInput.value = 'yes'; if (heading) heading.textContent = 'YES'; openModal(); });
    if (maybeBtn) maybeBtn.addEventListener('click', function(e){ e.preventDefault(); if (answerInput) answerInput.value = 'maybe'; if (heading) heading.textContent = 'MAYBE'; openModal(); });
    if (noBtn) noBtn.addEventListener('click', function(e){ e.preventDefault(); if (answerInput) answerInput.value = 'no'; if (heading) heading.textContent = 'NO'; openModal(); });
    if (editBtn) editBtn.addEventListener('click', function(e){
      e.preventDefault();
      if (heading && answerInput) heading.textContent = (answerInput.value || 'yes').toUpperCase();
      openModal();
    });

    if (closeBtn) closeBtn.addEventListener('click', function(){ closeModal(); });
    if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
  })();
</script>

<?php footer_html(); ?>
