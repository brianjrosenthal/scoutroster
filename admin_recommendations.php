<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/Search.php';
require_once __DIR__.'/settings.php';
require_once __DIR__.'/lib/Recommendations.php';
require_admin();

$msg = null;
$err = null;

// Inputs
$q = trim($_GET['q'] ?? '');
$sf = trim($_GET['s'] ?? 'new_active'); // new_active|new|active|joined|unsubscribed

// Build query
$rows = Recommendations::list(['q' => $q, 'status' => $sf]);

header_html('Recommendations');
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Recommendations</h2>
  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
    <a class="button" href="/recommend.php">Recommend a friend!</a>
  </div>
</div>

<div class="card">
  <form id="filterForm" method="get" class="stack">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Search
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Parent, child, email, phone">
      </label>
      <label>Status
        <select name="s">
          <option value="new_active" <?= $sf==='new_active'?'selected':'' ?>>New and Active</option>
          <option value="new" <?= $sf==='new'?'selected':'' ?>>New</option>
          <option value="active" <?= $sf==='active'?'selected':'' ?>>Active</option>
          <option value="joined" <?= $sf==='joined'?'selected':'' ?>>Joined</option>
          <option value="unsubscribed" <?= $sf==='unsubscribed'?'selected':'' ?>>Unsubscribed</option>
        </select>
      </label>
    </div>
  </form>
  <script>
    (function(){
      var f=document.getElementById('filterForm');
      if(!f) return;
      var q=f.querySelector('input[name="q"]');
      var s=f.querySelector('select[name="s"]');
      var t;
      function submitNow(){ if(typeof f.requestSubmit==='function') f.requestSubmit(); else f.submit(); }
      if(q){ q.addEventListener('input', function(){ if(t) clearTimeout(t); t=setTimeout(submitNow,600); }); }
      if(s){ s.addEventListener('change', submitNow); }
    })();
  </script>
</div>

<div class="card">
  <?php if (empty($rows)): ?>
    <p class="small">No recommendations found.</p>
  <?php else: ?>
    <table class="list">
      <thead>
        <tr>
          <th>Parent</th>
          <th>Child</th>
          <th>Contact</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $contact = [];
            if (!empty($r['email'])) $contact[] = h($r['email']);
            if (!empty($r['phone'])) $contact[] = h($r['phone']);
            $statusLabel = isset($r['status']) ? ucfirst((string)$r['status']) : '';
          ?>
          <tr>
            <td><?= h($r['parent_name']) ?></td>
            <td><?= h($r['child_name']) ?></td>
            <td><?= !empty($contact) ? implode('<br>', $contact) : '&mdash;' ?></td>
            <td><?= $statusLabel !== '' ? h($statusLabel) : '&mdash;' ?></td>
            <td class="small">
              <a class="button" href="/admin_recommendation_view.php?id=<?= (int)$r['id'] ?>">View</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
