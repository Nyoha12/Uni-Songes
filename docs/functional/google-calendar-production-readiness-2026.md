# Google Calendar production readiness 2026

Date: 2026-09-02

Audited base: `origin/release/prod` at `8cc82f9af6899aedc14490931c415293d0bdf0cb`

Scope: static repository audit and implementation plan. This change does not
enable synchronization, change a public route, call Google Calendar, inspect a
runtime secret, or mutate an environment.

## Decision and production hold

The repository contains a useful outbound synchronization prototype, but it is
not production-ready. It can enqueue one row per course reservation, build a
Calendar payload, and call create, update, and delete endpoints from Drupal
cron. Its safe repository defaults are disabled plus dry-run. However, the
current implementation has no renewable authentication, safe create
idempotency, retry schedule, lease, poison-item state, reconciliation, or queue
administration. Its payload and error handling also exceed the acceptable
privacy boundary.

**Production writes must remain blocked.** Until phase 10, the production
runtime's deployment-owned write gate, unavailable to the Drupal UI, must be
absent or false. Phase 9 may open only the separate pilot runtime's gate, whose
mode, identity, and immutable target are test-only. The gate is checked before
selecting a row, resolving a target, or reading a credential. The current
`enabled` flag is UI-controlled, so it is not that gate. `dry_run = true` is not
a sufficient hold either because the current dry-run consumes pending rows by
marking them `skipped`.

Before PR 1, operators must treat the module README's current "Real sync"
instructions as prototype-only and must not enable them. PR 1 establishes this
truth table and its automated tests:

| Deployment mode | Write gate | `enabled` | `dry_run` | Allowed claim cohort | Derived execution state and behavior |
|---|---:|---:|---:|---|---|
| `off` | any | any | any | any | `off`: record desired state only; no selection, remote-target resolution, secret access, or HTTP |
| `pilot`/`live` | absent/false | any | any | any | `blocked`: record desired state only; no selection, remote-target resolution, secret access, or HTTP |
| `pilot`/`live` | true | false | any | any | `disabled`: record desired state only; no selection, secret access, or HTTP |
| `pilot`/`live` | true | true | true | any | `preview`: build/read the current projection without a claim or row mutation; no secret access or HTTP |
| `pilot`/`live` | true | true | false | absent/`none`/unknown | `held`: record desired state only; no selection, target/secret access, or HTTP |
| `pilot`/`live` | true | true | false | `smoke` or `ordinary` | `pilot-write`/`live-write`: prepare and claim only rows in the exact allowed cohort after target, auth, schema, and privacy gates pass |
| absent/unknown | any | any | any | any | `invalid`: fail closed before selection, target/credential access, or HTTP |

Deployment mode, write gate, allowed claim cohort, target binding, principal
fingerprint, and a monotonically increasing `activation_generation` form one
atomically published deployment tuple; `enabled` and `dry_run` are Drupal
controls, and the last column is derived health, not another independent mode.
The dedicated worker requires an exact match between its deployment snapshot and
a non-secret, deployment-command-owned runtime-control row. Any missing field,
generation mismatch, or partial publication derives `invalid`. Every accepted
control change increments the generation, so a worker holding an older snapshot
cannot claim or authorize dispatch.

The cohort defaults to `none`; every new mapping defaults to `ordinary`. A
deployment-only, audited, one-item command may change an exact mapping to
`smoke` only while the write gate is false and the row has no lease. It uses a
server-loaded mapping identifier, CAS, and no Calendar secret or client; no
Drupal form or public endpoint can set the cohort. Changing mode, cohort,
target, or principal is rejected unless the gate is false, the dedicated
scheduler is stopped/drained, and there are zero active or dispatch-authorized
leases. Gate-off is published to the control row first; claims serialize against
that row, and operators then account for leases before publishing a new tuple.
Direct `smoke -> ordinary` while the gate is true is invalid.

`pilot` accepts only the separately bound test target; `live` accepts only the
production target. Safe defaults for fresh install and conservative update-hook
behavior for existing sites are versioned. Enqueueing continues in every state
so Drupal intent is durable; preview never consumes it. The table governs new
preparation/claims: the worker applies the generation and cohort predicate in its
first candidate query, before target resolution or either secret, so a held row
cannot be identity-prepared or claimed. Turning a gate off cannot cancel a
request already dispatch-authorized by the final pre-HTTP CAS; a still-valid
finalizer may persist its safe result without making another request, and
operators account for every such in-flight lease.

The recommended authentication model is a dedicated service account with
writer access to one private, shared secondary calendar. The calendar must be
owned by a durable Uni-Songes organizational account, not by the service
account or a volunteer's personal account. Domain-wide delegation is neither
needed nor permitted for this integration. The owner decisions and setup gate
are in [google-calendar-owner-setup-checklist-2026.md](google-calendar-owner-setup-checklist-2026.md).

## Audit method and evidence boundary

The inventory below is based only on tracked repository files. Runtime Drupal
configuration, database contents, scheduled jobs, Google Cloud configuration,
the target calendar ACL, and production state were not inspected. Consequently:

- repository fallbacks and update-hook defaults are facts about code, not proof
  of the active production values;
- historical test statements in tracked documentation are identified as such,
  not treated as a reproducible automated suite;
- no credential presence or value was queried;
- no Google endpoint was called.

Primary evidence:

- schema and defaults:
  `drupal/web/modules/custom/unisonges_structure/unisonges_structure.install:11-108,219-256`;
- reservation hooks and payload:
  `drupal/web/modules/custom/unisonges_structure/unisonges_structure.module:2350-2734,2785-2931`;
- worker:
  `drupal/web/modules/custom/unisonges_structure/src/GoogleCalendar/BookingCalendarSyncService.php:82-403`;
- HTTP client:
  `drupal/web/modules/custom/unisonges_structure/src/GoogleCalendar/GoogleCalendarClient.php:50-197`;
- settings and service wiring:
  `GoogleCalendarSettingsForm.php:32-174`,
  `unisonges_structure.services.yml:1-21`, and
  `unisonges_structure.routing.yml:1-7`;
- versioned automated-cron interval:
  `drupal/config/sync/automated_cron.settings.yml:1-3`;
- configuration-export absence: there is no tracked
  `drupal/config/sync/unisonges_structure.google_calendar.yml`;
- local-test history:
  `docs/functional/reservation-first-course-tunnel-2026.md:504-534` and
  `drupal/scripts/test-local-commerce-credit-flow.sh:564-573,699-765`.

Line numbers describe the audited base and can move in later implementation
PRs. Code references take precedence over the older comments that still call
the integration a dry-run skeleton.

## Factual repository inventory

| Area | What exists on the audited base | Classification |
|---|---|---|
| Booking slot configuration | The versioned `reservation` Webform element is authenticated for creation/update, exposes 30 days, 60-minute slots, capacity/max booking 1, and a 09:00-16:30 interval (`drupal/config/sync/webform.webform.cours_particuliers_reservation.yml:15-35`). | Implemented and versioned |
| Reservation source and ownership | The source is Webform `cours_particuliers_reservation`. Its submission SID and UUID identify the reservation, and its Drupal owner UID identifies the student. The reservation-first form explicitly creates the submission with the current account UID (`ReservationFirstCourseTunnelForm.php:1872-1905`). | Implemented |
| Enqueue on create | A valid submission is enqueued as `pending_create` only after the course-right consumption path succeeds (`unisonges_structure.module:2350-2429`). | Implemented; historical local evidence for this path exists, but no checked-in Calendar test exercises the worker |
| Enqueue on update/reschedule | A changed, non-cancelled raw reservation value is enqueued as `pending_update`. Finalizing a pay-on-site payment also enqueues an update (`unisonges_structure.module:1969-1974,2436-2484`). Changes only to phone, location, instrument, or notes do not enqueue an update. | Implemented but incomplete/fragile |
| Enqueue on cancel | A changed reservation whose seat suffix becomes `\|0` is enqueued as `pending_cancel`. There is no Webform-submission deletion hook, so deletion can orphan a remote event. | Implemented but incomplete/fragile |
| Storage | Custom table `unisonges_structure_booking_gcal_sync`, not Drupal Queue API. It is unique on SID and separately on submission UUID and contains action, status, raw reservation, payload JSON, event ID, error, and timestamps. | Implemented |
| Durable event ID | `google_event_id` exists and is retained by the SID-keyed merge. It is populated only after a successful create response. | Implemented but unsafe across an ambiguous create result |
| Remote target/link ownership | The worker reads the current global `calendar_id` at processing time. The row stores neither its target calendar nor ETag, the event-ID index is not unique, and no GET/private-marker check precedes PUT/DELETE. A config target change can orphan an event or address the same ID in the wrong calendar. | Missing production guarantee |
| Coalescing | Enqueue uses a merge keyed by SID, so a later action overwrites the single row. This prevents multiple local rows for normal sequential saves, but can overwrite intent while a worker is acting. | Operationally fragile |
| Payload | Start/end RFC3339 values include the configured IANA zone. Summary, location, description, and private extended properties are generated. | Implemented |
| Payload privacy | The payload currently includes display name, telephone, home address when applicable, free-text notes, Drupal UID/SID/UUID, payment labels/status/source, and Commerce order identifiers (`unisonges_structure.module:2852-2930`). The JSON is duplicated in the sync table. | Insecure/excessive for production |
| Timezone | Missing or invalid configuration falls back to `Europe/Paris`; the payload sends both RFC3339 offsets and `timeZone`. Nonexistent local times are rejected indirectly by a format round-trip. Ambiguous fall-back times have no explicit fold policy or test. | Implemented but insufficiently specified/tested |
| Configuration gates | `enabled` false and `dry_run` true are installed by update 11002 when keys are absent. Missing config also fails closed in code. Real calls additionally require a calendar ID and a token provider that returns a value. | Implemented; active environment state unknown |
| Config export | Schema and an admin form exist, but no config object is exported under `config/sync`. An environment can therefore have an active-only object that repository checkout cannot reproduce. | Operationally fragile |
| Fresh-install behavior | Safe fallbacks exist in the worker/form, but the named defaults are created by an update hook rather than `config/install`. A fresh module install does not obtain that update-hook object from Git. | Safe failure behavior, fragile reproducibility |
| Dry-run | When enabled, it resolves and logs a preview, then marks the row `skipped`. | Implemented, consuming, and unsuitable as a production-readiness queue gate |
| HTTP client | Guzzle performs POST, PUT, and DELETE against Calendar v3 with a ten-second timeout and `sendUpdates=none`. Calendar and event IDs are URL-encoded. DELETE treats 404/410 as success. | Implemented but disabled by safe defaults and not production-hardened |
| Authentication | A bearer access token is read from an environment-variable name chosen in the Drupal form. The form reveals whether that arbitrary name resolves, and the resolved value is sent as a Bearer token. No token value is intentionally saved in Drupal config. | Implemented prototype; operationally unusable and an exfiltration/oracle risk for environment values |
| Cron | `hook_cron()` calls the worker. Pending rows are selected by changed time and ID with a configurable batch limit of 1-100. Versioned automated cron runs every 10,800 seconds (three hours). | Implemented but not a production scheduling contract |
| Retry/error state | Any thrown payload, HTTP, or transport error becomes terminal local status `error`; missing calendar/token marks the loaded batch `error`. There is no error taxonomy, next attempt, bounded retry, or poison threshold. | Missing production guarantee |
| Leases/concurrency | Selection does not claim rows and there is no lease or compare-and-swap finalizer. Two cron workers can process the same row. | Missing and duplicate-prone |
| Idempotency | Create uses a server-generated event ID. A timeout after remote success but before local persistence can lead to another POST and a duplicate event. Update repeats against the stored ID; DELETE is HTTP-repeatable for 404/410 but the worker does not distinguish absence from an inaccessible target. | Create is unsafe; update/cancel are only partially idempotent |
| Reconciliation | `prepareInboundBusySlotSync()` is a zero-work placeholder. There is no per-link get/compare, remote deletion handling, duplicate detection, or repair of missing local queue rows. | Documentation/stub only |
| Separate Google booking surface | A tracked legacy static page embeds a public Google Appointment Schedule (`public/reserver-un-cours/index.html:39-43`). No repository mechanism reconciles bookings from that surface with Drupal or this outbound mapping. | Implemented separately; ownership/migration decision required |
| Logs | Channel `unisonges_booking_sync` records enqueue, dry-run, success, and errors. It logs SID, UUID, UID, raw reservation value, payment label, event ID, and raw exception text; dry-run summary can also contain the student's name. The client places up to 500 characters of a remote body in exceptions; the worker persists up to 1,000 characters. No integration-specific purge/retention mechanism exists. | Implemented but overexposes identifiers and lacks structured redaction/retention |
| Administrative diagnostics | A protected settings form exists under Drupal administration and requires `administer site configuration`. No queue list, health summary, linkage view, manual retry, or reconcile action exists. | Settings implemented; diagnostics missing |
| Tests | Tracked documentation reports local DDEV probes of payload/title/description and `pending_create`; those probes explicitly did not run cron or `processPending()`. The checked-in commerce fixture script only asserts that its scenarios leave the Google row count unchanged. No Calendar unit, kernel, fake-server, concurrency, retry, or state-machine tests are tracked. | Limited historical local evidence; production-hardening tests missing |

