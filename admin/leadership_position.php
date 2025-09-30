<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LeadershipManagement.php';

require_login();

$u = current_user();

// Check access: Cubmaster OR (Admin AND no Cubmaster exists)
$isAdmin = !empty($u['is_admin']);

$msg = null;
$err = null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;

// Load existing position for editing
$position = null;
if ($isEdit) {
    try {
        $position = LeadershipManagement::getPackPosition($id);
        if (!$position) {
            http_response_code(404);
            exit('Position not found');
        }
    } catch (Throwable $e) {
        $err = 'Unable to load position details.';
        $position = null;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    
    $name = trim($_POST['name'] ?? '');
    $sortPriority = (int)($_POST['sort_priority'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    if ($description === '') $description = null;
    
    try {
        $ctx = UserContext::getLoggedInUserContext();
        
        if ($isEdit) {
            // Update existing position
            $success = LeadershipManagement::updatePackPosition($ctx, $id, $name, $sortPriority, $description);
            if ($success) {
                header('Location: /admin/leadership_positions.php?success=' . urlencode("Position '$name' updated successfully."));
                exit;
            } else {
                $err = 'Failed to update position.';
            }
        } else {
            // Create new position
            $newId = LeadershipManagement::createPackPosition($ctx, $name, $sortPriority, $description);
            header('Location: /admin/leadership_positions.php?success=' . urlencode("Position '$name' created successfully."));
            exit;
        }
        
    } catch (InvalidArgumentException $e) {
        $err = $e->getMessage();
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Error in admin/leadership_position.php: ' . $e->getMessage());
        $err = 'An unexpected error occurred while saving the position.';
    }
    
    // If we get here, there was an error - preserve form data
    $position = [
        'name' => $name,
        'sort_priority' => $sortPriority,
        'description' => $description
    ];
}

$pageTitle = $isEdit ? 'Edit Leadership Position' : 'Add Leadership Position';
header_html($pageTitle);
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <h2><?= h($pageTitle) ?></h2>
    <div style="display:flex;gap:8px;">
        <a class="button" href="/admin/leadership_positions.php">Back to Positions</a>
        <?php if ($isEdit && $position): ?>
            <button type="button" class="button danger" onclick="confirmDeletePosition(<?= (int)$id ?>, '<?= h(addslashes($position['name'])) ?>')">Remove Position</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($msg): ?><p class="flash"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?= h($err) ?></p><?php endif; ?>

<div class="card">
    <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        
        <label>Position Name *
            <input 
                type="text" 
                name="name" 
                value="<?= h($position['name'] ?? '') ?>" 
                required 
                maxlength="255"
                placeholder="e.g., Assistant Cubmaster, Secretary">
        </label>
        
        <label>Sort Priority
            <input 
                type="number" 
                name="sort_priority" 
                value="<?= (int)($position['sort_priority'] ?? 0) ?>" 
                min="0" 
                max="999"
                placeholder="0">
            <div class="small">Lower numbers appear first in lists. Use 0 for highest priority.</div>
        </label>
        
        <label>Description
            <textarea 
                name="description" 
                rows="3" 
                placeholder="Optional description of this position's responsibilities..."><?= h($position['description'] ?? '') ?></textarea>
        </label>
        
        <div class="actions">
            <button type="submit" class="button primary">
                <?= $isEdit ? 'Update Position' : 'Create Position' ?>
            </button>
            <a class="button" href="/admin/leadership_positions.php">Cancel</a>
        </div>
    </form>
</div>

<?php if ($isEdit && $position): ?>
    <div class="card" style="margin-top:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <h3 style="margin:0;">Current Assignments</h3>
            <button type="button" class="button" onclick="openAssignmentModal(<?= (int)$id ?>, '<?= h(addslashes($position['name'])) ?>')">Manage Assignments</button>
        </div>
        <?php
        try {
            // Get current assignments for this position
            $sql = "SELECT u.first_name, u.last_name, u.id, alpa.created_at
                    FROM adult_leadership_position_assignments alpa
                    JOIN users u ON u.id = alpa.adult_id
                    WHERE alpa.adult_leadership_position_id = ?
                    ORDER BY u.last_name, u.first_name";
            $st = pdo()->prepare($sql);
            $st->execute([$id]);
            $assignments = $st->fetchAll();
        } catch (Throwable $e) {
            $assignments = [];
        }
        ?>
        
        <?php if (empty($assignments)): ?>
            <p class="small">No one is currently assigned to this position.</p>
        <?php else: ?>
            <table class="list">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Assigned Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignments as $assignment): ?>
                        <tr>
                            <td><?= h(trim($assignment['first_name'] . ' ' . $assignment['last_name'])) ?></td>
                            <td class="small"><?= h(date('M j, Y', strtotime($assignment['created_at']))) ?></td>
                            <td class="small">
                                <a class="button" href="/adult_edit.php?id=<?= (int)$assignment['id'] ?>">View Profile</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="small" style="margin-top:12px;">
                To modify assignments, use the "Edit" button on individual adult profiles.
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Position Assignment Modal -->
<div id="positionModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-content" style="max-width:600px;">
        <button class="close" type="button" id="positionModalClose" aria-label="Close">&times;</button>
        <h3 id="positionModalTitle">Manage Position</h3>
        <div id="positionModalErr" class="error small" style="display:none;"></div>
        <div id="positionModalSuccess" class="flash small" style="display:none;"></div>
        
        <div class="stack">
            <h4>Current Assignments</h4>
            <div id="currentAssignments">
                <!-- Will be populated via JavaScript -->
            </div>
            
            <h4>Add Assignment</h4>
            <div class="grid" style="grid-template-columns:1fr auto;gap:8px;align-items:end;">
                <label>Search Adults
                    <input type="text" id="adultSearch" placeholder="Type adult name...">
                    <div id="adultSearchResults" class="search-results" style="display:none;"></div>
                </label>
                <button type="button" class="button" id="assignBtn" disabled>Assign</button>
            </div>
        </div>
        
        <div class="actions" style="margin-top:24px;">
            <button class="button" type="button" id="positionModalCancel">Close</button>
        </div>
    </div>
</div>

<style>
.search-results {
    position: absolute;
    background: white;
    border: 1px solid #ddd;
    border-top: none;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    width: 100%;
}

.search-result-item {
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 1px solid #eee;
}

.search-result-item:hover {
    background-color: #f5f5f5;
}

.search-result-item.selected {
    background-color: #e3f2fd;
}
</style>

<script>
function confirmDeletePosition(positionId, positionName) {
    var message = "Are you sure you want to delete the '" + positionName + "' position?";
    message += "\n\nThis will remove all current assignments for this position and cannot be undone.";
    
    if (confirm(message)) {
        // Create a form and submit it
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/admin/leadership_position_remove.php';
        form.style.display = 'none';
        
        var csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf';
        csrfInput.value = '<?= h(csrf_token()) ?>';
        form.appendChild(csrfInput);
        
        var idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'position_id';
        idInput.value = positionId;
        form.appendChild(idInput);
        
        var redirectInput = document.createElement('input');
        redirectInput.type = 'hidden';
        redirectInput.name = 'redirect';
        redirectInput.value = '/admin/leadership_positions.php';
        form.appendChild(redirectInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Assignment Modal Functions
(function(){
    var modal = document.getElementById('positionModal');
    var closeBtn = document.getElementById('positionModalClose');
    var cancelBtn = document.getElementById('positionModalCancel');
    var title = document.getElementById('positionModalTitle');
    var err = document.getElementById('positionModalErr');
    var success = document.getElementById('positionModalSuccess');
    var currentAssignments = document.getElementById('currentAssignments');
    var adultSearch = document.getElementById('adultSearch');
    var searchResults = document.getElementById('adultSearchResults');
    var assignBtn = document.getElementById('assignBtn');
    
    var currentPositionId = null;
    var currentPositionName = '';
    var selectedAdultId = null;
    var searchTimeout = null;
    
    function showErr(msg){ 
        if(err){ err.style.display=''; err.textContent = msg || 'Operation failed.'; } 
        if(success){ success.style.display='none'; }
    }
    
    function showSuccess(msg){ 
        if(success){ success.style.display=''; success.textContent = msg || 'Success'; } 
        if(err){ err.style.display='none'; }
    }
    
    function clearMessages(){ 
        if(err){ err.style.display='none'; err.textContent=''; } 
        if(success){ success.style.display='none'; success.textContent=''; }
    }
    
    window.openAssignmentModal = function(positionId, positionName){ 
        currentPositionId = positionId;
        currentPositionName = positionName;
        if(title) title.textContent = 'Manage ' + positionName;
        if(modal){ 
            modal.classList.remove('hidden'); 
            modal.setAttribute('aria-hidden','false'); 
            clearMessages(); 
            loadCurrentAssignments();
        } 
    };
    
    function closeModal(){ 
        if(modal){ 
            modal.classList.add('hidden'); 
            modal.setAttribute('aria-hidden','true'); 
        }
        // Reset form
        if(adultSearch) adultSearch.value = '';
        if(searchResults) searchResults.style.display = 'none';
        selectedAdultId = null;
        if(assignBtn) assignBtn.disabled = true;
        // Refresh page to update assignments display
        window.location.reload();
    }
    
    function loadCurrentAssignments() {
        if(!currentAssignments || !currentPositionId) return;
        currentAssignments.innerHTML = '<p class="small">Loading...</p>';
        
        var formData = new FormData();
        formData.append('csrf', '<?= h(csrf_token()) ?>');
        formData.append('position_id', currentPositionId);
        
        fetch('/position_get_assignments_ajax.php', { 
            method: 'POST', 
            body: formData, 
            credentials: 'same-origin' 
        })
        .then(function(r){ return r.json(); })
        .then(function(json){
            if (json && json.ok) {
                displayCurrentAssignments(json.assignments || []);
            } else {
                currentAssignments.innerHTML = '<p class="error small">Failed to load assignments</p>';
            }
        })
        .catch(function(){
            currentAssignments.innerHTML = '<p class="error small">Network error loading assignments</p>';
        });
    }
    
    function displayCurrentAssignments(assignments) {
        if(!currentAssignments) return;
        
        if(!assignments || assignments.length === 0) {
            currentAssignments.innerHTML = '<p class="small">No adults currently assigned to this position.</p>';
            return;
        }
        
        var html = '<ul class="list">';
        assignments.forEach(function(assignment) {
            html += '<li>' + escapeHtml(assignment.name) + ' <a href="#" onclick="removeAssignment(' + assignment.adult_id + ', \'' + escapeHtml(assignment.name) + '\'); return false;" style="color: #007bff; margin-left: 8px;">Remove</a></li>';
        });
        html += '</ul>';
        currentAssignments.innerHTML = html;
    }
    
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
    // Global function for remove buttons
    window.removeAssignment = function(adultId, adultName) {
        if (!confirm('Remove ' + adultName + ' from ' + currentPositionName + '?')) return;
        
        var formData = new FormData();
        formData.append('csrf', '<?= h(csrf_token()) ?>');
        formData.append('position_id', currentPositionId);
        formData.append('adult_id', adultId);
        
        fetch('/position_remove_assignment_ajax.php', { 
            method: 'POST', 
            body: formData, 
            credentials: 'same-origin' 
        })
        .then(function(r){ return r.json(); })
        .then(function(json){
            if (json && json.ok) {
                showSuccess(json.message || 'Assignment removed successfully');
                loadCurrentAssignments();
            } else {
                showErr((json && json.error) ? json.error : 'Failed to remove assignment.');
            }
        })
        .catch(function(){ showErr('Network error.'); });
    };
    
    // Adult search functionality
    if(adultSearch) {
        adultSearch.addEventListener('input', function() {
            var query = this.value.trim();
            
            if(searchTimeout) {
                clearTimeout(searchTimeout);
            }
            
            if(query.length < 2) {
                if(searchResults) searchResults.style.display = 'none';
                selectedAdultId = null;
                if(assignBtn) assignBtn.disabled = true;
                return;
            }
            
            searchTimeout = setTimeout(function() {
                searchAdults(query);
            }, 300);
        });
        
        adultSearch.addEventListener('blur', function() {
            // Delay hiding results to allow clicks
            setTimeout(function() {
                if(searchResults) searchResults.style.display = 'none';
            }, 200);
        });
    }
    
    function searchAdults(query) {
        var url = '/ajax_search_adults.php?q=' + encodeURIComponent(query) + '&limit=10';
        
        fetch(url, { 
            method: 'GET', 
            credentials: 'same-origin' 
        })
        .then(function(r){ return r.json(); })
        .then(function(json){
            if (json && json.length > 0) {
                displaySearchResults(json);
            } else {
                if(searchResults) {
                    searchResults.innerHTML = '<div class="search-result-item">No adults found</div>';
                    searchResults.style.display = 'block';
                }
            }
        })
        .catch(function(){
            if(searchResults) {
                searchResults.innerHTML = '<div class="search-result-item">Search error</div>';
                searchResults.style.display = 'block';
            }
        });
    }
    
    function displaySearchResults(adults) {
        if(!searchResults) return;
        
        var html = '';
        adults.forEach(function(adult) {
            html += '<div class="search-result-item" data-adult-id="' + adult.id + '" data-adult-name="' + escapeHtml(adult.first_name + ' ' + adult.last_name) + '">';
            html += escapeHtml(adult.first_name + ' ' + adult.last_name);
            if(adult.email) html += ' (' + escapeHtml(adult.email) + ')';
            html += '</div>';
        });
        
        searchResults.innerHTML = html;
        searchResults.style.display = 'block';
        
        // Add click handlers
        var items = searchResults.querySelectorAll('.search-result-item');
        items.forEach(function(item) {
            item.addEventListener('click', function() {
                selectedAdultId = parseInt(this.getAttribute('data-adult-id'));
                var selectedName = this.getAttribute('data-adult-name');
                if(adultSearch) adultSearch.value = selectedName;
                searchResults.style.display = 'none';
                if(assignBtn) assignBtn.disabled = false;
            });
        });
    }
    
    // Assign button functionality
    if(assignBtn) {
        assignBtn.addEventListener('click', function() {
            if(!selectedAdultId || !currentPositionId) {
                showErr('Please select an adult.');
                return;
            }
            
            var formData = new FormData();
            formData.append('csrf', '<?= h(csrf_token()) ?>');
            formData.append('position_id', currentPositionId);
            formData.append('adult_id', selectedAdultId);
            
            fetch('/position_assign_ajax.php', { 
                method: 'POST', 
                body: formData, 
                credentials: 'same-origin' 
            })
            .then(function(r){ return r.json(); })
            .then(function(json){
                if (json && json.ok) {
                    showSuccess(json.message || 'Assignment successful');
                    loadCurrentAssignments();
                    // Reset form
                    if(adultSearch) adultSearch.value = '';
                    selectedAdultId = null;
                    assignBtn.disabled = true;
                } else {
                    showErr((json && json.error) ? json.error : 'Failed to assign position.');
                }
            })
            .catch(function(){ showErr('Network error.'); });
        });
    }
    
    // Event listeners for modal
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
})();
</script>

<?php footer_html(); ?>
