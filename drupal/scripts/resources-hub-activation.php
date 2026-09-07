<?php

declare(strict_types=1);

/**
 * @file
 * Single dry-run-first CLI for the guarded Resources feature.
 *
 * This file is also required by its static planner tests. Defining
 * UNISONGES_RESOURCES_HELPER_LIBRARY_ONLY suppresses the CLI entry point.
 */

const RESOURCES_MODULE = 'unisonges_resources';
const RESOURCES_CONFIG = 'unisonges_resources.settings';
const RESOURCES_ROUTE = 'unisonges_resources.page';
const RESOURCES_PATH = '/ressources';
const RESOURCES_ACCESS = '_unisonges_resources_access';
const RESOURCES_MENU = 'unisonges_resources.page';
const RESOURCES_LOCK = 'unisonges_resources.activation';
const RESOURCES_BLOCKED = 3;

function resources_canonicalize(mixed $value): mixed {
  if (!is_array($value)) {
    return $value;
  }
  if (!array_is_list($value)) {
    ksort($value, SORT_STRING);
  }
  foreach ($value as $key => $item) {
    $value[$key] = resources_canonicalize($item);
  }
  return $value;
}

/**
 * Pure, deterministic planner shared by the CLI and static tests.
 *
 * @param array<string, mixed> $manifest
 * @param array<string, mixed>|null $state
 *
 * @return array<string, mixed>
 */
