<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/LeadershipManagement.php';
require_once __DIR__ . '/lib/GradeCalculator.php';

// This is a public page - no login required
// But we'll show logged-in navigation if user is authenticated
$user = current_user_optional();

try {
    // Get pack positions with assignments
    $packPositions = LeadershipManagement::getPackLeadershipForDisplay();
    $denLeaders = LeadershipManagement::getDenLeadersForDisplay();
} catch (Throwable $e) {
    $packPositions = [];
    $denLeaders = [];
}

// Separate positions by priority
$keyPositions = []; // sort_priority <= 2
$otherPositions = []; // sort_priority > 2
$openPositions = []; // no assignments

foreach ($packPositions as $position) {
    $sortPriority = (int)$position['sort_priority'];
    $hasAssignments = !empty($position['holders']);
    
    if ($sortPriority <= 2) {
        if ($hasAssignments) {
            $keyPositions[] = $position;
        } else {
            $openPositions[] = $position;
        }
    } else {
        if ($hasAssignments) {
            $otherPositions[] = $position;
        } else {
            $openPositions[] = $position;
        }
    }
}

// Sort other positions by sort_priority
usort($otherPositions, function($a, $b) {
    return (int)$a['sort_priority'] - (int)$b['sort_priority'];
});

header_html('Pack Leadership', $user);
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <h2>Pack Leadership</h2>
    <?php if ($user && !empty($user['is_admin'])): ?>
        <button type="button" class="button" onclick="exportLeaderEmails()">Export Leaders' Emails</button>
    <?php endif; ?>
</div>

<?php if (!empty($keyPositions)): ?>
    <div class="card" style="margin-bottom:16px;">
        <?php foreach ($keyPositions as $position): ?>
            <div style="margin-bottom:8px;">
                <strong><?= h($position['name']) ?>:</strong>
                <?php
                    $names = [];
                    foreach ($position['holders'] as $holder) {
                        $names[] = trim($holder['first_name'] . ' ' . $holder['last_name']);
                    }
                    echo h(implode(', ', $names));
                ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>


<?php if (!empty($otherPositions)): ?>
    <div class="card" style="margin-bottom:16px;">
        <h3>Program Chairs and Committees</h3>
        <?php foreach ($otherPositions as $position): ?>
            <div style="margin-bottom:8px;">
                <strong><?= h($position['name']) ?>:</strong>
                <?php
                    $names = [];
                    foreach ($position['holders'] as $holder) {
                        $names[] = trim($holder['first_name'] . ' ' . $holder['last_name']);
                    }
                    echo h(implode(', ', $names));
                ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($denLeaders)): ?>
    <div class="card" style="margin-bottom:16px;">
        <h3>Den Leaders</h3>
        <?php
        // Sort den leaders by grade descending (5 to K)
        $sortedDenLeaders = $denLeaders;
        krsort($sortedDenLeaders);
        
        foreach ($sortedDenLeaders as $classOf => $denInfo):
            $gradeLabel = $denInfo['grade'] === 0 ? 'Kindergarten (Lions)' : "Grade {$denInfo['grade']}";
            
            // Add grade-specific labels
            switch ($denInfo['grade']) {
                case 5: $gradeLabel .= ' (AOL\'s)'; break;
                case 4: $gradeLabel .= ' (Webelos)'; break;
                case 3: $gradeLabel .= ' (Bears)'; break;
                case 2: $gradeLabel .= ' (Wolves)'; break;
                case 1: $gradeLabel .= ' (Tigers)'; break;
            }
        ?>
            <div style="margin-bottom:8px;">
                <strong>Den Leader <?= h($gradeLabel) ?>:</strong>
                <?php
                    $names = [];
                    foreach ($denInfo['leaders'] as $leader) {
                        $names[] = trim($leader['first_name'] . ' ' . $leader['last_name']);
                    }
                    echo h(implode(', ', $names));
                ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>


