<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LeadershipManagement.php';

require_login();

$u = current_user();
if (empty($u['is_admin'])) {
    http_response_code(403);
    exit('Admin access required');
}

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
} catch (Throwable $e) {
    $err = 'Unable to load leadership positions.';
    $positions = [];
}

header_html('Manage Leadership Positions');
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <h2>Leadership Positions</h2>
    <a class="button" href="/admin/leadership_position.php">Add New Position</a>
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
                    <th>Sort Priority</th>
                    <th>Description</th>
                    <th>Assigned</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($positions as $pos): ?>
                    <tr>
                        <td><strong><?= h($pos['name']) ?></strong></td>
                        <td><?= (int)$pos['sort_priority'] ?></td>
                        <td><?= h($pos['description'] ?? '') ?></td>
                        <td>
                            <?php 
                                $count = (int)$pos['assignment_count'];
                                echo $count . ($count === 1 ? ' person' : ' people');
                            ?>
                        </td>
                        <td class="small">
                            <a class="button" href="/admin/leadership_position.php?id=<?= (int)$pos['id'] ?>">Edit</a>
                            <button 
                                type="button" 
                                class="button danger" 
                                onclick="confirmDelete(<?= (int)$pos['id'] ?>, '<?= h(addslashes($pos['name'])) ?>', <?= (int)$pos['assignment_count'] ?>)"
                                style="margin-left:6px;">
                                Remove
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card" style="margin-top:16px;">
    <h3>About Leadership Positions</h3>
    <p class="small">
        Leadership positions can be assigned to adults through their individual profile pages. 
        When you remove a position, all current assignments for that position will also be removed. 
        Den Leader positions are managed separately from pack leadership positions.
    </p>
</div>

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
</script>

<?php footer_html(); ?>
