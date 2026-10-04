<?php
// Photo gallery for one event: grid of thumbnails, upload (from a phone's
// photo library or files), lightbox, per-photo caption / slideshow-exclude /
// delete for the uploader or admins, and admin reordering.
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/EventManagement.php';
require_once __DIR__.'/lib/PhotoStorage.php';
require_once __DIR__.'/lib/EventPhotos.php';
require_once __DIR__.'/lib/EventPhotosUI.php';
require_login();

$me = current_user();
$isAdmin = !empty($me['is_admin']);
$ctx = UserContext::getLoggedInUserContext();

$eventId = (int)($_GET['event_id'] ?? 0);
if ($eventId <= 0) { http_response_code(400); exit('Missing event_id'); }
$e = EventManagement::findById($eventId);
if (!$e) { http_response_code(404); exit('Event not found'); }

$configured = PhotoStorage::isConfigured();
$photos = EventPhotos::withUrls(EventPhotos::listForEvent($eventId));
$count = count($photos);
$manualOrder = $isAdmin && EventPhotos::hasManualOrder($eventId);
$acceptExts = '.' . implode(',.', PhotoStorage::extensionsFor('image'));

header_html('Photos: ' . $e['name']);
?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
  <div>
    <h2 style="margin:0">Photos: <?= h($e['name']) ?></h2>
    <p class="small" style="margin:4px 0 0"><?= h(EventManagement::getWhenText($e)) ?> &middot; <span id="photoCount"><?= $count ?></span> photo<span id="photoCountS"><?= $count === 1 ? '' : 's' ?></span></p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <a class="button" href="/event.php?id=<?= $eventId ?>">Back to event</a>
    <?php if ($isAdmin && $count > 1): ?>
      <button type="button" class="button" id="reorderBtn">Reorder photos</button>
      <button type="button" class="button primary hidden" id="saveOrderBtn">Save order</button>
      <button type="button" class="button hidden" id="cancelOrderBtn">Cancel</button>
      <?php if ($manualOrder): ?>
        <button type="button" class="button" id="resetOrderBtn" title="Clear the manual order and sort by the date each photo was taken">Reset to chronological</button>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card" id="upload">
  <?php if (!$configured): ?>
    <h3>Add photos</h3>
    <p class="small">Photo storage is not configured<?php if ($isAdmin): ?>: see <a href="/admin_photo_storage.php">Admin &rarr; Photo Storage</a><?php endif; ?>.</p>
  <?php else: ?>
    <form id="photoUploader" class="stack" onsubmit="return false;"
          data-event-id="<?= $eventId ?>"
          data-presign-url="/event_photo_presign.php"
          data-attach-url="/event_photo_attach.php"
          data-max-bytes="<?= (int)PhotoStorage::maxBytes('image') ?>"
          data-display-edge="<?= (int)PhotoStorage::DISPLAY_LONG_EDGE ?>"
          data-thumb-edge="<?= (int)PhotoStorage::THUMB_LONG_EDGE ?>">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <div class="dropzone" data-role="dropzone">
        <h3 style="margin:0 0 6px">Add photos</h3>
        <p class="small" style="margin:0 0 10px">Choose photos from your phone's library or drop files here. JPEG, PNG, WebP or GIF up to <?= h(PhotoStorage::humanBytes(PhotoStorage::maxBytes('image'))) ?> each. Photos are visible to logged-in pack members.</p>
        <label class="button primary" style="cursor:pointer">
          Choose photos
          <input type="file" name="photos" accept="image/*,<?= h($acceptExts) ?>" multiple style="display:none" data-role="file">
        </label>
      </div>
      <ul class="upload-queue" id="uploadQueue"></ul>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <?php if ($count === 0): ?>
    <p class="small" id="emptyNote">No photos yet.</p>
  <?php endif; ?>
  <div id="photoGrid" class="photo-grid"
       data-event-id="<?= $eventId ?>"
       data-csrf="<?= h(csrf_token()) ?>"
       data-update-url="/event_photo_update.php"
       data-delete-url="/event_photo_delete.php"
       data-reorder-url="/event_photo_reorder.php"
       data-is-admin="<?= $isAdmin ? '1' : '0' ?>">
    <?= EventPhotosUI::renderGrid($photos, $ctx) ?>
  </div>
</div>

<!-- Lightbox -->
<div id="photoLightbox" class="modal lightbox hidden" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content">
    <button class="close" type="button" id="lightboxClose" aria-label="Close">&times;</button>
    <button class="lightbox-nav prev" type="button" id="lightboxPrev" aria-label="Previous">&#8249;</button>
    <button class="lightbox-nav next" type="button" id="lightboxNext" aria-label="Next">&#8250;</button>
    <img class="lightbox-img" id="lightboxImg" src="" alt="">
    <div class="lightbox-bar">
      <div id="lightboxMeta" class="small" style="color:#ccc"></div>
      <div id="lightboxCaptionView"></div>
      <form class="caption-form hidden" id="lightboxCaptionForm" onsubmit="return false;">
        <input type="text" id="lightboxCaptionInput" maxlength="<?= (int)EventPhotos::CAPTION_MAX ?>" placeholder="Add a caption">
        <button type="submit" class="button primary">Save</button>
      </form>
      <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <button type="button" class="button hidden" id="lightboxToggle"></button>
        <button type="button" class="button hidden" id="lightboxDelete" style="background:#b91c1c">Delete</button>
        <a class="button" id="lightboxOriginal" href="#" target="_blank" rel="noopener">Open original</a>
      </div>
    </div>
  </div>
</div>

<?php $jsVer = @filemtime(__DIR__ . '/photos.js') ?: date('Ymd'); ?>
<script src="/photos.js?v=<?= h((string)$jsVer) ?>"></script>
<?php footer_html(); ?>
