#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRUPAL_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
DRUSH="./vendor/bin/drush"

MODE="dry-run"
REQUESTED_MODE=""
CONTAINER_SCRIPT="/tmp/test-local-commerce-credit-flow.php"

log() {
  printf '[test-local-commerce-credit-flow] %s\n' "$*"
}

warn() {
  printf '[test-local-commerce-credit-flow] WARNING: %s\n' "$*" >&2
}

section() {
  printf '\n== %s ==\n' "$*"
}

usage() {
  cat <<'EOF'
Usage: ./scripts/test-local-commerce-credit-flow.sh [--dry-run|--run]

Runs local-only DDEV checks for Commerce order -> course credit attribution
using existing local.fixture.* users and LOCAL-FIXTURE-* products.

Options:
  --dry-run  Verify local prerequisites and print the scenarios. Default.
  --run      Create local fixture orders/payments, assert credits, then clean up.
  -h, --help Show this help.
EOF
}

for arg in "$@"; do
  case "${arg}" in
    --dry-run)
      if [[ "${REQUESTED_MODE}" == "run" ]]; then
        warn "Use either --dry-run or --run, not both."
        usage
        exit 2
      fi
      REQUESTED_MODE="dry-run"
      MODE="dry-run"
      ;;
    --run)
      if [[ "${REQUESTED_MODE}" == "dry-run" ]]; then
        warn "Use either --dry-run or --run, not both."
        usage
        exit 2
      fi
      REQUESTED_MODE="run"
      MODE="run"
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
    warn "vendor/bin/drush is missing. Run Composer install inside DDEV before local credit-flow tests."
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
Commerce credit-flow tests require an installed local Drupal database with
fixture users and Commerce fixture products. No data was changed.
EOF
    exit 1
  fi

  log "Drupal table key_value exists."
}

require_bootstrap() {
  section "Drupal bootstrap"

  ddev exec "${DRUSH}" php:eval 'echo "Drupal bootstrap OK: " . \Drupal::VERSION . PHP_EOL;'
}

print_plan() {
  section "Dry-run scenario plan"
  cat <<'EOF'
The script will use only existing local fixtures:
- user: local.fixture.checkout
- SKUs:
  - LOCAL-FIXTURE-COURS-ESSAI
  - LOCAL-FIXTURE-COURS-DEB-INTER
  - LOCAL-FIXTURE-COURS-AVANCE
  - LOCAL-FIXTURE-PACK-4-DEB-INTER
- gateway: local_fixture_manual

Run scenarios:
1. LOCAL-FIXTURE-COURS-ESSAI quantity 1, paid/completed -> +1 credit, trial used.
2. LOCAL-FIXTURE-COURS-ESSAI quantity 2, paid/completed -> capped at +1 credit.
3. LOCAL-FIXTURE-COURS-DEB-INTER quantity 2, paid/completed -> +2 credits.
4. LOCAL-FIXTURE-COURS-AVANCE quantity 1, paid/completed -> +1 credit.
5. LOCAL-FIXTURE-PACK-4-DEB-INTER quantity 1, paid/completed -> +4 credits and pack expiry around +6 months.
6. LOCAL-FIXTURE-COURS-DEB-INTER quantity 1, completed but unpaid -> no credits.

--dry-run verifies prerequisites only and changes nothing.
--run resets only local.fixture.checkout credit fields before each scenario,
creates temporary local Commerce orders/payments, routes mail to Drupal's test
mail collector, asserts user fields, cleans up test orders/payments/items, and
does not create webform submissions or call Google Calendar.
EOF
}

