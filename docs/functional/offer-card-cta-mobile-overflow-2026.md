# Offer-card CTA box-model containment — 2026

## Result and scope

This static phase makes the canonical `.unisonges-offer-card__cta` own its box
model. The selector now uses `border-box`, preserves its existing painted
minimum height, and permits emergency wrapping to participate in flex intrinsic
sizing. No wording, route, padding, border, radius, colour, font, weight,
line-height, letter-spacing, interaction-state style, markup, JavaScript,
library, configuration, navigation, account, reservation, Commerce, or
background file changes.

The initial audited base was `origin/release/prod` at
`2ffa2538204f0705dadf6faebceef8c77ebcbfc2`, the merge of PR #95. The 2026-09-07
revalidation compares the existing correction at
`8817fa7d0c2c4529e72ea2f865d8d2e1a11d6152` with current production base
`3e53bc5b3d1b3ded9a207bc2e16ec48aa84b9bdf`. The canonical stylesheet is
byte-identical between these two production bases. No rebase or CSS adjustment
is needed; this follow-up updates the evidence and runtime handoff only. The
complete PR remains limited to:

```text
docs/functional/offer-card-cta-mobile-overflow-2026.md
drupal/web/themes/custom/unisonges_theme/css/styles.css
```

No DDEV, Docker, Drush, browser, local server, Mailpit, or VPS resource was used.
Terminal 3 / PR #94 exclusively owns DDEV and the serving checkout. PR #110
remains draft pending the combined runtime matrix with #92/#89 below. Release
of #94's resources does not trigger any runtime launch by this task. No other
worktree, including #82 and its local changes, is accessed.

## CSS and cascade audit

The initial value of `box-sizing` is `content-box`, and the property is not
inherited. The border-box declarations on the header, scrollframe, booking
form, errors, drawer, and account controls therefore do not reach this
component.

The only repository-wide-looking resets are outside the applicable cascade:

- `public/styles.css` belongs to the separate static site, not the Drupal theme;
- `navigation-submenus.css` resets only `.navigation-submenus` and its
  descendants and pseudo-elements;
- `auth-account.css` is attached only in account contexts and has only scoped
  sizing rules;
- the exported Drupal modules do not enable `bootstrap_library`, and no
  `bootstrap_barrio_source` setting supplies a Bootstrap reset.

The documented Stage measurement of `197.953125 × 66.140625` CSS pixels also
confirms that the live direct-anchor CTA used content-box sizing when that
runtime matrix was recorded.

Every rule that can match the class was inspected:

| Source rule                                            |       Specificity | Geometry or cascade effect                                                                                                                           |
| ------------------------------------------------------ | ----------------: | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| `.unisonges-offer-card__cta`                           |           `0-1-0` | `inline-flex`, `max-width: 100%`, minimum height, padding, border, radius, colours, type, shadow and wrapping. This is the sole source rule changed. |
| `.unisonges-offer-card__cta a` / `a:visited`           | `0-1-1` / `0-2-1` | An inner wrapper link is an unpadded, auto-sized `inline-flex`; it inherits colour, decoration and wrapping.                                         |
| CTA hover/focus/focus-within                           |           `0-2-0` | Changes colour, brightness and shadow only.                                                                                                          |
| CTA inner-link hover/focus                             |           `0-2-1` | Keeps inherited colour; no geometry.                                                                                                                 |
| `@media (max-width: 640px) .unisonges-offer-card__cta` |           `0-1-0` | Adds `width: 100%`; this exposed the confirmed content-box overflow. It remains unchanged and follows the base rule.                                 |
| `.unisonges-detail-section p > a` and states           | `0-1-2` / `0-2-2` | A pre-existing colour precedence for four paragraph-wrapper consumers; it does not set geometry and this PR does not alter it.                       |
| Later intro/detail `p` readability rules               |           `0-1-1` | Set paragraph wrappers to `max-width: 68ch`; direct anchors keep `max-width: 100%`.                                                                  |

Apart from the mobile `width: 100%` and higher-specificity paragraph maximum
documented above, no later rule changes the CTA box sizing, width, padding,
border, display or white-space. No pseudo-element box-sizing rule matches an
offer-card CTA.

