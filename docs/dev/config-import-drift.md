# Drupal Config Import Drift

Status date: 2026-08-13.

This note records the config-drift evidence refreshed after rebasing PR #48 onto
the latest `origin/release/prod`. The refresh changed only this document and the
read-only diagnostic script. It did not run commands on the VPS or production,
and it did not change active config or `drupal/config/sync`.

## Current Problem

A previous production diagnosis found that a full `drush config:import` was
unsafe: Barrio-related block config depended on a theme that Drupal reported
would not remain installed after import. The same historical diagnosis reported
`unisonges_structure.google_calendar` as `Only in DB`. Those observations are
historical evidence, not a claim about the current production database; this
refresh deliberately did not query production.

The repository snapshot now contains 437 YAML files in the default
`drupal/config/sync` collection. File-only inspection of the five known names
shows:

- `block.block.unisonges_branding_barrio.yml` is present;
- `block.block.unisonges_main_menu_barrio.yml` is present;
- `block.block.unisonges_messages_barrio.yml` is present;
- `unisonges_structure.google_calendar.yml` is absent;
- `webform.webform.cours_particuliers_reservation.yml` is present.

All three named `unisonges_*_barrio` files are disabled and contain both a
top-level `theme: null` value and a null theme dependency. This is staged-file
evidence of a block/theme anomaly, not evidence of the corresponding active
values. The staged `core.extension` still lists `bootstrap_barrio` and
`unisonges_theme` as installed themes.

The reservation webform is present in the repository and contains the enabled
`reservation_student_confirmation` and `reservation_admin_notification` email
handlers. Its presence does not prove that active config matches it. Likewise,
absence of the Google Calendar file does not prove that the config object
currently exists in any active database.

## Strict Read-Only Boundary

The diagnostic in this PR must never:

- run full `config:import`;
- run partial or targeted config import;
- write or delete active config;
- export config or edit `drupal/config/sync`;
- run raw SQL writes;
- install, uninstall, or switch themes;
- start, stop, or reconfigure DDEV, or intentionally mutate the VPS or
  production.

The script uses only Git/file reads, `drush status`, `drush config:status`, and a
`drush php:eval` program limited to active config-storage reads and repository
`FileStorage` reads. A potentially safe candidate is still only a review label;
it is not authorization for a targeted import or any other write.

## Why Full Import Is Unsafe

Full import reconciles the entire staged collection with active config. Until
the complete drift inventory and the known blockers are reviewed, it could fail
dependency validation or remove/replace active-only configuration whose policy
has not been decided.

The known risks are:

- The three named Barrio block files have null staged theme data, while the
  current staged theme set still contains Barrio and the custom theme.
- Google Calendar config has historically been active-only. Runtime identifiers,
  tokens, and credentials must remain outside Git, while non-secret operational
  defaults need an explicit environment policy.
- The reservation webform was previously changed through a narrow deployment
  workaround because full import was blocked. Active and staged handler data
  must be compared before treating any delta as safe.
- Other drift may exist. Repository filenames alone cannot establish `Only in
  DB`, `Only in sync directory`, or `Different`.

## Running the Read-Only Diagnostic

Run from the Drupal project directory:

```bash
cd drupal
./scripts/diagnose-config-drift.sh
```

The script also derives its paths from its own location, so invoking it from the
repository root is safe:

```bash
./drupal/scripts/diagnose-config-drift.sh
```

### Local DDEV

When this worktree has a `.ddev` directory and the `ddev` executable is
available, the script selects the worktree's DDEV project. It verifies the
container's project Drush and Drupal root, then uses an argument-safe equivalent
of the following when `.ddev` is in the Drupal directory:

```bash
ddev exec --dir /var/www/html --raw -- ./vendor/bin/drush -r web
```

If `.ddev` is at the repository root and Drupal is in `drupal`, the container
directory is `/var/www/html/drupal` instead.

If DDEV is configured but stopped or cannot verify the container root, the
script does not start it and does not silently use a different DDEV project. It
reports the limitation and continues with Git and repository-file diagnostics.

### VPS

From the deployed Drupal project directory, the project-local command is always
rooted explicitly:

```bash
./vendor/bin/drush -r web
```

This PR refresh did not execute the diagnostic on the VPS. Any future VPS run
must remain read-only and occur only in an approved capture window.

### Safe fallback

Outside DDEV, the script prefers `./vendor/bin/drush`, then may use `drush` from
`PATH`; both are allowed only after it identifies the expected Drupal root from
`web/index.php`, Drupal core, and the project autoloader. It always supplies
`-r web`. If it cannot prove that root, it does not run Drush; it prints a clear
limitation and continues with:

