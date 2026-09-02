<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Drupal\Core\Config\CachedStorage;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\DatabaseStorage;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigDependencyManager;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Session\UserSession;
use Drupal\field\FieldConfigInterface;
use Symfony\Component\Yaml\Yaml;

$mode = getenv('UNISONGES_FORUM_BLOG_MODE') ?: 'dry-run';
$action = getenv('UNISONGES_FORUM_BLOG_ACTION') ?: 'install';

if (!in_array($mode, ['dry-run', 'apply'], TRUE)) {
  throw new RuntimeException('Invalid mode; expected dry-run or apply.');
}
if (!in_array($action, ['install', 'rollback'], TRUE)) {
  throw new RuntimeException('Invalid action; expected install or rollback.');
}

$is_apply = $mode === 'apply';
$project_root = dirname(\Drupal::root());
$sync_dir = $project_root . '/config/sync';
$cached_config_storage = \Drupal::service('config.storage');
$config_storage = NULL;
$entity_type_manager = \Drupal::entityTypeManager();

// Creation order is dependency order. Rollback uses the exact reverse order.
$targets = [
  [
    'name' => 'node.type.forum_topic',
    'entity_type' => 'node_type',
    'prefix' => 'node.type',
    'id_key' => 'type',
    'id' => 'forum_topic',
  ],
  [
    'name' => 'core.base_field_override.node.forum_topic.status',
    'entity_type' => 'base_field_override',
    'prefix' => 'core.base_field_override',
    'id_key' => 'id',
    'id' => 'node.forum_topic.status',
  ],
  [
    'name' => 'core.base_field_override.node.forum_topic.promote',
    'entity_type' => 'base_field_override',
    'prefix' => 'core.base_field_override',
    'id_key' => 'id',
    'id' => 'node.forum_topic.promote',
  ],
  [
    'name' => 'field.field.node.forum_topic.body',
    'entity_type' => 'field_config',
    'prefix' => 'field.field',
    'id_key' => 'id',
    'id' => 'node.forum_topic.body',
  ],
  [
    'name' => 'field.field.node.forum_topic.comment',
    'entity_type' => 'field_config',
    'prefix' => 'field.field',
    'id_key' => 'id',
    'id' => 'node.forum_topic.comment',
  ],
  [
    'name' => 'core.entity_form_display.node.forum_topic.default',
    'entity_type' => 'entity_form_display',
    'prefix' => 'core.entity_form_display',
    'id_key' => 'id',
    'id' => 'node.forum_topic.default',
  ],
  [
    'name' => 'core.entity_view_display.node.forum_topic.default',
    'entity_type' => 'entity_view_display',
    'prefix' => 'core.entity_view_display',
    'id_key' => 'id',
    'id' => 'node.forum_topic.default',
  ],
  [
    'name' => 'core.entity_view_display.node.forum_topic.teaser',
    'entity_type' => 'entity_view_display',
    'prefix' => 'core.entity_view_display',
    'id_key' => 'id',
    'id' => 'node.forum_topic.teaser',
  ],
  [
    'name' => 'views.view.blog_posts',
    'entity_type' => 'view',
    'prefix' => 'views.view',
    'id_key' => 'id',
    'id' => 'blog_posts',
  ],
  [
    'name' => 'views.view.forum_topics',
    'entity_type' => 'view',
    'prefix' => 'views.view',
    'id_key' => 'id',
    'id' => 'forum_topics',
  ],
  [
    'name' => 'webform.webform.forum_blog_proposal',
    'entity_type' => 'webform',
    'prefix' => 'webform.webform',
    'id_key' => 'id',
    'id' => 'forum_blog_proposal',
  ],
  [
    'name' => 'block.block.unisonges_blog_posts',
    'entity_type' => 'block',
    'prefix' => 'block.block',
    'id_key' => 'id',
    'id' => 'unisonges_blog_posts',
  ],
  [
    'name' => 'block.block.unisonges_forum_topics',
    'entity_type' => 'block',
    'prefix' => 'block.block',
    'id_key' => 'id',
    'id' => 'unisonges_forum_topics',
  ],
  [
    'name' => 'block.block.unisonges_forum_blog_proposal',
    'entity_type' => 'block',
    'prefix' => 'block.block',
    'id_key' => 'id',
    'id' => 'unisonges_forum_blog_proposal',
  ],
];

$section = static function (string $title): void {
  echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
};

$fail = static function (string $message): never {
  throw new RuntimeException($message);
};

$section('Locked runtime');
if (get_class($cached_config_storage) !== CachedStorage::class) {
  $fail('The public active config storage must be Drupal core CachedStorage exactly.');
}
try {
  $wrapped_storage_property = new ReflectionProperty(CachedStorage::class, 'storage');
  $config_storage = $wrapped_storage_property->getValue($cached_config_storage);
}
catch (ReflectionException $exception) {
  $fail('Could not inspect the pinned Drupal config-storage backend: ' . $exception->getMessage());
}
if (!$config_storage instanceof DatabaseStorage
  || $config_storage->getCollectionName() !== StorageInterface::DEFAULT_COLLECTION) {
  $fail('CachedStorage must wrap the default-collection DatabaseStorage directly.');
}
try {
  $connection_property = new ReflectionProperty(DatabaseStorage::class, 'connection');
  $table_property = new ReflectionProperty(DatabaseStorage::class, 'table');
  $storage_connection = $connection_property->getValue($config_storage);
  $storage_table = $table_property->getValue($config_storage);
}
catch (ReflectionException $exception) {
  $fail('Could not verify the pinned DatabaseStorage backend: ' . $exception->getMessage());
}
if ($storage_connection !== \Drupal::database() || $storage_table !== 'config') {
  $fail('Active config writes must use this site connection and the core config table.');
}
$expected_site_uri = getenv('UNISONGES_FORUM_BLOG_SITE_URI');
$site_uri_parts = is_string($expected_site_uri) ? parse_url($expected_site_uri) : FALSE;
if (!is_array($site_uri_parts)
  || !in_array($site_uri_parts['scheme'] ?? NULL, ['http', 'https'], TRUE)
  || !is_string($site_uri_parts['host'] ?? NULL)
  || $site_uri_parts['host'] === ''
  || isset($site_uri_parts['user'])
  || isset($site_uri_parts['pass'])
  || isset($site_uri_parts['query'])
  || isset($site_uri_parts['fragment'])
  || !in_array($site_uri_parts['path'] ?? '', ['', '/'], TRUE)) {
  $fail('UNISONGES_FORUM_BLOG_SITE_URI must be an approved absolute HTTP(S) site origin.');
}
$expected_site_origin = strtolower(
  $site_uri_parts['scheme'] . '://' . $site_uri_parts['host']
  . (isset($site_uri_parts['port']) ? ':' . $site_uri_parts['port'] : '')
);
$active_site_origin = strtolower(\Drupal::request()->getSchemeAndHttpHost());
if (!hash_equals($expected_site_origin, $active_site_origin)) {
  $fail(
    'Bootstrapped site URI mismatch; expected=' . $expected_site_origin
    . ' active=' . $active_site_origin . '.'
  );
}
$reviewed_project_root = realpath(__DIR__ . '/..');
$reviewed_docroot = $reviewed_project_root === FALSE
  ? FALSE
  : realpath($reviewed_project_root . '/web');
if ($reviewed_docroot === FALSE || $reviewed_docroot !== \Drupal::root()) {
  $fail('Bootstrapped Drupal root does not match the reviewed helper checkout.');
}
if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 3) {
  $fail('The reviewed deployment runtime is PHP 8.3.x; active=' . PHP_VERSION . '.');
}
if (\Drupal::VERSION !== '11.3.3') {
  $fail('The reviewed Drupal runtime is 11.3.3; active=' . \Drupal::VERSION . '.');
}
foreach ([
  'drupal/webform' => '6.3.0-beta7',
  'drush/drush' => '13.7.1',
] as $package => $expected_version) {
  if (!InstalledVersions::isInstalled($package)) {
    $fail('Required locked package is not installed: ' . $package . '.');
  }
  $active_version = ltrim((string) InstalledVersions::getPrettyVersion($package), 'v');
  if ($active_version !== $expected_version) {
    $fail(
      'The reviewed ' . $package . ' runtime is ' . $expected_version
      . '; active=' . $active_version . '.'
    );
  }
}
if (!\Drupal::database()->schema()->tableExists('config')) {
  $fail('The targeted apply requires Drupal cached configuration backed by the config database table.');
}
echo 'RUNTIME OK site=' . $active_site_origin
  . ' (PHP 8.3; Drupal 11.3.3; Webform 6.3.0-beta7; Drush 13.7.1)' . PHP_EOL;

$get_config_entity_storage = static function (string $entity_type_id) use (
  $entity_type_manager,
  $fail
): ConfigEntityStorageInterface {
  $storage = $entity_type_manager->getStorage($entity_type_id);
  if (!$storage instanceof ConfigEntityStorageInterface) {
    $fail('Expected config-entity storage for ' . $entity_type_id . '.');
  }
  return $storage;
};

$canonicalize = static function ($value) use (&$canonicalize) {
  if (!is_array($value)) {
    return $value;
  }

  foreach ($value as $key => $item) {
    $value[$key] = $canonicalize($item);
  }
  if (!array_is_list($value)) {
    ksort($value, SORT_STRING);
  }
  return $value;
};

$normalized_hash = static function ($value) use ($canonicalize): string {
  $json = json_encode(
    $canonicalize($value),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
  );
  if ($json === FALSE) {
    throw new RuntimeException('Could not normalize data for hashing.');
  }
  return hash('sha256', $json);
};

$raw_config_names = $config_storage->listAll();
$cached_config_names = $cached_config_storage->listAll();
sort($raw_config_names, SORT_STRING);
sort($cached_config_names, SORT_STRING);
$raw_config_values = $config_storage->readMultiple($raw_config_names);
$cached_config_values = $cached_config_storage->readMultiple($cached_config_names);
if ($raw_config_names !== $cached_config_names
  || $canonicalize($raw_config_values) !== $canonicalize($cached_config_values)) {
  $fail('Cached active configuration does not exactly match the raw config database table.');
}
echo 'ACTIVE STORAGE OK (cached and raw database-backed default collection match)' . PHP_EOL;

$target_names = array_fill_keys(array_column($targets, 'name'), TRUE);
$editorial_home_block_name = 'block.block.unisonges_editorial_home';
$editorial_home_module_name = 'unisonges_editorial_home';
$editorial_home_state_key = 'unisonges_editorial_home.rollback.v1';
$sources = [];
$source_hashes = [];

$section('Reviewed source configuration');
foreach ($targets as $target) {
  $expected_path = $sync_dir . '/' . $target['name'] . '.yml';
  $resolved_path = realpath($expected_path);
  if ($resolved_path === FALSE || $resolved_path !== $expected_path || is_link($expected_path)) {
    $fail('Exact source-path guard failed for ' . $expected_path . '.');
  }

  try {
    $data = Yaml::parseFile($resolved_path);
  }
  catch (Throwable $throwable) {
    $fail('Could not parse ' . $target['name'] . ': ' . $throwable->getMessage());
  }
  if (!is_array($data)) {
    $fail('Source config must be a top-level mapping: ' . $target['name']);
  }
  if (($data[$target['id_key']] ?? NULL) !== $target['id']) {
    $fail('Config entity id mismatch in ' . $target['name'] . '.');
  }
  if (!isset($data['uuid']) || !is_string($data['uuid']) || !preg_match(
    '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
    $data['uuid']
  )) {
    $fail('Config UUID is missing or invalid in ' . $target['name'] . '.');
  }

  if (!$entity_type_manager->hasDefinition($target['entity_type'])) {
    $fail('Config entity type is unavailable: ' . $target['entity_type']);
  }
  $definition = $entity_type_manager->getDefinition($target['entity_type']);
  if (!$definition->entityClassImplements(ConfigEntityInterface::class)) {
    $fail('Target is not a config entity type: ' . $target['entity_type']);
  }
  if ($definition->getConfigPrefix() !== $target['prefix']) {
    $fail('Unexpected config prefix for ' . $target['entity_type'] . '.');
  }

  $sources[$target['name']] = $data;
  $source_hashes[$target['name']] = hash_file('sha256', $resolved_path);
  echo 'SOURCE ' . $target['name'] . ' sha256=' . $source_hashes[$target['name']] . PHP_EOL;
}