## Complete consumer inventory

### Markup and layout families

| Family                                | Element and display                                                                                           | Effective width                                                                        | Padding and border                                        | Parent layout                                                                          | Behaviour at `<= 640px`                                                                 |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- | --------------------------------------------------------- | -------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| Content-architecture card CTA         | `p.unisonges-offer-card__cta` wrapper, `inline-flex`; child `a` is another `inline-flex`                      | Wrapper is auto/stretch in a column-flex card; the later `p` rule can cap it at `68ch` | Wrapper: `.68rem 1.05rem`, transparent `1px`; child: none | `article.unisonges-offer-card` is column flex in a three/two/one-column grid           | Wrapper receives `width: 100%`; grid becomes one column and card padding becomes `1rem` |
| Content-architecture intro/detail CTA | Same `p > a` wrapper shape and displays                                                                       | Auto/shrink-to-fit above mobile, with effective `max-width: 68ch`                      | Same wrapper chrome; child remains unpadded               | Block `.unisonges-page-intro` or `.unisonges-detail-section`, each padded and bordered | Wrapper receives `width: 100%` of the parent content box                                |
| Stage ticket CTA                      | Direct `a.unisonges-offer-card__cta`, `inline-flex`                                                           | Auto/shrink-to-fit, capped at `100%`                                                   | Padding and border are on the interactive anchor          | Block `div.unisonges-detail-section`                                                   | Anchor receives `width: 100%`; the whole pill remains interactive                       |
| PR #92 Concert card CTA               | Direct `a.unisonges-offer-card__cta`, `inline-flex`                                                           | Auto/stretch in the column-flex card, capped at `100%`                                 | Padding and border are on the interactive anchor          | `article.card.unisonges-offer-card` in the canonical grid                              | Anchor receives `width: 100%` in the one-column grid                                    |
| PR #92 D’Jam/Orchestre action         | Direct `a.btn.unisonges-offer-card__cta`; the later exact CTA rule wins shared `.btn` presentation properties | Auto/shrink-to-fit, capped at `100%`                                                   | Exact CTA padding/border; no `.btn--cta`                  | `nav.actions-row.unisonges-detail-section` in normal block flow                        | Each exact CTA receives `width: 100%`; `.btn--cta` is never matched                     |

In column-flex cards, the declared `inline-flex` CTA is blockified as a flex
item and stretches across its track. In intro/detail block flow, `inline-flex`
uses shrink-to-fit sizing instead. The paragraph maximum applies to wrappers
inside `.node-body`, `.unisonges-page-intro` or `.unisonges-detail-section`;
it does not apply to a direct anchor.

No current producer emits a `button.unisonges-offer-card__cta`. The component
guide permits a link, button or wrapper, but its two examples are direct
anchors. Current tracked Twig and standalone HTML have no exact-class producer;
the Twig producers described above exist only in the open PR #92 head.

Paragraph wrappers retain a pre-existing interaction nuance: the painted
wrapper is about `66.16px` tall, but its unpadded child anchor is the interactive
target and is approximately one `1.22rem` line tall for a one-line label. This
patch neither shrinks nor expands that hit area. Its surrounding wrapper spacing
is unchanged; a producer migration or descendant hit-area change would be a
separate accessibility change and is deferred for runtime review. Direct-anchor
consumers keep the complete painted pill as their interactive target.

### Versioned content-architecture producers

All 35 producers below are paragraph wrappers with one inner anchor in
`drupal/scripts/apply-content-architecture-2026.sh`. The script writes
`full_html`; the exported filter does not strip the class. Their source lines
are unchanged in the current production base. This is a source inventory, not
a claim about the database or active rendered output inspected in this phase.

