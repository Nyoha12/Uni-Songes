# Autonomous background motion — 2026

Status: implemented and validated in local Chromium after PR #67 merged into
`origin/release/prod`. DDEV was used only for the isolated local validation
described below. No VPS was accessed.

## Scope

This change restores a slow, continuous drift to the existing fixed BGFX
background. It changes no public URL, template, stylesheet, route data, image,
Drupal configuration, or dependency. The implementation is confined to:

- `drupal/web/themes/custom/unisonges_theme/js/bgfx-scroll-11.js`;
- this validation record.

The outer `#unisonges-bgfx` remains the viewport-fixed and pointer-inert
container. The JavaScript moves only its `#unisonges-bgfx-scroll` child and
continues to use the existing CSS-owned image selection and sizing variables.

## Preserved rendering contract

The active templates still supply one decorative hierarchy:

```text
#unisonges-bgfx[aria-hidden=true]
└── #unisonges-bgfx-scroll
    └── #unisonges-bgfx-layer
        └── ::before (painted image)
```

The standard and front-page templates also retain the independent
`#unisonges-scrollframe`. No template or CSS edit is part of this change.

The controller preserves:

- the section-specific images for Accueil, Cours, Stages, Concerts,
  Association, D'Jam, Orchestre, and the default section;
- the Accueil rule that tries `accueil.jpg` first and falls back to
  `fontdefault.jpg` only when the required scale exceeds `1.45`;
- the existing normal and heavy-zoom margins and scale limits;
- the resolved image-dimension `Map`, now also sharing an in-flight load when
  resize and font callbacks overlap and reusing the CSS image URL/cache;
- `--bg-w`, `--bg-img-h`, `--bg-scaled-h`, and `--header-h`;
- the existing fixed stacking and `pointer-events: none` click safety.

Sizing and fallback calculation still run when animation is disabled. Reduced
motion and Save-Data therefore produce a stable background without losing the
route image, the Accueil fallback, or the scrollframe header offset.

## Motion contract

There is one continuous `requestAnimationFrame` owner. The legacy CSS cascade
contains animations on both `#unisonges-bgfx-layer` and its `::before`; a
small controller-owned style rule disables those two animations so they cannot
compound the bounded drift or continue in static modes.

Autonomous movement uses a deterministic cosine interpolation:

- one round trip lasts `140000 ms`;
- autonomous displacement is at most `14 px`;
- scroll progress contributes at most `5 px`;
- scroll targets and in-range recalculation changes settle with a
  frame-rate-independent `650 ms` exponential blend; a resize that makes the
  current position unsafe is clamped immediately to prevent a blank edge;
- the cosine advances from visible `requestAnimationFrame` timestamps; hidden
  time is excluded by clearing the timestamp when the loop stops;
- only the blend input is capped at `100 ms`, so a visible main-thread stall
  cannot apply most of the scroll offset in one painted frame while the
  autonomous timeline still follows elapsed time.

Scroll progress is read by its event handler, which refreshes the scroll extent
without writing a transform. It never maps to the full image travel range. The
continuous animation callback does no layout read; it only advances numeric
state and writes the wrapper transform.

### Real travel bounds

The final CSS cascade retains `top: -10vh` on the clipping layer and paints the
image into an extra-wide `::before`. The controller therefore derives the
actual painted capacity from the pseudo-element width, current scale, and
cached intrinsic ratio. It expands `--bg-scaled-h` only as far as the viewport
requires and the painted image permits. This preserves the existing image
scale while avoiding an obsolete layer clip on tall viewports.

`scaled image height - viewport height` is not, by itself, a safe upward travel
distance. Recalculation reads the fixed clip, wrapper, and layer rectangles
outside the continuous loop and derives a guarded transform interval:

```text
baseTop    = layer.top - scrollWrapper.top
baseBottom = baseTop + layer.height
safeMinY   = fixedClipHeight + 2px - baseBottom
safeMaxY   = -2px - baseTop
```

The static anchor is zero clamped into that interval. Motion uses whichever
direction has safe capacity and allocates no more than 45% of that directional
capacity to autonomous drift and 15% to scroll. The combined target therefore
leaves unused travel rather than reaching either guarded clipping edge. If an
extreme viewport cannot fit the rendered image at all, the controller aligns
the lower edge and disables motion for that geometry.

## Lifecycle and resource contract

- A document-level singleton recognizes repeat evaluation against the same DOM
  and refreshes the existing controller instead of adding listeners or a
  second animation loop.
