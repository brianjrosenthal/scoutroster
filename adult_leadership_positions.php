<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/LeadershipManagement.php';

// This is a public page - no login required
// But we'll show logged-in navigation if user is authenticated
$user = current_user_optional();

$packPositions = LeadershipManagement::getPackLeadershipForDisplay();
$denLeaders = LeadershipManagement::getDenLeadersForDisplay();

header_html('Pack Leadership', $user);
?>

<h2>Pack Leadership Positions</h2>

<div class="card">
    <h3>Pack Leadership</h3>
    <?php if (empty($packPositions)): ?>
        <p class="small">No pack leadership positions defined.</p>
    <?php else: ?>
        <div class="stack">
            <?php foreach ($packPositions as $position): ?>
                <div class="leadership-position">
                    <h4><?= h($position['name']) ?></h4>
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

<?php footer_html(); ?>