$source_uuid_owners = [];
foreach ($sources as $name => $data) {
  $uuid = $data['uuid'];
  if (isset($source_uuid_owners[$uuid])) {
    $fail(
      'Target source UUID collision between ' . $source_uuid_owners[$uuid]
      . ' and ' . $name . '.'
    );
  }
  $source_uuid_owners[$uuid] = $name;
}
if ($action === 'install') {
  $all_active_names = $config_storage->listAll();
  $all_active_values = $config_storage->readMultiple($all_active_names);
  foreach ($all_active_values as $active_name => $active_data) {
    if (!is_array($active_data) || !isset($active_data['uuid'])) {
      continue;
    }
    $uuid = (string) $active_data['uuid'];
    if (isset($source_uuid_owners[$uuid]) && $source_uuid_owners[$uuid] !== $active_name) {
      $fail(
        'Target source UUID for ' . $source_uuid_owners[$uuid]
        . ' is already owned by active config ' . $active_name . '.'
      );
    }
  }
}
echo $action === 'install'
  ? 'SOURCE UUID NAMESPACE OK (14 unique targets; no active cross-name collision)' . PHP_EOL
  : 'SOURCE UUIDS OK (14 internally unique rollback identities)' . PHP_EOL;

$comment_field_name = 'field.field.comment.comment.comment_body';
$comment_field_path = $sync_dir . '/' . $comment_field_name . '.yml';
$resolved_comment_field_path = realpath($comment_field_path);
if ($resolved_comment_field_path === FALSE
  || $resolved_comment_field_path !== $comment_field_path
  || is_link($comment_field_path)) {
  $fail('Exact source-path guard failed for ' . $comment_field_path . '.');
}
$comment_field_source = Yaml::parseFile($resolved_comment_field_path);
if (!is_array($comment_field_source)
  || ($comment_field_source['id'] ?? NULL) !== 'comment.comment.comment_body'
  || ($comment_field_source['field_type'] ?? NULL) !== 'text_long'
  || ($comment_field_source['settings']['allowed_formats'] ?? NULL) !== ['basic_html']
  || !in_array(
    'filter.format.basic_html',
    $comment_field_source['dependencies']['config'] ?? [],
    TRUE
  )) {
  $fail('The reviewed comment body source must allow and depend on exactly basic_html.');
}
$source_hashes[$comment_field_name] = hash_file('sha256', $resolved_comment_field_path);
echo 'SOURCE ' . $comment_field_name . ' sha256=' . $source_hashes[$comment_field_name] . PHP_EOL;

$matches_string_set = static function ($actual, array $expected): bool {
  if (!is_array($actual) || !array_is_list($actual)) {
    return FALSE;
  }
  foreach ($actual as $item) {
    if (!is_string($item)) {
      return FALSE;
    }
  }
  sort($actual, SORT_STRING);
  sort($expected, SORT_STRING);
  return $actual === $expected;
};