function resources_plan(string $action, array $manifest, ?array $state, string $contract): array {
  if (!in_array($action, ['activate', 'disable'], TRUE)
    || preg_match('/^[a-f0-9]{64}$/D', $contract) !== 1) {
    throw new InvalidArgumentException('Unknown action or malformed contract fingerprint.');
  }
  $expected_manifest_keys = [
    'approved',
    'error_codes',
    'fingerprint',
    'published',
    'themes',
    'total',
    'valid',
  ];
  $manifest_keys = array_keys($manifest);
  sort($manifest_keys, SORT_STRING);
  if ($manifest_keys !== $expected_manifest_keys
    || !is_bool($manifest['valid'])
    || !is_bool($manifest['approved'])
    || !is_int($manifest['total'])
    || !is_int($manifest['published'])
    || !is_int($manifest['themes'])
    || min($manifest['total'], $manifest['published'], $manifest['themes']) < 0
    || $manifest['published'] > $manifest['total']
    || !is_string($manifest['fingerprint'])
    || !is_array($manifest['error_codes'])
    || !array_is_list($manifest['error_codes'])) {
    throw new InvalidArgumentException('Malformed manifest summary.');
  }
  foreach ($manifest['error_codes'] as $code) {
    if (!is_string($code) || preg_match('/^[a-z0-9_]{1,64}$/D', $code) !== 1) {
      throw new InvalidArgumentException('Malformed manifest error code.');
    }
  }
  $manifest['error_codes'] = array_values(array_unique($manifest['error_codes']));
  sort($manifest['error_codes'], SORT_STRING);
  if ($manifest['valid'] && preg_match('/^[a-f0-9]{64}$/D', $manifest['fingerprint']) !== 1) {
    throw new InvalidArgumentException('A valid manifest needs a SHA-256 fingerprint.');
  }

  $blockers = [];
  if ($action === 'activate') {
    if (!$manifest['valid']) {
      $blockers[] = 'manifest_invalid';
    }
    if (!$manifest['approved']) {
      $blockers[] = 'catalogue_not_approved';
    }
    if ($manifest['published'] === 0) {
      $blockers[] = 'no_published_resources';
    }
    if ($manifest['published'] > 20 || in_array('model_b_required', $manifest['error_codes'], TRUE)) {
      $blockers[] = 'model_a_limit_exceeded';
    }
  }
  if ($blockers !== []) {
    if ($state !== NULL) {
      throw new InvalidArgumentException('Content-blocked activation must skip runtime inspection.');
    }
    return resources_finalize_plan($action, $manifest, ['inspection' => 'skipped'], $contract, [], $blockers);
  }
  if ($state === NULL) {
    throw new InvalidArgumentException('Runtime state is required for an actionable plan.');
  }

  $expected_state_keys = [
    'config_exists',
    'effective_config',
    'menu_state',
    'module_installed',
    'route_state',
    'settings_overridden',
    'stored_config',
  ];
  $state_keys = array_keys($state);
  sort($state_keys, SORT_STRING);
  if ($state_keys !== $expected_state_keys
    || !is_bool($state['module_installed'])
    || !is_bool($state['config_exists'])
    || !is_bool($state['settings_overridden'])
    || !is_string($state['route_state'])
    || !is_string($state['menu_state'])) {
    throw new InvalidArgumentException('Malformed runtime state.');
  }

  $state_blockers = [];
  $kind = 'unknown';
  if (!$state['module_installed'] && !$state['config_exists']) {
    if ($state['stored_config'] !== NULL || $state['effective_config'] !== NULL) {
      $state_blockers[] = 'unexpected_config_while_absent';
    }
    $kind = 'absent';
  }
  elseif ($state['module_installed'] !== $state['config_exists']) {
    $state_blockers[] = 'partial_module_config_state';
  }
  elseif (!is_array($state['stored_config']) || !is_array($state['effective_config'])) {
    $state_blockers[] = 'settings_missing';
  }
  elseif ($state['settings_overridden'] || $state['stored_config'] !== $state['effective_config']) {
    $state_blockers[] = 'settings_override';
  }
  elseif (!resources_known_settings($state['stored_config'])) {
    $state_blockers[] = 'settings_shape_unknown';
  }
  elseif ($state['stored_config']['enabled'] === FALSE
    && $state['stored_config']['manifest_fingerprint'] === '') {
    $kind = 'disabled';
  }
  elseif ($state['stored_config']['enabled'] === TRUE
    && preg_match('/^[a-f0-9]{64}$/D', $state['stored_config']['manifest_fingerprint']) === 1) {
    $kind = 'active';
  }
  else {
    $state_blockers[] = 'settings_values_unknown';
  }

  if ($action === 'activate') {
    if ($kind === 'absent' && ($state['route_state'] !== 'absent' || $state['menu_state'] !== 'absent')) {
      $state_blockers[] = 'runtime_contract_present_while_absent';
    }
    if ($kind !== 'absent' && ($state['route_state'] !== 'exact' || $state['menu_state'] !== 'exact')) {
      $state_blockers[] = 'runtime_contract_drift';
    }
  }
  if ($state_blockers !== []) {
    return resources_finalize_plan($action, $manifest, $state, $contract, [], $state_blockers);
  }

  $actions = [];
  if ($action === 'disable') {
    if ($kind === 'active') {
      $actions[] = 'set_settings_disabled';
    }
  }
  elseif ($kind === 'absent') {
    $actions = ['install_unisonges_resources', 'set_settings_enabled'];
  }
  elseif ($kind === 'disabled') {
    $actions[] = 'set_settings_enabled';
  }
  elseif (!hash_equals($manifest['fingerprint'], $state['stored_config']['manifest_fingerprint'])) {
    $actions[] = 'set_settings_enabled';
  }

  return resources_finalize_plan($action, $manifest, $state, $contract, $actions, []);
}

/** @return array<string, mixed> */
function resources_finalize_plan(
  string $action,
  array $manifest,
  array $state,
  string $contract,
  array $actions,
  array $blockers,
): array {
  $plan = resources_canonicalize([
    'version' => 2,
    'action' => $action,
    'status' => $blockers !== [] ? 'BLOCKED' : ($actions === [] ? 'NO_CHANGE' : 'READY'),
    'contract_fingerprint' => $contract,
    'manifest' => $manifest,
    'state' => $state,
    'actions' => $actions,
    'blockers' => array_values(array_unique($blockers)),
  ]);
  $plan['token'] = hash('sha256', json_encode(
    $plan,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
  ));
  return $plan;
}

function resources_known_settings(array $settings): bool {
  $keys = array_keys($settings);
  sort($keys, SORT_STRING);
  if ($keys !== ['enabled', 'manifest_fingerprint']
    && $keys !== ['_core', 'enabled', 'manifest_fingerprint']) {
    return FALSE;
  }
  return is_bool($settings['enabled'])
    && is_string($settings['manifest_fingerprint'])
    && (!isset($settings['_core'])
      || (is_array($settings['_core'])
        && array_keys($settings['_core']) === ['default_config_hash']
        && is_string($settings['_core']['default_config_hash'])));
}

