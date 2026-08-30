# Forum + Blog MVP (2026)

## Status and ownership boundary

This change implements the Drupal configuration for a small Blog and moderated
Forum MVP. It does not create the Basic pages or menu links for `/blog` and
`/forum`; those remain owned by the parallel content/menu change. The two Views
and the proposal form are blocks whose request-path visibility is limited to
those existing aliases.

Static preparation was performed without DDEV and without VPS access. Runtime
validation remains pending while PR #67 owns DDEV. This change must remain a
draft until the functional matrix below passes with zero residual fixtures.

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

## DDEV functional matrix (pending PR #67 handoff)

Use disposable fixtures with a unique test prefix and record every entity ID.
Do not invent or retain production-looking Articles. Mailpit is used only for
the existing registration-verification message; the proposal form has no mail
handler.

- [ ] Record the exclusive test window: maintenance active, scheduled
  cron/queue workers stopped, and no external administrator UI, CLI, config, or
  content writes during each dry-run/apply/rollback sequence. Invalidate and
  repeat any sequence whose quiescence was breached.
- [ ] Confirm stored/effective registration is `visitors` with
  `verify_mail=true`; complete a member signup through Mailpit.
- [ ] As a normal member editing their own `/user/{uid}/edit` form, verify all
  three lesson-credit widgets are absent. Forge each field in the self-edit
  POST and confirm the stored values remain unchanged; treat any mutation as a
  launch blocker.
- [ ] Anonymous `/blog` renders only published Article fixtures, newest first,
  with title/date/summary/canonical link and the useful empty state.
- [ ] Anonymous `/forum` renders only published `forum_topic` fixtures and the
  useful empty state.
- [ ] As anonymous, `authenticated`, `content_editor`, and another representative
  non-admin account, request an unpublished Forum topic directly at
  `/node/{nid}`, its `/node/{nid}/revisions` overview, and a direct
  `/node/{nid}/revisions/{vid}/view` revision URL. Require access denial even
  for the topic author and for roles with `view own unpublished content` or
  `view all revisions`.
- [ ] As `content_editor` and the representative non-admin, verify the same
  unpublished topic is absent from `/admin/content`, `/admin/content/recent`,
  and every relevant generic node View. Repeat after warming caches and after
  publish/unpublish saves.
- [ ] Confirm unpublished Article and Forum fixtures never appear in feature
  listings, rendered caches, feeds, search, or other recent-content surfaces
  relevant to the test.
- [ ] Authenticated verified member sees and submits all three proposal types.
- [ ] Anonymous and ordinary member requests to
  `/webform/forum_blog_proposal` both return `403` and do not render the form;
  the authenticated member can submit only through the `/forum` block.
- [ ] Verify no PathAlias entity uses any underscore
  `/webform/forum_blog_proposal{suffix}` internal source or exact
  `/form/forum-blog-proposal{suffix}` default alias for the empty,
  `/confirmation`, `/submissions`, and `/drafts` suffixes. Do not require an
  absolute HTTP `404` if an unrelated pre-existing Redirect entity handles a
  public alias; confirm the feature neither creates nor modifies that redirect.
- [ ] If the member confirmation route is reachable, confirm it exposes neither
  the proposal form nor any own/other submission data; do not treat route
  existence alone as a failure.
- [ ] Member cannot view, edit or delete own/other proposal submissions.
- [ ] Administrator can review the private submission, create an unpublished
  topic, and publish it explicitly.
- [ ] On the Forum-topic create and edit forms, confirm the author (`uid`)
  widget is hidden.
- [ ] Authenticated member can comment with `basic_html`; comment is immediately
  visible under the retained `skip comment approval` policy.
- [ ] Anonymous comment POST is rejected; authenticated user cannot select or
  forge `webform_default` as the comment format.
- [ ] Administrator can unpublish/delete the test comment; unpublishing the
  topic removes listing/direct anonymous access.
- [ ] Verify role permissions, including no authenticated Article/Forum create
  or publication grant and no non-admin Forum publication bypass.
- [ ] Create/publish/unpublish fixtures around warmed caches; verify listing and
  direct-page cache invalidation after `cache:rebuild` and normal entity saves.
- [ ] Run install dry-run a second time and require only `MATCH`/`NOOP` target
  state.
- [ ] Exercise rollback on an empty disposable fixture state, then reinstall
  only after any reported Forum FieldConfig tombstones are purged and a new
  install dry-run accepts `feature_entries=0`; recheck both listings/form.
- [ ] Delete every test Node, comment, user and submission; run reviewed
  cron/queue work only for generated field-purge metadata or other known test
  work; verify zero residual fixture IDs/files/mail and zero feature tombstones.

## Static validation record

The initial static phase must include:

```bash
bash -n scripts/apply-forum-blog-mvp-2026.sh
shellcheck scripts/apply-forum-blog-mvp-2026.sh
php -l scripts/forum-blog-mvp-config.php
git diff --check
```

YAML must also be parsed and the exact semantic invariants checked before the
draft PR is handed over. Drupal entity/schema validation remains pending until
the locked PHP 8.3 Drupal runtime is available through the PR #67 DDEV handoff.
