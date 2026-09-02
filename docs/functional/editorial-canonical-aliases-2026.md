# Editorial canonical aliases (2026)

## Status and scope

This draft change adds two bundle-specific Pathauto patterns and a guarded
existing-content audit/apply helper. It is a static phase only. No Drupal
runtime, DDEV, Docker, Drush, browser, Mailpit, VPS, configuration import, or
content write was used while preparing it. PR #87 has now released the shared
runtime resources, but this explicitly static phase still defers runtime
validation to a separately authorized DDEV pass.

The reviewed base is `origin/release/prod` at
`9021fc0197fc001ac3225e879cfa2c1a0b409e88`. This change does not alter public
hub routes, node access, Views, publication defaults, global Pathauto or
Redirect settings, sitemap configuration, robots policy, themes, menus, or
content. It must remain a draft and must not be merged before the deferred
matrix succeeds on an approved complete clone.

## Audited baseline

The repository locks Drupal Core `11.3.3`, Pathauto `1.14.0`, Redirect `1.12.0`,
Simple Sitemap `4.2.3`, Token `1.17.0`, and Drush `13.7.1`. Drush is recorded
only as an audited locked dependency; neither this implementation nor its
helper invokes it.

Because Composer-installed Core and contrib trees are intentionally ignored by
Git, a clean worktree alone does not prove their bytes. The helper therefore
recomputes deterministic full-tree manifests (sorted relative path plus file
SHA-256, with exact file count and no symlink) for the locked Core, Pathauto,
Redirect, Simple Sitemap, and Token distributions. Each must equal the
statically reviewed dist tree before a plan can be fingerprinted.

Before this change, the complete tracked Pathauto pattern inventory contained
only `stage` and `concert`. Those files use their existing bundle selectors and
remain byte-for-byte unchanged. The new pattern documents use Pathauto 1.14's
config-entity shape: a unique config UUID, a unique UUID-keyed bundle condition,
the `canonical_entities:node` type, the `node` module dependency, `and`
selection logic, and an exact single-bundle condition. No global setting was
changed to make the patterns work.

The tracked custom code contains no Pathauto pattern, alias, reservation, or
punctuation behavior hook. The runtime helper refuses any such active hook that
was not part of this audit and verifies that the cleaner, uniquifier and
transliterator use Core's exact active module handler.

Before producing a plan, the helper also requires the complete active Pathauto
pattern inventory to contain exactly `article`, `concert`, `forum_topic`, and
`stage`. It compares both legacy Stage/Concert patterns to their unchanged
tracked YAML and both editorial patterns to the new reviewed YAML. An extra,
missing, disabled, or drifted pattern therefore fails closed.

The tracked global settings have the following relevant behavior:

| Setting | Audited value | Consequence |
| --- | --- | --- |
| Separator | `-` | Whitespace and the configured hyphen action use `-`. |
| Maximum alias/component length | `100` / `100` | The complete generated alias is truncated to 100 Unicode characters at a word boundary when possible. |
| Transliteration | enabled | French accents are transliterated before the final lowercase pass. |
| ASCII reduction | disabled | The helper still rejects malformed stored/candidate paths; the global option is unchanged. |
| Lowercase (`case`) | enabled | Generated aliases are lowercase. |
| Ignored words | existing English list | In particular, transliterated standalone `À` becomes `a` and is removed when other text remains. |
| Punctuation map | only `hyphen: 1` | Hyphens are separators. Every unconfigured punctuation value compares to Pathauto's remove action in 1.14; apostrophes, ampersands and slash are removed, not replaced. |
| Update action | `2` (`UPDATE_ACTION_DELETE`) | A later automatic title update replaces the current automatic alias. Redirect can retain the former route as described below. |

Pathauto caches its punctuation-character inventory in `cache.discovery`. The
helper projects the live cached inventory to its complete name/value map and
requires an exact match with reviewed Pathauto 1.14 before cleaning any title;
a stale, extended or poisoned cache therefore fails closed.

