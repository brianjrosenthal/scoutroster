<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/settings.php';
require_admin();

header_html('Import Data');
?>
<div class="card">
  <h2>Import Data</h2>
  <p class="small">Use these tools to import or update data in bulk. Each flow guides you through Upload → Map → Validate → Commit.</p>
</div>

<div class="card">
  <h3>Import Members</h3>
  <p>
    Add or update Adults and Youth from a CSV export. You will:
  </p>
  <ol class="small">
    <li>Upload a CSV file</li>
    <li>Map your columns to fields in the system</li>
    <li>Validate rows and review any warnings/errors</li>
    <li>Commit the import to create and/or update records</li>
  </ol>
  <div class="actions">
    <a class="button primary" href="/admin_import_upload.php">Open Import Members</a>
  </div>
</div>

<div class="card">
  <h3>Registration Dates Import</h3>
  <p>
    Update BSA Registration expiration dates in bulk from the official scouting.org export. This flow does not change other fields.
  </p>
  <ol class="small">
    <li>Upload the registration export</li>
    <li>Map columns</li>
    <li>Validate and review changes</li>
    <li>Commit to update expiration dates</li>
  </ol>
  <div class="actions">
    <a class="button" href="/admin_regdates_upload.php">Open Reg Dates Import</a>
  </div>
</div>

<div class="card">
  <h3>Sync with Scouting.org Roster Export</h3>
  <p>
    Sync adult and youth records with the official Scouting.org roster export. Updates registration dates, safeguarding training, and contact information while preserving existing data.
  </p>
  <ol class="small">
    <li>Upload the Scouting.org roster report (detects header automatically)</li>
    <li>Map columns to database fields (smart defaults applied)</li>
    <li>Validate changes and review notices</li>
    <li>Commit updates with full audit logging</li>
  </ol>
  <div class="actions">
    <a class="button primary" href="/admin_scoutingorg_upload.php">Open Scouting.org Sync</a>
  </div>
  <div class="small" style="margin-top:8px;">
    <strong>Features:</strong> Adult/Youth detection, intelligent matching, audit trail, discrepancy reporting
  </div>
</div>

<?php footer_html(); ?>
