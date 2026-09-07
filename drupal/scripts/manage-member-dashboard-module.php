#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guarded direct-bootstrap lifecycle helper for one reviewed Drupal module.
 *
 * This script never imports configuration, migrates content, or reads/writes
 * user records. Its only write is the selected Drupal module lifecycle call.
 */

use Composer\Autoload\ClassLoader;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Serialization\Yaml;
use Symfony\Component\HttpFoundation\Request;

const TARGET = 'unisonges_member_dashboard';
const TARGET_PATH = 'modules/custom/unisonges_member_dashboard';
const DECLARED_DEPENDENCIES = [
  'commerce:commerce_order',
  'commerce:commerce_price',
  'drupal:comment',
  'drupal:node',
  'drupal:user',
  'unisonges_structure:unisonges_structure',
  'webform:webform',
];

final class CliError extends RuntimeException {
}

function usage(): string {
  return <<<'TEXT'
Usage: php scripts/manage-member-dashboard-module.php --site-uri=ORIGIN [options]

Dry-run is the default. The fixed target is unisonges_member_dashboard.

  --site-uri=ORIGIN   Required absolute HTTP(S) origin; no path/query/fragment.
  --dry-run           Print the exact plan and token without writing (default).
  --apply             Execute the plan; requires both confirmations below.
  --backup-confirmed  Confirm a current database backup/snapshot.
  --plan-token=HASH   Exact token emitted by the matching dry-run.
  --rollback          Select Drupal uninstall instead of enable.
  -h, --help          Show help; must be the only argument.

Apply example:
  php scripts/manage-member-dashboard-module.php --site-uri=https://example.org \
    --apply --backup-confirmed --plan-token=<HASH_FROM_DRY_RUN>

This directly bootstraps Drupal PHP. It does not call Drush, DDEV, Docker, SSH,
a VPS, config import/export, content migration, or a user-data operation.
TEXT;
}

/** @return array{apply: bool, rollback: bool, site: ?string, token: ?string} */
function options(array $argv): array {
  $out = ['apply' => FALSE, 'rollback' => FALSE, 'site' => NULL, 'token' => NULL];
  $seen = [];
  $mode = NULL;
  for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '-h' || $arg === '--help') {
      if (count($argv) !== 2) {
        throw new CliError('--help must be the only argument.');
      }
      fwrite(STDOUT, usage() . PHP_EOL);
      exit(0);
    }
    if ($arg === '--dry-run' || $arg === '--apply') {
      if ($mode !== NULL) {
        throw new CliError('Choose --dry-run or --apply only once.');
      }
      $mode = $arg;
      $out['apply'] = $arg === '--apply';
      continue;
    }
    if ($arg === '--rollback' || $arg === '--backup-confirmed') {
      if (isset($seen[$arg])) {
        throw new CliError($arg . ' may be supplied only once.');
      }
      $seen[$arg] = TRUE;
      $out[$arg === '--rollback' ? 'rollback' : 'backup'] = TRUE;
      continue;
    }
    $matched = FALSE;
    foreach (['--site-uri' => 'site', '--plan-token' => 'token'] as $flag => $key) {
      if ($arg === $flag || str_starts_with($arg, $flag . '=')) {
        if (isset($seen[$flag])) {
          throw new CliError($flag . ' may be supplied only once.');
        }
        $seen[$flag] = TRUE;
        $value = $arg === $flag ? ($argv[++$i] ?? '') : substr($arg, strlen($flag) + 1);
        if (!is_string($value) || $value === '' || str_starts_with($value, '--')) {
          throw new CliError($flag . ' requires a value.');
        }
        $out[$key] = $value;
        $matched = TRUE;
        break;
      }
    }
    if (!$matched) {
      throw new CliError('Unknown argument: ' . (string) $arg);
    }
  }
  if (!is_string($out['site'])) {
    throw new CliError('Explicit --site-uri=ORIGIN is required.');
  }
  if ($out['apply']) {
    if (($out['backup'] ?? FALSE) !== TRUE) {
      throw new CliError('--apply requires --backup-confirmed.');
    }
    if (!is_string($out['token']) || !preg_match('/\A[a-f0-9]{64}\z/D', $out['token'])) {
      throw new CliError('--apply requires a 64-hex --plan-token from dry-run.');
    }
  }
  elseif (($out['backup'] ?? FALSE) || $out['token'] !== NULL) {
    throw new CliError('--backup-confirmed/--plan-token are valid only with --apply.');
  }
  unset($out['backup']);
  return $out;
}

