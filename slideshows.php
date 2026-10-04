<?php
// Slideshows: list (published for everyone, drafts too for admins), and for
// admins: create a slideshow and manage the music library.
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/PhotoStorage.php';
require_once __DIR__.'/lib/Slideshows.php';
require_once __DIR__.'/lib/SlideshowTracks.php';
require_login();

$me = current_user();
$isAdmin = !empty($me['is_admin']);
$ctx = UserContext::getLoggedInUserContext();
$msg = isset($_GET['msg']) ? (string)$_GET['msg'] : null;
$err = isset($_GET['err']) ? (string)$_GET['err'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_admin();
  require_csrf();
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'create') {
      $id = Slideshows::create($ctx, (string)($_POST['title'] ?? ''));
      header('Location: /admin_slideshow_edit.php?id=' . $id);
      exit;
    } elseif ($action === 'rename_track') {
      SlideshowTracks::rename($ctx, (int)($_POST['track_id'] ?? 0), (string)($_POST['title'] ?? ''));
      $msg = 'Track renamed.';
    } else {
      throw new InvalidArgumentException('Unknown action.');
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$shows = Slideshows::listVisible($ctx);
$tracks = $isAdmin ? SlideshowTracks::listAll() : [];
$configured = PhotoStorage::isConfigured();

header_html('Slideshows');
?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
  <h2 style="margin:0">Slideshows</h2>
  <a class="button" href="/photos.php">Back to Photos</a>
</div>
<?php if ($msg): ?><p class="flash"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?= h($err) ?></p><?php endif; ?>

<?php if (empty($shows)): ?>
  <div class="card"><p class="small">No slideshows<?= $isAdmin ? ' yet. Create one below.' : ' have been published yet.' ?></p></div>
<?php else: ?>
  <div class="grid">
    <?php foreach ($shows as $s): ?>
      <?php $sections = Slideshows::listSections((int)$s['id']); $runtime = Slideshows::totalSeconds($sections); $played = array_filter($sections, fn($x) => (int)$x['photo_count'] > 0); ?>
      <div class="card">
        <h3 style="margin-top:0"><?= h($s['title']) ?>
          <?php if ($isAdmin): ?><span class="badge <?= !empty($s['is_published']) ? 'success' : '' ?>" style="<?= empty($s['is_published']) ? 'background:#fff3cd;color:#7a4a00' : '' ?>"><?= !empty($s['is_published']) ? 'Published' : 'Draft' ?></span><?php endif; ?>
        </h3>
        <?php if (!empty($s['description'])): ?><p><?= nl2br(h($s['description'])) ?></p><?php endif; ?>
        <p class="small"><?= count($played) ?> event<?= count($played) === 1 ? '' : 's' ?> &middot; about <?= h(Slideshows::formatSeconds($runtime)) ?></p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <a class="button primary" href="/slideshow_play.php?id=<?= (int)$s['id'] ?>">Play</a>
          <?php if ($isAdmin): ?><a class="button" href="/admin_slideshow_edit.php?id=<?= (int)$s['id'] ?>">Edit</a><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($isAdmin): ?>
  <div class="card" style="margin-top:16px">
    <h3>Create a slideshow</h3>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <label>Title
        <input type="text" name="title" required maxlength="255" placeholder="e.g. Pack 440 Year in Review 2026">
      </label>
      <div class="actions"><button class="button primary" type="submit">Create</button></div>
    </form>
  </div>

  <div class="card" style="margin-top:16px">
    <h3>Music library</h3>
    <?php if (!$configured): ?>
      <p class="small">Photo storage is not configured, so music cannot be uploaded. See <a href="/admin_photo_storage.php">Admin &rarr; Photo Storage</a>.</p>
    <?php endif; ?>
    <?php if (empty($tracks)): ?>
      <p class="small">No music yet. Upload tracks from a slideshow's editor (each section has an "Upload music" button).</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Title</th><th>Length</th><th>Size</th><th>Used by</th><th></th></tr>
        <?php foreach ($tracks as $t): ?>
          <tr>
            <td>
              <form method="post" class="inline" style="display:flex;gap:6px;align-items:center">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="rename_track">
                <input type="hidden" name="track_id" value="<?= (int)$t['id'] ?>">
                <input type="text" name="title" value="<?= h($t['title']) ?>" maxlength="255" style="min-width:200px">
                <button type="submit" class="button" title="Rename">Save</button>
              </form>
            </td>
            <td><?= h(SlideshowTracks::formatDuration($t['duration_seconds']) ?: '?') ?></td>
            <td><?= h(PhotoStorage::humanBytes((int)$t['byte_length'])) ?></td>
            <td><?= (int)$t['usage_count'] ?> section<?= (int)$t['usage_count'] === 1 ? '' : 's' ?></td>
            <td>
              <form method="post" action="/slideshow_track_delete.php" class="inline" onsubmit="return confirm('Delete this track?');">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="track_id" value="<?= (int)$t['id'] ?>">
                <button type="submit" class="button danger" <?= (int)$t['usage_count'] > 0 ? 'disabled title="In use by a slideshow section"' : '' ?>>Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php footer_html(); ?>
