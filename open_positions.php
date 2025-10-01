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
    <a href="/leadership.php" class="button">View Full Leadership</a>
</div>

<?php if (!empty($openPositions)): ?>
    <div class="card" style="margin-bottom:16px;">
        <p style="margin-bottom:16px;">These positions are currently available and looking for volunteers. We welcome anyone interested in helping our Cub Scout pack succeed!</p>
        <?php foreach ($openPositions as $position): ?>
            <div style="margin-bottom:16px;padding:12px;border-left:3px solid #0066cc;background:#f8f9fa;">
                <h3 style="margin:0 0 8px 0;color:#0066cc;"><?= h($position['name']) ?></h3>
                <?php if (!empty($position['description'])): ?>
                    <p style="margin:0;color:#666;"><?= h($position['description']) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        
        <div style="margin-top:20px;padding:16px;background:#e8f4f8;border-radius:4px;">
            <h3 style="margin:0 0 8px 0;">Interested in Volunteering?</h3>
            <p style="margin:0;">
                If you're interested in any of these positions or would like to learn more about how you can help, 
                please contact our leadership team. Every contribution, big or small, makes a difference in providing 
                a great Cub Scout experience for our youth!
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <p>Great news! All of our volunteer positions are currently filled. Check back later or contact our leadership team if you're interested in getting involved.</p>
        <div style="margin-top:16px;">
            <a href="/leadership.php" class="button primary">View Current Leadership</a>
        </div>
    </div>
<?php endif; ?>

<div class="card" style="margin-top:16px;">
    <h3>Why Volunteer?</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;margin-top:12px;">
        <div>
            <h4 style="margin:0 0 8px 0;color:#0066cc;">Make a Difference</h4>
            <p style="margin:0;">Help shape the character and experiences of our young scouts.</p>
        </div>
        <div>
            <h4 style="margin:0 0 8px 0;color:#0066cc;">Build Community</h4>
            <p style="margin:0;">Connect with other families and strengthen our pack community.</p>
        </div>
        <div>
            <h4 style="margin:0 0 8px 0;color:#0066cc;">Learn New Skills</h4>
            <p style="margin:0;">Gain leadership experience and develop new abilities.</p>
        </div>
        <div>
            <h4 style="margin:0 0 8px 0;color:#0066cc;">Create Memories</h4>
            <p style="margin:0;">Be part of the adventures and memories that last a lifetime.</p>
        </div>
    </div>
</div>

<?php footer_html(); ?>
