#!/usr/bin/env php
<?php

declare(strict_types=1);

use Drupal\unisonges_resources\Access\ResourcesAccess;
use Drupal\unisonges_resources\Manifest\ManifestRepository;
use Drupal\unisonges_resources\Manifest\ManifestValidator;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

$module_root = dirname(__DIR__);
$drupal_root = dirname($module_root, 4);
$repository_root = dirname($drupal_root);
require_once $drupal_root . '/vendor/autoload.php';
require_once $module_root . '/src/Manifest/ManifestValidationResult.php';
require_once $module_root . '/src/Manifest/ManifestValidator.php';
require_once $module_root . '/src/Manifest/ManifestRepository.php';
require_once $module_root . '/src/Access/ResourcesAccess.php';

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
  $assertions++;
  if (!$condition) {
    $failures[] = $message;
  }
};
$parse = static function (string $path): array {
  $value = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
  if (!is_array($value) || array_is_list($value)) {
    throw new RuntimeException('Expected a YAML mapping: ' . $path);
  }
  return $value;
};

$routing = $parse($module_root . '/unisonges_resources.routing.yml');
$route = $routing['unisonges_resources.page'] ?? NULL;
$assert(count($routing) === 1 && is_array($route), 'Exactly one module route must exist.');
$assert(($route['path'] ?? NULL) === '/ressources', 'The approved route must remain /ressources.');
$assert(($route['defaults']['_title'] ?? NULL) === 'Ressources', 'The route must supply the only H1 title.');
$assert(($route['requirements']['_unisonges_resources_access'] ?? NULL) === 'TRUE', 'The route must use fail-closed access.');
$assert(($route['options']['no_cache'] ?? NULL) === TRUE, 'Core no_cache must be scoped to /ressources.');

$menu = $parse($module_root . '/unisonges_resources.links.menu.yml');
$menu_link = $menu['unisonges_resources.page'] ?? NULL;
$assert(count($menu) === 1 && is_array($menu_link), 'Exactly one module menu plugin must exist.');
$assert(($menu_link['title'] ?? NULL) === 'Ressources'
  && ($menu_link['route_name'] ?? NULL) === 'unisonges_resources.page'
  && ($menu_link['menu_name'] ?? NULL) === 'main'
  && ($menu_link['weight'] ?? NULL) === 25, 'The root menu contract differs.');
$assert(!isset($menu_link['parent']) && !isset($menu_link['expanded']), 'The Resources menu must have no child hierarchy.');

$menu_occurrences = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($drupal_root . '/web/modules', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
  if ($file instanceof SplFileInfo && $file->isFile() && !$file->isLink() && str_ends_with($file->getFilename(), '.links.menu.yml')) {
    $menu_occurrences += array_key_exists('unisonges_resources.page', $parse($file->getPathname())) ? 1 : 0;
  }
}
$assert($menu_occurrences === 1, 'The menu plugin ID must be globally unique.');
$mobile_source = file_get_contents($drupal_root . '/web/themes/custom/unisonges_theme/js/navigation-submenus.js');
$assert(str_contains($mobile_source, 'desktopList.cloneNode(true)')
  && str_contains($mobile_source, 'mobileRoot.appendChild(mobileList)'), 'Desktop and mobile must consume the same access-filtered menu tree.');

$defaults = $parse($module_root . '/config/install/unisonges_resources.settings.yml');
$assert($defaults === ['enabled' => FALSE, 'manifest_fingerprint' => ''], 'Installed defaults must remain closed.');
$schema = $parse($module_root . '/config/schema/unisonges_resources.schema.yml');
$mapping = $schema['unisonges_resources.settings']['mapping'] ?? [];
$assert(($mapping['enabled']['type'] ?? NULL) === 'boolean'
  && ($mapping['manifest_fingerprint']['type'] ?? NULL) === 'string', 'Owned settings need exact config schema types.');

$services = $parse($module_root . '/unisonges_resources.services.yml');
$definitions = $services['services'] ?? [];
$assert(array_keys($definitions) === [
  'unisonges_resources.manifest_validator',
  'unisonges_resources.manifest_repository',
  'unisonges_resources.access',
], 'Only validator, repository, and access services should remain.');
$assert(($services['parameters']['unisonges_resources.manifest_path'] ?? NULL) === '%app.root%/../content/resources/resources.yml', 'Manifest must remain outside webroot.');
$assert(($definitions['unisonges_resources.access']['tags'][0]['applies_to'] ?? NULL) === '_unisonges_resources_access', 'The combined access service must own the route requirement.');
$assert(!isset($definitions['cache_context.unisonges_resources_manifest'])
  && !isset($definitions['unisonges_resources.page_cache_request_policy']), 'Custom fingerprint context and global page-cache policy must be absent.');

