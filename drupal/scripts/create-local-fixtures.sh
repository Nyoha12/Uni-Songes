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
Usage: ./scripts/create-local-fixtures.sh [--dry-run|--apply] [--with-commerce]

Creates or updates local-only DDEV fixture users.
Commerce fixture store/gateway/products are opt-in with --with-commerce.

Options:
  --dry-run        Run read-only guards and print planned fixture user changes. Default.
  --apply          Create or update only local.fixture.* users through Drupal APIs.
  --with-commerce  Also plan/apply guarded local Commerce fixture data.
  -h, --help       Show this help.
EOF
}

mode="dry-run"
requested_mode=""
with_commerce="0"

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
    --with-commerce)
      with_commerce="1"
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

  if [[ "${with_commerce}" == "1" ]]; then
    section "Planned local Commerce fixtures"
    cat <<'EOF'
Only these local Commerce fixtures may be created or updated if active Commerce
config prerequisites exist:
- store: [Local Fixture] Store, mail=local.fixture.store@example.invalid, EUR
- gateway: local_fixture_manual, plugin=manual, mode=n/a
- product/variation SKU LOCAL-FIXTURE-COURS-ESSAI, type=cours_essai, price=20.00 EUR
- product/variation SKU LOCAL-FIXTURE-COURS-DEB-INTER, type=cours_deb_inter, price=40.00 EUR
- product/variation SKU LOCAL-FIXTURE-COURS-AVANCE, type=cours_avance, price=40.00 EUR
- product/variation SKU LOCAL-FIXTURE-PACK-4-DEB-INTER, type=pack_4_deb_inter, price=100.00 EUR

No orders, webform submissions, Google Calendar data, config/sync, Composer
files, or .ddev files are created or changed.
EOF
  else
    section "Commerce fixtures"
    cat <<'EOF'
Skipped by default. Re-run with --with-commerce to include guarded local
Commerce store, gateway, product, and variation fixtures.
EOF
  fi
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
  section "User fixture guards"

  local php
  php="$(cat <<'PHP'
$failed = FALSE;

$check = function (bool $ok, string $message) use (&$failed): void {
  echo ($ok ? 'OK' : 'FAIL') . ' ' . $message . PHP_EOL;
  $failed = $failed || !$ok;
};

$check(\Drupal::moduleHandler()->moduleExists('user'), 'module user is enabled');

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

if ($failed) {
  throw new \RuntimeException('User fixture guards failed.');
}
PHP
)"

  if ! run_drush_php_eval "${php}"; then
    warn "User fixture guards failed."
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
  echo "Fixture user phase did not create products, orders, webform submissions, Google Calendar data, config/sync, Composer files, or .ddev files.\n";
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

