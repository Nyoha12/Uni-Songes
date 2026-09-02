<?php

declare(strict_types=1);

/**
 * @file
 * Direct-kernel, fail-closed installer for the 2026 editorial Blog homepage.
 */

use Composer\InstalledVersions;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

const EDITORIAL_HOME_MODULE = 'unisonges_editorial_home';
const EDITORIAL_HOME_VIEW_CONFIG = 'views.view.blog_posts';
const EDITORIAL_HOME_VIEW_ID = 'blog_posts';
const EDITORIAL_HOME_DISPLAY_ID = 'editorial_home';
const EDITORIAL_HOME_BLOCK_CONFIG = 'block.block.unisonges_editorial_home';
const EDITORIAL_HOME_BLOCK_ID = 'unisonges_editorial_home';
const EDITORIAL_HOME_BLOCK_PLUGIN = 'unisonges_editorial_home';
const EDITORIAL_HOME_THEME = 'unisonges_theme';
const EDITORIAL_HOME_REGION = 'content';
const EDITORIAL_HOME_STATE_KEY = 'unisonges_editorial_home.rollback.v1';
const EDITORIAL_HOME_LOCK = 'unisonges_editorial_home.deploy.v1';
const EDITORIAL_HOME_SITE_UUID = 'ff0af3b7-b9cf-4d63-8932-4f55870ce430';
const EDITORIAL_HOME_BODY_FORMAT = 'full_html';

/**
 * Throw one consistently formatted refusal.
 */
function editorial_home_fail(string $message): never {
  throw new RuntimeException($message);
}

/**
 * Print one machine-readable status line.
 */
function editorial_home_line(string $status, string $message): void {
  print $status . ' ' . $message . PHP_EOL;
}

/**
 * Recursively sort mapping keys while retaining list order.
 */
function editorial_home_canonicalize(mixed $value): mixed {
  if (!is_array($value)) {
    return $value;
  }

  foreach ($value as $key => $item) {
    $value[$key] = editorial_home_canonicalize($item);
  }
  if (!array_is_list($value)) {
    ksort($value, SORT_STRING);
  }
  return $value;
}

/**
 * Hash structured state without depending on mapping insertion order.
 */
function editorial_home_hash_data(mixed $value): string {
  return hash('sha256', json_encode(
    editorial_home_canonicalize($value),
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
  ));
}

/**
 * Compare structured values with mapping keys normalized.
 */
function editorial_home_data_equals(mixed $left, mixed $right): bool {
  return editorial_home_canonicalize($left) === editorial_home_canonicalize($right);
}

/**
 * Require an exact associative-key set.
 */
function editorial_home_require_keys(array $value, array $expected, string $label): void {
  $actual = array_keys($value);
  sort($actual, SORT_STRING);
  sort($expected, SORT_STRING);
  if ($actual !== $expected) {
    editorial_home_fail($label . ' has an unexpected key set.');
  }
}

/**
 * Validate and return a canonical regular file.
 */
function editorial_home_exact_file(string $path): string {
  $resolved = realpath($path);
  if ($resolved === FALSE
    || $resolved !== $path
    || !is_file($path)
    || !is_readable($path)
    || is_link($path)) {
    editorial_home_fail('Exact regular-file guard failed for ' . $path . '.');
  }
  return $resolved;
}

/**
 * Parse a reviewed YAML mapping.
 */
function editorial_home_read_yaml(string $path): array {
  editorial_home_exact_file($path);
  try {
    $data = Yaml::parseFile($path);
  }
  catch (Throwable $throwable) {
    editorial_home_fail('Could not parse reviewed YAML ' . $path . ': ' . $throwable->getMessage());
  }
  if (!is_array($data) || array_is_list($data)) {
    editorial_home_fail('Reviewed YAML must contain a top-level mapping: ' . $path . '.');
  }
  return $data;
}

/**
 * Read one exact nowdoc page body from the content-architecture source.
 */
function editorial_home_extract_page_body(string $source, string $key): string {
  $page_marker = "  '" . $key . "' => [\n";
  if (substr_count($source, $page_marker) !== 1) {
    editorial_home_fail('Expected exactly one page source marker for ' . $key . '.');
  }
  $page_start = strpos($source, $page_marker);
  if ($page_start === FALSE) {
    editorial_home_fail('Could not locate page source ' . $key . '.');
  }
  $body_marker = "    'body' => <<<'HTML'\n";
  $body_marker_start = strpos($source, $body_marker, $page_start);
  if ($body_marker_start === FALSE) {
    editorial_home_fail('Could not locate body source ' . $key . '.');
  }
  $next_page = strpos($source, "\n  '", $page_start + strlen($page_marker));
  if ($next_page !== FALSE && $body_marker_start > $next_page) {
    editorial_home_fail('Body source escaped its declared page for ' . $key . '.');
  }
  $body_start = $body_marker_start + strlen($body_marker);
  $end_marker = "\nHTML,\n  ],";
  $body_end = strpos($source, $end_marker, $body_start);
  if ($body_end === FALSE || ($next_page !== FALSE && $body_end > $next_page)) {
    editorial_home_fail('Could not locate the exact body terminator for ' . $key . '.');
  }
  return substr($source, $body_start, $body_end - $body_start);
}

/**
 * Print an exact body with byte/hash boundary markers.
 */
function editorial_home_print_body(string $label, string $state, string $body): void {
  print $label . '_BEGIN state=' . $state
    . ' bytes=' . strlen($body)
    . ' sha256=' . hash('sha256', $body) . PHP_EOL;
  print $body;
  if ($body !== '' && !str_ends_with($body, "\n")) {
    print PHP_EOL;
  }
  print $label . '_END' . PHP_EOL;
}

/**
 * Snapshot value, format, and summary without inventing an empty field item.
 */
function editorial_home_body_snapshot(NodeInterface $node): array {
  if (!$node->hasField('body')) {
    editorial_home_fail('The page node has no body field: node/' . $node->id() . '.');
  }
  $items = $node->get('body')->getValue();
  if ($items === []) {
    return [
      'empty' => TRUE,
      'value' => '',
      'format' => NULL,
      'summary' => NULL,
      'items' => [],
    ];
  }
  if (count($items) !== 1 || !is_array($items[0])) {
    editorial_home_fail('The reviewed Basic-page body must have cardinality zero or one.');
  }
  $item = $items[0];
  $value = $item['value'] ?? NULL;
  $format = $item['format'] ?? NULL;
  $summary = $item['summary'] ?? NULL;
  if (!is_string($value)
    || !is_string($format)
    || (!is_string($summary) && $summary !== NULL)) {
    editorial_home_fail('The Basic-page body value/format/summary tuple is malformed.');
  }
  return [
    'empty' => FALSE,
    'value' => $value,
    'format' => $format,
    'summary' => $summary,
    'items' => [[
      'value' => $value,
      'format' => $format,
      'summary' => $summary,
    ]],
  ];
}

/**
 * Stable identity fields which a body-only save must preserve.
 */
function editorial_home_node_identity(NodeInterface $node): array {
  return [
    'entity_type' => 'node',
    'bundle' => (string) $node->bundle(),
    'id' => (int) $node->id(),
    'uuid' => (string) $node->uuid(),
    'langcode' => (string) $node->language()->getId(),
    'title' => (string) $node->label(),
    'published' => (bool) $node->isPublished(),
    'owner_id' => (int) $node->getOwnerId(),
    'created' => (int) $node->getCreatedTime(),
  ];
}

/**
 * Exact revision metadata retained so rollback can restore the original row.
 */
function editorial_home_revision_identity(NodeInterface $node): array {
  return [
    'revision_id' => (int) $node->getRevisionId(),
    'revision_user_id' => (int) $node->getRevisionUserId(),
    'revision_created' => (int) $node->getRevisionCreationTime(),
    'revision_log_message' => $node->getRevisionLogMessage(),
    'changed' => (int) $node->getChangedTime(),
  ];
}

/**
 * Stable identity of the one PathAlias entity.
 */
function editorial_home_alias_identity(object $alias): array {
  return [
    'entity_type' => 'path_alias',
    'id' => (int) $alias->id(),
    'uuid' => (string) $alias->uuid(),
    'langcode' => (string) $alias->language()->getId(),
    'alias' => (string) $alias->getAlias(),
    'path' => (string) $alias->getPath(),
  ];
}

/**
 * Resolve exactly one alias to a distinct, published Basic page.
 */
function editorial_home_resolve_page(string $alias, string $expected_title): array {
  $alias_storage = \Drupal::entityTypeManager()->getStorage('path_alias');
  $aliases = $alias_storage->loadByProperties(['alias' => $alias]);
  if (count($aliases) !== 1) {
    editorial_home_fail(
      $alias . ' must have exactly one PathAlias entity; found ' . count($aliases) . '.',
    );
  }
  $alias_entity = reset($aliases);
  $path = (string) $alias_entity->getPath();
  if (!preg_match('/^\/node\/([1-9][0-9]*)$/D', $path, $matches)) {
    editorial_home_fail($alias . ' must resolve directly to one canonical node path.');
  }
  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  if (!$node_storage instanceof RevisionableStorageInterface) {
    editorial_home_fail('Node storage must support exact revision guards.');
  }
  $node = $node_storage->load((int) $matches[1]);
  if (!$node instanceof NodeInterface
    || $node->bundle() !== 'page'
    || !$node->isPublished()
    || (string) $node->label() !== $expected_title
    || $node->language()->getId() !== $alias_entity->language()->getId()) {
    editorial_home_fail(
      $alias . ' must own the exact published Basic page titled ' . $expected_title . '.',
    );
  }
  $revision_id = (int) $node->getRevisionId();
  $latest_revision_id = $node_storage->getLatestRevisionId($node->id());
  if (!$node->isDefaultRevision()
    || $latest_revision_id === NULL
    || (string) $latest_revision_id !== (string) $revision_id) {
    editorial_home_fail(
      $alias . ' has a forward or non-default revision; refusing a body transition.',
    );
  }
  return [
    'node' => $node,
    'node_identity' => editorial_home_node_identity($node),
    'revision_identity' => editorial_home_revision_identity($node),
    'alias' => $alias_entity,
    'alias_identity' => editorial_home_alias_identity($alias_entity),
    'body' => editorial_home_body_snapshot($node),
    'revision_id' => $revision_id,
  ];
}

/**
 * Stored and effective config must agree; runtime overrides block deployment.
 */
function editorial_home_active_config(string $name): array {
  $stored = \Drupal::service('config.storage')->read($name);
  if (!is_array($stored)) {
    editorial_home_fail('Required active configuration is missing: ' . $name . '.');
  }
  $effective = \Drupal::config($name)->get();
  if (!is_array($effective) || !editorial_home_data_equals($stored, $effective)) {
    editorial_home_fail('A runtime override affects required configuration: ' . $name . '.');
  }
  return $stored;
}