$manifest_path = $drupal_root . '/content/resources/resources.yml';
$manifest_text = file_get_contents($manifest_path);
$assert($manifest_text === "schema_version: 1\ncatalogue_approved: false\nresources: []\n", 'Production manifest must be exactly empty and unapproved.');
$production_result = (new ManifestRepository($manifest_path, new ManifestValidator()))->load();
$assert($production_result->isValid()
  && !$production_result->isCatalogueApproved()
  && $production_result->publishedCount() === 0, 'Production manifest must validate without becoming exposable.');
$assert(!ResourcesAccess::allowsState(FALSE, '', $production_result), 'Disabled config must deny the route.');
$assert(!ResourcesAccess::allowsState(TRUE, $production_result->fingerprint(), $production_result), 'Enabled config cannot expose empty/unapproved content.');

$fixture_validator = ManifestValidator::forTestFixtures();
$valid_result = $fixture_validator->validate(resources_test_manifest_for_contract());
$assert($valid_result->isValid(), 'The access test seam needs one valid fixture manifest.');
$assert(!ResourcesAccess::allowsState(FALSE, '', $valid_result), 'Module installation defaults must keep a valid catalogue closed.');
$assert(!ResourcesAccess::allowsState(TRUE, str_repeat('0', 64), $valid_result), 'A stale fingerprint must deny access.');
$assert(ResourcesAccess::allowsState(TRUE, $valid_result->fingerprint(), $valid_result), 'Only exact approved state may pass the pure gate.');

$access_source = file_get_contents($module_root . '/src/Access/ResourcesAccess.php');
$controller_source = file_get_contents($module_root . '/src/Controller/ResourcesController.php');
$assert(str_contains($access_source, '$config->hasOverrides()')
  && str_contains($access_source, 'implements AccessInterface')
  && str_contains($access_source, "addCacheTags(['unisonges_resources:manifest'])"), 'Access must implement Drupal access, reject overrides, and expose the stable manifest tag.');
$assert(str_contains($controller_source, '$this->resourcesAccess->isOpen($manifest)'), 'Controller must repeat the shared gate.');
$assert(str_contains($controller_source, "'languages:language_interface'")
  && str_contains($controller_source, "'route'")
  && str_contains($controller_source, "'url.query_args'")
  && !str_contains($controller_source, 'unisonges_resources_manifest'), 'Rendering must use bounded standard cache contexts only.');
$assert(str_contains($controller_source, "'config:unisonges_resources.settings'")
  && str_contains($controller_source, "'unisonges_resources:manifest'")
  && !str_contains($controller_source, "'unisonges_resources:manifest:'"), 'Rendering must use stable cache tags, not hash-derived variants.');
$assert(str_contains($controller_source, 'hasOnlyOneThemeParameter')
  && str_contains($controller_source, 'Unknown Resources theme')
  && str_contains($controller_source, 'NotFoundHttpException'), 'Invalid or duplicate theme queries must never fall back to all resources.');
$assert(str_contains($controller_source, "['X-Robots-Tag', 'noindex, follow', TRUE]")
  && str_contains($controller_source, "['Referrer-Policy', 'no-referrer', TRUE]"), 'Filtered noindex and no-referrer headers must remain.');

$twig_path = $module_root . '/templates/unisonges-resources-page.html.twig';
$twig_source = file_get_contents($twig_path);
$twig_executable = preg_replace('/\{#.*?#\}/s', '', $twig_source) ?? $twig_source;
$assert(preg_match('/<h1\b/i', $twig_source) !== 1
  && substr_count(strtolower($twig_source), '<h2') === 1
  && substr_count(strtolower($twig_source), '<h3') === 1, 'Twig must preserve one external H1 followed by H2/H3.');
$assert(!str_contains($twig_executable, '|raw')
  && !str_contains($twig_executable, 'editorial_note')
  && !str_contains($twig_executable, 'target='), 'Twig must autoescape, hide notes, and keep same-tab links.');
$assert(str_contains($twig_source, 'rel="external noreferrer"')
  && str_contains($twig_source, 'site externe')
  && str_contains($twig_source, 'aria-hidden="true">↗'), 'External indication must be visible and accessible.');
$assert(!preg_match('/<(?:img|picture|video|iframe|script)\b/i', $twig_source), 'Twig must not request third-party media or scripts.');

