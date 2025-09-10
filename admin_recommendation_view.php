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

function rec_format_date_only(?string $sqlDt): string {
  if (!$sqlDt) return '';
  try {
    $dt = new DateTime($sqlDt);
    $dt->setTimezone(new DateTimeZone(Settings::timezoneId()));
    return $dt->format('Y-m-d');
  } catch (Throwable $e) {
    return '';
  }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $action = $_POST['action'] ?? '';
  try {
    switch ($action) {
      case 'mark_reached_out':
        $st = pdo()->prepare("UPDATE recommendations SET status='active', reached_out_at=NOW(), reached_out_by_user_id=? WHERE id=? AND status='new'");
        $st->execute([(int)($me['id'] ?? 0), $id]);
        $msg = 'Saved.';
        $rec = loadRecommendation($id);
        break;
      case 'mark_joined':
        $st = pdo()->prepare("UPDATE recommendations SET status='joined' WHERE id=? AND status='active'");
        $st->execute([$id]);
        $msg = 'Saved.';
        $rec = loadRecommendation($id);
        break;
      case 'unsubscribe':
        $st = pdo()->prepare("UPDATE recommendations SET status='unsubscribed' WHERE id=? AND status IN ('new','active')");
        $st->execute([$id]);
        $msg = 'Saved.';
        $rec = loadRecommendation($id);
        break;
      case 'add_comment':
        $text = trim($_POST['text'] ?? '');
        if ($text === '') throw new InvalidArgumentException('Comment cannot be empty.');
        $st = pdo()->prepare("INSERT INTO recommendation_comments (recommendation_id, created_by_user_id, text, created_at) VALUES (?,?,?,NOW())");
        $st->execute([$id, (int)($me['id'] ?? 0), $text]);
        $msg = 'Comment added.';
        break;
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

<?php
// Precompute conditional "Create adult account" link (only if status=joined and email present with no existing user)
$createAdultUrl = '';
$canCreateAdult = false;
try {
  $recEmail = trim((string)($rec['email'] ?? ''));
  if ($recEmail !== '') {
    $stE = pdo()->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stE->execute([$recEmail]);
    $exists = $stE->fetchColumn() ? true : false;

    if (($rec['status'] ?? '') === 'joined' && !$exists) {
      $full = trim((string)($rec['parent_name'] ?? ''));
      $first = ''; $last = '';
      if ($full !== '') {
        $parts = preg_split('/\s+/', $full);
        if ($parts) {
          $last = (string)array_pop($parts);
          $first = trim(implode(' ', $parts));
          if ($first === '') { $first = $last; $last = ''; }
        }
      }
      $qs = [
        'first_name' => $first,
        'last_name'  => $last,
        'email'      => $recEmail,
      ];
      $recPhone = trim((string)($rec['phone'] ?? ''));
      if ($recPhone !== '') {
        $qs['phone_cell'] = $recPhone;
      }
      $createAdultUrl = '/admin_adult_add.php?'.http_build_query($qs);
      $canCreateAdult = true;
    }
  }
} catch (Throwable $e) {
  $createAdultUrl = '';
  $canCreateAdult = false;
}
?>
<?php header_html('Recommendation Details'); ?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Recommendation Details</h2>
  <div class="actions">
    <a class="button" href="/admin_recommendations.php">Back to list</a>
    <?php if (($rec['status'] ?? '') === 'new'): ?>
      <form method="post" style="display:inline-block;margin-left:8px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="mark_reached_out">
        <button class="button">Mark as reached out</button>
      </form>
      <form method="post" style="display:inline-block;margin-left:8px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="unsubscribe">
        <button class="button">Unsubscribe</button>
      </form>
    <?php elseif (($rec['status'] ?? '') === 'active'): ?>
      <form method="post" style="display:inline-block;margin-left:8px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="mark_joined">
        <button class="button">Mark as joined</button>
      </form>
      <form method="post" style="display:inline-block;margin-left:8px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="unsubscribe">
        <button class="button">Unsubscribe</button>
      </form>
    <?php endif; ?>
    <?php if ($canCreateAdult): ?>
      <a class="button" href="<?= h($createAdultUrl) ?>">Create adult account</a>
    <?php endif; ?>
  </div>
</div>
<?php if ($msg): ?><p class="flash"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?= h($err) ?></p><?php endif; ?>

<div class="card">
  <h3><?= h($rec['parent_name'] ?? '') ?> (<?= h(ucfirst((string)($rec['status'] ?? ''))) ?>)</h3>
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
    <div><strong>Child Name:</strong> <?= h($rec['child_name'] ?? '') ?></div>
    <div><strong>Email:</strong> <?= h($rec['email'] ?? '') ?></div>
    <div><strong>Phone number:</strong> <?= h($rec['phone'] ?? '') ?></div>
    <div><strong>Grade:</strong> <?= isset($rec['grade']) && $rec['grade'] !== null && $rec['grade'] !== '' ? h($rec['grade']) : 'Unknown' ?></div>
    <div style="grid-column:1/-1;"><strong>Submitted:</strong>
      <?php
        $submitName = trim((string)($rec['submit_first'] ?? '').' '.(string)($rec['submit_last'] ?? ''));
        $submittedDate = rec_format_date_only($rec['created_at'] ?? null);
        echo h($submitName !== '' ? $submitName : '');
        echo $submittedDate !== '' ? ' ('.h($submittedDate).')' : '';
      ?>
    </div>
    <?php if (!empty($rec['reached_out_at'])): ?>
      <div style="grid-column:1/-1;"><strong>Reached out:</strong>
        <?php
          $whoReached = trim((string)($rec['reached_first'] ?? '').' '.(string)($rec['reached_last'] ?? ''));
          $reachedDate = rec_format_date_only($rec['reached_out_at'] ?? null);
          echo h($whoReached !== '' ? $whoReached : '');
          echo $reachedDate !== '' ? ' at '.h($reachedDate) : '';
        ?>
      </div>
    <?php endif; ?>
    <div style="grid-column:1/-1;">
      <strong>Notes:</strong>
      <div class="small" style="white-space:pre-wrap;"><?= nl2br(h($rec['notes'] ?? '')) ?></div>
    </div>
  </div>
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
