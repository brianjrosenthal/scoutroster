<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/Recommendations.php';
require_admin();

$err = null;
$msg = null;

$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

$ctx = UserContext::getLoggedInUserContext();

// Load existing recommendation for initial render or redisplay after error
$rec = Recommendations::getDetail($id);
if (!$rec) { http_response_code(404); exit('Not found'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  try {
    // Collect fields from POST
    $parent_name = trim((string)($_POST['parent_name'] ?? ''));
    $child_name  = trim((string)($_POST['child_name'] ?? ''));
    $email       = trim((string)($_POST['email'] ?? ''));
    $phone       = trim((string)($_POST['phone'] ?? ''));
    $grade_raw   = trim((string)($_POST['grade'] ?? ''));
    $grade       = in_array($grade_raw, ['K','1','2','3','4','5'], true) ? $grade_raw : null;
    $notes       = trim((string)($_POST['notes'] ?? ''));

    // Minimal validation (client-side required in form too)
    if ($parent_name === '' || $child_name === '') {
      throw new InvalidArgumentException('Parent and child name are required.');
    }

    $fields = [
      'parent_name' => $parent_name,
      'child_name'  => $child_name,
      'email'       => ($email !== '' ? $email : null),
      'phone'       => ($phone !== '' ? $phone : null),
      'grade'       => $grade,
      'notes'       => ($notes !== '' ? $notes : null),
    ];

    $ok = Recommendations::update($ctx, $id, $fields);
    if ($ok) {
      header('Location: /admin_recommendation_view.php?id='.(int)$id.'&msg=updated');
      exit;
    } else {
      // No changes or update failed silently
      $msg = 'No changes to save.';
      // Refresh rec for display
      $rec = Recommendations::getDetail($id) ?: $rec;
    }
  } catch (Throwable $e) {
    $err = $e->getMessage() ?: 'Unable to save changes.';
    // repopulate $rec with posted values so user doesn&#39;t lose edits
    $rec['parent_name'] = $parent_name ?? $rec['parent_name'];
    $rec['child_name']  = $child_name  ?? $rec['child_name'];
    $rec['email']       = $email       ?? $rec['email'];
    $rec['phone']       = $phone       ?? $rec['phone'];
    $rec['grade']       = $grade       ?? $rec['grade'];
    $rec['notes']       = $notes       ?? $rec['notes'];
  }
}

header_html('Edit Recommendation');
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Edit Recommendation</h2>
  <div class="actions">
    <a class="button" href="/admin_recommendation_view.php?id=<?= (int)$id ?>">Back to details</a>
  </div>
</div>

<?php if ($msg): ?><p class="flash"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?= h($err) ?></p><?php endif; ?>

<div class="card" style="max-width:720px;">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$id ?>">

    <label>Parent Name
      <input type="text" name="parent_name" value="<?= h((string)($rec['parent_name'] ?? '')) ?>" required maxlength="255">
    </label>

    <label>Child Name
      <input type="text" name="child_name" value="<?= h((string)($rec['child_name'] ?? '')) ?>" required maxlength="255">
    </label>

    <label>Email
      <input type="email" name="email" value="<?= h((string)($rec['email'] ?? '')) ?>" maxlength="255">
    </label>

    <label>Phone
      <input type="text" name="phone" value="<?= h((string)($rec['phone'] ?? '')) ?>" maxlength="50">
    </label>

    <label>Grade
      <?php $g = (string)($rec['grade'] ?? ''); ?>
      <select name="grade">
        <option value="" <?= $g===''?'selected':'' ?>>Unknown</option>
        <option value="K" <?= $g==='K'?'selected':'' ?>>K</option>
        <option value="1" <?= $g==='1'?'selected':'' ?>>1</option>
        <option value="2" <?= $g==='2'?'selected':'' ?>>2</option>
        <option value="3" <?= $g==='3'?'selected':'' ?>>3</option>
        <option value="4" <?= $g==='4'?'selected':'' ?>>4</option>
        <option value="5" <?= $g==='5'?'selected':'' ?>>5</option>
      </select>
    </label>

    <label>Notes
      <textarea name="notes" rows="5" maxlength="5000"><?= h((string)($rec['notes'] ?? '')) ?></textarea>
    </label>

    <div class="actions">
      <button class="primary">Save</button>
      <a class="button" href="/admin_recommendation_view.php?id=<?= (int)$id ?>">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