<?php if (!empty($openPositions)): ?>
    <div class="card" style="margin-bottom:16px;">
        <h3>Open Positions</h3>
        <p>These positions are currently available and looking for volunteers.</p>
        <?php foreach ($openPositions as $position): ?>
            <div style="margin-bottom:12px;">
                <strong><?= h($position['name']) ?></strong>
                <?php if (!empty($position['description'])): ?>
                    <br><span><?= h($position['description']) ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (empty($keyPositions) && empty($denLeaders) && empty($otherPositions) && empty($openPositions)): ?>
    <div class="card">
        <p>No leadership positions have been configured yet.</p>
    </div>
<?php endif; ?>

<?php if ($user && !empty($user['is_admin'])): ?>
<!-- Email Export Modal -->
<div id="emailExportModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-content" style="max-width:600px;">
        <button class="close" type="button" id="emailExportModalClose" aria-label="Close">&times;</button>
        <h3>Leadership Email Addresses</h3>
        <div id="emailExportError" class="error" style="display:none;"></div>
        <div id="emailExportSuccess" class="flash" style="display:none;"></div>
        
        <div class="stack">
            <div id="emailExportCount" style="margin-bottom:8px;font-weight:bold;"></div>
            <label>Email Addresses (one per line)
                <textarea id="emailExportTextarea" rows="15" readonly style="width:100%;font-family:monospace;"></textarea>
            </label>
        </div>
        
        <div class="actions" style="margin-top:16px;">
            <button class="button primary" type="button" id="selectAllEmailsBtn">Select All</button>
            <button class="button" type="button" id="emailExportModalCancel">Close</button>
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
</style>

<script>
function exportLeaderEmails() {
    const modal = document.getElementById('emailExportModal');
    const closeBtn = document.getElementById('emailExportModalClose');
    const cancelBtn = document.getElementById('emailExportModalCancel');
    const selectAllBtn = document.getElementById('selectAllEmailsBtn');
    const textarea = document.getElementById('emailExportTextarea');
    const errorDiv = document.getElementById('emailExportError');
    const successDiv = document.getElementById('emailExportSuccess');
    const countDiv = document.getElementById('emailExportCount');

    function showError(msg) {
        if (errorDiv) {
            errorDiv.style.display = '';
            errorDiv.textContent = msg || 'Failed to fetch emails.';
        }
        if (successDiv) successDiv.style.display = 'none';
    }

    function showSuccess(msg) {
        if (successDiv) {
            successDiv.style.display = '';
            successDiv.textContent = msg || 'Success';
        }
        if (errorDiv) errorDiv.style.display = 'none';
    }

    function clearMessages() {
        if (errorDiv) {
            errorDiv.style.display = 'none';
            errorDiv.textContent = '';
        }
        if (successDiv) {
            successDiv.style.display = 'none';
            successDiv.textContent = '';
        }
    }

    function closeModal() {
        if (modal) {
            modal.classList.add('hidden');
            modal.setAttribute('aria-hidden', 'true');
        }
    }

    // Open modal
    if (modal) {
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        clearMessages();

        // Show loading state
        if (textarea) textarea.value = 'Loading...';
        if (countDiv) countDiv.textContent = '';

        // Fetch emails via AJAX
        fetch('/leadership_emails_export_ajax.php', {
            method: 'GET',
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.ok) {
                if (textarea) textarea.value = data.emails.join('\n');
                if (countDiv) countDiv.textContent = data.count + ' email address' + (data.count === 1 ? '' : 'es') + ' found';
                showSuccess('Email addresses loaded successfully');
            } else {
                showError(data.error || 'Failed to fetch emails');
                if (textarea) textarea.value = '';
            }
        })
        .catch(error => {
            console.error('Email export error:', error);
            showError('Network error while fetching emails');
            if (textarea) textarea.value = '';
        });
    }

    // Event listeners
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            if (textarea) {
                textarea.select();
                textarea.setSelectionRange(0, 99999); // For mobile devices
                try {
                    document.execCommand('copy');
                    showSuccess('Email addresses copied to clipboard');
                } catch (err) {
                    // Fallback - just select the text
                    showSuccess('Email addresses selected - press Ctrl+C to copy');
                }
            }
        });
    }

    // Close modal when clicking outside
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal();
        });
    }
}
</script>
<?php endif; ?>

<?php footer_html(); ?>