function resources_activation_content_blocked(array $manifest): bool {
  return $manifest['valid'] !== TRUE
    || $manifest['approved'] !== TRUE
    || ($manifest['published'] ?? 0) < 1
    || ($manifest['published'] ?? 0) > 20
    || in_array('model_b_required', $manifest['error_codes'] ?? [], TRUE);
}

function resources_line(string $name, string $value): void {
  print $name . ' ' . $value . PHP_EOL;
}

function resources_require_file(string $path): string {
  $real = realpath($path);
  if (!is_file($path) || !is_readable($path) || is_link($path) || $real !== $path) {
    throw new RuntimeException('A required project file is missing, unreadable, or not an exact regular file.');
  }
  return $path;
}

/** @return array<string, mixed> */
function resources_yaml(string $path): array {
  $parsed = Symfony\Component\Yaml\Yaml::parseFile(
    resources_require_file($path),
    Symfony\Component\Yaml\Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
  );
  if (!is_array($parsed) || array_is_list($parsed)) {
    throw new RuntimeException('A reviewed module contract must be a YAML mapping.');
  }
  return $parsed;
}

/**
 * Verifies critical static contracts and fingerprints every production file.
 */
function resources_contract_fingerprint(string $drupal_root): string {
  $module = $drupal_root . '/web/modules/custom/' . RESOURCES_MODULE;
  $routing = resources_yaml($module . '/unisonges_resources.routing.yml');
  $menu = resources_yaml($module . '/unisonges_resources.links.menu.yml');
  $defaults = resources_yaml($module . '/config/install/unisonges_resources.settings.yml');
  $info = resources_yaml($module . '/unisonges_resources.info.yml');
  if (count($routing) !== 1
    || count($menu) !== 1
    || ($info['type'] ?? NULL) !== 'module'
    || ($info['core_version_requirement'] ?? NULL) !== '^11'
    || isset($info['dependencies'])
    || $defaults !== ['enabled' => FALSE, 'manifest_fingerprint' => '']
    || ($routing[RESOURCES_ROUTE]['path'] ?? NULL) !== RESOURCES_PATH
    || ($routing[RESOURCES_ROUTE]['defaults']['_controller'] ?? NULL) !== '\\Drupal\\unisonges_resources\\Controller\\ResourcesController::page'
    || ($routing[RESOURCES_ROUTE]['defaults']['_title'] ?? NULL) !== 'Ressources'
    || ($routing[RESOURCES_ROUTE]['requirements'][RESOURCES_ACCESS] ?? NULL) !== 'TRUE'
    || ($routing[RESOURCES_ROUTE]['options']['no_cache'] ?? NULL) !== TRUE
    || ($menu[RESOURCES_MENU]['title'] ?? NULL) !== 'Ressources'
    || ($menu[RESOURCES_MENU]['route_name'] ?? NULL) !== RESOURCES_ROUTE
    || ($menu[RESOURCES_MENU]['menu_name'] ?? NULL) !== 'main'
    || ($menu[RESOURCES_MENU]['weight'] ?? NULL) !== 25
    || array_key_exists('parent', $menu[RESOURCES_MENU] ?? [])
    || array_key_exists('expanded', $menu[RESOURCES_MENU] ?? [])) {
    throw new RuntimeException('The module, route, menu, or disabled-default contract differs from review.');
  }

  $hashes = ['helper' => hash_file('sha256', __FILE__)];
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($module, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
  );
  foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
      continue;
    }
    if ($file->isLink()) {
      throw new RuntimeException('The production module tree must not contain symlinks.');
    }
    $relative = substr($file->getPathname(), strlen($module) + 1);
    if (str_starts_with($relative, 'tests/')) {
      continue;
    }
    $hashes[$relative] = hash_file('sha256', $file->getPathname());
  }
  ksort($hashes, SORT_STRING);
  return hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

