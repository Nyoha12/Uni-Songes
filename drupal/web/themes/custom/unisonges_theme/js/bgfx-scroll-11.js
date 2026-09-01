(() => {
  const CONTROLLER_KEY = '__unisongesBgfxScroll11';
  const body = document.body;
  const root = document.documentElement;
  const bgfx = document.getElementById('unisonges-bgfx');
  const scrollLayer = document.getElementById('unisonges-bgfx-scroll');
  const layer = document.getElementById('unisonges-bgfx-layer');
  const frame = document.getElementById('unisonges-scrollframe');
  const header = document.querySelector('.site-header') || document.querySelector('.site-header__inner');
  const previousController = window[CONTROLLER_KEY];

  if (
    previousController &&
    previousController.body === body &&
    previousController.bgfx === bgfx &&
    previousController.scrollLayer === scrollLayer &&
    previousController.layer === layer &&
    previousController.frame === frame
  ) {
    previousController.refresh();
    return;
  }

  if (previousController && typeof previousController.destroy === 'function') {
    previousController.destroy();
  }

  if (!body || !bgfx || !scrollLayer || !layer || !frame) return;

  const DEFAULT_URL = '/themes/custom/unisonges_theme/images/bgsrc/fontdefault.jpg';
  const AUTONOMOUS_PERIOD_MS = 140000;
  const AUTONOMOUS_MAX_PX = 14;
  const EDGE_GUARD_PX = 2;
  const POSITION_EASE_MS = 650;
  const MAX_BLEND_DELTA_MS = 100;
  const LEGACY_MOTION_STYLE_ID = 'unisonges-bgfx-js-motion-owner';
  const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
  const extractUrl = (value) => {
    value = (value || '').trim();
    const match = value.match(/url\((['"]?)(.*?)\1\)/i);
    return match ? match[2] : '';
  };

  const oldMotionStyle = document.getElementById(LEGACY_MOTION_STYLE_ID);
  if (oldMotionStyle) oldMotionStyle.remove();

  // The active CSS cascade animates both nodes. The wrapper below must be the
  // sole motion owner so reduced motion and Save-Data can be genuinely static.
  const motionStyle = document.createElement('style');
  motionStyle.id = LEGACY_MOTION_STYLE_ID;
  motionStyle.textContent = `
    #unisonges-bgfx-layer,
    #unisonges-bgfx-layer::before {
      animation: none !important;
    }
  `;
  (document.head || root).appendChild(motionStyle);

  const reduceQuery = typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-reduced-motion: reduce)')
    : null;
  const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
  const dimCache = new Map(); // url -> {w,h}
  const dimLoads = new Map(); // url -> in-flight Promise
  const cleanups = [];

  let destroyed = false;
  let pageSuspended = false;
  let motionAllowed = !(reduceQuery && reduceQuery.matches) && !(connection && connection.saveData);
  let motionRaf = 0;
  let recalcRaf = 0;
  let recalcVersion = 0;
  let lastFrameTime = null;
  let writtenY = null;
  let ownedImgH = '';
  let legacyProbeMasked = false;
  let controller = null;

  const state = {
    ready: false,
    elapsedMs: 0,
    renderedY: 0,
    minY: 0,
    maxY: 0,
    anchorY: 0,
    direction: -1,
    autonomousRange: 0,
  };

  const listen = (target, type, handler, options) => {
    target.addEventListener(type, handler, options);
    cleanups.push(() => target.removeEventListener(type, handler, options));
  };

  const setHeaderH = () => {
    const height = header ? Math.round(header.getBoundingClientRect().height) : 72;
    body.style.setProperty('--header-h', `${height}px`);
  };

  const loadDims = (url) => {
    if (!url) return Promise.resolve(null);
    if (dimCache.has(url)) return Promise.resolve(dimCache.get(url));
    if (dimLoads.has(url)) return dimLoads.get(url);

    const loading = new Promise((resolve) => {
      const image = new Image();
      image.decoding = 'async';
      image.onload = () => {
        const dimensions = {
          w: image.naturalWidth || 0,
          h: image.naturalHeight || 0,
        };
        if (dimensions.w && dimensions.h) {
          dimCache.set(url, dimensions);
          resolve(dimensions);
        } else {
          resolve(null);
        }
      };
      image.onerror = () => resolve(null);
      // Reuse the same URL as the CSS background so the browser cache can
      // satisfy this dimension probe, including when Save-Data is enabled.
      image.src = url;
    }).finally(() => {
      dimLoads.delete(url);
    });

    dimLoads.set(url, loading);
    return loading;
  };

  const compute = async (url, viewportWidth, viewportHeight, margin, maxScale) => {
    const dimensions = await loadDims(url);
    if (!dimensions) return null;

    const imgH = Math.round(viewportWidth * (dimensions.h / dimensions.w));
    const base = 1.00;
    const needed = (viewportHeight * (1 + margin)) / Math.max(1, imgH);
    const scale = clamp(Math.max(base, needed), base, maxScale);
    const scaledH = Math.max(1, Math.round(imgH * scale));

    return {
      imgH,
      scale,
      scaledH,
      aspectRatio: dimensions.h / dimensions.w,
    };
  };

  const writeTransform = (value) => {
    const clamped = clamp(value, state.minY, state.maxY);
    const rounded = Math.abs(clamped) < 0.0005 ? 0 : Math.round(clamped * 1000) / 1000;
    const safe = clamp(rounded, state.minY, state.maxY);
    if (safe === writtenY) return;
    writtenY = safe;
    scrollLayer.style.transform = `translate3d(0, ${safe}px, 0)`;
  };

  const configureMotionRange = () => {
    const clipRect = bgfx.getBoundingClientRect();
    const scrollRect = scrollLayer.getBoundingClientRect();
    const layerRect = layer.getBoundingClientRect();
    const baseTop = layerRect.top - scrollRect.top;
    const baseBottom = baseTop + layerRect.height;
    const safeMinY = clipRect.height + EDGE_GUARD_PX - baseBottom;
    const safeMaxY = -EDGE_GUARD_PX - baseTop;

    // If an extreme aspect ratio cannot cover both edges, keep the lower edge
    // aligned and static. Normal layouts always have a non-empty safe interval.
    if (safeMinY > safeMaxY) {
      state.minY = safeMinY;
      state.maxY = safeMinY;
      state.anchorY = safeMinY;
      state.direction = 1;
      state.autonomousRange = 0;
      return;
    }

    state.minY = safeMinY;
    state.maxY = safeMaxY;
    state.anchorY = clamp(0, safeMinY, safeMaxY);

    const upwardCapacity = state.anchorY - safeMinY;
    const downwardCapacity = safeMaxY - state.anchorY;

    if (upwardCapacity >= AUTONOMOUS_MAX_PX) {
      state.direction = -1;
    } else if (downwardCapacity >= AUTONOMOUS_MAX_PX) {
      state.direction = 1;
    } else {
      state.direction = upwardCapacity >= downwardCapacity ? -1 : 1;
    }

    const directionalCapacity = state.direction < 0 ? upwardCapacity : downwardCapacity;
    state.autonomousRange = Math.min(AUTONOMOUS_MAX_PX, directionalCapacity * 0.45);
  };

  const canAnimate = () => (
    !destroyed &&
    !pageSuspended &&
    !document.hidden &&
    motionAllowed &&
    state.ready &&
    state.autonomousRange > 0.01
  );

  const stopMotionLoop = () => {
    if (motionRaf) cancelAnimationFrame(motionRaf);
    motionRaf = 0;
    lastFrameTime = null;
  };

  const animate = (timestamp) => {
    motionRaf = 0;
    if (!canAnimate()) return;

    if (lastFrameTime === null) lastFrameTime = timestamp;
    const delta = Math.max(0, timestamp - lastFrameTime);
    lastFrameTime = timestamp;
    state.elapsedMs = (state.elapsedMs + delta) % AUTONOMOUS_PERIOD_MS;

    const phase = (state.elapsedMs / AUTONOMOUS_PERIOD_MS) * Math.PI * 2;
    const autonomousProgress = 0.5 - (0.5 * Math.cos(phase));
    const desiredY = clamp(
      state.anchorY + state.direction * state.autonomousRange * autonomousProgress,
      state.minY,
      state.maxY,
    );

    if (delta > 0) {
      const blendDelta = Math.min(delta, MAX_BLEND_DELTA_MS);
      const blend = 1 - Math.exp(-blendDelta / POSITION_EASE_MS);
      state.renderedY += (desiredY - state.renderedY) * blend;
    }
    state.renderedY = clamp(state.renderedY, state.minY, state.maxY);
    writeTransform(state.renderedY);
    motionRaf = requestAnimationFrame(animate);
  };

  const syncMotionLoop = () => {
    if (!canAnimate()) {
      stopMotionLoop();
      return;
    }
    if (!motionRaf) motionRaf = requestAnimationFrame(animate);
  };

  const recalc = async () => {
    const version = ++recalcVersion;
    setHeaderH();

    const viewportWidth = Math.max(root.clientWidth, window.innerWidth || 0);
    const viewportHeight = Math.max(root.clientHeight, window.innerHeight || 0);
    const isAccueil = body.classList.contains('section-accueil');
    const heavyZoom =
      body.classList.contains('section-asso') || body.classList.contains('section-association') ||
      body.classList.contains('section-djam') || body.classList.contains('section-djams') ||
      body.classList.contains('section-orchestre') || body.classList.contains('section-orchestre-des-reveurs');
    const margin = heavyZoom ? 0.22 : 0.10;
    const maxScale = heavyZoom ? 3.20 : 1.75;

    // Re-read the route-owned value so Accueil can reconsider its fallback.
    body.style.removeProperty('--bg-once');
    legacyProbeMasked = false;
    const cssOnce = extractUrl(getComputedStyle(body).getPropertyValue('--bg-once'));
    if (!cssOnce || destroyed || version !== recalcVersion) return;

    let chosen = cssOnce;
    let result = await compute(chosen, viewportWidth, viewportHeight, 0.10, 1.75);
    if (!result || destroyed || version !== recalcVersion) return;

    if (isAccueil) {
      const switchThreshold = 1.45;
      if (result.scale > switchThreshold) {
        chosen = DEFAULT_URL;
        body.style.setProperty('--bg-once', `url("${chosen}")`, 'important');
        result = await compute(chosen, viewportWidth, viewportHeight, 0.10, 1.75);
        if (!result || destroyed || version !== recalcVersion) return;
      }
    } else {
      result = await compute(chosen, viewportWidth, viewportHeight, margin, maxScale);
      if (!result || destroyed || version !== recalcVersion) return;
    }

    const bgW = `${(result.scale * 100).toFixed(2)}%`;
    ownedImgH = `${result.imgH}px`;
    body.style.setProperty('--bg-w', bgW);
    body.style.setProperty('--bg-img-h', ownedImgH);
    body.style.setProperty('--bg-scaled-h', `${result.scaledH}px`);
    scrollLayer.style.height = `${result.scaledH}px`;
    layer.style.height = `${result.scaledH}px`;

    // The final CSS paints into an extra-wide ::before and then clips it to
    // the layer. Expand that clip only as far as the painted image and viewport
    // require so capped landscape images cover tall screens without a blank.
    const layerRect = layer.getBoundingClientRect();
    const clipRect = bgfx.getBoundingClientRect();
    const scrollRect = scrollLayer.getBoundingClientRect();
    const pseudoWidthValue = getComputedStyle(layer, '::before').width || '';
    const parsedPseudoWidth = Number.parseFloat(pseudoWidthValue);
    let pseudoWidth = 0;
    if (Number.isFinite(parsedPseudoWidth)) {
      pseudoWidth = pseudoWidthValue.trim().endsWith('%')
        ? layerRect.width * parsedPseudoWidth / 100
        : parsedPseudoWidth;
    }
    const publishedScale = Number.parseFloat(bgW) / 100;
    const paintedHeight = pseudoWidth > 0
      ? Math.floor(pseudoWidth * publishedScale * result.aspectRatio)
      : result.scaledH;
    const baseTop = layerRect.top - scrollRect.top;
    const requiredCoverHeight = Math.ceil(clipRect.height + EDGE_GUARD_PX - baseTop);
    const visibleHeight = Math.max(
      result.scaledH,
      Math.min(paintedHeight, requiredCoverHeight),
    );

    body.style.setProperty('--bg-scaled-h', `${visibleHeight}px`);
    scrollLayer.style.height = `${visibleHeight}px`;
    layer.style.height = `${visibleHeight}px`;

    configureMotionRange();
    state.renderedY = clamp(state.renderedY, state.minY, state.maxY);
    state.ready = true;
    writeTransform(state.renderedY);
    syncMotionLoop();
  };

  const scheduleRecalc = () => {
    if (destroyed || recalcRaf) return;
    recalcRaf = requestAnimationFrame(() => {
      recalcRaf = 0;
      recalc();
    });
  };

  const maskLegacyDimensionProbe = () => {
    if (destroyed || legacyProbeMasked) return;
    legacyProbeMasked = true;
    body.style.setProperty('--bg-once', 'none', 'important');
  };

  const refreshMotionPolicy = () => {
    motionAllowed = !(reduceQuery && reduceQuery.matches) && !(connection && connection.saveData);
    if (!motionAllowed) {
      stopMotionLoop();
      return;
    }
    syncMotionLoop();
  };

  const onVisibilityChange = () => {
    if (document.hidden) {
      stopMotionLoop();
      return;
    }
    scheduleRecalc();
    syncMotionLoop();
  };

  const onPageHide = () => {
    pageSuspended = true;
    stopMotionLoop();
  };

  const onPageShow = (event) => {
    pageSuspended = false;
    if (event.persisted) scheduleRecalc();
    syncMotionLoop();
  };

  const destroy = () => {
    if (destroyed) return;
    destroyed = true;
    recalcVersion += 1;
    stopMotionLoop();
    if (recalcRaf) cancelAnimationFrame(recalcRaf);
    recalcRaf = 0;
    if (legacyProbeMasked) body.style.removeProperty('--bg-once');
    legacyProbeMasked = false;
    cleanups.splice(0).forEach((cleanup) => cleanup());
    if (motionStyle.isConnected) motionStyle.remove();
    if (window[CONTROLLER_KEY] === controller) delete window[CONTROLLER_KEY];
  };

  controller = {
    body,
    bgfx,
    scrollLayer,
    layer,
    frame,
    refresh: () => {
      refreshMotionPolicy();
      scheduleRecalc();
    },
    destroy,
  };

  // Capture runs before bg-mirror-height.js's older non-capture listener. Its
  // probe then sees no URL; this controller's later recalculation rAF restores
  // the CSS route value in the same rendering cycle, before the next paint.
  listen(window, 'resize', maskLegacyDimensionProbe, { capture: true, passive: true });
  listen(window, 'resize', scheduleRecalc, { passive: true });
  listen(document, 'visibilitychange', onVisibilityChange);
  listen(window, 'pagehide', onPageHide);
  listen(window, 'pageshow', onPageShow);

  if (reduceQuery) {
    if (typeof reduceQuery.addEventListener === 'function') {
      listen(reduceQuery, 'change', refreshMotionPolicy);
    } else if (typeof reduceQuery.addListener === 'function') {
      reduceQuery.addListener(refreshMotionPolicy);
      cleanups.push(() => reduceQuery.removeListener(refreshMotionPolicy));
    }
  }

  if (connection && typeof connection.addEventListener === 'function') {
    listen(connection, 'change', refreshMotionPolicy);
  }

  // bg-mirror-height.js predates this controller and may finish a cache-busted
  // image probe after a newer resize/fallback calculation. Keep its late write
  // from publishing dimensions for the previously selected image.
  if (typeof MutationObserver === 'function') {
    const sizingObserver = new MutationObserver(() => {
      if (
        !destroyed &&
        ownedImgH &&
        body.style.getPropertyValue('--bg-img-h') !== ownedImgH
      ) {
        body.style.setProperty('--bg-img-h', ownedImgH);
      }
    });
    sizingObserver.observe(body, { attributes: true, attributeFilter: ['style'] });
    cleanups.push(() => sizingObserver.disconnect());
  }

  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(() => {
      if (!destroyed) {
        maskLegacyDimensionProbe();
        scheduleRecalc();
      }
    }).catch(() => {});
  }

  window[CONTROLLER_KEY] = controller;
  recalc();
})();
