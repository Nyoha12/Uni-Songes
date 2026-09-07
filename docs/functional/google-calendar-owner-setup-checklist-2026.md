# Google Calendar owner setup checklist 2026

Date: 2026-09-02

Companion design: [google-calendar-production-readiness-2026.md](google-calendar-production-readiness-2026.md)

## How to use this checklist

This is a go/no-go checklist for the Uni-Songes owner, Google administrator,
deployment operator, privacy reviewer, and Drupal maintainer. Complete it first
for a dedicated test calendar and again for production. Keep sensitive answers
in the approved private operations system, not in this tracked document.

Do not paste or attach a client secret, refresh token, service-account JSON,
private key, idempotency HMAC key, access token, authorization header,
secret-manager payload, or credential screenshot in this file, GitHub, Drupal
configuration, a ticket, a chat, or logs. Record only approval, accountable
role, date, and a non-secret private-record reference where organizational policy
permits it.

Production synchronization remains a **no-go** until every required checkbox is
complete, the implementation phases through the dedicated-calendar pilot have
passed, and the named release owner approves the production window. Phase 9 may
open only the separate pilot runtime's gate for its test identity and target.

## 1. Decision record

- [ ] Authentication decision accepted: dedicated service account, directly
  shared private secondary calendar, no user refresh token.
- [ ] A durable organizational account owns the calendar; it is not a personal
  volunteer account and not the service account.
- [ ] Direct sharing to the service-account principal is permitted and tested by
  the applicable Google/Workspace policy.
- [ ] Domain-wide delegation is disabled and is not an accepted workaround.
- [ ] OAuth scope is exactly
  `https://www.googleapis.com/auth/calendar.events`; no calendar-sharing/ACL
  management scope is granted. Calendar ACLs, not this OAuth scope, constrain
  which calendars the principal can reach.
- [ ] Drupal Webform remains the source of truth. Manual Calendar edits never
  update a reservation.
- [ ] The target calendar will contain only Uni-Songes reservation events or the
  owner has approved a stricter isolation rule that produces the same safety.
- [ ] The test and production calendars, credentials, and non-secret target
  selectors are distinct.

Record outside Git:

| Decision | Accountable role | Approval date | Private record reference |
|---|---|---|---|
| Authentication and no domain-wide delegation |  |  |  |
| Calendar owner and backup owner |  |  |  |
| Secret store and rotation |  |  |  |
| Privacy allowlist and retention |  |  |  |
| Legacy target binding and data remediation |  |  |  |
| Public Appointment Schedule disposition |  |  |  |
| Booking-to-calendar SLO and capacity |  |  |  |
| Scheduler, dead-man alert, and escalation ownership |  |  |  |
| Rollback floor, authority, RPO, and RTO |  |  |  |
| Out-of-RPO target/key-version recovery inventory |  |  |  |
| Pilot go/no-go |  |  |  |
| Production go/no-go |  |  |  |

## 2. Calendar ownership and sharing

- [ ] Create a dedicated secondary calendar for the environment.
- [ ] Set the calendar timezone to `Europe/Paris`.
- [ ] Keep the calendar non-public and confirm organization/domain visibility
  does not expose event details unexpectedly.
- [ ] Assign a durable organizational owner and at least one documented backup
  administrator able to recover or transfer the calendar.
- [ ] Owner and backup administration use individually attributable accounts,
  enforced MFA, reviewed recovery methods, and no shared password. Alert the
  named owners on ACL changes, public/domain publication, ownership transfer, or
  recovery-setting changes.
- [ ] Grant the service-account principal only the level needed to create,
  inspect, update, and delete events on this calendar (writer/event-change
  access), not sharing administration.
- [ ] Share event details only with the minimum named staff who schedule or
  deliver lessons.
- [ ] Confirm no group, public link, inherited domain rule, mobile integration,
  or third-party application broadens access beyond the approved audience.
- [ ] Inventory every calendar shared to the service-account principal at setup
  and on a recurring schedule. The production principal has access only to the
  approved production calendar; test and production principals are distinct.
- [ ] Record the calendar's non-secret logical environment key in the private
  operations inventory. Do not put the actual production calendar identifier in
  test configuration or evidence packets.