Redirect remains globally configured with `auto_redirect: true` and status
`301`. Pathauto updates an automatic alias in place when `update_action: 2`;
Redirect observes the change and normally creates a 301 from the old alias to
the node route. Pathauto does not reserve Redirect source paths during
uniquification, however. The helper therefore refuses a candidate that would
collide with an existing Redirect source, treats the same collision on an
existing target alias as ambiguous, refuses a collision on either hub, and
verifies that apply changed no Redirect entity. Before each insert it also
reproduces Redirect 1.12's escaped `LIKE` plus language query through Drupal's
query builder. This catches accent-, case-, or width-equivalent values under
the active database collation that a PHP string comparison could miss and
makes Redirect's alias-insert deletion hook ineligible.

The tracked and required active site language/default language are both `fr`.
The helper also accepts a hub alias stored as language-neutral (`und`), but
requires each hub to round-trip through the alias manager for a French request.

## Exact patterns

| Bundle | Pattern ID | Pattern | Generated namespace |
| --- | --- | --- | --- |
| Article (`article`) | `article` | `blog/article/[node:title]` | `/blog/article[/<non-empty-slug>]` |
| Forum Topic (`forum_topic`) | `forum_topic` | `forum/topic/[node:title]` | `/forum/topic[/<non-empty-slug>]` |

The preferred expressions `blog/[node:title]` and `forum/[node:title]` are not
safe under the unchanged tracked limits. A cleaned, unbroken 200-character
title first reaches the 100-character component limit. Pathauto 1.14 then
word-safely truncates the complete alias to 100; because `/` is a word boundary
and no later boundary remains, that second pass collapses the bases to `/blog`
and `/forum`. Hub reservation prevents overwriting but uniquification produces
`/blog-0` or `/forum-0`, outside the required bundle namespace. The same edge
can occur while adding a suffix to a near-limit unbroken title.

Locked Pathauto and Token provide no per-pattern length modifier. Changing the
global 100-character settings is forbidden in this phase. The fixed,
non-numeric `article` and `topic` guard segments are therefore the narrow
configuration-only deviation: the same worst case bottoms out at
`/blog/article` or `/forum/topic`, and every suffix remains below its hub. This
uses the task's explicit conflict exception to the preferred expressions and
is called out for URL-policy validation in the draft PR; no route is activated
by this static change.

Neither pattern contains a node ID or any numeric fallback. The fixed bundle
prefixes prevent a cross-bundle collision. Pathauto also reserves an existing
alias, exact route, file, or directory and deterministically appends `-0`, then
`-1`, and so on. It never overwrites the other owner. The helper extends that
preflight across every candidate in the same immutable plan and refuses
case-folded, incompatible-language, Redirect-source, or ownership ambiguity. A
unique language-neutral (`und`) manual alias remains valid for a French node
because Core resolves it as the documented fallback.

The literal patterns still end in a slash before the title token, while an
empty title token makes Pathauto return `NULL` before alias cleaning.
Consequently, neither a guard segment nor either hub becomes an empty-title
fallback. The helper additionally requires
both hubs to have exactly one distinct published Basic-page owner before any
plan is accepted. Their aliases must be unique even after case folding and
must be stored in `fr` or `und`.

## Static slug cases

These expectations reproduce Pathauto 1.14's cleaner with the tracked settings.
They are static expectations until the same cases are exercised in Drupal.

