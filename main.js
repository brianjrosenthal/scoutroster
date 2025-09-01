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
  });
})();
