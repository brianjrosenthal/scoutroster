<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/ScoutingOrgImport.php';
require_admin();

$SESSION_KEY_CSV = 'scoutingorg_csv';
$SESSION_KEY_MAP = 'scoutingorg_map';
$SESSION_KEY_VALID = 'scoutingorg_validated';

$csv = $_SESSION[$SESSION_KEY_CSV] ?? null;
if (!$csv || !is_array($csv) || empty($csv['rows'])) {
  header_html('Scouting.org Roster Import - Map Fields');
  echo '<h2>Sync with Scouting.org Roster Export</h2>';
  echo '<p class="error">No parsed CSV found in session. Start at Step 1.</p>';
  echo '<p><a class="button" href="/admin_scoutingorg_upload.php">Go to Step 1: Upload</a></p>';
  footer_html();
  exit;
}

$headers = $csv['headers'] ?? [];
$rows = $csv['rows'] ?? [];

// Get default mappings and available destinations
$defaultMappings = ScoutingOrgImport::getDefaultMappings();
$destinations = ScoutingOrgImport::getMappingDestinations();

// Guess destination for each header
function guessDestination(string $header): string {
  $defaults = ScoutingOrgImport::getDefaultMappings();
  
  // Try exact match first
  if (isset($defaults[strtolower($header)])) {
    return $defaults[strtolower($header)];
  }
  
  // Try case-insensitive partial matches
  $lower = strtolower($header);
  foreach ($defaults as $pattern => $dest) {
    if ($lower === $pattern) {
      return $dest;
    }
  }
  
  return ScoutingOrgImport::DEST_IGNORE;
}

// Handle POST to save mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $map = [];
  foreach ($headers as $i => $h) {
    $key = 'col_' . $i;
    $sel = $_POST[$key] ?? ScoutingOrgImport::DEST_IGNORE;
    if (!array_key_exists($sel, $destinations)) $sel = ScoutingOrgImport::DEST_IGNORE;
    $map[$h] = $sel;
  }
  $_SESSION[$SESSION_KEY_MAP] = $map;
  unset($_SESSION[$SESSION_KEY_VALID]);

  header('Location: /admin_scoutingorg_validate.php');
  exit;
}

header_html('Scouting.org Roster Import - Map Fields');
?>
<h2>Sync with Scouting.org Roster Export</h2>
<p class="small">Step 2 of 4: Map CSV columns to destination fields. Default mappings have been applied based on standard Scouting.org field names.</p>

<div class="card">
  <form method="post">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <table class="list">
      <thead>
        <tr>
          <th>#</th>
          <th>CSV Header</th>
          <th>Map To</th>
          <th>Sample Data</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($headers as $i => $h): ?>
        <?php 
          $default = guessDestination((string)$h);
          $sample = '';
          // Get first non-empty sample value
          for ($r = 0; $r < min(3, count($rows)); $r++) {
            $val = isset($rows[$r][$i]) ? trim((string)$rows[$r][$i]) : '';
            if ($val !== '') {
              $sample = $val;
              break;
            }
          }
        ?>
        <tr>
          <td class="small"><?= (int)$i + 1 ?></td>
          <td><strong><?= h($h) ?></strong></td>
          <td>
            <select name="col_<?= (int)$i ?>">
              <?php foreach ($destinations as $val => $label): ?>
                <option value="<?= h($val) ?>" <?= $val === $default ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="small"><?= h($sample) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="actions" style="margin-top:12px;">
      <button class="button" type="submit">Continue to Validation</button>
      <a class="button" href="/admin_scoutingorg_upload.php" style="margin-left:8px;">Back</a>
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

<div class="card">
  <h3>Import Logic</h3>
  <div class="small">
    <p><strong>Adult vs Youth Detection:</strong></p>
    <ul>
      <li><strong>Youth:</strong> Position Name = "Youth Member"</li>
      <li><strong>Adult:</strong> Any other Position Name value</li>
    </ul>
    
    <p><strong>Matching Logic:</strong></p>
    <ul>
      <li><strong>Adults:</strong> Try BSA Registration Number → Email → First + Last Name</li>
      <li><strong>Youth:</strong> Try BSA Registration Number → First + Last Name</li>
    </ul>
    
    <p><strong>Update Strategy:</strong></p>
    <ul>
      <li><strong>Always Updated:</strong> BSA registration dates, safeguarding training dates</li>
      <li><strong>Fill if Empty:</strong> Contact information (address, phone)</li>
      <li><strong>Notices Only:</strong> Name/email discrepancies (no changes made)</li>
    </ul>
  </div>
</div>

<?php footer_html(); ?>
