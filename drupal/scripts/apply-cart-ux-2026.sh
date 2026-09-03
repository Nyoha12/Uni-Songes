#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DRUPAL_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"
REPO_DIR="$(cd -- "${DRUPAL_DIR}/.." && pwd -P)"
SYNC_DIR="${DRUPAL_DIR}/config/sync"
CONFIG_NAME="views.view.commerce_cart_form"
CONFIG_FILE="${SYNC_DIR}/${CONFIG_NAME}.yml"
DRUSH="${DRUSH:-./vendor/bin/drush}"

if [[ "${DRUSH}" == /* ]]; then
  DRUSH_CMD="${DRUSH}"
else
  DRUSH_CMD="${DRUPAL_DIR}/${DRUSH}"
fi

ACTION="dry-run"
DIRECTION="forward"
REQUESTED_ACTION=""
EXPECTED_FINGERPRINT=""
ALLOW_VPS="0"

log() {
  printf '[apply-cart-ux-2026] %s\n' "$*"
}

warn() {
  printf '[apply-cart-ux-2026] WARNING: %s\n' "$*" >&2
}

section() {
  printf '\n== %s ==\n' "$*"
}

usage() {
  cat <<'EOF'
Usage: ./scripts/apply-cart-ux-2026.sh [--dry-run|--apply] [--rollback]
       [--expect-fingerprint=<sha256>] [--allow-vps]

Audits or applies the single reviewed Cart View configuration change. Dry-run
is the default. An apply or rollback requires the exact fingerprint printed by
an immediately preceding dry-run against the same active configuration.

Options:
  --dry-run    Complete every preflight and print the plan without writing.
  --apply      Apply the selected direction after fingerprint verification.
  --rollback   Select the exact reviewed pre-change state. Without --apply this
               performs a rollback dry-run.
  --expect-fingerprint=<sha256>
               Required with --apply. Must match the dry-run plan exactly.
  --allow-vps  Permit /var/www execution on reviewed staging only. This option
               does not authorize production execution.
  -h, --help   Show this help.

Forward sequence:
  ./scripts/apply-cart-ux-2026.sh --dry-run
  ./scripts/apply-cart-ux-2026.sh --apply --expect-fingerprint=<sha256>
  ./scripts/apply-cart-ux-2026.sh --dry-run

Rollback sequence:
  ./scripts/apply-cart-ux-2026.sh --rollback --dry-run
  ./scripts/apply-cart-ux-2026.sh --rollback --apply --expect-fingerprint=<sha256>
  ./scripts/apply-cart-ux-2026.sh --rollback --dry-run

Safety:
  - The exact writable allowlist is views.view.commerce_cart_form only.
  - Active target config must exactly match the reviewed before or target state.
  - A fingerprint covers every active config object in every collection.
  - The preflight is repeated under persistent Cart and config-import locks.
  - Post-write verification permits no active config change outside the allowlist.
  - No full or partial import/export, raw SQL, or content/entity write is used.
  - Dry-run performs no active configuration or content writes.
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
    --expect-fingerprint=*)
      if [[ -n "${EXPECTED_FINGERPRINT}" ]]; then
        warn "Provide --expect-fingerprint only once."
        exit 2
      fi
      EXPECTED_FINGERPRINT="${arg#--expect-fingerprint=}"
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

if [[ -n "${EXPECTED_FINGERPRINT}" && ! "${EXPECTED_FINGERPRINT}" =~ ^[0-9a-f]{64}$ ]]; then
  warn "--expect-fingerprint must be exactly 64 lowercase hexadecimal characters."
  exit 2
fi
if [[ "${ACTION}" == "apply" && -z "${EXPECTED_FINGERPRINT}" ]]; then
  warn "--apply requires --expect-fingerprint from the immediately preceding dry-run."
  exit 2
fi
if [[ "${ACTION}" == "dry-run" && -n "${EXPECTED_FINGERPRINT}" ]]; then
  warn "--expect-fingerprint is valid only with --apply."
  exit 2
fi

require_safe_path() {
  case "${DRUPAL_DIR}" in
    /|/tmp|/tmp/*|/mnt/c|/mnt/c/*)
      warn "Refusing unsafe Drupal path: ${DRUPAL_DIR}"
      exit 1
      ;;
    /var/www|/var/www/*)
      if [[ "${ALLOW_VPS}" != "1" ]]; then
        warn "Refusing /var/www execution without --allow-vps: ${DRUPAL_DIR}"
        warn "Use --allow-vps only on reviewed staging, never production."
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
    warn "Exact source-path guard failed."
    warn "Expected: ${CONFIG_FILE}"
    warn "Resolved: ${resolved_config_file}"
    exit 1
  fi
  if [[ "$(basename -- "${CONFIG_FILE}")" != "${CONFIG_NAME}.yml" ]]; then
    warn "Exact config-name guard failed for ${CONFIG_FILE}."
    exit 1
  fi
}

require_reviewed_source() {
  if ! git --no-optional-locks -C "${REPO_DIR}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    warn "A Git checkout is required to verify the reviewed config source."
    exit 1
  fi
  if ! git --no-optional-locks -C "${REPO_DIR}" ls-files --error-unmatch -- "drupal/config/sync/${CONFIG_NAME}.yml" >/dev/null 2>&1; then
    warn "The allowlisted config source is not tracked by Git."
    exit 1
  fi
  if ! git --no-optional-locks -C "${REPO_DIR}" diff --quiet HEAD -- "drupal/config/sync/${CONFIG_NAME}.yml"; then
    warn "The allowlisted config source differs from deployed HEAD."
    exit 1
  fi
}

require_runtime() {
  if [[ ! -f "${DRUPAL_DIR}/web/core/lib/Drupal.php" ]]; then
    warn "Installed Drupal core is missing. Install the locked Composer dependencies first."
    exit 1
  fi
  if [[ ! -x "${DRUSH_CMD}" ]]; then
    warn "Local Drush is missing or not executable at ${DRUSH_CMD}."
    exit 1
  fi
}

print_plan() {
  section "Safety plan"
  printf 'Action: %s\n' "${ACTION}"
  printf 'Direction: %s\n' "${DIRECTION}"
  printf 'Drupal project: %s\n' "${DRUPAL_DIR}"
  printf 'Reviewed source: %s\n' "${CONFIG_FILE}"
  printf 'Writable config count: 1\n'
  printf 'Writable config: %s\n' "${CONFIG_NAME}"
  if [[ "${ACTION}" == "apply" ]]; then
    printf 'Expected dry-run fingerprint: %s\n' "${EXPECTED_FINGERPRINT}"
  fi
  cat <<'EOF'
Every preflight completes before lock acquisition or a config write. Apply then
repeats the active snapshot and plan under persistent locks. The transaction is
accepted only if the complete active configuration differs exactly as planned.
No cart, order, order item, product, customer, price, workflow, or payment data
is read for mutation or written by this helper.
EOF
}

require_safe_path
require_reviewed_source
require_runtime
print_plan

cd "${DRUPAL_DIR}"

PHP_CODE=''
IFS= read -r -d '' PHP_CODE <<'PHP' || true
declare(strict_types=1);

use Drupal\Core\Config\CachedStorage;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\DatabaseStorage;
use Drupal\Core\Config\StorageInterface;
use Symfony\Component\Yaml\Yaml;

$action = getenv('UNISONGES_CART_UX_ACTION') ?: 'dry-run';
$direction = getenv('UNISONGES_CART_UX_DIRECTION') ?: 'forward';
$expected_plan_fingerprint = getenv('UNISONGES_CART_UX_EXPECT_FINGERPRINT') ?: '';
if (!in_array($action, ['dry-run', 'apply'], TRUE)) {
  throw new RuntimeException('Invalid action; expected dry-run or apply.');
}
if (!in_array($direction, ['forward', 'rollback'], TRUE)) {
  throw new RuntimeException('Invalid direction; expected forward or rollback.');
}
if ($action === 'apply' && !preg_match('/^[0-9a-f]{64}$/', $expected_plan_fingerprint)) {
  throw new RuntimeException('Apply requires a valid dry-run plan fingerprint.');
}
$is_apply = $action === 'apply';

// Immutable reviewed identifiers and hashes. These cannot be overridden by
// environment variables or command-line input.
$config_name = 'views.view.commerce_cart_form';
$view_id = 'commerce_cart_form';
$expected_uuid = '333893f0-e316-472b-879a-00908431a754';
$expected_source_sha256 = 'f2e4fa2e87f4fc3509578fd978298ed9d7f357907b27eb84ea168521e2eb1b82';
$expected_state_sha256 = [
  'before' => 'd9f0e95f095afd0212e185841144055bf1c501cec8d1c9312d249c272c452741',
  'target' => 'b73daaebb4a44634c4bb19b5d0a4354d72b13b9d545e8c8d24dbc7f0488c8b8f',
];
$project_root = dirname(\Drupal::root());
$expected_source_path = $project_root . '/config/sync/' . $config_name . '.yml';
$source_path = realpath($expected_source_path);

$section = static function (string $title): void {
  echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
};

$fail = static function (string $message): never {
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
  return strlen($encoded) > 240 ? substr($encoded, 0, 237) . '...' : $encoded;
};

$expect = static function ($actual, $expected, string $label) use ($fail, $format): void {
  if ($actual !== $expected) {
    $fail(
      $label . ' is not the reviewed value. Expected ' . $format($expected) .
      '; found ' . $format($actual) . '.'
    );
  }
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

$hash_value = static function ($value) use ($canonicalize, $fail): string {
  $encoded = json_encode(
    $canonicalize($value),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
  );
  if ($encoded === FALSE) {
    $fail('Could not normalize a value for SHA-256 hashing.');
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

$section('Drupal and reviewed source guards');
echo 'Drupal bootstrap: OK (' . \Drupal::VERSION . ')' . PHP_EOL;
echo 'Action: ' . $action . PHP_EOL;
echo 'Direction: ' . $direction . PHP_EOL;
$expect(\Drupal::VERSION, '11.3.3', 'Installed Drupal core version');

if ($source_path === FALSE || $source_path !== $expected_source_path) {
  $fail(
    'Exact source-path guard failed. Expected ' . $expected_source_path .
    '; resolved ' . ($source_path === FALSE ? '(missing)' : $source_path) . '.'
  );
}
if (basename($source_path) !== $config_name . '.yml') {
  $fail('Exact config filename guard failed.');
}
$source_sha256 = hash_file('sha256', $source_path);
$expect($source_sha256, $expected_source_sha256, 'Reviewed source SHA-256');

try {
  $target = Yaml::parseFile($source_path);
}
catch (Throwable $throwable) {
  $fail('Reviewed YAML could not be parsed: ' . $throwable->getMessage());
}
if (!is_array($target)) {
  $fail('Reviewed YAML must contain a top-level mapping.');
}

$expect($target['uuid'] ?? NULL, $expected_uuid, 'View UUID');
$expect($target['langcode'] ?? NULL, 'fr', 'View language');
$expect($target['status'] ?? NULL, TRUE, 'View status');
$expect($target['id'] ?? NULL, $view_id, 'View id');
$expect($target['module'] ?? NULL, 'views', 'View module');
$expect($target['tag'] ?? NULL, 'commerce_cart_form', 'View tag');
$expect($target['base_table'] ?? NULL, 'commerce_order', 'View base table');
$expect($target['base_field'] ?? NULL, 'order_id', 'View base field');
$expect(
  $target['dependencies'] ?? NULL,
  [
    'module' => ['commerce_cart', 'commerce_order', 'commerce_price'],
    'enforced' => ['module' => ['commerce_cart']],
  ],
  'View dependencies'
);

$options = $target['display']['default']['display_options'] ?? NULL;
if (!is_array($options)) {
  $fail('The default View display options are missing.');
}
$fields = $options['fields'] ?? NULL;
if (!is_array($fields)) {
  $fail('The Cart View fields are missing.');
}
$expect(
  array_keys($fields),
  ['purchased_entity', 'unit_price__number', 'edit_quantity', 'remove_button', 'total_price__number'],
  'Cart field allowlist and order'
);
$expect($fields['purchased_entity']['plugin_id'] ?? NULL, 'field', 'Article field plugin');
$expect($fields['purchased_entity']['label'] ?? NULL, 'Article', 'Article field label');
$expect($fields['purchased_entity']['exclude'] ?? NULL, FALSE, 'Article field visibility');
$expect($fields['purchased_entity']['type'] ?? NULL, 'entity_reference_entity_view', 'Article formatter');
$expect($fields['purchased_entity']['settings']['view_mode'] ?? NULL, 'cart', 'Article view mode');
$expect($fields['unit_price__number']['plugin_id'] ?? NULL, 'field', 'Unit-price field plugin');
$expect($fields['unit_price__number']['label'] ?? NULL, 'Prix', 'Unit-price label');
$expect($fields['unit_price__number']['exclude'] ?? NULL, FALSE, 'Unit-price visibility');
$expect($fields['unit_price__number']['type'] ?? NULL, 'commerce_price_default', 'Unit-price formatter');
$expect($fields['edit_quantity']['plugin_id'] ?? NULL, 'commerce_order_item_edit_quantity', 'Quantity plugin');
$expect($fields['edit_quantity']['label'] ?? NULL, 'Quantité', 'Quantity label');
$expect($fields['edit_quantity']['exclude'] ?? NULL, FALSE, 'Quantity visibility');
$expect($fields['edit_quantity']['allow_decimal'] ?? NULL, FALSE, 'Quantity decimal setting');
$expect($fields['remove_button']['plugin_id'] ?? NULL, 'commerce_order_item_remove_button', 'Removal plugin');
$expect($fields['remove_button']['label'] ?? NULL, 'Retirer', 'Removal label');
$expect($fields['remove_button']['exclude'] ?? NULL, FALSE, 'Removal visibility');
$expect($fields['total_price__number']['plugin_id'] ?? NULL, 'field', 'Line-total field plugin');
$expect($fields['total_price__number']['label'] ?? NULL, 'Total', 'Line-total label');
$expect($fields['total_price__number']['exclude'] ?? NULL, FALSE, 'Line-total visibility');
$expect($fields['total_price__number']['type'] ?? NULL, 'commerce_price_default', 'Line-total formatter');
$expect($options['style']['type'] ?? NULL, 'table', 'Cart View style');
$expect($options['row']['type'] ?? NULL, 'fields', 'Cart View row plugin');
$expect($options['relationships']['order_items']['required'] ?? NULL, TRUE, 'Order-items relationship');
$expect($options['arguments']['order_id']['plugin_id'] ?? NULL, 'entity_target_id', 'Order argument plugin');
$expect($options['footer']['commerce_order_total']['plugin_id'] ?? NULL, 'commerce_order_total', 'Order-total footer');
$expect($options['footer']['commerce_order_total']['empty'] ?? NULL, FALSE, 'Order-total empty behavior');
echo 'Cart View semantic assertions: OK' . PHP_EOL;

// The exact reviewed pre-change object differs only in the public column label.
$before = $target;
$before['display']['default']['display_options']['fields']['purchased_entity']['label'] = 'Item';
$expect($hash_value($before), $expected_state_sha256['before'], 'Reconstructed before-state SHA-256');
$expect($hash_value($target), $expected_state_sha256['target'], 'Target-state SHA-256');
echo 'Reviewed before/target state hashes: OK' . PHP_EOL;

$entity_type_manager = \Drupal::entityTypeManager();
if (!$entity_type_manager->hasDefinition('view')) {
  $fail('The Views config entity type is unavailable.');
}
$view_definition = $entity_type_manager->getDefinition('view');
$expect($view_definition->getConfigPrefix(), 'views.view', 'Views config prefix');

$cached_config_storage = \Drupal::service('config.storage');
if (get_class($cached_config_storage) !== CachedStorage::class) {
  $fail('The public active config storage must be Drupal core CachedStorage exactly.');
}
try {
  $wrapped_storage_property = new ReflectionProperty(CachedStorage::class, 'storage');
  $config_storage = $wrapped_storage_property->getValue($cached_config_storage);
}
catch (ReflectionException $exception) {
  $fail('Could not inspect the pinned Drupal config-storage backend: ' . $exception->getMessage());
}
if (!$config_storage instanceof DatabaseStorage
  || $config_storage->getCollectionName() !== StorageInterface::DEFAULT_COLLECTION) {
  $fail('CachedStorage must wrap the default-collection DatabaseStorage directly.');
}
try {
  $connection_property = new ReflectionProperty(DatabaseStorage::class, 'connection');
  $table_property = new ReflectionProperty(DatabaseStorage::class, 'table');
  $storage_connection = $connection_property->getValue($config_storage);
  $storage_table = $table_property->getValue($config_storage);
}
catch (ReflectionException $exception) {
  $fail('Could not verify the pinned DatabaseStorage backend: ' . $exception->getMessage());
}
if ($storage_connection !== \Drupal::database() || $storage_table !== 'config') {
  $fail('Active config must use this site connection and the core config table.');
}
echo 'Transactional active-config storage: OK' . PHP_EOL;

$module_handler = \Drupal::moduleHandler();
foreach ($target['dependencies']['module'] as $module_name) {
  if (!is_string($module_name) || $module_name === '' || !$module_handler->moduleExists($module_name)) {
    $fail('Required module is unavailable: ' . $format($module_name));
  }
}
echo 'Required modules: OK' . PHP_EOL;

$read_snapshot = static function () use ($config_storage, $canonicalize, $fail): array {
  $collection_names = $config_storage->getAllCollectionNames();
  sort($collection_names, SORT_STRING);
  array_unshift($collection_names, StorageInterface::DEFAULT_COLLECTION);

  $snapshot = [];
  foreach ($collection_names as $collection_name) {
    $storage = $collection_name === StorageInterface::DEFAULT_COLLECTION
      ? $config_storage
      : $config_storage->createCollection($collection_name);
    $names = $storage->listAll();
    sort($names, SORT_STRING);
    $values = [];
    foreach ($names as $name) {
      $value = $storage->read($name);
      if (!is_array($value)) {
        $fail(
          'Active config is unreadable in collection ' .
          ($collection_name === '' ? '(default)' : $collection_name) . ': ' . $name . '.'
        );
      }
      $values[$name] = $canonicalize($value);
    }
    $snapshot[$collection_name] = $values;
  }
  return $snapshot;
};

$classify_target = static function (array $active) use ($canonicalize, $before, $target): string {
  $canonical_active = $canonicalize($active);
  if ($canonical_active === $canonicalize($before)) {
    return 'before';
  }
  if ($canonical_active === $canonicalize($target)) {
    return 'target';
  }
  return 'unknown';
};

$build_plan = static function (array $snapshot) use (
  $config_name,
  $direction,
  $expected_source_sha256,
  $hash_value,
  $classify_target,
  $fail
): array {
  $default_collection = $snapshot[StorageInterface::DEFAULT_COLLECTION] ?? NULL;
  if (!is_array($default_collection)) {
    $fail('The default active config collection is missing from the snapshot.');
  }
  $active_target = $default_collection[$config_name] ?? NULL;
  if (!is_array($active_target)) {
    $fail('Active ' . $config_name . ' is missing; refusing to create it silently.');
  }
  $active_state = $classify_target($active_target);
  if ($active_state === 'unknown') {
    $fail('Unknown active drift: ' . $config_name . ' matches neither reviewed state.');
  }
  $desired_state = $direction === 'forward' ? 'target' : 'before';
  $active_fingerprint = $hash_value($snapshot);
  $plan_material = [
    'schema' => 1,
    'config_allowlist' => [$config_name],
    'direction' => $direction,
    'desired_state' => $desired_state,
    'active_state' => $active_state,
    'active_config_fingerprint' => $active_fingerprint,
    'source_sha256' => $expected_source_sha256,
  ];
  return [
    'active_state' => $active_state,
    'desired_state' => $desired_state,
    'active_fingerprint' => $active_fingerprint,
    'plan_fingerprint' => $hash_value($plan_material),
  ];
};

$section('Complete active-config preflight');
$snapshot_before = $read_snapshot();
$plan = $build_plan($snapshot_before);
echo 'Active target state: ' . $plan['active_state'] . PHP_EOL;
echo 'Desired target state: ' . $plan['desired_state'] . PHP_EOL;
echo 'Active config fingerprint: ' . $plan['active_fingerprint'] . PHP_EOL;
echo 'PLAN_FINGERPRINT ' . $plan['plan_fingerprint'] . PHP_EOL;

$active_target = $snapshot_before[StorageInterface::DEFAULT_COLLECTION][$config_name];
$desired = $direction === 'forward' ? $target : $before;
$write_required = $plan['active_state'] !== $plan['desired_state'];
if ($write_required) {
  $differences = [];
  $compare($canonicalize($active_target), $canonicalize($desired));
  echo 'DIFF ' . $config_name . ': ' . count($differences) . ' changed value(s)' . PHP_EOL;
  foreach ($differences as $difference) {
    echo '- ' . $difference['path'] . PHP_EOL;
    echo '  active: ' . $format($difference['active']) . PHP_EOL;
    echo '  desired: ' . $format($difference['desired']) . PHP_EOL;
  }
}
else {
  echo 'MATCH Active target already matches the desired reviewed state.' . PHP_EOL;
}

if (!$is_apply) {
  echo $write_required
    ? 'WOULD_WRITE ' . $config_name . PHP_EOL
    : 'WOULD_WRITE none (idempotent no-op).' . PHP_EOL;
  echo 'DRY_RUN No active configuration or content was changed.' . PHP_EOL;
  return;
}

if (!hash_equals($expected_plan_fingerprint, $plan['plan_fingerprint'])) {
  $fail(
    'Dry-run fingerprint mismatch before lock acquisition. Expected ' .
    $expected_plan_fingerprint . '; current ' . $plan['plan_fingerprint'] . '.'
  );
}

$section('Locked preflight');
$lock = \Drupal::service('lock.persistent');
$cart_lock_name = 'unisonges_cart_ux_2026_config_apply';
$import_lock_name = ConfigImporter::LOCK_NAME;
$lock_ttl = 3600.0;
$cart_lock_acquired = FALSE;
$import_lock_acquired = FALSE;

try {
  $cart_lock_acquired = $lock->acquire($cart_lock_name, $lock_ttl);
  if (!$cart_lock_acquired) {
    $fail('Could not acquire the persistent Cart UX deployment lock.');
  }
  $import_lock_acquired = $lock->acquire($import_lock_name, $lock_ttl);
  if (!$import_lock_acquired) {
    $fail('A configuration import is running or holds the importer lock.');
  }

  $locked_snapshot = $read_snapshot();
  $locked_plan = $build_plan($locked_snapshot);
  if (!hash_equals($expected_plan_fingerprint, $locked_plan['plan_fingerprint'])) {
    $fail(
      'Active configuration changed after dry-run; expected plan ' .
      $expected_plan_fingerprint . ', locked plan ' . $locked_plan['plan_fingerprint'] . '.'
    );
  }
  if ($hash_value($locked_snapshot) !== $hash_value($snapshot_before)) {
    $fail('Active configuration changed during preflight; rerun the dry-run.');
  }
  echo 'Persistent Cart lock: OK' . PHP_EOL;
  echo 'Config importer lock: OK' . PHP_EOL;
  echo 'Locked plan fingerprint: ' . $locked_plan['plan_fingerprint'] . PHP_EOL;

  if (!$write_required) {
    echo 'NOOP No config write was necessary.' . PHP_EOL;
    return;
  }

  $predicted_snapshot = $locked_snapshot;
  $predicted_snapshot[StorageInterface::DEFAULT_COLLECTION][$config_name] = $canonicalize($desired);
  $predicted_fingerprint = $hash_value($predicted_snapshot);
  $before_fingerprint = $hash_value($locked_snapshot);

  // Extend both leases after the complete locked snapshot, immediately before
  // the transaction. Losing either lease aborts before a config write.
  $cart_lock_renewed = $lock->acquire($cart_lock_name, $lock_ttl);
  $import_lock_renewed = $lock->acquire($import_lock_name, $lock_ttl);
  if (!$cart_lock_renewed || !$import_lock_renewed) {
    $fail('Could not renew both deployment locks; no config was written.');
  }

  $section('Targeted transactional write');
  $database = $storage_connection;
  if ($database->inTransaction()) {
    $fail('Refusing to write inside an existing database transaction.');
  }
  try {
    $transaction = $database->startTransaction('unisonges_cart_ux_2026');
  }
  catch (Throwable $throwable) {
    throw new RuntimeException(
      'The targeted transaction could not start; no config was written.',
      0,
      $throwable
    );
  }
  if (!$database->inTransaction()) {
    unset($transaction);
    $fail('The database did not enter the targeted transaction; no config was written.');
  }

  try {
    echo 'WRITE ' . $config_name . PHP_EOL;
    \Drupal::configFactory()
      ->getEditable($config_name)
      ->setData($desired)
      ->save(TRUE);

    \Drupal::configFactory()->reset($config_name);
    $entity_type_manager->getStorage('view')->resetCache([$view_id]);
    $written_snapshot = $read_snapshot();
    $written_fingerprint = $hash_value($written_snapshot);
    if (!hash_equals($predicted_fingerprint, $written_fingerprint)) {
      $fail(
        'Post-write isolation failed: active config differs outside the exact planned snapshot. '
        . 'Expected ' . $predicted_fingerprint . '; found ' . $written_fingerprint . '.'
      );
    }
    $written_target = $written_snapshot[StorageInterface::DEFAULT_COLLECTION][$config_name] ?? NULL;
    if (!is_array($written_target) || $classify_target($written_target) !== $plan['desired_state']) {
      $fail('Post-write target verification failed for ' . $config_name . '.');
    }
  }
  catch (Throwable $throwable) {
    $rollback_failure = NULL;
    try {
      if (!$database->inTransaction()) {
        throw new RuntimeException('The targeted transaction was no longer active.');
      }
      $transaction->rollBack();
    }
    catch (Throwable $caught_rollback_failure) {
      $rollback_failure = $caught_rollback_failure;
    }
    unset($transaction);

    $cleanup_failure = NULL;
    try {
      // CachedStorage may use a non-database cache backend, outside the
      // transaction. Invalidate only the allowlisted config cache entry.
      \Drupal::cache('config')->delete($config_name);
      \Drupal::configFactory()->reset($config_name);
      $entity_type_manager->getStorage('view')->resetCache([$view_id]);
    }
    catch (Throwable $caught_cleanup_failure) {
      $cleanup_failure = $caught_cleanup_failure;
    }

    $rollback_fingerprint = '(unreadable)';
    $rollback_read_failure = NULL;
    try {
      $rollback_snapshot = $read_snapshot();
      $rollback_fingerprint = $hash_value($rollback_snapshot);
    }
    catch (Throwable $caught_read_failure) {
      $rollback_read_failure = $caught_read_failure;
    }
    $rollback_confirmed = $rollback_failure === NULL
      && $cleanup_failure === NULL
      && $rollback_read_failure === NULL
      && !$database->inTransaction()
      && hash_equals($before_fingerprint, $rollback_fingerprint);
    if (!$rollback_confirmed) {
      throw new RuntimeException(
        'Targeted write failed and exact rollback could not be confirmed. Before ' .
        $before_fingerprint . '; after ' . $rollback_fingerprint .
        ($rollback_failure ? '; rollback error: ' . $rollback_failure->getMessage() : '') .
        ($cleanup_failure ? '; cache cleanup error: ' . $cleanup_failure->getMessage() : '') .
        ($rollback_read_failure ? '; verification error: ' . $rollback_read_failure->getMessage() : '') .
        '.',
        0,
        $throwable
      );
    }
    throw new RuntimeException(
      'Targeted write failed; the transaction restored the complete active-config fingerprint.',
      0,
      $throwable
    );
  }

  try {
    $transaction->commitOrRelease();
    unset($transaction);
  }
  catch (Throwable $throwable) {
    $rollback_failure = NULL;
    if ($database->inTransaction()) {
      try {
        $transaction->rollBack();
      }
      catch (Throwable $caught_rollback_failure) {
        $rollback_failure = $caught_rollback_failure;
      }
    }
    unset($transaction);

    $cleanup_failure = NULL;
    try {
      \Drupal::cache('config')->delete($config_name);
      \Drupal::configFactory()->reset($config_name);
      $entity_type_manager->getStorage('view')->resetCache([$view_id]);
    }
    catch (Throwable $caught_cleanup_failure) {
      $cleanup_failure = $caught_cleanup_failure;
    }
    $commit_failure_fingerprint = '(unreadable)';
    $commit_failure_read_error = NULL;
    try {
      $commit_failure_snapshot = $read_snapshot();
      $commit_failure_fingerprint = $hash_value($commit_failure_snapshot);
    }
    catch (Throwable $caught_read_failure) {
      $commit_failure_read_error = $caught_read_failure;
    }
    $rollback_confirmed = $rollback_failure === NULL
      && $cleanup_failure === NULL
      && $commit_failure_read_error === NULL
      && !$database->inTransaction()
      && hash_equals($before_fingerprint, $commit_failure_fingerprint);
    throw new RuntimeException(
      $rollback_confirmed
        ? 'Transaction finalization failed before commit; exact rollback was confirmed.'
        : 'Transaction finalization failed and commit state requires a new dry-run. '
          . 'Before ' . $before_fingerprint . '; current ' . $commit_failure_fingerprint .
          ($rollback_failure ? '; rollback error: ' . $rollback_failure->getMessage() : '') .
          ($cleanup_failure ? '; cache cleanup error: ' . $cleanup_failure->getMessage() : '') .
          ($commit_failure_read_error ? '; verification error: ' . $commit_failure_read_error->getMessage() : '') . '.',
      0,
      $throwable
    );
  }

  echo 'OK Active config matches the exact desired reviewed state.' . PHP_EOL;
  echo 'Written config count: 1' . PHP_EOL;
  echo 'Written config name: ' . $config_name . PHP_EOL;
  echo 'Active config fingerprint before: ' . $before_fingerprint . PHP_EOL;
  echo 'Verified transaction fingerprint committed: ' . $predicted_fingerprint . PHP_EOL;
}
finally {
  if ($import_lock_acquired) {
    $lock->release($import_lock_name);
  }
  if ($cart_lock_acquired) {
    $lock->release($cart_lock_name);
  }
}
PHP

UNISONGES_CART_UX_ACTION="${ACTION}" \
UNISONGES_CART_UX_DIRECTION="${DIRECTION}" \
UNISONGES_CART_UX_EXPECT_FINGERPRINT="${EXPECTED_FINGERPRINT}" \
  "${DRUSH_CMD}" php:eval "${PHP_CODE}"

section "Result"
if [[ "${ACTION}" == "apply" ]]; then
  log "Targeted ${DIRECTION} completed; only ${CONFIG_NAME} was eligible for writing."
else
  log "${DIRECTION} dry-run completed with zero active config/content writes."
fi
