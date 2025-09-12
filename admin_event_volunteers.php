<?php
require_once __DIR__ . '/partials.php';
require_admin();
require_once __DIR__ . '/lib/Volunteers.php';
require_once __DIR__ . '/lib/EventManagement.php';

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : (int)($_POST['event_id'] ?? 0);
if ($eventId <= 0) { http_response_code(400); exit('Missing event_id'); }

// Load event (for title / validation)
$event = EventManagement::findBasicById($eventId);
if (!$event) { http_response_code(404); exit('Event not found'); }

$msg = null;
$err = null;

// Save roles payload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    require_csrf();

    // Expect arrays role_id[], title[], description[], slots_needed[], sort_order[] (aligned by index)
    $roleIds      = $_POST['role_id'] ?? [];
    $titles       = $_POST['title'] ?? [];
    $descs        = $_POST['description'] ?? [];
    $slotsNeededs = $_POST['slots_needed'] ?? [];
    $sortOrders   = $_POST['sort_order'] ?? [];

    $count = max(count((array)$roleIds), count((array)$titles), count((array)$descs), count((array)$slotsNeededs), count((array)$sortOrders));
    $roles = [];
    for ($i = 0; $i < $count; $i++) {
      $rid   = isset($roleIds[$i])      ? (int)$roleIds[$i] : 0;
      $title = isset($titles[$i])       ? trim((string)$titles[$i]) : '';
      $desc  = isset($descs[$i])        ? trim((string)$descs[$i]) : '';
      $slots = isset($slotsNeededs[$i]) ? (int)$slotsNeededs[$i] : 0;
      $order = isset($sortOrders[$i])   ? (int)$sortOrders[$i] : $i;

      // Skip blank titles
      if ($title === '') continue;

      // Normalize non-negative values
      if ($slots < 0) $slots = 0;
      if ($order < 0) $order = 0;

      $roles[] = [
        'id' => $rid,
        'title' => $title,
        'description' => $desc,
        'slots_needed' => $slots,
        'sort_order' => $order,
      ];
    }

    Volunteers::saveRoles($eventId, $roles);
    header('Location: /admin_events.php?id='.(int)$eventId.'&volunteers_saved=1');
    exit;
  } catch (Throwable $e) {
    $err = $e->getMessage() ?: 'Failed to save volunteer roles.';
  }
}

// GET: show a tiny management page (useful if not using modal)
$roles = Volunteers::rolesWithCounts($eventId);

header_html('Manage Volunteer Roles');
?>
<h2>Manage Volunteers &mdash; <?= h($event['name']) ?></h2>
<?php if ($msg): ?><p class="flash"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?= h($err) ?></p><?php endif; ?>

<div class="card">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">

    <div class="grid" style="grid-template-columns:repeat(5,1fr);gap:12px;">
      <div><strong>Title</strong></div>
      <div><strong>Description</strong></div>
      <div><strong># Needed</strong></div>
      <div><strong>Sort</strong></div>
      <div><strong>Current</strong></div>
    </div>

    <div id="rolesContainer">
      <?php if (empty($roles)): ?>
        <div class="grid" style="grid-template-columns:repeat(5,1fr);gap:12px;align-items:center;">
          <input type="hidden" name="role_id[]" value="0">
          <input type="text" name="title[]" placeholder="e.g., Setup">
          <input type="text" name="description[]" placeholder="Optional description">
          <input type="number" name="slots_needed[]" min="0" value="1" style="max-width:120px">
          <input type="number" name="sort_order[]" min="0" value="0" style="max-width:120px">
          <div class="small">&nbsp;</div>
        </div>
      <?php else: ?>
        <?php foreach ($roles as $idx => $r): ?>
          <div class="grid" style="grid-template-columns:repeat(5,1fr);gap:12px;align-items:center;margin:6px 0;">
            <input type="hidden" name="role_id[]" value="<?= (int)$r['id'] ?>">
            <input type="text" name="title[]" value="<?= h($r['title']) ?>">
            <input type="text" name="description[]" value="<?= h((string)($r['description'] ?? '')) ?>">
            <input type="number" name="slots_needed[]" min="0" value="<?= (int)$r['slots_needed'] ?>" style="max-width:120px">
            <input type="number" name="sort_order[]" min="0" value="<?= (int)$r['sort_order'] ?>" style="max-width:120px">
            <div class="small"><?= (int)$r['filled_count'] ?> filled<?= !empty($r['is_unlimited']) ? ' / no limit' : ((int)$r['open_count'] > 0 ? ' / '.(int)$r['open_count'].' open' : '') ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="actions">
      <button class="button" type="button" onclick="addRoleRow()">Add Role</button>
      <button class="primary" type="submit">Save Roles</button>
      <a class="button" href="/admin_events.php?id=<?= (int)$eventId ?>">Back</a>
    </div>
  </form>
</div>

<script>
function addRoleRow(){
  const c = document.getElementById('rolesContainer');
  const row = document.createElement('div');
  row.className = 'grid';
  row.style.cssText = 'grid-template-columns:repeat(5,1fr);gap:12px;align-items:center;margin:6px 0;';
  row.innerHTML = `
    <input type="hidden" name="role_id[]" value="0">
    <input type="text" name="title[]" placeholder="e.g., Clean-up">
    <input type="text" name="description[]" placeholder="Optional description">
    <input type="number" name="slots_needed[]" min="0" value="1" style="max-width:120px">
    <input type="number" name="sort_order[]" min="0" value="0" style="max-width:120px">
    <div class="small">&nbsp;</div>
  `;
  c.appendChild(row);
}
</script>

<?php footer_html(); ?>
