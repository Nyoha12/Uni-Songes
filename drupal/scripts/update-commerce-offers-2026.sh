#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
DRUPAL_DIR="$(cd "${SCRIPT_DIR}/.." && pwd -P)"
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
  printf '[update-commerce-offers-2026] %s\n' "$*"
}

warn() {
  printf '[update-commerce-offers-2026] WARNING: %s\n' "$*" >&2
}

usage() {
  cat <<'EOF'
Usage: ./scripts/update-commerce-offers-2026.sh [--dry-run|--apply] [--allow-vps]

Audits and, with --apply, updates the confirmed 2026 Commerce course offers.

Default mode is --dry-run. Writes require --apply.

Options:
  --dry-run     List existing offers and planned changes. Default.
  --apply       Apply only unambiguous course product/variation updates.
  --allow-vps   Permit execution from /var/www paths. Required on VPS paths.
  -h, --help    Show this help.
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
    /mnt/c|/mnt/c/*)
      warn "Refusing to run from /mnt/c: ${DRUPAL_DIR}"
      exit 1
      ;;
    /var/www|/var/www/*)
      if [[ "${ALLOW_VPS}" != "1" ]]; then
        warn "Refusing to run from /var/www without --allow-vps: ${DRUPAL_DIR}"
        exit 1
      fi
      ;;
  esac
}

require_drush() {
  if [[ ! -x "${DRUSH_CMD}" ]]; then
    warn "Local Drush is not executable at ${DRUSH_CMD}."
    warn "Install Composer dependencies for this Drupal checkout before running the audit."
    exit 1
  fi
}

require_safe_path
require_drush

cd "${DRUPAL_DIR}"

TMP_SCRIPT="$(mktemp -t update-commerce-offers-2026.XXXXXX.php)"
cleanup() {
  rm -f "${TMP_SCRIPT}"
}
trap cleanup EXIT

cat > "${TMP_SCRIPT}" <<'PHP'
<?php

declare(strict_types=1);

use Drupal\commerce_price\Price;

$apply = getenv('UNISONGES_COMMERCE_OFFERS_MODE') === 'apply';
$mode = $apply ? 'apply' : 'dry-run';
$blocked = FALSE;

$section = static function (string $title): void {
  echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
};

$line = static function (string $status, string $message) use (&$blocked): void {
  echo $status . ' ' . $message . PHP_EOL;
  if ($status === 'FAIL' || $status === 'REFUSE') {
    $blocked = TRUE;
  }
};

$format_text = static function ($value): string {
  $text = trim(preg_replace('/\s+/', ' ', (string) $value));
  return '"' . str_replace('"', '\"', $text) . '"';
};

$normalize_amount = static function ($amount): string {
  if ($amount === NULL || $amount === '') {
    return '';
  }
  return number_format((float) $amount, 2, '.', '');
};

$entity_status = static function ($entity): string {
  if (method_exists($entity, 'isPublished')) {
    return $entity->isPublished() ? 'published' : 'unpublished';
  }
  if ($entity->hasField('status') && !$entity->get('status')->isEmpty()) {
    return ((bool) $entity->get('status')->value) ? 'published' : 'unpublished';
  }
  return 'status-n/a';
};

$entity_is_published = static function ($entity): ?bool {
  if (method_exists($entity, 'isPublished')) {
    return (bool) $entity->isPublished();
  }
  if ($entity->hasField('status') && !$entity->get('status')->isEmpty()) {
    return (bool) $entity->get('status')->value;
  }
  return NULL;
};

$price_info = static function ($variation) use ($normalize_amount): array {
  $price = NULL;
  if (method_exists($variation, 'getPrice')) {
    $price = $variation->getPrice();
  }
  if (!$price && $variation->hasField('price') && !$variation->get('price')->isEmpty()) {
    $item = $variation->get('price')->first();
    if ($item && method_exists($item, 'toPrice')) {
      $price = $item->toPrice();
    }
  }

  if (!$price) {
    return [
      'number' => NULL,
      'currency' => NULL,
      'display' => 'N/A',
    ];
  }

  $number = $normalize_amount($price->getNumber());
  $currency = $price->getCurrencyCode();
  return [
    'number' => $number,
    'currency' => $currency,
    'display' => $number . ' ' . $currency,
  ];
};

$variation_sku = static function ($variation): string {
  if (method_exists($variation, 'getSku')) {
    return (string) $variation->getSku();
  }
  if ($variation->hasField('sku') && !$variation->get('sku')->isEmpty()) {
    return (string) $variation->get('sku')->value;
  }
  return '';
};

$variation_label = static function ($variation) use ($variation_sku, $price_info, $entity_status): string {
  $price = $price_info($variation);
  return sprintf(
    'variation_id=%s type=%s status=%s sku=%s price=%s',
    (string) $variation->id(),
    $variation->bundle(),
    $entity_status($variation),
    $variation_sku($variation) ?: '(empty)',
    $price['display']
  );
};

$entity_type_manager = \Drupal::entityTypeManager();

$section('Safety');
echo 'Mode: ' . $mode . PHP_EOL;
echo 'Dry-run is read-only. Apply mode saves only matched Commerce product and product variation entities.' . PHP_EOL;
echo 'No config import, config/sync edits, product deletes, orders, webform submissions, or Google Calendar calls are performed.' . PHP_EOL;

$section('Drupal Commerce readiness');
$required_modules = [
  'commerce',
  'commerce_price',
  'commerce_product',
  'commerce_store',
];
foreach ($required_modules as $module) {
  $line(
    \Drupal::moduleHandler()->moduleExists($module) ? 'OK' : 'FAIL',
    'module ' . $module . ' is enabled'
  );
}

foreach ([
  'commerce_product',
  'commerce_product_variation',
  'commerce_product_type',
  'commerce_product_variation_type',
] as $entity_type_id) {
  $line(
    $entity_type_manager->hasDefinition($entity_type_id) ? 'OK' : 'FAIL',
    'entity type ' . $entity_type_id . ' exists'
  );
}

if ($blocked) {
  throw new \RuntimeException('Commerce prerequisites are missing; no changes were made.');
}

$product_storage = $entity_type_manager->getStorage('commerce_product');
$variation_storage = $entity_type_manager->getStorage('commerce_product_variation');
$product_type_storage = $entity_type_manager->getStorage('commerce_product_type');
$variation_type_storage = $entity_type_manager->getStorage('commerce_product_variation_type');
$store_storage = $entity_type_manager->getStorage('commerce_store');

$course_product_types = [
  'cours_essai',
  'cours_deb_inter',
  'cours_avance',
  'pack_4_deb_inter',
];
$stage_product_types = [
  'ticket_stage',
];
$listed_product_types = array_merge($course_product_types, $stage_product_types);

$section('Commerce offer type readiness');
foreach (array_unique($listed_product_types) as $product_type_id) {
  $line(
    $product_type_storage->load($product_type_id) ? 'OK' : 'WARN',
    'product type ' . $product_type_id . ' exists'
  );
}
foreach ($course_product_types as $variation_type_id) {
  $line(
    $variation_type_storage->load($variation_type_id) ? 'OK' : 'WARN',
    'variation type ' . $variation_type_id . ' exists'
  );
}

$store_label = static function ($store) use ($format_text): string {
  return sprintf(
    'store_id=%s label=%s',
    (string) $store->id(),
    $format_text($store->label())
  );
};

$store_ids = $store_storage->getQuery()
  ->accessCheck(FALSE)
  ->sort('store_id', 'ASC')
  ->execute();
$store_ids = array_map('intval', array_values($store_ids));
$stores = $store_ids ? $store_storage->loadMultiple($store_ids) : [];
$default_stores = [];
foreach ($stores as $store) {
  if (method_exists($store, 'isDefault') && $store->isDefault()) {
    $default_stores[(int) $store->id()] = $store;
  }
}

$selected_store = NULL;
$selected_store_reason = '';
if (count($default_stores) === 1) {
  $selected_store = reset($default_stores);
  $selected_store_reason = 'single default store';
}
elseif (count($stores) === 1) {
  $selected_store = reset($stores);
  $selected_store_reason = 'single store';
}
elseif (count($stores) === 0) {
  $selected_store_reason = 'no Commerce store found';
}
else {
  $selected_store_reason = 'multiple stores without one unambiguous default';
}
$selected_store_label = $selected_store ? $store_label($selected_store) : $selected_store_reason;

$section('Store diagnostics for product creation');
if ($stores === []) {
  echo 'No Commerce stores found. Existing product updates can still be planned; new product creation requires one store.' . PHP_EOL;
}
foreach ($stores as $store) {
  $default_suffix = method_exists($store, 'isDefault') && $store->isDefault() ? ' default=yes' : ' default=no-or-n/a';
  echo '- ' . $store_label($store) . $default_suffix . PHP_EOL;
}
echo 'Selected store for new products: ' . $selected_store_label . PHP_EOL;

$load_variation_ids_by_sku = static function (string $sku) use ($variation_storage): array {
  $ids = $variation_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('sku', $sku)
    ->execute();
  $ids = array_map('intval', array_values($ids));
  sort($ids);
  return $ids;
};

$load_product_ids_by_title = static function (string $product_type, string $title) use ($product_storage): array {
  $ids = $product_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', $product_type)
    ->condition('title', $title)
    ->execute();
  $ids = array_map('intval', array_values($ids));
  sort($ids);
  return $ids;
};

$linked_page_label = static function ($product): string {
  if (!$product->hasField('field_linked_page') || $product->get('field_linked_page')->isEmpty()) {
    return '';
  }
  $page = $product->get('field_linked_page')->entity;
  return $page ? (string) $page->label() : '';
};

$product_ids = $product_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', $listed_product_types, 'IN')
  ->sort('product_id', 'ASC')
  ->execute();
$product_ids = array_map('intval', array_values($product_ids));

$products = $product_ids ? $product_storage->loadMultiple($product_ids) : [];

$section('Existing course and stage products');
if ($products === []) {
  echo 'No course or stage Commerce products found for types: ' . implode(', ', $listed_product_types) . PHP_EOL;
}
foreach ($products as $product) {
  $linked_label = $linked_page_label($product);
  $linked_suffix = $linked_label !== '' ? ' linked_page=' . $format_text($linked_label) : '';
  printf(
    'Product /product/%s type=%s status=%s title=%s%s' . PHP_EOL,
    (string) $product->id(),
    $product->bundle(),
    $entity_status($product),
    $format_text($product->label()),
    $linked_suffix
  );

  $variations = method_exists($product, 'getVariations') ? $product->getVariations() : [];
  if ($variations === []) {
    echo '  - no variations' . PHP_EOL;
  }
  foreach ($variations as $variation) {
    echo '  - ' . $variation_label($variation) . PHP_EOL;
  }
}

$private_lesson_product_type = 'cours_deb_inter';
$private_lesson_variation_type = 'cours_deb_inter';

$offer_targets = [
  [
    'id' => 'trial_lesson',
    'description' => 'Trial lesson, one per account',
    'product_type' => 'cours_essai',
    'variation_type' => 'cours_essai',
    'expected_product_id' => 4,
    'match_skus' => ['COURS-ESSAI-20', 'COURS-ESSAI-10'],
    'new_sku' => 'COURS-ESSAI-10',
    'title' => "Cours d'essai - 1 seance",
    'price' => '10.00',
    'currency' => 'EUR',
    'create_if_missing' => FALSE,
    'policy' => 'update /product/4 only if product id, type, and variation mapping are safe',
  ],
  [
    'id' => 'didgeridoo_private_full',
    'description' => 'Didgeridoo private lesson, 1h, all levels, full rate',
    'product_type' => $private_lesson_product_type,
    'variation_type' => $private_lesson_variation_type,
    'expected_product_id' => 5,
    'match_skus' => ['COURS-DEB-INTER-ADULTE-40', 'COURS-DIDGERIDOO-1H-25'],
    'new_sku' => 'COURS-DIDGERIDOO-1H-25',
    'title' => 'Cours didgeridoo 1h - tous niveaux - plein tarif',
    'price' => '25.00',
    'currency' => 'EUR',
    'create_if_missing' => FALSE,
    'policy' => 'ensure /product/5 remains the didgeridoo full-rate credit product',
  ],
  [
    'id' => 'didgeridoo_private_student',
    'description' => 'Didgeridoo private lesson, 1h, all levels, student rate',
    'product_type' => $private_lesson_product_type,
    'variation_type' => $private_lesson_variation_type,
    'expected_product_id' => 6,
    'match_skus' => ['COURS-DEB-INTER-ETUDIANT-30', 'COURS-DIDGERIDOO-1H-ETUDIANT-15'],
    'new_sku' => 'COURS-DIDGERIDOO-1H-ETUDIANT-15',
    'title' => 'Cours didgeridoo 1h - tous niveaux - tarif etudiant',
    'price' => '15.00',
    'currency' => 'EUR',
    'create_if_missing' => FALSE,
    'policy' => 'ensure /product/6 remains the didgeridoo student-rate credit product',
  ],
  [
    'id' => 'guimbarde_private_full',
    'description' => 'Guimbarde private lesson, 1h, full rate',
    'product_type' => $private_lesson_product_type,
    'variation_type' => $private_lesson_variation_type,
    'match_skus' => ['COURS-GUIMBARDE-1H-25'],
    'new_sku' => 'COURS-GUIMBARDE-1H-25',
    'title' => 'Cours guimbarde 1h - tous niveaux - plein tarif',
    'price' => '25.00',
    'currency' => 'EUR',
    'create_if_missing' => TRUE,
    'policy' => 'create or update with the course credit-compatible cours_deb_inter type',
  ],
  [
    'id' => 'guimbarde_private_student',
    'description' => 'Guimbarde private lesson, 1h, student rate',
    'product_type' => $private_lesson_product_type,
    'variation_type' => $private_lesson_variation_type,
    'match_skus' => ['COURS-GUIMBARDE-1H-ETUDIANT-15'],
    'new_sku' => 'COURS-GUIMBARDE-1H-ETUDIANT-15',
    'title' => 'Cours guimbarde 1h - tous niveaux - tarif etudiant',
    'price' => '15.00',
    'currency' => 'EUR',
    'create_if_missing' => TRUE,
    'policy' => 'create or update with the course credit-compatible cours_deb_inter type',
  ],
  [
    'id' => 'meditation_impro_private_full',
    'description' => 'Meditation / improvisation private lesson, 1h, full rate',
    'product_type' => $private_lesson_product_type,
    'variation_type' => $private_lesson_variation_type,
    'match_skus' => ['COURS-MEDITATION-IMPRO-1H-25'],
    'new_sku' => 'COURS-MEDITATION-IMPRO-1H-25',
    'title' => 'Cours meditation / improvisation 1h - tous niveaux - plein tarif',
    'price' => '25.00',
    'currency' => 'EUR',
    'create_if_missing' => TRUE,
    'policy' => 'create or update with the course credit-compatible cours_deb_inter type',
  ],
  [
    'id' => 'meditation_impro_private_student',
    'description' => 'Meditation / improvisation private lesson, 1h, student rate',
    'product_type' => $private_lesson_product_type,
    'variation_type' => $private_lesson_variation_type,
    'match_skus' => ['COURS-MEDITATION-IMPRO-1H-ETUDIANT-15'],
    'new_sku' => 'COURS-MEDITATION-IMPRO-1H-ETUDIANT-15',
    'title' => 'Cours meditation / improvisation 1h - tous niveaux - tarif etudiant',
    'price' => '15.00',
    'currency' => 'EUR',
    'create_if_missing' => TRUE,
    'policy' => 'create or update with the course credit-compatible cours_deb_inter type',
  ],
];

$describe_candidate = static function ($variation) use ($variation_sku, $price_info, $format_text): string {
  $product = method_exists($variation, 'getProduct') ? $variation->getProduct() : NULL;
  $product_text = $product
    ? sprintf('/product/%s type=%s title=%s', (string) $product->id(), $product->bundle(), $format_text($product->label()))
    : 'product=(none)';
  $price = $price_info($variation);
  return sprintf(
    'variation_id=%s sku=%s price=%s product=%s',
    (string) $variation->id(),
    $variation_sku($variation) ?: '(empty)',
    $price['display'],
    $product_text
  );
};

$product_variations = static function ($product): array {
  if (method_exists($product, 'getVariations')) {
    return array_values($product->getVariations());
  }
  if ($product->hasField('variations') && !$product->get('variations')->isEmpty()) {
    $variations = [];
    foreach ($product->get('variations') as $item) {
      if ($item->entity) {
        $variations[] = $item->entity;
      }
    }
    return $variations;
  }
  return [];
};

$product_summary = static function ($product) use ($format_text): string {
  return sprintf(
    '/product/%s type=%s title=%s',
    (string) $product->id(),
    $product->bundle(),
    $format_text($product->label())
  );
};

$product_type_allows_variation_type = static function ($product_type, string $variation_type_id): bool {
  if (!$product_type || !method_exists($product_type, 'getVariationTypeIds')) {
    return TRUE;
  }
  $allowed_variation_type_ids = $product_type->getVariationTypeIds();
  return $allowed_variation_type_ids === [] || in_array($variation_type_id, $allowed_variation_type_ids, TRUE);
};

$set_product_title = static function ($product, string $title): void {
  if (method_exists($product, 'setTitle')) {
    $product->setTitle($title);
  }
  else {
    $product->set('title', $title);
  }
};

$set_variation_sku = static function ($variation, string $sku): void {
  if (method_exists($variation, 'setSku')) {
    $variation->setSku($sku);
  }
  else {
    $variation->set('sku', $sku);
  }
};

$set_entity_published = static function ($entity, bool $published): void {
  if ($published && method_exists($entity, 'setPublished')) {
    $entity->setPublished();
    return;
  }
  if (!$published && method_exists($entity, 'setUnpublished')) {
    $entity->setUnpublished();
    return;
  }
  if ($entity->hasField('status')) {
    $entity->set('status', $published ? 1 : 0);
  }
};

$attach_variation_to_product = static function ($product, $variation): void {
  if (method_exists($product, 'getVariations') && method_exists($product, 'setVariations')) {
    $variations = array_values($product->getVariations());
    foreach ($variations as $existing_variation) {
      if ((int) $existing_variation->id() === (int) $variation->id()) {
        return;
      }
    }
    $variations[] = $variation;
    $product->setVariations($variations);
    return;
  }

  if (!$product->hasField('variations')) {
    throw new \RuntimeException('Product /product/' . $product->id() . ' has no variations field.');
  }

  $values = $product->get('variations')->getValue();
  foreach ($values as $value) {
    if ((int) ($value['target_id'] ?? 0) === (int) $variation->id()) {
      return;
    }
  }
  $values[] = ['target_id' => (int) $variation->id()];
  $product->set('variations', $values);
};

$resolve_offer_target = static function (array $target) use (
  $load_variation_ids_by_sku,
  $load_product_ids_by_title,
  $product_storage,
  $variation_storage,
  $product_type_storage,
  $variation_type_storage,
  $variation_sku,
  $price_info,
  $normalize_amount,
  $describe_candidate,
  $product_variations,
  $product_summary,
  $product_type_allows_variation_type
): array {
  $product_type = $product_type_storage->load($target['product_type']);
  if (!$product_type) {
    return [
      'status' => 'refused',
      'message' => 'Product type ' . $target['product_type'] . ' is missing.',
      'target' => $target,
    ];
  }

  $variation_type = $variation_type_storage->load($target['variation_type']);
  if (!$variation_type) {
    return [
      'status' => 'refused',
      'message' => 'Variation type ' . $target['variation_type'] . ' is missing.',
      'target' => $target,
    ];
  }

  if (!$product_type_allows_variation_type($product_type, $target['variation_type'])) {
    return [
      'status' => 'refused',
      'message' => 'Product type ' . $target['product_type'] . ' does not allow variation type ' . $target['variation_type'] . '.',
      'target' => $target,
    ];
  }

  $match_skus = array_values(array_unique(array_filter(array_merge($target['match_skus'] ?? [], [$target['new_sku']]))));
  $sku_variation_ids = [];
  foreach ($match_skus as $sku) {
    $sku_variation_ids = array_merge($sku_variation_ids, $load_variation_ids_by_sku($sku));
  }
  $sku_variation_ids = array_values(array_unique($sku_variation_ids));
  sort($sku_variation_ids);

  $candidate_product_ids = [];
  $expected_product_id = isset($target['expected_product_id']) ? (int) $target['expected_product_id'] : NULL;
  if ($expected_product_id !== NULL) {
    $expected_product = $product_storage->load($expected_product_id);
    if (!$expected_product) {
      return [
        'status' => 'missing',
        'message' => 'Expected /product/' . $expected_product_id . ' was not found.',
        'target' => $target,
      ];
    }
    $candidate_product_ids[] = $expected_product_id;
  }

  foreach ($variation_storage->loadMultiple($sku_variation_ids) as $sku_variation) {
    $sku_product = method_exists($sku_variation, 'getProduct') ? $sku_variation->getProduct() : NULL;
    if (!$sku_product) {
      return [
        'status' => 'refused',
        'message' => 'Matched SKU variation has no parent product: ' . $describe_candidate($sku_variation),
        'target' => $target,
      ];
    }
    $sku_product_id = (int) $sku_product->id();
    if ($expected_product_id !== NULL && $sku_product_id !== $expected_product_id) {
      return [
        'status' => 'refused',
        'message' => 'A matched SKU belongs to ' . $product_summary($sku_product) . ', expected /product/' . $expected_product_id . ': ' . $describe_candidate($sku_variation),
        'target' => $target,
      ];
    }
    $candidate_product_ids[] = $sku_product_id;
  }

  if ($expected_product_id === NULL && !empty($target['create_if_missing'])) {
    $candidate_product_ids = array_merge(
      $candidate_product_ids,
      $load_product_ids_by_title($target['product_type'], $target['title'])
    );
  }

  $candidate_product_ids = array_values(array_unique(array_map('intval', $candidate_product_ids)));
  sort($candidate_product_ids);

  if ($candidate_product_ids === []) {
    if (!empty($target['create_if_missing'])) {
      return [
        'status' => 'ready',
        'action' => 'create_product',
        'target' => $target,
        'changes' => [
          [
            'entity' => 'product',
            'field' => 'create',
            'current' => '',
            'target' => $target['title'],
          ],
        ],
      ];
    }

    return [
      'status' => 'missing',
      'message' => 'No matching product found for expected SKU(s): ' . implode(', ', $match_skus) . '.',
      'target' => $target,
    ];
  }

  if (count($candidate_product_ids) > 1) {
    $candidates = [];
    foreach ($product_storage->loadMultiple($candidate_product_ids) as $candidate_product) {
      $candidates[] = $product_summary($candidate_product);
    }
    return [
      'status' => 'refused',
      'message' => 'Multiple products match by SKU/title: ' . implode('; ', $candidates),
      'target' => $target,
    ];
  }

  $product = $product_storage->load(reset($candidate_product_ids));
  if (!$product) {
    return [
      'status' => 'refused',
      'message' => 'Matched product could not be loaded.',
      'target' => $target,
    ];
  }

  if ($product->bundle() !== $target['product_type']) {
    return [
      'status' => 'refused',
      'message' => 'Matched product has type ' . $product->bundle() . ', expected ' . $target['product_type'] . ': ' . $product_summary($product),
      'target' => $target,
    ];
  }

  $variations = $product_variations($product);
  $matching_variations = [];
  foreach ($variations as $candidate_variation) {
    if (in_array($variation_sku($candidate_variation), $match_skus, TRUE)) {
      $matching_variations[] = $candidate_variation;
    }
  }

  if (count($matching_variations) > 1) {
    $candidates = [];
    foreach ($matching_variations as $matching_variation) {
      $candidates[] = $describe_candidate($matching_variation);
    }
    return [
      'status' => 'refused',
      'message' => 'Multiple variations on the matched product match target SKU(s): ' . implode('; ', $candidates),
      'target' => $target,
    ];
  }

  if (count($matching_variations) === 1) {
    $variation = reset($matching_variations);
  }
  elseif (count($variations) === 1) {
    $variation = reset($variations);
  }
  elseif (count($variations) === 0 && !empty($target['create_if_missing'])) {
    return [
      'status' => 'ready',
      'action' => 'create_variation',
      'target' => $target,
      'product' => $product,
      'changes' => [
        [
          'entity' => 'variation',
          'field' => 'create',
          'current' => '',
          'target' => $target['new_sku'],
        ],
      ],
    ];
  }
  elseif (count($variations) === 0) {
    return [
      'status' => 'refused',
      'message' => 'Matched product has no variation to update: ' . $product_summary($product),
      'target' => $target,
    ];
  }
  else {
    return [
      'status' => 'refused',
      'message' => 'Matched product has multiple variations and none has a target SKU: ' . $product_summary($product),
      'target' => $target,
    ];
  }

  if ($variation->bundle() !== $target['variation_type']) {
    return [
      'status' => 'refused',
      'message' => 'Matched variation type is ' . $variation->bundle() . ', expected ' . $target['variation_type'] . ': ' . $describe_candidate($variation),
      'target' => $target,
    ];
  }

  $changes = [];
  if ((string) $product->label() !== $target['title']) {
    $changes[] = [
      'entity' => 'product',
      'field' => 'title',
      'current' => (string) $product->label(),
      'target' => $target['title'],
    ];
  }

  $current_sku = $variation_sku($variation);
  if ($current_sku !== $target['new_sku']) {
    $changes[] = [
      'entity' => 'variation',
      'field' => 'sku',
      'current' => $current_sku,
      'target' => $target['new_sku'],
    ];
  }

  $current_price = $price_info($variation);
  if ($current_price['number'] !== $normalize_amount($target['price']) || $current_price['currency'] !== $target['currency']) {
    $changes[] = [
      'entity' => 'variation',
      'field' => 'price',
      'current' => $current_price['display'],
      'target' => $normalize_amount($target['price']) . ' ' . $target['currency'],
    ];
  }

  return [
    'status' => 'ready',
    'action' => 'update',
    'target' => $target,
    'product' => $product,
    'variation' => $variation,
    'changes' => $changes,
  ];
};

$section('Target 2026 course offers');
foreach ($offer_targets as $target) {
  printf(
    '- %s: %s; match SKU(s) %s; target title=%s sku=%s price=%s %s; product_type=%s variation_type=%s; policy=%s' . PHP_EOL,
    $target['id'],
    $target['description'],
    implode(', ', array_values(array_unique(array_merge($target['match_skus'] ?? [], [$target['new_sku']])))),
    $format_text($target['title']),
    $target['new_sku'],
    $target['price'],
    $target['currency'],
    $target['product_type'],
    $target['variation_type'],
    $target['policy']
  );
}

$section('Course product migration plan');
$offer_results = [];
$offer_blockers = 0;
foreach ($offer_targets as $target) {
  $result = $resolve_offer_target($target);
  if (($result['status'] ?? '') === 'ready' && ($result['action'] ?? '') === 'create_product' && !$selected_store) {
    $result = [
      'status' => 'refused',
      'message' => 'New product creation needs one unambiguous Commerce store; current store state: ' . $selected_store_label . '.',
      'target' => $target,
    ];
  }
  $offer_results[] = $result;
  echo $target['id'] . ': ' . $target['description'] . PHP_EOL;

  if ($result['status'] === 'missing') {
    $offer_blockers++;
    echo '  REFUSE ' . $result['message'] . PHP_EOL;
    continue;
  }

  if ($result['status'] === 'refused') {
    $offer_blockers++;
    echo '  REFUSE ' . $result['message'] . PHP_EOL;
    continue;
  }

  if (($result['action'] ?? '') === 'create_product') {
    printf(
      '  PLAN create product type=%s title=%s store=%s with variation type=%s sku=%s price=%s %s' . PHP_EOL,
      $target['product_type'],
      $format_text($target['title']),
      $selected_store_label,
      $target['variation_type'],
      $target['new_sku'],
      $target['price'],
      $target['currency']
    );
    continue;
  }

  if (($result['action'] ?? '') === 'create_variation') {
    $product = $result['product'];
    printf(
      '  Match: /product/%s type=%s title=%s' . PHP_EOL,
      (string) $product->id(),
      $product->bundle(),
      $format_text($product->label())
    );
    printf(
      '  PLAN create variation type=%s sku=%s price=%s %s and attach to product' . PHP_EOL,
      $target['variation_type'],
      $target['new_sku'],
      $target['price'],
      $target['currency']
    );
    continue;
  }

  $product = $result['product'];
  $variation = $result['variation'];
  printf(
    '  Match: /product/%s type=%s variation_id=%s sku=%s' . PHP_EOL,
    (string) $product->id(),
    $product->bundle(),
    (string) $variation->id(),
    $variation_sku($variation) ?: '(empty)'
  );

  if ($result['changes'] === []) {
    echo '  OK already matches target.' . PHP_EOL;
    continue;
  }

  foreach ($result['changes'] as $change) {
    printf(
      '  PLAN %s.%s %s -> %s' . PHP_EOL,
      $change['entity'],
      $change['field'],
      $format_text($change['current']),
      $format_text($change['target'])
    );
  }
}

$retire_targets = [
  [
    'id' => 'retire_pack_full',
    'product_id' => 7,
    'expected_type' => 'pack_4_deb_inter',
    'description' => 'Old four-course full-rate pack: unpublish, do not delete',
  ],
  [
    'id' => 'retire_pack_student',
    'product_id' => 8,
    'expected_type' => 'pack_4_deb_inter',
    'description' => 'Old four-course student pack: unpublish, do not delete',
  ],
  [
    'id' => 'retire_advanced_course',
    'product_id' => 9,
    'expected_type' => 'cours_avance',
    'description' => 'Old advanced course: unpublish, do not delete',
  ],
];

$section('Products to unpublish without deleting');
$retire_results = [];
$retire_blockers = 0;
foreach ($retire_targets as $target) {
  $product = $product_storage->load((int) $target['product_id']);
  echo $target['id'] . ': ' . $target['description'] . PHP_EOL;
  if (!$product) {
    $retire_results[] = [
      'status' => 'missing',
      'target' => $target,
    ];
    echo '  WARN /product/' . $target['product_id'] . ' was not found; no delete is attempted.' . PHP_EOL;
    continue;
  }

  printf(
    '  Match: /product/%s type=%s status=%s title=%s' . PHP_EOL,
    (string) $product->id(),
    $product->bundle(),
    $entity_status($product),
    $format_text($product->label())
  );

  if ($product->bundle() !== $target['expected_type']) {
    $retire_blockers++;
    $retire_results[] = [
      'status' => 'refused',
      'target' => $target,
      'product' => $product,
      'message' => 'Product type is ' . $product->bundle() . ', expected ' . $target['expected_type'] . '.',
    ];
    echo '  REFUSE Product type is ' . $product->bundle() . ', expected ' . $target['expected_type'] . '.' . PHP_EOL;
    continue;
  }

  if ($entity_is_published($product) === FALSE) {
    $retire_results[] = [
      'status' => 'ready',
      'action' => 'unchanged',
      'target' => $target,
      'product' => $product,
    ];
    echo '  OK already unpublished.' . PHP_EOL;
    continue;
  }

  if ($entity_is_published($product) === NULL) {
    $retire_blockers++;
    $retire_results[] = [
      'status' => 'refused',
      'target' => $target,
      'product' => $product,
      'message' => 'Product publication status cannot be read safely.',
    ];
    echo '  REFUSE Product publication status cannot be read safely.' . PHP_EOL;
    continue;
  }

  $retire_results[] = [
    'status' => 'ready',
    'action' => 'unpublish',
    'target' => $target,
    'product' => $product,
  ];
  echo '  PLAN product.status published -> unpublished' . PHP_EOL;
}

if ($apply && ($offer_blockers + $retire_blockers) > 0) {
  throw new \RuntimeException('Apply refused because one or more course offer targets or retirement targets are missing, ambiguous, or unsafe.');
}

$applied_offer_changes = 0;
$applied_offer_creations = 0;
if ($apply) {
  $section('Applying course product updates');
  foreach ($offer_results as $result) {
    if ($result['status'] !== 'ready') {
      continue;
    }

    $target = $result['target'];
    if (($result['action'] ?? '') === 'create_product') {
      if (!$selected_store) {
        throw new \RuntimeException('No selected store was available for product creation.');
      }
      $price = new Price($target['price'], $target['currency']);
      $variation = $variation_storage->create([
        'type' => $target['variation_type'],
        'sku' => $target['new_sku'],
        'status' => TRUE,
        'price' => $price->toArray(),
      ]);
      $variation->save();

      $product = $product_storage->create([
        'type' => $target['product_type'],
        'title' => $target['title'],
        'stores' => [(int) $selected_store->id()],
        'variations' => [(int) $variation->id()],
        'status' => TRUE,
      ]);
      $product->save();
      $applied_offer_creations++;
      printf(
        'CREATED %s as /product/%s variation_id=%s sku=%s' . PHP_EOL,
        $target['id'],
        (string) $product->id(),
        (string) $variation->id(),
        $target['new_sku']
      );
      continue;
    }

    if (($result['action'] ?? '') === 'create_variation') {
      $price = new Price($target['price'], $target['currency']);
      $variation = $variation_storage->create([
        'type' => $target['variation_type'],
        'sku' => $target['new_sku'],
        'status' => TRUE,
        'price' => $price->toArray(),
      ]);
      $variation->save();

      $product = $result['product'];
      $attach_variation_to_product($product, $variation);
      $product->save();
      $applied_offer_creations++;
      printf(
        'CREATED %s variation_id=%s sku=%s on /product/%s' . PHP_EOL,
        $target['id'],
        (string) $variation->id(),
        $target['new_sku'],
        (string) $product->id()
      );
      continue;
    }

    if ($result['changes'] === []) {
      echo 'UNCHANGED ' . $target['id'] . PHP_EOL;
      continue;
    }

    $product = $result['product'];
    $variation = $result['variation'];
    $product_changed = FALSE;
    $variation_changed = FALSE;
    foreach ($result['changes'] as $change) {
      if ($change['entity'] === 'product' && $change['field'] === 'title') {
        $set_product_title($product, $change['target']);
        $product_changed = TRUE;
      }
      if ($change['entity'] === 'variation' && $change['field'] === 'sku') {
        $set_variation_sku($variation, $change['target']);
        $variation_changed = TRUE;
      }
      if ($change['entity'] === 'variation' && $change['field'] === 'price') {
        $variation->set('price', new Price($target['price'], $target['currency']));
        $variation_changed = TRUE;
      }
    }

    if ($product_changed) {
      $product->save();
    }
    if ($variation_changed) {
      $variation->save();
    }
    $applied_offer_changes += count($result['changes']);
    printf(
      'APPLIED %s on /product/%s variation_id=%s' . PHP_EOL,
      $target['id'],
      (string) $product->id(),
      (string) $variation->id()
    );
  }
}

$applied_unpublishes = 0;
if ($apply) {
  $section('Applying product unpublishes');
  foreach ($retire_results as $result) {
    if (($result['status'] ?? '') !== 'ready' || ($result['action'] ?? '') !== 'unpublish') {
      continue;
    }
    $product = $result['product'];
    $set_entity_published($product, FALSE);
    $product->save();
    $applied_unpublishes++;
    printf(
      'UNPUBLISHED %s /product/%s' . PHP_EOL,
      $result['target']['id'],
      (string) $product->id()
    );
  }
}

$classify_stage = static function ($product, string $linked_label) use ($variation_sku): string {
  $parts = [
    (string) $product->label(),
    $linked_label,
  ];
  if (method_exists($product, 'getVariations')) {
    foreach ($product->getVariations() as $variation) {
      $parts[] = $variation_sku($variation);
    }
  }
  $haystack = implode(' ', $parts);
  if (preg_match('/didgeridoo/i', $haystack)) {
    return 'didgeridoo_stage_flat';
  }
  if (preg_match('/improvisation|m.ditation/i', $haystack)) {
    return 'music_improvisation_meditation_stage_flat';
  }
  return 'special_or_other_stage';
};

$section('Stage ticket diagnostics');
echo 'ticket_stage products are listed only. Existing stage publication -> ticket system remains authoritative; this script creates no generic fixed stage products and changes no stage prices.' . PHP_EOL;
$stage_products = array_filter($products, static function ($product): bool {
  return $product->bundle() === 'ticket_stage';
});
if ($stage_products === []) {
  echo 'No ticket_stage products found.' . PHP_EOL;
}
foreach ($stage_products as $product) {
  $linked_label = $linked_page_label($product);
  $category = $classify_stage($product, $linked_label);
  printf(
    'Stage /product/%s title=%s linked_page=%s category=%s' . PHP_EOL,
    (string) $product->id(),
    $format_text($product->label()),
    $linked_label !== '' ? $format_text($linked_label) : '(none)',
    $category
  );

  $variations = method_exists($product, 'getVariations') ? $product->getVariations() : [];
  foreach ($variations as $variation) {
    echo '  - ' . $variation_label($variation) . PHP_EOL;
    echo '    DIAGNOSTIC only; no ticket_stage mutation is planned or applied.' . PHP_EOL;
  }
}

$section('Confirmed non-actions');
$confirmed_non_actions = [
  'No products are deleted; old packs and the old advanced course are only unpublished when matched safely.',
  'No orders, webform submissions, Google Calendar calls, config imports, or config/sync writes are performed.',
  'ticket_stage products remain controlled by the existing stage publication/ticket system and are diagnostics only here.',
  'Existing course bundle ids are reused because cours_deb_inter is already compatible with one-session course credit attribution.',
];
foreach ($confirmed_non_actions as $decision) {
  echo '- ' . $decision . PHP_EOL;
}

$section('Summary');
$planned_offer_changes = 0;
$planned_offer_creations = 0;
foreach ($offer_results as $result) {
  if (($result['status'] ?? '') === 'ready') {
    if (in_array(($result['action'] ?? ''), ['create_product', 'create_variation'], TRUE)) {
      $planned_offer_creations++;
    }
    else {
      $planned_offer_changes += count($result['changes']);
    }
  }
}
$planned_unpublishes = 0;
foreach ($retire_results as $result) {
  if (($result['status'] ?? '') === 'ready' && ($result['action'] ?? '') === 'unpublish') {
    $planned_unpublishes++;
  }
}
echo 'Mode: ' . $mode . PHP_EOL;
echo 'Offer field changes planned: ' . $planned_offer_changes . PHP_EOL;
echo 'Offer creations planned: ' . $planned_offer_creations . PHP_EOL;
echo 'Product unpublishes planned: ' . $planned_unpublishes . PHP_EOL;
echo 'Offer field changes applied: ' . $applied_offer_changes . PHP_EOL;
echo 'Offer creations applied: ' . $applied_offer_creations . PHP_EOL;
echo 'Product unpublishes applied: ' . $applied_unpublishes . PHP_EOL;
echo 'Apply blockers: ' . ($offer_blockers + $retire_blockers) . PHP_EOL;
echo 'Confirmed non-actions listed: ' . count($confirmed_non_actions) . PHP_EOL;
PHP

log "Running ${MODE} from ${DRUPAL_DIR}"
UNISONGES_COMMERCE_OFFERS_MODE="${MODE}" "${DRUSH_CMD}" php:script "${TMP_SCRIPT}"