/**
 * Require active config to match one reviewed repository object exactly.
 */
function editorial_home_assert_source_config(string $name, array $source): array {
  $active = editorial_home_active_config($name);
  if (!editorial_home_data_equals($active, $source)) {
    editorial_home_fail('Active prerequisite differs from its reviewed source: ' . $name . '.');
  }
  return $active;
}

/**
 * Describe the absent/present state value without conflating NULL and absence.
 */
function editorial_home_read_rollback_state(): array {
  $sentinel = new stdClass();
  $value = \Drupal::state()->get(EDITORIAL_HOME_STATE_KEY, $sentinel);
  return [
    'exists' => $value !== $sentinel,
    'value' => $value !== $sentinel ? $value : NULL,
  ];
}

/**
 * Snapshot every active config collection through Drupal's storage API.
 */
function editorial_home_config_snapshot(): array {
  $default = \Drupal::service('config.storage');
  $collections = array_values(array_unique(array_merge(
    [StorageInterface::DEFAULT_COLLECTION],
    $default->getAllCollectionNames(),
  )));
  sort($collections, SORT_STRING);
  $snapshot = [];
  foreach ($collections as $collection_name) {
    $storage = $collection_name === StorageInterface::DEFAULT_COLLECTION
      ? $default
      : $default->createCollection($collection_name);
    $names = $storage->listAll();
    sort($names, SORT_STRING);
    $values = $names === [] ? [] : $storage->readMultiple($names);
    $snapshot[$collection_name] = [];
    foreach ($names as $name) {
      if (!isset($values[$name]) || !is_array($values[$name])) {
        editorial_home_fail('Could not snapshot active config ' . $collection_name . ':' . $name . '.');
      }
      $snapshot[$collection_name][$name] = $values[$name];
    }
  }
  return $snapshot;
}

/**
 * List unexpected config dependents before module uninstall.
 */
function editorial_home_module_dependents(array $config_snapshot): array {
  $dependents = [];
  foreach ($config_snapshot as $collection_name => $collection) {
    foreach ($collection as $name => $data) {
      if (!in_array(EDITORIAL_HOME_MODULE, $data['dependencies']['module'] ?? [], TRUE)) {
        continue;
      }
      $label = ($collection_name === StorageInterface::DEFAULT_COLLECTION ? 'default' : $collection_name)
        . ':' . $name;
      if ($collection_name === StorageInterface::DEFAULT_COLLECTION
        && $name === EDITORIAL_HOME_BLOCK_CONFIG) {
        continue;
      }
      $dependents[] = $label;
    }
  }
  sort($dependents, SORT_STRING);
  return $dependents;
}

/**
 * Return the fixed reviewed source inventory and SHA-256 values.
 */
function editorial_home_source_inventory(string $repo_root): array {
  $relative_files = [
    'drupal/composer.lock',
    'drupal/config/sync/block.block.unisonges_blog_posts.yml',
    'drupal/config/sync/block.block.unisonges_editorial_home.yml',
    'drupal/config/sync/block.block.unisonges_forum_blog_proposal.yml',
    'drupal/config/sync/block.block.unisonges_forum_topics.yml',
    'drupal/config/sync/core.base_field_override.node.forum_topic.promote.yml',
    'drupal/config/sync/core.base_field_override.node.forum_topic.status.yml',
    'drupal/config/sync/core.entity_form_display.node.forum_topic.default.yml',
    'drupal/config/sync/core.entity_view_display.node.forum_topic.default.yml',
    'drupal/config/sync/core.entity_view_display.node.forum_topic.teaser.yml',
    'drupal/config/sync/core.extension.yml',
    'drupal/config/sync/field.field.comment.comment.comment_body.yml',
    'drupal/config/sync/field.field.node.forum_topic.body.yml',
    'drupal/config/sync/field.field.node.forum_topic.comment.yml',
    'drupal/config/sync/field.field.node.article.field_tags.yml',
    'drupal/config/sync/field.field.node.page.body.yml',
    'drupal/config/sync/field.storage.node.field_tags.yml',
    'drupal/config/sync/filter.format.full_html.yml',
    'drupal/config/sync/node.type.article.yml',
    'drupal/config/sync/node.type.forum_topic.yml',
    'drupal/config/sync/node.type.page.yml',
    'drupal/config/sync/system.site.yml',
    'drupal/config/sync/system.theme.yml',
    'drupal/config/sync/taxonomy.vocabulary.tags.yml',
    'drupal/config/sync/user.role.anonymous.yml',
    'drupal/config/sync/views.view.blog_posts.yml',
    'drupal/config/sync/views.view.forum_topics.yml',
    'drupal/config/sync/webform.webform.forum_blog_proposal.yml',
    'drupal/scripts/apply-content-architecture-2026.sh',
    'drupal/scripts/apply-editorial-home-blog-2026.sh',
    'drupal/scripts/editorial-home-blog-config.php',
    'drupal/web/modules/custom/unisonges_editorial_home/css/editorial-home.css',
    'drupal/web/modules/custom/unisonges_editorial_home/src/EditorialHomeBuilder.php',
    'drupal/web/modules/custom/unisonges_editorial_home/src/EditorialHomeUninstallValidator.php',
    'drupal/web/modules/custom/unisonges_editorial_home/src/Plugin/Block/EditorialHomeBlock.php',
    'drupal/web/modules/custom/unisonges_editorial_home/templates/unisonges-editorial-home.html.twig',
    'drupal/web/modules/custom/unisonges_editorial_home/unisonges_editorial_home.info.yml',
    'drupal/web/modules/custom/unisonges_editorial_home/unisonges_editorial_home.libraries.yml',
    'drupal/web/modules/custom/unisonges_editorial_home/unisonges_editorial_home.module',
    'drupal/web/modules/custom/unisonges_editorial_home/unisonges_editorial_home.services.yml',
    'drupal/web/themes/custom/unisonges_theme/unisonges_theme.info.yml',
  ];
  $hashes = [];
  foreach ($relative_files as $relative_file) {
    $path = $repo_root . '/' . $relative_file;
    editorial_home_exact_file($path);
    $hash = hash_file('sha256', $path);
    if (!is_string($hash)) {
      editorial_home_fail('Could not hash reviewed source ' . $relative_file . '.');
    }
    $hashes[$relative_file] = $hash;
  }
  ksort($hashes, SORT_STRING);
  return $hashes;
}

/**
 * Assert that the custom module directory has no undeclared source files.
 */
function editorial_home_assert_module_inventory(string $module_dir): void {
  $expected = [
    'css/editorial-home.css',
    'src/EditorialHomeBuilder.php',
    'src/EditorialHomeUninstallValidator.php',
    'src/Plugin/Block/EditorialHomeBlock.php',
    'templates/unisonges-editorial-home.html.twig',
    'unisonges_editorial_home.info.yml',
    'unisonges_editorial_home.libraries.yml',
    'unisonges_editorial_home.module',
    'unisonges_editorial_home.services.yml',
  ];
  $resolved = realpath($module_dir);
  if ($resolved === FALSE || $resolved !== $module_dir || !is_dir($module_dir) || is_link($module_dir)) {
    editorial_home_fail('The custom module directory failed its canonical-path guard.');
  }
  $actual = [];
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($module_dir, FilesystemIterator::SKIP_DOTS),
  );
  foreach ($iterator as $file) {
    $path = $file->getPathname();
    if ($file->isLink()) {
      editorial_home_fail('Symlinks are forbidden in the reviewed custom module: ' . $path . '.');
    }
    if ($file->isFile()) {
      $actual[] = substr($path, strlen($module_dir) + 1);
    }
  }
  sort($actual, SORT_STRING);
  sort($expected, SORT_STRING);
  if ($actual !== $expected) {
    editorial_home_fail('The custom module file inventory differs from the fixed allowlist.');
  }
}

/**
 * Validate the unchanged Blog displays and the one exact editorial display.
 */
