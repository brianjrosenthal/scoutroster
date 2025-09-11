<?php
require_once __DIR__.'/partials.php';
require_login();
require_once __DIR__ . '/lib/Volunteers.php';
require_once __DIR__ . '/lib/EventManagement.php';
require_once __DIR__ . '/lib/UserManagement.php';

$me = current_user();
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : (int)($_POST['event_id'] ?? 0);
if ($eventId <= 0) { http_response_code(400); exit('Missing event_id'); }

// Load event
$event = EventManagement::findById($eventId);
if (!$event) { http_response_code(404); exit('Event not found'); }

// Build selectable participants

// My children
$myChildren = UserManagement::listChildrenForAdult((int)$me['id']);
$childIdsAllowed = array_map(fn($r) => (int)$r['id'], $myChildren);
$childIdsAllowedSet = array_flip($childIdsAllowed);

// Other adult parents of my children (co-parents), plus myself
$coParents = [];
if (!empty($childIdsAllowed)) {
  $coParents = UserManagement::listCoParentsForYouthIds((int)$me['id'], $childIdsAllowed);
}
$adultIdsAllowed = [(int)$me['id']];
foreach ($coParents as $cp) { $adultIdsAllowed[] = (int)$cp['id']; }
$adultIdsAllowed = array_values(array_unique($adultIdsAllowed));
$adultIdsAllowedSet = array_flip($adultIdsAllowed);

 // Load existing RSVP group associated with me:
 // 1) Prefer my created group; 2) else any group where I'm included as an adult member
 $st = pdo()->prepare("SELECT * FROM rsvps WHERE event_id=? AND created_by_user_id=? LIMIT 1");
 $st->execute([$eventId, (int)$me['id']]);
 $myRsvp = $st->fetch();
 if (!$myRsvp) {
   $st = pdo()->prepare("
     SELECT r.*
     FROM rsvps r
     JOIN rsvp_members rm ON rm.rsvp_id = r.id AND rm.event_id = r.event_id
     WHERE r.event_id = ? AND rm.participant_type='adult' AND rm.adult_id=?
     LIMIT 1
   ");
   $st->execute([$eventId, (int)$me['id']]);
   $myRsvp = $st->fetch();
 }

$selectedAdults = [];
$selectedYouth = [];
$comments = '';
$nGuests = 0;

// Default answer for hidden field (from existing RSVP or from ?answer= param)
$defaultAnswer = strtolower((string)($myRsvp['answer'] ?? ($_GET['answer'] ?? 'yes')));
if (!in_array($defaultAnswer, ['yes','maybe','no'], true)) { $defaultAnswer = 'yes'; }

if ($myRsvp) {
  $comments = (string)($myRsvp['comments'] ?? '');
  $nGuests = (int)($myRsvp['n_guests'] ?? 0);
  $st = pdo()->prepare("SELECT participant_type, youth_id, adult_id FROM rsvp_members WHERE rsvp_id=? ORDER BY id");
  $st->execute([(int)$myRsvp['id']]);
  foreach ($st->fetchAll() as $rm) {
    if ($rm['participant_type'] === 'adult' && !empty($rm['adult_id'])) $selectedAdults[] = (int)$rm['adult_id'];
    if ($rm['participant_type'] === 'youth' && !empty($rm['youth_id'])) $selectedYouth[] = (int)$rm['youth_id'];
  }
  $selectedAdults = array_values(array_unique($selectedAdults));
  $selectedYouth  = array_values(array_unique($selectedYouth));
}

// Handle POST save
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $adults = $_POST['adults'] ?? [];
  $youths = $_POST['youth'] ?? [];
  $comments = trim($_POST['comments'] ?? '');
  $nGuests = (int)($_POST['n_guests'] ?? 0);
  if ($nGuests < 0) $nGuests = 0;

  // RSVP answer comes from the launching button; keep existing if not provided
  $answer = strtolower(trim((string)($_POST['answer'] ?? '')));
  if (!in_array($answer, ['yes','maybe','no'], true)) {
    $answer = (string)($myRsvp['answer'] ?? 'yes');
  }

  // Normalize to ints
  $adults = array_values(array_unique(array_map('intval', (array)$adults)));
  $youths = array_values(array_unique(array_map('intval', (array)$youths)));

  // Validate allowed sets
  foreach ($adults as $aid) {
    if (!isset($adultIdsAllowedSet[$aid])) { $error = 'Invalid adult selection.'; break; }
  }
  if (!$error) {
    foreach ($youths as $yid) {
      if (!isset($childIdsAllowedSet[$yid])) { $error = 'Invalid youth selection.'; break; }
    }
  }

  // Enforce event youth cap if set
  if (!$error && !empty($event['max_cub_scouts'])) {
    $max = (int)$event['max_cub_scouts'];

    // Current total youth RSVPs for event
    $st = pdo()->prepare("SELECT COUNT(*) AS c FROM rsvp_members WHERE event_id=? AND participant_type='youth'");
    $st->execute([$eventId]);
    $row = $st->fetch();
    $currentYouth = (int)($row['c'] ?? 0);

    // Youth currently in my RSVP (to allow replacing without inflating total)
    $myCurrentYouth = 0;
    if ($myRsvp) {
      $st = pdo()->prepare("SELECT COUNT(*) AS c FROM rsvp_members WHERE rsvp_id=? AND participant_type='youth'");
      $st->execute([(int)$myRsvp['id']]);
      $r = $st->fetch();
      $myCurrentYouth = (int)($r['c'] ?? 0);
    }

    $newTotalYouth = $currentYouth - $myCurrentYouth + count($youths);
    if ($newTotalYouth > $max) {
      $error = 'This event has reached its maximum number of Cub Scouts.';
    }
  }

  if (!$error) {
    try {
      if ($myRsvp) {
        $st = pdo()->prepare("UPDATE rsvps SET comments=?, n_guests=?, answer=? WHERE id=?");
        $st->execute([$comments !== '' ? $comments : null, $nGuests, $answer, (int)$myRsvp['id']]);
        $rsvpId = (int)$myRsvp['id'];
      } else {
        $st = pdo()->prepare("INSERT INTO rsvps (event_id, created_by_user_id, comments, n_guests, answer) VALUES (?,?,?,?,?)");
        $st->execute([$eventId, (int)$me['id'], $comments !== '' ? $comments : null, $nGuests, $answer]);
        $rsvpId = (int)pdo()->lastInsertId();
      }

      // Replace members
      pdo()->prepare("DELETE FROM rsvp_members WHERE rsvp_id=?")->execute([$rsvpId]);

      $ins = pdo()->prepare("INSERT INTO rsvp_members (rsvp_id, event_id, participant_type, youth_id, adult_id) VALUES (?,?,?,?,?)");
      foreach ($adults as $aid) {
        $ins->execute([$rsvpId, $eventId, 'adult', null, $aid]);
      }
      foreach ($youths as $yid) {
        $ins->execute([$rsvpId, $eventId, 'youth', $yid, null]);
      }

      $vol = (strtolower($answer) === 'yes' && Volunteers::openRolesExist($eventId)) ? '&vol=1' : '';
      header('Location: /event.php?id='.$eventId.'&rsvp=1'.$vol); exit;
    } catch (Throwable $e) {
      $error = 'Failed to save RSVP.';
    }
  }

  // On error, keep selections for redisplay
  $selectedAdults = $adults;
  $selectedYouth = $youths;
}

