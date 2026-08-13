#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DRUPAL_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"
SYNC_DIR="${DRUPAL_DIR}/config/sync"
CONFIG_NAME="views.view.hub_stages_posts"
CONFIG_FILE="${SYNC_DIR}/${CONFIG_NAME}.yml"
DRUSH="${DRUSH:-./vendor/bin/drush}"

if [[ "${DRUSH}" == /* ]]; then
  DRUSH_CMD="${DRUSH}"
else
  DRUSH_CMD="${DRUPAL_DIR}/${DRUSH}"
fi

MODE="dry-run"
REQUESTED_MODE=""
ALLOW_VPS="0"

log() {
  printf '[apply-stage-hub-view-2026] %s\n' "$*"
}

warn() {
  printf '[apply-stage-hub-view-2026] WARNING: %s\n' "$*" >&2
}

section() {
  printf '\n== %s ==\n' "$*"
}

usage() {
  cat <<'EOF'
Usage: ./scripts/apply-stage-hub-view-2026.sh [--dry-run|--apply] [--allow-vps]

Compares the active Stages hub View with the reviewed project YAML. Dry-run is
the default. Active configuration is changed only when --apply is explicit.

Options:
  --dry-run    Compare active and staged configuration without writing. Default.
  --apply      Write exactly views.view.hub_stages_posts when it differs.
  --allow-vps  Permit /var/www execution on reviewed staging only. This option
               does not authorize production execution.
  -h, --help   Show this help.

Safety:
  - The only writable config name is views.view.hub_stages_posts.
  - system.date:timezone.default must already be exactly Europe/Paris. This
    script reads that setting but never changes it.
  - No full or partial config import/export is run. No content is written.
  - /mnt/c and temporary-directory checkouts are refused. /var/www requires
    the explicit --allow-vps staging acknowledgement.

Targeted rollback:
  Deploy the previous reviewed revision of
  config/sync/views.view.hub_stages_posts.yml, run this script once in its
  default dry-run mode, then rerun it with --apply. This rewrites the same
  single active config name; do not use a full or partial config import.
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
    --allow-vps)
      ALLOW_VPS="1"
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
    /|/tmp|/tmp/*|/mnt/c|/mnt/c/*)
      warn "Refusing unsafe Drupal path: ${DRUPAL_DIR}"
      exit 1
      ;;
    /var/www|/var/www/*)
      if [[ "${ALLOW_VPS}" != "1" ]]; then
        warn "Refusing /var/www execution without --allow-vps: ${DRUPAL_DIR}"
        warn "Use --allow-vps only on the reviewed staging checkout, never production."
        exit 1
      fi
      ;;
    *)
      if [[ "${ALLOW_VPS}" == "1" ]]; then
        warn "--allow-vps is only valid for a reviewed staging checkout under /var/www."
        exit 2
      fi
      ;;
  esac

  if [[ ! -f "${DRUPAL_DIR}/composer.json" || ! -f "${DRUPAL_DIR}/web/index.php" ]]; then
    warn "Could not verify the Drupal project at ${DRUPAL_DIR}."
    exit 1
  fi

  if [[ ! -d "${SYNC_DIR}" || -L "${DRUPAL_DIR}/config" || -L "${SYNC_DIR}" ]]; then
    warn "Refusing a missing or symlinked config/sync path: ${SYNC_DIR}"
    exit 1
  fi

  if [[ ! -f "${CONFIG_FILE}" || ! -r "${CONFIG_FILE}" || -L "${CONFIG_FILE}" ]]; then
    warn "Target config must be a readable regular file, not a symlink: ${CONFIG_FILE}"
    exit 1
  fi

  local resolved_config_file
  resolved_config_file="$(realpath -e -- "${CONFIG_FILE}")"
  if [[ "${resolved_config_file}" != "${CONFIG_FILE}" ]]; then
    warn "Resolved target path does not match the exact allowlisted path."
    warn "Expected: ${CONFIG_FILE}"
    warn "Resolved: ${resolved_config_file}"
    exit 1
  fi

  if [[ "$(basename -- "${CONFIG_FILE}")" != "${CONFIG_NAME}.yml" ]]; then
    warn "Exact config-name guard failed for ${CONFIG_FILE}."
    exit 1
  fi
}

require_drush() {
  if [[ ! -x "${DRUSH_CMD}" ]]; then
    warn "Drush is missing or not executable at ${DRUSH_CMD}."
    warn "Install the locked Composer dependencies before running this script."
    exit 1
  fi
}

print_plan() {
  section "Safety plan"
  printf 'Mode: %s\n' "${MODE}"
  printf 'Drupal project: %s\n' "${DRUPAL_DIR}"
  printf 'Staged source: %s\n' "${CONFIG_FILE}"
  printf 'Only writable config: %s\n' "${CONFIG_NAME}"
  cat <<'EOF'
Required active timezone: Europe/Paris
The command compares normalized active and staged values before any write.
It never runs a full or partial config import/export and never writes content entities.
EOF
}

require_safe_path
require_drush
print_plan

cd "${DRUPAL_DIR}"

PHP_SCRIPT="$(mktemp -t unisonges-stage-hub-view-2026.XXXXXX.php)"
cleanup() {
  rm -f -- "${PHP_SCRIPT}"
}
trap cleanup EXIT
chmod 600 "${PHP_SCRIPT}"

cat > "${PHP_SCRIPT}" <<'PHP'
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$mode = getenv('UNISONGES_STAGE_HUB_VIEW_MODE') ?: 'dry-run';
if (!in_array($mode, ['dry-run', 'apply'], TRUE)) {
  throw new RuntimeException('Invalid execution mode; expected dry-run or apply.');
}
$is_apply = $mode === 'apply';

// These constants are intentionally not configurable at runtime. They are the
// second exact-name guard after the shell-level canonical-path checks.
$config_name = 'views.view.hub_stages_posts';
$view_id = 'hub_stages_posts';
$expected_timezone = 'Europe/Paris';
$project_root = dirname(\Drupal::root());
$expected_source_path = $project_root . '/config/sync/' . $config_name . '.yml';
$source_path = realpath($expected_source_path);

$section = static function (string $title): void {
  echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
};

$fail = static function (string $message): void {
  throw new RuntimeException($message);
};

$format = static function ($value): string {
  if ($value === NULL) {
    return '(missing)';
  }
  $encoded = json_encode(
    $value,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
  );
  if ($encoded === FALSE) {
    return '(unprintable value)';
  }
  if (strlen($encoded) > 240) {
    return substr($encoded, 0, 237) . '...';
  }
  return $encoded;
};

$canonicalize = static function ($value) use (&$canonicalize) {
  if (!is_array($value)) {
    return $value;
  }

  foreach ($value as $key => $item) {
    $value[$key] = $canonicalize($item);
  }
  if (!array_is_list($value)) {
    ksort($value, SORT_STRING);
  }
  return $value;
};

$differences = [];
$compare = static function ($active, $staged, string $path = '') use (&$compare, &$differences): void {
  if (is_array($active) && is_array($staged)) {
    $keys = array_unique(array_merge(array_keys($active), array_keys($staged)));
    usort($keys, static fn($left, $right): int => strnatcmp((string) $left, (string) $right));
    foreach ($keys as $key) {
      $child_path = $path === '' ? (string) $key : $path . '.' . $key;
      $active_exists = array_key_exists($key, $active);
      $staged_exists = array_key_exists($key, $staged);
      if (!$active_exists || !$staged_exists) {
        $differences[] = [
          'path' => $child_path,
          'active' => $active_exists ? $active[$key] : NULL,
          'staged' => $staged_exists ? $staged[$key] : NULL,
        ];
        continue;
      }
      $compare($active[$key], $staged[$key], $child_path);
    }
    return;
  }

  if ($active !== $staged) {
    $differences[] = [
      'path' => $path === '' ? '(root)' : $path,
      'active' => $active,
      'staged' => $staged,
    ];
  }
};

$section('Drupal and target guards');
echo 'Drupal bootstrap: OK (' . \Drupal::VERSION . ')' . PHP_EOL;
echo 'Mode: ' . $mode . PHP_EOL;

if ($source_path === FALSE || $source_path !== $expected_source_path) {
  $fail(
    'Exact source-path guard failed. Expected ' . $expected_source_path .
    '; resolved ' . ($source_path === FALSE ? '(missing)' : $source_path) . '.'
  );
}
if (basename($source_path) !== $config_name . '.yml') {
  $fail('Exact config filename guard failed.');
}

try {
  $staged = Yaml::parseFile($source_path);
}
catch (Throwable $throwable) {
  $fail('Staged YAML could not be parsed: ' . $throwable->getMessage());
}
if (!is_array($staged)) {
  $fail('Staged YAML must contain a top-level mapping.');
}
if (($staged['id'] ?? NULL) !== $view_id) {
  $fail('Staged View id must be exactly ' . $view_id . '.');
}
if (($staged['module'] ?? NULL) !== 'views') {
  $fail('Staged config module must be exactly views.');
}
if ($config_name !== 'views.view.' . $staged['id']) {
  $fail('Staged View id does not map to the exact allowlisted config name.');
}

$entity_type_manager = \Drupal::entityTypeManager();
if (!$entity_type_manager->hasDefinition('view')) {
  $fail('The Views config entity type is unavailable.');
}
$view_definition = $entity_type_manager->getDefinition('view');
if ($view_definition->getConfigPrefix() !== 'views.view') {
  $fail('Unexpected Views config prefix; refusing the targeted write.');
}

$config_storage = \Drupal::service('config.storage');
$system_date = $config_storage->read('system.date');
if (!is_array($system_date)) {
  $fail('Active system.date config is missing or unreadable; no config was changed.');
}
$active_timezone = $system_date['timezone']['default'] ?? NULL;
if ($active_timezone !== $expected_timezone) {
  $fail(
    'Active system.date:timezone.default must be exactly ' . $expected_timezone .
    '; found ' . $format($active_timezone) . '. This script will not change it.'
  );
}
echo 'Active timezone: OK (' . $expected_timezone . ')' . PHP_EOL;

$active = $config_storage->read($config_name);
if (!is_array($active)) {
  $fail('Active ' . $config_name . ' is missing or unreadable; refusing to create it silently.');
}
if (($active['id'] ?? NULL) !== $view_id) {
  $fail('Active View id is not exactly ' . $view_id . '; refusing to overwrite it.');
}
echo 'Exact config name: OK (' . $config_name . ')' . PHP_EOL;

$module_handler = \Drupal::moduleHandler();
foreach (($staged['dependencies']['module'] ?? []) as $module_name) {
  if (!is_string($module_name) || $module_name === '' || !$module_handler->moduleExists($module_name)) {
    $fail('Required staged dependency module is unavailable: ' . $format($module_name));
  }
  echo 'Dependency module: OK (' . $module_name . ')' . PHP_EOL;
}
foreach (($staged['dependencies']['config'] ?? []) as $dependency_name) {
  if (!is_string($dependency_name) || $dependency_name === '' || !$config_storage->exists($dependency_name)) {
    $fail('Required staged dependency config is unavailable: ' . $format($dependency_name));
  }
  echo 'Dependency config: OK (' . $dependency_name . ')' . PHP_EOL;
}

$canonical_active = $canonicalize($active);
$canonical_staged = $canonicalize($staged);
$active_json = json_encode($canonical_active, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$staged_json = json_encode($canonical_staged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($active_json === FALSE || $staged_json === FALSE) {
  $fail('Could not normalize active and staged config for comparison.');
}

$section('Active versus staged View');
echo 'Active SHA-256: ' . hash('sha256', $active_json) . PHP_EOL;
echo 'Staged SHA-256: ' . hash('sha256', $staged_json) . PHP_EOL;

if ($canonical_active === $canonical_staged) {
  echo 'MATCH Active and staged config already match.' . PHP_EOL;
  echo $is_apply
    ? 'NOOP No config write was necessary.' . PHP_EOL
    : 'DRY_RUN No config was changed.' . PHP_EOL;
  exit(0);
}

$compare($canonical_active, $canonical_staged);
echo 'DIFF Changed value count: ' . count($differences) . PHP_EOL;
foreach ($differences as $difference) {
  echo '- ' . $difference['path'] . PHP_EOL;
  echo '  active: ' . $format($difference['active']) . PHP_EOL;
  echo '  staged: ' . $format($difference['staged']) . PHP_EOL;
}

if (!$is_apply) {
  echo PHP_EOL . 'WOULD_WRITE ' . $config_name . PHP_EOL;
  echo 'DRY_RUN No config was changed. Rerun with --apply after reviewing this comparison.' . PHP_EOL;
  exit(0);
}

$section('Targeted apply');
echo 'WRITE ' . $config_name . PHP_EOL;
\Drupal::configFactory()
  ->getEditable($config_name)
  ->setData($staged)
  ->save(TRUE);

// Reset only this process's Views entity cache before verifying the persisted
// value. Config save events perform the normal runtime cache invalidations.
$entity_type_manager->getStorage('view')->resetCache([$view_id]);
$written = $config_storage->read($config_name);
if (!is_array($written) || $canonicalize($written) !== $canonical_staged) {
  $fail('Post-write verification failed for ' . $config_name . '.');
}

echo 'OK Active config now matches the staged View.' . PHP_EOL;
echo 'Written config count: 1' . PHP_EOL;
echo 'Written config name: ' . $config_name . PHP_EOL;
PHP

UNISONGES_STAGE_HUB_VIEW_MODE="${MODE}" "${DRUSH_CMD}" php:script "${PHP_SCRIPT}"

section "Result"
if [[ "${MODE}" == "apply" ]]; then
  log "Targeted apply completed; only ${CONFIG_NAME} was eligible for writing."
  cat <<'EOF'
Rollback: deploy the previous reviewed revision of
config/sync/views.view.hub_stages_posts.yml, review the default dry-run, then
rerun this script with --apply. Do not run a full or partial config import.
EOF
else
  log "Dry-run completed. No active config was changed."
fi