function resources_require_manifest_classes(string $drupal_root): void {
  $source = $drupal_root . '/web/modules/custom/' . RESOURCES_MODULE . '/src/Manifest';
  foreach (['ManifestValidationResult.php', 'ManifestValidator.php', 'ManifestRepository.php'] as $file) {
    require_once resources_require_file($source . '/' . $file);
  }
}

/** @return array<string, mixed> */
function resources_manifest_summary(string $drupal_root): array {
  $repository = new Drupal\unisonges_resources\Manifest\ManifestRepository(
    resources_require_file($drupal_root . '/content/resources/resources.yml'),
    new Drupal\unisonges_resources\Manifest\ManifestValidator(),
  );
  $result = $repository->load();
  return [
    'valid' => $result->isValid(),
    'approved' => $result->isCatalogueApproved(),
    'total' => $result->totalCount(),
    'published' => $result->publishedCount(),
    'themes' => $result->themeCount(),
    'fingerprint' => $result->fingerprint(),
    'error_codes' => $result->errorCodes(),
  ];
}

function resources_route_state(object $container, bool $installed): string {
  try {
    $route = $container->get('router.route_provider')->getRouteByName(RESOURCES_ROUTE);
  }
  catch (Symfony\Component\Routing\Exception\RouteNotFoundException) {
    return $installed ? 'missing' : 'absent';
  }
  if (!$installed) {
    return 'drift';
  }
  $exact = $route->getPath() === RESOURCES_PATH
    && $route->getDefault('_controller') === '\\Drupal\\unisonges_resources\\Controller\\ResourcesController::page'
    && $route->getDefault('_title') === 'Ressources'
    && $route->getRequirement(RESOURCES_ACCESS) === 'TRUE'
    && $route->getOption('no_cache') === TRUE;
  if (!$exact) {
    return 'drift';
  }
  try {
    $match = $container->get('router.no_access_checks')->match(RESOURCES_PATH);
  }
  catch (Throwable) {
    return 'drift';
  }
  return ($match['_route'] ?? NULL) === RESOURCES_ROUTE ? 'exact' : 'drift';
}

function resources_menu_state(object $container, bool $installed): string {
  $storage = $container->get('config.storage');
  $overrides = $storage->read('core.menu.static_menu_link_overrides');
  if (!is_array($overrides) || !is_array($overrides['definitions'] ?? NULL)) {
    return 'drift';
  }
  if (array_key_exists('unisonges_resources__page', $overrides['definitions'])) {
    return 'override';
  }
  $manager = $container->get('plugin.manager.menu.link');
  $definitions = $manager->getDefinitions();
  if (!isset($definitions[RESOURCES_MENU])) {
    return $installed ? 'missing' : 'absent';
  }
  if (!$installed) {
    return 'drift';
  }
  foreach ($definitions as $id => $definition) {
    if ($id !== RESOURCES_MENU && ($definition['parent'] ?? '') === RESOURCES_MENU) {
      return 'drift';
    }
  }
  try {
    $link = $manager->createInstance(RESOURCES_MENU);
  }
  catch (Throwable) {
    return 'drift';
  }
  return $link->getRouteName() === RESOURCES_ROUTE
    && $link->getMenuName() === 'main'
    && $link->getParent() === ''
    && $link->getWeight() === 25
    && $link->getProvider() === RESOURCES_MODULE
    && $link->isEnabled()
    && !$link->isExpanded()
    ? 'exact'
    : 'drift';
}