- Replacement DOM destroys the prior controller, cancels pending animation and
  recalculation frames, removes listeners, and invalidates asynchronous
  recalculations.
- Recalculation results carry a generation token, so an older image load cannot
  overwrite a newer resize result.
- `document.hidden` and `pagehide` cancel the animation frame and clear its
  timestamp. Visible or bfcache-restored pages resume the same timeline without
  adding hidden time or starting a second loop.
- `prefers-reduced-motion: reduce` and `navigator.connection.saveData` prevent
  the continuous loop. Preference/connection changes are handled when the
  browser exposes change events.
- Resize and `document.fonts.ready` schedule one recalculation frame. The
  scroll extent is refreshed on scroll events, while the continuous animation
  callback performs no layout measurement.
- A capture-phase resize hook temporarily hides `--bg-once` from the older
  `bg-mirror-height.js` resize probe. The controller restores the route-owned
  value during its recalculation in the same rendering cycle, preventing a
  cache-busted image transfer on every resize.
- A body-style observer protects the controller-owned `--bg-img-h` from a late
  result produced by that older probe. The observer is disconnected with all
  other controller resources during destruction.
- No interval, timeout, network API, module import, package, or external script
  is introduced.

## Static validation

Run from the repository root without DDEV:

```bash
set -euo pipefail

git fetch --no-tags origin release/prod
bg_base_ref=origin/release/prod
bg_js=drupal/web/themes/custom/unisonges_theme/js/bgfx-scroll-11.js
bg_doc=docs/functional/background-motion-2026.md

test "$(git merge-base HEAD "$bg_base_ref")" = "$(git rev-parse "$bg_base_ref")"
test "$(git rev-list --left-right --count "$bg_base_ref"...HEAD | cut -f1)" = 0

node --check "$bg_js"

npx --yes --package=eslint@8.57.1 eslint \
  --no-eslintrc \
  --env browser,es2021 \
  --parser-options ecmaVersion:2021 \
  --rule 'no-undef:error' \
  --rule 'no-unused-vars:error' \
  --rule 'no-redeclare:error' \
  --rule 'no-unreachable:error' \
  "$bg_js"

git diff --check "$bg_base_ref" --
test -z "$(git diff --no-index --check /dev/null "$bg_doc" 2>&1 || true)"
git diff --cached --check
```

The repository does not contain Drupal core, a JavaScript package manifest, or
local `node_modules`, so the pinned `npx` command is an ESLint sanity check,
not a claim that the unavailable full Drupal lint ran. `npx` may download the
pinned tool into its npm cache, but it adds no dependency to the project.

Guard the exact two-file scope, including untracked files:

```bash
bg_expected_files="$(printf '%s\n' \
  docs/functional/background-motion-2026.md \
  drupal/web/themes/custom/unisonges_theme/js/bgfx-scroll-11.js |
  LC_ALL=C sort)"

bg_changed_files="$({
  git diff --no-renames --name-only --diff-filter=ACMRDTUXB "$bg_base_ref" --
  git ls-files --others --exclude-standard
} | LC_ALL=C sort -u)"

diff -u \
  <(printf '%s\n' "$bg_expected_files") \
  <(printf '%s\n' "$bg_changed_files")
```

Guard against an accidental runtime dependency:

```bash
if rg -n \
  "https?://|//[[:alnum:].-]+/|\b(import|export)[[:space:](]|\brequire[[:space:]]*\(|\b(fetch|XMLHttpRequest|WebSocket|EventSource)[[:space:](]|createElement[[:space:]]*\([[:space:]]*['\"]script" \
  "$bg_js"
then
  echo "Unexpected external dependency marker" >&2
  exit 1
fi
```

Review lifecycle and layout access locations explicitly:

```bash
rg -n \
  'requestAnimationFrame|cancelAnimationFrame|visibilitychange|document\.hidden|pagehide|pageshow|matchMedia|saveData|addEventListener|removeEventListener|getBoundingClientRect|getComputedStyle|scrollHeight|clientHeight|setInterval|setTimeout' \
  "$bg_js"
```

The final pre-commit pass must stage only the two guarded files before
`git diff --cached --check`; after commit, also run
`git diff --check "$bg_base_ref"...HEAD`.

### Results — 30 August 2026

PR #67 was verified merged, `origin` was fetched, and the PR branch was rebased
onto `origin/release/prod` at `fe419c2`. The local `release/prod` branch was not
used because it was stale. The final JavaScript exercised by the correction
reruns is commit `b288f80f4c64a098441463bc8e66b7734913e238` (SHA-256
`4084b6ee5ed4887579b570a1f5083f93647ec652caca17ca2a014687aa585cba`).

