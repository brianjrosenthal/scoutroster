<?php
require_once __DIR__.'/partials.php';
require_once __DIR__.'/lib/GradeCalculator.php';
require_once __DIR__.'/lib/YouthManagement.php';
require_once __DIR__.'/lib/UserManagement.php';
require_admin();

$msg = null;
$err = null;
$canEditPaidUntil = \UserManagement::isApprover((int)(current_user()['id'] ?? 0));

// Handle POST (create)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  // Required fields
  $first = trim($_POST['first_name'] ?? '');
  $last  = trim($_POST['last_name'] ?? '');
  $suffix = trim($_POST['suffix'] ?? '');
  $gradeLabel = trim($_POST['grade'] ?? ''); // K,0..5
  $street1 = trim($_POST['street1'] ?? '');
  $city    = trim($_POST['city'] ?? '');
  $state   = trim($_POST['state'] ?? '');
  $zip     = trim($_POST['zip'] ?? '');

  // Optional fields
  $preferred = trim($_POST['preferred_name'] ?? '');
  $gender = $_POST['gender'] ?? null; // enum or null
  $birthdate = trim($_POST['birthdate'] ?? '');
  $school = trim($_POST['school'] ?? '');
  $shirt = trim($_POST['shirt_size'] ?? '');
  $bsa = trim($_POST['bsa_registration_number'] ?? '');
  $street2 = trim($_POST['street2'] ?? '');
  $sibling = !empty($_POST['sibling']) ? 1 : 0;

  // Registration & Dues (admin/approver controlled)
  $regExpires = trim($_POST['bsa_registration_expires_date'] ?? '');
  $paidUntil  = trim($_POST['date_paid_until'] ?? '');

  // Validate required fields
  $errors = [];
  if ($first === '') $errors[] = 'First name is required.';
  if ($last === '')  $errors[] = 'Last name is required.';
  $g = GradeCalculator::parseGradeLabel($gradeLabel);
  if ($g === null) $errors[] = 'Grade is required.';

  // Normalize/validate enums and dates
  $allowedGender = ['male','female','non-binary','prefer not to say'];
  if ($gender !== null && $gender !== '' && !in_array($gender, $allowedGender, true)) {
    $gender = null;
  }
  if ($birthdate !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $birthdate);
    if (!$d || $d->format('Y-m-d') !== $birthdate) {
      $errors[] = 'Birthdate must be in YYYY-MM-DD format.';
    }
  }
  if ($regExpires !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $regExpires);
    if (!$d || $d->format('Y-m-d') !== $regExpires) {
      $errors[] = 'Registration Expires must be in YYYY-MM-DD format.';
    }
  }
  if ($paidUntil !== '') {
    if (!$canEditPaidUntil) {
      // ignore for safety; non-approvers cannot set this
      $paidUntil = '';
    } else {
      $d = DateTime::createFromFormat('Y-m-d', $paidUntil);
      if (!$d || $d->format('Y-m-d') !== $paidUntil) {
        $errors[] = 'Paid Until must be in YYYY-MM-DD format.';
      }
    }
  }

  $adultId = (int)($_POST['adult_id'] ?? 0);
$adultId2 = (int)($_POST['adult_id2'] ?? 0);
if ($adultId <= 0) { $errors[] = 'You must specify a parent to add a child.'; }
if ($adultId2 === $adultId) { $adultId2 = 0; }