write_container_script() {
  ddev exec bash -lc "cat > ${CONTAINER_SCRIPT}" <<'PHP'
<?php

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;

$mode = getenv('LOCAL_COMMERCE_CREDIT_FLOW_MODE') ?: 'dry-run';
$is_run = $mode === 'run';
$failed = FALSE;
$run_id = 'local-credit-flow-' . date('Ymd-His') . '-' . substr(hash('sha256', random_bytes(16)), 0, 8);
$created = [
  'commerce_payment' => [],
  'commerce_order' => [],
  'commerce_order_item' => [],
];
$mail_original_interface = NULL;
$mail_was_rerouted = FALSE;
$mail_credit_messages = 0;
$mail_total_messages = 0;
$initial_webform_submission_count = NULL;
$initial_google_sync_count = NULL;

$section = static function (string $title): void {
  echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
};

$line = static function (string $status, string $message): void {
  echo $status . ' ' . $message . PHP_EOL;
};

$check = static function (bool $ok, string $message) use (&$failed, $line): void {
  $line($ok ? 'OK' : 'FAIL', $message);
  $failed = $failed || !$ok;
};

$mark_fail = static function (string $message) use (&$failed, $line): void {
  $line('FAIL', $message);
  $failed = TRUE;
};

$format_value = static function ($value): string {
  if ($value === NULL || $value === '') {
    return 'NULL';
  }
  if (is_bool($value)) {
    return $value ? 'true' : 'false';
  }
  if ($value instanceof \DateTimeInterface) {
    return $value->format('Y-m-d');
  }
  if (is_array($value)) {
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }
  return (string) $value;
};

$entity_type_manager = \Drupal::entityTypeManager();

$required_modules = [
  'user',
  'commerce',
  'commerce_price',
  'commerce_store',
  'commerce_order',
  'commerce_payment',
  'commerce_product',
  'unisonges_structure',
];

$required_entity_types = [
  'user',
  'commerce_store',
  'commerce_payment',
  'commerce_payment_gateway',
  'commerce_order',
  'commerce_order_item',
  'commerce_product',
  'commerce_product_variation',
];

$fixture_usernames = [
  'local.fixture.no_credit',
  'local.fixture.with_credit',
  'local.fixture.checkout',
  'local.fixture.trial_used',
  'local.fixture.pack_active',
];

$fixture_skus = [
  'LOCAL-FIXTURE-COURS-ESSAI' => [
    'product_type' => 'cours_essai',
    'variation_type' => 'cours_essai',
  ],
  'LOCAL-FIXTURE-COURS-DEB-INTER' => [
    'product_type' => 'cours_deb_inter',
    'variation_type' => 'cours_deb_inter',
  ],
  'LOCAL-FIXTURE-COURS-AVANCE' => [
    'product_type' => 'cours_avance',
    'variation_type' => 'cours_avance',
  ],
  'LOCAL-FIXTURE-PACK-4-DEB-INTER' => [
    'product_type' => 'pack_4_deb_inter',
    'variation_type' => 'pack_4_deb_inter',
  ],
];

$scenarios = [
  [
    'id' => 'trial_single',
    'label' => 'trial course quantity 1 grants one credit and marks trial used',
    'sku' => 'LOCAL-FIXTURE-COURS-ESSAI',
    'quantity' => '1',
    'paid' => TRUE,
    'expected_remaining' => 1,
    'expected_trial' => 1,
    'expected_expiry' => 'empty',
  ],
  [
    'id' => 'trial_quantity_cap',
    'label' => 'trial course quantity 2 is capped at one credit',
    'sku' => 'LOCAL-FIXTURE-COURS-ESSAI',
    'quantity' => '2',
    'paid' => TRUE,
    'expected_remaining' => 1,
    'expected_trial' => 1,
    'expected_expiry' => 'empty',
    'quantity_cap_optional' => TRUE,
  ],
  [
    'id' => 'deb_inter_quantity_two',
    'label' => 'beginner/intermediate course quantity 2 grants two credits',
    'sku' => 'LOCAL-FIXTURE-COURS-DEB-INTER',
    'quantity' => '2',
    'paid' => TRUE,
    'expected_remaining' => 2,
    'expected_trial' => 0,
    'expected_expiry' => 'empty',
  ],
  [
    'id' => 'avance_single',
    'label' => 'advanced course quantity 1 grants one credit',
    'sku' => 'LOCAL-FIXTURE-COURS-AVANCE',
    'quantity' => '1',
    'paid' => TRUE,
    'expected_remaining' => 1,
    'expected_trial' => 0,
    'expected_expiry' => 'empty',
  ],
  [
    'id' => 'pack_four',
    'label' => 'pack quantity 1 grants four credits and future expiry',
    'sku' => 'LOCAL-FIXTURE-PACK-4-DEB-INTER',
    'quantity' => '1',
    'paid' => TRUE,
    'expected_remaining' => 4,
    'expected_trial' => 0,
    'expected_expiry' => 'future_around_six_months',
  ],
  [
    'id' => 'pending_unpaid',
    'label' => 'completed unpaid manual-style order grants no credits',
    'sku' => 'LOCAL-FIXTURE-COURS-DEB-INTER',
    'quantity' => '1',
    'paid' => FALSE,
    'expected_remaining' => 0,
    'expected_trial' => 0,
    'expected_expiry' => 'empty',
  ],
];

$load_single_id = static function ($storage, string $field, string $value): array {
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition($field, $value)
    ->execute();
  $ids = array_map('intval', array_values($ids));
  sort($ids);
  return $ids;
};

$load_fixture_user = static function (string $username) use ($entity_type_manager, $load_single_id): \Drupal\user\UserInterface {
  $user_storage = $entity_type_manager->getStorage('user');
  $ids = $load_single_id($user_storage, 'name', $username);
  if (count($ids) !== 1) {
    throw new \RuntimeException(sprintf('Expected exactly one fixture user %s, found %d.', $username, count($ids)));
  }
  $uid = reset($ids);
  if ((int) $uid === 1) {
    throw new \RuntimeException(sprintf('Refusing to use uid=1 for fixture user %s.', $username));
  }
  $user = $user_storage->load($uid);
  if (!$user) {
    throw new \RuntimeException(sprintf('Could not load fixture user %s.', $username));
  }
  if (!str_starts_with($user->getAccountName(), 'local.fixture.')) {
    throw new \RuntimeException(sprintf('Refusing non-local fixture username %s.', $user->getAccountName()));
  }
  foreach (['field_seances_restantes', 'field_essai_utilise', 'field_pack_expire_le'] as $field_name) {
    if (!$user->hasField($field_name)) {
      throw new \RuntimeException(sprintf('Fixture user %s is missing field %s.', $username, $field_name));
    }
  }
  return $user;
};

$load_fixture_variation = static function (string $sku) use ($entity_type_manager, $load_single_id): \Drupal\commerce_product\Entity\ProductVariationInterface {
  if (!str_starts_with($sku, 'LOCAL-FIXTURE-')) {
    throw new \RuntimeException(sprintf('Refusing non-local fixture SKU %s.', $sku));
  }
  $variation_storage = $entity_type_manager->getStorage('commerce_product_variation');
  $ids = $load_single_id($variation_storage, 'sku', $sku);
  if (count($ids) !== 1) {
    throw new \RuntimeException(sprintf('Expected exactly one fixture variation SKU %s, found %d.', $sku, count($ids)));
  }
  $variation = $variation_storage->load(reset($ids));
  if (!$variation) {
    throw new \RuntimeException(sprintf('Could not load fixture variation SKU %s.', $sku));
  }
  return $variation;
};

$user_snapshot = static function (\Drupal\user\UserInterface $user): array {
  return [
    'remaining' => (int) ($user->get('field_seances_restantes')->value ?? 0),
    'trial' => (int) ($user->get('field_essai_utilise')->value ?? 0),
    'expiry' => $user->get('field_pack_expire_le')->value ?: NULL,
  ];
};

$reset_checkout_user = static function () use ($load_fixture_user, $line): \Drupal\user\UserInterface {
  $user = $load_fixture_user('local.fixture.checkout');
  $user->set('field_seances_restantes', 0);
  $user->set('field_essai_utilise', 0);
  $user->set('field_pack_expire_le', NULL);
  $user->save();
  $line('RESET', 'local.fixture.checkout credit fields -> remaining=0 trial=0 expiry=NULL');
  return $user;
};

$select_store_for_variation = static function (\Drupal\commerce_product\Entity\ProductVariationInterface $variation) use ($entity_type_manager): \Drupal\commerce_store\Entity\StoreInterface {
  $store_storage = $entity_type_manager->getStorage('commerce_store');
  $product = $variation->getProduct();
  if ($product) {
    $store_ids = array_map('intval', $product->getStoreIds());
    $store_ids = array_values(array_filter($store_ids));
    if ($store_ids) {
      $store = $store_storage->load(reset($store_ids));
      if ($store) {
        return $store;
      }
    }
  }

  $default_store_ids = $store_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('is_default', TRUE)
    ->range(0, 1)
    ->execute();
  if ($default_store_ids) {
    $store = $store_storage->load(reset($default_store_ids));
    if ($store) {
      return $store;
    }
  }

  $store_ids = $store_storage->getQuery()
    ->accessCheck(FALSE)
    ->range(0, 1)
    ->execute();
  if ($store_ids) {
    $store = $store_storage->load(reset($store_ids));
    if ($store) {
      return $store;
    }
  }

  throw new \RuntimeException('No Commerce store is available for fixture order creation.');
};

$create_order = static function (array $scenario, \Drupal\user\UserInterface $user) use ($entity_type_manager, $load_fixture_variation, $select_store_for_variation, &$created, $run_id): array {
  $variation = $load_fixture_variation($scenario['sku']);
  $product = $variation->getProduct();
  if (!$product) {
    throw new \RuntimeException(sprintf('Fixture SKU %s has no parent product.', $scenario['sku']));
  }
  $price = $variation->getPrice();
  if (!$price) {
    throw new \RuntimeException(sprintf('Fixture SKU %s has no price.', $scenario['sku']));
  }
  $store = $select_store_for_variation($variation);
  $quantity = (string) $scenario['quantity'];

  $order_item_storage = $entity_type_manager->getStorage('commerce_order_item');
  $order_storage = $entity_type_manager->getStorage('commerce_order');

  $order_item = $order_item_storage->create([
    'type' => 'default',
    'purchased_entity' => $variation,
    'quantity' => $quantity,
    'unit_price' => $price->toArray(),
    'title' => $variation->label(),
  ]);
  $order_item->save();
  $created['commerce_order_item'][] = (int) $order_item->id();

  $order = $order_storage->create([
    'type' => 'default',
    'store_id' => (int) $store->id(),
    'uid' => (int) $user->id(),
    'mail' => $user->getEmail(),
    'ip_address' => '127.0.0.1',
    'order_items' => [$order_item],
    'state' => 'draft',
  ]);
  $order->setData('local_fixture_credit_flow', [
    'run_id' => $run_id,
    'scenario' => $scenario['id'],
  ]);
  $order->setRefreshState(OrderInterface::REFRESH_SKIP);
  $order->save();
  $created['commerce_order'][] = (int) $order->id();

  return [$order, $order_item, $variation, $product, $store];
};

$complete_order = static function (\Drupal\commerce_order\Entity\OrderInterface $order): void {
  $order->setRefreshState(OrderInterface::REFRESH_SKIP);
  if ($order->getState()->isTransitionAllowed('place')) {
    $order->getState()->applyTransitionById('place');
  }
  else {
    $order->set('state', 'completed');
  }
  $order->save();
};

$pay_order = static function (\Drupal\commerce_order\Entity\OrderInterface $order, string $scenario_id) use ($entity_type_manager, &$created, $run_id): \Drupal\commerce_payment\Entity\PaymentInterface {
  $gateway = $entity_type_manager->getStorage('commerce_payment_gateway')->load('local_fixture_manual');
  if (!$gateway) {
    throw new \RuntimeException('Payment gateway local_fixture_manual is missing.');
  }
  $total = $order->getTotalPrice();
  if (!$total instanceof Price) {
    throw new \RuntimeException(sprintf('Order %s has no total price.', $order->id()));
  }

  $payment = $entity_type_manager->getStorage('commerce_payment')->create([
    'type' => 'payment_manual',
    'payment_gateway' => $gateway,
    'order_id' => (int) $order->id(),
    'amount' => $total,
    'state' => 'completed',
    'remote_id' => $run_id . ':' . $scenario_id,
    'remote_state' => 'local_fixture_completed',
    'test' => TRUE,
  ]);
  $payment->save();
  $created['commerce_payment'][] = (int) $payment->id();

  \Drupal::service('commerce_payment.order_updater')->updateOrder($order, TRUE);
  $reloaded = $entity_type_manager->getStorage('commerce_order')->load((int) $order->id());
  if (!$reloaded || !$reloaded->isPaid()) {
    throw new \RuntimeException(sprintf('Order %s was not paid after local completed payment.', $order->id()));
  }

  return $payment;
};

$cleanup_created_entities = static function () use ($entity_type_manager, &$created, $line): void {
  foreach (['commerce_payment', 'commerce_order', 'commerce_order_item'] as $entity_type_id) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $created[$entity_type_id] ?? []))));
    if ($ids === []) {
      continue;
    }
    $storage = $entity_type_manager->getStorage($entity_type_id);
    $entities = $storage->loadMultiple($ids);
    if ($entities) {
      $storage->delete($entities);
      $line('CLEANUP', sprintf('deleted %d %s test entit%s', count($entities), $entity_type_id, count($entities) === 1 ? 'y' : 'ies'));
    }
    $created[$entity_type_id] = [];
  }
};

