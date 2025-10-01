<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LeadershipManagement.php';

require_login();

$u = current_user();

// Check access: Cubmaster OR (Admin AND no Cubmaster exists)
$isAdmin = !empty($u['is_admin']);


$msg = null;
$err = null;

// Check for success/error messages
if (isset($_GET['success'])) {
    $msg = trim($_GET['success']);
}
if (isset($_GET['error'])) {
    $err = trim($_GET['error']);
}

try {
    $positions = LeadershipManagement::listPackPositionsWithCounts();
    $denLeaders = LeadershipManagement::getDenLeadersForDisplay();
} catch (Throwable $e) {
    $err = 'Unable to load leadership positions.';
    $positions = [];
    $denLeaders = [];
}

// Prepare den leaders data by grade for the table
$denLeadersByGrade = [];
foreach ($denLeaders as $classOf => $denInfo) {
    $denLeadersByGrade[$denInfo['grade']] = $denInfo['leaders'];
}

header_html('Manage Leadership Positions');
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <h2>Leadership Positions</h2>
    <?php if ($isAdmin): ?>
        <a class="button" href="/admin/leadership_position.php">Add New Position</a>
    <?php endif; ?>
</div>

<?php if ($msg): ?><p class="flash"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?= h($err) ?></p><?php endif; ?>

<div class="card">
    <?php if (empty($positions)): ?>
        <p>No leadership positions defined.</p>
    <?php else: ?>
        <table class="list">
            <thead>
                <tr>
                    <th>Position Name</th>
                    <?php if ($isAdmin): ?>
                        <th>Sort Priority</th>
                    <?php endif; ?>
                    <th>Description</th>
                    <th>Assigned</th>
                    <?php if ($isAdmin): ?>
                        <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($positions as $pos): ?>
                    <tr>
                        <td><strong><?= h($pos['name']) ?></strong></td>
                        <?php if ($isAdmin): ?>
                            <td><?= (int)$pos['sort_priority'] ?></td>
                        <?php endif; ?>
                        <td><?= nl2br(h($pos['description'] ?? '')) ?></td>
                        <td>
                            <?php 
                                $count = (int)$pos['assignment_count'];
                                if ($count === 0) {
                                    echo '<em>----</em>';
                                } else {
                                    // Get the actual names of assigned people
                                    try {
                                        $sql = "SELECT u.first_name, u.last_name
                                                FROM adult_leadership_position_assignments alpa
                                                JOIN users u ON u.id = alpa.adult_id
                                                WHERE alpa.adult_leadership_position_id = ?
                                                ORDER BY u.last_name, u.first_name";
                                        $st = pdo()->prepare($sql);
                                        $st->execute([(int)$pos['id']]);
                                        $assignees = $st->fetchAll();
                                        
                                        $names = [];
                                        foreach ($assignees as $assignee) {
                                            $names[] = h(trim($assignee['first_name'] . ' ' . $assignee['last_name']));
                                        }
                                        
                                        if (count($names) === 1) {
                                            echo $names[0];
                                        } else {
                                            echo implode('<br>', $names);
                                        }
                                    } catch (Throwable $e) {
                                        echo $count . ($count === 1 ? ' person' : ' people');
                                    }
                                }
                            ?>
                        </td>
                        <?php if ($isAdmin): ?>
                            <td class="small">
                                <a href="/admin/leadership_position.php?id=<?= (int)$pos['id'] ?>">Edit</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card" style="margin-top:16px;">
    <h3>Den Leaders</h3>
    <table class="list">
        <thead>
            <tr>
                <th>Grade</th>
                <th>Den Leaders</th>
                <?php if ($isAdmin): ?>
                    <th>Actions</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php
            // Show all grades from 5 down to K, even if empty
            $grades = [5, 4, 3, 2, 1, 0];
            foreach ($grades as $grade):
                // Get grade label with descriptive name
                switch ($grade) {
                    case 5: $gradeLabel = 'Grade 5 (AOL\'s)'; break;
                    case 4: $gradeLabel = 'Grade 4 (Webelos)'; break;
                    case 3: $gradeLabel = 'Grade 3 (Bears)'; break;
                    case 2: $gradeLabel = 'Grade 2 (Wolves)'; break;
                    case 1: $gradeLabel = 'Grade 1 (Tigers)'; break;
                    case 0: $gradeLabel = 'Kindergarten (Lions)'; break;
                    default: $gradeLabel = "Grade $grade"; break;
                }
                
                // Get den leaders for this grade
                $leaders = $denLeadersByGrade[$grade] ?? [];
            ?>
                <tr>
                    <td><strong><?= h($gradeLabel) ?></strong></td>
                    <td>
                        <?php if (empty($leaders)): ?>
                            <em>----</em>
                        <?php else: ?>
                            <?php
                                $names = [];
                                foreach ($leaders as $leader) {
                                    $names[] = h(trim($leader['first_name'] . ' ' . $leader['last_name']));
                                }
                                echo implode(',<br>', $names);
                            ?>
                        <?php endif; ?>
                    </td>
                    <?php if ($isAdmin): ?>
                        <td class="small">
                            <a href="#" class="edit-den-leaders-link" data-grade="<?= $grade ?>" data-grade-label="<?= h($gradeLabel) ?>">Edit</a>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card" style="margin-top:16px;">
    <h3>About Leadership Positions</h3>
    <p class="small">
        <?php if ($isAdmin): ?>
            Leadership positions can be assigned to adults through their individual profile pages. 
            When you remove a position, all current assignments for that position will also be removed. 
            Den Leader positions are managed separately from pack leadership positions.
        <?php else: ?>
            This page shows the current pack leadership positions and who holds each role. 
            Den Leader positions are managed separately from pack leadership positions.
        <?php endif; ?>
    </p>