/** @return array<string, mixed> */
function resources_runtime_state(object $container, bool $inspect_contract): array {
  $storage = $container->get('config.storage');
  $factory = $container->get('config.factory');
  $factory->reset('core.extension');
  $factory->reset(RESOURCES_CONFIG);
  $core = $storage->read('core.extension');
  if (!is_array($core) || !is_array($core['module'] ?? NULL)) {
    throw new RuntimeException('Stored extension state is malformed.');
  }
  $installed = array_key_exists(RESOURCES_MODULE, $core['module']);
  $stored_module = $core['module'][RESOURCES_MODULE] ?? NULL;
  $effective_module = $factory->get('core.extension')->get('module.' . RESOURCES_MODULE);
  if ($effective_module !== $stored_module) {
    throw new RuntimeException('A runtime override affects the Resources module state.');
  }
  if ($container->get('module_handler')->moduleExists(RESOURCES_MODULE) !== $installed) {
    throw new RuntimeException('Stored and booted module state disagree.');
  }
  $extension = $container->get('extension.list.module')->get(RESOURCES_MODULE);
  if ($extension->getPath() !== 'modules/custom/' . RESOURCES_MODULE) {
    throw new RuntimeException('Drupal resolved the module outside its reviewed path.');
  }
  $stored = $storage->read(RESOURCES_CONFIG);
  $config_exists = is_array($stored);
  $config = $factory->get(RESOURCES_CONFIG);
  $effective = $config->get();
  return [
    'module_installed' => $installed,
    'config_exists' => $config_exists,
    'stored_config' => $config_exists ? $stored : NULL,
    'effective_config' => $config_exists || $effective !== [] ? $effective : NULL,
    'settings_overridden' => $config->hasOverrides(),
    'route_state' => $inspect_contract ? resources_route_state($container, $installed) : 'unchecked',
    'menu_state' => $inspect_contract ? resources_menu_state($container, $installed) : 'unchecked',
  ];
}

/** @return array{0: object, 1: object} */
function resources_bootstrap(string $drupal_root): array {
  resources_require_file($drupal_root . '/web/sites/default/settings.php');
  $autoload = require resources_require_file($drupal_root . '/web/autoload.php');
  $request = Symfony\Component\HttpFoundation\Request::create('http://localhost/', 'GET', [], [], [], [
    'SCRIPT_FILENAME' => $drupal_root . '/web/index.php',
    'SCRIPT_NAME' => '/index.php',
    'REMOTE_ADDR' => '127.0.0.1',
  ]);
  $previous = getcwd();
  if (!chdir($drupal_root . '/web')) {
    throw new RuntimeException('Could not enter the Drupal webroot.');
  }
  try {
    $kernel = Drupal\Core\DrupalKernel::createFromRequest($request, $autoload, 'prod');
    $kernel->boot();
    $request->attributes->set(Drupal\Core\Routing\RouteObjectInterface::ROUTE_OBJECT, new Symfony\Component\Routing\Route('<none>'));
    $request->attributes->set(Drupal\Core\Routing\RouteObjectInterface::ROUTE_NAME, '<none>');
    $kernel->preHandle($request);
    $container = $kernel->getContainer();
  }
  finally {
    if (is_string($previous)) {
      chdir($previous);
    }
  }
  if (($container->getParameter('site.path') ?? NULL) !== 'sites/default') {
    throw new RuntimeException('Only the installed sites/default site is supported.');
  }
  return [$kernel, $container];
}

function resources_print_plan(string $mode, array $plan): void {
  $manifest = $plan['manifest'];
  resources_line('MODE', $mode);
  resources_line('ACTION', $plan['action']);
  resources_line('MANIFEST', sprintf(
    'valid=%s approved=%s total=%d published=%d unpublished=%d themes=%d fingerprint=%s',
    $manifest['valid'] ? 'true' : 'false',
    $manifest['approved'] ? 'true' : 'false',
    $manifest['total'],
    $manifest['published'],
    $manifest['total'] - $manifest['published'],
    $manifest['themes'],
    $manifest['fingerprint'] ?: 'unavailable',
  ));
  if ($manifest['error_codes'] !== []) {
    resources_line('MANIFEST_ERRORS', implode(',', $manifest['error_codes']));
  }
  if (($plan['state']['inspection'] ?? NULL) === 'skipped') {
    resources_line('STATE', 'not-inspected because content already blocks activation');
  }
  else {
    $stored = $plan['state']['stored_config'];
    $enabled = is_array($stored) && array_key_exists('enabled', $stored)
      ? ($stored['enabled'] ? 'true' : 'false')
      : 'unavailable';
    resources_line('STATE', sprintf(
      'module=%s config=%s enabled=%s route=%s menu=%s overrides=%s',
      $plan['state']['module_installed'] ? 'installed' : 'absent',
      $plan['state']['config_exists'] ? 'present' : 'absent',
      $enabled,
      $plan['state']['route_state'],
      $plan['state']['menu_state'],
      $plan['state']['settings_overridden'] ? 'true' : 'false',
    ));
  }
  foreach ($plan['actions'] as $action) {
    resources_line('PLAN', match ($action) {
      'install_unisonges_resources' => 'Install only module unisonges_resources with disabled defaults.',
      'set_settings_enabled' => 'Set only enabled=true and the current manifest fingerprint.',
      'set_settings_disabled' => 'Set only enabled=false and clear the manifest fingerprint.',
      default => throw new RuntimeException('Planner emitted an unknown action.'),
    });
  }
  if ($plan['actions'] === []) {
    resources_line('PLAN', 'No module or configuration write.');
  }
  if ($plan['blockers'] !== []) {
    resources_line('BLOCKERS', implode(',', $plan['blockers']));
  }
  resources_line('PLAN_TOKEN', $plan['token']);
}

