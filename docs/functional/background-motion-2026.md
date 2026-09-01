# Autonomous background motion — 2026

Status: the final zero-scroll-coupling behavior is implemented and validated
with deterministic and real Chromium coverage. PR #84 was merged before the
runtime phase, releasing its exclusive DDEV and Chromium ownership. The
recorded runtime was restored, so PR #91 is ready for review. The VPS was not
accessed.

## Scope

This change restores a slow, continuous drift to the existing fixed BGFX
background. It changes no public URL, template, stylesheet, route data, image,
Drupal configuration, or dependency. The implementation is confined to:

- `drupal/web/themes/custom/unisonges_theme/js/bgfx-scroll-11.js`;
- this validation record.

The outer `#unisonges-bgfx` remains the viewport-fixed and pointer-inert
container. The JavaScript moves only its `#unisonges-bgfx-scroll` child and
continues to use the existing CSS-owned image selection and sizing variables.

The preceding controller deliberately mapped page progress to as much as `5px`
of vertical background travel. That influence has now been intentionally
removed. Although it was subtle and bounded, it made content interaction alter
the ambient trajectory and therefore read as scroll-linked motion. The final
product decision is stricter: the background stays fixed relative to the
viewport while content scrolls, and only time-based ambient drift may move it.

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
- the available autonomous amplitude shrinks below `14px`, including to zero,
  whenever the measured safe travel cannot support the preferred range;
- in-range target changes settle with a frame-rate-independent `650 ms`
  exponential blend; a resize that makes the current position unsafe is
  clamped immediately to prevent a blank edge;
- the cosine advances from visible `requestAnimationFrame` timestamps; hidden
  time is excluded by clearing the timestamp when the loop stops;
- only the blend input is capped at `100 ms`, so a visible main-thread stall
  cannot cause a large settling step while the autonomous timeline still
  follows elapsed time.

At each animation frame, the target is calculated only from the safe anchor,
autonomous phase, autonomous range, and chosen autonomous direction:

```text
phase              = elapsedMs / 140000ms * 2π
autonomousProgress = 0.5 - 0.5 * cos(phase)
desiredY            = clamp(
  anchorY + direction * autonomousRange * autonomousProgress,
  safeMinY,
  safeMaxY
)
```

The cosine has the same value and zero slope at both sides of the `140000ms`
boundary. Modulo wrap therefore creates no target jump or reset; the existing
continuous interpolation remains smooth across the boundary.

The controller does not listen for scroll, wheel, or touch motion and does not
read `frame.scrollTop`, `frame.scrollHeight`, or `frame.clientHeight`.
Consequently top, middle, bottom, direction changes, and scroll speed have zero
input into either the desired or rendered transform. The continuous animation
callback performs no layout read; it only advances numeric state and writes the
wrapper transform.

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
capacity to autonomous drift, capped at the preferred `14px`. The target
therefore leaves unused travel rather than reaching either guarded clipping
edge. A final post-rounding clamp keeps every emitted transform inside the
measured interval, including when its endpoints are fractional. If a geometry
has no directional travel, the range becomes zero and the controller remains
static rather than expose an edge.

```text
autonomousRange = min(14px, directionalCapacity * 0.45)
```

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
- Resize and `document.fonts.ready` schedule one recalculation frame. No content
  scroll, wheel, or touch listener participates in background motion. The
  continuous animation callback performs no layout measurement.
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

if rg -n \
  '\b(SCROLL_MAX_PX|scrollTarget|scrollRange|maxScroll|updateScrollTarget)\b' \
  "$bg_js"
then
  echo "Obsolete scroll-motion identifier remains" >&2
  exit 1
fi

if rg -n \
  "frame\\.(scrollTop|scrollHeight|clientHeight)|listen\\([^,]+,[[:space:]]*['\"]scroll['\"]" \
  "$bg_js"
then
  echo "Background scroll coupling remains" >&2
  exit 1