| Page                              | Parent and source locations                                             | Complete CTA set                                                                                                                                  |
| --------------------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| `/accueil`                        | One intro at script line 251; six cards at 258, 263, 268, 273, 278, 283 | Réserver un cours; Découvrir les cours; Découvrir les stages; Voir les concerts; Voir les artistes; Voir les prestations; Découvrir l’association |
| `/cours-et-stages`                | One intro at 295; two cards at 303, 309                                 | Réserver un cours; Découvrir les cours; Découvrir les stages                                                                                      |
| `/cours`                          | One intro at 322; three cards at 330, 336, 342                          | Réserver un cours; Voir le cours de didgeridoo; Voir le cours de guimbarde; Voir le cours de méditation / improvisation                           |
| `/cours/didgeridoo`               | Detail at 366                                                           | Réserver un cours de didgeridoo                                                                                                                   |
| `/cours/guimbarde`                | Detail at 389                                                           | Réserver un cours de guimbarde                                                                                                                    |
| `/cours/meditation-improvisation` | Detail at 411                                                           | Réserver un cours de méditation / improvisation                                                                                                   |
| `/stages`                         | Three cards at 430, 436, 442                                            | Voir les stages didgeridoo; Voir musique improvisée / méditation; Voir les stages spéciaux                                                        |
| `/ateliers`                       | Four cards at 529, 534, 539, 544                                        | Découvrir D’Jam; Découvrir l’Orchestre des Rêveurs; Découvrir le Forum; Voir les services et prestations                                          |
| `/forum`                          | Detail at 562                                                           | Proposer un sujet à l’association                                                                                                                 |
| `/a-propos`                       | Five cards at 579, 584, 589, 594, 599                                   | Découvrir l’association; Voir les artistes et partenaires; Découvrir l’origine; Découvrir le Blog; Voir les services et prestations               |
| `/association`                    | Five cards at 633, 638, 643, 648, 653                                   | Voir les cours; Voir les stages; Voir les concerts; Voir les artistes; Voir les prestations                                                       |

The scripted `/blog` body has no exact-class CTA; `/a-propos` has the card that
links to it. The `/accueil` row records seven legacy promotional wrappers.
Merged PR #103 supplies an installer that replaces that body with its editorial
intro and component, which contain no exact-class consumer. Its own stylesheet
also changes the home scrollframe's inner padding. The active home content and
library attachment are deferred runtime checks; the canonical shell fixtures
below do not model the editorial-home alternative.

The other current runtime producer is the theme preprocess hook: a published
full Stage with an accessible published `ticket_stage` and usable variation gets
one direct `a` labelled “Réserver ce stage” inside
`div.unisonges-detail-section`. Unavailable Stage cases receive a status
paragraph, not this CTA. Concert nodes cannot enter that Stage-only branch.

### PR #92 prospective output

The #92 contract was read from the following immutable Git head on 2026-09-07:
`28b13c0046062bd2b7193ff003e79fe0d4168df0`. Its documentation and five Twig
files were read as Git objects, without accessing its worktree. It has exactly
six files, none modified here:

```text
docs/functional/public-hub-components-2026.md
drupal/web/themes/custom/unisonges_theme/templates/content/node--10.html.twig
drupal/web/themes/custom/unisonges_theme/templates/content/node--6.html.twig
drupal/web/themes/custom/unisonges_theme/templates/content/node--9.html.twig
drupal/web/themes/custom/unisonges_theme/templates/includes/_card-grid.html.twig
drupal/web/themes/custom/unisonges_theme/templates/includes/_public-hub-actions.html.twig
```

Its `_card-grid` include emits two direct Concert card anchors: “Contacter
l’association” and “Voir les jams”. Its new public-actions include emits two
direct `a.btn.unisonges-offer-card__cta` links each for Orchestre (“Rejoindre le
collectif”, “Voir les concerts”) and D’Jam (“Voir les concerts”, “Participer à
une prochaine jam”). The `.btn` compatibility class does not make them
`.btn--cta`; the exact selector safely supplies the same corrected box model.

### Negative consumer audit

- Current Contact actions use `_actions-row` and `.btn` only.
- Current D’Jam and Orchestre page actions use `.btn` only; PR #92 deliberately
  moves them to the exact CTA selector plus `.btn`.
- Reservation portal and tunnel controls use `.btn`, `.btn--cta`, Webform
  submits and reservation-specific classes.
- Commerce cart, add-to-cart and checkout controls do not use the exact class.
- Account forms and messages use account-scoped classes and
  `auth-account.css`.
