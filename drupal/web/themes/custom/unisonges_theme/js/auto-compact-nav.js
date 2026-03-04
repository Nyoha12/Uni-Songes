(() => {
  const body = document.body;
  const inner = document.querySelector('.site-header__inner');
  const center = document.querySelector('.site-header__center');
  const right = document.querySelector('.site-header__right');

  if (!body || !inner || !center || !right) return;

  const navList =
    center.querySelector('.menu--main ul') ||
    center.querySelector('nav ul') ||
    center.querySelector('ul');

  if (!navList) return;

  const COMPACT_MARGIN = 2;
  const EXPAND_MARGIN  = 96;

  let isCompact = body.classList.contains('compact-nav');
  let required = 0;
  let raf = 0;

  const computeRequiredDesktop = () => {
    const r = right.getBoundingClientRect().width;
    const navNeeded = navList.scrollWidth;
    required = r + navNeeded + 140;
  };

  const navOverflows = () => navList.scrollWidth > navList.clientWidth + COMPACT_MARGIN;

  const decide = () => {
    raf = 0;
    const cw = inner.clientWidth;

    if (!isCompact) {
      computeRequiredDesktop();
      if (navOverflows()) {
        isCompact = true;
        body.classList.add('compact-nav');
      }
    } else {
      if (cw >= required + EXPAND_MARGIN) {
        isCompact = false;
        body.classList.remove('compact-nav');
        computeRequiredDesktop();
      }
    }
  };

  const schedule = () => {
    if (raf) return;
    raf = requestAnimationFrame(decide);
  };

  schedule();
  window.addEventListener('resize', schedule, { passive: true });

  const ro = new ResizeObserver(schedule);
  ro.observe(inner);
  ro.observe(center);
  ro.observe(right);
  ro.observe(navList);

  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(schedule).catch(() => {});
  }
})();
