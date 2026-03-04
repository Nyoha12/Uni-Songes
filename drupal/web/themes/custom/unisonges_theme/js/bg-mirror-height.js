(() => {
  const body = document.body;
  if (!body) return;

  const extractUrl = (v) => {
    v = (v || "").trim();
    const m = v.match(/url\((['"]?)(.*?)\1\)/i);
    return m ? m[2] : "";
  };

  const apply = async () => {
    const cs = getComputedStyle(body);
    const u = extractUrl(cs.getPropertyValue('--bg-once'));
    if (!u) return;

    // load image to get intrinsic ratio
    const img = new Image();
    img.decoding = 'async';
    img.src = u + (u.includes('?') ? '&' : '?') + 'v=' + Date.now();

    await new Promise((res, rej) => {
      img.onload = () => res();
      img.onerror = () => rej();
    }).catch(() => null);

    if (!img.naturalWidth || !img.naturalHeight) return;

    const w = Math.max(document.documentElement.clientWidth, window.innerWidth || 0);
    const h = Math.round(w * (img.naturalHeight / img.naturalWidth));
    body.style.setProperty('--bg-img-h', `${h}px`);
  };

  // run on load + resize (debounced via rAF)
  let raf = 0;
  const schedule = () => {
    if (raf) return;
    raf = requestAnimationFrame(async () => {
      raf = 0;
      await apply();
    });
  };

  schedule();
  window.addEventListener('resize', schedule, { passive: true });
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(schedule).catch(() => {});
  }
})();