- [ ] Preserve the full immutable target-version registry (restricted Calendar
  binding, namespace, fingerprints, lifecycle dates) in database recovery and
  a restricted append-only operations inventory outside the same database RPO so
  restore recovery can enumerate old and post-snapshot targets. Verify retrieval
  before the first write on each version.
- [ ] Confirm who owns manual edits: staff may view events, but edits to managed
  fields are temporary drift and Drupal will overwrite them.
- [ ] Record a mandatory go/no-go outcome for the existing public Google
  Appointment Schedule page: it is retired/disabled before rollout, or an owner
  proves that its calendar, availability, and capacity are isolated so it cannot
  double-book a Drupal slot. Unknown or merely "still in use" is a no-go. Never
  reuse its identifiers without an independently reviewed migration.

Owner decisions:

| Question | Required answer before pilot |
|---|---|
| Who owns the calendar if the primary operator leaves? | Named durable role and backup |
| Can Workspace policy share directly to the service account? | Verified yes; otherwise stop for architecture review |
| May staff manually change generated events? | No for managed fields; correction occurs in Drupal |
| What happens if someone deletes a generated event? | Alert and reconciliation from Drupal using the same mapped identity |
| May unrelated events be created in this calendar? | Prefer no; if yes, they must never carry the Uni-Songes opaque linkage marker |
| What is the public Appointment Schedule outcome? | Retired, or documented isolation proof; otherwise no-go |

## 3. Google Cloud project and workload identity

- [ ] Use a project controlled by the organization, with at least two accountable
  project administrators and recovery details held privately.
- [ ] Enable only the APIs required by the reviewed implementation.
- [ ] Create one service account dedicated to this integration/environment; do
  not reuse a deployment, backup, analytics, or developer identity.
- [ ] Grant no project-wide role to the service account merely to use Calendar.
  Calendar access comes from the calendar ACL.
- [ ] Do not enable Google Workspace domain-wide delegation.
- [ ] Restrict who can create, download, view metadata for, rotate, disable, or
  impersonate the service account.
- [ ] Review inherited IAM roles, every allowed impersonator, and domain-wide
  delegation grants in both Google Cloud IAM and Workspace Admin; record zero
  unexpected grants.
- [ ] Select exactly `https://www.googleapis.com/auth/calendar.events` in the auth
  provider; assert it in tests and do not request broader calendar/ACL scopes.
- [ ] Prefer an approved keyless workload identity if this host can support it
  without broader privileges or a fallback credential search. Otherwise use one
  explicitly loaded service-account credential for this environment.
- [ ] Confirm quota ownership and alerts for the project. The worker's backoff
  remains mandatory even when expected traffic is low.
- [ ] Record the project, principal, and key metadata only in the approved private
  inventory. Never record private credential content.

## 4. Secret storage and renewal

- [ ] Select an approved encrypted secret manager or equivalent host-managed
  secret facility.
- [ ] Provision a separate versioned idempotency HMAC key. Use a new version only
  for new mappings; retain referenced versions through the longest mapping/
  tombstone and backup-recovery horizon. Ensure the approved secret facility can
  recover a version created after the database snapshot; only version metadata,
  never key material, enters the external recovery inventory. Rotation never
  changes existing IDs.
- [ ] Inject the credential as a read-only runtime file outside Git checkout and
  outside the Drupal document root. Do not store JSON in an exported Drupal
  config object, database state, queue row, deployment log, shell history, or a
  world-readable environment dump.
- [ ] Restrict file read access to the dedicated non-web CLI worker OS/service
  identity and secret-provisioning identity. PHP-FPM, the web-server identity,
  interactive Drupal administrators, and automated web cron cannot traverse or
  read either the service credential or idempotency-key mount.
- [ ] Inject a fixed, allowlisted locator through deployment-owned settings. It is
  absent from active/exported Drupal config and cannot be selected or probed by
  a Drupal administrator.
- [ ] Validate expected credential type, principal, project, and deployment-held
  fingerprint before token minting. Disable all ADC/developer/user/metadata
  fallback discovery.
- [ ] Fix the OAuth token and Calendar API origins to reviewed HTTPS endpoints,
  disable redirects, and permit endpoint overrides only through test services.
- [ ] Monitor system clock/NTP health within the JWT issue/expiry tolerance.
- [ ] Confirm the authentication library keeps short-lived access tokens in
  process memory only and refreshes before expiry.
