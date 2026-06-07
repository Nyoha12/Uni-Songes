#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRUPAL_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${DRUPAL_DIR}"

log() {
  printf '[check-local-test-readiness] %s\n' "$*"
}

warn() {
  printf '[check-local-test-readiness] WARNING: %s\n' "$*" >&2
}

section() {
  printf '\n== %s ==\n' "$*"
}

if ! command -v ddev >/dev/null 2>&1; then
  warn "ddev is not available in PATH."
  exit 1
fi

section "DDEV project"
ddev describe

section "Container PHP"
ddev exec php -v

section "Drush"
if ddev exec test -x ./vendor/bin/drush >/dev/null; then
  log "vendor/bin/drush is present."
else
  warn "vendor/bin/drush is missing. Run Composer install inside DDEV before active checks."
  exit 1
fi

if ddev exec ./vendor/bin/drush status; then
  log "Drush status completed."
else
  warn "Drush status did not complete. Continuing with the read-only database table check."
fi

section "Database bootstrap"
if ! key_value_table="$(ddev exec bash -lc 'mariadb -h db -u db -pdb db -NBe "SHOW TABLES LIKE '\''key_value'\'';"')"; then
  warn "Could not inspect Drupal database tables."
  exit 1
fi

if [[ "${key_value_table}" != "key_value" ]]; then
  warn "Drupal table key_value was not found. The local database is probably empty."
  cat <<'EOF'
Active entity tests are not available yet.

Minimum local fixture target:
- installed Drupal database;
- Webform and webform_booking enabled;
- Commerce store, gateways, and course products;
- reservation webform cours_particuliers_reservation;
- user credit fields;
- a dedicated non-uid=1 local test user.

No data was changed.
EOF
  exit 0
fi
log "Drupal database tables are present."

section "Active entity readiness"
ddev exec ./vendor/bin/drush php:eval '
$failed = FALSE;

$module_checks = [
  "webform" => "webform",
  "webform_booking" => "webform_booking",
  "unisonges_structure" => "unisonges_structure",
  "commerce" => "commerce",
  "commerce_order" => "commerce_order",
  "commerce_payment" => "commerce_payment",
  "commerce_product" => "commerce_product",
];

foreach ($module_checks as $label => $module) {
  $ok = \Drupal::moduleHandler()->moduleExists($module);
  echo ($ok ? "OK" : "MISSING") . " module " . $label . PHP_EOL;
  $failed = $failed || !$ok;
}

$field_storage = \Drupal::entityTypeManager()->getStorage("field_config");
foreach ([
  "user.user.field_seances_restantes",
  "user.user.field_essai_utilise",
  "user.user.field_pack_expire_le",
] as $field_id) {
  $ok = (bool) $field_storage->load($field_id);
  echo ($ok ? "OK" : "MISSING") . " field " . $field_id . PHP_EOL;
  $failed = $failed || !$ok;
}

$webform = \Drupal::entityTypeManager()->getStorage("webform")->load("cours_particuliers_reservation");
$webform_ok = (bool) $webform;
echo ($webform_ok ? "OK" : "MISSING") . " webform cours_particuliers_reservation" . PHP_EOL;
$failed = $failed || !$webform_ok;

if ($webform_ok && method_exists($webform, "getElementsDecoded")) {
  $elements = $webform->getElementsDecoded();
  $reservation_ok = isset($elements["reservation"]) && (($elements["reservation"]["#type"] ?? "") === "webform_booking");
  echo ($reservation_ok ? "OK" : "MISSING") . " reservation webform_booking element" . PHP_EOL;
  $failed = $failed || !$reservation_ok;
}

$product_type_storage = \Drupal::entityTypeManager()->getStorage("commerce_product_type");
foreach ([
  "cours_essai",
  "cours_deb_inter",
  "cours_avance",
  "pack_4_deb_inter",
] as $product_type) {
  $ok = (bool) $product_type_storage->load($product_type);
  echo ($ok ? "OK" : "MISSING") . " product type " . $product_type . PHP_EOL;
  $failed = $failed || !$ok;
}

exit($failed ? 1 : 0);
'

log "Local DDEV is ready for active entity checks."
