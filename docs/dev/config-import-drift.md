# Drupal Config Import Drift

Status date: 2026-06-07.

This note documents why a full Drupal config import is currently unsafe and how
to remediate the drift without making blind production changes. It is a
docs-only audit; no config, code, Composer, DDEV, VPS, or production change is
made here.

## Current Problem

A full `drush config:import` failed because some `block.block.*_barrio`
configuration depends on a theme that Drupal reported would not be installed
after the import. During the same production diagnosis, `drush config:status`
also reported `unisonges_structure.google_calendar` as `Only in DB`.

Because the full import was unsafe, PR #36 did not rely on `drush
config:import` to apply the reservation webform email handlers. Those handlers
were applied directly instead, leaving the wider config drift unresolved.

The current Git export contains several Barrio block configs, for example
`drupal/config/sync/block.block.bootstrap_barrio_account_menu.yml`, and those
files declare a `theme` dependency on `bootstrap_barrio`. The custom theme
`drupal/web/themes/custom/unisonges_theme/unisonges_theme.info.yml` also uses
`bootstrap_barrio` as its base theme. Separately, no
`drupal/config/sync/unisonges_structure.google_calendar.yml` file is exported,
while `unisonges_structure_update_11002()` initializes that active config object
with non-secret Google Calendar sync defaults.

## Why Full Import Is Unsafe

Full import is an all-or-nothing reconciliation of staged config with active
config. In the current state it can fail before applying intended changes, or it
can try to remove or replace active config that has not been classified yet.

The unsafe parts are:

- Theme dependency mismatch: Barrio-related block config and the custom theme's
  base-theme requirement must align with the installed theme set, deployed code,
  Composer-installed dependencies, and `core.extension`.
- Active-only custom config: `unisonges_structure.google_calendar` exists in
  active config but is missing from `config/sync`, so import may treat it as
  unmanaged drift unless the team decides how it should be represented.
- Manual deployment workaround: the PR #36 webform handler change was applied
  directly because full import was blocked. The active webform config may match
  Git for that specific change, but the broader import baseline is still not
  proven safe.
- Environment boundary: Google Calendar tokens must not be exported, but the
  non-secret sync settings need an explicit policy: export them, override them
  per environment, or intentionally ignore them.

## Drift Categories

1. Import-blocking dependency drift

   Config references a theme dependency that import validation does not consider
   installed after the staged import. Barrio blocks are the known symptom.

2. Active-only config drift

   `unisonges_structure.google_calendar` is active in the DB but absent from the
   Git export. Its defaults include `enabled: false`, `dry_run: true`,
   `calendar_id: ''`, `timezone: Europe/Paris`, `batch_size: 10`,
   `token_provider: env_access_token`, and
   `access_token_env_var: UNISONGES_GCAL_ACCESS_TOKEN`.

3. Targeted manual-change drift

   Some production changes may have been applied directly to avoid full import.
   PR #36 webform handlers are the known case. These must be reconciled by
   comparing active config to Git, not by assuming either side is authoritative.

4. Environment-specific config drift

   Values that differ safely by environment must be identified before export.
   Secret values remain outside Git. Non-secret operational defaults should not
   stay accidental.

## Investigation Required Before Fixing

Do this first on local DDEV or a staging clone with safe data. Use production
only for read-only capture in an approved maintenance/debug window.

- Capture `drush config:status` and keep the full output with timestamps.
- Compare active and staged `core.extension`, especially the `theme` section.
- Verify deployed theme code and Composer install state for `bootstrap_barrio`,
  `unisonges_theme`, admin themes, and any theme listed in active or staged
  config.
- List all active and staged `block.block.*_barrio` configs, including status,
  theme, region, plugin, and dependencies.
- Decide whether each Barrio block is valid, obsolete, or a migration artifact.
  Do not delete it just because import currently fails.
- Read active `unisonges_structure.google_calendar` and decide whether its
  non-secret defaults belong in Git config, environment overrides, or an
  intentional ignore policy.
- Verify the active `cours_particuliers_reservation` webform handlers against
  the Git export from PR #36.
- Confirm whether any additional `Only in DB`, `Only in sync`, or `Different`
  items exist after the targeted evidence capture.

## Read-Only Diagnostic Script

Use `drupal/scripts/diagnose-config-drift.sh` to capture the first-pass
inventory without importing, deleting, exporting, or editing config.

Run it from the Drupal project directory:

```bash
cd drupal
./scripts/diagnose-config-drift.sh
```

The script is safe for a local DDEV checkout and for the VPS because it only
runs read-only commands:

- `git` commands for HEAD, branch, and short status;
- `drush status`;
- `drush config:status`;
- `drush php:eval` reads against active config storage and sync config storage.

It explicitly does not run full `drush config:import`, partial config import,
config deletion, active config writes, config export, or edits to
`drupal/config/sync`.

The script refuses `/mnt/c` paths so the Windows clone is not used
accidentally. It prefers DDEV Drush when a DDEV project is available, then
falls back to `./vendor/bin/drush`, then `drush` from `PATH`.

## Diagnostic Interpretation

Read the sections in order:

- `Git`: confirms the branch, exact HEAD SHA, and whether local files are dirty.
- `Drupal / Drush status`: confirms Drush can bootstrap the site context.
- `drush config:status`: preserves Drush's own raw status output.
- `Active vs sync inventory`: lists `Only in DB`, `Only in sync directory`, and
  `Different` by comparing active config storage with sync storage.
- `Known blocker inspection`: prints active and sync summaries for the known
  risky config names:
  - `block.block.unisonges_branding_barrio`
  - `block.block.unisonges_main_menu_barrio`
  - `block.block.unisonges_messages_barrio`
  - `unisonges_structure.google_calendar`
  - `webform.webform.cours_particuliers_reservation`
- `Risk classification for current drift`: groups all drift found by the
  diagnostic into:
  - `theme dependency / block drift`: usually Barrio block/theme dependency
    drift that can block import validation;
  - `prod-only secret/config`: active-only or environment-specific config such
    as Google Calendar settings, with token-like values redacted;
  - `safe targeted config candidate`: config that may be suitable for a small
    reviewed targeted reconciliation, such as the reservation webform;
  - `unknown`: drift that has not been classified and must not be imported or
    deleted blindly.

If the local database is empty or missing the full active config set, the script
prints a `LIMITATION` block and exits without treating the environment as clean.
In that case, rerun it against a complete local clone, staging clone, or the VPS
in read-only mode.

## Safe Remediation Sequence

1. Keep full `drush config:import` blocked for production until the dependency
   and active-only drift are classified.
2. Build a read-only inventory from DDEV/staging first, then production read-only
   if explicitly approved.
3. Fix the theme dependency baseline in the smallest possible PR. The decision
   must be explicit: keep Barrio as a required base theme and ensure code/config
   agree, or migrate away from Barrio and remove only proven-obsolete Barrio
   block config.
4. Decide the Google Calendar config policy. If exported, add only non-secret
   defaults and keep real tokens in environment variables. If ignored, document
   the reason and the exact guard that prevents import from deleting it.
5. Reconcile the PR #36 webform handler state by comparing active config to the
   Git export and applying only the missing delta.
6. Test `drush config:import --preview` or the safest available equivalent on
   DDEV/staging. Then run a full import only after the preview has no
   unclassified deletes, theme dependency errors, or surprise active-only
   removals.
7. After staging succeeds, prepare one small production runbook with exact
   commands, backups, expected `config:status` output, rollback notes, and
   post-import checks.
8. Apply production changes only after approval. Capture `config:status` before
   and after, and do not combine this with unrelated Commerce, reservation,
   Google Calendar real-sync, DNS, routing, or Composer changes.

## What Not To Do

- Do not run `drush config:import -y` in production while these drift categories
  are unresolved.
- Do not delete production config blindly, including Barrio blocks or
  `unisonges_structure.google_calendar`.
- Do not edit `drupal/config/sync` by hand to make the error disappear.
- Do not install, uninstall, or switch themes in production just to satisfy a
  config import error.
- Do not export secrets, OAuth tokens, refresh tokens, credential JSON, or real
  access-token values into Git.
- Do not combine config drift remediation with new reservation, Commerce,
  Google Calendar real-sync, DNS, routing, Composer, or DDEV changes.

## Next Recommendation

Run the read-only diagnostic locally first. If local active config is
incomplete, rerun the same script on the VPS in a read-only capture window.
Only after that inventory is reviewed should the project choose the minimal
config PR that makes `drush config:import` safe again.
