#!/usr/bin/env bash
# shellcheck disable=SC2016 # Embedded PHP is intentionally single-quoted.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRUPAL_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${DRUPAL_DIR}"

DRUSH="vendor/bin/drush"

echo "[bootstrap-commerce] Ensuring Commerce store exists"
"${DRUSH}" php:eval '
$storage = \Drupal::entityTypeManager()->getStorage("commerce_store");
$stores = $storage->loadByProperties(["type" => "online"]);
if (!empty($stores)) {
  echo "Store online already exists.\n";
}
else {
  $email = \Drupal::config("system.site")->get("mail") ?: "noreply@example.invalid";
  $currency = "EUR";
  $store = $storage->create([
    "type" => "online",
    "name" => "Online Store",
    "mail" => $email,
    "default_currency" => $currency,
    "timezone" => "Europe/Paris",
  ]);
  $store->save();
  echo "Store online created.\n";
}
'

echo "[bootstrap-commerce] Ensuring Manual payment gateway exists"
"${DRUSH}" php:eval '
$storage = \Drupal::entityTypeManager()->getStorage("commerce_payment_gateway");
$gateway = $storage->load("manual");
if ($gateway) {
  echo "Gateway manual already exists.\n";
}
else {
  $gateway = $storage->create([
    "id" => "manual",
    "label" => "Manual",
    "plugin" => "manual",
    "status" => TRUE,
    "configuration" => [
      "display_label" => "Paiement manuel",
      "mode" => "test",
      "collect_billing_information" => TRUE,
    ],
  ]);
  $gateway->save();
  echo "Gateway manual created.\n";
}
'

echo "[bootstrap-commerce] PayPal configuration is runtime-managed"
echo "[bootstrap-commerce] No PayPal state changed; follow the credential remediation runbook"

echo "[bootstrap-commerce] Completed"
