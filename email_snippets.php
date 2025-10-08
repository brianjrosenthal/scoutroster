<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/EmailSnippets.php';
require_once __DIR__ . '/lib/UserContext.php';

// Require login
require_login();

// Check if user is an approver (key 3 position holder)
$u = current_user();
if (!UserManagement::isApprover((int)$u['id'])) {
  http_response_code(403);
  exit('Access restricted to key 3 positions (Cubmaster, Committee Chair, Treasurer)');
}

$ctx = UserContext::getLoggedInUserContext();

// Get all snippets
try {
  $snippets = EmailSnippets::listSnippets($ctx);
} catch (Exception $e) {
  $error = 'Error loading snippets: ' . h($e->getMessage());
  $snippets = [];
}

header_html('Email Snippets');
?>

<?php if (!empty($error)): ?>
<p class="error"><?= h($error) ?></p>
<?php endif; ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
  <h2>Saved Snippets</h2>
  <button id="addSnippetBtn" style="padding: 8px 16px;">Add Snippet</button>
</div>

<?php if (empty($snippets)): ?>
<p style="color: #666;">No snippets saved yet. Click "Add Snippet" to create your first one.</p>
<?php else: ?>
<div class="snippets-list">
  <?php foreach ($snippets as $snippet): ?>
  <div class="snippet-item" style="margin-bottom: 20px; padding: 12px; border: 1px solid #ddd; border-radius: 4px;">
    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
      <div style="flex: 1;">
        <div>
          <strong><?= h($snippet['name']) ?></strong>
          <span style="color: #666; font-size: 14px;">(<?= h($snippet['sort_order']) ?>)</span>
        </div>
        <div style="margin-top: 8px; white-space: pre-wrap; font-family: monospace; background: #f5f5f5; padding: 8px; border-radius: 4px;"><?= h($snippet['value']) ?></div>
      </div>
      <div style="margin-left: 16px;">
        <a href="#" class="edit-snippet-link" data-id="<?= h($snippet['id']) ?>">Edit</a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Add Snippet Modal -->
<div id="addSnippetModal" class="modal hidden" aria-hidden="true">
  <div class="modal-content">
    <button class="close" id="addSnippetModalClose" aria-label="Close">&times;</button>
    <h3>Add Snippet</h3>
    <form id="addSnippetForm">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
      
      <div style="margin-bottom: 16px;">
        <label for="add_name" style="display: block; margin-bottom: 4px; font-weight: bold;">Name:</label>
        <input type="text" id="add_name" name="name" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
      </div>
      
      <div style="margin-bottom: 16px;">
        <label for="add_value" style="display: block; margin-bottom: 4px; font-weight: bold;">Value:</label>
        <textarea id="add_value" name="value" required rows="6" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-family: monospace;"></textarea>
      </div>
      
      <div id="addSnippetError" class="error" style="display: none; margin-bottom: 16px;"></div>
      
      <div style="display: flex; gap: 8px; justify-content: flex-end;">
        <button type="button" id="addSnippetCancelBtn" style="padding: 8px 16px;">Cancel</button>
        <button type="submit" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px;">Add Snippet</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Snippet Modal -->
<div id="editSnippetModal" class="modal hidden" aria-hidden="true">
  <div class="modal-content">
    <button class="close" id="editSnippetModalClose" aria-label="Close">&times;</button>
    <h3>Edit Snippet</h3>
    <form id="editSnippetForm">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" id="edit_id" name="id">
      
      <div style="margin-bottom: 16px;">
        <label for="edit_name" style="display: block; margin-bottom: 4px; font-weight: bold;">Name:</label>
        <input type="text" id="edit_name" name="name" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
      </div>
      
      <div style="margin-bottom: 16px;">
        <label for="edit_value" style="display: block; margin-bottom: 4px; font-weight: bold;">Value:</label>
        <textarea id="edit_value" name="value" required rows="6" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-family: monospace;"></textarea>
      </div>
      
      <div style="margin-bottom: 16px;">
        <label for="edit_sort_order" style="display: block; margin-bottom: 4px; font-weight: bold;">Sort Order:</label>
        <input type="number" id="edit_sort_order" name="sort_order" required min="0" style="width: 100px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
      </div>
      
      <div id="editSnippetError" class="error" style="display: none; margin-bottom: 16px;"></div>
      
      <div style="display: flex; gap: 8px; justify-content: space-between;">
        <button type="button" id="deleteSnippetBtn" style="padding: 8px 16px; background: #dc3545; color: white; border: none; border-radius: 4px;">Delete</button>
        <div style="display: flex; gap: 8px;">
          <button type="button" id="editSnippetCancelBtn" style="padding: 8px 16px;">Cancel</button>
          <button type="submit" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px;">Save Changes</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
