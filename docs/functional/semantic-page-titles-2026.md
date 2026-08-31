# Semantic page titles — 2026

## Scope and validated state

This change assigns exactly one semantic H1 source to normal public routes
rendered by `unisonges_theme`, while preserving the existing visible hero titles.
It was rebased onto `origin/release/prod` at
`fe1e915dfcb8b2ad502642c495c94fd52d08b319` after PRs #78, #80, #81, and #83
merged.

The final source was exercised on 2026-09-01 in the disposable local DDEV site.
Drupal configuration entities and fixtures were created through Drupal APIs; no
full or partial configuration import, raw SQL write, VPS access, external form
submission, PayPal call, or real payment was used. All screenshots and
machine-readable runtime evidence were written under
`/tmp/pr84-semantic-page-titles-runtime-20260901` and are not repository assets.

No public URL, page shell, system-messages block, navigation, background,
scrollframe, Contact-owned file, reservation or Commerce business logic, or
Forum/Blog source configuration is changed by this PR.

## Root cause

The active `unisonges_theme_page_title` block is a Core `page_title_block` placed
globally in `content`; Core renders its title as an H1. Nodes 6–10 also use
`includes/_hero.html.twig`, which renders another H1. The duplicate semantic
title therefore affected these canonical routes and their internal paths:

- `/concerts` and `/node/6`;
- `/contact` and `/node/7`;
- `/reserver` and `/node/8`;
- `/orchestre-des-reveurs` and `/node/9`;
- `/djam` and `/node/10`.

The legacy rule `.path-node .block-page-title-block { display: none !important; }`
only hides the block visually. It does not remove the duplicate H1 from the DOM
on hero nodes, and it hides the only visible title on node templates that do not
render a hero.

`page.html.twig` and `page--front.html.twig` do not create headings; they render
`page.content`. Of the node-specific content templates, only nodes 6–10 include
the H1 hero. Other node routes and non-node routes depend on the global
page-title block.

## Semantic strategy

The global page-title block remains enabled. Four narrow behaviors divide H1
ownership without duplicating page content:

1. A negative `request_path` condition prevents the page-title block from being
   built on only the five hero aliases and `/node/6` through `/node/10`. Their
   unchanged `_hero.html.twig` title is the sole visible H1.
2. The instance-specific
   `block--unisonges-theme-page-title.html.twig` override preserves the block's
   attributes, contextual placeholders, optional label, and content wrapper,
   but replaces the outer `block-page-title-block` class with
   `unisonges-page-title-block`. The legacy CSS selector can no longer hide the
   only title on generic node routes.
3. NID-specific hero template suggestions are removed outside `full` view mode.
   Teasers, search results, and View rows for nodes 6–10 therefore cannot emit a
   page-level H1 or repeat the full hero/body/actions.
4. The override renders the Core title once. If Core supplies an empty title—as
   the `/node` frontpage View does when reached outside the configured front
   route—it emits one translatable, visible `Content` H1 instead of an empty
   heading. Non-empty route and entity titles pass through unchanged.

Both aliases and internal paths are listed so canonical and direct access use
the same source. The `system` dependency records the provider of the
`request_path` condition. The NID coupling is historical: changing any of these
five alias/NID mappings or adding another `_hero` consumer requires updating the
visibility list and non-full suggestion guard together. The exclusions also
assume those five hero nodes remain published public pages; if a hero is removed,
unpublished, or access-restricted, remove its alias/internal-path exclusions in
the same deployment so an error response keeps the global title.

The obsolete CSS selector is intentionally untouched because `styles.css` is
outside this PR. Semantic correctness does not depend on removing it.

## Server-rendered DOM matrix

The applied configuration used controlled Basic page fixtures at nodes 1–16,
including the required hero nodes 6–10, plus reversible Stage, Concert, Article,
Forum topic, Commerce, cart, and member fixtures. The existing targeted PR #80
installer was used only for the local Forum/Blog runtime state.

The final anonymous server-response matrix covered 51 routes. Each response
contained one non-empty H1, one visible H1, one `main#main-content`, no H1 in the
header/messages/View rows, no duplicate DOM IDs, and the expected title source.
Two additional empty-state captures for `/blog` and `/forum` also passed; the
table includes supplemental authenticated states from the browser matrix.

