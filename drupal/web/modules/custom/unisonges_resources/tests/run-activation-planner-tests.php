#!/usr/bin/env php
<?php

declare(strict_types=1);

define('UNISONGES_RESOURCES_HELPER_LIBRARY_ONLY', TRUE);
require_once dirname(__DIR__, 5) . '/scripts/resources-hub-activation.php';

$contract = str_repeat('a', 64);
$fingerprint = str_repeat('b', 64);
$manifest = [
  'valid' => TRUE,
  'approved' => TRUE,
  'total' => 1,
  'published' => 1,
  'themes' => 1,
  'fingerprint' => $fingerprint,
  'error_codes' => [],
];
$invalid_manifest = [
  'valid' => FALSE,
  'approved' => FALSE,
  'total' => 1,
  'published' => 1,
  'themes' => 1,
  'fingerprint' => '',
  'error_codes' => ['duplicate_id'],
];
$absent = [
  'module_installed' => FALSE,
  'config_exists' => FALSE,
  'stored_config' => NULL,
  'effective_config' => NULL,
  'settings_overridden' => FALSE,
  'route_state' => 'absent',
  'menu_state' => 'absent',
];
$disabled = [
  'module_installed' => TRUE,
  'config_exists' => TRUE,
  'stored_config' => ['enabled' => FALSE, 'manifest_fingerprint' => ''],
  'effective_config' => ['enabled' => FALSE, 'manifest_fingerprint' => ''],
  'settings_overridden' => FALSE,
  'route_state' => 'exact',
  'menu_state' => 'exact',
];
$active = [
  'module_installed' => TRUE,
  'config_exists' => TRUE,
  'stored_config' => ['enabled' => TRUE, 'manifest_fingerprint' => $fingerprint],
  'effective_config' => ['enabled' => TRUE, 'manifest_fingerprint' => $fingerprint],
  'settings_overridden' => FALSE,
  'route_state' => 'exact',
  'menu_state' => 'exact',
];

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
  $assertions++;
  if (!$condition) {
    $failures[] = $message;
  }
};

$fresh = resources_plan('activate', $manifest, $absent, $contract);
$assert($fresh['status'] === 'READY', 'A valid approved manifest must form a READY plan.');
$assert($fresh['actions'] === ['install_unisonges_resources', 'set_settings_enabled'], 'Fresh activation must target only the module and owned settings.');
$assert(preg_match('/^[a-f0-9]{64}$/D', $fresh['token']) === 1, 'Plan token must be lowercase SHA-256.');
$assert($fresh === resources_plan('activate', $manifest, $absent, $contract), 'Identical input must yield an identical plan.');
$assert(!str_contains(json_encode($fresh, JSON_THROW_ON_ERROR), '://'), 'Plans must contain no resource URL.');

$empty = $manifest;
$empty['approved'] = FALSE;
$empty['total'] = 0;
$empty['published'] = 0;
$empty['themes'] = 0;
$empty_plan = resources_plan('activate', $empty, NULL, $contract);
$assert($empty_plan['status'] === 'BLOCKED' && $empty_plan['actions'] === [], 'Empty activation must be BLOCKED with zero writes.');
$assert($empty_plan['blockers'] === ['catalogue_not_approved', 'no_published_resources'], 'Empty blockers must be explicit.');

$invalid_plan = resources_plan('activate', $invalid_manifest, NULL, $contract);
$assert($invalid_plan['status'] === 'BLOCKED' && $invalid_plan['actions'] === [], 'Invalid activation must be BLOCKED with zero writes.');
$assert(in_array('manifest_invalid', $invalid_plan['blockers'], TRUE), 'Invalid activation must report manifest_invalid.');

