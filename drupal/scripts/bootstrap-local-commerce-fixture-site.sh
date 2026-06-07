#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRUPAL_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
DRUSH="./vendor/bin/drush"

MODE="dry-run"
REQUESTED_MODE=""
CONTAINER_SCRIPT="/tmp/bootstrap-local-commerce-fixture-site.php"

COURSE_TYPE_IDS=(
  cours_essai
  cours_deb_inter
  cours_avance
  pack_4_deb_inter
)

log() {
  printf '[bootstrap-local-commerce-fixture-site] %s\n' "$*"
}

warn() {
  printf '[bootstrap-local-commerce-fixture-site] WARNING: %s\n' "$*" >&2
}

section() {
  printf '\n== %s ==\n' "$*"
}

usage() {
  cat <<'EOF'
Usage: ./scripts/bootstrap-local-commerce-fixture-site.sh [--dry-run|--apply]

Prepares only the missing local active Commerce config prerequisites needed by
./scripts/create-local-fixtures.sh --with-commerce.

Options:
  --dry-run  Print the exact local active Commerce config that would be created.
             Default.
  --apply    Create only missing allowlisted local active Commerce config.
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
    /mnt/c|/mnt/c/*|/var/www|/var/www/*|/srv|/srv/*)
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
    warn "vendor/bin/drush is missing. Run Composer install inside DDEV before Commerce fixture bootstrap."
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
Commerce fixture bootstrap requires an installed local Drupal database.
No data was changed. Do not use production data; see docs/dev/ddev-testing.md.
EOF
    exit 1
  fi

  log "Drupal table key_value exists."
}

require_config_sources() {
  section "Commerce config sources"

  local missing=0
  local type_id
  local config_name

  for type_id in "${COURSE_TYPE_IDS[@]}"; do
    for config_name in \
      "commerce_product.commerce_product_variation_type.${type_id}" \
      "commerce_product.commerce_product_type.${type_id}"
    do
      if [[ -f "${DRUPAL_DIR}/config/sync/${config_name}.yml" ]]; then
        log "Found config/sync/${config_name}.yml"
      else
        warn "Missing config/sync/${config_name}.yml"
        missing=1
      fi
    done
  done

  if [[ "${missing}" -ne 0 ]]; then
    warn "Cannot continue without all allowlisted Commerce config sources."
    exit 1
  fi
}

print_plan() {
  section "Dry-run plan"

  cat <<'EOF'
The script will inspect active config and print exactly which missing entries
would be created.

Allowlisted active Commerce config:
- commerce_price.commerce_currency.EUR
- commerce_product.commerce_product_variation_type.cours_essai
- commerce_product.commerce_product_variation_type.cours_deb_inter
- commerce_product.commerce_product_variation_type.cours_avance
- commerce_product.commerce_product_variation_type.pack_4_deb_inter
- commerce_product.commerce_product_type.cours_essai
- commerce_product.commerce_product_type.cours_deb_inter
- commerce_product.commerce_product_type.cours_avance
- commerce_product.commerce_product_type.pack_4_deb_inter

No full drush config:import will run.
No modules will be enabled or disabled.
No config/sync files will be changed.
No stores, gateways, products, variations, orders, webform submissions, Google
Calendar data, Composer files, or .ddev files will be created or changed.
EOF
}

write_container_script() {
  ddev exec bash -lc "cat > ${CONTAINER_SCRIPT}" <<'PHP'
<?php

use CommerceGuys\Intl\Currency\CurrencyRepository;
use Symfony\Component\Yaml\Yaml;

$mode = getenv('BOOTSTRAP_LOCAL_COMMERCE_FIXTURE_MODE') ?: 'dry-run';
$is_apply = $mode === 'apply';
$failed = FALSE;
$blocked = [];
$created = [];
$unchanged = [];
$missing_entries = [];

$project_root = \Drupal::root() . '/..';
$config_dir = $project_root . '/config/sync';

$course_type_ids = [
  'cours_essai',
  'cours_deb_inter',
  'cours_avance',
  'pack_4_deb_inter',
];

$check = function (bool $ok, string $message) use (&$failed): void {
  echo ($ok ? 'OK' : 'FAIL') . ' ' . $message . PHP_EOL;
  $failed = $failed || !$ok;
};

$block = function (string $message) use (&$blocked): void {
  echo 'BLOCKED ' . $message . PHP_EOL;
  $blocked[] = $message;
};

$format_value = function ($value) use (&$format_value): string {
  if ($value === NULL || $value === '') {
    return 'NULL';
  }
  if (is_bool($value)) {
    return $value ? 'true' : 'false';
  }
  if (is_array($value)) {
    if ($value === []) {
      return '[]';
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }
  return (string) $value;
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

$summarize_currency = function (array $source) use ($format_value): string {
  return sprintf(
    'currencyCode=%s name=%s numericCode=%s symbol=%s fractionDigits=%s',
    $format_value($source['currencyCode'] ?? NULL),
    $format_value($source['name'] ?? NULL),
    $format_value($source['numericCode'] ?? NULL),
    $format_value($source['symbol'] ?? NULL),
    $format_value($source['fractionDigits'] ?? NULL)
  );
};

$summarize_product_type = function (array $source) use ($format_value): string {
  return sprintf(
    'id=%s label=%s variationTypes=%s multipleVariations=%s injectVariationFields=%s',
    $format_value($source['id'] ?? NULL),
    $format_value($source['label'] ?? NULL),
    $format_value($source['variationTypes'] ?? []),
    $format_value($source['multipleVariations'] ?? NULL),
    $format_value($source['injectVariationFields'] ?? NULL)
  );
};

$summarize_variation_type = function (array $source) use ($format_value): string {
  return sprintf(
    'id=%s label=%s orderItemType=%s generateTitle=%s',
    $format_value($source['id'] ?? NULL),
    $format_value($source['label'] ?? NULL),
    $format_value($source['orderItemType'] ?? NULL),
    $format_value($source['generateTitle'] ?? NULL)
  );
};

$entity_type_manager = \Drupal::entityTypeManager();
$config_factory = \Drupal::configFactory();

section('Drupal bootstrap');
echo 'OK Drupal bootstrap OK: ' . \Drupal::VERSION . PHP_EOL;

section('Commerce module readiness');
foreach ([
  'commerce',
  'commerce_price',
  'commerce_store',
  'commerce_order',
  'commerce_payment',
  'commerce_product',
] as $module) {
  $check(\Drupal::moduleHandler()->moduleExists($module), 'module ' . $module . ' is enabled');
}

section('Commerce entity type readiness');
foreach ([
  'commerce_currency',
  'commerce_store_type',
  'commerce_order_item_type',
  'commerce_product_type',
  'commerce_product_variation_type',
] as $entity_type_id) {
  $check($entity_type_manager->hasDefinition($entity_type_id), 'entity type ' . $entity_type_id . ' exists');
}

if ($failed) {
  echo PHP_EOL . 'Blocked before active Commerce config inspection. No config was changed.' . PHP_EOL;
  exit(1);
}

section('Supporting Commerce prerequisites');
$store_type_storage = $entity_type_manager->getStorage('commerce_store_type');
$order_item_type_storage = $entity_type_manager->getStorage('commerce_order_item_type');

$check((bool) $store_type_storage->load('online'), 'commerce store type online exists');
$check((bool) $order_item_type_storage->load('default'), 'commerce order item type default exists');

$gateway_definitions = \Drupal::service('plugin.manager.commerce_payment_gateway')->getDefinitions();
$check(isset($gateway_definitions['manual']), 'manual payment gateway plugin is available');

if ($failed) {
  echo PHP_EOL . 'Blocked before creating course Commerce prerequisites. This script creates only EUR and the four course product/variation types.' . PHP_EOL;
  exit(1);
}

section('Allowlisted Commerce config sources');

$default_langcode = \Drupal::languageManager()->getDefaultLanguage()->getId();
$currency_repository = new CurrencyRepository();
$currency = $currency_repository->get('EUR', $default_langcode, 'en');
$currency_source = [
  'langcode' => $default_langcode,
  'status' => TRUE,
  'dependencies' => [],
  'currencyCode' => $currency->getCurrencyCode(),
  'name' => $currency->getName(),
  'numericCode' => $currency->getNumericCode(),
  'symbol' => $currency->getSymbol(),
  'fractionDigits' => $currency->getFractionDigits(),
];

$entries = [
  [
    'config_name' => 'commerce_price.commerce_currency.EUR',
    'entity_type_id' => 'commerce_currency',
    'entity_id' => 'EUR',
    'source' => $currency_source,
    'summary' => $summarize_currency($currency_source),
  ],
];

foreach ($course_type_ids as $type_id) {
  $config_name = 'commerce_product.commerce_product_variation_type.' . $type_id;
  try {
    $source = $load_source($config_name);
    $check(($source['id'] ?? NULL) === $type_id, "{$config_name} source id matches {$type_id}");
    $check(($source['orderItemType'] ?? NULL) === 'default', "{$config_name} source orderItemType is default");
    $entries[] = [
      'config_name' => $config_name,
      'entity_type_id' => 'commerce_product_variation_type',
      'entity_id' => $type_id,
      'source' => $source,
      'summary' => $summarize_variation_type($source),
    ];
  }
  catch (Throwable $throwable) {
    $check(FALSE, "{$config_name} source validation failed: " . $throwable->getMessage());
  }
}

foreach ($course_type_ids as $type_id) {
  $config_name = 'commerce_product.commerce_product_type.' . $type_id;
  try {
    $source = $load_source($config_name);
    $check(($source['id'] ?? NULL) === $type_id, "{$config_name} source id matches {$type_id}");
    $variation_type_ids = array_values(array_filter($source['variationTypes'] ?? []));
    if ($variation_type_ids === []) {
      echo "OK {$config_name} source has no explicit variationTypes; fixture guard accepts matching variation type {$type_id}" . PHP_EOL;
    }
    else {
      $check(in_array($type_id, $variation_type_ids, TRUE), "{$config_name} source allows variation type {$type_id}");
    }
    $entries[] = [
      'config_name' => $config_name,
      'entity_type_id' => 'commerce_product_type',
      'entity_id' => $type_id,
      'source' => $source,
      'summary' => $summarize_product_type($source),
    ];
  }
  catch (Throwable $throwable) {
    $check(FALSE, "{$config_name} source validation failed: " . $throwable->getMessage());
  }
}

if ($failed) {
  echo PHP_EOL . 'Blocked because an allowlisted Commerce config source is unsafe or unavailable. No config was changed.' . PHP_EOL;
  exit(1);
}

section($is_apply ? 'Active Commerce config apply plan' : 'Active Commerce config dry-run');

foreach ($entries as $entry) {
  $config_name = $entry['config_name'];
  $entity_type_id = $entry['entity_type_id'];
  $entity_id = $entry['entity_id'];
  $source = $entry['source'];
  $summary = $entry['summary'];

  try {
    $storage = $entity_type_manager->getStorage($entity_type_id);
    $active_config = $config_factory->get($config_name);
    $existing = $storage->load($entity_id);

    if (!$active_config->isNew() || $existing) {
      if (!$existing) {
        $block("{$config_name} exists in active config but cannot be loaded as {$entity_type_id}. No overwrite attempted.");
        continue;
      }

      $active_data = $active_config->getRawData();
      if ($normalize($active_data) === $normalize($source)) {
        echo "OK {$config_name} already matches allowlisted local config." . PHP_EOL;
        $unchanged[] = $config_name;
        continue;
      }

      $block("{$config_name} already exists locally and differs from the allowlisted local config. No overwrite attempted.");
      continue;
    }

    echo ($is_apply ? 'CREATE ' : 'WOULD_CREATE ') . "{$config_name} {$summary}" . PHP_EOL;
    $missing_entries[] = $entry;
  }
  catch (Throwable $throwable) {
    $check(FALSE, "{$config_name} active config inspection failed: " . $throwable->getMessage());
  }
}

if ($blocked) {
  echo PHP_EOL . 'Blocked because local active Commerce config differs from the allowlisted local fixture prerequisites:' . PHP_EOL;
  foreach ($blocked as $message) {
    echo '- ' . $message . PHP_EOL;
  }
  echo 'No active config was deleted or overwritten. Reconcile the local config manually before rerunning.' . PHP_EOL;
  exit(1);
}

if ($failed) {
  echo PHP_EOL . 'Active Commerce config inspection failed. No config was changed.' . PHP_EOL;
  exit(1);
}

if ($is_apply) {
  section('Active Commerce config create');

  foreach ($missing_entries as $entry) {
    $config_name = $entry['config_name'];
    $entity_type_id = $entry['entity_type_id'];
    $source = $entry['source'];

    try {
      $storage = $entity_type_manager->getStorage($entity_type_id);
      $entity = $storage->create($source);
      if (method_exists($entity, 'trustData')) {
        $entity->trustData();
      }
      $entity->save();
      echo "CREATED {$config_name}" . PHP_EOL;
      $created[] = $config_name;
    }
    catch (Throwable $throwable) {
      $check(FALSE, "{$config_name} create failed: " . $throwable->getMessage());
    }
  }

  if ($failed) {
    echo PHP_EOL . 'Commerce prerequisite config creation failed. No full config import was attempted.' . PHP_EOL;
    exit(1);
  }

  section('Post-apply Commerce guards');
  $currency_storage = $entity_type_manager->getStorage('commerce_currency');
  $product_type_storage = $entity_type_manager->getStorage('commerce_product_type');
  $variation_type_storage = $entity_type_manager->getStorage('commerce_product_variation_type');

  $check((bool) $currency_storage->load('EUR'), 'commerce currency EUR exists');

  foreach ($course_type_ids as $type_id) {
    $variation_type = $variation_type_storage->load($type_id);
    $check((bool) $variation_type, 'variation type ' . $type_id . ' exists');
    if ($variation_type && method_exists($variation_type, 'getOrderItemTypeId')) {
      $check($variation_type->getOrderItemTypeId() === 'default', 'variation type ' . $type_id . ' uses order item type default');
    }

    $product_type = $product_type_storage->load($type_id);
    $check((bool) $product_type, 'product type ' . $type_id . ' exists');
    if ($product_type && method_exists($product_type, 'getVariationTypeIds')) {
      $variation_type_ids = $product_type->getVariationTypeIds();
      if ($variation_type_ids === []) {
        echo 'OK product type ' . $type_id . ' has no explicit variationTypes; fixture guard accepts matching variation type ' . $type_id . PHP_EOL;
      }
      else {
        $check(in_array($type_id, $variation_type_ids, TRUE), 'product type ' . $type_id . ' allows variation type ' . $type_id);
      }
    }
  }

  if ($failed) {
    echo PHP_EOL . 'Commerce prerequisite guards failed after the local bootstrap step.' . PHP_EOL;
    exit(1);
  }
}

section('Result');
if ($is_apply) {
  echo 'Created active config count: ' . count($created) . PHP_EOL;
  echo 'Already matching active config count: ' . count($unchanged) . PHP_EOL;
  echo 'Local Commerce fixture prerequisite bootstrap completed without full config import.' . PHP_EOL;
  echo 'No stores, gateways, products, variations, orders, webform submissions, Google Calendar data, config/sync, Composer files, or .ddev files were changed.' . PHP_EOL;
}
else {
  echo 'Would create active config count: ' . count($missing_entries) . PHP_EOL;
  echo 'Already matching active config count: ' . count($unchanged) . PHP_EOL;
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
  ddev exec env BOOTSTRAP_LOCAL_COMMERCE_FIXTURE_MODE="${MODE}" "${DRUSH}" php:script "${CONTAINER_SCRIPT}"
}

cd "${DRUPAL_DIR}"

log "Mode: ${MODE}"
require_safe_path
require_ddev
require_drush
require_database
require_config_sources
if [[ "${MODE}" == "dry-run" ]]; then
  print_plan
fi

run_php_step

if [[ "${MODE}" == "dry-run" ]]; then
  section "Dry-run result"
  log "Dry-run completed. No data or config was changed."
else
  section "Apply result"
  log "Local Commerce fixture prerequisites were created or verified. No full config import was run."
fi
