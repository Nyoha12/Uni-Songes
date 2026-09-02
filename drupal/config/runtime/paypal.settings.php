<?php

declare(strict_types=1);

/**
 * Runtime-only PayPal configuration.
 *
 * This file must be required from the deployed, untracked settings.php. The
 * exported gateway stays disabled and credential-free; it is enabled at
 * runtime only when both required environment variables contain values.
 */

$unisonges_paypal_client_id = trim((string) getenv('UNISONGES_PAYPAL_CLIENT_ID'));
$unisonges_paypal_client_secret = trim((string) getenv('UNISONGES_PAYPAL_CLIENT_SECRET'));

$unisonges_paypal_credentials_available = $unisonges_paypal_client_id !== '' && $unisonges_paypal_client_secret !== '';

$config['commerce_payment.commerce_payment_gateway.paypal']['configuration']['client_id'] =
  $unisonges_paypal_credentials_available ? $unisonges_paypal_client_id : '';
$config['commerce_payment.commerce_payment_gateway.paypal']['configuration']['secret'] =
  $unisonges_paypal_credentials_available ? $unisonges_paypal_client_secret : '';
$config['commerce_payment.commerce_payment_gateway.paypal']['status'] =
  $unisonges_paypal_credentials_available;

unset(
  $unisonges_paypal_client_id,
  $unisonges_paypal_client_secret,
  $unisonges_paypal_credentials_available,
);