Two lifecycle races deserve explicit production blockers:

- a late pay-on-site payment always queues `pending_update`, even if the current
  reservation has meanwhile become `|0`; that can overwrite a pending cancel
  with update/create intent (`unisonges_structure.module:1969-1974,2643-2699`);
- after current DELETE success, the row keeps its event ID but is merely marked
  `synced`. Restoring the reservation queues update and PUTs the deleted ID,
  which normally ends as an unretried 404 (`BookingCalendarSyncService.php:202-236`).

Several code comments/schema labels still call this a no-API/no-cron dry-run
skeleton even though the real client and `hook_cron()` now exist
(`unisonges_structure.module:143-158,2535-2544`). These labels are stale; they
must not be used as a safety control.

### Status by requested category

Implemented and tested locally, with the limitations of repository evidence:

- reservation-first local probes historically exercised `pending_create` and
  portions of the dry-run payload; the probe source is not a checked-in test
  suite, so it is not reproducible from GitHub by a single test command;
- the checked-in local commerce script has only a negative assertion that its
  non-reservation scenarios do not add Google rows;
- no local test in the repository executes create, update, cancel, the HTTP
  client, cron processing, or a retry/lease path.

Implemented but disabled or held behind safe defaults:

- the cron worker and real POST/PUT/DELETE client;
- the admin settings form and environment access-token adapter;
- `enabled`/`dry_run` gates. Their repository behavior is safe, but their active
  production values cannot be established statically.

Documentation only:

- the previous readiness recommendations in
  `docs/dev/google-calendar-sync-plan.md`;
- proposed fixture assertions in `docs/dev/ddev-testing.md` and
  `docs/dev/ddev-fixtures-plan.md`;
- inbound busy-slot reconciliation, represented in code only by a no-op stub.

Missing:

- renewable non-human authentication and key rotation integration;
- a durable state machine, per-attempt metadata, retry schedule, leases, atomic
  claims, generation-aware finalizers, poison-item handling, and a global auth
  circuit breaker;
- deterministic create identity and ambiguous-result reconciliation;
- repair for deleted submissions, missing mappings, deleted remote events, and
  remote drift;
- least-privilege queue diagnostics and mutation permissions;
- an automated fake-server and concurrency suite;
- an approved dedicated-calendar pilot and production runbook.

Insecure or operationally fragile:

- a manually supplied short-lived access token cannot sustain unattended cron;
- the Drupal settings form can select and test an arbitrary environment-variable
  name, then the client uses that value as a Bearer token;
- a create timeout can duplicate an event;
- concurrent workers can execute the same row;
- a new reservation mutation can overwrite an action being processed;
- dry-run consumes backlog;
- errors never retry and transient/permanent failures are indistinguishable;
- event payload storage and logs contain more personal/payment data than a
  scheduling integration needs;
- raw remote error excerpts are neither allowlisted nor reliably redacted;
- config exists only in active storage on environments where the update ran;
- Webform deletion and non-slot detail changes do not produce the required
  remote transition.

## Production contract

The following are release-blocking invariants, not aspirations.

1. Drupal Webform is the source of truth for whether a reservation exists, is
   active or cancelled, and for its current start, end, mode, and instrument.
   Google changes never mutate Drupal reservation data.
2. One reservation UUID has at most one live event in one frozen target
   calendar. A remote event ID is selected and committed locally before the
   first create request and is never replaced automatically.
3. Every local mutation increments a durable desired revision. A worker may
   only finalize the revision and lease it claimed; a newer revision always
   receives a follow-up transition.
4. Create, update, and cancel retain their action across failures and are each
   independently retryable. A cancel supersedes the desired result of any
   earlier create/update, but it does not erase evidence of an uncertain remote
   result.
5. Duplicate delivery and duplicate workers are expected. Repeating any remote
   operation converges to the same event identity and desired projection.
6. Event ID, desired revision/action, current state, attempt count, next retry,
   lease, and redacted failure are durable in the database.
7. Automatic retries are bounded exponential backoff with jitter. After the
   attempt or age ceiling, the item stops automatically and remains visible.
8. Workers use expiring per-row leases and token-checked finalizers. A crashed
   worker cannot hold an item forever; a late stale worker cannot overwrite a
   newer result.
9. A cancel after an ambiguous or partially successful create remains
   recoverable because it targets the already-persisted deterministic event ID.
10. Local wall times are interpreted only in `Europe/Paris`, validated for DST,
    converted to a UTC instant for comparison, and sent as RFC3339 with explicit
    offset plus `timeZone: Europe/Paris`.
11. Google receives only the scheduling projection defined below. No secret,
    payment fact, Commerce identifier, phone, email, student account ID, raw
    Webform SID/UUID, free-text note, or home address is sent.
12. Configuration and credentials fail closed. An exact versioned activation
    tuple and deployment write gate are checked before row selection or any
    secret read, captured at claim, and checked again before dispatch; disabling
    the worker prevents new claims but does not delete or consume backlog.
13. Every automatic or manual state mutation is authorized, CSRF-protected
    where applicable, and auditable without logging payloads or credentials.
14. Rollback disables claims and preserves mapping/history data; it never uses
    a down migration or mass remote deletion.
15. Service credentials, access tokens, and idempotency keys exist only in the
    dedicated CLI worker boundary. Web requests can enqueue intent but cannot
    read either secret or contact Google.
16. Activation isolates an exact smoke cohort from ordinary backlog. Restore and
    final purge preserve or rediscover every possible remote identity before any
    automatic write or loss of its last deletion handle.

### Managed Google event projection

The integration owns a minimal private event:

| Field | Allowed content |
|---|---|
| `id` | Locally generated opaque ID persisted before POST |
| `summary` | Generic lesson label plus non-personal mode/instrument when operationally useful |
| `start.dateTime`, `end.dateTime` | RFC3339 instants with explicit offset |
| `start.timeZone`, `end.timeZone` | Literal `Europe/Paris` |
| `location` | Category only: `Studio`, `Visio`, or `À domicile`; never a student's address |
| `visibility` | `private` |
| `transparency` | `opaque` |
| `extendedProperties.private` | Opaque synchronization key, fixed non-personal `managed_by` marker, and payload schema version only |
| attendees/conference/description | Absent by default |

The event description must be empty unless a later privacy review approves a
fixed, non-personal operational sentence. Payment status, price, payment method,
order IDs, secrets, and free text are prohibited in the description and all
other event fields. Staff retrieve contact and address details from the
permission-checked Drupal reservation, not Calendar.

The canonical projection is normalized before hashing: UTF-8 NFC strings,
fixed field order, normalized line endings, exact RFC3339 timestamps, and no
empty optional keys. `payload_hash = SHA-256(canonical JSON)` lets repeated
updates become no-ops without retaining a second copy of personal data.

### Privacy lifecycle

Opaque does not mean anonymous. The mapping/link/event identifiers, target,
times, hashes, source SID/UUID, and operator UID are pseudonymous operational
data. `extendedProperties.private` is access-controlled Calendar metadata, not
encryption and not a place for a secret.

| Support/data | Minimum purpose | Required lifecycle before live enablement |
|---|---|---|
| Google event | Busy-time projection only | Owner-approved retention; account for Calendar trash, exports, Workspace Vault/legal hold, and staff copies |
| Active mapping | Idempotency, linkage, current convergence | Restrict access; retain while reservation can drive a remote operation |
| Cancelled tombstone | Prevent duplicate/recreated identity | After the approved dispute/recovery period, detach SID/UUID but retain a bounded SHA-256 lookup hash of the random source UUID plus minimum independent opaque target/event/link/key-version evidence; final identity purge requires fresh proved remote absence on the reachable frozen target and no ambiguity |
| Attempt history | Retry and operational audit | Metadata-only, immutable until an explicit bounded purge job |
| Application/security logs | Alerts and investigation | Allowlisted codes/opaque IDs only, with a numeric TTL and verified purge |
| Backups/restores | Recovery | Numeric retention and access controls; restored data is scrubbed before the worker or diagnostics can run |

The privacy owner must approve numeric retention periods, erasure/DSAR handling,
and the lawful treatment of Calendar tombstones/Vault before pilot. A successful
DELETE 2xx, or event 404/410 after frozen-target reachability proof, establishes
functional absence for synchronization; it does not by itself prove regulatory
erasure. Rollback or an outage never suspends the retention job. Automatic
restoration is allowed only while the direct UUID link
is retained. A matching detached source hash enters `reconciliation_required`
for owner review and cannot create an event. After the entire approved tombstone
horizon expires, restoration is a new explicitly approved lifecycle and still
requires proof that the old target/event is absent. Immediately before final
identity purge, the CLI reconciler must obtain a fresh event 404/410 plus a
successful same-principal frozen-target reachability probe (or another already
defined, equally strong absence proof) and verify that no ambiguous request or
lease remains. A 401, 403, target-level 404, unreachable target, ambiguous
response, or incomplete evidence forbids identity purge. Detach all unnecessary
personal linkage, but retain the minimum opaque target/event/link/key-version
evidence in restricted quarantine, with a named privacy/operations owner, review
date, exception expiry, and resolvable key version. Purge operations therefore
cannot destroy the only handle needed to find or remove a possible event.

### Europe/Paris and DST contract

Use one strict parser for both reservation validation and Calendar projection.
Interpret wall time with the literal IANA zone `Europe/Paris`, enumerate the
possible instants around timezone transitions, and apply these rules:

- zero matching instants (spring-forward gap): reject before queue/HTTP;
- two matching instants (fall-back fold): reject unless a future source format
  carries an explicit offset/fold choice;
- one matching instant: persist UTC start, selected UTC offset, timezone, and
  duration with the desired revision;
- compute end as elapsed duration on the UTC instant, then render both endpoints
  back to RFC3339 with their applicable Europe/Paris offsets;
- never reinterpret a historical desired revision merely because server tzdata
  or a configurable default changed.

