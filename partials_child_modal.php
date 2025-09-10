<?php
// Reusable child modal (Add New / Link Existing) for both adult_edit.php (mode=edit) and admin_adult_add.php (mode=add).
// Usage:
//   require_once __DIR__ . '/partials_child_modal.php';
//   render_child_modal(['mode' => 'edit'|'add', 'adult_id' => int|null, 'id_prefix' => 'ac']);
// In mode='edit': submits via AJAX to /adult_relationships.php and redirects to adult_edit.php?id={adult_id}&child_added=1.
// In mode='add': dispatches CustomEvent('childModal:add', { detail: { item } }) where item is:
//   - { type: 'new', child: { first_name, last_name, suffix?, preferred_name?, grade_label, school?, sibling: 0|1 }, label: "..."} or
//   - { type: 'link', youth_id: number, label: "Last, First" }
// Host page should add a button with: data-open-child-modal="{id_prefix}" to open the modal.

if (!function_exists('render_child_modal')) {
  function render_child_modal(array $opts = []): void {
    $mode = $opts['mode'] ?? 'edit';
    $adultId = $opts['adult_id'] ?? null;
    $idPrefix = $opts['id_prefix'] ?? 'ac';

    // Load minimal youth list for "Link Existing"
    try {
      $allY = pdo()->query("SELECT id, first_name, last_name FROM youth ORDER BY last_name, first_name")->fetchAll();
    } catch (Throwable $e) {
      $allY = [];
    }

    // Build IDs
    $modalId = $idPrefix . '_modal';
    $tabNewId = $idPrefix . '_tabNew';
    $tabLinkId = $idPrefix . '_tabLink';
    $panelNewId = $idPrefix . '_panelNew';
    $panelLinkId = $idPrefix . '_panelLink';
    $errBoxId = $idPrefix . '_err';
    $formNewId = $idPrefix . '_formNew';
    $formLinkId = $idPrefix . '_formLink';
    $closeBtnId = $idPrefix . '_close';

    // Render modal
    ?>
    <div id="<?= h($modalId) ?>" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
      <div class="modal-content">
        <button class="close" type="button" id="<?= h($closeBtnId) ?>" aria-label="Close">&times;</button>
        <h3>Add Child</h3>
        <div style="display:flex;gap:8px;margin-bottom:8px;">
          <button class="button" id="<?= h($tabNewId) ?>" aria-controls="<?= h($panelNewId) ?>">Add New Child</button>
          <button class="button" id="<?= h($tabLinkId) ?>" aria-controls="<?= h($panelLinkId) ?>">Link Existing Child</button>
        </div>
        <div id="<?= h($errBoxId) ?>" class="error small" style="display:none;"></div>

        <div id="<?= h($panelNewId) ?>" class="stack">
          <form id="<?= h($formNewId) ?>" class="stack" <?= $mode === 'edit' ? 'action="/adult_relationships.php" method="post"' : 'onsubmit="return false;"' ?>>
            <?php if ($mode === 'edit'): ?>
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="ajax" value="1">
              <input type="hidden" name="action" value="create_and_link">
              <input type="hidden" name="adult_id" value="<?= (int)$adultId ?>">
            <?php endif; ?>
            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
              <label>First name
                <input type="text" name="first_name" required>
              </label>
              <label>Last name
                <input type="text" name="last_name" required>
              </label>
              <label>Suffix
                <input type="text" name="suffix" placeholder="Jr, III">
              </label>
              <label>Preferred name
                <input type="text" name="preferred_name">
              </label>
              <label>Grade
                <select name="grade" required>
                  <?php for($i=0;$i<=5;$i++): $lbl = \GradeCalculator::gradeLabel($i); ?>
                    <option value="<?= h($lbl) ?>"><?= $i===0 ? 'K' : $i ?></option>
                  <?php endfor; ?>
                </select>
              </label>
              <label>School
                <input type="text" name="school">
              </label>
              <label class="inline"><input type="checkbox" name="sibling" value="1"> Sibling</label>
            </div>
            <div class="actions">
              <button class="button primary" type="<?= $mode === 'edit' ? 'submit' : 'button' ?>" 
              id="<?= h($formNewId) ?>_submit">Add Child</button>
            </div>
          </form>
        </div>

        <div id="<?= h($panelLinkId) ?>" class="stack" style="display:none;">
          <form id="<?= h($formLinkId) ?>" class="stack" <?= $mode === 'edit' ? 'action="/adult_relationships.php" method="post"' : 'onsubmit="return false;"' ?>>
            <?php if ($mode === 'edit'): ?>
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="ajax" value="1">
              <input type="hidden" name="action" value="link">
              <input type="hidden" name="adult_id" value="<?= (int)$adultId ?>">
            <?php endif; ?>
            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
              <label>Child
                <input type="hidden" name="youth_id" id="<?= h($idPrefix) ?>_youth_id" value="">
                <input
                  type="text"
                  id="<?= h($idPrefix) ?>_youth_search"
                  list="<?= h($idPrefix) ?>_youth_datalist"
                  placeholder="Type to search child by name"
                  autocomplete="off"
                  value="">
                <datalist id="<?= h($idPrefix) ?>_youth_datalist">
                  <?php foreach ($allY as $yy): ?>
                    <option data-id="<?= (int)($yy['id'] ?? 0) ?>" value="<?= h(trim(($yy['last_name'] ?? '').', '.($yy['first_name'] ?? ''))) ?>"></option>
                  <?php endforeach; ?>
                </datalist>
                <button type="button" class="button" id="<?= h($idPrefix) ?>_youth_clear" style="margin-top:4px;">Clear</button>
              </label>
            </div>
            <div class="actions">
              <button class="button primary" type="<?= $mode === 'edit' ? 'submit' : 'button' ?>" id="<?= h($formLinkId) ?>_submit">Link Child</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
    (function(){
      var MODE = <?= json_encode($mode) ?>;
      var IDP = <?= json_encode($idPrefix) ?>;
      var MODAL = document.getElementById(IDP + '_modal');
      var BTN_CLOSE = document.getElementById(IDP + '_close');
      var TAB_NEW = document.getElementById(IDP + '_tabNew');
      var TAB_LINK = document.getElementById(IDP + '_tabLink');
      var PANEL_NEW = document.getElementById(IDP + '_panelNew');
      var PANEL_LINK = document.getElementById(IDP + '_panelLink');
      var ERR = document.getElementById(IDP + '_err');
      var FORM_NEW = MODAL ? MODAL.querySelector('#' + IDP + '_formNew') : null;
      var FORM_LINK = MODAL ? MODAL.querySelector('#' + IDP + '_formLink') : null;

      function resetNewForm() {
        var form = FORM_NEW || (MODAL ? MODAL.querySelector('#' + IDP + '_formNew') : null);
        if (!form) return;
        var fns = ['first_name','last_name','suffix','preferred_name','school'];
        for (var i=0;i<fns.length;i++){
          var inp = form.querySelector('[name="' + fns[i] + '"]');
          if (inp) inp.value = '';
        }
        var gradeSel = form.querySelector('select[name="grade"]');
        if (gradeSel) gradeSel.selectedIndex = 0;
        var sib = form.querySelector('input[name="sibling"]');
        if (sib) sib.checked = false;
      }
      function resetLinkForm() {
        var form = FORM_LINK || (MODAL ? MODAL.querySelector('#' + IDP + '_formLink') : null);
        if (!form) return;
        var hid = document.getElementById(IDP + '_youth_id');
        var inp = document.getElementById(IDP + '_youth_search');
        if (hid) hid.value = '';
        if (inp) inp.value = '';
      }

      function showErr(msg) { if (ERR){ ERR.style.display=''; ERR.textContent = msg || 'Operation failed.'; } }
      function clearErr(){ if (ERR){ ERR.style.display='none'; ERR.textContent=''; } }

      function showModal(){
        if (!MODAL) return;
        MODAL.classList.remove('hidden');
        MODAL.setAttribute('aria-hidden','false');
        // re-resolve forms on open to avoid stale/null refs
        FORM_NEW = MODAL.querySelector('#' + IDP + '_formNew');
        FORM_LINK = MODAL.querySelector('#' + IDP + '_formLink');
        // present a fresh UI each open
        resetNewForm();
        resetLinkForm();
        switchTab('new');
      }
      function hideModal(){ if (MODAL){ MODAL.classList.add('hidden'); MODAL.setAttribute('aria-hidden','true'); } }

      // Expose a helper and hook any launcher with matching data attribute
      window['openModal_' + IDP] = showModal;
      (function(){
        var launchers = document.querySelectorAll('[data-open-child-modal="' + IDP + '"]');
        for (var i=0; i<launchers.length; i++) {
          launchers[i].addEventListener('click', function(e){ e.preventDefault(); clearErr(); switchTab('new'); showModal(); });
        }
      })();

      if (BTN_CLOSE) BTN_CLOSE.addEventListener('click', hideModal);
      if (MODAL) MODAL.addEventListener('click', function(e){ if (e.target === MODAL) hideModal(); });

      function switchTab(which){
        if (!PANEL_NEW || !PANEL_LINK) return;
        if (which === 'new'){
          PANEL_NEW.style.display='';
          PANEL_LINK.style.display='none';
          resetNewForm();
        } else {
          PANEL_NEW.style.display='none';
          PANEL_LINK.style.display='';
          resetLinkForm();
        }
      }
      if (TAB_NEW) TAB_NEW.addEventListener('click', function(e){ e.preventDefault(); switchTab('new'); });
      if (TAB_LINK) TAB_LINK.addEventListener('click', function(e){ e.preventDefault(); switchTab('link'); });
      var YCLR = document.getElementById(IDP + '_youth_clear');
      if (YCLR) {
        YCLR.addEventListener('click', function(){
          var hid = document.getElementById(IDP + '_youth_id');
          var inp = document.getElementById(IDP + '_youth_search');
          if (hid) hid.value = '';
          if (inp) inp.value = '';
        });
      }

      <?php if ($mode === 'edit'): ?>
      // Edit mode: submit to server via AJAX and redirect back to adult_edit
      var ADULT_ID = <?= (int)$adultId ?>;
      function ajaxSubmit(form){
        clearErr();
        var fd = new FormData(form);
        fetch('/adult_relationships.php', { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function(res){ return res.json().catch(function(){ throw new Error('Invalid server response'); }); })
          .then(function(json){
            if (json && json.ok) {
              window.location = '/adult_edit.php?id=' + ADULT_ID + '&child_added=1';
            } else {
              showErr((json && json.error) ? json.error : 'Operation failed.');
            }
          })
          .catch(function(){ showErr('Network error.'); });
      }
      if (FORM_NEW){ FORM_NEW.addEventListener('submit', function(e){ e.preventDefault(); ajaxSubmit(FORM_NEW); }); }
      if (FORM_LINK){ FORM_LINK.addEventListener('submit', function(e){
        e.preventDefault();
        // Attempt to map datalist selection to hidden input before submit
        var hid = FORM_LINK.querySelector('input[name="youth_id"]');
        var inp = document.getElementById(IDP + '_youth_search');
        var dl = document.getElementById(IDP + '_youth_datalist');
        if (hid && (!hid.value || hid.value === '') && inp && dl) {
          var val = inp.value || '';
          var opts = dl ? dl.querySelectorAll('option') : [];
          for (var i=0;i<opts.length;i++){
            if ((opts[i].value || '') === val && opts[i].dataset && opts[i].dataset.id){
              hid.value = opts[i].dataset.id;
              break;
            }
          }
        }
        if (!hid || !hid.value) { showErr('Please select a valid child.'); return; }
        ajaxSubmit(FORM_LINK);
      }); }
      <?php else: ?>
      // Add mode: emit CustomEvent with staged item; host must aggregate and submit later with pending_children JSON
      function dispatchItem(item) {
        try {
          var ev = new CustomEvent('childModal:add', { detail: { item: item, id_prefix: IDP }});
          window.dispatchEvent(ev);
        } catch (e) {}
      }
      var BTN_NEW = document.getElementById(IDP + '_formNew_submit');
      if (BTN_NEW){
        BTN_NEW.addEventListener('click', function(e){
          e.preventDefault(); clearErr();
          var form = BTN_NEW.closest && BTN_NEW.closest('form') ? BTN_NEW.closest('form') : (FORM_NEW || (MODAL ? MODAL.querySelector('#' + IDP + '_formNew') : null));
          if (!form) { console.warn('Child modal: new form not found'); return; }
          var first = (form.querySelector('input[name="first_name"]') || {}).value || '';
          var last  = (form.querySelector('input[name="last_name"]') || {}).value || '';
          var suffix = (form.querySelector('input[name="suffix"]') || {}).value || '';
          var preferred = (form.querySelector('input[name="preferred_name"]') || {}).value || '';
          var grade = (form.querySelector('select[name="grade"]') || {}).value || '';
          var school = (form.querySelector('input[name="school"]') || {}).value || '';
          var sibling = (form.querySelector('input[name="sibling"]') || {}).checked ? 1 : 0;

          if (!first.trim() || !last.trim() || !grade.trim()) {
            showErr('First name, Last name, and Grade are required.');
            return;
          }
          var label = (last + ', ' + first + ' (' + grade + ')').trim();
          var item = { type: 'new', child: {
            first_name: first.trim(),
            last_name: last.trim(),
            suffix: suffix.trim(),
            preferred_name: preferred.trim(),
            grade_label: grade.trim(),
            school: school.trim(),
            sibling: sibling
          }, label: label };
          dispatchItem(item);
          try {
            var hook = window['childModalAdd_' + IDP];
            if (typeof hook === 'function') { hook({ item: item }); }
          } catch (ex) {}
          hideModal();
        });
      }
      var BTN_LINK = document.getElementById(IDP + '_formLink_submit');
      if (BTN_LINK){
        BTN_LINK.addEventListener('click', function(e){
          e.preventDefault(); clearErr();
          var form = BTN_LINK.closest && BTN_LINK.closest('form') ? BTN_LINK.closest('form') : (FORM_LINK || (MODAL ? MODAL.querySelector('#' + IDP + '_formLink') : null));
          if (!form) { console.warn('Child modal: link form not found'); return; }
          var hid = form.querySelector('input[name="youth_id"]');
          var inp = document.getElementById(IDP + '_youth_search');
          var dl = document.getElementById(IDP + '_youth_datalist');
          if (hid && (!hid.value || hid.value === '') && inp && dl) {
            var val = inp.value || '';
            var opts = dl ? dl.querySelectorAll('option') : [];
            for (var i=0;i<opts.length;i++){
              if ((opts[i].value || '') === val && opts[i].dataset && opts[i].dataset.id){
                hid.value = opts[i].dataset.id;
                break;
              }
            }
          }
          var yid = hid ? (parseInt(hid.value, 10) || 0) : 0;
          if (!yid) { showErr('Please select a valid child.'); return; }
          var label = inp && inp.value ? inp.value : ('Youth #' + yid);
          var item = { type: 'link', youth_id: yid, label: label };
          dispatchItem(item);
          try {
            var hook = window['childModalAdd_' + IDP];
            if (typeof hook === 'function') { hook({ item: item }); }
          } catch (ex) {}
          hideModal();
        });
      }
      <?php endif; ?>
    })();
    </script>
    <?php
  }
}
