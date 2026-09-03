# Forum + Blog MVP (2026)

## Status and ownership boundary

This change implements the Drupal configuration for a small Blog and moderated
Forum MVP. It does not create the Basic pages or menu links for `/blog` and
`/forum`; those remain owned by the parallel content/menu change. The two Views
and the proposal form are blocks whose request-path visibility is limited to
those existing aliases.

Static preparation was followed by the complete local DDEV, Chromium and
Mailpit matrix on 2026-08-31. The tested checkout was rebased onto
`54562e22f4025b88ce2b755248db833151d1637b`; no VPS was accessed. All test
entities and local route fixtures were removed, the named baseline snapshot and
public files were restored, and DDEV was stopped and unlisted after validation.

No public View page route, path alias, main-menu item, external email handler,
theme global CSS, taxonomy discriminator, Article, comment, proposal submission,
or fixture is created by the implementation or deployment script. Applying the
Webform config does create exactly one Webform-owned internal serial-tracking
database row; that lifecycle row is not business content.

## Required audit

The audit is based on the repository `config/sync` snapshot. Active production
configuration was not queried, so active drift must be checked again on an
approved complete clone before applying anything.

| Area | Audited baseline | MVP decision |
| --- | --- | --- |
| Article | Existing, revisioned, submitted date enabled, body summary, image, tags, comments and teaser display | Reuse unchanged as the Blog post bundle |
| `field_tags` | Existing free-tag vocabulary reference with auto-create | Do not use as the Blog/Forum discriminator; no numeric term ID is used |
| Comments | Existing `comment` type; Article comments open; anonymous role cannot post | Reuse for Forum topics; restrict `comment_body` to sanitized `basic_html`; `anonymous: 0` disables anonymous contact data, not posting |
| Views | No Blog or Forum View; generic Views would mix bundles | Add two block-only Views with explicit bundle and `status=1` filters |
| Webform | Module/UI enabled; existing forms have unrelated behavior and handlers | Add one independent, handler-free, authenticated-only proposal queue |
| Anonymous role | Can read content/comments, cannot post comments | Read Blog, published topics and published comments only |
| Authenticated role | Can post and has `skip comment approval`; cannot create Article | Retain immediate comments consciously; do not add Node creation/publication permissions |
| Content editor role | Can create Article/Page but has no Forum permissions or comment administration | No change |
| Administrator role | `is_admin: true` | Sole reviewer/creator/publisher for Forum topics and proposal results |
| Registration | `register: visitors`, `verify_mail: true`, registration notice enabled | Apply preflight requires the same stored and effective policy |
| Editorial workflows | `content_moderation` and `workflows` are not enabled; no editorial workflow config exists | Use private proposals plus unpublished-by-default Forum topics |
| Config drift | Full and partial imports remain blocked by `docs/dev/config-import-drift.md` | Use the exact guarded entity apply script only |

### Implemented access boundary

The `unisonges_structure` module supplies the procedural access enforcement
used by this MVP:

- `hook_node_access()` explicitly denies every non-administrator access to an
  unpublished `forum_topic`, including its direct canonical page, and denies
  all revision view/revert/delete operations. Generic permissions such as
  `view own unpublished content` and `view all revisions` do not override the
  forbidden result.
- `hook_views_query_alter()` adds a published-only condition for
  `forum_topic` rows to every SQL View based on `node_field_data` for a
  non-administrator. This also protects generic and administrative Views, not
  just the feature listing.
- `hook_entity_field_access()` explicitly forbids members from editing
  `field_seances_restantes`, `field_pack_expire_le`, and
  `field_essai_utilise`; only accounts with `administer users` may edit them.

The apply preflight verifies that the module and access hooks are registered
and that a synthetic authenticated member receives an explicit forbidden
result for each lesson-credit field. This is the implemented server-side fix,
so there is no separate account-credit launch blocker. Reservation, Commerce,
and credit-calculation business logic remain outside this change.

## Implemented design

### Blog

`views.view.blog_posts` is a block-only View over `node_field_data`:

- bundle is exactly `article`;
- publication status is exactly `1`;
- created date is sorted descending;
- the standard Article teaser supplies linked title, submitted date, body
  summary, and canonical content link;
- tagged Views caching and node-grant/permission cache contexts are retained;
- empty output is `Aucun article publié pour le moment.`.