- [ ] Confirm the worker persists only timestamped `available|unavailable` and a
  coarse expiry bucket with bounded TTL. The web health page never reads the
  credential or mints a token and never displays credential fields/tokens.
- [ ] Production web service wiring has a non-networked Calendar/auth stub. An
  admin reconcile POST only records durable intent; only the dedicated CLI
  worker can mint a token or contact Google.
- [ ] Exclude the secret mount and authorization data from backups, support
  archives, crash/core dumps, APM request capture, reverse-proxy logs, and PHP
  error pages.
- [ ] Define a normal rotation interval and reminders in the private operations
  system.
- [ ] Enforce maximum credential age and inventory exactly the expected active
  keys/identities; do not assume a user-managed key expires automatically.
- [ ] Rehearse overlapping rotation on the test identity: create/release new
  credential, verify one synthetic lifecycle, revoke old credential, confirm
  health.
- [ ] Define immediate containment for suspected exposure: live gate off, remove
  Calendar ACL or disable the service account, account for the maximum remaining
  lifetime of already minted access tokens, then replace credentials. Key
  rotation/deletion alone is not assumed to revoke an existing access token.
- [ ] Name primary and backup rotation operators.
- [ ] Before pilot, scan the tracked tree and Git history with an approved scanner
  whose evidence is redacted/filename-only; enable GitHub secret scanning and
  push protection where available. A confirmed match triggers rotation, not
  publication of the matched value.

Secret incident stop conditions:

- [ ] Any credential in Git, Drupal config/export, logs, diagnostics, evidence,
  issue/PR content, chat, or screenshot triggers immediate revocation/rotation.
- [ ] The worker live-write gate stays off until the incident owner confirms the
  replacement and completes a synthetic test.
- [ ] Queue/mapping data is preserved during the outage; operators do not mass
  retry while authentication health is open.

## 5. Environment and configuration contract

- [ ] Versioned config has only safe defaults: worker disabled, dry-run enabled,
  `Europe/Paris`, conservative batch size, bounded retry/lease defaults, and
  no provider, environment, principal, locator, or target identity.
- [ ] The same safe object exists under the module's `config/install` and the
  site's `drupal/config/sync`; the deployment/config-import check proves neither
  drift nor a settings override can export environment identity or bypass the
  independent write gate.
- [ ] Environment mode, non-secret target binding/fingerprints, expected
  principal, and fixed secret locator are supplied by deployment-owned settings,
  not active config or `config/sync`.
- [ ] Production has a separate live-write allow gate that fails closed outside
  the intended environment, is not editable in Drupal, and is checked before row
  selection, remote-target resolution, auth/idempotency secret access, or HTTP.
- [ ] Mode, gate, cohort, target/principal fingerprints, and monotonically
  increasing activation generation are atomically published as one deployment
  snapshot and must exactly match a non-secret runtime-control row. Missing,
  partial, or stale tuples derive `invalid`; only the deployment CLI can update
  the row.
- [ ] The deployment-owned allowed claim cohort defaults to `none`. Every mapping
  defaults to `ordinary`; only the gate-off, audited, exact-item CLI command can
  mark `smoke`. The worker filters by the exact allowed cohort before target or
  secret resolution, and the setting is unavailable to Drupal forms/public URLs.
- [ ] Gate-off increments the control generation before drain. Mode/cohort/
  target/principal changes are rejected unless the gate is false, scheduler is
  stopped/drained, and all leases/dispatch authorizations are accounted with zero
  active leases. Direct gate-on `smoke -> ordinary` is impossible; re-enable uses
  a separate generation.
- [ ] Only the named deployment role can run the control command; every accepted
  or rejected change records actor, old/new generation, safe fields, timestamp,
  and approval reference without a locator or secret value.
- [ ] `enabled = false` prevents claims but continues recording durable desired
  state.
- [ ] `dry_run = true` previews without calling Google and without consuming or
  changing a queued item to success/skipped.
- [ ] Each mapping references an immutable, versioned target-registry row holding
  the exact Calendar binding. Deployment environment, target fingerprint, and
  principal fingerprint must match before HTTP. A selector change creates a new
  version and explicit migration/reconciliation; it cannot redirect old writes.