fi

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
  'requestAnimationFrame|cancelAnimationFrame|visibilitychange|document\.hidden|pagehide|pageshow|matchMedia|saveData|addEventListener|removeEventListener|getBoundingClientRect|getComputedStyle|setInterval|setTimeout' \
  "$bg_js"
```

The final pre-commit pass must stage only the two guarded files before
`git diff --cached --check`; after commit, also run
`git diff --check "$bg_base_ref"...HEAD`.

### Scroll-decoupling results — 1 September 2026

The existing PR #91 branch was fetched and rebased onto `origin/release/prod`
at `a673a078430501d29f1631b96edf57cb65ec4c19`, the merge commit for PR #84.
The exact rebased controller tested in Drupal was commit
`8a12a508926adba94b14133f916cf5fecb2090c8`, with source SHA-256
`13e268738655b2cc911c9b90642c1504b27b6a63bf3571ca26018d6d8dc6754f`.

A deterministic Node harness executed that production controller in isolated
mocked DOM worlds with an explicitly stepped `requestAnimationFrame` queue.
Test-only instrumentation was inserted into the in-memory source to observe
`desiredY`, state, and raw safe bounds; no production test API or harness file
is tracked.

At the frozen `35000ms` autonomous phase, independent worlds at top, 25%, 50%,
75%, and bottom all produced exactly the same `desiredY`
(`-6.999999999999999`), rendered state, and serialized transform
(`translate3d(0, -0.998px, 0)`). Frozen-time scroll, wheel, touch-start,
touch-move, and touch-end sequences caused no state change, transform write, or
next-frame difference from an untouched control.

| Check | Result |
| --- | --- |
| `node --check` with Node `v24.20.0` | Pass |
| Targeted ESLint `8.57.1` (`no-undef`, `no-unused-vars`, `no-redeclare`, `no-unreachable`) | Pass |
| Obsolete scroll-motion identifiers and frame scroll-position reads | Absent |
| Background-motion scroll, wheel, or touch listener | Absent |
| Equal-phase top/25%/50%/75%/bottom desired and rendered transforms | Exactly identical |
| Frozen scroll, wheel, and touch-style event isolation | No motion-state change or direct transform write |
| Autonomous progression on still content | 825 distinct transforms over 16s; maximum 16ms step `0.004px` |
| Full-cycle continuity | Desired position returned exactly at `140000ms`; phase-aligned rendered transforms after warm-up were both `-0.006px` |
| Abundant, 10px, 1.5px, and zero safe travel | Ranges reduced to `14px`, `4.5px`, `0.675px`, and `0px` |
| Extreme landscape and tall-mobile sizing | Non-empty guarded intervals; Pass |
| Every emitted transform, including fractional endpoints | Inside measured `safeMinY … safeMaxY`; Pass |
| Resize, repeated resize, delayed fonts, and header measurement | Pass |
| Hidden/visible and persisted pagehide/pageshow bfcache paths | Phase preserved; Pass |
| Five repeated evaluations and explicit destruction | One motion loop; zero leaked listeners, frames, observer, or owner style |
| Reduced motion and Save-Data, including live policy changes | Static after sizing with no autonomous loop; Pass |
| Image selection, Accueil fallback, and dimension cache reuse | Pass; no post-ready cache-busted request |
| Timer, dependency, and continuous layout-read guards | Pass |
| Exact two-file scope, PR-overlap, secret, whitespace, animation, and edge reviews | Pass |
| Chromium `151.0.7922.34` matrix | Pass |

## Chromium validation

Playwright `1.62.1` and Chromium `151.0.7922.34` loaded the exact rebased PR
source into the local DDEV serving checkout. The tracked serving tree matched
the PR commit while the ignored `drupal/.ddev` inputs retained their original
fingerprint. The VPS was not accessed.

The controlled browser harness froze the autonomous timestamp at `35000ms`.
Top, 25%, 50%, 75%, and bottom positions across a `4975px` content scroll
range all returned the same desired value (`-7px`), rendered state, and exact
inline transform (`translate3d(0px, -0.998px, 0px)`). Mouse wheel,
trackpad-style wheel sequences, touch scrolling, PageDown, PageUp, Home, End,
and scripted `scrollTop` changes all moved the content while producing zero
background transform writes and zero changes to anchor, direction, amplitude,
phase, target, or velocity. A separate headed X11 probe dragged the actual
native Chromium thumb on the production scrollframe from `scrollTop 0` to
`4736`, generating nine trusted scroll events. At the same frozen `35000ms`
phase, its complete observed motion tuple and exact transform remained
byte-identical, with zero transform mutation during the drag.

Two accelerated cycles sampled the production callback every `100ms` while a
control world remained idle and a second world scrolled continuously. All
`2800` paired samples matched exactly. Travel was `13.997px`, the maximum
sampled step was `0.032px`, and the serialized transforms immediately before
and at both `140000ms` and `280000ms` boundaries were continuously `-0.006px`.

A separate native-clock trace ran for `142149ms`, collected `282` samples, and
measured `13.996px` of travel. At `140135ms` the transform was `-0.003px`,
demonstrating the approximately 140-second return without a boundary jump.

### Edge and route matrix

Thirty-three production-DOM cases covered desktop, tablet, tall mobile, short
landscape, extreme landscape, long and very short content, and 100%, 150%, and
200% reflow. They covered Accueil, Cours, Stages, Concerts, Association,
D'Jam, Orchestre, and the default image. Accueil retained `accueil.jpg` on
desktop and selected the existing `fontdefault.jpg` fallback on tall mobile.

Every sampled transform remained inside its directly captured safe interval.
Across the natural route/device matrix, the smallest observed bottom guard was
exactly `2px`, the smallest top guard was greater than `3.7px`, and horizontal
overflow was zero. Additional CSS-constrained production-DOM cases supplied
directional capacities of `6px`, `1.5px`, and `0px`; the production controller
reduced their amplitudes to `2.7px`, `0.675px`, and `0px`. No top or bottom edge
was uncovered, including after transform rounding.

### Lifecycle and resource matrix

- resize bursts, a native post-ready resize sequence, delayed font readiness,
  duplicate script evaluation, explicit destruction, and clean recreation
  passed with one controller and one animation owner;
- reduced motion and Save-Data completed sizing but scheduled no autonomous
  animation frame and stayed static;
- native post-ready resize and font windows issued no image request and no
  cache-busted request. The older `bg-mirror-height.js` still makes its
  pre-existing initial `?v=` probe before controller readiness; PR #91 creates
  no such URL, and no post-ready probe remained;
- a genuine headed Chrome tab was hidden through the browser UI for `2.5s`
  with automation disconnected. BGFX callbacks stayed `67 → 67`, the
  transform stayed exactly `-0.005px`, the first visible transform was
  unchanged, and autonomous movement then resumed;
- a genuine headed history navigation emitted `pagehide.persisted = true` and
  `pageshow.persisted = true`. It retained document identity, one controller,
  one owner style, and the exact `-0.088px` suspended transform before
  resuming.

No controller exception, failed background request, HTTP 5xx, external
dependency, continuous-frame layout read, duplicate loop, listener leak, blank
edge, or horizontal overflow was observed. Independent lifecycle and
image-edge reviews found no source issue.

### Observational integration notes

The unrelated `/accueil`, `/cours-et-stages`, `/reservation-cours`, `/reserver`,
`/ateliers`, and `/a-propos` observations made no code change. Navigation text
visibly rendered `É`, `é`, `À`, `&`, and `’` on every route. Lowercase `à` is
not present in the canonical labels, which is source-text absence rather than a
glyph failure. Top-level and submenu labels both compute to
`system-ui, "Segoe UI", Arial, sans-serif`; Chromium selected DejaVu Sans
bold for top-level labels and DejaVu Sans regular for submenu labels.

All 11 primary CTAs were visible in default, actual visited-history, hover,
keyboard-focus, and active states; none was invisible before hover or became
visible only on hover. The header `Réserver` CTA has an unrelated low-contrast
hover/active state: `a:hover` changes its text from white to amber
`rgb(245, 158, 11)` while `.btn--cta` keeps the teal
`rgb(15, 118, 110)` background, reducing measured contrast from `5.4733:1` to
`2.5485:1`. This PR intentionally does not repair that separate stylesheet
defect.

Machine-readable results and screenshots are outside the repository under
`/tmp/pr91-background-no-scroll-runtime-20260901T103129Z`. The principal files
are `logs/chromium-background-results.json`,
`logs/chromium-real-hidden-tab.json`,
`logs/chromium-headed-native-scrollbar.json`,
`integration-observations.json`, and the `screenshots/` tree.

## PR #91 runtime restoration

Before any Drupal/runtime write, DDEV snapshot
`pr91-background-no-scroll-prechromium-20260901T103129Z` was created. The
baseline recorded:

- normalized database SHA-256
  `161ef10fa5a32b0075cc19c4abd9a3ec8b9d8e0039be392db83f676397134b4b`;
- 314 canonical active-config entries with SHA-256
  `d007925159064df3cdfa907ed7faf53ebc37215b74fa3185d5145f84dbbb2b38`;
- 314 raw config rows with SHA-256
  `0045e74f9223c99b7a48f1c7009717ac0f46fe2c35885cf91db935b6b3eb5bb4`;
- 370 public-file entries, 245 files, and 838007 bytes with SHA-256
  `51e4eb31f850df8f0f88b3406c0257c5c9f085fcb76c6bee7556acc26fa87d9b`;
- Olivero as the default theme, Claro as the admin theme, front page `/node`,
  zero nodes, 16 path aliases, and zero content menu links.

After the browser matrix, the named snapshot was restored. The normalized SQL
was byte-identical to the baseline after excluding only the dump-completion
timestamp. Canonical and raw config fingerprints matched exactly, as did the
theme/front-page state and all recorded entity counts. A second final snapshot
restore removed the cache effects of those Drupal bootstrap checks; the final
raw config and normalized SQL fingerprints again matched exactly.

The public-file baseline was restored to the exact validated
`drupal/web/sites/default/files` path. Its fingerprint matched exactly, and a
checksum-aware `rsync --dry-run --delete` reported no difference. The serving
checkout was returned to clean `release/prod` at
`a673a078430501d29f1631b96edf57cb65ec4c19`. Temporary container and host
helpers, browser packages, and test profiles were removed while the JSON logs
and screenshots were retained under `/tmp`. DDEV was stopped with no remaining
`ddev-unisonges-*` container, explicitly releasing its ownership.

The historical Chromium evidence below validated the superseded implementation
with a deliberate `5px` scroll contribution. It is retained only as historical
context and is not the evidence for this zero-scroll-coupling correction.

## Historical evidence for the superseded controller

The records below are retained as evidence for the sizing, lifecycle, image,
and autonomous-motion architecture inherited by this change. They exercised
the earlier controller with a deliberate `5px` scroll contribution and do not
validate the final zero-scroll-coupling decision.

### Historical results — 30 August 2026

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

### Historical Chromium validation

The matrix ran against local DDEV with Playwright `1.55.0` and Chromium
`140.0.7339.16`, in both headless and headed Xvfb sessions. Viewports included
desktop `1440 × 900`, tablet `1024 × 768`, tall tablet `820 × 1180`, and mobile
`390 × 844`. Seven temporary page fixtures supplied both zero-overflow and
long-scroll content; `/user/login` exercised the default image. The fixtures
were local only and were removed by snapshot restoration.

The 145-second motion trace exercised the autonomous-motion core inherited by
this correction. The two defects found afterward were confined to resize/font
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
