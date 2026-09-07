# Google Calendar state foundation 2026

Date: 2026-09-02

Implementation base: `origin/release/prod` at
`2ffa2538204f0705dadf6faebceef8c77ebcbfc2`

Design/readiness source, read with `git show` and not modified:

- branch `codex-design-google-calendar-production-readiness`;
- commit `0a0542fb3c1c074cd33f472b0b35647abffdc7e3`;
- `docs/functional/google-calendar-production-readiness-2026.md`;
- `docs/functional/google-calendar-owner-setup-checklist-2026.md`.

## Outcome and activation boundary

This change implements only a durable, testable state foundation inside the
existing owning module. It does not migrate legacy rows, connect the new state
service to reservation hooks, enable a scheduler, load a credential, require a
Calendar ID, or call Google.

The production hold is enforced in several independent places:

- fresh-install config has `enabled: false`, `dry_run: true`, and
  `token_provider: disabled`;
- update `unisonges_structure_update_11004()` forces those same active-control
  values while adding the schema;
- the existing configuration form cannot enable processing, inspect an
  environment variable, or edit Calendar/auth wiring;
- `unisonges_structure_cron()` does not resolve the legacy worker or client;
- a direct call to `BookingCalendarSyncService::processPending()` encounters a
  non-configurable `GoogleCalendarActivationBoundary` before config, table,
  backlog, credential, or client access;
- the registered `GoogleCalendarClient` is itself a dependency-free fail-closed
  stub: credential probing returns false and every mutation throws the fixed
  `state_foundation_inactive` reason before config, environment, or HTTP access;
- existing legacy rows receive NULL in every new state field and cannot be
  claimed by the new repository;
- there is no candidate-selection method or automatic backlog loop in the new
  repository.

Existing reservation hooks continue their already deployed local legacy-intent
write. That preserves booking behavior and backlog. It is not a Google write,
and no consumer can process it in this release.

## First audit

The audit covered the complete implementation, both design documents, current
tests/update hooks, and all open pull-request changed files. Runtime config and
database contents were not inspected, so every existing row remains
unclassified and active-only config is treated as unknown.

### Ownership

`unisonges_structure` owns the entire current lifecycle:

- schema/update hooks: `unisonges_structure.install`;
- reservation insert/update and pay-on-site requeue producers:
  `unisonges_structure.module`;
- raw reservation parser and payload creation: `unisonges_structure.module`;
- cron, worker, disabled client stub, services, config schema, form, and admin-only
  settings route: the same module.

No open PR changes `unisonges_structure.install`, `.services.yml`, `.module`, or
`src/GoogleCalendar/**`. PR #107 changes only its two design documents. PR #103
changes `core.extension.yml`, which this change deliberately does not touch.

### Current field classification

| Current field/key | Classification | Foundation treatment |
|---|---|---|
| `id` | reusable | Stable internal record ID and CAS target. |
| `sid` | migratable; unsafe to expose | Preserved for local source linkage; omitted from diagnostics. |
| `submission_uuid` | migratable; unsafe to expose | Preserved. New initialized records derive an environment-scoped opaque reference; diagnostics never return the UUID. |
| `google_event_id` | migratable; restricted | Preserved exactly. A finalizer may only confirm the already stored ID; it cannot replace it. |
| `sync_status` | ambiguous/migratable | Retained as legacy `pending/synced/skipped/error`; never interpreted as the reviewed state without guarded classification. |
| `sync_action` | ambiguous/migratable | Retained as legacy `pending_*`; new `operation` is independent. |
| `reservation_value` | obsolete; unsafe to expose | Retained only for later migration/scrub compatibility; never selected by the state repository or diagnostic reader. |
| `payload_json` | obsolete; unsafe to expose | Same. It may contain names, telephone, address, notes, payment/order facts, SID/UUID, and schedule data. |
| `last_error` | obsolete; unsafe to expose | Same. It may contain a raw exception or remote body excerpt. |
| `created` | reusable | Audit timestamp for both models. |
| `changed` | reusable but insufficient alone | Monotonic timestamp; CAS correctness uses `generation` and exact expected fields. |
| `last_synced` | obsolete/ambiguous | Preserved. Legacy cancel also wrote it, so it is not proof of a present event. |
| `cancelled` | obsolete/ambiguous | Preserved. It records queued intent, not proved remote absence. |
| unique SID/UUID keys | migratable | Preserved; no constraint change before collision/source-detachment review. |
| legacy queue/event/timestamp indexes | migratable/obsolete mix | Preserved. Additive state/lease/diagnostic indexes are separate. |

