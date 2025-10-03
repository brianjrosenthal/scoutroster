<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/ScoutingOrgImport.php';
require_once __DIR__ . '/lib/UserContext.php';
require_admin();

$SESSION_KEY_CSV = 'scoutingorg_csv';
$SESSION_KEY_MAP = 'scoutingorg_map';
$SESSION_KEY_VALID = 'scoutingorg_validated';

$csv = $_SESSION[$SESSION_KEY_CSV] ?? null;
$map = $_SESSION[$SESSION_KEY_MAP] ?? null;

if (!$csv || !is_array($csv) || empty($csv['rows'])) {
  header_html('Scouting.org Roster Import - Validate');
  echo '<h2>Sync with Scouting.org Roster Export</h2>';
  echo '<p class="error">No parsed CSV found in session. Start at Step 1.</p>';
  echo '<p><a class="button" href="/admin_scoutingorg_upload.php">Go to Step 1: Upload</a></p>';
  footer_html();
  exit;
}
if (!$map || !is_array($map)) {
  header_html('Scouting.org Roster Import - Validate');
  echo '<h2>Sync with Scouting.org Roster Export</h2>';
  echo '<p class="error">No column mapping found in session. Complete Step 2 first.</p>';
  echo '<p><a class="button" href="/admin_scoutingorg_map.php">Go to Step 2: Map Fields</a></p>';
  footer_html();
  exit;
}

$headers = $csv['headers'] ?? [];
$rows = $csv['rows'] ?? [];

// Build normalized rows from headers/map
$normalized = [];
foreach ($rows as $r) {
  $normalized[] = ScoutingOrgImport::normalizeRow($headers, $r, $map);
}

// Validate and generate preview
$validated = ScoutingOrgImport::validateAndPreview($normalized);
$_SESSION[$SESSION_KEY_VALID] = $validated;

// Create changes array for debugging as requested - each field change is a separate entry
$changes = [];
foreach ($validated as $rowIndex => $row) {
  if (!empty($row['matched']) && !empty($row['changes'])) {
    $recordId = (int)$row['matched']['id'];
    $recordType = ucfirst($row['type']);
    
    // Create a separate entry for each field change
    foreach ($row['changes'] as $changeIndex => $change) {
      $changeEntry = [
        'type' => $recordType,
        'id' => $recordId,
        'fields' => [
          'name' => $change['field_name'],
          'value' => $change['new_value']
        ]
      ];
      
      $changes[] = $changeEntry;
    }
  }
}

$totalRows = count($validated);
$withMatches = 0;
$withChanges = 0;
$notices = 0;

foreach ($validated as $row) {
  if (!empty($row['matched'])) $withMatches++;
  if (!empty($row['changes'])) $withChanges++;
  if (!empty($row['notices'])) $notices++;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $action = $_POST['action'] ?? '';
  if ($action === 'proceed') {
    header('Location: /admin_scoutingorg_commit.php');
    exit;
  }
}

header_html('Scouting.org Roster Import - Validate');
?>
<h2>Sync with Scouting.org Roster Export</h2>
<p class="small">Step 3 of 4: Validate the parsed and mapped data before committing. Review changes and notices below.</p>

<div class="card">
  <p><strong>Summary:</strong> Total rows: <?= (int)$totalRows ?> &middot; Matched: <?= (int)$withMatches ?> &middot; With changes: <?= (int)$withChanges ?> &middot; With notices: <?= (int)$notices ?></p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <button class="button" name="action" value="proceed" type="submit">Proceed to Commit</button>
    <a class="button" href="/admin_scoutingorg_map.php" style="margin-left:8px;">Back to Mapping</a>
    <a class="button" href="/admin_scoutingorg_upload.php" style="margin-left:8px;">Start Over</a>
  </form>
</div>

<div class="card">
  <h3>Validation Details</h3>
  <div style="overflow:auto;">
    <table class="list">
      <thead>
        <tr>
          <th>#</th>
          <th>Type</th>
          <th>BSA #</th>
          <th>Name</th>
          <th>Email</th>
          <th>Status</th>
          <th>Changes</th>
          <th>Notices</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($validated as $i => $row): ?>
          <?php
            $data = $row['data'];
            $matched = $row['matched'];
            $rowChanges = $row['changes'];
            $notices = $row['notices'];
            $messages = $row['messages'];
            
            $bsaNum = $data[ScoutingOrgImport::DEST_BSA_REG_NUM] ?? '';
            $firstName = $data[ScoutingOrgImport::DEST_FIRST_NAME] ?? '';
            $lastName = $data[ScoutingOrgImport::DEST_LAST_NAME] ?? '';
            $email = $data[ScoutingOrgImport::DEST_EMAIL] ?? '';
            $name = trim($firstName . ' ' . $lastName);
            
            $status = $matched ? 'Matched' : 'No Match';
            $statusClass = $matched ? '' : ' style="color: orange;"';
          ?>
          <tr>
            <td class="small"><?= (int)$i + 1 ?></td>
            <td><strong><?= h(strtoupper($row['type'])) ?></strong></td>
            <td><?= h($bsaNum) ?></td>
            <td><?= h($name ?: '(no name)') ?></td>
            <td><?= h($email) ?></td>
            <td<?= $statusClass ?>><?= h($status) ?></td>
            <td class="small">
              <?php if (!empty($rowChanges)): ?>
                <ul style="margin:0; padding-left:18px;">
                  <?php foreach ($rowChanges as $change): ?>
                    <li><strong><?= h($change['field_name']) ?>:</strong> <?= h($change['new_value']) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <span class="small">No changes</span>
              <?php endif; ?>
            </td>
            <td class="small">
              <?php if (!empty($notices)): ?>
                <ul style="margin:0; padding-left:18px; color: #d9534f;">
                  <?php foreach ($notices as $notice): ?>
                    <li><?= h($notice) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <?php if (!empty($messages)): ?>
                <ul style="margin:0; padding-left:18px; color: #f0ad4e;">
                  <?php foreach ($messages as $message): ?>
                    <li><?= h($message) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h3>Changes Debug Data</h3>
  <div class="small">This is the internal changes array that will be processed during commit (for debugging purposes):</div>
  <textarea rows="20" style="width:100%; font-family: monospace; font-size: 12px;" readonly><?= h(json_encode($changes, JSON_PRETTY_PRINT)) ?></textarea>
</div>

<?php footer_html(); ?>