function editorial_home_assert_view_source(array $view): array {
  if (($view['status'] ?? NULL) !== TRUE
    || ($view['id'] ?? NULL) !== EDITORIAL_HOME_VIEW_ID
    || ($view['module'] ?? NULL) !== 'views'
    || ($view['base_table'] ?? NULL) !== 'node_field_data'
    || ($view['base_field'] ?? NULL) !== 'nid'
    || array_keys($view['display'] ?? []) !== ['default', 'block_1', EDITORIAL_HOME_DISPLAY_ID]
    || ($view['dependencies']['config'] ?? NULL) !== [
      'core.entity_view_mode.node.teaser',
      'node.type.article',
      'taxonomy.vocabulary.tags',
    ]
    || ($view['dependencies']['module'] ?? NULL) !== ['node', 'taxonomy', 'user']) {
    editorial_home_fail('The Blog View source does not have the exact reviewed target identity/dependencies.');
  }

  $default = $view['display']['default'];
  $options = $default['display_options'] ?? NULL;
  $block = $view['display']['block_1'];
  if (!is_array($options)
    || ($default['id'] ?? NULL) !== 'default'
    || ($default['display_plugin'] ?? NULL) !== 'default'
    || ($options['pager']['type'] ?? NULL) !== 'mini'
    || ($options['pager']['options']['items_per_page'] ?? NULL) !== 10
    || ($options['access'] ?? NULL) !== [
      'type' => 'perm',
      'options' => ['perm' => 'access content'],
    ]
    || ($options['cache']['type'] ?? NULL) !== 'tag'
    || ($options['sorts']['created']['order'] ?? NULL) !== 'DESC'
    || ($options['filters']['status']['value'] ?? NULL) !== '1'
    || ($options['filters']['status']['plugin_id'] ?? NULL) !== 'boolean'
    || ($options['filters']['type']['value'] ?? NULL) !== ['article' => 'article']
    || ($options['filters']['type']['plugin_id'] ?? NULL) !== 'bundle'
    || ($options['row']['type'] ?? NULL) !== 'entity:node'
    || ($options['row']['options']['view_mode'] ?? NULL) !== 'teaser'
    || ($options['query']['options']['disable_sql_rewrite'] ?? NULL) !== FALSE
    || ($options['query']['options']['distinct'] ?? NULL) !== FALSE
    || trim((string) ($options['empty']['area_text_custom']['content'] ?? '')) === '') {
    editorial_home_fail('The default Blog display no longer has the exact published-Article semantics.');
  }
  if (($block['id'] ?? NULL) !== 'block_1'
    || ($block['display_plugin'] ?? NULL) !== 'block'
    || ($block['position'] ?? NULL) !== 1
    || ($block['display_options'] ?? NULL) !== [
      'block_description' => 'Blog : articles publiés',
      'block_category' => 'Listes (Views)',
      'display_extenders' => [],
    ]) {
    editorial_home_fail('The existing /blog block_1 display semantics changed.');
  }

  $expected_editorial = [
    'id' => 'editorial_home',
    'display_title' => 'Accueil éditorial',
    'display_plugin' => 'embed',
    'position' => 2,
    'display_options' => [
      'arguments' => [
        'tid' => [
          'id' => 'tid',
          'table' => 'taxonomy_index',
          'field' => 'tid',
          'relationship' => 'none',
          'group_type' => 'group',
          'admin_label' => '',
          'plugin_id' => 'taxonomy_index_tid',
          'default_action' => 'default',
          'exception' => [
            'value' => 'all',
            'title_enable' => FALSE,
            'title' => 'Tout',
          ],
          'title_enable' => FALSE,
          'title' => '',
          'default_argument_type' => 'fixed',
          'default_argument_options' => ['argument' => 'all'],
          'summary_options' => [
            'base_path' => '',
            'count' => TRUE,
            'override' => FALSE,
            'items_per_page' => 25,
          ],
          'summary' => [
            'sort_order' => 'asc',
            'number_of_records' => 0,
            'format' => 'default_summary',
          ],
          'specify_validation' => TRUE,
          'validate' => [
            'type' => 'entity:taxonomy_term',
            'fail' => 'not found',
          ],
          'validate_options' => [
            'bundles' => ['tags' => 'tags'],
            'access' => TRUE,
            'operation' => 'view',
            'multiple' => 0,
          ],
          'break_phrase' => FALSE,
          'add_table' => FALSE,
          'require_value' => FALSE,
          'reduce_duplicates' => FALSE,
        ],
      ],
      'query' => [
        'type' => 'views_query',
        'options' => [
          'query_comment' => '',
          'disable_sql_rewrite' => FALSE,
          'distinct' => TRUE,
          'replica' => FALSE,
          'query_tags' => [],
        ],
      ],
      'defaults' => [
        'query' => FALSE,
        'arguments' => FALSE,
      ],
      'display_extenders' => [],
    ],
    'cache_metadata' => [
      'max-age' => -1,
      'contexts' => [
        'languages:language_interface',
        'url',
        'url.query_args',
        'user.node_grants:view',
        'user.permissions',
      ],
      'tags' => [],
    ],
  ];
  if (!editorial_home_data_equals($view['display'][EDITORIAL_HOME_DISPLAY_ID], $expected_editorial)) {
    editorial_home_fail('The editorial_home View display differs from the exact reviewed embed target.');
  }
  foreach ($view['display'] as $display_id => $display) {
    if (($display['display_plugin'] ?? NULL) === 'page'
      || isset($display['display_options']['path'])) {
      editorial_home_fail('The Blog View must not add a public View route: ' . $display_id . '.');
    }
    $contexts = $display['cache_metadata']['contexts'] ?? [];
    if (!in_array('user.node_grants:view', $contexts, TRUE)
      || !in_array('user.permissions', $contexts, TRUE)) {
      editorial_home_fail('Every Blog display must retain grants/permission cache contexts.');
    }
  }

  $baseline = $view;
  unset($baseline['display'][EDITORIAL_HOME_DISPLAY_ID]);
  $baseline['dependencies']['config'] = array_values(array_filter(
    $baseline['dependencies']['config'],
    static fn(string $name): bool => $name !== 'taxonomy.vocabulary.tags',
  ));
  $baseline['dependencies']['module'] = array_values(array_filter(
    $baseline['dependencies']['module'],
    static fn(string $name): bool => $name !== 'taxonomy',
  ));
  if (array_keys($baseline['display']) !== ['default', 'block_1']
    || $baseline['dependencies']['config'] !== [
      'core.entity_view_mode.node.teaser',
      'node.type.article',
    ]
    || $baseline['dependencies']['module'] !== ['node', 'user']) {
    editorial_home_fail('Could not derive the exact two-display rollback baseline.');
  }
  return $baseline;
}

/**
 * Validate the exact synced custom block.
 */
function editorial_home_assert_block_source(array $block): void {
  $uuid = $block['uuid'] ?? NULL;
  editorial_home_require_keys($block, [
    'uuid',
    'langcode',
    'status',
    'dependencies',
    'id',
    'theme',
    'region',
    'weight',
    'provider',
    'plugin',
    'settings',
    'visibility',
  ], 'Editorial homepage block source');
  if (!is_string($uuid)
    || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid)
    || ($block['langcode'] ?? NULL) !== 'fr'
    || ($block['status'] ?? NULL) !== TRUE
    || ($block['id'] ?? NULL) !== EDITORIAL_HOME_BLOCK_ID
    || ($block['theme'] ?? NULL) !== EDITORIAL_HOME_THEME
    || ($block['region'] ?? NULL) !== EDITORIAL_HOME_REGION
    || ($block['weight'] ?? NULL) !== 0
    || $block['provider'] !== NULL
    || ($block['plugin'] ?? NULL) !== EDITORIAL_HOME_BLOCK_PLUGIN
    || ($block['dependencies']['config'] ?? NULL) !== [EDITORIAL_HOME_VIEW_CONFIG]
    || ($block['dependencies']['module'] ?? NULL) !== ['system', EDITORIAL_HOME_MODULE]
    || ($block['dependencies']['theme'] ?? NULL) !== [EDITORIAL_HOME_THEME]
    || ($block['visibility'] ?? NULL) !== [
      'request_path' => [
        'id' => 'request_path',
        'negate' => FALSE,
        'pages' => '/accueil',
      ],
    ]) {
    editorial_home_fail('The synced editorial homepage block identity, dependencies, placement, or visibility changed.');
  }
  $settings = $block['settings'] ?? NULL;
  if (!is_array($settings) || $settings !== [
    'id' => EDITORIAL_HOME_BLOCK_PLUGIN,
    'label' => 'Accueil éditorial',
    'label_display' => '0',
    'provider' => EDITORIAL_HOME_MODULE,
  ]) {
    editorial_home_fail('The editorial homepage block settings are not the hidden-label custom plugin target.');
  }
}

/**
 * Validate the existing /blog block which must remain a separate collection.
 */
function editorial_home_assert_blog_block_source(array $block): void {
  editorial_home_require_keys($block, [
    'uuid',
    'langcode',
    'status',
    'dependencies',
    'id',
    'theme',
    'region',
    'weight',
    'provider',
    'plugin',
    'settings',
    'visibility',
  ], 'Existing Blog block source');
  if (($block['langcode'] ?? NULL) !== 'fr'
    || ($block['status'] ?? NULL) !== TRUE
    || ($block['id'] ?? NULL) !== 'unisonges_blog_posts'
    || ($block['theme'] ?? NULL) !== EDITORIAL_HOME_THEME
    || ($block['region'] ?? NULL) !== EDITORIAL_HOME_REGION
    || ($block['weight'] ?? NULL) !== 20
    || $block['provider'] !== NULL
    || ($block['plugin'] ?? NULL) !== 'views_block:blog_posts-block_1'
    || ($block['dependencies']['config'] ?? NULL) !== [EDITORIAL_HOME_VIEW_CONFIG]
    || ($block['dependencies']['module'] ?? NULL) !== ['system', 'views']
    || ($block['dependencies']['theme'] ?? NULL) !== [EDITORIAL_HOME_THEME]
    || ($block['settings'] ?? NULL) !== [
      'id' => 'views_block:blog_posts-block_1',
      'label' => 'Derniers articles',
      'label_display' => 'visible',
      'provider' => 'views',
      'views_label' => '',
      'items_per_page' => NULL,
    ]
    || ($block['visibility'] ?? NULL) !== [
      'request_path' => [
        'id' => 'request_path',
        'negate' => FALSE,
        'pages' => '/blog',
      ],
    ]) {
    editorial_home_fail('The existing /blog block placement or behavior changed.');
  }
}

/**
 * Classify an exact reviewed homepage body tuple.
 */
function editorial_home_classify_body(
  array $body,
  string $merged_body,
  string $target_body,
): string {
  editorial_home_require_keys($body, [
    'empty',
    'value',
    'format',
    'summary',
    'items',
  ], '/accueil body tuple');
  if ($body['empty'] === FALSE
    && $body['value'] === $merged_body
    && $body['format'] === EDITORIAL_HOME_BODY_FORMAT
    && $body['summary'] === NULL
    && $body['items'] === [[
      'value' => $merged_body,
      'format' => EDITORIAL_HOME_BODY_FORMAT,
      'summary' => NULL,
    ]]) {
    return 'reviewed_content_architecture_merged';
  }
  if ($body['empty'] === FALSE
    && $body['value'] === $target_body
    && $body['format'] === EDITORIAL_HOME_BODY_FORMAT
    && $body['summary'] === NULL
    && $body['items'] === [[
      'value' => $target_body,
      'format' => EDITORIAL_HOME_BODY_FORMAT,
      'summary' => NULL,
    ]]) {
    return 'editorial_home_target';
  }
  editorial_home_fail(
    'The /accueil body is neither exact reviewed pre-state nor the exact editorial target; refusing overwrite.',
  );
}

/**
 * Contract hashes retained with the body rollback copy.
 */
function editorial_home_rollback_contract(
  array $view_baseline,
  array $view_target,
  string $target_body,
  string $content_source_hash,
): array {
  return [
    'view_baseline_sha256' => editorial_home_hash_data($view_baseline),
    'view_target_sha256' => editorial_home_hash_data($view_target),
    'target_body_sha256' => hash('sha256', $target_body),
    'content_source_sha256' => $content_source_hash,
  ];
}

/**
 * Validate the retained body copy and bind it to this exact page/alias/revision.
 */
