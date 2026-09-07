# Sitemap and robots policy 2026

Status date: 2026-08-31.

This document prepares a narrow public-indexing policy for the Drupal site. It
does not report current production state. This change was prepared without
DDEV, Docker, Drush, Chromium, Mailpit, or VPS access, and it neither applies
active configuration nor regenerates a sitemap.

PR #78 and PR #80 are now merged into `release/prod`, and this branch has been
rebased onto both merge commits. The policy must remain in draft until the
runtime matrix below passes after PR #81 releases its exclusive ownership of
DDEV and the other runtime tools. It must not be merged or applied to
production before then.

## Scope and concurrency boundary

This work is based on `origin/release/prod` at
`625c613dca22301b04a3f1bdc3c93db961fe9132`.

- PR #80 merged as `233896619e6f74904927fbb62073a00962881069`.
  Its tracked Forum/Blog bundle, Views, blocks, private Webform, access hooks,
  apply helpers, and functional document are now present. Blog uses the
  existing `article` bundle. `forum_topic` defaults to unpublished.
- PR #78 merged as `625c613dca22301b04a3f1bdc3c93db961fe9132`.
  Its targeted content/menu helper prepares the `/blog` and `/forum` Basic
  pages and aliases and retains `/ateliers` as the canonical path for the
  “Projets collectifs” hub.
- Git ancestry checks confirm both merge commits are ancestors of the base.
  This policy still edits no PR #78 or PR #80 file.
- PR #81 exclusively owns DDEV, Docker, Drush, Chromium, and Mailpit during
  this static refresh. Its three files do not overlap this policy, and no
  runtime or VPS access occurred here.

## Repository audit

### Simple XML Sitemap

The repository locks Simple XML Sitemap 4.2.3. Before this policy:

- `simple_sitemap.sitemap.default` was the only enabled sitemap; the sitemap
  index remained disabled;
- the default sitemap type enabled `custom`, `entity`,
  `entity_menu_link_content`, and `arbitrary` generators;
- `enabled_entity_types` listed `node`, `taxonomy_term`, and
  `menu_link_content`;
- no `simple_sitemap.bundle_settings.*` object was tracked;
- the only custom link was `/`, with a daily change frequency.

In Simple XML Sitemap 4.2.3 an absent bundle object defaults to
`index: false`. The tracked policy therefore represented only the custom root
link. The XML itself and per-entity overrides are runtime database state, not
tracked configuration. An older production audit saw an XML sitemap containing
only the root URL, but that historical observation is not treated as the
current runtime result.

`enabled_entity_types` controls support and form integration; it is not a safe
generation allowlist by itself. Active bundle settings and rows in
`simple_sitemap_entity_overrides` can still affect output and must be inspected
before any targeted apply. This policy permits zero override rows: every
per-entity override is untracked drift and blocks the run instead of being
silently preserved or deleted.

The upstream 4.2.3 entity generator enumerates configured entities and then
checks view access as the anonymous user. That normally removes unpublished
Drupal nodes, but node-access grants and hooks are runtime behavior. Published
versus unpublished cases therefore remain mandatory tests.

### Front page and aliases

Tracked `system.site:page.front` is `/accueil`. The previous public audit saw
`/` redirect to `/accueil`, so this policy uses the final alias `/accueil` and
removes `/` from sitemap input.

There is a separate runtime risk: the tracked front-page Metatag default uses
`[site:url]`. It may emit a root canonical even when `/accueil` is the final
HTTP URL. This PR cannot change Metatag configuration. A disagreement between
the final URL, canonical tag, and sitemap URL blocks promotion of this draft.

Path aliases are content entities and are not exported in `config/sync`.
Tracked Stage and Concert Pathauto patterns do not prove that active aliases
exist. A historical Stage response used a numeric `/node/...` canonical. The
targeted diagnostic must therefore refuse a required static alias that is
missing, duplicated, unpublished, inaccessible anonymously, or not canonical.
Runtime verification must reject numeric canonical URLs for every dynamically
included node.

### Routes and content types

Tracked editorial node bundles are now `page`, `article`, `stage`, `concert`,
and `forum_topic`. The current public architecture describes the following
stable aliases without depending on numeric node IDs:

