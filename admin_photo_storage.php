<?php
// Admin: diagnostics and setup for the Cloudflare R2 bucket that holds event
// photos and slideshow music. Check credentials, create the bucket, apply the
// CORS rule browsers need to upload directly, run a test upload, and compare
// the bucket with the database. Every storage call is caught and reported
// inline: this page exists precisely for the case where storage is broken.
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/PhotoStorage.php';
require_once __DIR__.'/lib/EventPhotos.php';
if (is_file(__DIR__.'/lib/SlideshowTracks.php')) {
  require_once __DIR__.'/lib/SlideshowTracks.php';
}
require_admin();

$msg = null;
$err = null;
$ctx = UserContext::getLoggedInUserContext();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $action = (string)($_POST['action'] ?? '');
  try {
    if (!PhotoStorage::isConfigured()) {
      throw new RuntimeException('Photo storage is not configured.');
    }
    switch ($action) {
      case 'create_bucket':
        $created = PhotoStorage::storage()->createBucketIfMissing(PhotoStorage::bucket());
        ActivityLog::log($ctx, 'photo_storage.create_bucket', ['bucket' => PhotoStorage::bucket(), 'created' => $created]);
        $msg = $created ? 'Bucket created.' : 'The bucket already exists.';
        break;
      case 'apply_cors':
        $origins = PhotoStorage::applyCors();
        ActivityLog::log($ctx, 'photo_storage.apply_cors', ['bucket' => PhotoStorage::bucket(), 'origins' => $origins]);
        $msg = 'CORS rule applied for: ' . implode(', ', $origins);
        break;
      case 'test_upload':
        $msg = PhotoStorage::describeTestUpload();
        ActivityLog::log($ctx, 'photo_storage.test_upload', ['result' => $msg]);
        break;
      case 'delete_orphans':
        $recorded = EventPhotos::allRecordedKeys();
        if (class_exists('SlideshowTracks')) {
          $recorded = array_merge($recorded, SlideshowTracks::allRecordedKeys());
        }
        $all = array_column(PhotoStorage::storage()->listObjects(PhotoStorage::bucket()), 'key');
        $orphans = array_values(array_diff($all, $recorded));
        PhotoStorage::deleteObjects($orphans);
        ActivityLog::log($ctx, 'photo_storage.delete_orphans', ['count' => count($orphans)]);
        $msg = 'Deleted ' . count($orphans) . ' orphaned object(s).';
        break;
      default:
        throw new RuntimeException('Unknown action.');
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

// Probe the bucket.
$configured = PhotoStorage::isConfigured();
$missingConfig = PhotoStorage::missingConfig();
$bucket = PhotoStorage::bucket();
$wantedOrigins = PhotoStorage::corsOrigins();
$dbKeys = EventPhotos::allRecordedKeys();
if (class_exists('SlideshowTracks')) {
  $dbKeys = array_merge($dbKeys, SlideshowTracks::allRecordedKeys());
}
$photoCount = (int)pdo()->query('SELECT COUNT(*) FROM event_photos')->fetchColumn();
$probe = ['exists' => false, 'count' => null, 'bytes' => null, 'cors' => null, 'cors_error' => null, 'error' => null, 'keys' => []];
if ($configured) {
  try {
    $client = PhotoStorage::storage();
    $probe['exists'] = $client->bucketExists($bucket);
    if ($probe['exists']) {
      $objects = $client->listObjects($bucket);
      $probe['count'] = count($objects);
      $probe['bytes'] = array_sum(array_column($objects, 'size'));
      $probe['keys'] = array_column($objects, 'key');
    }
  } catch (Throwable $e) {
    $probe['error'] = $e->getMessage();
  }
  // Reading the CORS rule needs an "Admin Read & Write" R2 token; an
  // "Object Read & Write" token gets 403 here although uploads work fine.
  // Treat that as its own, non-fatal problem.
  if ($probe['exists'] && $probe['error'] === null) {
    try {
      $probe['cors'] = PhotoStorage::storage()->getBucketCorsOrigins($bucket);
    } catch (Throwable $e) {
      $probe['cors_error'] = $e->getMessage();
    }
  }
}
$corsPolicyJson = json_encode([[
  'AllowedOrigins' => $wantedOrigins,
  'AllowedMethods' => ['GET', 'PUT', 'HEAD'],
  'AllowedHeaders' => ['*'],
  'ExposeHeaders'  => ['ETag'],
  'MaxAgeSeconds'  => 3000,
]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$missing = $probe['exists'] ? array_values(array_diff($dbKeys, $probe['keys'])) : [];
$orphans = $probe['exists'] ? array_values(array_diff($probe['keys'], $dbKeys)) : [];
$corsMissing = $probe['cors'] === null ? $wantedOrigins : array_values(array_diff($wantedOrigins, $probe['cors']));

function photo_storage_action_form(string $action, string $label, string $class = '', string $confirm = ''): string {
  return '<form method="post" class="inline"' . ($confirm !== '' ? ' onsubmit="return confirm(' . h(json_encode($confirm)) . ');"' : '') . '>'
       . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
       . '<input type="hidden" name="action" value="' . h($action) . '">'
       . '<button type="submit" class="button ' . h($class) . '">' . h($label) . '</button>'
       . '</form>';
}

header_html('Photo Storage');
?>
<h2>Photo Storage</h2>
<?php if ($msg): ?><p class="flash"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?= h($err) ?></p><?php endif; ?>
<p class="small">Event photos and slideshow music are stored in a private Cloudflare R2 bucket, not on this server or in the database. Browsers upload straight to the bucket with a short-lived URL signed here, so the bucket needs a CORS rule that allows this site's origin. Pages show photos through presigned links that expire.</p>

<div class="card">
  <h3>Status</h3>
  <table class="list">
    <tr><th style="width:220px">Credentials</th><td>
      <?php if ($configured): ?><span class="badge success">Configured</span>
      <?php else: ?><strong>Not configured</strong> &mdash; set <?php foreach ($missingConfig as $i => $c): ?><?= $i ? ', ' : '' ?><code><?= h($c) ?></code><?php endforeach; ?> in <code>config.local.php</code>. Photo uploads are disabled until then.<?php endif; ?>
    </td></tr>
    <tr><th>Endpoint</th><td><code><?= h(PhotoStorage::endpoint() !== '' ? PhotoStorage::endpoint() : '(unset)') ?></code></td></tr>
    <tr><th>Region</th><td><code><?= h(PhotoStorage::region()) ?></code></td></tr>
    <tr><th>Bucket</th><td><code><?= h($bucket !== '' ? $bucket : '(unset)') ?></code></td></tr>
    <tr><th>Upload limits</th><td><?= h(PhotoStorage::humanBytes(PhotoStorage::maxBytes('image'))) ?> per photo, <?= h(PhotoStorage::humanBytes(PhotoStorage::maxBytes('audio'))) ?> per music track</td></tr>
    <tr><th>Photos in the database</th><td><?= (int)$photoCount ?> (<?= count($dbKeys) ?> objects referenced)</td></tr>
    <?php if ($configured): ?>
      <?php if ($probe['error'] !== null): ?>
        <tr><th>Bucket status</th><td><strong>Storage error:</strong> <?= h($probe['error']) ?></td></tr>
      <?php else: ?>
        <tr><th>Bucket status</th><td><?= $probe['exists'] ? '<span class="badge success">Ready</span>' : '<strong>Bucket missing</strong>' ?></td></tr>
        <?php if ($probe['exists']): ?>
          <tr><th>Objects</th><td><?= number_format((int)$probe['count']) ?> (<?= h(PhotoStorage::humanBytes((int)$probe['bytes'])) ?>)</td></tr>
          <tr><th>Missing from bucket</th><td><?= count($missing) === 0 ? '<span class="badge success">None</span>' : '<strong>' . count($missing) . '</strong> database row(s) point at objects that are gone' ?></td></tr>
          <tr><th>Orphans in bucket</th><td><?= count($orphans) === 0 ? '<span class="badge success">None</span>' : count($orphans) . ' object(s) not referenced by any row (abandoned uploads)' ?></td></tr>
          <tr><th>CORS origins</th><td>
            <?php if ($probe['cors_error'] !== null): ?>
              <strong>Cannot read the CORS rule with this API token</strong> (<?= h($probe['cors_error']) ?>).<br>
              <span class="small">Cloudflare only lets an <em>Admin Read &amp; Write</em> token read or change CORS; an <em>Object Read &amp; Write</em> token can still upload and serve photos. Either recreate the token as Admin Read &amp; Write (scoped to this bucket) so <strong>Apply CORS</strong> works, or paste the policy below into the Cloudflare dashboard: bucket &rarr; <em>Settings</em> &rarr; <em>CORS Policy</em>.</span>
            <?php elseif ($probe['cors'] === null): ?><strong>No CORS rule</strong> &mdash; browser uploads will fail.
            <?php else: ?><?php foreach ($probe['cors'] as $o): ?><code><?= h($o) ?></code> <?php endforeach; ?><?php endif; ?>
            <?php if ($corsMissing !== [] && $probe['cors'] !== null): ?><br><strong>Missing:</strong> <?php foreach ($corsMissing as $o): ?><code><?= h($o) ?></code> <?php endforeach; ?><?php endif; ?>
          </td></tr>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </table>
  <?php if ($configured && $probe['error'] === null && $probe['exists'] && ($probe['cors_error'] !== null || $probe['cors'] === null)): ?>
    <details style="margin-top:12px">
      <summary>CORS policy to paste into the Cloudflare dashboard</summary>
      <p class="small">Bucket <code><?= h($bucket) ?></code> &rarr; <em>Settings</em> &rarr; <em>CORS Policy</em> &rarr; Edit, paste this, save. It allows browsers on this site to upload directly.</p>
      <pre style="white-space:pre-wrap;background:#f5f5f7;padding:10px;border-radius:8px;font-size:12px"><?= h($corsPolicyJson) ?></pre>
    </details>
  <?php endif; ?>
  <?php if ($configured && $probe['error'] === null): ?>
    <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
      <?php if (!$probe['exists']): ?>
        <?= photo_storage_action_form('create_bucket', 'Create bucket', 'primary') ?>
      <?php else: ?>
        <?php if ($probe['cors_error'] === null): ?>
          <?= photo_storage_action_form('apply_cors', 'Apply CORS for this site', $corsMissing !== [] ? 'primary' : '') ?>
        <?php endif; ?>
        <?= photo_storage_action_form('test_upload', 'Test upload') ?>
        <?php if ($orphans !== []): ?>
          <?= photo_storage_action_form('delete_orphans', 'Delete orphans', 'danger', 'Delete ' . count($orphans) . ' orphaned object(s) from the bucket? They are not referenced by any photo or music track.') ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Setup</h3>
  <ol class="small" style="line-height:1.6">
    <li>Cloudflare dashboard &rarr; <em>R2 Object Storage</em> &rarr; <strong>Create bucket</strong> named <code><?= h($bucket) ?></code>. Leave it private. On its <em>Settings</em> tab copy the <strong>S3 API</strong> URL.</li>
    <li><em>Manage R2 API Tokens</em> &rarr; <strong>Create API token</strong> scoped to this bucket. <em>Admin Read &amp; Write</em> lets this page apply the CORS rule for you; <em>Object Read &amp; Write</em> also works for uploads, but then you paste the CORS policy into the dashboard yourself (shown below when needed). Copy the Access Key ID and Secret Access Key.</li>
    <li>In <code>config.local.php</code> set <code>R2_ENDPOINT</code>, <code>R2_ACCESS_KEY</code>, <code>R2_SECRET_KEY</code> (see <code>config.local.php.example</code>).</li>
    <li>Reload this page: it should read <em>Ready</em>. Click <strong>Apply CORS for this site</strong> (or paste the policy in the dashboard), then <strong>Test upload</strong>.</li>
    <li>Upload a photo from any event page.</li>
  </ol>
  <p class="small">Origins the CORS rule will allow: <?php foreach ($wantedOrigins as $o): ?><code><?= h($o) ?></code> <?php endforeach; ?>. Applying adds to whatever the bucket already allows, so applying from a development machine never removes the production origin. <strong>Test upload</strong> performs a presigned PUT from this server exactly as a browser would and shows the raw response.</p>
</div>
<?php footer_html(); ?>
