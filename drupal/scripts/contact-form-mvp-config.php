<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Drupal\Core\Config\CachedStorage;
use Drupal\Core\Config\DatabaseStorage;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Config\StorageInterface;
use Symfony\Component\Yaml\Yaml;

$mode = getenv('UNISONGES_CONTACT_FORM_MODE') ?: 'dry-run';
$action = getenv('UNISONGES_CONTACT_FORM_ACTION') ?: 'install';
$expected_site_uri = getenv('UNISONGES_CONTACT_FORM_SITE_URI') ?: '';
if (!in_array($mode, ['dry-run', 'apply'], TRUE)) {
  throw new RuntimeException('Invalid mode; expected dry-run or apply.');
}
if (!in_array($action, ['install', 'rollback'], TRUE)) {
  throw new RuntimeException('Invalid action; expected install or rollback.');
}
$is_apply = $mode === 'apply';

$webform_name = 'webform.webform.contact';
$webform_id = 'contact';
$webform_uuid = 'c76dd154-d2cd-4b04-92c9-fe61536beabe';
$block_name = 'block.block.unisonges_contact_form';
$block_id = 'unisonges_contact_form';
$block_uuid = 'fd67a4c0-e06a-46f0-af88-acc5c0b28f8f';
$legacy_webform_hashes = [
  // The exact tracked default config, with its install-time metadata.
  '4d5476e9e234f6837822622aebe23b3d851eb8a45ae5a61d58a6dabf94811803',
  // The same exact record after Drupal strips non-functional _core metadata.
  '73f36e858d9d8cf63d430c0fa3e6d049c883b57d76f176708529b04d66bc3002',
];
$project_root = realpath(__DIR__ . '/..');
if ($project_root === FALSE) {
  throw new RuntimeException('Could not resolve the Drupal project root.');
}
$sync_dir = $project_root . '/config/sync';
$source_paths = [
  $webform_name => $sync_dir . '/' . $webform_name . '.yml',
  $block_name => $sync_dir . '/' . $block_name . '.yml',
];
$translation_source_path = $sync_dir . '/language/fr/' . $webform_name . '.yml';

$section = static function (string $title): void {
  echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
};

$fail = static function (string $message): never {
  throw new RuntimeException($message);
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
  $encoded = json_encode(
    $canonicalize($value),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
  );
  if ($encoded === FALSE) {
    throw new RuntimeException('Could not normalize data for hashing.');
  }
  return hash('sha256', $encoded);
};

$is_uuid_v4 = static fn ($value): bool => is_string($value)
  && preg_match(
    '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
    $value
  ) === 1;

$section('Reviewed source configuration');
$sources = [];
foreach ($source_paths as $name => $path) {
  $resolved = realpath($path);
  if ($resolved === FALSE || $resolved !== $path || is_link($path)) {
    $fail('Exact source-path guard failed for ' . $path . '.');
  }
  try {
    $data = Yaml::parseFile($path);
  }
  catch (Throwable $throwable) {
    $fail('Could not parse ' . $name . ': ' . $throwable->getMessage());
  }
  if (!is_array($data)) {
    $fail('Source config must contain a top-level mapping: ' . $name . '.');
  }
  $sources[$name] = $data;
  echo 'SOURCE ' . $name . ' sha256=' . hash_file('sha256', $path) . PHP_EOL;
}
$resolved_translation_path = realpath($translation_source_path);
if ($resolved_translation_path === FALSE
  || $resolved_translation_path !== $translation_source_path
  || is_link($translation_source_path)) {
  $fail('Exact source-path guard failed for the existing French Contact override.');
}
try {
  $translation_source = Yaml::parseFile($translation_source_path);
}
catch (Throwable $throwable) {
  $fail('Could not parse the French Contact override: ' . $throwable->getMessage());
}
$expected_translation = [
  'title' => 'Contact',
  'settings' => [
    'confirmation_message' => 'Votre message a été envoyé.',
  ],
];
if (!is_array($translation_source)
  || $canonicalize($translation_source) !== $canonicalize($expected_translation)) {
  $fail('The existing French Contact override has unexpected drift.');
}
echo 'PREREQUISITE language.fr::' . $webform_name . ' is the reviewed unchanged override.' . PHP_EOL;

$webform_source = $sources[$webform_name];
$block_source = $sources[$block_name];
if (($webform_source['id'] ?? NULL) !== $webform_id
  || ($webform_source['uuid'] ?? NULL) !== $webform_uuid
  || !$is_uuid_v4($webform_source['uuid'] ?? NULL)
  || ($webform_source['langcode'] ?? NULL) !== 'fr'
  || ($webform_source['status'] ?? NULL) !== 'open'
  || !array_key_exists('uid', $webform_source)
  || $webform_source['uid'] !== NULL
  || ($webform_source['template'] ?? NULL) !== FALSE
  || ($webform_source['archive'] ?? NULL) !== FALSE
  || isset($webform_source['_core'])
  || ($webform_source['dependencies'] ?? NULL) !== [
    'enforced' => ['module' => ['webform']],
  ]) {
  $fail('The reviewed Contact Webform identity or dependency contract is invalid.');
}
if (($webform_source['css'] ?? NULL) !== ''
  || ($webform_source['javascript'] ?? NULL) !== ''
  || ($webform_source['handlers'] ?? NULL) !== []
  || ($webform_source['variants'] ?? NULL) !== []
  || isset($webform_source['third_party_settings'])) {
  $fail('The Contact Webform must contain no custom assets, handlers, variants or integrations.');
}

