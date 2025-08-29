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
})();