- [ ] Event ID and linkage marker use separate HMAC domain labels, immutable
  target namespace, and a recorded key version. Missing-mapping recovery checks
  every plausible retained target-version × retained-key-version combination;
  zero matches may create only with unambiguous target provenance, while an
  unreachable old target or any ambiguity quarantines.
- [ ] HTTP endpoint override exists only in test service wiring; administrators
  cannot configure an arbitrary production URL.
- [ ] Initial production batch size is one and the reviewed cron interval is at
  most five minutes.
- [ ] The scheduler cannot overlap uncontrolled workers; row leases remain the
  correctness mechanism even if scheduling also prevents overlap.
- [ ] Deployment, web, and worker agree on safe non-secret config/schema, while
  only the dedicated CLI worker receives the secret source and write/cohort
  inputs. Automated web cron cannot invoke remote processing.
- [ ] Fresh-install and upgrade tests prove safe defaults. The complete gate/
  `enabled`/`dry_run`/cohort truth table proves preview is non-consuming and every
  inconsistent combination makes zero auth/idempotency secret read and zero HTTP
  request.
- [ ] Claim stores activation generation/cohort. Identity preparation serializes
  on the control row; immediately before credential read/HTTP, a CAS rechecks
  generation, gate, cohort, target/principal, revision, token, and unexpired
  lease. Mismatch safely requeues with zero remote-attempt debit/secret/network.
- [ ] A versioned scheduler/runbook supplies the exact non-web command, identity,
  working directory, environment source, install/verify/remove steps, timeout,
  overlap policy, and cadence of at most five minutes.

No values belong in this checklist. In the private deployment record, account
for these configuration classes:

| Class | Examples of meaning | Storage expectation |
|---|---|---|
| Safe versioned default | `enabled=false`, `dry_run=true`, timezone, batch/retry/lease limits | Drupal `config/sync`, with no environment identity |
| Environment-specific restricted metadata | mode, exact target registry binding/fingerprint, expected principal, fixed secret locator | deployment settings override and restricted target registry; never ordinary evidence |
| Secret | service credential/private key material and idempotency HMAC key versions | encrypted secret manager or protected runtime mount only |
| Ephemeral | minted access token | process memory only |

## 6. Privacy and data minimisation

- [ ] Privacy owner approves Calendar as a processor/destination for the minimal
  scheduling projection and records the applicable retention/legal basis
  privately.
- [ ] Calendar payload allowlist is limited to opaque ID/link key, generic lesson
  label, start/end, `Europe/Paris`, non-personal mode/instrument, categorical
  location (`Studio`, `Visio`, or `À domicile` only), private visibility, busy
  transparency, fixed non-personal `managed_by` marker, and schema version.
- [ ] Event description is empty by default.
- [ ] No name, email, telephone, account ID, SID, raw submission UUID, home
  address, postal code, free-text note, attendee, meeting link, payment status,
  price, method, Commerce order/item ID, or secret is sent to Google.
- [ ] Staff use the access-controlled Drupal reservation to retrieve contact or
  home-address details.
- [ ] Before production writes, the compatibility bridge has stopped new legacy
  payload/raw-error writes and a bounded scrub has been executed and verified
  for live database rows, application logs, and support exports.
- [ ] Backups have an approved numeric retention/access policy; every restore
  reruns the legacy scrub before the worker or diagnostics become available.
- [ ] Privately inventory any events the prototype may already have written to
  Google and complete owner-approved remediation. Every exception has a named
  owner, expiry, restricted access, and closure evidence.
- [ ] Attempt records contain metadata only and have a documented numeric
  retention period plus a tested purge job.
- [ ] Calendar retention for cancelled/past events is defined. Cleanup must not
  erase the local mapping needed to prevent duplicates.
- [ ] Logs, metrics, alerts, and evidence use opaque mapping IDs and allowlisted
  error classes; no payload or raw Google response is captured.
- [ ] Treat SID/UUID, target/event/link identifiers, schedule times, hashes, and
  operator UID as pseudonymous data. A private extended property is neither
  anonymous nor encrypted and never holds a credential.
- [ ] Define erasure/DSAR treatment across Drupal DB, attempts, logs, backups,
  Calendar trash/tombstones, exports, and Workspace Vault/legal hold. A 404/410
  proves sync convergence, not necessarily regulatory erasure.
- [ ] After the approved recovery period, detach SID/UUID from cancelled mappings
  and retain only a bounded source-UUID hash plus minimum opaque target/event/
  link tombstone needed for idempotency. A matching detached source cannot
  restore automatically.
