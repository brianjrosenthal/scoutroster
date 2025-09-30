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
    <a class="button" href="/admin/leadership_positions.php">Back to Positions</a>
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
        <h3>Current Assignments</h3>
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

<?php footer_html(); ?>
