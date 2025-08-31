<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/ImportManagement.php';
require_admin();

$csv = $_SESSION['import_csv'] ?? null;
if (!$csv || !is_array($csv) || empty($csv['rows'])) {
  header_html('Import Members - Map Fields');
  echo '<h2>Import Members</h2>';
  echo '<p class="error">No parsed CSV found in session. Start at Step 1.</p>';
  echo '<p><a class="button" href="/admin_import_upload.php">Go to Step 1: Upload</a></p>';
  footer_html();
  exit;
}

$headers = $csv['headers'] ?? [];
$rows = $csv['rows'] ?? [];

// Heuristic mapper for defaults
function guess_dest(string $h): string {
  $l = strtolower(trim($h));
  if ($l === '') return 'ignore';
  // explicit exact header mappings
  $explicit = [
    'youth_first_name' => 'youth_first_name',
    'youth_last_name' => 'youth_last_name',
    'youth_bsa_member_id' => 'youth_bsa_id',
    'youth_gender' => 'youth_gender',
    'parent_1_first_name' => 'p1_first_name',
    'parent_1_last_name' => 'p1_last_name',
    'parent_1_bsa_member_id' => 'p1_bsa_id',
    'parent_1_email' => 'p1_email',
    'parent_1_phone' => 'p1_phone',
    'parent_2_first_name' => 'p2_first_name',
    'parent_2_last_name' => 'p2_last_name',
    'parent_2_bsa_member_id' => 'p2_bsa_id',
    'parent_2_email' => 'p2_email',
    'parent_2_phone' => 'p2_phone',
    'youth_address_street' => 'youth_street1',
    'youth_address_city' => 'youth_city',
    'youth_address_state' => 'youth_state',
    'youth_address_zip' => 'youth_zip',
  ];
  if (isset($explicit[$l])) return $explicit[$l];

  // youth
  if (preg_match('/^(youth\s*)?first\s*name$/', $l)) return 'youth_first_name';
  if (preg_match('/^(youth\s*)?last\s*name$/', $l)) return 'youth_last_name';
  if (preg_match('/^(youth\s*)?(grade|grade\s*level|den)$/', $l)) return 'youth_grade';
  if (preg_match('/^(youth\s*)?(bsa|bsa\s*id|bsa\s*registration)$/', $l)) return 'youth_bsa_id';
  if (preg_match('/^(youth\s*)?gender$/', $l)) return 'youth_gender';
  if (preg_match('/^(address|street|street1)$/', $l)) return 'youth_street1';
  if ($l === 'city') return 'youth_city';
  if ($l === 'state') return 'youth_state';
  if ($l === 'zip' || $l === 'zipcode' || $l === 'postal code') return 'youth_zip';

  // parent 1
  if (preg_match('/^parent\s*1\s*first\s*name$/', $l)) return 'p1_first_name';
  if (preg_match('/^parent\s*1\s*last\s*name$/', $l)) return 'p1_last_name';
  if (preg_match('/^parent\s*1\s*(bsa|bsa\s*id)$/', $l)) return 'p1_bsa_id';
  if (preg_match('/^parent\s*1\s*email$/', $l)) return 'p1_email';
  if (preg_match('/^parent\s*1\s*(phone|cell|mobile)$/', $l)) return 'p1_phone';

  // parent 2
  if (preg_match('/^parent\s*2\s*first\s*name$/', $l)) return 'p2_first_name';
  if (preg_match('/^parent\s*2\s*last\s*name$/', $l)) return 'p2_last_name';
  if (preg_match('/^parent\s*2\s*(bsa|bsa\s*id)$/', $l)) return 'p2_bsa_id';
  if (preg_match('/^parent\s*2\s*email$/', $l)) return 'p2_email';
  if (preg_match('/^parent\s*2\s*(phone|cell|mobile)$/', $l)) return 'p2_phone';

  // generic guesses
  if ($l === 'first name') return 'youth_first_name';
  if ($l === 'last name') return 'youth_last_name';
  if ($l === 'email') return 'p1_email';
  if ($l === 'phone' || $l === 'cell' || $l === 'mobile') return 'p1_phone';

  return 'ignore';
}

// Targets
$targets = [
  'ignore' => 'Ignore',
  'youth_first_name' => 'Youth: First Name',
  'youth_last_name' => 'Youth: Last Name',
  'youth_grade' => 'Youth: Grade (K or 0..5)',
  'youth_bsa_id' => 'Youth: BSA Registration #',
  'youth_gender' => 'Youth: Gender',
  'youth_street1' => 'Youth: Address Line 1',
  'youth_city' => 'Youth: City',
  'youth_state' => 'Youth: State',
  'youth_zip' => 'Youth: Zip',
  'p1_first_name' => 'Parent 1: First Name',
  'p1_last_name' => 'Parent 1: Last Name',
  'p1_bsa_id' => 'Parent 1: BSA #',
  'p1_email' => 'Parent 1: Email',
  'p1_phone' => 'Parent 1: Phone',
  'p2_first_name' => 'Parent 2: First Name',
  'p2_last_name' => 'Parent 2: Last Name',
  'p2_bsa_id' => 'Parent 2: BSA #',
  'p2_email' => 'Parent 2: Email',
  'p2_phone' => 'Parent 2: Phone',
];

// Handle POST to save mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $map = [];
  foreach ($headers as $i => $h) {
    $key = 'col_' . $i;
    $sel = $_POST[$key] ?? 'ignore';
    if (!array_key_exists($sel, $targets)) $sel = 'ignore';
    $map[$h] = $sel;
  }
  $_SESSION['import_map'] = $map;
  unset($_SESSION['import_structured'], $_SESSION['import_validated']);

  header('Location: /admin_import_validate.php');
  exit;
}

header_html('Import Members - Map Fields');
?>
<h2>Import Members</h2>
<p class="small">Step 2 of 4: Map CSV columns to destination fields.</p>

<div class="card">
  <form method="post">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
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
        <?php $default = guess_dest((string)$h); ?>
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
      <a class="button" href="/admin_import_upload.php" style="margin-left:8px;">Back</a>
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