No new uniqueness or foreign-key constraint is added. That is intentional until
legacy collisions, missing sources, target ownership, and event IDs have been
audited in a real database.

### Current behavior classification

| Behavior | Classification | Reason |
|---|---|---|
| One merged row per SID | reusable desired-state idea; concurrency-unsafe implementation | Coalescing is useful, but legacy merge can overwrite a row while an old worker is acting. |
| Stable UUID/event mapping | migratable | Must be preserved through future cutover. |
| Insert queues create after booking-right success | reusable producer point | Not connected to the new state model in this static phase. |
| Reservation-value update queues update/cancel | incomplete/migratable | Other managed-field changes and deletion are missing. |
| Pay-on-site completion queues update | ambiguous | It can overwrite a newer cancel in the legacy model. |
| Payload start/end and IANA zone | migratable | RFC3339 concept is useful; DST fold and immutable projection rules remain future work. |
| Summary/location/description/private properties | unsafe to expose/send | They include personal, address, free-note, payment, order, SID/UUID/UID data. |
| Pending-row selection by `changed,id` | reusable ordering; unsafe claim | There is no legacy lease or CAS. |
| Dry-run | obsolete/ambiguous | It consumes backlog as `skipped` and logs summary/time data. |
| POST/PUT/DELETE client | obsolete prototype removed from the registered implementation | The current client is an inert stub with no credential or HTTP dependency. A future dedicated worker/client needs separate review. |
| Event-ID persistence after create | migratable; ambiguity-unsafe | Timeout after remote success can leave no local ID and lead to duplicates. |
| Raw error persistence/logging | unsafe to expose | It is not an allowlisted taxonomy. |
| Missing Calendar/token marks batch `error` | obsolete | It drains work rather than applying bounded retry/circuit behavior. |
| Drupal cron invokes worker | obsolete for remote work | Now hard-disabled without service resolution. |
| Existing admin settings route | reusable only as an internal config surface | No new route was added; remote/auth controls are unavailable in this phase. |
| Existing tests | missing for Calendar lifecycle | Prior scripts only count rows or document historical probes. |
| Updates 11001/11002/11003 | reusable history | 11001 and 11003 now retain frozen historical table specs; 11004 is additive, independently guarded, and also freezes its own specs. |
| Inbound busy-slot stub | obsolete/ambiguous | Google must not become the reservation source of truth. |

## Architecture decision: A

The existing module and table are extended through an additive update hook.
This is narrower and safer than a dedicated replacement module because it:

- preserves deployed SID, UUID, event-ID, and unresolved legacy rows in place;
- keeps one table owner and prevents a second active outbox;
- avoids cross-module producer ordering and circular/dual-write ownership;
- requires no module activation or `core.extension.yml` change;
- installs with remote processing disabled;
- supports a future expand/bridge/classify/backfill/validate/cutover sequence;
- rolls back at code level without a down migration, while preserving all
  additive data.

Returning to the pre-foundation worker after migrated operational state exists
will not be safe. Rollback must stay at or above a later tested compatibility
floor. This PR itself performs no migration, so its immediate rollback leaves
only nullable additive columns/indexes and preserved config/legacy data.

## Additive durable representation

The current table gains nullable columns so deployed legacy rows are held rather
than silently classified:

| New field | Contract |
|---|---|
| `reservation_ref` | Full SHA-256 opaque reference derived from a fixed domain label, immutable environment namespace, and source UUID. |
| `operation` | Exactly `create`, `update`, or `cancel`; independent of state. |
| `desired_revision` | Monotonic source revision, independent of internal CAS generation; duplicate and out-of-order intent fails closed without resetting retry state. |
| `processing_operation` | Operation snapshot captured by the lease. |
| `state` | One of the exact eight reviewed public states. |
| `generation` | Monotonic row/CAS generation. Claim, finalization, recovery, and newer desired intent increment it. |
| `attempt_count` | Attempts within the current desired-operation retry window. |
| `first_queued_at` | First operational queue timestamp. |
| `retry_window_started_at` | Anchor for the current operation's 48-hour retry budget. |
| `last_attempt_at`, `next_retry_at` | Durable attempt and eligibility times. |
| `claim_mode` | Internal `work` or `reconciliation`; this is not a public state or fourth intended operation. |
| `lease_owner`, `lease_expires_at` | Opaque worker owner and expiry. |
| `lease_generation` | Claimed generation required for renewal/finalization. A newer intent invalidates the old finalizer. |
| `last_successful_sync_at` | Latest successful create/update/cancel finalization time. |
| `remote_etag` | Restricted bounded remote version metadata when safely available. |
| `last_error_code`, `last_error_summary` | Allowlisted code and its exact fixed summary only. |