| Title / case | Article result | Forum Topic result | Policy result |
| --- | --- | --- | --- |
| `Un article simple` | `/blog/article/un-article-simple` | `/forum/topic/un-article-simple` | lowercase, non-numeric |
| `Écoute et improvisation` | `/blog/article/ecoute-et-improvisation` | `/forum/topic/ecoute-et-improvisation` | French accent transliterated |
| `À propos du souffle` | `/blog/article/propos-du-souffle` | `/forum/topic/propos-du-souffle` | `À` -> `a`, then tracked stop-word removal |
| `L'art du didgeridoo` | `/blog/article/lart-du-didgeridoo` | `/forum/topic/lart-du-didgeridoo` | ASCII apostrophe removed |
| `L’art du didgeridoo` | `/blog/article/lart-du-didgeridoo` | `/forum/topic/lart-du-didgeridoo` | typographic apostrophe transliterated/removed |
| `Didgeridoo & guimbarde` | `/blog/article/didgeridoo-guimbarde` | `/forum/topic/didgeridoo-guimbarde` | ampersand removed; surrounding spaces collapse |
| `Pratique / écoute` | `/blog/article/pratique-ecoute` | `/forum/topic/pratique-ecoute` | slash removed; surrounding spaces collapse |
| `Espaces   répétés` | `/blog/article/espaces-repetes` | `/forum/topic/espaces-repetes` | repeated whitespace becomes one separator |
| duplicate `Titre identique` | base, then `/blog/article/titre-identique-0` | base, then `/forum/topic/titre-identique-0` | next owners use `-1`, `-2`, etc.; never overwrite |
| title `Blog` | `/blog/article/blog` | `/forum/topic/blog` | cannot equal `/blog` |
| title `Forum` | `/blog/article/forum` | `/forum/topic/forum` | cannot equal `/forum` |
| punctuation-only title | no alias | no alias | blocked as `empty_generated_slug`; no numeric fallback |
| literal `&lt;b&gt;` text | `/blog/article/b` | `/forum/topic/b` | reproduces Core Token's plain-text escaping before Pathauto cleaning |
| 200 repeated `A` characters | `/blog/article` | `/forum/topic` | fixed guard is the final safe word boundary; a collision gets the next suffix |
| `MiXeD UPPER lower` | `/blog/article/mixed-upper-lower` | `/forum/topic/mixed-upper-lower` | lowercase |
| duplicate `Écoute` then `Ecoute` | `/blog/article/ecoute`, then `/blog/article/ecoute-0` | `/forum/topic/ecoute`, then `/forum/topic/ecoute-0` | transliteration-identical titles are uniquified |

The static assertions also reject an empty path, `//`, a path beginning
`/node/`, a trailing slash, percent signs or malformed encoding, control/space
characters (including Unicode separators and bidi/format controls),
backslashes, query/fragment delimiters, non-NFC text, a generated numeric slug,
and a generated slug with no Unicode letter.

Punctuation removal does not itself insert a separator. Thus `Cours/Stage`
becomes `coursstage` and `Rock&Roll` becomes `rockroll`; the spaces surrounding
the punctuation in `Pratique / écoute` and `Didgeridoo & guimbarde` are what
produce the single hyphen shown in the table. Both apostrophe forms concatenate
the surrounding letters in the same way.

## Existing-content policy

Patterns affect new saves but do not safely backfill existing nodes. A guarded
helper is therefore included for existing `article` and `forum_topic` nodes.
It uses Drupal entity, KeyValue/Database query-builder, and Pathauto APIs; it
contains no raw SQL and never saves a Node. The database query builder is used
read-only to prove that Core's KeyValue API returned the complete
`pathauto_state.node` collection, because Core 11.3.3 otherwise converts some
backend read failures to an empty result. The two reviewed patterns must
already be active and exactly match their tracked YAML before either mode can
produce a plan. The helper does not create, update, delete, or import
configuration.

For every published or unpublished target node, dry-run emits only its numeric
entity ID, bundle, publication state, and one required classification:

- `valid unique non-numeric alias`;
- `no alias`;
- `numeric alias`;
- `duplicate/ambiguous alias`;
- `manual alias`;
- `malformed alias`.

It prints no title, alias candidate, body, author, UUID, language value, or
revision content. Per-entity lines contain only the four fields above. Separate
aggregate output gives only planned-create, state-write and total-blocker
counts; blocker reasons remain inside the hashed plan and are never printed.
Candidate and persisted values are never printed. One deterministic SHA-256
plan fingerprint binds the reviewed Git commit and source hashes, site
UUID/origin, every active config object, all PathAlias and Redirect entities,
all Simple Sitemap entity overrides, target node state, the complete persisted
Node Pathauto-state collection, the exact transactional-storage topology,
the full reviewed-package-tree manifest, exact candidate plan,
classifications, and blockers.

