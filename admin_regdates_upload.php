<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/ImportManagement.php';
require_admin();

$msg = null;
$err = null;

// Use separate session keys from the main member import to avoid collisions
$SESSION_KEY_CSV = 'regdates_csv';
$SESSION_KEY_MAP = 'regdates_map';
$SESSION_KEY_VALID = 'regdates_validated';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  // Determine delimiter
  $delimSel = $_POST['delimiter'] ?? 'comma';
  $delimiter = ',';
  if ($delimSel === 'tab') $delimiter = "\t";
  if ($delimSel === 'semicolon') $delimiter = ';';

  $hasHeader = isset($_POST['has_header']) && $_POST['has_header'] === '1';

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
      $parsed = ImportManagement::parseCsvToRows($csvText, $hasHeader, $delimiter);

      // Reset import session state and store parsed data (in regdates-specific keys)
      $_SESSION[$SESSION_KEY_CSV] = [
        'headers' => $parsed['headers'] ?? [],
        'rows' => $parsed['rows'] ?? [],
        'raw' => $csvText,
        'has_header' => $hasHeader ? 1 : 0,
        'delimiter' => $delimSel,
      ];
      unset($_SESSION[$SESSION_KEY_MAP], $_SESSION[$SESSION_KEY_VALID]);

      header('Location: /admin_regdates_map.php');
      exit;
    } catch (Throwable $e) {
      $err = 'Unable to parse CSV. Please check your delimiter and header settings.';
    }
  }
}

header_html('Registration Dates Import - Upload');
?>
<h2>Registration Dates Import</h2>
<p class="small">Step 1 of 4: Upload or paste your CSV file. You can choose a delimiter and whether the first row contains headers.</p>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">

    <div class="field">
      <label for="csv_file">CSV File (optional):</label>
      <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv">
      <div class="small">If a file is selected, it will be used instead of the pasted text.</div>
    </div>

    <div class="field">
      <label for="csv_text">Paste CSV (optional):</label>
      <textarea id="csv_text" name="csv_text" rows="10" style="width:100%;"></textarea>
      <div class="small">Paste raw CSV text here if not uploading a file.</div>
    </div>

    <div class="field">
      <label>Delimiter:</label>
      <label><input type="radio" name="delimiter" value="comma" checked> Comma (,)</label>
      <label style="margin-left:12px;"><input type="radio" name="delimiter" value="tab"> Tab (\t)</label>
      <label style="margin-left:12px;"><input type="radio" name="delimiter" value="semicolon"> Semicolon (;)</label>
    </div>

    <div class="field">
      <label><input type="checkbox" name="has_header" value="1" checked> First row contains headers</label>
    </div>

    <div class="actions">
      <button class="button" type="submit">Parse CSV</button>
      <a class="button" href="/admin_settings.php" style="margin-left:8px;">Cancel</a>
    </div>

    <div class="small" style="margin-top:12px;">
      Expected columns (you can map them in the next step):
      <ul>
        <li><code>youth_bsa_registration_number</code> (required)</li>
        <li><code>youth_first_name</code> (optional, used for mismatch warnings)</li>
        <li><code>youth_last_name</code> (optional, used for mismatch warnings)</li>
        <li><code>youth_registration_expires_date</code> (required)</li>
      </ul>
      <div>Dates should be YYYY-MM-DD; we also accept some common formats and convert to YYYY-MM-DD.</div>
    </div>
  </form>
</div>

<?php footer_html(); ?>