- `/accueil`;
- `/cours-et-stages`, `/cours`, `/cours/didgeridoo`, `/cours/guimbarde`, and
  `/cours/meditation-improvisation`;
- `/stages`, `/stages/didgeridoo`,
  `/stages/musique-improvisee-meditation`, and `/stages/speciaux`;
- `/concerts`;
- `/ateliers`, `/djam`, and `/orchestre-des-reveurs`;
- `/a-propos`, `/association`, `/les-artistes-de-l-asso`, `/origine`, and
  `/services-prestations-artistiques`;
- `/contact`.

The merged PR #78 architecture keeps `/ateliers` as the canonical alias for the
“Projets collectifs” hub. Forum is its third child after D’Jam and Orchestre;
Blog is the fourth child of À propos after L’Asso, Partenaires, and Origine.
The Services menu link is disabled in place, while its public informational
page remains reachable from the hubs and remains eligible for the sitemap.

The merged PR #80 configuration makes Blog a block-only listing of published
`article` nodes on `/blog`, and Forum a block-only listing of published
`forum_topic` nodes on `/forum`. The bundle defaults to unpublished, the custom
access hook explicitly forbids unpublished topics to non-administrators, and
the View also filters `status=1`. Its proposal Webform has `page: false`, is
embedded only on `/forum` for authenticated users, grants no submission
view/update/delete access, and has no handler that creates public content.
These are tracked-source findings; active installation and sitemap behavior
remain part of the later PR #81-owned runtime phase.

### Deployment and configuration drift

`drupal/scripts/deploy-staging.sh` currently performs a fast-forward pull,
Composer install, database updates, and a cache rebuild. It does not apply sync
configuration or this sitemap policy. The older staging README still shows a
full config import workflow; that instruction must not be used. The current
config-drift document blocks both full and partial config imports until all
active drift has been classified.

The YAML files alone therefore cannot change the active site. The guarded
script in this change exists to compare and write only the exact sitemap keys
listed below after a reviewed dry-run. It never imports configuration and never
queues, purges, or generates a sitemap.

### Tracked Drupal robots file and Composer scaffold

`drupal/web/robots.txt` is the actual Drupal docroot file. `public/robots.txt`
belongs to the separate legacy static frontend and is intentionally untouched.

There is an operational caveat: Drupal Core 11.3.3 declares
`web/robots.txt` as a Composer scaffold file, and this project has no root
`file-mapping` override. Composer scaffold overwrites the destination by
default. Because `composer.json` and a separate append asset are outside this
PR's allowed files, the current deployment script will overwrite the tracked
policy during `composer install`. The post-rebase static audit reconfirmed the
same locked Core/Scaffold version, mapping, and deployment behavior; neither
merged prerequisite changed this caveat.

Until a separately approved Composer mapping is added, staging deployment must
restore the reviewed Git blob immediately after every standalone Composer
install. For the current deploy helper, do it immediately after the helper
returns and before HTTP tests. Confirm that `HEAD` is the exact reviewed
deployment SHA before restoring:

```bash
cd drupal
./scripts/deploy-staging.sh
test "$(git rev-parse HEAD)" = '<REVIEWED_DEPLOYMENT_SHA>'
git restore --source=HEAD -- web/robots.txt
git diff --exit-code HEAD -- web/robots.txt
unisonges_tracked_robots_blob="$(git rev-parse HEAD:drupal/web/robots.txt)"
unisonges_deployed_robots_blob="$(git hash-object web/robots.txt)"
test "${unisonges_deployed_robots_blob}" = "${unisonges_tracked_robots_blob}"
```

After a standalone `composer install`, run the same commands starting at the
reviewed-SHA assertion and `git restore`; do not wait for a later deployment
step to repair the scaffolded file.

This is a known promotion prerequisite, not authorization to edit the current
deployment script in this PR. Production deployment remains blocked until the
robots persistence mechanism is reviewed or the exact post-Composer restore is
part of the approved runbook.

## Proposed inclusion policy

### Static editorial pages

The `page` bundle remains excluded globally. The exact aliases above are
tracked as custom links instead. This avoids automatically indexing
transactional or historical Basic pages such as `/reserver`.

