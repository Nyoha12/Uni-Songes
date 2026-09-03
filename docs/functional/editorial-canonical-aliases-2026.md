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
content. The targeted matrix succeeded except for the literal child-path
requirement on a very long single-token title, recorded below. PR #113 must
remain draft until that policy finding is resolved and its affected cases are
retested; merge remains outside this validation's authority and scope.

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
`/blog/article` or `/forum/topic`, and every suffix remains below its hub. The
runtime pass confirmed that exact result, which avoids the two hubs but is not
a child below the literal `/blog/article/` or `/forum/topic/` prefix required
by the continuation matrix. Locked Pathauto/Token has no per-pattern length
modifier; changing a global limit is forbidden, while adding another fixed
segment would change every public alias and requires a separately reviewed
pattern decision. The current patterns are therefore preserved, but this
finding prevents readiness. No route is activated merely by merging these
tracked configuration definitions.

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
| 200 repeated `A` characters | `/blog/article` | `/forum/topic` | runtime actual; safe from hub overwrite, but not a child path below the required guard prefix, so readiness is blocked |
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
edit or activate Simple Sitemap configuration, and PR #82 must continue to
fail closed for any helper blocker.

The latest open-PR filename audit on 2026-09-03 covered all 22 open PRs and 144
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
| Long unbroken title | Blocked | A 200-character token bottomed out at `/blog/article` or `/forum/topic`. It never claimed either hub or `/blog-0`/`/forum-0`, but it did not remain a child below `/blog/article/` or `/forum/topic/`. |
| Title edit and Redirect | Pass | `/blog/article/souffle-initial` became `/blog/article/souffle-renouvele`; one 301 retained the old path and could not bypass later unpublished denial. |
| Manual alias | Pass | Explicit `SKIP` stayed unchanged through title edit; unmarked provenance was preserved and blocked helper apply. |
| Numeric/malformed alias | Pass | Both were classified and preserved; apply refused with no silent migration. |
| Invalid title | Pass | Punctuation-only titles received no alias, hub claim, or numeric fallback and blocked apply. |
| Helper lifecycle | Pass | Dry-run/apply/second dry-run/second apply proved create-only behavior and idempotence. No title, body, author, publication state or revision changed. |
| Controlled rollback | Pass | Injected failure returned non-zero, verified root rollback, preserved the missing-alias state and reproduced the immutable plan fingerprint. |
| Views, access and sitemap non-regression | Pass | No Blog/Forum View, access rule, publication default or Simple Sitemap state/configuration changed. |
| PR #82 sitemap recognition | Deferred to #82 | This PR deliberately neither activates nor edits Simple Sitemap; #82 remains responsible for runtime inclusion recognition. |
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