| Routes or state | Sole H1 source and observed result |
| --- | --- |
| `/concerts`, `/contact`, `/reserver`, `/orchestre-des-reveurs`, `/djam` | One visible hero H1; page-title block wrapper absent from the DOM |
| `/node/6`, `/node/7`, `/node/8`, `/node/9`, `/node/10` | Same one hero H1 and no page-title block wrapper |
| `/accueil`, `/cours`, `/cours-et-stages`, `/stages`, `/ateliers`, `/a-propos`, `/association`, `/les-artistes-de-l-asso`, `/origine` | One visible page-title-block H1; `/ateliers` is “Projets collectifs” |
| `/blog`, `/forum`, empty and populated | One page-title-block H1; blocks, empty states, and teaser rows add no H1 |
| Stage fixture alias and `/node/17`; Concert fixture alias and `/node/18` | One page-title-block H1 on each full canonical response |
| Published Article alias and `/node/19` | One page-title-block H1 |
| Unpublished Article alias and `/node/20` as anonymous/member | Protected with HTTP 403 and one “Access denied” H1; administrator canonical response has one content-title H1 |
| Published Forum topic alias and `/node/21` | One page-title-block H1 |
| Unpublished Forum topic alias and `/node/22` as anonymous/member | Protected with HTTP 403 and one “Access denied” H1; administrator canonical response has one content-title H1 |
| `/reservation-cours` | One “Réserver un cours” page-title-block H1; reservation form remains present |
| `/user/login`, `/user/register`, `/user`, `/user/password` | One visible route/account H1 in every response |
| Baseline product `/product/1`; Stage and Concert ticket products `/product/27`, `/product/28` | One page-title-block H1; add-to-cart control remains present |
| `/cart`, empty and authenticated non-empty | One “Shopping cart” H1; cart form remains present |
| `/checkout/1` anonymous | Protected with HTTP 403 and one “Access denied” H1 |
| `/checkout/1` and `/checkout/1/order_information` as the fixture member | One “Order information” H1 and one checkout form |
| `/form/contact`, `/webform/contact` | One “Contact” H1 and one Webform |
| `/form/cours-particuliers-reservation`, `/webform/cours_particuliers_reservation` | One “Réservation cours particuliers” H1 and one Webform |
| Forum proposal embedded on `/forum` | The asynchronous block appears for the member and adds no H1 |
| Direct `/form/forum-blog-proposal` | Page-disabled behavior preserved: HTTP 404 with one “Page not found” H1 |
| `/search/node`; `/search/node?keys=Concerts` with a hero-node result | One search page H1; result rows add no H1 |
| `/node` | One visible fallback “Content” H1 from the global block |

A direct Drupal render probe covered nodes 6–10 plus representative Stage,
Concert, Article, and `forum_topic` entities in `full`, `teaser`, and
`search_result` modes: all 27 combinations matched their expected count. Only
the five full hero renders contained an entity-level H1; every teaser and search
result contained zero H1 elements.

## Browser and accessibility results

Real Chromium 140.0.7339.16 was driven through Playwright. Fourteen
authenticated server/DOM states covered member and administrator access,
including non-empty cart/checkout, Forum proposal injection, unpublished
Article/Forum protection, and administrator canonical access.

Eight representative families—hero, generic, account, reservation, Commerce,
Webform, Forum, and Blog—were tested in 72 reflow cases:

- desktop at 100%, 150%, and 200% effective reflow;
- tablet at 100%, 150%, and 200% effective reflow;
- mobile at 100%, 150%, and 200% effective reflow.

Every case exposed exactly one level-1 heading in Chromium's accessibility tree,
and its accessible name matched the one visible DOM H1. Each also retained the
single main landmark, working skip-link target, fixed header, scrollframe,
accented “Réserver” and “Créer un compte” copy, functional drawer, correct focus
order, and zero horizontal overflow or duplicate IDs. Seventy-seven screenshots
were captured, including invalid-form states and all reflow cases.

The active front page `/accueil` retained its single `main#main-content`, and the
skip link continued to target it. An invalid-login response confirmed one global
messages region, before the page-title block, with no H1 inside the message.
Representative visual review retained the BGFX/background treatment and hero
layout. Header, drawer, and navigation sources—including submenu behavior—are
unchanged by the exact file guard.