/** @return array{origin: string, host: string, host_header: string, port: int, scheme: string} */
function site_origin(string $uri): array {
  if ($uri !== trim($uri) || preg_match('/[\x00-\x20\x7f\\\\]/', $uri)) {
    throw new CliError('--site-uri contains unsafe characters.');
  }
  $p = parse_url($uri);
  if (!is_array($p) || !isset($p['scheme'], $p['host'])
    || array_diff_key($p, array_flip(['scheme', 'host', 'port', 'path']))
    || !in_array(strtolower($p['scheme']), ['http', 'https'], TRUE)
    || !in_array($p['path'] ?? '', ['', '/'], TRUE)) {
    throw new CliError('--site-uri must be an absolute HTTP(S) origin only.');
  }
  $scheme = strtolower($p['scheme']);
  $raw_host = strtolower($p['host']);
  $bracketed = str_starts_with($raw_host, '[') && str_ends_with($raw_host, ']');
  if (str_starts_with($raw_host, '[') !== str_ends_with($raw_host, ']')
    || (str_contains($raw_host, ':') && !$bracketed)) {
    throw new CliError('--site-uri IPv6 host must use one matching bracket pair.');
  }
  $host = $bracketed ? substr($raw_host, 1, -1) : $raw_host;
  $ip = filter_var($host, FILTER_VALIDATE_IP) !== FALSE;
  $dns = (bool) preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/D', $host);
  if ((!$ip && !$dns) || str_ends_with($host, '.')) {
    throw new CliError('--site-uri host is invalid or unsupported.');
  }
  $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
  if (!is_int($port) || $port < 1 || $port > 65535) {
    throw new CliError('--site-uri port is invalid.');
  }
  $display_host = str_contains($host, ':') ? '[' . $host . ']' : $host;
  $non_default = !($scheme === 'https' && $port === 443) && !($scheme === 'http' && $port === 80);
  $host_header = $display_host . ($non_default ? ':' . $port : '');
  return compact('scheme', 'host', 'host_header', 'port') + ['origin' => "$scheme://$host_header"];
}

function sorted(mixed $value): mixed {
  if (!is_array($value)) {
    return $value;
  }
  foreach ($value as $key => $item) {
    $value[$key] = sorted($item);
  }
  if (!array_is_list($value)) {
    ksort($value, SORT_STRING);
  }
  return $value;
}