try {
  $elements = Yaml::parse((string) ($webform_source['elements'] ?? ''));
}
catch (Throwable $throwable) {
  $fail('Could not parse Contact Webform elements: ' . $throwable->getMessage());
}
$expected_element_keys = ['name', 'email', 'subject', 'message', 'consent', 'actions'];
$expected_subject_options = [
  'cours_stages' => 'Cours et stages',
  'concerts_evenements' => 'Concerts et événements',
  'projets_collectifs' => 'Projets collectifs',
  'association_partenariats' => 'Association et partenariats',
  'prestations_artistiques' => 'Prestations artistiques',
  'autre' => 'Autre',
];
if (!is_array($elements)
  || array_keys($elements) !== $expected_element_keys
  || ($elements['name']['#type'] ?? NULL) !== 'textfield'
  || ($elements['name']['#title'] ?? NULL) !== 'Nom'
  || ($elements['name']['#description'] ?? NULL)
    !== 'Indiquez le nom à utiliser pour vous répondre.'
  || ($elements['name']['#required'] ?? NULL) !== TRUE
  || ($elements['name']['#required_error'] ?? NULL) !== 'Veuillez indiquer votre nom.'
  || ($elements['name']['#maxlength'] ?? NULL) !== 120
  || ($elements['name']['#attributes'] ?? NULL) !== ['autocomplete' => 'name']
  || ($elements['email']['#type'] ?? NULL) !== 'email'
  || ($elements['email']['#title'] ?? NULL) !== 'Adresse e-mail'
  || ($elements['email']['#description'] ?? NULL)
    !== 'Indiquez une adresse valide à laquelle l’association pourra vous répondre.'
  || ($elements['email']['#required'] ?? NULL) !== TRUE
  || ($elements['email']['#required_error'] ?? NULL)
    !== 'Veuillez indiquer votre adresse e-mail.'
  || ($elements['email']['#maxlength'] ?? NULL) !== 254
  || ($elements['email']['#attributes'] ?? NULL) !== [
    'autocomplete' => 'email',
    'inputmode' => 'email',
  ]
  || ($elements['subject']['#type'] ?? NULL) !== 'select'
  || ($elements['subject']['#title'] ?? NULL) !== 'Objet'
  || ($elements['subject']['#description'] ?? NULL)
    !== 'Choisissez la catégorie qui correspond le mieux à votre demande.'
  || ($elements['subject']['#required'] ?? NULL) !== TRUE
  || ($elements['subject']['#required_error'] ?? NULL) !== 'Veuillez choisir un objet.'
  || ($elements['subject']['#empty_option'] ?? NULL) !== '- Sélectionner -'
  || ($elements['subject']['#options'] ?? NULL) !== $expected_subject_options
  || ($elements['message']['#type'] ?? NULL) !== 'textarea'
  || ($elements['message']['#title'] ?? NULL) !== 'Message'
  || ($elements['message']['#description'] ?? NULL)
    !== 'Décrivez votre demande en 20 à 5 000 caractères.'
  || ($elements['message']['#required'] ?? NULL) !== TRUE
  || ($elements['message']['#required_error'] ?? NULL) !== 'Veuillez saisir votre message.'
  || ($elements['message']['#rows'] ?? NULL) !== 10
  || ($elements['message']['#minlength'] ?? NULL) !== 20
  || ($elements['message']['#maxlength'] ?? NULL) !== 5000
  || ($elements['message']['#pattern'] ?? NULL) !== '[\s\S]{20,5000}'
  || ($elements['message']['#pattern_error'] ?? NULL)
    !== 'Le message doit contenir entre 20 et 5 000 caractères.'
  || ($elements['consent']['#type'] ?? NULL) !== 'checkbox'
  || ($elements['consent']['#required'] ?? NULL) !== TRUE
  || ($elements['consent']['#title'] ?? NULL)
    !== 'J’accepte que les informations fournies soient utilisées uniquement pour traiter ma demande de contact et y répondre.'
  || ($elements['consent']['#required_error'] ?? NULL)
    !== 'Votre consentement est nécessaire pour envoyer cette demande.'
  || ($elements['actions']['#type'] ?? NULL) !== 'webform_actions'
  || ($elements['actions']['#title'] ?? NULL) !== 'Actions'
  || ($elements['actions']['#submit__label'] ?? NULL) !== 'Envoyer la demande') {
  $fail('The Contact Webform fields, limits, consent or subject options are not exact.');
}
foreach (['name', 'email', 'subject', 'message', 'consent'] as $required_key) {
  if (!isset($elements[$required_key]['#required_error'])
    || !is_string($elements[$required_key]['#required_error'])
    || trim($elements[$required_key]['#required_error']) === '') {
    $fail('Required field lacks a clear server-side error: ' . $required_key . '.');
  }
  foreach (['#format', '#allowed_formats', '#default_value', '#value'] as $forbidden_property) {
    if (array_key_exists($forbidden_property, $elements[$required_key])) {
      $fail('Forbidden property ' . $forbidden_property . ' on ' . $required_key . '.');
    }
  }
}
$forbidden_element_types = [
  'captcha',
  'managed_file',
  'text_format',
  'webform_attachment',
  'webform_codemirror',
  'webform_document_file',
  'webform_image_file',
  'webform_multiple_file',
];
foreach ($elements as $key => $element) {
  if (!is_array($element)
    || in_array($element['#type'] ?? '', $forbidden_element_types, TRUE)) {
    $fail('Forbidden or malformed Contact element: ' . $key . '.');
  }
}

$settings = $webform_source['settings'] ?? NULL;
if (!is_array($settings)
  || ($settings['page'] ?? NULL) !== FALSE
  || ($settings['page_submit_path'] ?? NULL) !== ''
  || ($settings['page_confirm_path'] ?? NULL) !== ''
  || ($settings['form_submit_once'] ?? NULL) !== TRUE
  || ($settings['form_previous_submissions'] ?? NULL) !== FALSE
  || ($settings['form_confidential'] ?? NULL) !== FALSE
  || ($settings['form_disable_remote_addr'] ?? NULL) !== TRUE
  || ($settings['form_convert_anonymous'] ?? NULL) !== FALSE
  || ($settings['form_prepopulate'] ?? NULL) !== FALSE
  || ($settings['form_action'] ?? NULL) !== ''
  || ($settings['share'] ?? NULL) !== FALSE
  || ($settings['share_node'] ?? NULL) !== FALSE
  || ($settings['submission_log'] ?? NULL) !== FALSE
  || ($settings['submission_user_duplicate'] ?? NULL) !== FALSE
  || ($settings['draft'] ?? NULL) !== 'none'
  || ($settings['confirmation_type'] ?? NULL) !== 'inline'
  || ($settings['confirmation_url'] ?? NULL) !== ''
  || ($settings['confirmation_title'] ?? NULL) !== 'Demande enregistrée'
  || ($settings['confirmation_message'] ?? NULL)
    !== $expected_translation['settings']['confirmation_message']
  || ($settings['limit_total'] ?? NULL) !== 30
  || ($settings['limit_total_interval'] ?? NULL) !== 3600
  || ($settings['limit_user'] ?? NULL) !== 5
  || ($settings['limit_user_interval'] ?? NULL) !== 3600
  || ($settings['limit_total_unique'] ?? NULL) !== FALSE
  || ($settings['limit_user_unique'] ?? NULL) !== FALSE
  || !array_key_exists('entity_limit_total', $settings)
  || $settings['entity_limit_total'] !== NULL
  || !array_key_exists('entity_limit_user', $settings)
  || $settings['entity_limit_user'] !== NULL
  || ($settings['purge'] ?? NULL) !== 'none'
  || !array_key_exists('purge_days', $settings)
  || $settings['purge_days'] !== NULL
  || ($settings['results_disabled'] ?? NULL) !== FALSE
  || ($settings['results_customize'] ?? NULL) !== FALSE
  || ($settings['token_view'] ?? NULL) !== FALSE
  || ($settings['token_update'] ?? NULL) !== FALSE
  || ($settings['token_delete'] ?? NULL) !== FALSE) {
  $fail('The Contact storage, privacy, confirmation, route or limit settings are invalid.');
}

