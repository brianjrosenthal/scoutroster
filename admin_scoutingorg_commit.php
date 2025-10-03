<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/ScoutingOrgImport.php';
require_once __DIR__ . '/lib/UserContext.php';
require_admin();

$SESSION_KEY_CSV = 'scoutingorg_csv';
$SESSION_KEY_MAP = 'scoutingorg_map';
$SESSION_KEY_VALID = 'scoutingorg_validated';

$validated = $_SESSION[$SESSION_KEY_VALID] ?? null;
if (!$validated || !is_array($validated)) {
  header_html('Scouting.org Roster Import - Commit');
  echo '<h2>Sync with Scouting.org Roster Export</h2>';
  echo '<p class="error">No validated rows found in session. Complete validation first.</p>';
  echo '<p><a class="button" href="/admin_scoutingorg_upload.php">Go to Step 1: Upload</a></p>';
  footer_html();
  exit;
}

// Get user context and check permissions
$ctx = UserContext::getLoggedInUserContext();
if ($ctx === null || !$ctx->admin) {
  header_html('Scouting.org Roster Import - Commit');
  echo '<h2>Sync with Scouting.org Roster Export</h2>';
  echo '<p class="error">Permission denied. Admin access required.</p>';
  echo '<p><a class="button" href="/admin_imports.php">Back to Imports</a></p>';
  footer_html();
  exit;
}

// Additional permission check for "Approvers" - using admin for now since that's what's available
// In a future version, this could be enhanced with specific approver roles

$started = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'commit');

header_html('Scouting.org Roster Import - Commit');
?>
<h2>Sync with Scouting.org Roster Export</h2>
<p class="small">Step 4 of 4: Commit the import. This will update adult and youth records and log all changes to the audit table.</p>

<?php if (!$started): ?>
  <div class="card">
    <p><strong>Warning:</strong> This action will write changes to the database and cannot be undone easily.</p>
    <p>All field changes will be logged to the audit table <code>scouting_org_field_changes</code> for tracking purposes.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button class="button" name="action" value="commit" type="submit">Start Import</button>
      <a class="button" href="/admin_scoutingorg_validate.php" style="margin-left:8px;">Back</a>
    </form>
  </div>
<?php else: ?>
  <div class="card">
    <h3>Import Progress</h3>
    <div class="small">Streaming log of commit operations:</div>
    <pre id="log" style="max-height:360px; overflow:auto; background:#f7f7f7; padding:8px; border:1px solid #ddd;"><?php
      require_csrf();

      // Try to stream output progressively
      @set_time_limit(0);
      while (ob_get_level() > 0) { @ob_end_flush(); }
      @ob_implicit_flush(true);

      if ($ctx === null || !$ctx->admin) {
        echo "Error: Missing or invalid user context.\n";
      } else {
        $count = count($validated);
        echo "Starting Scouting.org import of {$count} row(s) ...\n";
        
        if ($ctx->id === null) {
          echo "ERROR: User context has null id - this should not happen\n";
          echo "User context details: admin={$ctx->admin}, id={$ctx->id}\n";
          echo "Import aborted.\n";
          return;
        }
        
        echo "Import will be performed by user ID {$ctx->id}\n\n";
        
        $logger = function(string $msg) {
          echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "\n";
          @flush();
        };
        
        try {
          $results = ScoutingOrgImport::commit($validated, $ctx, $logger);
          
          echo "\n" . str_repeat("=", 50) . "\n";
          echo "Import Summary:\n";
          echo "  Updated records: {$results['updated']}\n";
          echo "  Skipped records: {$results['skipped']}\n";
          echo "  Error records: {$results['errors']}\n";
          echo "\nImport complete.\n";
          
          if ($results['errors'] > 0) {
            echo "\nWarning: Some records had errors. Please review the log above.\n";
          }
          
        } catch (Throwable $e) {
          echo "\nFatal error during import: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "\n";
          echo "Stack trace:\n" . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . "\n";
        }
      }

      // Clear import session state to prevent accidental re-commit
      unset($_SESSION[$SESSION_KEY_CSV], $_SESSION[$SESSION_KEY_MAP], $_SESSION[$SESSION_KEY_VALID]);
    ?></pre>
    <div class="actions" style="margin-top:12px;">
      <a class="button" href="/adults.php">View Adults Roster</a>
      <a class="button" href="/youth.php" style="margin-left:8px;">View Youth Roster</a>
      <a class="button" href="/admin_scoutingorg_upload.php" style="margin-left:8px;">Start New Import</a>
      <a class="button" href="/admin_imports.php" style="margin-left:8px;">Back to Imports</a>
    </div>
  </div>
  
  <div class="card">
    <h3>Audit Trail</h3>
    <div class="small">
      All changes made during this import have been logged to the <code>scouting_org_field_changes</code> table.
      You can query this table to see exactly what was changed:
    </div>
    <pre style="background:#f7f7f7; padding:8px; border:1px solid #ddd; font-size:12px;">
SELECT 
  sofc.*,
  u.first_name as changed_by_first_name,
  u.last_name as changed_by_last_name,
  CASE 
    WHEN sofc.type = 'adult' THEN au.first_name || ' ' || au.last_name
    WHEN sofc.type = 'youth' THEN yu.first_name || ' ' || yu.last_name
  END as affected_person_name
FROM scouting_org_field_changes sofc
LEFT JOIN users u ON sofc.created_by = u.id  
LEFT JOIN users au ON sofc.adult_id = au.id
LEFT JOIN youth yu ON sofc.youth_id = yu.id
WHERE DATE(sofc.created_at) = CURDATE()
ORDER BY sofc.created_at DESC;
    </pre>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