- [ ] Final identity purge requires a fresh proved 404/410 on the event, a
  successful same-principal probe of the frozen target, and no ambiguous request
  or lease. A 401, 403, target-level 404, unreachable target, or uncertain result
  preserves the minimum opaque target/event/link/key-version evidence in a
  restricted quarantine with owner, review date, and exception expiry.
- [ ] UTF-8/NFC normalization and forbidden-field tests pass.

Privacy owner decisions:

| Decision | Required recorded outcome |
|---|---|
| Event audience | Minimum named roles/accounts |
| Managed field allowlist | Approved fixed projection |
| Calendar event retention | Period and deletion owner |
| Local mapping/tombstone/attempt/log retention | Numeric periods sufficient for idempotency/audit, with purge evidence |
| Backup/export/Vault and restore treatment | Numeric retention/access and pre-enable restore scrub |
| Legacy payload/error/remote-event scrub | Completed migration/remediation evidence, not future approval |
| DSAR/erasure | Responsible role and support-by-support procedure |
| Data incident contact | Primary and backup roles |

## 7. Administrative access and support

- [ ] Assign `view google calendar sync diagnostics` only to staff who may see
  reservation/event linkage.
- [ ] Assign retry/requeue and reconcile permissions separately to the minimum
  operators. Configuration administrators do not receive them implicitly unless
  explicitly approved.
- [ ] All integration permissions use `restrict access: true`; no role receives
  them during installation/update. Missing-source visibility uses a separate
  exceptional permission.
- [ ] Confirm every list/detail row requires both its integration permission and
  Webform submission `view` access, with access-result cacheability metadata,
  user/permission contexts, and source/mapping cache tags propagated.
- [ ] Confirm item mutations use Form API confirmation, POST, CSRF, server reload,
  and revision/state CAS. Route/form build and submit each require the specific
  action permission plus current Webform submission `view` access; missing-source
  actions require a separate orphan-action permission. Actor UID is server-
  derived and the reason is an allowlisted code, not arbitrary free text.
- [ ] Confirm a valid-CSRF cross-user POST returns 403 with zero state transition,
  queued remote work, secret read, or HTTP. Reconcile submit only queues intent
  for the CLI worker.
- [ ] Confirm target/calendar/event IDs are loaded from the server mapping and
  never accepted from POST. The initial UI shows only a masked event ID with no
  reveal/copy control.
- [ ] Confirm no public queue page, API/feed, anonymous diagnostic, arbitrary URL,
  arbitrary event ID, raw payload, raw response, or bulk delete/retry exists.
- [ ] Train primary and backup operators to interpret queued, processing, synced,
  retryable failure, permanent failure, cancel pending, cancelled, and
  reconciliation required.
- [ ] Give operators a decision tree for 401, permission 403, rate-limit 403/429,
  404/410, 409, 412, 5xx, expired lease, collision, and poison items.
- [ ] Access tests cover anonymous, ordinary, diagnostic-only, mutation,
  configuration, cross-user, missing-source, CSRF, stale-CAS, and cached-page
  leakage cases.

## 8. Monitoring and operating schedule

- [ ] Monitor counts and oldest age by state/action/error class.
- [ ] Monitor eligible and held counts by `smoke`/`ordinary` cohort and alert if
  more than the approved smoke mapping becomes eligible during activation.
- [ ] Alert on any permanent failure, linkage collision, privacy validator
  failure, duplicate detector result, or expired lease.
- [ ] Alert on authentication circuit open, repeated 401, non-quota 403, and
  inability to mint a token or resolve every still-referenced idempotency-key
  version.
- [ ] Alert on queue age beyond the agreed service level, no successful cron,
  missing mapping/source, or reconciliation backlog.
- [ ] Record heartbeat start/completion even when no item is due. Configure an
  external dead-man for two scheduler intervals plus an approved grace period.
- [ ] Record last successful create, update, cancel, and reconcile.
- [ ] Keep alert payloads free of event content, user identifiers, raw responses,
  and secret metadata.
- [ ] Name an on-call/business-hours owner and backup for the initial rollout.
- [ ] Record primary/backup alert destinations, severity thresholds,
  deduplication key, acknowledgement deadline, escalation, maintenance
  suppression, and recovery notification.