header_html('Edit RSVP');
?>
<h2>Edit RSVP - <?=h($event['name'])?></h2>
<?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

<div class="card">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
    <input type="hidden" name="answer" value="<?= h($defaultAnswer) ?>">

    <h3>Adults</h3>
    <label class="inline"><input type="checkbox" name="adults[]" value="<?= (int)$me['id'] ?>" <?= in_array((int)$me['id'], $selectedAdults, true) ? 'checked' : '' ?>> You (<?=h($me['first_name'].' '.$me['last_name'])?>)</label>
    <?php foreach ($coParents as $a): ?>
      <label class="inline"><input type="checkbox" name="adults[]" value="<?= (int)$a['id'] ?>" <?= in_array((int)$a['id'], $selectedAdults, true) ? 'checked' : '' ?>> <?=h($a['first_name'].' '.$a['last_name'])?></label>
    <?php endforeach; ?>

    <h3>Children</h3>
    <?php if (empty($myChildren)): ?>
      <p class="small">You have no children on file.</p>
    <?php else: ?>
      <?php foreach ($myChildren as $c): ?>
        <label class="inline"><input type="checkbox" name="youth[]" value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $selectedYouth, true) ? 'checked' : '' ?>> <?=h($c['first_name'].' '.$c['last_name'])?></label>
      <?php endforeach; ?>
    <?php endif; ?>

    <h3>Guests</h3>
    <label>Number of other guests
      <input type="number" name="n_guests" value="<?= (int)$nGuests ?>" min="0">
    </label>

    <h3>Comments</h3>
    <label>
      <textarea name="comments" rows="3"><?=h($comments)?></textarea>
    </label>

    <div class="actions">
      <button class="primary">Save RSVP</button>
      <a class="button" href="/event.php?id=<?= (int)$eventId ?>">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