`/blog` and `/forum` are also present in the custom-link policy. Simple XML
Sitemap validates custom paths and omits a path that does not exist. The
targeted diagnostic treats both as a deferred pair while they are absent,
refuses a partial or ambiguous pair, and requires the same published,
anonymous-accessible, canonical Basic-page guarantees when the merged PR #78
content architecture is active. Source integration is complete; an absent
active pair may still be diagnosed as deferred while PR #80 is also inactive,
but cannot satisfy the runtime promotion gate. Active PR #80 configuration with
either page absent is blocking drift.

The custom-link priorities are deliberately simple: `/accueil` is `1.0`; every
other page is `0.5`; no speculative change frequency is asserted. Images are
not added from custom links.

### Dynamic editorial content

| Bundle | Policy | Rationale |
| --- | --- | --- |
| `article` | Include by bundle | Current editorial articles and published Blog articles. |
| `stage` | Include by bundle | Published, anonymous-accessible Stage detail content. |
| `concert` | Include by bundle | Published, anonymous-accessible Concert detail content. |
| `page` | Exclude by bundle | Only the explicit alias allowlist is eligible. |
| `forum_topic` | Conditional include | Its tracked source is merged; apply only when the exact bundle and all PR #80 publication/access guards are active, and runtime tests prove drafts are absent. |

The `forum_topic` Simple XML Sitemap config object remains owned by this policy;
no merged PR #80 file is copied or modified. Simple XML Sitemap bundle objects
do not declare Drupal config dependencies in their schema. The guarded script
therefore refuses to write this object until all fourteen merged PR #80 config
objects, the exact unpublished-default override, the hardened comment format,
and the three access hooks are active and match their tracked sources. An
absent active feature is deferred; a partially present or divergent guard is an
error, not an invitation to repair Forum/Blog from this PR.

All bundle settings use priority `0.5`, no change frequency, and no image
expansion. The type is restricted to the `custom` and `entity` generators, and
only `node` remains enabled for sitemap support. Taxonomy terms, menu links,
users, Commerce entities, Webform submissions, and other entity types are not
eligible.

Stage and Concert dates do not change sitemap eligibility. A past event that
remains published and publicly accessible is treated as an editorial archive
and remains included. Hub Views may separately hide past events. If an event
must leave the index, that requires an explicit editorial unpublish/archive or
redirect decision rather than an implicit date hook in this policy.

Dynamic entities must have exactly one non-numeric canonical PathAlias. A
published Stage, Concert, Article, or Forum Topic whose canonical remains
`/node/<id>`, is duplicated, or falls under any private/transactional route
family blocks generation until its alias policy is fixed in the owning scope.

The merged base still has no Pathauto pattern for `article` or `forum_topic`;
only Stage and Concert patterns are tracked. The PR #80 runtime evidence used
numeric `/node/{nid}` links for its temporary Blog and Forum fixtures. That was
valid for its feature tests but is intentionally a blocking result for this
sitemap policy: every published Blog Article and Forum Topic must receive a
reviewed non-numeric canonical alias before generation. Pathauto and public
route changes remain outside this PR.

## Explicit exclusion policy

The sitemap and robots policy exclude the following route families. Robots is
only crawl guidance; Drupal access control remains the confidentiality boundary.

| Surface | Decision |
| --- | --- |
| `/user` and account subroutes | Exclude profiles, login/registration helpers, order history, address book, and payment methods. |
| `/admin` | Exclude all administrative routes. |
| `/cart`, `/checkout`, order, payment, and `/commerce-paypal` routes | Exclude session, transactional, provider callback, return, cancellation, notification, and history URLs. |
| Webform direct pages, drafts, submissions, results | Exclude `/form`, `/webform`, and administrative results; retain explicit Webform CSS/JavaScript asset allows. |
| Private Forum/Blog proposal | Never include its direct Webform, drafts, confirmations, submissions, or administrative review routes. Only the authenticated block on `/forum` is intended. |
| Unpublished Forum Topics and other unpublished nodes | Never include; anonymous access and runtime tests must enforce this. |
| Reservation flow | Exclude `/reservation-cours`, `/reserver`, direct reservation Webform pages, parameters, and the Webform Booking `/get-days/`, `/get-slots/`, `/webform_booking/`, and `/webform-booking-calendar-data/` endpoint families. |
| Commerce products and variations | Exclude public/internal product, variation, order, checkout, and payment routes. Editorial Stage/Concert/Course pages remain the discovery surface. |
| Numeric node paths | Never emit `/node/<id>`; aliases and canonical consistency are mandatory. |
| Search, filters, comment reply and media utilities | Retain Drupal Core exclusions. |