- [ ] Prove firing, delivery, deduplication, acknowledgement, escalation, and
  recovery through a fake sink locally and a controlled non-production alert.
- [ ] Define the booking-to-calendar service objective and the manual fallback
  staff use while the worker is off.
- [ ] Schedule periodic ACL, credential age, calendar ownership, operator access,
  all calendars shared to the principal, IAM impersonators/DWD, retention, and
  duplicate-health reviews.
- [ ] Record numeric booking-to-calendar SLO, expected peak capacity/backlog,
  manual fallback, RPO/RTO, and a stop condition if the SLO cannot be met.

## 9. Dedicated test-calendar pilot

Prerequisites:

- [ ] Implementation phases 1-8 are merged into the pilot branch and their CI
  checks pass from a clean GitHub checkout without external credentials.
- [ ] Independent Google API architecture, concurrency, security/privacy, and
  operations reviews have no unresolved blocker.
- [ ] Test calendar and test service identity satisfy sections 1-8.
- [ ] Production calendar selector and credential are not available to the pilot
  runtime.
- [ ] Batch size is one; kill switch, dashboard, alerts, and rollback artifact are
  ready. Scheduler is paused and allowed claim cohort is `none`.
- [ ] The command-exact runbook covers preflight, additive updates, bounded
  backfill/resume, config override verification, health, activation, stop
  conditions, rollback floor, roll-forward/reconciliation, and redacted evidence.
- [ ] Legacy local/log/restore scrub and any prototype remote-event remediation
  are completed, not merely approved.
- [ ] The public Appointment Schedule is retired or its no-double-booking
  isolation proof is approved.

Execute with synthetic, non-personal reservations only:

- [ ] Set deployment mode `pilot` with the test target/identity, prepare
  `enabled=true`/`dry_run=false` and allowed cohort `none` behind a false gate,
  then create each synthetic reservation and mark its exact mapping `smoke` with
  the audited gate-off command. Assert a matching activation generation and no
  ordinary item eligible.
- [ ] Complete non-secret preflight, set the pilot allowed cohort to `smoke`, and
  with two-person approval open only the pilot gate in a separate activation
  generation. Keep the scheduler paused and invoke the explicit worker commands
  required by the test; production and every ordinary mapping remain held.
- [ ] Create and repeated create retain one event ID and one live event.
- [ ] Update and repeated update retain the same ID and latest Drupal time.
- [ ] Cancel and repeated cancel converge to remote absence.
- [ ] Simulate timeout before response and timeout after remote success.
- [ ] Exercise one token renewal and a test-calendar-only 401 path.
- [ ] Exercise permission 403, quota/rate-limit 403/429, 404 update/cancel,
  ACL-hidden 404 with a failing target probe, deterministic create 409, ETag
  412, and 5xx with controlled fault injection.
- [ ] Run two workers and recover leases both before and after remote-call start.
- [ ] Rebind the target/principal selector and prove the frozen-binding circuit
  makes zero credential read and zero Calendar request.
- [ ] Exercise exactly one concurrent half-open auth probe and an expired probe
  lease without draining queued attempts.
- [ ] Exercise invalid payload and retry exhaustion/poison visibility.
- [ ] Validate both Europe/Paris DST boundaries.
- [ ] Delete and manually edit only pilot events, then prove Drupal-wins
  reconciliation.
- [ ] Cancel while create outcome is ambiguous and prove the same ID is removed.
- [ ] Run duplicate detector by mapped ID and opaque link key: exactly one live
  event for every reservation.
- [ ] Run privacy and log scans: none of the prohibited fields or secret material
  appears.
- [ ] Rotate the pilot credential and repeat a complete synthetic lifecycle.
- [ ] Advance the pilot idempotency-key version for one new reservation, prove an
  older mapping keeps its exact IDs, and prove missing-mapping recovery checks
  both versions. Retain—not revoke—still-referenced old key versions.
- [ ] Deliver and recover a non-production dead-man/queue/auth alert through the
  named primary/escalation paths with redacted content.
- [ ] Disable the worker, account for all leases, deploy the minimum compatible
  rollback-floor artifact, record create/update/cancel intent without HTTP, then
  roll forward and reconcile.

