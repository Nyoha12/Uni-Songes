#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRUPAL_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
DRUSH="./vendor/bin/drush"

log() {
  printf '[create-local-fixtures] %s\n' "$*"
}

warn() {
  printf '[create-local-fixtures] WARNING: %s\n' "$*" >&2
}

section() {
  printf '\n== %s ==\n' "$*"
}

run_drush_php_eval() {
  local php="$1"
  local escaped_php="${php//\$/\\$}"

  ddev exec "${DRUSH}" php:eval "${escaped_php}"
}

usage() {
  cat <<'EOF'
Usage: ./scripts/create-local-fixtures.sh [--dry-run|--apply]

Creates or updates local-only DDEV fixture users.

Options:
  --dry-run  Run read-only guards and print planned fixture user changes. Default.
  --apply    Create or update only local.fixture.* users through Drupal APIs.
  -h, --help Show this help.
EOF
}

mode="dry-run"
requested_mode=""

for arg in "$@"; do
  case "${arg}" in
    --dry-run)
      if [[ "${requested_mode}" == "apply" ]]; then
        warn "Use either --dry-run or --apply, not both."
        usage
        exit 2
      fi
      requested_mode="dry-run"
      mode="dry-run"
      ;;
    --apply)
      if [[ "${requested_mode}" == "dry-run" ]]; then
        warn "Use either --dry-run or --apply, not both."
        usage
        exit 2
      fi
      requested_mode="apply"
      mode="apply"
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      warn "Unknown argument: ${arg}"
      usage
      exit 2
      ;;
  esac
done

print_fixture_plan() {
  section "Planned local fixture users"
  cat <<'EOF'
Only these local users may be created or updated:
- local.fixture.no_credit: mail=local.fixture.no_credit@example.invalid, field_seances_restantes=0, field_essai_utilise=0, field_pack_expire_le=NULL
- local.fixture.with_credit: mail=local.fixture.with_credit@example.invalid, field_seances_restantes=3, field_essai_utilise=0, field_pack_expire_le=NULL
- local.fixture.checkout: mail=local.fixture.checkout@example.invalid, field_seances_restantes=0, field_essai_utilise=0, field_pack_expire_le=NULL
- local.fixture.trial_used: mail=local.fixture.trial_used@example.invalid, field_seances_restantes=0, field_essai_utilise=1, field_pack_expire_le=NULL
- local.fixture.pack_active: mail=local.fixture.pack_active@example.invalid, field_seances_restantes=4, field_essai_utilise=0, field_pack_expire_le=today + 6 months

Newly created users use the local-only password: local-fixture-only
Existing fixture user passwords are not changed.
EOF
}