$count_entities = static function (string $entity_type_id) use ($entity_type_manager): ?int {
  if (!$entity_type_manager->hasDefinition($entity_type_id)) {
    return NULL;
  }
  return (int) $entity_type_manager->getStorage($entity_type_id)
    ->getQuery()
    ->accessCheck(FALSE)
    ->count()
    ->execute();
};

$count_google_sync_rows = static function (): ?int {
  $database = \Drupal::database();
  if (!$database->schema()->tableExists('unisonges_structure_booking_gcal_sync')) {
    return NULL;
  }
  return (int) $database->select('unisonges_structure_booking_gcal_sync', 'sync')
    ->countQuery()
    ->execute()
    ->fetchField();
};

$reroute_mail = static function () use (&$mail_original_interface, &$mail_was_rerouted, $line): void {
  $definitions = \Drupal::service('plugin.manager.mail')->getDefinitions();
  if (!isset($definitions['test_mail_collector'])) {
    $line('MAIL', 'test_mail_collector plugin is unavailable; credit mail will be reported from logs/config only.');
    return;
  }
  $mail_config = \Drupal::configFactory()->getEditable('system.mail');
  $mail_original_interface = $mail_config->get('interface');
  \Drupal::state()->set('system.test_mail_collector', []);
  $mail_config->set('interface.default', 'test_mail_collector')->save();
  \Drupal::configFactory()->reset('system.mail');
  $mail_was_rerouted = TRUE;
  $line('MAIL', 'routing mail through Drupal test_mail_collector for this local run');
};