`block.block.unisonges_blog_posts` places that View only in the custom theme's
content region when the request path is exactly `/blog`. There is no View page
display and no new path.

### Forum topics

`forum_topic` is a dedicated content type. It reuses the existing `body` and
`comment` field storage, avoiding a taxonomy ID or an Article-category access
boundary. Its important defaults are:

- new revisions enabled;
- `status=0` by bundle-specific base-field override;
- `promote=0` by bundle-specific base-field override;
- required body limited to `basic_html`;
- comments open and threaded; `anonymous: 0` means the comment field does not
  collect an anonymous name, email address, or homepage;
- title, submitted date, summary and canonical link in teaser rendering.

Anonymous posting is independently prohibited by the anonymous role's lack of
`post comments`; the field's `anonymous: 0` setting is not an authorization
boundary. Authenticated members retain `post comments` and
`skip comment approval`.

No non-administrator role receives a `forum_topic` create/edit/publish
permission. The deploy guard checks every non-admin role and rejects both
bundle-specific grants and broad Node or Webform privileges, including Node
administration/access bypass and Webform/submission administration or
view/edit/delete grants. It never rewrites a role.

`views.view.forum_topics` is block-only, filters exactly `forum_topic` and
`status=1`, sorts newest first, and retains node-access SQL rewriting and cache
contexts. Its empty output is
`Aucun sujet de discussion publié pour le moment.`.

`block.block.unisonges_forum_topics` places the listing only on `/forum`.
The explicit `unisonges_structure` access hooks additionally deny unpublished
topics and revisions to every non-administrator, including direct requests,
and remove unpublished Forum rows from generic node Views. Unpublished topics
therefore fail closed beyond this feature View's own `status=1` filter.

### Proposal queue

`webform.webform.forum_blog_proposal` accepts exactly three proposal types:

- idea;
- discussion topic;
- Blog Article theme.

The form contains only a required type, title and plain textarea description.
It records the authenticated submission owner, disables remote-IP storage, and
has no email, remote, or content-creation handler. A submission never creates a
Node and never becomes public.

The Webform page setting is disabled. Its block is limited to `/forum` and the
authenticated role. Webform create access also names only `authenticated`;
view/update/delete/purge access for both arbitrary and own submissions is empty.
Administrators retain access through their `is_admin` role. Anonymous requests
cannot submit even if block visibility were bypassed.

With `page: false`, a direct request to
`/webform/forum_blog_proposal` must return `403` for both anonymous visitors and
ordinary members, without rendering the proposal form. The path-alias guard
uses the underscore internal sources under `/webform/forum_blog_proposal` and
requires that neither those sources nor the default
`/form/forum-blog-proposal` aliases (including confirmation, submissions, and
drafts suffixes) are occupied by a PathAlias entity. It does not claim that
every `/webform/...` router path is absent: the confirmation route may be
reachable by a member without exposing the form or submissions. A pre-existing
Redirect entity affecting `/form/forum-blog-proposal` is outside this feature's
ownership and is never created, changed, or deleted by the helper.

There is deliberately no notification handler. Administrators must review the
private queue explicitly; no external mail and no Mailpit check are needed for
proposal delivery.

## Exact moderation behavior

1. A verified member signs in and submits the proposal form embedded on
   `/forum`.
2. The submission remains private in Webform storage. It does not create an
   Article or Forum topic.
3. An administrator reviews submissions at
   `/admin/structure/webform/manage/forum_blog_proposal/results/submissions`.
4. For an accepted discussion, the administrator creates a `forum_topic` at
   `/node/add/forum_topic`. The bundle defaults to unpublished and unpromoted.
5. Publication requires the administrator to select the Published control and
   save. Only then can the Forum View and anonymous Node access expose it.
6. For an accepted Blog theme, the administrator follows the existing Article
   editorial process. The proposal itself never creates or publishes Article
   content.
7. Authenticated comments are published immediately because the existing role
   keeps `skip comment approval`. Anonymous visitors cannot post.
8. Administrators can unpublish or delete comments at `/admin/content/comment`
   and can unpublish the topic itself. Unpublishing a topic removes it from the
   listing and makes its canonical page unavailable to ordinary visitors.

