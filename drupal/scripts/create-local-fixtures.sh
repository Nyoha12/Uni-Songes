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

usage() {
  cat <<'EOF'
Usage: ./scripts/create-local-fixtures.sh [--dry-run|--apply]

Creates local-only DDEV fixture data in a later phase.

Options:
  --dry-run  Run read-only guards and print planned fixture records. Default.
  --apply    Run the same guards, then stop. Writes are not implemented yet.
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
  section "Planned local fixture records"
  cat <<'EOF'
Users:
- local.fixture.no_credit
- local.fixture.with_credit
- local.fixture.checkout
- local.fixture.trial_used
- local.fixture.pack_active

Course product SKUs:
- LOCAL-FIXTURE-COURS-ESSAI
- LOCAL-FIXTURE-COURS-DEB-INTER
- LOCAL-FIXTURE-COURS-AVANCE
- LOCAL-FIXTURE-PACK-4-DEB-INTER
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

  ddev exec "${DRUSH}" php:eval '
$failed = FALSE;

$check = function (bool $ok, string $message) use (&$failed): void {
  echo ($ok ? "OK" : "FAIL") . " " . $message . PHP_EOL;
  $failed = $failed || !$ok;
};

foreach ([
  "user",
  "commerce",
  "commerce_order",
  "commerce_payment",
  "commerce_product",
  "webform",
  "webform_booking",
  "unisonges_structure",
] as $module) {
  $check(\Drupal::moduleHandler()->moduleExists($module), "module " . $module . " is enabled");
}

try {
  $field_storage = \Drupal::entityTypeManager()->getStorage("field_config");
  foreach ([
    "user.user.field_seances_restantes",
    "user.user.field_essai_utilise",
    "user.user.field_pack_expire_le",
  ] as $field_id) {
    $check((bool) $field_storage->load($field_id), "user field " . $field_id . " exists");
  }
}
catch (\Throwable $throwable) {
  $check(FALSE, "user credit field storage is readable");
}

try {
  $webform = \Drupal::entityTypeManager()->getStorage("webform")->load("cours_particuliers_reservation");
  $check((bool) $webform, "webform cours_particuliers_reservation exists");

  if ($webform && method_exists($webform, "getElementsDecoded")) {
    $elements = $webform->getElementsDecoded();
    $reservation_exists = isset($elements["reservation"]);
    $reservation_type = $elements["reservation"]["#type"] ?? NULL;

    $check($reservation_exists, "webform element reservation exists");
    $check($reservation_type === "webform_booking", "webform element reservation type is webform_booking");
  }
  else {
    $check(FALSE, "webform element reservation exists");
    $check(FALSE, "webform element reservation type is webform_booking");
  }
}
catch (\Throwable $throwable) {
  $check(FALSE, "webform cours_particuliers_reservation exists");
  $check(FALSE, "webform element reservation exists");
  $check(FALSE, "webform element reservation type is webform_booking");
}

$calendar_config = \Drupal::configFactory()->get("unisonges_structure.google_calendar");
if ($calendar_config->isNew()) {
  echo "OK Google Calendar active config is absent; no real sync can be enabled by this script." . PHP_EOL;
}
else {
  $enabled = (bool) $calendar_config->get("enabled");
  $dry_run = (bool) $calendar_config->get("dry_run");
  $check(!$enabled, "Google Calendar enabled is false");
  $check($dry_run, "Google Calendar dry_run is true");
}

exit($failed ? 1 : 0);
'
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

if [[ "${mode}" == "apply" ]]; then
  section "Apply"
  warn "Writes are not implemented yet for this first safe fixture command."
  log "Guards passed. No data was changed."
  exit 1
fi

section "Dry-run result"
log "Guards passed. No data was changed."
