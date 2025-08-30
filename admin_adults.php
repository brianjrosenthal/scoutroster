<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/mailer.php';
require_once __DIR__.'/lib/UserManagement.php';
require_admin();

$msg = null;
$err = null;

// Handle POST (invite/create adult)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $action = $_POST['action'] ?? '';

  if ($action === 'invite') {
    // Invite an existing adult by id (must have an email and be unverified)
    try {
      $aid = (int)($_POST['adult_id'] ?? 0);
      if ($aid <= 0) throw new Exception('Invalid adult');

      // Load adult
      $a = UserManagement::findById($aid);
      if (!$a || empty($a['email']) || !empty($a['email_verified_at'])) {
        throw new Exception('Adult not eligible for invite.');
      }

      $token = bin2hex(random_bytes(32));
      UserManagement::setEmailVerifyToken((int)$a['id'], $token);

      $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
      $verifyUrl = $scheme.'://'.$host.'/verify_email.php?token='.urlencode($token);

      $safeName = htmlspecialchars(trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')), ENT_QUOTES, 'UTF-8');
      $safeUrl  = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');
      $html = '<p>Hello '.($safeName ?: htmlspecialchars($a['email'], ENT_QUOTES, 'UTF-8')).',</p>'
            . '<p>Please verify your email to activate your account for '.htmlspecialchars(Settings::siteTitle(), ENT_QUOTES, 'UTF-8').'.</p>'
            . '<p><a href="'.$safeUrl.'">'.$safeUrl.'</a></p>'
            . '<p>After verifying, you will be prompted to set your password.</p>';

      @send_email((string)$a['email'], 'Activate your '.Settings::siteTitle().' account', $html, $safeName ?: (string)$a['email']);
      $msg = 'Invitation sent if eligible.';
    } catch (Throwable $e) {
      $err = 'Failed to send invitation.';
    }
  } elseif ($action === 'delete') {
    try {
      $aid = (int)($_POST['adult_id'] ?? 0);
      if ($aid <= 0) throw new Exception('Invalid adult');
      // prevent self-deletion
      $me = current_user();
      if ($aid === (int)($me['id'] ?? 0)) {
        throw new Exception('You cannot delete your own account.');
      }
      $deleted = UserManagement::delete($aid);
      if ($deleted > 0) {
        $msg = 'Adult deleted.';
      } else {
        $err = 'Adult not found.';
      }
    } catch (Throwable $e) {
      // Likely blocked by FK constraints (RSVPs or other references)
      $err = 'Unable to delete adult. Remove RSVP references first.';
    }
  } // end if action === invite
} // end POST handler

header_html('Manage Adults');
?>
<h2>Manage Adults</h2>
<p><a class="button" href="/admin_adult_add.php">Create Adult</a></p>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>


<div class="card">
  <h3>All Adults</h3>
  <?php $all = UserManagement::listAllBasic(); ?>
  <?php if (empty($all)): ?>
    <p class="small">No adults found.</p>
  <?php else: ?>
    <table class="list">
      <thead>
        <tr>
          <th>Adult</th>
          <th>Email</th>
          <th>Role</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($all as $a): ?>
          <tr>
            <td><?=h($a['first_name'].' '.$a['last_name'])?></td>
            <td><?=h($a['email'])?></td>
            <td><?= !empty($a['is_admin']) ? 'Admin' : '' ?></td>
            <td class="small">
              <a class="button" href="/adult_edit.php?id=<?= (int)$a['id'] ?>">Edit</a>
              <?php $verified = !empty($a['email_verified_at']); ?>
              <?php if (!empty($a['email']) && !$verified): ?>
                <form method="post" style="display:inline; margin-left:6px;">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="invite">
                  <input type="hidden" name="adult_id" value="<?= (int)$a['id'] ?>">
                  <button class="button">Invite</button>
                </form>
              <?php elseif (empty($a['email'])): ?>
                <span class="small" style="margin-left:6px;">No email</span>
              <?php else: ?>
                <span class="small" style="margin-left:6px;">Verified</span>
              <?php endif; ?>
              <?php if ((int)$a['id'] !== (int)(current_user()['id'] ?? 0)): ?>
                <form method="post" style="display:inline; margin-left:6px;" onsubmit="return confirm('Delete this adult? This cannot be undone.');">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="adult_id" value="<?= (int)$a['id'] ?>">
                  <button class="button danger">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
