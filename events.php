<?php
require_once __DIR__.'/partials.php';
require_login();

$u = current_user();
$isAdmin = !empty($u['is_admin']);

$now = date('Y-m-d H:i:s');
$st = pdo()->prepare("SELECT * FROM events WHERE starts_at >= ? ORDER BY starts_at");
$st->execute([$now]);
$events = $st->fetchAll();

// Helper to render start/end per spec
function renderEventWhen(string $startsAt, ?string $endsAt): string {
  $s = strtotime($startsAt);
  if ($s === false) return $startsAt;
  $base = date('F j, Y gA', $s);
  if (!$endsAt) return $base;

  $e = strtotime($endsAt);
  if ($e === false) return $base;

  if (date('Y-m-d', $s) === date('Y-m-d', $e)) {
    // Same-day end time
    return $base . ' (ends at ' . date('gA', $e) . ')';
  }
  // Different-day end time
  return $base . ' (ends on ' . date('F j, Y', $e) . ' at ' . date('gA', $e) . ')';
}

/**
 * Truncate plain text to a maximum length with an ellipsis.
 */
function truncatePlain(string $text, int $limit = 200): string {
  $text = trim((string)$text);
  if ($text === '') return '';
  $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
  if ($len <= $limit) return $text;
  $slice = function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
  return rtrim($slice) . '…';
}

header_html('Upcoming Events');
?>
<h2>Upcoming Events</h2>

<?php if ($isAdmin): ?>
  <p><a class="button" href="/admin_events.php">Add Event</a></p>
<?php endif; ?>

<?php if (empty($events)): ?>
  <p class="small">No upcoming events.</p>
<?php else: ?>
  <div class="grid">
    <?php foreach ($events as $e): ?>
      <div class="card">
        <h3><a href="/event.php?id=<?= (int)$e['id'] ?>"><?=h($e['name'])?></a></h3>
        <?php if (!empty($e['photo_path'])): ?>
          <img src="/<?= h($e['photo_path']) ?>" alt="<?= h($e['name']) ?> image" class="event-thumb" width="180">
        <?php endif; ?>
        <p><strong>When:</strong> <?= h(renderEventWhen($e['starts_at'], $e['ends_at'] ?? null)) ?></p>
        <?php if (!empty($e['location'])): ?><p><strong>Where:</strong> <?=h($e['location'])?></p><?php endif; ?>
        <?php if (!empty($e['description'])): ?>
          <p><?= nl2br(h(truncatePlain($e['description'], 200))) ?></p>
        <?php endif; ?>
        <?php if (!empty($e['max_cub_scouts'])): ?><p class="small"><strong>Max Cub Scouts:</strong> <?= (int)$e['max_cub_scouts'] ?></p><?php endif; ?>
        <?php
          $eviteUrl = trim((string)($e['evite_rsvp_url'] ?? ''));
          if ($eviteUrl !== ''):
        ?>
          <p><a class="button primary" target="_blank" rel="noopener" href="<?= h($eviteUrl) ?>">RSVP TO EVITE</a></p>
        <?php else: ?>
        <?php
          // Show current RSVP summary if user has one (membership-based); otherwise show RSVP CTA
          $st2 = pdo()->prepare("SELECT id, answer, n_guests, created_by_user_id FROM rsvps WHERE event_id=? AND created_by_user_id=? LIMIT 1");
          $st2->execute([(int)$e['id'], (int)$u['id']]);
          $my = $st2->fetch();

          if (!$my) {
            $st2 = pdo()->prepare("
              SELECT r.id, r.answer, r.n_guests, r.created_by_user_id
              FROM rsvps r
              JOIN rsvp_members rm ON rm.rsvp_id = r.id AND rm.event_id = r.event_id
              WHERE r.event_id = ? AND rm.participant_type='adult' AND rm.adult_id=?
              LIMIT 1
            ");
            $st2->execute([(int)$e['id'], (int)$u['id']]);
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

            // Creator hint if RSVP was created by someone else
            $byText = '';
            $creatorId = (int)($my['created_by_user_id'] ?? 0);
            if ($creatorId && $creatorId !== (int)$u['id']) {
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
<?php endif; ?>
<?php
  $gcal = Settings::get('google_calendar_url', '');
  if ($gcal !== ''):
?>
<div class="card">
  <p>
   Subscribe to the Google Calendar
    <a href="<?= h($gcal) ?>" target="_blank" rel="noopener">here</a>
  </p>
</div>
<?php endif; ?>


<?php footer_html(); ?>