if (empty($errors)) {
    // Compute class_of from grade
    $currentFifthClassOf = GradeCalculator::schoolYearEndYear();
    $class_of = $currentFifthClassOf + (5 - (int)$g);

    try {
      $ctx = UserContext::getLoggedInUserContext();
      $pdo = pdo();
      $pdo->beginTransaction();

      $createData = [
        'first_name' => $first,
        'last_name' => $last,
        'suffix' => $suffix,
        'grade_label' => $gradeLabel,
        'preferred_name' => $preferred,
        'gender' => $gender,
        'birthdate' => $birthdate,
        'school' => $school,
        'shirt_size' => $shirt,
        'bsa_registration_number' => $bsa,
        'street1' => $street1,
        'street2' => $street2,
        'city' => $city,
        'state' => $state,
        'zip' => $zip,
        'sibling' => $sibling,
      ];
      if ($regExpires !== '') {
        $createData['bsa_registration_expires_date'] = $regExpires;
      }
      if ($canEditPaidUntil && $paidUntil !== '') {
        $createData['date_paid_until'] = $paidUntil;
      }

      // Create youth
      $id = YouthManagement::create($ctx, $createData);

      // Ensure adult exists
      $stA = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
      $stA->execute([$adultId]);
      if (!$stA->fetchColumn()) {
        throw new RuntimeException('Selected adult not found.');
      }
      if ($adultId2 > 0) {
        $stA->execute([$adultId2]);
        if (!$stA->fetchColumn()) {
          throw new RuntimeException('Selected second adult not found.');
        }
      }

      // Link youth to adult(s)
      $stRel = $pdo->prepare('INSERT INTO parent_relationships (youth_id, adult_id) VALUES (?, ?)');
      $stRel->execute([$id, $adultId]);
      if ($adultId2 > 0) {
        $stRel->execute([$id, $adultId2]);
      }

      $pdo->commit();
      header('Location: /youth.php'); exit;
    } catch (Throwable $e) {
      if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
      $err = 'Error creating youth.';
    }
  } else {
    $err = implode(' ', $errors);
  }
}