// Assert the security and routing properties that must not be weakened by a
// seemingly innocuous YAML edit.
$assert_view = static function (array $view, string $bundle, string $name) use (
  $canonicalize,
  $matches_string_set,
  $fail
): void {
  $expected_id = substr($name, strlen('views.view.'));
  $displays = $view['display'] ?? NULL;
  $expected_display_ids = $name === 'views.view.blog_posts'
    ? ['default', 'block_1', 'editorial_home']
    : ['default', 'block_1'];
  $options = is_array($displays)
    ? ($displays['default']['display_options'] ?? NULL)
    : NULL;
  $block_options = is_array($displays)
    ? ($displays['block_1']['display_options'] ?? NULL)
    : NULL;
  $status_filter = $options['filters']['status'] ?? NULL;
  $bundle_filter = $options['filters']['type'] ?? NULL;
  if (($view['status'] ?? NULL) !== TRUE
    || ($view['id'] ?? NULL) !== $expected_id
    || ($view['module'] ?? NULL) !== 'views'
    || !is_array($displays)
    || array_keys($displays) !== $expected_display_ids
    || ($displays['default']['id'] ?? NULL) !== 'default'
    || ($displays['default']['display_plugin'] ?? NULL) !== 'default'
    || ($displays['block_1']['id'] ?? NULL) !== 'block_1'
    || ($displays['block_1']['display_plugin'] ?? NULL) !== 'block'
    || !is_array($block_options)
    || array_keys($block_options) !== [
      'block_description',
      'block_category',
      'display_extenders',
    ]
    || ($view['base_table'] ?? NULL) !== 'node_field_data'
    || ($view['base_field'] ?? NULL) !== 'nid'
    || !is_array($options)
    || !is_array($status_filter)
    || ($status_filter['table'] ?? NULL) !== 'node_field_data'
    || ($status_filter['field'] ?? NULL) !== 'status'
    || ($status_filter['plugin_id'] ?? NULL) !== 'boolean'
    || ($status_filter['value'] ?? NULL) !== '1'
    || !is_array($bundle_filter)
    || ($bundle_filter['table'] ?? NULL) !== 'node_field_data'
    || ($bundle_filter['field'] ?? NULL) !== 'type'
    || ($bundle_filter['plugin_id'] ?? NULL) !== 'bundle'
    || ($bundle_filter['value'] ?? NULL) !== [$bundle => $bundle]
    || ($options['sorts']['created']['order'] ?? NULL) !== 'DESC'
    || ($options['access']['type'] ?? NULL) !== 'perm'
    || ($options['access']['options']['perm'] ?? NULL) !== 'access content'
    || ($options['cache']['type'] ?? NULL) !== 'tag'
    || ($options['row']['type'] ?? NULL) !== 'entity:node'
    || ($options['row']['options']['view_mode'] ?? NULL) !== 'teaser'
    || ($options['query']['options']['disable_sql_rewrite'] ?? NULL) !== FALSE) {
    $fail($name . ' must render published ' . $bundle . ' teasers newest-first.');
  }
  if (trim((string) ($options['empty']['area_text_custom']['content'] ?? '')) === '') {
    $fail($name . ' must retain a non-empty empty state.');
  }

  if ($name === 'views.view.blog_posts') {
    if (!$matches_string_set($view['dependencies']['config'] ?? NULL, [
      'core.entity_view_mode.node.teaser',
      'node.type.article',
      'taxonomy.vocabulary.tags',
    ]) || !$matches_string_set($view['dependencies']['module'] ?? NULL, [
      'node',
      'taxonomy',
      'user',
    ])) {
      $fail('views.view.blog_posts must depend exactly on the Article teaser and Tags taxonomy stack.');
    }

    $expected_editorial_display = [
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
            'default_argument_options' => [
              'argument' => 'all',
            ],
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
    if ($canonicalize($displays['editorial_home'] ?? NULL)
      !== $canonicalize($expected_editorial_display)) {
      $fail(
        'views.view.blog_posts editorial_home must be the exact reviewed Tags-TID embed override.'
      );
    }
  }

  foreach ($displays as $display) {
    if (($display['display_plugin'] ?? NULL) === 'page' || isset($display['display_options']['path'])) {
      $fail($name . ' must expose blocks only, never a View page URL.');
    }
    $contexts = $display['cache_metadata']['contexts'] ?? [];
    if (!is_array($contexts)
      || !in_array('user.node_grants:view', $contexts, TRUE)
      || !in_array('user.permissions', $contexts, TRUE)) {
      $fail($name . ' must retain node-grant and permission cache contexts on every display.');
    }
  }
};

$assert_view($sources['views.view.blog_posts'], 'article', 'views.view.blog_posts');
$assert_view($sources['views.view.forum_topics'], 'forum_topic', 'views.view.forum_topics');

// Forum/Blog owns the two-display collection baseline. The editorial-home
// feature adds and removes its third display with its own exact installer.
// Select one variant from the complete active feature state so a fresh Forum
// install never creates a partial editorial-home deployment, and Forum
// rollback remains possible after editorial-home is rolled back first.
\Drupal::state()->resetCache();
$editorial_state_sentinel = new stdClass();
$editorial_state = \Drupal::state()->get(
  $editorial_home_state_key,
  $editorial_state_sentinel
);
$core_extension = $config_storage->read('core.extension');
$editorial_module_in_config = is_array($core_extension)
  && array_key_exists($editorial_home_module_name, $core_extension['module'] ?? []);
$editorial_module_in_handler = \Drupal::moduleHandler()->moduleExists(
  $editorial_home_module_name
);
if ($editorial_module_in_config !== $editorial_module_in_handler) {
  $fail('Editorial-home module state disagrees between core.extension and the module handler.');
}
$editorial_block_exists = $config_storage->exists($editorial_home_block_name);
$editorial_state_exists = $editorial_state !== $editorial_state_sentinel;
$editorial_signal_count = count(array_filter([
  $editorial_module_in_config,
  $editorial_block_exists,
  $editorial_state_exists,
]));
if (!in_array($editorial_signal_count, [0, 3], TRUE)) {
  $fail('Editorial-home is partial; exact absent or complete ownership state required.');
}
$editorial_home_active = $editorial_signal_count === 3;
if ($action === 'rollback' && $editorial_home_active) {
  $fail('Rollback editorial-home first, then rerun the Forum/Blog rollback dry-run.');
}

$blog_view_baseline = $sources['views.view.blog_posts'];
unset($blog_view_baseline['display']['editorial_home']);
$blog_view_baseline['dependencies']['config'] = array_values(array_filter(
  $blog_view_baseline['dependencies']['config'],
  static fn (string $name): bool => $name !== 'taxonomy.vocabulary.tags'
));
$blog_view_baseline['dependencies']['module'] = array_values(array_filter(
  $blog_view_baseline['dependencies']['module'],
  static fn (string $name): bool => $name !== 'taxonomy'
));
if (array_keys($blog_view_baseline['display']) !== ['default', 'block_1']
  || $blog_view_baseline['dependencies']['config'] !== [
    'core.entity_view_mode.node.teaser',
    'node.type.article',
  ]
  || $blog_view_baseline['dependencies']['module'] !== ['node', 'user']) {
  $fail('Could not derive the exact Forum-owned Blog View baseline.');
}
$blog_view_variant = $editorial_home_active
  ? 'editorial_home_three_displays'
  : 'forum_blog_two_displays';
if (!$editorial_home_active) {
  $sources['views.view.blog_posts'] = $blog_view_baseline;
}
echo 'BLOG VIEW SOURCE VARIANT ' . $blog_view_variant
  . ' sha256=' . $normalized_hash($sources['views.view.blog_posts']) . PHP_EOL;

if (($sources['core.base_field_override.node.forum_topic.status']['default_value'][0]['value'] ?? NULL) !== 0) {
  $fail('New forum topics must default to unpublished.');
}
if (($sources['core.base_field_override.node.forum_topic.promote']['default_value'][0]['value'] ?? NULL) !== 0) {
  $fail('New forum topics must not default to promoted.');
}
if (($sources['field.field.node.forum_topic.body']['settings']['allowed_formats'] ?? NULL) !== ['basic_html']) {
  $fail('Forum topic bodies must allow exactly basic_html.');
}
if (($sources['field.field.node.forum_topic.comment']['settings']['anonymous'] ?? NULL) !== 0
  || ($sources['field.field.node.forum_topic.comment']['default_value'][0]['status'] ?? NULL) !== 2) {
  $fail('Forum comments must be open and must not collect anonymous contact details.');
}
if (($sources['core.entity_form_display.node.forum_topic.default']['hidden']['uid'] ?? NULL) !== TRUE
  || isset($sources['core.entity_form_display.node.forum_topic.default']['content']['uid'])) {
  $fail('The Forum topic author widget must remain hidden from the default edit form.');
}

$webform = $sources['webform.webform.forum_blog_proposal'];
if (($webform['status'] ?? NULL) !== 'open'
  || ($webform['id'] ?? NULL) !== 'forum_blog_proposal'
  || ($webform['uid'] ?? NULL) !== 0
  || ($webform['template'] ?? NULL) !== FALSE
  || ($webform['archive'] ?? NULL) !== FALSE
  || ($webform['css'] ?? NULL) !== ''
  || ($webform['javascript'] ?? NULL) !== ''
  || ($webform['settings']['page'] ?? NULL) !== FALSE
  || ($webform['settings']['share'] ?? NULL) !== FALSE
  || ($webform['settings']['share_node'] ?? NULL) !== FALSE
  || ($webform['settings']['form_disable_remote_addr'] ?? NULL) !== TRUE
  || ($webform['settings']['form_convert_anonymous'] ?? NULL) !== FALSE
  || ($webform['settings']['form_previous_submissions'] ?? NULL) !== FALSE
  || ($webform['settings']['form_action'] ?? NULL) !== ''
  || ($webform['settings']['submission_log'] ?? NULL) !== TRUE
  || ($webform['settings']['results_disabled'] ?? NULL) !== FALSE
  || ($webform['settings']['token_view'] ?? NULL) !== FALSE
  || ($webform['settings']['token_update'] ?? NULL) !== FALSE
  || ($webform['settings']['token_delete'] ?? NULL) !== FALSE
  || ($webform['settings']['draft'] ?? NULL) !== 'none'
  || ($webform['settings']['purge'] ?? NULL) !== 'none'
  || ($webform['settings']['serial_disabled'] ?? NULL) !== FALSE
  || ($webform['settings']['confirmation_type'] ?? NULL) !== 'inline'
  || ($webform['access']['create']['roles'] ?? NULL) !== ['authenticated']
  || ($webform['access']['create']['users'] ?? NULL) !== []
  || ($webform['access']['create']['permissions'] ?? NULL) !== []
  || ($webform['handlers'] ?? NULL) !== []
  || ($webform['variants'] ?? NULL) !== []) {
  $fail('Proposal Webform must be private, block-only, authenticated-only, and handler-free.');
}
$webform_access_operations = [
  'view_any',
  'update_any',
  'delete_any',
  'purge_any',
  'view_own',
  'update_own',
  'delete_own',
  'administer',
  'test',
  'configuration',
];
foreach ($webform_access_operations as $operation) {
  $rule = $webform['access'][$operation] ?? NULL;
  if (!is_array($rule)
    || ($rule['roles'] ?? NULL) !== []
    || ($rule['users'] ?? NULL) !== []
    || ($rule['permissions'] ?? NULL) !== []) {
    $fail('Proposal submissions must remain private; unexpected access rule: ' . $operation);
  }
}
$proposal_elements = Yaml::parse((string) ($webform['elements'] ?? ''));
if (!is_array($proposal_elements)
  || array_keys($proposal_elements) !== ['proposal_type', 'title', 'description', 'actions']
  || ($proposal_elements['proposal_type']['#type'] ?? NULL) !== 'radios'
  || ($proposal_elements['proposal_type']['#required'] ?? NULL) !== TRUE
  || array_keys($proposal_elements['proposal_type']['#options'] ?? []) !== ['idea', 'discussion_topic', 'article_theme']
  || ($proposal_elements['title']['#type'] ?? NULL) !== 'textfield'
  || ($proposal_elements['title']['#required'] ?? NULL) !== TRUE
  || ($proposal_elements['title']['#maxlength'] ?? NULL) !== 160
  || ($proposal_elements['description']['#type'] ?? NULL) !== 'textarea'
  || ($proposal_elements['description']['#required'] ?? NULL) !== TRUE
  || ($proposal_elements['description']['#maxlength'] ?? NULL) !== 4000
  || isset($proposal_elements['description']['#format'])
  || isset($proposal_elements['description']['#allowed_formats'])
  || ($proposal_elements['actions']['#type'] ?? NULL) !== 'webform_actions') {
  $fail('Proposal Webform must contain the three reviewed plain-text proposal choices.');
}

$expected_block_paths = [
  'block.block.unisonges_blog_posts' => '/blog',
  'block.block.unisonges_forum_topics' => '/forum',
  'block.block.unisonges_forum_blog_proposal' => '/forum',
];
foreach ($expected_block_paths as $name => $path) {
  $block = $sources[$name];
  $expected_visibility_keys = $name === 'block.block.unisonges_forum_blog_proposal'
    ? ['request_path', 'user_role']
    : ['request_path'];
  if (($block['status'] ?? NULL) !== TRUE
    || ($block['id'] ?? NULL) !== substr($name, strlen('block.block.'))
    || ($block['theme'] ?? NULL) !== 'unisonges_theme'
    || ($block['region'] ?? NULL) !== 'content'
    || array_keys($block['visibility'] ?? []) !== $expected_visibility_keys
    || ($block['visibility']['request_path']['id'] ?? NULL) !== 'request_path'
    || ($block['visibility']['request_path']['negate'] ?? NULL) !== FALSE
    || ($block['visibility']['request_path']['pages'] ?? NULL) !== $path) {
    $fail($name . ' must be scoped exactly to ' . $path . ' in the content region.');
  }
}
$expected_block_plugins = [
  'block.block.unisonges_blog_posts' => 'views_block:blog_posts-block_1',
  'block.block.unisonges_forum_topics' => 'views_block:forum_topics-block_1',
  'block.block.unisonges_forum_blog_proposal' => 'webform_block',
];
foreach ($expected_block_plugins as $name => $plugin_id) {
  if (($sources[$name]['plugin'] ?? NULL) !== $plugin_id
    || ($sources[$name]['settings']['id'] ?? NULL) !== $plugin_id) {
    $fail('Unexpected block plugin for ' . $name . '.');
  }
}
$proposal_block_settings = $sources['block.block.unisonges_forum_blog_proposal']['settings'];
if (($proposal_block_settings['webform_id'] ?? NULL) !== 'forum_blog_proposal'
  || ($proposal_block_settings['default_data'] ?? NULL) !== ''
  || ($proposal_block_settings['redirect'] ?? NULL) !== FALSE
  || ($proposal_block_settings['lazy'] ?? NULL) !== FALSE) {
  $fail('The proposal block must embed only the reviewed Webform without defaults or redirect.');
}
$proposal_role_visibility = $sources['block.block.unisonges_forum_blog_proposal']['visibility']['user_role'] ?? NULL;
if (!is_array($proposal_role_visibility)
  || ($proposal_role_visibility['id'] ?? NULL) !== 'user_role'
  || ($proposal_role_visibility['negate'] ?? NULL) !== FALSE
  || ($proposal_role_visibility['roles'] ?? NULL) !== ['authenticated' => 'authenticated']) {
  $fail('The proposal block must be visible only to authenticated users.');
}

$section('Runtime prerequisites');
$required_modules = [
  'block',
  'comment',
  'filter',
  'language',
  'node',
  'path',
  'system',
  'text',
  'user',
  'views',
  'webform',
];
if ($action === 'install') {
  $required_modules[] = 'unisonges_structure';
}
foreach ($required_modules as $module_name) {
  if (!\Drupal::moduleHandler()->moduleExists($module_name)) {
    $fail('Required module is not enabled: ' . $module_name);
  }
  echo 'MODULE OK ' . $module_name . PHP_EOL;
}
if ($action === 'install') {
  $required_access_hooks = [
    'node_access' => 'unisonges_structure_node_access',
    'entity_field_access' => 'unisonges_structure_entity_field_access',
    'views_query_alter' => 'unisonges_structure_views_query_alter',
  ];
  foreach ($required_access_hooks as $hook => $function) {
    if (!function_exists($function)
      || !\Drupal::moduleHandler()->hasImplementations($hook, 'unisonges_structure')) {
      $fail(
        'Required access hook is not registered: ' . $function
        . '. Rebuild Drupal caches after deploying the reviewed code.'
      );
    }
  }
  echo 'ACCESS HOOKS OK (forum drafts/revisions/Views and account-credit fields)' . PHP_EOL;
}

if ($action === 'install') {
  $system_theme = $config_storage->read('system.theme');
  $effective_system_theme = \Drupal::config('system.theme')->get();
  if (!is_array($system_theme)
    || !is_array($effective_system_theme)
    || ($system_theme['default'] ?? NULL) !== 'unisonges_theme'
    || ($effective_system_theme['default'] ?? NULL) !== 'unisonges_theme') {
    $fail('The active default theme must be exactly unisonges_theme.');
  }
}

$system_site_source_path = $sync_dir . '/system.site.yml';
$resolved_system_site_source_path = realpath($system_site_source_path);
if ($resolved_system_site_source_path === FALSE
  || $resolved_system_site_source_path !== $system_site_source_path
  || is_link($system_site_source_path)) {
  $fail('Exact source-path guard failed for system.site.yml.');
}
$system_site_source = Yaml::parseFile($system_site_source_path);
$system_site_active = $config_storage->read('system.site');
if (!is_array($system_site_source)
  || !is_array($system_site_active)
  || !isset($system_site_source['uuid'], $system_site_active['uuid'])
  || !is_string($system_site_source['uuid'])
  || !hash_equals($system_site_source['uuid'], (string) $system_site_active['uuid'])) {
  $fail('Active system.site UUID does not match the reviewed repository baseline.');
}
$sync_files = glob($sync_dir . '/*.yml');
$active_config_names = $config_storage->listAll();
if ($sync_files === FALSE || count($sync_files) < 1) {
  $fail('Could not inventory the repository sync collection.');
}
$minimum_active_count = (int) floor(count($sync_files) * 0.75);
if (count($active_config_names) < $minimum_active_count) {
  $fail(
    'Active config inventory is implausibly small (' . count($active_config_names)
    . ' active versus ' . count($sync_files) . ' repository files).'
  );
}
echo 'BASELINE OK (system.site UUID match; active inventory plausibility passed)' . PHP_EOL;

if ($action === 'install') {
  $required_existing_config = [
    'comment.type.comment',
    'core.entity_view_display.comment.comment.default',
    'core.entity_view_display.node.article.teaser',
    'core.entity_view_mode.node.teaser',
    'field.field.node.article.body',
    'field.storage.node.body',
    'field.storage.node.comment',
    'filter.format.basic_html',
    'node.type.article',
    'user.role.administrator',
    'user.role.anonymous',
    'user.role.authenticated',
  ];
  foreach ($required_existing_config as $config_name) {
    if (!$config_storage->exists($config_name)) {
      $fail('Required active config is missing: ' . $config_name);
    }
    echo 'CONFIG OK ' . $config_name . PHP_EOL;
  }
}

if ($action === 'install') {
  $active_article_type = $config_storage->read('node.type.article');
  $active_article_teaser = $config_storage->read('core.entity_view_display.node.article.teaser');
  $effective_article_type = \Drupal::config('node.type.article')->get();
  $effective_article_teaser = \Drupal::config('core.entity_view_display.node.article.teaser')->get();
  if (!is_array($active_article_type)
    || !is_array($active_article_teaser)
    || !is_array($effective_article_type)
    || !is_array($effective_article_teaser)
    || ($active_article_type['display_submitted'] ?? NULL) !== TRUE
    || ($effective_article_type['display_submitted'] ?? NULL) !== TRUE
    || ($active_article_teaser['content']['body']['type'] ?? NULL) !== 'text_summary_or_trimmed'
    || ($effective_article_teaser['content']['body']['type'] ?? NULL) !== 'text_summary_or_trimmed'
    || ($active_article_teaser['content']['links']['region'] ?? NULL) !== 'content'
    || ($effective_article_teaser['content']['links']['region'] ?? NULL) !== 'content') {
    $fail('Active Article teaser must retain submitted date, summary, and canonical content links.');
  }
  echo 'ARTICLE TEASER OK (date, summary, canonical content links)' . PHP_EOL;

  $basic_html = $config_storage->read('filter.format.basic_html');
  $effective_basic_html = \Drupal::config('filter.format.basic_html')->get();
  $allowed_basic_html = is_array($basic_html)
    ? ($basic_html['filters']['filter_html']['settings']['allowed_html'] ?? NULL)
    : NULL;
  if (!is_array($basic_html)
    || !is_array($effective_basic_html)
    || ($basic_html['status'] ?? NULL) !== TRUE
    || ($effective_basic_html['status'] ?? NULL) !== TRUE
    || ($basic_html['filters']['filter_html']['status'] ?? NULL) !== TRUE
    || ($effective_basic_html['filters']['filter_html']['status'] ?? NULL) !== TRUE
    || !is_string($allowed_basic_html)
    || trim($allowed_basic_html) === ''
    || $allowed_basic_html
      !== ($effective_basic_html['filters']['filter_html']['settings']['allowed_html'] ?? NULL)) {
    $fail('Stored and effective basic_html must retain the active HTML allowlist filter.');
  }
  echo 'TEXT FORMAT OK (stored/effective basic_html uses filter_html)' . PHP_EOL;
}

if ($action === 'install') {
  foreach ($sources as $name => $data) {
    foreach (($data['dependencies']['module'] ?? []) as $module_name) {
      if (!is_string($module_name) || !\Drupal::moduleHandler()->moduleExists($module_name)) {
        $fail('Unavailable module dependency in ' . $name . ': ' . (string) $module_name);
      }
    }
    foreach (($data['dependencies']['config'] ?? []) as $dependency_name) {
      if (!is_string($dependency_name)
        || (!isset($target_names[$dependency_name]) && !$config_storage->exists($dependency_name))) {
        $fail('Unavailable config dependency in ' . $name . ': ' . (string) $dependency_name);
      }
    }
  }
}

if ($action === 'install') {
  $stored_user_settings = $config_storage->read('user.settings');
  $effective_user_settings = \Drupal::config('user.settings');
  $effective_verify_mail = $effective_user_settings->get('verify_mail');
  $effective_register = $effective_user_settings->get('register');
  $effective_registration_notice = $effective_user_settings
    ->get('notify.register_no_approval_required');
  if (!is_array($stored_user_settings)
    || ($stored_user_settings['verify_mail'] ?? NULL) !== TRUE
    || ($stored_user_settings['register'] ?? NULL) !== 'visitors'
    || ($stored_user_settings['notify']['register_no_approval_required'] ?? NULL) !== TRUE
    || $effective_verify_mail !== TRUE
    || $effective_register !== 'visitors'
    || $effective_registration_notice !== TRUE) {
    $fail('Stored and effective registration must be visitors + verified email + registration notice.');
  }
  echo 'REGISTRATION OK (visitors; verified email; registration notice; stored and effective)' . PHP_EOL;

  $stored_anonymous_role = $config_storage->read('user.role.anonymous');
  $stored_authenticated_role = $config_storage->read('user.role.authenticated');
  $stored_administrator_role = $config_storage->read('user.role.administrator');
  $anonymous_role = \Drupal::config('user.role.anonymous')->get();
  $authenticated_role = \Drupal::config('user.role.authenticated')->get();
  $administrator_role = \Drupal::config('user.role.administrator')->get();
  if (!is_array($stored_anonymous_role)
    || !is_array($stored_authenticated_role)
    || !is_array($stored_administrator_role)
    || !is_array($anonymous_role)
    || !is_array($authenticated_role)
    || !is_array($administrator_role)) {
    $fail('Could not read the three required roles.');
  }
  foreach (['anonymous', 'authenticated', 'administrator'] as $role_id) {
    $stored_role = ${'stored_' . $role_id . '_role'};
    $effective_role = ${$role_id . '_role'};
    if ($canonicalize($stored_role) !== $canonicalize($effective_role)) {
      $fail('A config override changes the effective ' . $role_id . ' role.');
    }
  }
  $anonymous_permissions = $anonymous_role['permissions'] ?? [];
  $authenticated_permissions = $authenticated_role['permissions'] ?? [];
  foreach (['access content', 'access comments'] as $permission) {
    if (!in_array($permission, $anonymous_permissions, TRUE)) {
      $fail('Anonymous role is missing required read permission: ' . $permission);
    }
  }
  foreach (['post comments', 'skip comment approval'] as $permission) {
    if (in_array($permission, $anonymous_permissions, TRUE)) {
      $fail('Anonymous role must not have permission: ' . $permission);
    }
  }
  foreach ([
    'access content',
    'access comments',
    'post comments',
    'skip comment approval',
    'use text format basic_html',
  ] as $permission) {
    if (!in_array($permission, $authenticated_permissions, TRUE)) {
      $fail('Authenticated role is missing the reviewed MVP permission: ' . $permission);
    }
  }
  foreach ([
    'create article content',
    'create forum_topic content',
    'administer nodes',
    'bypass node access',
  ] as $permission) {
    if (in_array($permission, $authenticated_permissions, TRUE)) {
      $fail('Authenticated role has forbidden broad publishing permission: ' . $permission);
    }
  }
  if (($administrator_role['is_admin'] ?? NULL) !== TRUE) {
    $fail('The administrator role must retain is_admin=true for proposal review and publication.');
  }
  echo 'PERMISSIONS OK (anonymous read-only; authenticated comments; administrator review)' . PHP_EOL;
  echo 'COMMENT APPROVAL POLICY retained: authenticated has skip comment approval.' . PHP_EOL;

  foreach ($config_storage->listAll('user.role.') as $role_config_name) {
    $stored_role_data = $config_storage->read($role_config_name);
    $role_data = \Drupal::config($role_config_name)->get();
    if (!is_array($stored_role_data) || !is_array($role_data)) {
      $fail('Could not read role config: ' . $role_config_name);
    }
    if ($canonicalize($stored_role_data) !== $canonicalize($role_data)) {
      $fail('A config override changes the effective role: ' . $role_config_name);
    }
    if (($role_data['is_admin'] ?? FALSE) === TRUE) {
      continue;
    }
    $permissions = $role_data['permissions'] ?? [];
    foreach ([
      'create forum_topic content',
      'edit any forum_topic content',
      'edit own forum_topic content',
      'delete any forum_topic content',
      'delete own forum_topic content',
      'administer node published status',
      'administer nodes',
      'bypass node access',
      'administer webform',
      'administer webform submission',
      'edit any webform',
      'edit own webform',
      'delete any webform',
      'delete own webform',
      'view any webform submission',
      'view own webform submission',
      'edit any webform submission',
      'edit own webform submission',
      'delete any webform submission',
      'delete own webform submission',
    ] as $permission) {
      if (in_array($permission, $permissions, TRUE)) {
        $fail(
          $role_config_name
          . ' may bypass administrator-only topic publication via: ' . $permission
        );
      }
    }
  }

  // Public registration must not make Commerce-backed lesson credits
  // self-service. Require the explicit server-side field-access denial.
  $synthetic_member = new UserSession([
    'uid' => 2147483000,
    'roles' => ['authenticated'],
  ]);
  $user_field_definitions = \Drupal::service('entity_field.manager')
    ->getFieldDefinitions('user', 'user');
  $user_access_handler = $entity_type_manager->getAccessControlHandler('user');
  foreach (['field_seances_restantes', 'field_pack_expire_le', 'field_essai_utilise'] as $field_name) {
    if (!isset($user_field_definitions[$field_name])) {
      $fail('Required protected user field definition is missing: ' . $field_name);
    }
    $field_access = $user_access_handler->fieldAccess(
      'edit',
      $user_field_definitions[$field_name],
      $synthetic_member,
      NULL,
      TRUE
    );
    if (!$field_access->isForbidden()) {
      $fail(
        'Authenticated field access is not explicitly forbidden for ' . $field_name
        . '. The reviewed account-credit access boundary is not active.'
      );
    }
  }
  echo 'ACCOUNT FIELD ACCESS OK (member edits explicitly forbidden for lesson-credit fields)' . PHP_EOL;
}

$comment_field_storage = NULL;
$active_comment_field = $config_storage->read($comment_field_name);
$active_comment_formats = is_array($active_comment_field)
  ? ($active_comment_field['settings']['allowed_formats'] ?? NULL)
  : NULL;
$comment_config_matches = FALSE;
if ($action === 'install') {
  $comment_field_storage = $get_config_entity_storage('field_config');
  $comment_field = $comment_field_storage->loadOverrideFree('comment.comment.comment_body');
  if (!$comment_field || $comment_field->getType() !== 'text_long') {
    $fail('The existing default comment body field is missing or has an unexpected type.');
  }
  if (!is_array($active_comment_field)) {
    $fail('Could not read the raw active default comment body field.');
  }
  $effective_comment_field = \Drupal::config($comment_field_name)->get();
  if (!is_array($effective_comment_field)
    || $canonicalize($effective_comment_field) !== $canonicalize($active_comment_field)) {
    $fail('A config override changes the effective default comment body field.');
  }
  if (!is_array($active_comment_formats)
    || $comment_field->getSetting('allowed_formats') !== $active_comment_formats) {
    $fail('Raw and override-free comment allowed_formats state is invalid or inconsistent.');
  }

  // Accept only the exact synchronized target or its exact pre-MVP
  // predecessor, so saving cannot overwrite unrelated active drift.
  $legacy_comment_field = $comment_field_source;
  $legacy_comment_field['settings']['allowed_formats'] = [];
  $legacy_comment_field['dependencies']['config'] = array_values(array_filter(
    $legacy_comment_field['dependencies']['config'] ?? [],
    static fn ($dependency): bool => $dependency !== 'filter.format.basic_html'
  ));
  $comment_config_matches = $canonicalize($active_comment_field)
    === $canonicalize($comment_field_source);
  $comment_config_is_legacy = $canonicalize($active_comment_field)
    === $canonicalize($legacy_comment_field);
  if (!$comment_config_matches && !$comment_config_is_legacy) {
    $fail('Refusing unrelated active drift/collision in ' . $comment_field_name . '.');
  }
  echo 'COMMENT FORMAT ' . ($comment_config_matches ? 'MATCH' : 'WOULD_SET')
    . ' active=' . json_encode($active_comment_formats, JSON_UNESCAPED_UNICODE)
    . ' target=["basic_html"] dependency=filter.format.basic_html' . PHP_EOL;
}
else {
  echo 'COMMENT POLICY NOT INSPECTED (rollback leaves shared config unchanged) active_formats='
    . json_encode($active_comment_formats, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

$count_unsafe_comments = static function () use ($entity_type_manager): int {
  return (int) $entity_type_manager
    ->getStorage('comment')
    ->getQuery()
    ->accessCheck(FALSE)
    ->condition('comment_body.format', 'webform_default')
    ->count()
    ->execute();
};
$unsafe_comment_count = NULL;
if ($action === 'install') {
  $unsafe_comment_count = $count_unsafe_comments();
  if ($unsafe_comment_count > 0) {
    $fail(
      'Found ' . $unsafe_comment_count
      . ' comment(s) stored with webform_default. Moderate them manually; this script never rewrites comments.'
    );
  }
  echo 'COMMENT DATA OK webform_default_comments=0' . PHP_EOL;
}

$webform_internal_paths = [
  '/webform/forum_blog_proposal',
  '/webform/forum_blog_proposal/confirmation',
  '/webform/forum_blog_proposal/submissions',
  '/webform/forum_blog_proposal/drafts',
];
$webform_public_aliases = [
  '/form/forum-blog-proposal',
  '/form/forum-blog-proposal/confirmation',
  '/form/forum-blog-proposal/submissions',
  '/form/forum-blog-proposal/drafts',
];
$assert_webform_path_namespace_clear = static function () use (
  $entity_type_manager,
  $webform_internal_paths,
  $webform_public_aliases,
  $fail
): void {
  $path_alias_storage = $entity_type_manager->getStorage('path_alias');
  $query = $path_alias_storage->getQuery()->accessCheck(FALSE);
  $namespace_condition = $query->orConditionGroup()
    ->condition('path', $webform_internal_paths, 'IN')
    ->condition('alias', $webform_public_aliases, 'IN');
  $alias_ids = $query->condition($namespace_condition)->execute();
  if ($alias_ids !== []) {
    $details = [];
    foreach ($path_alias_storage->loadMultiple($alias_ids) as $path_alias) {
      $details[] = $path_alias->id() . ':' . $path_alias->get('path')->value
        . '->' . $path_alias->get('alias')->value;
    }
    sort($details, SORT_STRING);
    $fail(
      'Refusing Webform path/alias namespace collision(s): ' . implode(', ', $details)
      . '. This script never deletes aliases.'
    );
  }
};
$assert_webform_path_namespace_clear();
echo 'WEBFORM PATH NAMESPACE OK (internal sources and default public aliases are clear)' . PHP_EOL;

$resolve_basic_page = static function (string $alias) use ($entity_type_manager, $fail): int {
  $path_alias_storage = $entity_type_manager->getStorage('path_alias');
  $alias_ids = $path_alias_storage
    ->getQuery()
    ->accessCheck(FALSE)
    ->condition('alias', $alias)
    ->execute();
  if (count($alias_ids) !== 1) {
    $fail($alias . ' must have exactly one path-alias row owned by the content PR.');
  }
  $path_alias = $path_alias_storage->load((string) reset($alias_ids));
  if (!$path_alias) {
    $fail('Could not load the unique path-alias row for ' . $alias . '.');
  }
  $path = (string) $path_alias->get('path')->value;
  $langcode = (string) $path_alias->get('langcode')->value;
  $default_langcode = \Drupal::languageManager()->getDefaultLanguage()->getId();
  if (!in_array($langcode, ['und', $default_langcode], TRUE)) {
    $fail($alias . ' must be available in the site default language or language-neutral.');
  }
  if (!preg_match('#^/node/([1-9][0-9]*)$#D', $path, $matches)) {
    $fail($alias . ' must point directly to a Basic page node path.');
  }
  $alias_manager = \Drupal::service('path_alias.manager');
  if ($alias_manager->getPathByAlias($alias, $langcode) !== $path
    || $alias_manager->getPathByAlias($alias, $default_langcode) !== $path) {
    $fail($alias . ' does not resolve effectively to its unique reviewed node path.');
  }
  $nid = (int) $matches[1];
  $node = $entity_type_manager->getStorage('node')->load($nid);
  if (!$node || $node->bundle() !== 'page' || !$node->isPublished()) {
    $fail($alias . ' must resolve to a published Basic page before these blocks are applied.');
  }
  return $nid;
};

$page_ids = [];
if ($action === 'install') {
  $page_ids['/blog'] = $resolve_basic_page('/blog');
  $page_ids['/forum'] = $resolve_basic_page('/forum');
  if ($page_ids['/blog'] === $page_ids['/forum']) {
    $fail('/blog and /forum must resolve to distinct Basic pages.');
  }
  echo 'ROUTE OWNER OK /blog -> node/' . $page_ids['/blog'] . PHP_EOL;
  echo 'ROUTE OWNER OK /forum -> node/' . $page_ids['/forum'] . PHP_EOL;
}

$inspect_targets = static function () use (
  $targets,
  $sources,
  $config_storage,
  $get_config_entity_storage,
  $canonicalize,
  $fail
): array {
  $states = [];
  foreach ($targets as $target) {
    $active = $config_storage->read($target['name']);
    $effective = \Drupal::config($target['name'])->get();
    $storage = $get_config_entity_storage($target['entity_type']);
    $entity = $storage->loadOverrideFree($target['id']);
    if (!is_array($active)) {
      if ($entity !== NULL) {
        $fail('Entity/config storage mismatch for missing ' . $target['name'] . '.');
      }
      if (is_array($effective) && $effective !== []) {
        $fail('A config override targets missing config ' . $target['name'] . '.');
      }
      $states[$target['name']] = 'missing';
      continue;
    }
    if ($entity === NULL) {
      $fail('Entity/config storage mismatch for active ' . $target['name'] . '.');
    }
    $source = $canonicalize($sources[$target['name']]);
    $states[$target['name']] = $canonicalize($active) === $source
      && $canonicalize($effective) === $source
        ? 'match'
        : 'collision';
  }
  return $states;
};

$count_feature_records = static function () use ($entity_type_manager): array {
  return [
    'forum_topic_nodes' => (int) $entity_type_manager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'forum_topic')
      ->count()
      ->execute(),
    'proposal_submissions' => (int) $entity_type_manager
      ->getStorage('webform_submission')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('webform_id', 'forum_blog_proposal')
      ->count()
      ->execute(),
  ];
};

$feature_field_specs = [
  'field.field.node.forum_topic.body' => [
    'uuid' => $sources['field.field.node.forum_topic.body']['uuid'],
    'name' => 'body',
    'type' => 'text_with_summary',
  ],
  'field.field.node.forum_topic.comment' => [
    'uuid' => $sources['field.field.node.forum_topic.comment']['uuid'],
    'name' => 'comment',
    'type' => 'comment',
  ],
];
$bundle_field_map_store = \Drupal::service('keyvalue')
  ->get('entity.definitions.bundle_field_map');
$read_node_bundle_field_map = static function () use (
  $bundle_field_map_store,
  $fail
): array {
  $map = $bundle_field_map_store->get('node', []);
  if (!is_array($map)) {
    $fail('The node bundle-field map key-value entry is not an array.');
  }
  return $map;
};
$inspect_node_bundle_field_map = static function (array $states) use (
  $feature_field_specs,
  $read_node_bundle_field_map,
  $canonicalize,
  $normalized_hash,
  $fail
): array {
  $map = $read_node_bundle_field_map();
  $feature = [];
  foreach ($feature_field_specs as $config_name => $spec) {
    $entry = $map[$spec['name']] ?? NULL;
    $has_forum_bundle = is_array($entry)
      && is_array($entry['bundles'] ?? NULL)
      && ($entry['bundles']['forum_topic'] ?? NULL) === 'forum_topic';
    if (($states[$config_name] ?? NULL) === 'match') {
      if (!$has_forum_bundle || ($entry['type'] ?? NULL) !== $spec['type']) {
        $fail('Bundle-field map is inconsistent with active config ' . $config_name . '.');
      }
    }
    elseif ($has_forum_bundle) {
      $fail('Stale bundle-field map entry occupies missing config ' . $config_name . '.');
    }
    $feature[$spec['name']] = [
      'type' => is_array($entry) ? ($entry['type'] ?? NULL) : NULL,
      'forum_topic' => $has_forum_bundle,
    ];
  }
  return [
    'hash' => $normalized_hash($canonicalize($map)),
    'feature' => $feature,
  ];
};

$describe_deleted_field_state = static function () use ($canonicalize, $fail): array {
  $deleted_fields = \Drupal::state()->get('field.field.deleted', []);
  if (!is_array($deleted_fields)) {
    $fail('The field.field.deleted state entry is not an array.');
  }
  $descriptors = [];
  foreach ($deleted_fields as $key => $field) {
    if (!$field instanceof FieldConfigInterface) {
      $fail('field.field.deleted contains a non-FieldConfig value at ' . (string) $key . '.');
    }
    $key = (string) $key;
    $descriptors[$key] = [
      'class' => get_class($field),
      'key' => $key,
      'uuid' => $field->uuid(),
      'unique_id' => $field->getUniqueIdentifier(),
      'id' => $field->id(),
      'deleted' => $field->isDeleted(),
      'entity_type' => $field->getTargetEntityTypeId(),
      'bundle' => $field->getTargetBundle(),
      'name' => $field->getName(),
      'type' => $field->getType(),
      'settings' => $canonicalize($field->getSettings()),
    ];
  }
  ksort($descriptors, SORT_STRING);
  return $descriptors;
};

$inspect_feature_deleted_fields = static function (array $states) use (
  $feature_field_specs,
  $describe_deleted_field_state,
  $normalized_hash,
  $action,
  $fail
): array {
  $descriptors = $describe_deleted_field_state();
  $feature = [];
  foreach ($feature_field_specs as $config_name => $spec) {
    $present = isset($descriptors[$spec['uuid']]);
    if (($states[$config_name] ?? NULL) === 'match' && $present) {
      $fail('Active field config and deleted-field state share UUID ' . $spec['uuid'] . '.');
    }
    if ($action === 'install' && $present) {
      $fail(
        'Deleted-field state still contains feature UUID ' . $spec['uuid']
        . '. Run the reviewed field-purge/cron cleanup before reinstalling.'
      );
    }
    $feature[$spec['name']] = $present;
  }
  return [
    'count' => count($descriptors),
    'hash' => $normalized_hash($descriptors),
    'feature' => $feature,
  ];
};

$inspect_webform_runtime_namespace = static function (array $states) use ($fail): array {
  $webform_id = 'forum_blog_proposal';
  $tracking_rows = \Drupal::database()
    ->select('webform', 'w')
    ->fields('w', ['webform_id', 'next_serial'])
    ->condition('webform_id', $webform_id)
    ->execute()
    ->fetchAllKeyed();
  $webform_matches = ($states['webform.webform.forum_blog_proposal'] ?? NULL) === 'match';
  if ($webform_matches) {
    if (count($tracking_rows) !== 1
      || !isset($tracking_rows[$webform_id])
      || (int) $tracking_rows[$webform_id] < 1) {
      $fail('The installed proposal Webform has invalid serial-tracking state.');
    }
  }
  elseif ($tracking_rows !== []) {
    $fail('A stale Webform serial-tracking row occupies the proposal namespace.');
  }

  $state_store = \Drupal::service('keyvalue')->get('state');
  $webform_state_key = 'webform.webform.' . $webform_id;
  if ($state_store->has($webform_state_key)) {
    $fail('Webform runtime state exists in the proposal namespace: ' . $webform_state_key . '.');
  }
  $webform_libraries = \Drupal::state()->get('webform_libraries', []);
  if (!is_array($webform_libraries) || isset($webform_libraries[$webform_id])) {
    $fail('Unexpected Webform library state exists in the proposal namespace.');
  }
  $user_data = \Drupal::service('user.data')->get('webform', NULL, $webform_id);
  if ($user_data !== []) {
    $fail('Webform user data exists in the proposal namespace.');
  }

  $stream_wrappers = \Drupal::service('stream_wrapper_manager')->getNames(
    \Drupal\Core\StreamWrapper\StreamWrapperInterface::WRITE_VISIBLE
  );
  foreach (array_keys($stream_wrappers) as $scheme) {
    $directory = $scheme . '://webform/' . $webform_id;
    if (file_exists($directory)) {
      $fail('Webform files exist in the proposal namespace: ' . $directory . '.');
    }
  }

  return [
    'tracking_next_serial' => $webform_matches ? (int) $tracking_rows[$webform_id] : NULL,
    'state' => 'clear',
    'user_data' => 'clear',
    'files' => 'clear',
  ];
};

$assert_install_data_namespace = static function (array $states, array $record_counts) use ($fail): void {
  $forum_targets = [
    'node.type.forum_topic',
    'core.base_field_override.node.forum_topic.status',
    'core.base_field_override.node.forum_topic.promote',
    'field.field.node.forum_topic.body',
    'field.field.node.forum_topic.comment',
    'core.entity_form_display.node.forum_topic.default',
    'core.entity_view_display.node.forum_topic.default',
    'core.entity_view_display.node.forum_topic.teaser',
    'views.view.forum_topics',
    'block.block.unisonges_forum_topics',
  ];
  $proposal_targets = [
    'webform.webform.forum_blog_proposal',
    'block.block.unisonges_forum_blog_proposal',
  ];

  foreach ($forum_targets as $name) {
    if ($record_counts['forum_topic_nodes'] > 0 && ($states[$name] ?? NULL) !== 'match') {
      $fail(
        'Refusing forum_topic data namespace collision: found '
        . $record_counts['forum_topic_nodes'] . ' node(s) while ' . $name . ' is not an exact match.'
      );
    }
  }
  foreach ($proposal_targets as $name) {
    if ($record_counts['proposal_submissions'] > 0 && ($states[$name] ?? NULL) !== 'match') {
      $fail(
        'Refusing proposal data namespace collision: found '
        . $record_counts['proposal_submissions'] . ' submission(s) while ' . $name . ' is not an exact match.'
      );
    }
  }
};

$assert_editorial_home_block = static function (array $block, string $variant) use (
  $canonicalize,
  $matches_string_set,
  $fail
): void {
  $dependencies = $block['dependencies'] ?? NULL;
  $expected_settings = [
    'id' => 'unisonges_editorial_home',
    'label' => 'Accueil éditorial',
    'label_display' => '0',
    'provider' => 'unisonges_editorial_home',
  ];
  $expected_visibility = [
    'request_path' => [
      'id' => 'request_path',
      'negate' => FALSE,
      'pages' => '/accueil',
    ],
  ];
  if (($block['status'] ?? NULL) !== TRUE
    || ($block['id'] ?? NULL) !== 'unisonges_editorial_home'
    || ($block['theme'] ?? NULL) !== 'unisonges_theme'
    || ($block['region'] ?? NULL) !== 'content'
    || ($block['weight'] ?? NULL) !== 0
    || !array_key_exists('provider', $block)
    || $block['provider'] !== NULL
    || ($block['plugin'] ?? NULL) !== 'unisonges_editorial_home'
    || $canonicalize($block['settings'] ?? NULL) !== $canonicalize($expected_settings)
    || $canonicalize($block['visibility'] ?? NULL) !== $canonicalize($expected_visibility)
    || !is_array($dependencies)
    || !$matches_string_set(array_keys($dependencies), ['config', 'module', 'theme'])
    || !$matches_string_set($dependencies['config'] ?? NULL, ['views.view.blog_posts'])
    || !$matches_string_set($dependencies['module'] ?? NULL, [
      'system',
      'unisonges_editorial_home',
    ])
    || !$matches_string_set($dependencies['theme'] ?? NULL, ['unisonges_theme'])) {
    $fail(
      'The ' . $variant . ' editorial homepage block must match its exact reviewed configuration.'
    );
  }
};

$assert_feature_config_namespace = static function () use (
  $action,
  $editorial_home_block_name,
  $assert_editorial_home_block,
  $target_names,
  $config_storage,
  $get_config_entity_storage,
  $fail
): array {
  $raw_names = $config_storage->listAll();
  $raw_values = $config_storage->readMultiple($raw_names);
  $raw_entities = array_filter(
    $raw_values,
    static fn ($data): bool => is_array($data) && isset($data['uuid'])
  );
  $dependency_manager = new ConfigDependencyManager();
  $dependency_manager->setData($raw_entities);
  $allowed_external_dependents = [];
  if ($action === 'install' && $config_storage->exists($editorial_home_block_name)) {
    if (!\Drupal::moduleHandler()->moduleExists('unisonges_editorial_home')) {
      $fail('The active editorial homepage block requires its enabled custom module.');
    }
    $editorial_home_variants = [
      'raw' => $config_storage->read($editorial_home_block_name),
      'effective' => \Drupal::config($editorial_home_block_name)->get(),
    ];
    foreach ($editorial_home_variants as $variant => $block) {
      if (!is_array($block)) {
        $fail(
          'Could not inspect ' . $variant . ' block config: ' . $editorial_home_block_name . '.'
        );
      }
      $assert_editorial_home_block($block, $variant);
    }
    $allowed_external_dependents[$editorial_home_block_name] = TRUE;
  }
  $dependent_names = [];
  foreach (array_keys($target_names) as $target_name) {
    foreach ($dependency_manager->getDependentEntities('config', $target_name) as $name => $dependency) {
      if (!isset($target_names[$name])) {
        $is_reviewed_editorial_home_block = $target_name === 'views.view.blog_posts'
          && isset($allowed_external_dependents[$name]);
        if (!$is_reviewed_editorial_home_block) {
          $fail('Non-allowlisted config depends on the feature namespace: ' . $name . '.');
        }
      }
      $dependent_names[] = $name;
    }
  }

  $bundle_prefixes = [
    'core.entity_view_display.node.forum_topic.',
    'core.entity_form_display.node.forum_topic.',
    'field.field.node.forum_topic.',
    'core.base_field_override.node.forum_topic.',
    'core.entity_view_display.webform_submission.forum_blog_proposal.',
    'core.entity_form_display.webform_submission.forum_blog_proposal.',
    'field.field.webform_submission.forum_blog_proposal.',
    'core.base_field_override.webform_submission.forum_blog_proposal.',
  ];
  $bundle_names = [];
  foreach ($bundle_prefixes as $prefix) {
    $bundle_names = array_merge($bundle_names, $config_storage->listAll($prefix));
  }
  if ($config_storage->exists('language.content_settings.node.forum_topic')) {
    $bundle_names[] = 'language.content_settings.node.forum_topic';
  }
  if ($config_storage->exists('language.content_settings.webform_submission.forum_blog_proposal')) {
    $bundle_names[] = 'language.content_settings.webform_submission.forum_blog_proposal';
  }
  foreach (array_values(array_unique($bundle_names)) as $name) {
    if (!isset($target_names[$name])) {
      $fail('Non-allowlisted config occupies a Forum/Proposal bundle namespace: ' . $name . '.');
    }
  }

  $field_storage = $get_config_entity_storage('field_config');
  foreach ([
    $field_storage->loadMultipleOverrideFree(),
    $field_storage->loadMultiple(),
  ] as $field_set) {
    foreach ($field_set as $field) {
      if ($field->getType() !== 'entity_reference') {
        continue;
      }
      $target_type = $field->getSetting('target_type');
      $handler_settings = $field->getSetting('handler_settings');
      $targets_forum = $target_type === 'node'
        && is_array($handler_settings)
        && isset($handler_settings['target_bundles']['forum_topic']);
      $targets_proposals = $target_type === 'webform_submission'
        && is_array($handler_settings)
        && isset($handler_settings['target_bundles']['forum_blog_proposal']);
      if ($targets_forum || $targets_proposals) {
        $fail(
          'Entity-reference config already targets a feature bundle namespace: '
          . $field->getConfigDependencyName() . '.'
        );
      }
    }
  }

  foreach ($config_storage->listAll('block.block.') as $block_name) {
    if (isset($target_names[$block_name])) {
      continue;
    }
    if ($block_name === $editorial_home_block_name) {
      if (isset($allowed_external_dependents[$block_name])) {
        continue;
      }
      $fail('Non-allowlisted block occupies the editorial homepage block namespace.');
    }
    $block_variants = [
      'raw' => $config_storage->read($block_name),
      'effective' => \Drupal::config($block_name)->get(),
    ];
    foreach ($block_variants as $variant => $block) {
      if (!is_array($block)) {
        $fail('Could not inspect ' . $variant . ' block config: ' . $block_name . '.');
      }
      $plugin = $block['plugin'] ?? NULL;
      $webform_id = $block['settings']['webform_id'] ?? NULL;
      if ($plugin === 'webform_block' && $webform_id === 'forum_blog_proposal') {
        $fail(
          'Non-allowlisted ' . $variant . ' block targets the proposal Webform: '
          . $block_name . '.'
        );
      }
      if (is_string($plugin)
        && (str_starts_with($plugin, 'views_block:blog_posts-')
          || str_starts_with($plugin, 'views_block:forum_topics-'))) {
        $fail(
          'Non-allowlisted ' . $variant . ' block targets a feature View: '
          . $block_name . '.'
        );
      }
    }
  }

  $dependent_names = array_values(array_unique($dependent_names));
  sort($dependent_names, SORT_STRING);
  sort($bundle_names, SORT_STRING);
  return ['dependents' => $dependent_names, 'bundle' => $bundle_names];
};

$assert_target_collections_clear = static function () use (
  $config_storage,
  $target_names,
  $fail
): void {
  foreach ($config_storage->getAllCollectionNames() as $collection_name) {
    $collection = $config_storage->createCollection($collection_name);
    foreach (array_keys($target_names) as $target_name) {
      if ($collection->exists($target_name)) {
        $fail(
          'Feature config exists in non-default collection '
          . $collection_name . ': ' . $target_name . '.'
        );
      }
    }
  }
};

$assert_rollback_isolated = static function (array $states) use (
  $target_names,
  $config_storage,
  $get_config_entity_storage,
  $fail
): array {
  $present_names = [];
  foreach ($states as $name => $state) {
    if ($state === 'match') {
      $present_names[] = $name;
    }
  }
  if ($present_names === []) {
    return ['raw' => [], 'update' => [], 'delete' => [], 'unchanged' => [], 'bundle' => []];
  }

  $raw_names = $config_storage->listAll();
  $raw_values = $config_storage->readMultiple($raw_names);
  $raw_entities = array_filter(
    $raw_values,
    static fn ($data): bool => is_array($data) && isset($data['uuid'])
  );
  $raw_dependency_manager = new ConfigDependencyManager();
  $raw_dependency_manager->setData($raw_entities);

  $changes = \Drupal::service('config.manager')
    ->getConfigEntitiesToChangeOnDependencyRemoval('config', $present_names, TRUE);
  $summary = ['raw' => [], 'update' => [], 'delete' => [], 'unchanged' => [], 'bundle' => []];
  foreach ($present_names as $present_name) {
    foreach ($raw_dependency_manager->getDependentEntities('config', $present_name) as $name => $dependency) {
      if (!isset($target_names[$name])) {
        $fail('Rollback has a raw non-allowlisted config dependent: ' . $name . '.');
      }
      $summary['raw'][] = $name;
    }
  }
  $summary['raw'] = array_values(array_unique($summary['raw']));
  sort($summary['raw'], SORT_STRING);
  foreach (['update', 'delete', 'unchanged'] as $change_type) {
    foreach ($changes[$change_type] ?? [] as $dependent) {
      if (!$dependent instanceof ConfigEntityInterface) {
        $fail('Dependency-removal simulation returned a non-config entity.');
      }
      $name = $dependent->getConfigDependencyName();
      if (!isset($target_names[$name])) {
        $fail(
          'Rollback would ' . $change_type . ' non-allowlisted dependent config: ' . $name . '.'
        );
      }
      $summary[$change_type][] = $name;
    }
    sort($summary[$change_type], SORT_STRING);
  }

  // Node-type deletion invokes bundle callbacks even when dependency cascades
  // are suppressed. Inventory every core bundle-specific config class first.
  if (($states['node.type.forum_topic'] ?? NULL) === 'match') {
    $bundle_prefixes = [
      'core.entity_view_display.node.forum_topic.',
      'core.entity_form_display.node.forum_topic.',
      'field.field.node.forum_topic.',
      'core.base_field_override.node.forum_topic.',
    ];
    $bundle_names = [];
    foreach ($bundle_prefixes as $prefix) {
      $bundle_names = array_merge($bundle_names, $config_storage->listAll($prefix));
    }
    if ($config_storage->exists('language.content_settings.node.forum_topic')) {
      $bundle_names[] = 'language.content_settings.node.forum_topic';
    }
    foreach (array_values(array_unique($bundle_names)) as $name) {
      if (!isset($target_names[$name])) {
        $fail('Rollback bundle deletion would remove non-allowlisted config: ' . $name . '.');
      }
      $summary['bundle'][] = $name;
    }
    $summary['bundle'] = array_values(array_unique($summary['bundle']));
    sort($summary['bundle'], SORT_STRING);

    // Core's field bundle-delete hook rewrites entity-reference target bundle
    // settings even when the reference field has no explicit config dependency.
    $field_storage = $get_config_entity_storage('field_config');
    foreach ([
      $field_storage->loadMultipleOverrideFree(),
      $field_storage->loadMultiple(),
    ] as $field_set) {
      foreach ($field_set as $field) {
        if ($field->getType() !== 'entity_reference'
          || $field->getSetting('target_type') !== 'node') {
          continue;
        }
        $handler_settings = $field->getSetting('handler_settings');
        if (is_array($handler_settings)
          && isset($handler_settings['target_bundles']['forum_topic'])) {
          $fail(
            'Rollback would rewrite non-allowlisted entity-reference config: '
            . $field->getConfigDependencyName() . '.'
          );
        }
      }
    }
  }

  return $summary;
};

$states = $inspect_targets();
$section('Active target state');
foreach ($targets as $target) {
  $state = $states[$target['name']];
  $verb = $action === 'rollback'
    ? ($state === 'match' ? 'WOULD_DELETE' : strtoupper($state))
    : ($state === 'missing' ? 'WOULD_CREATE' : strtoupper($state));
  echo $verb . ' ' . $target['name'] . PHP_EOL;
  if ($state === 'collision') {
    $fail('Refusing active config drift/collision at ' . $target['name'] . '.');
  }
}
$assert_target_collections_clear();
$feature_config_namespace = $assert_feature_config_namespace();
echo 'CONFIG NAMESPACE OK (raw dependents, bundle config, blocks, collections)' . PHP_EOL;
$bundle_field_map_state = $inspect_node_bundle_field_map($states);
echo 'BUNDLE FIELD MAP OK (body/comment lifecycle metadata matches config state)' . PHP_EOL;
$deleted_field_state = $inspect_feature_deleted_fields($states);
echo 'DELETED FIELD STATE OK feature_entries='
  . count(array_filter($deleted_field_state['feature'])) . PHP_EOL;
$webform_runtime_namespace = $inspect_webform_runtime_namespace($states);
echo 'WEBFORM RUNTIME NAMESPACE OK (tracking row, state, user data, files)' . PHP_EOL;

$record_counts = $count_feature_records();
$rollback_isolation = [];
if ($action === 'rollback') {
  echo 'ROLLBACK CONTENT GUARD forum_topic_nodes=' . $record_counts['forum_topic_nodes']
    . ' proposal_submissions=' . $record_counts['proposal_submissions'] . PHP_EOL;
  if ($record_counts['forum_topic_nodes'] > 0 || $record_counts['proposal_submissions'] > 0) {
    $fail(
      'Rollback refuses to delete feature config while topics or proposals exist. '
      . 'Export and remove them through reviewed admin workflows first.'
    );
  }
  $rollback_isolation = $assert_rollback_isolated($states);
  echo 'ROLLBACK ISOLATION OK (dependency and bundle side effects remain inside allowlist)' . PHP_EOL;
}
else {
  $assert_install_data_namespace($states, $record_counts);
  echo 'INSTALL DATA NAMESPACE OK forum_topic_nodes=' . $record_counts['forum_topic_nodes']
    . ' proposal_submissions=' . $record_counts['proposal_submissions'] . PHP_EOL;
}

ksort($source_hashes, SORT_STRING);
$plan = [
  'action' => $action,
  'site_origin' => $active_site_origin,
  'sources' => $source_hashes,
  'blog_view_variant' => $blog_view_variant,
  'blog_view_effective_source_hash' => $normalized_hash(
    $sources['views.view.blog_posts']
  ),
  'states' => $states,
  'comment_formats' => $active_comment_formats,
  'feature_config_namespace' => $feature_config_namespace,
  'bundle_field_map' => $bundle_field_map_state,
  'deleted_field_state' => $deleted_field_state,
  'webform_runtime_namespace' => $webform_runtime_namespace,
  'page_ids' => $page_ids,
  'record_counts' => $record_counts,
  'rollback_isolation' => $rollback_isolation,
];
$plan_hash = $normalized_hash($plan);
echo 'PLAN SHA-256 ' . $plan_hash . PHP_EOL;

if (!$is_apply) {
  echo PHP_EOL . 'DRY_RUN No active configuration or content was changed.' . PHP_EOL;
  echo $action === 'install'
    ? 'Rerun with --apply after reviewing the plan and taking a database backup.' . PHP_EOL
    : 'Rerun with --rollback --apply only after reviewing the zero-content guards.' . PHP_EOL;
  return;
}

if (\Drupal::state()->get('system.maintenance_mode', FALSE) !== TRUE) {
  $fail(
    'Apply and rollback require Drupal maintenance mode. Enable it and rebuild caches '
    . 'before rerunning the reviewed dry-run and apply commands.'
  );
}
echo 'MAINTENANCE OK (system.maintenance_mode=true)' . PHP_EOL;

$snapshot_all_config = static function () use (
  $config_storage,
  $fail
): array {
  $collection_names = array_values(array_unique(array_merge(
    [StorageInterface::DEFAULT_COLLECTION],
    $config_storage->getAllCollectionNames()
  )));
  sort($collection_names, SORT_STRING);

  $snapshot = [];
  foreach ($collection_names as $collection_name) {
    $storage = $collection_name === StorageInterface::DEFAULT_COLLECTION
      ? $config_storage
      : $config_storage->createCollection($collection_name);
    $names = $storage->listAll();
    sort($names, SORT_STRING);
    $values = $names === [] ? [] : $storage->readMultiple($names);
    foreach ($names as $name) {
      if (!array_key_exists($name, $values) || !is_array($values[$name])) {
        $fail(
          'Could not snapshot active config ' . $collection_name . ':' . $name . '.'
        );
      }
      $snapshot[$collection_name][$name] = $values[$name];
    }
    $snapshot[$collection_name] ??= [];
    ksort($snapshot[$collection_name], SORT_STRING);
  }
  return $snapshot;
};

$assert_exact_config_snapshot = static function (
  array $expected,
  string $context
) use ($snapshot_all_config, $canonicalize, $fail): void {
  $actual = $snapshot_all_config();
  if ($canonicalize($actual) === $canonicalize($expected)) {
    return;
  }

  $changed = [];
  $collections = array_values(array_unique(array_merge(
    array_keys($expected),
    array_keys($actual)
  )));
  sort($collections, SORT_STRING);
  foreach ($collections as $collection_name) {
    $expected_collection = $expected[$collection_name] ?? [];
    $actual_collection = $actual[$collection_name] ?? [];
    $names = array_values(array_unique(array_merge(
      array_keys($expected_collection),
      array_keys($actual_collection)
    )));
    sort($names, SORT_STRING);
    foreach ($names as $name) {
      $expected_exists = array_key_exists($name, $expected_collection);
      $actual_exists = array_key_exists($name, $actual_collection);
      if ($expected_exists !== $actual_exists
        || ($expected_exists
          && $canonicalize($expected_collection[$name]) !== $canonicalize($actual_collection[$name]))) {
        $collection_label = $collection_name === StorageInterface::DEFAULT_COLLECTION
          ? 'default'
          : $collection_name;
        $changed[] = $collection_label . ':' . $name;
      }
    }
  }
  $suffix = count($changed) > 12 ? ', ...' : '';
  $fail(
    $context . ' changed unexpected active configuration: '
    . implode(', ', array_slice($changed, 0, 12)) . $suffix . '.'
  );
};

$reset_runtime_caches = static function () use ($entity_type_manager): void {
  \Drupal::service('config.factory')->reset();
  \Drupal::service('cache.config')->deleteAll();
  \Drupal::state()->resetCache();
  $entity_type_manager->clearCachedDefinitions();
  \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
};

$persistent_lock = \Drupal::service('lock.persistent');
$script_lock = \Drupal::lock();
$import_lock_name = ConfigImporter::LOCK_NAME;
$script_lock_name = 'unisonges_forum_blog_mvp_config';
$lock_ttl = 3600.0;
if (!$persistent_lock->acquire($import_lock_name, $lock_ttl)) {
  $fail('Could not acquire Drupal\'s persistent configuration-import lock.');
}
$script_lock_acquired = FALSE;
try {
  if (!$script_lock->acquire($script_lock_name, $lock_ttl)) {
    $fail('Could not acquire the Forum/Blog configuration lock.');
  }
  $script_lock_acquired = TRUE;

  // Revalidate the entire target set after acquiring the lock and immediately
  // before the first write.
  $locked_states = $inspect_targets();
  if ($locked_states !== $states) {
    $fail('Target configuration changed after planning; rerun the dry-run.');
  }
  $assert_target_collections_clear();
  if ($assert_feature_config_namespace() !== $feature_config_namespace) {
    $fail('Feature config namespace changed after planning; rerun the dry-run.');
  }
  if ($inspect_node_bundle_field_map($locked_states) !== $bundle_field_map_state) {
    $fail('Bundle-field map changed after planning; rerun the dry-run.');
  }
  if ($inspect_feature_deleted_fields($locked_states) !== $deleted_field_state) {
    $fail('Deleted-field state changed after planning; rerun the dry-run.');
  }
  if ($inspect_webform_runtime_namespace($locked_states) !== $webform_runtime_namespace) {
    $fail('Webform runtime namespace changed after planning; rerun the dry-run.');
  }
  if ($action === 'install') {
    $locked_comment_field = $config_storage->read($comment_field_name);
    $locked_effective_comment_field = \Drupal::config($comment_field_name)->get();
    if (!is_array($locked_comment_field)
      || !is_array($locked_effective_comment_field)
      || $canonicalize($locked_comment_field) !== $canonicalize($active_comment_field)
      || $canonicalize($locked_effective_comment_field) !== $canonicalize($locked_comment_field)) {
      $fail('Comment body configuration changed after planning; rerun the dry-run.');
    }
  }
  $assert_webform_path_namespace_clear();
  $locked_record_counts = $count_feature_records();
  if ($locked_record_counts !== $record_counts) {
    $fail('Forum/Proposal record counts changed after planning; rerun the dry-run.');
  }
  if ($action === 'install') {
    if ($count_unsafe_comments() !== 0) {
      $fail('Unsafe webform_default comments appeared after planning; no configuration was written.');
    }
    $assert_install_data_namespace($locked_states, $locked_record_counts);
    $locked_page_ids = [
      '/blog' => $resolve_basic_page('/blog'),
      '/forum' => $resolve_basic_page('/forum'),
    ];
    if ($locked_page_ids !== $page_ids || $locked_page_ids['/blog'] === $locked_page_ids['/forum']) {
      $fail('Basic-page route ownership changed after planning; rerun the dry-run.');
    }
  }
  else {
    if ($locked_record_counts['forum_topic_nodes'] > 0
      || $locked_record_counts['proposal_submissions'] > 0) {
      $fail('Feature content appeared after rollback planning; rerun the dry-run.');
    }
    if ($assert_rollback_isolated($locked_states) !== $rollback_isolation) {
      $fail('Rollback dependency isolation changed after planning; rerun the dry-run.');
    }
  }

  if ($action === 'install') {
    if (!$comment_config_matches) {
      if (!$persistent_lock->acquire($import_lock_name, $lock_ttl)
        || !$script_lock->acquire($script_lock_name, $lock_ttl)) {
        $fail('Could not renew both configuration locks before comment hardening.');
      }
      $section('Comment hardening transaction');
      $comment_transaction = \Drupal::database()
        ->startTransaction('unisonges_forum_blog_comment');
      try {
        $comment_before = $snapshot_all_config();
        $comment_bundle_field_maps_before = $bundle_field_map_store->getAll();
        $comment_deleted_fields_before = $describe_deleted_field_state();
        if (!is_array($comment_bundle_field_maps_before)) {
          $fail('Could not snapshot bundle-field map metadata before comment hardening.');
        }
        $comment_expected = $comment_before;
        $comment_expected[StorageInterface::DEFAULT_COLLECTION][$comment_field_name]
          = $comment_field_source;
        if ($count_unsafe_comments() !== 0) {
          $fail('Unsafe webform_default comments appeared immediately before comment hardening.');
        }

        $fresh_comment_field = $comment_field_storage
          ->loadOverrideFree('comment.comment.comment_body');
        if (!$fresh_comment_field
          || $fresh_comment_field->getSetting('allowed_formats') !== $active_comment_formats) {
          $fail('The comment body field changed immediately before comment hardening.');
        }
        echo 'WRITE ' . $comment_field_name
          . ':settings.allowed_formats=["basic_html"] + calculated dependency' . PHP_EOL;
        $fresh_comment_field->setSetting('allowed_formats', ['basic_html']);
        $fresh_comment_field->save();
        $comment_field_storage->resetCache(['comment.comment.comment_body']);
        \Drupal::service('config.factory')->reset($comment_field_name);
        $reloaded_comment_field = $comment_field_storage
          ->loadOverrideFree('comment.comment.comment_body');
        $written_comment_field = $config_storage->read($comment_field_name);
        $effective_written_comment_field = \Drupal::config($comment_field_name)->get();
        if (!$reloaded_comment_field
          || $reloaded_comment_field->getSetting('allowed_formats') !== ['basic_html']
          || !is_array($written_comment_field)
          || !is_array($effective_written_comment_field)
          || $canonicalize($written_comment_field) !== $canonicalize($comment_field_source)
          || $canonicalize($effective_written_comment_field) !== $canonicalize($comment_field_source)
          || $count_unsafe_comments() !== 0) {
          $fail('Post-write verification failed for the comment format restriction.');
        }
        $assert_exact_config_snapshot($comment_expected, 'Comment hardening');
        $comment_bundle_field_maps_after = $bundle_field_map_store->getAll();
        if (!is_array($comment_bundle_field_maps_after)
          || $canonicalize($comment_bundle_field_maps_after)
            !== $canonicalize($comment_bundle_field_maps_before)
          || $describe_deleted_field_state() !== $comment_deleted_fields_before) {
          $fail('Comment hardening changed unexpected field lifecycle metadata.');
        }
        $comment_transaction->commitOrRelease();
        echo 'COMMIT Comment hardening is durable and intentionally independent.' . PHP_EOL;
      }
      catch (Throwable $throwable) {
        try {
          $comment_transaction->rollBack();
        }
        finally {
          $reset_runtime_caches();
        }
        throw new RuntimeException(
          'Comment hardening transaction rolled back: ' . $throwable->getMessage(),
          0,
          $throwable
        );
      }
    }
  }

  if (!$persistent_lock->acquire($import_lock_name, $lock_ttl)
    || !$script_lock->acquire($script_lock_name, $lock_ttl)) {
    $fail('Could not renew both configuration locks before the feature transaction.');
  }
  $section($action === 'install' ? 'Targeted apply transaction' : 'Targeted rollback transaction');
  $target_transaction = \Drupal::database()
    ->startTransaction('unisonges_forum_blog_targets');
  try {
    $target_before = $snapshot_all_config();
    $target_expected = $target_before;
    foreach ($targets as $target) {
      if ($action === 'install') {
        $target_expected[StorageInterface::DEFAULT_COLLECTION][$target['name']]
          = $sources[$target['name']];
      }
      else {
        unset($target_expected[StorageInterface::DEFAULT_COLLECTION][$target['name']]);
      }
    }

    $bundle_field_maps_before = $bundle_field_map_store->getAll();
    if (!is_array($bundle_field_maps_before)) {
      $fail('Could not snapshot all bundle-field map metadata.');
    }
    $bundle_field_map_before = $read_node_bundle_field_map();
    $bundle_field_map_expected = $bundle_field_map_before;
    foreach ($feature_field_specs as $spec) {
      if ($action === 'install') {
        if (!isset($bundle_field_map_expected[$spec['name']])) {
          $bundle_field_map_expected[$spec['name']] = [
            'type' => $spec['type'],
            'bundles' => [],
          ];
        }
        if (($bundle_field_map_expected[$spec['name']]['type'] ?? NULL) !== $spec['type']
          || !is_array($bundle_field_map_expected[$spec['name']]['bundles'] ?? NULL)) {
          $fail('Cannot construct a safe bundle-field map update for ' . $spec['name'] . '.');
        }
        $bundle_field_map_expected[$spec['name']]['bundles']['forum_topic'] = 'forum_topic';
      }
      elseif (isset($bundle_field_map_expected[$spec['name']]['bundles']['forum_topic'])) {
        unset($bundle_field_map_expected[$spec['name']]['bundles']['forum_topic']);
        if ($bundle_field_map_expected[$spec['name']]['bundles'] === []) {
          unset($bundle_field_map_expected[$spec['name']]);
        }
      }
    }

    $deleted_fields_before = $describe_deleted_field_state();
    $expected_new_deleted_fields = [];
    if ($action === 'rollback') {
      $field_config_storage = $get_config_entity_storage('field_config');
      $node_storage = $entity_type_manager->getStorage('node');
      foreach ($feature_field_specs as $config_name => $spec) {
        if ($locked_states[$config_name] !== 'match') {
          continue;
        }
        $field = $field_config_storage->loadOverrideFree('node.forum_topic.' . $spec['name']);
        if (!$field instanceof FieldConfigInterface) {
          $fail('Could not load field config for deleted-field lifecycle prediction: ' . $config_name . '.');
        }
        if ($node_storage->countFieldData($field->getFieldStorageDefinition(), TRUE)) {
          $expected_new_deleted_fields[$spec['uuid']] = $spec;
        }
      }
    }

    if ($action === 'install') {
      if ($count_unsafe_comments() !== 0) {
        $fail('Unsafe webform_default comments appeared before the feature transaction.');
      }
    foreach ($targets as $target) {
      if ($locked_states[$target['name']] === 'match') {
        echo 'NOOP ' . $target['name'] . PHP_EOL;
        continue;
      }
      if ($config_storage->exists($target['name'])) {
        $fail('Concurrent config appeared before create: ' . $target['name']);
      }
      $storage = $get_config_entity_storage($target['entity_type']);
      $entity = $storage->create($sources[$target['name']]);
      if ($entity->id() !== $target['id']) {
        $fail('Created entity id guard failed for ' . $target['name'] . '.');
      }
      echo 'CREATE ' . $target['name'] . PHP_EOL;
      $entity->save();
      $storage->resetCache([$target['id']]);
      $written = $config_storage->read($target['name']);
      if (!is_array($written) || $canonicalize($written) !== $canonicalize($sources[$target['name']])) {
        $fail('Post-create exact config verification failed for ' . $target['name'] . '.');
      }
    }
    }
    else {
    foreach (array_reverse($targets) as $target) {
      if ($locked_states[$target['name']] === 'missing') {
        echo 'NOOP ' . $target['name'] . PHP_EOL;
        continue;
      }
      $storage = $get_config_entity_storage($target['entity_type']);
      $entity = $storage->loadOverrideFree($target['id']);
      if (!$entity) {
        $fail('Rollback could not load exact entity ' . $target['name'] . '.');
      }
      echo 'DELETE ' . $target['name'] . PHP_EOL;
      $entity->setSyncing(TRUE);
      $storage->delete([$entity]);
      $storage->resetCache([$target['id']]);
      if ($config_storage->exists($target['name'])) {
        $fail('Post-delete verification failed for ' . $target['name'] . '.');
      }
    }
    }

    $final_states = $inspect_targets();
    foreach ($final_states as $name => $state) {
      $expected = $action === 'install' ? 'match' : 'missing';
      if ($state !== $expected) {
        $fail('Final state verification failed for ' . $name . ': ' . $state);
      }
    }
    $assert_webform_path_namespace_clear();
    $inspect_webform_runtime_namespace($final_states);
    $bundle_field_maps_expected = $bundle_field_maps_before;
    $bundle_field_maps_expected['node'] = $bundle_field_map_expected;
    $bundle_field_maps_after = $bundle_field_map_store->getAll();
    if (!is_array($bundle_field_maps_after)
      || $canonicalize($bundle_field_maps_after) !== $canonicalize($bundle_field_maps_expected)) {
      $fail('Unexpected bundle-field map change during the feature transaction.');
    }
    $inspect_node_bundle_field_map($final_states);

    $deleted_fields_after = $describe_deleted_field_state();
    foreach ($deleted_fields_before as $uuid => $descriptor) {
      if (!isset($deleted_fields_after[$uuid]) || $deleted_fields_after[$uuid] !== $descriptor) {
        $fail('Pre-existing deleted-field metadata changed during the feature transaction: ' . $uuid . '.');
      }
    }
    $new_deleted_field_uuids = array_values(array_diff(
      array_keys($deleted_fields_after),
      array_keys($deleted_fields_before)
    ));
    $expected_new_deleted_field_uuids = array_keys($expected_new_deleted_fields);
    sort($new_deleted_field_uuids, SORT_STRING);
    sort($expected_new_deleted_field_uuids, SORT_STRING);
    if ($new_deleted_field_uuids !== $expected_new_deleted_field_uuids) {
      $fail('Unexpected deleted-field metadata was created during the feature transaction.');
    }
    foreach ($expected_new_deleted_fields as $uuid => $spec) {
      $descriptor = $deleted_fields_after[$uuid] ?? [];
      if (($descriptor['key'] ?? NULL) !== $uuid
        || ($descriptor['uuid'] ?? NULL) !== $uuid
        || ($descriptor['unique_id'] ?? NULL) !== $uuid
        || ($descriptor['id'] ?? NULL) !== 'node.forum_topic.' . $spec['name']
        || ($descriptor['deleted'] ?? NULL) !== TRUE
        || ($descriptor['entity_type'] ?? NULL) !== 'node'
        || ($descriptor['bundle'] ?? NULL) !== 'forum_topic'
        || ($descriptor['name'] ?? NULL) !== $spec['name']
        || ($descriptor['type'] ?? NULL) !== $spec['type']) {
        $fail('Invalid feature deleted-field metadata was created for ' . $uuid . '.');
      }
    }
    $inspect_feature_deleted_fields($final_states);
    if ($count_feature_records() !== $locked_record_counts) {
      $fail('Feature content changed during the targeted configuration transaction.');
    }
    if ($action === 'install' && $count_unsafe_comments() !== 0) {
      $fail('Unsafe webform_default comments appeared during the feature transaction.');
    }
    $assert_exact_config_snapshot($target_expected, 'Targeted ' . $action);
    $target_transaction->commitOrRelease();
  }
  catch (Throwable $throwable) {
    try {
      $target_transaction->rollBack();
    }
    finally {
      $reset_runtime_caches();
    }
    throw new RuntimeException(
      'Targeted ' . $action . ' transaction rolled back: ' . $throwable->getMessage(),
      0,
      $throwable
    );
  }

  echo PHP_EOL . 'OK Targeted ' . $action . ' completed.' . PHP_EOL;
  echo 'Config entity allowlist count: ' . count($targets) . PHP_EOL;
  echo $action === 'install'
    ? 'Comment format hardening: basic_html (retained during feature rollback).' . PHP_EOL
    : 'Comment format policy left unchanged; Article content and existing comments were not changed.' . PHP_EOL;
  if ($action === 'rollback') {
    $feature_tombstone_uuids = array_values(array_intersect(
      array_keys($deleted_fields_after),
      array_column($feature_field_specs, 'uuid')
    ));
    if ($feature_tombstone_uuids !== []) {
      echo 'NOTICE Core field-purge metadata remains for: '
        . implode(', ', $feature_tombstone_uuids)
        . '. Complete reviewed cron/field purge before reinstalling.' . PHP_EOL;
    }
  }
  echo 'Run drush cache:rebuild, then execute the documented functional matrix.' . PHP_EOL;
}
finally {
  if ($script_lock_acquired) {
    $script_lock->release($script_lock_name);
  }
  $persistent_lock->release($import_lock_name);
}
