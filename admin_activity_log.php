<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/ActivityLog.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_admin();

function int_param(string $key, int $default = 0): int {
  $v = $_GET[$key] ?? null;
  if ($v === null) return $default;
  if (is_string($v)) $v = trim($v);
  $n = (int)$v;
  return $n;
}

function str_param(string $key, string $default = ''): string {
  $v = $_GET[$key] ?? null;
  if ($v === null) return $default;
  $v = (string)$v;
  return trim($v);
}

$limitOptions = [10, 25, 50, 100];

// Filters
$qUserId = int_param('user_id', 0);
$qActionType = str_param('action_type', '');
$qLimit = int_param('limit', 25);
if (!in_array($qLimit, $limitOptions, true)) { $qLimit = 25; }
$qPage = max(1, int_param('page', 1));

// Build filters for ActivityLog
$filters = [];
if ($qUserId > 0) $filters['user_id'] = $qUserId;
if ($qActionType !== '') $filters['action_type'] = $qActionType;

// Count + paging
$total = ActivityLog::count($filters);
$totalPages = max(1, (int)ceil($total / $qLimit));
if ($qPage > $totalPages) $qPage = $totalPages;
$offset = ($qPage - 1) * $qLimit;

// Fetch rows
$rows = ActivityLog::list($filters, $qLimit, $offset);

// Populate selects
$users = UserManagement::listAllForSelect(); // id, first_name, last_name, email
$actionTypes = ActivityLog::distinctActionTypes();

// Prefill user typeahead label if a specific user_id is selected
$prefillLabel = '';
if ($qUserId > 0) {
  $uSel = UserManagement::findBasicForEmailingById($qUserId);
  if ($uSel) {
    $first = (string)($uSel['first_name'] ?? '');
    $last  = (string)($uSel['last_name'] ?? '');
    $email = (string)($uSel['email'] ?? '');
    $prefillLabel = trim($last . ', ' . $first) . ($email !== '' ? ' <' . $email . '>' : '');
  }
}

// Build quick lookup for user names
$userMap = [];
foreach ($users as $u) {
  $id = (int)$u['id'];
  $name = trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? ''));
  if ($name === '') $name = 'User #' . $id;
  if (!empty($u['email'])) {
    $name .= ' (' . (string)$u['email'] . ')';
  }
  $userMap[$id] = $name;
}

function build_url(array $overrides): string {
  $base = [
    'user_id' => isset($_GET['user_id']) ? $_GET['user_id'] : '',
    'action_type' => isset($_GET['action_type']) ? $_GET['action_type'] : '',
    'limit' => isset($_GET['limit']) ? $_GET['limit'] : '',
    'page' => isset($_GET['page']) ? $_GET['page'] : '',
  ];
  foreach ($overrides as $k => $v) {
    if ($v === null) {
      unset($base[$k]);
    } else {
      $base[$k] = $v;
    }
  }
  // Normalize empties
  if (empty($base['user_id'])) unset($base['user_id']);
  if (empty($base['action_type'])) unset($base['action_type']);
  if (empty($base['limit'])) unset($base['limit']);
  if (empty($base['page'])) unset($base['page']);
  $qs = http_build_query($base);
  return '/admin_activity_log.php' . ($qs ? ('?' . $qs) : '');
}

header_html('Activity Log');
?>
<style>
#userTypeaheadResults { display:none; border:1px solid #ddd; border-radius:6px; background:#fff; max-height:220px; overflow:auto; margin-top:4px; }
#userTypeaheadResults .item { padding:6px 8px; cursor:pointer; }
#userTypeaheadResults .item:hover { background:#f5f5f5; }
</style>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Activity Log</h2>
</div>

<div class="card">
  <form method="get" class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end;">
    <label>User
      <input type="text" style="width: auto;" id="userTypeahead" name="user_label" value="<?= h($prefillLabel) ?>" placeholder="Search name or email">
      <input type="hidden" id="userId" name="user_id" value="<?= $qUserId > 0 ? (int)$qUserId : '' ?>">
      <div id="userTypeaheadResults" class="typeahead-results"></div>
    </label>
    <label>Action Type
      <select name="action_type"  style="width: auto;">
        <option value="">Any Type</option>
        <?php foreach ($actionTypes as $t): $sel = ($qActionType === $t) ? ' selected' : ''; ?>
          <option value="<?= h($t) ?>"<?= $sel ?>><?= h($t) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Page size
      <select name="limit"  style="width: auto;">
        <?php foreach ($limitOptions as $opt): $sel = ($qLimit === $opt) ? ' selected' : ''; ?>
          <option value="<?= (int)$opt ?>"<?= $sel ?>><?= (int)$opt ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div>
      <button class="button primary" type="submit">Filter</button>
      <a class="button" href="/admin_activity_log.php">Reset</a>
    </div>
  </form>
</div>

<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <h3>Results</h3>
    <div class="small">Total: <?= (int)$total ?> | Page <?= (int)$qPage ?> of <?= (int)$totalPages ?></div>
  </div>

  <?php if (empty($rows)): ?>
    <p class="small">No activity entries found.</p>
  <?php else: ?>
    <table class="list">
      <thead>
        <tr>
          <th>When</th>
          <th>User</th>
          <th>Action</th>
          <th>Metadata</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="small"><?= h(Settings::formatDateTime($r['created_at'] ?? '')) ?></td>
            <td>
              <?php
                $uid = isset($r['user_id']) ? (int)$r['user_id'] : 0;
                if ($uid > 0) {
                  $label = $userMap[$uid] ?? ('User #'.$uid);
                  echo h($label);
                } else {
                  echo 'System';
                }
              ?>
            </td>
            <td class="small"><?= h($r['action_type'] ?? '') ?></td>
            <td class="small">
              <?php
                $metaRaw = (string)($r['json_metadata'] ?? '');
                if ($metaRaw === '' || $metaRaw === 'null') {
                  echo '<span class="muted">—</span>';
                } else {
                  // Trim overly long metadata for display
                  $display = $metaRaw;
                  if (mb_strlen($display) > 300) {
                    $display = mb_substr($display, 0, 300) . '…';
                  }
                  echo '<code>' . h($display) . '</code>';
                }
              ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="actions" style="margin-top:8px;display:flex;align-items:center;gap:8px;justify-content:flex-end;">
      <?php if ($qPage > 1): ?>
        <a class="button" href="<?= h(build_url(['page' => $qPage - 1])) ?>">Prev</a>
      <?php else: ?>
        <span class="button disabled" aria-disabled="true">Prev</span>
      <?php endif; ?>
      <?php if ($qPage < $totalPages): ?>
        <a class="button" href="<?= h(build_url(['page' => $qPage + 1])) ?>">Next</a>
      <?php else: ?>
        <span class="button disabled" aria-disabled="true">Next</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