Pilot evidence is redacted and records only commit, test case IDs, booleans,
counts, state transitions, timestamps, and sign-offs. It contains no credential,
calendar identifier, event content, authorization header, or personal data.

Independent review sign-off, recorded for the exact pilot release SHA:

| Review | Reviewer role | Result/date | Private evidence reference |
|---|---|---|---|
| Google API architecture |  |  |  |
| Concurrency/state machine |  |  |  |
| Security/privacy |  |  |  |
| Operations/deployment |  |  |  |

## 10. Production rollout gate

Before the window:

- [ ] Release SHA and immutable artifact are approved.
- [ ] Backup/restore policy covers Drupal mapping/attempt data without capturing
  the runtime secret mount.
- [ ] Additive database updates have been rehearsed and their count-only output
  reviewed.
- [ ] Online migration evidence covers expand, compatibility bridge, bounded CAS
  backfill, concurrent enqueue, orphan/collision quarantine, count validation,
  and constraints. The owner supplied the legacy target binding; it was not
  inferred from current config.
- [ ] Before backfill starts, the bridge writes the new model plus harmless
  empty/null legacy compatibility defaults only; no legacy payload/raw error is
  dual-written. Cutover re-verifies this rule before scrub.
- [ ] Restore testing sets a global reconciliation hold, checks derived ID/link
  candidates across every retained target/key version and legacy inventory for
  post-backup remote writes, and never treats a locally missing mapping as proof
  that Google was never called.
- [ ] Restore testing also merges the database registry with the restricted
  append-only inventory outside its RPO, then completely paginates partial-field
  `managed_by` event listings on every retained target, including a target/key
  version created after the snapshot. Any remote-only event is quarantined and
  no automatic write or diagnostics/scheduler release occurs before closure.
- [ ] Runtime deploys with deployment mode `live`, write gate false,
  allowed cohort `none`, `enabled=false`, and `dry_run=true`; derived health is
  `blocked`/`disabled` and no row selection, auth/idempotency secret read, or HTTP
  occurs. Deployment snapshot and runtime-control generation match; the scheduler
  is stopped and its absence is verified.
- [ ] Read-only health confirms schema/indexes, zero invalid projections, no
  duplicate event IDs, no active lease, and expected backlog classification.
- [ ] Calendar owner, full principal ACL/IAM/DWD inventory, timezone, immutable
  target version, secret availability, clock, scheduler, heartbeat, dashboard,
  and alert delivery receive two-person review.
- [ ] Legacy scrub/restore and prototype-event remediation evidence is complete,
  and the public Appointment Schedule disposition remains valid.
- [ ] Rollback authority and the staffed observation window are named.

Controlled enablement:

- [ ] Configure batch size one and keep the scheduler paused. While the write
  gate is false and allowed cohort is `none`, set `enabled=true` and
  `dry_run=false`; verify derived `blocked`, zero row selection, zero auth/
  idempotency secret read, and zero HTTP.
- [ ] Still gate-off, create exactly one synthetic production reservation with no
  personal/free-text data. Use the deployment-only audited one-item CAS command
  to change that mapping from default `ordinary` to `smoke`; assert exactly one
  smoke mapping, no lease, identity unprepared, and all backlog held.
- [ ] Complete the non-secret target/principal/schema/privacy/clock/health
  preflight and record two-person approval. Set only the deployment-owned allowed
  cohort to `smoke` under a new gate-off generation, then open the write gate in
  a separate matching generation and manually invoke the dedicated CLI worker
  with batch size one after each synthetic mutation. The scheduler remains
  paused; the first query can return only that smoke mapping.
- [ ] Verify create, same-ID update, cancel, repeated cancel, mapping, event count,
  minimal projection, logs, health, and zero identity preparation/claim/secret
  access/HTTP for every older ordinary backlog item.
- [ ] Exercise an old worker snapshot and a control change between selection,
  claim, and dispatch; each stale generation must fail the CAS and produce zero
  credential read/HTTP. Confirm a direct gate-on `smoke -> ordinary` change is
  rejected.
- [ ] Close the write gate immediately after the smoke; account for every lease,
  set allowed cohort back to `none`, and review the redacted evidence. Observe at
  least one full cron/lease/retry interval with ordinary work still held.
- [ ] Stop immediately on any duplicate, forbidden payload/log field, auth/
  permission issue, stale finalizer, unexplained reconciliation item, or schema
  mismatch.