</div>

<!-- Den Leaders Management Modal -->
<?php if ($isAdmin): ?>
<div id="denLeadersModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-content" style="max-width:600px;">
        <button class="close" type="button" id="denLeadersModalClose" aria-label="Close">&times;</button>
        <h3 id="denLeadersModalTitle">Manage Den Leaders</h3>
        <div id="denLeadersModalErr" class="error small" style="display:none;"></div>
        <div id="denLeadersModalSuccess" class="flash small" style="display:none;"></div>
        
        <div class="stack">
            <h4>Current Den Leaders</h4>
            <div id="currentDenLeaders">
                <!-- Will be populated via JavaScript -->
            </div>
            
            <h4>Add Den Leader</h4>
            <div class="grid" style="grid-template-columns:1fr auto;gap:8px;align-items:end;">
                <label>Search Adults
                    <input type="text" id="denAdultSearch" placeholder="Type adult name...">
                    <div id="denAdultSearchResults" class="search-results" style="display:none;"></div>
                </label>
                <button type="button" class="button" id="denAssignBtn" disabled>Assign</button>
            </div>
        </div>
        
        <div class="actions" style="margin-top:24px;">
            <button class="button" type="button" id="denLeadersModalCancel">Close</button>
        </div>
    </div>
</div>

<style>
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal.hidden {
    display: none;
}

