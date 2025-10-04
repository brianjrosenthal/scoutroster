<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/UserManagement.php';
require_once __DIR__.'/settings.php';

// No login required for unsubscribe

$error = null;
$success = null;
$user = null;
$validToken = false;

// Get parameters
$encryptedUserId = $_GET['uid'] ?? '';
$encryptedTimestamp = $_GET['ts'] ?? '';
$signature = $_GET['sig'] ?? '';

// Helper function to decrypt data
function decryptUnsubscribeData($encrypted, $key) {
    if (empty($encrypted) || empty($key)) {
        return null;
    }
    
    try {
        $data = base64_decode($encrypted, true);
        if ($data === false) {
            return null;
        }
        
        // Simple XOR encryption for demo - in production, use proper encryption
        $keyLen = strlen($key);
        $result = '';
        for ($i = 0; $i < strlen($data); $i++) {
            $result .= chr(ord($data[$i]) ^ ord($key[$i % $keyLen]));
        }
        
        return $result;
    } catch (Exception $e) {
        return null;
    }
}

// Helper function to encrypt data
function encryptUnsubscribeData($data, $key) {
    if (empty($data) || empty($key)) {
        return null;
    }
    
    try {
        // Simple XOR encryption for demo - in production, use proper encryption
        $keyLen = strlen($key);
        $encrypted = '';
        for ($i = 0; $i < strlen($data); $i++) {
            $encrypted .= chr(ord($data[$i]) ^ ord($key[$i % $keyLen]));
        }
        
        return base64_encode($encrypted);
    } catch (Exception $e) {
        return null;
    }
}

// Validate token if provided
if (!empty($encryptedUserId) && !empty($encryptedTimestamp) && !empty($signature)) {
    if (!defined('INVITE_HMAC_KEY') || INVITE_HMAC_KEY === '') {
        $error = 'Unsubscribe functionality is not configured. Please contact an administrator.';
    } else {
        // Decrypt user ID and timestamp
        $userId = decryptUnsubscribeData($encryptedUserId, INVITE_HMAC_KEY);
        $timestamp = decryptUnsubscribeData($encryptedTimestamp, INVITE_HMAC_KEY);
        
        if ($userId === null || $timestamp === null) {
            $error = 'Invalid unsubscribe link.';
        } else {
            // Verify signature
            $expectedSignature = hash_hmac('sha256', $encryptedUserId . $encryptedTimestamp, INVITE_HMAC_KEY);
            
            if (!hash_equals($expectedSignature, $signature)) {
                $error = 'Invalid signature. This unsubscribe link may have been tampered with.';
            } else {
                // Check timestamp (30 days = 30 * 24 * 60 * 60 = 2592000 seconds)
                $currentTime = time();
                $tokenTime = (int)$timestamp;
                
                if (($currentTime - $tokenTime) > 2592000) {
                    $error = 'This unsubscribe link has expired. Links are valid for 30 days.';
                } else {
                    // Load user
                    $user = UserManagement::findById((int)$userId);
                    if (!$user) {
                        $error = 'User not found.';
                    } else {
                        $validToken = true;
                    }
                }
            }
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    require_csrf();
    
    $action = $_POST['action'] ?? '';
    $submittedUserId = $_POST['user_id'] ?? '';
    $submittedSignature = $_POST['signature'] ?? '';
    $submittedTimestamp = $_POST['timestamp'] ?? '';
    
    // Re-validate the token from form data
    if ($action === 'unsubscribe') {
        $expectedFormSignature = hash_hmac('sha256', $submittedUserId . $submittedTimestamp, INVITE_HMAC_KEY);
        
        if (!hash_equals($expectedFormSignature, $submittedSignature)) {
            $error = 'Invalid form signature.';
        } else {
            $formTimestamp = (int)$submittedTimestamp;
            if ((time() - $formTimestamp) > 2592000) {
                $error = 'This unsubscribe request has expired.';
            } else {
                try {
                    // Update unsubscribe status without requiring login context
                    $st = pdo()->prepare("UPDATE users SET unsubscribed = 1 WHERE id = ?");
                    $ok = $st->execute([(int)$submittedUserId]);
                    
                    if ($ok) {
                        $success = 'You have been successfully unsubscribed from emails.';
                        // Refresh user data
                        $user = UserManagement::findById((int)$submittedUserId);
                    } else {
                        $error = 'Failed to update your email preferences. Please try again.';
                    }
                } catch (Throwable $e) {
                    $error = 'An error occurred while updating your email preferences.';
                }
            }
        }
    }
}

header_html('Unsubscribe from Emails');
?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <h2>Unsubscribe from Emails</h2>
    
    <?php if ($error): ?>
        <p class="error"><?= h($error) ?></p>
        <div class="actions">
            <a href="/" class="button">Return to Home</a>
        </div>
    <?php elseif ($success): ?>
        <p class="flash"><?= h($success) ?></p>
        <?php if ($user): ?>
            <p>Hi <?= h(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?>,</p>
            <p>You have been unsubscribed from most emails sent by <?= h(Settings::siteTitle()) ?>. This includes event invitations and general announcements.</p>
            <p>You can resubscribe at any time by logging into your account and updating your email preferences in "My Profile".</p>
        <?php endif; ?>
        <div class="actions">
            <a href="/" class="button">Return to Home</a>
            <?php if ($user): ?>
                <?php $currentUser = current_user(); ?>
                <?php if ($currentUser): ?>
                    <a href="/my_profile.php" class="button">My Profile</a>
                <?php else: ?>
                    <a href="/login.php" class="button">Login to Your Account</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php elseif ($validToken && $user): ?>
        <p>Hi <?= h(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?>,</p>
        
        <?php if (!empty($user['unsubscribed'])): ?>
            <p>You are already unsubscribed from emails.</p>
            <p>If you would like to resubscribe, please log into your account and update your email preferences in "My Profile".</p>
            <div class="actions">
                <?php $currentUser = current_user(); ?>
                <?php if ($currentUser): ?>
                    <a href="/my_profile.php" class="button">My Profile</a>
                <?php else: ?>
                    <a href="/login.php" class="button">Login to Your Account</a>
                <?php endif; ?>
                <a href="/" class="button">Return to Home</a>
            </div>
        <?php else: ?>
            <p>Would you like to unsubscribe from emails sent by <?= h(Settings::siteTitle()) ?>?</p>
            <p class="small">This will remove you from most emails, including event invitations and general announcements. You can resubscribe at any time by logging into your account.</p>
            
            <form method="post" class="stack">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="unsubscribe">
                <input type="hidden" name="user_id" value="<?= h($user['id']) ?>">
                <input type="hidden" name="timestamp" value="<?= h($timestamp) ?>">
                <input type="hidden" name="signature" value="<?= h(hash_hmac('sha256', $user['id'] . $timestamp, INVITE_HMAC_KEY)) ?>">
                
                <div class="actions">
                    <button type="submit" class="button danger">Unsubscribe from Emails</button>
                    <a href="/" class="button">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <p>To unsubscribe from emails, you need a valid unsubscribe link. These links are typically provided at the bottom of emails sent by <?= h(Settings::siteTitle()) ?>.</p>
        <p>If you are logged in, you can also update your email preferences in your profile:</p>
        <div class="actions">
            <a href="/my_profile.php" class="button">My Profile</a>
            <a href="/login.php" class="button">Login</a>
            <a href="/" class="button">Home</a>
        </div>
    <?php endif; ?>
</div>

<?php footer_html(); ?>