Immediate member comments are an explicit MVP tradeoff, not an inherited
accident. It provides low-friction discussion but requires administrator
reaction rather than pre-publication review. A later move to queued comments
must be a separate global moderation decision because `skip comment approval`
currently affects Article comments too.

The existing default comment body previously allowed every format available to
the author. This change limits it to sanitized `basic_html`, so the
Webform-internal unfiltered format cannot be chosen for Article or Forum
comments. The implementation changes the FieldConfig's `allowed_formats` and
saves the entity so Drupal recalculates the `filter.format.basic_html` config
dependency. Existing comments are not rewritten. Install refuses to proceed if
any comment is already stored with `webform_default`, regardless of whether
that comment is published, unpublished, or otherwise pending moderation; an
administrator must review it manually.

## Targeted deployment

### Repository deployment automation

Merging this change does **not** activate the new configuration through any
tracked deployment automation. `scripts/deploy-staging.sh` performs the Git and
Composer update, database updates, and cache rebuild, but contains neither a
config import nor this targeted installer. `infra/README-staging.md` documents
config import as a separate operator action, and no tracked GitHub workflow or
Composer deploy hook runs the helper. The tracked configuration files therefore
arrive with the code, but an operator must run the reviewed dry-run/apply path
below. Unversioned infrastructure outside this repository was not inspected.

### Preconditions

- Use a reviewed commit from this repository; the wrapper rejects untracked or
  modified target files.
- Deploy the parallel content/menu change first. `/blog` and `/forum` must each
  resolve through exactly one alias to a distinct published Basic page.
- Use a complete, representative database. The wrapper fixes Drush to
  `./vendor/bin/drush`, supplies an explicit `--root` and required `--uri` on
  every bootstrap, and unsets the corresponding `DRUSH_OPTIONS_ROOT` and
  `DRUSH_OPTIONS_URI` environment overrides. Those explicit CLI options take
  precedence over root/URI site-config defaults, and the wrapper accepts no
  Drush alias argument. It also verifies
  that `composer.lock` pins Drupal `11.3.3`, Webform `6.3.0-beta7`, and Drush
  `13.7.1`; the bootstrapped helper requires PHP `8.3` and those exact runtime
  versions.
- Supply `--site-uri` explicitly on every wrapper invocation. It must be the
  independently approved absolute `http`/`https` site origin, with no user info,
  non-root path, query, or fragment. The example host below is a placeholder to
  replace; the option selects an existing site and creates no route or path.
- Deploy the reviewed `unisonges_structure` access hooks, enable maintenance
  mode before any preflight/dry-run/apply command, and rebuild caches before the
  first dry-run so Drupal registers the procedural hooks.
- Establish an exclusive window before the first dry-run: stop scheduled cron
  and queue workers and prohibit every administrator UI, other CLI, config, and
  content write through the post-operation dry-run. Maintenance mode and the
  two locks do not block privileged writers.
- Review drift with Drush's read-only `config:status` using the same explicit
  root and site URI; do not run `cim`, including `--partial`. The existing
  `diagnose-config-drift.sh` accepts no URI and is therefore not authoritative
  for a multisite bootstrap, so it is intentionally absent from this sequence.
- Record a current database backup or disposable local snapshot.
- On every non-verified-DDEV host, independently verify hostname, the exact URI,
  database, and checkout before invoking the wrapper. A staging checkout under
  `/var/www` additionally requires `--allow-vps`; that flag is an operator
  acknowledgement, not proof that the host is staging and never authorizes
  production access.

### Install commands

From the Drupal project directory:

```bash
SITE_URI='https://approved-host.example'
# Replace the placeholder with the independently approved existing site origin.
./vendor/bin/drush --root="${PWD}/web" --uri="${SITE_URI}" maint:set 1
# Required immediately after code deployment so procedural hooks register.
./vendor/bin/drush --root="${PWD}/web" --uri="${SITE_URI}" cache:rebuild
# Stop cron/queue workers and enforce the exclusive admin/CLI write freeze now.
./vendor/bin/drush --root="${PWD}/web" --uri="${SITE_URI}" status --fields=bootstrap,db-status,uri
./vendor/bin/drush --root="${PWD}/web" --uri="${SITE_URI}" config:status

./scripts/apply-forum-blog-mvp-2026.sh --site-uri="${SITE_URI}" --dry-run
# Review every prerequisite, source hash, target state and PLAN SHA-256.
# Take and record the database backup/snapshot.
./scripts/apply-forum-blog-mvp-2026.sh --site-uri="${SITE_URI}" --apply --backup-confirmed
./vendor/bin/drush --root="${PWD}/web" --uri="${SITE_URI}" cache:rebuild
./scripts/apply-forum-blog-mvp-2026.sh --site-uri="${SITE_URI}" --dry-run
./vendor/bin/drush --root="${PWD}/web" --uri="${SITE_URI}" maint:set 0
./vendor/bin/drush --root="${PWD}/web" --uri="${SITE_URI}" cache:rebuild
```

