# Local DDEV Testing

This note describes the current safe local testing baseline for Uni-Songes.
It is intentionally about local WSL/DDEV only. Do not use `/mnt/c`, the Windows
clone, the VPS, or production data for this workflow.

## Current limitation

The local DDEV project can run PHP and Drush, but the database may be empty. In
that state, `drush status` can report the codebase and connection settings while
active Drupal entity checks fail because core tables such as `key_value` are
missing. Audits then have to fall back to static code and `config/sync`
inspection.

If the local database was installed with the Drupal standard profile, Drupal can
bootstrap but fixture guards may still fail because project modules, user credit
fields, and the reservation webform are absent. Prepare only that local fixture
readiness surface with:

```bash
cd drupal
./scripts/bootstrap-local-fixture-site.sh --dry-run
```

Review the dry-run output first. If it only lists the expected local-safe module
enables and allowlisted config creates, apply it with:

```bash
cd drupal
./scripts/bootstrap-local-fixture-site.sh --apply
```

This bootstrap command does not run full `drush config:import`, does not delete
active config, does not import unrelated config, does not create fixture data,
and does not enable real Google Calendar sync.

Run the non-destructive readiness check before active tests:

```bash
cd drupal
./scripts/check-local-test-readiness.sh
```

The script does not import data, drop tables, reset the database, or touch
production. If the database is empty, it reports that active entity tests are not
available and exits without changing data.

The first local fixture command is also read-only by default:

```bash
cd drupal
./scripts/create-local-fixtures.sh --dry-run
```

It lists the local fixture user records, notes that Commerce fixtures are
opt-in, then runs guards for DDEV, Drush, Drupal bootstrap, the user module, and
user credit fields.

To include the guarded Commerce fixture checks in dry-run output, use:

```bash
cd drupal
./scripts/create-local-fixtures.sh --dry-run --with-commerce
```

After reviewing the dry-run output, the fixture command can be applied locally
with:

```bash
cd drupal
./scripts/create-local-fixtures.sh --apply
```

Default `--apply` creates or updates only the five `local.fixture.*` users
through Drupal APIs. Newly created fixture users use the local-only password
`local-fixture-only`; existing fixture user passwords are not changed.

The Commerce phase is opt-in:

```bash
cd drupal
./scripts/create-local-fixtures.sh --apply --with-commerce
```

With `--with-commerce`, the script creates or updates only local fixture
Commerce entities if active Commerce prerequisites exist: `[Local Fixture]
Store`, `local_fixture_manual`, and products or variations with
`LOCAL-FIXTURE-*` SKUs. It does not create orders, webform submissions, Google
Calendar data, Composer changes, `.ddev` changes, or `config/sync` changes.

On a standard-profile local database prepared only by
`bootstrap-local-fixture-site.sh`, the Commerce phase can still block because
that bootstrap command does not create active `commerce_currency.EUR` or the
four course product and variation types. `--dry-run --with-commerce` reports
those blockers without changing data and should exit 0 unless there is a script
or runtime error. `--apply --with-commerce` exits 1 before Commerce writes when
those prerequisites are missing. Do not run full `drush config:import` to fix
that. The next required step is a separate reviewed local bootstrap command that
creates or imports only those allowlisted active Commerce config entities.

## Safe local workflow

1. Work from WSL `~/Uni-Songes/repo`.
2. Start or inspect DDEV from `~/Uni-Songes/repo/drupal`.
3. Run `./scripts/check-local-test-readiness.sh`.
4. If the installed local site lacks fixture guard prerequisites, run
   `./scripts/bootstrap-local-fixture-site.sh --dry-run` and only then
   `./scripts/bootstrap-local-fixture-site.sh --apply` after reviewing the
   output.
5. Run `./scripts/create-local-fixtures.sh --dry-run` before attempting local
   fixture work.
6. If the database is empty, limit validation to syntax checks and static
   inspection until a local fixture set exists.
7. If the default dry-run lists only expected fixture user changes, run
   `./scripts/create-local-fixtures.sh --apply` locally.
8. Use `./scripts/create-local-fixtures.sh --dry-run --with-commerce` only when
   you want to inspect guarded Commerce fixture readiness.
9. If the Commerce phase blocks on missing active Commerce config, keep the
   user fixtures and add only the missing allowlisted Commerce prerequisites in
   a separate reviewed bootstrap step.
10. Checkout, reservation submission, and Google queue checks remain future
   fixture phases until Commerce fixture products exist.

Do not use `uid=1` for functional tests. Use a dedicated local test account. Do
not import production data unless a separate, reviewed, sanitized-data procedure
exists.

## Fixture Requirements

A useful local fixture set should be generated by a future explicit developer
command. It should be idempotent and should not run destructive database
commands.

The implementation plan for generated local-only fixtures is
`docs/dev/ddev-fixtures-plan.md`.

Minimum fixture shape:

- Drupal is installed and can bootstrap active config.
- Required modules are enabled: Commerce, Webform, `webform_booking`, and
  `unisonges_structure`.
- User credit fields exist on users:
  - `field_seances_restantes`
  - `field_essai_utilise`
  - `field_pack_expire_le`
- Dedicated local fixture users exist, not `uid=1`.
- Commerce store and checkout/payment gateways exist for local checkout.
- Course product types and purchasable product/variation entities exist:
  - `cours_essai`: grants at most 1 trial credit per user.
  - `cours_deb_inter`: grants quantity credits.
  - `cours_avance`: grants quantity credits.
  - `pack_4_deb_inter`: grants `4 * quantity` credits and sets/extends pack
    expiry by 6 months.
- Reservation webform `cours_particuliers_reservation` exists with the
  `reservation` booking element.
- Google Calendar sync config stays disabled or dry-run; local tests should only
  verify queued rows.

`./scripts/bootstrap-local-fixture-site.sh --apply` covers the module, user
field, reservation webform, and Google Calendar safety prerequisites above for a
standard-profile local database. It intentionally does not create users, stores,
gateways, products, orders, submissions, or Commerce course config.

`./scripts/create-local-fixtures.sh --apply` covers fixture users only by
default. Add `--with-commerce` to include local fixture store, gateway, product,
and variation data once the active Commerce config prerequisites exist. It
intentionally does not create orders, submissions, Google Calendar data, or broad
active config.

## Test Matrix To Enable Later

Once local fixtures exist, the useful active checks are:

- Anonymous user can view reservation slots but cannot submit.
- Connected user with 0 credits cannot submit.
- Connected user with credits can submit.
- Course purchase changes credits only after the order is completed.
- `cours_essai` grants 1 credit maximum, even if quantity is greater than 1.
- `cours_deb_inter` and `cours_avance` grant one credit per purchased quantity.
- `pack_4_deb_inter` grants four credits per purchased quantity and updates
  expiry.
- A successful reservation decrements exactly one credit.
- Reservation submission queues one Google Calendar row as `pending_create`
  with real sync still disabled/dry-run.

## Out Of Scope

This document does not define a data import format. The current fixture command
does not create orders, webform submissions, Google queue rows, or reset/delete
fixture data. Adding those phases should be separate, reviewed changes with
explicit commands, idempotency, and clear rollback/reset behavior.
