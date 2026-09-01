<?php

declare(strict_types=1);

/**
 * @file
 * Guarded Drupal bootstrap logic for apply-system-message-placement-2026.sh.
 */

use Drupal\block\BlockInterface;
use Drupal\Core\Config\FileStorage;

const SYSTEM_MESSAGE_CONFIG_NAME = 'block.block.unisonges_theme_messages';
const SYSTEM_MESSAGE_BLOCK_ID = 'unisonges_theme_messages';
const SYSTEM_MESSAGE_THEME = 'unisonges_theme';
const SYSTEM_MESSAGE_PLUGIN = 'system_messages_block';
const SYSTEM_MESSAGE_TARGET_REGION = 'content';
const SYSTEM_MESSAGE_TARGET_WEIGHT = -8;

$fail = static function (string $message): never {
  fwrite(STDERR, 'REFUSE ' . $message . PHP_EOL);
  exit(1);
};

$line = static function (string $status, string $message): void {
  print $status . ' ' . $message . PHP_EOL;
};

$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
  if (!is_array($value)) {
    return $value;
  }
  if (!array_is_list($value)) {
    ksort($value, SORT_STRING);
  }
  foreach ($value as $key => $item) {
    $value[$key] = $canonicalize($item);
  }
  return $value;
};

$arguments = isset($extra) && is_array($extra) ? array_values($extra) : [];
if ($arguments === [] || !in_array($arguments[0], ['dry-run', 'apply'], TRUE)) {
  $fail('Expected the internal mode dry-run or apply.');
}
$mode = $arguments[0];
$supplied_plan_token = $arguments[1] ?? NULL;
if (($mode === 'dry-run' && count($arguments) !== 1)
  || ($mode === 'apply' && (count($arguments) !== 2
    || !is_string($supplied_plan_token)
    || !preg_match('/^[a-f0-9]{64}$/D', $supplied_plan_token)))) {
  $fail('Internal plan-token arguments do not match the selected mode.');
}

$sync_directory = dirname(DRUPAL_ROOT) . '/config/sync';
$sync_storage = new FileStorage($sync_directory);
$sync = $sync_storage->read(SYSTEM_MESSAGE_CONFIG_NAME);
if (!is_array($sync)) {
  $fail('The reviewed sync object is missing: ' . SYSTEM_MESSAGE_CONFIG_NAME . '.');
}

$expected_sync = [
  'id' => SYSTEM_MESSAGE_BLOCK_ID,
  'theme' => SYSTEM_MESSAGE_THEME,
  'plugin' => SYSTEM_MESSAGE_PLUGIN,
  'status' => TRUE,
  'region' => SYSTEM_MESSAGE_TARGET_REGION,
  'weight' => SYSTEM_MESSAGE_TARGET_WEIGHT,
];
foreach ($expected_sync as $key => $expected_value) {
  if (($sync[$key] ?? NULL) !== $expected_value) {
    $fail(sprintf(
      'Sync guard failed for %s: expected %s, found %s.',
      $key,
      json_encode($expected_value, JSON_UNESCAPED_SLASHES),
      json_encode($sync[$key] ?? NULL, JSON_UNESCAPED_SLASHES),
    ));
  }
}
if (($sync['visibility'] ?? NULL) !== []) {
  $fail('The synced messages block must have no visibility restrictions.');
}

$sync_message_blocks = [];
foreach ($sync_storage->listAll('block.block.') as $config_name) {
  $candidate = $sync_storage->read($config_name);
  if (is_array($candidate)
    && ($candidate['status'] ?? FALSE) === TRUE
    && ($candidate['theme'] ?? NULL) === SYSTEM_MESSAGE_THEME
    && ($candidate['plugin'] ?? NULL) === SYSTEM_MESSAGE_PLUGIN) {
    $sync_message_blocks[] = $config_name;
  }
}
sort($sync_message_blocks, SORT_STRING);
if ($sync_message_blocks !== [SYSTEM_MESSAGE_CONFIG_NAME]) {
  $fail('Expected exactly one enabled synced Uni-Songes messages block; found: '
    . implode(', ', $sync_message_blocks) . '.');
}