The robots additions do not disallow `/modules`, `/themes`, `/sites`, or public
file paths. Existing Core/Profile asset allows remain, and the longer Webform
asset allows take precedence over the Webform route exclusion.

The canonical sitemap declaration is:

```text
Sitemap: https://unisonges.fr/sitemap.xml
```

## Guarded targeted diagnostic and apply

Run the script only from a reviewed, complete Drupal environment after the
merged PR #78/#80 installers and content prerequisites are active. PR #81 owns
that environment now, so none of these commands is authorized during this
static refresh. The script's default mode is read-only with respect to Drupal,
content, aliases, generated XML, and active configuration:

```bash
cd drupal
./scripts/apply-sitemap-policy-2026.sh \
  --site-uri=https://approved-test.example \
  --dry-run
```

The dry-run must print:

- the exact staged targets and deferred/present Forum/Blog state;
- every active sitemap variant, bundle setting, and entity override considered;
- alias, publication, anonymous-access, and canonical checks;
- a canonical active-policy SHA-256 fingerprint;
- a separate generated-sitemap-state fingerprint, used to prove that XML does
  not change during an invocation but not to forbid a reviewed later generation;
- the exact target objects and their create/update/no-op classification,
  without writing active configuration or generated XML.

Any unexpected variant, bundle setting, route target, bundle, alias, access
result, active/sync site identity, or relevant active-config shape is a blocker.
Any entity override row is also a blocker. Do not bypass a blocker with
`config:import`, a partial import, or an ad hoc `config:set`.

After review, the targeted apply requires the exact dry-run fingerprint and an
absolute, non-existing backup path. Use a path outside the repository and web
root:

```bash
./scripts/apply-sitemap-policy-2026.sh \
  --site-uri=https://approved-test.example \
  --apply \
  --expect-fingerprint='<DRY_RUN_SHA256>' \
  --backup-file=/approved/private/path/sitemap-policy-before.json
```

Use `CURRENT_FINGERPRINT`, not `GENERATED_FINGERPRINT`, for
`--expect-fingerprint`.

The explicit URI must be the origin bootstrapped by Drupal. If the reviewed
test checkout is below `/var/www`, the additional `--allow-vps` acknowledgement
is required; it is not production authorization. Apply and rollback also
require maintenance mode plus an exclusive writer/cron window established by
the separately approved runtime procedure.

The apply is eligible to write only:

- `simple_sitemap.settings:enabled_entity_types`;
- `simple_sitemap.type.default_hreflang:url_generators`;
- `simple_sitemap.custom_links.default:links`;
- the five tracked `default.node` bundle-setting objects, with
  `default.node.forum_topic` deferred until the merged PR #80 bundle and all
  publication/access guards are active and exact.

It does not modify content, aliases, access, Views, Webforms, Commerce,
generated sitemap tables, queues, or cron settings, and it never runs a cache
rebuild. Normal cache-tag invalidation caused by saving an allowlisted config
object may still occur. It does not run a full, partial, or targeted config
import. A matching target is a no-op.

The apply backup is mode `0600` and records only the allowlisted sitemap config
state necessary for exact rollback. It must never be committed. Preserve the
reported post-apply fingerprint with the approved runtime evidence.

Rollback uses the same script, the apply backup, and the current fingerprint:

```bash
./scripts/apply-sitemap-policy-2026.sh \
  --site-uri=https://approved-test.example \
  --rollback \
  --expect-fingerprint='<CURRENT_SHA256>' \
  --backup-file=/approved/private/path/sitemap-policy-before.json
```

Review that dry-run plan, then add `--apply` without changing its URI,
fingerprint, or backup path:

```bash
./scripts/apply-sitemap-policy-2026.sh \
  --site-uri=https://approved-test.example \
  --rollback \
  --apply \
  --expect-fingerprint='<CURRENT_SHA256>' \
  --backup-file=/approved/private/path/sitemap-policy-before.json
```

