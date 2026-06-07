# Local DDEV Fixtures Plan

This plan defines the next safe step for local-only DDEV fixtures. It does not
create data, import a database, change public routes, or alter production
behavior.

## Scope

- Work only in the WSL DDEV worktree and local DDEV database.
- Generate deterministic local data through Drupal, Commerce, and Webform APIs.
- Never import production data or a production database dump.
- Do not run destructive database commands such as `sql-drop`.
- Do not modify `config/sync`, Composer, `.ddev`, Commerce/payment/order logic,
  Google Calendar logic, or `unisonges_structure.module`.

The fixture generator should be a later explicit command under
`drupal/scripts/`. It should refuse to continue if Drupal cannot bootstrap active
config.

## Implemented Phase 1

`drupal/scripts/create-local-fixtures.sh` now provides the first safe local
fixture command.

- `--dry-run` is the default and prints the planned local fixture users.
- The command runs read-only guards for DDEV, Drush, the Drupal `key_value`
  table, Drupal bootstrap, required modules, user credit fields, the reservation
  webform, and Google Calendar disabled/dry-run config.

## Implemented Phase 1b

`drupal/scripts/bootstrap-local-fixture-site.sh` prepares a standard-profile
local DDEV database for the fixture guards without running a full config import.

- `--dry-run` is the default and prints the local-safe module enables and
  allowlisted config creates that would be needed.
- `--apply` is required before any change. It enables only the modules needed
  for the fixture guard surface and imports only the user credit field config
  plus `webform.webform.cours_particuliers_reservation`.
- The import path is allowlisted config-entity creation only. It does not call
  `drush config:import`, does not delete active config, and blocks instead of
  overwriting a local active config entity that already exists with different
  data.
- The script refuses non-local paths such as `/mnt/c`, `/var/www`, and `/srv`,
  verifies DDEV, Drush, the `key_value` table, and keeps Google Calendar real
  sync disabled or absent.
- No fixture users, stores, gateways, products, orders, submissions, or Google
  credentials are created.

## Implemented Phase 2

`drupal/scripts/create-local-fixtures.sh --apply` now creates or updates only
the five `local.fixture.*` users through Drupal user entity APIs.

- Existing fixture user passwords are left unchanged; newly created fixture
  users use the local-only password `local-fixture-only`.
- The command resets only fixture user mail, active status, non-locked roles,
  `field_seances_restantes`, `field_essai_utilise`, and
  `field_pack_expire_le` to the documented baseline.
- `local.fixture.pack_active` receives `field_pack_expire_le` set to today plus
  6 months.
- The command refuses `uid=1`, mail collisions, duplicate lookups, and any
  non-`local.fixture.*` username.
- The user fixture phase itself does not create or update Commerce stores,
  gateways, products, orders, webform submissions, Google Calendar data,
  `config/sync`, Composer files, or `.ddev` files.

## Implemented Phase 3

`drupal/scripts/create-local-fixtures.sh` now includes a guarded Commerce
fixture phase after the user fixture phase.

- `--dry-run` remains the default and prints the exact Commerce fixture changes
  that would be made after active Commerce config prerequisites are present.
- `--apply` can create or update only local fixture Commerce entities:
  `[Local Fixture] Store`, payment gateway `local_fixture_manual`, and products
  or variations with `LOCAL-FIXTURE-*` SKUs.
- The Commerce phase uses Drupal entity APIs only. It does not use raw SQL,
  does not run `drush config:import`, and does not modify `config/sync`,
  Composer files, `.ddev`, orders, webform submissions, or Google Calendar data.
- If a non-fixture Commerce store already exists, the fixture products use it
  without changing that store. A fixture store is created only when no store is
  available.
- If the required active Commerce currency, product types, variation types,
  store type, order item type, or manual payment plugin are missing, the
  Commerce phase stops before creating stores, gateways, products, or
  variations.
- The current local blocker for a standard-profile fixture site is the missing
  active Commerce config for `commerce_currency.EUR` plus the four course
  product and variation types. The next requirement is a separate reviewed local
  bootstrap step that creates or imports only those allowlisted active config
  entities without a broad config import.