- Git branch, HEAD, and short status;
- a sorted, normalized list of default-collection repository sync names;
- repository presence/absence for all five known blockers;
- explicit detection of the named Barrio files' null theme fields.

That fallback output is file evidence only. It is never labelled as a full
active-vs-sync inventory.

## Diagnostic Output

Read the sections in order:

1. `Git` reports the branch, exact HEAD, and `git status --short` result.
2. `Repository sync files` reports the default-collection file count and sorted
   config names without inferring database state.
3. `Drupal / Drush status` reports the selected, rooted command and redacted
   status output.
4. `drush config:status` reports Drush's status output with credential-looking
   values redacted.
5. `Active vs repository sync inventory` compares active storage to
   `drupal/config/sync` only when conservative baseline plausibility checks
   pass. Its normalized, sorted lists are:
   - `Only in DB`;
   - `Only in sync directory`;
   - `Different`.
6. `Known blocker runtime inspection` reports state and selected safe fields for:
   - `block.block.unisonges_branding_barrio`;
   - `block.block.unisonges_main_menu_barrio`;
   - `block.block.unisonges_messages_barrio`;
   - `unisonges_structure.google_calendar`;
   - `webform.webform.cours_particuliers_reservation`.
7. `Risk classification for current drift` groups verified drift into the four
   categories below.

## Risk Classifications

- `theme/block dependency drift`: the three named Barrio block objects whose
  theme relationship requires review before import validation can be trusted.
- `production-only runtime/secret config`: the Google Calendar object when it is
  observed as runtime-only or environment-specific. It must not be exported
  blindly.
- `potentially safe targeted candidate`: the reservation webform, but only when
  verified drift exists and its exact active/staged delta has been reviewed.
- `unknown/review required`: every other drift item, plus any runtime state that
  could not be verified.

The word “potentially” is important: the classification does not permit partial
import, direct active-config writes, or any automatic remediation.

## Incomplete Local Active Config

A successful Drupal bootstrap does not prove that a local database is a complete
or relevant copy. Before producing drift lists, the script applies a conservative
plausibility filter:

- the `core.extension`, `system.site`, and `system.theme` sentinels in both
  stores;
- equality of the active and staged `system.site` UUID without printing it;
- a conservative minimum active-config count relative to the repository sync
  collection.

If those checks fail, the script prints each list as unavailable, marks the
diagnostic partial, and does not classify the local database as clean. Passing
the checks still cannot prove completeness: confirm the clone's provenance and
freshness before treating its lists as authoritative. Obtain a complete approved
local/staging clone or perform a separately approved read-only VPS capture
before making decisions from active config.

## Redaction

Output is filtered for token-, secret-, password-, credential-, API-key-,
authorization-, private-key-, database-username-, and calendar-identifier-like
values. Bearer/Basic credentials, credential-bearing URLs, private keys, common
token formats, and JWT-looking values are also redacted. Known config summaries
expose only selected operational fields; Google Calendar credential-related
fields are redacted and unrecognized fields are omitted.

Redaction is defense in depth, not a guarantee. Review captured output before
sharing it or attaching it to a ticket. Never copy real access tokens, refresh
tokens, passwords, credential JSON, API keys, private keys, or secret-bearing
URLs into Git or PR comments.

## Review Sequence Before Any Separate Remediation

1. Capture this read-only diagnostic from a complete DDEV or staging clone.
2. If explicitly approved, capture the same read-only evidence on the VPS; do
   not mutate it.
3. Review active and staged `core.extension`, `system.theme`, and each named
   Barrio block. Decide whether every null-theme block is valid, obsolete, or a
   migration artifact; do not delete it merely because it is anomalous.
4. Decide the Google Calendar policy for non-secret runtime defaults and
   environment overrides. Secrets stay outside config export and Git.
5. Compare the reservation webform's active and staged handlers field by field.
6. Classify every remaining item. No item in `unknown/review required` may be
   silently accepted, imported, or deleted.
7. Propose any mutation later in a separate, explicitly approved, narrowly
   scoped runbook with backup, rollback, and post-change checks.

## Recommendation

Full config import remains blocked until reviewed blockers are resolved.

Do not run full or partial config import as part of this diagnostic. Resolve or
explicitly accept the verified theme/block dependency drift, establish the
production-only runtime/secret config policy, review any potentially safe
targeted candidate, and classify every unknown item before a separate import
runbook can even be considered.