function editorial_home_validate_rollback_state(
  mixed $raw,
  array $home,
  string $merged_body,
  string $target_body,
  array $contract,
): array {
  if (!is_array($raw)) {
    editorial_home_fail('The retained rollback state is not an array.');
  }
  editorial_home_require_keys($raw, [
    'version',
    'feature',
    'site_uuid',
    'contract',
    'homepage',
  ], 'Rollback state');
  if (($raw['version'] ?? NULL) !== 2
    || ($raw['feature'] ?? NULL) !== EDITORIAL_HOME_MODULE
    || ($raw['site_uuid'] ?? NULL) !== EDITORIAL_HOME_SITE_UUID
    || !editorial_home_data_equals($raw['contract'] ?? NULL, $contract)
    || !is_array($raw['homepage'] ?? NULL)) {
    editorial_home_fail('The retained rollback state contract is inconsistent with this reviewed deployment.');
  }
  $homepage = $raw['homepage'];
  editorial_home_require_keys($homepage, [
    'identity',
    'alias',
    'original_revision_id',
    'target_revision_id',
    'original_revision',
    'target_revision',
    'original_body',
    'reviewed_prestate',
  ], 'Rollback homepage state');
  if (!editorial_home_data_equals($homepage['identity'] ?? NULL, $home['node_identity'])
    || !editorial_home_data_equals($homepage['alias'] ?? NULL, $home['alias_identity'])
    || !is_int($homepage['original_revision_id'] ?? NULL)
    || ($homepage['original_revision_id'] ?? 0) < 1
    || ($homepage['target_revision_id'] ?? NULL) !== $home['revision_id']
    || !is_array($homepage['original_revision'] ?? NULL)
    || !is_array($homepage['target_revision'] ?? NULL)
    || ($homepage['original_revision']['revision_id'] ?? NULL)
      !== ($homepage['original_revision_id'] ?? NULL)
    || ($homepage['target_revision']['revision_id'] ?? NULL)
      !== ($homepage['target_revision_id'] ?? NULL)
    || !editorial_home_data_equals(
      $homepage['target_revision'] ?? NULL,
      $home['revision_identity'],
    )
    || !is_array($homepage['original_body'] ?? NULL)) {
    editorial_home_fail('The rollback copy does not identify the current /accueil entity and target revision exactly.');
  }
  $prestate = editorial_home_classify_body(
    $homepage['original_body'],
    $merged_body,
    $target_body,
  );
  if ($prestate === 'editorial_home_target'
    || ($homepage['reviewed_prestate'] ?? NULL) !== $prestate) {
    editorial_home_fail('The retained /accueil body is not the exact reviewed pre-state.');
  }
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  if (!$storage instanceof RevisionableStorageInterface) {
    editorial_home_fail('Node storage lost revision support while validating rollback state.');
  }
  $original_revision = $storage->loadRevision($homepage['original_revision_id']);
  if (!$original_revision instanceof NodeInterface
    || !editorial_home_data_equals(
      editorial_home_node_identity($original_revision),
      $home['node_identity'],
    )
    || !editorial_home_data_equals(
      editorial_home_revision_identity($original_revision),
      $homepage['original_revision'],
    )
    || !editorial_home_data_equals(
      editorial_home_body_snapshot($original_revision),
      $homepage['original_body'],
    )) {
    editorial_home_fail('The retained original /accueil revision changed or disappeared.');
  }
  return $raw;
}

/**
 * Build and validate the complete read-only plan.
 */
function editorial_home_preflight(
  DrupalKernel $kernel,
  Request $request,
  string $site_origin,
  string $git_head,
  string $action,
  bool $require_maintenance,
  bool $verbose,
): array {
  $drupal_root = realpath(__DIR__ . '/../web');
  $project_root = realpath(__DIR__ . '/..');
  $repo_root = $project_root === FALSE ? FALSE : realpath($project_root . '/..');
  if ($drupal_root === FALSE || $project_root === FALSE || $repo_root === FALSE
    || $drupal_root !== \Drupal::root()
    || realpath($kernel->getAppRoot()) !== $drupal_root
    || $kernel->getSitePath() !== 'sites/default') {
    editorial_home_fail('The bootstrapped Drupal root/site path differs from the reviewed checkout.');
  }
  if (PHP_VERSION_ID < 80300) {
    editorial_home_fail('PHP 8.3 or newer is required; active=' . PHP_VERSION . '.');
  }
  if (\Drupal::VERSION !== '11.3.3') {
    editorial_home_fail('Drupal must be exactly 11.3.3; active=' . \Drupal::VERSION . '.');
  }
  if (!InstalledVersions::isInstalled('drupal/core')
    || ltrim((string) InstalledVersions::getPrettyVersion('drupal/core'), 'v') !== '11.3.3') {
    editorial_home_fail('Composer runtime does not contain the locked drupal/core 11.3.3 package.');
  }
  $active_origin = strtolower($request->getSchemeAndHttpHost());
  if (!hash_equals($site_origin, $active_origin)) {
    editorial_home_fail('Bootstrapped origin mismatch; expected=' . $site_origin . ' active=' . $active_origin . '.');
  }
  if (!preg_match('/^[a-f0-9]{40}$/D', $git_head)) {
    editorial_home_fail('The reviewed Git object ID is invalid.');
  }
  if ($require_maintenance && \Drupal::state()->get('system.maintenance_mode', FALSE) !== TRUE) {
    editorial_home_fail('Apply and rollback require system.maintenance_mode=true before any write.');
  }

  $source_hashes = editorial_home_source_inventory($repo_root);
  $module_dir = $drupal_root . '/modules/custom/' . EDITORIAL_HOME_MODULE;
  editorial_home_assert_module_inventory($module_dir);
  $sync_dir = $project_root . '/config/sync';
  $view_source = editorial_home_read_yaml($sync_dir . '/' . EDITORIAL_HOME_VIEW_CONFIG . '.yml');
  $view_baseline = editorial_home_assert_view_source($view_source);
  $blog_block_name = 'block.block.unisonges_blog_posts';
  $blog_block_source = editorial_home_read_yaml($sync_dir . '/' . $blog_block_name . '.yml');
  editorial_home_assert_blog_block_source($blog_block_source);
  editorial_home_assert_source_config($blog_block_name, $blog_block_source);
  $forum_prerequisite_names = [
    'node.type.forum_topic',
    'core.base_field_override.node.forum_topic.status',
    'core.base_field_override.node.forum_topic.promote',
    'field.field.node.forum_topic.body',
    'field.field.node.forum_topic.comment',
    'core.entity_form_display.node.forum_topic.default',
    'core.entity_view_display.node.forum_topic.default',
    'core.entity_view_display.node.forum_topic.teaser',
    'views.view.forum_topics',
    'webform.webform.forum_blog_proposal',
    'block.block.unisonges_forum_topics',
    'block.block.unisonges_forum_blog_proposal',
    'field.field.comment.comment.comment_body',
  ];
  foreach ($forum_prerequisite_names as $forum_prerequisite_name) {
    $forum_prerequisite_source = editorial_home_read_yaml(
      $sync_dir . '/' . $forum_prerequisite_name . '.yml',
    );
    editorial_home_assert_source_config(
      $forum_prerequisite_name,
      $forum_prerequisite_source,
    );
  }
  $block_source = editorial_home_read_yaml($sync_dir . '/' . EDITORIAL_HOME_BLOCK_CONFIG . '.yml');
  editorial_home_assert_block_source($block_source);
  $core_extension_source = editorial_home_read_yaml($sync_dir . '/core.extension.yml');
  if (($core_extension_source['module'][EDITORIAL_HOME_MODULE] ?? NULL) !== 0) {
    editorial_home_fail('Reviewed core.extension must enable only the exact custom module entry at weight zero.');
  }
  $core_extension_baseline = $core_extension_source;
  unset($core_extension_baseline['module'][EDITORIAL_HOME_MODULE]);

  $module_info = editorial_home_read_yaml($module_dir . '/' . EDITORIAL_HOME_MODULE . '.info.yml');
  if (($module_info['type'] ?? NULL) !== 'module'
    || ($module_info['name'] ?? NULL) !== 'Accueil éditorial Uni-Songes'
    || ($module_info['hidden'] ?? NULL) !== TRUE
    || ($module_info['dependencies'] ?? NULL) !== [
      'drupal:block',
      'drupal:node',
      'drupal:path_alias',
      'drupal:taxonomy',
      'drupal:text',
      'drupal:user',
      'drupal:views',
    ]) {
    editorial_home_fail('The custom module info/dependency contract changed.');
  }
  $extension_path = \Drupal::service('extension.list.module')->getPathname(EDITORIAL_HOME_MODULE);
  if (realpath($drupal_root . '/' . $extension_path)
    !== $module_dir . '/' . EDITORIAL_HOME_MODULE . '.info.yml') {
    editorial_home_fail('Drupal extension discovery resolved the custom module outside its reviewed path.');
  }

  $required_modules = ['block', 'filter', 'node', 'path_alias', 'system', 'taxonomy', 'text', 'user', 'views'];
  foreach ($required_modules as $module_name) {
    if (!\Drupal::moduleHandler()->moduleExists($module_name)) {
      editorial_home_fail('Required dependency module is not enabled: ' . $module_name . '.');
    }
  }
  $module_enabled = \Drupal::moduleHandler()->moduleExists(EDITORIAL_HOME_MODULE);

  $system_site_source = editorial_home_read_yaml($sync_dir . '/system.site.yml');
  $system_site = editorial_home_active_config('system.site');
  if (($system_site_source['uuid'] ?? NULL) !== EDITORIAL_HOME_SITE_UUID
    || ($system_site['uuid'] ?? NULL) !== EDITORIAL_HOME_SITE_UUID
    || ($system_site['page']['front'] ?? NULL) !== '/accueil') {
    editorial_home_fail('Active site UUID/front path differs from the locked Uni-Songes site contract.');
  }
  $system_theme = editorial_home_active_config('system.theme');
  if (($system_theme['default'] ?? NULL) !== EDITORIAL_HOME_THEME) {
    editorial_home_fail('The active default theme must be exactly ' . EDITORIAL_HOME_THEME . '.');
  }
  $theme_info_path = $drupal_root . '/themes/custom/' . EDITORIAL_HOME_THEME
    . '/' . EDITORIAL_HOME_THEME . '.info.yml';
  $theme_info = editorial_home_read_yaml($theme_info_path);
  if (($theme_info['type'] ?? NULL) !== 'theme'
    || !isset($theme_info['regions'][EDITORIAL_HOME_REGION])
    || !isset($core_extension_source['theme'][EDITORIAL_HOME_THEME])) {
    editorial_home_fail('The reviewed default theme does not expose the required content region.');
  }

  foreach ([
    'field.field.node.article.field_tags',
    'field.field.node.page.body',
    'field.storage.node.field_tags',
    'taxonomy.vocabulary.tags',
  ] as $prerequisite_name) {
    $source = editorial_home_read_yaml($sync_dir . '/' . $prerequisite_name . '.yml');
    editorial_home_assert_source_config($prerequisite_name, $source);
  }
  $article_type = editorial_home_active_config('node.type.article');
  $page_type = editorial_home_active_config('node.type.page');
  $full_html = editorial_home_active_config('filter.format.' . EDITORIAL_HOME_BODY_FORMAT);
  if (($article_type['type'] ?? NULL) !== 'article'
    || ($page_type['type'] ?? NULL) !== 'page'
    || ($full_html['format'] ?? NULL) !== EDITORIAL_HOME_BODY_FORMAT
    || ($full_html['status'] ?? NULL) !== TRUE) {
    editorial_home_fail('Required Article/Page bundles or full_html format are unavailable.');
  }
  $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'article');
  $tags_field = $field_definitions['field_tags'] ?? NULL;
  if ($tags_field === NULL
    || $tags_field->getType() !== 'entity_reference'
    || $tags_field->getSetting('target_type') !== 'taxonomy_term'
    || ($tags_field->getSetting('handler_settings')['target_bundles'] ?? NULL) !== ['tags' => 'tags']) {
    editorial_home_fail('Article field_tags must be the exact Tags-vocabulary entity reference.');
  }
  $page_fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'page');
  if (!isset($page_fields['body']) || $page_fields['body']->getType() !== 'text_with_summary') {
    editorial_home_fail('Basic pages require the text_with_summary body field.');
  }

  $anonymous_config = editorial_home_active_config('user.role.anonymous');
  $anonymous_role = \Drupal::entityTypeManager()->getStorage('user_role')->load('anonymous');
  if (($anonymous_config['is_admin'] ?? NULL) !== FALSE
    || !in_array('access content', $anonymous_config['permissions'] ?? [], TRUE)
    || $anonymous_role === NULL
    || !$anonymous_role->hasPermission('access content')) {
    editorial_home_fail('Anonymous users must retain the access content permission.');
  }

  $content_source_path = $project_root . '/scripts/apply-content-architecture-2026.sh';
  $content_source = file_get_contents(editorial_home_exact_file($content_source_path));
  if (!is_string($content_source)) {
    editorial_home_fail('Could not read the approved content-architecture source.');
  }
  $merged_home_body = editorial_home_extract_page_body($content_source, 'accueil');
  $approved_about_body = editorial_home_extract_page_body($content_source, 'a_propos');
  $approved_blog_body = editorial_home_extract_page_body($content_source, 'blog');
  $approved_forum_body = editorial_home_extract_page_body($content_source, 'forum');
  $approved_sentence = "Le Blog accueillera les actualités de l'association, des articles artistiques et pédagogiques, ainsi que des réflexions et des ressources autour de ses pratiques et de ses projets.";
  if (substr_count($approved_blog_body, '<p>' . $approved_sentence . '</p>') !== 1) {
    editorial_home_fail('The approved /blog source sentence changed unexpectedly.');
  }

  $pages = [
    '/accueil' => editorial_home_resolve_page('/accueil', 'Accueil'),
    '/blog' => editorial_home_resolve_page('/blog', 'Blog'),
    '/a-propos' => editorial_home_resolve_page('/a-propos', 'À propos'),
    '/forum' => editorial_home_resolve_page('/forum', 'Forum'),
  ];
  $node_ids = array_map(
    static fn(array $page): int => $page['node_identity']['id'],
    $pages,
  );
  if (count(array_unique($node_ids, SORT_NUMERIC)) !== 4) {
    editorial_home_fail('/accueil, /blog, /a-propos, and /forum must own four distinct Basic pages.');
  }
  $anonymous = new AnonymousUserSession();
  foreach ($pages as $alias => $page) {
    if (!$page['node']->access('view', $anonymous, TRUE)->isAllowed()) {
      editorial_home_fail('Anonymous access is not allowed for the published page ' . $alias . '.');
    }
  }
  $about_body = $pages['/a-propos']['body'];
  if ($about_body['empty'] !== FALSE
    || $about_body['value'] !== $approved_about_body
    || $about_body['format'] !== EDITORIAL_HOME_BODY_FORMAT
    || $about_body['summary'] !== NULL) {
    editorial_home_fail('The current /a-propos body is not the exact approved content source.');
  }
  $blog_body = $pages['/blog']['body'];
  if ($blog_body['empty'] !== FALSE
    || substr_count($blog_body['value'], '<p>' . $approved_sentence . '</p>') !== 1) {
    editorial_home_fail('The current /blog body does not contain the one approved source sentence.');
  }
  $forum_body = $pages['/forum']['body'];
  if ($forum_body['empty'] !== FALSE
    || $forum_body['value'] !== $approved_forum_body
    || $forum_body['format'] !== EDITORIAL_HOME_BODY_FORMAT
    || $forum_body['summary'] !== NULL) {
    editorial_home_fail('The current /forum body is not the exact approved content source.');
  }
  $current_blog_sentence = $approved_sentence;
  $target_body = <<<HTML