For an approved staging checkout under `/var/www`, add `--allow-vps` to every
apply-script invocation. Do not add this script to `deploy-staging.sh`:
application must remain a separately reviewed manual step after `updb` and
before runtime acceptance. If any guard or apply step fails, leave maintenance
mode enabled and investigate; disable it only after the post-apply dry-run is an
exact `MATCH`/`NOOP` result. Resume scheduled workers and privileged writers
only after maintenance is disabled and the final cache rebuild succeeds.

The script creates only the 14 named Forum/Blog config entities through their
Drupal config-entity storage APIs. In a separate transaction it updates the
existing comment FieldConfig's `allowed_formats`, saves the FieldConfig, and
requires Drupal's recalculated `filter.format.basic_html` dependency to match
the reviewed target. No business content, role, registration setting, menu,
alias, Basic page, public route, or non-allowlisted config is written. The one
feature-owned runtime side effect is Webform's expected internal serial-tracking
row for `forum_blog_proposal`; exactly one such row must exist after install.
Drupal's config-entity lifecycle can also maintain core internal metadata such
as the `entity.definitions.bundle_field_map` key-value entry. Those records are
implementation metadata, not business content or feature-owned submissions.

Compatibility with the later editorial-home feature is fail-closed. The synced
Blog View can contain its third `editorial_home` display, but this helper derives
and creates the original two-display Forum/Blog baseline whenever editorial-home
is absent. When the editorial module, block and rollback state are all active,
an install recheck accepts only the exact three-display variant. Partial
editorial ownership is refused, so a fresh Forum/Blog install cannot create half
of the homepage feature.

A write-mode install or rollback holds both the custom
`unisonges_forum_blog_mvp_config` lock and core's persistent `config_importer`
lock. Both use a one-hour TTL and are renewed immediately before each applicable
write phase: comment hardening on install, then the feature transaction. The
helper also refuses a nonstandard active-config backend: core `CachedStorage`
must wrap the default `DatabaseStorage` on this site connection and its `config`
table, which is the same storage read by the transactional snapshots. The
comment-hardening transaction is separate and deliberately remains committed if
the later feature apply fails. The 14 feature entities are created or removed
in a second, atomic database transaction. Before commit, the helper performs
exact comparisons in the default config collection and every collection
reported by active config storage; any mismatch rolls back the complete feature
transaction. Maintenance mode is still mandatory because neither lock prevents
privileged administrative, CLI, config, content, cron, or queue writes. The
narrow rechecks reduce races but do not replace the required exclusive window;
if any external write occurs, abandon the plan and restart from a reviewed
dry-run.

### Rollback

Rollback is dry-run first:

If editorial-home is active, execute and verify its own exact rollback first.
The Forum/Blog rollback deliberately refuses the reverse order because the
homepage block depends on `views.view.blog_posts`; after the editorial rollback,
this helper recognizes the restored two-display baseline and can remove the 14
Forum/Blog entities normally.

```bash
SITE_URI='https://approved-host.example'
# Replace the placeholder with the independently approved existing site origin.
./vendor/bin/drush --root="${PWD}/web" --uri="${SITE_URI}" maint:set 1
./vendor/bin/drush --root="${PWD}/web" --uri="${SITE_URI}" cache:rebuild
# Stop cron/queue workers and enforce the exclusive admin/CLI write freeze now.
./scripts/apply-forum-blog-mvp-2026.sh --site-uri="${SITE_URI}" --rollback --dry-run
# Review and take/record a database backup or snapshot.
./scripts/apply-forum-blog-mvp-2026.sh --site-uri="${SITE_URI}" --rollback --apply --backup-confirmed
./vendor/bin/drush --root="${PWD}/web" --uri="${SITE_URI}" cache:rebuild
./scripts/apply-forum-blog-mvp-2026.sh --site-uri="${SITE_URI}" --rollback --dry-run
```

