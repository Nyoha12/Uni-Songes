# Google Calendar Sync Readiness Audit

Date: 2026-06-08

Scope: static audit of the current local implementation. This document does not
enable real Google Calendar sync, add credentials, call Google APIs, change
business logic, edit `config/sync`, or alter reservation credit behavior.

## Current Implementation

The current booking sync store is the custom database table
`unisonges_structure_booking_gcal_sync`. It is not Drupal Queue API storage. The
table is declared in `unisonges_structure_schema()` and created by update
`unisonges_structure_update_11001()`.

Important fields:

- `sid` and `submission_uuid` identify the webform submission.
- `google_event_id` stores the remote event ID after real sync creates or
  updates an event.
- `sync_status` tracks row state.
- `sync_action` tracks the requested operation.
- `reservation_value` stores the raw booking value.
- `payload_json` stores the generated dry-run event payload.
- `last_error`, `created`, `changed`, `last_synced`, and `cancelled` provide
  audit metadata.

The table has unique keys on `sid` and `submission_uuid`, so queueing currently
merges to one sync row per reservation submission. The queue helper sets
`sync_status` to `pending` and one of the supported actions below.

Supported pending actions:

- `pending_create`: queued on reservation insert after the local credit flow
  succeeds.
- `pending_update`: queued when an existing reservation value changes to a
  non-cancelled value.
- `pending_cancel`: queued when the reservation value changes to a cancellation.

Observed row statuses:

- `pending`: eligible for processing.
- `synced`: real sync completed.
- `skipped`: the row was intentionally skipped, including dry-run processing.
- `error`: processing failed or required configuration was missing.

The sync service processes only rows where `sync_status = pending` and
`sync_action` is one of `pending_create`, `pending_update`, or
`pending_cancel`. Rows are loaded in `changed`, then `id` order, capped by the
configured batch size.

Dry-run behavior is intentionally consuming: with sync enabled and dry-run on,
the service logs the action that would be sent and marks the row `skipped` with
`Dry-run: no Google Calendar request sent.`

Real-call behavior exists as a skeleton:

- `pending_create` creates an event unless `google_event_id` already exists, in
  which case it updates that event.
- `pending_update` updates `google_event_id` when present, otherwise creates an
  event.
- `pending_cancel` deletes `google_event_id` when present, otherwise skips.

The current Google client supports `POST`, `PUT`, and `DELETE` against Google
Calendar v3 with `sendUpdates=none`, a 10 second timeout, and a bearer access
token read from an environment variable. `DELETE` treats HTTP 404 and 410 as
already gone. Other non-2xx responses throw and mark the row `error`.

## Config Gates

The config object is `unisonges_structure.google_calendar`. Current install
defaults are:

- `enabled: false`
- `dry_run: true`
- `calendar_id: ''`
- `timezone: Europe/Paris`
- `batch_size: 10`
- `token_provider: env_access_token`
- `access_token_env_var: UNISONGES_GCAL_ACCESS_TOKEN`

These values disable real sync in two layers:

- When `enabled` is false, cron returns before loading pending rows.
- When `dry_run` is true, pending rows are logged and marked skipped without
  any Google Calendar request.

Additional real-sync guards:

- Non-dry-run processing fails the batch if `calendar_id` is empty.
- Non-dry-run processing fails the batch if the configured token provider cannot
  return a token.
- The admin settings form requires `calendar_id` and a non-disabled token
  provider before saving `enabled = true` with `dry_run = false`.

No `drupal/config/sync/unisonges_structure.google_calendar.yml` file is present
in this worktree. Any future config export policy must keep secrets out of Git
and should export only non-secret defaults if this object is added to
`config/sync`.

## Credential Readiness

The current token support is not enough for production real sync. It reads a
short-lived OAuth access token from `UNISONGES_GCAL_ACCESS_TOKEN` or another
configured environment variable. It does not implement an OAuth authorization
flow, refresh-token rotation, token expiry handling, or secure credential
storage.

Before real sync is enabled, choose and document the credential model:

- OAuth client flow with refresh-token handling stored outside Git and outside
  Drupal config, for example in host environment secrets or a deployment secret
  manager.
- Or a service account only if the target calendar explicitly grants it access
  and that matches the operational ownership model.

Recommended non-secret config surface:

- Calendar ID.
- Token provider mode.
- Environment variable names or secret identifiers.
- Timezone and batch size.

Never commit:

- Access tokens.
- Refresh tokens.
- OAuth client secrets.
- Service account JSON.
- Credential test output that contains bearer tokens or private keys.

Use the narrowest practical Google Calendar scope for event create, update, and
delete access, and define an explicit rotation and revocation process before
production use.

## Runner Strategy

Drupal cron currently calls
`unisonges_structure.booking_calendar_sync->processPendingFromCron()` from
`hook_cron()`. That is acceptable for dry-run and for a first controlled real
sync pilot only after retry behavior is improved.

Safe runner requirements before production:

- Keep the batch size small at first, for example 1 to 10 rows.
- Run at a bounded interval such as every 5 minutes only after dry-run output is
  reviewed.
