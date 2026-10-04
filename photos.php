<?php
// Photos index: every event that has photos, newest first, plus the way in to
// slideshows.
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/PhotoStorage.php';
require_once __DIR__.'/lib/EventPhotos.php';
require_once __DIR__.'/lib/EventManagement.php';
require_login();

$me = current_user();
$isAdmin = !empty($me['is_admin']);
$events = EventPhotos::listEventsWithPhotos();
$now = time();

header_html('Photos');
?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
  <h2 style="margin:0">Photos</h2>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a class="button" href="/slideshows.php">Slideshows</a>
    <a class="button primary" href="/events.php">Add photos to an event</a>
  </div>
</div>

<?php if (!PhotoStorage::isConfigured()): ?>
  <p class="announcement">Photo storage is not configured<?php if ($isAdmin): ?>: see <a href="/admin_photo_storage.php">Admin &rarr; Photo Storage</a><?php endif; ?>.</p>
<?php endif; ?>

<div class="card">
  <?php if (empty($events)): ?>
    <p class="small">No event photos yet. Open any event and use <strong>Add photos</strong> to upload from your phone.</p>
  <?php else: ?>
    <div class="event-photo-cards">
      <?php foreach ($events as $ev): ?>
        <?php $cover = $ev['cover'] ?? null; $thumb = $cover ? PhotoStorage::urlFor((string)$cover['thumb_key'], $now) : ''; ?>
        <a class="event-photo-card" href="/event_photos.php?event_id=<?= (int)$ev['id'] ?>">
          <?php if ($thumb !== ''): ?><img src="<?= h($thumb) ?>" alt="" loading="lazy"><?php endif; ?>
          <div class="meta">
            <strong><?= h($ev['name']) ?></strong>
            <span class="small"><?= h(date('M j, Y', strtotime((string)$ev['starts_at']))) ?> &middot; <?= (int)$ev['photo_count'] ?> photo<?= (int)$ev['photo_count'] === 1 ? '' : 's' ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php footer_html(); ?>
