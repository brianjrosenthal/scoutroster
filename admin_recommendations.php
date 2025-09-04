<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/Search.php';
require_once __DIR__.'/settings.php';
require_admin();

$msg = null;
$err = null;

// Inputs
$q = trim($_GET['q'] ?? '');
$rf = trim($_GET['r'] ?? 'all'); // all|yes|no

// Build query
$params = [];
$sql = "
  SELECT r.*,
         u.first_name AS submit_first,
         u.last_name  AS submit_last
  FROM recommendations r
  JOIN users u ON u.id = r.created_by_user_id
  WHERE 1=1
";

if ($q !== '') {
  $tokens = Search::tokenize($q);
  $sql .= Search::buildAndLikeClause(
    ['r.parent_name','r.child_name','r.email','r.phone','r.notes','u.first_name','u.last_name'],
    $tokens,
    $params
  );
}

if ($rf === 'yes') {
  $sql .= " AND r.reached_out = 1";
} elseif ($rf === 'no') {
  $sql .= " AND r.reached_out = 0";
}

$sql .= " ORDER BY r.created_at DESC";

$st = pdo()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

header_html('Recommendations');
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Recommendations</h2>
</div>

<div class="card">
  <form id="filterForm" method="get" class="stack">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>Search
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Parent, child, email, phone, notes, submitter">
      </label>
      <label>Reached out
        <select name="r">
          <option value="all" <?= $rf==='all'?'selected':'' ?>>All</option>
          <option value="yes" <?= $rf==='yes'?'selected':'' ?>>Yes</option>
          <option value="no"  <?= $rf==='no'?'selected':'' ?>>No</option>
        </select>
      </label>
    </div>
  </form>
  <script>
    (function(){
      var f=document.getElementById('filterForm');
      if(!f) return;
      var q=f.querySelector('input[name="q"]');
      var r=f.querySelector('select[name="r"]');
      var t;
      function submitNow(){ if(typeof f.requestSubmit==='function') f.requestSubmit(); else f.submit(); }
      if(q){ q.addEventListener('input', function(){ if(t) clearTimeout(t); t=setTimeout(submitNow,600); }); }
      if(r){ r.addEventListener('change', submitNow); }
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
          <th>Submitted</th>
          <th>Submitted By</th>
          <th>Parent</th>
          <th>Child</th>
          <th>Contact</th>
          <th>Reached Out</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $submitted = Settings::formatDateTime($r['created_at'] ?? '');
            $submitter = trim((string)($r['submit_first'] ?? '').' '.(string)($r['submit_last'] ?? ''));
            $contact = [];
            if (!empty($r['email'])) $contact[] = h($r['email']);
            if (!empty($r['phone'])) $contact[] = h($r['phone']);
            $reached = !empty($r['reached_out']);
          ?>
          <tr>
            <td><?= h($submitted) ?></td>
            <td><?= h($submitter) ?></td>
            <td><?= h($r['parent_name']) ?></td>
            <td><?= h($r['child_name']) ?></td>
            <td class="small"><?= !empty($contact) ? implode('<br>', $contact) : '&mdash;' ?></td>
            <td><?= $reached ? 'Yes' : 'No' ?></td>
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