function digest(mixed $value): string {
  return hash('sha256', json_encode(sorted($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

/** @return array{project: string, docroot: string, script: string, loader: ClassLoader} */
function project_paths(): array {
  $project = realpath(__DIR__ . '/..');
  $script = realpath(__FILE__);
  if ($project === FALSE || $script !== $project . '/scripts/' . basename(__FILE__) || is_link(__FILE__)) {
    throw new RuntimeException('Helper path is not the canonical reviewed path.');
  }
  $docroot = realpath($project . '/web');
  $autoload = realpath($project . '/vendor/autoload.php');
  if ($docroot !== $project . '/web' || $autoload !== $project . '/vendor/autoload.php'
    || is_link($docroot) || is_link($autoload) || !is_file($project . '/web/index.php')) {
    throw new RuntimeException('Canonical installed Drupal project paths are unavailable or symlinked.');
  }
  $loader = require $autoload;
  if (!$loader instanceof ClassLoader) {
    throw new RuntimeException('Composer autoload did not return ClassLoader.');
  }
  return compact('project', 'docroot', 'script', 'loader');
}

function bootstrap(array $paths, array $site): string {
  $server = [
    'HTTP_HOST' => $site['host_header'], 'SERVER_NAME' => $site['host'],
    'SERVER_PORT' => (string) $site['port'], 'HTTPS' => $site['scheme'] === 'https' ? 'on' : 'off',
    'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => $paths['docroot'] . '/index.php',
    'REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '127.0.0.1',
  ];
  $_SERVER = array_replace($_SERVER, $server);
  $request = Request::create($site['origin'] . '/', 'GET', [], [], [], $server);
  $kernel = DrupalKernel::createFromRequest($request, $paths['loader'], 'prod', TRUE, $paths['docroot']);
  $site_path = $kernel->getSitePath();
  $site_dir = $paths['docroot'] . '/' . $site_path;
  if (!is_string($site_path) || !preg_match('/\Asites(?:\/[A-Za-z0-9._-]+)+\z/D', $site_path)
    || realpath($site_dir) !== $site_dir || realpath($site_dir . '/settings.php') !== $site_dir . '/settings.php'
    || is_link($site_dir) || is_link($site_dir . '/settings.php')) {
    throw new RuntimeException('Selected Drupal site path/settings.php is unsafe or unavailable.');
  }
  $kernel->boot();
  $kernel->preHandle($request);
  if (realpath(\Drupal::root()) !== $paths['docroot']
    || strtolower(\Drupal::request()->getSchemeAndHttpHost()) !== $site['origin']
    || (int) explode('.', \Drupal::VERSION)[0] !== 11) {
    throw new RuntimeException('Bootstrapped root/origin/Drupal 11 guard failed.');
  }
  return $site_path;
}

/** @return array<string, array<string, array>> */
function config_snapshot(): array {
  $storage = \Drupal::service('config.storage');
  if (!$storage instanceof StorageInterface || $storage->getCollectionName() !== '') {
    throw new RuntimeException('Active config storage is not the default Drupal collection.');
  }
  $collections = array_values(array_unique(array_merge([''], $storage->getAllCollectionNames())));
  sort($collections, SORT_STRING);
  $snapshot = [];
  foreach ($collections as $collection) {
    $current = $collection === '' ? $storage : $storage->createCollection($collection);
    $names = $current->listAll();
    sort($names, SORT_STRING);
    $snapshot[$collection] = $current->readMultiple($names);
    if (count($snapshot[$collection]) !== count($names)) {
      throw new RuntimeException('Active config changed while it was being audited.');
    }
    ksort($snapshot[$collection], SORT_STRING);
  }
  return $snapshot;
}

/** @return array{dependencies: string[], manifest: array<string, string>, list: ModuleExtensionList} */
function source_audit(string $docroot): array {
  $list = \Drupal::service('extension.list.module');
  if (!$list instanceof ModuleExtensionList) {
    throw new RuntimeException('Unexpected module extension-list service.');
  }
  $modules = $list->getList();
  if (!isset($modules[TARGET])) {
    throw new RuntimeException('Exact target module is not discoverable: ' . TARGET . '.');
  }
  $target = $modules[TARGET];
  if ($target->getName() !== TARGET || $target->getType() !== 'module' || $target->getPath() !== TARGET_PATH
    || $target->getPathname() !== TARGET_PATH . '/' . TARGET . '.info.yml'
    || !empty($target->info['core_incompatible'])) {
    throw new RuntimeException('Target module identity/path/compatibility guard failed.');
  }
  $declared = $target->info['dependencies'] ?? [];
  sort($declared, SORT_STRING);
  if ($declared !== DECLARED_DEPENDENCIES) {
    throw new RuntimeException('Dependency drift: expected [' . implode(',', DECLARED_DEPENDENCIES)
      . '], found [' . implode(',', $declared) . '].');
  }
  $dependencies = array_keys($target->requires);
  sort($dependencies, SORT_STRING);
  $directory = $docroot . '/' . TARGET_PATH;
  if (realpath($directory) !== $directory || is_link($directory)) {
    throw new RuntimeException('Target module directory is missing or symlinked.');
  }
  $manifest = [];
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
  foreach ($iterator as $file) {
    if ($file->isLink() || !$file->isFile() || !$file->isReadable()) {
      throw new RuntimeException('Unsafe target source entry: ' . $file->getPathname() . '.');
    }
    $relative = substr($file->getPathname(), strlen($directory) + 1);
    if ($relative === TARGET . '.install' || str_ends_with($relative, '.post_update.php')
      || preg_match('#\A(?:config/(?:install|optional)|src/Entity)/#D', $relative)) {
      throw new RuntimeException('Target unexpectedly owns lifecycle/config/entity source: ' . $relative . '.');
    }
    $contents = file_get_contents($file->getPathname());
    if ($contents === FALSE) {
      throw new RuntimeException('Could not read target source: ' . $relative . '.');
    }
    if (preg_match('/(?:\.php|\.module|\.inc)$/D', $relative)
      && (preg_match('/\bfunction\s+&?\s*' . TARGET . '_(?:schema|install|uninstall|update_|post_update_|entity_(?:base|bundle)_field_info)/i', $contents)
        || preg_match('/\b(?:BaseFieldDefinition|ConfigEntityType|ContentEntityType)\b/', $contents))) {
      throw new RuntimeException('Target source declares forbidden lifecycle/entity schema: ' . $relative . '.');
    }
    $manifest[$relative] = hash('sha256', $contents);
  }
  ksort($manifest, SORT_STRING);
  return compact('dependencies', 'manifest', 'list');
}

function mentions_target(mixed $value): bool {
  if (!is_array($value)) {
    return $value === TARGET;
  }
  foreach ($value as $item) {
    if (mentions_target($item)) {
      return TRUE;
    }
  }
  return FALSE;
}

/** @return array{config: array, modules: array, schema: ?int, state: string, tables: string[]} */
function runtime_audit(string $docroot, array $source, string $action): array {
  if (\Drupal::service('config.installer')->isSyncing()) {
    throw new RuntimeException('Config installer is in syncing/import state.');
  }
  $config = config_snapshot();
  $core = $config['']['core.extension'] ?? NULL;
  if (!is_array($core) || !is_array($core['module'] ?? NULL)
    || sorted(\Drupal::config('core.extension')->get()) !== sorted($core)) {
    throw new RuntimeException('Raw/effective active core.extension mismatch.');
  }
  $modules = $core['module'];
  $configured = array_keys($modules);
  $handled = array_keys(\Drupal::moduleHandler()->getModuleList());
  sort($configured, SORT_STRING);
  sort($handled, SORT_STRING);
  if ($configured !== $handled) {
    throw new RuntimeException('Active config and module-handler lists differ.');
  }
  $schema_store = \Drupal::service('keyvalue')->get('system.schema');
  $schema = $schema_store->get(TARGET, NULL);
  $in_config = array_key_exists(TARGET, $modules);
  $in_handler = \Drupal::moduleHandler()->moduleExists(TARGET);
  $discovered = (int) ($source['list']->get(TARGET)->status ?? 0) === 1;
  if ($in_config && $in_handler && $discovered && $schema === \Drupal::CORE_MINIMUM_SCHEMA_VERSION) {
    $state = 'enabled';
  }
  elseif (!$in_config && !$in_handler && !$discovered && $schema === NULL) {
    $state = 'disabled';
  }
  else {
    throw new RuntimeException('Unknown partial target state (config/handler/discovery/system.schema disagree).');
  }
  foreach ($source['dependencies'] as $dependency) {
    if (!array_key_exists($dependency, $modules) || !\Drupal::moduleHandler()->moduleExists($dependency)) {
      throw new RuntimeException('Dependency is not already enabled: ' . $dependency . '.');
    }
  }
  $owned_config = [];
  foreach ($config as $collection => $objects) {
    foreach ($objects as $name => $data) {
      if ($name === TARGET || str_starts_with($name, TARGET . '.')
        || mentions_target(is_array($data) ? ($data['dependencies'] ?? []) : [])) {
        $owned_config[] = ($collection === '' ? 'default' : $collection) . ':' . $name;
      }
    }
  }
  foreach (array_keys(\Drupal::service('config.manager')->findConfigEntityDependencies('module', [TARGET])) as $name) {
    $owned_config[] = 'default:' . $name;
  }
  if ($owned_config) {
    throw new RuntimeException('Target unexpectedly owns/anchors active config: ' . implode(',', array_unique($owned_config)) . '.');
  }
  $tables = array_values(\Drupal::database()->schema()->findTables('%'));
  sort($tables, SORT_STRING);
  foreach ($tables as $table) {
    if (str_starts_with(strtolower($table), TARGET)) {
      throw new RuntimeException('Target-prefixed schema table indicates partial state: ' . $table . '.');
    }
  }
  if ($action === 'enable' && $state === 'disabled') {
    foreach (array_keys($modules) as $module) {
      $directory = $docroot . '/' . $source['list']->getPath($module) . '/config/optional';
      foreach (is_dir($directory) ? glob($directory . '/*.yml') ?: [] : [] as $file) {
        $text = file_get_contents($file);
        $name = basename($file, '.yml');
        if ($text !== FALSE && (str_contains($text, TARGET) || str_starts_with($name, TARGET . '.'))) {
          $data = Yaml::decode($text);
          if (str_starts_with($name, TARGET . '.')
            || mentions_target(is_array($data) ? ($data['dependencies'] ?? []) : [])) {
            throw new RuntimeException('Enable could create site-optional config: ' . basename($file) . '.');
          }
        }
      }
    }
  }
  if ($action === 'uninstall' && $state === 'enabled') {
    $reasons = \Drupal::service('module_installer')->validateUninstall([TARGET]);
    if ($reasons) {
      throw new RuntimeException('Drupal uninstall validator refused: ' . implode('; ', array_map(
        static fn(array $items): string => implode(', ', array_map('strval', $items)),
        $reasons,
      )) . '.');
    }
  }
  return compact('config', 'modules', 'schema', 'state', 'tables');
}

function expected_config(array $before, string $action): array {
  if ($action === 'enable') {
    $before['']['core.extension']['module'][TARGET] = 0;
  }
  else {
    unset($before['']['core.extension']['module'][TARGET]);
  }
  return $before;
}

function main(array $argv): int {
  if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('CLI execution is required.');
  }
  $option = options($argv);
  if (PHP_VERSION_ID < 80300) {
    throw new RuntimeException('CLI PHP 8.3+ is required by the locked Drupal project.');
  }
  $site = site_origin($option['site']);
  $paths = project_paths();
  $site_path = bootstrap($paths, $site);
  $action = $option['rollback'] ? 'uninstall' : 'enable';
  $mode = $option['apply'] ? 'apply' : 'dry-run';
  $source = source_audit($paths['docroot']);
  $before = runtime_audit($paths['docroot'], $source, $action);
  $payload = [
    'version' => 1, 'site' => $site['origin'], 'site_path' => $site_path,
    'action' => $action, 'state' => $before['state'], 'schema' => $before['schema'],
    'module' => TARGET, 'manifest' => $source['manifest'], 'dependencies' => $source['dependencies'],
    'active_config' => digest($before['config']), 'tables' => digest($before['tables']),
    'helper' => hash_file('sha256', $paths['script']), 'drupal' => \Drupal::VERSION,
  ];
  $token = digest($payload);
  $change = ($action === 'enable') === ($before['state'] === 'disabled');

  printf("MODE %s\nSITE %s path=%s\nTARGET %s\nDEPENDENCIES already-enabled=[%s]\n",
    $mode, $site['origin'], $site_path, TARGET, implode(',', $source['dependencies']));
  echo 'STATE ' . $before['state'] . ' config=handler=system.schema' . PHP_EOL;
  echo 'OWNERSHIP schema_tables=none active_config=none default_config=none' . PHP_EOL;
  echo 'SCOPE no-config-import no-content-migration no-user-data auto-dependencies=FALSE' . PHP_EOL;
  if (!$change) {
    echo 'PLAN NO CHANGE; target already ' . $before['state'] . '.' . PHP_EOL;
  }
  elseif ($action === 'enable') {
    echo 'PLAN ENABLE ONLY ' . TARGET . '; install([target], FALSE); add core.extension entry=0 '
      . 'and system.schema=' . \Drupal::CORE_MINIMUM_SCHEMA_VERSION . '; rebuild Drupal routes/caches.' . PHP_EOL;
  }
  else {
    echo 'PLAN DISABLE/UNINSTALL ONLY ' . TARGET . '; uninstall([target], FALSE); remove core.extension '
      . 'and system.schema entries; rebuild Drupal routes/caches.' . PHP_EOL;
  }
  echo 'PLAN_TOKEN ' . $token . PHP_EOL;
  if (!$option['apply']) {
    echo 'DRY_RUN_OK no lifecycle write attempted.' . PHP_EOL;
    return 0;
  }
  if (!hash_equals($token, $option['token'])) {
    throw new RuntimeException('Plan token mismatch; rerun dry-run against current state.');
  }
  if (!$change) {
    echo 'NO_CHANGE verified idempotent state; module_installer was not called.' . PHP_EOL;
    return 0;
  }
  $fresh_source = source_audit($paths['docroot']);
  $fresh = runtime_audit($paths['docroot'], $fresh_source, $action);
  if (digest($fresh_source['manifest']) !== digest($source['manifest'])
    || digest($fresh['config']) !== digest($before['config']) || $fresh['tables'] !== $before['tables']) {
    throw new RuntimeException('Code/site changed after planning; no lifecycle write attempted.');
  }
  $installer = \Drupal::service('module_installer');
  if (!$installer instanceof ModuleInstallerInterface) {
    throw new RuntimeException('Unexpected module_installer service.');
  }
  $ok = $action === 'enable'
    ? $installer->install([TARGET], FALSE)
    : $installer->uninstall([TARGET], FALSE);
  if ($ok !== TRUE) {
    throw new RuntimeException('Drupal module_installer returned failure; inspect the backup.');
  }
  $after_source = source_audit($paths['docroot']);
  $after = runtime_audit($paths['docroot'], $after_source, $action);
  $wanted = $action === 'enable' ? 'enabled' : 'disabled';
  if ($after['state'] !== $wanted || sorted($after['config']) !== sorted(expected_config($fresh['config'], $action))
    || $after['tables'] !== $fresh['tables'] || $after_source['manifest'] !== $fresh_source['manifest']) {
    throw new RuntimeException('Unexpected post-state; restore/investigate the confirmed backup.');
  }
  echo 'APPLIED ' . ($action === 'enable' ? 'enabled ' : 'uninstalled ') . TARGET
    . ' only; config/handler/schema agree; no config or table drift.' . PHP_EOL;
  return 0;
}

try {
  exit(main($argv));
}
catch (CliError $e) {
  fwrite(STDERR, 'REFUSE ' . $e->getMessage() . PHP_EOL . usage() . PHP_EOL);
  exit(2);
}
catch (Throwable $e) {
  $message = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $e->getMessage());
  fwrite(STDERR, 'REFUSE ' . ($message ?: get_class($e)) . PHP_EOL);
  exit(1);
}