- [ ] To stop, set the deployment write gate false first; only then change Drupal
  controls or deploy code.
- [ ] In a separate backlog-release decision, keep the gate false, set allowed
  cohort to `ordinary` under a new generation, start the dedicated scheduler,
  and verify a completed heartbeat with zero selection/secret/HTTP. Only then
  use a second two-person approval and separate generation to open the gate and
  release one ordinary item per batch.
- [ ] Keep the conservative batch/cadence for at least 24 hours; review again at
  24 hours and seven days before any increase.

## 11. Rollback and recovery decisions

- [ ] Kill switch procedure disables new claims without deleting backlog.
- [ ] Operators know that already-sent requests may finish; they enumerate every
  active/in-flight lease, wait for expiry, and reconcile each affected item.
- [ ] Rollback preserves `google_event_id`, opaque linkage, state, revision,
  leases, failures, and attempt history. No down migration is run.
- [ ] The approved minimum rollback-floor artifact is named by SHA. It supports
  current constraints and continues recording create/update/cancel in the new
  desired model while remote claims stay hard-gated off.
- [ ] Returning to the pre-migration/current prototype code is prohibited after
  migrated state exists; worker-off alone does not make its legacy producer safe.
- [ ] A rollback-floor rehearsal records synthetic create/update/cancel intent,
  then roll-forward reconciliation converges without duplicate HTTP.
- [ ] Remote bulk deletion is prohibited as rollback.
- [ ] Cancellation after an uncertain create is recovered by deleting the same
  precommitted event ID.
- [ ] A deleted/mismatched remote event remains a one-item reconciliation issue;
  operators do not assign a new ID or adopt by title/time. If Google refuses the
  retained ID/tombstone, the initial release uses visible permanent failure and
  the documented manual scheduling fallback; it has no relink action.
- [ ] Database restore procedure accounts for remote writes after the backup
  point, recovers target versions from the out-of-RPO append-only inventory,
  completely inventories fixed-marker events on every target, quarantines
  remote-only results, reruns privacy scrubs, and requires reconciliation before
  web diagnostics or the CLI scheduler are re-enabled.
- [ ] Credential revocation, Calendar ACL removal, code rollback, and database
  restore have separate owners and are not conflated.
- [ ] Rollback authority, rollback floor, application/database RPO and RTO,
  maximum gate-off interval, and manual business fallback are approved.

## 12. Final sign-off

The release owner answers each question with a documented yes outside Git:

- [ ] Is Drupal still authoritative in every create/update/cancel/reconcile path?
- [ ] Can duplicate delivery, timeout after success, and two workers create at
  most one live event?
- [ ] Can cancellation always converge after a partial failure?
- [ ] Are retry, poison, expired lease, and stale-finalizer behaviors observable?
- [ ] Can the team renew and rotate authentication without a volunteer's login?
- [ ] Does the service account reach only the approved calendar without
  domain-wide delegation?
- [ ] Can a target/principal config change cause zero remote request against every
  existing immutable mapping?
- [ ] Do stale/partial activation generations and a smoke-to-ordinary change fail
  before secret/network, with cohort changes possible only gate-off after drain?
- [ ] Is every Google field necessary and free of prohibited personal, payment,
  and secret data?
- [ ] Can an authorized operator diagnose and repair one item without exposing a
  public interface or raw sensitive data?
- [ ] Are both secret classes and all Google network access absent from the web
  runtime, with admin reconcile implemented only as durable worker intent?
- [ ] Can tombstone purge and post-backup restore neither orphan nor duplicate a
  possible remote event when target/auth evidence is unavailable?
- [ ] Have the complete local matrix, dedicated-calendar pilot, rollback
  rehearsal, and independent reviews passed on the release SHA?
- [ ] Have legacy data/remotes/restores been remediated and the public Appointment
  Schedule been retired or proven isolated from Drupal capacity?
- [ ] Are the versioned scheduler, heartbeat dead-man, alert escalation, SLO,
  RPO/RTO, and command-exact runbook approved and tested?
- [ ] Are primary and backup owners available for the production window?

If any answer is no or unknown, keep the deployment live-write gate off and
`enabled = false`, then return to the relevant implementation phase. Do not
compensate with a personal access token, broader scope, domain-wide delegation,
manual event duplication, or unreviewed public diagnostics.