<section class="unisonges-editorial-home-intro">
  <p class="unisonges-editorial-home-intro__kicker">Uni-Songes · Blog</p>
  <p class="unisonges-editorial-home-intro__deck">{$current_blog_sentence}</p>
  <a class="unisonges-editorial-home-intro__link" href="/blog">Parcourir le Blog complet</a>
</section>
HTML;
  if (preg_match('/<h[1-6]\b/i', $target_body)
    || str_contains($target_body, 'unisonges-card')
    || str_contains($target_body, 'reservation-cours')
    || substr_count($target_body, 'href="/blog"') !== 1
    || substr_count($target_body, '<a ') !== 1) {
    editorial_home_fail('The approved homepage introduction acquired a heading, card, CTA, or extra link.');
  }
  $home_state = editorial_home_classify_body(
    $pages['/accueil']['body'],
    $merged_home_body,
    $target_body,
  );

  $view_active = editorial_home_active_config(EDITORIAL_HOME_VIEW_CONFIG);
  $view_state = editorial_home_data_equals($view_active, $view_baseline)
    ? 'baseline_two_displays'
    : (editorial_home_data_equals($view_active, $view_source)
      ? 'target_three_displays'
      : 'unexpected');
  if ($view_state === 'unexpected') {
    editorial_home_fail('Active blog_posts View is neither the exact two-display baseline nor exact source target.');
  }

  $core_extension_active = editorial_home_active_config('core.extension');
  $core_state = editorial_home_data_equals($core_extension_active, $core_extension_baseline)
    ? 'module_disabled_baseline'
    : (editorial_home_data_equals($core_extension_active, $core_extension_source)
      ? 'module_enabled_target'
      : 'unexpected');
  if ($core_state === 'unexpected'
    || ($module_enabled && $core_state !== 'module_enabled_target')
    || (!$module_enabled && $core_state !== 'module_disabled_baseline')) {
    editorial_home_fail('Active core.extension and the module handler disagree with the exact baseline/target.');
  }
  if ($module_enabled) {
    $module_data = \Drupal::service('extension.list.module')->getList();
    $module_extension = $module_data[EDITORIAL_HOME_MODULE] ?? NULL;
    if (!is_object($module_extension)
      || !is_array($module_extension->required_by ?? NULL)) {
      editorial_home_fail('Could not inspect extension dependents of the custom module.');
    }
    $extension_dependents = [];
    foreach (array_keys($module_extension->required_by) as $dependent) {
      if (isset($core_extension_active['module'][$dependent])) {
        $extension_dependents[] = 'module:' . $dependent;
      }
      if (isset($core_extension_active['theme'][$dependent])) {
        $extension_dependents[] = 'theme:' . $dependent;
      }
    }
    sort($extension_dependents, SORT_STRING);
    if ($extension_dependents !== []) {
      editorial_home_fail('Enabled extensions depend on the custom module: '
        . implode(', ', $extension_dependents) . '.');
    }
  }

  $active_storage = \Drupal::service('config.storage');
  $block_active = $active_storage->read(EDITORIAL_HOME_BLOCK_CONFIG);
  $block_effective = \Drupal::config(EDITORIAL_HOME_BLOCK_CONFIG)->get();
  if ($block_active === FALSE) {
    if ($block_effective !== []) {
      editorial_home_fail('A runtime override fabricates the absent editorial homepage block.');
    }
    $block_state = 'missing';
  }
  elseif (is_array($block_active)
    && editorial_home_data_equals($block_active, $block_source)
    && editorial_home_data_equals($block_effective, $block_source)) {
    $block_state = 'target';
  }
  else {
    editorial_home_fail('The stored/effective editorial homepage block is neither absent nor the exact source target.');
  }
  $custom_plugin_blocks = [];
  foreach ($active_storage->listAll('block.block.') as $config_name) {
    $candidate = $active_storage->read($config_name);
    $effective_candidate = \Drupal::config($config_name)->get();
    if ((is_array($candidate) && ($candidate['plugin'] ?? NULL) === EDITORIAL_HOME_BLOCK_PLUGIN)
      || ($effective_candidate['plugin'] ?? NULL) === EDITORIAL_HOME_BLOCK_PLUGIN) {
      $custom_plugin_blocks[] = $config_name;
    }
  }
  sort($custom_plugin_blocks, SORT_STRING);
  $expected_plugin_blocks = $block_state === 'target' ? [EDITORIAL_HOME_BLOCK_CONFIG] : [];
  if ($custom_plugin_blocks !== $expected_plugin_blocks) {
    editorial_home_fail('Unexpected placed blocks use the editorial homepage plugin.');
  }
  if ($block_state === 'target') {
    if (!$module_enabled) {
      editorial_home_fail('The custom block exists while its provider module is disabled.');
    }
    $definition = \Drupal::service('plugin.manager.block')->getDefinition(EDITORIAL_HOME_BLOCK_PLUGIN, FALSE);
    if (!is_array($definition) || ($definition['provider'] ?? NULL) !== EDITORIAL_HOME_MODULE) {
      editorial_home_fail('The enabled custom block plugin definition is unavailable or has the wrong provider.');
    }
  }

  $config_snapshot = editorial_home_config_snapshot();
  $dependents = editorial_home_module_dependents($config_snapshot);
  if ($dependents !== []) {
    editorial_home_fail('Unexpected config depends on the custom module: ' . implode(', ', $dependents) . '.');
  }

  $contract = editorial_home_rollback_contract(
    $view_baseline,
    $view_source,
    $target_body,
    $source_hashes['drupal/scripts/apply-content-architecture-2026.sh'],
  );
  $state_record = editorial_home_read_rollback_state();
  $rollback_state = NULL;
  if ($state_record['exists']) {
    $rollback_state = editorial_home_validate_rollback_state(
      $state_record['value'],
      $pages['/accueil'],
      $merged_home_body,
      $target_body,
      $contract,
    );
  }

  $is_baseline = $view_state === 'baseline_two_displays'
    && $core_state === 'module_disabled_baseline'
    && !$module_enabled
    && $block_state === 'missing'
    && !$state_record['exists']
    && $home_state === 'reviewed_content_architecture_merged';
  $is_target = $view_state === 'target_three_displays'
    && $core_state === 'module_enabled_target'
    && $module_enabled
    && $block_state === 'target'
    && $state_record['exists']
    && $rollback_state !== NULL
    && $home_state === 'editorial_home_target';

  if ($action === 'install') {
    if ($is_baseline) {
      $operations = [
        ['type' => 'config_update', 'target' => EDITORIAL_HOME_VIEW_CONFIG],
        ['type' => 'module_enable', 'target' => EDITORIAL_HOME_MODULE],
        ['type' => 'config_create', 'target' => EDITORIAL_HOME_BLOCK_CONFIG],
        ['type' => 'content_body_update', 'target' => '/accueil'],
        ['type' => 'state_store', 'target' => EDITORIAL_HOME_STATE_KEY],
      ];
      $deployment_state = 'baseline';
    }
    elseif ($is_target) {
      $operations = [];
      $deployment_state = 'target';
    }
    else {
      editorial_home_fail(
        'Install state is partial or inconsistent; exact baseline or complete idempotent target required.',
      );
    }
  }
  else {
    if (!$state_record['exists']) {
      editorial_home_fail('Rollback requires the retained exact /accueil body/identity copy; it is missing.');
    }
    if (!$is_target) {
      editorial_home_fail('Rollback requires the complete exact target state and consistent retained copy.');
    }
    $uninstall_validation_key = 'UNISONGES_EDITORIAL_HOME_UNINSTALL_VALIDATION_AUTHORIZATION';
    if (array_key_exists($uninstall_validation_key, $GLOBALS)) {
      editorial_home_fail('A module-uninstall validation authorization already exists.');
    }
    $GLOBALS[$uninstall_validation_key] = [
      'version' => 1,
      'feature' => EDITORIAL_HOME_MODULE,
      'mode' => getenv('UNISONGES_EDITORIAL_HOME_MODE') ?: '',
      'action' => $action,
      'site_origin' => $site_origin,
      'git_head' => $git_head,
      'node_id' => $pages['/accueil']['node_identity']['id'],
    ];
    try {
      $uninstall_reasons = \Drupal::service('module_installer')
        ->validateUninstall([EDITORIAL_HOME_MODULE]);
    }
    finally {
      unset($GLOBALS[$uninstall_validation_key]);
    }
    if ($uninstall_reasons !== []) {
      editorial_home_fail('The module uninstall validator refused rollback: '
        . json_encode($uninstall_reasons, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '.');
    }
    $operations = [
      ['type' => 'content_body_restore', 'target' => '/accueil'],
      ['type' => 'config_delete', 'target' => EDITORIAL_HOME_BLOCK_CONFIG],
      ['type' => 'config_update', 'target' => EDITORIAL_HOME_VIEW_CONFIG],
      ['type' => 'module_disable', 'target' => EDITORIAL_HOME_MODULE],
      ['type' => 'state_delete', 'target' => EDITORIAL_HOME_STATE_KEY],
    ];
    $deployment_state = 'target';
  }

  $current_state = [
    'deployment_state' => $deployment_state,
    'view_state' => $view_state,
    'view_sha256' => editorial_home_hash_data($view_active),
    'core_extension_state' => $core_state,
    'core_extension_sha256' => editorial_home_hash_data($core_extension_active),
    'module_enabled' => $module_enabled,
    'block_state' => $block_state,
    'block_sha256' => is_array($block_active) ? editorial_home_hash_data($block_active) : NULL,
    'home_state' => $home_state,
    'home' => [
      'identity' => $pages['/accueil']['node_identity'],
      'alias' => $pages['/accueil']['alias_identity'],
      'revision_id' => $pages['/accueil']['revision_id'],
      'revision_identity' => $pages['/accueil']['revision_identity'],
      'body' => $pages['/accueil']['body'],
    ],
    'blog' => [
      'identity' => $pages['/blog']['node_identity'],
      'alias' => $pages['/blog']['alias_identity'],
      'body_sha256' => hash('sha256', $blog_body['value']),
    ],
    'about' => [
      'identity' => $pages['/a-propos']['node_identity'],
      'alias' => $pages['/a-propos']['alias_identity'],
      'body_sha256' => hash('sha256', $about_body['value']),
    ],
    'forum' => [
      'identity' => $pages['/forum']['node_identity'],
      'alias' => $pages['/forum']['alias_identity'],
      'body_sha256' => hash('sha256', $forum_body['value']),
    ],
    'rollback_state' => $state_record,
    'config_snapshot_sha256' => editorial_home_hash_data($config_snapshot),
  ];
  $plan_payload = [
    'version' => 1,
    'feature' => EDITORIAL_HOME_MODULE,
    'action' => $action,
    'site_origin' => $site_origin,
    'site_uuid' => EDITORIAL_HOME_SITE_UUID,
    'git_head' => $git_head,
    'runtime' => [
      'php' => PHP_VERSION,
      'drupal' => \Drupal::VERSION,
    ],
    'source_hashes' => $source_hashes,
    'contract' => $contract,
    'current_state' => $current_state,
    'operations' => $operations,
  ];
  $plan_token = editorial_home_hash_data($plan_payload);

  if ($verbose) {
    $planned_body_state = 'editorial_home_target';
    $planned_body_value = $target_body;
    if ($action === 'rollback') {
      $planned_body_state = (string) $rollback_state['homepage']['reviewed_prestate'];
      $planned_body_value = (string) $rollback_state['homepage']['original_body']['value'];
    }
    editorial_home_line('RUNTIME_OK', 'PHP=' . PHP_VERSION . ' Drupal=' . \Drupal::VERSION
      . ' site=' . $site_origin . ' uuid=' . EDITORIAL_HOME_SITE_UUID);
    editorial_home_line('SOURCE_COUNT', (string) count($source_hashes));
    foreach ($source_hashes as $relative_file => $hash) {
      editorial_home_line('SOURCE_SHA256', $hash . ' ' . $relative_file);
    }
    editorial_home_line('FEATURE_STATE', $deployment_state);
    editorial_home_line('HOME_PRESTATE', $home_state);
    editorial_home_print_body('ACCUEIL_CURRENT_BODY', $home_state, $pages['/accueil']['body']['value']);
    editorial_home_print_body('ACCUEIL_TARGET_BODY', $planned_body_state, $planned_body_value);
    editorial_home_line('PLAN_OPERATION_COUNT', (string) count($operations));
    foreach ($operations as $index => $operation) {
      editorial_home_line(
        'PLAN_OPERATION',
        ($index + 1) . '/' . count($operations) . ' ' . $operation['type'] . ' ' . $operation['target'],
      );
    }
    editorial_home_line('PLAN_TOKEN', $plan_token);
  }

  return [
    'plan_token' => $plan_token,
    'operations' => $operations,
    'source_hashes' => $source_hashes,
    'sources' => [
      'view_target' => $view_source,
      'view_baseline' => $view_baseline,
      'block' => $block_source,
      'core_extension_target' => $core_extension_source,
      'core_extension_baseline' => $core_extension_baseline,
    ],
    'config_snapshot' => $config_snapshot,
    'pages' => $pages,
    'home_state' => $home_state,
    'target_body' => $target_body,
    'merged_home_body' => $merged_home_body,
    'contract' => $contract,
    'rollback_state' => $rollback_state,
  ];
}

