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
    if (window.location && window.location.pathname.endsWith('/admin_mailing_list.php')) {
      const form = document.querySelector('form');
      if (!form) return;

      const submitForm = () => {
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

      const q = form.querySelector('input[name="q"]');
      const g = form.querySelector('select[name="g"]');
      const reg = form.querySelector('select[name="registered"]');

      if (q) q.addEventListener('input', debounce(submitForm, 1000));
      if (g) g.addEventListener('change', submitForm);
      if (reg) reg.addEventListener('change', submitForm);
    }
  });
})();