- Navigation, disclosures and editorial-home links use their own selectors.

## Chosen source correction

The correction belongs in the base `.unisonges-offer-card__cta` rule, not only
in the mobile query:

```css
box-sizing: border-box;
max-width: 100%;
min-height: calc(2.65rem + 0.68rem + 0.68rem + 2px);
/* unchanged declarations */
overflow-wrap: anywhere;
```

Base placement makes the mobile `width: 100%` measure the painted border box.
Direct anchors, including all six #92 links, also retain an effective
`max-width: 100%` at every width. Paragraph wrappers instead have the later
`max-width: 68ch` where the paragraph readability selectors match; the base
`max-width: 100%` does not win that cascade.

Let `C` be the parent's content width and `K` the font-dependent `68ch` cap.
Mobile wrappers have painted width `min(C, K) <= C`. Desktop wrappers use
shrink-to-fit sizing or card stretch, with `anywhere` enabling intrinsic
shrinking; their painted width stays at most `min(C, K)` when the available
width accommodates the padding, border and a text unit. Ordinary desktop
labels below the applicable caps retain their auto outer width. A long wrapper
actually bound by `68ch` has its painted cap reduced from `K + 35.6px` to `K`
at a 16px root, so parity is not claimed for those constrained labels.

`box-sizing` also applies to `min-height`. Leaving the old value unchanged would
shrink a one-line CTA by about `22.88px`. The replacement expresses the exact
border-box equivalent of the old content-box minimum:

```text
before = max(2.65rem, content height) + 0.68rem + 0.68rem + 2px
after  = max(2.65rem + 0.68rem + 0.68rem + 2px,
             content height + 0.68rem + 0.68rem + 2px)
```

These are algebraically equal whenever text uses the same number of lines. Text
that must wrap earlier after containment may become taller, which is the safe
and intended result; it is never clipped.

`overflow-wrap: anywhere` differs from the previous `break-word` only for
emergency wrapping. Unlike `break-word`, its opportunities participate in
min-content sizing, which is required for the anonymous flex text item of a
direct anchor and the nested flex item of a paragraph wrapper. Ordinary French
labels with spaces keep their normal wrap opportunities. No font, weight,
line-height or letter-spacing changes; unconstrained ordinary labels retain
their geometry.

No `overflow: hidden`, arbitrary percentage, JavaScript, `!important`, or
unrelated selector is added.

## Deterministic box-model fixtures

The fixture uses the source values at a `16px` root:

```text
horizontal padding = 2 × 1.05rem = 33.6px
horizontal border  = 2 × 1px     = 2px
content-box excess                  35.6px
```

For the canonical non-editorial page shell, the fixed scrollframe border box is
`min(980px, viewport - 32px)`. Removing its two borders and the
`.scrollframe__inner` horizontal padding gives `frame - 42px`. Card tracks then
use the canonical one/two/three-column grid and `1.1rem` gaps; card content
width removes the card’s border and breakpoint-specific padding. Detail-panel
content width similarly removes its own border and padding and respects the
existing `840px` maximum.

Mobile rows have a declared `100%` width. Above 640px, the max-bound token rows
model direct anchors whose old `break-word` min-content size exceeded the
available width. An ordinary spaced French label can already shrink and wrap
with `width: auto`; it need not reach that old content-box maximum. “Stretch”
rows model the column-flex card consumer. The desktop Stage short row reuses
the previously recorded outer width; no new browser measurement was made.
Numeric wrapper rows assume the `68ch` cap is not binding; the symbolic
`min(C, K)` calculation above covers a binding cap without inventing font metrics.

