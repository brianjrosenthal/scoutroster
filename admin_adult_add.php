<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/UserManagement.php';
require_once __DIR__.'/lib/YouthManagement.php';
require_admin();

$msg = null;
$err = null;

// Helper: normalize empty string to NULL
function nn($v) { $v = is_string($v) ? trim($v) : $v; return ($v === '' ? null : $v); }

// For repopulating form after errors
$form = [];

// Prefill from GET on initial load (for convenience links like from recommendations)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  $prefillKeys = ['first_name','last_name','email','preferred_name','phone_cell','phone_home'];
  foreach ($prefillKeys as $k) {
    if (isset($_GET[$k]) && !isset($form[$k])) {
      $val = trim((string)$_GET[$k]);
      // Basic sanitation; form rendering escapes via h()
      $form[$k] = $val;
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  // Capture raw POST into $form for re-population on error
  $form = $_POST;

  // Required
  $first = trim($_POST['first_name'] ?? '');
  $last  = trim($_POST['last_name'] ?? '');

  // Email is optional (nullable schema); coerce blank to NULL
  $rawEmail = trim($_POST['email'] ?? '');
  $email = ($rawEmail === '') ? null : strtolower($rawEmail);

  $is_admin = !empty($_POST['is_admin']) ? 1 : 0;

  // Optional personal/contact
  $preferred_name = nn($_POST['preferred_name'] ?? '');
  $street1 = nn($_POST['street1'] ?? '');
  $street2 = nn($_POST['street2'] ?? '');
  $city    = nn($_POST['city'] ?? '');
  $state   = nn($_POST['state'] ?? '');
  $zip     = nn($_POST['zip'] ?? '');
  $email2  = nn($_POST['email2'] ?? '');
  $phone_home = nn($_POST['phone_home'] ?? '');
  $phone_cell = nn($_POST['phone_cell'] ?? '');
  $shirt_size = nn($_POST['shirt_size'] ?? '');

  // Optional scouting (admin-editable)
  $bsa_membership_number = nn($_POST['bsa_membership_number'] ?? '');
  $bsa_registration_expires_on = nn($_POST['bsa_registration_expires_on'] ?? '');
  $safeguarding_training_completed_on = nn($_POST['safeguarding_training_completed_on'] ?? '');

  // Optional emergency
  $em1_name  = nn($_POST['emergency_contact1_name'] ?? '');
  $em1_phone = nn($_POST['emergency_contact1_phone'] ?? '');
  $em2_name  = nn($_POST['emergency_contact2_name'] ?? '');
  $em2_phone = nn($_POST['emergency_contact2_phone'] ?? '');

  $errors = [];
  if ($first === '') $errors[] = 'First name is required.';
  if ($last === '')  $errors[] = 'Last name is required.';
  // If email provided, validate format
  if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email is invalid.';
  }
  // Validate dates (if provided)
  foreach ([
    'bsa_registration_expires_on' => $bsa_registration_expires_on,
    'safeguarding_training_completed_on' => $safeguarding_training_completed_on
  ] as $k => $v) {
    if ($v !== null) {
      $d = DateTime::createFromFormat('Y-m-d', $v);
      if (!$d || $d->format('Y-m-d') !== $v) {
        $errors[] = ucfirst(str_replace('_',' ', $k)).' must be YYYY-MM-DD.';
      }
    }
  }

  if (empty($errors)) {
    try {
      $id = UserManagement::createAdultWithDetails(UserContext::getLoggedInUserContext(), [
        'first_name' => $first,
        'last_name' => $last,
        'email' => $email,
        'is_admin' => $is_admin,
        'preferred_name' => $preferred_name,
        'street1' => $street1,
        'street2' => $street2,
        'city' => $city,
        'state' => $state,
        'zip' => $zip,
        'email2' => $email2,
        'phone_home' => $phone_home,
        'phone_cell' => $phone_cell,
        'shirt_size' => $shirt_size,
         'bsa_membership_number' => $bsa_membership_number,
        'bsa_registration_expires_on' => $bsa_registration_expires_on,
        'safeguarding_training_completed_on' => $safeguarding_training_completed_on,
        'emergency_contact1_name' => $em1_name,
        'emergency_contact1_phone' => $em1_phone,
        'emergency_contact2_name' => $em2_name,
        'emergency_contact2_phone' => $em2_phone,
      ]);

      // Process staged children (pending_children JSON)
      try {
        $ctx = UserContext::getLoggedInUserContext();
        $pendingJson = $_POST['pending_children'] ?? '[]';
        $items = json_decode($pendingJson, true);
        if (is_array($items)) {
          $link = pdo()->prepare('INSERT IGNORE INTO parent_relationships (youth_id, adult_id) VALUES (?, ?)');
          $chkYouth = pdo()->prepare('SELECT 1 FROM youth WHERE id=? LIMIT 1');
          foreach ($items as $it) {
            if (!is_array($it) || empty($it['type'])) continue;
            if ($it['type'] === 'new' && !empty($it['child']) && is_array($it['child'])) {
              $c = $it['child'];
              $data = [
                'first_name' => trim((string)($c['first_name'] ?? '')),
                'last_name' => trim((string)($c['last_name'] ?? '')),
                'preferred_name' => trim((string)($c['preferred_name'] ?? '')),
                'suffix' => trim((string)($c['suffix'] ?? '')),
                'grade_label' => (string)($c['grade_label'] ?? ''),
                'school' => trim((string)($c['school'] ?? '')),
                'sibling' => !empty($c['sibling']) ? 1 : 0,
              ];
              if ($data['first_name'] !== '' && $data['last_name'] !== '' && $data['grade_label'] !== '') {
                $newYid = \YouthManagement::create($ctx, $data);
                $link->execute([$newYid, (int)$id]);
              }
            } elseif ($it['type'] === 'link' && !empty($it['youth_id'])) {
              $yid = (int)$it['youth_id'];
              if ($yid > 0) {
                $chkYouth->execute([$yid]);
                if ($chkYouth->fetchColumn()) {
                  $link->execute([$yid, (int)$id]);
                }
              }
            }
          }
        }
      } catch (Throwable $e) {
        // Ignore child staging errors; adult was created successfully
      }

      header('Location: /adult_edit.php?id='.(int)$id.'&created=1'); exit;
    } catch (Throwable $e) {
      // Likely duplicate email (if not null) or other constraint
      $err = 'Error creating adult. Ensure the email (if provided) is unique';
    }
  } else {
    $err = implode(' ', $errors);
  }
}