$restore_mail = static function () use (&$mail_original_interface, &$mail_was_rerouted, &$mail_credit_messages, &$mail_total_messages, $line): void {
  if ($mail_was_rerouted) {
    $captured = \Drupal::state()->get('system.test_mail_collector', []);
    $mail_total_messages = is_array($captured) ? count($captured) : 0;
    if (is_array($captured)) {
      foreach ($captured as $message) {
        if (($message['module'] ?? '') === 'unisonges_structure' && ($message['key'] ?? '') === 'course_rights_applied') {
          $mail_credit_messages++;
        }
      }
    }

    $mail_config = \Drupal::configFactory()->getEditable('system.mail');
    if (is_array($mail_original_interface)) {
      $mail_config->set('interface', $mail_original_interface)->save();
    }
    else {
      $mail_config->clear('interface')->save();
    }
    \Drupal::configFactory()->reset('system.mail');
    \Drupal::state()->delete('system.test_mail_collector');
    $line('MAIL', sprintf('captured=%d credit_messages=%d; restored original system.mail interface', $mail_total_messages, $mail_credit_messages));
  }
};

$assert_user_fields = static function (array $scenario, \Drupal\user\UserInterface $user, \Drupal\commerce_order\Entity\OrderInterface $order) use ($entity_type_manager, $user_snapshot, $check): void {
  $account = $entity_type_manager->getStorage('user')->load((int) $user->id());
  if (!$account) {
    throw new \RuntimeException(sprintf('Could not reload user %s.', $user->id()));
  }
  $snapshot = $user_snapshot($account);
  $check($snapshot['remaining'] === $scenario['expected_remaining'], sprintf('%s field_seances_restantes expected %d got %d', $scenario['id'], $scenario['expected_remaining'], $snapshot['remaining']));
  $check($snapshot['trial'] === $scenario['expected_trial'], sprintf('%s field_essai_utilise expected %d got %d', $scenario['id'], $scenario['expected_trial'], $snapshot['trial']));

  if ($scenario['expected_expiry'] === 'empty') {
    $check($snapshot['expiry'] === NULL, sprintf('%s field_pack_expire_le expected NULL got %s', $scenario['id'], $snapshot['expiry'] ?? 'NULL'));
  }
  elseif ($scenario['expected_expiry'] === 'future_around_six_months') {
    $expiry = $snapshot['expiry'] ? new \DateTimeImmutable($snapshot['expiry']) : NULL;
    $today = new \DateTimeImmutable('today');
    $min = $today->modify('+5 months');
    $max = $today->modify('+7 months');
    $check($expiry instanceof \DateTimeImmutable, sprintf('%s field_pack_expire_le is set', $scenario['id']));
    if ($expiry instanceof \DateTimeImmutable) {
      $check($expiry > $today, sprintf('%s field_pack_expire_le is in the future: %s', $scenario['id'], $expiry->format('Y-m-d')));
      $check($expiry >= $min && $expiry <= $max, sprintf('%s field_pack_expire_le is around +6 months: %s', $scenario['id'], $expiry->format('Y-m-d')));
    }
  }

  $applied = (bool) $order->getData('unisonges_course_rights_applied');
  $check($applied === (bool) $scenario['paid'], sprintf('%s unisonges_course_rights_applied expected %s got %s', $scenario['id'], $scenario['paid'] ? 'true' : 'false', $applied ? 'true' : 'false'));
};

