// Small helpers for the admin page (inline scripts are blocked by its security policy).
document.addEventListener('change', e => { if (e.target.matches('[data-autosubmit]')) e.target.form.submit(); });
document.addEventListener('click', e => {
  const c = e.target.closest('[data-confirm]');
  if (c && !confirm(c.dataset.confirm)) { e.preventDefault(); return; }
  const m = e.target.closest('[data-move]');
  if (m) { const li = m.closest('li'), d = +m.dataset.move; if (d < 0 && li.previousElementSibling) li.parentNode.insertBefore(li, li.previousElementSibling); if (d > 0 && li.nextElementSibling) li.parentNode.insertBefore(li.nextElementSibling, li); }
});
// warn before leaving a form with unsaved edits
let dirty = false;
document.addEventListener('input', e => { if (e.target.closest('form[method=post]') && !e.target.matches('[data-autosubmit]')) dirty = true; });
document.addEventListener('submit', () => { dirty = false; });
window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