header_html('Create Adult');
?>
<h2>Create Adult</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <h3>Basic</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>First name
        <input type="text" name="first_name" value="<?=h($form['first_name'] ?? '')?>" required>
      </label>
      <label>Last name
        <input type="text" name="last_name" value="<?=h($form['last_name'] ?? '')?>" required>
      </label>
      <label>Email (optional)
        <input type="email" name="email" value="<?=h($form['email'] ?? '')?>">
      </label>
      <label>Preferred name
        <input type="text" name="preferred_name" value="<?=h($form['preferred_name'] ?? '')?>">
      </label>
      <label>Cell Phone
        <input type="text" name="phone_cell" value="<?=h($form['phone_cell'] ?? '')?>">
      </label>
      <label class="inline"><input type="checkbox" name="is_admin" value="1" <?= !empty($form['is_admin']) ? 'checked' : '' ?>> Admin</label>
    </div>

    <div class="card" style="margin-top:8px;">
      <h3 style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        Children to Add
        <button type="button" class="button" data-open-child-modal="ac_add">Add Child</button>
      </h3>
      <input type="hidden" name="pending_children" id="pending_children" value="<?= h($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['pending_children'] ?? '[]') : '[]') ?>">
      <div id="pending_children_list" class="stack"></div>
    </div>


    <script>
      (function(){
        var hidden = document.getElementById('pending_children');
        var listEl = document.getElementById('pending_children_list');
        var items = [];
        try { items = JSON.parse(hidden && hidden.value ? hidden.value : '[]') || []; } catch (e) { items = []; }

        function serialize() {
          if (hidden) hidden.value = JSON.stringify(items);
        }
        function removeAt(idx) {
          if (idx >= 0 && idx < items.length) {
            items.splice(idx, 1);
            render();
          }
        }
        function dedupeNew(item) {
          // Prevent duplicate new child by same first/last/grade
          for (var i=0;i<items.length;i++){
            var it = items[i];
            if (it.type === 'new' && item.type === 'new') {
              var a = it.child || {}; var b = item.child || {};
              if ((a.first_name||'').toLowerCase() === (b.first_name||'').toLowerCase() &&
                  (a.last_name||'').toLowerCase() === (b.last_name||'').toLowerCase() &&
                  (a.grade_label||'') === (b.grade_label||'')) return true;
            }
          }
          return false;
        }
        function hasLink(yid) {
          yid = parseInt(yid,10)||0;
          for (var i=0;i<items.length;i++){
            var it = items[i];
            if (it.type === 'link' && parseInt(it.youth_id,10) === yid) return true;
          }
          return false;
        }
        function render() {
          serialize();
          if (!listEl) return;
          listEl.innerHTML = '';
          if (!items.length) {
            var p = document.createElement('p');
            p.textContent = 'No children staged.';
            listEl.appendChild(p);
            return;
          }
          var ul = document.createElement('ul');
          ul.className = 'list';
          items.forEach(function(it, idx){
            var li = document.createElement('li');
            var label = it.label || (it.type === 'link' ? ('Youth #' + it.youth_id) : ((it.child && (it.child.last_name + ', ' + it.child.first_name)) || 'New child'));
            li.textContent = (it.type === 'link' ? '[Link] ' : '[New] ') + label + ' ';
            var btn = document.createElement('button');
            btn.className = 'button danger';
            btn.type = 'button';
            btn.style.marginLeft = '8px';
            btn.textContent = 'Remove';
            btn.addEventListener('click', function(){ removeAt(idx); });
            li.appendChild(btn);
            ul.appendChild(li);
          });
          listEl.appendChild(ul);
        }

        function addStagedItem(d) {
          if (!d) return;
          if (d.type === 'link') {
            if (!hasLink(d.youth_id)) {
              items.push({ type: 'link', youth_id: parseInt(d.youth_id,10)||0, label: d.label || null });
              render();
            }
          } else if (d.type === 'new') {
            if (!dedupeNew(d)) {
              items.push(d);
              render();
            }
          }
        }
        window.addEventListener('childModal:add', function(e){
          addStagedItem((e && e.detail && e.detail.item) || null);
        });
        // Optional callback hook used by modal
        window.childModalAdd_ac_add = function(payload){
          var d = payload && payload.item;
          addStagedItem(d);
        };

        render();
      })();
    </script>

    <div class="actions">
      <button class="primary" type="submit">Create</button>
      <a class="button" href="/admin_adults.php">Cancel</a>
    </div>
    <small class="small">Note: This creates an adult record without a login. Use the Invite action later to let them activate their account and set a password.</small>
  </form>
</div>

<?php
  // Render modal outside the main form to avoid nested forms
  require_once __DIR__ . '/partials_child_modal.php';
  render_child_modal(['mode' => 'add', 'id_prefix' => 'ac_add']);
  footer_html();
?>