$access = $webform_source['access'] ?? NULL;
$expected_access_operations = [
  'create',
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
if (!is_array($access) || array_keys($access) !== $expected_access_operations) {
  $fail('The Contact Webform access-operation list is not exact.');
}
foreach ($access as $operation => $rule) {
  if (!is_array($rule)
    || array_keys($rule) !== ['roles', 'users', 'permissions']
    || ($rule['users'] ?? NULL) !== []
    || ($rule['permissions'] ?? NULL) !== []) {
    $fail('Malformed Contact Webform access rule: ' . $operation . '.');
  }
  $expected_roles = $operation === 'create' ? ['anonymous', 'authenticated'] : [];
  if (($rule['roles'] ?? NULL) !== $expected_roles) {
    $fail('Unexpected Contact Webform role access: ' . $operation . '.');
  }
}

if (($block_source['id'] ?? NULL) !== $block_id
  || ($block_source['uuid'] ?? NULL) !== $block_uuid
  || !$is_uuid_v4($block_source['uuid'] ?? NULL)
  || ($block_source['langcode'] ?? NULL) !== 'fr'
  || ($block_source['status'] ?? NULL) !== TRUE
  || ($block_source['theme'] ?? NULL) !== 'unisonges_theme'
  || ($block_source['region'] ?? NULL) !== 'content'
  || ($block_source['weight'] ?? NULL) !== 50
  || ($block_source['plugin'] ?? NULL) !== 'webform_block'
  || ($block_source['dependencies'] ?? NULL) !== [
    'config' => [$webform_name],
    'module' => ['system', 'webform'],
    'theme' => ['unisonges_theme'],
  ]
  || ($block_source['settings'] ?? NULL) !== [
    'id' => 'webform_block',
    'label' => 'Formulaire de contact',
    'label_display' => 'visible',
    'provider' => 'webform',
    'webform_id' => $webform_id,
    'default_data' => '',
    'redirect' => FALSE,
    'lazy' => FALSE,
  ]
  || ($block_source['visibility'] ?? NULL) !== [
    'request_path' => [
      'id' => 'request_path',
      'negate' => FALSE,
      'pages' => '/contact',
    ],
  ]) {
  $fail('The Contact block must embed only contact in content on exact path /contact.');
}
if ($webform_uuid === $block_uuid) {
  $fail('The two Contact config UUIDs collide.');
}
echo 'SOURCE SEMANTICS OK (five fields, private results, no handler, exact /contact block).' . PHP_EOL;

$section('Locked runtime and storage');
$reviewed_docroot = realpath($project_root . '/web');
if ($reviewed_docroot === FALSE || $reviewed_docroot !== \Drupal::root()) {
  $fail('Bootstrapped Drupal root does not match this reviewed checkout.');
}
if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 3) {
  $fail('The reviewed runtime is PHP 8.3.x; active=' . PHP_VERSION . '.');
}
if (\Drupal::VERSION !== '11.3.3') {
  $fail('The reviewed Drupal runtime is 11.3.3; active=' . \Drupal::VERSION . '.');
}
foreach ([
  'drupal/webform' => '6.3.0-beta7',
  'drush/drush' => '13.7.1',
] as $package => $version) {
  if (!InstalledVersions::isInstalled($package)
    || ltrim((string) InstalledVersions::getPrettyVersion($package), 'v') !== $version) {
    $fail('Unexpected installed version for ' . $package . '; expected ' . $version . '.');
  }
}
$site_parts = parse_url($expected_site_uri);
$site_host = is_array($site_parts) && isset($site_parts['host'])
  ? rtrim(strtolower((string) $site_parts['host']), '.')
  : '';
if ($expected_site_uri === ''
  || preg_match('/[\x00-\x20\x7f]/', $expected_site_uri) === 1
  || !filter_var($expected_site_uri, FILTER_VALIDATE_URL)
  || !is_array($site_parts)
  || !isset($site_parts['scheme'], $site_parts['host'])
  || !in_array(strtolower((string) $site_parts['scheme']), ['http', 'https'], TRUE)
  || $site_host === ''
  || in_array($site_host, ['unisonges.fr', 'www.unisonges.fr'], TRUE)
  || isset($site_parts['user'])
  || isset($site_parts['pass'])
  || isset($site_parts['query'])
  || isset($site_parts['fragment'])
  || !in_array($site_parts['path'] ?? '', ['', '/'], TRUE)
  || (isset($site_parts['port'])
    && ($site_parts['port'] < 1 || $site_parts['port'] > 65535))) {
  $fail('UNISONGES_CONTACT_FORM_SITE_URI is not an approved non-production origin.');
}
$expected_origin = strtolower(
  $site_parts['scheme'] . '://' . $site_host
  . (isset($site_parts['port']) ? ':' . $site_parts['port'] : '')
);
$active_parts = parse_url(\Drupal::request()->getSchemeAndHttpHost());
$active_host = is_array($active_parts) && isset($active_parts['host'])
  ? rtrim(strtolower((string) $active_parts['host']), '.')
  : '';
if (!is_array($active_parts)
  || !isset($active_parts['scheme'], $active_parts['host'])
  || $active_host === '') {
  $fail('Could not normalize the bootstrapped site origin.');
}
$active_origin = strtolower(
  $active_parts['scheme'] . '://' . $active_host
  . (isset($active_parts['port']) ? ':' . $active_parts['port'] : '')
);
if (!hash_equals($expected_origin, $active_origin)) {
  $fail('Bootstrapped site origin does not match the explicit approved origin.');
}

$cached_config_storage = \Drupal::service('config.storage');
if (get_class($cached_config_storage) !== CachedStorage::class) {
  $fail('Active config storage must be Drupal core CachedStorage exactly.');
}
try {
  $storage_property = new ReflectionProperty(CachedStorage::class, 'storage');
  $config_storage = $storage_property->getValue($cached_config_storage);
}
catch (ReflectionException $exception) {
  $fail('Could not inspect active config storage: ' . $exception->getMessage());
}
if (!$config_storage instanceof DatabaseStorage
  || $config_storage->getCollectionName() !== StorageInterface::DEFAULT_COLLECTION) {
  $fail('CachedStorage must wrap default-collection DatabaseStorage directly.');
}
try {
  $connection_property = new ReflectionProperty(DatabaseStorage::class, 'connection');
  $table_property = new ReflectionProperty(DatabaseStorage::class, 'table');
  $storage_connection = $connection_property->getValue($config_storage);
  $storage_table = $table_property->getValue($config_storage);
}
catch (ReflectionException $exception) {
  $fail('Could not verify DatabaseStorage internals: ' . $exception->getMessage());
}
if ($storage_connection !== \Drupal::database() || $storage_table !== 'config') {
  $fail('Active config writes are not backed by this site connection and core config table.');
}
if (!\Drupal::database()->schema()->tableExists('config')) {
  $fail('The active config database table is unavailable.');
}
if ($is_apply && (int) \Drupal::state()->get('system.maintenance_mode', 0) !== 1) {
  $fail('Apply requires Drupal maintenance mode before any lock or configuration write.');
}
echo 'RUNTIME OK site=' . $active_origin
  . ' PHP=8.3 Drupal=11.3.3 Webform=6.3.0-beta7 Drush=13.7.1' . PHP_EOL;

$entity_type_manager = \Drupal::entityTypeManager();
$get_config_storage = static function (string $entity_type_id) use (
  $entity_type_manager,
  $fail
): ConfigEntityStorageInterface {
  $storage = $entity_type_manager->getStorage($entity_type_id);
  if (!$storage instanceof ConfigEntityStorageInterface) {
    $fail('Expected config-entity storage for ' . $entity_type_id . '.');
  }
  return $storage;
};
$webform_storage = $get_config_storage('webform');
$block_storage = $get_config_storage('block');
if ($entity_type_manager->getDefinition('webform')->getConfigPrefix() !== 'webform.webform'
  || $entity_type_manager->getDefinition('block')->getConfigPrefix() !== 'block.block') {
  $fail('Unexpected Webform or Block config prefix.');
}