The helper's dry-run arm directly invokes no API that writes aliases,
Redirects, active configuration, content, Pathauto state, or revisions. Drupal
bootstrap, schema and cleaner reads may warm technical caches; those cache
effects are not represented as business-state changes and are not described as
a literal zero-write runtime. Every successful plan is built inside a root
database transaction
whose verified rollback completes before classifications or a fingerprint are
printed; runtime caches are then reset. Before boot and before the first
transaction, a guarded shutdown callback is placed first in Drupal's callback
list, and that position is rechecked before every transaction and commit. The
guard retains the connection before `BEGIN`, requires the exact locked Core
PDO MySQL driver/transaction manager with a non-persistent client, requires
stack depth zero before `BEGIN` and exactly one before commit, and reserves 1
MiB anew for each transaction so an OOM path has rollback memory. It also
requires the exact Pathauto cleaner, uniquifier, storage helper and quiet
messenger, with their module-handler/config/language/cache dependencies;
the exact PathAlias manager, repository and entity storage; the exact Redirect
repository/storage; the exact Simple Sitemap entity manager; the exact Core
database lock backends behind their lazy proxies; and the exact Pathauto-state
and menu-tree storage classes. Their durable dependencies must all use the same
guarded connection. The complete PathAlias table mapping must remain exactly
`path_alias` plus `path_alias_revision`.

The helper inspects `information_schema.tables` and
`information_schema.triggers` only through Drupal's query builder. The
`path_alias`, `path_alias_revision`, `key_value`, `redirect`, `menu_tree`,
`semaphore`, and `simple_sitemap_entity_overrides` tables must each be one
exact InnoDB base table, including resolved cross-schema prefixes. Each table
must have zero triggers, and the effective MySQL account must have an explicit
direct `TRIGGER` grant at schema or table scope so that a hidden trigger cannot
produce a false zero. A global grant alone is rejected because MySQL partial
revokes are not represented completely by `USER_PRIVILEGES`. Missing metadata
visibility, a trigger, or any engine/service/storage drift fails closed before
an alias write.

The normal path uses Drupal's root rollback and accepts it only when Core's
exact client state is `RolledBack`, PDO is inactive, and the stack is empty. A
normal commit likewise succeeds only in Core's exact `Committed` state. If a
fatal leaves a nested entity savepoint active, the guard requires an explicit
successful PDO rollback of the complete client transaction, voids Core's
retained transaction stack so neither `commitAllOnShutdown()` nor later
`Transaction` destructors can commit it, and then records Core's exact
`RolledBack` client state so post-transaction callbacks receive `false`. An
already inactive PDO client, a false/throwing PDO fallback, an unexpected
stack, or the narrow interval after commit was attempted is never called a
rollback:
it latches `TRANSACTION_OUTCOME_UNKNOWN`, forces a non-zero exit, requires
exact state verification and backup restoration before retry, and prevents
later Drupal shutdown callbacks from running. A premature `exit(0)` likewise
becomes exit status 1; only the explicit end-of-script marker lets the first
callback return normally. Because Drupal otherwise logs an ordinary
`E_USER_ERROR` and continues, the helper installs a narrow handler after
bootstrap which turns that level into a privacy-safe exception while chaining
every other level to Core; an `E_USER_ERROR` inside a guarded transaction must
therefore follow the same rollback path.

Immediately after `commitOrRelease()` returns, the guard marks the durable
boundary before any further probe. It then drops both retained `Transaction`
references so Core purges the root item and runs all post-transaction callbacks,
and requires the pinned manager's root, stack, voided-item and callback lists to
be empty before any `CREATED` or `APPLIED` line is emitted. A callback or purge
failure is consequently a non-zero `POST_COMMIT_ERROR`, never a rollback claim
or a prior success line.

Every verified rollback similarly destroys the caller's root `Transaction`
and requires the manager lifecycle to be empty before runtime-cache reset,
fingerprint rebuild, another root transaction, or privacy-minimized output.