require_safe_path() {
  case "${DRUPAL_DIR}" in
    /mnt/c/*|/var/www/*|/srv/*)
      warn "Refusing to run from a path that looks non-local or production-like: ${DRUPAL_DIR}"
      exit 1
      ;;
  esac
}

require_ddev() {
  section "DDEV local context"

  if ! command -v ddev >/dev/null 2>&1; then
    warn "ddev is not available in PATH."
    exit 1
  fi

  if ! ddev describe >/dev/null 2>&1; then
    warn "This directory is not an available DDEV project, or DDEV is not running."
    exit 1
  fi

  if ! ddev exec bash -lc 'test -f composer.json && test -f web/core/lib/Drupal.php' >/dev/null 2>&1; then
    warn "Could not verify a Drupal codebase inside the DDEV app container."
    exit 1
  fi

  log "DDEV project context verified for ${DRUPAL_DIR}."
}

require_drush() {
  section "Drush"

  if ddev exec test -x "${DRUSH}" >/dev/null 2>&1; then
    log "vendor/bin/drush is present."
  else
    warn "vendor/bin/drush is missing. Run Composer install inside DDEV before fixture guards."
    exit 1
  fi
}

require_database() {
  section "Database"

  local key_value_table
  if ! key_value_table="$(ddev exec bash -lc 'mariadb -h db -u db -pdb db -NBe "SHOW TABLES LIKE '\''key_value'\'';"')"; then
    warn "Could not inspect Drupal database tables."
    exit 1
  fi

  key_value_table="${key_value_table//$'\r'/}"
  if [[ "${key_value_table}" != "key_value" ]]; then
    warn "Drupal table key_value was not found. The local database is probably empty."
    cat <<'EOF'
Fixture creation requires an installed local Drupal database.
No data was changed. Do not use production data; see docs/dev/ddev-testing.md.
EOF
    exit 1
  fi

  log "Drupal table key_value exists."
}

require_bootstrap() {
  section "Drupal bootstrap"

  ddev exec "${DRUSH}" php:eval 'echo "Drupal bootstrap OK: " . \Drupal::VERSION . PHP_EOL;'
}

require_active_readiness() {
  section "Active fixture guards"

  local php
  php="$(cat <<'PHP'
$failed = FALSE;

$check = function (bool $ok, string $message) use (&$failed): void {
  echo ($ok ? 'OK' : 'FAIL') . ' ' . $message . PHP_EOL;
  $failed = $failed || !$ok;
};

foreach ([
  'user',
  'commerce',
  'commerce_order',
  'commerce_payment',
  'commerce_product',
  'webform',
  'webform_booking',
  'unisonges_structure',
] as $module) {
  $check(\Drupal::moduleHandler()->moduleExists($module), 'module ' . $module . ' is enabled');
}

try {
  $field_storage = \Drupal::entityTypeManager()->getStorage('field_config');
  foreach ([
    'user.user.field_seances_restantes',
    'user.user.field_essai_utilise',
    'user.user.field_pack_expire_le',
  ] as $field_id) {
    $check((bool) $field_storage->load($field_id), 'user field ' . $field_id . ' exists');
  }
}
catch (\Throwable $throwable) {
  $check(FALSE, 'user credit field storage is readable');
}

try {
  $webform = \Drupal::entityTypeManager()->getStorage('webform')->load('cours_particuliers_reservation');
  $check((bool) $webform, 'webform cours_particuliers_reservation exists');

  if ($webform && method_exists($webform, 'getElementsDecoded')) {
    $elements = $webform->getElementsDecoded();
    $reservation_exists = isset($elements['reservation']);
    $reservation_type = $elements['reservation']['#type'] ?? NULL;

    $check($reservation_exists, 'webform element reservation exists');
    $check($reservation_type === 'webform_booking', 'webform element reservation type is webform_booking');
  }
  else {
    $check(FALSE, 'webform element reservation exists');
    $check(FALSE, 'webform element reservation type is webform_booking');
  }
}
catch (\Throwable $throwable) {
  $check(FALSE, 'webform cours_particuliers_reservation exists');
  $check(FALSE, 'webform element reservation exists');
  $check(FALSE, 'webform element reservation type is webform_booking');
}

$calendar_config = \Drupal::configFactory()->get('unisonges_structure.google_calendar');
if ($calendar_config->isNew()) {
  echo 'OK Google Calendar active config is absent; no real sync can be enabled by this script.' . PHP_EOL;
}
else {
  $enabled = (bool) $calendar_config->get('enabled');
  $dry_run = (bool) $calendar_config->get('dry_run');
  $check(!$enabled, 'Google Calendar enabled is false');
  $check($dry_run, 'Google Calendar dry_run is true');
}

if ($failed) {
  throw new \RuntimeException('Active fixture guards failed.');
}
PHP
)"

  if ! run_drush_php_eval "${php}"; then
    warn "Active fixture guards failed."
    cat <<'EOF'
For a local standard-profile DDEV database, prepare the missing local-only
module and config prerequisites with:

  ./scripts/bootstrap-local-fixture-site.sh --dry-run

Review the dry-run output before running --apply. No fixture data was changed.
EOF
    exit 1
  fi
}

apply_or_plan_fixture_users() {
  local apply_flag="0"
  local result_label="Dry-run result"
  if [[ "${mode}" == "apply" ]]; then
    apply_flag="1"
    result_label="Apply result"
  fi

  section "Fixture users"

  local php
  php="$(cat <<'PHP'
$apply = getenv('LOCAL_FIXTURE_APPLY') === '1';
$default_password = 'local-fixture-only';
$pack_expiry = (new \DateTimeImmutable('today', new \DateTimeZone(date_default_timezone_get())))
  ->modify('+6 months')
  ->format('Y-m-d');

$fixtures = [
  [
    'name' => 'local.fixture.no_credit',
    'mail' => 'local.fixture.no_credit@example.invalid',
    'field_seances_restantes' => 0,
    'field_essai_utilise' => 0,
    'field_pack_expire_le' => NULL,
  ],
  [
    'name' => 'local.fixture.with_credit',
    'mail' => 'local.fixture.with_credit@example.invalid',
    'field_seances_restantes' => 3,
    'field_essai_utilise' => 0,
    'field_pack_expire_le' => NULL,
  ],
  [
    'name' => 'local.fixture.checkout',
    'mail' => 'local.fixture.checkout@example.invalid',
    'field_seances_restantes' => 0,
    'field_essai_utilise' => 0,
    'field_pack_expire_le' => NULL,
  ],
  [
    'name' => 'local.fixture.trial_used',
    'mail' => 'local.fixture.trial_used@example.invalid',
    'field_seances_restantes' => 0,
    'field_essai_utilise' => 1,
    'field_pack_expire_le' => NULL,
  ],
  [
    'name' => 'local.fixture.pack_active',
    'mail' => 'local.fixture.pack_active@example.invalid',
    'field_seances_restantes' => 4,
    'field_essai_utilise' => 0,
    'field_pack_expire_le' => $pack_expiry,
  ],
];

$user_storage = \Drupal::entityTypeManager()->getStorage('user');

$format_value = static function ($value): string {
  return $value === NULL || $value === '' ? 'NULL' : (string) $value;
};

$load_user_ids = static function (string $field, string $value) use ($user_storage): array {
  $ids = $user_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition($field, $value)
    ->execute();
  $ids = array_map('intval', array_values($ids));
  sort($ids);
  return $ids;
};

$field_value = static function (\Drupal\user\UserInterface $user, string $field_name) {
  if (!$user->hasField($field_name) || $user->get($field_name)->isEmpty()) {
    return NULL;
  }

  return $user->get($field_name)->value;
};

$set_fixture_values = static function (\Drupal\user\UserInterface $user, array $fixture): void {
  foreach (['field_seances_restantes', 'field_essai_utilise', 'field_pack_expire_le'] as $field_name) {
    if (!$user->hasField($field_name)) {
      throw new \RuntimeException(sprintf('User %s does not have field %s.', $user->getAccountName(), $field_name));
    }
  }

  $user->setEmail($fixture['mail']);
  $user->activate();
  $user->set('roles', []);
  $user->set('field_seances_restantes', $fixture['field_seances_restantes']);
  $user->set('field_essai_utilise', $fixture['field_essai_utilise']);
  $user->set('field_pack_expire_le', $fixture['field_pack_expire_le']);
};

$created = 0;
$updated = 0;
$unchanged = 0;

foreach ($fixtures as $fixture) {
  $name = $fixture['name'];
  $mail = $fixture['mail'];

  if (!str_starts_with($name, 'local.fixture.')) {
    throw new \RuntimeException(sprintf('Refusing non-local fixture username %s.', $name));
  }

  $uids_by_name = $load_user_ids('name', $name);
  $uids_by_mail = $load_user_ids('mail', $mail);

  if (in_array(1, $uids_by_name, TRUE) || in_array(1, $uids_by_mail, TRUE)) {
    throw new \RuntimeException(sprintf('Refusing to touch uid=1 while processing %s.', $name));
  }

  if (count($uids_by_name) > 1 || count($uids_by_mail) > 1) {
    throw new \RuntimeException(sprintf('Unexpected duplicate user lookup while processing %s.', $name));
  }

  $user = NULL;
  if ($uids_by_name) {
    $uid = reset($uids_by_name);
    $user = $user_storage->load($uid);
    if (!$user) {
      throw new \RuntimeException(sprintf('Could not load existing fixture user %s.', $name));
    }
    if (!str_starts_with($user->getAccountName(), 'local.fixture.')) {
      throw new \RuntimeException(sprintf('Refusing to update non-local user uid=%d.', (int) $user->id()));
    }
    if ($uids_by_mail && !in_array((int) $user->id(), $uids_by_mail, TRUE)) {
      throw new \RuntimeException(sprintf('Desired mail %s already belongs to another user.', $mail));
    }
  }
  elseif ($uids_by_mail) {
    $mail_uid = reset($uids_by_mail);
    $mail_user = $user_storage->load($mail_uid);
    $mail_name = $mail_user ? $mail_user->getAccountName() : 'unknown';
    throw new \RuntimeException(sprintf('Desired mail %s already belongs to uid=%d (%s); refusing to rename users.', $mail, $mail_uid, $mail_name));
  }

  if (!$user) {
    $created++;
    printf(
      "%s create %s mail=%s field_seances_restantes=%d field_essai_utilise=%d field_pack_expire_le=%s password=%s roles=authenticated-only\n",
      $apply ? 'Will' : 'Would',
      $name,
      $mail,
      $fixture['field_seances_restantes'],
      $fixture['field_essai_utilise'],
      $format_value($fixture['field_pack_expire_le']),
      $default_password
    );

    if ($apply) {
      $user = \Drupal\user\Entity\User::create([
        'name' => $name,
        'mail' => $mail,
        'pass' => $default_password,
        'status' => 1,
      ]);
      $set_fixture_values($user, $fixture);
      $user->save();
      printf("Created %s uid=%d\n", $name, (int) $user->id());
    }
    continue;
  }

  $changes = [];
  if ($user->getEmail() !== $mail) {
    $changes[] = sprintf('mail %s -> %s', $format_value($user->getEmail()), $mail);
  }
  if (!$user->isActive()) {
    $changes[] = 'status blocked -> active';
  }

  $assigned_roles = array_values($user->getRoles(TRUE));
  sort($assigned_roles);
  if ($assigned_roles !== []) {
    $changes[] = sprintf('roles %s -> authenticated-only', implode(',', $assigned_roles));
  }

  $current_remaining = (int) ($field_value($user, 'field_seances_restantes') ?? 0);
  if ($current_remaining !== $fixture['field_seances_restantes']) {
    $changes[] = sprintf('field_seances_restantes %d -> %d', $current_remaining, $fixture['field_seances_restantes']);
  }

  $current_trial = (int) ($field_value($user, 'field_essai_utilise') ?? 0);
  if ($current_trial !== $fixture['field_essai_utilise']) {
    $changes[] = sprintf('field_essai_utilise %d -> %d', $current_trial, $fixture['field_essai_utilise']);
  }

  $current_expiry = $field_value($user, 'field_pack_expire_le');
  $desired_expiry = $fixture['field_pack_expire_le'];
  if ($current_expiry !== $desired_expiry) {
    $changes[] = sprintf('field_pack_expire_le %s -> %s', $format_value($current_expiry), $format_value($desired_expiry));
  }

  if ($changes === []) {
    $unchanged++;
    printf("Up-to-date %s uid=%d\n", $name, (int) $user->id());
    continue;
  }

  $updated++;
  printf(
    "%s update %s uid=%d: %s; password unchanged\n",
    $apply ? 'Will' : 'Would',
    $name,
    (int) $user->id(),
    implode('; ', $changes)
  );

  if ($apply) {
    $set_fixture_values($user, $fixture);
    $user->save();
    printf("Updated %s uid=%d\n", $name, (int) $user->id());
  }
}

printf(
  "%s complete. created=%d updated=%d unchanged=%d\n",
  $apply ? 'Apply' : 'Dry-run',
  $created,
  $updated,
  $unchanged
);

if (!$apply) {
  echo "No data was changed.\n";
}
else {
  echo "No products, orders, webform submissions, Google Calendar data, config/sync, Composer files, or .ddev files were changed by this script.\n";
}
PHP
)"

  local escaped_php="${php//\$/\\$}"
  ddev exec env LOCAL_FIXTURE_APPLY="${apply_flag}" "${DRUSH}" php:eval "${escaped_php}"

  section "${result_label}"
  if [[ "${mode}" == "dry-run" ]]; then
    log "Guards passed. No data was changed."
  else
    log "Guards passed. Fixture users were created or updated as needed."
  fi
}

cd "${DRUPAL_DIR}"

log "Mode: ${mode}"
require_safe_path

if [[ "${mode}" == "dry-run" ]]; then
  print_fixture_plan
fi

require_ddev
require_drush
require_database
require_bootstrap
require_active_readiness
apply_or_plan_fixture_users