$required_modules = ['block', 'language', 'node', 'path', 'path_alias', 'system', 'user', 'webform'];
foreach ($required_modules as $module) {
  if (!\Drupal::moduleHandler()->moduleExists($module)) {
    $fail('Required active module is missing: ' . $module . '.');
  }
}
foreach (['antibot', 'captcha', 'honeypot', 'recaptcha'] as $unsupported_spam_module) {
  if (\Drupal::moduleHandler()->moduleExists($unsupported_spam_module)) {
    $fail('Unreviewed spam module is active: ' . $unsupported_spam_module . '.');
  }
}
$system_theme = $config_storage->read('system.theme');
if (!is_array($system_theme) || ($system_theme['default'] ?? NULL) !== 'unisonges_theme') {
  $fail('The active default theme must be exactly unisonges_theme.');
}
$theme_info = \Drupal::service('extension.list.theme')->getExtensionInfo('unisonges_theme');
if (!is_array($theme_info) || !isset($theme_info['regions']['content'])) {
  $fail('The active UniSonges theme does not expose the required content region.');
}
if (!\Drupal::service('plugin.manager.block')->hasDefinition('webform_block')) {
  $fail('The native Webform block plugin is unavailable.');
}
if (!\Drupal::service('plugin.manager.condition')->hasDefinition('request_path')) {
  $fail('The request_path visibility condition plugin is unavailable.');
}
$element_manager = \Drupal::service('plugin.manager.webform.element');
foreach (['textfield', 'email', 'select', 'textarea', 'checkbox', 'webform_actions'] as $plugin_id) {
  if (!$element_manager->hasDefinition($plugin_id)) {
    $fail('Required Webform element plugin is unavailable: ' . $plugin_id . '.');
  }
}
echo 'DEPENDENCIES OK (native Webform only; no CAPTCHA/Honeypot/Antibot/IP limiter).' . PHP_EOL;

$active_translation_storage = \Drupal::languageManager()->getLanguageConfigOverrideStorage('fr');
$active_translation = $active_translation_storage->read($webform_name);
if (!is_array($active_translation)
  || $canonicalize($active_translation) !== $canonicalize($expected_translation)) {
  $fail('Active French Contact override does not match the reviewed unchanged prerequisite.');
}

$all_active_names = $config_storage->listAll();
$all_active_values = $config_storage->readMultiple($all_active_names);
$source_uuid_owners = [
  $webform_uuid => $webform_name,
  $block_uuid => $block_name,
];
foreach ($all_active_values as $active_name => $active_data) {
  if (!is_array($active_data) || !isset($active_data['uuid'])) {
    continue;
  }
  $uuid = (string) $active_data['uuid'];
  if (isset($source_uuid_owners[$uuid]) && $source_uuid_owners[$uuid] !== $active_name) {
    $fail('Contact source UUID is already owned by active config ' . $active_name . '.');
  }
}
echo 'UUID NAMESPACE OK (existing Webform UUID retained; block UUID unique).' . PHP_EOL;

$rollback_webform = $webform_source;
$rollback_webform['status'] = 'closed';
$rollback_block = $block_source;
$rollback_block['status'] = FALSE;
$target_hashes = [
  'webform' => $normalized_hash($webform_source),
  'block' => $normalized_hash($block_source),
  'rollback_webform' => $normalized_hash($rollback_webform),
  'rollback_block' => $normalized_hash($rollback_block),
];

$snapshot_all_config = static function () use ($config_storage): array {
  $collections = [StorageInterface::DEFAULT_COLLECTION];
  foreach ($config_storage->getAllCollectionNames() as $collection) {
    if ($collection !== StorageInterface::DEFAULT_COLLECTION) {
      $collections[] = $collection;
    }
  }
  sort($collections, SORT_STRING);
  $snapshot = [];
  foreach ($collections as $collection) {
    $storage = $collection === StorageInterface::DEFAULT_COLLECTION
      ? $config_storage
      : $config_storage->createCollection($collection);
    $names = $storage->listAll();
    sort($names, SORT_STRING);
    $values = $storage->readMultiple($names);
    ksort($values, SORT_STRING);
    $snapshot[$collection] = $values;
  }
  return $snapshot;
};

$internal_webform_paths = [
  '/webform/contact',
  '/webform/contact/confirmation',
  '/webform/contact/submissions',
  '/webform/contact/drafts',
];
$expected_internal_aliases = [
  '/webform/contact' => '/form/contact',
  '/webform/contact/confirmation' => '/form/contact/confirmation',
  '/webform/contact/submissions' => '/form/contact/submissions',
  '/webform/contact/drafts' => '/form/contact/drafts',
];
$path_alias_storage = $entity_type_manager->getStorage('path_alias');

$inspect_internal_aliases = static function () use (
  $path_alias_storage,
  $internal_webform_paths,
  $expected_internal_aliases,
  $fail
): array {
  $ids = $path_alias_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('path', $internal_webform_paths, 'IN')
    ->sort('id')
    ->execute();
  $records = [];
  foreach ($path_alias_storage->loadMultiple($ids) as $alias) {
    $path = (string) $alias->getPath();
    $public_alias = (string) $alias->getAlias();
    $langcode = (string) $alias->language()->getId();
    if (!isset($expected_internal_aliases[$path])
      || $public_alias !== $expected_internal_aliases[$path]
      || !in_array($langcode, ['fr', 'und'], TRUE)) {
      $fail('Conflicting path alias occupies the Contact Webform route namespace.');
    }
    $key = $path . '|' . $langcode;
    if (isset($records[$key])) {
      $fail('Duplicate Contact Webform alias exists for ' . $key . '.');
    }
    $records[$key] = [
      'id' => (string) $alias->id(),
      'uuid' => (string) $alias->uuid(),
      'path' => $path,
      'alias' => $public_alias,
      'langcode' => $langcode,
    ];
  }
  ksort($records, SORT_STRING);
  return $records;
};

$snapshot_unrelated_aliases = static function () use (
  $path_alias_storage,
  $internal_webform_paths,
  $canonicalize,
  $normalized_hash
): array {
  $ids = $path_alias_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('path', $internal_webform_paths, 'NOT IN')
    ->sort('id')
    ->execute();
  $records = [];
  foreach ($path_alias_storage->loadMultiple($ids) as $alias) {
    $records[(string) $alias->id()] = $canonicalize($alias->toArray());
  }
  ksort($records, SORT_STRING);
  return ['count' => count($records), 'hash' => $normalized_hash($records)];
};

$resolve_contact_page = static function () use (
  $path_alias_storage,
  $entity_type_manager,
  $normalized_hash,
  $fail
): array {
  $aliases = $path_alias_storage->loadByProperties(['alias' => '/contact']);
  if (count($aliases) !== 1) {
    $fail('/contact must be owned by exactly one existing path alias.');
  }
  $alias = reset($aliases);
  $path = (string) $alias->getPath();
  if (preg_match('#^/node/([1-9][0-9]*)$#D', $path, $matches) !== 1) {
    $fail('/contact must resolve to one existing node route.');
  }
  $node = $entity_type_manager->getStorage('node')->load((int) $matches[1]);
  if (!$node
    || $node->bundle() !== 'page'
    || !$node->isPublished()
    || (string) $node->label() !== 'Contact') {
    $fail('/contact must remain the published Basic page titled Contact.');
  }
  return [
    'nid' => (int) $node->id(),
    'alias_id' => (string) $alias->id(),
    'alias_uuid' => (string) $alias->uuid(),
    'node_hash' => $normalized_hash($node->toArray()),
  ];
};

