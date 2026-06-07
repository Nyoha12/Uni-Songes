#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRUPAL_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
DRUSH="./vendor/bin/drush"

MODE="dry-run"
REQUESTED_MODE=""
CONTAINER_SCRIPT="/tmp/bootstrap-local-fixture-site.php"

REQUIRED_MODULES=(
  datetime
  commerce
  commerce_order
  commerce_payment
  commerce_product
  webform
  webform_ui
  webform_booking
  unisonges_structure
)

OPTIONAL_MODULES=(
  webform_booking_calendar
)

MINIMAL_CONFIGS=(
  field.storage.user.field_seances_restantes
  field.field.user.user.field_seances_restantes
  field.storage.user.field_essai_utilise
  field.field.user.user.field_essai_utilise
  field.storage.user.field_pack_expire_le
  field.field.user.user.field_pack_expire_le
  webform.webform.cours_particuliers_reservation
)

log() {
  printf '[bootstrap-local-fixture-site] %s\n' "$*"
}

warn() {
  printf '[bootstrap-local-fixture-site] WARNING: %s\n' "$*" >&2
}

section() {
  printf '\n== %s ==\n' "$*"
}

usage() {
  cat <<'EOF'
Usage: ./scripts/bootstrap-local-fixture-site.sh [--dry-run|--apply]

Prepares a local-only DDEV Drupal site for fixture readiness guards without
running a full drush config:import.

Options:
  --dry-run  Print the local-safe module/config bootstrap plan. Default.
  --apply    Enable required modules and import only the allowlisted config.
  -h, --help Show this help.
EOF
}

for arg in "$@"; do
  case "${arg}" in
    --dry-run)
      if [[ "${REQUESTED_MODE}" == "apply" ]]; then
        warn "Use either --dry-run or --apply, not both."
        usage
        exit 2
      fi
      REQUESTED_MODE="dry-run"
      MODE="dry-run"
      ;;
    --apply)
      if [[ "${REQUESTED_MODE}" == "dry-run" ]]; then
        warn "Use either --dry-run or --apply, not both."
        usage
        exit 2
      fi
      REQUESTED_MODE="apply"
      MODE="apply"
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
    warn "vendor/bin/drush is missing. Run Composer install inside DDEV before fixture bootstrap."
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
Fixture bootstrap requires an installed local Drupal database.
No data was changed. Do not use production data; see docs/dev/ddev-testing.md.
EOF
    exit 1
  fi

  log "Drupal table key_value exists."
}

require_config_sources() {
  section "Minimal config sources"

  local missing=0
  local config_name
  for config_name in "${MINIMAL_CONFIGS[@]}"; do
    if [[ -f "${DRUPAL_DIR}/config/sync/${config_name}.yml" ]]; then
      log "Found config/sync/${config_name}.yml"
    else
      warn "Missing config/sync/${config_name}.yml"
      missing=1
    fi
  done

  if [[ "${missing}" -ne 0 ]]; then
    warn "Cannot continue without all allowlisted config sources."
    exit 1
  fi
}

print_plan() {
  section "Dry-run plan"

  printf 'Required modules to enable if missing:\n'
  printf -- '- %s\n' "${REQUIRED_MODULES[@]}"

  printf '\nOptional modules not enabled by default:\n'
  printf -- '- %s (not required for fixture guards)\n' "${OPTIONAL_MODULES[@]}"

  printf '\nAllowlisted config to create if absent and verify if present:\n'
  printf -- '- %s\n' "${MINIMAL_CONFIGS[@]}"

  cat <<'EOF'

No full drush config:import will run.
No unrelated config will be imported.
No active config will be deleted.
No fixture data, credentials, or real Google Calendar sync will be created.
EOF
}

