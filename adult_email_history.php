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

<style>
.typeahead {
  position: relative;
}
.typeahead-results {
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  background: white;
  border: 1px solid #ced4da;
  border-top: none;
  max-height: 300px;
  overflow-y: auto;
  z-index: 1000;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.typeahead-result {
  padding: 8px 12px;
  cursor: pointer;
  border-bottom: 1px solid #e9ecef;
}
.typeahead-result:hover {
  background: #f8f9fa;
}
.typeahead-result:last-child {
  border-bottom: none;
}
.selected-user-display {
  padding: 12px;
  background: #e9ecef;
  border-radius: 4px;
  margin-top: 8px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
</style>

<div class="card">
  <h3>Select User</h3>
  
  <?php if ($selectedUser && $selectedEmail): ?>
    <div class="selected-user-display">
      <div>
        <strong>Viewing email history for:</strong> 
        <?= h(trim(($selectedUser['first_name'] ?? '') . ' ' . ($selectedUser['last_name'] ?? ''))) ?> 
        (<?= h($selectedEmail) ?>)
      </div>
      <button type="button" id="changeUserBtn" class="button">Change User</button>
    </div>
    <div id="userSearchContainer" style="display:none;">
  <?php else: ?>
    <div id="userSearchContainer">
  <?php endif; ?>
      <label>Search for a user:
        <div class="typeahead">
          <input type="text" id="adultSearch" placeholder="Type name or email..." autocomplete="off" style="width:100%;">
          <div id="searchResults" class="typeahead-results" style="display:none;"></div>
        </div>
      </label>
      <p class="small">Start typing to search for an adult by name or email address.</p>
    </div>
</div>

<script>
(function() {
  const searchInput = document.getElementById('adultSearch');
  const searchResults = document.getElementById('searchResults');
  const changeUserBtn = document.getElementById('changeUserBtn');
  const userSearchContainer = document.getElementById('userSearchContainer');
  let searchTimeout = null;
  
  // Show search when "Change User" is clicked
  if (changeUserBtn) {
    changeUserBtn.addEventListener('click', function() {
      userSearchContainer.style.display = 'block';
      searchInput.focus();
    });
  }
  
  // Typeahead search
  if (searchInput) {
    searchInput.addEventListener('input', function() {
      const query = this.value.trim();
      clearTimeout(searchTimeout);
      
      if (query.length < 2) {
        searchResults.style.display = 'none';
        return;
      }
      
      searchTimeout = setTimeout(() => {
        fetch('/ajax_search_adults.php?q=' + encodeURIComponent(query))
          .then(response => response.json())
          .then(data => {
            searchResults.innerHTML = '';
            
            if (data.length === 0) {
              searchResults.innerHTML = '<div class="typeahead-result">No adults found</div>';
            } else {
              data.forEach(adult => {
                const div = document.createElement('div');
                div.className = 'typeahead-result';
                const displayName = adult.last_name + ', ' + adult.first_name;
                const displayEmail = adult.email ? ' <' + adult.email + '>' : '';
                div.textContent = displayName + displayEmail;
                
                div.addEventListener('click', function() {
                  if (adult.email) {
                    // Redirect to email history for this user
                    window.location.href = '/adult_email_history.php?email=' + encodeURIComponent(adult.email);
                  } else {
                    alert('This user has no email address on file.');
                  }
                });
                
                searchResults.appendChild(div);
              });
            }
            
            searchResults.style.display = 'block';
          })
          .catch(error => {
            console.error('Error searching adults:', error);
            searchResults.innerHTML = '<div class="typeahead-result">Error searching</div>';
            searchResults.style.display = 'block';
          });
      }, 300);
    });
    
    // Hide results when clicking outside
    document.addEventListener('click', function(e) {
      if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
        searchResults.style.display = 'none';
      }
    });
  }
})();
</script>

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
                  <a href="/adult_email_view.php?id=<?= (int)$email['id'] ?>&return_email=<?= urlencode($selectedEmail) ?>" class="button">View</a>
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
