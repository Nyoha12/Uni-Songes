# Unisonges Structure

## Google Calendar booking sync

Drupal/webform_booking remains the source of truth. This release contains only
the durable state foundation and is under a non-configurable production hold:

- `enabled` is forced to `false` and `dry_run` to `true`;
- Drupal cron does not resolve or invoke the Calendar worker;
- direct worker calls stop before configuration, rows, credentials, or clients;
- the registered client is an inert stub with no config, token, or HTTP
  dependency;
- no Calendar ID is required;
- no token, service-account file, OAuth provider, scheduler, or Google request
  is loaded or activated;
- existing legacy backlog remains stored and unclassified.

The additive schema preserves legacy SID, UUID, event IDs, payload/error fields,
and rows for a later guarded migration. New `state` and `operation` fields stay
NULL on legacy rows, so merely deploying this code cannot claim them. The
reviewed operational vocabulary, monotonic desired revision, set-once event-ID
CAS, lease/CAS storage service, deterministic retry policy, redacted diagnostic
read model, and future migration classifier live in
`src/GoogleCalendar/State` but are not connected to reservation producers or a
scheduler in this phase.

Run only the static PHP harness documented in
`docs/functional/google-calendar-state-foundation-2026.md` for this phase. DDEV,
database updates, connected clients, credentials, and schedulers require the
later validation gates documented there.