| Fixture                                                      | Containing block | Declared/effective width  | Before border box | Before positive overflow | After border box | After positive overflow |
| ------------------------------------------------------------ | ---------------: | ------------------------- | ----------------: | -----------------------: | ---------------: | ----------------------: |
| 320px, short CTA, one-card row                               |            212px | `100%`                    |           247.6px |                   35.6px |            212px |                       0 |
| 360px, long French CTA, one-card row                         |            252px | `100%`                    |           287.6px |                   35.6px |            252px |                       0 |
| 390px, unbroken token, one-card row                          |            282px | `100%`                    |           317.6px |                   35.6px |            282px |                       0 |
| 640px boundary, long French CTA                              |            532px | `100%`                    |           567.6px |                   35.6px |            532px |                       0 |
| 768px tablet, short CTA, two-card row                        |          297.8px | `auto` / stretch          |           297.8px |                        0 |          297.8px |                       0 |
| 768px tablet, max-bound token in detail parent               |            652px | `auto`, `max-width: 100%` |           687.6px |                   35.6px |            652px |                       0 |
| 1440px desktop, short Stage CTA                              |            840px | `auto`                    |         197.953px |                        0 |        197.953px |                       0 |
| 1440px desktop, long spaced French CTA                       |            840px | `auto` / shrink-to-fit    |             840px |                        0 |            840px |                       0 |
| 960px at 150% equivalent width, three-card row               |          243.2px | `auto` / stretch          |           243.2px |                        0 |          243.2px |                       0 |
| 720px at 200% equivalent width, max-bound token              |            604px | `auto`, `max-width: 100%` |           639.6px |                   35.6px |            604px |                       0 |
| 320px physical at 150% equivalent width, token               |        105.333px | `100%`                    |         140.933px |                   35.6px |        105.333px |                       0 |
| 320px physical at 200% equivalent width, token               |             52px | `100%`                    |            87.6px |                   35.6px |             52px |                       0 |
| Nested 240px parent with 24px padding and 1px borders, token |            190px | `100%`                    |           225.6px |                   35.6px |            190px |                       0 |

All 13 cases have zero positive overflow after the change. The mobile short,
long French and unbroken-token variants share the same outer-width calculation;
`anywhere` lets the token shrink and wrap inside the resulting content area.

The 2026-09-07 review retains 12 numeric fixtures unchanged and corrects the
desktop spaced-French fixture's before value from `875.6px` to `840px`
(`0px` overflow already before the fix). This corrects the earlier model's
classification, not the CSS. Text fitting assumes enough room for one rendered
text unit; actual glyph metrics, line counts and document overflow require the
runtime cells below. Mathematical containment is not a Chromium pass.

At a `16px` root, the minimum painted height is unchanged:

```text
before: 2.65rem + 1.36rem + 2px = 66.16px
after:  2.65rem + 1.36rem + 2px = 66.16px
```

Direct anchors therefore retain their approximately `66px` interactive target.
Wrapped labels grow vertically. No fixed height or clipping is introduced.

## Integration boundaries

PR #95’s merged `.btn--cta:hover`, `.btn--cta:active` and four historical
Webform submit focus/focus-visible selectors remain byte-identical. The new rule
does not match `.btn--cta`.

PR #92 changes no CSS and explicitly documented this follow-up. Its direct
Concert, D’Jam and Orchestre anchors receive containment only when they carry
the exact class. Its six files remain untouched.

Merged PR #103’s editorial links and disclosures use a separate module stylesheet.
Merged PR #99 account controls and PR #100 messages remain outside the exact
selector. Reservation portal, tunnel, cart, checkout, Contact, navigation and
background families remain unchanged.

The original 2026-09-02 pre-commit open-PR audit found 20 draft PRs. Its canonical JSON
serialization of PR numbers and sorted filenames had SHA-256
`98450f3119feebbd4841453e9059e5814fed8f05f1309d539f7bc9ff9abe8054`.
None changed either file in this PR at that time. PR #92 adds exact-class
consumers, while now-merged PR #87 changed the artists body without altering
the 35 architecture CTA producer lines.

The #89 documentation was read at
`811bc6ce392f85e41a6736581090ce9bc1d6e0ac`. Its dynamic Concert View remains
separate from #92's static cards: main node content has weight `-3`, followed
by the View block at `50`. Body and View empty/populated combinations are
pending combined runtime checks, not static evidence of rendered output.

The #94 footer contract was read at
`3352898c0dc762dc8802519d63b11fc1fe32ca23`. Its include attaches the dedicated
`unisonges_theme/public-footer` library and `css/public-footer.css`. It has no
exact CTA consumer. Its footer rules and auth-main layout guard remain wholly
in #94; no footer correction is copied into `styles.css` here.