/**
 * Return a config-entity storage with the expected interface.
 */
function editorial_home_config_entity_storage(string $entity_type): ConfigEntityStorageInterface {
  $storage = \Drupal::entityTypeManager()->getStorage($entity_type);
  if (!$storage instanceof ConfigEntityStorageInterface) {
    editorial_home_fail('Expected config-entity storage for ' . $entity_type . '.');
  }
  return $storage;
}

/**
 * Write only the two mutable View properties through the View entity API.
 */
function editorial_home_write_view(array $target): void {
  $storage = editorial_home_config_entity_storage('view');
  $view = $storage->loadOverrideFree(EDITORIAL_HOME_VIEW_ID);
  if (!$view instanceof ConfigEntityInterface) {
    editorial_home_fail('Could not load the exact Blog View entity before write.');
  }
  $view->setSyncing(TRUE);
  $view->set('dependencies', $target['dependencies']);
  $view->set('display', $target['display']);
  $view->save();
  $storage->resetCache([EDITORIAL_HOME_VIEW_ID]);
  \Drupal::configFactory()->reset(EDITORIAL_HOME_VIEW_CONFIG);
  $written = \Drupal::service('config.storage')->read(EDITORIAL_HOME_VIEW_CONFIG);
  if (!is_array($written) || !editorial_home_data_equals($written, $target)) {
    editorial_home_fail('Post-write exact verification failed for ' . EDITORIAL_HOME_VIEW_CONFIG . '.');
  }
}

/**
 * Create the one exact block entity through its config-entity storage.
 */
function editorial_home_create_block(array $source): void {
  $storage = editorial_home_config_entity_storage('block');
  if ($storage->loadOverrideFree(EDITORIAL_HOME_BLOCK_ID) !== NULL) {
    editorial_home_fail('The target block appeared immediately before create.');
  }
  $block = $storage->create($source);
  if (!$block instanceof ConfigEntityInterface || $block->id() !== EDITORIAL_HOME_BLOCK_ID) {
    editorial_home_fail('Could not construct the exact target block config entity.');
  }
  $block->setSyncing(TRUE);
  $block->save();
  $storage->resetCache([EDITORIAL_HOME_BLOCK_ID]);
  \Drupal::configFactory()->reset(EDITORIAL_HOME_BLOCK_CONFIG);
  $written = editorial_home_active_config(EDITORIAL_HOME_BLOCK_CONFIG);
  if (!editorial_home_data_equals($written, $source)) {
    editorial_home_fail('Post-create exact verification failed for ' . EDITORIAL_HOME_BLOCK_CONFIG . '.');
  }
}

/**
 * Delete only the exact block entity.
 */
function editorial_home_delete_block(array $source): void {
  $storage = editorial_home_config_entity_storage('block');
  $block = $storage->loadOverrideFree(EDITORIAL_HOME_BLOCK_ID);
  $active = editorial_home_active_config(EDITORIAL_HOME_BLOCK_CONFIG);
  if (!$block instanceof ConfigEntityInterface
    || !editorial_home_data_equals($active, $source)) {
    editorial_home_fail('The exact target block changed immediately before rollback deletion.');
  }
  $block->setSyncing(TRUE);
  $storage->delete([$block]);
  $storage->resetCache([EDITORIAL_HOME_BLOCK_ID]);
  \Drupal::configFactory()->reset(EDITORIAL_HOME_BLOCK_CONFIG);
  if (\Drupal::service('config.storage')->exists(EDITORIAL_HOME_BLOCK_CONFIG)
    || \Drupal::config(EDITORIAL_HOME_BLOCK_CONFIG)->get() !== []) {
    editorial_home_fail('Post-delete verification failed for ' . EDITORIAL_HOME_BLOCK_CONFIG . '.');
  }
}

/**
 * Save one body-only revision and preserve stable entity identity.
 */