Core represents a merely voided stack as successful to post-transaction
callbacks. This helper never accepts that state as rollback: after a proven
direct PDO rollback it explicitly restores Core's `RolledBack` state before
the root object is destroyed. When PDO is already inactive and the server
outcome cannot be known, it still marks callbacks as failed solely to prevent
follow-on success work, while latching `TRANSACTION_OUTCOME_UNKNOWN`; that
callback state is not claimed as proof of the server outcome. Durable-state
non-mutation, cache/backend effects, nested-savepoint failures and the unknown
branch remain explicit deferred runtime checks. Kernel shutdown deliberately
does not dispatch HTTP terminate subscribers, so this direct helper cannot
opportunistically launch Automated Cron after the audited plan.

Apply requires the exact dry-run fingerprint, a clean tracked checkout, an
explicit backup acknowledgement, maintenance mode, and unchanged
source/site/data. An apply with planned writes additionally requires the exact
database-backed application lock and Drupal persistent config lock, verifies
both owned `semaphore` rows after renewal, and starts a monotonic 15-minute
write deadline under their 3600-second leases. The deadline is checked around
each potentially long operation and immediately before and after commit; a
pre-commit expiry rolls back, while a detected post-commit expiry is reported
as `POST_COMMIT_ERROR`. A zero-operation idempotence check takes no write lock
because it has no persistent operation.

The helper generates only nodes still classified `no alias` with Pathauto
state `CREATE`. Core's reviewed `[node:title]` implementation returns exactly
`Node::getTitle()`. Because these two exact patterns contain only that one
token, the helper deliberately reads the title directly, runs Pathauto's
reviewed `AliasCleaner::cleanString()`, substitutes it into the literal
pattern, runs `cleanAlias()`, and then uses the reviewed `AliasUniquifier`.
This is statically equivalent to the relevant Core token result but does not
invoke the site's ECA-decorated Token service, ECA token events, or arbitrary
`tokens`/`tokens_alter` hooks during dry-run or apply. Any pattern drift or an
empty cleaned title fails closed before the prefix could become a hub alias.

Immediately before each write the helper repeats that derivation and requires
the exact planned candidate and hash. It explicitly requires a default-revision
Node with its path field in `CREATE`, then calls Pathauto's reviewed
`AliasStorageHelper::save()` with no existing alias and operation `insert`.
That exact branch can create a PathAlias but cannot update or transfer an
existing entity under the global update action. The helper never calls the
generator's update operation and never passes Pathauto's `force` option.
Drupal's PathAlias storage
has no database uniqueness constraint, so maintenance mode, the acknowledged
exclusive writer window, and the locks are correctness prerequisites: no
outside writer may mutate nodes, aliases, Redirects, Pathauto state, or active
configuration between dry-run and the completed apply.

Core's active `menu_link_content` alias-insert hook can rewrite a derived
`menu_tree` record when a stored menu link already targets the candidate path.
The plan and immediate pre-write check refuse that case, so this helper does
not change menu content or its derived tree. `menu_tree` is nevertheless part
of the InnoDB guard as defense against a missed race or runtime drift.

Apply also rebuilds its pre-write plan in a separate always-rolled-back
transaction after acquiring both locks. The actual insert/state transaction
starts only after that fingerprint has matched and the pre-write marker reports
that no planned persistent write has begun.

After each creation it persists `CREATE` through Pathauto's own state API so
the new alias has explicit automatic ownership and a second audit is
idempotent. This state write is allowed only for a node whose missing alias was
just created; no existing alias or opt-out state is changed. The helper
requires Core's reviewed database-backed state store so this marker and the
PathAlias share the same transaction. It verifies the alias owner and reverse
resolution, and verifies that node fields, revision, publication state,
pre-existing aliases, Redirect entities, Simple Sitemap entity overrides,
unrelated Pathauto state, and active configuration are unchanged. The state
comparison covers every key in the Node Pathauto-state collection. It
invalidates only the changed nodes' cache tags
in addition to the PathAlias entity's normal `route_match` invalidation. All
creations and their ownership markers run in one root database transaction; an
ambient transaction/savepoint is refused, and commit is refused if any nested
savepoint remains. A caught failure must complete a verified rollback, clear
only relevant runtime caches, rebuild the plan, and restore the original
fingerprint. Fatal/exit failures use the same verified guard or terminate with
the explicit unknown-outcome state described above; they can never report
success. A second run has no missing operation, making the policy idempotent.

