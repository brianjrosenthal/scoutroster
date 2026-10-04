<?php
// The slideshow player: a standalone full-screen document (no site header,
// nav or main.js, whose key handlers and layout would fight the player).
// partials.php is still required for auth and h(). The manifest is fetched by
// slideshow.js from slideshow_manifest.php.
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/PhotoStorage.php';
require_once __DIR__.'/lib/Slideshows.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$ctx = UserContext::getLoggedInUserContext();
$show = $id > 0 ? Slideshows::findById($id) : null;
if (!$show) { http_response_code(404); exit('Slideshow not found'); }
if (!Slideshows::canView($ctx, $show)) { http_response_code(403); exit('This slideshow is not published yet.'); }

$sections = Slideshows::listSections($id);
$played = array_filter($sections, fn($s) => (int)$s['photo_count'] > 0);
$runtime = Slideshows::formatSeconds(Slideshows::totalSeconds($sections));
$configured = PhotoStorage::isConfigured();
$cssVer = @filemtime(__DIR__ . '/slideshow.css') ?: date('Ymd');
$jsVer = @filemtime(__DIR__ . '/slideshow.js') ?: date('Ymd');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($show['title']) ?></title>
<link rel="stylesheet" href="/slideshow.css?v=<?= h((string)$cssVer) ?>">
</head>
<body>
<div id="stage">
  <div class="layer" id="layerA"><div class="bg"></div><img alt=""></div>
  <div class="layer" id="layerB"><div class="bg"></div><img alt=""></div>
  <div id="card" class="card hidden"><h1></h1><p></p></div>
  <div id="caption" class="hidden"></div>
  <div id="hud"><div id="progress"><i></i></div><span id="hudText"></span></div>
  <div id="overlay">
    <h1><?= h($show['title']) ?></h1>
    <p><?= count($played) ?> event<?= count($played) === 1 ? '' : 's' ?> &middot; about <?= h($runtime) ?></p>
    <?php if (!$configured): ?>
      <p class="err">Photo storage is not configured, so this slideshow cannot play.</p>
    <?php elseif (count($played) === 0): ?>
      <p class="err">This slideshow has no photos yet.</p>
    <?php else: ?>
      <button id="begin" type="button">Click to begin</button>
      <p class="small">Space pause &middot; &larr;/&rarr; photo &middot; N next event &middot; C captions &middot; F full screen &middot; Esc exit</p>
    <?php endif; ?>
    <p class="small"><a href="/slideshows.php">Back to slideshows</a></p>
  </div>
  <div id="error" class="hidden"></div>
</div>
<audio id="audioA" preload="auto"></audio>
<audio id="audioB" preload="auto"></audio>
<script>window.SLIDESHOW = { id: <?= (int)$id ?>, manifestUrl: '/slideshow_manifest.php?id=<?= (int)$id ?>', exitUrl: '/slideshows.php' };</script>
<script src="/slideshow.js?v=<?= h((string)$jsVer) ?>"></script>
</body>
</html>
