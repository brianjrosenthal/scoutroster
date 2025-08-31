<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/ImportManagement.php';
require_once __DIR__ . '/lib/UserContext.php';
require_admin();

$csv = $_SESSION['import_csv'] ?? null;
$map = $_SESSION['import_map'] ?? null;

if (!$csv || !is_array($csv) || empty($csv['rows'])) {
  header_html('Import Members - Validate');
  echo '<h2>Import Members</h2>';
  echo '<p class="error">No parsed CSV found in session. Start at Step 1.</p>';
  echo '<p><a class="button" href="/admin_import_upload.php">Go to Step 1: Upload</a></p>';
  footer_html();
  exit;
}
if (!$map || !is_array($map)) {
  header_html('Import Members - Validate');
  echo '<h2>Import Members</h2>';
  echo '<p class="error">No column mapping found in session. Complete Step 2 first.</p>';
  echo '<p><a class="button" href="/admin_import_map.php">Go to Step 2: Map Fields</a></p>';
  footer_html();
  exit;
}

$headers = $csv['headers'] ?? [];
$rows = $csv['rows'] ?? [];

// Build structured rows from headers/map
$structured = [];
foreach ($rows as $r) {
  $structured[] = ImportManagement::normalizeRow($headers, $r, $map);
}
$_SESSION['import_structured'] = $structured;

// Validate
$validated = ImportManagement::validateStructuredRows($structured);
$_SESSION['import_validated'] = $validated;

$total = count($validated);
$errors = 0;
foreach ($validated as $vr) {
  if (empty($vr['ok'])) $errors++;
}
$oks = $total - $errors;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $action = $_POST['action'] ?? '';
  if ($action === 'proceed') {
    if ($errors === 0) {
      header('Location: /admin_import_commit.php');
      exit;
    } else {
      $blockErr = 'Cannot proceed: there are validation errors. Fix your mapping or source data.';
    }
  }
}

header_html('Import Members - Validate');
?>
<h2>Import Members</h2>
<p class="small">Step 3 of 4: Validate the parsed and mapped data before committing.</p>

<div class="card">
  <p><strong>Summary:</strong> Total rows: <?= (int)$total ?> &middot; Valid: <?= (int)$oks ?> &middot; Errors: <?= (int)$errors ?></p>
  <?php if (!empty($blockErr)): ?><p class="error"><?= h($blockErr) ?></p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php if ($errors === 0): ?>
      <button class="button" name="action" value="proceed" type="submit">Proceed to Commit</button>
    <?php else: ?>
      <button class="button" type="submit" disabled>Proceed to Commit</button>
      <a class="button" href="/admin_import_map.php" style="margin-left:8px;">Back to Mapping</a>
    <?php endif; ?>
    <a class="button" href="/admin_import_upload.php" style="margin-left:8px;">Start Over</a>
  </form>
</div>

<div class="card">
  <h3>Details</h3>
  <div style="overflow:auto;">
    <table class="list">
      <thead>
        <tr>
          <th>#</th>
          <th>Status</th>
          <th>Messages</th>
          <th>Youth</th>
          <th>Parent 1</th>
          <th>Parent 2</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($validated as $i => $vr): ?>
          <?php
            $y = $vr['row']['youth'] ?? [];
            $p1 = $vr['row']['p1'] ?? [];
            $p2 = $vr['row']['p2'] ?? [];
            $status = !empty($vr['ok']) ? 'OK' : 'Error';
            $cls = !empty($vr['ok']) ? '' : ' class="error"';
            $msgs = $vr['messages'] ?? [];
            $yName = trim(($y['first_name'] ?? '') . ' ' . ($y['last_name'] ?? ''));
            $yGrade = $y['grade_label'] ?? '';
            $pFmt = function($p) {
              $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
              $parts = [];
              if ($name !== '') $parts[] = $name;
              if (!empty($p['email'])) $parts[] = $p['email'];
              if (!empty($p['phone'])) $parts[] = $p['phone'];
              if (!empty($p['bsa_id'])) $parts[] = 'BSA '.$p['bsa_id'];
              return h(implode(' • ', $parts));
            };
          ?>
          <tr<?= $cls ?>>
            <td class="small"><?= (int)$i + 1 ?></td>
            <td><?= h($status) ?></td>
            <td class="small">
              <?php if ($msgs): ?>
                <ul class="small" style="margin:0; padding-left:18px;">
                  <?php foreach ($msgs as $m): ?>
                    <li><?= h($m) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <span class="small">No messages</span>
              <?php endif; ?>
            </td>
            <td>
              <?= h($yName !== '' ? $yName : '(no name)') ?>
              <?php if ($yGrade !== ''): ?>
                <div class="small">Grade: <?= h($yGrade) ?></div>
              <?php endif; ?>
              <?php if (!empty($y['bsa_registration_number'])): ?>
                <div class="small">BSA: <?= h($y['bsa_registration_number']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= $pFmt($p1) ?></td>
            <td><?= $pFmt($p2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php footer_html(); ?>
