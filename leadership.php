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

<h2>Pack Leadership</h2>

<?php if (!empty($keyPositions)): ?>
    <div class="card" style="margin-bottom:16px;">
        <h3>Key Leadership</h3>
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

<?php if (!empty($otherPositions)): ?>
    <div class="card" style="margin-bottom:16px;">
        <h3>Program Chairs</h3>
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

<?php footer_html(); ?>
