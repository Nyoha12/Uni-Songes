# Editorial canonical aliases (2026)

## Status and scope

This change adds two bundle-specific Pathauto patterns and a guarded
existing-content audit/apply helper. Its tracked scope remains static: it
contains configuration definitions, an operator helper/wrapper, and this
record, but no deployment or content fixture. The authorized local DDEV pass
described below exercised the exact change with disposable fixtures and then
restored its named snapshot. It used no production data, configuration import,
Mailpit, or VPS access.

The reviewed base is `origin/release/prod` at
`9ef3d4a2c260af9f3f2fcfe4ac584648bb592e0c`. It contains the actual PR #103
merge (`36b023c91a4a2723391c3ddb04716c911ac6bfe1`) and the later, unrelated PR
#104 deployment-permission files. This change does not alter public
hub routes, node access, Views, publication defaults, global Pathauto or
Redirect settings, sitemap configuration, robots policy, themes, menus, or
content. The 2026-09-07 targeted continuation proved that the exceptional guard
roots and native suffixes resolve correctly and work with the helper, access
rules, and homepage. It also demonstrated a blocking native Redirect-source
reuse after a title edit. PR #113 remains draft for that concrete conflict;
merely relaxing the required path depth is insufficient. The two patterns and
both executable files remain unchanged. Merge is outside this validation's
authority and scope.

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

This protection belongs to the helper, not to every ordinary Node save.
The targeted continuation reproduced Pathauto reusing a previous Redirect
source and Redirect deleting that redirect during the new PathAlias insert.
No existing PathAlias row changed owner, but the historical public URL moved
to a different node. The unchanged patterns alone cannot promise permanent
Redirect-source reservation; see the exact reproduction below.

The tracked and required active site language/default language are both `fr`.
The helper also accepts a hub alias stored as language-neutral (`und`), but
requires each hub to round-trip through the alias manager for a French request.

## Exact patterns

| Bundle | Pattern ID | Pattern | Generated namespace |
| --- | --- | --- | --- |
| Article (`article`) | `article` | `blog/article/[node:title]` | normally `/blog/article/<slug>`; exceptionally `/blog/article`, `/blog/article-0`, etc. |
| Forum Topic (`forum_topic`) | `forum_topic` | `forum/topic/[node:title]` | normally `/forum/topic/<slug>`; exceptionally `/forum/topic`, `/forum/topic-0`, etc. |

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
`/blog/article` or `/forum/topic`, and every suffix remains below its hub. The
runtime passes confirmed that exact result. The proposed adjusted contract
would accept these exceptional paths and their native suffixes anywhere below
`/blog/` or `/forum/`, while keeping normal URLs and both patterns intact.
The 2026-09-07 tests proved current route availability, uniqueness, canonical
links, access and helper idempotence under that interpretation. However, the
required absence of Redirect collisions failed on a later ordinary Node save.
The adjusted contract therefore has not been accepted unconditionally, and
readiness remains blocked by Redirect reuse rather than by path depth alone.
No route is activated merely by merging these configuration definitions.

Neither pattern contains a node ID or any numeric fallback. The fixed bundle
prefixes prevent a cross-bundle collision. Pathauto also reserves an existing
alias, exact route, file, or directory and deterministically appends `-0`, then
`-1`, and so on. It does not overwrite another existing PathAlias owner. This
does not reserve a historical Redirect source. The helper extends that
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

## Slug cases

These expectations reproduce Pathauto 1.14's cleaner with the tracked settings.
The authorized Drupal pass confirmed the listed punctuation, case, collision,
empty-token, and length behavior.

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
| 200 repeated `A` characters, then 200 `B` characters | `/blog/article`, then `/blog/article-0` | `/forum/topic`, then `/forum/topic-0` | current route/ownership/access/helper checks pass; later native Redirect-source reuse blocks unconditional acceptance |
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
callback state is not claimed as proof of the server outcome. The targeted
post-insert `E_USER_ERROR` test below proved nested entity-save rollback and
durable alias/state/fingerprint non-mutation. Technical cache/backend effects
and the separate fatal, OOM, unknown-outcome and commit-boundary hardening
branches were not part of that targeted fault injection. Kernel shutdown
deliberately does not dispatch HTTP terminate subscribers, so this direct
helper cannot opportunistically launch Automated Cron after the audited plan.

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
tracked `access_check: false` is unchanged. The runtime pass confirmed that an
old automatic alias redirects to the canonical route and still ends in the
normal unpublished-node denial.

