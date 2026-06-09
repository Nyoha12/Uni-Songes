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

Audits and, with --apply, updates the unambiguous 2026 Commerce course offers.

Default mode is --dry-run. Writes require --apply.

Options:
  --dry-run     List existing offers and planned changes. Default.
  --apply       Apply only unambiguous course product title/SKU/price updates.
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

$load_variation_ids_by_sku = static function (string $sku) use ($variation_storage): array {
  $ids = $variation_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('sku', $sku)
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

$course_targets = [
  [
    'id' => 'didgeridoo_private_full',
    'description' => 'Didgeridoo private lesson, 1h, all levels, full rate',
    'product_type' => 'cours_deb_inter',
    'old_sku' => 'COURS-DEB-INTER-ADULTE-40',
    'new_sku' => 'COURS-DIDGERIDOO-1H-25',
    'title' => 'Cours didgeridoo 1h - tous niveaux - plein tarif',
    'price' => '25.00',
    'currency' => 'EUR',
  ],
  [
    'id' => 'didgeridoo_private_student',
    'description' => 'Didgeridoo private lesson, 1h, all levels, student rate',
    'product_type' => 'cours_deb_inter',
    'old_sku' => 'COURS-DEB-INTER-ETUDIANT-30',
    'new_sku' => 'COURS-DIDGERIDOO-1H-ETUDIANT-15',
    'title' => 'Cours didgeridoo 1h - tous niveaux - tarif etudiant',
    'price' => '15.00',
    'currency' => 'EUR',
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

$resolve_course_target = static function (array $target) use (
  $load_variation_ids_by_sku,
  $variation_storage,
  $variation_sku,
  $price_info,
  $normalize_amount,
  $describe_candidate
): array {
  $old_ids = $load_variation_ids_by_sku($target['old_sku']);
  $new_ids = $load_variation_ids_by_sku($target['new_sku']);
  $ids = array_values(array_unique(array_merge($old_ids, $new_ids)));
  sort($ids);

  if (count($ids) === 0) {
    return [
      'status' => 'missing',
      'message' => 'No variation found with old SKU ' . $target['old_sku'] . ' or target SKU ' . $target['new_sku'] . '.',
      'target' => $target,
    ];
  }

  if (count($ids) > 1) {
    $candidates = [];
    foreach ($variation_storage->loadMultiple($ids) as $variation) {
      $candidates[] = $describe_candidate($variation);
    }
    return [
      'status' => 'refused',
      'message' => 'Multiple variations match old/target SKU: ' . implode('; ', $candidates),
      'target' => $target,
    ];
  }

  $variation = $variation_storage->load(reset($ids));
  if (!$variation) {
    return [
      'status' => 'refused',
      'message' => 'Matched variation could not be loaded.',
      'target' => $target,
    ];
  }

  $product = method_exists($variation, 'getProduct') ? $variation->getProduct() : NULL;
  if (!$product) {
    return [
      'status' => 'refused',
      'message' => 'Matched variation has no parent product: ' . $describe_candidate($variation),
      'target' => $target,
    ];
  }

  if ($product->bundle() !== $target['product_type']) {
    return [
      'status' => 'refused',
      'message' => 'Matched SKU belongs to product type ' . $product->bundle() . ', expected ' . $target['product_type'] . ': ' . $describe_candidate($variation),
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
    'target' => $target,
    'product' => $product,
    'variation' => $variation,
    'changes' => $changes,
  ];
};

$section('Target 2026 course offers');
foreach ($course_targets as $target) {
  printf(
    '- %s: %s; match %s or %s; target title=%s price=%s %s; product type remains %s' . PHP_EOL,
    $target['id'],
    $target['description'],
    $target['old_sku'],
    $target['new_sku'],
    $format_text($target['title']),
    $target['price'],
    $target['currency'],
    $target['product_type']
  );
}

$section('Course product migration plan');
$course_results = [];
$course_blockers = 0;
foreach ($course_targets as $target) {
  $result = $resolve_course_target($target);
  $course_results[] = $result;
  echo $target['id'] . ': ' . $target['description'] . PHP_EOL;

  if ($result['status'] === 'missing') {
    $course_blockers++;
    echo '  REFUSE ' . $result['message'] . PHP_EOL;
    continue;
  }

  if ($result['status'] === 'refused') {
    $course_blockers++;
    echo '  REFUSE ' . $result['message'] . PHP_EOL;
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

if ($apply && $course_blockers > 0) {
  throw new \RuntimeException('Apply refused because one or more course targets are missing or ambiguous.');
}

$applied_changes = 0;
if ($apply) {
  $section('Applying course product updates');
  foreach ($course_results as $result) {
    if ($result['status'] !== 'ready') {
      continue;
    }
    if ($result['changes'] === []) {
      echo 'UNCHANGED ' . $result['target']['id'] . PHP_EOL;
      continue;
    }

    $product = $result['product'];
    $variation = $result['variation'];
    foreach ($result['changes'] as $change) {
      if ($change['entity'] === 'product' && $change['field'] === 'title') {
        if (method_exists($product, 'setTitle')) {
          $product->setTitle($change['target']);
        }
        else {
          $product->set('title', $change['target']);
        }
      }
      if ($change['entity'] === 'variation' && $change['field'] === 'sku') {
        if (method_exists($variation, 'setSku')) {
          $variation->setSku($change['target']);
        }
        else {
          $variation->set('sku', $change['target']);
        }
      }
      if ($change['entity'] === 'variation' && $change['field'] === 'price') {
        $variation->set('price', new Price($result['target']['price'], $result['target']['currency']));
      }
    }

    $product->save();
    $variation->save();
    $applied_changes += count($result['changes']);
    printf(
      'APPLIED %s on /product/%s variation_id=%s' . PHP_EOL,
      $result['target']['id'],
      (string) $product->id(),
      (string) $variation->id()
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

$section('Stage ticket comparison');
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
    $price = $price_info($variation);
    echo '  - ' . $variation_label($variation) . PHP_EOL;
    if ($category === 'didgeridoo_stage_flat' || $category === 'music_improvisation_meditation_stage_flat') {
      if ($price['number'] === '20.00' && $price['currency'] === 'EUR') {
        echo '    OK matches 2026 flat stage price 20.00 EUR.' . PHP_EOL;
      }
      else {
        echo '    PLAN variation.price ' . $price['display'] . ' -> 20.00 EUR' . PHP_EOL;
        echo '    SKIP apply: stage ticket prices are controlled by the stage publication/ticket system; update the linked stage price after explicit content matching.' . PHP_EOL;
      }
    }
    else {
      echo '    PENDING no automatic 2026 price mapping; leave to the existing special stage publication/ticket system.' . PHP_EOL;
    }
  }
}

$section('Ambiguous or pending decisions');
$pending_decisions = [
  'cours_essai / COURS-ESSAI-20: no explicit decision to keep, rename, reprice, unpublish, or replace the trial lesson offer.',
  'cours_avance / COURS-AVANCE-40: all-level didgeridoo pricing suggests consolidation, but deactivation or reuse needs an explicit decision.',
  'pack_4_deb_inter products: no explicit decision to keep, reprice, hide, or retire packs; no deletes or unpublishes are performed.',
  'Private lessons outside didgeridoo: guimbarde and music improvisation/meditation private lesson prices are not documented here.',
  'Product type labels: existing course bundle ids still carry beginner/intermediate/advanced names; credit attribution is usable, but user-facing/admin labels need later config work if they must change.',
  'Stage tickets: didgeridoo and music improvisation/meditation stages target 20.00 EUR, but exact stage content/products must be matched through the existing stage publication/ticket system.',
  'Special stages: handled by the existing stage publication/ticket system; this script does not infer or mutate special stage products.',
];
foreach ($pending_decisions as $decision) {
  echo '- ' . $decision . PHP_EOL;
}

$section('Summary');
$planned_changes = 0;
foreach ($course_results as $result) {
  if (($result['status'] ?? '') === 'ready') {
    $planned_changes += count($result['changes']);
  }
}
echo 'Mode: ' . $mode . PHP_EOL;
echo 'Course field changes planned: ' . $planned_changes . PHP_EOL;
echo 'Course field changes applied: ' . $applied_changes . PHP_EOL;
echo 'Course apply blockers: ' . $course_blockers . PHP_EOL;
echo 'Pending decisions listed: ' . count($pending_decisions) . PHP_EOL;
PHP

log "Running ${MODE} from ${DRUPAL_DIR}"
UNISONGES_COMMERCE_OFFERS_MODE="${MODE}" "${DRUSH_CMD}" php:script "${TMP_SCRIPT}"
