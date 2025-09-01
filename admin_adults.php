<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/UserManagement.php';
require_once __DIR__.'/lib/Search.php';
require_admin();

$msg = null;
$err = null;

$q = trim($_GET['q'] ?? '');

// Handle POST (invite/create adult)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $action = $_POST['action'] ?? '';

  if ($action === 'invite') {
    // Invite an existing adult by id (must have an email and be unverified)
    try {
      $aid = (int)($_POST['adult_id'] ?? 0);
      if ($aid <= 0) throw new Exception('Invalid adult');

      // Delegate invite flow to domain layer
      $sent = UserManagement::sendInvite(UserContext::getLoggedInUserContext(), $aid);
      if ($sent) {
        $msg = 'Invitation sent if eligible.';
      } else {
        $err = 'Adult not eligible for invite.';
      }
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
      $deleted = UserManagement::delete(UserContext::getLoggedInUserContext(), $aid);
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
<div class="card">
  <form method="get" class="stack">
    <label>Search
      <input type="text" name="q" value="<?=h($q)?>" placeholder="Name or email">
    </label>
    <div class="actions">
      <a class="button" href="/admin_adults.php">Reset</a>
      <a class="button" href="/admin_adult_add.php">Create Adult</a>
    </div>
  </form>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>


<div class="card">
  <h3>All Adults</h3>
  <?php
    $params = [];
    $sql = "SELECT id, first_name, last_name, email, is_admin, email_verified_at FROM users WHERE 1=1";
    if ($q !== '') {
      $tokens = Search::tokenize($q);
      $sql .= Search::buildAndLikeClause(['first_name','last_name','email'], $tokens, $params);
    }
    $sql .= " ORDER BY last_name, first_name";
    $st = pdo()->prepare($sql);
    $st->execute($params);
    $all = $st->fetchAll();
  ?>
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
            <td><?=h($a['email'] ?: '---')?></td>
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