The first-position fatal guard intentionally stops all later Drupal shutdown
callbacks, including database-lock cleanup. After a fatal/exit `REFUSE`,
`TRANSACTION_OUTCOME_UNKNOWN`, or `POST_COMMIT_ERROR`, the two apply locks may
therefore remain until their 3600-second TTL expires. An operator must wait for
expiry, or perform a separately controlled release only after the required
exact state verification and any backup restoration; an immediate retry is
not authorized. Maintenance mode and the acknowledged exclusive-writer window
remain primary correctness requirements; the leases and bounded deadline are
additional defenses, not authorization for concurrent content writers.

The helper never replaces or transfers an alias:

- a valid alias with persisted `CREATE` is retained as automatic;
- a valid alias explicitly marked `SKIP` is classified manual and retained;
- a structurally safe explicit manual alias may keep its intentional historical
  path outside the automatic bundle namespace; the namespace/lowercase rule is
  imposed on generated and persisted-`CREATE` aliases, not used to rewrite it;
  the manual path may use the locked 255-character storage capacity, while an
  automatic alias remains capped at the configured 100 characters;
- every valid alias with no persisted ownership marker is retained as manual
  and blocks apply pending a separate opt-out review; matching text never
  causes inferred or transferred ownership;
- numeric, malformed, duplicate, cross-language, and ambiguous aliases are
  retained and block apply;
- a non-French entity or any translated target keeps its factual alias
  classification but receives a separate
  `unsupported_language_or_translation_topology` blocker;
- a numeric alias cannot be replaced without a future separately reviewed
  migration mode, which this change deliberately does not provide;
- a missing alias explicitly opted out of Pathauto blocks apply;
- a punctuation-only/otherwise empty generated slug blocks apply and never
  falls back to the node ID.

Pathauto's direct alias creation does not itself persist the computed `CREATE`
marker without a Node save. The helper deliberately persists that marker only
for its own newly created aliases, through `PathautoState::persist()`, and does
not save the Node. This avoids guessing from alias text and prevents a later
automatic save from treating a helper-created alias as unknown provenance.

## Access remains route-independent

A PathAlias only maps a request path to the existing canonical Node route. It
does not grant `view` access. This change intentionally leaves all access and
publication controls untouched:

- the Blog View still selects exactly published Article rows and retains SQL
  access rewriting;
- Article publication continues to use Core Node status/access behavior;
- the Forum View still selects exactly published `forum_topic` rows and
  retains SQL access rewriting;
- the existing `unisonges_structure_node_access()` protection still forbids
  non-administrator access to unpublished Forum Topics and revisions;
- the existing global Forum View query guard still excludes unpublished Forum
  Topics for non-administrators;
- Forum Topics still default to unpublished.

An unpublished Article or Forum Topic may therefore own an alias, but an
anonymous request to that alias must still be denied by Node access. Redirect's
tracked `access_check: false` is unchanged; old-alias behavior for unpublished
content is explicitly part of the deferred runtime matrix.

## Relationship with PR #103 and PR #82

PR #103 renders Article links with `$node->toUrl('canonical')`. Drupal resolves
that canonical route through the alias manager, so once these aliases are
active its homepage Blog links consume them without any edit to PR #103 files.
Its dynamic cache tags also let a changed alias invalidate the rendered feed.
Because PR heads are mutable and PR #103 also changes the active module/View
baseline fingerprinted by the helper, this draft must be rebased and
statically re-audited if #103 lands first; it does not edit any #103 file. PR
#111 likewise changes the guarded `unisonges_structure.module` source and
requires that same rebase/re-audit if it lands first, although it has no
editorial-alias behavior or exact changed-file overlap here.

PR #82 requires every dynamically included entity to resolve to exactly one
unique non-numeric PathAlias before Simple Sitemap inclusion. These patterns
provide the missing Article/Forum generation policy; the helper supplies a
guarded path for genuinely alias-free existing content. This change does not
edit or activate Simple Sitemap configuration, and PR #82 must continue to
fail closed for any helper blocker.

