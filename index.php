<?php
require_once __DIR__.'/partials.php';
require_login();

$announcement = Settings::get('announcement', '');
$siteTitle = Settings::siteTitle();

header_html('Home');
?>
<?php if (trim($announcement) !== ''): ?>
  <p class="announcement"><?=h($announcement)?></p>
<?php endif; ?>

<div class="card">
  <h2>Welcome to <?=h($siteTitle)?></h2>
  <p class="small">Use the navigation above to view rosters, events, and your profile.</p>
</div>
<?php footer_html(); ?>
