<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/UserManagement.php';
require_once __DIR__.'/lib/EmailLog.php';

require_login();

$me = current_user();

// Permission check: Only Key 3 positions (Cubmaster, Committee Chair, Treasurer) can access
if (!\UserManagement::isApprover((int)($me['id'] ?? 0))) {
  http_response_code(403);
  exit('Access restricted to key 3 positions (Cubmaster, Committee Chair, Treasurer)');
}

// Get selected user - support both ?id= (from button) and ?email= (from dropdown)
$selectedUserId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$selectedEmail = isset($_GET['email']) ? trim((string)$_GET['email']) : '';

$selectedUser = null;

// Determine which user we're viewing
if ($selectedUserId > 0) {
  $selectedUser = UserManagement::findFullById($selectedUserId);
  if ($selectedUser) {
    $selectedEmail = $selectedUser['email'] ?? '';
  }
} elseif ($selectedEmail !== '') {
  $selectedUser = UserManagement::findByEmail($selectedEmail);
  if ($selectedUser) {
    $selectedUserId = (int)$selectedUser['id'];
  }
}

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Get emails for selected user
$emails = [];
$totalEmails = 0;

if ($selectedEmail !== '') {
  $filters = ['to_email' => $selectedEmail];
  $emails = EmailLog::list($filters, $perPage, $offset);
  $totalEmails = EmailLog::count($filters);
}

// Calculate pagination
$totalPages = $totalEmails > 0 ? (int)ceil($totalEmails / $perPage) : 1;

// Get all adults for the dropdown
$allAdults = UserManagement::listAllForSelect();

header_html('Email History');
?>

<h2>Email History</h2>

<?php if (isset($_GET['viewed'])): ?>
  <p class="flash">Email viewed.</p>
<?php endif; ?>

<div class="card">
  <h3>Select User</h3>
  <form method="get" action="/adult_email_history.php">
    <div class="grid" style="grid-template-columns:1fr auto;gap:12px;align-items:end;">
      <label>View email history for:
        <select name="email" required onchange="this.form.submit()">
          <option value="">-- Select a user --</option>
          <?php foreach ($allAdults as $adult): ?>
            <?php 
              $adultEmail = $adult['email'] ?? '';
              $adultName = trim(($adult['first_name'] ?? '') . ' ' . ($adult['last_name'] ?? ''));
              $selected = ($adultEmail === $selectedEmail) ? 'selected' : '';
            ?>
            <?php if ($adultEmail !== ''): ?>
              <option value="<?= h($adultEmail) ?>" <?= $selected ?>>
                <?= h($adultName) ?> (<?= h($adultEmail) ?>)
              </option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="button" type="submit">View History</button>
    </div>
  </form>
</div>

<?php if ($selectedUser && $selectedEmail): ?>
  <div class="card" style="margin-top:20px;">
    <h3>Email History for <?= h(trim(($selectedUser['first_name'] ?? '') . ' ' . ($selectedUser['last_name'] ?? ''))) ?></h3>
    <p><strong>Email:</strong> <?= h($selectedEmail) ?></p>
    <p><strong>Total Emails:</strong> <?= (int)$totalEmails ?></p>
    
    <?php if (empty($emails)): ?>
      <p class="small">No emails have been sent to this user.</p>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="list">
          <thead>
            <tr>
              <th>Date</th>
              <th>Subject</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($emails as $email): ?>
              <tr>
                <td><?= h(date('Y-m-d H:i', strtotime($email['created_at']))) ?></td>
                <td>
                  <a href="/adult_email_view.php?id=<?= (int)$email['id'] ?>&return_email=<?= urlencode($selectedEmail) ?>">
                    <?= h($email['subject']) ?>
                  </a>
                </td>
                <td>
                  <?php if (!empty($email['success'])): ?>
                    <span style="color:#28a745;">✓ Sent</span>
                  <?php else: ?>
                    <span style="color:#dc3545;">✗ Failed</span>
                    <?php if (!empty($email['error_message'])): ?>
                      <div class="small" style="color:#dc3545;"><?= h($email['error_message']) ?></div>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td>
                  <a href="/adult_email_view.php?id=<?= (int)$email['id'] ?>&return_email=<?= urlencode($selectedEmail) ?>" class="button small">View</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      
      <?php if ($totalPages > 1): ?>
        <div style="margin-top:20px;display:flex;gap:8px;align-items:center;justify-content:center;">
          <?php if ($page > 1): ?>
            <a href="?email=<?= urlencode($selectedEmail) ?>&page=<?= $page - 1 ?>" class="button">← Previous</a>
          <?php endif; ?>
          
          <span>Page <?= $page ?> of <?= $totalPages ?></span>
          
          <?php if ($page < $totalPages): ?>
            <a href="?email=<?= urlencode($selectedEmail) ?>&page=<?= $page + 1 ?>" class="button">Next →</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php elseif (!empty($_GET['email']) || !empty($_GET['id'])): ?>
  <div class="card" style="margin-top:20px;">
    <p class="error">User not found or has no email address.</p>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
