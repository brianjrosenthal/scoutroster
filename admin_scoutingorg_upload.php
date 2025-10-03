<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/ScoutingOrgImport.php';
require_admin();

$msg = null;
$err = null;

// Use separate session keys to avoid collisions with other imports
$SESSION_KEY_CSV = 'scoutingorg_csv';
$SESSION_KEY_MAP = 'scoutingorg_map';
$SESSION_KEY_VALID = 'scoutingorg_validated';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  
  // Determine delimiter
  $delimSel = $_POST['delimiter'] ?? 'tab';
  $delimiter = "\t";
  if ($delimSel === 'comma') $delimiter = ',';
  if ($delimSel === 'semicolon') $delimiter = ';';

  // Prefer uploaded file if provided, else textarea
  $csvText = '';
  if (!empty($_FILES['csv_file']) && isset($_FILES['csv_file']['error']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
    $tmp = $_FILES['csv_file']['tmp_name'];
    $csvText = @file_get_contents($tmp) ?: '';
  }
  if ($csvText === '') {
    $csvText = trim($_POST['csv_text'] ?? '');
  }

  if ($csvText === '') {
    $err = 'Please upload a CSV file or paste CSV text.';
  } else {
    try {
      $parsed = ScoutingOrgImport::parseCsvToRows($csvText, $delimiter);

      // Reset import session state and store parsed data
      $_SESSION[$SESSION_KEY_CSV] = [
        'headers' => $parsed['headers'] ?? [],
        'rows' => $parsed['rows'] ?? [],
        'raw' => $csvText,
        'delimiter' => $delimSel,
      ];
      unset($_SESSION[$SESSION_KEY_MAP], $_SESSION[$SESSION_KEY_VALID]);

      header('Location: /admin_scoutingorg_map.php');
      exit;
    } catch (Throwable $e) {
      $err = 'Unable to parse Scouting.org roster file: ' . $e->getMessage();
    }
  }
}

header_html('Scouting.org Roster Import - Upload');
?>
<h2>Sync with Scouting.org Roster Export</h2>
<p class="small">Step 1 of 4: Upload your Scouting.org roster export file. The system will automatically detect the header line starting with "..memberid".</p>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">

    <div class="field">
      <label for="csv_file">Roster Report File (optional):</label>
      <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv,.txt">
      <div class="small">If a file is selected, it will be used instead of the pasted text.</div>
    </div>

    <div class="field">
      <label for="csv_text">Paste Roster Data (optional):</label>
      <textarea id="csv_text" name="csv_text" rows="10" style="width:100%;"></textarea>
      <div class="small">Paste raw roster report text here if not uploading a file.</div>
    </div>

    <div class="field">
      <label>Delimiter:</label>
      <label><input type="radio" name="delimiter" value="tab" checked> Tab (\t) - Default for Scouting.org</label>
      <label style="margin-left:12px;"><input type="radio" name="delimiter" value="comma"> Comma (,)</label>
      <label style="margin-left:12px;"><input type="radio" name="delimiter" value="semicolon"> Semicolon (;)</label>
    </div>

    <div class="actions">
      <button class="button" type="submit">Parse Roster File</button>
      <a class="button" href="/admin_imports.php" style="margin-left:8px;">Cancel</a>
    </div>

    <div class="small" style="margin-top:12px;">
      <strong>About Scouting.org Roster Reports:</strong>
      <ul>
        <li>Export your roster from <strong>scouting.org</strong> as a "Roster Report"</li>
        <li>The file should contain lines before the actual data - these will be ignored</li>
        <li>The header line must start with <code>..memberid</code> - this will be detected automatically</li>
        <li>Tab-delimited format is the standard export format from scouting.org</li>
        <li>Adults and Youth will be identified by their <strong>Position Name</strong> field</li>
        <li>Youth are identified when Position Name = "Youth Member"</li>
      </ul>
      <div style="margin-top:8px;">
        <strong>Expected header fields include:</strong><br>
        <code>..memberid, firstname, lastname, positionname, stryptcompletiondate, stryptexpirationdate, streetaddress, city, statecode, zip9, primaryemail, primaryphone, expirydtstr</code>
      </div>
    </div>
  </form>
</div>

<?php footer_html(); ?>