| Check | Result |
| --- | --- |
| `node --check` with Node `v24.20.0` | Pass |
| Targeted ESLint `8.57.1` sanity (`no-undef`, unused/redeclared variables, unreachable code) | Pass |
| Whitespace checks on the working, staged, and final committed diff | Pass |
| Exact two-file scope guard, including untracked files before staging | Pass |
| External dependency and interval/timeout guards | Pass |
| Animation lifecycle review: one continuous rAF owner, one debounced recalculation rAF, cleanup, visibility and bfcache paths | Pass |
| Reduced-motion and Save-Data review, including both legacy CSS animation targets | Pass |
| Supplemental mocked DOM/rAF lifecycle and bounds harness | Pass; static harness only |
| Chromium matrix | Pass; all BG-C01 through BG-C15 |
| DDEV | Used locally after taking a snapshot; original state restored |
| VPS | Not accessed |

## Chromium validation

The matrix ran against local DDEV with Playwright `1.55.0` and Chromium
`140.0.7339.16`, in both headless and headed Xvfb sessions. Viewports included
desktop `1440 × 900`, tablet `1024 × 768`, tall tablet `820 × 1180`, and mobile
`390 × 844`. Seven temporary page fixtures supplied both zero-overflow and
long-scroll content; `/user/login` exercised the default image. The fixtures
were local only and were removed by snapshot restoration.

The 145-second motion trace exercised the same autonomous-motion core as the
final commit. The two defects found afterward were confined to resize/font
interop with `bg-mirror-height.js`; all affected resize, font, resource,
singleton, bfcache, reduced-motion, and Save-Data cases were rerun against the
final JavaScript before the correction was committed.

| ID | Scenario | Measured result | Status |
| --- | --- | --- | --- |
| BG-C01 | Idle motion | A `145015.5 ms` trace collected 3,894 animation frames and 545 samples. The observed range was `13.994 px`; the best and exact fit was `140000 ms` with R² `0.999999837` and `0.00203 px` RMSE. Maximum transform change for a sub-50 ms frame was `0.018 px`. BGFX layout-read counters stayed at 14 rect/4 style reads for the whole trace. | Pass |
| BG-C02 | Active scrolling | Dedicated autonomous-baseline isolation measured a maximum scroll residual of `4.996 px` (`4.990 px` at bottom and `2.495 px` at half progress). It returned to `0.012 px` at the top. Wheel, touch, programmatic top/middle/bottom, and start/stop smoothing produced no discontinuity. | Pass |
| BG-C03 | Fixed viewport relation | `#unisonges-bgfx` remained `position: fixed` with viewport origin and dimensions before and after content scrolling; only the inner wrapper transform moved. | Pass |
| BG-C04 | Long page | Cours provided `3206 px` of scroll range. Top, middle, and bottom stayed bounded, covered, and retained autonomous motion independently of scroll position. Association was also exercised as long content. | Pass |
| BG-C05 | Short page | Accueil had `maxScroll = 0`; its idle transform changed from `0.009 px` to `0.119 px` while its scroll contribution remained zero. All sizing and transforms stayed finite. | Pass |
| BG-C06 | Route images | Accueil `accueil.jpg`, Cours `cours.jpg`, Stages `yoksel-zok-LdDewlTIn34-unsplash.jpg`, Concerts `concerrts.jpg`, Association `asso.jpg`, D'Jam `djams.jpg`, Orchestre `orchestre.jpg`, and default `fontdefault.jpg` all matched their CSS route selection. | Pass |
| BG-C07 | Accueil fallback | `1440 × 900` retained `accueil.jpg`; `390 × 844` selected `fontdefault.jpg`; returning to `1440 × 900` restored `accueil.jpg`. `--bg-img-h` correctly followed `960 → 585 → 960 px`. | Pass |
| BG-C08 | Resize and fonts | Desktop, tablet, tall portrait, mobile, burst resize, and desktop return all recalculated safe bounds, image variables, and `--header-h`. A delayed real WOFF2 load and `document.fonts.ready` also recalculated. No uncovered transient or duplicate loop was observed. | Pass |
| BG-C09 | Hidden tab | Direct headed Chromium was hidden through real Chrome UI tab switching for `33875 ms`, with CDP disconnected. Transform delta and fired animation frames while hidden were both zero. Return deltas were `0.027 px` immediately and `0.032 px` at 80 ms; motion then resumed by `0.164 px` with one loop. | Pass |
| BG-C10 | Reduced motion | Native Chromium media emulation loaded and toggled `prefers-reduced-motion: reduce`. All visual transforms stayed static for 3.2 seconds, no autonomous rAF was requested, and sizing/fallback variables remained valid. | Pass |
| BG-C11 | Save-Data | Chromium CDP `Emulation.setDataSaverOverride` exposed `navigator.connection.saveData === true` before controller evaluation. Initial and live-policy checks stayed static for 3.2 seconds with no autonomous rAF, while sizing remained valid. | Pass |
| BG-C12 | Page lifecycle | Five repeated evaluations kept the same controller, one owner style, and at most one pending animation frame. Real persisted `pagehide`/`pageshow` bfcache navigation retained document/controller identity and resumed one smooth loop. | Pass |
| BG-C13 | No blank edge | Layer and reconstructed pseudo-image bounds covered the guarded top and bottom at autonomous extrema and top/middle/bottom scroll positions across desktop, tablet, tall portrait, and mobile. Screenshots at 0, 70, and 140 seconds showed no blank band. | Pass |
| BG-C14 | Pointer safety | BGFX remained `aria-hidden` and `pointer-events: none`; four real control clicks and the mobile drawer worked during motion. Root/body widths equalled every viewport width, with no horizontal overflow. | Pass |
| BG-C15 | Resource reuse | After initial settle, three resize cycles plus controller refresh produced zero cache-busted background transfers, zero new BGFX dimension loads, and no request series. The resolved and in-flight dimension caches were reused. | Pass |