/** @return array{action: string, mode: string, token: ?string} */
function resources_arguments(array $argv): array {
  $action = 'activate';
  $mode = 'dry-run';
  $token = NULL;
  $seen_action = FALSE;
  $seen_mode = FALSE;
  foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--activate', '--disable'], TRUE) && !$seen_action) {
      $action = substr($argument, 2);
      $seen_action = TRUE;
    }
    elseif (in_array($argument, ['--dry-run', '--apply'], TRUE) && !$seen_mode) {
      $mode = substr($argument, 2);
      $seen_mode = TRUE;
    }
    elseif (is_string($argument) && str_starts_with($argument, '--plan-token=') && $token === NULL) {
      $token = substr($argument, 13);
    }
    else {
      throw new InvalidArgumentException('Unknown, duplicate, or conflicting option.');
    }
  }
  if ($mode === 'apply' && (!is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1)) {
    throw new InvalidArgumentException('--apply requires --plan-token=<64 lowercase hex>.');
  }
  if ($mode === 'dry-run' && $token !== NULL) {
    throw new InvalidArgumentException('A plan token is accepted only with --apply.');
  }
  return ['action' => $action, 'mode' => $mode, 'token' => $token];
}

/**
 * Restores only an exact helper-written settings snapshot.
 */
function resources_restore_settings(object $container, array $expected_current, array $restore): void {
  if (!resources_known_settings($expected_current) || !resources_known_settings($restore)) {
    throw new RuntimeException('Rollback settings have an unknown shape.');
  }
  $factory = $container->get('config.factory');
  $storage = $container->get('config.storage');
  $factory->reset(RESOURCES_CONFIG);
  $stored = $storage->read(RESOURCES_CONFIG);
  $effective = $factory->get(RESOURCES_CONFIG);
  if ($effective->hasOverrides()) {
    throw new RuntimeException('A runtime override appeared; rollback will not overwrite it.');
  }
  if ($stored === $restore && $effective->get() === $restore) {
    return;
  }
  if ($stored !== $expected_current || $effective->get() !== $expected_current) {
    throw new RuntimeException('Owned settings drifted; rollback will not overwrite them.');
  }
  $factory->getEditable(RESOURCES_CONFIG)->setData($restore)->save(TRUE);
  $factory->reset(RESOURCES_CONFIG);
  $restored = $factory->get(RESOURCES_CONFIG);
  if ($storage->read(RESOURCES_CONFIG) !== $restore
    || $restored->hasOverrides()
    || $restored->get() !== $restore) {
    throw new RuntimeException('Exact settings rollback did not verify.');
  }
}

