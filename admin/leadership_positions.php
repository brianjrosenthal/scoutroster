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
} catch (Throwable $e) {
    $err = 'Unable to load leadership positions.';
    $positions = [];
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
                        <td><?= h($pos['description'] ?? '') ?></td>
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