If and only if rollback emitted the field-purge `NOTICE`, first review all site
cron effects. Then keep scheduled workers stopped and run one approved cron
manually; repeat this separate block only while the notice/feature entries
remain:

```bash
SITE_URI='https://approved-host.example'
./vendor/bin/drush --root="${PWD}/web" --uri="${SITE_URI}" cron
./scripts/apply-forum-blog-mvp-2026.sh --site-uri="${SITE_URI}" --rollback --dry-run
```

Require `DELETED FIELD STATE OK feature_entries=0`. Before any reinstall, make
the install preflight prove the same tombstone-free state:

```bash
SITE_URI='https://approved-host.example'
./scripts/apply-forum-blog-mvp-2026.sh --site-uri="${SITE_URI}" --dry-run
```

If no immediate reinstall follows, close the exclusive window only after the
post-rollback checks pass:

```bash
SITE_URI='https://approved-host.example'
./vendor/bin/drush --root="${PWD}/web" --uri="${SITE_URI}" maint:set 0
./vendor/bin/drush --root="${PWD}/web" --uri="${SITE_URI}" cache:rebuild
```

If reinstall follows immediately, keep maintenance and the exclusive write
freeze active instead of running the final two commands; resume writers only
after the reinstall's exact post-apply verification and final cache rebuild.

Rollback refuses to delete config while any `forum_topic` Node or proposal
submission exists. Export/review retained business records and obtain explicit
deletion approval before removing them through normal administrative workflows;
the script never deletes business content. It removes matching feature config
in reverse dependency order inside the atomic feature transaction and never
deletes Articles or rewrites/deletes existing comments. The comment FieldConfig
is outside rollback: whatever comment config exists when rollback starts must
remain exactly unchanged.

Deletion of the proposal Webform invokes its normal config-entity lifecycle.
The strict guards require zero submissions and require the Webform's internal
path aliases, state key, `webform_libraries` entry, user data, and files to be
absent before deletion. The successful Webform lifecycle therefore removes only
the single serial-tracking row; a collision or unexpected runtime artifact
blocks rollback instead of being deleted. FieldConfig deletion may separately
maintain core lifecycle metadata, including deleted-field state when shared
Article field storage still contains data, but it does not delete that Article
data. A successful rollback can therefore end with expected Forum body/comment
UUID tombstones and does not promise zero internal metadata immediately.

When rollback prints `NOTICE Core field-purge metadata remains`, keep
maintenance mode enabled, review the site's other cron work, and run the
approved cron/field-purge cycle. Repeat the rollback dry-run until it reports
`DELETED FIELD STATE OK feature_entries=0`. An install dry-run deliberately
blocks while either reviewed Forum FieldConfig UUID remains in
`field.field.deleted`; verify both are purged before any reinstall. Purging
feature tombstones does not require or imply removing unrelated internal
metadata.

## DDEV runtime validation record (2026-08-31)

### Isolation and local prerequisites

The exclusive test window used DDEV 1.25.3, PHP 8.3.31, Drupal 11.3.3,
Webform 6.3.0-beta7, Chromium/Playwright 1.55.0, MariaDB 10.11, and local
Mailpit 1.30.3. A database snapshot named
`pr80-forum-blog-runtime-baseline-20260831T0805Z` was taken before the first
local write. The baseline contained no Nodes, comments, or Webform submissions;
users were IDs 0–6, PathAlias IDs 1–16, and there were no content menu links.
The active-config inventory contained 314 items. The public-files baseline was
245 files/838,007 bytes with fingerprint
`3e414f9bd88e393d0ceb2a57c010938bea83815e655b111b9d359f401280c6a6`;
the preserved tar had SHA-256
`46edb97fa0b27cae731e8b6a5ea1b9fe062bbf20cbc7ca9835300f04654d350b`.

All missing prerequisites were local fixtures, created through Drupal entity or
config APIs after the snapshot: the custom default theme; the reviewed site
UUID/registration settings; the existing legacy comment field; the Language
module; and published Basic pages `Blog` (`/blog`, Node 37, PathAlias 17) and
`Forum` (`/forum`, Node 38, PathAlias 18). No menu fixture was created. The
local active date timezone had drifted to `Etc/UTC`, which Drupal 11 rejects as
the hidden registration timezone choice; it was set to the repository value
`Europe/Paris` only for the signup test. Every local prerequisite was later
removed by snapshot restoration.