$snapshot_submissions = static function () use (
  $entity_type_manager,
  $webform_id,
  $canonicalize,
  $normalized_hash
): array {
  $storage = $entity_type_manager->getStorage('webform_submission');
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->sort('sid')
    ->execute();
  $records = [];
  $contact_count = 0;
  foreach (array_chunk(array_values($ids), 100) as $id_chunk) {
    foreach ($storage->loadMultiple($id_chunk) as $submission) {
      $entity = $canonicalize($submission->toArray());
      if (($entity['webform_id'][0]['target_id'] ?? NULL) === $webform_id) {
        $contact_count++;
      }
      $records[(string) $submission->id()] = $normalized_hash([
        'entity' => $entity,
        'data' => $canonicalize($submission->getRawData()),
      ]);
    }
    $storage->resetCache($id_chunk);
  }
  if (count($records) !== count($ids)) {
    throw new RuntimeException('A Webform submission changed while it was being snapshotted.');
  }
  ksort($records, SORT_NATURAL);
  return [
    'total_count' => count($records),
    'contact_count' => $contact_count,
    'hash' => $normalized_hash($records),
  ];
};

$snapshot_webform_runtime = static function () use (
  $webform_id,
  $normalized_hash,
  $fail
): array {
  $result = \Drupal::database()
    ->select('webform', 'w')
    ->fields('w', ['webform_id', 'next_serial'])
    ->orderBy('webform_id')
    ->orderBy('next_serial')
    ->execute();
  $rows = [];
  $contact_serials = [];
  while ($row = $result->fetchAssoc()) {
    $record = [
      'webform_id' => (string) $row['webform_id'],
      'next_serial' => (int) $row['next_serial'],
    ];
    $rows[] = $record;
    if ($record['webform_id'] === $webform_id) {
      $contact_serials[] = $record['next_serial'];
    }
  }
  if (count($contact_serials) !== 1 || $contact_serials[0] < 1) {
    $fail('Contact must have exactly one positive Webform serial-tracking row.');
  }
  return [
    'count' => count($rows),
    'contact_next_serial' => $contact_serials[0],
    'hash' => $normalized_hash($rows),
  ];
};

$snapshot_webform_state = static function () use (
  $webform_id,
  $normalized_hash,
  $fail
): array {
  $state = \Drupal::state();
  $libraries = $state->get('webform_libraries', []);
  if (!is_array($libraries)) {
    $fail('The webform_libraries state value must be an array.');
  }
  if (array_key_exists($webform_id, $libraries)
    || in_array($webform_id, $libraries, TRUE)) {
    $fail('Contact unexpectedly appears in webform_libraries despite having no assets.');
  }
  return [
    'libraries_hash' => $normalized_hash($libraries),
    'contact_state_hash' => $normalized_hash(
      $state->get('webform.webform.' . $webform_id, [])
    ),
  ];
};

$assert_role_policy = static function () use ($entity_type_manager, $fail): array {
  $roles = $entity_type_manager->getStorage('user_role')->loadMultiple();
  if (!isset($roles['administrator']) || !$roles['administrator']->isAdmin()) {
    $fail('The administrator role must retain is_admin=true for review and deletion.');
  }
  foreach (['anonymous', 'authenticated'] as $public_role_id) {
    if (!isset($roles[$public_role_id])
      || !$roles[$public_role_id]->hasPermission('access content')) {
      $fail('Required public role is missing access content: ' . $public_role_id . '.');
    }
  }
  $forbidden_permissions = [
    'access webform overview',
    'access webform submission user',
    'administer webform',
    'administer webform element access',
    'administer webform overview',
    'administer webform submission',
    'edit webform source',
    'edit webform twig',
    'edit webform assets',
    'edit webform variants',
    'create webform',
    'edit any webform',
    'edit own webform',
    'delete any webform',
    'delete own webform',
    'access own webform configuration',
    'access any webform configuration',
    'view any webform submission',
    'view own webform submission',
    'edit any webform submission',
    'edit own webform submission',
    'delete any webform submission',
    'delete own webform submission',
  ];
  foreach ($roles as $role_id => $role) {
    if ($role->isAdmin()) {
      continue;
    }
    $unsafe = array_values(array_intersect($forbidden_permissions, $role->getPermissions()));
    if ($unsafe !== []) {
      $fail('Non-admin role has broad Webform result access: ' . $role_id . '.');
    }
  }
  return ['administrator' => 'is_admin', 'non_admin_roles' => count($roles) - 1];
};

$assert_contact_namespace = static function () use (
  $config_storage,
  $webform_name,
  $block_name,
  $webform_id,
  $fail
): void {
  foreach ($config_storage->listAll('webform.webform.') as $name) {
    if ($name === $webform_name) {
      continue;
    }
    $data = $config_storage->read($name);
    if (!is_array($data)) {
      $fail('Could not inspect active Webform config: ' . $name . '.');
    }
    $id = strtolower((string) ($data['id'] ?? ''));
    $title = strtolower(trim((string) ($data['title'] ?? '')));
    if ($title === 'contact' || preg_match('/(^|_)contact(_|$)/D', $id) === 1) {
      $fail('A second Contact-like Webform already exists: ' . $name . '.');
    }
  }
  foreach ($config_storage->listAll('block.block.') as $name) {
    if ($name === $block_name) {
      continue;
    }
    $data = $config_storage->read($name);
    if (is_array($data)
      && ($data['plugin'] ?? NULL) === 'webform_block'
      && ($data['settings']['webform_id'] ?? NULL) === $webform_id) {
      $fail('A non-allowlisted block already embeds the Contact Webform: ' . $name . '.');
    }
  }
};

$inspect_state = static function () use (
  $config_storage,
  $webform_storage,
  $block_storage,
  $webform_name,
  $webform_id,
  $webform_uuid,
  $block_name,
  $block_id,
  $block_uuid,
  $legacy_webform_hashes,
  $target_hashes,
  $normalized_hash,
  $canonicalize,
  $fail
): array {
  $active_webform = $config_storage->read($webform_name);
  $effective_webform = \Drupal::config($webform_name)->get();
  $webform_entity = $webform_storage->loadOverrideFree($webform_id);
  if (!is_array($active_webform)
    || !is_array($effective_webform)
    || !$webform_entity
    || ($active_webform['id'] ?? NULL) !== $webform_id
    || ($active_webform['uuid'] ?? NULL) !== $webform_uuid
    || $webform_entity->uuid() !== $webform_uuid
    || $canonicalize($active_webform) !== $canonicalize($effective_webform)) {
    $fail('Active Contact Webform is missing, overridden or has an identity conflict.');
  }
  $webform_hash = $normalized_hash($active_webform);
  if (in_array($webform_hash, $legacy_webform_hashes, TRUE)) {
    $webform_state = 'legacy';
  }
  elseif ($webform_hash === $target_hashes['webform']) {
    $webform_state = 'target';
  }
  elseif ($webform_hash === $target_hashes['rollback_webform']) {
    $webform_state = 'rollback';
  }
  else {
    $fail('Active Contact Webform has unknown drift; refusing to overwrite it.');
  }

  $active_block = $config_storage->read($block_name);
  $effective_block = \Drupal::config($block_name)->get();
  $block_entity = $block_storage->loadOverrideFree($block_id);
  if ($active_block === FALSE) {
    if ($block_entity || $effective_block !== []) {
      $fail('Contact block storage and config state disagree.');
    }
    $block_state = 'missing';
  }
  else {
    if (!is_array($active_block)
      || !is_array($effective_block)
      || !$block_entity
      || ($active_block['id'] ?? NULL) !== $block_id
      || ($active_block['uuid'] ?? NULL) !== $block_uuid
      || $block_entity->uuid() !== $block_uuid
      || $canonicalize($active_block) !== $canonicalize($effective_block)) {
      $fail('Active Contact block is overridden or has an identity conflict.');
    }
    $block_hash = $normalized_hash($active_block);
    if ($block_hash === $target_hashes['block']) {
      $block_state = 'target';
    }
    elseif ($block_hash === $target_hashes['rollback_block']) {
      $block_state = 'rollback';
    }
    else {
      $fail('Active Contact block has unknown drift; refusing to overwrite it.');
    }
  }
  return ['webform' => $webform_state, 'block' => $block_state];
};