## Relationship with PR #103 and PR #82

PR #103 is merged at
`36b023c91a4a2723391c3ddb04716c911ac6bfe1` and renders Article links with
`$node->toUrl('canonical')`. Drupal resolves that canonical route through the
alias manager, so the homepage Blog links consume these aliases without any
edit to a PR #103 file. The runtime browser check confirmed that the homepage,
Blog View, and canonical Article page all use the same Article alias. The
branch was then rebased onto the later PR #104 merge; its two added deployment
safety files do not overlap or change the tested Drupal/runtime inputs.

PR #82 requires every dynamically included entity to resolve to exactly one
unique non-numeric PathAlias before Simple Sitemap inclusion. These patterns
provide the missing Article/Forum generation policy; the helper supplies a
guarded path for genuinely alias-free existing content. This change does not
edit tracked Simple Sitemap configuration or apply #82's policy. Its module
was enabled transiently only to satisfy the helper's runtime prerequisites;
#82 must continue to fail closed for any helper blocker.

Read-only inspection of #82 at `55d1a407a9073bbd63e1c36ebf20f5ca717d6773`
confirmed that its dynamic canonical gate requires an internal, unique,
non-numeric PathAlias and protects the exact hubs. It does not require another
segment after `article` or `topic`, so the four exceptional paths meet those
structural checks. This is not a complete Simple Sitemap runtime validation:
its generation/inclusion checks remain owned by #82. The 2026-09-07 Chromium
check confirmed #103's actual homepage link to `/blog/article`, without editing
either PR's files or the #82 worktree.

The latest open-PR filename audit on 2026-09-07 covered all 22 open PRs and 144
file rows. Excluding PR #113 itself, there is no exact filename overlap with
its five files. The remaining semantic adjacency is #82 (the sitemap gate);
#103 is now part of the reviewed base. Open heads remain mutable, so the guard
is repeated immediately before the final push/readiness transition.

## Activation boundary and operator commands

The tracked staging deployment script pulls Git, installs Composer packages,
runs database updates, and rebuilds caches; it does not import configuration.
Accordingly, merging code alone would not activate these pattern config
entities. Pattern activation is a separately reviewed runtime prerequisite and
is intentionally not hidden inside this alias/state helper. The validation
activated the patterns only inside the disposable local snapshot; the final
snapshot restore removed that active configuration and every fixture. It did
not authorize activation or apply on any persistent environment.

Activation, audit, remediation and any apply must occur inside one real
maintenance/exclusive-writer window. If dry-run reports an unmarked manual
alias, the site must not be reopened for content saves with the new pattern
active: the two patterns must be returned to their prior inactive/absent state,
or explicitly setting that alias to `SKIP` must complete under a separate
review, before maintenance mode ends. The helper will not infer that decision
or make it automatically. The same fail-closed rule applies to unsupported
language/translation topology and every other blocker.

After an approved process has activated exactly the two reviewed pattern
configs, an operator can run the helper from `drupal/` on the approved complete
clone:

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

The rebased static checks passed all of the following without bootstrapping
Drupal:

