<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/ImportManagement.php';
require_once __DIR__ . '/lib/UserContext.php';
require_admin();

$validated = $_SESSION['import_validated'] ?? null;
if (!$validated || !is_array($validated)) {
  header_html('Import Members - Commit');
  echo '<h2>Import Members</h2>';
  echo '<p class="error">No validated rows found in session. Complete validation first.</p>';
  echo '<p><a class="button" href="/admin_import_upload.php">Go to Step 1: Upload</a></p>';
  footer_html();
  exit;
}

$hasErrors = false;
foreach ($validated as $vr) {
  if (empty($vr['ok'])) { $hasErrors = true; break; }
}

if ($hasErrors) {
  header_html('Import Members - Commit');
  echo '<h2>Import Members</h2>';
  echo '<p class="error">Cannot commit: there are validation errors. Return to validation and resolve them.</p>';
  echo '<p><a class="button" href="/admin_import_validate.php">Back to Step 3: Validate</a></p>';
  footer_html();
  exit;
}

$started = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'commit');

header_html('Import Members - Commit');
?>
<h2>Import Members</h2>
<p class="small">Step 4 of 4: Commit the import. This will create or update youth and adults, and link parent relationships.</p>

<?php if (!$started): ?>
  <div class="card">
    <p>This action will write changes to the database.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button class="button" name="action" value="commit" type="submit">Start Import</button>
      <a class="button" href="/admin_import_validate.php" style="margin-left:8px;">Back</a>
    </form>
  </div>
<?php else: ?>
  <div class="card">
    <h3>Progress</h3>
    <div class="small">Streaming log of commit operations:</div>
    <pre id="log" style="max-height:360px; overflow:auto; background:#f7f7f7; padding:8px; border:1px solid #ddd;"><?php
      require_csrf();

      // Try to stream output progressively
      @set_time_limit(0);
      while (ob_get_level() > 0) { @ob_end_flush(); }
      @ob_implicit_flush(true);

      $ctx = UserContext::getLoggedInUserContext();
      if ($ctx === null || !$ctx->admin) {
        echo "Error: Missing or invalid user context.\n";
      } else {
        $count = count($validated);
        echo "Starting import of {$count} row(s) ...\n";
        $logger = function(string $msg) {
          echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "\n";
          @flush();
        };
        try {
          ImportManagement::commit($validated, $ctx, $logger);
          echo "Import complete.\n";
        } catch (Throwable $e) {
          echo "Fatal error during import: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "\n";
        }
      }

      // Clear import session state to prevent accidental re-commit
      unset($_SESSION['import_csv'], $_SESSION['import_map'], $_SESSION['import_structured'], $_SESSION['import_validated']);
    ?></pre>
    <div class="actions" style="margin-top:12px;">
      <a class="button" href="/youth.php">Go to Youth Roster</a>
      <a class="button" href="/admin_adults.php" style="margin-left:8px;">Manage Adults</a>
      <a class="button" href="/admin_import_upload.php" style="margin-left:8px;">Start New Import</a>
    </div>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
