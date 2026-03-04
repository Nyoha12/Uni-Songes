(() => {
  const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const saveData = navigator.connection && navigator.connection.saveData;
  if (reduce || saveData) return;

  const body = document.body;
  const root = document.documentElement;
  const scrollLayer = document.getElementById('unisonges-bgfx-scroll');
  const layer = document.getElementById('unisonges-bgfx-layer');
  const frame = document.getElementById('unisonges-scrollframe');
  const header = document.querySelector('.site-header') || document.querySelector('.site-header__inner');

  if (!body || !scrollLayer || !layer || !frame) return;

  const DEFAULT_URL = "/themes/custom/unisonges_theme/images/bgsrc/fontdefault.jpg";
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const extractUrl = (v) => {
    v = (v || "").trim();
    const m = v.match(/url\((['"]?)(.*?)\1\)/i);
    return m ? m[2] : "";
  };

  const setHeaderH = () => {
    const h = header ? Math.round(header.getBoundingClientRect().height) : 72;
    body.style.setProperty('--header-h', `${h}px`);
  };

  const dimCache = new Map(); // url -> {w,h}
  const loadDims = (u) => new Promise((res) => {
    if (!u) return res(null);
    if (dimCache.has(u)) return res(dimCache.get(u));
    const img = new Image();
    img.decoding = 'async';
    img.onload = () => {
      const d = { w: img.naturalWidth || 0, h: img.naturalHeight || 0 };
      if (d.w && d.h) dimCache.set(u, d);
      res(d.w && d.h ? d : null);
    };
    img.onerror = () => res(null);
    img.src = u + (u.includes('?') ? '&' : '?') + 'v=' + Date.now();
  });

  let state = { maxTravel: 0 };

  let rafScroll = 0;
  const onScroll = () => {
    if (rafScroll) return;
    rafScroll = requestAnimationFrame(() => {
      rafScroll = 0;
      const maxScroll = Math.max(0, frame.scrollHeight - frame.clientHeight);
      const progress = maxScroll > 0 ? clamp(frame.scrollTop / maxScroll, 0, 1) : 0;
      const y = -Math.round(progress * state.maxTravel);
      scrollLayer.style.transform = `translate3d(0, ${y}px, 0)`;
    });
  };

  const compute = async (url, vw, vh, margin, maxS) => {
    const d = await loadDims(url);
    if (!d) return null;

    const imgH = Math.round(vw * (d.h / d.w)); // height when background-width = 100%
    const base = 1.00;

    // scale needed so scaled height >= viewport height (plus margin)
    const needed = ((vh * (1 + margin)) / Math.max(1, imgH));
    const scale = clamp(Math.max(base, needed), base, maxS);

    const scaledH = Math.max(1, Math.round(imgH * scale));
    const maxTravel = Math.max(0, scaledH - vh);

    return { imgH, scale, scaledH, maxTravel };
  };

  // Debounced resize -> recalc
  let rafRecalc = 0;
  const scheduleRecalc = () => {
    if (rafRecalc) return;
    rafRecalc = requestAnimationFrame(() => {
      rafRecalc = 0;
      recalc();
    });
  };

  const recalc = async () => {
    setHeaderH();

    const vw = Math.max(root.clientWidth, window.innerWidth || 0);
    const vh = Math.max(root.clientHeight, window.innerHeight || 0);

    const isAccueil = body.classList.contains('section-accueil');

    const heavyZoom =
      body.classList.contains('section-asso') || body.classList.contains('section-association') ||
      body.classList.contains('section-djam') || body.classList.contains('section-djams') ||
      body.classList.contains('section-orchestre') || body.classList.contains('section-orchestre-des-reveurs');

    // margins are relative: 0.12 = +12% of viewport height
    const margin = heavyZoom ? 0.22 : 0.10;
    const maxS   = heavyZoom ? 3.20 : 1.75;

    // Start from CSS var (current page image)
    body.style.removeProperty('--bg-once');
    const cssOnce = extractUrl(getComputedStyle(body).getPropertyValue('--bg-once'));
    if (!cssOnce) return;

    // Accueil rule: keep accueil.jpg unless it would require "too much zoom".
    // If required scale > 1.45 => switch to default instead.
    let chosen = cssOnce;
    let r = await compute(chosen, vw, vh, 0.10, 1.75);
    if (!r) return;

    if (isAccueil) {
      const switchThreshold = 1.45;
      if (r.scale > switchThreshold) {
        chosen = DEFAULT_URL;
        body.style.setProperty('--bg-once', `url("${chosen}")`, 'important');
        r = await compute(chosen, vw, vh, 0.10, 1.75);
        if (!r) return;
      }
    } else {
      // For other pages we always enforce "never see below image"
      r = await compute(chosen, vw, vh, margin, maxS);
      if (!r) return;
    }

    // publish vars: background-size via % width
    const bgW = (r.scale * 100).toFixed(2) + "%";
    body.style.setProperty('--bg-w', bgW);
    body.style.setProperty('--bg-img-h', `${r.imgH}px`);
    body.style.setProperty('--bg-scaled-h', `${r.scaledH}px`);

    // lock actual element heights so we never translate beyond end
    scrollLayer.style.height = `${r.scaledH}px`;
    layer.style.height = `${r.scaledH}px`;

    state = { maxTravel: r.maxTravel };
    onScroll();
  };

  frame.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', scheduleRecalc, { passive: true });
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(scheduleRecalc).catch(() => {});
  }
  recalc();
})();