$section('Mode');
echo 'Mode: ' . $mode . PHP_EOL;
echo 'Run id: ' . $run_id . PHP_EOL;

try {
  $section('Drupal and module readiness');
  foreach ($required_modules as $module) {
    $check(\Drupal::moduleHandler()->moduleExists($module), 'module ' . $module . ' is enabled');
  }
  foreach ($required_entity_types as $entity_type_id) {
    $check($entity_type_manager->hasDefinition($entity_type_id), 'entity type ' . $entity_type_id . ' exists');
  }

  $section('Fixture users');
  foreach ($fixture_usernames as $username) {
    try {
      $user = $load_fixture_user($username);
      $snapshot = $user_snapshot($user);
      $line('OK', sprintf('%s uid=%d remaining=%d trial=%d expiry=%s', $username, (int) $user->id(), $snapshot['remaining'], $snapshot['trial'], $format_value($snapshot['expiry'])));
    }
    catch (\Throwable $throwable) {
      $mark_fail($throwable->getMessage());
    }
  }

  $section('Fixture Commerce entities');
  foreach ($fixture_skus as $sku => $expected) {
    try {
      $variation = $load_fixture_variation($sku);
      $product = $variation->getProduct();
      $check($variation->bundle() === $expected['variation_type'], sprintf('%s variation type is %s', $sku, $expected['variation_type']));
      $check($product && $product->bundle() === $expected['product_type'], sprintf('%s product type is %s', $sku, $expected['product_type']));
      $check((bool) $variation->getPrice(), sprintf('%s has a price', $sku));
      $line('OK', sprintf('%s variation_id=%d product_id=%s price=%s', $sku, (int) $variation->id(), $product ? (int) $product->id() : 'NULL', $variation->getPrice() ? (string) $variation->getPrice() : 'NULL'));
    }
    catch (\Throwable $throwable) {
      $mark_fail($throwable->getMessage());
    }
  }

  $gateway = $entity_type_manager->getStorage('commerce_payment_gateway')->load('local_fixture_manual');
  $check((bool) $gateway, 'payment gateway local_fixture_manual exists');
  if ($gateway) {
    $check($gateway->getPluginId() === 'manual', 'payment gateway local_fixture_manual uses manual plugin');
  }
  $check((bool) $entity_type_manager->getStorage('commerce_order_type')->load('default'), 'commerce order type default exists');
  $check((bool) $entity_type_manager->getStorage('commerce_order_item_type')->load('default'), 'commerce order item type default exists');

  $section('Mail availability');
  $mail_definitions = \Drupal::service('plugin.manager.mail')->getDefinitions();
  $mail_interface = \Drupal::config('system.mail')->get('interface') ?: [];
  $line('MAIL', 'current default mail plugin=' . ($mail_interface['default'] ?? 'php_mail'));
  $line(isset($mail_definitions['test_mail_collector']) ? 'OK' : 'WARN', 'Drupal test_mail_collector mail plugin ' . (isset($mail_definitions['test_mail_collector']) ? 'is available' : 'is unavailable'));

  $section('Webform and Google safety counters');
  $initial_webform_submission_count = $count_entities('webform_submission');
  $initial_google_sync_count = $count_google_sync_rows();
  $line('INFO', 'webform submissions before=' . ($initial_webform_submission_count === NULL ? 'unavailable' : (string) $initial_webform_submission_count));
  $line('INFO', 'Google sync rows before=' . ($initial_google_sync_count === NULL ? 'table unavailable' : (string) $initial_google_sync_count));

  $section('Scenarios');
  foreach ($scenarios as $scenario) {
    $line($is_run ? 'WILL_RUN' : 'WOULD_RUN', sprintf('%s: %s; sku=%s quantity=%s paid=%s expected_remaining=%d expected_trial=%d expected_expiry=%s', $scenario['id'], $scenario['label'], $scenario['sku'], $scenario['quantity'], $scenario['paid'] ? 'yes' : 'no', $scenario['expected_remaining'], $scenario['expected_trial'], $scenario['expected_expiry']));
  }

  if ($failed) {
    throw new \RuntimeException('Prerequisite checks failed. No scenario was run.');
  }

  if (!$is_run) {
    echo PHP_EOL . 'Dry-run complete. No data was changed.' . PHP_EOL;
  }
  else {
    $section('Run');
    $reroute_mail();

    foreach ($scenarios as $scenario) {
      $section('Scenario ' . $scenario['id']);
      $user = $reset_checkout_user();
      [$order, $order_item] = $create_order($scenario, $user);
      $line('ORDER', sprintf('created order_id=%d order_item_id=%d quantity=%s total=%s', (int) $order->id(), (int) $order_item->id(), (string) $order_item->getQuantity(), $order->getTotalPrice() ? (string) $order->getTotalPrice() : 'NULL'));

      if (!empty($scenario['quantity_cap_optional']) && (float) $order_item->getQuantity() < 2.0) {
        $line('SKIP', 'order item quantity > 1 was not preserved; skipping optional quantity cap scenario');
        $cleanup_created_entities();
        continue;
      }

      if ($scenario['paid']) {
        $pay_order($order, $scenario['id']);
        $order = $entity_type_manager->getStorage('commerce_order')->load((int) $order->id());
        if (!$order) {
          throw new \RuntimeException('Paid order could not be reloaded before completion.');
        }
        $check($order->isPaid(), $scenario['id'] . ' order is paid before completion');
      }

      $complete_order($order);
      $order = $entity_type_manager->getStorage('commerce_order')->load((int) $order->id());
      if (!$order) {
        throw new \RuntimeException('Order could not be reloaded after completion.');
      }
      $check($order->getState()->getId() === 'completed', $scenario['id'] . ' order state is completed');
      $check($order->isPaid() === (bool) $scenario['paid'], sprintf('%s paid state expected %s', $scenario['id'], $scenario['paid'] ? 'paid' : 'unpaid'));
      $assert_user_fields($scenario, $user, $order);
      $cleanup_created_entities();
    }

    $section('Post-run local safety checks');
    $reset_checkout_user();
    $final_webform_submission_count = $count_entities('webform_submission');
    $final_google_sync_count = $count_google_sync_rows();
    if ($initial_webform_submission_count !== NULL && $final_webform_submission_count !== NULL) {
      $check($final_webform_submission_count === $initial_webform_submission_count, sprintf('webform submission count unchanged (%d)', $final_webform_submission_count));
    }
    else {
      $line('INFO', 'webform submission count unavailable; no webform submission APIs were called');
    }
    if ($initial_google_sync_count !== NULL && $final_google_sync_count !== NULL) {
      $check($final_google_sync_count === $initial_google_sync_count, sprintf('Google sync row count unchanged (%d)', $final_google_sync_count));
    }
    else {
      $line('INFO', 'Google sync row count unavailable; no Google Calendar APIs or queues were called');
    }
  }
}
catch (\Throwable $throwable) {
  $mark_fail($throwable->getMessage());
}
finally {
  if ($is_run) {
    try {
      $cleanup_created_entities();
    }
    catch (\Throwable $cleanup_throwable) {
      $mark_fail('Cleanup failed: ' . $cleanup_throwable->getMessage());
    }
    try {
      $restore_mail();
    }
    catch (\Throwable $mail_throwable) {
      $mark_fail('Mail restore failed: ' . $mail_throwable->getMessage());
    }
  }
}

