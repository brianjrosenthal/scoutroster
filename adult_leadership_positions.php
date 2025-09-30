<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/LeadershipManagement.php';
require_once __DIR__.'/lib/UserManagement.php';

// This is a public page - no login required
// But we'll show logged-in navigation if user is authenticated
$user = current_user_optional();
$ctx = UserContext::getLoggedInUserContext();

// Check for edit mode and approver status
$editMode = !empty($_GET['edit']);
$canEdit = $ctx && UserManagement::isApprover((int)$ctx->id);

$packPositions = LeadershipManagement::getPackLeadershipForDisplay();
$denLeaders = LeadershipManagement::getDenLeadersForDisplay();

header_html('Pack Leadership', $user);
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <h2>Pack Leadership Positions</h2>
    <?php if ($canEdit && !$editMode): ?>
        <a class="button" href="?edit=1">Edit Mode</a>
    <?php elseif ($canEdit && $editMode): ?>
        <a class="button" href="?">View Mode</a>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Pack Leadership</h3>
    <?php if (empty($packPositions)): ?>
        <p class="small">No pack leadership positions defined.</p>
    <?php else: ?>
        <div class="stack">
            <?php foreach ($packPositions as $position): ?>
                <div class="leadership-position">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                        <h4 style="margin:0;"><?= h($position['name']) ?></h4>
                        <?php if ($canEdit && $editMode): ?>
                            <a href="#" class="edit-position-link" data-position-id="<?= (int)$position['id'] ?>" data-position-name="<?= h($position['name']) ?>">Edit</a>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($position['description'])): ?>
                        <p class="small"><?= h($position['description']) ?></p>
                    <?php endif; ?>
                    
                    <?php if (empty($position['holders'])): ?>
                        <p class="small"><em>Position currently vacant</em></p>
                    <?php else: ?>
                        <div class="leadership-holders">
                            <?php foreach ($position['holders'] as $holder): ?>
                                <div class="person-card">
                                    <?php
                                        require_once __DIR__.'/lib/Files.php';
                                        $photoUrl = Files::profilePhotoUrl($holder['photo_public_file_id'] ?? null);
                                        $fullName = trim($holder['first_name'] . ' ' . $holder['last_name']);
                                        $initials = strtoupper(substr($holder['first_name'], 0, 1) . substr($holder['last_name'], 0, 1));
                                    ?>
                                    <div class="person-photo">
                                        <?php if ($photoUrl): ?>
                                            <img class="avatar" src="<?= h($photoUrl) ?>" alt="<?= h($fullName) ?>" style="width:60px;height:60px">
                                        <?php else: ?>
                                            <div class="avatar avatar-initials" aria-hidden="true" style="width:60px;height:60px;font-size:16px"><?= h($initials) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="person-name">
                                        <?= h($fullName) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Den Leaders</h3>
    <?php if (empty($denLeaders)): ?>
        <p class="small">No den leader assignments.</p>
    <?php else: ?>
        <div class="stack">
            <?php foreach ($denLeaders as $den): ?>
                <div class="leadership-position">
                    <h4>Grade <?= $den['grade'] === 0 ? 'K' : $den['grade'] ?> Den Leaders</h4>
                    
                    <div class="leadership-holders">
                        <?php foreach ($den['leaders'] as $leader): ?>
                            <div class="person-card">
                                <?php
                                    require_once __DIR__.'/lib/Files.php';
                                    $photoUrl = Files::profilePhotoUrl($leader['photo_public_file_id'] ?? null);
                                    $fullName = trim($leader['first_name'] . ' ' . $leader['last_name']);
                                    $initials = strtoupper(substr($leader['first_name'], 0, 1) . substr($leader['last_name'], 0, 1));
                                ?>
                                <div class="person-photo">
                                    <?php if ($photoUrl): ?>
                                        <img class="avatar" src="<?= h($photoUrl) ?>" alt="<?= h($fullName) ?>" style="width:60px;height:60px">
                                    <?php else: ?>
                                        <div class="avatar avatar-initials" aria-hidden="true" style="width:60px;height:60px;font-size:16px"><?= h($initials) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="person-name">
                                    <?= h($fullName) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.leadership-position {
    margin-bottom: 24px;
}

.leadership-holders {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 12px;
}

.person-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    min-width: 120px;
}

.person-photo {
    margin-bottom: 8px;
}

.person-name {
    font-weight: 500;
    font-size: 14px;
}
</style>

<!-- Position Assignment Modal -->
<?php if ($canEdit): ?>
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
    
    function openModal(positionId, positionName){ 
        currentPositionId = positionId;
        currentPositionName = positionName;
        if(title) title.textContent = 'Manage ' + positionName;
        if(modal){ 
            modal.classList.remove('hidden'); 
            modal.setAttribute('aria-hidden','false'); 
            clearMessages(); 
            loadCurrentAssignments();
        } 
    }
    
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
        var formData = new FormData();
        formData.append('q', query);
        formData.append('limit', '10');
        
        fetch('/ajax_search_adults.php', { 
            method: 'POST', 
            body: formData, 
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
    
    // Event listeners for edit links
    var editLinks = document.querySelectorAll('.edit-position-link');
    editLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var positionId = parseInt(this.getAttribute('data-position-id'));
            var positionName = this.getAttribute('data-position-name');
            openModal(positionId, positionName);
        });
    });
})();
</script>
<?php endif; ?>

<?php footer_html(); ?>
