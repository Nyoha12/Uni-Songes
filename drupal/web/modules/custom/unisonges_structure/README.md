# Unisonges Structure

## Google Calendar booking sync

This module keeps Drupal/webform_booking as the source of truth for course
reservations. The Google Calendar integration processes rows from
`unisonges_structure_booking_gcal_sync` that were queued by reservation insert
and update hooks.

The sync is disabled by default and dry-run is enabled by default. No Google
token, client secret, refresh token, or credential JSON is stored in Git or in
Drupal config.

### Configuration

1. Go to `/admin/config/services/unisonges-google-calendar`.
2. Keep dry-run enabled while testing.
3. Set a non-sensitive Google Calendar ID.
4. Set the timezone, normally `Europe/Paris`.
5. Set the batch size for Drupal cron.
6. Configure the token provider as `Environment access token`.

The default environment variable name is:

```text
UNISONGES_GCAL_ACCESS_TOKEN
```

This variable must contain a short-lived OAuth access token with enough scope to
create, update, and delete events on the configured calendar. The module does
not implement refresh-token rotation and does not store the token value.

### Dry-run test

With sync enabled and dry-run enabled, run Drupal cron:

```bash
drush cron
```

Or process a single pending row directly:

```bash
drush php:eval "\Drupal::service('unisonges_structure.booking_calendar_sync')->processPending(1);"
```

Pending rows are marked as `skipped` with a dry-run message and the
`unisonges_booking_sync` log channel records what would have been sent.

### Real sync

Real Google Calendar requests are sent only when all of these are true:

- sync is enabled,
- dry-run is disabled,
- `calendar_id` is configured,
- the configured environment variable contains an access token.

Supported outgoing actions:

- `pending_create`: create an event, or update it if a Google event ID is
  already stored,
- `pending_update`: update the stored event, or create one if no Google event
  ID exists,
- `pending_cancel`: delete the stored Google event, or skip if no event ID is
  available.

### Validation commands

```bash
drush updb -y
drush cr
drush cron
```

When PHP is available locally:

```bash
php -l web/modules/custom/unisonges_structure/unisonges_structure.module
php -l web/modules/custom/unisonges_structure/unisonges_structure.install
find web/modules/custom/unisonges_structure/src -name "*.php" -print -exec php -l {} \;
```

### Out of scope

- full OAuth authorization flow,
- automatic refresh-token handling,
- credential JSON storage,
- inbound Google busy-slot replay every 5 minutes.

The service exposes a documented stub for future inbound busy-slot sync. That
future work should reconcile external busy slots with Drupal/webform_booking
without making Google Calendar the source of truth.