Existing `id`, `submission_uuid`, `google_event_id`, `created`, and `changed`
remain the durable identity/mapping/audit base. New event identity is persisted
only through a dedicated NULL-to-value CAS before ordinary work; identity
generation and its HMAC key remain deferred. The repository never selects
legacy payload, raw reservation, raw error, payment, billing, note, credential,
authorization, response-body, or stack-trace fields.

## State and operation model

Public states are exactly:

1. `queued`
2. `processing`
3. `synced`
4. `retryable_failure`
5. `permanent_failure`
6. `cancel_pending`
7. `cancelled`
8. `reconciliation_required`

Intended operations are exactly `create`, `update`, and `cancel`.
Reconciliation is an explicit claim mode orthogonal to desired operation.

### Transition matrix

| From | Event/guard | To |
|---|---|---|
| `queued` create/update | ordinary atomic claim | `processing` |
| `cancel_pending` cancel | ordinary atomic claim | `processing` |
| due `retryable_failure` | ordinary claim, or explicit claim when retaining reconciliation mode | `processing` |
| `reconciliation_required` | explicit reconciliation claim only | `processing` |
| `processing` create/update | successful owner/generation/lease finalization with exact precommitted event ID | `synced` |
| `processing` cancel | successful owner/generation/lease finalization | `cancelled` |
| `processing` | classified transient result with budget | `retryable_failure` |
| `processing` | permanent result or exhausted budget | `permanent_failure` |
| `processing` | ambiguous result | `reconciliation_required` |
| `processing` | expired lease; no dispatch marker exists in this phase | `reconciliation_required` |
| exhausted/over-age `retryable_failure` | atomic terminalization on claim attempt | `permanent_failure` |
| `synced` | strictly newer update | `queued` |
| `synced` | strictly newer cancel | `cancel_pending` |
| `cancel_pending` with no event ID and zero attempts | proved local cancellation | `cancelled` |
| `cancelled` | strictly newer restoration/create | `reconciliation_required` (old deleted incarnation is preserved) |
| `queued` | strictly newer cancel | `cancel_pending` |
| `cancel_pending` | strictly newer update | `queued` |
| `retryable_failure` | strictly newer create/update or cancel | `queued` or `cancel_pending` |
| `processing`, `reconciliation_required`, `permanent_failure` | newer desired operation | same public state; generation/desired operation advances and any lease snapshot remains accountable |

Queued/cancel-pending coalescing of a compatible newer operation may also keep
the same state while incrementing generation. A queued/retry/permanent/
reconciliation CREATE cannot silently become UPDATE, or vice versa, merely due
to a repeated edit: its remote-existence operation is preserved. Permanent
failure stays latched; reconciliation stays reconciliation-first. Every other
state/event pair is rejected with a fixed machine reason and no mutation.

## Lease and compare-and-swap contract

The safe service parameter is 120 seconds; the lifecycle rejects values outside
30–900 seconds. There is no process-local lock.

Claim:

1. Before ordinary work, a dedicated CAS may persist a caller-supplied,
   base32hex-compatible event ID only when the stored value is NULL. The
   foundation never generates that ID or loads its future secret key.
2. Caller supplies record ID, expected generation, expected state, expected
   attempt count, and a 128-bit opaque worker identity.
3. Pure lifecycle validates state eligibility, retry attempt/age budget, due
   time, claim mode, event identity, clock direction, and absence of an
   unexpired lease. Explicit reconciliation may claim a no-ID quarantine row;
   a transient probe retains reconciliation claim mode and a verified set-once
   binding path, but it cannot finalize remote success before that binding.
4. One parameterized Drupal update matches every expected value, including
   NULL with `IS NULL`.
5. The update snapshots `processing_operation`, increments generation and
   attempt count, records `lease_generation`, owner, and expiry.
6. Exactly one affected row is success; zero is a clear `cas_conflict`. Query
   or write exceptions return fixed `storage_failure`, never false contention.

Renewal requires the same owner, state, generation, desired revision, lease
generation, exact processing snapshot, event ID/ETag, `changed`, and exact
prior expiry, plus an atomic `lease_expires_at > now` predicate. Clock rollback
relative to persisted history fails closed rather than extending the lease.