The current versioned booking window is 09:00-16:30, outside the ambiguous
02:xx fold, but this remains a production invariant and test requirement. The
repository-wide Drupal timezone default is also Europe/Paris
(`drupal/config/sync/system.date.yml:6-10`); user-specific display timezone must
not alter reservation storage or the Calendar projection.

## Authentication decision

### Compared models

| Concern | OAuth user consent + refresh token | Service account + shared calendar |
|---|---|---|
| Runtime identity | Acts as a consenting human account | Acts as a dedicated non-human workload identity |
| Unattended renewal | Refresh token mints short-lived access tokens; consent must request offline access | Signed service credential mints short-lived access tokens; no interactive consent |
| Durable ownership | Coupled to the chosen user and their account lifecycle | Calendar remains owned by an organizational account; service account is only a writer |
| Stored secret material | OAuth client secret, refresh token, and possibly token metadata | Service-account private credential unless a later keyless workload identity is available |
| Revocation/renewal failure | User revocation, consent-policy change, refresh-token invalidation, or account departure | Credential disable/delete, project/service-account disable, ACL removal, or missed rotation; user-managed service-account keys do not necessarily expire automatically |
| Calendar sharing | User must already own or have suitable access | Owner grants the service-account principal writer access to only the target calendar |
| Scope/impact | Token can reach every calendar available to the consenting user within the granted event scope | Event scope is still broad in OAuth terms, but the service account is granted ACL access to only one calendar |
| Operations | Requires a named user re-consent runbook and custody of refresh credentials | Requires key/secret rotation and calendar ACL checks; no volunteer login is needed by cron |
| Tenant constraints | Works when user consent is allowed | Direct calendar sharing to the principal must be verified; no domain-wide delegation should be enabled |

### Recommendation

Use a **dedicated service account with a private shared secondary calendar**.
This matches a server-to-server cron job and removes the renewal dependency on a
specific person. A durable organizational account owns the calendar and grants
only event-writer access to the service-account principal. Use different service
accounts and calendars for test and production. Granting ACL administration,
project-wide roles, or Google Workspace domain-wide delegation would violate
this design.

This recommendation has a hard setup gate: direct writer sharing to the service
account must work under the owner's Google/Workspace policy. If that gate fails,
do not silently add domain-wide delegation or fall back to a personal token;
return to an architecture review.

Use the official Google authentication library to create and cache short-lived
access tokens; do not implement JWT signing by hand. The exact requested scope
is `https://www.googleapis.com/auth/calendar.events`; no calendar-list or ACL
scope is needed. This scope can reach events in *every* calendar accessible to
the principal, so the real least-privilege boundary is the Calendar ACL. Before
pilot and periodically thereafter, inventory every calendar shared to the
principal, inherited IAM role, service-account impersonator, and Workspace
domain-wide-delegation grant. The expected result is only the approved target
calendar and no impersonation/DWD path.

Official assumptions checked for this design:

- Calendar accepts a client-supplied event ID using lowercase base32hex
  characters, length 5-1024, unique per calendar; Google advises a standard
  UUID-grade identifier because collision detection is not globally guaranteed:
  [Events: insert](https://developers.google.com/workspace/calendar/api/v3/reference/events/insert).
- `calendar.events` can view and edit events, while broader calendar/ACL scopes
  are separate: [Calendar scopes](https://developers.google.com/workspace/calendar/api/auth).
- service accounts are intended for server-to-server access and Google advises
  using an auth library rather than hand-written JWT signing:
  [OAuth 2.0 for server-to-server applications](https://developers.google.com/identity/protocols/oauth2/service-account).
- Calendar sharing provides per-calendar access levels and can be restricted by
  Workspace policy:
  [Calendar sharing](https://developers.google.com/workspace/calendar/api/concepts/sharing).

### Secret and environment boundary

Secret values are provisioned by the deployment/operations owner, never by a
Drupal form or config export.

- Preferred storage is an approved encrypted secret manager that injects a
  read-only credential file only into the dedicated non-web CLI worker. If that
  facility is unavailable, use a root-provisioned file outside the repository
  and Drupal document root, readable only by the worker's distinct OS/service
  identity and the provisioning identity—not by PHP-FPM, the web server, or an
  interactive Drupal administrator. Prefer an approved keyless workload identity
  when the production host can provide it to that worker without broader
  privileges.
- Store the separate idempotency HMAC key under the same worker-only controls but
  a distinct secret identity. Version it; use a new version only for new mappings
  and retain old versions through the longest mapping/tombstone plus backup-
  recovery horizon.
  Losing a still-referenced version is a production incident and blocks orphan
  reconstruction; rotating it never rewrites an existing event/link ID.
- Versioned Drupal config contains safe defaults only: disabled, dry-run,
  `Europe/Paris`, conservative batch/retry settings, and no environment identity.
  The live gate, provider choice, fixed allowlisted credential locator, expected
  principal/project/fingerprint, and target binding are deployment-owned
  settings, not editable active config.
- Publish mode/gate/cohort/target/principal/generation as one atomic deployment
  snapshot and require equality with the non-secret runtime-control row. The
  deployment command rejects cohort/target/principal changes until gate-off,
  scheduler drain, and zero leases; no web code can update this control.
- Remove provider, locator, target, and live-gate controls from the production UI.
  In particular, remove the current arbitrary environment-name probe. A
  dedicated `restrict access: true` configuration permission may show safe
  booleans, but `administer site configuration` alone cannot change live wiring.
- Load only the explicitly configured credential source. Validate its expected
  credential type, principal, project, and deployment-held fingerprint before
  use. Do not fall back to an Application Default Credentials search chain,
  developer/user credentials, or an unexpected metadata identity.
- Production OAuth token and Calendar API endpoints are fixed to their official
  HTTPS origins, redirects are disabled, and endpoint overrides exist only in
  test service wiring. Worker health includes system-clock/NTP drift because JWT
  issue/expiry validation depends on accurate time.
- The fixed locator, target selector, Calendar ID, principal, and fingerprints
  are not authentication secrets, but they are internal operational metadata:
  restrict their display and never put environment-specific values in
  `config/sync` or ordinary evidence.
- Access tokens live only in worker process memory for their short validity
  window. A worker may persist only a coarse token-health bucket and observation
  timestamp with a bounded TTL. Production web service wiring has a non-networked
  stub and no credential/HMAC provider. It cannot read either secret, mint a
  token, or call Google; diagnostics display only persisted safe health.
- Rotation uses overlap: provision a new credential, update the runtime secret,
  reload workers, verify a synthetic operation, then revoke the old credential.
  Enforce a maximum credential age and an inventory containing exactly the
  expected active credentials; do not assume a user-managed key expires itself.
- Suspected exposure requires gate-off containment, Calendar ACL removal or
  service-account disablement, and incident handling. Rotating/deleting a key
  alone may not invalidate an already minted access token; record the maximum
  remaining token lifetime before re-enabling with replacement material.
- Backups, support bundles, core dumps, and monitoring exporters must exclude all
  credential/idempotency-key mounts and authorization headers.

Never place client secrets, refresh tokens, service-account JSON, access tokens,
idempotency keys, or copied credential output in Git, `config/sync`, exported
Drupal config, issue comments, PR text, screenshots, diagnostics, or logs.

## Target durable model

To keep the implementation small, evolve the existing table into the
per-reservation mapping and desired-state record rather than replacing it with
Drupal Queue API. Add one immutable-target registry and one metadata-only
attempt ledger. Apply additive, idempotent update hooks first; do
not drop legacy columns in the same PR.

### Immutable target registry

Add `unisonges_structure_booking_gcal_target` with an internal ID, environment,
logical key, monotonically versioned binding, exact Calendar identifier,
identifier fingerprint, expected service-principal fingerprint, creation time,
random target namespace, active identity-key version, and
`active_for_new_mappings`. Treat the Calendar identifier as restricted
operational metadata even though it is not a credential. The binding is
immutable after first use; a changed selector creates a new version and an
explicit migration/reconciliation decision, never an in-place rebind.

Before the first write with any target or identity-key version, copy its
immutable non-secret registry record (including namespace/version and target
fingerprints) to a restricted append-only recovery inventory outside the same
database backup/RPO, and verify retrieval. The corresponding key version remains
recoverable only from the approved secret store; its value is never copied into
the inventory. A version is not write-eligible until both recovery records are
durable. This permits a restore to enumerate a target/version created after the
database snapshot without exposing secret material.

Add one non-secret `unisonges_structure_booking_gcal_runtime_control` row per
environment. It stores the activation generation, mode, write gate, allowed
cohort, exact target-registry version/fingerprint, expected principal
fingerprint, scheduler-drained acknowledgement, and changed timestamp. A
deployment-owned CLI command—not Drupal config, a web route, or an admin form—is
the only supported writer. It atomically validates legal transitions and bumps
the generation. The CLI worker also receives the same tuple as one atomically
replaced deployment snapshot and requires exact equality. This database row is
the serialization point used by identity preparation, claim, and dispatch CAS;
it contains no locator, credential, key, or token.

Each remotely actionable mapping has a foreign key to one registry row. A row
may remain identity-unprepared while writes are blocked; target/event/link fields
are nullable only in that state. Before every remote call,
the worker compares the deployment-provided environment, target fingerprint,
and principal fingerprint to that frozen row and fails closed on any mismatch.
This check happens before credential loading. A copied database or a config
change therefore cannot redirect existing PUT/DELETE calls to another calendar.

### Mapping/state table

Retain `unisonges_structure_booking_gcal_sync` and define these production
semantics:

| Field | Contract |
|---|---|
| `id` | Internal primary key |
| `sid` | Nullable current Webform SID for admin linkage; never sent remotely |
| `submission_uuid`, `source_uuid_hash`, `source_detached_at` | UUID is nullable and unique while directly linked; SHA-256 of a fixed domain label + environment namespace + random UUID is a bounded pseudonymous lookup guard after detachment; neither source value is sent remotely |
| `link_key` | Nullable only before identity preparation; opaque domain-separated HMAC output persisted before POST; detects linkage collision/drift but is not authentication |
| `calendar_target_id` | Nullable only before identity preparation; immutable foreign key to the exact target-registry version; never resolved from a mutable global selector |
| `identity_key_version`, `google_event_id`, `identity_committed_at` | Nullable only before identity preparation; retained derivation-key version plus opaque domain-separated client ID committed before first POST; unique with `calendar_target_id` |
| `claim_cohort` | `ordinary` by default; `smoke` only through the deployment-only, gate-off, audited one-item CAS command; never sourced from reservation input or an admin form |
| `desired_revision` | Monotonic integer incremented for every relevant Drupal mutation |
| `applied_revision` | Latest desired revision proven converged remotely |
| `desired_state` | `present` for an active source reservation or `absent` for cancellation/deletion |
| `desired_action` | `create`, `update`, or `cancel` |
| `next_operation` | `create`, `update`, `cancel`, or `reconcile`; preserves a failed reconcile without changing Drupal desire |
| `processing_revision`, `processing_operation`, `processing_payload_hash` | Immutable revision/operation/hash snapshot for the current lease |
| `processing_activation_generation`, `processing_claim_cohort` | Activation tuple captured by claim and required again by the dispatch-authorizing CAS |
| `sync_status` | One state from the state machine below |
| `remote_state` | `unknown`, `present`, or `absent` |
| `payload_schema_version`, `desired_payload_hash`, `applied_payload_hash` | Canonical projection version and hashes; no payload body |
| `desired_summary_code`, `desired_mode_code`, `desired_instrument_code`, `desired_location_category` | Allowlisted scalar inputs needed to reconstruct the exact desired managed projection; no free text or person identifier |
| `desired_start_utc`, `desired_end_utc`, `desired_timezone`, `desired_start_offset`, `desired_end_offset` | Frozen schedule interpretation; timezone is always `Europe/Paris`, while comparisons use UTC instants and each endpoint retains its applicable offset |
| corresponding `processing_*` projection fields | Scalar projection snapshot copied atomically at claim so a later Drupal mutation cannot change the in-flight body |
| `remote_etag`, `remote_updated_at`, `last_reconciled_at` | Conditional update and drift evidence |
| `attempt_count`, `total_attempt_count`, `first_failed_at`, `last_attempt_at`, `next_retry_at`, `poisoned_at` | Per-revision budget, lifetime count, scheduling, and poison visibility |
| `lease_token`, `lease_owner`, `lease_expires_at` | Atomic claim/finalizer guard; cleared outside `processing` |
| `remote_call_started_at`, `request_precondition_etag` | Conservative dispatch-authorization boundary set only by the final activation/lease CAS; distinguishes pre-dispatch failure and records the ETag used by conditional update/delete |
| `ambiguity_started_at`, `reconcile_not_before`, `last_absence_observed_at`, `absence_observation_count` | Fence delayed requests and require stable observations before resolving an ambiguous write |
| `last_error_class`, `last_error_code`, `last_http_status`, `last_error_redacted` | Allowlisted operational error, never a raw body |
| `created`, `changed`, `completed_at` | UTC audit timestamps |

Required constraints and indexes:

- unique non-null `submission_uuid` and unique `source_uuid_hash` while retained;
- unique non-null `link_key`;
- unique non-null (`calendar_target_id`, `google_event_id`);
- claim index (`claim_cohort`, `sync_status`, `next_retry_at`,
  `lease_expires_at`, `changed`, `id`);
- reconciliation index (`sync_status`, `last_reconciled_at`, `id`);
- check/application validation that a processing row has a lease token, owner,
  expiry, processing revision/operation, activation generation, and claim cohort;
- check/application validation that a claimable/processing row has all immutable
  target/identity fields committed;
- check/application validation that `applied_revision <= desired_revision`.

At claim, copy every desired projection scalar, both endpoint offsets, schema
version, and hash into the processing snapshot in the same transaction as the
lease/attempt. The HTTP body is reconstructed only from that snapshot. A hash
alone is not considered a reconstructible revision.

After effective write/cohort gates pass but before claim/HTTP, a short identity-
preparation transaction locks and revalidates the runtime-control generation,
deployment-tuple equality, target, cohort, and source revision. Only while that
lock establishes an enabled exact tuple may the CLI process read the HMAC key,
derive, and atomically commit all identity fields. The transaction makes no
network call and releases the key from process memory immediately afterward.
Only then may the row be claimed. Thus disabled/preview executions, every web
request, an old-generation worker, and rows outside the allowed cohort never
read the idempotency secret, while an event ID is still durable before the first
POST.

Derive `google_event_id` and `link_key` as computationally independent outputs of
HMAC-SHA-256 using distinct domain labels (`event-id` and `link-key`), the source
UUID, immutable target namespace, and a versioned environment-specific
idempotency key. Encode at least 128 output bits as lowercase base32hex; add a
fixed allowed event-ID prefix. Persist both outputs and key version before POST.
The key is a separate long-lived runtime secret, never stored in Drupal/Git/logs,
and old versions remain available at least through backup RPO and mapping/
tombstone retention. On a missing mapping, derive and GET the Cartesian set
of every plausible retained target-registry version for that environment and
every still-valid identity-key version. One exact match reconstructs that target
and key version; multiple/mismatched matches quarantine. Zero matches permits a
new binding only when source/restore provenance unambiguously selects the current
target; an unreachable old target also quarantines. Domain separation prevents
the link marker from being the event ID, and per-environment target namespaces
prevent cross-environment correlation. The private property is readable/copyable
by another Calendar writer, so it is only a collision/drift signal; the Calendar
ACL is the security boundary. Preserve a legacy event ID. Never choose a fresh
ID merely because a request timed out.

Legacy `reservation_value`, `payload_json`, and raw errors become deprecated.
The compatibility bridge stops all new writes to them before backfill. Before
production writes, an executed and verified scrub must cover live database rows,
application logs, support exports, and the restore procedure; backups age out or
are access-restricted under the approved retention policy, and every restore
reruns the scrub before service. Existing prototype Google events are inventoried
privately and remediated under owner approval. An exception needs a named owner,
expiry, restricted access, and recorded closure evidence. Merely approving a
future cleanup is not a go-live gate. Do not expose legacy values in diagnostics
during the transition.

### Attempt table

Add `unisonges_structure_booking_gcal_attempt` with:

- primary key, mapping ID, desired revision, executed operation, attempt number,
  activation generation, and claim cohort;
- start/end UTC timestamps and duration;
- outcome and error class; allowlisted HTTP status and Google reason when safe;
- a random correlation ID and a non-sensitive worker instance label;
- previous and resulting state.

It must not contain authorization headers, secret locators, request/response
bodies, payload JSON, direct personal fields, or raw exception messages. Mapping
ID, timestamps, and correlation metadata remain pseudonymous operational data
subject to restricted access and bounded retention; they are not anonymous.
Insert an
`in_progress` row atomically with a successful claim, including its lease token
fingerprint and processing snapshot hash. Finalization or expired-lease recovery
closes that same row by CAS with end time, outcome, and resulting state. A closed
record is immutable until scheduled purge. Thus a crash remains visible rather
than losing its attempt history. Retention is an owner-approved bounded period.
A unique key on mapping ID + desired revision + attempt number makes claim
recording idempotent.

### Online migration and classification

Disabling the worker does not stop reservation hooks from enqueueing. Migration
therefore uses an explicit expand/bridge/backfill/contract sequence while remote
writes remain hard-gated off. The migration runner has neither auth/idempotency
secret injection nor a Calendar client; it may receive only the owner-approved
restricted non-secret legacy target-registry version:

1. **Expand.** Add nullable/defaulted fields and tables compatible with current
   inserts. Add no uniqueness constraint until duplicates are audited. Deploy
   fresh-install defaults and conservative upgrade defaults.
2. **Bridge.** Deploy a producer that writes the new desired model while still
   tolerating legacy column constraints. From this point it writes only harmless
   empty/null compatibility defaults to `reservation_value`, `payload_json`, and
   legacy error fields—never their former sensitive content. Prefer this online
   dual-model compatibility; if it cannot be proved, use a short owner-approved
   booking-write maintenance window. Worker-off alone is not a migration lock.
3. **Preflight.** Count legacy states, missing/deleted sources, duplicate
   submission UUIDs/event IDs, blank/malformed IDs, and rows changed during the
   scan. Obtain the legacy target registry version from the calendar owner; never
   infer it silently from the currently configured global `calendar_id`.
4. **Backfill.** Process bounded primary-key ranges with a recorded high-water
   mark. Each update uses CAS on the source row's `changed` value/revision; skip
   and revisit a row changed after read. Preserve every non-empty legacy event
   ID and bind its owner-supplied non-secret target version, but leave missing
   event/link/key fields identity-unprepared. Backfill never reads the HMAC key
   while the write gate is off. Identity preparation occurs only after effective
   pilot/live gates pass and immediately before an allowed claim. Make reruns
   idempotent; no secret read or network request is allowed.
5. **Classify.** Map legacy `pending` from current Drupal truth to `queued` or
   `cancel_pending`; treat `skipped` as unaudited; map `error` to
   `permanent_failure`; map legacy `synced` and ambiguous cancel rows to
   `reconciliation_required`. Missing sources, target ambiguity, and identity
   collisions remain quarantined for owner review. An existing legacy event ID
   has no proven new link marker and cannot be adopted or mutated automatically
   until the private pilot inventory establishes its exact disposition.
6. **Validate and constrain.** Compare count-only source/mapping/state totals and
   rerun until no skipped-CAS row remains. Resolve or quarantine collisions,
   then add foreign keys, unique constraints, and claim/reconciliation indexes.
7. **Cut over.** Verify and test that the bridge's no-sensitive-legacy-write rule
   is still enforced, run the verified privacy scrub, retain harmless old columns
   for the rollback floor, and keep real HTTP disabled until reconciliation/
   pilot gates pass.

Every step is restartable, emits counts and opaque batch IDs only, and has an
interruption test plus an enqueue-during-backfill concurrency test.

## Complete state machine

`desired_action`, `next_operation`, desired revision, remote state, and rollout
cohort are orthogonal to `sync_status`; they must never be inferred from an
error string. `smoke`/`ordinary` controls eligibility only and is not a ninth
state.

| State | Meaning | Automatically claimable? | Normal exit |
|---|---|---:|---|
| `queued` | Active reservation has a create/update due now | Yes | `processing` |
| `processing` | Exactly one unexpired lease owns a snapshot of operation + revision | No | success, failure, reconciliation, or newer desired revision |
| `synced` | Remote event is proven to match the latest active Drupal projection | No | a Drupal change or drift finding |
| `retryable_failure` | A classified transient failure has a future retry and remaining budget | When due | `processing` |
| `permanent_failure` | Invalid payload, permission/config failure, collision, or exhausted retry budget needs a human | No | authorized requeue/reconcile after correction |
| `cancel_pending` | Drupal says cancelled/deleted and remote absence is not yet proven | Yes | `processing` with operation `cancel` |
| `cancelled` | Remote absence was confirmed by delete success or event 404/410 plus same-principal frozen-target reachability proof for the latest cancelled revision | No | explicit reservation restoration |
| `reconciliation_required` | Outcome, linkage, ETag, duplicate status, or remote state is ambiguous | Only by the bounded CLI reconciler after a scheduled or authorized queued request | a converged state, new queue action, retryable failure, or permanent failure |

### Transition table

| From | Event/guard | To | Durable effects |
|---|---|---|---|
| no row | valid active reservation committed | `queued` | Insert desired-state mapping and revision 1; remote identity may remain null while hard-gated off |
| `queued`, `synced`, `cancel_pending`, or write-flavoured `retryable_failure` with direct source linkage | active managed projection changes/restores | `queued` | Increment revision; choose create if remote not proven present, otherwise update; set write operation and reset per-revision budget |
| `queued`, `synced`, `cancel_pending`, or write-flavoured `retryable_failure` with direct source linkage | Drupal cancellation or deletion | `cancel_pending` | Increment revision; retain mapping/event ID; desired action/next operation cancel and reset per-revision budget |
| `reconciliation_required` or reconcile-flavoured `retryable_failure` | any Drupal mutation/cancellation | `reconciliation_required` | Increment desired revision/state/action but keep `next_operation=reconcile`; never bypass ambiguity with a write |
| `permanent_failure` | any Drupal mutation/cancellation | `permanent_failure` | Record newer desired revision/state and `new_desire_awaits_review`; safety latch remains until authorized correction |
| `processing` | newer active Drupal mutation | `processing` | Increment desired revision/action only; do not steal lease or processing snapshot |
| `processing` | newer cancellation/deletion | `processing` | Increment desired revision, set desired action cancel; current lease remains accountable |
| `queued` with unprepared identity | locked runtime-control generation, deployment tuple, effective pilot/live write/cohort gates, source revision, and immutable target checks pass in the CLI worker | `queued` | In one short transaction derive/commit target, key version, event ID, and link marker before claim/HTTP; key remains process-memory only |
| `cancel_pending` with no prior remote dispatch (identity prepared or not) | attempt ledger proves local absence | `cancelled` | Mark latest cancelled revision applied locally; perform no identity preparation/secret read/HTTP solely for cancellation |
| `queued`, due `retryable_failure`, `cancel_pending` | atomic claim wins and write/cohort gates pass | `processing` | Snapshot next operation/revision, increment attempt, set lease token/owner/expiry and last attempt |
| `reconciliation_required` or due reconcile retry | CLI reconciler claim wins under write/cohort gates | `processing` | Snapshot operation reconcile/revision under the same lease contract |
| `processing` | create/update returns a validated exact ID/ETag/marker/projection and claimed revision is still latest active revision | `synced` | Persist event ID/ETag/hash, applied revision, remote present; clear lease/error |
| `processing` | cancel returns 2xx, or event 404/410 plus a successful same-principal frozen-target probe, and claimed revision is latest cancelled revision | `cancelled` | Mark remote absent and applied revision; clear lease/error |
| `processing` | verified linkage/identity collision or immutable-target safety latch, while desired revision advanced | `permanent_failure` | Preserve the safety evidence, record latest desire as `new_desire_awaits_review`, clear lease, alert, and forbid automatic write/requeue |
| `processing` | remote result succeeds but desired revision advanced | `queued` or `cancel_pending` | Persist safe remote facts, clear lease, derive next state from latest Drupal desire |
| `processing` | revision-scoped pre-remote/transient failure but desired revision advanced, with no ambiguity or safety latch | `queued` or `cancel_pending` | Record the old attempt, clear lease, derive a fresh operation/budget from latest Drupal desire, and open any applicable global circuit |
| `processing` | ambiguous remote outcome and desired revision advanced | `reconciliation_required` | Preserve latest desired revision/state, set next operation reconcile, retain the same event ID |
| `processing` | transient classified failure with budget remaining | `retryable_failure` | Redacted error and bounded `next_retry_at`; clear lease |
| `processing` | token mint fails before HTTP and claimed revision is still current | `retryable_failure` | Close this attempt as `auth_mint_failed`, set retry no earlier than the circuit probe, clear lease, and open credential circuit; no other row is claimed |
| `processing` | second 401 after one token refresh/replay and claimed revision is still current | `retryable_failure` | Close this attempt as `remote_auth_rejected`, set retry no earlier than the circuit probe, clear lease, and open remote-probe circuit |
| `processing` | invalid payload, verified linkage collision, or exhausted budget | `permanent_failure` | Redacted reason, no next retry; clear lease and alert |
| `processing` | target-level/unknown 403 policy denial or certificate/hostname/protocol failure | `permanent_failure` | Close current item with safe class, clear lease, open global target/transport circuit, and stop claims |
| `processing` | allowlisted event-specific 403 denial | `permanent_failure` | Quarantine only this item with safe class; do not open the global circuit |
| `processing` | pre-dispatch CAS detects changed/disabled activation generation, cohort, target, or principal | `queued`, `cancel_pending`, or `reconciliation_required` | Close attempt as activation-changed, clear lease, restore latest safe intent, consume no remote-attempt budget, and perform no credential read/HTTP |
| `processing` | any post-dispatch ambiguous write result, 409 identity conflict, update 404/410, ETag 412, malformed success, or missing/unreadable linkage evidence | `reconciliation_required` | Retain event ID/evidence, set next operation reconcile, clear lease; do not choose another ID |
| `processing` | event 404/410 followed by target-probe 401/403/404 | `reconciliation_required` | Preserve desired state and ambiguity; open auth/target circuit and do not infer event absence |
| `processing` | lease expires before remote start and desired revision is unchanged | `retryable_failure` | Recovery CAS records `lease_expired_before_remote`, schedules the same operation, closes attempt, and invalidates old token |
| `processing` | lease expires before remote start and desired revision advanced | `queued` or `cancel_pending` | Close obsolete attempt, invalidate old token, derive latest operation, and start a fresh per-revision budget; never retry old intent |
| `processing` | lease expires after remote call start | `reconciliation_required` | Recovery CAS records ambiguous outcome, sets next operation reconcile, and invalidates old token; no blind alternative operation |
| `synced` or `cancelled` | bounded sweep marks mapping due for a remote check | `reconciliation_required` | Set next operation reconcile; no remote call occurs before a reconciler claim |
| `retryable_failure` | retry/age ceiling reached | `permanent_failure` | Class `retry_exhausted`; alert; no automatic retry |
| `permanent_failure` | authorized retry after underlying correction | `queued` or `cancel_pending` | New desired revision, reset budget, audited actor and allowlisted reason code |
| `permanent_failure` | authorized read-only diagnosis is required | `reconciliation_required` | Retain desired revision, set operation reconcile, audit actor and allowlisted reason code |
| `processing` (reconcile) | GET proves latest active projection | `synced` | Store ETag/hash/applied revision/remote present |
| `processing` (reconcile) | GET proves absence for an active source and frozen-target probe succeeds | `queued` | Retain the precommitted ID and queue create for the same desired revision; after ambiguity, first satisfy the observation fence |
| `processing` (reconcile) | GET proves absence for cancelled source and frozen-target probe succeeds | `cancelled` | Store applied revision/remote absent; after ambiguity, first satisfy the observation fence |
| `processing` (reconcile) | exact mapped event has the expected linkage marker but differs, or exists while cancellation is desired | `queued` or `cancel_pending` | Set next write operation from Drupal truth; never import remote fields |
| `processing` (reconcile) | transient GET failure | `retryable_failure` | Preserve `next_operation = reconcile`; bounded retry |
| `processing` (reconcile) | event ID has a verified different linkage marker | `permanent_failure` | Linkage-collision alert; latch state and make no remote mutation |
| `cancelled` | directly linked Drupal reservation is deliberately restored | `queued` | New revision, action create with same mapped ID; tombstone refusal becomes permanent failure |
| `cancelled` with detached source | matching source hash reappears | `reconciliation_required` | Do not create automatically; require owner proof of old-event absence and an approved new lifecycle after retention rules |

Every transition uses a database transaction or affected-row compare-and-swap.
Claim creates the in-progress attempt in its transaction; finalization or lease
recovery closes it in the mapping-state transaction. Finalization evaluates, in
order: valid lease token; remote ambiguity or any verified identity/immutable-
target safety latch; desired-revision drift; then the revision-scoped operation
outcome. A newer cancel cannot be replaced by a retry of the older create/update,
and an ambiguous old create is reconciled before that cancel is issued. A
linkage/identity collision or other target-binding safety latch always enters
`permanent_failure` and records the latest desire as
`new_desire_awaits_review`, even when the desired revision advanced; only a
definitive success or a revision-scoped/pre-remote failure can be superseded
automatically by newer Drupal intent.

## Lease, retry, and finalizer model

### Claim

1. Atomically load the deployment tuple and matching runtime-control generation.
   After the effective write gates pass, apply that generation and cohort
   predicate in the first database query. Prepare identity only through the
   locked control-row transaction above for due active rows in that exact cohort,
   then select only identity-complete rows in that same cohort in `queued`,
   `cancel_pending`, or `retryable_failure` whose `next_operation` is
   create/update/cancel. The
   separate reconciler selects `reconciliation_required` and due retryable rows
   whose next operation is reconcile. Both use stable `next_retry_at`, `changed`,
   `id` order. `none`, an unknown cohort, or a row/cohort mismatch returns no
   candidate before target or secret resolution.
2. For each candidate, issue an atomic conditional update matching its current
   state/revision and absent-or-expired lease **and** the still-current control
   generation, enabled gate, cohort, target, and principal fingerprints.
3. The winning update sets `processing`, copies desired revision and
   `next_operation` plus every projection scalar/hash into processing fields,
   increments the attempt count, writes the activation generation/cohort, random
   lease token, worker label, and expiry, and inserts the `in_progress` attempt
   row in the same transaction.
4. Only a worker whose update affected one row may make a remote call.

Use the current wall-clock service for claim/finalizer timestamps, not Drupal's
request-start time, which can be stale during a long cron invocation. Immediately
before reading the Google credential or opening a network connection, mark
`remote_call_started_at` durably by one CAS matching token, revision, unexpired
lease, processing cohort/generation, and the current enabled control tuple
(including target/principal). A failed check closes the attempt as
`activation_changed_before_dispatch`, clears the lease, returns the latest
intent to `queued`, `cancel_pending`, or `reconciliation_required` as applicable,
consumes no remote-attempt budget, and performs zero credential read/network.
After this CAS commits, dispatch follows without intervening work; a later
emergency gate-off treats that authorization as in-flight and accounts for its
lease. No database transaction spans credential minting or HTTP.

Use a two-minute lease for the initial pilot with HTTP connect timeout at most
three seconds and total request timeout at most fifteen seconds. Process one
remote request at a time per lease; do not sleep while holding a lease. If future
request duration can exceed half the lease, add a token-checked renewal before
the remote call rather than an unbounded lease.

### Finalize

The finalizer updates only a row still in `processing` with the exact lease
token and processing revision **and `lease_expires_at > current time`**. It first
classifies/persists safe remote facts, ambiguity, and any identity/target safety
latch. Ambiguity enters reconciliation regardless of revision drift; a verified
safety latch enters `permanent_failure` and preserves the latest desire for
review. Only then does it compare desired and processing revisions. If they
differ and the remaining outcome is a definitive success or revision-scoped/
pre-remote failure, it derives `queued` or `cancel_pending` from current Drupal
truth. This prevents a late finalizer from erasing a cancel and prevents a newer
mutation from bypassing a collision discovered by the older request.

A stale worker whose lease expired gets an affected-row count of zero. It logs a
safe stale-finalizer metric and performs no state overwrite. A stable event ID
prevents duplicate creates, but it does **not** make a delayed update/delete
harmless. Every update and delete therefore uses the exact quoted ETag obtained
for that operation in `If-Match`; a newer successful mutation changes the ETag
and makes the delayed request fail rather than overwrite/delete it. An expired
lease with no recorded remote start is retryable; one with a recorded remote
start is reconciled first because its remote outcome is unknown.

An ambiguous mapping is fenced: no create/update/cancel for a newer revision is
sent until reconciliation establishes stable remote state. Initially use a
15-minute ambiguity quiet period (never less than ten total HTTP timeouts or two
leases) followed by two consistent exact-ID reads at least one minute apart.
Store the fence/observations durably. Reads do not shorten the fence. If a delayed
request changes state between observations, restart it. Only then derive the
latest operation. Periodic sweeps of cancelled mappings remain mandatory so an
exceptionally late create is detected and deleted with the same mapped ID.

Tests delay an expired worker's update and delete until after a newer revision
is queued/applied. They must prove lease-expiry blocks its finalizer, `If-Match`
blocks stale remote mutation, the ambiguity fence prevents conflicting writes,
and the latest Drupal projection/absence ultimately wins.

### Retry policy

For attempt number `n` starting at 1:

```text
base = min(6 hours, 60 seconds * 2^(n - 1))
jittered = base + random(0, min(30 minutes, base / 4))
delay = min(6 hours, jittered)
if Retry-After is valid: delay = min(6 hours, max(delay, Retry-After))
```

The final delay is therefore never greater than six hours. Reject malformed,
negative, or past `Retry-After` values. Allow at most 10 automatic attempts and
at most 48 hours since the first failure, whichever occurs first. Reaching either
bound produces `permanent_failure/retry_exhausted`. Manual retry creates a new
desired revision and retry budget and requires an allowlisted operator reason
code; the initial release has no free-text reason field.

Do not retry every row through an authentication outage. A durable provider
health/circuit record stores only `closed|open|half_open`, generation, safe error
class, opened/last-success/next-probe times, cooldown, and a probe lease token/
expiry. It opens after token minting fails, a persistent 401, or a target-level
permission/reachability failure.
While open, workers stop claiming rows and queue items do not consume attempts.
A CAS transition after an exponentially bounded five-minute-to-one-hour cooldown
permits exactly one half-open probe. A minting failure uses a credential-only
probe; persistent 401 and target permission/reachability failures use one
normally claimed row as the remote probe. That row is finalized normally and is
never discarded. Probe success closes the circuit; failure reopens it with a
bounded cooldown; an expired probe lease is recoverable by CAS. Concurrent
half-open, repeated-401, and recovery behavior is mandatory in the test matrix.
No circuit record contains credential or token material.

### Error classification

| Result | Classification and action |
|---|---|
| Payload validation failure before HTTP; HTTP 400/422 | `permanent_failure/payload_invalid` |
| HTTP 401 | Discard cached access token, mint once, replay the identical request once; repeated failure finalizes this row as retryable auth failure and opens the circuit, so other rows are not claimed/drained |
| HTTP 403 with rate/quota reason | `retryable_failure/rate_limited` |
| HTTP 403 permission/policy reason | Classify with an endpoint/reason allowlist: target/principal policy (and unknown reasons) opens the circuit; an allowlisted event/organizer-specific denial quarantines only this item as `permanent_failure/permission_denied` |
| HTTP 404/410 for an event | Probe the frozen calendar immediately with the same principal using `events.list`, `maxResults=1`, and partial `fields=kind`; only probe 200 permits absence semantics, while probe 401/403/404 opens the circuit and preserves reconciliation/cancel intent |
| HTTP 409 on deterministic create | GET the same ID on a reachable target; matching marker/projection may be adopted, mismatch is permanent collision, 404/410 is tombstone conflict, and transient GET remains reconciliation |
| HTTP 409 on update/cancel | Use endpoint/reason allowlist; expected version/conflict enters reconciliation, unknown conflict fails closed on the item; never treat it as create success |
| HTTP 412 | `reconciliation_required/etag_conflict`; GET and compare against Drupal projection |
| HTTP 429 | `retryable_failure/rate_limited`, respecting bounded `Retry-After` |
| Proven pre-dispatch DNS/connect/transient TLS failure | `retryable_failure/remote_unavailable`; retry the same operation with bounded backoff |
| Certificate, hostname, TLS verification, or protocol failure | Fail closed as `permanent_failure/transport_security`, open transport-security health/circuit, and never disable verification |
| Any post-dispatch disconnect/read timeout or 5xx for a mutating request | `reconciliation_required/ambiguous_remote_result` for create, update, or cancel; next operation is reconcile, not a blind write retry |
| 5xx/transient transport failure on a GET/probe | `retryable_failure/remote_unavailable` with `next_operation=reconcile` |
| 2xx create/update without exact ID, ETag, expected marker, and canonical managed projection | `reconciliation_required/malformed_success`; follow with an exact-ID GET when safe |
| Any event with expected ID but wrong opaque link key | `permanent_failure/linkage_collision`; never update or delete it automatically |

Persist an allowlisted class, numeric status, documented Google reason token when
safe, and a fixed redacted message. Do not persist raw response bodies or
exception strings. Google documents reason-sensitive 403 handling, exponential
backoff for 429/5xx, 410 semantics, and 412 refetch behavior in
[Handle API errors](https://developers.google.com/workspace/calendar/api/guides/errors).

## Idempotent remote protocol

### Create

1. Commit mapping, opaque link key, event ID, revision, and payload hash.
2. POST the minimal event with that explicit ID and link key.
3. On 2xx, validate exact ID, ETag, expected private marker, and the canonical
   managed projection in the partial response (or an immediate exact-ID GET)
   before finalizing `synced`.
4. On timeout/connection ambiguity, never generate a new ID. Observe the
   ambiguity fence, reconcile with GET, prove target reachability for absence,
   and only then retry POST with the same ID.
5. On 409, GET that ID on a proven reachable target. If its private link key
   matches, compare the canonical projection and adopt/update it. A mismatch is
   a collision and blocks. GET 404/410 means retained-ID/tombstone conflict and
   becomes visible permanent failure; a transient GET stays in reconciliation.

This closes both timeout-before-response and timeout-after-remote-success paths.
The Calendar documentation notes that even caller IDs cannot provide perfect
global collision detection; UUID-grade randomness plus local/remote linkage
verification is therefore mandatory.

### Update/reschedule

GET the mapped event, prove target reachability when absence is reported, verify
the opaque link key, compare the normalized managed projection, and no-op if the
hash already matches. Otherwise call `events.update` (PUT, never PATCH) with only
the complete canonical allowlisted body, the exact quoted ETag in `If-Match`, and
`sendUpdates=none`. Full replacement deliberately removes legacy description or
private fields. Validate returned ID, ETag, marker, and projection before
`synced`. A repeated update converges; 409/412, missing remote state, or malformed
success goes to reconciliation. Drupal fields win over manual edits to the
managed projection. The integration never imports a manual Calendar edit into a
reservation.

Google recommends retrieving and updating with ETags to avoid lost concurrent
modifications: [Get specific versions of resources](https://developers.google.com/workspace/calendar/api/guides/version-resources).

### Cancel

DELETE only the persisted mapped ID after GET verifies the exact target, marker,
and ETag; send that ETag in `If-Match` and `sendUpdates=none`. A 2xx proves the
delete. Event 404/410 proves absence only after the same-principal minimal target
probe returns 200; otherwise keep cancel/reconciliation intent and open the
target/auth circuit. A repeated cancel is a success under the same rule. A cancel
following an ambiguous create targets the same ID and remains behind the
ambiguity fence until stable presence/absence is known.

### Relevant local changes

Queue an update for every change to the managed projection, not only a changed
reservation string. Queue cancel on Webform deletion before losing the durable
UUID/SID linkage. If the queue write itself fails, the booking remains valid in
Drupal and health becomes critical. A missing mapping is created automatically
only after the client checks every plausible retained target-version × identity-
key-version candidate and provenance unambiguously selects the current target.
A single match reconstructs its exact old target/key; multiple/mismatched
matches, an unreachable old target, ambiguous provenance, or unknown legacy
identity are quarantined. Never generate a new random remote identity merely
from current Drupal truth.

## Reconciliation contract

Reconciliation is outbound convergence, not inbound scheduling. It never makes
Google the source of truth and does not require a public webhook or queue route.

Triggers:

- ambiguous create/update/cancel response;
- update/get 404/410, create 409, or ETag 412;
- expired lease after a potentially sent request;
- periodic bounded sweep of stale `synced` and `cancelled` mappings;
- missing mapping discovered for an active/cancelled Drupal reservation;
- authorized one-item admin action.

For one mapping, reconciliation:

1. reloads current Drupal truth and the frozen mapping;
2. GETs the persisted event ID;
3. on event 404/410, proves the frozen target remains reachable by the same
   principal using a minimal partial-response probe before inferring absence;
4. verifies the exact target/event mapping and opaque private collision marker
   before any mutation; the marker is not authentication against another writer;
5. compares only the canonical managed projection and applies any active
   ambiguity fence/two-observation requirement;
6. moves to `synced`, `cancelled`, `queued`, `cancel_pending`,
   `retryable_failure`, or `permanent_failure` according to the state table;
7. records safe timestamps/hashes/ETag, never a remote body.

For an active reservation whose remote event was manually deleted, attempt
recreation only with the same persisted ID. If Calendar retains an incompatible
tombstone or rejects reuse, enter visible
`permanent_failure/event_id_retired`; the first production release does not
relink or assign a replacement ID. Staff use the documented manual scheduling
fallback until a separately reviewed generation/relink protocol exists. For a
manual edit, Drupal's canonical projection is re-applied. For a mismatched link
key or duplicate candidates found by an exact private-property query, quarantine
the mapping and require human review; never mass-delete.

Missing-mapping repair derives exact candidate IDs/markers for all plausible
retained target versions crossed with all retained key versions and uses the
same reachability/marker checks. Legacy rows whose IDs are not derivable require
the private migration inventory. A database restore sets a global restore-
reconciliation hold before the web UI, scheduler, identity preparation, or any
claim becomes available. It merges the restored registry with the append-only
recovery inventory, verifies secret-store recoverability for every referenced
key version, then enumerates **all** managed events on every retained target,
including targets/versions created after the snapshot. Enumeration filters by
the fixed non-personal `managed_by` private property, uses partial fields,
`showDeleted=true`, no time bound, and complete pagination. It compares every
remote ID/link against restored mappings. Any remote-only, mismatched, duplicate,
or locally unprovable event is quarantined; no create/update/delete is automatic.
Only after the full inventory, per-item reconstruction/review, privacy scrub, and
count-only closure evidence may the web diagnostics and CLI scheduler be
released. It never assumes "missing locally" means "never sent remotely."

Outside the exceptional restore procedure, run a small rolling sweep rather than
a full-calendar poll. Any duplicate search
uses the frozen target, exact `privateExtendedProperty`, `showDeleted=false`, no
time bound, partial fields only, and complete pagination before concluding the
live count. A future incremental
sync token is optional and would require its own durable cursor/full-resync
design; it is not necessary for the first production contract.

## Administrative tooling

All tooling belongs under Drupal administration, adjacent to the existing
Google Calendar settings page. No public queue endpoint, JSON feed, or anonymous
diagnostic route is permitted.

### Permissions

- `view google calendar sync diagnostics`: read-only health, queue, and linkage;
- `retry google calendar sync items`: retry/requeue one reviewed item;
- `reconcile google calendar sync items`: enqueue one reconciliation request for
  the CLI worker; the web request itself performs no remote read;
- `administer google calendar runtime diagnostics`: view safe wiring booleans,
  never change deployment-owned target/auth/live values;
- existing configuration administration remains separate from item operations.

Define every permission in `*.permissions.yml` with `restrict access: true` and
assign none automatically. List/detail rendering requires both the specific
diagnostic permission and `$submission->access('view', $account, TRUE)`; propagate
the returned cacheability metadata and user/permission cache contexts/tags. For
retry/requeue or reconcile, both route/form build and submit repeat the action-
specific permission and current source `view` access checks, then reload the
source/mapping and apply revision/state CAS. A missing source is visible or
actionable only under separate restricted orphan-view and orphan-action
permissions. Mutations use Form API/`ConfirmFormBase`, POST, CSRF protection, and
an allowlisted operation; a valid cross-user POST still returns 403 and produces
zero transition or remote work. Calendar/target/event IDs are loaded server-side
from the mapping, never accepted from POST. Reconcile submit records durable
`reconciliation_required` intent; only the dedicated CLI worker later performs
the remote GET.

The audit record contains server-derived actor UID, operation, mapping ID,
revision, and an allowlisted `reason_code`. The initial release has no free-text
reason. If a later review permits a comment, it must be short, plain text,
warn against personal/secret content, have restricted access, and a numeric TTL.

### Read-only queue list

Default columns and filters:

- mapping ID, rollout cohort, and permission-checked link to the Drupal
  reservation;
- masked event ID; the initial UI has no full-ID reveal/copy control;
- desired action/revision and current state;
- attempt count, last attempt, next retry, lease-expiry indicator;
- last successful sync/reconciliation time;
- fixed redacted error class/code/message;
- age and filters by state/action/error class.

Do not render raw payload JSON, reservation value, remote response, access-token
status details, telephone/address/notes, or secret locator. If the current user
cannot access the Webform submission, the diagnostic page must not bypass that
access decision merely because they hold the queue-view permission.

### Item view and actions

The item view shows reservation/event linkage, hashes, revisions, timestamps,
and a state-transition history. It offers:

- retry/requeue after an operator records the corrected cause;
- request reconciliation of one item, with a clear warning that the CLI worker
  will later perform a remote read and may enqueue a later write but never import
  Google data;
- no arbitrary event ID, payload, URL, method, or error-body editor;
- no bulk retry/delete in the first production release.

### Health summary

Expose and alert on:

- deployment mode/write-gate booleans, allowed cohort, activation generation,
  deployment/control equality, and derived execution state (`off`, `blocked`,
  `disabled`, `preview`, `held`, `pilot-write`, `live-write`, or `invalid`)
  without secret values;
- last worker-observed credential availability and coarse token-expiry bucket,
  persisted without token data and expired after a bounded TTL;
- required idempotency-key versions available/unavailable as worker-observed
  booleans, never locators, hashes of key material, or values;
- calendar target configured and frozen-link mismatches, without displaying it
  to unauthorized users;
- counts by state/action/error class and eligible/held counts by rollout cohort;
- oldest due item, oldest retry, permanent failures, reconciliation backlog;
- active and expired leases;
- last successful create/update/cancel/reconcile and last cron completion;
- mappings missing a source, active sources missing a mapping, duplicate local
  event IDs, and non-NFC/invalid projection counts;
- auth circuit and rate-limit status.

Health checks are read-only and make no Google call. A separately labeled,
permissioned one-item reconcile action only enqueues worker intent; no production
web request is permitted to contact Google or load either secret.

Access tests cover anonymous, ordinary authenticated, diagnostic-only, mutation,
configuration, and cross-user submission cases, including cached-page leakage.

## Scheduler, heartbeat, and alerting contract

The current repository provides only Drupal automated cron at 10,800 seconds,
and `drupal/scripts/deploy-staging.sh` does not install a dedicated scheduler.
That is not a deployable synchronization SLO.

PR 3 must add a versioned scheduler artifact and runbook with the exact non-web
command, dedicated OS/service identity, working directory, environment source,
cadence of at most five minutes, timeout, overlap policy, exit semantics,
install/verify/remove commands, and GitHub-clean-checkout prerequisites. Disable
the automated web-cron path for remote processing; only this CLI runner receives
write-gate and secret injection. Leases remain the correctness control even when
the scheduler suppresses overlap. The runner records a durable heartbeat at
start and completion even when no row is due.

The deployment command serializes control changes against claims. Disabling
writes increments the database control generation first. Mode/cohort/target/
principal changes then require the scheduler stopped, all prior-generation
leases/finalizers accounted for, zero active lease, and gate false; enabling is a
separate final generation. An old worker or partially published deployment sees
a tuple mismatch and cannot prepare identity, claim, load a secret, or dispatch.

PR 6 connects an external dead-man alert that fires after two scheduled
intervals plus an approved grace period without a completed heartbeat. The
owner record names the primary
and backup destination, severity thresholds, deduplication key, acknowledgement
deadline, escalation path, maintenance suppression, and recovery notification.
Other alerts include permanent/poison failures, queue-age SLO, auth circuit,
target mismatch, privacy validator, duplicate identity, expired lease, and
reconciliation age. A fake notification sink and a controlled non-production
delivery test prove firing, acknowledgement, escalation, deduplication, and
recovery without exposing event/user/secret data.

## Test strategy

No test may depend on a developer credential or the production calendar. The
local suite injects a fake clock, deterministic jitter, fake auth provider, and
a loopback fake Calendar server. It records method/path/headers/body in memory,
redacts authorization before assertion output, and can commit a remote result
before simulating a dropped response. Concurrency tests use two real worker
processes or connections against the same test database; mocks alone are not
sufficient for lease claims.

Every test asserts the database state, action, revision, attempts, retry time,
lease cleanup, activation generation/cohort, attempt metadata, secret-provider
read count, HTTP call count, exact event ID, minimal payload, and absence of
forbidden data.

PR 1 creates the test database/bootstrap, fake clock/auth/transport seams, and a
required CI job with one exact command that runs from a clean GitHub checkout
without a credential or external network. Later PRs extend that same harness;
phase 8 completes the matrix rather than introducing test infrastructure late.

### Local fake-server matrix

| ID | Scenario | Required assertions |
|---|---|---|
| GC-L01 | Create | One precommitted ID, one POST with same ID/link key, `queued -> processing -> synced`, durable ETag/hash |
| GC-L02 | Repeated create | Same ID on every delivery; validated 2xx or 409+GET adopts only matching event; mismatch and reachable 404/410 tombstone quarantine; at most one fake-server event |
| GC-L03 | Update/reschedule | GET verifies linkage, conditional update changes start/end, latest revision becomes `synced` |
| GC-L04 | Repeated update | `events.update` PUT uses full allowlist, exact `If-Match`, and `sendUpdates=none`; second delivery converges, strips legacy fields, and creates no event |
| GC-L05 | Cancel | Same mapped ID deleted; `cancel_pending -> processing -> cancelled` |
| GC-L06 | Repeated cancel | Event 404/410 plus successful target probe is absence; inaccessible-target 404 preserves intent/circuit; no recreated event |
| GC-L07 | Timeout before any remote success/response | Connect or pre-commit read timeout follows bounded retry; same create ID later succeeds once |
| GC-L08 | Timeout after remote create/update/cancel success | Reconcile observes the fence and exact mapped presence/absence; no conflicting write/second identity and converged state |
| GC-L09 | 401 refresh | Cached token discarded, provider mints once, identical request replayed once; persistent 401 finalizes the probe row and opens remote-probe circuit; no token in DB/log/output |
| GC-L10 | 403/obscured-404 permission | Target-level failure opens degraded auth/target health and stops claims; event-specific denial quarantines one item; an inaccessible-target 404 is never treated as absence |
| GC-L11 | 403 quota and 429 | Reason-aware retry, bounded `Retry-After`, jitter, no hot loop |
| GC-L12 | 404 on update | `reconciliation_required`; same ID checked/recreated only under remote-missing policy |
| GC-L13 | 404/410 on cancel | Reachable target yields idempotent `cancelled`; probe 401/403/404 preserves cancel/reconciliation and opens circuit |
| GC-L14 | 500/502/503/504 | Post-dispatch writes reconcile before retry; proven pre-dispatch/GET failures back off; shared 10-attempt/48h ceiling ends visibly |
| GC-L15 | Concurrent workers | Exactly one claim/call for a revision; loser does no HTTP; stale finalizer affects zero rows |
| GC-L16 | Expired lease | Before dispatch, unchanged revision retries but newer update/cancel supersedes old intent; after dispatch it fences/reconciles; delayed stale update/delete fail `If-Match`, expired finalizer changes zero rows |
| GC-L17 | Invalid payload | Zero HTTP, `permanent_failure/payload_invalid`, forbidden fields identified without their values |
| GC-L18 | Europe/Paris DST boundaries | Spring gap rejected; fall ambiguity follows explicit policy; valid pre/post-boundary instants and 60-minute elapsed duration are exact |
| GC-L19 | Deleted remote event/tombstone | Active source uses the same ID; reusable ID recreates, retained tombstone becomes visible permanent failure with manual fallback; cancelled source converges only after reachability proof |
| GC-L20 | Manual remote edit | ETag/projection drift detected; Drupal fields win; no remote field imported into Drupal |
| GC-L21 | Cancellation during ambiguous create | New desired revision survives old finalizer; delete same precommitted ID; final state `cancelled` |
| GC-L22 | Mutation during processing/failure | Old facts persist and latest desire wins; ambiguity stays reconcile-first, permanent collision stays latched, and pre-dispatch cancel never retries old intent |
| GC-L23 | Poison item | Attempt/age ceiling stops automatic claims; admin list and health show redacted failure |
| GC-L24 | Rollback/kill switch | Gate-off increments activation generation; old/new concurrent workers fail claim or pre-dispatch CAS with zero secret/HTTP, while already dispatch-authorized leases are accounted; rollback-floor code preserves and continues recording new state without claiming it |
| GC-L25 | No duplicate event | Across create retries, crashes, two workers, 409, and both timeout positions, count by link key and live mapped ID is exactly one |
| GC-L26 | Privacy/logging | Payload has only allowlisted fields; DB/admin/logs contain none of the forbidden data or authorization value |
| GC-L27 | Missing mapping/source/restore | Derive and GET all retained-target × retained-key candidates; one match reconstructs exact binding, zero requires unambiguous provenance, and multiple/mismatch/unreachable/legacy cases quarantine without duplicate |
| GC-L28 | Gate/dry-run/cohort truth table | Every combination matches the table; missing/partially published/mismatched deployment-control tuples are invalid; off/blocked/held precede selection and both secrets; preview makes zero row change/HTTP; only the exact generation/cohort can prepare or claim |
| GC-L29 | Fresh install and upgrade | Clean install and every supported legacy config state receive safe defaults; valid-looking active config still cannot bypass absent deployment gate |
| GC-L30 | Target/principal rebinding | Changed environment, target, or principal fingerprint opens health/circuit and produces zero credential read/HTTP for existing mappings |
| GC-L31 | Interrupted online migration | Every bounded step restarts; enqueue during backfill wins CAS; collision/orphan quarantine precedes UNIQUE constraints; counts converge |
| GC-L32 | Auth circuit concurrency | One CAS half-open credential or remote probe, persistent-401 path, bounded cooldown, expired probe recovery, no queue drain, and correct probe-row finalization |
| GC-L33 | Scheduler and alert delivery | Empty-run heartbeat, dead-man firing/recovery, dedupe, acknowledgement/escalation, and redacted fake-sink payloads work |
| GC-L34 | Admin authorization | Anonymous/cross-user/cache-leak cases are 403; build and submit recheck action permission plus source access; a valid-CSRF cross-user POST causes zero transition/HTTP; orphan actions need their distinct permission; mutation is POST+CSRF+CAS and accepts no target/event ID |
| GC-L35 | Rollback bridge | While rollback-floor code serves bookings, create/update/cancel intent is durable; roll-forward/reconcile converges without duplicate HTTP |
| GC-L36 | Authentication/transport boundary | Exact scope/principal/type/fingerprint and clock are validated; unexpected source/endpoint/redirect/fallback or certificate/hostname/protocol fault fails closed, and TLS verification cannot be disabled |
| GC-L37 | Retention/restore scrub | Bounded purge detaches approved identifiers, legacy values stay absent, and restored data is scrubbed before worker/admin availability |
| GC-L38 | Rollout cohort isolation | With scheduler paused, gate false and cohort `none`, mark exactly one mapping `smoke`; old/new workers race changes between selection, identity preparation, claim, and dispatch; direct gate-on `smoke -> ordinary` is rejected; stale generations make zero secret/HTTP; only after gate-off/drain/zero leases can ordinary work be released |
| GC-L39 | Web/worker secret isolation | PHP-FPM/web requests, automated web cron, health, and admin reconcile cannot open either secret or a socket to Google; reconcile only records intent; the dedicated CLI identity processes it |
| GC-L40 | Safe tombstone purge | Fresh proved absence permits bounded final purge; 401/403/target-404/unreachable/ambiguous/incomplete evidence preserves minimum opaque identity and key-version reference in visible quarantine |
| GC-L41 | Post-backup remote-only restore | Create a target/key version, mapping, and remote event after a snapshot, then restore it: external registry inventory plus full paginated managed-event listing finds and quarantines the remote-only event before any automatic write or UI/scheduler release |

### Pilot and production matrix

All destructive fault injection occurs against the dedicated test calendar,
first with local fake credentials and then with production-like service-account
authentication. The real production calendar gets only a controlled synthetic
smoke reservation after approval.

| Scenario group | Dedicated test-calendar pilot gate | Production-safe verification |
|---|---|---|
| Create and repeated create | Execute L01-L02 and verify one remote ID/link | One synthetic create; read-only linkage shows exactly one event |
| Update and repeated update | Execute L03-L04 with a synthetic reservation | Move the synthetic slot once, then authorized requeue; same ID, latest time |
| Cancel and repeated cancel | Execute L05-L06/L13 | Cancel the synthetic reservation and requeue once; remote absent and local `cancelled` |
| Timeout before/after success | Test through a controlled proxy/fake transport in the pilot environment, never by disrupting production networking | Rely on released automated evidence; monitor natural transport failures and reconcile one item if encountered |
| 401 renewal | Expire/invalidate only a pilot cached token while credential remains valid | Verify token-expiry health bucket and renewal metrics; do not revoke production credential as a test |
| 403 permission | Remove writer ACL only from the test calendar, prove circuit/alert, then restore | Read-only ACL owner sign-off and health; no deliberate production permission outage |
| 403 quota, 429, 5xx | Fake server plus test-project quota/fault injection | Verify counters/backoff from natural responses; never generate quota load deliberately |
| 404 update/cancel | Delete the pilot event manually and run update/cancel cases | Use the already-cancelled synthetic event for safe repeated cancel; do not delete a live production booking event |
| Concurrent workers/expired lease | Run two pilot workers and terminate one after claim | Observe lease gauges; no deliberate production worker kill after rollout |
| Invalid payload | Inject fixture invalidity before HTTP | Deployment suite gate; production validator metric must remain zero |
| DST boundaries | Use synthetic dates around both Europe/Paris transitions in fake/pilot calendar | Read-only verify generated offsets for approved future synthetic dates; do not alter customer bookings |
| Deleted event/manual edit | Use only pilot events and prove Drupal-wins reconciliation | Health/reconciliation alert is enabled; operator runbook exercised on synthetic item only |
| Rollback | Disable pilot worker, roll code back with additive schema retained, then roll forward | Before live enablement, rehearse the named rollback-floor artifact and roll-forward release SHA; production rollback starts with kill switch and preserves data |
| No duplicate event | Query pilot by exact opaque link property and mapped ID across every fault case | Health duplicate detector remains zero; synthetic lifecycle retains one identity |

Production acceptance requires a saved, redacted evidence packet containing
commit, config-state booleans, test IDs, state transitions, counts, and operator
sign-offs. It must not contain calendar IDs if treated as internal, payloads,
event contents, authorization headers, credentials, or personal data.

## Safe PR roadmap

Real writes remain disabled through phase 8; phase 9 uses only the dedicated test
calendar. A phase may use multiple small PRs, but a PR has one objective and the
listed dependency order is preserved. Every schema change is additive and
idempotent, and each PR includes tests plus exact deployment/rollback notes.

Before PR 1 lands, the operational hold at the top of this document applies:
leave the current module disabled and do not follow its prototype "Real sync"
instructions. This is a precondition, not an extra roadmap phase.

1. **Secret/auth foundation.** Add an authentication-provider interface and the
   official auth library; add the atomically versioned non-UI deployment tuple
   and matching non-secret runtime-control row, fixed allowlisted auth and
   versioned idempotency-key providers, safe `config/install` defaults
   plus the matching site-level safe `drupal/config/sync` object, a conservative
   update hook, and a drift-safe import/deploy check; remove the
   arbitrary locator/target/live UI; split production service wiring so only a
   dedicated non-web CLI identity has auth/HMAC providers and networked Calendar
   client; validate principal/type/scope/endpoint/clock and fail closed. Establish
   the clean-checkout test bootstrap/CI command with fake auth/clock/transport
   before any token code. Keep the event client disabled. Acceptance:
   GC-L24/L28/L29/L30/L36/L39 pass, renewal works only against the fake token
   endpoint, and no secret is persisted/logged.
2. **Durable state and schema.** Add the immutable versioned target registry,
   externally recoverable registry record, additive mapping fields including the
   default-ordinary claim cohort and processing activation generation,
   metadata-only attempt table, independent stable
   event/link IDs, and compatibility rollback bridge. Execute the online
   expand/compatibility-bridge/backfill/quarantine/validate/constrain/cutover sequence in
   bounded CAS batches. Stop new legacy payload/error writes and add the scrub
   mechanism, without dropping legacy columns. Acceptance: all legacy statuses,
   interruption, concurrent enqueue, orphan/collision, fresh-install, scrub, and
   rollback-floor fixtures pass GC-L29/L31/L35/L37/L41.
3. **Leases/retries.** Implement generation/cohort-bound CAS claim and final
   pre-dispatch authorization, two-minute lease, expired-lease recovery,
   generation-aware finalizer skeleton, error taxonomy, bounded
   backoff/jitter, poison ceiling, durable CAS auth circuit, runner heartbeat,
   and the versioned scheduler/runbook artifact. Acceptance: two-process,
   fake-clock, half-open, old/new activation-generation, and empty-heartbeat
   tests prove one claim, a maximum six-hour delay, no infinite retry, and no
   lost probe row; GC-L38 passes; alert delivery is deliberately deferred to
   phase 6.
4. **Idempotent client.** Inject API base only in test services; add explicit
   event ID, GET, ETag conditional update, reason-aware errors, bounded timeouts,
   target reachability probe, inline exact-ID ambiguity resolver, 401 one-refresh
   replay, 409/412 handling, and response redaction. This phase handles the
   current operation's ambiguity; phase 7 adds scheduled/admin/global repair.
   Acceptance: both timeout positions and all HTTP classes pass with one event
   identity.
5. **Create/update/cancel finalizers.** Replace legacy payload with the minimal
   canonical projection; queue all managed-field changes and deletion; implement
   revision/lease-checked finalizers and cancel-after-partial-create. Acceptance:
   GC-L01-L11, GC-L13-L18, GC-L21-L22, and GC-L26 pass; GC-L12 proves entry to
   reconciliation, while phase 7 completes deleted-event policy; real writes
   still off.
6. **Admin diagnostics.** Add separate permissions, read-only list/item/health,
   one-item POST retry/requeue and reconcile-intent enqueue, redacted transition
   history, durable worker health, and notification/dead-man routing. The web
   path never reads a secret or contacts Google. No public route and no raw
   payload/error or full event-ID display. Acceptance: GC-L33/L34/L39,
   alert-delivery, access/CSRF/cache, and unauthorized-data tests pass.
7. **Reconciliation.** Add per-item and bounded rolling mapping reconciliation,
   missing-link repair, remote delete/drift/collision policies, duplicate
   detector, restore-only full managed-event inventory, and proof-gated tombstone
   purge/quarantine. Remove the no-op inbound stub if no longer useful.
   Acceptance: GC-L19-L20/GC-L25/GC-L27/GC-L40/L41 pass and Google never changes
   Drupal truth.
8. **Local fake-server tests.** Complete GC-L01-L41 and make the matrix a required CI
   job, including two-process database concurrency and UTF-8/NFC/privacy scans.
   Acceptance: reproducible from a clean GitHub checkout with no credential or
   external network.
9. **Dedicated test-calendar pilot.** Owner provisions private test calendar and
   runtime identity, resolves the public Appointment Schedule go/no-go, executes
   the legacy-data remediation, approves the command-exact runbook, enables
   one-item batches in an explicitly marked smoke cohort only for that calendar,
   executes the pilot matrix, rotates
   the pilot credential, advances the identity-key version for a new mapping
   while proving old mappings remain stable, and rehearses kill switch/rollback
   floor/roll-forward.
   Acceptance: redacted evidence and four independent reviews signed on the SHA.
10. **Production rollout.** Deploy disabled and scheduler-paused; run schema/
    read-only health; confirm immutable target/ACL/backup/restore scrub/scheduler/
    dead-man alerts; mark exactly one synthetic mapping `smoke` with the gate off,
    manually execute its one-item lifecycle while ordinary work remains held,
    close the gate, then separately release ordinary one-item batches in a
    staffed window; increase conservatively after 24h/7d gates.
    Acceptance: no duplicate, poison, expired lease, target, privacy, heartbeat,
    or auth alert; owner authorizes continued operation.

### Rollback contract

1. Set the environment live-write gate off and verify no new claims.
2. Allow the short lease window to expire or account for all active/in-flight
   operations; reconcile those items before any manual remote change.
3. Preserve all mapping/attempt tables and new columns. Do not run down migrations
   and do not clear event IDs.
4. Roll back only to the tested **rollback floor** delivered before migration.
   That bridge accepts current table constraints, keeps producing the new desired
   model for create/update/cancel, never emits claimable legacy `pending` work,
   and keeps the remote worker hard-gated off. Returning to the currently audited
   code is prohibited once migrated state has been written: its producer rewrites
   legacy `pending` rows and is not schema/state compatible.
5. Exercise one synthetic create/update/cancel through the rollback-floor producer
   without HTTP, then roll forward and reconcile the preserved intent. Keep health
   evidence. Remote bulk deletion is not a rollback mechanism.

Rollback authority, minimum compatible artifact SHA, database/application RPO
and RTO, and the maximum gate-off business interval are approved before pilot.

## Review and validation gates

Every implementation PR must include these independent review lenses:

- **Google API architecture:** client-chosen identity rules, scope, 401/403/404/
  409/410/412/429/5xx semantics, ETag use, quota behavior, and response ambiguity;
- **concurrency:** enqueue/claim/finalize races, cancellation during create,
  expired lease and stale-worker behavior, unique constraints, and two-process
  evidence;
- **security/privacy:** secret lifecycle, permissions/CSRF, log and error
  redaction, minimal event projection, retention, and incident rotation;
- **operations:** config reproducibility, cron cadence, health/alerts, poison
  handling, pilot, rollout, rollback, and owner coverage.

Static acceptance checklist for this design change:

- factual inventory rechecked against the named files and latest base;
- only the two requested functional documents changed; no diagnostic script was
  justified because ordinary read-only repository commands provide the needed
  evidence;
- no credential value or credential file inspected or included;
- no Google API/VPS/application call and no production mutation;
- every state is defined and every automatic/manual transition has an exit;
- idempotency reviewed for duplicate delivery, ambiguous success, cancellation,
  and two workers;
- privacy allowlist and prohibited-data list reviewed independently;
- both documents decode as UTF-8 and are NFC normalized;
- `git diff --check` passes;
- a diff-scoped secret-pattern scan and exact-path scope check pass for this
  design PR;
- documentation links and official API assumptions are reviewed as of the date
  above.

Before any connected pilot, run an approved scanner over the tracked tree and
Git history with redacted/filename-only output, enable GitHub secret scanning
and push protection where available, and rotate immediately if a match is
confirmed. A clean diff scan is not proof that repository history is clean.

## Owner decisions still required

Implementation cannot start its connected pilot until the owner checklist
records, outside Git when sensitive, the calendar owner and backups, direct
service-account writer-sharing result, approved secret store and rotation owner,
immutable production and legacy target bindings, privacy/retention/legacy scrub,
administrator role assignments, scheduler/alert/SLO ownership, public Appointment
Schedule disposition, pilot window, RPO/RTO, rollback authority/floor, and four
review signatures on the release SHA. The checklist intentionally contains no
place to paste a credential value.
