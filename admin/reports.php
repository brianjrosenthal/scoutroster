<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/UserContext.php';
require_admin();

// Require approver permissions (Key 3 leaders: Cubmaster, Committee Chair, Treasurer)
$ctx = UserContext::getLoggedInUserContext();
if (!$ctx || !UserManagement::isApprover($ctx->id)) {
    http_response_code(403);
    header_html('Reports - Access Denied');
    echo '<div class="card">';
    echo '<h2>Access Denied</h2>';
    echo '<p class="error">This section is only available to Key 3 leaders (Cubmaster, Committee Chair, and Treasurer).</p>';
    echo '<p><a class="button" href="/index.php">Return to Home</a></p>';
    echo '</div>';
    footer_html();
    exit;
}

header_html('Reports');
?>
<div class="card">
  <h2>Reports</h2>
  <p>Administrative reports and oversight tools for Key 3 leaders. These reports help track registrations, payments, and system activity.</p>
</div>

<div class="card">
  <h3>Registration Management</h3>
  <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
    
    <div class="card" style="margin: 0;">
      <h4>Membership Report</h4>
      <p>View all Scouts who have joined or renewed their membership this year, with counts by grade and status.</p>
      <div class="actions">
        <a class="button primary" href="/admin/membership_report.php">View Membership Report</a>
      </div>
    </div>
    
    <div class="card" style="margin: 0;">
      <h4>Pending Registrations</h4>
      <p>Review and process new Scout registration applications submitted by families.</p>
      <div class="actions">
        <a class="button primary" href="/admin/pending_registrations.php">View Pending Registrations</a>
      </div>
    </div>
    
    <div class="card" style="margin: 0;">
      <h4>Registration Renewals</h4>
      <p>Track and manage annual registration renewals for returning Scouts and their families.</p>
      <div class="actions">
        <a class="button primary" href="/admin/registration_renewals.php">View Registration Renewals</a>
      </div>
    </div>
    
    <div class="card" style="margin: 0;">
      <h4>BSA Renewals Needed</h4>
      <p>View adults and youth whose BSA registrations have expired or will expire within 30 days.</p>
      <div class="actions">
        <a class="button primary" href="/admin/renewals_needed.php">View BSA Renewals Needed</a>
      </div>
    </div>
    
    <div class="card" style="margin: 0;">
      <h4>Registered Adult Leaders</h4>
      <p>View all registered adult leaders with their BSA registration numbers, expiration dates, and safeguarding youth training status.</p>
      <div class="actions">
        <a class="button primary" href="/admin/registered_adult_leaders.php">View Registered Adult Leaders</a>
      </div>
    </div>
    
  </div>
</div>

<div class="card">
  <h3>Financial Oversight</h3>
  <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
    
    <div class="card" style="margin: 0;">
      <h4>Payment Notifications</h4>
      <p>Process payment notifications submitted by families for registration fees and activities.</p>
      <div class="actions">
        <a class="button primary" href="/admin/payment_notifications.php">View Payment Notifications</a>
      </div>
    </div>
    
  </div>
</div>

<div class="card">
  <h3>System Monitoring</h3>
  <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
    
    <div class="card" style="margin: 0;">
      <h4>Activity Log</h4>
      <p>Review system activity and administrative actions performed by users and administrators.</p>
      <div class="actions">
        <a class="button primary" href="/admin/activity_log.php">View Activity Log</a>
      </div>
    </div>
    
    <div class="card" style="margin: 0;">
      <h4>Email Log</h4>
      <p>Review all emails sent by the system, including delivery status and error messages for troubleshooting.</p>
      <div class="actions">
        <a class="button primary" href="/admin/email_log.php">View Email Log</a>
      </div>
    </div>
    
  </div>
</div>

<div class="card">
  <h3>Public Links</h3>
  <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
    
    <div class="card" style="margin: 0;">
      <h4>Public Calendar</h4>
      <p>Publicly accessible calendar showing upcoming events. Share this link with prospective families or post on social media.</p>
      <div class="actions">
        <a class="button primary" href="/public_calendar.php" target="_blank">View Public Calendar</a>
      </div>
    </div>
    
    <div class="card" style="margin: 0;">
      <h4>Pack Leadership</h4>
      <p>Publicly accessible page showing pack leadership structure, committee chairs, and den leaders.</p>
      <div class="actions">
        <a class="button primary" href="/leadership.php" target="_blank">View Pack Leadership</a>
      </div>
    </div>
    
  </div>
</div>

<?php footer_html(); ?>