apply_or_plan_commerce_fixtures() {
  local apply_flag="0"
  local result_label="Dry-run Commerce result"
  if [[ "${mode}" == "apply" ]]; then
    apply_flag="1"
    result_label="Apply Commerce result"
  fi

  section "Commerce fixtures"

  local php
  php="$(cat <<'PHP'
$apply = getenv('LOCAL_FIXTURE_APPLY') === '1';
$blocked = FALSE;

$line = static function (string $status, string $message): void {
  echo $status . ' ' . $message . PHP_EOL;
};

$block = static function (string $message) use (&$blocked, $line): void {
  $line('BLOCKED', $message);
  $blocked = TRUE;
};

$format_value = static function ($value): string {
  if ($value === NULL || $value === '') {
    return 'NULL';
  }
  if (is_bool($value)) {
    return $value ? 'true' : 'false';
  }
  if (is_array($value)) {
    return json_encode($value, JSON_UNESCAPED_SLASHES);
  }
  return (string) $value;
};

$normalize_array = static function (array $data) use (&$normalize_array): array {
  foreach ($data as $key => $value) {
    if (is_array($value)) {
      $data[$key] = $normalize_array($value);
    }
  }
  ksort($data);
  return $data;
};

$entity_type_manager = \Drupal::entityTypeManager();

$fixtures = [
  [
    'sku' => 'LOCAL-FIXTURE-COURS-ESSAI',
    'product_type' => 'cours_essai',
    'variation_type' => 'cours_essai',
    'title' => '[Local Fixture] Cours essai',
    'price' => '20.00',
  ],
  [
    'sku' => 'LOCAL-FIXTURE-COURS-DEB-INTER',
    'product_type' => 'cours_deb_inter',
    'variation_type' => 'cours_deb_inter',
    'title' => '[Local Fixture] Cours debutant/intermediaire',
    'price' => '40.00',
  ],
  [
    'sku' => 'LOCAL-FIXTURE-COURS-AVANCE',
    'product_type' => 'cours_avance',
    'variation_type' => 'cours_avance',
    'title' => '[Local Fixture] Cours avance',
    'price' => '40.00',
  ],
  [
    'sku' => 'LOCAL-FIXTURE-PACK-4-DEB-INTER',
    'product_type' => 'pack_4_deb_inter',
    'variation_type' => 'pack_4_deb_inter',
    'title' => '[Local Fixture] Pack 4 debutant/intermediaire',
    'price' => '100.00',
  ],
];

$required_modules = [
  'commerce',
  'commerce_price',
  'commerce_store',
  'commerce_order',
  'commerce_payment',
  'commerce_product',
];

foreach ($required_modules as $module) {
  if (\Drupal::moduleHandler()->moduleExists($module)) {
    $line('OK', 'module ' . $module . ' is enabled');
  }
  else {
    $block('module ' . $module . ' is not enabled');
  }
}

foreach ([
  'commerce_store',
  'commerce_payment_gateway',
  'commerce_product',
  'commerce_product_variation',
  'commerce_product_type',
  'commerce_product_variation_type',
  'commerce_currency',
] as $entity_type_id) {
  if ($entity_type_manager->hasDefinition($entity_type_id)) {
    $line('OK', 'entity type ' . $entity_type_id . ' exists');
  }
  else {
    $block('entity type ' . $entity_type_id . ' is unavailable');
  }
}

if (!$blocked) {
  $currency_storage = $entity_type_manager->getStorage('commerce_currency');
  if ($currency_storage->load('EUR')) {
    $line('OK', 'commerce currency EUR exists');
  }
  else {
    $block('commerce currency EUR is missing; create/import only the EUR currency locally before Commerce fixture products');
  }

  $store_type_storage = $entity_type_manager->getStorage('commerce_store_type');
  if ($store_type_storage->load('online')) {
    $line('OK', 'commerce store type online exists');
  }
  else {
    $block('commerce store type online is missing; create/import only the online store type locally before Commerce fixture store creation');
  }

  $order_item_type_storage = $entity_type_manager->getStorage('commerce_order_item_type');
  if ($order_item_type_storage->load('default')) {
    $line('OK', 'commerce order item type default exists');
  }
  else {
    $block('commerce order item type default is missing; create/import only the default order item type locally before Commerce fixture variations');
  }

  $product_type_storage = $entity_type_manager->getStorage('commerce_product_type');
  $variation_type_storage = $entity_type_manager->getStorage('commerce_product_variation_type');

  foreach ($fixtures as $fixture) {
    $product_type = $product_type_storage->load($fixture['product_type']);
    if ($product_type) {
      $line('OK', 'product type ' . $fixture['product_type'] . ' exists');
      if (method_exists($product_type, 'getVariationTypeIds')) {
        $variation_type_ids = $product_type->getVariationTypeIds();
        if ($variation_type_ids && !in_array($fixture['variation_type'], $variation_type_ids, TRUE)) {
          $block('product type ' . $fixture['product_type'] . ' does not allow variation type ' . $fixture['variation_type']);
        }
        elseif ($variation_type_ids === []) {
          $line('NOTE', 'product type ' . $fixture['product_type'] . ' has no explicit variationTypes; fixture script will not modify product type config');
        }
      }
    }
    else {
      $block('product type ' . $fixture['product_type'] . ' is missing; create/import only required Commerce product type config locally before fixture products');
    }

    $variation_type = $variation_type_storage->load($fixture['variation_type']);
    if ($variation_type) {
      $line('OK', 'variation type ' . $fixture['variation_type'] . ' exists');
    }
    else {
      $block('variation type ' . $fixture['variation_type'] . ' is missing; create/import only required Commerce variation type config locally before fixture variations');
    }
  }

  $gateway_definitions = \Drupal::service('plugin.manager.commerce_payment_gateway')->getDefinitions();
  if (isset($gateway_definitions['manual'])) {
    $line('OK', 'manual payment gateway plugin is available');
  }
  else {
    $block('manual payment gateway plugin is unavailable');
  }
}

if ($blocked) {
  echo PHP_EOL . 'Commerce fixture phase blocked before creating stores, gateways, products, variations, orders, submissions, or config.' . PHP_EOL;
  echo 'Do not run full drush config:import for this fixture phase. Add only the missing local active Commerce prerequisites through a reviewed local bootstrap step, then rerun this script.' . PHP_EOL;
  echo 'LOCAL_FIXTURE_COMMERCE_BLOCKED=1' . PHP_EOL;
  return;
}

$store_storage = $entity_type_manager->getStorage('commerce_store');
$gateway_storage = $entity_type_manager->getStorage('commerce_payment_gateway');
$product_storage = $entity_type_manager->getStorage('commerce_product');
$variation_storage = $entity_type_manager->getStorage('commerce_product_variation');

$load_ids = static function ($storage, string $field, string $value): array {
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition($field, $value)
    ->execute();
  $ids = array_map('intval', array_values($ids));
  sort($ids);
  return $ids;
};

$fixture_store_name = '[Local Fixture] Store';
$fixture_store_mail = 'local.fixture.store@example.invalid';
$fixture_store_address = [
  'country_code' => 'FR',
  'address_line1' => '1 rue locale',
  'locality' => 'Paris',
  'postal_code' => '75000',
];
$fixture_gateway_id = 'local_fixture_manual';
$fixture_gateway_label = '[Local Fixture] Manual payment';
$fixture_gateway_configuration = [
  'display_label' => 'Local fixture manual payment',
  'mode' => 'n/a',
  'payment_method_types' => ['credit_card'],
  'collect_billing_information' => FALSE,
  'instructions' => [
    'value' => 'Local fixture manual payment. Do not use outside DDEV.',
    'format' => 'plain_text',
  ],
];

$created = 0;
$updated = 0;
$unchanged = 0;
$used_existing = 0;

// Store plan.
$fixture_store_ids_by_mail = $load_ids($store_storage, 'mail', $fixture_store_mail);
$fixture_store_ids_by_name = $load_ids($store_storage, 'name', $fixture_store_name);
$fixture_store_ids = array_values(array_unique(array_merge($fixture_store_ids_by_mail, $fixture_store_ids_by_name)));
sort($fixture_store_ids);

if (count($fixture_store_ids) > 1) {
  throw new \RuntimeException('Multiple stores match the local fixture store name/mail; refusing to guess.');
}

$all_store_ids = array_map('intval', array_values($store_storage->getQuery()->accessCheck(FALSE)->execute()));
sort($all_store_ids);

$fixture_store = $fixture_store_ids ? $store_storage->load(reset($fixture_store_ids)) : NULL;
$selected_store = NULL;
$selected_store_label = NULL;
$store_will_be_created = FALSE;

if ($fixture_store) {
  $changes = [];
  if ($fixture_store->getName() !== $fixture_store_name) {
    $changes[] = sprintf('name %s -> %s', $format_value($fixture_store->getName()), $fixture_store_name);
  }
  if ($fixture_store->get('mail')->value !== $fixture_store_mail) {
    $changes[] = sprintf('mail %s -> %s', $format_value($fixture_store->get('mail')->value), $fixture_store_mail);
  }
  if ($fixture_store->getDefaultCurrencyCode() !== 'EUR') {
    $changes[] = sprintf('default_currency %s -> EUR', $format_value($fixture_store->getDefaultCurrencyCode()));
  }
  if ($fixture_store->getTimezone() !== 'Europe/Paris') {
    $changes[] = sprintf('timezone %s -> Europe/Paris', $format_value($fixture_store->getTimezone()));
  }
  if (!$fixture_store->isPublished()) {
    $changes[] = 'status disabled -> enabled';
  }
  if (count($all_store_ids) === 1 && !$fixture_store->isDefault()) {
    $changes[] = 'default false -> true';
  }

  $current_address = [];
  if (!$fixture_store->get('address')->isEmpty()) {
    $current_address = array_intersect_key($fixture_store->get('address')->first()->getValue(), $fixture_store_address);
  }
  if ($normalize_array($current_address) !== $normalize_array($fixture_store_address)) {
    $changes[] = 'address -> local FR fixture address';
  }

  if ($changes === []) {
    $unchanged++;
    printf("Up-to-date fixture store id=%d name=%s\n", (int) $fixture_store->id(), $fixture_store_name);
  }
  else {
    $updated++;
    printf(
      "%s update fixture store id=%d: %s\n",
      $apply ? 'Will' : 'Would',
      (int) $fixture_store->id(),
      implode('; ', $changes)
    );
    if ($apply) {
      $fixture_store->setName($fixture_store_name);
      $fixture_store->setEmail($fixture_store_mail);
      $fixture_store->setDefaultCurrencyCode('EUR');
      $fixture_store->setTimezone('Europe/Paris');
      $fixture_store->set('address', $fixture_store_address);
      $fixture_store->setPublished();
      if (count($all_store_ids) === 1) {
        $fixture_store->setDefault(TRUE);
      }
      $fixture_store->save();
      printf("Updated fixture store id=%d\n", (int) $fixture_store->id());
    }
  }
  $selected_store = $fixture_store;
  $selected_store_label = sprintf('fixture store id=%d', (int) $fixture_store->id());
}
elseif ($all_store_ids !== []) {
  $default_store_ids = array_map('intval', array_values($store_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('is_default', TRUE)
    ->execute()));
  sort($default_store_ids);
  $selected_store_id = $default_store_ids ? reset($default_store_ids) : reset($all_store_ids);
  $selected_store = $store_storage->load($selected_store_id);
  if (!$selected_store) {
    throw new \RuntimeException('Could not load selected existing Commerce store.');
  }
  $used_existing++;
  $selected_store_label = sprintf('existing store id=%d name=%s', (int) $selected_store->id(), $selected_store->getName());
  printf("Use %s without store changes\n", $selected_store_label);
}
else {
  $created++;
  $store_will_be_created = TRUE;
  $selected_store_label = 'new fixture store [Local Fixture] Store';
  printf(
    "%s create fixture store name=%s mail=%s default_currency=EUR timezone=Europe/Paris address=FR local fixture address default=true\n",
    $apply ? 'Will' : 'Would',
    $fixture_store_name,
    $fixture_store_mail
  );
  if ($apply) {
    $fixture_store = $store_storage->create([
      'type' => 'online',
      'name' => $fixture_store_name,
      'mail' => $fixture_store_mail,
      'default_currency' => 'EUR',
      'timezone' => 'Europe/Paris',
      'address' => $fixture_store_address,
      'billing_countries' => ['FR'],
      'is_default' => TRUE,
      'status' => TRUE,
    ]);
    $fixture_store->save();
    $selected_store = $fixture_store;
    $selected_store_label = sprintf('fixture store id=%d', (int) $fixture_store->id());
    printf("Created fixture store id=%d\n", (int) $fixture_store->id());
  }
}

// Payment gateway plan.
$gateway = $gateway_storage->load($fixture_gateway_id);
if (!$gateway) {
  $created++;
  printf(
    "%s create payment gateway id=%s label=%s plugin=manual mode=n/a status=enabled\n",
    $apply ? 'Will' : 'Would',
    $fixture_gateway_id,
    $fixture_gateway_label
  );
  if ($apply) {
    $gateway = $gateway_storage->create([
      'id' => $fixture_gateway_id,
      'label' => $fixture_gateway_label,
      'plugin' => 'manual',
      'status' => TRUE,
      'configuration' => $fixture_gateway_configuration,
      'conditions' => [],
      'conditionOperator' => 'AND',
    ]);
    $gateway->save();
    printf("Created payment gateway id=%s\n", $fixture_gateway_id);
  }
}
else {
  $changes = [];
  if ($gateway->label() !== $fixture_gateway_label) {
    $changes[] = sprintf('label %s -> %s', $format_value($gateway->label()), $fixture_gateway_label);
  }
  if ($gateway->getPluginId() !== 'manual') {
    $changes[] = sprintf('plugin %s -> manual', $format_value($gateway->getPluginId()));
  }
  if (!$gateway->status()) {
    $changes[] = 'status disabled -> enabled';
  }
  if ($normalize_array($gateway->getPluginConfiguration()) !== $normalize_array($fixture_gateway_configuration)) {
    $changes[] = 'configuration -> local manual fixture configuration';
  }
  if ($gateway->getConditionOperator() !== 'AND') {
    $changes[] = sprintf('conditionOperator %s -> AND', $format_value($gateway->getConditionOperator()));
  }
  $raw_gateway = $gateway->toArray();
  if (($raw_gateway['conditions'] ?? []) !== []) {
    $changes[] = 'conditions -> none';
  }

  if ($changes === []) {
    $unchanged++;
    printf("Up-to-date payment gateway id=%s\n", $fixture_gateway_id);
  }
  else {
    $updated++;
    printf(
      "%s update payment gateway id=%s: %s\n",
      $apply ? 'Will' : 'Would',
      $fixture_gateway_id,
      implode('; ', $changes)
    );
    if ($apply) {
      $gateway->set('label', $fixture_gateway_label);
      $gateway->setPluginId('manual');
      $gateway->setPluginConfiguration($fixture_gateway_configuration);
      $gateway->set('conditions', []);
      $gateway->setConditionOperator('AND');
      $gateway->enable();
      $gateway->save();
      printf("Updated payment gateway id=%s\n", $fixture_gateway_id);
    }
  }
}

// Product and variation plans.
foreach ($fixtures as $fixture) {
  if (!str_starts_with($fixture['sku'], 'LOCAL-FIXTURE-')) {
    throw new \RuntimeException(sprintf('Refusing non-local fixture SKU %s.', $fixture['sku']));
  }
  if (!str_starts_with($fixture['title'], '[Local Fixture] ')) {
    throw new \RuntimeException(sprintf('Refusing non-local fixture product title %s.', $fixture['title']));
  }

  $sku_ids = $variation_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('sku', $fixture['sku'])
    ->execute();
  $sku_ids = array_map('intval', array_values($sku_ids));
  sort($sku_ids);
  if (count($sku_ids) > 1) {
    throw new \RuntimeException(sprintf('Multiple variations have fixture SKU %s.', $fixture['sku']));
  }

  $title_ids = $product_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('title', $fixture['title'])
    ->execute();
  $title_ids = array_map('intval', array_values($title_ids));
  sort($title_ids);
  if (count($title_ids) > 1) {
    throw new \RuntimeException(sprintf('Multiple products have fixture title %s.', $fixture['title']));
  }

  $variation = $sku_ids ? $variation_storage->load(reset($sku_ids)) : NULL;
  $product = NULL;

  if ($variation) {
    if ($variation->bundle() !== $fixture['variation_type']) {
      throw new \RuntimeException(sprintf('Fixture SKU %s exists with variation type %s, expected %s.', $fixture['sku'], $variation->bundle(), $fixture['variation_type']));
    }
    $product_id = (int) $variation->getProductId();
    if ($product_id) {
      $product = $product_storage->load($product_id);
      if (!$product) {
        throw new \RuntimeException(sprintf('Fixture SKU %s references missing product id %d.', $fixture['sku'], $product_id));
      }
    }
  }

  if ($title_ids) {
    $title_product = $product_storage->load(reset($title_ids));
    if ($product && $title_product && (int) $product->id() !== (int) $title_product->id()) {
      throw new \RuntimeException(sprintf('Fixture SKU %s and title %s point to different products.', $fixture['sku'], $fixture['title']));
    }
    $product = $product ?: $title_product;
  }

  if ($product && $product->bundle() !== $fixture['product_type']) {
    throw new \RuntimeException(sprintf('Fixture product %s exists with product type %s, expected %s.', $fixture['title'], $product->bundle(), $fixture['product_type']));
  }

  $desired_price = new \Drupal\commerce_price\Price($fixture['price'], 'EUR');

  if (!$variation && !$product) {
    $created++;
    printf(
      "%s create product title=%s type=%s with variation sku=%s variation_type=%s price=%s EUR store=%s\n",
      $apply ? 'Will' : 'Would',
      $fixture['title'],
      $fixture['product_type'],
      $fixture['sku'],
      $fixture['variation_type'],
      $fixture['price'],
      $selected_store_label
    );

    if ($apply) {
      if (!$selected_store) {
        throw new \RuntimeException('No selected store was available for product creation.');
      }
      $variation = $variation_storage->create([
        'type' => $fixture['variation_type'],
        'sku' => $fixture['sku'],
        'status' => TRUE,
        'price' => $desired_price->toArray(),
      ]);
      $variation->save();

      $product = $product_storage->create([
        'type' => $fixture['product_type'],
        'title' => $fixture['title'],
        'stores' => [(int) $selected_store->id()],
        'variations' => [(int) $variation->id()],
        'status' => TRUE,
      ]);
      $product->save();
      printf(
        "Created product id=%d variation id=%d sku=%s\n",
        (int) $product->id(),
        (int) $variation->id(),
        $fixture['sku']
      );
    }
    continue;
  }

  if (!$variation) {
    $created++;
    printf(
      "%s create variation sku=%s variation_type=%s price=%s EUR for product id=%d\n",
      $apply ? 'Will' : 'Would',
      $fixture['sku'],
      $fixture['variation_type'],
      $fixture['price'],
      (int) $product->id()
    );

    if ($apply) {
      $variation = $variation_storage->create([
        'type' => $fixture['variation_type'],
        'sku' => $fixture['sku'],
        'status' => TRUE,
        'price' => $desired_price->toArray(),
      ]);
      $variation->save();
      printf("Created variation id=%d sku=%s\n", (int) $variation->id(), $fixture['sku']);
    }
  }

  $product_changes = [];
  if ($product->getTitle() !== $fixture['title']) {
    $product_changes[] = sprintf('title %s -> %s', $format_value($product->getTitle()), $fixture['title']);
  }
  if (!$product->isPublished()) {
    $product_changes[] = 'status unpublished -> published';
  }

  $store_ids = array_map('intval', $product->getStoreIds());
  $selected_store_id = $selected_store ? (int) $selected_store->id() : NULL;
  if ($selected_store_id && !in_array($selected_store_id, $store_ids, TRUE)) {
    $product_changes[] = sprintf('stores add %d', $selected_store_id);
  }
  elseif (!$selected_store_id && $store_will_be_created && $store_ids === []) {
    $product_changes[] = 'stores add new fixture store';
  }

  $variation_id = $variation ? (int) $variation->id() : NULL;
  $product_variation_ids = array_map('intval', $product->getVariationIds());
  if ($variation_id && !in_array($variation_id, $product_variation_ids, TRUE)) {
    $product_changes[] = sprintf('variations add %d', $variation_id);
  }

  $variation_changes = [];
  if ($variation) {
    $current_price = $variation->getPrice();
    if (!$current_price || !$current_price->equals($desired_price)) {
      $variation_changes[] = sprintf('price %s -> %s EUR', $current_price ? (string) $current_price : 'NULL', $fixture['price']);
    }
    if (!$variation->isPublished()) {
      $variation_changes[] = 'status unpublished -> published';
    }
    $variation_product_id = (int) $variation->getProductId();
    if ($variation_product_id && $product && $variation_product_id !== (int) $product->id()) {
      throw new \RuntimeException(sprintf('Fixture SKU %s belongs to product id %d, expected %d.', $fixture['sku'], $variation_product_id, (int) $product->id()));
    }
  }

  if ($product_changes === [] && $variation_changes === []) {
    $unchanged++;
    printf("Up-to-date product id=%d variation sku=%s\n", (int) $product->id(), $fixture['sku']);
    continue;
  }

  if ($product_changes !== []) {
    $updated++;
    printf(
      "%s update product id=%d title=%s: %s\n",
      $apply ? 'Will' : 'Would',
      (int) $product->id(),
      $fixture['title'],
      implode('; ', $product_changes)
    );
  }
  if ($variation_changes !== []) {
    $updated++;
    printf(
      "%s update variation id=%d sku=%s: %s\n",
      $apply ? 'Will' : 'Would',
      (int) $variation->id(),
      $fixture['sku'],
      implode('; ', $variation_changes)
    );
  }

  if ($apply) {
    if ($product_changes !== []) {
      $product->setTitle($fixture['title']);
      $product->setPublished();
      if ($selected_store) {
        $store_ids = array_values(array_unique(array_merge($store_ids, [(int) $selected_store->id()])));
        $product->setStoreIds($store_ids);
      }
      if ($variation && !$product->hasVariation($variation)) {
        $product->addVariation($variation);
      }
      $product->save();
      printf("Updated product id=%d\n", (int) $product->id());
    }
    if ($variation_changes !== []) {
      $variation->setPrice($desired_price);
      $variation->setPublished();
      $variation->save();
      printf("Updated variation id=%d sku=%s\n", (int) $variation->id(), $fixture['sku']);
    }
  }
}

printf(
  "%s Commerce fixture phase complete. created=%d updated=%d unchanged=%d used_existing=%d\n",
  $apply ? 'Apply' : 'Dry-run',
  $created,
  $updated,
  $unchanged,
  $used_existing
);

if (!$apply) {
  echo "No data was changed.\n";
}
else {
  echo "No orders, webform submissions, Google Calendar data, config/sync, Composer files, or .ddev files were changed by the Commerce fixture phase.\n";
}
PHP
)"

  local escaped_php="${php//\$/\\$}"
  local output
  if ! output="$(ddev exec env LOCAL_FIXTURE_APPLY="${apply_flag}" "${DRUSH}" php:eval "${escaped_php}" 2>&1)"; then
    printf '%s\n' "${output}"
    exit 1
  fi

  local commerce_blocked=0
  local output_line
  while IFS= read -r output_line; do
    if [[ "${output_line}" == "LOCAL_FIXTURE_COMMERCE_BLOCKED=1" ]]; then
      commerce_blocked=1
      continue
    fi
    printf '%s\n' "${output_line}"
  done <<< "${output}"

  if [[ "${commerce_blocked}" -eq 1 ]]; then
    section "${result_label}"
    if [[ "${mode}" == "dry-run" ]]; then
      log "Commerce fixture prerequisites are missing. No data was changed."
      return 0
    fi

    warn "Commerce fixture phase blocked by missing active Commerce prerequisites."
    exit 1
  fi

  section "${result_label}"
  if [[ "${mode}" == "dry-run" ]]; then
    log "Commerce fixture dry-run completed. No Commerce data was changed."
  else
    log "Commerce fixture store, gateway, products, and variations were created or updated as needed."
  fi
}

cd "${DRUPAL_DIR}"

log "Mode: ${mode}"
if [[ "${with_commerce}" == "1" ]]; then
  log "Commerce fixtures: enabled"
else
  log "Commerce fixtures: skipped; use --with-commerce to include them."
fi
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
if [[ "${with_commerce}" == "1" ]]; then
  apply_or_plan_commerce_fixtures
else
  section "Commerce fixtures"
  log "Skipped. Re-run with --with-commerce to include guarded Commerce fixtures."
fi
