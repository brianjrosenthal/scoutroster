<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/Reimbursements.php';
require_login();

$ctx = UserContext::getLoggedInUserContext();
$me = current_user();

$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

$err = null;
$msg = null;

// Fetch request with permission check (for GET display as well)
try {
  $req = Reimbursements::getWithAuth($ctx, $id);
} catch (Throwable $e) {
  http_response_code(403);
  exit('Forbidden');
}
$isApprover = Reimbursements::isApprover($ctx);
$isOwner = ((int)$req['created_by'] === (int)($me['id'] ?? 0));

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $action = $_POST['action'] ?? '';

  try {
    if ($action === 'comment') {
      $text = (string)($_POST['comment_text'] ?? '');
      Reimbursements::addComment($ctx, $id, $text);
      $msg = 'Comment added.';
    } elseif ($action === 'status') {
      $newStatus = (string)($_POST['new_status'] ?? '');
      $comment = (string)($_POST['comment_text'] ?? '');
      Reimbursements::changeStatus($ctx, $id, $newStatus, $comment);
      $msg = 'Status updated.';
      // Refresh req after change
      $req = Reimbursements::getWithAuth($ctx, $id);
    } elseif ($action === 'upload_file') {
      if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        throw new InvalidArgumentException('No file uploaded.');
      }
      $f = $_FILES['file'];
      if (!empty($f['error'])) {
        throw new RuntimeException('Upload failed.');
      }
      $tmp = $f['tmp_name'] ?? '';
      $name = $f['name'] ?? 'file';
      $desc = trim((string)($_POST['file_description'] ?? '')) ?: null;
      if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid upload.');
      }
      // Allow images and PDFs
      $allowedExt = ['pdf','jpg','jpeg','png','heic','webp'];
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('Unsupported file type. Allowed: pdf, jpg, jpeg, png, heic, webp.');
      }
      $destDir = __DIR__ . '/uploads/reimbursements/' . (int)$id;
      if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }
      $rand = bin2hex(random_bytes(12));
      $storedRel = 'uploads/reimbursements/' . (int)$id . '/' . $rand . '.' . $ext;
      $storedAbs = __DIR__ . '/' . $storedRel;
      if (!@move_uploaded_file($tmp, $storedAbs)) {
        throw new RuntimeException('Failed to store uploaded file.');
      }
      Reimbursements::recordFile($ctx, $id, $storedRel, $name, $desc);
      $msg = 'File uploaded.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage() ?: 'Operation failed.';
  }
}

// Load associated data for display
$files = [];
$comments = [];
try {
  $files = Reimbursements::fetchFiles($ctx, $id);
  $comments = Reimbursements::fetchComments($ctx, $id);
} catch (Throwable $e) {
  $files = [];
  $comments = [];
  if (!$err) $err = 'Unable to load related data.';
}

$allowed = Reimbursements::allowedTransitionsFor($ctx, $req);
$canUpload = Reimbursements::canUploadFiles($ctx, $req);

header_html('Reimbursement Details');
?>
<h2>Reimbursement: <?= h($req['title']) ?></h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <h3>Details</h3>
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
    <div><strong>Status:</strong> <?= h($req['status']) ?></div>
    <div><strong>Created at:</strong> <?= h($req['created_at']) ?></div>
    <div><strong>Last updated:</strong> <?= h($req['last_modified_at']) ?></div>
  </div>
  <?php if (!empty($req['comment_from_last_status_change'])): ?>
    <p class="small"><strong>Last status comment:</strong> <?= nl2br(h($req['comment_from_last_status_change'])) ?></p>
  <?php endif; ?>
  <?php if (!empty($req['description'])): ?>
    <div style="margin-top:8px;">
      <strong>Description</strong>
      <div class="small" style="white-space:pre-wrap;"><?= h($req['description']) ?></div>
    </div>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:16px;">
  <h3>Files</h3>
  <?php if (empty($files)): ?>
    <p class="small">No files uploaded.</p>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($files as $f): ?>
        <li>
          <a href="/reimbursement_download.php?id=<?= (int)$f['id'] ?>"><?= h($f['original_filename']) ?></a>
          <span class="small">uploaded by <?= h(trim(($f['first_name'] ?? '').' '.($f['last_name'] ?? ''))) ?> at <?= h($f['created_at']) ?></span>
          <?php if (!empty($f['description'])): ?>
            <div class="small"><?= h($f['description']) ?></div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($canUpload): ?>
    <form method="post" enctype="multipart/form-data" class="stack" style="margin-top:8px;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="action" value="upload_file">
      <label>Attach a file
        <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.heic,.webp" required>
      </label>
      <label class="small">Description (optional)
        <input type="text" name="file_description" maxlength="255">
      </label>
      <div class="actions"><button class="button">Upload</button></div>
      <p class="small">Allowed: pdf, jpg, jpeg, png, heic, webp. Max 15 MB recommended.</p>
    </form>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:16px;">
  <h3>Comments</h3>
  <?php if (empty($comments)): ?>
    <p class="small">No comments yet.</p>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($comments as $c): ?>
        <li>
          <div class="small">
            <strong><?= h(trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? ''))) ?></strong>
            at <?= h($c['created_at']) ?>
            <?php if (!empty($c['status_changed_to'])): ?>
              — <em>Status changed to <?= h($c['status_changed_to']) ?></em>
            <?php endif; ?>
          </div>
          <div style="white-space:pre-wrap;"><?= h($c['comment_text']) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <form method="post" class="stack" style="margin-top:8px;">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="action" value="comment">
    <label>Add a comment
      <textarea name="comment_text" rows="3" required></textarea>
    </label>
    <div class="actions"><button class="button">Post Comment</button></div>
  </form>
</div>

<div class="card" style="margin-top:16px;">
  <h3>Actions</h3>
  <?php if (empty($allowed)): ?>
    <p class="small">No actions available for the current status.</p>
  <?php else: ?>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="action" value="status">
      <label>Change status
        <select name="new_status" required>
          <option value="">-- Select --</option>
          <?php foreach ($allowed as $s): ?>
            <option value="<?= h($s) ?>"><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Comment (required)
        <textarea name="comment_text" rows="3" required placeholder="Explain your action..."></textarea>
      </label>
      <div class="actions"><button class="primary">Submit</button></div>
      <p class="small">A comment is required for all status changes. It will be recorded with the new status.</p>
    </form>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
