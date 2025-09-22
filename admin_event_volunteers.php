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

$me = current_user();
$isAdmin = !empty($me['is_admin']);

header_html('Manage Volunteer Roles');
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
  <h2 style="margin: 0;">Manage Volunteers: <?= h($event['name']) ?></h2>
  <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
    <a class="button" href="/event.php?id=<?= (int)$eventId ?>">Back to Event</a>
    <?php if ($isAdmin): ?>
      <div style="position: relative;">
        <button class="button" id="adminLinksBtn" style="display: flex; align-items: center; gap: 4px;">
          Admin Links
          <span style="font-size: 12px;">▼</span>
        </button>
        <div id="adminLinksDropdown" style="
          display: none;
          position: absolute;
          top: 100%;
          right: 0;
          background: white;
          border: 1px solid #ddd;
          border-radius: 4px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.1);
          z-index: 1000;
          min-width: 180px;
          margin-top: 4px;
        ">
          <a href="/admin_event_edit.php?id=<?= (int)$eventId ?>" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Edit Event</a>
          <a href="/event_public.php?event_id=<?= (int)$eventId ?>" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Public RSVP Link</a>
          <a href="/admin_event_invite.php?event_id=<?= (int)$eventId ?>" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Invite</a>
          <a href="#" id="adminCopyEmailsBtn" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Copy Emails</a>
          <a href="#" id="adminManageRsvpBtn" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Manage RSVPs</a>
          <a href="#" id="adminExportAttendeesBtn" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Export Attendees</a>
          <a href="/event_dietary_needs.php?id=<?= (int)$eventId ?>" style="display: block; padding: 8px 12px; text-decoration: none; color: #333; border-bottom: 1px solid #eee;">Dietary Needs</a>
          <?php
            // Show Event Compliance only for Cubmaster, Treasurer, or Committee Chair
            $showCompliance = false;
            try {
              $stPos = pdo()->prepare("SELECT LOWER(position) AS p FROM adult_leadership_positions WHERE adult_id=?");
              $stPos->execute([(int)($me['id'] ?? 0)]);
              $rowsPos = $stPos->fetchAll();
              if (is_array($rowsPos)) {
                foreach ($rowsPos as $pr) {
                  $p = trim((string)($pr['p'] ?? ''));
                  if ($p === 'cubmaster' || $p === 'treasurer' || $p === 'committee chair') { 
                    $showCompliance = true; 
                    break; 
                  }
                }
              }
            } catch (Throwable $e) {
              $showCompliance = false;
            }
            if ($showCompliance): ?>
              <a href="/event_compliance.php?id=<?= (int)$eventId ?>" style="display: block; padding: 8px 12px; text-decoration: none; color: #333;">Event Compliance</a>
            <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  // Admin Links Dropdown
  const adminLinksBtn = document.getElementById('adminLinksBtn');
  const adminLinksDropdown = document.getElementById('adminLinksDropdown');
  
  if (adminLinksBtn && adminLinksDropdown) {
    adminLinksBtn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      const isVisible = adminLinksDropdown.style.display === 'block';
      adminLinksDropdown.style.display = isVisible ? 'none' : 'block';
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
      if (!adminLinksBtn.contains(e.target) && !adminLinksDropdown.contains(e.target)) {
        adminLinksDropdown.style.display = 'none';
      }
    });
    
    // Close dropdown when pressing Escape
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        adminLinksDropdown.style.display = 'none';
      }
    });
    
    // Add hover effects
    const dropdownLinks = adminLinksDropdown.querySelectorAll('a');
    dropdownLinks.forEach(link => {
      link.addEventListener('mouseenter', function() {
        this.style.backgroundColor = '#f5f5f5';
      });
      link.addEventListener('mouseleave', function() {
        this.style.backgroundColor = 'white';
      });
    });
  }
})();
</script>

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