### Installer, rollback, and guards

- The first successful dry-run was read-only: active config, entity IDs, and
  public-file fingerprints were identical before/after.
- The initial apply transaction revealed that the forum form-display entity is
  normalized when core Language is disabled. The feature transaction rolled
  back. The required-module preflight now includes `language`; after enabling
  that prerequisite, apply created exactly 14 allowlisted config entities.
- The post-apply dry-run reported all 14 entities `MATCH`; a second apply
  reported 14 `NOOP` results. No config import was used.
- Rollback dry-run/apply removed the 14 entities only, retained the hardened
  shared comment FieldConfig, removed the Webform tracking row, and reported
  `DELETED FIELD STATE OK feature_entries=0`. A clean dry-run/reinstall then
  recreated exactly the same 14 entities, and its post-apply dry-run again
  reported only `MATCH`.
- Reversible negative tests all failed closed: default-theme mismatch, missing
  `/blog` alias, missing Language module, a conflicting target label, changed
  target UUID metadata, forbidden authenticated `create article content`, and
  moved route alias. Each altered prerequisite was restored and the next
  dry-run returned `MATCH`.
- With three `forum_topic` Nodes and three private proposals present, rollback
  dry-run exited nonzero with
  `ROLLBACK CONTENT GUARD forum_topic_nodes=3 proposal_submissions=3` and made
  no change.
- After fixture deletion, final rollback dry-run plan
  `2b6db7db55d15a65cd3a5221b672cca3f79f1aecb332e2b8816e0b43f03c4925`
  passed, apply deleted exactly 14 entities, and the post-rollback dry-run
  reported 14 `MISSING`, zero content, zero Webform runtime artifacts, and
  `feature_entries=0` (plan
  `a6c468911debf3d2b23f4f7e9accaf8d6745e7e269fc4da7113052e0f57c48a8`).

### Blog and Forum behavior

- Empty anonymous `/blog` and `/forum` responses were `200` and rendered the
  exact useful empty states. With fixtures, `/blog` rendered only published
  Articles 40 then 39, proving newest-first order, and exposed linked title,
  submitted date, explicit summary, and canonical `/node/{nid}` links.
- Unpublished Article 41 was absent from the listing, RSS, search, and warmed
  caches. Anonymous canonical and JSON requests returned `403` without its
  title/body. Publishing through an entity save inserted it at the top without
  a manual cache rebuild; unpublishing removed it and restored direct `403`.
- `/forum` rendered only published topic 42 with title/date/summary/canonical
  link. Unpublished topic 43 was absent from the feature and generic Views;
  canonical, JSON, revision overview, and direct revision requests returned
  `403` for anonymous, an ordinary member, and the authoring `content_editor`
  account even though that role has generic revision permissions.
- The content editor saw `/admin/content` but not unpublished Forum rows on two
  warmed passes. `/admin/content/recent` does not exist in this installation
  (`404`) and exposed no content. RSS/search checks also contained no draft
  title or body.
- Publishing topic 43 made its listing and canonical page public immediately;
  unpublishing removed both without a manual cache rebuild. An administrator
  also created topic 44 through Chromium: Published was unchecked by default,
  the author widget was absent, explicit publish exposed it, and unpublish
  returned its canonical page to `403`.

### Proposals, comments, permissions, and registration

- Anonymous and member direct requests to `/webform/forum_blog_proposal`
  returned `403`; only the authenticated `/forum` block exposed the form. The
  form included build and CSRF tokens, produced three server-side required-field
  validation errors, and missing-token posts created nothing.
- Verified member 7 submitted `idea`, `discussion_topic`, and `article_theme`.
  The three submissions retained owner 7, stored no remote address, created no
  Node, had no handler, and were inaccessible to the member for view/update/
  delete. The confirmation route exposed neither form nor submission data. An
  administrator reviewed the private values in the Webform results UI.
- Anonymous lacked comment form/route/POST access. Member 7 posted on published
  topic 42 and the comment was public immediately (`status=1`) because the
  existing authenticated role deliberately retains `skip comment approval`.
  The widget had only hidden `basic_html`; a forged `webform_default` value was
  stored as `basic_html`, not honored. Administrator Chromium forms successfully
  unpublished and deleted the test comment.