function resources_save_settings(object $container, array $expected, bool $enabled, string $fingerprint): array {
  if (!resources_known_settings($expected)) {
    throw new RuntimeException('Owned settings have an unknown shape.');
  }
  $factory = $container->get('config.factory');
  $storage = $container->get('config.storage');
  $factory->reset(RESOURCES_CONFIG);
  $current = $storage->read(RESOURCES_CONFIG);
  $effective = $factory->get(RESOURCES_CONFIG);
  if ($current !== $expected || $effective->hasOverrides() || $effective->get() !== $expected) {
    throw new RuntimeException('Owned settings changed after dry-run.');
  }
  $written = $expected;
  $written['enabled'] = $enabled;
  $written['manifest_fingerprint'] = $fingerprint;
  try {
    $factory->getEditable(RESOURCES_CONFIG)->setData($written)->save(TRUE);
    $factory->reset(RESOURCES_CONFIG);
    $after = $storage->read(RESOURCES_CONFIG);
    if ($after !== $written
      || $factory->get(RESOURCES_CONFIG)->hasOverrides()
      || $factory->get(RESOURCES_CONFIG)->get() !== $after) {
      throw new RuntimeException('Owned settings save did not verify.');
    }
    return $after;
  }
  catch (Throwable $write_error) {
    if (!$enabled) {
      throw new RuntimeException(
        'Disable write failed; no rollback toward an enabled state was attempted.',
        0,
        $write_error,
      );
    }
    try {
      resources_restore_settings($container, $written, $expected);
    }
    catch (Throwable $rollback_error) {
      throw new RuntimeException(
        'Settings write and exact rollback both failed; inspect the local site before retrying.',
        0,
        $write_error,
      );
    }
    throw new RuntimeException('Settings write failed; the previous owned settings were restored.', 0, $write_error);
  }
}