$preflight = static function () use (
  $action,
  $sources,
  $webform_name,
  $block_name,
  $rollback_webform,
  $rollback_block,
  $inspect_state,
  $assert_contact_namespace,
  $assert_role_policy,
  $resolve_contact_page,
  $inspect_internal_aliases,
  $snapshot_unrelated_aliases,
  $snapshot_submissions,
  $snapshot_webform_runtime,
  $snapshot_webform_state,
  $snapshot_all_config,
  $normalized_hash,
  $target_hashes,
  $fail,
  $section
): array {
  $section('Read-only active-state preflight');
  $states = $inspect_state();
  $assert_contact_namespace();
  $role_policy = $assert_role_policy();
  $contact_page = $resolve_contact_page();
  $internal_aliases = $inspect_internal_aliases();
  $unrelated_aliases = $snapshot_unrelated_aliases();
  $submissions = $snapshot_submissions();
  $webform_runtime = $snapshot_webform_runtime();
  $webform_state = $snapshot_webform_state();
  $all_config = $snapshot_all_config();

  if ($action === 'install') {
    if ($states === ['webform' => 'legacy', 'block' => 'missing']) {
      $operations = ['UPDATE ' . $webform_name, 'CREATE ' . $block_name];
    }
    elseif ($states === ['webform' => 'rollback', 'block' => 'rollback']) {
      $operations = ['UPDATE ' . $webform_name, 'UPDATE ' . $block_name];
    }
    elseif ($states === ['webform' => 'target', 'block' => 'target']) {
      $operations = [];
    }
    else {
      $fail('Contact install state is partial or conflicting; no write is safe.');
    }
    $desired = [
      $webform_name => $sources[$webform_name],
      $block_name => $sources[$block_name],
    ];
  }
  else {
    if ($states === ['webform' => 'target', 'block' => 'target']) {
      $operations = ['UPDATE ' . $webform_name, 'UPDATE ' . $block_name];
    }
    elseif ($states === ['webform' => 'rollback', 'block' => 'rollback']) {
      $operations = [];
    }
    else {
      $fail('Safe rollback is available only from the exact installed or rollback state.');
    }
    $desired = [
      $webform_name => $rollback_webform,
      $block_name => $rollback_block,
    ];
  }

  if ($states['webform'] !== 'legacy' && $internal_aliases !== []) {
    $fail('Hardened Contact state must not retain standalone Webform aliases.');
  }
  echo 'STATE webform=' . $states['webform'] . ' block=' . $states['block'] . PHP_EOL;
  echo 'CONTACT PAGE OK nid=' . $contact_page['nid'] . ' alias=/contact (read-only)' . PHP_EOL;
  echo 'LEGACY WEBFORM ALIASES planned_removal=' . count($internal_aliases) . PHP_EOL;
  echo 'SUBMISSIONS retained_contact=' . $submissions['contact_count']
    . ' retained_all=' . $submissions['total_count'] . PHP_EOL;
  echo 'ACCESS OK administrator=is_admin; non-admin result access=none.' . PHP_EOL;
  echo 'LIMIT POLICY target=5 per UID/session-cookie per hour; 30 completed globally per hour.' . PHP_EOL;

  $fingerprint_data = [
    'action' => $action,
    'states' => $states,
    'operations' => $operations,
    'source_hashes' => $target_hashes,
    'config_hash' => $normalized_hash($all_config),
    'contact_page' => $contact_page,
    'internal_aliases' => $internal_aliases,
    'unrelated_aliases' => $unrelated_aliases,
    'submissions' => $submissions,
    'webform_runtime' => $webform_runtime,
    'webform_state' => $webform_state,
    'role_policy' => $role_policy,
  ];
  return [
    'states' => $states,
    'operations' => $operations,
    'desired' => $desired,
    'all_config' => $all_config,
    'contact_page' => $contact_page,
    'internal_aliases' => $internal_aliases,
    'unrelated_aliases' => $unrelated_aliases,
    'submissions' => $submissions,
    'webform_runtime' => $webform_runtime,
    'webform_state' => $webform_state,
    'fingerprint' => $normalized_hash($fingerprint_data),
  ];
};

$plan = $preflight();
$section('Immutable plan');
echo 'PLAN SHA-256 ' . $plan['fingerprint'] . PHP_EOL;
if ($plan['operations'] === []) {
  echo 'NOOP Active configuration already matches the requested state.' . PHP_EOL;
}
else {
  foreach ($plan['operations'] as $operation) {
    echo 'WOULD_' . $operation . PHP_EOL;
  }
}

if (!$is_apply) {
  echo 'DRY_RUN No configuration, content, alias or submission was changed.' . PHP_EOL;
  return;
}
if ($plan['operations'] === []) {
  echo 'APPLY_NOOP No lock or configuration write was necessary.' . PHP_EOL;
  return;
}