## Fixture Source Strategy

Fixtures should be generated locally, not imported. The future command should:

- run from `drupal/` inside the DDEV project;
- use Drush and Drupal entity APIs instead of raw SQL where possible;
- create or update only objects identified by stable local fixture keys;
- keep all fixture identifiers obviously local, using `local.fixture.*` users,
  `LOCAL-FIXTURE-*` SKUs, and `[Local Fixture]` labels;
- write only to the local active database.

If the local database is empty or Drupal tables such as `key_value` are missing,
the command should stop with a clear message and point back to
`docs/dev/ddev-testing.md`. Fixture generation should not install Drupal, import
production data, or rebuild the database.

## Idempotency Rules

The future fixture command should be safe to run repeatedly:

- Look up users by username and mail before creating them.
- Look up products and variations by SKU before creating them.
- Look up the Commerce store and payment gateway before creating them.
- Reset fixture-owned user credit fields to the documented baseline on each run.
- Update only fixture-owned labels, prices, and status values.
- Leave non-fixture users, orders, products, submissions, and config untouched.
- Keep test-created orders and submissions append-only unless an explicit
  fixture reset command is run.

Fixture-owned data should be identifiable without guessing:

- usernames: `local.fixture.*`;
- mails: `local.fixture.*@example.invalid`;
- SKUs: `LOCAL-FIXTURE-*`;
- labels/titles: `[Local Fixture] ...`;
- optional order metadata: `unisonges_fixture_key`.

## Reset And Rollback Strategy

Rollback must be targeted and non-destructive. A later implementation can add an
explicit reset mode, but it should:

- default to dry-run output that lists what would be removed or reset;
- require an explicit flag such as `--apply-reset` before changing data;
- delete or reset only records matching the fixture keys above;
- remove Google Calendar queue rows only when they are linked to fixture
  webform submissions;
- delete fixture webform submissions before deleting fixture users;
- delete fixture orders, payments, and order items before deleting fixture
  products and variations;
- never call `sql-drop`, `site:install`, `config:import`, or production
  services.

For normal test repeatability, prefer resetting fixture user credit fields and
creating fresh fixture orders/submissions over deleting broad tables.

## Test Users

Create only non-uid-1 accounts with the `authenticated` role. Password handling
should be local-only, for example from `LOCAL_FIXTURE_PASSWORD`, with a harmless
default documented by the future script.

| Username | Mail | Baseline fields | Purpose |
| --- | --- | --- | --- |
| `local.fixture.no_credit` | `local.fixture.no_credit@example.invalid` | `field_seances_restantes=0`, `field_essai_utilise=0`, no `field_pack_expire_le` | Reservation denial with no credits. |
| `local.fixture.with_credit` | `local.fixture.with_credit@example.invalid` | `field_seances_restantes=3`, `field_essai_utilise=0`, no `field_pack_expire_le` | Successful reservation and credit decrement. |
| `local.fixture.checkout` | `local.fixture.checkout@example.invalid` | `field_seances_restantes=0`, `field_essai_utilise=0`, no `field_pack_expire_le` | Checkout and order-completion credit grants. |
| `local.fixture.trial_used` | `local.fixture.trial_used@example.invalid` | `field_seances_restantes=0`, `field_essai_utilise=1`, no `field_pack_expire_le` | `cours_essai` cap validation. |
| `local.fixture.pack_active` | `local.fixture.pack_active@example.invalid` | `field_seances_restantes=4`, `field_essai_utilise=0`, `field_pack_expire_le` set to a future date | Pack expiry and reservation behavior with active pack credits. |

The generator should not create an administrator account and should not use
`uid=1` for functional tests.

## Commerce Store, Gateway, Products

Fixtures use a local Commerce store and a local manual/test payment gateway
only. If a store already exists, the command uses it without overwriting
non-fixture business data. If no store exists, it creates a local fixture store
with an `example.invalid` mail address and EUR as the default currency.

