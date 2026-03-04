(() => {
  const btn = document.querySelector('.nav-toggle');
  const drawer = document.getElementById('mobile-drawer');
  const backdrop = document.querySelector('.mobile-backdrop');

  if (!btn || !drawer || !backdrop) return;

  const closeBtn = drawer.querySelector('.mobile-drawer__close');

  const open = () => {
    drawer.hidden = false;
    backdrop.hidden = false;
    requestAnimationFrame(() => {
      drawer.classList.add('is-open');
      backdrop.classList.add('is-open');
      btn.setAttribute('aria-expanded', 'true');
    });
  };

  const close = () => {
    drawer.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    btn.setAttribute('aria-expanded', 'false');
    setTimeout(() => {
      drawer.hidden = true;
      backdrop.hidden = true;
    }, 180);
  };

  // ensure default state (prevents “auto open”)
  const hardClose = () => {
    drawer.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    btn.setAttribute('aria-expanded', 'false');
    drawer.hidden = true;
    backdrop.hidden = true;
  };
  hardClose();

  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (drawer.hidden) open(); else close();
  });

  backdrop.addEventListener('click', (e) => { e.preventDefault(); close(); });
  if (closeBtn) closeBtn.addEventListener('click', (e) => { e.preventDefault(); close(); });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !drawer.hidden) close();
  });

  // if layout switches back to desktop, keep it closed
  const mo = new MutationObserver(() => {
    if (!document.body.classList.contains('compact-nav')) hardClose();
  });
  mo.observe(document.body, { attributes: true, attributeFilter: ['class'] });
})();