$section('Result');
if ($failed) {
  echo 'Commerce credit-flow local test FAILED.' . PHP_EOL;
  exit(1);
}

if ($is_run) {
  echo 'Commerce credit-flow local test PASSED.' . PHP_EOL;
  echo 'Temporary fixture orders, payments, and order items were cleaned up.' . PHP_EOL;
  echo 'Credit mail captured locally: ' . $mail_credit_messages . ' credit message(s), ' . $mail_total_messages . ' total captured message(s).' . PHP_EOL;
}
else {
  echo 'Commerce credit-flow dry-run PASSED. No data was changed.' . PHP_EOL;
}
PHP
}

cleanup_container_script() {
  ddev exec rm -f "${CONTAINER_SCRIPT}" >/dev/null 2>&1 || true
}

run_php_step() {
  write_container_script
  trap cleanup_container_script EXIT
  ddev exec env LOCAL_COMMERCE_CREDIT_FLOW_MODE="${MODE}" "${DRUSH}" php:script "${CONTAINER_SCRIPT}"
}

cd "${DRUPAL_DIR}"

log "Mode: ${MODE}"
require_safe_path

if [[ "${MODE}" == "dry-run" ]]; then
  print_plan
fi

require_ddev
require_drush
require_database
require_bootstrap
run_php_step