Payment assumptions:

- use the local gateway `local_fixture_manual` with the Commerce `manual`
  plugin;
- do not require PayPal, Stripe, secrets, callbacks, or external network access;
- keep order completion local and deterministic;
- assert that credits are granted only after the order reaches `completed` and
  is paid according to the local test flow.

Minimum fixture products and variations:

| Product type | Variation type | SKU | Expected credit behavior |
| --- | --- | --- | --- |
| `cours_essai` | `cours_essai` | `LOCAL-FIXTURE-COURS-ESSAI` | Grants one trial credit only if `field_essai_utilise` is false. |
| `cours_deb_inter` | `cours_deb_inter` | `LOCAL-FIXTURE-COURS-DEB-INTER` | Grants `quantity` credits. |
| `cours_avance` | `cours_avance` | `LOCAL-FIXTURE-COURS-AVANCE` | Grants `quantity` credits. |
| `pack_4_deb_inter` | `pack_4_deb_inter` | `LOCAL-FIXTURE-PACK-4-DEB-INTER` | Grants `4 * quantity` credits and sets or extends `field_pack_expire_le` by 6 months. |

The fixture prices are deterministic local test values: 20 EUR for trial,
40 EUR for beginner/intermediate, 40 EUR for advanced, and 100 EUR for the
pack of four. The exact values are not business facts; the important part is
that local checkout can create paid/completed orders without a real payment
provider.

## Reservation Webform Assumptions

The active local site should already contain the webform
`cours_particuliers_reservation` from project config. The fixture command should
verify, not recreate, that:

- the webform exists and is open;
- the `reservation` element exists;
- the `reservation` element type is `webform_booking`;
- anonymous users can view slots but only authenticated users can submit;
- required fields can be populated with deterministic local values.

The current config has a 60-minute booking slot duration, one seat per slot, and
a visible 30-day window. If no available slot can be derived from active config,
the generator should fail clearly instead of changing webform config.

## Google Calendar Dry-Run Guard

Local fixture tests should verify queue behavior only. They must not perform
real synchronization.

Before reservation tests, the future command should verify active config for
`unisonges_structure.google_calendar`:

- `enabled` is false;
- `dry_run` is true;
- `calendar_id` is empty or clearly local;
- no real access token is required.

Submitting `cours_particuliers_reservation` should create or update a local queue
row with `pending_create` and a dry-run payload. Tests should assert the queued
row, not call Google APIs.

## Future Tests Enabled By Fixtures

Once the fixtures exist, Codex can run active local DDEV checks for:

- readiness of required modules, fields, product types, and webform elements;
- checkout with the local manual/test gateway;
- order completion granting user credits once;
- `cours_essai` granting at most one trial credit per user;
- `cours_deb_inter` and `cours_avance` granting quantity credits;
- `pack_4_deb_inter` granting four credits per quantity and updating expiry;
- reservation denial for a connected user with zero credits;
- successful reservation for a connected user with credits;
- exact one-credit decrement after reservation submission;
- Google Calendar queue row creation as `pending_create` while real sync remains
  disabled or dry-run.

## Proposed Implementation Sequence

1. Done: add `drupal/scripts/create-local-fixtures.sh` with `--dry-run` as the
   default and `--apply` guarded from writes.
2. Done: implement read-only environment guards for DDEV presence, Drupal
   bootstrap, required modules, credit fields, webform, and Calendar config.
3. Done: add a local-only bootstrap command for standard-profile DDEV databases
   that need the module, user field, and reservation webform prerequisites.
4. Done: implement idempotent local fixture user creation/update only.
5. Done: add guarded local store, gateway, product, and variation fixture
   creation after the user fixture phase.
6. Next: add a reviewed local bootstrap step for only the missing active
   Commerce config prerequisites: EUR currency and the four course product and
   variation types.
7. Add focused local test commands for checkout/order completion and reservation
   submission.
8. Add an explicit reset mode only after fixture creation is working and covered
   by dry-run output.