### Runtime defects corrected

Chromium exposed two races with the older `bg-mirror-height.js` listener:

- its delayed Accueil image probe could overwrite the current `--bg-img-h`
  after a wide/mobile/wide fallback cycle;
- its resize path issued a cache-busted full background transfer on every
  resize.

The controller now protects its owned height value and masks the legacy probe
until its own same-frame recalculation. Final reruns measured the correct
`960 → 585 → 960 px` Accueil sequence and zero post-settle resize transfers.

### Temporary evidence

Browser artifacts were kept outside the repository, as required, under
`/tmp/pr79-background-motion.DoaZUN`. Principal records are:

- `full-cycle-results.json` and the 0/70/140-second screenshots for the
  145-second trace (its combined harness's early-sample scroll assertion is
  superseded by the dedicated residual result below; the trace itself fits the
  140-second curve);
- `2026-08-30T18-46-38-128Z-short-scroll-influence.json` for the isolated
  scroll cap;
- `matrix-results.json` for routes, short/long content, viewports, geometry,
  fallback, fixed positioning, and overflow;
- `2026-08-30T18-59-45-383Z-short-resize-font-touch.json` and
  `2026-08-30T18-59-24-662Z-short-pointer-resource-overflow.json` for final
  resize/font/touch and pointer/resource reruns;
- `2026-08-30T19-00-49-325Z-short-lifecycle-repeat-bfcache.json`,
  `chromium-lifecycle-validation.json`, and
  `chromium-hidden-no-frame-validation.json` for singleton, bfcache, and the
  genuine hidden-tab interval;
- `2026-08-30T19-00-48-786Z-short-reduced-motion.json` and
  `2026-08-30T19-00-48-777Z-short-save-data.json` for static policies.

These `/tmp` artifacts are intentionally ephemeral and are not PR files or
project dependencies.

### DDEV restoration

Before changing local Drupal state, DDEV snapshot
`pr79-background-motion-prebrowser-20260830T182647Z` and a public-files archive
were created. After Chromium validation, the snapshot and archive were
restored and the serving checkout was returned to its original local
`release/prod` commit `be485180c2c2d13419014b2489ea34f96006ace8`.

The restoration audit found:

- zero nodes, zero `PR79-BGFX-D2D0F771` titles, zero fixture aliases/sources,
  and the original total of 16 aliases;
- default/admin themes `olivero`/`claro`, front page `/node`, and no enabled
  `unisonges_theme` entry; the active-config fingerprint exactly matched the
  baseline (`f1c730b40df5ef1063370c36b1006dace96fb26ab8ac2db12c9ea3c74c3f8dd0`);
- an exact normalized public-file path/type/content fingerprint before and
  after (`4e8060931358d3aae596c7f6f09e371d11c194635b75c829cc1becd119b45e85`);
- a clean serving checkout. Full normalized SQL differed only in three
  volatile `cache_bootstrap` entries warmed between snapshot creation and the
  baseline export; every other database line was identical.

No fixture, browser package, test container, tracked dependency, or theme/local
state change remains in the repository or Drupal instance. The VPS was never
contacted.