## Static validation

The initial source passed the following checks on 2026-09-02. The revalidation
below distinguishes reused evidence from new checks and corrects the one
before-width fixture classification described above:

| Check                 | Result                                                                                                                                                                          |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| CSS parsing           | CSSTree CLI 1.0.0 parsed `styles.css`; Stylelint 17.0.0 parsed it together with `navigation-submenus.css`, `auth-account.css` and `reservation-first-tunnel.css`.               |
| Structural balance    | An independent state-machine scan found balanced braces, comments and strings in all four stylesheets.                                                                          |
| Cascade               | Exactly two exact-selector rules remain: base and `max-width: 640px`, both specificity `0-1-0`; the mobile rule follows the base rule and retains `width: 100%`.                |
| Box model             | All 13 fixtures above passed with zero positive overflow after correction; minimum height remained `66.16px` and the recorded normal desktop width remained `197.953125px`.     |
| Source transformation | Reconstructing `styles.css` from the base by changing only the base CTA’s box sizing, equivalent minimum height and emergency wrapping produced the current file byte for byte. |
| Protected families    | `.btn--cta`, historical Webform focus, account, message, navigation, reservation, background and all six PR #92 files compare byte-identical to the base.                       |
| Formatting            | Prettier 3.6.2 passed this complete report and each of the three changed CSS declaration ranges.                                                                                |
| Repository hygiene    | `git diff --check` passed; the exact-file guard found only the two declared paths.                                                                                              |
| PR overlap            | A fresh GitHub audit inspected every filename in all 20 open PRs and found zero intersection with the two paths.                                                                |
| Secrets               | The added-line credential/secret scan passed.                                                                                                                                   |

An independent read-only CSS, layout and accessibility review passed with no
blocking finding. It independently recomputed the fixtures and checked every
consumer family, the wrapper-link target nuance, and the PR #92/#95 boundaries.

The historical full `styles.css` file is not globally Prettier-formatted on the
base branch. A full-file `prettier --check` reports the same pre-existing warning
on base and head; globally rewriting it would violate the narrow scope. The
changed declaration ranges are checked independently, and this report is checked
as a whole.

### Revalidation on 2026-09-07

The production branch has advanced, but the canonical CSS, Stage producer and
35 architecture CTA source lines used by this correction are unchanged. The
existing #110 CSS is also byte-identical to its original commit. This makes a
documentation-only commit useful; rebasing or rewriting the CSS would not add
validation evidence. The remote #110 head was verified as `8817fa7d0c2c4529e72ea2f865d8d2e1a11d6152`
before this follow-up; publication uses an ordinary fast-forward push.

| Evidence                   | Reused or new result                                                                                                                                                                                                          |
| -------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Canonical input comparison | New byte comparisons against production `3e53bc5`: the existing correction applies unchanged; no missing shared stylesheet or producer change to import.                                                                      |
| Numeric fixtures           | 12 original rows reused; one desktop spaced-label before value corrected. Independent shell/box arithmetic confirms 13/13 after-overflow values are zero under the stated assumptions.                                        |
| Wrapper cascade            | New explicit `0-1-1` paragraph-cap audit and 90 symbolic width/cap/sizing combinations pass; `68ch` is kept in font-relative terms.                                                                                           |
| Desktop and target height  | Existing ordinary Stage width `197.953125px` reused; algebra confirms painted minimum `66.16px` before/after and distinguishes the one-line inner link's approximately `19.52px`.                                             |
| CSS syntax and balance     | New parse with already cached CSSTree 3.2.1, PostCSS 8.5.26 and Stylelint 17.0.0; geometry declaration grammar and independent brace/comment/string checks pass. No dependencies installed.                                   |
| Scope and #95              | Exact reconstruction from current production CSS changes only the original base CTA box sizing, minimum height and wrapping. Every other CSS byte, including `.btn--cta`, Webform, header and background rules, is identical. |
| Integration review         | New read-only reviews confirm the pinned #92/#89/#94 contracts, unchanged architecture producers and merged #103's separate active-home path.                                                                                 |
| Open PR files              | A new audit of all 21 open PRs finds no other PR changing either allowed path; #110 remains draft.                                                                                                                            |