$twig = new Environment(new FilesystemLoader($module_root . '/templates'), [
  'autoescape' => 'html',
  'strict_variables' => TRUE,
]);
$template = $twig->load('unisonges-resources-page.html.twig');
$context = [
  'active_theme' => NULL,
  'all_themes_url' => '/ressources',
  'themes' => [['label' => 'Thème & test', 'url' => '/ressources?theme=test']],
  'groups' => [[
    'theme' => 'Thème & test',
    'resources' => [[
      'title' => 'Titre <script>alert(1)</script>',
      'url' => 'https://example.invalid/path?useful=one&second=two',
      'description' => 'Description & texte.',
      'theme' => 'Thème & test',
      'type' => 'Guide',
      'language' => 'fr',
    ]],
  ]],
];
$html = $template->render($context);
$assert(!str_contains($html, '<script>')
  && str_contains($html, '&lt;script&gt;')
  && str_contains($html, 'useful=one&amp;second=two'), 'Twig compile/render must escape text and URL attributes.');
$assert(substr_count(strtolower($html), '<h1') === 0
  && substr_count(strtolower($html), '<h2') === 1
  && substr_count(strtolower($html), '<h3') === 1, 'Rendered feature markup must not duplicate the page H1.');
$assert(!str_contains($html, '<nav') && !str_contains($html, 'Tous les thèmes'), 'One theme must omit redundant filtering navigation.');
$context['themes'][] = ['label' => 'Thème B', 'url' => '/ressources?theme=b'];
$several = $template->render($context);
$assert(str_contains($several, 'Tous les thèmes')
  && substr_count($several, 'aria-current="page"') === 1, 'Several themes must expose Tous les thèmes.');
$context['themes'] = [
  ['label' => '0', 'url' => '/ressources?theme=0'],
  ['label' => '00', 'url' => '/ressources?theme=00'],
];
$context['active_theme'] = '0';
$numeric_themes = $template->render($context);
$assert(substr_count($numeric_themes, 'aria-current="page"') === 1, 'Theme navigation must compare numeric-looking labels strictly.');

$css = file_get_contents($module_root . '/css/resources-hub.css');
$assert(substr_count($css, '{') === substr_count($css, '}'), 'Scoped CSS braces must balance.');
$assert(!preg_match('/@import\b|url\s*\(|animation\s*:/i', $css), 'Scoped CSS must not import assets or animate.');
$assert(str_contains($css, 'overflow-wrap: anywhere')
  && str_contains($css, '@media (max-width: 40rem)')
  && str_contains($css, 'min-height: 2.75rem')
  && str_contains($css, 'outline: 0.1875rem')
  && str_contains($css, '@media (forced-colors: active)'), 'CSS must retain wrapping, mobile, 44px, focus, and forced-colors safeguards.');
$assert(preg_match('/\.unisonges-resources__metadata li\s*\{[^}]*min-width:\s*0;[^}]*overflow-wrap:\s*anywhere;/s', $css) === 1, 'Metadata tokens need their own 320px wrapping safeguard.');

$helper = file_get_contents($drupal_root . '/scripts/resources-hub-activation.php');
$blocked_position = strpos($helper, "resources_line('BLOCKED', 'Activation content policy failed");
$bootstrap_position = strpos($helper, 'resources_bootstrap($drupal_root)');
$assert(is_int($blocked_position) && is_int($bootstrap_position) && $blocked_position < $bootstrap_position, 'Blocked content must exit before Drupal bootstrap.');
$assert(str_contains($helper, "\$action = 'activate';")
  && str_contains($helper, "\$mode = 'dry-run';")
  && str_contains($helper, "--plan-token=<64 lowercase hex>"), 'The single helper must default to token-bound dry-run activation.');
$assert(str_contains($helper, "install([RESOURCES_MODULE], FALSE)")
  && substr_count($helper, "getEditable(RESOURCES_CONFIG)") === 2
  && str_contains($helper, 'resources_restore_settings($container, $activation_after, $activation_before)')
  && str_contains($helper, 'setData($restore)')
  && str_contains($helper, 'Disable write failed; no rollback toward an enabled state was attempted.'), 'Apply may install only this module, edit only its settings, rollback activation, and never rollback disable toward enabled.');
$assert(str_contains($helper, "router.no_access_checks")
  && str_contains($helper, "(\$match['_route'] ?? NULL) === RESOURCES_ROUTE"), 'Activation must verify that /ressources resolves to the intended route winner.');
$assert(str_contains($helper, "'unisonges_resources:manifest'")
  && !str_contains($helper, "'unisonges_resources:manifest:'"), 'Apply must invalidate the stable manifest tag.');
$assert(!str_contains($helper, 'config_import')
  && !str_contains($helper, 'menu_link_content')
  && !str_contains($helper, 'taxonomy_term')
  && !preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE)\b\s+/i', $helper), 'Helper must not import config, create content, taxonomy, menu entities, or use raw SQL.');