$connection = \Drupal::database();
if ($connection->inTransaction()) {
  $fail('Refusing to write inside an existing database transaction; writes=0.');
}
$persistent_lock = \Drupal::service('lock.persistent');
$import_lock_name = 'config_importer';
$feature_lock_name = 'unisonges.contact_form_mvp_2026';
$lock_ttl = 3600.0;
$import_lock_acquired = FALSE;
$feature_lock_acquired = FALSE;
$reset_entity_caches = static function () use ($entity_type_manager): void {
  foreach ([
    'webform',
    'block',
    'user_role',
    'path_alias',
    'node',
    'webform_submission',
  ] as $entity_type_id) {
    $entity_type_manager->getStorage($entity_type_id)->resetCache();
  }
};
$reset_runtime_caches = static function () use (
  $cached_config_storage,
  $reset_entity_caches,
  $webform_name,
  $block_name
): void {
  \Drupal::service('cache.config')->deleteMultiple([$webform_name, $block_name]);
  $cached_config_storage->resetListCache();
  \Drupal::state()->resetCache();
  \Drupal::service('config.factory')->reset($webform_name);
  \Drupal::service('config.factory')->reset($block_name);
  $reset_entity_caches();
};
$reset_failure_caches = static function () use (
  $cached_config_storage,
  $reset_entity_caches
): void {
  \Drupal::service('cache.config')->deleteAll();
  $cached_config_storage->resetListCache();
  \Drupal::state()->resetCache();
  \Drupal::service('config.factory')->reset();
  $reset_entity_caches();
};
$try_reset_runtime_caches = static function (bool $after_failure = FALSE) use (
  $reset_runtime_caches,
  $reset_failure_caches
): ?string {
  try {
    if ($after_failure) {
      $reset_failure_caches();
    }
    else {
      $reset_runtime_caches();
    }
    return NULL;
  }
  catch (Throwable $throwable) {
    return $throwable->getMessage();
  }
};
$dispose_transaction = static function (&$transaction): ?string {
  try {
    $transaction = NULL;
    return NULL;
  }
  catch (Throwable $throwable) {
    return $throwable->getMessage();
  }
};
$verify_rollback = static function (array $locked_plan) use (
  $connection,
  $try_reset_runtime_caches,
  $snapshot_all_config,
  $snapshot_submissions,
  $snapshot_unrelated_aliases,
  $inspect_internal_aliases,
  $resolve_contact_page,
  $snapshot_webform_runtime,
  $snapshot_webform_state,
  $canonicalize
): array {
  if ($connection->inTransaction()) {
    return [FALSE, 'the database transaction remains open'];
  }
  if (($reset_failure = $try_reset_runtime_caches(TRUE)) !== NULL) {
    return [FALSE, 'cache reset failed: ' . $reset_failure];
  }
  try {
    $mismatches = [];
    if ($canonicalize($snapshot_all_config())
      !== $canonicalize($locked_plan['all_config'])) {
      $mismatches[] = 'configuration';
    }
    if ($snapshot_submissions() !== $locked_plan['submissions']) {
      $mismatches[] = 'submissions';
    }
    if ($snapshot_unrelated_aliases() !== $locked_plan['unrelated_aliases']) {
      $mismatches[] = 'unrelated aliases';
    }
    if ($inspect_internal_aliases() !== $locked_plan['internal_aliases']) {
      $mismatches[] = 'Webform aliases';
    }
    if ($resolve_contact_page() !== $locked_plan['contact_page']) {
      $mismatches[] = '/contact page';
    }
    if ($snapshot_webform_runtime() !== $locked_plan['webform_runtime']) {
      $mismatches[] = 'Webform serial state';
    }
    if ($snapshot_webform_state() !== $locked_plan['webform_state']) {
      $mismatches[] = 'Webform state';
    }
    return $mismatches === []
      ? [TRUE, 'all protected snapshots match']
      : [FALSE, 'snapshot mismatch: ' . implode(', ', $mismatches)];
  }
  catch (Throwable $throwable) {
    return [FALSE, 'rollback verification failed: ' . $throwable->getMessage()];
  }
};