Invalid local submissions were exercised for Contact, course-reservation
Webform, the embedded Forum proposal, and `/reservation-cours`. Each displayed
validation errors without adding another H1; no submission was completed. The
Commerce product, non-empty cart, checkout entry, and checkout-step controls
remained present. Chromium recorded no PR-caused console error, page error,
request failure, HTTP 5xx, or PayPal request. DDEV's web log contained no PHP
warning or fatal error caused by a rendered page.

## Cache results

After an explicit cache rebuild before each pair, `/concerts` and `/cours` were
loaded cold and warm as anonymous, authenticated member, and administrator: six
route/state pairs and twelve responses. The H1 count, accessible name, visibility,
and source were identical in both phases:

- `/concerts`: one hero H1 and no page-title block;
- `/cours`: one page-title-block H1 and no hero H1.

This verifies that route and user cache contexts do not leak page-title block
visibility between hero and generic routes.

## Runtime-discovered correction

The first complete raw response run found one issue: `/node` was available but
Core's `view.frontpage.page_1` title callback supplied an empty title because the
fixture front page was `/accueil`. The global block therefore rendered an empty
H1. The block override was corrected within the existing four-file scope to
render the Core content once and use the translatable `Content` fallback only
when it is text-empty. The entire server DOM, render-mode, browser,
accessibility, validation, Commerce, and cache matrix passed with that exact
source loaded.

## Cleanup and restoration proof

Before testing, the named DDEV snapshot
`pr84-semantic-page-titles-baseline-20260901` and a public-files archive were
created. The baseline recorded the database, active configuration, public
files, themes, front page, nodes, aliases, menu links, users, and main checkout.

Cleanup verified fixture UUIDs/paths before using Drupal entity APIs to remove
all 22 fixture nodes and revisions, 22 fixture aliases, 3 fixture menu links,
2 generated ticket products and variations, the fixture cart/order and item,
and the fixture member. Fixture submissions and comments remained zero. The
targeted Forum/Blog installer then rolled back its 14 test configuration
entities, and the named snapshot was restored.

The restored state matches every baseline fingerprint:

| Baseline guard | Restored value |
| --- | --- |
| Normalized database SHA-256 | `aca01feb9ea9bfc8cfefd703b27b33ed41f7021e995ca31cadc69df3d4dcb979` |
| Active-config SHA-256 (314 names) | `e96a6b849b5e15c6e16fde5b6494a9e57fe9f7161dd8398c819963ddfdfc2127` |
| Public-files SHA-256 | `3e414f9bd88e393d0ceb2a57c010938bea83815e655b111b9d359f401280c6a6` |

The restored database has zero nodes, zero content menu links, zero orders and
order items, zero comments and Webform submissions, the original 16 aliases,
4 products/variations, and 7 users. The original Olivero default theme, Claro
admin theme, and `/node` front page are restored. The main serving checkout is
clean on `release/prod` at
`fe1e915dfcb8b2ad502642c495c94fd52d08b319`; DDEV is stopped and runtime
ownership is released.

## Changed-file allowlist

Only these files belong to this change:

- `docs/functional/semantic-page-titles-2026.md`;
- `drupal/config/sync/block.block.unisonges_theme_page_title.yml`;
- `drupal/web/themes/custom/unisonges_theme/templates/block/block--unisonges-theme-page-title.html.twig`;
- `drupal/web/themes/custom/unisonges_theme/unisonges_theme.theme`.

## Final static checks

The final diff is checked for PHP syntax, strict YAML parsing, all custom Twig
templates, include targets, H1 sources, route/source assertions, whitespace,
the exact four-file allowlist, open-PR filename overlap, credentials/secrets,
and repository diagnostics. The source audit confirms:

- `_hero.html.twig` owns hero H1 output, and the page-title block override's
  literal H1 is limited to its text-empty Core-title fallback;
- all five `_hero` consumers are covered by alias and internal-path block
  exclusions;
- the five NID suggestions are unavailable outside `full` view mode;
- all non-hero routes retain the global page-title H1;
- page content, messages, navigation, and dynamic blocks are rendered once;
- no route in the validated matrix has zero or two H1 elements.

The exact runtime evidence is intentionally local under `/tmp`; only this
reproducible procedure and its results are committed.
