#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DRUPAL_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"
SYNC_DIR="${DRUPAL_DIR}/config/sync"
VIEW_CONFIG_NAME="views.view.hub_concerts_posts"
BLOCK_CONFIG_NAME="block.block.unisonges_hub_concerts_posts"
VIEW_CONFIG_FILE="${SYNC_DIR}/${VIEW_CONFIG_NAME}.yml"
BLOCK_CONFIG_FILE="${SYNC_DIR}/${BLOCK_CONFIG_NAME}.yml"
DRUSH="${DRUSH:-./vendor/bin/drush}"

if [[ "${DRUSH}" == /* ]]; then
  DRUSH_CMD="${DRUSH}"
else
  DRUSH_CMD="${DRUPAL_DIR}/${DRUSH}"
fi

ACTION="dry-run"
DIRECTION="forward"
REQUESTED_ACTION=""
ALLOW_VPS="0"
VERIFIED_LOCAL_DDEV="0"

log() {
  printf '[apply-concert-hub-upcoming-events-2026] %s\n' "$*"
}

warn() {
  printf '[apply-concert-hub-upcoming-events-2026] WARNING: %s\n' "$*" >&2
}

section() {
  printf '\n== %s ==\n' "$*"
}

usage() {
  cat <<'EOF'
Usage: ./scripts/apply-concert-hub-upcoming-events-2026.sh [--dry-run|--apply] [--rollback] [--allow-vps]

Compares the two active Concert hub configurations with their exact reviewed
before and target states. Dry-run is the default. Active configuration is
changed only when --apply is explicit.

Options:
  --dry-run    Compare active and desired configuration without writing. Default.
  --apply      Write only allowlisted configs whose state differs from desired.
  --rollback   Select the exact reviewed before state as the desired state.
               Without --apply this is a rollback dry-run.
  --allow-vps  Permit /var/www execution on reviewed staging only. This option
               does not authorize production execution.
  -h, --help   Show this help.

Forward sequence:
  ./scripts/apply-concert-hub-upcoming-events-2026.sh --dry-run
  ./scripts/apply-concert-hub-upcoming-events-2026.sh --apply
  ./scripts/apply-concert-hub-upcoming-events-2026.sh --dry-run

Rollback sequence:
  ./scripts/apply-concert-hub-upcoming-events-2026.sh --rollback --dry-run
  ./scripts/apply-concert-hub-upcoming-events-2026.sh --rollback --apply
  ./scripts/apply-concert-hub-upcoming-events-2026.sh --rollback --dry-run

Safety:
  - The exact writable allowlist contains only:
      views.view.hub_concerts_posts
      block.block.unisonges_hub_concerts_posts
  - Every active value must exactly match its reviewed before or target state.
    Any unknown drift aborts before all writes.
  - system.date:timezone.default must already be exactly Europe/Paris. This
    script reads that setting but never changes it.
  - No full or partial config import/export is run. No content is written.
  - /mnt/c and temporary-directory checkouts are refused. /var/www requires
    either a positively identified local DDEV web container or the explicit
    --allow-vps staging acknowledgement.
EOF
}

for arg in "$@"; do
  case "${arg}" in
    --dry-run)
      if [[ "${REQUESTED_ACTION}" == "apply" ]]; then
        warn "Use either --dry-run or --apply, not both."
        usage
        exit 2
      fi
      REQUESTED_ACTION="dry-run"
      ACTION="dry-run"
      ;;
    --apply)
      if [[ "${REQUESTED_ACTION}" == "dry-run" ]]; then
        warn "Use either --dry-run or --apply, not both."
        usage
        exit 2
      fi
      REQUESTED_ACTION="apply"
      ACTION="apply"
      ;;
    --rollback)
      DIRECTION="rollback"
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

is_verified_local_ddev() {
  [[ "${IS_DDEV_PROJECT:-}" == "true" ]] || return 1
  [[ "${DEPLOY_NAME:-}" == "local" ]] || return 1
  [[ "${DDEV_PROJECT_TYPE:-}" == "drupal11" ]] || return 1
  [[ "${DDEV_DOCROOT:-${DOCROOT:-}}" == "web" ]] || return 1
  [[ -f "/mnt/ddev_config/config.yaml" ]] || return 1

  local ddev_approot="${DDEV_APPROOT:-}"
  local ddev_composer_root="${DDEV_COMPOSER_ROOT:-}"
  [[ -n "${ddev_approot}" && -n "${ddev_composer_root}" ]] || return 1

  ddev_approot="$(realpath -e -- "${ddev_approot}")" || return 1
  ddev_composer_root="$(realpath -e -- "${ddev_composer_root}")" || return 1
  [[ "${ddev_approot}" == "${DRUPAL_DIR}" ]] || return 1
  [[ "${ddev_composer_root}" == "${DRUPAL_DIR}" ]] || return 1
}

require_safe_path() {
  case "${DRUPAL_DIR}" in
    /|/tmp|/tmp/*|/mnt/c|/mnt/c/*)
      warn "Refusing unsafe Drupal path: ${DRUPAL_DIR}"
      exit 1
      ;;
    /var/www|/var/www/*)
      if is_verified_local_ddev; then
        if [[ "${ALLOW_VPS}" == "1" ]]; then
          warn "--allow-vps is invalid inside a verified local DDEV container."
          exit 2
        fi
        VERIFIED_LOCAL_DDEV="1"
      elif [[ "${ALLOW_VPS}" != "1" ]]; then
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

  local config_name
  local config_file
  local resolved_config_file
  for config_name in "${VIEW_CONFIG_NAME}" "${BLOCK_CONFIG_NAME}"; do
    config_file="${SYNC_DIR}/${config_name}.yml"
    if [[ ! -f "${config_file}" || ! -r "${config_file}" || -L "${config_file}" ]]; then
      warn "Target config must be a readable regular file, not a symlink: ${config_file}"
      exit 1
    fi

    resolved_config_file="$(realpath -e -- "${config_file}")"
    if [[ "${resolved_config_file}" != "${config_file}" ]]; then
      warn "Resolved target path does not match the exact allowlisted path."
      warn "Expected: ${config_file}"
      warn "Resolved: ${resolved_config_file}"
      exit 1
    fi

    if [[ "$(basename -- "${config_file}")" != "${config_name}.yml" ]]; then
      warn "Exact config-name guard failed for ${config_file}."
      exit 1
    fi
  done
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
  printf 'Action: %s\n' "${ACTION}"
  printf 'Direction: %s\n' "${DIRECTION}"
  printf 'Drupal project: %s\n' "${DRUPAL_DIR}"
  printf 'Staged View: %s\n' "${VIEW_CONFIG_FILE}"
  printf 'Staged block: %s\n' "${BLOCK_CONFIG_FILE}"
  printf 'Writable config count: 2\n'
  printf 'Writable config: %s\n' "${VIEW_CONFIG_NAME}"
  printf 'Writable config: %s\n' "${BLOCK_CONFIG_NAME}"
  if [[ "${VERIFIED_LOCAL_DDEV}" == "1" ]]; then
    printf 'Execution context: verified local DDEV web container\n'
  else
    printf 'Execution context: canonical host or acknowledged staging checkout\n'
  fi
  cat <<'EOF'
Required active timezone: Europe/Paris
Both active configs are classified as exact before, exact target, or unknown
before any write. Unknown drift fails closed. No config import or content write
is performed.
EOF
}

require_safe_path
require_drush
print_plan

cd "${DRUPAL_DIR}"

PHP_SCRIPT="$(mktemp -t unisonges-concert-hub-upcoming-events-2026.XXXXXX.php)"
cleanup() {
  rm -f -- "${PHP_SCRIPT}"
}
trap cleanup EXIT
chmod 600 "${PHP_SCRIPT}"

cat > "${PHP_SCRIPT}" <<'PHP'
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$action = getenv('UNISONGES_CONCERT_HUB_ACTION') ?: 'dry-run';
$direction = getenv('UNISONGES_CONCERT_HUB_DIRECTION') ?: 'forward';
if (!in_array($action, ['dry-run', 'apply'], TRUE)) {
  throw new RuntimeException('Invalid action; expected dry-run or apply.');
}
if (!in_array($direction, ['forward', 'rollback'], TRUE)) {
  throw new RuntimeException('Invalid direction; expected forward or rollback.');
}
$is_apply = $action === 'apply';

// These names and source hashes are intentionally not configurable at runtime.
$view_config_name = 'views.view.hub_concerts_posts';
$block_config_name = 'block.block.unisonges_hub_concerts_posts';
$config_names = [$view_config_name, $block_config_name];
$view_id = 'hub_concerts_posts';
$block_id = 'unisonges_hub_concerts_posts';
$expected_timezone = 'Europe/Paris';
$expected_source_sha256 = [
  $view_config_name => '66acd317b7885941e0ccfa4d0ff00547f0eef84506a1ee155060567ec4368f58',
  $block_config_name => '52cf78f2b1c3aa7e0c6f61d0cc89b25b68cd71a19afbfee6264c34f8f6e039ce',
];
$expected_state_sha256 = [
  $view_config_name => [
    'before' => '204e859d00c69cd76b70372437da3ae3bdfffac3dfab85102b6d9d2a9a71672b',
    'target' => 'ac962dc6cc6859215640251b100903b423a4993823783e0f0961dcd35d3b3e5c',
  ],
  $block_config_name => [
    'before' => '9819379da6b600eedbf794b661635fdea8be021d54db8024703489221691dd8b',
    'target' => '2f994d214933e1393f0993acf8d9e2120309d04ef4fce5425ce17392209b0c09',
  ],
];
$project_root = dirname(\Drupal::root());

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

$expect = static function ($actual, $expected, string $label) use ($fail, $format): void {
  if ($actual !== $expected) {
    $fail(
      $label . ' is not the reviewed value. Expected ' . $format($expected) .
      '; found ' . $format($actual) . '.'
    );
  }
};

$canonicalize = static function ($value, string $path = '') use (&$canonicalize) {
  if (!is_array($value)) {
    return $value;
  }
  foreach ($value as $key => $item) {
    $child_path = $path === '' ? (string) $key : $path . '.' . $key;
    $value[$key] = $canonicalize($item, $child_path);
  }
  // Sort-handler order is semantic: the first handler is the primary sort.
  // Preserve that one mapping while normalizing unordered config mappings.
  $is_views_sort_map = str_starts_with($path, 'display.')
    && str_ends_with($path, '.display_options.sorts');
  if (!array_is_list($value) && !$is_views_sort_map) {
    ksort($value, SORT_STRING);
  }
  return $value;
};

$hash_config = static function (array $config) use ($canonicalize, $fail): string {
  $encoded = json_encode(
    $canonicalize($config),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
  );
  if ($encoded === FALSE) {
    $fail('Could not normalize config for hashing.');
  }
  return hash('sha256', $encoded);
};

$differences = [];
$compare = static function ($active, $desired, string $path = '') use (&$compare, &$differences): void {
  if (is_array($active) && is_array($desired)) {
    $keys = array_unique(array_merge(array_keys($active), array_keys($desired)));
    usort($keys, static fn($left, $right): int => strnatcmp((string) $left, (string) $right));
    foreach ($keys as $key) {
      $child_path = $path === '' ? (string) $key : $path . '.' . $key;
      $active_exists = array_key_exists($key, $active);
      $desired_exists = array_key_exists($key, $desired);
      if (!$active_exists || !$desired_exists) {
        $differences[] = [
          'path' => $child_path,
          'active' => $active_exists ? $active[$key] : NULL,
          'desired' => $desired_exists ? $desired[$key] : NULL,
        ];
        continue;
      }
      $compare($active[$key], $desired[$key], $child_path);
    }
    return;
  }
  if ($active !== $desired) {
    $differences[] = [
      'path' => $path === '' ? '(root)' : $path,
      'active' => $active,
      'desired' => $desired,
    ];
  }
};

$section('Drupal and source guards');
echo 'Drupal bootstrap: OK (' . \Drupal::VERSION . ')' . PHP_EOL;
echo 'Action: ' . $action . PHP_EOL;
echo 'Direction: ' . $direction . PHP_EOL;

$target = [];
foreach ($config_names as $config_name) {
  $expected_source_path = $project_root . '/config/sync/' . $config_name . '.yml';
  $source_path = realpath($expected_source_path);
  if ($source_path === FALSE || $source_path !== $expected_source_path) {
    $fail(
      'Exact source-path guard failed for ' . $config_name . '. Expected ' .
      $expected_source_path . '; resolved ' .
      ($source_path === FALSE ? '(missing)' : $source_path) . '.'
    );
  }
  if (basename($source_path) !== $config_name . '.yml') {
    $fail('Exact config filename guard failed for ' . $config_name . '.');
  }
  $source_sha256 = hash_file('sha256', $source_path);
  if ($source_sha256 !== $expected_source_sha256[$config_name]) {
    $fail(
      'Reviewed source SHA-256 mismatch for ' . $config_name . '. Expected ' .
      $expected_source_sha256[$config_name] . '; found ' . $source_sha256 . '.'
    );
  }
  try {
    $parsed = Yaml::parseFile($source_path);
  }
  catch (Throwable $throwable) {
    $fail('Staged YAML could not be parsed for ' . $config_name . ': ' . $throwable->getMessage());
  }
  if (!is_array($parsed)) {
    $fail('Staged YAML must contain a top-level mapping for ' . $config_name . '.');
  }
  $target[$config_name] = $parsed;
  echo 'Reviewed source: OK (' . $config_name . ')' . PHP_EOL;
}

$view = $target[$view_config_name];
$block = $target[$block_config_name];
$expect($view['uuid'] ?? NULL, '4e959ba1-15b8-4a37-a2c1-ccad6b4b565b', 'View UUID');
$expect($view['id'] ?? NULL, $view_id, 'View id');
$expect($view['module'] ?? NULL, 'views', 'View module');
$expect($view['base_table'] ?? NULL, 'node_field_data', 'View base table');
$expect($view['base_field'] ?? NULL, 'nid', 'View base field');
$expect(
  $view['dependencies'] ?? NULL,
  [
    'config' => [
      'core.entity_view_mode.node.teaser',
      'field.storage.node.field_event_dates',
      'node.type.concert',
    ],
    'module' => ['datetime', 'node', 'user'],
  ],
  'View dependencies'
);

$options = $view['display']['default']['display_options'] ?? NULL;
if (!is_array($options)) {
  $fail('View default display options are missing.');
}
$expect(
  $options['pager'] ?? NULL,
  ['type' => 'mini', 'options' => ['offset' => 0, 'items_per_page' => 10]],
  'View pager'
);
$expect(
  $options['access'] ?? NULL,
  ['type' => 'perm', 'options' => ['perm' => 'access content']],
  'View access'
);
$expect($options['cache'] ?? NULL, ['type' => 'none', 'options' => []], 'View cache');
$expect(
  $options['sorts'] ?? NULL,
  [
    'field_event_dates_value' => [
      'id' => 'field_event_dates_value',
      'table' => 'node__field_event_dates',
      'field' => 'field_event_dates_value',
      'plugin_id' => 'datetime',
      'order' => 'ASC',
    ],
    'nid' => [
      'id' => 'nid',
      'table' => 'node_field_data',
      'field' => 'nid',
      'plugin_id' => 'standard',
      'order' => 'ASC',
    ],
  ],
  'View sorts'
);
$expect(
  $options['filters'] ?? NULL,
  [
    'status' => [
      'id' => 'status',
      'table' => 'node_field_data',
      'field' => 'status',
      'plugin_id' => 'boolean',
      'value' => '1',
    ],
    'type' => [
      'id' => 'type',
      'table' => 'node_field_data',
      'field' => 'type',
      'plugin_id' => 'bundle',
      'value' => ['concert' => 'concert'],
    ],
    'field_event_dates_end_value' => [
      'id' => 'field_event_dates_end_value',
      'table' => 'node__field_event_dates',
      'field' => 'field_event_dates_end_value',
      'plugin_id' => 'datetime',
      'operator' => '>=',
      'value' => ['min' => '', 'max' => '', 'value' => 'now', 'type' => 'offset'],
    ],
  ],
  'View filters'
);
$expect(
  $options['empty'] ?? NULL,
  [
    'area_text_custom' => [
      'id' => 'area_text_custom',
      'table' => 'views',
      'field' => 'area_text_custom',
      'plugin_id' => 'text_custom',
      'empty' => TRUE,
      'content' => 'Aucun concert ou événement à venir pour le moment.',
      'tokenize' => FALSE,
    ],
  ],
  'View empty state'
);
$expect($options['row'] ?? NULL, ['type' => 'entity:node', 'options' => ['view_mode' => 'teaser']], 'View row');
$expect($options['query'] ?? NULL, ['type' => 'views_query', 'options' => []], 'View query');
$expected_cache_contexts = [
  'languages:language_interface',
  'url.query_args',
  'user.node_grants:view',
  'user.permissions',
];
foreach (['default', 'block_1'] as $display_id) {
  $cache_metadata = $view['display'][$display_id]['cache_metadata'] ?? NULL;
  $expect($cache_metadata['max-age'] ?? NULL, 0, $display_id . ' cache max-age');
  $expect($cache_metadata['contexts'] ?? NULL, $expected_cache_contexts, $display_id . ' cache contexts');
}
$expect(
  $view['display']['block_1']['display_options']['defaults'] ?? NULL,
  ['pager' => TRUE, 'style' => TRUE, 'row' => TRUE, 'sorts' => TRUE, 'filters' => TRUE],
  'Block display inheritance'
);

$expect($block['uuid'] ?? NULL, '15959ec1-8bab-4b2a-ad30-bebb9c557635', 'Block UUID');
$expect($block['id'] ?? NULL, $block_id, 'Block id');
$expect(
  $block['dependencies'] ?? NULL,
  [
    'config' => [$view_config_name],
    'module' => ['system', 'views'],
    'theme' => ['unisonges_theme'],
  ],
  'Block dependencies'
);
$expect($block['plugin'] ?? NULL, 'views_block:hub_concerts_posts-block_1', 'Block plugin');
$expect($block['settings']['id'] ?? NULL, 'views_block:hub_concerts_posts-block_1', 'Block settings id');
$expect($block['settings']['label'] ?? NULL, 'Concerts et événements à venir', 'Block label');
$expect($block['settings']['label_display'] ?? NULL, 'visible', 'Block label visibility');
$expect(
  $block['visibility'] ?? NULL,
  [
    'request_path' => [
      'id' => 'request_path',
      'negate' => FALSE,
      'pages' => '/concerts',
    ],
  ],
  'Block route visibility'
);
echo 'Target semantic assertions: OK' . PHP_EOL;

// Reconstruct the exact previous reviewed values from the source-locked target.
$before = $target;
$before_view = &$before[$view_config_name];
$before_view['dependencies'] = [
  'config' => ['core.entity_view_mode.node.teaser', 'node.type.concert'],
  'module' => ['node', 'user'],
];
$before_options = &$before_view['display']['default']['display_options'];
unset($before_options['cache']);
$before_options['sorts'] = [
  'created' => [
    'id' => 'created',
    'table' => 'node_field_data',
    'field' => 'created',
    'plugin_id' => 'date',
    'order' => 'DESC',
  ],
];
unset($before_options['filters']['field_event_dates_end_value'], $before_options['empty']);
$before_view['display']['default']['cache_metadata']['max-age'] = -1;
$before_view['display']['block_1']['cache_metadata']['max-age'] = -1;
unset($before_options, $before_view);
$before[$block_config_name]['settings']['label'] = 'Nouveaux évènements :';

foreach ($config_names as $config_name) {
  $expect(
    $hash_config($before[$config_name]),
    $expected_state_sha256[$config_name]['before'],
    $config_name . ' reconstructed before-state SHA-256'
  );
  $expect(
    $hash_config($target[$config_name]),
    $expected_state_sha256[$config_name]['target'],
    $config_name . ' target-state SHA-256'
  );
}
echo 'Reviewed before/target state hashes: OK' . PHP_EOL;

$entity_type_manager = \Drupal::entityTypeManager();
$expected_prefixes = ['view' => 'views.view', 'block' => 'block.block'];
foreach ($expected_prefixes as $entity_type_id => $expected_prefix) {
  if (!$entity_type_manager->hasDefinition($entity_type_id)) {
    $fail('Required config entity type is unavailable: ' . $entity_type_id . '.');
  }
  $definition = $entity_type_manager->getDefinition($entity_type_id);
  if ($definition->getConfigPrefix() !== $expected_prefix) {
    $fail('Unexpected config prefix for ' . $entity_type_id . '; refusing targeted writes.');
  }
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

$module_handler = \Drupal::moduleHandler();
$theme_handler = \Drupal::service('theme_handler');
foreach ($target as $config_name => $staged) {
  foreach (($staged['dependencies']['module'] ?? []) as $module_name) {
    if (!is_string($module_name) || $module_name === '' || !$module_handler->moduleExists($module_name)) {
      $fail('Required module is unavailable for ' . $config_name . ': ' . $format($module_name));
    }
  }
  foreach (($staged['dependencies']['config'] ?? []) as $dependency_name) {
    if (!is_string($dependency_name) || $dependency_name === '' || !$config_storage->exists($dependency_name)) {
      $fail('Required config is unavailable for ' . $config_name . ': ' . $format($dependency_name));
    }
  }
  foreach (($staged['dependencies']['theme'] ?? []) as $theme_name) {
    if (!is_string($theme_name) || $theme_name === '' || !$theme_handler->themeExists($theme_name)) {
      $fail('Required theme is unavailable for ' . $config_name . ': ' . $format($theme_name));
    }
  }
}
echo 'Dependencies: OK' . PHP_EOL;

$section('Known-state classification');
$active = [];
$states = [];
$unknown = [];
foreach ($config_names as $config_name) {
  $active_config = $config_storage->read($config_name);
  if (!is_array($active_config)) {
    $fail('Active ' . $config_name . ' is missing or unreadable; refusing to create it silently.');
  }
  $active[$config_name] = $active_config;
  if ($canonicalize($active_config) === $canonicalize($before[$config_name])) {
    $states[$config_name] = 'before';
  }
  elseif ($canonicalize($active_config) === $canonicalize($target[$config_name])) {
    $states[$config_name] = 'target';
  }
  else {
    $states[$config_name] = 'unknown';
    $unknown[] = $config_name;
  }
  echo $config_name . PHP_EOL;
  echo '  active state: ' . $states[$config_name] . PHP_EOL;
  echo '  active SHA-256: ' . $hash_config($active_config) . PHP_EOL;
  echo '  before SHA-256: ' . $hash_config($before[$config_name]) . PHP_EOL;
  echo '  target SHA-256: ' . $hash_config($target[$config_name]) . PHP_EOL;
}

if ($unknown !== []) {
  echo PHP_EOL . 'UNKNOWN_DRIFT No config was changed.' . PHP_EOL;
  foreach ($unknown as $config_name) {
    echo '- ' . $config_name . ' matches neither reviewed state.' . PHP_EOL;
  }
  $fail('Unknown active configuration drift detected; refusing all writes.');
}

$desired_state = $direction === 'forward' ? 'target' : 'before';
$desired = $direction === 'forward' ? $target : $before;
$writes = [];
foreach ($config_names as $config_name) {
  if ($states[$config_name] !== $desired_state) {
    $writes[] = $config_name;
  }
}

$section('Active versus desired state');
echo 'Desired state: ' . $desired_state . PHP_EOL;
if ($writes === []) {
  echo 'MATCH Both active configs already match the desired reviewed state.' . PHP_EOL;
  echo $is_apply
    ? 'NOOP No config write was necessary.' . PHP_EOL
    : 'DRY_RUN No config was changed.' . PHP_EOL;
  return;
}

foreach ($writes as $config_name) {
  $differences = [];
  $compare($canonicalize($active[$config_name]), $canonicalize($desired[$config_name]));
  echo 'DIFF ' . $config_name . ': ' . count($differences) . ' changed value(s)' . PHP_EOL;
  foreach ($differences as $difference) {
    echo '- ' . $difference['path'] . PHP_EOL;
    echo '  active: ' . $format($difference['active']) . PHP_EOL;
    echo '  desired: ' . $format($difference['desired']) . PHP_EOL;
  }
}

if (!$is_apply) {
  foreach ($writes as $config_name) {
    echo PHP_EOL . 'WOULD_WRITE ' . $config_name . PHP_EOL;
  }
  echo 'DRY_RUN No config was changed. Rerun with --apply after reviewing this comparison.' . PHP_EOL;
  return;
}

$section('Targeted write');
$written_names = [];
foreach ($writes as $config_name) {
  echo 'WRITE ' . $config_name . PHP_EOL;
  \Drupal::configFactory()
    ->getEditable($config_name)
    ->setData($desired[$config_name])
    ->save(TRUE);
  $written_names[] = $config_name;
}

$entity_type_manager->getStorage('view')->resetCache([$view_id]);
$entity_type_manager->getStorage('block')->resetCache([$block_id]);
foreach ($config_names as $config_name) {
  $written = $config_storage->read($config_name);
  if (!is_array($written) || $canonicalize($written) !== $canonicalize($desired[$config_name])) {
    $fail('Post-write verification failed for ' . $config_name . '.');
  }
}

echo 'OK Both active configs now match the desired reviewed state.' . PHP_EOL;
echo 'Written config count: ' . count($written_names) . PHP_EOL;
foreach ($written_names as $config_name) {
  echo 'Written config name: ' . $config_name . PHP_EOL;
}
PHP

UNISONGES_CONCERT_HUB_ACTION="${ACTION}" \
UNISONGES_CONCERT_HUB_DIRECTION="${DIRECTION}" \
  "${DRUSH_CMD}" php:script "${PHP_SCRIPT}"

section "Result"
if [[ "${ACTION}" == "apply" ]]; then
  log "Targeted ${DIRECTION} write completed; only the two allowlisted configs were eligible."
else
  log "${DIRECTION} dry-run completed. No active config or content was changed."
fi