- Anonymous/authenticated/content-editor permission inspection found no
  Article grant on authenticated, no Forum create/edit/publish grant on any
  non-admin, no Node administration/access bypass, and no proposal
  administration grant. Administrator remains `is_admin`. The authenticated
  role already has Webform's global `use text format webform_default`
  permission; this PR adds no text-format permission, and the shared comment
  FieldConfig plus tested server-side normalization prevents that unfiltered
  format from being used for Article or Forum comments.
- The three lesson-credit widgets were absent from member self-edit. A CSRF-
  valid forged POST for all three fields left all stored values empty.
- With stored/effective registration temporarily set to `visitors` and
  `verify_mail=true`, anonymous Chromium registration created active user 11,
  Mailpit alone received its verification message, and Chromium completed the
  one-time login/password flow. No external SMTP was used. The proposal form
  emitted no email. Registration was restored to the snapshot's original
  `admin_only` value; `verify_mail=true` remained unchanged.

### Browser and operational evidence

Chromium rendered empty and populated Blog/Forum pages at 1440×1000 and
390×844. Each page had exactly its intended block, correct cards/links, no
horizontal overflow (scroll width exactly 1440/390), no public proposal block,
no external request, no page/console error, and no `5xx`. Authenticated proposal
and comment forms and administrator review/publication/moderation were exercised
with screenshots retained only under `/tmp`. A final isolated web-container log
window for public Blog/Forum renders contained no PHP warning or fatal error.

The Webform administrator results page references existing third-party CDN
assets from the installed Webform UI; the test interceptor blocked those assets,
which produced expected admin-only `ERR_FAILED` console entries. Public and
member feature pages made no such request. No Google or PayPal request occurred,
no production credential was used, and no VPS/public production endpoint was
accessed.

### Cleanup and restoration

Drupal storage APIs deleted the exact eight Nodes, one comment, three
submissions, five temporary users, and two PathAlias fixtures. Mailpit was
cleared. Before snapshot restoration there were zero Nodes/comments/
submissions, users were again 0–6, aliases were again 16, menu links were zero,
all 14 feature configs were absent, and rollback reported no field tombstone or
Webform artifact.

The named physical database snapshot and preserved public-files tar were then
restored. Final state was: 314 active configs; zero Nodes/comments/submissions;
users 0–6; 16 aliases; zero content menu links; `admin_only` plus
`verify_mail=true`; Olivero/Claro; front page `/node`; maintenance off; and
Mailpit empty. The final deterministic active-config `readMultiple()` fingerprint
was `5dc5f088dd497e83c5991257ff17dcd7da0039457ebfe79859dc3afdd5235f56`;
the physical snapshot is the authoritative pre-write comparison artifact. The
public files exactly returned to 245 files/838,007 bytes and the recorded
`3e414f9b…` fingerprint. The main serving checkout is clean on `release/prod`,
the temporary root DDEV config is absent, DDEV has no registered project or
running container, and all fixture helpers/evidence remain outside the
repository under `/tmp` only.

## Static validation record

The final post-runtime static phase passed:

```bash
bash -n scripts/apply-forum-blog-mvp-2026.sh
shellcheck scripts/apply-forum-blog-mvp-2026.sh
php -l scripts/forum-blog-mvp-config.php
php -l web/modules/custom/unisonges_structure/unisonges_structure.module
git diff --check
```

Both PHP files were linted with PHP 8.3.31. Symfony YAML parsed all 15 changed
config files; the complete sync directory contained 451 parseable config files
and 396 globally unique UUIDs. Every changed config dependency resolved.
Assertions passed for block-only Views, published-only filters, newest-first
sorts, empty states, unpublished/unpromoted Forum defaults, hidden author,
`basic_html`, the authenticated-only handler-free Webform, exact route scopes,
access hooks, permission guards, rollback content guards, and the Language
dependency.

The diff contains exactly the 19 reviewed Forum/Blog files. Forbidden-file,
role/menu, Commerce/reservation, config-import, credential-file,
high-confidence secret, and new external-integration scans passed. There is no
untracked fixture/helper in the repository. Runtime apply/dry-run exact matching
also supplied Drupal config-entity/schema validation under the locked runtime.
