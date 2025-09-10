<?php
// Reusable parent modal (Add New Adult / Link Existing Adult) for youth_edit.php (edit flow; AJAX).
// Usage in youth_edit.php:
//   require_once __DIR__ . '/partials_parent_modal.php';
//   render_parent_modal(['mode' => 'edit', 'youth_id' => (int)$id, 'id_prefix' => 'yp']);
// Behavior (mode=edit):
//   - "Add Parent" tab submits via AJAX to /adult_relationships.php with action=create_adult_and_link.
//   - "Link Parent" tab submits via AJAX to /adult_relationships.php with action=link (adult_id required).
//   - On success: reload /youth_edit.php?id={youth_id}&parent_added=1. On error: show error within modal.
// Host page should add a button with: data-open-parent-modal="{id_prefix}" to open the modal.

if (!function_exists('render_parent_modal')) {
  function render_parent_modal(array $opts = []): void {
    $mode = $opts['mode'] ?? 'edit';
    $youthId = isset($opts['youth_id']) ? (int)$opts['youth_id'] : 0;
    $idPrefix = $opts['id_prefix'] ?? 'yp';

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

    ?>
    <div id="<?= h($modalId) ?>" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
      <div class="modal-content">
        <button class="close" type="button" id="<?= h($closeBtnId) ?>" aria-label="Close">&times;</button>
        <h3>Add Parent</h3>
        <div style="display:flex;gap:8px;margin-bottom:8px;">
          <button class="button" id="<?= h($tabNewId) ?>" aria-controls="<?= h($panelNewId) ?>">Add New Parent</button>
          <button class="button" id="<?= h($tabLinkId) ?>" aria-controls="<?= h($panelLinkId) ?>">Link Existing Parent</button>
        </div>
        <div id="<?= h($errBoxId) ?>" class="error small" style="display:none;"></div>

        <div id="<?= h($panelNewId) ?>" class="stack">
          <form id="<?= h($formNewId) ?>" class="stack" <?= $mode === 'edit' ? 'action="/adult_relationships.php" method="post"' : 'onsubmit="return false;"' ?>>
            <?php if ($mode === 'edit'): ?>
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="ajax" value="1">
              <input type="hidden" name="action" value="create_adult_and_link">
              <input type="hidden" name="youth_id" value="<?= (int)$youthId ?>">
            <?php endif; ?>
            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
              <label>First name
                <input type="text" name="first_name" required>
              </label>
              <label>Last name
                <input type="text" name="last_name" required>
              </label>
              <label>Email (optional)
                <input type="email" name="email">
              </label>
              <label>Preferred name
                <input type="text" name="preferred_name">
              </label>
              <label>Cell Phone
                <input type="text" name="phone_cell">
              </label>
              <label>Home Phone
                <input type="text" name="phone_home">
              </label>
            </div>
            <div class="actions">
              <button class="button primary" type="<?= $mode === 'edit' ? 'submit' : 'button' ?>" id="<?= h($formNewId) ?>_submit">Add Parent</button>
            </div>
          </form>
        </div>

        <div id="<?= h($panelLinkId) ?>" class="stack" style="display:none;">
          <form id="<?= h($formLinkId) ?>" class="stack" <?= $mode === 'edit' ? 'action="/adult_relationships.php" method="post"' : 'onsubmit="return false;"' ?>>
            <?php if ($mode === 'edit'): ?>
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="ajax" value="1">
              <input type="hidden" name="action" value="link">
              <input type="hidden" name="youth_id" value="<?= (int)$youthId ?>">
            <?php endif; ?>
            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
              <div class="stack">
                <label for="<?= h($idPrefix) ?>_adult_search">Parent</label>
                <input type="hidden" name="adult_id" id="<?= h($idPrefix) ?>_adult_id" value="">
                <input
                  type="text"
                  id="<?= h($idPrefix) ?>_adult_search"
                  placeholder="Type to search adults by name or email"
                  autocomplete="off"
                  role="combobox"
                  aria-expanded="false"
                  aria-owns="<?= h($idPrefix) ?>_adult_results_list"
                  aria-autocomplete="list"
                  value="">
                <div id="<?= h($idPrefix) ?>_adult_results" class="typeahead-results" role="listbox" style="position:relative;">
                  <div id="<?= h($idPrefix) ?>_adult_results_list" class="list" style="position:absolute; z-index:1000; background:#fff; border:1px solid #ccc; max-height:200px; overflow:auto; width:100%; display:none;"></div>
                </div>
                <button type="button" class="button" id="<?= h($idPrefix) ?>_adult_clear" style="margin-top:4px;">Clear</button>
              </div>
            </div>
            <div class="actions">
              <button class="button primary" type="<?= $mode === 'edit' ? 'submit' : 'button' ?>" id="<?= h($formLinkId) ?>_submit">Link Parent</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
    (function(){
      var MODE = <?= json_encode($mode) ?>;
      var IDP = <?= json_encode($idPrefix) ?>;
      var YOUTH_ID = <?= (int)$youthId ?>;
      var MODAL = document.getElementById(IDP + '_modal');
      var BTN_CLOSE = document.getElementById(IDP + '_close');
      var TAB_NEW = document.getElementById(IDP + '_tabNew');
      var TAB_LINK = document.getElementById(IDP + '_tabLink');
      var PANEL_NEW = document.getElementById(IDP + '_panelNew');
      var PANEL_LINK = document.getElementById(IDP + '_panelLink');
      var ERR = document.getElementById(IDP + '_err');

      var FORM_NEW = MODAL ? MODAL.querySelector('#' + IDP + '_formNew') : null;
      var FORM_LINK = MODAL ? MODAL.querySelector('#' + IDP + '_formLink') : null;

      function showErr(msg) { if (ERR){ ERR.style.display=''; ERR.textContent = msg || 'Operation failed.'; } }
      function clearErr(){ if (ERR){ ERR.style.display='none'; ERR.textContent=''; } }

      function showModal(){
        if (!MODAL) return;
        MODAL.classList.remove('hidden');
        MODAL.setAttribute('aria-hidden','false');
        // re-resolve forms on open
        FORM_NEW = MODAL.querySelector('#' + IDP + '_formNew');
        FORM_LINK = MODAL.querySelector('#' + IDP + '_formLink');
        clearErr();
        switchTab('new');
        resetNewForm();
        resetLinkForm();
      }
      function hideModal(){ if (MODAL){ MODAL.classList.add('hidden'); MODAL.setAttribute('aria-hidden','true'); } }

      window['openModal_' + IDP] = showModal;
      (function(){
        var launchers = document.querySelectorAll('[data-open-parent-modal="' + IDP + '"]');
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
        } else {
          PANEL_NEW.style.display='none';
          PANEL_LINK.style.display='';
        }
      }
      if (TAB_NEW) TAB_NEW.addEventListener('click', function(e){ e.preventDefault(); switchTab('new'); });
      if (TAB_LINK) TAB_LINK.addEventListener('click', function(e){ e.preventDefault(); switchTab('link'); });

      function resetNewForm() {
        var form = FORM_NEW || (MODAL ? MODAL.querySelector('#' + IDP + '_formNew') : null);
        if (!form) return;
        ['first_name','last_name','email','preferred_name','phone_cell','phone_home'].forEach(function(n){
          var inp = form.querySelector('[name="' + n + '"]');
          if (inp) inp.value = '';
        });
      }
      function resetLinkForm() {
        var form = FORM_LINK || (MODAL ? MODAL.querySelector('#' + IDP + '_formLink') : null);
        if (!form) return;
        var hid = form.querySelector('input[name="adult_id"]');
        var inp = document.getElementById(IDP + '_adult_search');
        if (hid) hid.value = '';
        if (inp) inp.value = '';
        var list = document.getElementById(IDP + '_adult_results_list');
        if (list) list.style.display = 'none';
      }

      <?php if ($mode === 'edit'): ?>
      function ajaxSubmit(form){
        clearErr();
        var fd = new FormData(form);
        fetch('/adult_relationships.php', { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function(res){ return res.json().catch(function(){ throw new Error('Invalid server response'); }); })
          .then(function(json){
            if (json && json.ok) {
              window.location = '/youth_edit.php?id=' + YOUTH_ID + '&parent_added=1';
            } else {
              showErr((json && json.error) ? json.error : 'Operation failed.');
            }
          })
          .catch(function(){ showErr('Network error.'); });
      }
      if (FORM_NEW){ FORM_NEW.addEventListener('submit', function(e){
        e.preventDefault();
        // simple client validation
        var fn = (FORM_NEW.querySelector('[name="first_name"]') || {}).value || '';
        var ln = (FORM_NEW.querySelector('[name="last_name"]') || {}).value || '';
        if (!fn.trim() || !ln.trim()) { showErr('First name and Last name are required.'); return; }
        ajaxSubmit(FORM_NEW);
      }); }

      if (FORM_LINK){ FORM_LINK.addEventListener('submit', function(e){
        e.preventDefault();
        var hid = FORM_LINK.querySelector('input[name="adult_id"]');
        if (!hid || !hid.value) { showErr('Please select a parent to link.'); return; }
        ajaxSubmit(FORM_LINK);
      }); }
      <?php endif; ?>

      // Typeahead for Link tab
      (function(){
        var input = document.getElementById(IDP + '_adult_search');
        var hidden = document.getElementById(IDP + '_adult_id');
        var list = document.getElementById(IDP + '_adult_results_list');
        var clearBtn = document.getElementById(IDP + '_adult_clear');
        var open = false, items = [], highlight = -1;

        function debounce(fn, wait) {
          var t=null; return function(){ var ctx=this, args=arguments; clearTimeout(t); t=setTimeout(function(){ fn.apply(ctx,args); }, wait); };
        }

        function close(){
          if (!list) return;
          list.style.display = 'none';
          if (input) input.setAttribute('aria-expanded', 'false');
          open = false; highlight = -1;
          if (input) input.removeAttribute('aria-activedescendant');
        }
        function openList(){
          if (!list) return;
          if (!items.length) { close(); return; }
          list.style.display = '';
          if (input) input.setAttribute('aria-expanded','true');
          open = true;
        }
        function render(){
          if (!list) return;
          list.innerHTML = '';
          var frag = document.createDocumentFragment();
          items.forEach(function(it, idx){
            var div = document.createElement('div');
            div.setAttribute('role','option');
            div.setAttribute('id', IDP + '_adult_results_list_opt_' + idx);
            div.setAttribute('tabindex','-1');
            div.style.padding = '6px 8px';
            div.style.cursor = 'pointer';
            div.textContent = it.label;
            if (idx === highlight) { div.style.background = '#eef'; }
            div.addEventListener('mousedown', function(e){ e.preventDefault(); });
            div.addEventListener('click', function(){ select(idx); });
            frag.appendChild(div);
          });
          list.appendChild(frag);
          openList();
        }
        function select(idx){
          var it = items[idx];
          if (!it) return;
          if (hidden) hidden.value = it.id;
          if (input) input.value = it.label;
          close();
        }

        var doSearch = debounce(function(){
          var q = (input && input.value ? input.value.trim() : '');
          if (!q) { items = []; render(); return; }
          fetch('/admin_adult_search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(json){
              if (!json || !json.ok) { items=[]; render(); return; }
              items = json.items || [];
              highlight = items.length ? 0 : -1;
              render();
              if (items.length && input) {
                input.setAttribute('aria-activedescendant', IDP + '_adult_results_list_opt_0');
              } else if (input) {
                input.removeAttribute('aria-activedescendant');
              }
            })
            .catch(function(){ items=[]; render(); });
        }, 200);

        if (input) {
          input.addEventListener('input', function(){
            if (hidden) hidden.value = '';
            doSearch();
          });
          input.addEventListener('keydown', function(e){
            if (!open && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { openList(); }
            if (e.key === 'ArrowDown') {
              e.preventDefault();
              if (!items.length) return;
              highlight = (highlight + 1) % items.length;
              if (input) input.setAttribute('aria-activedescendant', IDP + '_adult_results_list_opt_' + highlight);
              render();
            } else if (e.key === 'ArrowUp') {
              e.preventDefault();
              if (!items.length) return;
              highlight = (highlight - 1 + items.length) % items.length;
              if (input) input.setAttribute('aria-activedescendant', IDP + '_adult_results_list_opt_' + highlight);
              render();
            } else if (e.key === 'Enter') {
              if (open && highlight >= 0) { e.preventDefault(); select(highlight); }
            } else if (e.key === 'Escape') {
              close();
            }
          });
          input.addEventListener('blur', function(){ setTimeout(close, 120); });
        }
        if (clearBtn) {
          clearBtn.addEventListener('click', function(){
            if (hidden) hidden.value = '';
            if (input) input.value = '';
            items = []; render();
          });
        }
      })();
    })();
    </script>
    <?php
  }
}