.modal-content {
    background: white;
    padding: 24px;
    border-radius: 8px;
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.modal .close {
    position: absolute;
    top: 8px;
    right: 12px;
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    padding: 4px;
    line-height: 1;
}

.modal .close:hover {
    background: #f0f0f0;
    border-radius: 4px;
}

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
<?php endif; ?>

<script>
function confirmDelete(positionId, positionName, assignmentCount) {
    var message = "Are you sure you want to delete the '" + positionName + "' position?";
    if (assignmentCount > 0) {
        message += "\n\nThis will remove " + assignmentCount + " current assignment" + (assignmentCount === 1 ? "" : "s") + " and cannot be undone.";
    } else {
        message += "\n\nThis action cannot be undone.";
    }
    
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

<?php if ($isAdmin): ?>
// Den Leaders Modal functionality
(function(){
    var modal = document.getElementById('denLeadersModal');
    var closeBtn = document.getElementById('denLeadersModalClose');
    var cancelBtn = document.getElementById('denLeadersModalCancel');
    var title = document.getElementById('denLeadersModalTitle');
    var err = document.getElementById('denLeadersModalErr');
    var success = document.getElementById('denLeadersModalSuccess');
    var currentDenLeaders = document.getElementById('currentDenLeaders');
    var adultSearch = document.getElementById('denAdultSearch');
    var searchResults = document.getElementById('denAdultSearchResults');
    var assignBtn = document.getElementById('denAssignBtn');
    
    var currentGrade = null;
    var currentGradeLabel = '';
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
    
    function openDenLeadersModal(grade, gradeLabel){ 
        currentGrade = grade;
        currentGradeLabel = gradeLabel;
        if(title) title.textContent = 'Manage ' + gradeLabel + ' Den Leaders';
        if(modal){ 
            modal.classList.remove('hidden'); 
            modal.setAttribute('aria-hidden','false'); 
            clearMessages(); 
            loadCurrentDenLeaders();
        } 
    }
    
    function closeDenLeadersModal(){ 
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
    
    function loadCurrentDenLeaders() {
        if(!currentDenLeaders || currentGrade === null) return;
        currentDenLeaders.innerHTML = '<p class="small">Loading...</p>';
        
        var formData = new FormData();
        formData.append('csrf', '<?= h(csrf_token()) ?>');
        formData.append('grade', currentGrade);
        
        fetch('/den_leaders_get_by_grade_ajax.php', { 
            method: 'POST', 
            body: formData, 
            credentials: 'same-origin' 
        })
        .then(function(r){ return r.json(); })
        .then(function(json){
            if (json && json.ok) {
                displayCurrentDenLeaders(json.leaders || []);
            } else {
                currentDenLeaders.innerHTML = '<p class="error small">Failed to load den leaders</p>';
            }
        })
        .catch(function(){
            currentDenLeaders.innerHTML = '<p class="error small">Network error loading den leaders</p>';
        });
    }
    
    function displayCurrentDenLeaders(leaders) {
        if(!currentDenLeaders) return;
        
        if(!leaders || leaders.length === 0) {
            currentDenLeaders.innerHTML = '<p class="small">No adults currently assigned as den leaders for this grade.</p>';
            return;
        }
        
        var html = '<ul class="list">';
        leaders.forEach(function(leader) {
            html += '<li>' + escapeHtml(leader.name) + ' <a href="#" onclick="removeDenLeader(' + leader.adult_id + ', \'' + escapeHtml(leader.name) + '\'); return false;" style="color: #007bff; margin-left: 8px;">Remove</a></li>';
        });
        html += '</ul>';
        currentDenLeaders.innerHTML = html;
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
    window.removeDenLeader = function(adultId, adultName) {
        if (!confirm('Remove ' + adultName + ' as den leader for ' + currentGradeLabel + '?')) return;
        
        var formData = new FormData();
        formData.append('csrf', '<?= h(csrf_token()) ?>');
        formData.append('grade', currentGrade);
        formData.append('adult_id', adultId);
        
        fetch('/den_leader_remove_by_grade_ajax.php', { 
            method: 'POST', 
            body: formData, 
            credentials: 'same-origin' 
        })
        .then(function(r){ return r.json(); })
        .then(function(json){
            if (json && json.ok) {
                showSuccess(json.message || 'Den leader removed successfully');
                loadCurrentDenLeaders();
                // Reload the main page to update the table
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                showErr((json && json.error) ? json.error : 'Failed to remove den leader.');
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
        var url = '/ajax_search_adults.php?q=' + encodeURIComponent(query);
        
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
            if(!selectedAdultId || currentGrade === null) {
                showErr('Please select an adult.');
                return;
            }
            
            var formData = new FormData();
            formData.append('csrf', '<?= h(csrf_token()) ?>');
            formData.append('grade', currentGrade);
            formData.append('adult_id', selectedAdultId);
            
            fetch('/den_leader_assign_by_grade_ajax.php', { 
                method: 'POST', 
                body: formData, 
                credentials: 'same-origin' 
            })
            .then(function(r){ return r.json(); })
            .then(function(json){
                if (json && json.ok) {
                    showSuccess(json.message || 'Den leader assigned successfully');
                    loadCurrentDenLeaders();
                    // Reset form
                    if(adultSearch) adultSearch.value = '';
                    selectedAdultId = null;
                    assignBtn.disabled = true;
                    // Reload the main page to update the table
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showErr((json && json.error) ? json.error : 'Failed to assign den leader.');
                }
            })
            .catch(function(){ showErr('Network error.'); });
        });
    }
    
    // Event listeners for modal
    if (closeBtn) closeBtn.addEventListener('click', closeDenLeadersModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeDenLeadersModal);
    if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeDenLeadersModal(); });
    
    // Event listeners for edit den leaders links
    var editDenLeadersLinks = document.querySelectorAll('.edit-den-leaders-link');
    editDenLeadersLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var grade = parseInt(this.getAttribute('data-grade'));
            var gradeLabel = this.getAttribute('data-grade-label');
            openDenLeadersModal(grade, gradeLabel);
        });
    });
})();
<?php endif; ?>
</script>

<?php footer_html(); ?>