$production_sources = $helper;
foreach (glob($module_root . '/src/*/*.php') ?: [] as $source_path) {
  $production_sources .= file_get_contents($source_path);
}
foreach (['GuzzleHttp', 'curl_', 'dns_get_record', 'fsockopen', 'get_headers(', 'gethostbyname', 'gethostbynamel', 'http_client', 'proc_open(', 'shell_exec(', 'stream_socket_client'] as $network_api) {
  $assert(!str_contains($production_sources, $network_api), 'Production code contains forbidden API ' . $network_api . '.');
}
$assert(!preg_match('/\bArchives\b/u', $production_sources)
  && !str_contains($production_sources, 'taxonomy_term'), 'No Archives feature or Blog taxonomy behavior may be introduced.');

$module_files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($module_root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
  if ($file instanceof SplFileInfo && $file->isFile()) {
    $relative = substr($file->getPathname(), strlen($module_root) + 1);
    if (!str_starts_with($relative, 'tests/')) {
      $module_files[] = $relative;
    }
  }
}
sort($module_files, SORT_STRING);
$expected_module_files = [
  'config/install/unisonges_resources.settings.yml',
  'config/schema/unisonges_resources.schema.yml',
  'css/resources-hub.css',
  'src/Access/ResourcesAccess.php',
  'src/Controller/ResourcesController.php',
  'src/Manifest/ManifestRepository.php',
  'src/Manifest/ManifestValidationResult.php',
  'src/Manifest/ManifestValidator.php',
  'templates/unisonges-resources-page.html.twig',
  'unisonges_resources.info.yml',
  'unisonges_resources.libraries.yml',
  'unisonges_resources.links.menu.yml',
  'unisonges_resources.module',
  'unisonges_resources.routing.yml',
  'unisonges_resources.services.yml',
];
sort($expected_module_files, SORT_STRING);
$assert($module_files === $expected_module_files, 'Production module inventory must contain exactly 15 files.');

$expected_changed = [
  'docs/functional/resources-hub-foundation-2026.md',
  'drupal/content/resources/resources.yml',
  'drupal/scripts/resources-hub-activation.php',
];
foreach ($expected_module_files as $relative) {
  $expected_changed[] = 'drupal/web/modules/custom/unisonges_resources/' . $relative;
}
foreach ([
  'manifest-validation-cases.php',
  'run-activation-planner-tests.php',
  'run-manifest-tests.php',
  'run-static-contract-tests.php',
] as $relative) {
  $expected_changed[] = 'drupal/web/modules/custom/unisonges_resources/tests/' . $relative;
}
sort($expected_changed, SORT_STRING);

$diff_lines = [];
exec('git -C ' . escapeshellarg($repository_root) . ' diff --name-only origin/release/prod --', $diff_lines, $diff_status);
$untracked_lines = [];
exec('git -C ' . escapeshellarg($repository_root) . ' ls-files --others --exclude-standard', $untracked_lines, $untracked_status);
$changed = array_values(array_unique(array_filter([...$diff_lines, ...$untracked_lines])));
sort($changed, SORT_STRING);
$assert($diff_status === 0 && $untracked_status === 0, 'Exact-file guard must read Git state.');
$assert($changed === $expected_changed && count($changed) === 22, 'The final diff must contain exactly the 22 reviewed files.');
foreach ([
  'drupal/config/sync/core.extension.yml',
  'drupal/scripts/apply-content-architecture-2026.sh',
  'drupal/web/themes/custom/unisonges_theme/templates/layout/page.html.twig',
  'drupal/web/themes/custom/unisonges_theme/templates/layout/page--front.html.twig',
  'drupal/web/themes/custom/unisonges_theme/css/styles.css',
  'drupal/web/themes/custom/unisonges_theme/unisonges_theme.theme',
] as $protected) {
  $assert(!in_array($protected, $changed, TRUE), 'Protected file changed: ' . $protected);
}

if ($failures !== []) {
  fwrite(STDERR, sprintf("FAIL: %d/%d static assertions failed.\n", count($failures), $assertions));
  foreach ($failures as $failure) {
    fwrite(STDERR, ' - ' . $failure . PHP_EOL);
  }
  exit(1);
}

fwrite(STDOUT, sprintf("PASS: %d static route/menu/render/cache/activation assertions; final_files=22.\n", $assertions));

function resources_test_manifest_for_contract(): array {
  return [
    'schema_version' => 1,
    'catalogue_approved' => TRUE,
    'resources' => [[
      'id' => 'contract-fixture',
      'title' => 'Ressource fictive',
      'url' => 'https://example.invalid/contract',
      'description' => 'Description factuelle réservée au test statique.',
      'theme' => 'Thème test',
      'type' => 'Guide',
      'language' => 'fr',
      'published' => TRUE,
    ]],
  ];
}
