<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/Files.php';
require_once __DIR__.'/lib/UserManagement.php';
require_once __DIR__.'/lib/EventManagement.php';
require_once __DIR__.'/lib/RSVPManagement.php';
require_login();

$u = current_user();
$isAdmin = !empty($u['is_admin']);

/**
 * Load events by view:
 * - upcoming (default): starts_at >= NOW(), ordered ascending (closest first)
 * - past: starts_at < NOW(), ordered descending (most recent first)
 */
$view = $_GET['view'] ?? 'upcoming';
if ($view !== 'past') $view = 'upcoming';

/**
 * Layout: cards (default) or list
 */
$layout = $_GET['layout'] ?? 'cards';
if ($layout !== 'list') $layout = 'cards';

$events = [];
if ($view === 'upcoming') {
  $events = EventManagement::listUpcoming(500);
} else {
  $events = EventManagement::listPast(500);
}

/**
 * Format time smartly: hide :00 minutes, show non-zero minutes
 * Example: 6:00PM becomes 6PM, 6:30PM stays 6:30PM
 */
function formatTime(int $timestamp): string {
  $formatted = date('g:iA', $timestamp);
  // Remove :00 from times on the hour
  return preg_replace('/:00(AM|PM)/', '$1', $formatted);
}

// Helper to render start/end per spec
function renderEventWhen(string $startsAt, ?string $endsAt): string {
  $s = strtotime($startsAt);
  if ($s === false) return $startsAt;
  
  $dateStr = date('F j, Y', $s);
  $startTime = formatTime($s);
  
  if (!$endsAt) {
    return $dateStr . ' ' . $startTime;
  }

  $e = strtotime($endsAt);
  if ($e === false) {
    return $dateStr . ' ' . $startTime;
  }

  $endTime = formatTime($e);
  
  if (date('Y-m-d', $s) === date('Y-m-d', $e)) {
    // Same-day event: show as "Date StartTime - EndTime"
    return $dateStr . ' ' . $startTime . ' - ' . $endTime;
  }
  
  // Different-day event: show full end date
  $endDateStr = date('F j, Y', $e);
  return $dateStr . ' ' . $startTime . ' - ' . $endDateStr . ' ' . $endTime;
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

header_html($view === 'past' ? 'Previous Events' : 'Upcoming Events');
?>
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;"><?= $view === 'past' ? 'Previous Events' : 'Upcoming Events' ?></h2>
  <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <?php if ($layout === 'cards'): ?>
      <a class="button" href="/events.php?view=<?= h($view) ?>&layout=list">List View</a>
    <?php else: ?>
      <a class="button" href="/events.php?view=<?= h($view) ?>&layout=cards">Card View</a>
    <?php endif; ?>
    <?php if ($view === 'past'): ?>
      <a class="button" href="/events.php?layout=<?= h($layout) ?>">View Upcoming</a>
    <?php else: ?>
      <a class="button" href="/events.php?view=past&layout=<?= h($layout) ?>">View Previous</a>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
      <a class="button" href="/admin_event_edit.php">Add Event</a>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($events)): ?>
  <p class="small"><?= $view === 'past' ? 'No previous events.' : 'No upcoming events.' ?></p>
<?php elseif ($layout === 'list'): ?>
  <!-- List View -->
  <div class="card">
    <table class="events-list">
      <thead>
        <tr>
          <th>Event</th>
          <th>Date & Time</th>
          <th>Location</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($events as $e): ?>
          <tr>
            <td><a href="/event.php?id=<?= (int)$e['id'] ?>"><?= h($e['name']) ?></a></td>
            <td><?= h(renderEventWhen($e['starts_at'], $e['ends_at'] ?? null)) ?></td>
            <td><?= h($e['location'] ?? '-') ?></td>
            <td>
              <?php
                $rsvpUrl = trim((string)($e['rsvp_url'] ?? ''));
                if ($rsvpUrl !== ''):
                  $rsvpLabel = trim((string)($e['rsvp_url_label'] ?? ''));
              ?>
                <a href="<?= h($rsvpUrl) ?>" target="_blank" rel="noopener"><?= h($rsvpLabel !== '' ? $rsvpLabel : 'rsvp') ?></a>
              <?php else: ?>
                <?php
                  $summary = RSVPManagement::getRsvpSummaryForUserEvent((int)$e['id'], (int)$u['id']);
                  if ($summary):
                ?>
                  <a href="/event.php?id=<?= (int)$e['id'] ?>">view</a> | <a href="/event.php?id=<?= (int)$e['id'] ?>&open_rsvp=1">edit rsvp</a>
                <?php else: ?>
                  <a href="/event.php?id=<?= (int)$e['id'] ?>">view</a> | <a href="/event.php?id=<?= (int)$e['id'] ?>&open_rsvp=1">rsvp</a>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php else: ?>
  <!-- Card View -->
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
          $rsvpUrl = trim((string)($e['rsvp_url'] ?? ''));
          $rsvpLabel = trim((string)($e['rsvp_url_label'] ?? ''));
          if ($rsvpUrl !== ''):
        ?>
          <p><a class="button primary" target="_blank" rel="noopener" href="<?= h($rsvpUrl) ?>"><?= h($rsvpLabel !== '' ? $rsvpLabel : 'RSVP HERE') ?></a></p>
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
              You RSVP'd <?= h(ucfirst((string)$summary['answer'])) ?> for
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
