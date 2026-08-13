#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRUPAL_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${DRUPAL_DIR}/.." && pwd)"
WEB_ROOT="${DRUPAL_DIR}/web"
SYNC_DIR="${DRUPAL_DIR}/config/sync"

DRUSH_MODE=""
DRUSH_LABEL=""
DRUSH_BIN=""
DDEV_PROJECT_DIR=""
DDEV_CONTAINER_DIR=""
DISCOVERY_LIMITATION=""

KNOWN_CONFIGS=(
  block.block.unisonges_branding_barrio
  block.block.unisonges_main_menu_barrio
  block.block.unisonges_messages_barrio
  unisonges_structure.google_calendar
  webform.webform.cours_particuliers_reservation
)

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

The script prints Git state, a repository sync-file inventory, Drush status,
drush config:status, normalized active-vs-sync lists when the active baseline can
be verified, and known blocker classifications. It never imports, deletes,
writes, or exports Drupal config, never edits config/sync, and never writes SQL.
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
This diagnostic only reads Git, repository files, Drupal status, active config,
and sync config. It does not run full or partial config import. It does not
delete, export, or write active config. It does not edit config/sync. It does
not run SQL or write directly to the database.
EOF

case "${DRUPAL_DIR}" in
  /mnt/c/*)
    warn "Refusing to run from /mnt/c. Use the WSL worktree or the VPS checkout."
    exit 1
    ;;
esac

risk_category_for_name() {
  case "$1" in
    block.block.unisonges_branding_barrio|\
    block.block.unisonges_main_menu_barrio|\
    block.block.unisonges_messages_barrio)
      printf '%s' 'theme/block dependency drift'
      ;;
    unisonges_structure.google_calendar)
      printf '%s' 'production-only runtime/secret config'
      ;;
    webform.webform.cours_particuliers_reservation)
      printf '%s' 'potentially safe targeted candidate'
      ;;
    *)
      printf '%s' 'unknown/review required'
      ;;
  esac
}

repository_review_category_for_name() {
  if [[ "$1" == block.block.unisonges_*_barrio ]]; then
    printf '%s' 'theme/block dependency drift'
    return
  fi

  printf '%s (if runtime drift is verified)' "$(risk_category_for_name "$1")"
}

redact_output() {
  awk '
    BEGIN {
      in_private_key = 0
      sensitive_key = "(access[_ -]?token|refresh[_ -]?token|token|secret|password|passwd|credential|api[_ -]?key|access[_ -]?key|client[_ -]?(secret|id|email)|private[_ -]?key|auth(orization)?|(db|database)[ _-]?(url|user(name)?)|calendar[_ -]?id)"
    }
    /-----BEGIN ([A-Z ]*)PRIVATE KEY-----/ {
      print "[redacted private key]"
      in_private_key = 1
      next
    }
    in_private_key && /-----END ([A-Z ]*)PRIVATE KEY-----/ {
      in_private_key = 0
      next
    }
    !in_private_key {
      lowered = tolower($0)
      if (match(lowered, sensitive_key "[^:=]*[:=][[:space:]]*")) {
        print substr($0, 1, RSTART + RLENGTH - 1) "[redacted]"
        next
      }
      print
    }
  ' | sed -E \
    -e 's#([A-Za-z][A-Za-z0-9+.-]*://)[^/@[:space:]]+:[^/@[:space:]]+@#\1[redacted]@#g' \
    -e 's#(Bearer[[:space:]]+)[A-Za-z0-9._~+/=-]+#\1[redacted]#gI' \
    -e 's#(Basic[[:space:]]+)[A-Za-z0-9+/=]+#\1[redacted]#gI' \
    -e 's#(AIza|gh[pousr]_|github_pat_|glpat-|sk[-_]|AKIA|ASIA)[A-Za-z0-9_-]{12,}#[redacted credential]#g' \
    -e 's#eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}#[redacted JWT]#g'
}

print_git_status() {
  section "Git"

  if ! git -C "${REPO_ROOT}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    warn "Could not identify a Git worktree at ${REPO_ROOT}."
    return 0
  fi

  local branch
  local status_output
  branch="$(git -C "${REPO_ROOT}" branch --show-current)"
  if [[ -z "${branch}" ]]; then
    branch="(detached HEAD)"
  fi

  printf 'Repository: %s\n' "${REPO_ROOT}"
  printf 'Branch: %s\n' "${branch}"
  printf 'HEAD: %s\n' "$(git -C "${REPO_ROOT}" rev-parse HEAD)"
  printf 'Short status:\n'

  status_output="$(git -C "${REPO_ROOT}" status --short)"
  if [[ -n "${status_output}" ]]; then
    printf '%s\n' "${status_output}"
  else
    printf 'clean\n'
  fi
}

print_repository_sync_inventory() {
  section "Repository sync files"

  if [[ ! -d "${SYNC_DIR}" ]]; then
    warn "Repository sync directory is missing: ${SYNC_DIR}"
    cat <<'EOF'
LIMITATION: No repository sync-file inventory or file-level blocker inspection
can be produced. Active config will not be treated as a complete comparison.
EOF
    return 1
  fi

  if [[ ! -r "${SYNC_DIR}" ]]; then
    warn "Repository sync directory is not readable: ${SYNC_DIR}"
    return 1
  fi

  local sync_file_output
  if ! sync_file_output="$(
    find "${SYNC_DIR}" -maxdepth 1 -type f -name '*.yml' -printf '%f\n' |
      LC_ALL=C sort
  )"; then
    warn "Could not enumerate repository sync YAML files safely."
    return 1
  fi

  local -a sync_names=()
  local filename
  if [[ -n "${sync_file_output}" ]]; then
    mapfile -t sync_names <<<"${sync_file_output}"
    for filename in "${!sync_names[@]}"; do
      sync_names["${filename}"]="${sync_names["${filename}"]%.yml}"
    done
  fi

  printf 'Directory: %s\n' "${SYNC_DIR}"
  printf 'Default-collection YAML count: %s\n' "${#sync_names[@]}"
  printf 'Normalized repository names (file-only; not a drift classification):\n'
  if ((${#sync_names[@]} == 0)); then
    printf '  (none)\n'
  else
    printf '  - %s\n' "${sync_names[@]}"
  fi

  printf '\nKnown blocker repository inspection:\n'
  local name
  local blocker_file
  for name in "${KNOWN_CONFIGS[@]}"; do
    blocker_file="${SYNC_DIR}/${name}.yml"
    printf -- '- %s\n' "${name}"
    printf '  review category: %s\n' "$(repository_review_category_for_name "${name}")"
    if [[ -f "${blocker_file}" ]]; then
      printf '  sync file: present\n'
      if [[ ! -r "${blocker_file}" ]]; then
        warn "Known blocker file is not readable: ${blocker_file}"
        printf '  file details: unavailable (not readable)\n'
        return 1
      fi
      if [[ "${name}" == block.block.unisonges_*_barrio ]]; then
        if grep -Eq '^theme:[[:space:]]*null[[:space:]]*$' "${blocker_file}"; then
          printf '  top-level theme: null (review required)\n'
        fi
        if awk '
          $0 == "  theme:" {
            getline
            if ($0 ~ /^    - null[[:space:]]*$/) {
              found = 1
            }
          }
          END { exit(found ? 0 : 1) }
        ' "${blocker_file}"; then
          printf '  theme dependency: null (review required)\n'
        fi
      fi
    else
      printf '  sync file: absent\n'
    fi
  done
}

host_drupal_root_is_safe() {
  [[ -f "${WEB_ROOT}/index.php" ]] &&
    [[ -f "${WEB_ROOT}/core/lib/Drupal.php" ]] &&
    [[ -f "${DRUPAL_DIR}/vendor/autoload.php" ]]
}

set_ddev_project() {
  if [[ -d "${DRUPAL_DIR}/.ddev" ]]; then
    DDEV_PROJECT_DIR="${DRUPAL_DIR}"
    DDEV_CONTAINER_DIR="/var/www/html"
    return 0
  fi

  if [[ -d "${REPO_ROOT}/.ddev" ]]; then
    DDEV_PROJECT_DIR="${REPO_ROOT}"
    DDEV_CONTAINER_DIR="/var/www/html/${DRUPAL_DIR#"${REPO_ROOT}/"}"
    return 0
  fi

  return 1
}

run_ddev() {
  (
    cd "${DDEV_PROJECT_DIR}"
    ddev exec --dir "${DDEV_CONTAINER_DIR}" --raw -- "$@"
  )
}

discover_drush() {
  if set_ddev_project && command -v ddev >/dev/null 2>&1; then
    local host_script_hash
    local container_script_hash

    if ! (cd "${DDEV_PROJECT_DIR}" && ddev describe >/dev/null 2>&1); then
      DISCOVERY_LIMITATION="A worktree .ddev directory and the ddev command were found, but the DDEV project is not available. It was not started automatically."
      return 1
    fi

    if ! run_ddev test -x ./vendor/bin/drush >/dev/null 2>&1 ||
      ! run_ddev test -f ./web/index.php >/dev/null 2>&1 ||
      ! run_ddev test -f ./web/core/lib/Drupal.php >/dev/null 2>&1; then
      DISCOVERY_LIMITATION="DDEV is configured, but project Drush or the Drupal web root could not be verified inside the running container."
      return 1
    fi

    host_script_hash="$(sha256sum "${SCRIPT_DIR}/diagnose-config-drift.sh" | awk '{print $1}')"
    container_script_hash="$(
      run_ddev sha256sum ./scripts/diagnose-config-drift.sh 2>/dev/null |
        awk 'length($1) == 64 { print $1; exit }'
    )"
    if [[ -z "${container_script_hash}" || "${container_script_hash}" != "${host_script_hash}" ]]; then
      DISCOVERY_LIMITATION="DDEV responded, but its mounted Drupal project does not match this worktree; Drush was not run."
      return 1
    fi

    DRUSH_MODE="ddev"
    DRUSH_LABEL="ddev exec --dir ${DDEV_CONTAINER_DIR} --raw -- ./vendor/bin/drush -r web"
    return 0
  fi

  if [[ -x ./vendor/bin/drush ]]; then
    if ! host_drupal_root_is_safe; then
      DISCOVERY_LIMITATION="./vendor/bin/drush exists, but the expected Drupal root at ./web could not be verified; Drush was not run."
      return 1
    fi
    DRUSH_MODE="vendor"
    DRUSH_LABEL="./vendor/bin/drush -r web"
    DRUSH_BIN="./vendor/bin/drush"
    return 0
  fi

  local path_drush
  path_drush="$(type -P drush || true)"
  if [[ -n "${path_drush}" ]]; then
    if ! host_drupal_root_is_safe; then
      DISCOVERY_LIMITATION="Drush is available at ${path_drush}, but the expected Drupal root at ./web could not be verified; fallback Drush was not run."
      return 1
    fi
    DRUSH_MODE="path"
    DRUSH_LABEL="${path_drush} -r web"
    DRUSH_BIN="${path_drush}"
    return 0
  fi

  if set_ddev_project && ! command -v ddev >/dev/null 2>&1; then
    DISCOVERY_LIMITATION="A worktree .ddev directory exists, but the ddev command is unavailable and no safely rooted host Drush was found."
  else
    DISCOVERY_LIMITATION="No project Drush was found for a safely identified Drupal root."
  fi
  return 1
}

run_drush() {
  if [[ "${DRUSH_MODE}" == "ddev" ]]; then
    run_ddev ./vendor/bin/drush -r web "$@"
  else
    "${DRUSH_BIN}" -r web "$@"
  fi
}

print_drush_status() {
  section "Drupal / Drush status"
  printf 'Drush command: %s\n\n' "${DRUSH_LABEL}"

  local status_output
  if status_output="$(run_drush status 2>&1)"; then
    printf '%s\n' "${status_output}" | redact_output
    return 0
  fi

  warn "drush status failed. Redacted output follows."
  printf '%s\n' "${status_output}" | redact_output
  cat <<'EOF'

LIMITATION: Drupal did not bootstrap cleanly enough to print full status. This
can happen when the local DB is missing, empty, or not the intended site.
EOF
  return 1
}

print_config_status() {
  section "drush config:status"

  local config_status_output
  if config_status_output="$(run_drush config:status 2>&1)"; then
    printf '%s\n' "${config_status_output}" | redact_output
    return 0
  fi

  warn "drush config:status failed. Redacted output follows."
  printf '%s\n' "${config_status_output}" | redact_output
  cat <<'EOF'

LIMITATION: Could not read Drupal config status. Do not infer that config is
clean; review the bootstrap/database limitation and rerun this diagnostic.
EOF
  return 1
}

print_config_inventory() {
  section "Active vs repository sync inventory"

  local php
  php="$(cat <<'PHP'
$active = \Drupal::service('config.storage');
$repository_sync_directory = dirname(DRUPAL_ROOT) . '/config/sync';

if (!is_dir($repository_sync_directory)) {
  echo 'Only in DB: unavailable' . PHP_EOL;
  echo 'Only in sync directory: unavailable' . PHP_EOL;
  echo 'Different: unavailable' . PHP_EOL;
  echo 'LIMITATION: Repository config/sync is not available beside the verified Drupal root.' . PHP_EOL;
  echo '__UNISONGES_INVENTORY_AVAILABLE__=0' . PHP_EOL;
  return;
}

$sync = new \Drupal\Core\Config\FileStorage($repository_sync_directory);
$active_names = $active->listAll();
$sync_names = $sync->listAll();
sort($active_names, SORT_STRING);
sort($sync_names, SORT_STRING);

$active_lookup = array_fill_keys($active_names, TRUE);
$sync_lookup = array_fill_keys($sync_names, TRUE);
$baseline_issues = [];
$sentinels = ['core.extension', 'system.site', 'system.theme'];
foreach ($sentinels as $sentinel) {
  if (!isset($active_lookup[$sentinel])) {
    $baseline_issues[] = 'active config is missing sentinel ' . $sentinel;
  }
  if (!isset($sync_lookup[$sentinel])) {
    $baseline_issues[] = 'repository sync is missing sentinel ' . $sentinel;
  }
}

$active_site = $active->read('system.site');
$sync_site = $sync->read('system.site');
$active_uuid = is_array($active_site) ? ($active_site['uuid'] ?? '') : '';
$sync_uuid = is_array($sync_site) ? ($sync_site['uuid'] ?? '') : '';
if (!is_string($active_uuid) || $active_uuid === '' || !is_string($sync_uuid) || $sync_uuid === '') {
  $baseline_issues[] = 'system.site UUID could not be verified in both stores';
}
elseif (!hash_equals($sync_uuid, $active_uuid)) {
  $baseline_issues[] = 'active and repository system.site UUIDs do not match';
}

$minimum_plausible_active_count = max(25, (int) floor(count($sync_names) * 0.75));
if (count($active_names) < $minimum_plausible_active_count) {
  $baseline_issues[] = 'active config count is too small to treat this as a complete site inventory';
}

echo 'Active config count: ' . count($active_names) . PHP_EOL;
echo 'Repository sync config count: ' . count($sync_names) . PHP_EOL;

if ($baseline_issues) {
  echo 'Inventory baseline plausibility: failed' . PHP_EOL;
  foreach ($baseline_issues as $issue) {
    echo '  - ' . $issue . PHP_EOL;
  }
  echo PHP_EOL;
  echo 'Only in DB: unavailable (active baseline not verified)' . PHP_EOL;
  echo 'Only in sync directory: unavailable (active baseline not verified)' . PHP_EOL;
  echo 'Different: unavailable (active baseline not verified)' . PHP_EOL;
  echo PHP_EOL;
  echo 'Known blocker runtime inspection: unavailable (see repository inspection above)' . PHP_EOL;
  echo 'Risk classification: unavailable; all unverified runtime drift is unknown/review required.' . PHP_EOL;
  echo 'LIMITATION: No full config inventory is claimed from this active database.' . PHP_EOL;
  echo '__UNISONGES_INVENTORY_AVAILABLE__=0' . PHP_EOL;
  return;
}

function unisonges_drift_is_list_array(array $value): bool {
  if ($value === []) {
    return TRUE;
  }
  return array_keys($value) === range(0, count($value) - 1);
}

function unisonges_drift_normalize_value($value) {
  if (!is_array($value)) {
    return $value;
  }
  foreach ($value as $key => $child) {
    $value[$key] = unisonges_drift_normalize_value($child);
  }
  if (!unisonges_drift_is_list_array($value)) {
    ksort($value, SORT_STRING);
  }
  return $value;
}

$only_db = [];
$only_sync = [];
$different = [];
$same = [];
foreach ($active_names as $name) {
  if (!isset($sync_lookup[$name])) {
    $only_db[] = $name;
    continue;
  }
  $active_data = unisonges_drift_normalize_value($active->read($name));
  $sync_data = unisonges_drift_normalize_value($sync->read($name));
  if ($active_data === $sync_data) {
    $same[] = $name;
  }
  else {
    $different[] = $name;
  }
}
foreach ($sync_names as $name) {
  if (!isset($active_lookup[$name])) {
    $only_sync[] = $name;
  }
}
sort($only_db, SORT_STRING);
sort($only_sync, SORT_STRING);
sort($different, SORT_STRING);

function unisonges_drift_print_name_list(string $label, array $names): void {
  echo $label . ' (' . count($names) . ')' . PHP_EOL;
  if (!$names) {
    echo '  (none)' . PHP_EOL;
    return;
  }
  foreach ($names as $name) {
    echo '  - ' . $name . PHP_EOL;
  }
}

function unisonges_drift_state(
  string $name,
  array $active_lookup,
  array $sync_lookup,
  array $only_db,
  array $only_sync,
  array $different
): string {
  if (in_array($name, $only_db, TRUE)) {
    return 'Only in DB';
  }
  if (in_array($name, $only_sync, TRUE)) {
    return 'Only in sync directory';
  }
  if (in_array($name, $different, TRUE)) {
    return 'Different';
  }
  if (isset($active_lookup[$name]) && isset($sync_lookup[$name])) {
    return 'Same';
  }
  return 'Missing from both stores';
}

function unisonges_drift_risk(string $name): string {
  if (in_array($name, [
    'block.block.unisonges_branding_barrio',
    'block.block.unisonges_main_menu_barrio',
    'block.block.unisonges_messages_barrio',
  ], TRUE)) {
    return 'theme/block dependency drift';
  }
  if ($name === 'unisonges_structure.google_calendar') {
    return 'production-only runtime/secret config';
  }
  if ($name === 'webform.webform.cours_particuliers_reservation') {
    return 'potentially safe targeted candidate';
  }
  return 'unknown/review required';
}

function unisonges_drift_sensitive_key(string $path): bool {
  return (bool) preg_match(
    '/(^|[._-])(access[_-]?token|refresh[_-]?token|token|secret|password|passwd|credential|api[_-]?key|access[_-]?key|client[_-]?(secret|id|email)|private[_-]?key|auth(orization)?|(db|database)[_-]?(url|user(name)?)|calendar[_-]?id)([._-]|$)/i',
    $path
  );
}

function unisonges_drift_sensitive_string(string $value): bool {
  return (bool) (
    preg_match('/^(Bearer|Basic)\s+\S+$/i', $value) ||
    preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $value) ||
    preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://[^/@\s]+:[^/@\s]+@#', $value) ||
    preg_match('/^eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}$/', $value) ||
    preg_match('/^(AIza|gh[pousr]_|github_pat_|glpat-|sk[-_]|AKIA|ASIA)[A-Za-z0-9_-]{12,}$/', $value) ||
    (
      strlen($value) >= 24 &&
      !preg_match('/\s/', $value) &&
      preg_match('/[A-Za-z]/', $value) &&
      preg_match('/[0-9]/', $value) &&
      preg_match('/^[A-Za-z0-9+\/_=.-]+$/', $value)
    )
  );
}

function unisonges_drift_redact($value, string $path = '') {
  if (is_array($value)) {
    $redacted = [];
    foreach ($value as $key => $child) {
      $child_path = $path === '' ? (string) $key : $path . '.' . $key;
      $redacted[$key] = unisonges_drift_redact($child, $child_path);
    }
    return $redacted;
  }
  if (unisonges_drift_sensitive_key($path)) {
    return ($value === NULL || $value === '') ? $value : '[redacted]';
  }
  if (is_string($value) && unisonges_drift_sensitive_string($value)) {
    return '[redacted]';
  }
  return $value;
}

function unisonges_drift_scalar_to_string($value, string $path = ''): string {
  $value = unisonges_drift_redact($value, $path);
  if (is_bool($value)) {
    return $value ? 'true' : 'false';
  }
  if ($value === NULL) {
    return 'null';
  }
  if (is_scalar($value)) {
    return (string) $value;
  }
  $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
  return $encoded === FALSE ? '[unprintable]' : $encoded;
}

function unisonges_drift_value_at(array $data, array $path, bool &$found) {
  $value = $data;
  foreach ($path as $part) {
    if (!is_array($value) || !array_key_exists($part, $value)) {
      $found = FALSE;
      return NULL;
    }
    $value = $value[$part];
  }
  $found = TRUE;
  return $value;
}

function unisonges_drift_print_field(array $data, string $label, array $path): void {
  $found = FALSE;
  $value = unisonges_drift_value_at($data, $path, $found);
  if (!$found) {
    return;
  }
  echo '    ' . $label . ': ' . unisonges_drift_scalar_to_string($value, implode('.', $path)) . PHP_EOL;
}

function unisonges_drift_print_list_field(array $data, string $label, array $path): void {
  $found = FALSE;
  $value = unisonges_drift_value_at($data, $path, $found);
  if (!$found || !is_array($value)) {
    return;
  }
  $items = [];
  foreach ($value as $item) {
    $items[] = unisonges_drift_scalar_to_string($item, implode('.', $path));
  }
  sort($items, SORT_STRING);
  echo '    ' . $label . ': ' . ($items ? implode(', ', $items) : '(none)') . PHP_EOL;
}

function unisonges_drift_summarize(string $name, string $store_label, $data): void {
  echo '  ' . $store_label . ': ';
  if (!is_array($data)) {
    echo 'missing' . PHP_EOL;
    return;
  }
  echo 'present' . PHP_EOL;

  if (str_starts_with($name, 'block.block.')) {
    unisonges_drift_print_field($data, 'id', ['id']);
    unisonges_drift_print_field($data, 'status', ['status']);
    unisonges_drift_print_field($data, 'theme', ['theme']);
    unisonges_drift_print_field($data, 'region', ['region']);
    unisonges_drift_print_field($data, 'plugin', ['plugin']);
    unisonges_drift_print_list_field($data, 'theme dependencies', ['dependencies', 'theme']);
    unisonges_drift_print_list_field($data, 'module dependencies', ['dependencies', 'module']);
    unisonges_drift_print_list_field($data, 'config dependencies', ['dependencies', 'config']);
    return;
  }

  if ($name === 'unisonges_structure.google_calendar') {
    unisonges_drift_print_field($data, 'enabled', ['enabled']);
    unisonges_drift_print_field($data, 'dry_run', ['dry_run']);
    unisonges_drift_print_field($data, 'timezone', ['timezone']);
    unisonges_drift_print_field($data, 'batch_size', ['batch_size']);
    foreach (['calendar_id', 'token_provider', 'access_token_env_var'] as $sensitive_field) {
      $found = FALSE;
      unisonges_drift_value_at($data, [$sensitive_field], $found);
      if ($found) {
        echo '    ' . $sensitive_field . ': [redacted]' . PHP_EOL;
      }
    }
    echo '    other fields: omitted' . PHP_EOL;
    return;
  }

  if ($name === 'webform.webform.cours_particuliers_reservation') {
    unisonges_drift_print_field($data, 'id', ['id']);
    unisonges_drift_print_field($data, 'title', ['title']);
    unisonges_drift_print_field($data, 'status', ['status']);
    $found = FALSE;
    $handlers = unisonges_drift_value_at($data, ['handlers'], $found);
    if ($found && is_array($handlers)) {
      $handler_ids = [];
      foreach (array_keys($handlers) as $handler_id) {
        $handler_ids[] = unisonges_drift_scalar_to_string($handler_id, 'handlers.id');
      }
      sort($handler_ids, SORT_STRING);
      echo '    handlers: ' . ($handler_ids ? implode(', ', $handler_ids) : '(none)') . PHP_EOL;
    }
  }
}

echo 'Inventory baseline plausibility: checks passed; completeness depends on this runtime clone' . PHP_EOL;
echo 'Same count: ' . count($same) . PHP_EOL;
echo PHP_EOL;
unisonges_drift_print_name_list('Only in DB', $only_db);
echo PHP_EOL;
unisonges_drift_print_name_list('Only in sync directory', $only_sync);
echo PHP_EOL;
unisonges_drift_print_name_list('Different', $different);
echo PHP_EOL;

$known = [
  'block.block.unisonges_branding_barrio',
  'block.block.unisonges_main_menu_barrio',
  'block.block.unisonges_messages_barrio',
  'unisonges_structure.google_calendar',
  'webform.webform.cours_particuliers_reservation',
];
echo 'Known blocker runtime inspection' . PHP_EOL;
foreach ($known as $name) {
  echo '- ' . $name . PHP_EOL;
  echo '  state: ' . unisonges_drift_state($name, $active_lookup, $sync_lookup, $only_db, $only_sync, $different) . PHP_EOL;
  echo '  risk: ' . unisonges_drift_risk($name) . PHP_EOL;
  unisonges_drift_summarize($name, 'active DB', $active->read($name));
  unisonges_drift_summarize($name, 'repository sync', $sync->read($name));
}
echo PHP_EOL;

$all_drift = array_values(array_unique(array_merge($only_db, $only_sync, $different)));
sort($all_drift, SORT_STRING);
$by_risk = [
  'theme/block dependency drift' => [],
  'production-only runtime/secret config' => [],
  'potentially safe targeted candidate' => [],
  'unknown/review required' => [],
];
foreach ($all_drift as $name) {
  $risk = unisonges_drift_risk($name);
  $state = unisonges_drift_state($name, $active_lookup, $sync_lookup, $only_db, $only_sync, $different);
  $by_risk[$risk][] = $name . ' [' . $state . ']';
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
echo '__UNISONGES_INVENTORY_AVAILABLE__=1' . PHP_EOL;
PHP
)"

  local inventory_output
  if inventory_output="$(run_drush php:eval "${php}" 2>&1)"; then
    printf '%s\n' "${inventory_output}" |
      sed '/^__UNISONGES_INVENTORY_AVAILABLE__=/d' |
      redact_output
    if grep -q '^__UNISONGES_INVENTORY_AVAILABLE__=1$' <<<"${inventory_output}"; then
      return 0
    fi
    warn "Active config baseline could not be verified; normalized drift lists were withheld."
    return 1
  fi

  warn "Read-only active-vs-sync inventory failed. Redacted output follows."
  printf '%s\n' "${inventory_output}" | redact_output
  cat <<'EOF'

Only in DB: unavailable
Only in sync directory: unavailable
Different: unavailable

LIMITATION: The database may be missing full active config, or Drupal may not
bootstrap far enough for read-only config storage access. No full config
inventory is claimed. Use the repository file evidence above, then rerun on a
complete local or approved read-only environment.
EOF
  return 1
}

print_runtime_unavailable() {
  section "Drupal / Drush status"
  warn "No safely rooted, usable Drush command was found."
  printf 'LIMITATION: %s\n' "${DISCOVERY_LIMITATION}"

  section "drush config:status"
  printf 'LIMITATION: unavailable because Drush was not run.\n'

  section "Active vs repository sync inventory"
  cat <<'EOF'
Only in DB: unavailable
Only in sync directory: unavailable
Different: unavailable

Known blocker runtime inspection: unavailable; repository evidence is reported
above. Risk classification for active drift is unknown/review required until a
complete active baseline can be read safely.
EOF

  if [[ "${repository_inventory_ok}" == "1" ]]; then
    cat <<'EOF'

LIMITATION: Git and repository sync-file diagnostics are complete, but no active
database inventory is claimed.
EOF
  else
    cat <<'EOF'

LIMITATION: The repository sync-file diagnostic also failed. Only the successful
read-only sections above are valid; no active database inventory is claimed.
EOF
  fi
}

print_recommendation() {
  section "Recommendation"
  cat <<'EOF'
Full config import remains blocked until reviewed blockers are resolved.
Do not run partial config import from this diagnostic. Review the classified
theme/block dependency drift, production-only runtime/secret config,
potentially safe targeted candidate, and unknown/review required drift before
approving any separate remediation runbook.
EOF
}

print_git_status
repository_inventory_ok=0
if print_repository_sync_inventory; then
  repository_inventory_ok=1
fi

if ! discover_drush; then
  print_runtime_unavailable
  print_recommendation
  exit 0
fi

drush_status_ok=0
config_status_ok=0
inventory_ok=0

if print_drush_status; then
  drush_status_ok=1
fi
if print_config_status; then
  config_status_ok=1
fi
if print_config_inventory; then
  inventory_ok=1
fi

if [[ "${repository_inventory_ok}" != "1" ||
  "${drush_status_ok}" != "1" ||
  "${config_status_ok}" != "1" ||
  "${inventory_ok}" != "1" ]]; then
  section "Diagnostic completeness"
  cat <<'EOF'
Partial diagnostic only. Git and each successful read-only section above remain
valid, but failed or withheld sections must not be interpreted as clean.
EOF
fi

print_recommendation