function editorial_home_write_body(
  int $node_id,
  array $expected_identity,
  int $expected_revision_id,
  array $expected_body,
  array $target_body,
  ?string $revision_message,
): array {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  if (!$storage instanceof RevisionableStorageInterface) {
    editorial_home_fail('Node storage lost exact revision support before the body write.');
  }
  $storage->resetCache([$node_id]);
  $node = $storage->load($node_id);
  $latest_revision_id = $storage->getLatestRevisionId($node_id);
  if (!$node instanceof NodeInterface
    || !$node->isDefaultRevision()
    || $latest_revision_id === NULL
    || (string) $latest_revision_id !== (string) $expected_revision_id
    || !editorial_home_data_equals(editorial_home_node_identity($node), $expected_identity)
    || (int) $node->getRevisionId() !== $expected_revision_id
    || !editorial_home_data_equals(editorial_home_body_snapshot($node), $expected_body)) {
    editorial_home_fail('/accueil changed immediately before its body-only revision.');
  }
  if ($target_body['empty'] === TRUE) {
    $node->set('body', []);
  }
  else {
    $node->set('body', [[
      'value' => $target_body['value'],
      'format' => $target_body['format'],
      'summary' => $target_body['summary'],
    ]]);
  }
  $node->setNewRevision(TRUE);
  $node->setRevisionLogMessage($revision_message);
  $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
  $node->setRevisionUserId((int) $node->getOwnerId());
  $node->save();

  $storage = \Drupal::entityTypeManager()->getStorage('node');
  if (!$storage instanceof RevisionableStorageInterface) {
    editorial_home_fail('Node storage lost exact revision support after the body write.');
  }
  $storage->resetCache([$node_id]);
  $written = $storage->load($node_id);
  $written_latest_revision_id = $storage->getLatestRevisionId($node_id);
  if (!$written instanceof NodeInterface
    || !$written->isDefaultRevision()
    || $written_latest_revision_id === NULL
    || (string) $written_latest_revision_id !== (string) $written->getRevisionId()
    || !editorial_home_data_equals(editorial_home_node_identity($written), $expected_identity)
    || !editorial_home_data_equals(editorial_home_body_snapshot($written), $target_body)
    || (int) $written->getRevisionId() === $expected_revision_id) {
    editorial_home_fail('Post-save verification failed for the /accueil body-only revision.');
  }
  return [
    'node' => $written,
    'revision_id' => (int) $written->getRevisionId(),
    'revision_identity' => editorial_home_revision_identity($written),
  ];
}

/**
 * Reinstate the retained original revision and remove only the feature revision.
 */
function editorial_home_restore_original_revision(
  int $node_id,
  array $expected_identity,
  int $target_revision_id,
  array $target_revision_identity,
  array $target_body,
  int $original_revision_id,
  array $original_revision_identity,
  array $original_body,
): void {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  if (!$storage instanceof RevisionableStorageInterface) {
    editorial_home_fail('Node storage lost exact revision support before rollback restoration.');
  }
  $storage->resetCache([$node_id]);
  $current = $storage->load($node_id);
  $original = $storage->loadRevision($original_revision_id);
  $latest_revision_id = $storage->getLatestRevisionId($node_id);
  if (!$current instanceof NodeInterface
    || !$original instanceof NodeInterface
    || !$current->isDefaultRevision()
    || $latest_revision_id === NULL
    || (string) $latest_revision_id !== (string) $target_revision_id
    || (int) $current->getRevisionId() !== $target_revision_id
    || !editorial_home_data_equals(editorial_home_node_identity($current), $expected_identity)
    || !editorial_home_data_equals(
      editorial_home_revision_identity($current),
      $target_revision_identity,
    )
    || !editorial_home_data_equals(editorial_home_body_snapshot($current), $target_body)
    || (int) $original->getRevisionId() !== $original_revision_id
    || !editorial_home_data_equals(editorial_home_node_identity($original), $expected_identity)
    || !editorial_home_data_equals(
      editorial_home_revision_identity($original),
      $original_revision_identity,
    )
    || !editorial_home_data_equals(editorial_home_body_snapshot($original), $original_body)) {
    editorial_home_fail('/accueil revision history changed immediately before exact rollback.');
  }

  $original->setNewRevision(FALSE);
  $original->isDefaultRevision(TRUE);
  $original->setSyncing(TRUE);
  $original->save();

  $storage = \Drupal::entityTypeManager()->getStorage('node');
  if (!$storage instanceof RevisionableStorageInterface) {
    editorial_home_fail('Node storage lost exact revision support during rollback restoration.');
  }
  $storage->resetCache([$node_id]);
  $restored = $storage->load($node_id);
  if (!$restored instanceof NodeInterface
    || !$restored->isDefaultRevision()
    || (int) $restored->getRevisionId() !== $original_revision_id
    || !editorial_home_data_equals(editorial_home_node_identity($restored), $expected_identity)
    || !editorial_home_data_equals(
      editorial_home_revision_identity($restored),
      $original_revision_identity,
    )
    || !editorial_home_data_equals(editorial_home_body_snapshot($restored), $original_body)) {
    editorial_home_fail('The original /accueil revision was not reinstated exactly.');
  }

  $storage->deleteRevision($target_revision_id);
  $storage->resetCache([$node_id]);
  $restored = $storage->load($node_id);
  $restored_latest_revision_id = $storage->getLatestRevisionId($node_id);
  if (!$restored instanceof NodeInterface
    || !$restored->isDefaultRevision()
    || $restored_latest_revision_id === NULL
    || (string) $restored_latest_revision_id !== (string) $original_revision_id
    || $storage->loadRevision($target_revision_id) !== NULL
    || !editorial_home_data_equals(editorial_home_node_identity($restored), $expected_identity)
    || !editorial_home_data_equals(
      editorial_home_revision_identity($restored),
      $original_revision_identity,
    )
    || !editorial_home_data_equals(editorial_home_body_snapshot($restored), $original_body)) {
    editorial_home_fail('Exact /accueil revision restoration or feature-revision deletion failed.');
  }
}

/**
 * Rebuild runtime caches after a transaction rollback or module lifecycle.
 */
function editorial_home_reset_runtime(DrupalKernel $kernel): void {
  try {
    $kernel->rebuildContainer();
  }
  catch (Throwable) {
    // The caller performs a persisted-state verification and reports failure.
  }
  if (\Drupal::hasService('state')) {
    \Drupal::state()->resetCache();
  }
  if (\Drupal::hasService('config.factory')) {
    \Drupal::configFactory()->reset();
  }
}

/**
 * Execute exactly the preflighted five-operation plan.
 */
function editorial_home_apply_plan(
  DrupalKernel $kernel,
  Request $request,
  string $site_origin,
  string $git_head,
  string $action,
  array $plan,
): void {
  $expected_count = count($plan['operations']);
  if ($expected_count === 0) {
    editorial_home_line('NO_CHANGE', 'APPLIED_OPERATION_COUNT 0; exact target already active.');
    return;
  }
  if ($expected_count !== 5) {
    editorial_home_fail('Non-idempotent plans must contain exactly five allowlisted operations.');
  }

  $before_config = $plan['config_snapshot'];
  $expected_config = $before_config;
  $default_collection = StorageInterface::DEFAULT_COLLECTION;
  if ($action === 'install') {
    $expected_config[$default_collection][EDITORIAL_HOME_VIEW_CONFIG]
      = $plan['sources']['view_target'];
    $expected_config[$default_collection][EDITORIAL_HOME_BLOCK_CONFIG]
      = $plan['sources']['block'];
    $expected_config[$default_collection]['core.extension']
      = $plan['sources']['core_extension_target'];
  }
  else {
    $expected_config[$default_collection][EDITORIAL_HOME_VIEW_CONFIG]
      = $plan['sources']['view_baseline'];
    unset($expected_config[$default_collection][EDITORIAL_HOME_BLOCK_CONFIG]);
    $expected_config[$default_collection]['core.extension']
      = $plan['sources']['core_extension_baseline'];
  }

  $connection = \Drupal::database();
  $transaction = $connection->startTransaction('unisonges_editorial_home_deploy');
  $applied_count = 0;
  try {
    $home = $plan['pages']['/accueil'];
    if ($action === 'install') {
      editorial_home_line('WRITE', EDITORIAL_HOME_VIEW_CONFIG . ' baseline -> three-display target');
      editorial_home_write_view($plan['sources']['view_target']);
      $applied_count++;

      editorial_home_line('WRITE', 'enable module ' . EDITORIAL_HOME_MODULE);
      if (!\Drupal::service('module_installer')->install([EDITORIAL_HOME_MODULE], FALSE)
        || !\Drupal::moduleHandler()->moduleExists(EDITORIAL_HOME_MODULE)) {
        editorial_home_fail('Drupal module API did not enable the exact custom module.');
      }
      $active_core = \Drupal::service('config.storage')->read('core.extension');
      if (!is_array($active_core)
        || !editorial_home_data_equals($active_core, $plan['sources']['core_extension_target'])) {
        editorial_home_fail('Module enable changed core.extension outside the exact reviewed target.');
      }
      $applied_count++;

      editorial_home_line('WRITE', 'create ' . EDITORIAL_HOME_BLOCK_CONFIG);
      editorial_home_create_block($plan['sources']['block']);
      $applied_count++;

      $target_body = [
        'empty' => FALSE,
        'value' => $plan['target_body'],
        'format' => EDITORIAL_HOME_BODY_FORMAT,
        'summary' => NULL,
        'items' => [[
          'value' => $plan['target_body'],
          'format' => EDITORIAL_HOME_BODY_FORMAT,
          'summary' => NULL,
        ]],
      ];
      editorial_home_line('WRITE', 'body-only revision for /accueil node/' . $home['node_identity']['id']);
      $written = editorial_home_write_body(
        $home['node_identity']['id'],
        $home['node_identity'],
        $home['revision_id'],
        $home['body'],
        $target_body,
        $home['revision_identity']['revision_log_message'],
      );
      $applied_count++;

      $rollback_state = [
        'version' => 2,
        'feature' => EDITORIAL_HOME_MODULE,
        'site_uuid' => EDITORIAL_HOME_SITE_UUID,
        'contract' => $plan['contract'],
        'homepage' => [
          'identity' => $home['node_identity'],
          'alias' => $home['alias_identity'],
          'original_revision_id' => $home['revision_id'],
          'target_revision_id' => $written['revision_id'],
          'original_revision' => $home['revision_identity'],
          'target_revision' => $written['revision_identity'],
          'original_body' => $home['body'],
          'reviewed_prestate' => $plan['home_state'],
        ],
      ];
      editorial_home_line('WRITE', 'store ' . EDITORIAL_HOME_STATE_KEY);
      \Drupal::state()->set(EDITORIAL_HOME_STATE_KEY, $rollback_state);
      \Drupal::state()->resetCache();
      if (!editorial_home_data_equals(
        editorial_home_read_rollback_state(),
        ['exists' => TRUE, 'value' => $rollback_state],
      )) {
        editorial_home_fail('Post-store verification failed for the rollback body/identity copy.');
      }
      $applied_count++;
    }
    else {
      $rollback_state = $plan['rollback_state'];
      if (!is_array($rollback_state)) {
        editorial_home_fail('Rollback state disappeared before the transaction.');
      }
      $original_body = $rollback_state['homepage']['original_body'];
      editorial_home_line('WRITE', 'restore original revision for /accueil node/'
        . $home['node_identity']['id']);
      editorial_home_restore_original_revision(
        $home['node_identity']['id'],
        $home['node_identity'],
        $home['revision_id'],
        $home['revision_identity'],
        $home['body'],
        $rollback_state['homepage']['original_revision_id'],
        $rollback_state['homepage']['original_revision'],
        $original_body,
      );
      $applied_count++;

      editorial_home_line('WRITE', 'delete ' . EDITORIAL_HOME_BLOCK_CONFIG);
      editorial_home_delete_block($plan['sources']['block']);
      $applied_count++;

      editorial_home_line('WRITE', EDITORIAL_HOME_VIEW_CONFIG . ' target -> exact two-display baseline');
      editorial_home_write_view($plan['sources']['view_baseline']);
      $applied_count++;

      $snapshot_before_uninstall = editorial_home_config_snapshot();
      if (editorial_home_module_dependents($snapshot_before_uninstall) !== []) {
        editorial_home_fail('A module config dependent appeared immediately before uninstall.');
      }
      editorial_home_line('WRITE', 'uninstall module ' . EDITORIAL_HOME_MODULE);
      if (!\Drupal::service('module_installer')->uninstall([EDITORIAL_HOME_MODULE], FALSE)
        || \Drupal::moduleHandler()->moduleExists(EDITORIAL_HOME_MODULE)) {
        editorial_home_fail('Drupal module API did not uninstall the exact custom module.');
      }
      $active_core = \Drupal::service('config.storage')->read('core.extension');
      if (!is_array($active_core)
        || !editorial_home_data_equals($active_core, $plan['sources']['core_extension_baseline'])) {
        editorial_home_fail('Module uninstall did not restore exact baseline core.extension.');
      }
      $applied_count++;

      editorial_home_line('WRITE', 'delete ' . EDITORIAL_HOME_STATE_KEY);
      \Drupal::state()->delete(EDITORIAL_HOME_STATE_KEY);
      \Drupal::state()->resetCache();
      if (editorial_home_read_rollback_state()['exists']) {
        editorial_home_fail('Post-delete verification failed for the rollback state copy.');
      }
      $applied_count++;
    }

    if ($applied_count !== $expected_count) {
      editorial_home_fail('Applied operation count differs from the exact plan.');
    }
    $after_config = editorial_home_config_snapshot();
    if (!editorial_home_data_equals($after_config, $expected_config)) {
      editorial_home_fail('The transaction changed active configuration outside the exact allowlist.');
    }

    $verified = editorial_home_preflight(
      $kernel,
      $request,
      $site_origin,
      $git_head,
      'install',
      TRUE,
      FALSE,
    );
    if ($action === 'install' && count($verified['operations']) !== 0) {
      editorial_home_fail('Final install verification did not reach the exact idempotent target.');
    }
    if ($action === 'rollback') {
      if (count($verified['operations']) !== 5
        || $verified['home_state'] !== 'reviewed_content_architecture_merged') {
        editorial_home_fail('Final rollback verification did not restore the exact original baseline.');
      }
    }
    $transaction->commitOrRelease();
  }
  catch (Throwable $throwable) {
    $rollback_problem = NULL;
    try {
      $transaction->rollBack();
      editorial_home_reset_runtime($kernel);
      $restored = editorial_home_preflight(
        $kernel,
        $request,
        $site_origin,
        $git_head,
        $action,
        TRUE,
        FALSE,
      );
      if (!hash_equals($plan['plan_token'], $restored['plan_token'])
        || !editorial_home_data_equals(editorial_home_config_snapshot(), $before_config)) {
        editorial_home_fail('Persisted state differs after transaction rollback.');
      }
    }
    catch (Throwable $rollback_error) {
      $rollback_problem = $rollback_error->getMessage();
    }
    if ($rollback_problem !== NULL) {
      editorial_home_fail('Apply failed and rollback verification also failed: '
        . $throwable->getMessage() . ' / rollback: ' . $rollback_problem);
    }
    editorial_home_fail('Apply failed; transaction rollback was verified: ' . $throwable->getMessage());
  }

  editorial_home_line('APPLIED_OPERATION_COUNT', (string) $applied_count);
  editorial_home_line('APPLIED', 'Exact targeted ' . $action . ' committed atomically.');
}