The latest open-PR filename audit on 2026-09-02 covered all 22 open PRs and 216
file rows (#82, #85-#86, #88-#90, #92, #94, #96-#97, and #101-#112; all are
drafts). There is no exact filename overlap with this change. The semantic or
operational adjacencies are #82 (sitemap gate), #103 (canonical Article
consumers and guarded config), #111 (guarded custom-module source), and #87
(now-merged exclusive runtime owner). This inventory must be rerun immediately
before PR creation because open heads are mutable.

## Activation boundary and future commands

The tracked staging deployment script pulls Git, installs Composer packages,
runs database updates, and rebuilds caches; it does not import configuration.
Accordingly, merging code alone would not activate these pattern config
entities. Pattern activation is a separately reviewed runtime prerequisite and
is intentionally not hidden inside this alias/state helper. No activation or
apply is authorized in this static phase.

Activation, audit, remediation and any apply must occur inside one real
maintenance/exclusive-writer window. If dry-run reports an unmarked manual
alias, the site must not be reopened for content saves with the new pattern
active: the two patterns must be returned to their prior inactive/absent state,
or explicitly setting that alias to `SKIP` must complete under a separate
review, before maintenance mode ends. The helper will not infer that decision
or make it automatically. The same fail-closed rule applies to unsupported
language/translation topology and every other blocker.

After an approved process has activated exactly the two reviewed pattern
configs, the future runtime operator can run the helper from `drupal/` on the
approved complete clone:

```bash
./scripts/apply-editorial-alias-policy-2026.sh \
  --site-uri=https://approved-clone.example \
  --dry-run

./scripts/apply-editorial-alias-policy-2026.sh \
  --site-uri=https://approved-clone.example \
  --apply \
  --backup-confirmed \
  --expect-fingerprint=<exact-dry-run-sha256>
```

The wrapper bootstraps project-local Drupal directly. It refuses an incomplete
runtime, a dirty/untracked checkout, unsafe project roots, a non-root or
credential-bearing site URI, an origin/site UUID mismatch, config/runtime
drift (including the exact active module/theme inventory), unaudited active
Pathauto behavior hooks, a hidden/visible database trigger, an unproved
schema/table `TRIGGER` grant, non-InnoDB or cross-connection durable storage,
pre-existing menu links or Redirect collation matches for a generated
candidate, and every `/var/www` execution except a positively identified local
DDEV container. It offers no VPS override.

## Static validation record

The final commit must pass all of the following without bootstrapping Drupal:

- strict parsing of every tracked YAML file and exact schema-shape assertions
  for both new patterns;
- unique top-level pattern IDs/UUIDs and condition UUIDs, and exact `article`
  / `forum_topic` bundle selection;
- locked Pathauto/Redirect/Simple Sitemap/Token/Core versions and source API
  review, plus exact complete dist-tree file counts and SHA-256 manifests;
- all slug cases above, Core plain-token escaping equivalence, max-length,
  namespace, collision, empty-title and manual-preservation assertions;
- PHP syntax, Bash syntax, and ShellCheck;
- dry-run reachability and write-API guards, no decorated Token/ECA event or
  token-hook call, no raw SQL, no config import, and no Node save;
- fatal-shutdown callback ordering/rechecking, root/nested transaction-stack
  invariants, exact `RolledBack`/`Committed` client states, exact service graph,
  connection/table mappings, InnoDB table engines, zero-trigger/direct-grant
  checks, non-persistent PDO fallback, callback failure state after rollback,
  exact database locks, bounded write deadline, per-transaction emergency-memory
  reserve, premature-exit failure, commit-outcome latch, and absence of HTTP
  terminate-subscriber dispatch;
- exact changed-file allowlist and forbidden-area diff guards;
- no global Pathauto, Stage/Concert, sitemap, robots, Views, access, publication,
  theme, menu, Commerce, Contact, reservation, or public-legacy change;
- UTF-8/NFC, whitespace/error-marker, secret, and Git diff checks;
- a fresh open-PR filename-overlap check;
- independent Pathauto, SEO, access-control, helper/API, and operations reviews.

The concrete commands and their final results are recorded in the draft PR
description; no claim in this section substitutes for deferred Drupal runtime
validation.