Rollback must restore the backup's original fingerprint exactly. If anything
outside the write allowlist changed between apply and rollback, stop and review
the drift rather than attempting a broad import.

This task does not run any of these Drush-backed commands and does not apply the
policy anywhere.

## Later runtime matrix

PR #81 exclusively owns DDEV and the runtime toolchain. This matrix is the next
phase only after that ownership is explicitly released; this rebase performs
none of it.

Capture commands, timestamps, environment identity, relevant fingerprints, and
redacted outputs in the PR before it leaves draft. Never include credentials or
private submission data.

Create all purpose-built content/alias fixtures before the baseline dry-run,
then freeze content, aliases, configuration writers, and cron through the
apply/generation/rollback sequence. The policy fingerprint intentionally
detects node or alias changes. If a fixture must change mid-sequence, restore
the exact baseline state and obtain a fresh reviewed fingerprint before
rollback; never weaken the guard.

### 1. Baseline before apply

- Capture the current active-policy fingerprint and complete diagnostic.
- Fetch the current `/sitemap.xml`; record HTTP status, content type, XML
  validity, generated timestamp if exposed, and the sorted `<loc>` set.
- Confirm the historical “root only” result rather than assuming it still
  applies.
- Capture `/robots.txt` and prove the exact Sitemap declaration state.
- Confirm no active config or generated XML changed during dry-run.

### 2. Front and static aliases

- Request `/` without following redirects and record status and `Location`.
- Request `/accueil`; require the intended 200 final URL.
- Compare the response canonical, final HTTP URL, and the one sitemap entry.
  Require one consistent canonical choice and no simultaneous `/` entry.
- For every custom alias, require exactly one PathAlias targeting one published
  `page` node, anonymous view access, HTTP 200 without an unexpected redirect,
  and a matching canonical URL.
- The PR #78 sources are merged. In the runtime under test, `/blog` and `/forum`
  must both pass these checks and both be present. If the merged installer has
  not yet made them active, the diagnostic may report both deferred, but the
  policy must not be applied or generated. If PR #80 is active while the pair
  is absent, the diagnostic must fail rather than defer it.

### 3. Publication and canonical matrix

Use purpose-built fixtures owned by the runtime test scope; do not expose real
private content.

| Case | Expected sitemap result |
| --- | --- |
| Published Basic page on the allowlist | Included by its exact alias. |
| Published Basic page outside the allowlist | Excluded. |
| Unpublished Basic page, even on a known path | Excluded. |
| Published Article | Included by non-numeric canonical alias. |
| Unpublished Article | Excluded. |
| Published Blog Article | Included and discoverable from `/blog`. |
| Published Stage with future dates | Included. |
| Published Stage with past dates | Included while it remains a public archive. |
| Unpublished Stage | Excluded. |
| Published Concert with future dates | Included. |
| Published Concert with past dates | Included while it remains a public archive. |
| Unpublished Concert | Excluded. |
| Published Forum Topic | Included by non-numeric canonical alias. |
| Unpublished Forum Topic | Excluded and inaccessible anonymously. |
| Any dynamic node with `/node/<id>` canonical | Blocking failure; do not generate/promote. |

For every included URL, require HTTP 200, no redirect chain, one self-consistent
canonical, and no query-string variant.

### 4. Private and transactional exclusions

Assert that the generated `<loc>` set contains none of:

- `/user` or account/order/payment-method descendants;
- `/admin` descendants;
- `/cart`, `/checkout`, order, payment, `/commerce-paypal`, return, cancel, or
  notify routes;
- `/form` or `/webform` pages, drafts, confirmations, submissions, or results;
- the private `forum_blog_proposal` direct, draft, submission, confirmation, or
  administration routes;
- `/reservation-cours`, `/reserver`, reservation step/query variants,
  `/get-days/`, `/get-slots/`, `/webform_booking/`, or
  `/webform-booking-calendar-data/` endpoints;
- Commerce product or product-variation internals;
- unpublished nodes;
- numeric `/node/<id>` paths.

Verify separately that the merged PR #80 proposal block remains
authenticated-only, its direct Webform is forbidden as designed, and no
submission data appears in public HTML or XML.

### 5. robots and assets