$over_limit = $invalid_manifest;
$over_limit['total'] = 21;
$over_limit['published'] = 21;
$over_limit['themes'] = 3;
$over_limit['error_codes'] = ['model_b_required'];
$limit_plan = resources_plan('activate', $over_limit, NULL, $contract);
$assert($limit_plan['status'] === 'BLOCKED' && $limit_plan['actions'] === [], 'Twenty-one entries must plan zero writes.');
$assert(in_array('model_a_limit_exceeded', $limit_plan['blockers'], TRUE), 'Twenty-one entries must require Model B.');

$closed = resources_plan('activate', $manifest, $disabled, $contract);
$assert($closed['actions'] === ['set_settings_enabled'], 'Installed activation must change only owned settings.');
$current = resources_plan('activate', $manifest, $active, $contract);
$assert($current['status'] === 'NO_CHANGE' && $current['actions'] === [], 'Current activation must be idempotent.');

$stale = $active;
$stale['stored_config']['manifest_fingerprint'] = str_repeat('c', 64);
$stale['effective_config'] = $stale['stored_config'];
$update = resources_plan('activate', $manifest, $stale, $contract);
$assert($update['actions'] === ['set_settings_enabled'], 'A stale fingerprint must plan one owned-settings update.');
$assert($update['token'] !== $current['token'], 'State drift must change the plan token.');

$override = $disabled;
$override['settings_overridden'] = TRUE;
$override_plan = resources_plan('activate', $manifest, $override, $contract);
$assert($override_plan['status'] === 'BLOCKED' && $override_plan['actions'] === [], 'A same-value settings override must block writes.');
$assert(in_array('settings_override', $override_plan['blockers'], TRUE), 'Override blocker must be explicit.');

$partial = $absent;
$partial['config_exists'] = TRUE;
$partial['stored_config'] = ['enabled' => FALSE, 'manifest_fingerprint' => ''];
$partial['effective_config'] = $partial['stored_config'];
$partial_plan = resources_plan('activate', $manifest, $partial, $contract);
$assert($partial_plan['status'] === 'BLOCKED' && in_array('partial_module_config_state', $partial_plan['blockers'], TRUE), 'Unknown partial state must fail closed.');

$drift = $active;
$drift['route_state'] = 'drift';
$activate_drift = resources_plan('activate', $manifest, $drift, $contract);
$assert($activate_drift['status'] === 'BLOCKED' && in_array('runtime_contract_drift', $activate_drift['blockers'], TRUE), 'Activation must refuse route drift.');

$disable_drift = resources_plan('disable', $invalid_manifest, $drift, $contract);
$assert($disable_drift['status'] === 'READY' && $disable_drift['actions'] === ['set_settings_disabled'], 'Emergency disable must not depend on manifest or route health.');
$disabled_again = resources_plan('disable', $invalid_manifest, $disabled, $contract);
$assert($disabled_again['status'] === 'NO_CHANGE', 'Repeated disable must be idempotent.');
$absent_disable = resources_plan('disable', $invalid_manifest, $absent, $contract);
$assert($absent_disable['status'] === 'NO_CHANGE', 'Disable on an absent module must be a no-op.');

$unknown_refused = FALSE;
try {
  resources_plan('unknown', $manifest, $absent, $contract);
}
catch (InvalidArgumentException) {
  $unknown_refused = TRUE;
}
$assert($unknown_refused, 'Unknown actions must be refused.');

$helper_source = file_get_contents(dirname(__DIR__, 5) . '/scripts/resources-hub-activation.php');
$assert(!str_contains($helper_source, 'config_import'), 'The helper must not import configuration.');
$assert(!str_contains($helper_source, 'menu_link_content'), 'The helper must not create menu content entities.');
$assert(!preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE)\b/i', $helper_source), 'The helper must not contain raw SQL.');

if ($failures !== []) {
  fwrite(STDERR, sprintf("FAIL: %d/%d planner assertions failed.\n", count($failures), $assertions));
  foreach ($failures as $failure) {
    fwrite(STDERR, ' - ' . $failure . PHP_EOL);
  }
  exit(1);
}

fwrite(STDOUT, sprintf("PASS: %d planner assertions; zero writes executed.\n", $assertions));