Success/failure finalization requires the exact processing state, owner,
generation, lease generation, operation snapshot, attempt count, and unexpired
lease. It increments generation and clears lease fields. A remote success cannot
replace `google_event_id`; it must equal the precommitted value already stored.
An absent safely returned ETag clears any older ETag rather than retaining stale
remote version metadata. Repeated or stale finalization affects zero rows.

When an owner observes exact or later expiry, renewal/finalization returns an
atomic transition to `reconciliation_required` instead of merely rejecting and
leaving a resumable processing row. This makes that observed timeout durable;
a later clock rollback cannot resurrect it. The real two-connection/database
clock boundary remains an explicit pre-merge runtime gate.

Any valid worker may recover an expired lease, but the CAS still matches the
dead owner's identity, exact expiry, lease generation, current row generation,
state, operation snapshot, and attempt count. Because this phase deliberately
has no durable dispatch marker and no HTTP, all lease expiry is treated as
potentially ambiguous and enters `reconciliation_required`. A later remote
protocol may safely distinguish proved pre-dispatch expiry only after adding and
testing that evidence.

## Retry and failure policy

For attempt `n` starting at one:

```text
base = min(6 hours, 60 seconds * 2^(n - 1))
jitter = SHA-256(opaque mapping/generation seed) mod
         (min(30 minutes, base / 4) + 1)
delay = min(6 hours, base + jitter)
delay = max(delay, valid Retry-After within both bounds)
```

The deterministic seed is an opaque reservation reference plus generation, not
a UUID, person, Calendar ID, or event ID. Attempt 10 is terminal. The next retry
must be strictly earlier than 48 hours after `retry_window_started_at`; the
deadline itself is not eligible. Maximum individual delay is six hours. A
`Retry-After` minimum beyond six hours or the remaining 48-hour window is never
shortened; automatic retry stops with fixed `retry_exhausted` instead.

The pure classifier accepts only category, numeric HTTP status, a boolean saying
whether a remote result was possible, and a small allowlisted reason. It never
accepts a response body or exception message.

- network/timeout before dispatch: retryable;
- timeout or 5xx after a mutation may have applied: reconciliation required;
- 429 and quota/rate-limit 403: retryable;
- pre-result 5xx: retryable;
- selected temporary authentication: retryable within the same bounds;
- 404/410/409/412: reconciliation required in this foundation;
- payload 400/422 and non-temporary permission/permanent facts: permanent;
- unknown category: permanent fail-closed.

Detailed endpoint/reason semantics, target reachability proof, auth/target
circuit breaker, 401 refresh, deterministic-ID 409 handling, and real response
handling are intentionally deferred with the remote client phase.

## Diagnostic data boundary

`BookingSyncDiagnosticReader` is an internal service only. No route, menu item,
controller, JSON endpoint, or member-facing field is added.

Its exact output is:

- record ID;
- environment-scoped opaque reservation reference;
- operation and state;
- attempts;
- last attempt, next retry, and lease expiry;
- boolean remote-event-ID presence;
- allowlisted redacted error code.

It never outputs SID/UUID, event ID/ETag, reservation time/value, payload,
personal/contact/address/note data, payment/order data, raw error/summary,
Calendar ID, token/provider/locator, response, authorization, or stack trace.
Unknown stored error codes normalize to `unclassified_failure`.

## Future legacy migration, not executed here

Update 11004 performs only expand plus activation hold. It does not update a
single existing mapping row. The `LegacyBookingSyncClassifier` is a pure future
planning helper with no database/client/logger dependency:

- it requires an explicit environment namespace;
- pending rows require separately reloaded current Drupal truth;
- pending rows also require an explicit separately verified legacy target
  binding before any future queued/cancel-pending plan;
- pending create/update without an event ID is quarantined because a legacy
  request may have succeeded before local ID persistence;
- pending cancel without an event ID is quarantined because attempt history is
  absent;
- legacy `synced` and `skipped` become `reconciliation_required`;
- legacy `error` becomes visible `permanent_failure`;
- malformed/unknown status or action becomes `reconciliation_required`, and an
  ambiguous operation remains NULL rather than being guessed;
- missing/malformed source identity or a malformed non-empty event ID is
  quarantined with a fixed code while the raw legacy value remains untouched;
- it returns only opaque reference, state/operation, event-ID-presence and
  preservation booleans, fixed error fields, and review flag;
- the existing event ID, SID, UUID, failure, and row remain untouched in place.

A later PR must implement an idempotent, bounded, restartable
expand/compatibility-bridge/preflight/CAS-backfill/classify/validate/cutover
process. It must obtain legacy target ownership from the owner, audit collisions
before constraints, stop new sensitive legacy writes before scrub, produce
count-only output, and never infer legacy `synced` as proof of convergence.

