<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/ImportRegDates.php';
require_admin();

$SESSION_KEY_CSV = 'regdates_csv';
$SESSION_KEY_MAP = 'regdates_map';
$csv = $_SESSION[$SESSION_KEY_CSV] ?? null;

if (!$csv || !is_array($csv) || empty($csv['rows'])) {
  header_html('Registration Dates Import - Map Fields');
  echo '<h2>Registration Dates Import</h2>';
  echo '<p class="error">No parsed CSV found in session. Start at Step 1.</p>';
  echo '<p><a class="button" href="/admin_regdates_upload.php">Go to Step 1: Upload</a></p>';
  footer_html();
  exit;
}

$headers = $csv['headers'] ?? [];
$rows = $csv['rows'] ?? [];

// Auto-map exact header names (case-insensitive match)
function guess_regdates_dest(string $h): string {
  $l = strtolower(trim($h));
  if ($l === '') return ImportRegDates::DEST_IGNORE;
  $exact = [
    'youth_bsa_registration_number' => ImportRegDates::DEST_BSA,
    'youth_first_name' => ImportRegDates::DEST_FIRST,
    'youth_last_name' => ImportRegDates::DEST_LAST,
    'youth_registration_expires_date' => ImportRegDates::DEST_EXPIRES,
  ];
  if (isset($exact[$l])) return $exact[$l];

  // Some lenient guesses
  if ($l === 'bsa' || $l === 'bsa id' || $l === 'bsa_registration_number') return ImportRegDates::DEST_BSA;
  if ($l === 'first' || $l === 'first_name' || $l === 'youth first') return ImportRegDates::DEST_FIRST;
  if ($l === 'last' || $l === 'last_name' || $l === 'youth last') return ImportRegDates::DEST_LAST;
  if ($l === 'expires' || $l === 'expires_on' || $l === 'registration_expires' || $l === 'registration_expires_date') return ImportRegDates::DEST_EXPIRES;

  return ImportRegDates::DEST_IGNORE;
}

$targets = [
  ImportRegDates::DEST_IGNORE => 'Ignore',
  ImportRegDates::DEST_BSA    => 'BSA Registration Number (required)',
  ImportRegDates::DEST_FIRST  => 'Youth First Name (optional)',
  ImportRegDates::DEST_LAST   => 'Youth Last Name (optional)',
  ImportRegDates::DEST_EXPIRES=> 'Registration Expires Date (required)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $map = [];
  foreach ($headers as $i => $h) {
    $key = 'col_' . $i;
    $sel = $_POST[$key] ?? ImportRegDates::DEST_IGNORE;
    if (!array_key_exists($sel, $targets)) $sel = ImportRegDates::DEST_IGNORE;
    $map[$h] = $sel;
  }
  $_SESSION[$SESSION_KEY_MAP] = $map;
  unset($_SESSION['regdates_validated']);

  header('Location: /admin_regdates_validate.php');
  exit;
}

header_html('Registration Dates Import - Map Fields');
?>
<h2>Registration Dates Import</h2>
<p class="small">Step 2 of 4: Map CSV columns to destination fields.</p>

<div class="card">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <table class="list">
      <thead>
        <tr>
          <th>#</th>
          <th>CSV Header</th>
          <th>Map To</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($headers as $i => $h): ?>
        <?php $default = guess_regdates_dest((string)$h); ?>
        <tr>
          <td class="small"><?= (int)$i + 1 ?></td>
          <td><?= h($h) ?></td>
          <td>
            <select name="col_<?= (int)$i ?>">
              <?php foreach ($targets as $val => $label): ?>
                <option value="<?= h($val) ?>" <?= $val === $default ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="actions" style="margin-top:12px;">
      <button class="button" type="submit">Continue to Validation</button>
      <a class="button" href="/admin_regdates_upload.php" style="margin-left:8px;">Back</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>Preview (first 5 rows)</h3>
  <div class="small">This is a sample of your data for reference.</div>
  <div style="overflow:auto;">
    <table class="list">
      <thead>
        <tr>
          <?php foreach ($headers as $h): ?>
            <th><?= h($h) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_slice($rows, 0, 5) as $r): ?>
          <tr>
            <?php foreach ($r as $cell): ?>
              <td><?= h((string)$cell) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php footer_html(); ?>