$block_storage = \Drupal::entityTypeManager()->getStorage('block');
$active_message_blocks = [];
foreach ($block_storage->loadMultipleOverrideFree() as $candidate) {
  if ($candidate instanceof BlockInterface
    && $candidate->status()
    && $candidate->getTheme() === SYSTEM_MESSAGE_THEME
    && $candidate->getPluginId() === SYSTEM_MESSAGE_PLUGIN) {
    $active_message_blocks[] = $candidate->id();
  }
}
sort($active_message_blocks, SORT_STRING);
if ($active_message_blocks !== [SYSTEM_MESSAGE_BLOCK_ID]) {
  $fail('Expected exactly one enabled active Uni-Songes messages block; found: '
    . implode(', ', $active_message_blocks) . '.');
}

$block = $block_storage->loadOverrideFree(SYSTEM_MESSAGE_BLOCK_ID);
if (!$block instanceof BlockInterface) {
  $fail('The target active block does not exist. This helper never creates it.');
}

$identity = [
  'id' => $block->id(),
  'theme' => $block->getTheme(),
  'plugin' => $block->getPluginId(),
  'status' => $block->status(),
];
$expected_identity = [
  'id' => SYSTEM_MESSAGE_BLOCK_ID,
  'theme' => SYSTEM_MESSAGE_THEME,
  'plugin' => SYSTEM_MESSAGE_PLUGIN,
  'status' => TRUE,
];
if ($identity !== $expected_identity) {
  $fail('The active block identity/status does not match the reviewed target.');
}

$before_region = $block->getRegion();
$before_weight = (int) $block->getWeight();
$known_placement = ($before_region === 'header' && $before_weight === -6)
  || ($before_region === SYSTEM_MESSAGE_TARGET_REGION
    && $before_weight === SYSTEM_MESSAGE_TARGET_WEIGHT);
if (!$known_placement) {
  $fail(sprintf(
    'Unexpected active placement region=%s weight=%d; expected legacy header/-6 or target content/-8.',
    json_encode($before_region, JSON_UNESCAPED_SLASHES),
    $before_weight,
  ));
}

$active_storage = \Drupal::service('config.storage');
$before_config = $active_storage->read(SYSTEM_MESSAGE_CONFIG_NAME);
if (!is_array($before_config)) {
  $fail('Could not read the active target config.');
}
if (($before_config['visibility'] ?? NULL) !== []) {
  $fail('The active messages block must have no visibility restrictions.');
}
$effective_config = \Drupal::config(SYSTEM_MESSAGE_CONFIG_NAME)->get();
if (!is_array($effective_config)
  || $canonicalize($effective_config) !== $canonicalize($before_config)) {
  $fail('A runtime config override affects the messages block; no write is safe.');
}
$plan_payload = [
  'version' => 1,
  'sync' => $canonicalize($sync),
  'active' => $canonicalize($before_config),
];
$plan_token = hash('sha256', json_encode(
  $plan_payload,
  JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
));

$line('MODE', $mode);
$line('SYNC', sprintf(
  '%s enabled theme=%s plugin=%s region=%s weight=%d',
  SYSTEM_MESSAGE_CONFIG_NAME,
  SYSTEM_MESSAGE_THEME,
  SYSTEM_MESSAGE_PLUGIN,
  SYSTEM_MESSAGE_TARGET_REGION,
  SYSTEM_MESSAGE_TARGET_WEIGHT,
));
$line('ACTIVE', sprintf(
  '%s enabled theme=%s plugin=%s region=%s weight=%d',
  SYSTEM_MESSAGE_CONFIG_NAME,
  $block->getTheme(),
  $block->getPluginId(),
  $before_region,
  $before_weight,
));
$line('ROLLBACK', sprintf('region=%s weight=%d', $before_region, $before_weight));
$line('PLAN_TOKEN', $plan_token);