- strict parsing of all 492 tracked YAML files and exact Pathauto schema-shape
  assertions for both new patterns;
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
- a fresh open-PR filename-overlap check (22 open PRs, 144 file rows, zero
  overlap outside PR #113);
- independent Pathauto, SEO, access-control, helper/API, and operations reviews
  completed; the long-title runtime policy finding is tracked below.

The final static results and exact commands are also recorded in PR #113. The
runtime record below is evidence for the targeted matrix, not authorization to
run the helper against a persistent environment.

## Runtime validation record

The complete local clone was snapshotted as
`pr113-editorial-alias-pre-runtime-20260903T163010Z`. The executable source
tested at `bb65618eaadc2afaad51edc18346f2040a6d3fbc` has these SHA-256 values:

| Tracked input | SHA-256 |
| --- | --- |
| `pathauto.pattern.article.yml` | `9b11d35e8824ad218e5ac7882b6699ac2508720fd92e4728aa6287eaca63de88` |
| `pathauto.pattern.forum_topic.yml` | `bb51371a84f6fdd14a9551bfebaec85b093b22983a6c855ef12e1c46c15686be` |
| `apply-editorial-alias-policy-2026.sh` | `f5ae9ae8dd8fcfea48d1d5902bf1fd3cfff7b4aea90e8eeed346e142cf47b9eb` |
| `editorial-canonical-aliases.php` | `dc70827c42ad1e5811aad955476ab154560330e1a67aeac9cc6bc3222578cc10` |

The post-test rebase changes ancestry only for these inputs: PR #104 added two
unrelated files and all four hashes remain exact. During the pass, two runtime
defects were corrected within the existing PHP helper: the metadata query now
uses `addExpression()` without incorrectly chaining its string return value,
and the node-integrity snapshot hashes only stored fields, excluding computed
fields such as `metatag`. The full matrix and rollback test were rerun or
continued against those corrected bytes rather than against the failing
intermediate versions.

The first dry-run classified the two deliberately alias-free backfill nodes as
`no alias` and planned exactly two creations. Apply created and verified those
two aliases. The next dry-run planned zero operations and the repeated apply
reported `NO_CHANGE`. A separate blocker plan classified an explicit manual
alias without blocking it, then refused an unmarked manual alias, a numeric
alias, a malformed percent-encoded alias, and both punctuation-only titles.
The apply attempt with that exact plan also refused before any write.

For the controlled failure, one eligible Article produced fingerprint
`24f8b99eecd53c32cc481c45b6b22e2d7ef92d58a00a6a74758200613ca194ce`.
Temporary, untracked `/tmp` instrumentation raised `E_USER_ERROR` immediately
after the PathAlias insert. Apply exited non-zero with verified transaction
rollback; the node still had no alias and the next dry-run reproduced the exact
same fingerprint. The tracked Core PathAlias file stayed byte-identical at
`d13c73313f3d3c8daa321b539edba7769043f5b12b46059841f25e791843956a`.

The deliberately small Chromium suite ran five checks and passed `5/5`: one
Article canonical plus Blog link, one Forum Topic canonical plus Forum link,
one PR #103 homepage Article link, one anonymous unpublished denial, and one
old Redirect after a title edit ending in unpublished denial.

| Runtime case | Result | Evidence |
| --- | --- | --- |
| Published Article | Pass | One unique `/blog/article/article-canonique-principal` alias; canonical page, Blog View and PR #103 homepage all used it. The helper-created French backfill alias was `/blog/article/ecoute-et-improvisation`. |
| Unpublished Article | Pass | Alias ownership was allowed; alias and `/node/<id>` were denied anonymously; Blog and homepage omitted the row. |
| Published Forum Topic | Pass | One unique `/forum/topic/un-sujet-simple` alias; canonical page and Forum View used it. |
| Unpublished Forum Topic | Pass | Alias ownership did not alter the existing unpublished access or View filters. |
| French punctuation and case | Pass | Accents, straight and typographic apostrophes, ampersand, slash, repeated spaces and mixed case matched the slug table. |
| Duplicate/colliding titles | Pass | Same-title and transliteration collisions used deterministic `-0`; no overwrite, owner transfer or cross-bundle collision occurred. |
| Long unbroken title | Superseded by targeted continuation below | The original depth finding was reevaluated with the authorized broad-namespace contract. Guard roots/suffixes pass the targeted checks; native reuse of an old Redirect source remains blocking. |
| Title edit and Redirect | Pass | `/blog/article/souffle-initial` became `/blog/article/souffle-renouvele`; one 301 retained the old path and could not bypass later unpublished denial. |
| Manual alias | Pass | Explicit `SKIP` stayed unchanged through title edit; unmarked provenance was preserved and blocked helper apply. |
| Numeric/malformed alias | Pass | Both were classified and preserved; apply refused with no silent migration. |
| Invalid title | Pass | Punctuation-only titles received no alias, hub claim, or numeric fallback and blocked apply. |
| Helper lifecycle | Pass | Dry-run/apply/second dry-run/second apply proved create-only behavior and idempotence. No title, body, author, publication state or revision changed. |
| Controlled rollback | Pass | Injected failure returned non-zero, verified root rollback, preserved the missing-alias state and reproduced the immutable plan fingerprint. |
| Views, access and sitemap non-regression | Pass | No Blog/Forum View, access rule, publication default or Simple Sitemap state/configuration changed. |
| PR #82 sitemap recognition | Deferred to #82 | No #82 policy was applied; its runtime inclusion recognition remains deferred. The Simple Sitemap module is only a transient helper prerequisite. |
| Zero fixtures and environment cleanup | Pass | All runtime fixture nodes and their aliases/Redirects were removed, the snapshot was restored twice, the serving checkout returned to `release/prod`, and DDEV was stopped. |

Cleanup matched the baseline: the normalized database dump SHA-256 was
`e753afc47351ef4869fd87184b5df9602fa40650046e16b53836834cb4b89d7a`,
the public-files archive SHA-256 was
`4817197810907ded56075bfd15366c85b1d70ca53b4dad0a41096f46bb4dade6`,
active configuration contained 314 objects with canonical hash
`e96a6b849b5e15c6e16fde5b6494a9e57fe9f7161dd8398c819963ddfdfc2127`
and serialized hash
`5dc5f088dd497e83c5991257ff17dcd7da0039457ebfe79859dc3afdd5235f56`.
Users remained 7, nodes 0, aliases 16, Redirects 0, modules 59, default/admin
themes `olivero`/`claro`, front page `/node`, and maintenance mode false. The
serving checkout is clean on the latest `release/prod`, and no DDEV project
container remains running. Its final commit/tree fingerprint is
`9ef3d4a2c260af9f3f2fcfe4ac584648bb592e0c` /
`94316e6bedeae800078f5ecee755b4b2fd3f27dc`.

## Targeted extreme-title continuation, 2026-09-07

The exact tested head was `7306586b1beda708a8d14bff2c0d2cf0a2bc7401`.
A fresh fetch confirmed that `origin/release/prod` still equals `9ef3d4a2…`
and already contains the real #103 merge, so no further rebase or history
rewrite was necessary. All four executable/config hashes in the earlier
record remained identical. The successful French punctuation, ordinary
duplicate/transliteration, manual/SKIP, ambiguous provenance, numeric/malformed,
invalid-title and controlled post-insert rollback evidence was reused.
Only the extreme-title scenarios and their dependent consumers were exercised.

Before the first Drupal write, the current local state was captured in
`pr113-extreme-alias-resume-20260907T1445Z`. After the Codespaces interruption,
the partial module/theme installation and zero-node state were inspected and
additionally saved as `pr113-extreme-alias-interruption-recovery-20260907`.
The initial snapshot, not a September 3 snapshot, remained the final restore
target. The serving checkout was clean at the exact tested head; no other
agent used DDEV. #90 and #94 continued statically, and #82 was not modified.

The local preparation used Drupal APIs for the exact prerequisite settings,
four disposable Basic pages, installed modules/themes, and the existing guarded
Forum/Blog and editorial-home helpers. Their successful plans were
`f00ab243441e3629875463d2c6fd8265fdb11e9924ea006e08a9f236a8cac02f`
and `20c2c7cd27af5ebce89eac42c899608370821239643aa65a2243d800fb297a7f`.
The latter applied its five expected operations. Pathauto/Redirect settings
and the four patterns were made active only in this disposable local database.
No configuration import or raw SQL command was run. Normal module installation
also fetched Drupal.org French translations through Locale; those local
configuration/file effects were included in cleanup. No Google, PayPal API,
external email, Mailpit or VPS access occurred.

The CLI checks used a disposable PHP 8.3 DDEV-image container on the project's
local network, with the complete serving checkout mounted for the Git guards.
Early local-precondition refusals (incomplete Git mount, PHP 8.4, stale kernel
cache, exact comment/page metadata and sparse Stage/Concert config shape) were
resolved in the disposable environment. No production guard was bypassed or
edited. A preliminary auxiliary config hash differed without a complete value
snapshot; that integrity check was not counted as passing. The isolated repeat
below captured all config values and passed before/after equality. The local
French URL-prefix prerequisite was also aligned before the final URL checks.

| Affected check | Observed result |
| --- | --- |
| Real pre-generation resolution | Drupal's router returned not-found for all four paths below; PathAlias ownership and Redirect repository matches were both empty. This checks current routing, not hypothetical future routes. |
| Published Article, 200 `A` characters, node 41 | One PathAlias (ID 25) at `/blog/article`; Drupal resolved `entity.node.canonical` to node 41; canonical page, Blog and #103 homepage links used it. |
| Unpublished Article, 200 `B` characters, node 42 | One PathAlias (ID 26) at `/blog/article-0`; anonymous alias and `/node/42` returned 403; absent from Blog and homepage. |
| Published Forum Topic, 200 `A` characters, node 43 | One PathAlias (ID 27) at `/forum/topic`; correct canonical page and Forum link. |
| Unpublished Forum Topic, 200 `B` characters, node 44 | One PathAlias (ID 28) at `/forum/topic-0`; anonymous alias and `/node/44` returned 403; absent from Forum. |
| Hub/ownership boundaries | `/blog` and `/forum` retained their distinct Basic-page owners, nodes 38 and 40. No empty path, numeric canonical, `//`, hub alias, `/blog-0` or `/forum-0` was produced. No existing PathAlias row transferred owner. |
| Helper lifecycle | Dry-run planned four creations, apply committed exactly four, the next dry-run classified all four aliases as valid with zero blockers, and the next apply returned `NO_CHANGE`. |
| Integrity during helper apply | Full persisted node fields/revisions, all active config (including Views, access and sitemap), and all 20 preexisting PathAlias entities remained unchanged. |
| Published Article title edit | `/blog/article` became `/blog/article/article-extreme-redevenu-ordinaire`; Redirect ID 1 returned 301 to the new canonical, ending in HTTP 200. |
| Unpublished Forum title edit | `/forum/topic-0` became `/forum/topic/forum-extreme-redevenu-ordinaire`; Redirect ID 2 returned 301 to the new canonical, ending in HTTP 403. Publication remained unchanged. |
| Existing Redirect source, helper | Two new eligible alias-free extreme fixtures, nodes 45/46, produced two blockers. Both dry-run and apply exited 1 before writes and preserved Redirect IDs 1/2. |
| Existing Redirect source, native Node save | **Failure:** node 45 claimed `/blog/article` (new PathAlias ID 29) and deleted Redirect 1; node 46 claimed `/forum/topic-0` (new PathAlias ID 30) and deleted Redirect 2. Each path had one new owner, but the historical URL no longer redirected to its former node. |

The isolated creation fingerprint was
`8ee03790a493b19532ad220a0a7a1d70d6ce2ab001eee77ef8beb7e89f987b86`;
the successful no-op fingerprint was
`5a9695e8ecf8eaea9dd435b2861e61e30375f4fd07851467c7283dc8201dda4f`.
The collision dry-run and refused apply shared
`3df61188e6ae418dc6f5bddb34ff6b225ec2122d7e24794b8ab10e676d4ed8fc`.
The isolated content/revision hash stayed
`72744e2f42239a43fc6c1a2b52fda869aedfbec8f238cf076db9f573aee7c830`,
and its full config hash stayed
`358650dc027fae6cc63a6f9495d2a3301ece543e718c1030721082f363502ece`.
The config hash uses sorted config names. Both hashes use PHP serialization
and differ from the canonical-JSON cleanup hashes below.

The targeted real Chromium run passed two canonical pages, three collection
checks (Blog, Forum, homepage), four unpublished URL denials and the two
post-edit redirect chains. Its request interception allowed only
`http://127.0.0.1:8080`; no general visual matrix was rerun. The later native
collision was observed through Drupal Node, PathAlias and Redirect APIs, then
the alias manager and path validator confirmed the new owners. The prior
controlled rollback test remains valid because the executable bytes did not
change; it was not reinjected during this continuation.

### Blocking result and smallest review option

Pathauto 1.14's `AliasUniquifier::isReserved()` reserves existing aliases and
literal routes, but not Redirect sources. Redirect 1.12's
`redirect_path_alias_insert()` deletes a matching source; its update hook has
the same deletion behavior. The guarded helper already prevents this, but an
ordinary Node save does not call that helper. Accepting the shorter guard
roots does not eliminate this lifecycle conflict, and adding another fixed
segment would not provide permanent Redirect-source reservation either.

The smallest option to review is an explicit manual, meaningful, non-numeric
alias with `PathautoState::SKIP` for an exceptional content item, after real
route/alias/Redirect preflight. This preserves normal patterns and URLs without
a custom generator. It is only a per-content workaround: it does not reserve
all historical Redirect sources against every future automatic Node save.
Adopting an operational exception or requiring a global reservation invariant
therefore needs an explicit policy decision and its narrowly affected test.
No such remediation has been applied, and PR #113 remains draft.

### Restoration and durable evidence

All six extreme-content fixtures (41–46) and four prerequisite pages (37–40)
were deleted through entity APIs; the immediate counts were nodes 0, aliases
16 and Redirects 0. The initial September 7 snapshot was restored, and the
canonical fingerprints matched the recorded starting state:

| Restored scope | Result / SHA-256 |
| --- | --- |
| Active config | 314 objects; `e96a6b849b5e15c6e16fde5b6494a9e57fe9f7161dd8398c819963ddfdfc2127` |
| Users | 7, IDs 0–6; `664d8700cbc47f60eb9605f755f01866bbf4dd6b91352eb5f3e97bea74defde9` |
| Aliases | 16, IDs 1–16; `b48a719ac57860fe4bea9970ed96cd5f4324bfc541b6d23b12773c7fc2b7eb85` |
| Modules | 59; `66c29bfed12400162f4aeade7bbb5ff309483e2ab8e76fcfac5be1871d4c576c` |
| Themes | `claro`, `olivero`; `398cebcf832b4579bd342309888aaf8aa6b0ab7cf7c98cda9f58bf069ba5b6a1` |
| Node/Redirect counts | 0 / 0 |
| Site defaults | UUID `f50a83bf-a30c-4ddc-bcd1-1cf1fe8e0a3a`; front `/node`; default/admin `olivero`/`claro`; maintenance false |
| Public `.htaccess` | 486 bytes; `28039dffc5bcf9de06c999f11f9a6c3372bcf1c675fcca2d6e5773680e281061` |
| Public `sync/.htaccess` | 685 bytes; `4f62c1eb3b42589fccb318763f5012794152d667d159a930a90e5081b83fe1ef` |
| Serving checkout | clean `release/prod@9ef3d4a2c260af9f3f2fcfe4ac584648bb592e0c`; tree `94316e6bedeae800078f5ecee755b4b2fd3f27dc` |

The public-file inventory again contains only those two unchanged files and
the original empty `php`/`styles` directories. Generated CSS, JS, Twig cache
and downloaded translations were moved out of public storage into the local
evidence directory. The interruption had erased the original `/tmp` archive,
so its compressed-archive hash was not claimed as reverified; both file bytes
were compared to the durable interruption backup.

The verification bootstrap warmed technical database caches. After checking
the semantic fingerprints, the same initial snapshot was restored once more
and exported without bootstrapping Drupal. Removing only the dump-completion
timestamp reproduced the original full database hash exactly:
`e753afc47351ef4869fd87184b5df9602fa40650046e16b53836834cb4b89d7a`.
DDEV web/database and router are stopped, the temporary CLI containers are
gone, and runtime ownership is released. The shared DDEV SSH-agent container
was left as found.

Detailed local logs, scripts, dumps and the recovery archive are retained in
`/workspaces/Uni-Songes/.git/pr113-evidence/resume-bh0oMS3t/`; they are outside
the tracked PR and survive `/tmp` cleanup. This committed record and the PR
body retain the useful findings independently of those local artifacts.
The final diff contains the same five files, with this continuation modifying
only this documentation. Final checks passed: PHP lint, Bash syntax,
ShellCheck 0.9.0, strict parsing of 492 YAML files, installed Pathauto schema
and dependency/ID/UUID/bundle assertions, UTF-8/NFC, a targeted credential-pattern
scan, `git diff --check`, exact-file guards and the 22-PR/144-file overlap audit.
The independent focused Pathauto/access/SEO review and final documentation
review agreed that the native Redirect collision requires draft status.
