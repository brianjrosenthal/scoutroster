<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/Files.php';
require_once __DIR__.'/lib/UserManagement.php';
require_once __DIR__.'/lib/EventManagement.php';
require_once __DIR__.'/lib/RSVPManagement.php';
require_login();

$u = current_user();
$isAdmin = !empty($u['is_admin']);

$events = EventManagement::listUpcoming(500);

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
  <p><a class="button" href="/admin_events.php?show=add">Add Event</a></p>
<?php endif; ?>

<?php if (empty($events)): ?>
  <p class="small">No upcoming events.</p>
<?php else: ?>
  <div class="grid">
    <?php foreach ($events as $e): ?>
      <div class="card">
        <h3><a href="/event.php?id=<?= (int)$e['id'] ?>"><?=h($e['name'])?></a></h3>
        <?php $imgUrl = Files::eventPhotoUrl($e['photo_public_file_id'] ?? null); ?>
        <?php if ($imgUrl !== ''): ?>
          <img src="<?= h($imgUrl) ?>" alt="<?= h($e['name']) ?> image" class="event-thumb" width="180">
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
          $summary = RSVPManagement::getRsvpSummaryForUserEvent((int)$e['id'], (int)$u['id']);

          if ($summary):
            $ad = (int)($summary['adult_count'] ?? 0);
            $kids = (int)($summary['youth_count'] ?? 0);
            $guests = (int)($summary['n_guests'] ?? 0);

            // Creator hint if RSVP was created by someone else
            $byText = '';
            $creatorId = (int)($summary['created_by_user_id'] ?? 0);
            if ($creatorId && $creatorId !== (int)$u['id']) {
              $nameBy = UserManagement::getFullName($creatorId);
              if ($nameBy !== null) {
                $byText = ' <span class="small">(by ' . h($nameBy) . ')</span>';
              }
            }
        ?>
            <p>
              You RSVP’d <?= h(ucfirst((string)$summary['answer'])) ?> for
              <?= (int)$ad ?> adult<?= $ad === 1 ? '' : 's' ?> and
              <?= (int)$kids ?> kid<?= $kids === 1 ? '' : 's' ?>
              <?= $guests > 0 ? ', and '.(int)$guests.' other guest'.($guests === 1 ? '' : 's') : '' ?>.
              <?= $byText ?>
            </p>
            <p>
              <a class="button" href="/event.php?id=<?= (int)$e['id'] ?>">View</a>
              <a class="button" href="/event.php?id=<?= (int)$e['id'] ?>&open_rsvp=1">Edit RSVP</a>
            </p>
          <?php else: ?>
            <p>
              <a class="button" href="/event.php?id=<?= (int)$e['id'] ?>">View</a>
              <a class="button primary" href="/event.php?id=<?= (int)$e['id'] ?>&open_rsvp=1">RSVP</a>
            </p>
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
