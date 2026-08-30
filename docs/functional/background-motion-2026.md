# Autonomous background motion — 2026

Status: implemented for static review. Browser validation is deliberately
deferred. DDEV must not be used until PR #67 has released it, and no VPS is in
scope.

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

The validated base is `origin/release/prod` at `22e1673`. The local
`release/prod` branch was intentionally not used because it was stale.

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
| Chromium matrix | Pending; not run |
| DDEV / VPS | Not used |

These results do not constitute browser validation.

## Deferred Chromium matrix

This matrix remains pending until PR #67 releases DDEV. Run it only against a
local checkout serving the exact draft-PR commit; do not contact the VPS. The
test must inspect the wrapper, layer, and `::before` transforms so a legacy CSS
animation cannot be mistaken for the new controller.

| ID | Scenario | Procedure and required result | Status |
| --- | --- | --- | --- |
| BG-C01 | Idle motion | On a normal route, sample transforms and layer bounds for at least 60 seconds without input. The wrapper moves continuously and smoothly on the cosine path, remains within the measured interval, and has no abrupt frame delta. | Pending |
| BG-C02 | Active scrolling | Wheel/touch-scroll slowly and rapidly, then use programmatic jumps to top, middle, and bottom. The additional displacement never exceeds `5 px`, settles smoothly on start/stop, and does not consume image travel. | Pending |
| BG-C03 | Fixed viewport relation | Compare `#unisonges-bgfx.getBoundingClientRect()` before and after scroll. It remains fixed at the viewport while only the inner wrapper transform changes. | Pending |
| BG-C04 | Long page | Use a route where `scrollHeight > clientHeight`, repeat idle and scroll checks at top/middle/bottom, and verify identical ambient speed at every content position. | Pending |
| BG-C05 | Short page | Use a route with no scroll overflow. Scroll influence remains zero, autonomous drift remains available when image capacity permits, and no `NaN` transform appears. | Pending |
| BG-C06 | Route images | Check Accueil, Cours, Stages, Concerts, Association, D'Jam, Orchestre, and a default route. Each computed `--bg-once` keeps its expected image. | Pending |
| BG-C07 | Accueil fallback | Test one viewport that retains `accueil.jpg` and one that crosses the existing `1.45` threshold. Resize back and confirm the CSS-owned source is reconsidered correctly. | Pending |
| BG-C08 | Resize and fonts | Cycle desktop, tall portrait, mobile, and desktop sizes, including resize bursts before and after `document.fonts.ready`. Bounds, height variables, and `--header-h` recalculate without an avoidable jump or duplicate loop; a safety clamp is acceptable only when required to keep the image covering the viewport. | Pending |
| BG-C09 | Hidden tab | Hide the page for at least 30 seconds and return. No animation frames run while hidden, phase does not leap, and exactly one loop resumes. | Pending |
| BG-C10 | Reduced motion | Load with Chromium media emulation set to `reduce`, then toggle if supported. Wrapper, layer, and pseudo-element remain static while image sizing/fallback/header variables remain valid. | Pending |
| BG-C11 | Save-Data | Set `navigator.connection.saveData` before this script executes, and confirm on a real supported profile if available. No continuous frame is scheduled and all three visual transforms stay static. | Pending |
| BG-C12 | Page lifecycle | Exercise back/forward cache and repeat script evaluation against the same DOM. One controller and at most one continuous rAF remain; speed is unchanged after each return. | Pending |
| BG-C13 | No blank edge | At both autonomous extrema and top/middle/bottom scroll positions, test desktop and tall mobile viewports. The painted/clipping layer covers the viewport bottom (and top where geometrically possible) with no visible band. | Pending |
| BG-C14 | Pointer safety | Activate header/menu and content controls near every viewport edge during motion. BGFX remains `aria-hidden`, pointer-inert, and never captures a click. | Pending |
| BG-C15 | Resource reuse | During resize/font/lifecycle cases, inspect image requests and controller state. A URL's in-flight/resolved dimensions are reused and no resize-triggered request series appears. | Pending |

Browser success must not be claimed until every applicable row has been run and
the results, Chromium version, viewport matrix, exact commit SHA, and evidence
locations have been appended to this document.