Formatting checks use the already cached Prettier 3.6.2, with the historical
whole-stylesheet warning reused from the initial audit. Only the report is
formatted. The final publication guards pass for the doc-only follow-up diff,
the complete PR's exact two paths, whitespace and added-line credentials.
The independent review found documentation corrections, all reflected above,
and no need to alter CSS or the historical wrapper hit area.

## Deferred combined runtime matrix

No row below is claimed as executed in this static phase. Keep the PR draft
until combined Chromium validation with PR #92/#89 covers:

| Runtime surface                        | Required coverage                                                                                                                                                        |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| All 35 content-architecture wrappers   | `/accueil`, course/stage hubs and detail pages, `/ateliers`, `/forum`, `/a-propos`, `/association`; verify the painted wrapper, inner-link target and every normal label |
| Stage product CTA                      | Available/unavailable Stage states, direct anchor, known and fallback price paths, no add-to-cart regression                                                             |
| PR #92 Concert cards                   | Both cards at 320px and every standard width; one/two/three-column regimes; long and unbroken labels                                                                     |
| PR #92 D’Jam and Orchestre actions     | Both actions on both pages; exact CTA plus `.btn`; confirm no `.btn--cta` state crossover                                                                                |
| Architecture and home/Blog integration | Versioned legacy home wrappers and the merged PR #103 editorial-home alternative, according to active content; Blog has no unexpected CTA                                |
| Negative controls                      | Contact, auth/account, messages, reservation portal/tunnel, Commerce cart/checkout, navigation, disclosures and background                                               |

Run each applicable surface at desktop, tablet, mobile, `390px`, `360px`, the
`640px` boundary, and `320px`, plus 100%, 150% and 200% reflow. Exercise short
French, long translated and unbroken-token labels. Confirm:

- border boxes remain within every parent and no document or scrollframe has
  horizontal overflow;
- normal desktop widths and the approximately `66px` one-line height remain
  unchanged;
- wrapping grows the CTA without clipping;
- keyboard focus and accessible names remain visible;
- normal, visited, hover, active, focus and focus-visible colours/shadows remain
  those already owned by the component and PR #95;
- forced-colors presentation remains usable;
- direct anchors retain their full interactive target, and wrapper-link spacing
  is reviewed explicitly;
- no regression occurs in `.btn--cta` or the historical Webform focus fix;
- there is no PHP warning/error, browser page error or console error.

Record the following cells separately on the same identified combined #92/#89/#110
build. Each cell remains **pending**:

| Cell | Surface and conditions                                                                                           | Required real observations                                                                                            |
| ---- | ---------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| C1   | `/concerts`: editorial body empty/populated crossed with View empty/populated; both #92 anchors                  | Static cards before the View, no duplicate output; CTA containment at 320/360/390/640px and tablet/desktop            |
| C2   | `/djam` and `/orchestre-des-reveurs`: body empty/populated; both anchors on each page                            | Full-row mobile links; ordinary desktop widths; long translations and unbroken tokens                                 |
| C3   | Each actually active architecture wrapper and available Stage direct anchor                                      | Measure wrapper, child link and parent separately; retain ordinary height and real target area                        |
| C4   | All applicable consumers at 100/150/200% reflow, plus one/two/three-card rows                                    | Painted boxes inside parents, safe wrapping, no page or scrollframe horizontal overflow                               |
| I1   | Each of the six #92 links, Stage link and representative `p > a` wrapper                                         | Keyboard traversal, focus/focus-visible, visited, hover, active, forced colors, accessible name and exact destination |
| I2   | `.btn--cta` and historical Webform controls from #95; negative account/message/header/background/tunnel controls | No interaction regression; no PHP warning/error, browser page error or console error                                  |

Only that combined pass can lift the draft gate. Terminal 3 / #94 owns the
runtime resources; this task does not launch them automatically after #94.
This PR is not to be merged by this phase.