if ($mode === 'apply' && !hash_equals($plan_token, $supplied_plan_token)) {
  $fail('Plan token mismatch; rerun dry-run and review the current state.');
}

$needs_update = $before_region !== SYSTEM_MESSAGE_TARGET_REGION
  || $before_weight !== SYSTEM_MESSAGE_TARGET_WEIGHT;
if (!$needs_update) {
  $line('NO_CHANGE', 'Active placement already matches the reviewed sync target.');
  return;
}

$line('PLAN', sprintf(
  'Update only region %s -> %s and weight %d -> %d on %s.',
  $before_region,
  SYSTEM_MESSAGE_TARGET_REGION,
  $before_weight,
  SYSTEM_MESSAGE_TARGET_WEIGHT,
  SYSTEM_MESSAGE_CONFIG_NAME,
));
if ($mode === 'dry-run') {
  $line('DRY_RUN_OK', 'No active configuration was written.');
  return;
}

$protected_before = $before_config;
unset($protected_before['region'], $protected_before['weight']);

$prewrite_config = $active_storage->read(SYSTEM_MESSAGE_CONFIG_NAME);
if (!is_array($prewrite_config)
  || $canonicalize($prewrite_config) !== $canonicalize($before_config)) {
  $fail('Active config changed after the plan was computed; nothing was written.');
}

try {
  $editable = \Drupal::configFactory()->getEditable(SYSTEM_MESSAGE_CONFIG_NAME);
  $editable
    ->set('region', SYSTEM_MESSAGE_TARGET_REGION)
    ->set('weight', SYSTEM_MESSAGE_TARGET_WEIGHT)
    ->save(TRUE);

  $block_storage->resetCache([SYSTEM_MESSAGE_BLOCK_ID]);
  $after = $block_storage->loadOverrideFree(SYSTEM_MESSAGE_BLOCK_ID);
  $after_config = $active_storage->read(SYSTEM_MESSAGE_CONFIG_NAME);
  $protected_after = is_array($after_config) ? $after_config : [];
  unset($protected_after['region'], $protected_after['weight']);

  if (!$after instanceof BlockInterface
    || $after->getRegion() !== SYSTEM_MESSAGE_TARGET_REGION
    || (int) $after->getWeight() !== SYSTEM_MESSAGE_TARGET_WEIGHT
    || $canonicalize($protected_after) !== $canonicalize($protected_before)) {
    throw new RuntimeException('Post-save verification did not match the exact targeted change.');
  }
}
catch (Throwable $throwable) {
  try {
    \Drupal::configFactory()
      ->getEditable(SYSTEM_MESSAGE_CONFIG_NAME)
      ->setData($before_config)
      ->save(TRUE);
    $block_storage->resetCache([SYSTEM_MESSAGE_BLOCK_ID]);
    $rollback = $block_storage->loadOverrideFree(SYSTEM_MESSAGE_BLOCK_ID);
    $rollback_config = $active_storage->read(SYSTEM_MESSAGE_CONFIG_NAME);
    if (!$rollback instanceof BlockInterface
      || $rollback->getRegion() !== $before_region
      || (int) $rollback->getWeight() !== $before_weight
      || $canonicalize($rollback_config) !== $canonicalize($before_config)) {
      throw new RuntimeException('Exact rollback verification failed.');
    }
  }
  catch (Throwable $rollback_error) {
    $fail('Apply failed and rollback also failed: ' . $throwable->getMessage()
      . ' / rollback: ' . $rollback_error->getMessage());
  }
  $fail('Apply failed; the original placement was restored: ' . $throwable->getMessage());
}

$line('APPLIED', sprintf(
  '%s now has region=%s weight=%d; all other keys were preserved.',
  SYSTEM_MESSAGE_CONFIG_NAME,
  SYSTEM_MESSAGE_TARGET_REGION,
  SYSTEM_MESSAGE_TARGET_WEIGHT,
));
