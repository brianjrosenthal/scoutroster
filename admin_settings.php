<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/settings.php';
require_admin();

$msg = null;
$err = null;

// Settings definitions for Cub Scouts
$SETTINGS_DEF = [
  'site_title' => [
    'label' => 'Site Title',
    'hint'  => 'Shown in the header and page titles. Defaults to "Cub Scouts Pack 440" if empty.',
    'type'  => 'text',
  ],
  'announcement' => [
    'label' => 'Announcement',
    'hint'  => 'Shown on the Home and Events pages when non-empty.',
    'type'  => 'textarea',
  ],
  'timezone' => [
    'label' => 'Time zone',
    'hint'  => 'Times are displayed in this time zone.',
    'type'  => 'timezone',
  ],
  'google_calendar_url' => [
    'label' => 'Google Calendar URL',
    'hint'  => 'Public URL to your Pack\'s Google Calendar. If set, the Events page will show a subscribe link.',
    'type'  => 'text',
  ],
  'cubmaster_name' => [
    'label' => 'Cubmaster Name',
    'hint'  => 'Used as the recipient name for recommendation notifications.',
    'type'  => 'text',
  ],
  'cubmaster_email' => [
    'label' => 'Cubmaster Email',
    'hint'  => 'Recipient email address for recommendation notifications.',
    'type'  => 'text',
  ],
  'dues_amount' => [
    'label' => 'Dues Amount',
    'hint'  => 'Shown on the homepage renewal section, e.g., "$180" or "180". Leave blank to hide the amount.',
    'type'  => 'text',
  ],
];

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  try {
    foreach ($SETTINGS_DEF as $key => $_meta) {
      $val = $_POST['s'][$key] ?? '';
      Settings::set($key, $val);
    }
    $msg = 'Settings saved.';
  } catch (Throwable $e) {
    $err = 'Failed to save settings.';
  }
}

// Gather current values
$current = [];
foreach ($SETTINGS_DEF as $key => $_meta) {
  // Provide sensible defaults
  if ($key === 'site_title') {
    $default = 'Cub Scouts Pack 440';
  } elseif ($key === 'timezone') {
    $default = date_default_timezone_get();
  } else {
    $default = '';
  }
  $val = Settings::get($key, $default);
  $current[$key] = $val;
}

header_html('Manage Settings');
?>
<h2>Manage Settings</h2>
<?php if($msg):?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if($err):?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <?php foreach ($SETTINGS_DEF as $key => $meta): ?>
      <label>
        <?=h($meta['label'])?>
        <?php $typ = $meta['type'] ?? 'text'; ?>
        <?php if ($typ === 'textarea'): ?>
          <textarea name="s[<?=h($key)?>]" rows="4"><?=h($current[$key])?></textarea>
        <?php elseif ($typ === 'timezone'): ?>
          <?php $zones = DateTimeZone::listIdentifiers(); ?>
          <select name="s[<?=h($key)?>]">
            <?php foreach ($zones as $z): ?>
              <option value="<?=h($z)?>" <?= $current[$key] === $z ? 'selected' : '' ?>><?=h($z)?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" name="s[<?=h($key)?>]" value="<?=h($current[$key])?>">
        <?php endif; ?>
        <?php if (!empty($meta['hint'])): ?>
          <small class="small"><?=h($meta['hint'])?></small>
        <?php endif; ?>
      </label>
    <?php endforeach; ?>
    <div class="actions">
      <button class="primary" type="submit">Save</button>
      <a class="button" href="/index.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
