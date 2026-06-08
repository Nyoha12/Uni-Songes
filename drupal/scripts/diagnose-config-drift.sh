#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRUPAL_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${DRUPAL_DIR}/.." && pwd)"

DRUSH_MODE=""
DRUSH_LABEL=""
DRUSH_CMD=()

log() {
  printf '[diagnose-config-drift] %s\n' "$*"
}

warn() {
  printf '[diagnose-config-drift] WARNING: %s\n' "$*" >&2
}

section() {
  printf '\n== %s ==\n' "$*"
}

usage() {
  cat <<'EOF'
Usage: ./scripts/diagnose-config-drift.sh

Read-only Drupal config drift diagnostic.

The script prints Git state, Drush status, drush config:status, a read-only
active-vs-sync inventory, and known blocker classifications. It never runs full
or partial config imports, never deletes active config, and never edits
config/sync.
EOF
}

for arg in "$@"; do
  case "${arg}" in
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

cd "${DRUPAL_DIR}"

section "Read-only guard"
cat <<'EOF'
This diagnostic only reads Git, Drupal status, active config, and sync config.
It does not run drush config:import.
It does not run partial config import.
It does not delete or write active config.
It does not edit config/sync.
EOF

case "${DRUPAL_DIR}" in
  /mnt/c/*)
    warn "Refusing to run from /mnt/c. Use the WSL worktree or the VPS checkout."
    exit 1
    ;;
esac

discover_drush() {
  if command -v ddev >/dev/null 2>&1 && ddev describe >/dev/null 2>&1; then
    if ddev exec test -x ./vendor/bin/drush >/dev/null 2>&1; then
      DRUSH_MODE="ddev"
      DRUSH_LABEL="ddev exec ./vendor/bin/drush"
      DRUSH_CMD=(ddev exec ./vendor/bin/drush)
      return 0
    fi
  fi

  if [[ -x ./vendor/bin/drush ]]; then
    DRUSH_MODE="local-vendor"
    DRUSH_LABEL="./vendor/bin/drush"
    DRUSH_CMD=(./vendor/bin/drush)
    return 0
  fi

  if command -v drush >/dev/null 2>&1; then
    DRUSH_MODE="path"
    DRUSH_LABEL="$(command -v drush)"
    DRUSH_CMD=(drush)
    return 0
  fi

  return 1
}

run_drush() {
  "${DRUSH_CMD[@]}" "$@"
}

run_drush_php_eval() {
  local php="$1"

  if [[ "${DRUSH_MODE}" == "ddev" ]]; then
    local escaped_php="${php//\$/\\$}"
    run_drush php:eval "${escaped_php}"
  else
    run_drush php:eval "${php}"
  fi
}

print_git_status() {
  section "Git"

  if ! git -C "${REPO_ROOT}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    warn "Could not identify a Git worktree at ${REPO_ROOT}."
    return 0
  fi

  printf 'Repository: %s\n' "${REPO_ROOT}"
  printf 'Branch: %s\n' "$(git -C "${REPO_ROOT}" branch --show-current)"
  printf 'HEAD: %s\n' "$(git -C "${REPO_ROOT}" rev-parse HEAD)"
  printf 'Status:\n'

  local status_output
  status_output="$(git -C "${REPO_ROOT}" status --short)"
  if [[ -n "${status_output}" ]]; then
    printf '%s\n' "${status_output}"
  else
    printf 'clean\n'
  fi
}

print_drush_status() {
  section "Drupal / Drush status"
  printf 'Drush command: %s\n\n' "${DRUSH_LABEL}"

  local status_output
  if status_output="$(run_drush status 2>&1)"; then
    printf '%s\n' "${status_output}"
    return 0
  fi

  warn "drush status failed. Output follows."
  printf '%s\n' "${status_output}"
  cat <<'EOF'

LIMITATION: Drupal did not bootstrap cleanly enough to print full status.
This can happen when the local DB is missing, empty, or does not contain the
full active config set.
EOF
  return 1
}

print_config_status() {
  section "drush config:status"

  local config_status_output
  if config_status_output="$(run_drush config:status 2>&1)"; then
    printf '%s\n' "${config_status_output}"
    return 0
  fi

  warn "drush config:status failed. Output follows."
  printf '%s\n' "${config_status_output}"
  cat <<'EOF'

LIMITATION: Could not read Drupal config status. Do not infer that config is
clean; fix the bootstrap/database limitation first and rerun this diagnostic.
EOF
  return 1
}

print_config_inventory() {
  section "Active vs sync inventory"

  local php
  php="$(cat <<'PHP'
$active = \Drupal::service('config.storage');
$sync = \Drupal::service('config.storage.sync');

$active_names = $active->listAll();
$sync_names = $sync->listAll();
sort($active_names, SORT_STRING);
sort($sync_names, SORT_STRING);

$active_lookup = array_fill_keys($active_names, TRUE);
$sync_lookup = array_fill_keys($sync_names, TRUE);

$only_db = [];
$only_sync = [];
$different = [];
$same = [];

foreach ($active_names as $name) {
  if (!isset($sync_lookup[$name])) {
    $only_db[] = $name;
  }
}

foreach ($sync_names as $name) {
  if (!isset($active_lookup[$name])) {
    $only_sync[] = $name;
  }
}

function is_list_array(array $value): bool {
  if ($value === []) {
    return TRUE;
  }
  return array_keys($value) === range(0, count($value) - 1);
}

function normalize_config_value($value) {
  if (!is_array($value)) {
    return $value;
  }

  foreach ($value as $key => $child) {
    $value[$key] = normalize_config_value($child);
  }

  if (!is_list_array($value)) {
    ksort($value, SORT_STRING);
  }

  return $value;
}

foreach ($active_names as $name) {
  if (!isset($sync_lookup[$name])) {
    continue;
  }

  $active_data = normalize_config_value($active->read($name));
  $sync_data = normalize_config_value($sync->read($name));
  if ($active_data === $sync_data) {
    $same[] = $name;
  }
  else {
    $different[] = $name;
  }
}

sort($only_db, SORT_STRING);
sort($only_sync, SORT_STRING);
sort($different, SORT_STRING);

function print_name_list(string $label, array $names): void {
  echo $label . ' (' . count($names) . ')' . PHP_EOL;
  if (!$names) {
    echo '  (none)' . PHP_EOL;
    return;
  }
  foreach ($names as $name) {
    echo '  - ' . $name . PHP_EOL;
  }
}

function config_state(string $name, array $only_db, array $only_sync, array $different): string {
  if (in_array($name, $only_db, TRUE)) {
    return 'Only in DB';
  }
  if (in_array($name, $only_sync, TRUE)) {
    return 'Only in sync directory';
  }
  if (in_array($name, $different, TRUE)) {
    return 'Different';
  }
  return 'Same or missing from both stores';
}

function risk_category(string $name): string {
  if (preg_match('/^block\.block\..*_barrio$/', $name)) {
    return 'theme dependency / block drift';
  }
  if ($name === 'unisonges_structure.google_calendar') {
    return 'prod-only secret/config';
  }
  if ($name === 'webform.webform.cours_particuliers_reservation') {
    return 'safe targeted config candidate';
  }
  return 'unknown';
}

function scalar_to_string($value): string {
  if (is_bool($value)) {
    return $value ? 'true' : 'false';
  }
  if ($value === NULL) {
    return 'null';
  }
  if (is_scalar($value)) {
    return (string) $value;
  }
  return json_encode($value, JSON_UNESCAPED_SLASHES);
}

function value_at(array $data, array $path) {
  $value = $data;
  foreach ($path as $part) {
    if (!is_array($value) || !array_key_exists($part, $value)) {
      return NULL;
    }
    $value = $value[$part];
  }
  return $value;
}

function string_list_at(array $data, array $path): array {
  $value = value_at($data, $path);
  if (!is_array($value)) {
    return [];
  }

  $items = [];
  foreach ($value as $item) {
    if (is_scalar($item)) {
      $items[] = (string) $item;
    }
  }
  sort($items, SORT_STRING);
  return $items;
}

function print_field(array $data, string $label, array $path): void {
  $value = value_at($data, $path);
  if ($value === NULL || $value === '') {
    return;
  }
  echo '    ' . $label . ': ' . scalar_to_string($value) . PHP_EOL;
}

function print_list_field(array $data, string $label, array $path): void {
  $items = string_list_at($data, $path);
  if (!$items) {
    return;
  }
  echo '    ' . $label . ': ' . implode(', ', $items) . PHP_EOL;
}

function redact_value($value, string $path = '') {
  if (is_array($value)) {
    $redacted = [];
    foreach ($value as $key => $child) {
      $child_path = $path === '' ? (string) $key : $path . '.' . $key;
      $redacted[$key] = redact_value($child, $child_path);
    }
    return $redacted;
  }

  if (preg_match('/(secret|token|password|credential|private|client_secret)/i', $path)) {
    if ($value === NULL || $value === '') {
      return $value;
    }
    return '[redacted]';
  }

  return $value;
}

function print_key_values(array $data): void {
  $redacted = redact_value($data);
  ksort($redacted, SORT_STRING);
  foreach ($redacted as $key => $value) {
    if (is_array($value)) {
      echo '    ' . $key . ': ' . json_encode($value, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
    else {
      echo '    ' . $key . ': ' . scalar_to_string($value) . PHP_EOL;
    }
  }
}

function summarize_config(string $name, string $store_label, $data): void {
  echo '  ' . $store_label . ': ';
  if (!is_array($data)) {
    echo 'missing' . PHP_EOL;
    return;
  }
  echo 'present' . PHP_EOL;

  if (preg_match('/^block\.block\./', $name)) {
    print_field($data, 'id', ['id']);
    print_field($data, 'status', ['status']);
    print_field($data, 'theme', ['theme']);
    print_field($data, 'region', ['region']);
    print_field($data, 'plugin', ['plugin']);
    print_list_field($data, 'theme dependencies', ['dependencies', 'theme']);
    print_list_field($data, 'module dependencies', ['dependencies', 'module']);
    print_list_field($data, 'config dependencies', ['dependencies', 'config']);
    return;
  }

  if ($name === 'unisonges_structure.google_calendar') {
    print_key_values($data);
    return;
  }

  if ($name === 'webform.webform.cours_particuliers_reservation') {
    print_field($data, 'id', ['id']);
    print_field($data, 'title', ['title']);
    print_field($data, 'status', ['status']);
    $handlers = value_at($data, ['handlers']);
    if (is_array($handlers)) {
      $handler_ids = array_keys($handlers);
      sort($handler_ids, SORT_STRING);
      echo '    handlers: ' . ($handler_ids ? implode(', ', $handler_ids) : '(none)') . PHP_EOL;
    }
    return;
  }

  print_key_values($data);
}

echo 'Active config count: ' . count($active_names) . PHP_EOL;
echo 'Sync config count: ' . count($sync_names) . PHP_EOL;
echo 'Same count: ' . count($same) . PHP_EOL;
echo PHP_EOL;

print_name_list('Only in DB', $only_db);
echo PHP_EOL;
print_name_list('Only in sync directory', $only_sync);
echo PHP_EOL;
print_name_list('Different', $different);
echo PHP_EOL;

$known = [
  'block.block.unisonges_branding_barrio',
  'block.block.unisonges_main_menu_barrio',
  'block.block.unisonges_messages_barrio',
  'unisonges_structure.google_calendar',
  'webform.webform.cours_particuliers_reservation',
];

echo 'Known blocker inspection' . PHP_EOL;
foreach ($known as $name) {
  $state = config_state($name, $only_db, $only_sync, $different);
  echo '- ' . $name . PHP_EOL;
  echo '  state: ' . $state . PHP_EOL;
  echo '  risk: ' . risk_category($name) . PHP_EOL;
  summarize_config($name, 'active DB', $active->read($name));
  summarize_config($name, 'sync directory', $sync->read($name));
}
echo PHP_EOL;

$all_drift = array_values(array_unique(array_merge($only_db, $only_sync, $different)));
sort($all_drift, SORT_STRING);
$by_risk = [
  'theme dependency / block drift' => [],
  'prod-only secret/config' => [],
  'safe targeted config candidate' => [],
  'unknown' => [],
];
foreach ($all_drift as $name) {
  $by_risk[risk_category($name)][] = $name . ' [' . config_state($name, $only_db, $only_sync, $different) . ']';
}

echo 'Risk classification for current drift' . PHP_EOL;
foreach ($by_risk as $risk => $items) {
  echo '- ' . $risk . ' (' . count($items) . ')' . PHP_EOL;
  if (!$items) {
    echo '  (none)' . PHP_EOL;
    continue;
  }
  foreach ($items as $item) {
    echo '  - ' . $item . PHP_EOL;
  }
}
PHP
)"

  local inventory_output
  if inventory_output="$(run_drush_php_eval "${php}" 2>&1)"; then
    printf '%s\n' "${inventory_output}"
    return 0
  fi

  warn "Read-only active-vs-sync inventory failed. Output follows."
  printf '%s\n' "${inventory_output}"
  cat <<'EOF'

LIMITATION: The local database may be missing full active config, or Drupal may
not bootstrap far enough for config storage reads. Use the raw drush
config:status output above if available, then rerun on a complete local clone,
staging clone, or the VPS in read-only mode.
EOF
  return 1
}

print_recommendation() {
  section "Recommendation"
  cat <<'EOF'
Do not run full drush config:import while blockers remain.
Do not run partial config import from this diagnostic.
Resolve or explicitly accept the classified theme/block drift, prod-only
config, safe targeted config candidates, and unknown drift before any import
runbook is approved.
EOF
}

print_git_status

if ! discover_drush; then
  section "Drupal / Drush status"
  warn "No usable Drush command found."
  cat <<'EOF'
LIMITATION: Install project dependencies or run inside a valid DDEV/VPS Drupal
checkout, then rerun this read-only diagnostic.
EOF
  print_recommendation
  exit 0
fi

drush_status_ok=0
config_status_ok=0
inventory_ok=0

if print_drush_status; then
  drush_status_ok=1
fi

if [[ "${drush_status_ok}" == "1" ]] && print_config_status; then
  config_status_ok=1
fi

if [[ "${config_status_ok}" == "1" ]] && print_config_inventory; then
  inventory_ok=1
fi

if [[ "${inventory_ok}" != "1" ]]; then
  section "Diagnostic completeness"
  cat <<'EOF'
Partial diagnostic only. Git and any successful Drush output above are valid,
but the active-vs-sync classification is incomplete.
EOF
fi

print_recommendation
