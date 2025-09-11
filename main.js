// Minimal JS placeholder for Cub Scouts app.
// Used for small UX touches and future enhancements.
(function() {
  // Dismissible flash messages
  document.addEventListener('click', function(e) {
    const t = e.target;
    if (t && t.matches('.flash .close')) {
      const p = t.closest('.flash');
      if (p) p.remove();
    }
  });

  // Auto-submit filters on Mailing List page with debounce for search input
  document.addEventListener('DOMContentLoaded', function () {
    const path = (window.location && window.location.pathname) || '';

    const findForm = () => document.querySelector('form');
    const submitFormFor = (form) => {
      if (!form) return;
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.submit();
    };
    const debounce = (fn, delay) => {
      let t;
      return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn.apply(null, args), delay);
      };
    };

    // Admin Mailing List (existing)
    if (path.endsWith('/admin_mailing_list.php')) {
      const form = findForm();
      if (!form) return;

      const submitForm = () => submitFormFor(form);

      const q = form.querySelector('input[name="q"]');
      const g = form.querySelector('select[name="g"]');
      const reg = form.querySelector('select[name="registered"]');

      if (q) q.addEventListener('input', debounce(submitForm, 500));
      if (g) g.addEventListener('change', submitForm);
      if (reg) reg.addEventListener('change', submitForm);
    }

    // Adults roster: live search with 600ms debounce on text; immediate on selects
    if (path.endsWith('/adults.php')) {
      const form = findForm();
      if (form) {
        const submitForm = () => submitFormFor(form);
        const q = form.querySelector('input[name="q"]');
        const g = form.querySelector('select[name="g"]');
        if (q) q.addEventListener('input', debounce(submitForm, 600));
        if (g) g.addEventListener('change', submitForm);
      }
    }

    // Manage Adults: live search with 600ms debounce on text
    if (path.endsWith('/admin_adults.php')) {
      const form = findForm();
      if (form) {
        const submitForm = () => submitFormFor(form);
        const q = form.querySelector('input[name="q"]');
        if (q) q.addEventListener('input', debounce(submitForm, 600));
      }
    }

    // Activity Log: admin user typeahead for filtering
    if (path.endsWith('/admin_activity_log.php')) {
      const form = findForm();
      const input = document.getElementById('userTypeahead');
      const hidden = document.getElementById('userId');
      const results = document.getElementById('userTypeaheadResults');
      const clearBtn = document.getElementById('clearUserBtn');

      if (clearBtn && hidden && input && results) {
        clearBtn.addEventListener('click', function() {
          hidden.value = '';
          input.value = '';
          results.innerHTML = '';
          results.style.display = 'none';
        });
      }

      if (input && hidden && results) {
        let seq = 0;

        const hideResults = () => {
          results.style.display = 'none';
          results.innerHTML = '';
        };

        const render = (items) => {
          if (!Array.isArray(items) || items.length === 0) {
            hideResults();
            return;
          }
          results.innerHTML = '';
          items.forEach(item => {
            const div = document.createElement('div');
            div.className = 'item';
            div.setAttribute('role', 'option');
            div.dataset.id = String(item.id || '');
            div.textContent = String(item.label || '');
            div.addEventListener('click', function() {
              hidden.value = this.dataset.id || '';
              input.value = this.textContent || '';
              hideResults();
            });
            results.appendChild(div);
          });
          results.style.display = 'block';
        };

        const doSearch = (q, mySeq) => {
          fetch('/admin_adult_search.php?q=' + encodeURIComponent(q) + '&limit=20', { headers: { 'Accept': 'application/json' } })
            .then(r => r.ok ? r.json() : Promise.reject())
            .then(json => {
              if (mySeq !== seq) return; // ignore out-of-order responses
              const items = (json && json.items) ? json.items : [];
              render(items);
            })
            .catch(() => {
              if (mySeq === seq) hideResults();
            });
        };

        const onInput = () => {
          const q = input.value.trim();
          // Any typing invalidates any previous selection until a suggestion is chosen again
          hidden.value = '';
          seq++;
          const mySeq = seq;
          if (q.length < 2) {
            hideResults();
            hidden.value = '';
            return;
          }
          doSearch(q, mySeq);
        };

        input.addEventListener('input', debounce(onInput, 350));
        // Guard on submit: if text box is empty, ensure hidden user_id is cleared
        if (form) {
          form.addEventListener('submit', function() {
            if (input.value.trim().length === 0) {
              hidden.value = '';
            }
          });
        }

        // Dismiss suggestions on outside click or Escape
        document.addEventListener('click', function(e) {
          if (!results || !input) return;
          const within = results.contains(e.target) || input.contains(e.target);
          if (!within) hideResults();
        });
        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape') hideResults();
        });
      }
    }
    // Admin Event Invite: "One adult" typeahead for selecting a single adult
    if (path.endsWith('/admin_event_invite.php')) {
      const form = findForm();
      const input = document.getElementById('userTypeahead');
      const hidden = document.getElementById('userId');
      const results = document.getElementById('userTypeaheadResults');
      const clearBtn = document.getElementById('clearUserBtn');

      if (clearBtn && hidden && input && results) {
        clearBtn.addEventListener('click', function() {
          hidden.value = '';
          input.value = '';
          results.innerHTML = '';
          results.style.display = 'none';
        });
      }

      if (input && hidden && results) {
        let seq = 0;

        const hideResults = () => {
          results.style.display = 'none';
          results.innerHTML = '';
        };

        const render = (items) => {
          if (!Array.isArray(items) || items.length === 0) {
            hideResults();
            return;
          }
          results.innerHTML = '';
          items.forEach(item => {
            const div = document.createElement('div');
            div.className = 'item';
            div.setAttribute('role', 'option');
            div.dataset.id = String(item.id || '');
            div.textContent = String(item.label || '');
            div.addEventListener('click', function() {
              hidden.value = this.dataset.id || '';
              input.value = this.textContent || '';
              hideResults();
            });
            results.appendChild(div);
          });
          results.style.display = 'block';
        };

        const doSearch = (q, mySeq) => {
          fetch('/admin_adult_search.php?q=' + encodeURIComponent(q) + '&limit=20', { headers: { 'Accept': 'application/json' } })
            .then(r => r.ok ? r.json() : Promise.reject())
            .then(json => {
              if (mySeq !== seq) return; // ignore out-of-order responses
              const items = (json && json.items) ? json.items : [];
              render(items);
            })
            .catch(() => {
              if (mySeq === seq) hideResults();
            });
        };

        const onInput = () => {
          const q = input.value.trim();
          // Any typing invalidates any previous selection until a suggestion is chosen again
          hidden.value = '';
          seq++;
          const mySeq = seq;
          if (q.length < 2) {
            hideResults();
            hidden.value = '';
            return;
          }
          doSearch(q, mySeq);
        };

        input.addEventListener('input', debounce(onInput, 350));
        // Guard on submit: if text box is empty, ensure hidden user_id is cleared
        if (form) {
          form.addEventListener('submit', function() {
            if (input.value.trim().length === 0) {
              hidden.value = '';
            }
          });
        }

        // Dismiss suggestions on outside click or Escape
        document.addEventListener('click', function(e) {
          if (!results || !input) return;
          const within = results.contains(e.target) || input.contains(e.target);
          if (!within) hideResults();
        });
        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape') hideResults();
        });
      }
    }
  });
})();