$selectedGradeLabel = \GradeCalculator::gradeLabel(0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $selectedGradeLabel = trim($_POST['grade'] ?? $selectedGradeLabel);
}
header_html('Add Youth');
?>
<h2>Add Youth</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <h3>Select parent(s)</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <?php
        $selAdult = isset($_POST['adult_id']) ? (int)$_POST['adult_id'] : 0;
        $selAdult2 = isset($_POST['adult_id2']) ? (int)$_POST['adult_id2'] : 0;

        $label1 = '';
        if ($selAdult > 0) {
          $st = pdo()->prepare("SELECT last_name, first_name, email FROM users WHERE id=?");
          $st->execute([$selAdult]);
          if ($u = $st->fetch()) {
            $label1 = trim((string)($u['last_name'] ?? '') . ', ' . (string)($u['first_name'] ?? '')) . (!empty($u['email']) ? ' <'.(string)$u['email'].'>' : '');
          }
        }
        $label2 = '';
        if ($selAdult2 > 0) {
          $st = pdo()->prepare("SELECT last_name, first_name, email FROM users WHERE id=?");
          $st->execute([$selAdult2]);
          if ($u2 = $st->fetch()) {
            $label2 = trim((string)($u2['last_name'] ?? '') . ', ' . (string)($u2['first_name'] ?? '')) . (!empty($u2['email']) ? ' <'.(string)$u2['email'].'>' : '');
          }
        }
      ?>
      <div class="stack">
        <label for="adult_search_1">Parent</label>
        <input type="hidden" name="adult_id" id="adult_id_1" value="<?= (int)$selAdult ?>">
        <input
          type="text"
          id="adult_search_1"
          placeholder="Type to search adults by name or email"
          autocomplete="off"
          role="combobox"
          aria-expanded="false"
          aria-owns="adult_results_1_list"
          aria-autocomplete="list"
          value="<?= h($label1) ?>">
        <div id="adult_results_1" class="typeahead-results" role="listbox" style="position:relative;">
          <div id="adult_results_1_list" class="list" style="position:absolute; z-index:1000; background:#fff; border:1px solid #ccc; max-height:200px; overflow:auto; width:100%; display:none;"></div>
        </div>
        <button type="button" class="button" id="adult_clear_1" style="margin-top:4px;">Clear</button>
      </div>

      <div class="stack">
        <label for="adult_search_2">Parent 2</label>
        <input type="hidden" name="adult_id2" id="adult_id_2" value="<?= (int)$selAdult2 ?>">
        <input
          type="text"
          id="adult_search_2"
          placeholder="Optional: search another adult"
          autocomplete="off"
          role="combobox"
          aria-expanded="false"
          aria-owns="adult_results_2_list"
          aria-autocomplete="list"
          value="<?= h($label2) ?>">
        <div id="adult_results_2" class="typeahead-results" role="listbox" style="position:relative;">
          <div id="adult_results_2_list" class="list" style="position:absolute; z-index:1000; background:#fff; border:1px solid #ccc; max-height:200px; overflow:auto; width:100%; display:none;"></div>
        </div>
        <button type="button" class="button" id="adult_clear_2" style="margin-top:4px;">Clear</button>
      </div>
    </div>

    <script>
      (function(){
        function debounce(fn, wait) {
          let t = null;
          return function() {
            const ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(function(){ fn.apply(ctx, args); }, wait);
          };
        }

        function attachTypeahead(opts) {
          const input = document.getElementById(opts.inputId);
          const hidden = document.getElementById(opts.hiddenId);
          const otherHidden = document.getElementById(opts.otherHiddenId);
          const resultsWrap = document.getElementById(opts.resultsWrapId);
          const list = document.getElementById(opts.resultsListId);
          const clearBtn = document.getElementById(opts.clearBtnId);
          let items = [];
          let highlight = -1;
          let open = false;

          function close() {
            if (!list) return;
            list.style.display = 'none';
            input.setAttribute('aria-expanded', 'false');
            open = false;
            highlight = -1;
            input.removeAttribute('aria-activedescendant');
          }

          function openList() {
            if (!list) return;
            if (items.length === 0) { close(); return; }
            list.style.display = '';
            input.setAttribute('aria-expanded', 'true');
            open = true;
          }

          function render() {
            if (!list) return;
            list.innerHTML = '';
            const excludeId = (otherHidden && otherHidden.value) ? parseInt(otherHidden.value, 10) : 0;
            const frag = document.createDocumentFragment();
            items.forEach(function(it, idx){
              if (excludeId && parseInt(it.id, 10) === excludeId) return;
              const div = document.createElement('div');
              div.setAttribute('role', 'option');
              div.setAttribute('id', opts.resultsListId + '_opt_' + idx);
              div.setAttribute('tabindex', '-1');
              div.style.padding = '6px 8px';
              div.style.cursor = 'pointer';
              div.textContent = it.label;
              if (idx === highlight) {
                div.style.background = '#eef';
              }
              div.addEventListener('mousedown', function(e){
                // prevent blur before click handler
                e.preventDefault();
              });
              div.addEventListener('click', function(){
                select(idx);
              });
              frag.appendChild(div);
            });
            list.appendChild(frag);
            openList();
          }

          function select(idx) {
            const excludeId = (otherHidden && otherHidden.value) ? parseInt(otherHidden.value, 10) : 0;
            // compute visible items mapping to original items (since we filtered duplicates in render)
            const visible = items.filter(function(it){
              return !(excludeId && parseInt(it.id, 10) === excludeId);
            });
            const it = visible[idx];
            if (!it) return;
            if (excludeId && parseInt(it.id, 10) === excludeId) {
              alert('Parent 2 cannot be the same as Parent 1.');
              return;
            }
            hidden.value = it.id;
            input.value = it.label;
            close();
          }

          const doSearch = debounce(function(){
            const q = (input.value || '').trim();
            if (q.length < 1) { items = []; render(); return; }
            fetch('/admin_adult_search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
              .then(function(r){ return r.json(); })
              .then(function(json){
                if (!json || !json.ok) { items = []; render(); return; }
                items = json.items || [];
                highlight = (items.length > 0 ? 0 : -1);
                render();
                if (items.length > 0) {
                  input.setAttribute('aria-activedescendant', opts.resultsListId + '_opt_0');
                } else {
                  input.removeAttribute('aria-activedescendant');
                }
              })
              .catch(function(){ items = []; render(); });
          }, 200);

          if (input) {
            input.addEventListener('input', function(){
              // Clear selected id if user edits text
              hidden.value = hidden.value || '';
              doSearch();
            });
            input.addEventListener('keydown', function(e){
              if (!open && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                openList();
              }
              if (e.key === 'ArrowDown') {
                e.preventDefault();
                const excludeId = (otherHidden && otherHidden.value) ? parseInt(otherHidden.value, 10) : 0;
                const visibleCount = items.filter(function(it){ return !(excludeId && parseInt(it.id, 10) === excludeId); }).length;
                if (visibleCount === 0) return;
                highlight = (highlight + 1) % visibleCount;
                input.setAttribute('aria-activedescendant', opts.resultsListId + '_opt_' + highlight);
                render();
              } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                const excludeId = (otherHidden && otherHidden.value) ? parseInt(otherHidden.value, 10) : 0;
                const visibleCount = items.filter(function(it){ return !(excludeId && parseInt(it.id, 10) === excludeId); }).length;
                if (visibleCount === 0) return;
                highlight = (highlight - 1 + visibleCount) % visibleCount;
                input.setAttribute('aria-activedescendant', opts.resultsListId + '_opt_' + highlight);
                render();
              } else if (e.key === 'Enter') {
                if (open && highlight >= 0) {
                  e.preventDefault();
                  select(highlight);
                }
              } else if (e.key === 'Escape') {
                close();
              }
            });
            input.addEventListener('blur', function(){
              // Slight delay to allow click on option
              setTimeout(close, 120);
            });
          }

          if (clearBtn) {
            clearBtn.addEventListener('click', function(){
              if (hidden) hidden.value = '';
              if (input) input.value = '';
              items = [];
              render();
            });
          }
        }

        attachTypeahead({
          inputId: 'adult_search_1',
          hiddenId: 'adult_id_1',
          otherHiddenId: 'adult_id_2',
          resultsWrapId: 'adult_results_1',
          resultsListId: 'adult_results_1_list',
          clearBtnId: 'adult_clear_1'
        });
        attachTypeahead({
          inputId: 'adult_search_2',
          hiddenId: 'adult_id_2',
          otherHiddenId: 'adult_id_1',
          resultsWrapId: 'adult_results_2',
          resultsListId: 'adult_results_2_list',
          clearBtnId: 'adult_clear_2'
        });
      })();
    </script>

    <h3>Child</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>First name
        <input type="text" name="first_name" value="<?= h($first ?? '') ?>" required>
      </label>
      <label>Last name
        <input type="text" name="last_name" value="<?= h($last ?? '') ?>" required>
      </label>
      <label>Suffix
        <input type="text" name="suffix" value="<?= h($suffix ?? '') ?>" placeholder="Jr, III">
      </label>
      <label>Preferred name
        <input type="text" name="preferred_name" value="<?= h($preferred ?? '') ?>">
      </label>
      <label>Grade
        <select name="grade" required>
          <?php
            $grades = [-3,-2,-1,0,1,2,3,4,5,6,7,8,9,10,11,12];
            foreach ($grades as $i):
              $lbl = \GradeCalculator::gradeLabel($i);
          ?>
            <option value="<?= h($lbl) ?>" <?= ($selectedGradeLabel === $lbl ? 'selected' : '') ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>School
        <input type="text" name="school" value="<?= h($school ?? '') ?>">
      </label>
      <label class="inline"><input type="checkbox" name="sibling" value="1" <?= !empty($sibling) ? 'checked' : '' ?>> Sibling</label>
    </div>




    <div class="actions">
      <button class="primary" type="submit">Create</button>
      <a class="button" href="/youth.php">Cancel</a>
    </div>
  </form>
</div>


<?php footer_html(); ?>
