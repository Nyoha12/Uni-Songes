#!/usr/bin/env php
<?php

declare(strict_types=1);

use Drupal\unisonges_resources\Manifest\ManifestRepository;
use Drupal\unisonges_resources\Manifest\ManifestValidationResult;
use Drupal\unisonges_resources\Manifest\ManifestValidator;

$drupal_root = dirname(__DIR__, 5);
$module_root = dirname(__DIR__);
require_once $drupal_root . '/vendor/autoload.php';
require_once $module_root . '/src/Manifest/ManifestValidationResult.php';
require_once $module_root . '/src/Manifest/ManifestValidator.php';
require_once $module_root . '/src/Manifest/ManifestRepository.php';

$cases = require __DIR__ . '/manifest-validation-cases.php';
$fixture_validator = ManifestValidator::forTestFixtures();
$fixture_repository = ManifestRepository::forTestFixtures();
$production_validator = new ManifestValidator();
$assertions = 0;
$failures = [];
$results = [];
$fingerprints = [];
$case_ids = [];

$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
  $assertions++;
  if (!$condition) {
    $failures[] = $message;
  }
};

// The exact count and unique IDs make accidental matrix loss visible.
$assert(count($cases) === 99, 'The consolidated adversarial matrix must retain 99 reviewed cases.');
foreach ($cases as $case) {
  $id = $case['id'];
  $assert(is_string($id) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $id) === 1, 'Every case needs a stable lowercase ASCII ID.');
  $assert(!isset($case_ids[$id]), 'Duplicate data-provider case ID: ' . $id);
  $case_ids[$id] = TRUE;
  $result = isset($case['yaml'])
    ? $fixture_repository->validateYaml($case['yaml'])
    : (($case['production_validator'] ?? FALSE)
      ? $production_validator->validate($case['input'])
      : $fixture_validator->validate($case['input']));
  $results[$id] = $result;

  $assert($result->isValid() === $case['valid'], sprintf('%s validity differs.', $id));
  $actual_codes = array_values(array_unique($result->errorCodes()));
  $expected_codes = $case['errors'];
  sort($actual_codes, SORT_STRING);
  sort($expected_codes, SORT_STRING);
  $assert($actual_codes === $expected_codes, sprintf(
    '%s errors differ: expected [%s], got [%s].',
    $id,
    implode(',', $expected_codes),
    implode(',', $actual_codes),
  ));

  if (!$result->isValid()) {
    $assert($result->resources() === [] && $result->publishedResources() === [], $id . ' leaked partial catalogue data.');
    foreach ($result->errors() as $error) {
      $assert(array_keys($error) === ['path', 'code', 'message'], $id . ' emitted a malformed error shape.');
      $assert(!str_contains($error['message'], '://'), $id . ' disclosed an URL in an error.');
      $assert(!str_contains($error['message'], 'secret'), $id . ' disclosed a rejected value.');
    }
  }
  else {
    $assert(preg_match('/^[a-f0-9]{64}$/D', $result->fingerprint()) === 1, $id . ' lacks a deterministic SHA-256 fingerprint.');
  }
  if (isset($case['published'])) {
    $assert($result->publishedCount() === $case['published'], $id . ' published count differs.');
  }
  if (isset($case['themes'])) {
    $assert($result->themes() === $case['themes'], $id . ' theme order differs.');
  }
  if (isset($case['order'])) {
    $actual_order = array_column($result->publishedResources(), 'id');
    $assert($actual_order === $case['order'], $id . ' resource order differs.');
  }
  if (isset($case['fingerprint_group'])) {
    $fingerprints[$case['fingerprint_group']][] = $result->fingerprint();
  }
}

foreach ($fingerprints as $group => $values) {
  $assert(count(array_unique($values)) === 1, $group . ' did not produce one canonical fingerprint.');
}

$one = $results['one-resource'];
$public = $one->publicResourcesForTheme('Thème A');
$assert(count($public) === 1, 'The one-theme public result must contain one resource.');
$assert(array_keys($public[0]) === [
  'title',
  'url',
  'description',
  'theme',
  'type',
  'language',
  'audience',
  'last_verified',
], 'The public allowlist differs.');
foreach (['id', 'editorial_note', 'order', 'published'] as $private_key) {
  $assert(!array_key_exists($private_key, $public[0]), 'Public data leaked ' . $private_key . '.');
}

$four_result = $results['four-resources-several-themes'];
$assert(!$four_result->hasTheme('Thème absent'), 'An unknown GET theme must remain invalid.');
$assert($four_result->publicResourcesForTheme('Thème absent') === [], 'An unknown theme must not return every resource.');
$assert(count($four_result->publicResourcesForTheme('Thème A')) === 2, 'Theme filtering returned an incorrect group.');
$mixed = $results['published-and-unpublished'];
$assert(!$mixed->hasTheme('Thème masqué'), 'An unpublished-only theme must not enter navigation.');

$production_manifest = $drupal_root . '/content/resources/resources.yml';
$production_repository = new ManifestRepository($production_manifest, $production_validator);
$first = $production_repository->load();
$second = $production_repository->load();
$assert($first === $second, 'The repository must memoize one result per request.');
$production_repository->reset();
$third = $production_repository->load();
$assert($third !== $first && $third->fingerprint() === $first->fingerprint(), 'Repository reset must reload deterministically.');
$assert($third->isValid() && !$third->isCatalogueApproved() && $third->publishedCount() === 0, 'The production manifest must remain empty and unapproved.');

$serialized_cases = serialize($cases);
preg_match_all('~https?://(?:[^@\s/]+@)?([^/:\s"?]+)~iu', $serialized_cases, $hosts);
foreach ($hosts[1] ?? [] as $host) {
  $host = strtolower($host);
  $canonical_fixture_host = rtrim($host, '.');
  $fixture_only = $canonical_fixture_host === 'example.invalid'
    || $canonical_fixture_host === 'localhost'
    || $canonical_fixture_host === '['
    || str_ends_with($canonical_fixture_host, '.invalid')
    || filter_var(trim($canonical_fixture_host, '[]'), FILTER_VALIDATE_IP) !== FALSE;
  $assert($fixture_only, 'The data provider contains a real external domain: ' . $host);
}

$manifest_source = '';
foreach ([
  'ManifestRepository.php',
  'ManifestValidationResult.php',
  'ManifestValidator.php',
] as $file) {
  $manifest_source .= file_get_contents($module_root . '/src/Manifest/' . $file);
}
foreach (['GuzzleHttp', 'curl_', 'dns_get_record', 'fsockopen', 'get_headers(', 'gethostbyname', 'gethostbynamel', 'http_client', 'stream_socket_client'] as $network_api) {
  $assert(!str_contains($manifest_source, $network_api), 'Manifest code contains network API ' . $network_api . '.');
}
$assert(str_contains($manifest_source, 'PARSE_OBJECT_FOR_MAP'), 'Strict YAML parsing must preserve empty mapping versus list types.');

if ($failures !== []) {
  fwrite(STDERR, sprintf("FAIL: %d/%d manifest assertions failed.\n", count($failures), $assertions));
  foreach ($failures as $failure) {
    fwrite(STDERR, ' - ' . $failure . PHP_EOL);
  }
  exit(1);
}

fwrite(STDOUT, sprintf(
  "PASS: %d manifest assertions across %d data-driven cases; zero external requests.\n",
  $assertions,
  count($cases),
));