write_container_script() {
  ddev exec bash -lc "cat > ${CONTAINER_SCRIPT}" <<'PHP'
<?php

use Symfony\Component\Yaml\Yaml;

$mode = getenv('BOOTSTRAP_LOCAL_FIXTURE_MODE') ?: 'dry-run';
$is_apply = $mode === 'apply';
$failed = FALSE;
$imported = [];
$unchanged = [];
$blocked = [];

$project_root = \Drupal::root() . '/..';
$config_dir = $project_root . '/config/sync';

$minimal_configs = [
  'field.storage.user.field_seances_restantes' => 'field_storage_config',
  'field.field.user.user.field_seances_restantes' => 'field_config',
  'field.storage.user.field_essai_utilise' => 'field_storage_config',
  'field.field.user.user.field_essai_utilise' => 'field_config',
  'field.storage.user.field_pack_expire_le' => 'field_storage_config',
  'field.field.user.user.field_pack_expire_le' => 'field_config',
  'webform.webform.cours_particuliers_reservation' => 'webform',
];

$check = function (bool $ok, string $message) use (&$failed): void {
  echo ($ok ? 'OK' : 'FAIL') . ' ' . $message . PHP_EOL;
  $failed = $failed || !$ok;
};

$normalize = function (array $data) use (&$normalize): array {
  unset($data['uuid'], $data['_core']);
  foreach ($data as $key => $value) {
    if (is_array($value)) {
      $data[$key] = $normalize($value);
    }
  }
  ksort($data);
  return $data;
};

$load_source = function (string $config_name) use ($config_dir): array {
  $path = $config_dir . '/' . $config_name . '.yml';
  if (!is_file($path)) {
    throw new RuntimeException("Missing source config: {$path}");
  }

  $data = Yaml::parseFile($path);
  if (!is_array($data)) {
    throw new RuntimeException("Source config is not a YAML mapping: {$path}");
  }

  return $data;
};

$validate_dependencies = function (string $config_name, array $data) use ($check, $is_apply, $minimal_configs): void {
  $module_handler = \Drupal::moduleHandler();
  foreach (($data['dependencies']['module'] ?? []) as $module) {
    if ($module_handler->moduleExists($module)) {
      echo "OK {$config_name} dependency module {$module} is enabled" . PHP_EOL;
    }
    elseif ($is_apply) {
      $check(FALSE, "{$config_name} dependency module {$module} is enabled");
    }
    else {
      echo "WOULD_REQUIRE {$config_name} dependency module {$module}" . PHP_EOL;
    }
  }
  foreach (($data['dependencies']['config'] ?? []) as $dependency_name) {
    $exists = !\Drupal::config($dependency_name)->isNew();
    $planned = array_key_exists($dependency_name, $minimal_configs);
    if ($exists) {
      echo "OK {$config_name} dependency config {$dependency_name} exists" . PHP_EOL;
    }
    elseif ($planned) {
      echo ($is_apply ? 'OK' : 'WOULD_CREATE') . " {$config_name} dependency config {$dependency_name}" . PHP_EOL;
    }
    else {
      $check(FALSE, "{$config_name} dependency config {$dependency_name} exists");
    }
  }
};

section('Drupal bootstrap');
echo 'OK Drupal bootstrap OK: ' . \Drupal::VERSION . PHP_EOL;

section('Google Calendar safety');
$calendar_config = \Drupal::configFactory()->get('unisonges_structure.google_calendar');
if ($calendar_config->isNew()) {
  echo 'OK Google Calendar active config is absent; no real sync can be enabled by this script.' . PHP_EOL;
}
else {
  $check(!(bool) $calendar_config->get('enabled'), 'Google Calendar enabled is false');
  $check((bool) $calendar_config->get('dry_run'), 'Google Calendar dry_run is true');
  $calendar_id = trim((string) ($calendar_config->get('calendar_id') ?? ''));
  $check($calendar_id === '' || str_contains($calendar_id, 'local') || str_ends_with($calendar_id, '.invalid'), 'Google Calendar calendar_id is empty or local');
}

section('Minimal config dependencies');
foreach ($minimal_configs as $config_name => $entity_type_id) {
  try {
    $source = $load_source($config_name);
    $validate_dependencies($config_name, $source);
  }
  catch (Throwable $throwable) {
    $check(FALSE, "{$config_name} source/dependency validation failed: " . $throwable->getMessage());
  }
}

if ($failed) {
  echo PHP_EOL . 'Blocked before config import. No config was changed by this PHP step.' . PHP_EOL;
  exit(1);
}

section($is_apply ? 'Minimal config apply' : 'Minimal config dry-run');
$entity_type_manager = \Drupal::entityTypeManager();
$config_factory = \Drupal::configFactory();

foreach ($minimal_configs as $config_name => $entity_type_id) {
  try {
    $source = $load_source($config_name);
    $entity_id = (string) ($source['id'] ?? '');
    if ($entity_id === '') {
      throw new RuntimeException('Source config has no id.');
    }

    $active_config = $config_factory->get($config_name);
    if (!$entity_type_manager->hasDefinition($entity_type_id)) {
      if (!$active_config->isNew()) {
        $active_data = $active_config->getRawData();
        if ($normalize($active_data) === $normalize($source)) {
          echo "OK {$config_name} already matches project config; {$entity_type_id} will be available after enabling its module." . PHP_EOL;
          $unchanged[] = $config_name;
          continue;
        }

        echo "BLOCKED {$config_name} already exists locally and differs from project config." . PHP_EOL;
        $blocked[] = $config_name;
        continue;
      }

      if (!$is_apply) {
        echo "WOULD_CREATE {$config_name} after enabling the module that provides {$entity_type_id}" . PHP_EOL;
        continue;
      }

      throw new RuntimeException("Entity type {$entity_type_id} is unavailable after module enable.");
    }

    $storage = $entity_type_manager->getStorage($entity_type_id);
    $existing = $storage->load($entity_id);

    if (!$active_config->isNew() || $existing) {
      $active_data = $active_config->getRawData();
      if ($normalize($active_data) === $normalize($source)) {
        echo "OK {$config_name} already matches project config." . PHP_EOL;
        $unchanged[] = $config_name;
        continue;
      }

      echo "BLOCKED {$config_name} already exists locally and differs from project config." . PHP_EOL;
      $blocked[] = $config_name;
      continue;
    }

    if (!$is_apply) {
      echo "WOULD_CREATE {$config_name}" . PHP_EOL;
      continue;
    }

    $entity = $storage->create($source);
    if (method_exists($entity, 'trustData')) {
      $entity->trustData();
    }
    $entity->save();
    echo "CREATED {$config_name}" . PHP_EOL;
    $imported[] = $config_name;
  }
  catch (Throwable $throwable) {
    echo "FAIL {$config_name}: " . $throwable->getMessage() . PHP_EOL;
    $failed = TRUE;
  }
}

if ($blocked) {
  echo PHP_EOL . 'Blocked because local active config differs from the allowlisted project config:' . PHP_EOL;
  foreach ($blocked as $config_name) {
    echo '- ' . $config_name . PHP_EOL;
  }
  echo 'No active config was deleted or overwritten. Reconcile the local config manually before rerunning.' . PHP_EOL;
  exit(1);
}

if ($failed) {
  echo PHP_EOL . 'Minimal config import failed. No full config import was attempted.' . PHP_EOL;
  exit(1);
}

if ($is_apply) {
  section('Fixture readiness guards');
  foreach ([
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
    $field_storage = $entity_type_manager->getStorage('field_config');
    foreach ([
      'user.user.field_seances_restantes',
      'user.user.field_essai_utilise',
      'user.user.field_pack_expire_le',
    ] as $field_id) {
      $check((bool) $field_storage->load($field_id), 'user field ' . $field_id . ' exists');
    }
  }
  catch (Throwable $throwable) {
    $check(FALSE, 'user credit field storage is readable');
  }

  try {
    $webform = $entity_type_manager->getStorage('webform')->load('cours_particuliers_reservation');
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
  catch (Throwable $throwable) {
    $check(FALSE, 'webform cours_particuliers_reservation exists');
    $check(FALSE, 'webform element reservation exists');
    $check(FALSE, 'webform element reservation type is webform_booking');
  }

  if ($failed) {
    echo PHP_EOL . 'Fixture readiness guards failed after the minimal bootstrap step.' . PHP_EOL;
    exit(1);
  }
}

section('Result');
if ($is_apply) {
  echo 'Imported config count: ' . count($imported) . PHP_EOL;
  echo 'Already matching config count: ' . count($unchanged) . PHP_EOL;
  echo 'Local fixture site bootstrap completed without full config import.' . PHP_EOL;
}
else {
  echo 'Dry-run complete. No data or config was changed.' . PHP_EOL;
}

function section(string $title): void {
  echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
}
PHP
}

cleanup_container_script() {
  ddev exec rm -f "${CONTAINER_SCRIPT}" >/dev/null 2>&1 || true
}

run_php_step() {
  write_container_script
  trap cleanup_container_script EXIT
  ddev exec env BOOTSTRAP_LOCAL_FIXTURE_MODE="${MODE}" "${DRUSH}" php:script "${CONTAINER_SCRIPT}"
}

cd "${DRUPAL_DIR}"

log "Mode: ${MODE}"
require_safe_path
require_ddev
require_drush
require_database
require_config_sources
print_plan

if [[ "${MODE}" == "dry-run" ]]; then
  section "Module dry-run"
  log "No modules will be enabled in dry-run."
else
  section "Enable required modules"
  ddev exec "${DRUSH}" pm:enable -y "${REQUIRED_MODULES[@]}"
fi

run_php_step

if [[ "${MODE}" == "dry-run" ]]; then
  section "Dry-run result"
  log "Dry-run completed. No data or config was changed."
else
  section "Apply result"
  log "Local fixture site bootstrap completed. No full config import was run."
fi
