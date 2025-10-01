<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/LeadershipManagement.php';

// This is a public page - no login required
// But we'll show logged-in navigation if user is authenticated
$user = current_user_optional();

try {
    // Get pack positions with assignments
    $packPositions = LeadershipManagement::getPackLeadershipForDisplay();
} catch (Throwable $e) {
    $packPositions = [];
}

// Find open positions (positions with no assignments)
$openPositions = [];
foreach ($packPositions as $position) {
    $hasAssignments = !empty($position['holders']);
    if (!$hasAssignments) {
        $openPositions[] = $position;
    }
}

header_html('Open Volunteer Positions', $user);
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <h2>Open Volunteer Positions</h2>
    <a href="/leadership.php" class="button">Back to Leadership Page</a>
</div>

<?php if (!empty($openPositions)): ?>
    <div class="card" style="margin-bottom:16px;">
        <p style="margin-bottom:16px;"><strong>We are looking to fill the following important program positions.</strong>  If you are interested, please reach out to Brian Rosenthal (brian.rosenthal@gmail.com) or Takford Mau (takfordmau@gmail.com)</p>
        <?php foreach ($openPositions as $position): ?>
            <div style="margin-bottom:16px;">
                <strong><?= h($position['name']) ?></strong>
                <?php if (!empty($position['description'])): ?>
                    <br><?= nl2br(h($position['description'])) ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="card">
        <p>Great news! All of our volunteer positions are currently filled. Check back later or contact our leadership team if you're interested in getting involved.</p>
        <div style="margin-top:16px;">
            <a href="/leadership.php" class="button primary">Back to Leadership Page</a>
        </div>
    </div>
<?php endif; ?>

<?php footer_html(); ?>