- Add a lock or lease so two cron invocations cannot process the same row at the
  same time.
- Add retry scheduling before the runner can send real API requests
  unattended.
- Keep stable ordering by `changed` and `id`.
- Keep the per-request timeout bounded.
- Provide a manual one-row dry-run command for admin verification.
- Do not make Google Calendar the source of truth; Drupal/webform_booking
  remains authoritative.

If this grows beyond a small pilot, consider moving execution to Drupal Queue
API or add queue-like lease fields to the existing table. The current custom
table is useful as an audit and mapping table, but it lacks leases, attempt
counts, and next-attempt scheduling.

## Failure And Retry Gaps

Current behavior:

- A thrown client or payload error marks the row `error`.
- There is no automatic retry path for errored rows.
- Missing `calendar_id` or missing token marks every loaded row `error`.
- Transient HTTP errors, timeouts, and rate limits are not separated from
  permanent validation failures.
- There is no attempt count, retry window, or global pause on credential
  failure.

Needed before real sync:

- Add `attempt_count`, `next_attempt_at`, and a lease or `locked_until` field,
  or equivalent queue metadata.
- Classify errors as transient, credential, remote permission, payload, or
  permanent.
- Retry transient failures such as timeouts, HTTP 429, and HTTP 5xx with
  exponential backoff and jitter.
- Stop or pause processing on credential failures such as missing token,
  expired token, invalid grant, or broad HTTP 401/403 failures so cron does not
  burn through pending rows.
- Keep permanent payload errors visible to admins without retrying endlessly.
- Decide how to recover when updating an existing `google_event_id` returns
  not found.
- Ensure create is idempotent enough to avoid duplicate events after a network
  timeout or partial success.
- Store enough last-error detail for diagnosis without logging secrets or large
  response bodies.
- Add an admin-controlled requeue path for reviewed `error` or `skipped`
  dry-run rows.

## Manual Diagnostics Needed

Useful read-only admin diagnostics before enabling real sync:

- Active config summary: `enabled`, `dry_run`, calendar ID presence, timezone,
  batch size, token provider, token environment variable name, and token
  presence without displaying token values.
- Table health: table exists, schema has expected keys and indexes, and row
  counts by `sync_status` and `sync_action`.
- Queue age: oldest pending row, newest pending row, oldest error, and total
  errors.
- Payload health: rows missing `payload_json`, rows with invalid JSON, rows with
  missing start/end/summary, and rows whose reservation value parses as
  cancelled.
- Event mapping health: pending updates or cancels without `google_event_id`,
  duplicate event IDs, and rows with event IDs but non-synced statuses.
- Dry-run preview for one row showing action, submission ID, summary, start,
  end, location, and whether a request would be create, update, or delete.
- Recent log summary from the `unisonges_booking_sync` channel.
- Explicit confirmation that diagnostics do not call Google APIs by default.

An optional diagnostic script can be added later as
`drupal/scripts/diagnose-google-calendar-sync.sh`, but it should remain
read-only and must not call Google APIs unless a future explicitly reviewed
flag is added.

## Production Hold

Production must remain blocked from real sync until credentials and retry logic
are reviewed together.

Keep at least one of these true on production:

- `enabled` remains false.
- `dry_run` remains true.

Also keep these production safeguards in place:

- Do not set `enabled = true` and `dry_run = false` together.
- Do not provision a live `UNISONGES_GCAL_ACCESS_TOKEN` or equivalent provider
  secret for this module.
- Do not configure a live calendar ID together with a live token.
- Do not add a real-sync-specific cron or queue runner beyond normal disabled
  Drupal cron behavior.
- Do not export secrets or real credential material to `config/sync`.
- Do not treat dry-run rows marked `skipped` as proof that production real sync
  is safe.

## Recommended Implementation Phases

1. Diagnostics phase:
   Add read-only diagnostics for active config, table counts, row health,
   payload validity, event mapping, and recent logs. Keep Google API calls out.

2. Credential phase:
   Choose OAuth user flow or service account ownership. Store secrets outside
   Git and Drupal config. Implement token refresh and expiry handling. Add a
   credential health indicator that does not expose secret values.

3. Retry phase:
   Add lease, attempt, next-attempt, error-classification, and requeue support.
   Add transient backoff and a global pause on credential failure.

4. Idempotency phase:
   Harden create/update/cancel behavior against duplicate events, network
   timeouts, missing remote events, and repeated cron runs. Define recovery for
   update/delete not found cases.

5. Local dry-run phase:
   Use local or staging data to verify queue creation, payload contents, row
   status transitions, logs, and diagnostics while `dry_run` stays true.

6. Controlled non-production real-call phase:
   Use a dedicated test calendar and test credentials. Process one row at a
   time. Verify create, update, cancel, retry, and requeue behavior.

7. Production readiness review:
   Review credentials, retry controls, diagnostics, rollback, logging, privacy,
   and admin process. Only then consider setting production `enabled = true`
   with `dry_run = false`.
