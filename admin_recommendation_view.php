<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/settings.php';
require_admin();

$err = null;
$msg = null;

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

// Load recommendation
function loadRecommendation(int $id): ?array {
  $sql = "SELECT r.*,
                 u1.first_name AS submit_first, u1.last_name AS submit_last,
                 u2.first_name AS reached_first, u2.last_name AS reached_last
          FROM recommendations r
          JOIN users u1 ON u1.id = r.created_by_user_id
          LEFT JOIN users u2 ON u2.id = r.reached_out_by_user_id
          WHERE r.id = ?
          LIMIT 1";
  $st = pdo()->prepare($sql);
  $st->execute([$id]);
  $row = $st->fetch();
  return $row ?: null;
}

$rec = loadRecommendation($id);
if (!$rec) { http_response_code(404); exit('Not found'); }

$me = current_user();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'toggle_reached') {
      $newVal = !empty($_POST['reached_out']) ? 1 : 0;
      if ($newVal === 1) {
        $st = pdo()->prepare("UPDATE recommendations SET reached_out=1, reached_out_at=NOW(), reached_out_by_user_id=? WHERE id=?");
        $st->execute([(int)($me['id'] ?? 0), $id]);
      } else {
        $st = pdo()->prepare("UPDATE recommendations SET reached_out=0, reached_out_at=NULL, reached_out_by_user_id=NULL WHERE id=?");
        $st->execute([$id]);
      }
      $msg = 'Saved.';
      $rec = loadRecommendation($id);
    } elseif ($action === 'add_comment') {
      $text = trim($_POST['text'] ?? '');
      if ($text === '') throw new InvalidArgumentException('Comment cannot be empty.');
      $st = pdo()->prepare("INSERT INTO recommendation_comments (recommendation_id, created_by_user_id, text, created_at) VALUES (?,?,?,NOW())");
      $st->execute([$id, (int)($me['id'] ?? 0), $text]);
      $msg = 'Comment added.';
    }
  } catch (Throwable $e) {
    $err = 'Unable to save changes.';
  }
}

// Load comments
$comments = [];
try {
  $st = pdo()->prepare("
    SELECT rc.*, u.first_name, u.last_name
    FROM recommendation_comments rc
    JOIN users u ON u.id = rc.created_by_user_id
    WHERE rc.recommendation_id=?
    ORDER BY rc.created_at DESC, rc.id DESC
  ");
  $st->execute([$id]);
  $comments = $st->fetchAll();
} catch (Throwable $e) {
  $comments = [];
}

header_html('Recommendation Details');
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Recommendation Details</h2>
  <div class="actions">
    <a class="button" href="/admin_recommendations.php">Back to list</a>
  </div>
</div>
<?php if ($msg): ?><p class="flash"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?= h($err) ?></p><?php endif; ?>

<div class="card">
  <h3>Summary</h3>
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
    <div><strong>Submitted:</strong> <?= h(Settings::formatDateTime($rec['created_at'] ?? '')) ?></div>
    <div><strong>Submitted By:</strong> <?= h(trim((string)($rec['submit_first'] ?? '').' '.(string)($rec['submit_last'] ?? ''))) ?></div>
    <div><strong>Parent:</strong> <?= h($rec['parent_name'] ?? '') ?></div>
    <div><strong>Child:</strong> <?= h($rec['child_name'] ?? '') ?></div>
    <div><strong>Email:</strong> <?= h($rec['email'] ?? '') ?></div>
    <div><strong>Phone:</strong> <?= h($rec['phone'] ?? '') ?></div>
    <div style="grid-column:1/-1;">
      <strong>Notes:</strong>
      <div class="small" style="white-space:pre-wrap;"><?= nl2br(h($rec['notes'] ?? '')) ?></div>
    </div>
  </div>
</div>

<div class="card">
  <h3>Reached Out</h3>
  <form method="post" class="stack" style="max-width:520px;">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="toggle_reached">
    <label class="inline">
      <input type="hidden" name="reached_out" value="0">
      <input type="checkbox" name="reached_out" value="1" <?= !empty($rec['reached_out']) ? 'checked' : '' ?>>
      Mark as reached out
    </label>
    <div class="small">
      <?php if (!empty($rec['reached_out'])): ?>
        Marked reached out on <?= h(Settings::formatDateTime($rec['reached_out_at'] ?? '')) ?>
        <?php
          $who = trim((string)($rec['reached_first'] ?? '').' '.(string)($rec['reached_last'] ?? ''));
          if ($who !== '') echo ' by '.h($who);
        ?>
      <?php else: ?>
        Not yet marked as reached out.
      <?php endif; ?>
    </div>
    <div class="actions">
      <button class="button">Save</button>
    </div>
  </form>
</div>

<div class="card">
  <h3>Comments</h3>
  <?php if (empty($comments)): ?>
    <p class="small">No comments yet.</p>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($comments as $c): ?>
        <li>
          <div class="small" style="color:#666;"><?= h(Settings::formatDateTime($c['created_at'] ?? '')) ?> —
            <?= h(trim((string)($c['first_name'] ?? '').' '.(string)($c['last_name'] ?? ''))) ?></div>
          <div class="small" style="white-space:pre-wrap;"><?= nl2br(h($c['text'] ?? '')) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <form method="post" class="stack" style="margin-top:8px;max-width:640px;">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="add_comment">
    <label>Add a comment
      <textarea name="text" rows="3" required></textarea>
    </label>
    <div class="actions">
      <button class="button">Add Comment</button>
    </div>
  </form>
</div>

<?php footer_html(); ?>
