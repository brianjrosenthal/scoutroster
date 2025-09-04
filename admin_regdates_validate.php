<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/ImportRegDates.php';
require_admin();

$SESSION_KEY_CSV   = 'regdates_csv';
$SESSION_KEY_MAP   = 'regdates_map';
$SESSION_KEY_VALID = 'regdates_validated';

$csv = $_SESSION[$SESSION_KEY_CSV] ?? null;
$map = $_SESSION[$SESSION_KEY_MAP] ?? null;

if (!$csv || !is_array($csv) || empty($csv['rows']) || !$map || !is_array($map)) {
  header_html('Registration Dates Import - Validate');
  echo '<h2>Registration Dates Import</h2>';
  echo '<p class="error">No parsed/mapped CSV found in session. Please start at Step 1.</p>';
  echo '<p><a class="button" href="/admin_regdates_upload.php">Go to Step 1: Upload</a></p>';
  footer_html();
  exit;
}

$headers = $csv['headers'] ?? [];
$rows    = $csv['rows'] ?? [];

// Validate
$validated = ImportRegDates::validate($headers, $rows, $map);
$_SESSION[$SESSION_KEY_VALID] = $validated;

// Summaries
$total = count($validated);
$errors = 0; $warnings = 0; $oks = 0;
foreach ($validated as $vr) {
  if (!$vr['ok']) { $errors++; continue; }
  $w = (int)count($vr['warnings'] ?? []);
  if ($w > 0) { $warnings++; }
  else { $oks++; }
}

header_html('Registration Dates Import - Validate');
?>
<h2>Registration Dates Import</h2>
<p class="small">Step 3 of 4: Review validation. You can proceed to commit only if there are no errors.</p>

<div class="card">
  <p><strong>Summary:</strong></p>
  <ul class="small">
    <li>Total rows: <?= (int)$total ?></li>
    <li>OK: <?= (int)$oks ?></li>
    <li>Warnings: <?= (int)$warnings ?> (allowed; name mismatches do not block import)</li>
    <li>Errors: <?= (int)$errors ?> (must be resolved)</li>
  </ul>
</div>

<div class="card">
  <h3>Details</h3>
  <div style="overflow:auto; max-height: 420px;">
    <table class="list">
      <thead>
        <tr>
          <th>#</th>
          <th>BSA #</th>
          <th>First</th>
          <th>Last</th>
          <th>Expires</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($validated as $i => $vr): ?>
          <?php
            $n = $vr['normalized'] ?? [];
            $rowNo = (int)$i + 1;
            $statusBits = [];
            $cls = 'small';
            if (!$vr['ok']) {
              $cls .= ' error';
            } elseif (!empty($vr['warnings'])) {
              $cls .= ' flash';
            }
            foreach (($vr['messages'] ?? []) as $m) $statusBits[] = h((string)$m);
            foreach (($vr['warnings'] ?? []) as $w) $statusBits[] = h((string)$w);
            if (empty($statusBits)) $statusBits[] = 'OK';
          ?>
          <tr>
            <td class="small"><?= $rowNo ?></td>
            <td><?= h((string)($n['bsa'] ?? '')) ?></td>
            <td><?= h((string)($n['first'] ?? '')) ?></td>
            <td><?= h((string)($n['last'] ?? '')) ?></td>
            <td><?= h((string)($n['expires'] ?? '')) ?></td>
            <td class="<?= $cls ?>"><?= implode('<br>', $statusBits) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="actions" style="margin-top:12px;">
    <?php if ($errors === 0): ?>
      <a class="button primary" href="/admin_regdates_commit.php">Commit</a>
    <?php else: ?>
      <button class="button" disabled>Commit</button>
      <span class="small" style="margin-left:8px;">Resolve errors to proceed.</span>
    <?php endif; ?>
    <a class="button" href="/admin_regdates_map.php" style="margin-left:8px;">Back</a>
  </div>
</div>

<?php footer_html(); ?>