(function() {
  // Modal elements
  const addModal = document.getElementById('addSnippetModal');
  const editModal = document.getElementById('editSnippetModal');
  
  // Add modal controls
  const addBtn = document.getElementById('addSnippetBtn');
  const addCloseBtn = document.getElementById('addSnippetModalClose');
  const addCancelBtn = document.getElementById('addSnippetCancelBtn');
  const addForm = document.getElementById('addSnippetForm');
  const addError = document.getElementById('addSnippetError');
  
  // Edit modal controls
  const editCloseBtn = document.getElementById('editSnippetModalClose');
  const editCancelBtn = document.getElementById('editSnippetCancelBtn');
  const editForm = document.getElementById('editSnippetForm');
  const editError = document.getElementById('editSnippetError');
  const deleteBtn = document.getElementById('deleteSnippetBtn');
  
  // Open add modal
  function openAddModal() {
    addForm.reset();
    addError.style.display = 'none';
    addModal.classList.remove('hidden');
    addModal.setAttribute('aria-hidden', 'false');
  }
  
  // Close add modal
  function closeAddModal() {
    addModal.classList.add('hidden');
    addModal.setAttribute('aria-hidden', 'true');
  }
  
  // Open edit modal with snippet data
  function openEditModal(snippetId) {
    editError.style.display = 'none';
    
    // Fetch snippet data
    fetch('/email_snippets_get.php?id=' + snippetId)
      .then(response => response.json())
      .then(data => {
        if (data.error) {
          alert('Error loading snippet: ' + data.error);
          return;
        }
        
        document.getElementById('edit_id').value = data.id;
        document.getElementById('edit_name').value = data.name;
        document.getElementById('edit_value').value = data.value;
        document.getElementById('edit_sort_order').value = data.sort_order;
        
        editModal.classList.remove('hidden');
        editModal.setAttribute('aria-hidden', 'false');
      })
      .catch(err => {
        alert('Error loading snippet: ' + err.message);
      });
  }
  
  // Close edit modal
  function closeEditModal() {
    editModal.classList.add('hidden');
    editModal.setAttribute('aria-hidden', 'true');
  }
  
  // Event listeners
  if (addBtn) addBtn.addEventListener('click', openAddModal);
  if (addCloseBtn) addCloseBtn.addEventListener('click', closeAddModal);
  if (addCancelBtn) addCancelBtn.addEventListener('click', closeAddModal);
  
  if (editCloseBtn) editCloseBtn.addEventListener('click', closeEditModal);
  if (editCancelBtn) editCancelBtn.addEventListener('click', closeEditModal);
  
  // Close modals on outside click
  if (addModal) {
    addModal.addEventListener('click', function(e) {
      if (e.target === addModal) closeAddModal();
    });
  }
  
  if (editModal) {
    editModal.addEventListener('click', function(e) {
      if (e.target === editModal) closeEditModal();
    });
  }
  
  // Close modals on Escape key
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      if (!addModal.classList.contains('hidden')) closeAddModal();
      if (!editModal.classList.contains('hidden')) closeEditModal();
    }
  });
  
  // Edit links
  document.querySelectorAll('.edit-snippet-link').forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      const snippetId = this.getAttribute('data-id');
      openEditModal(snippetId);
    });
  });
  
  // Add form submission
  if (addForm) {
    addForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const formData = new FormData(addForm);
      
      fetch('/email_snippets_create.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.error) {
          addError.textContent = data.error;
          addError.style.display = 'block';
        } else {
          // Success - reload page
          window.location.reload();
        }
      })
      .catch(err => {
        addError.textContent = 'Error: ' + err.message;
        addError.style.display = 'block';
      });
    });
  }
  
  // Edit form submission
  if (editForm) {
    editForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const formData = new FormData(editForm);
      
      fetch('/email_snippets_update.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.error) {
          editError.textContent = data.error;
          editError.style.display = 'block';
        } else {
          // Success - reload page
          window.location.reload();
        }
      })
      .catch(err => {
        editError.textContent = 'Error: ' + err.message;
        editError.style.display = 'block';
      });
    });
  }
  
  // Delete button
  if (deleteBtn) {
    deleteBtn.addEventListener('click', function() {
      if (!confirm('Are you sure you want to delete this snippet? This action cannot be undone.')) {
        return;
      }
      
      const snippetId = document.getElementById('edit_id').value;
      const formData = new FormData();
      formData.append('id', snippetId);
      formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
      
      fetch('/email_snippets_delete.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.error) {
          editError.textContent = data.error;
          editError.style.display = 'block';
        } else {
          // Success - reload page
          window.location.reload();
        }
      })
      .catch(err => {
        editError.textContent = 'Error: ' + err.message;
        editError.style.display = 'block';
      });
    });
  }
})();
</script>

<?php footer_html(); ?>