$mode = getenv('UNISONGES_EDITORIAL_HOME_MODE') ?: '';
$action = getenv('UNISONGES_EDITORIAL_HOME_ACTION') ?: '';
$site_origin = getenv('UNISONGES_EDITORIAL_HOME_SITE_URI') ?: '';
$supplied_plan_token = getenv('UNISONGES_EDITORIAL_HOME_PLAN_TOKEN') ?: '';
$git_head = getenv('UNISONGES_EDITORIAL_HOME_GIT_HEAD') ?: '';

$kernel = NULL;
try {
  if (!in_array($mode, ['dry-run', 'apply'], TRUE)
    || !in_array($action, ['install', 'rollback'], TRUE)) {
    editorial_home_fail('Invalid internal mode/action contract.');
  }
  if (($mode === 'dry-run' && $supplied_plan_token !== '')
    || ($mode === 'apply' && !preg_match('/^[a-f0-9]{64}$/D', $supplied_plan_token))) {
    editorial_home_fail('Internal plan-token arguments do not match the selected mode.');
  }
  $uri_parts = parse_url($site_origin);
  if (!is_array($uri_parts)
    || !in_array($uri_parts['scheme'] ?? NULL, ['http', 'https'], TRUE)
    || !isset($uri_parts['host'])
    || $uri_parts['host'] === ''
    || isset($uri_parts['user'])
    || isset($uri_parts['pass'])
    || isset($uri_parts['query'])
    || isset($uri_parts['fragment'])
    || isset($uri_parts['path'])) {
    editorial_home_fail('Internal site URI must be the normalized approved root origin.');
  }

  $drupal_root = realpath(__DIR__ . '/../web');
  if ($drupal_root === FALSE
    || $drupal_root === '/'
    || str_starts_with($drupal_root, '/tmp/')
    || str_starts_with($drupal_root, '/mnt/c/')) {
    editorial_home_fail('Direct bootstrap refused an unsafe or missing Drupal root.');
  }
  editorial_home_exact_file($drupal_root . '/autoload.php');
  chdir($drupal_root);
  if (!defined('DRUPAL_ROOT')) {
    define('DRUPAL_ROOT', $drupal_root);
  }
  $autoloader = require $drupal_root . '/autoload.php';
  $request = Request::create($site_origin . '/', 'GET');
  $kernel = DrupalKernel::createFromRequest(
    $request,
    $autoloader,
    'prod',
    FALSE,
    $drupal_root,
  );
  $kernel->boot();
  $kernel->preHandle($request);

  editorial_home_line('MODE', $mode);
  editorial_home_line('ACTION', $action);
  $plan = editorial_home_preflight(
    $kernel,
    $request,
    $site_origin,
    $git_head,
    $action,
    $mode === 'apply',
    TRUE,
  );

  if ($mode === 'dry-run') {
    editorial_home_line('DRY_RUN_OK', 'No configuration, state, module, or content write occurred.');
  }
  else {
    if (!hash_equals($plan['plan_token'], $supplied_plan_token)) {
      editorial_home_fail('Plan token mismatch; rerun dry-run against the current source/site/state.');
    }

    $persistent_lock = \Drupal::service('lock.persistent');
    $feature_lock = \Drupal::lock();
    $lock_ttl = 3600.0;
    if (!$persistent_lock->acquire(ConfigImporter::LOCK_NAME, $lock_ttl)) {
      editorial_home_fail('Could not acquire Drupal\'s persistent config-importer lock.');
    }
    $feature_lock_acquired = FALSE;
    try {
      if (!$feature_lock->acquire(EDITORIAL_HOME_LOCK, $lock_ttl)) {
        editorial_home_fail('Could not acquire the editorial-home feature lock.');
      }
      $feature_lock_acquired = TRUE;
      $locked_plan = editorial_home_preflight(
        $kernel,
        $request,
        $site_origin,
        $git_head,
        $action,
        TRUE,
        FALSE,
      );
      if (!hash_equals($plan['plan_token'], $locked_plan['plan_token'])
        || !hash_equals($supplied_plan_token, $locked_plan['plan_token'])) {
        editorial_home_fail('Source/site/current state changed after planning or before the locked write.');
      }
      if (!$persistent_lock->acquire(ConfigImporter::LOCK_NAME, $lock_ttl)
        || !$feature_lock->acquire(EDITORIAL_HOME_LOCK, $lock_ttl)) {
        editorial_home_fail('Could not renew both locks immediately before the targeted transaction.');
      }
      $body_transition_constant = 'UNISONGES_EDITORIAL_HOME_BODY_TRANSITION_AUTHORIZATION';
      if (defined($body_transition_constant)
        || !define($body_transition_constant, $locked_plan['plan_token'])) {
        editorial_home_fail('Could not establish the one-process body-transition authorization.');
      }
      $home = $locked_plan['pages']['/accueil'];
      $authorized_target_body = $action === 'install'
        ? [
          'empty' => FALSE,
          'value' => $locked_plan['target_body'],
          'format' => EDITORIAL_HOME_BODY_FORMAT,
          'summary' => NULL,
          'items' => [[
            'value' => $locked_plan['target_body'],
            'format' => EDITORIAL_HOME_BODY_FORMAT,
            'summary' => NULL,
          ]],
        ]
        : ($locked_plan['rollback_state']['homepage']['original_body'] ?? NULL);
      if (!is_array($authorized_target_body)) {
        editorial_home_fail('Could not resolve the exact authorized target body.');
      }
      $GLOBALS['UNISONGES_EDITORIAL_HOME_BODY_TRANSITION_AUTHORIZATION'] = [
        'version' => 1,
        'action' => $action,
        'plan_token' => $locked_plan['plan_token'],
        'git_head' => $git_head,
        'node_id' => $home['node_identity']['id'],
        'expected_body' => $home['body'],
        'target_body' => $authorized_target_body,
      ];
      editorial_home_line('PREWRITE_LOCKED', 'All dependencies and the exact plan were revalidated; writes=0.');
      editorial_home_apply_plan(
        $kernel,
        $request,
        $site_origin,
        $git_head,
        $action,
        $locked_plan,
      );
    }
    finally {
      unset($GLOBALS['UNISONGES_EDITORIAL_HOME_BODY_TRANSITION_AUTHORIZATION']);
      if ($feature_lock_acquired) {
        $feature_lock->release(EDITORIAL_HOME_LOCK);
      }
      $persistent_lock->release(ConfigImporter::LOCK_NAME);
    }
  }
}
catch (Throwable $throwable) {
  fwrite(STDERR, 'REFUSE ' . $throwable->getMessage() . PHP_EOL);
  if ($kernel instanceof DrupalKernel) {
    try {
      $kernel->shutdown();
    }
    catch (Throwable) {
      // The original fail-closed error remains authoritative.
    }
  }
  exit(1);
}

if ($kernel instanceof DrupalKernel) {
  $kernel->shutdown();
}