Rollback limitation: additive columns/indexes are retained. No down migration
or event-ID deletion is supported. Once future migrated state is active, the old
prototype worker cannot be used as a rollback floor.

## Deterministic static test harness

Command, run without Drupal bootstrap:

```bash
php drupal/web/modules/custom/unisonges_structure/tests/static/google_calendar_state_foundation_test.php
```

Current result: `OK 4235 assertions`.

The harness covers:

- every allowed and rejected state/operation/event combination;
- create/update/cancel initial records, set-once identity CAS, and proved local
  cancellation without a remote identity;
- first claim and concurrent second-claim loss;
- owner renewal, non-owner rejection, and fail-closed clock rollback;
- owner finalization, non-owner/stale/repeated rejection;
- immutable event ID;
- explicit NULL-aware reconciliation claim and verified identity binding;
- no-ID reconciliation transient failure, explicit due retry, later verified
  binding, and retry exhaustion;
- retry not due/due;
- attempts one through ten, deterministic jitter, 48-hour and six-hour caps;
- 429, 5xx before/after possible result, timeout before/after possible result,
  temporary auth, payload/permanent and reconciliation categories;
- monotonic desired revision, duplicate rejection, and CREATE/UPDATE-preserving
  coalescing;
- newer desired generation during processing and from final states;
- owner-observed exact timeout plus expired-lease recovery/race by a different
  worker;
- atomic retry terminalization when attempt or 48-hour budgets are exhausted;
- redacted diagnostic output and unknown-code handling;
- malformed/error legacy classification;
- clock rollback/forward behavior;
- a directly invoked fail-closed client with source tripwires proving no token,
  config, authorization, HTTP client, or Google endpoint capability;
- activation/source tripwires, affected-row CAS, NULL-aware conditions,
  dedicated event-ID mutation shape, storage-failure separation, and no raw SQL
  in the repository;
- all 19 nullable foundation fields, the claim/recovery indexes, frozen
  historical/update specs, partial-rerun guards, and trusted config save.

The harness prints only its assertion count. Fixtures are synthetic; it emits no
credential, authorization value, Calendar/event value, or personal reservation
data.

## Intentionally absent behavior

This change includes no:

- Google API call or HTTP-capable client implementation;
- service-account/OAuth/HMAC/token loading or library;
- Calendar ID/target registry requirement;
- event-ID generation/HMAC key access (only atomic set-once persistence exists);
- payload creation/change to Google fields;
- scheduler, cron processing, queue selection, heartbeat, or backlog migration;
- config import or `core.extension.yml` change;
- route, permission, admin action, public/member diagnostic, or theme change;
- booking form, Commerce, PayPal, dashboard, or reservation Twig change.

The registered client is a fail-closed stub and contains no config, environment,
authorization, endpoint, or HTTP dependency. The legacy worker's unreachable
branch structure is retained only for future migration context.

## Later runtime gates (documented, not run)

This PR must remain draft until all of the following pass on its final SHA:

1. DDEV fresh-install schema validation confirms all field/index specs.
2. DDEV upgrade runs 11004 from representative legacy schemas, reruns partial
   field/index cases idempotently, preserves row counts/SID/UUID/event IDs/raw
   unresolved legacy evidence, and leaves every legacy `state`/`operation` NULL.
3. MySQL two-connection/process tests prove one claim, owner-only renewal,
   stale/repeated finalizer zero writes, newer-intent fencing, and cross-worker
   expired-lease recovery. They must also exercise expiry crossing between the
   application-time lifecycle decision and SQL execution; database-time fencing
   or an explicitly reviewed lease-validity boundary is required before merge.
4. A loopback fake Google server later proves deterministic precommitted IDs,
   create/update/cancel idempotency, ETag behavior, both timeout positions,
   401/403/404/409/410/412/429/5xx semantics, and zero forbidden payload/log
   data. This foundation has no client integration to run that matrix yet.
5. The PR #107 owner decisions are resolved: legacy/production target ownership,
   authentication/secret custody, privacy/retention/scrub, public Appointment
   Schedule disposition, scheduler/alert/SLO ownership, pilot, rollback floor,
   RPO/RTO, and independent review sign-off.
6. A dedicated test-calendar pilot passes before any production scheduler or
   real Google write is considered.

Until every gate passes, keep `enabled = false`, keep the deployment write gate
absent/false, do not load credentials, do not migrate backlog, and do not enable
or invoke any Google worker.