function resources_main(array $argv): int {
  try {
    $arguments = resources_arguments($argv);
    $drupal_root = realpath(dirname(__DIR__));
    if (!is_string($drupal_root) || $drupal_root !== dirname(__DIR__)) {
      throw new RuntimeException('The Drupal project path is not canonical.');
    }
    require_once resources_require_file($drupal_root . '/vendor/autoload.php');

    if ($arguments['action'] === 'activate') {
      resources_require_manifest_classes($drupal_root);
      $contract = resources_contract_fingerprint($drupal_root);
      $manifest = resources_manifest_summary($drupal_root);
      if (resources_activation_content_blocked($manifest)) {
        $preflight = resources_plan('activate', $manifest, NULL, $contract);
        resources_print_plan($arguments['mode'], $preflight);
        resources_line('BLOCKED', 'Activation content policy failed; zero Drupal writes were attempted.');
        return RESOURCES_BLOCKED;
      }
    }
    else {
      $contract = hash_file('sha256', __FILE__);
      $manifest = [
        'valid' => FALSE,
        'approved' => FALSE,
        'total' => 0,
        'published' => 0,
        'themes' => 0,
        'fingerprint' => '',
        'error_codes' => ['not_inspected_for_disable'],
      ];
    }

    if (PHP_VERSION_ID < 80300) {
      throw new RuntimeException('Actionable Drupal operations require PHP 8.3 or newer.');
    }
    [$kernel, $container] = resources_bootstrap($drupal_root);
    register_shutdown_function(static function () use ($kernel): void {
      try {
        $kernel->shutdown();
      }
      catch (Throwable) {
      }
    });

    $inspect_contract = $arguments['action'] === 'activate';
    $state = resources_runtime_state($container, $inspect_contract);
    $plan = resources_plan($arguments['action'], $manifest, $state, $contract);
    resources_print_plan($arguments['mode'], $plan);
    if ($plan['status'] === 'BLOCKED') {
      resources_line('BLOCKED', 'Unknown or drifted runtime state; zero planned writes were attempted.');
      return RESOURCES_BLOCKED;
    }
    if ($arguments['mode'] === 'dry-run') {
      resources_line($plan['status'] === 'NO_CHANGE' ? 'NO_CHANGE' : 'DRY_RUN_READY', 'No write was attempted.');
      return 0;
    }
    if (!hash_equals($plan['token'], (string) $arguments['token'])) {
      throw new RuntimeException('Plan token mismatch; rerun dry-run.');
    }
    if ($plan['status'] === 'NO_CHANGE') {
      resources_line('NO_CHANGE', 'The requested state already matches; no write was attempted.');
      return 0;
    }

    $lock = $container->get('lock.persistent');
    if (!$lock->acquire(RESOURCES_LOCK, 900.0)) {
      throw new RuntimeException('Another Resources operation holds the lock.');
    }
    $activation_before = NULL;
    $activation_after = NULL;
    try {
      if ($arguments['action'] === 'activate') {
        $locked_contract = resources_contract_fingerprint($drupal_root);
        $locked_manifest = resources_manifest_summary($drupal_root);
      }
      else {
        $locked_contract = hash_file('sha256', __FILE__);
        $locked_manifest = $manifest;
      }
      $locked_state = resources_runtime_state($container, $inspect_contract);
      $locked_plan = resources_plan($arguments['action'], $locked_manifest, $locked_state, $locked_contract);
      if ($locked_plan['status'] !== 'READY'
        || !hash_equals($locked_plan['token'], (string) $arguments['token'])) {
        throw new RuntimeException('Manifest, contract, or state changed after dry-run.');
      }

      if (in_array('install_unisonges_resources', $locked_plan['actions'], TRUE)) {
        if (!$container->get('module_installer')->install([RESOURCES_MODULE], FALSE)) {
          throw new RuntimeException('Drupal did not confirm the targeted module installation.');
        }
        $container = Drupal::getContainer();
        $locked_state = resources_runtime_state($container, TRUE);
        if (!$locked_state['module_installed']
          || !$locked_state['config_exists']
          || $locked_state['route_state'] !== 'exact'
          || $locked_state['menu_state'] !== 'exact'
          || ($locked_state['stored_config']['enabled'] ?? NULL) !== FALSE
          || ($locked_state['stored_config']['manifest_fingerprint'] ?? NULL) !== '') {
          throw new RuntimeException('Fresh installation did not remain closed with exact route/menu contracts.');
        }
      }

      if (in_array('set_settings_enabled', $locked_plan['actions'], TRUE)) {
        if (!hash_equals(resources_contract_fingerprint($drupal_root), $locked_contract)
          || resources_manifest_summary($drupal_root) !== $locked_manifest
          || resources_route_state($container, TRUE) !== 'exact'
          || resources_menu_state($container, TRUE) !== 'exact') {
          throw new RuntimeException('Activation inputs changed before the settings write.');
        }
        $activation_before = $locked_state['stored_config'];
        $activation_after = resources_save_settings(
          $container,
          $activation_before,
          TRUE,
          $locked_manifest['fingerprint'],
        );
      }
      elseif (in_array('set_settings_disabled', $locked_plan['actions'], TRUE)) {
        resources_save_settings($container, $locked_state['stored_config'], FALSE, '');
      }

      Drupal\Core\Cache\Cache::invalidateTags([
        'config:' . RESOURCES_CONFIG,
        'unisonges_resources:manifest',
      ]);
      $after = resources_runtime_state($container, $inspect_contract);
      $after_plan = resources_plan($arguments['action'], $locked_manifest, $after, $locked_contract);
      if ($after_plan['status'] !== 'NO_CHANGE') {
        throw new RuntimeException('Post-apply state is not idempotent.');
      }
      resources_line('APPLIED', $arguments['action'] === 'activate'
        ? 'Only unisonges_resources and its owned settings changed.'
        : 'Owned settings are disabled and cleared; the module remains installed.');
      return 0;
    }
    catch (Throwable $apply_error) {
      if ($arguments['action'] === 'activate'
        && is_array($activation_before)
        && is_array($activation_after)) {
        try {
          resources_restore_settings($container, $activation_after, $activation_before);
          Drupal\Core\Cache\Cache::invalidateTags([
            'config:' . RESOURCES_CONFIG,
            'unisonges_resources:manifest',
          ]);
        }
        catch (Throwable $rollback_error) {
          throw new RuntimeException(
            'Guarded activation and exact settings rollback both failed; inspect the local site before retrying.',
            0,
            $apply_error,
          );
        }
        throw new RuntimeException('Guarded activation failed; the previous owned settings were restored.', 0, $apply_error);
      }
      throw $apply_error;
    }
    finally {
      $lock->release(RESOURCES_LOCK);
    }
  }
  catch (Throwable $throwable) {
    fwrite(STDERR, 'REFUSE ' . $throwable->getMessage() . PHP_EOL);
    return 1;
  }
}

if (!defined('UNISONGES_RESOURCES_HELPER_LIBRARY_ONLY')) {
  exit(resources_main($_SERVER['argv'] ?? []));
}