try {
  if (!$persistent_lock->acquire($import_lock_name, $lock_ttl)) {
    $fail('Could not acquire the persistent config-importer lock.');
  }
  $import_lock_acquired = TRUE;
  if (!$persistent_lock->acquire($feature_lock_name, $lock_ttl)) {
    $fail('Could not acquire the persistent Contact feature lock.');
  }
  $feature_lock_acquired = TRUE;

  if (($reset_failure = $try_reset_runtime_caches()) !== NULL) {
    $fail('Could not refresh active state for the locked preflight: ' . $reset_failure);
  }
  if ((int) \Drupal::state()->get('system.maintenance_mode', 0) !== 1) {
    $fail('Maintenance mode was disabled before the locked preflight; writes=0.');
  }
  $locked_plan = $preflight();
  if (!hash_equals($plan['fingerprint'], $locked_plan['fingerprint'])) {
    $fail('Active state changed after planning; release locks and rerun the dry-run.');
  }

  $section($action === 'install' ? 'Targeted apply transaction' : 'Safe rollback transaction');
  \Drupal::state()->resetCache();
  if ((int) \Drupal::state()->get('system.maintenance_mode', 0) !== 1) {
    $fail('Maintenance mode was disabled immediately before the transaction; writes=0.');
  }
  if ($connection->inTransaction()) {
    $fail('A database transaction appeared before the Contact write; writes=0.');
  }
  try {
    $transaction = $connection->startTransaction('unisonges_contact_form_mvp_2026');
    if (!$connection->inTransaction()) {
      throw new RuntimeException('Database connection did not enter a transaction.');
    }
  }
  catch (Throwable $throwable) {
    throw new RuntimeException(
      'Contact transaction could not start; writes=0. Cause: ' . $throwable->getMessage(),
      0,
      $throwable
    );
  }

  try {
    $expected_config = $locked_plan['all_config'];
    $expected_config[StorageInterface::DEFAULT_COLLECTION][$webform_name]
      = $locked_plan['desired'][$webform_name];
    $expected_config[StorageInterface::DEFAULT_COLLECTION][$block_name]
      = $locked_plan['desired'][$block_name];
    ksort($expected_config[StorageInterface::DEFAULT_COLLECTION], SORT_STRING);

    $webform_storage->resetCache([$webform_id]);
    $webform_entity = $webform_storage->loadOverrideFree($webform_id);
    if (!$webform_entity || $webform_entity->uuid() !== $webform_uuid) {
      $fail('Could not reload the exact Contact Webform before its write.');
    }
    $webform_entity->setSyncing(TRUE);
    $webform_entity = $webform_storage->updateFromStorageRecord(
      $webform_entity,
      $locked_plan['desired'][$webform_name]
    );
    echo 'UPDATE ' . $webform_name . PHP_EOL;
    $webform_entity->save();
    $webform_storage->resetCache([$webform_id]);

    $block_storage->resetCache([$block_id]);
    $block_entity = $block_storage->loadOverrideFree($block_id);
    if ($block_entity) {
      if ($block_entity->uuid() !== $block_uuid) {
        $fail('Contact block UUID changed immediately before its write.');
      }
      $block_entity->setSyncing(TRUE);
      $block_entity = $block_storage->updateFromStorageRecord(
        $block_entity,
        $locked_plan['desired'][$block_name]
      );
      echo 'UPDATE ' . $block_name . PHP_EOL;
    }
    else {
      if ($action !== 'install' || $locked_plan['states']['block'] !== 'missing') {
        $fail('Contact block disappeared immediately before its write.');
      }
      $block_entity = $block_storage->createFromStorageRecord(
        $locked_plan['desired'][$block_name]
      );
      $block_entity->setSyncing(TRUE);
      if ($block_entity->id() !== $block_id || $block_entity->uuid() !== $block_uuid) {
        $fail('Created Contact block identity does not match the reviewed source.');
      }
      echo 'CREATE ' . $block_name . PHP_EOL;
    }
    $block_entity->save();
    $block_storage->resetCache([$block_id]);
    $reset_runtime_caches();

    $written_webform = $config_storage->read($webform_name);
    $written_block = $config_storage->read($block_name);
    if (!is_array($written_webform)
      || !is_array($written_block)
      || $canonicalize($written_webform)
        !== $canonicalize($locked_plan['desired'][$webform_name])
      || $canonicalize($written_block)
        !== $canonicalize($locked_plan['desired'][$block_name])
      || $canonicalize(\Drupal::config($webform_name)->get())
        !== $canonicalize($locked_plan['desired'][$webform_name])
      || $canonicalize(\Drupal::config($block_name)->get())
        !== $canonicalize($locked_plan['desired'][$block_name])) {
      $fail('Post-write exact Contact config verification failed.');
    }
    if ($inspect_internal_aliases() !== []) {
      $fail('Standalone Contact Webform aliases remain after page=false was saved.');
    }
    if ($snapshot_unrelated_aliases() !== $locked_plan['unrelated_aliases']) {
      $fail('An unrelated path alias changed during the Contact transaction.');
    }
    if ($resolve_contact_page() !== $locked_plan['contact_page']) {
      $fail('The /contact page, alias or content changed during the Contact transaction.');
    }
    if ($snapshot_submissions() !== $locked_plan['submissions']) {
      $fail('A Webform submission changed during the Contact configuration transaction.');
    }
    if ($snapshot_webform_runtime() !== $locked_plan['webform_runtime']) {
      $fail('Webform serial-tracking state changed unexpectedly.');
    }
    if ($snapshot_webform_state() !== $locked_plan['webform_state']) {
      $fail('Webform state changed during the Contact configuration transaction.');
    }
    $written_all_config = $snapshot_all_config();
    if ($canonicalize($written_all_config) !== $canonicalize($expected_config)) {
      $fail('A non-allowlisted configuration object changed during the transaction.');
    }
    $assert_contact_namespace();
    $assert_role_policy();
  }
  catch (Throwable $throwable) {
    $rollback_failure = NULL;
    try {
      $transaction->rollBack();
    }
    catch (Throwable $caught_rollback_failure) {
      $rollback_failure = $caught_rollback_failure;
    }
    $transaction_disposal_failure = $dispose_transaction($transaction);
    if ($rollback_failure === NULL && $transaction_disposal_failure === NULL) {
      [$rollback_confirmed, $rollback_detail] = $verify_rollback($locked_plan);
    }
    else {
      $rollback_confirmed = FALSE;
      $rollback_detail = $rollback_failure
        ? 'rollback call failed: ' . $rollback_failure->getMessage()
        : 'transaction cleanup failed: ' . $transaction_disposal_failure;
      if ($transaction_disposal_failure !== NULL) {
        if ($rollback_failure) {
          $rollback_detail .= '; transaction cleanup failed: '
            . $transaction_disposal_failure;
        }
      }
      if (!$connection->inTransaction()
        && ($reset_failure = $try_reset_runtime_caches(TRUE)) !== NULL) {
        $rollback_detail .= '; cache reset failed: ' . $reset_failure;
      }
    }
    throw new RuntimeException(
      $rollback_confirmed
        ? 'Contact transaction failed; rollback confirmed. Cause: '
          . $throwable->getMessage() . '. Verification: ' . $rollback_detail
        : 'Contact transaction failed and rollback could not be confirmed. Cause: '
          . $throwable->getMessage()
          . '. Verification: ' . $rollback_detail,
      0,
      $throwable
    );
  }

  try {
    $transaction->commitOrRelease();
  }
  catch (Throwable $throwable) {
    $rollback_failure = NULL;
    $rollback_confirmed = FALSE;
    $rollback_requested = FALSE;
    if ($connection->inTransaction()) {
      try {
        $transaction->rollBack();
        $rollback_requested = TRUE;
      }
      catch (Throwable $caught_rollback_failure) {
        $rollback_failure = $caught_rollback_failure;
      }
    }
    $transaction_disposal_failure = $dispose_transaction($transaction);
    if ($rollback_requested
      && $rollback_failure === NULL
      && $transaction_disposal_failure === NULL
      && !$connection->inTransaction()) {
      [$rollback_confirmed, $rollback_detail] = $verify_rollback($locked_plan);
    }
    if (!$rollback_confirmed) {
      if ($rollback_failure) {
        $rollback_detail = 'rollback call failed: ' . $rollback_failure->getMessage();
      }
      elseif (!isset($rollback_detail)) {
        $rollback_detail = $rollback_requested
          ? 'rollback or transaction cleanup could not be confirmed'
          : 'the transaction was no longer open; commit state is unknown';
      }
      if ($transaction_disposal_failure !== NULL) {
        $rollback_detail .= '; transaction cleanup failed: '
          . $transaction_disposal_failure;
      }
    }
    if (!$rollback_confirmed) {
      if (!$connection->inTransaction()
        && ($reset_failure = $try_reset_runtime_caches(TRUE)) !== NULL) {
        $rollback_detail .= '; cache reset failed: ' . $reset_failure;
      }
    }
    throw new RuntimeException(
      $rollback_confirmed
        ? 'Contact transaction finalization failed before commit; rollback confirmed. Cause: '
          . $throwable->getMessage() . '. Verification: ' . $rollback_detail
        : 'Contact transaction finalization failed; commit state is unknown. Cause: '
          . $throwable->getMessage()
          . '. Detail: ' . $rollback_detail,
      0,
      $throwable
    );
  }
  if (($transaction_disposal_failure = $dispose_transaction($transaction)) !== NULL) {
    $reset_failure = $try_reset_runtime_caches();
    throw new RuntimeException(
      'Contact transaction committed successfully and the requested state is applied, '
      . 'but transaction cleanup failed: ' . $transaction_disposal_failure
      . ($reset_failure !== NULL ? '; cache cleanup also failed: ' . $reset_failure : '')
      . '.'
    );
  }
  if (($reset_failure = $try_reset_runtime_caches()) !== NULL) {
    throw new RuntimeException(
      'Contact transaction committed successfully and the requested state is applied, '
      . 'but cache cleanup failed: ' . $reset_failure . '.'
    );
  }
  try {
    $post_commit_plan = $preflight();
    $expected_states = $action === 'install'
      ? ['webform' => 'target', 'block' => 'target']
      : ['webform' => 'rollback', 'block' => 'rollback'];
    if ($post_commit_plan['states'] !== $expected_states
      || $post_commit_plan['operations'] !== []
      || $canonicalize($post_commit_plan['all_config']) !== $canonicalize($expected_config)
      || $post_commit_plan['contact_page'] !== $locked_plan['contact_page']
      || $post_commit_plan['internal_aliases'] !== []
      || $post_commit_plan['unrelated_aliases'] !== $locked_plan['unrelated_aliases']
      || $post_commit_plan['submissions'] !== $locked_plan['submissions']
      || $post_commit_plan['webform_runtime'] !== $locked_plan['webform_runtime']
      || $post_commit_plan['webform_state'] !== $locked_plan['webform_state']) {
      $fail('Committed Contact state does not match the exact protected plan.');
    }
  }
  catch (Throwable $throwable) {
    throw new RuntimeException(
      'Contact transaction committed successfully, but post-commit verification failed; '
      . 'treat the requested state as applied and audit it before retrying. Cause: '
      . $throwable->getMessage(),
      0,
      $throwable
    );
  }

  echo 'COMMIT Exact writable configuration count: 2' . PHP_EOL;
  echo 'SUBMISSIONS UNCHANGED contact=' . $locked_plan['submissions']['contact_count']
    . ' all=' . $locked_plan['submissions']['total_count'] . PHP_EOL;
  echo $action === 'rollback'
    ? 'OK Safe rollback: Webform closed, block disabled, submissions retained.' . PHP_EOL
    : 'OK Contact form MVP installed; rerun dry-run and the deferred runtime matrix.' . PHP_EOL;
}
finally {
  if ($feature_lock_acquired) {
    $persistent_lock->release($feature_lock_name);
  }
  if ($import_lock_acquired) {
    $persistent_lock->release($import_lock_name);
  }
}
