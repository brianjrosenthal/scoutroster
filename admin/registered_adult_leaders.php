<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/UserContext.php';
require_once __DIR__ . '/../lib/Reports.php';
require_admin();

// Require approver permissions (Key 3 leaders: Cubmaster, Committee Chair, Treasurer)
$ctx = UserContext::getLoggedInUserContext();
if (!$ctx || !UserManagement::isApprover($ctx->id)) {
    http_response_code(403);
    header_html('Registered Adult Leaders - Access Denied');
    echo '<div class="card">';
    echo '<h2>Access Denied</h2>';
    echo '<p class="error">This report is only available to Key 3 leaders (Cubmaster, Committee Chair, and Treasurer).</p>';
    echo '<p><a class="button" href="/admin/reports.php">Return to Reports</a></p>';
    echo '</div>';
    footer_html();
    exit;
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csv = Reports::exportRegisteredAdultLeadersCSV();
    $filename = 'registered_adult_leaders_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

// Get the registered adult leaders data
$leaders = Reports::getRegisteredAdultLeaders();

header_html('Registered Adult Leaders Report');
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Registered Adult Leaders Report</h2>
  <a class="button" href="/admin/reports.php">Back to Reports</a>
</div>

<div class="card">
  <p>This report shows all adults who have a BSA registration number on file, indicating they are registered leaders with the BSA.</p>
  <div style="margin-top:12px;">
    <a class="button primary" href="?export=csv">Export to CSV</a>
  </div>
</div>

<?php if (empty($leaders)): ?>
  <div class="card">
    <p class="small">No registered adult leaders found.</p>
  </div>
<?php else: ?>
  <div class="card">
    <p class="small"><strong>Total Registered Adult Leaders:</strong> <?= count($leaders) ?></p>
    <table class="list">
      <thead>
        <tr>
          <th>Name</th>
          <th>BSA Registration Number</th>
          <th>BSA Registration Expiration</th>
          <th>Safeguarding Youth Expires</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($leaders as $leader): 
          $name = trim(($leader['first_name'] ?? '') . ' ' . ($leader['last_name'] ?? ''));
          $bsaNumber = $leader['bsa_membership_number'] ?? '';
          $bsaExpires = $leader['bsa_registration_expires_on'] ?? null;
          $safeguardingExpires = $leader['safeguarding_training_expires_on'] ?? null;
          
          // Format dates
          $bsaExpiresFormatted = $bsaExpires ? date('M j, Y', strtotime($bsaExpires)) : '—';
          $safeguardingExpiresFormatted = $safeguardingExpires ? date('M j, Y', strtotime($safeguardingExpires)) : '—';
          
          // Check if dates are expired or expiring soon
          $today = time();
          $bsaExpiresClass = '';
          $safeguardingExpiresClass = '';
          
          if ($bsaExpires) {
            $bsaExpiresTime = strtotime($bsaExpires);
            if ($bsaExpiresTime < $today) {
              $bsaExpiresClass = 'style="color:#d32f2f;font-weight:bold;"'; // Expired - red
            } elseif ($bsaExpiresTime < strtotime('+30 days')) {
              $bsaExpiresClass = 'style="color:#f57c00;font-weight:bold;"'; // Expiring soon - orange
            }
          }
          
          if ($safeguardingExpires) {
            $safeguardingExpiresTime = strtotime($safeguardingExpires);
            if ($safeguardingExpiresTime < $today) {
              $safeguardingExpiresClass = 'style="color:#d32f2f;font-weight:bold;"'; // Expired - red
            } elseif ($safeguardingExpiresTime < strtotime('+30 days')) {
              $safeguardingExpiresClass = 'style="color:#f57c00;font-weight:bold;"'; // Expiring soon - orange
            }
          }
          ?>
          <tr>
            <td><?= h($name) ?></td>
            <td><?= h($bsaNumber) ?></td>
            <td <?= $bsaExpiresClass ?>><?= $bsaExpiresFormatted ?></td>
            <td <?= $safeguardingExpiresClass ?>><?= $safeguardingExpiresFormatted ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  
  <div class="card">
    <p class="small"><strong>Legend:</strong></p>
    <ul class="small">
      <li><span style="color:#d32f2f;font-weight:bold;">Red</span> = Expired</li>
      <li><span style="color:#f57c00;font-weight:bold;">Orange</span> = Expires within 30 days</li>
      <li>Black = Valid or no date on file</li>
    </ul>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