## Deferred runtime matrix

All rows require zero persistent fixtures after cleanup and must be recorded
against the exact reviewed commit in the separately authorized post-#87 DDEV
pass; none was executed in this static phase.

| Runtime case | Required proof |
| --- | --- |
| Published Article | Save obtains one `/blog/article/<slug>` alias; canonical page and Blog View link resolve; anonymous access succeeds. |
| Unpublished Article | Alias may exist; canonical alias and numeric route both obey Node access; Blog View omits it. |
| Published Forum Topic | Save obtains one `/forum/topic/<slug>` alias; canonical page and Forum View link resolve. |
| Unpublished Forum Topic | Alias may exist; anonymous/member access remains denied; Forum and generic Views omit it; administrator behavior is unchanged. |
| French punctuation | Confirm accents, straight/typographic apostrophes, ampersand, slash, repeated whitespace, case and NFC against the static table. |
| Duplicate titles | Same-bundle and transliteration-identical duplicates use deterministic `-0`, `-1`; no owner changes and cross-bundle prefixes remain distinct. |
| Long unbroken title | A 200-character word bottoms out at `/blog/article` or `/forum/topic`; a collision uses `-0`, then `-1`, and every result remains below its hub. |
| Title edit | An automatic alias follows `update_action: 2`; verify the old alias's 301 and access behavior through Redirect. |
| Manual alias | Explicit `SKIP` remains byte-for-byte owned by the same entity after save, dry-run and apply. An unmarked manual alias is preserved by the helper, blocks activation/apply, and requires separately reviewed `SKIP` remediation before normal saves resume. |
| Numeric existing alias | Classified and preserved; apply refuses until a separately reviewed migration exists. |
| Invalid title | Punctuation-only/empty cleaned token creates no alias, no hub claim and no numeric fallback. |
| Anonymous unpublished access | Direct alias, `/node/<id>`, and any old redirect all remain denied as required for both bundles. |
| Blog/Forum Views | Every dynamic title/read-more link resolves to the entity's one canonical alias; unpublished rows remain absent. |
| PR #103 homepage | Its canonical Article links resolve to the same Article alias, with correct cache invalidation after an approved title edit. |
| PR #82 sitemap | Eligible published Article/Forum URLs are recognized only with one unique non-numeric alias; blocked/ambiguous/unpublished cases remain excluded. |
| Helper dry-run | Writes no alias/config/content/redirect/Pathauto state and prints only privacy-minimized entity lines, aggregate counts and one fingerprint; blocker reasons are not printed, and technical cache warmup is allowed and recorded separately. |
| Helper apply | With exact fingerprint/backup/maintenance/exclusive window/locks and bounded deadline, creates only planned missing aliases, persists `CREATE` only for those aliases and verifies every invariant. Exercise a collation-equivalent Redirect source and require pre-write refusal with no Redirect or sitemap-override mutation. |
| Idempotence | Immediate post-apply dry-run produces zero operations; a repeated apply reports no change. |
| Rollback | First verify all seven guarded tables and same-connection service/storage checks; inject MyISAM, trigger, missing direct `TRIGGER` grant, backend override and storage-connection drift and require a pre-write refusal. Then inject caught failures plus controlled `E_USER_ERROR`, `exit(0)`, non-zero exit, fatal and OOM subprocess failures before/during an entity-save savepoint on a disposable clone. The first-position guard must use a verified Core rollback or PDO fallback, prevent destructor/commit-all commits, make post-transaction callbacks receive `false`, return non-zero, and restore every alias/config/content/redirect/sitemap-override/state hash plus the original fingerprint. Record technical cache effects separately; test deadline expiry before and after commit, and after fatal/exit verify lock TTL behavior before any retry. |
| Commit boundary | Inject immediately before and after `commitOrRelease()`: pre-commit active transactions must roll back; any interrupted/ambiguous commit attempt must emit `TRANSACTION_OUTCOME_UNKNOWN`, forbid retry, and require exact state verification plus approved-backup restoration. |
| Zero fixtures | Delete all temporary nodes, aliases and controlled failure instrumentation; restore the named baseline snapshot and prove no residue. |
