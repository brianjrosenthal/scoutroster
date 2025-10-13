<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/UserContext.php';
require_once __DIR__ . '/../lib/MembershipReport.php';
require_admin();

// Require approver permissions (Key 3 leaders: Cubmaster, Committee Chair, Treasurer)
$ctx = UserContext::getLoggedInUserContext();
if (!$ctx || !UserManagement::isApprover($ctx->id)) {
    http_response_code(403);
    header_html('Membership Report - Access Denied');
    echo '<div class="card">';
    echo '<h2>Access Denied</h2>';
    echo '<p class="error">This section is only available to Key 3 leaders (Cubmaster, Committee Chair, and Treasurer).</p>';
    echo '<p><a class="button" href="/index.php">Return to Home</a></p>';
    echo '</div>';
    footer_html();
    exit;
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csv = MembershipReport::exportToCSV();
    $filename = 'membership_report_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo $csv;
    exit;
}

// Get membership data
$counts = MembershipReport::getMembershipCounts();
$membersByGrade = MembershipReport::getMembersByGrade();

header_html('Membership Report');
?>
<div class="card">
  <h2>Membership Report</h2>
  <p>This report shows all Scouts who have joined or renewed their membership this year. Members are counted based on BSA registration status, payment notifications, or pending registrations.</p>
  
  <div class="actions">
    <a class="button" href="?export=csv">Export to CSV</a>
    <a class="button secondary" href="/admin/reports.php">Back to Reports</a>
  </div>
</div>

<div class="card">
  <h3>Membership Summary</h3>
  <p><strong>Total Members: <?php echo $counts['total']; ?></strong></p>
  
  <?php if (!empty($counts['by_grade'])): ?>
    <h4>By Grade:</h4>
    <ul style="list-style: none; padding-left: 0;">
      <?php
      // Sort grades in descending order (5, 4, 3, 2, 1, K)
      $grades = $counts['by_grade'];
      uksort($grades, function($a, $b) {
        // Handle K specially
        if ($a === 'K') return 1;
        if ($b === 'K') return -1;
        // Handle Pre-K
        if (strpos($a, 'Pre-K') === 0) return 1;
        if (strpos($b, 'Pre-K') === 0) return -1;
        // Numeric comparison (descending)
        return (int)$b - (int)$a;
      });
      
      foreach ($grades as $grade => $count): ?>
        <li style="margin: 4px 0;">
          <strong>Grade <?php echo htmlspecialchars($grade); ?>:</strong> 
          <?php echo $count; ?> <?php echo $count === 1 ? 'Scout' : 'Scouts'; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Members by Grade</h3>
  
  <?php if (empty($membersByGrade)): ?>
    <p>No members found matching the criteria.</p>
  <?php else: ?>
    <?php
    // Sort grades in descending order
    uksort($membersByGrade, function($a, $b) {
      if ($a === 'K') return 1;
      if ($b === 'K') return -1;
      if (strpos($a, 'Pre-K') === 0) return 1;
      if (strpos($b, 'Pre-K') === 0) return -1;
      return (int)$b - (int)$a;
    });
    
    foreach ($membersByGrade as $grade => $members): ?>
      <h4>Grade <?php echo htmlspecialchars($grade); ?></h4>
      <table class="data-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>BSA Registration ID</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($members as $member): ?>
            <tr>
              <td>
                <a href="/youth_edit.php?id=<?php echo $member['id']; ?>">
                  <?php echo htmlspecialchars($member['display_name'] . ' ' . $member['last_name']); ?>
                </a>
              </td>
              <td>
                <?php 
                if (!empty($member['bsa_registration_number'])) {
                  echo htmlspecialchars($member['bsa_registration_number']);
                } else {
                  echo '<em>pending</em>';
                }
                ?>
              </td>
              <td>
                <?php if ($member['status'] === 'active'): ?>
                  <span class="badge success">Active</span>
                <?php else: ?>
                  <span class="badge warning">Processing</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Membership Criteria</h3>
  <p>A Scout is counted as a member if any of the following conditions are met:</p>
  <ul>
    <li>Has a BSA Registration ID and registration expiration date after June 1st of next year</li>
    <li>Has a non-deleted payment notification submitted since last June 1st</li>
    <li>Has a non-deleted pending registration created since last June 1st</li>
    <li>Has a BSA Registration ID and "paid until" date after June 1st of next year</li>
  </ul>
  
  <h4>Status Definitions</h4>
  <ul>
    <li><strong>Active:</strong> Both "paid until" and BSA registration expiration dates are after June 1st of next year</li>
    <li><strong>Processing:</strong> Has payment notification or pending registration, but registration is not yet fully active</li>
  </ul>
</div>

<?php footer_html(); ?>
