<?php
// Admin: edit one slideshow. Title/description/published, the ordered list of
// event sections, each section's music (pick an existing track or upload a
// new one straight to R2) and timing override. Everything except the music
// upload is a plain form POST.
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/PhotoStorage.php';
require_once __DIR__.'/lib/EventPhotos.php';
require_once __DIR__.'/lib/Slideshows.php';
require_once __DIR__.'/lib/SlideshowTracks.php';
require_admin();

$ctx = UserContext::getLoggedInUserContext();
$id = (int)($_GET['id'] ?? 0);
$show = $id > 0 ? Slideshows::findById($id) : null;
if (!$show) { http_response_code(404); exit('Slideshow not found'); }

$msg = isset($_GET['msg']) ? (string)$_GET['msg'] : null;
$err = isset($_GET['err']) ? (string)$_GET['err'] : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $action = (string)($_POST['action'] ?? '');
  $sectionId = (int)($_POST['section_id'] ?? 0);
  try {
    switch ($action) {
      case 'save_meta':
        Slideshows::update($ctx, $id, ['title' => $_POST['title'] ?? '', 'description' => $_POST['description'] ?? '', 'transition' => (string)($_POST['transition'] ?? Slideshows::DEFAULT_TRANSITION), 'is_published' => !empty($_POST['is_published'])]);
        $msg = 'Saved.';
        break;
      case 'add_section':
        Slideshows::addSection($ctx, $id, (int)($_POST['event_id'] ?? 0));
        $msg = 'Event added.';
        break;
      case 'remove_section':
        Slideshows::removeSection($ctx, $sectionId);
        $msg = 'Section removed.';
        break;
      case 'move_up':
      case 'move_down':
        Slideshows::moveSection($ctx, $sectionId, $action === 'move_up' ? 'up' : 'down');
        break;
      case 'set_track':
        $t = (string)($_POST['track_id'] ?? '');
        Slideshows::setSectionTrack($ctx, $sectionId, $t === '' ? null : (int)$t);
        $msg = 'Music updated.';
        break;
      case 'set_cues':
        $cues = [];
        $tids = (array)($_POST['cue_track_id'] ?? []);
        $starts = (array)($_POST['cue_start'] ?? []);
        foreach ($tids as $i => $tid) {
          if ((string)$tid === '') continue;
          // Admins type 1-based photo numbers; store 0-based indexes.
          $cues[] = ['track_id' => (int)$tid, 'start_index' => max(0, (int)($starts[$i] ?? 1) - 1)];
        }
        Slideshows::setSectionCues($ctx, $sectionId, $cues);
        $msg = 'Music updated.';
        break;
      case 'clear_cues':
        Slideshows::clearSectionCues($ctx, $sectionId);
        $msg = 'Back to a single track.';
        break;
      case 'set_options':
        $spp = trim((string)($_POST['seconds_per_photo'] ?? ''));
        Slideshows::setSectionOptions($ctx, $sectionId, $spp === '' ? null : (float)$spp, (string)($_POST['title_override'] ?? ''));
        $msg = 'Section updated.';
        break;
      case 'delete_slideshow':
        Slideshows::delete($ctx, $id);
        header('Location: /slideshows.php?msg=' . urlencode('Slideshow deleted.'));
        exit;
      default:
        throw new InvalidArgumentException('Unknown action.');
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
  // Redirect after every POST so a reload (e.g. after a music upload) never re-submits it.
  $q = $err !== null ? ['id' => $id, 'err' => $err] : ($msg !== null ? ['id' => $id, 'msg' => $msg] : ['id' => $id]);
  header('Location: /admin_slideshow_edit.php?' . http_build_query($q));
  exit;
}

$sections = Slideshows::listSections($id);
$total = Slideshows::totalSeconds($sections);
$tracks = SlideshowTracks::listAll();
$configured = PhotoStorage::isConfigured();
$inShow = array_map('intval', array_column($sections, 'event_id'));
// Only events that have photos can be added; newest first.
$eventsWithPhotos = EventPhotos::listEventsWithPhotos();
$counts = EventPhotos::countsByEvent(array_column($eventsWithPhotos, 'id'));
$acceptAudio = implode(',', PhotoStorage::allowedContentTypes('audio')) . ',.' . implode(',.', PhotoStorage::extensionsFor('audio'));

function ss_form(int $slideshowId, string $action, int $sectionId, string $label, string $class = '', string $confirm = ''): string {
  return '<form method="post" class="inline"' . ($confirm !== '' ? ' onsubmit="return confirm(' . h(json_encode($confirm)) . ');"' : '') . '>'
    . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
    . '<input type="hidden" name="action" value="' . h($action) . '">'
    . '<input type="hidden" name="section_id" value="' . $sectionId . '">'
    . '<button type="submit" class="button ' . h($class) . '" style="padding:4px 8px;font-size:12px">' . $label . '</button></form>';
}

header_html('Edit Slideshow');
?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
  <h2 style="margin:0">Edit Slideshow</h2>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a class="button primary" href="/slideshow_play.php?id=<?= $id ?>" target="_blank" rel="noopener">Preview</a>
    <a class="button" href="/slideshows.php">All slideshows</a>
  </div>
</div>
<?php if ($msg): ?><p class="flash"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?= h($err) ?></p><?php endif; ?>

<div class="card">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_meta">
    <label>Title <input type="text" name="title" value="<?= h($show['title']) ?>" required maxlength="255"></label>
    <label>Description (optional) <textarea name="description" rows="2"><?= h($show['description'] ?? '') ?></textarea></label>
    <label>Photo transitions
      <select name="transition">
        <?php $curT = Slideshows::transitionOf($show); foreach (Slideshows::TRANSITIONS as $val => $label): ?>
          <option value="<?= h($val) ?>" <?= $val === $curT ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><input type="checkbox" name="is_published" value="1" <?= !empty($show['is_published']) ? 'checked' : '' ?>> Published (visible to all logged-in members; drafts are visible only to admins)</label>
    <div class="actions">
      <button class="button primary" type="submit">Save</button>
      <span class="small">Total runtime about <strong><?= h(Slideshows::formatSeconds($total)) ?></strong> including the closing card.</span>
    </div>
  </form>
</div>

<div class="card">
  <h3>Sections</h3>
  <p class="small">Each section plays one event's photos in the order shown in that event's gallery (chronological unless an admin reordered them), skipping photos marked "Not in slideshow". Photos per second is worked out from the music length: between 3 and 8 seconds each unless you set an override.</p>
  <?php if (!$configured): ?><p class="error">Photo storage is not configured; music cannot be uploaded and the slideshow cannot play. See <a href="/admin_photo_storage.php">Admin &rarr; Photo Storage</a>.</p><?php endif; ?>
  <?php if (empty($sections)): ?>
    <p class="small">No events yet. Add one below.</p>
  <?php else: ?>
    <div style="overflow-x:auto">
    <table class="list" id="sectionsTable">
      <tr><th>#</th><th>Event</th><th>Photos</th><th>Music</th><th>Timing</th><th></th></tr>
      <?php foreach ($sections as $i => $s): $tm = $s['timing']; $n = (int)$s['photo_count']; ?>
        <tr data-section-id="<?= (int)$s['id'] ?>">
          <td><?= $i + 1 ?></td>
          <td>
            <strong><?= h($s['title']) ?></strong><?php if (!empty($s['title_override'])): ?> <span class="small">(event: <?= h($s['event_name']) ?>)</span><?php endif; ?><br>
            <span class="small"><?= h(date('M j, Y', strtotime((string)$s['event_starts_at']))) ?> &middot; <a href="/event_photos.php?event_id=<?= (int)$s['event_id'] ?>">Manage photos</a></span>
            <form method="post" class="stack" style="margin-top:6px">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="set_options">
              <input type="hidden" name="section_id" value="<?= (int)$s['id'] ?>">
              <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <input type="text" name="title_override" value="<?= h($s['title_override'] ?? '') ?>" placeholder="Title override (optional)" style="width:200px;padding:4px 6px;font-size:12px">
                <input type="number" name="seconds_per_photo" value="<?= $s['seconds_per_photo'] !== null ? h((string)(float)$s['seconds_per_photo']) : '' ?>" step="0.5" min="1" max="30" placeholder="sec/photo" title="Seconds per photo override (1-30); blank = automatic" style="width:90px;padding:4px 6px;font-size:12px">
                <button type="submit" class="button" style="padding:4px 8px;font-size:12px">Apply</button>
              </div>
            </form>
          </td>
          <td>
            <?php if ($n === 0): ?><strong style="color:#b91c1c"><?= (int)$s['photo_total'] ?> &middot; 0 included &mdash; skipped</strong>
            <?php else: ?><?= $n ?><?php if ((int)$s['photo_total'] !== $n): ?> <span class="small">of <?= (int)$s['photo_total'] ?></span><?php endif; ?><?php endif; ?>
          </td>
          <td>
            <?php $multi = Slideshows::hasCues($s); $cueRows = $multi ? $s['cues'] : []; ?>
            <?php if (!$multi): ?>
            <form method="post" class="inline">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="set_track">
              <input type="hidden" name="section_id" value="<?= (int)$s['id'] ?>">
              <select name="track_id" onchange="this.form.submit()" style="min-width:160px;padding:4px 6px;font-size:12px">
                <option value="">No music</option>
                <?php foreach ($tracks as $t): ?>
                  <option value="<?= (int)$t['id'] ?>" <?= (int)$s['track_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['title']) ?><?= $t['duration_seconds'] !== null ? ' (' . h(SlideshowTracks::formatDuration($t['duration_seconds'])) . ')' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <?php if (!empty($tracks) && $n > 1): ?>
              <div class="small" style="margin-top:4px"><a href="#" class="cues-open" data-section-id="<?= (int)$s['id'] ?>">Use multiple tracks&hellip;</a></div>
            <?php endif; ?>
            <?php endif; ?>
            <form method="post" class="stack cues-form <?= $multi ? '' : 'hidden' ?>" id="cues-<?= (int)$s['id'] ?>" style="margin:0">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="set_cues">
              <input type="hidden" name="section_id" value="<?= (int)$s['id'] ?>">
              <div class="cue-rows">
                <?php $rows = $cueRows ?: [['track_id' => $s['track_id'], 'start_index' => 0]]; foreach ($rows as $ci => $cue): ?>
                  <div class="cue-row" style="display:flex;gap:4px;align-items:center;margin-bottom:4px">
                    <select name="cue_track_id[]" style="min-width:140px;padding:4px 6px;font-size:12px">
                      <?php foreach ($tracks as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= (int)($cue['track_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['title']) ?><?= $t['duration_seconds'] !== null ? ' (' . h(SlideshowTracks::formatDuration($t['duration_seconds'])) . ')' : '' ?></option>
                      <?php endforeach; ?>
                    </select>
                    <span class="small">from photo</span>
                    <input type="number" name="cue_start[]" min="1" max="<?= max(1, $n) ?>" value="<?= (int)$cue['start_index'] + 1 ?>" <?= $ci === 0 ? 'readonly title="The first track starts with the first photo"' : '' ?> style="width:64px;padding:4px 6px;font-size:12px">
                    <button type="button" class="button cue-remove" style="padding:2px 7px;font-size:12px" <?= $ci === 0 ? 'disabled' : '' ?>>&#10005;</button>
                  </div>
                <?php endforeach; ?>
              </div>
              <template class="cue-template">
                <div class="cue-row" style="display:flex;gap:4px;align-items:center;margin-bottom:4px">
                  <select name="cue_track_id[]" style="min-width:140px;padding:4px 6px;font-size:12px">
                    <?php foreach ($tracks as $t): ?>
                      <option value="<?= (int)$t['id'] ?>"><?= h($t['title']) ?><?= $t['duration_seconds'] !== null ? ' (' . h(SlideshowTracks::formatDuration($t['duration_seconds'])) . ')' : '' ?></option>
                    <?php endforeach; ?>
                  </select>
                  <span class="small">from photo</span>
                  <input type="number" name="cue_start[]" min="2" max="<?= max(1, $n) ?>" value="" placeholder="#" style="width:64px;padding:4px 6px;font-size:12px">
                  <button type="button" class="button cue-remove" style="padding:2px 7px;font-size:12px">&#10005;</button>
                </div>
              </template>
              <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <button type="button" class="button cue-add" style="padding:4px 8px;font-size:12px">+ Add track</button>
                <button type="submit" class="button primary" style="padding:4px 8px;font-size:12px">Save music</button>
                <?php if ($multi): ?>
                  <button type="submit" class="button" name="action" value="clear_cues" style="padding:4px 8px;font-size:12px" title="Keep only the first track">Back to a single track</button>
                <?php else: ?>
                  <button type="button" class="button cues-cancel" style="padding:4px 8px;font-size:12px">Cancel</button>
                <?php endif; ?>
              </div>
              <p class="small" style="margin:4px 0 0">Each track plays from its photo until the next track starts; the photo timing is worked out per track. <?= $n ?> photo<?= $n === 1 ? '' : 's' ?> in this section.</p>
            </form>
            <?php if ($configured): ?>
              <div class="track-upload" data-section-id="<?= (int)$s['id'] ?>" style="margin-top:6px">
                <label class="button" style="padding:4px 8px;font-size:12px;cursor:pointer">Upload music&hellip;
                  <input type="file" accept="<?= h($acceptAudio) ?>" style="display:none" data-role="track-file">
                </label>
                <span class="small" data-role="track-status"></span>
              </div>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php if ($n > 0): ?>
              <strong><?= h(Slideshows::formatSeconds((float)$tm['section_seconds'])) ?> total</strong><?= $s['seconds_per_photo'] !== null ? ' (override ' . h((string)(float)$s['seconds_per_photo']) . ' s/photo)' : '' ?>
              <?php foreach ($tm['cues'] as $ci => $cue): $srcCue = $s['cues'][$ci] ?? null; $dur = $srcCue['duration_seconds'] ?? null; ?>
                <br><?php if (count($tm['cues']) > 1): ?>Photos <?= (int)$cue['start_index'] + 1 ?>&ndash;<?= (int)$cue['start_index'] + (int)$cue['photo_count'] ?>: <?php endif; ?>
                <?= h((string)$cue['seconds_per_photo']) ?> s/photo,
                <?php if ($cue['music_mode'] === 'none'): ?>no music
                <?php elseif ($cue['music_mode'] === 'loop'): ?>music loops
                <?php elseif ($dur !== null && $dur - (float)$cue['segment_seconds'] > 5): ?>music fades early
                <?php else: ?>fits the music<?php endif; ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap">
            <?= $i > 0 ? ss_form($id, 'move_up', (int)$s['id'], '&#9650;') : '' ?>
            <?= $i < count($sections) - 1 ? ss_form($id, 'move_down', (int)$s['id'], '&#9660;') : '' ?>
            <?= ss_form($id, 'remove_section', (int)$s['id'], '&#10005;', 'danger', 'Remove this event from the slideshow?') ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    </div>
  <?php endif; ?>

  <form method="post" class="stack" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="add_section">
    <label>Add an event
      <?php $addable = array_filter($eventsWithPhotos, fn($ev) => !in_array((int)$ev['id'], $inShow, true)); ?>
      <select name="event_id" required <?= empty($addable) ? 'disabled' : '' ?>>
        <option value=""><?= empty($addable) ? 'No other events have photos yet' : 'Choose an event with photos…' ?></option>
        <?php foreach ($addable as $ev): $c = $counts[(int)$ev['id']] ?? ['total' => 0, 'included' => 0]; ?>
          <option value="<?= (int)$ev['id'] ?>"><?= h(date('M j, Y', strtotime((string)$ev['starts_at']))) ?> &mdash; <?= h($ev['name']) ?> (<?= (int)$c['included'] ?> photo<?= (int)$c['included'] === 1 ? '' : 's' ?><?= (int)$c['included'] !== (int)$c['total'] ? ' of ' . (int)$c['total'] : '' ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <p class="small">Only events that already have photos are listed. Upload photos from an event page first.</p>
    <div class="actions"><button class="button primary" type="submit">Add event</button></div>
  </form>
</div>

<div class="card">
  <form method="post" onsubmit="return confirm('Delete this slideshow? Its music tracks are kept in the library.');">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="delete_slideshow">
    <button type="submit" class="button danger">Delete slideshow</button>
  </form>
</div>

<?php $jsVer = @filemtime(__DIR__ . '/photos.js') ?: date('Ymd'); ?>
<script src="/photos.js?v=<?= h((string)$jsVer) ?>"></script>
<script>
// Multiple-tracks editor: show/hide the cue form, add/remove rows.
(function () {
  document.querySelectorAll('.cues-open').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var form = document.getElementById('cues-' + a.getAttribute('data-section-id'));
      if (!form) return;
      form.classList.remove('hidden');
      a.closest('td').querySelectorAll('form.inline, .cues-open').forEach(function (el) { el.classList.add('hidden'); });
      if (form.querySelectorAll('.cue-row').length < 2) addRow(form);
    });
  });
  function addRow(form) {
    var tpl = form.querySelector('.cue-template');
    var rows = form.querySelector('.cue-rows');
    var node = tpl.content.firstElementChild.cloneNode(true);
    var last = rows.querySelector('.cue-row:last-child input[name="cue_start[]"]');
    var next = last ? (parseInt(last.value, 10) || 1) + 1 : 2;
    node.querySelector('input[name="cue_start[]"]').value = String(next);
    rows.appendChild(node);
    node.querySelector('select').focus();
  }
  document.querySelectorAll('.cues-form').forEach(function (form) {
    form.querySelector('.cue-add').addEventListener('click', function () { addRow(form); });
    form.addEventListener('click', function (e) {
      var btn = e.target.closest('.cue-remove');
      if (btn && !btn.disabled) { var row = btn.closest('.cue-row'); if (row) row.remove(); }
    });
    var cancel = form.querySelector('.cues-cancel');
    if (cancel) cancel.addEventListener('click', function () { location.reload(); });
  });
})();

// Music upload: read the duration locally, presign, PUT straight to R2 with
// progress, attach (assigning the track to this section), then reload so the
// timing is recomputed server-side.
(function () {
  var csrf = <?= json_encode(csrf_token()) ?>;
  var P = window.Pack440Photos;
  if (!P) return;

  function readDuration(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var a = new Audio();
      var done = function (d) { URL.revokeObjectURL(url); resolve(d); };
      var timer = setTimeout(function () { done(null); }, 5000);
      a.preload = 'metadata';
      a.onloadedmetadata = function () { clearTimeout(timer); done(isFinite(a.duration) ? a.duration : null); };
      a.onerror = function () { clearTimeout(timer); done(null); };
      a.src = url;
    });
  }

  function guessType(name) {
    var ext = (name.split('.').pop() || '').toLowerCase();
    return { mp3: 'audio/mpeg', m4a: 'audio/mp4', aac: 'audio/aac', wav: 'audio/wav' }[ext] || '';
  }

  document.querySelectorAll('.track-upload').forEach(function (box) {
    var input = box.querySelector('[data-role="track-file"]');
    var status = box.querySelector('[data-role="track-status"]');
    var sectionId = box.getAttribute('data-section-id');
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      input.value = '';
      if (!file) return;
      var type = (file.type || guessType(file.name)).split(';')[0].toLowerCase() || guessType(file.name);
      var title = file.name.replace(/\.[^.]+$/, '');
      status.textContent = 'Reading…';
      readDuration(file).then(function (duration) {
        status.textContent = 'Preparing…';
        return P.postForm('/slideshow_track_presign.php', { csrf: csrf, content_type: type, byte_length: file.size, filename: file.name })
          .then(function (grant) {
            return P.putToStorage(file, grant, function (loaded, total) {
              status.textContent = 'Uploading… ' + Math.round(loaded / total * 100) + '%';
            }).then(function () {
              status.textContent = 'Saving…';
              return P.postForm('/slideshow_track_attach.php', { csrf: csrf, key: grant.key, title: title, content_type: type, duration_seconds: duration, section_id: sectionId });
            });
          });
      }).then(function () {
        status.textContent = 'Saved.';
        location.reload();
      }).catch(function (e) {
        status.textContent = e.message || 'Upload failed.';
        status.style.color = '#7a0000';
      });
    });
  });
})();
</script>
<?php footer_html(); ?>