- Run Composer, restore the reviewed robots blob as documented, and prove it is
  byte-identical to the `robots.txt` blob at the reviewed `HEAD`.
- Fetch `/robots.txt` over HTTP and require exactly one canonical Sitemap line.
- In DDEV/non-production, validate the canonical production Sitemap declaration
  textually, but fetch and validate
  `<APPROVED_TEST_ORIGIN>/sitemap.xml`; do not turn this test into an
  unauthorized production request. Resolve and compare the declared production
  URL only in a separately authorized production verification window.
- Verify required CSS, JavaScript, images, public files, and Webform assets still
  return their intended status and are not blocked by the policy.
- Confirm account, admin, Commerce, Webform, reservation, product, and numeric
  node crawl exclusions for clean and `index.php` paths. Test each applicable
  route root in bare, trailing-slash, and `?query` forms, including all four
  Webform Booking endpoint prefixes.
- Remember that a robots exclusion is not an authorization test; repeat direct
  anonymous access checks for private routes.

### 6. Generation and idempotency

Only after the dry-run and targeted apply pass in the approved runtime scope:

```bash
./vendor/bin/drush -r web \
  --uri='<APPROVED_TEST_ORIGIN>' \
  simple-sitemap:generate
```

Record the sorted `<loc>` set and a normalized XML hash. Run the same generation
a second time with the same explicit URI and without intervening writes. Every
`<loc>` must use exactly that approved origin; no `http://default`, mixed host,
scheme, or port is acceptable. The second sorted URL set and
normalized hash must match the first. Investigate timestamps separately if the
serializer legitimately changes them; URL membership and canonical form must
remain identical.

This command is intentionally not called by the policy script and was not run
while preparing this PR.

### 7. Rollback proof

- Record the pre-apply fingerprint, apply backup checksum, and post-apply
  fingerprint.
- Exercise rollback in the approved non-production test environment.
- Require the restored fingerprint to equal the original pre-apply fingerprint.
- Prove the rollback did not alter content, aliases, Webforms, Commerce data,
  generated XML, or unrelated active configuration.
- Reapply with a fresh reviewed fingerprint/backup and confirm the target is
  idempotent before any production change window is considered.

## Promotion gates

The source-integration prerequisite is complete: PR #80 merge
`233896619e6f74904927fbb62073a00962881069` and PR #78 merge
`625c613dca22301b04a3f1bdc3c93db961fe9132` are ancestors of the rebased branch,
and the exact PR #82 file set overlaps neither merged change nor PR #81.

This draft remains blocked until all of the following are complete:

1. `/blog`, `/forum`, and `forum_topic` pass their active conditional guards.
2. Stage, Concert, Article, and Forum Topic aliases are non-numeric and tested
   for published/unpublished access.
3. The front redirect/canonical/sitemap decision is consistent at runtime.
4. Active Simple Sitemap bundle settings have no unknown drift and the entity
   override table has zero rows.
5. The Composer scaffold overwrite for `robots.txt` has an approved,
   reproducible deployment treatment.
6. After PR #81 releases DDEV, the complete runtime matrix, two generations,
   rollback, and fingerprint restoration pass in that approved environment.
7. A separate production change window explicitly authorizes any later active
   apply and generation. This PR itself performs neither.

## Static validation for this change

The implementation must pass, without DDEV, Docker, Drush, Chromium, or VPS
access:

- parse every changed YAML file;
- `bash -n` and ShellCheck for the guarded script;
- PHP lint for its embedded PHP program;
- config dependency and Simple XML Sitemap 4.2.3 schema-shape checks;
- repository-wide UUID uniqueness;
- exact changed-file allowlist guard, including no PR #78/#80/#81 filename;
- checks that no private/transactional custom route or numeric node ID entered
  sitemap config;
- checks that no import, sitemap generation, production command, or credential
  was added to executable code;
- robots asset-allow and canonical Sitemap declaration checks;
- `git diff --check` and a public-repository credential scan.

## Upstream references

- Simple XML Sitemap 4.2.3 source and schema:
  <https://git.drupalcode.org/project/simple_sitemap/tree/4.2.3>
- Drupal Composer Scaffold behavior and robots append/exclusion options:
  <https://github.com/drupal/core-composer-scaffold/tree/11.3.3>
