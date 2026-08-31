#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DRUPAL_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"
DRUPAL_ROOT="${DRUPAL_DIR}/web"
REPO_ROOT="$(cd -- "${DRUPAL_DIR}/.." && pwd -P)"
DRUSH="${DRUPAL_DIR}/vendor/bin/drush"

MODE="dry-run"
ACTION="apply-policy"
MODE_SEEN=0
ROLLBACK_SEEN=0
ALLOW_VPS=0
SITE_URI=""
EXPECT_FINGERPRINT=""
BACKUP_FILE=""

warn() {
  printf 'ERROR: %s\n' "$*" >&2
}

usage() {
  cat <<'EOF'
Usage:
  ./scripts/apply-sitemap-policy-2026.sh --site-uri=https://approved.example [--dry-run]
  ./scripts/apply-sitemap-policy-2026.sh --site-uri=https://approved.example --apply \
    --expect-fingerprint=<64-lowercase-hex> --backup-file=/absolute/new-backup.json
  ./scripts/apply-sitemap-policy-2026.sh --site-uri=https://approved.example --rollback \
    --expect-fingerprint=<64-lowercase-hex> --backup-file=/absolute/existing-backup.json [--apply]

Options:
  --dry-run                 Diagnose and print a deterministic plan. Default.
  --apply                   Execute the reviewed policy apply or rollback.
  --rollback                Restore the exact target-config snapshot in a backup.
                            Rollback is a dry-run unless --apply is also supplied.
  --site-uri=ORIGIN         Required absolute HTTP(S) site origin, with root path.
  --expect-fingerprint=SHA  Required by --apply and every rollback. It must equal
                            the current runtime fingerprint printed by dry-run.
  --backup-file=PATH        Normal apply: absolute, non-existing output path.
                            Rollback: absolute, existing 0600 backup from this tool.
  --allow-vps               Required for a reviewed checkout below /var/www.
  -h, --help                Show this help.

The script never imports configuration and never regenerates, purges, queues, or
submits a sitemap. It never changes content, aliases, or routes and never launches
a cache rebuild. Drupal configuration saves during apply/rollback may invalidate
normal cache tags. A normal apply changes only the reviewed Simple Sitemap keys
and config objects. Dry-run creates no helper file and makes no intentional
persistent Drupal, database, content, configuration, or filesystem write.
EOF
}

for argument in "$@"; do
  case "${argument}" in
    --dry-run)
      if (( MODE_SEEN )); then
        warn 'Choose exactly one of --dry-run and --apply.'
        exit 2
      fi
      MODE="dry-run"
      MODE_SEEN=1
      ;;
    --apply)
      if (( MODE_SEEN )); then
        warn 'Choose exactly one of --dry-run and --apply.'
        exit 2
      fi
      MODE="apply"
      MODE_SEEN=1
      ;;
    --rollback)
      if (( ROLLBACK_SEEN )); then
        warn '--rollback was supplied more than once.'
        exit 2
      fi
      ACTION="rollback"
      ROLLBACK_SEEN=1
      ;;
    --site-uri=*)
      if [[ -n "${SITE_URI}" ]]; then
        warn '--site-uri was supplied more than once.'
        exit 2
      fi
      SITE_URI="${argument#*=}"
      ;;
    --expect-fingerprint=*)
      if [[ -n "${EXPECT_FINGERPRINT}" ]]; then
        warn '--expect-fingerprint was supplied more than once.'
        exit 2
      fi
      EXPECT_FINGERPRINT="${argument#*=}"
      ;;
    --backup-file=*)
      if [[ -n "${BACKUP_FILE}" ]]; then
        warn '--backup-file was supplied more than once.'
        exit 2
      fi
      BACKUP_FILE="${argument#*=}"
      ;;
    --allow-vps)
      if (( ALLOW_VPS )); then
        warn '--allow-vps was supplied more than once.'
        exit 2
      fi
      ALLOW_VPS=1
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      warn "Unknown argument: ${argument}"
      usage >&2
      exit 2
      ;;
  esac
done

if [[ -z "${SITE_URI}" ]]; then
  warn '--site-uri is required.'
  exit 2
fi
if [[ ! "${SITE_URI}" =~ ^https?://[^/]+/?$ ]]; then
  warn '--site-uri must be an absolute HTTP(S) origin with no non-root path.'
  exit 2
fi

if [[ "${DRUPAL_DIR}" == "/var/www" || "${DRUPAL_DIR}" == /var/www/* ]]; then
  if (( ! ALLOW_VPS )); then
    warn "Refusing a /var/www checkout without --allow-vps: ${DRUPAL_DIR}"
    exit 1
  fi
  printf 'VPS_GUARD acknowledged for reviewed staging path; this is not production authorization.\n'
elif (( ALLOW_VPS )); then
  warn '--allow-vps is valid only for a reviewed checkout below /var/www.'
  exit 2
fi

if [[ "${ACTION}" == "rollback" ]]; then
  if [[ ! "${EXPECT_FINGERPRINT}" =~ ^[0-9a-f]{64}$ ]]; then
    warn '--rollback requires --expect-fingerprint with exactly 64 lowercase hexadecimal characters.'
    exit 2
  fi
  if [[ -z "${BACKUP_FILE}" || "${BACKUP_FILE}" != /* ]]; then
    warn '--rollback requires an absolute --backup-file path.'
    exit 2
  fi
  if [[ ! -f "${BACKUP_FILE}" || -L "${BACKUP_FILE}" ]]; then
    warn '--rollback backup must be an existing regular non-symlink file.'
    exit 2
  fi
elif [[ "${MODE}" == "apply" ]]; then
  if [[ ! "${EXPECT_FINGERPRINT}" =~ ^[0-9a-f]{64}$ ]]; then
    warn '--apply requires --expect-fingerprint with exactly 64 lowercase hexadecimal characters.'
    exit 2
  fi
  if [[ -z "${BACKUP_FILE}" || "${BACKUP_FILE}" != /* ]]; then
    warn '--apply requires an absolute --backup-file path.'
    exit 2
  fi
  if [[ -e "${BACKUP_FILE}" || -L "${BACKUP_FILE}" ]]; then
    warn '--backup-file must not exist before a normal apply.'
    exit 2
  fi
else
  if [[ -n "${EXPECT_FINGERPRINT}" || -n "${BACKUP_FILE}" ]]; then
    warn '--expect-fingerprint and --backup-file are apply-only, except with --rollback.'
    exit 2
  fi
fi

if [[ -n "${BACKUP_FILE}" ]]; then
  case "${BACKUP_FILE}" in
    *$'\n'*|*$'\r'*)
      warn '--backup-file contains a forbidden control character.'
      exit 2
      ;;
    */../*|*/./*|*/..|*/.)
      warn '--backup-file must be lexically normalized (no . or .. component).'
      exit 2
      ;;
  esac
  backup_parent="$(dirname -- "${BACKUP_FILE}")"
  if [[ ! -d "${backup_parent}" || -L "${backup_parent}" ]]; then
    warn 'The backup parent must be an existing non-symlink directory.'
    exit 2
  fi
  backup_parent_real="$(cd -- "${backup_parent}" && pwd -P)"
  if [[ "${backup_parent_real}/$(basename -- "${BACKUP_FILE}")" != "${BACKUP_FILE}" ]]; then
    warn '--backup-file must use the canonical absolute parent path.'
    exit 2
  fi
fi

if [[ ! -f "${DRUPAL_DIR}/composer.json" || ! -f "${DRUPAL_ROOT}/core/lib/Drupal.php" ]]; then
  warn "Drupal codebase not found at ${DRUPAL_DIR}."
  exit 1
fi
if [[ ! -x "${DRUSH}" ]]; then
  warn "Project Drush is missing or not executable: ${DRUSH}"
  exit 1
fi
if ! command -v git >/dev/null 2>&1; then
  warn 'git is required to verify reviewed source files.'
  exit 1
fi
if [[ "$(git -C "${REPO_ROOT}" rev-parse --show-toplevel)" != "${REPO_ROOT}" ]]; then
  warn 'Repository root verification failed.'
  exit 1
fi

reviewed_sources=(
  "drupal/scripts/apply-sitemap-policy-2026.sh"
  "drupal/config/sync/system.site.yml"
  "drupal/config/sync/simple_sitemap.settings.yml"
  "drupal/config/sync/simple_sitemap.type.default_hreflang.yml"
  "drupal/config/sync/simple_sitemap.custom_links.default.yml"
  "drupal/config/sync/simple_sitemap.sitemap.default.yml"
  "drupal/config/sync/simple_sitemap.sitemap.index.yml"
  "drupal/config/sync/simple_sitemap.type.index.yml"
  "drupal/web/robots.txt"
  "drupal/config/sync/simple_sitemap.bundle_settings.default.node.article.yml"
  "drupal/config/sync/simple_sitemap.bundle_settings.default.node.page.yml"
  "drupal/config/sync/simple_sitemap.bundle_settings.default.node.stage.yml"
  "drupal/config/sync/simple_sitemap.bundle_settings.default.node.concert.yml"
  "drupal/config/sync/simple_sitemap.bundle_settings.default.node.forum_topic.yml"
)

forum_feature_sources=(
  "drupal/config/sync/node.type.forum_topic.yml"
  "drupal/config/sync/core.base_field_override.node.forum_topic.status.yml"
  "drupal/config/sync/core.base_field_override.node.forum_topic.promote.yml"
  "drupal/config/sync/field.field.node.forum_topic.body.yml"
  "drupal/config/sync/field.field.node.forum_topic.comment.yml"
  "drupal/config/sync/core.entity_form_display.node.forum_topic.default.yml"
  "drupal/config/sync/core.entity_view_display.node.forum_topic.default.yml"
  "drupal/config/sync/core.entity_view_display.node.forum_topic.teaser.yml"
  "drupal/config/sync/views.view.blog_posts.yml"
  "drupal/config/sync/views.view.forum_topics.yml"
  "drupal/config/sync/webform.webform.forum_blog_proposal.yml"
  "drupal/config/sync/block.block.unisonges_blog_posts.yml"
  "drupal/config/sync/block.block.unisonges_forum_topics.yml"
  "drupal/config/sync/block.block.unisonges_forum_blog_proposal.yml"
)

forum_source_count=0
for source_path in "${forum_feature_sources[@]}"; do
  if [[ -e "${REPO_ROOT}/${source_path}" || -L "${REPO_ROOT}/${source_path}" ]]; then
    ((forum_source_count += 1))
    reviewed_sources+=("${source_path}")
  fi
done
if (( forum_source_count != 0 && forum_source_count != ${#forum_feature_sources[@]} )); then
  warn 'PR #80 Forum/Blog source files are only partially present; refusing ambiguous source state.'
  exit 1
fi
if (( forum_source_count == ${#forum_feature_sources[@]} )); then
  reviewed_sources+=(
    "drupal/config/sync/field.field.comment.comment.comment_body.yml"
    "drupal/web/modules/custom/unisonges_structure/unisonges_structure.module"
  )
fi

declare -A reviewed_source_seen=()
for source_path in "${reviewed_sources[@]}"; do
  if [[ -n "${reviewed_source_seen[${source_path}]+present}" ]]; then
    warn "Reviewed source was listed more than once: ${source_path}"
    exit 1
  fi
  reviewed_source_seen["${source_path}"]=1
  absolute_source="${REPO_ROOT}/${source_path}"
  if [[ ! -f "${absolute_source}" || -L "${absolute_source}" ]]; then
    warn "Reviewed source is missing, non-regular, or a symlink: ${source_path}"
    exit 1
  fi
  if ! git -C "${REPO_ROOT}" ls-files --error-unmatch -- "${source_path}" >/dev/null 2>&1; then
    warn "Reviewed source is not tracked by git: ${source_path}"
    exit 1
  fi
  if ! git -C "${REPO_ROOT}" diff --quiet -- "${source_path}" \
    || ! git -C "${REPO_ROOT}" diff --cached --quiet -- "${source_path}"; then
    warn "Reviewed source differs from HEAD: ${source_path}"
    exit 1
  fi
done

stream_embedded_php() {
  awk '
    BEGIN {
      start_count = 0
      end_count = 0
      emitting = 0
    }
    $0 == "__UNISONGES_SITEMAP_POLICY_PHP__" {
      if (start_count != 0 || end_count != 0) {
        exit 65
      }
      start_count = 1
      emitting = 1
      next
    }
    $0 == "__UNISONGES_SITEMAP_POLICY_PHP_END__" {
      if (start_count != 1 || end_count != 0 || emitting != 1) {
        exit 65
      }
      end_count = 1
      emitting = 0
      next
    }
    emitting == 1 {
      print
    }
    END {
      if (start_count != 1 || end_count != 1 || emitting != 0) {
        exit 65
      }
    }
  ' "${BASH_SOURCE[0]}"
}

runtime_env=(
  "UNISONGES_SITEMAP_POLICY_MODE=${MODE}"
  "UNISONGES_SITEMAP_POLICY_ACTION=${ACTION}"
  "UNISONGES_SITEMAP_POLICY_SITE_URI=${SITE_URI}"
  "UNISONGES_SITEMAP_POLICY_PROJECT_ROOT=${DRUPAL_DIR}"
)
if [[ -n "${EXPECT_FINGERPRINT}" ]]; then
  runtime_env+=("UNISONGES_SITEMAP_POLICY_EXPECT_FINGERPRINT=${EXPECT_FINGERPRINT}")
fi
if [[ -n "${BACKUP_FILE}" ]]; then
  runtime_env+=("UNISONGES_SITEMAP_POLICY_BACKUP_FILE=${BACKUP_FILE}")
fi

: <<'__UNISONGES_SITEMAP_POLICY_PHP_BLOCK__'
__UNISONGES_SITEMAP_POLICY_PHP__
declare(strict_types=1);

use Composer\InstalledVersions;
use Drupal\Core\Config\CachedStorage;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\DatabaseStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\Entity\Node;
use Symfony\Component\Yaml\Yaml;

$fail = static function (string $message): never {
  throw new RuntimeException($message);
};
$section = static function (string $title): void {
  echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
};
$mode = getenv('UNISONGES_SITEMAP_POLICY_MODE') ?: '';
$action = getenv('UNISONGES_SITEMAP_POLICY_ACTION') ?: '';
$expected_fingerprint = getenv('UNISONGES_SITEMAP_POLICY_EXPECT_FINGERPRINT') ?: '';
$backup_file = getenv('UNISONGES_SITEMAP_POLICY_BACKUP_FILE') ?: '';
$expected_site_uri = getenv('UNISONGES_SITEMAP_POLICY_SITE_URI') ?: '';
$expected_project_root = getenv('UNISONGES_SITEMAP_POLICY_PROJECT_ROOT') ?: '';

if (!in_array($mode, ['dry-run', 'apply'], TRUE)) {
  $fail('Invalid runtime mode.');
}
if (!in_array($action, ['apply-policy', 'rollback'], TRUE)) {
  $fail('Invalid runtime action.');
}
$is_apply = $mode === 'apply';
if (($is_apply || $action === 'rollback')
  && !preg_match('/^[0-9a-f]{64}$/D', $expected_fingerprint)) {
  $fail('A lowercase SHA-256 current fingerprint is required.');
}

$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
  if (!is_array($value)) {
    return $value;
  }
  if (array_is_list($value)) {
    return array_map($canonicalize, $value);
  }
  ksort($value, SORT_STRING);
  foreach ($value as $key => $item) {
    $value[$key] = $canonicalize($item);
  }
  return $value;
};
$canonical_json = static function (mixed $value) use ($canonicalize): string {
  return json_encode(
    $canonicalize($value),
    JSON_THROW_ON_ERROR
      | JSON_UNESCAPED_SLASHES
      | JSON_UNESCAPED_UNICODE
      | JSON_INVALID_UTF8_SUBSTITUTE
  );
};
$policy_fingerprint = static function (array $state) use ($canonical_json): string {
  unset($state['generated_chunks']);
  return hash('sha256', $canonical_json([
    'domain' => 'unisonges-sitemap-policy-concurrency-v1',
    'state' => $state,
  ]));
};
$generated_fingerprint = static function (array $state) use ($canonical_json, $fail): string {
  if (!isset($state['generated_chunks']) || !is_array($state['generated_chunks'])) {
    $fail('Cannot fingerprint missing generated sitemap state.');
  }
  return hash('sha256', $canonical_json([
    'domain' => 'unisonges-sitemap-generated-state-v1',
    'chunks' => $state['generated_chunks'],
  ]));
};

$project_root = dirname(\Drupal::root());
$reviewed_project_root = realpath($expected_project_root);
if ($reviewed_project_root === FALSE
  || $reviewed_project_root !== $project_root
  || realpath($project_root . '/web') !== \Drupal::root()) {
  $fail('Bootstrapped Drupal root differs from the reviewed checkout.');
}
$sync_dir = $project_root . '/config/sync';

$site_uri_parts = parse_url($expected_site_uri);
if (!is_array($site_uri_parts)
  || !in_array($site_uri_parts['scheme'] ?? NULL, ['http', 'https'], TRUE)
  || !is_string($site_uri_parts['host'] ?? NULL)
  || $site_uri_parts['host'] === ''
  || isset($site_uri_parts['user'])
  || isset($site_uri_parts['pass'])
  || isset($site_uri_parts['query'])
  || isset($site_uri_parts['fragment'])
  || !in_array($site_uri_parts['path'] ?? '', ['', '/'], TRUE)) {
  $fail('The site URI must be an absolute HTTP(S) origin with a root path.');
}
$expected_origin = strtolower(
  $site_uri_parts['scheme'] . '://' . $site_uri_parts['host']
  . (isset($site_uri_parts['port']) ? ':' . $site_uri_parts['port'] : '')
);
$active_origin = strtolower(\Drupal::request()->getSchemeAndHttpHost());
if (!hash_equals($expected_origin, $active_origin)) {
  $fail('Bootstrapped site origin does not match --site-uri.');
}

$cached_config_storage = \Drupal::service('config.storage');
if (get_class($cached_config_storage) !== CachedStorage::class) {
  $fail('Active configuration must use Drupal core CachedStorage exactly.');
}
try {
  $storage_property = new ReflectionProperty(CachedStorage::class, 'storage');
  $config_storage = $storage_property->getValue($cached_config_storage);
}
catch (ReflectionException $exception) {
  $fail('Cannot inspect the active configuration backend: ' . $exception->getMessage());
}
if (!$config_storage instanceof DatabaseStorage
  || $config_storage->getCollectionName() !== StorageInterface::DEFAULT_COLLECTION) {
  $fail('CachedStorage must directly wrap default-collection DatabaseStorage.');
}
try {
  $connection_property = new ReflectionProperty(DatabaseStorage::class, 'connection');
  $table_property = new ReflectionProperty(DatabaseStorage::class, 'table');
  $storage_connection = $connection_property->getValue($config_storage);
  $storage_table = $table_property->getValue($config_storage);
}
catch (ReflectionException $exception) {
  $fail('Cannot verify DatabaseStorage internals: ' . $exception->getMessage());
}
$database = \Drupal::database();
if ($storage_connection !== $database || $storage_table !== 'config') {
  $fail('Active configuration is not backed by this site connection and config table.');
}
foreach (['config', 'simple_sitemap', 'simple_sitemap_entity_overrides'] as $table) {
  if (!$database->schema()->tableExists($table)) {
    $fail('Required runtime table is missing: ' . $table . '.');
  }
}

$module_handler = \Drupal::moduleHandler();
if (!$module_handler->moduleExists('simple_sitemap')) {
  $fail('Simple XML Sitemap is not enabled.');
}
if (!InstalledVersions::isInstalled('drupal/simple_sitemap')) {
  $fail('Composer cannot identify drupal/simple_sitemap.');
}
$simple_sitemap_version = ltrim((string) InstalledVersions::getPrettyVersion('drupal/simple_sitemap'), 'v');
if ($simple_sitemap_version !== '4.2.3') {
  $fail('This policy supports only the repository-locked Simple XML Sitemap 4.2.3 runtime.');
}
$required_api = [
  \Drupal\simple_sitemap\Manager\EntityManager::class => [
    'getAllBundleSettings',
    'getEntityInstanceSettings',
  ],
  \Drupal\simple_sitemap\Manager\CustomLinkManager::class => ['get'],
  \Drupal\simple_sitemap\Plugin\simple_sitemap\UrlGenerator\CustomUrlGenerator::class => ['getDataSets'],
  \Drupal\simple_sitemap\Entity\SimpleSitemap::class => ['loadMultiple'],
];
foreach ($required_api as $class => $methods) {
  if (!class_exists($class)) {
    $fail('Required Simple Sitemap API class is unavailable: ' . $class . '.');
  }
  foreach ($methods as $method) {
    if (!method_exists($class, $method)) {
      $fail('Required Simple Sitemap API method is unavailable: ' . $class . '::' . $method . '().');
    }
  }
}
if (!\Drupal::hasService('simple_sitemap.custom_link_manager')
  || !\Drupal::hasService('plugin.manager.simple_sitemap.url_generator')) {
  $fail('Required Simple Sitemap services are unavailable.');
}
$url_generator_manager = \Drupal::service('plugin.manager.simple_sitemap.url_generator');
if (!$url_generator_manager->hasDefinition('custom')
  || !$url_generator_manager->hasDefinition('entity')) {
  $fail('Required custom/entity URL generators are unavailable.');
}

$read_yaml = static function (string $filename) use ($sync_dir, $fail): array {
  $path = $sync_dir . '/' . $filename;
  $real = realpath($path);
  if ($real === FALSE
    || $real !== $path
    || !is_file($real)
    || is_link($path)
    || !str_starts_with($real, $sync_dir . '/')) {
    $fail('Reviewed YAML source is missing or unsafe: ' . $filename . '.');
  }
  try {
    $parsed = Yaml::parseFile($real, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
  }
  catch (Throwable $throwable) {
    $fail('Cannot parse reviewed YAML source ' . $filename . ': ' . $throwable->getMessage());
  }
  if (!is_array($parsed)) {
    $fail('Reviewed YAML source is not a mapping: ' . $filename . '.');
  }
  return $parsed;
};
$hash_source = static function (string $relative_path) use ($project_root, $fail): string {
  $path = $project_root . '/' . $relative_path;
  $real = realpath($path);
  if ($real === FALSE || $real !== $path || !is_file($real) || is_link($path)) {
    $fail('Reviewed source is missing or unsafe: ' . $relative_path . '.');
  }
  $hash = hash_file('sha256', $real);
  if (!is_string($hash) || !preg_match('/^[0-9a-f]{64}$/D', $hash)) {
    $fail('Cannot hash reviewed source: ' . $relative_path . '.');
  }
  return $hash;
};

$required_custom_paths = [
  '/accueil',
  '/cours-et-stages',
  '/cours',
  '/cours/didgeridoo',
  '/cours/guimbarde',
  '/cours/meditation-improvisation',
  '/stages',
  '/stages/didgeridoo',
  '/stages/musique-improvisee-meditation',
  '/stages/speciaux',
  '/concerts',
  '/ateliers',
  '/djam',
  '/orchestre-des-reveurs',
  '/a-propos',
  '/association',
  '/les-artistes-de-l-asso',
  '/origine',
  '/services-prestations-artistiques',
  '/contact',
];
$optional_custom_paths = ['/blog', '/forum'];
$all_custom_paths = array_merge($required_custom_paths, $optional_custom_paths);
$forbidden_path_pattern = '#^(?:/(?:admin|user|cart|checkout|order|payment|webform|form|reserver|product)(?:/|$)|/reservation(?:[-/]|$)|/node/[1-9][0-9]*(?:/|$))#D';

$settings_source = $read_yaml('simple_sitemap.settings.yml');
$type_source = $read_yaml('simple_sitemap.type.default_hreflang.yml');
$custom_source = $read_yaml('simple_sitemap.custom_links.default.yml');
$system_site_source = $read_yaml('system.site.yml');
$sitemap_default_source = $read_yaml('simple_sitemap.sitemap.default.yml');
$sitemap_index_source = $read_yaml('simple_sitemap.sitemap.index.yml');
$type_index_source = $read_yaml('simple_sitemap.type.index.yml');

if (($settings_source['enabled_entity_types'] ?? NULL) !== ['node']) {
  $fail('Tracked enabled_entity_types must be exactly [node].');
}
if (($type_source['url_generators'] ?? NULL) !== ['custom', 'entity']) {
  $fail('Tracked URL generators must be exactly [custom, entity].');
}
if (($system_site_source['uuid'] ?? '') === ''
  || ($system_site_source['page']['front'] ?? NULL) !== '/accueil') {
  $fail('Tracked system.site must have a UUID and front=/accueil.');
}
if (($sitemap_default_source['id'] ?? NULL) !== 'default'
  || ($sitemap_default_source['type'] ?? NULL) !== 'default_hreflang'
  || ($sitemap_default_source['status'] ?? NULL) !== TRUE
  || ($sitemap_index_source['id'] ?? NULL) !== 'index'
  || ($sitemap_index_source['type'] ?? NULL) !== 'index'
  || ($sitemap_index_source['status'] ?? NULL) !== FALSE) {
  $fail('Tracked sitemap variants do not encode default-only enabled policy.');
}

$source_links = $custom_source['links'] ?? NULL;
if (!is_array($source_links) || !array_is_list($source_links)) {
  $fail('Tracked custom links must be a YAML sequence.');
}
$source_link_paths = [];
foreach ($source_links as $position => $link) {
  if (!is_array($link)
    || array_keys($link) !== ['path', 'priority', 'changefreq']
    || !is_string($link['path'])
    || !is_string($link['priority'])
    || !is_string($link['changefreq'])) {
    $fail('Malformed tracked custom link at position ' . $position . '.');
  }
  $expected_priority = $link['path'] === '/accueil' ? '1.0' : '0.5';
  if ($link['priority'] !== $expected_priority || $link['changefreq'] !== '') {
    $fail('Tracked custom link metadata is not conservative for ' . $link['path'] . '.');
  }
  if (preg_match($forbidden_path_pattern, $link['path'])) {
    $fail('Tracked custom links contain a forbidden utility/transactional path.');
  }
  if (isset($source_link_paths[$link['path']])) {
    $fail('Tracked custom links contain a duplicate path: ' . $link['path'] . '.');
  }
  $source_link_paths[$link['path']] = TRUE;
}
if (array_keys($source_link_paths) !== $all_custom_paths) {
  $fail('Tracked custom-link allowlist differs from the reviewed ordered policy.');
}

$bundle_source_files = [
  'article' => 'simple_sitemap.bundle_settings.default.node.article.yml',
  'page' => 'simple_sitemap.bundle_settings.default.node.page.yml',
  'stage' => 'simple_sitemap.bundle_settings.default.node.stage.yml',
  'concert' => 'simple_sitemap.bundle_settings.default.node.concert.yml',
  'forum_topic' => 'simple_sitemap.bundle_settings.default.node.forum_topic.yml',
];
$bundle_sources = [];
foreach ($bundle_source_files as $bundle => $filename) {
  $source = $read_yaml($filename);
  if (array_keys($source) !== ['index', 'priority', 'changefreq', 'include_images']
    || !is_bool($source['index'])
    || !is_string($source['priority'])
    || !is_string($source['changefreq'])
    || !is_bool($source['include_images'])
    || !preg_match('/^(?:0(?:\.[0-9])?|1(?:\.0)?)$/D', $source['priority'])
    || $source['changefreq'] !== ''
    || $source['include_images'] !== FALSE) {
    $fail('Malformed or non-conservative tracked bundle policy: ' . $filename . '.');
  }
  if (($bundle === 'page' && $source['index'] !== FALSE)
    || ($bundle !== 'page' && $source['index'] !== TRUE)) {
    $fail('Tracked bundle index decision is incorrect for ' . $bundle . '.');
  }
  $bundle_sources[$bundle] = $source;
}

$source_hashes = [];
foreach (array_merge(
  [
    'scripts/apply-sitemap-policy-2026.sh',
    'config/sync/system.site.yml',
    'config/sync/simple_sitemap.settings.yml',
    'config/sync/simple_sitemap.type.default_hreflang.yml',
    'config/sync/simple_sitemap.custom_links.default.yml',
    'config/sync/simple_sitemap.sitemap.default.yml',
    'config/sync/simple_sitemap.sitemap.index.yml',
    'config/sync/simple_sitemap.type.index.yml',
    'web/robots.txt',
  ],
  array_map(static fn(string $file): string => 'config/sync/' . $file, array_values($bundle_source_files))
) as $relative_source) {
  $source_hashes[$relative_source] = $hash_source($relative_source);
}

$forum_feature_config_names = [
  'node.type.forum_topic',
  'core.base_field_override.node.forum_topic.status',
  'core.base_field_override.node.forum_topic.promote',
  'field.field.node.forum_topic.body',
  'field.field.node.forum_topic.comment',
  'core.entity_form_display.node.forum_topic.default',
  'core.entity_view_display.node.forum_topic.default',
  'core.entity_view_display.node.forum_topic.teaser',
  'views.view.blog_posts',
  'views.view.forum_topics',
  'webform.webform.forum_blog_proposal',
  'block.block.unisonges_blog_posts',
  'block.block.unisonges_forum_topics',
  'block.block.unisonges_forum_blog_proposal',
];
$forum_source_presence = [];
foreach ($forum_feature_config_names as $config_name) {
  $path = $sync_dir . '/' . $config_name . '.yml';
  $forum_source_presence[$config_name] = is_file($path) && !is_link($path);
}
$forum_source_count = count(array_filter($forum_source_presence));
if (!in_array($forum_source_count, [0, count($forum_feature_config_names)], TRUE)) {
  $fail('PR #80 tracked configuration is partially present.');
}
$forum_sources = [];
if ($forum_source_count === count($forum_feature_config_names)) {
  foreach ($forum_feature_config_names as $config_name) {
    $forum_sources[$config_name] = $read_yaml($config_name . '.yml');
    $source_hashes['config/sync/' . $config_name . '.yml'] = $hash_source(
      'config/sync/' . $config_name . '.yml'
    );
  }
  foreach ([
    'config/sync/field.field.comment.comment.comment_body.yml',
    'web/modules/custom/unisonges_structure/unisonges_structure.module',
  ] as $support_source) {
    $source_hashes[$support_source] = $hash_source($support_source);
  }
}
ksort($source_hashes, SORT_STRING);

$snapshot_all_config = static function () use ($config_storage, $fail): array {
  $collections = array_values(array_unique(array_merge(
    [StorageInterface::DEFAULT_COLLECTION],
    $config_storage->getAllCollectionNames()
  )));
  sort($collections, SORT_STRING);
  $snapshot = [];
  foreach ($collections as $collection) {
    $storage = $collection === StorageInterface::DEFAULT_COLLECTION
      ? $config_storage
      : $config_storage->createCollection($collection);
    $names = $storage->listAll();
    sort($names, SORT_STRING);
    $values = $names === [] ? [] : $storage->readMultiple($names);
    foreach ($names as $name) {
      if (!isset($values[$name]) || !is_array($values[$name])) {
        $fail('Cannot snapshot active configuration ' . $collection . ':' . $name . '.');
      }
      $snapshot[$collection][$name] = $values[$name];
    }
    $snapshot[$collection] ??= [];
    ksort($snapshot[$collection], SORT_STRING);
  }
  return $snapshot;
};

$assert_config_schema = static function (array $settings, string $context) use ($fail): void {
  $keys = array_keys($settings);
  sort($keys, SORT_STRING);
  $expected_keys = ['changefreq', 'include_images', 'index', 'priority'];
  sort($expected_keys, SORT_STRING);
  if ($keys !== $expected_keys
    || !is_bool($settings['index'])
    || !is_string($settings['priority'])
    || !preg_match('/^(?:0(?:\.[0-9])?|1(?:\.0)?)$/D', $settings['priority'])
    || !is_string($settings['changefreq'])
    || !in_array($settings['changefreq'], ['', 'always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'], TRUE)
    || !is_bool($settings['include_images'])) {
    $fail('Malformed sitemap inclusion settings: ' . $context . '.');
  }
};

$config_factory = \Drupal::configFactory();
$entity_type_manager = \Drupal::entityTypeManager();
$path_validator = \Drupal::service('path.validator');
$alias_manager = \Drupal::service('path_alias.manager');
$anonymous = new AnonymousUserSession();
$default_language = \Drupal::languageManager()->getDefaultLanguage()->getId();

$inspect_state = static function () use (
  $config_storage,
  $config_factory,
  $database,
  $entity_type_manager,
  $path_validator,
  $alias_manager,
  $anonymous,
  $default_language,
  $active_origin,
  $simple_sitemap_version,
  $source_hashes,
  $system_site_source,
  $settings_source,
  $type_source,
  $custom_source,
  $sitemap_default_source,
  $sitemap_index_source,
  $type_index_source,
  $bundle_sources,
  $required_custom_paths,
  $optional_custom_paths,
  $all_custom_paths,
  $forum_feature_config_names,
  $forum_sources,
  $forum_source_count,
  $assert_config_schema,
  $canonicalize,
  $fail
): array {
  $active_site = $config_storage->read('system.site');
  if (!is_array($active_site)
    || !is_string($active_site['uuid'] ?? NULL)
    || !hash_equals((string) $system_site_source['uuid'], $active_site['uuid'])
    || ($active_site['page']['front'] ?? NULL) !== '/accueil') {
    $fail('Active site UUID/front does not match tracked system.site (UUID is intentionally not printed).');
  }
  $effective_site = $config_factory->get('system.site');
  if ($effective_site->get('uuid') !== $active_site['uuid']
    || $effective_site->get('page.front') !== '/accueil') {
    $fail('Runtime overrides alter the active UUID/front policy.');
  }

  $forum_active = [];
  foreach ($forum_feature_config_names as $config_name) {
    $forum_active[$config_name] = $config_storage->exists($config_name);
  }
  $forum_active_count = count(array_filter($forum_active));
  if (!in_array($forum_active_count, [0, count($forum_feature_config_names)], TRUE)) {
    $fail('PR #80 active configuration is partially present.');
  }
  if ($forum_source_count === 0 && $forum_active_count !== 0) {
    $fail('Active PR #80 configuration exists without its reviewed tracked sources.');
  }
  $forum_ready = $forum_source_count === count($forum_feature_config_names)
    && $forum_active_count === count($forum_feature_config_names);
  if ($forum_ready) {
    foreach ($forum_feature_config_names as $config_name) {
      $active = $config_storage->read($config_name);
      if (!is_array($active)
        || $canonicalize($active) !== $canonicalize($forum_sources[$config_name])) {
        $fail('Active PR #80 config differs from its reviewed source: ' . $config_name . '.');
      }
    }
    $comment_body = $config_storage->read('field.field.comment.comment.comment_body');
    if (!is_array($comment_body)
      || ($comment_body['settings']['allowed_formats'] ?? NULL) !== ['basic_html']) {
      $fail('PR #80 comment sanitization is not active.');
    }
    foreach ([
      'unisonges_structure_node_access',
      'unisonges_structure_entity_field_access',
      'unisonges_structure_views_query_alter',
    ] as $hook_function) {
      if (!function_exists($hook_function)) {
        $fail('PR #80 access hook is unavailable; rebuild caches after deploying its code.');
      }
    }
    $synthetic_unpublished = Node::create([
      'type' => 'forum_topic',
      'title' => 'Sitemap policy access probe',
      'status' => FALSE,
      'uid' => 0,
    ]);
    if (!$synthetic_unpublished->access('view', $anonymous, TRUE)->isForbidden()) {
      $fail('An unpublished synthetic forum topic is not explicitly forbidden to anonymous access.');
    }
  }

  $node_type_ids = array_keys($entity_type_manager->getStorage('node_type')->loadMultiple());
  sort($node_type_ids, SORT_STRING);
  $expected_node_types = ['article', 'concert', 'page', 'stage'];
  if ($forum_ready) {
    $expected_node_types[] = 'forum_topic';
  }
  sort($expected_node_types, SORT_STRING);
  if ($node_type_ids !== $expected_node_types) {
    $fail('Active node bundle inventory is unexpected or PR #80 is only partially active.');
  }

  $base_sitemap_names = [
    'simple_sitemap.custom_links.default',
    'simple_sitemap.settings',
    'simple_sitemap.sitemap.default',
    'simple_sitemap.sitemap.index',
    'simple_sitemap.type.default_hreflang',
    'simple_sitemap.type.index',
  ];
  $allowed_bundle_names = [
    'simple_sitemap.bundle_settings.default.node.article',
    'simple_sitemap.bundle_settings.default.node.page',
    'simple_sitemap.bundle_settings.default.node.stage',
    'simple_sitemap.bundle_settings.default.node.concert',
  ];
  if ($forum_ready) {
    $allowed_bundle_names[] = 'simple_sitemap.bundle_settings.default.node.forum_topic';
  }
  $allowed_simple_sitemap_names = array_merge($base_sitemap_names, $allowed_bundle_names);
  sort($allowed_simple_sitemap_names, SORT_STRING);
  $simple_sitemap_names = $config_storage->listAll('simple_sitemap.');
  sort($simple_sitemap_names, SORT_STRING);
  foreach ($simple_sitemap_names as $config_name) {
    if (!in_array($config_name, $allowed_simple_sitemap_names, TRUE)) {
      $fail('Unexpected active Simple Sitemap config object: ' . $config_name . '.');
    }
  }
  foreach ($base_sitemap_names as $config_name) {
    if (!$config_storage->exists($config_name)) {
      $fail('Required active Simple Sitemap config is missing: ' . $config_name . '.');
    }
  }
  foreach ($simple_sitemap_names as $config_name) {
    $raw_values = $config_storage->read($config_name);
    $effective_values = $config_factory->get($config_name)->get();
    if (!is_array($raw_values)
      || !is_array($effective_values)
      || $canonicalize($effective_values) !== $canonicalize($raw_values)) {
      $fail('Runtime override changes active Simple Sitemap config ' . $config_name . '.');
    }
  }

  $variant_names = $config_storage->listAll('simple_sitemap.sitemap.');
  sort($variant_names, SORT_STRING);
  if ($variant_names !== ['simple_sitemap.sitemap.default', 'simple_sitemap.sitemap.index']) {
    $fail('Only default and disabled index sitemap variants may exist.');
  }
  $active_default = $config_storage->read('simple_sitemap.sitemap.default');
  $active_index = $config_storage->read('simple_sitemap.sitemap.index');
  if (!is_array($active_default)
    || !is_array($active_index)
    || ($active_default['id'] ?? NULL) !== 'default'
    || ($active_default['type'] ?? NULL) !== 'default_hreflang'
    || ($active_default['status'] ?? NULL) !== TRUE
    || ($active_index['id'] ?? NULL) !== 'index'
    || ($active_index['type'] ?? NULL) !== 'index'
    || ($active_index['status'] ?? NULL) !== FALSE) {
    $fail('Active sitemap variants are not default-only enabled with index disabled.');
  }
  $active_type_index = $config_storage->read('simple_sitemap.type.index');
  if (!is_array($active_type_index)
    || $canonicalize($active_default) !== $canonicalize($sitemap_default_source)
    || $canonicalize($active_index) !== $canonicalize($sitemap_index_source)
    || $canonicalize($active_type_index) !== $canonicalize($type_index_source)) {
    $fail('Untargeted sitemap default/index variant or index-type config differs from tracked source.');
  }

  $active_settings = $config_storage->read('simple_sitemap.settings');
  $active_type = $config_storage->read('simple_sitemap.type.default_hreflang');
  $active_custom = $config_storage->read('simple_sitemap.custom_links.default');
  if (!is_array($active_settings) || !is_array($active_type) || !is_array($active_custom)) {
    $fail('Core active Simple Sitemap policy configs are unreadable.');
  }
  foreach ([
    [$active_settings, $settings_source, 'enabled_entity_types', 'simple_sitemap.settings'],
    [$active_type, $type_source, 'url_generators', 'simple_sitemap.type.default_hreflang'],
    [$active_custom, $custom_source, 'links', 'simple_sitemap.custom_links.default'],
  ] as [$active_values, $source_values, $target_key, $config_name]) {
    unset($active_values[$target_key], $source_values[$target_key]);
    if ($canonicalize($active_values) !== $canonicalize($source_values)) {
      $fail('Untargeted active config drift differs from tracked source: ' . $config_name . '.');
    }
  }

  $active_bundle_names = $config_storage->listAll('simple_sitemap.bundle_settings.');
  sort($active_bundle_names, SORT_STRING);
  foreach ($active_bundle_names as $config_name) {
    if (!in_array($config_name, $allowed_bundle_names, TRUE)) {
      $fail('Unexpected sitemap variant/entity/bundle config: ' . $config_name . '.');
    }
    $values = $config_storage->read($config_name);
    if (!is_array($values)) {
      $fail('Cannot read active bundle config ' . $config_name . '.');
    }
    $assert_config_schema($values, $config_name);
  }

  $nondefault_simple_sitemap = [];
  $collections = $config_storage->getAllCollectionNames();
  sort($collections, SORT_STRING);
  foreach ($collections as $collection) {
    $collection_storage = $config_storage->createCollection($collection);
    $names = $collection_storage->listAll('simple_sitemap.');
    sort($names, SORT_STRING);
    foreach ($names as $name) {
      $values = $collection_storage->read($name);
      if (!is_array($values)) {
        $fail('Cannot read nondefault Simple Sitemap config ' . $collection . ':' . $name . '.');
      }
      if (str_starts_with($name, 'simple_sitemap.bundle_settings.')
        || ($name === 'simple_sitemap.settings' && array_key_exists('enabled_entity_types', $values))
        || ($name === 'simple_sitemap.type.default_hreflang' && array_key_exists('url_generators', $values))
        || ($name === 'simple_sitemap.custom_links.default' && array_key_exists('links', $values))) {
        $fail('Nondefault collection overrides sitemap policy: ' . $collection . ':' . $name . '.');
      }
      $nondefault_simple_sitemap[$collection][$name] = $values;
    }
  }

  $alias_storage = $entity_type_manager->getStorage('path_alias');
  $node_storage = $entity_type_manager->getStorage('node');
  $route_state = [];
  $page_node_uuids = [];
  $resolve_page = static function (string $path) use (
    $alias_storage,
    $node_storage,
    $path_validator,
    $alias_manager,
    $anonymous,
    $default_language,
    $fail
  ): array {
    $alias_ids = $alias_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('alias', $path)
      ->execute();
    if (count($alias_ids) !== 1) {
      $fail('Custom path must have exactly one PathAlias entity: ' . $path . '.');
    }
    $alias = $alias_storage->load(reset($alias_ids));
    if (!$alias || !preg_match('#^/node/([1-9][0-9]*)$#D', (string) $alias->getPath(), $matches)) {
      $fail('Custom path must alias one canonical node path: ' . $path . '.');
    }
    $node = $node_storage->load($matches[1]);
    if (!$node
      || $node->bundle() !== 'page'
      || !$node->isPublished()
      || !$node->access('view', $anonymous, TRUE)->isAllowed()) {
      $fail('Custom path must resolve to a published anonymously accessible Basic page: ' . $path . '.');
    }
    $url_without_access = $path_validator->getUrlIfValidWithoutAccessCheck($path);
    if (!$url_without_access
      || !$url_without_access->isRouted()
      || !$url_without_access->access($anonymous, TRUE)->isAllowed()) {
      $fail('Custom generator path/access validation failed: ' . $path . '.');
    }
    $parameters = $url_without_access->getRouteParameters();
    $parameter_id = $parameters['node'] ?? NULL;
    if (is_object($parameter_id) && method_exists($parameter_id, 'id')) {
      $parameter_id = $parameter_id->id();
    }
    if ((string) $parameter_id !== (string) $node->id()) {
      $fail('Custom route resolves to an unexpected node: ' . $path . '.');
    }
    $internal_path = '/node/' . $node->id();
    if ($alias_manager->getAliasByPath($internal_path, $default_language) !== $path
      || $node->toUrl('canonical')->toString() !== $path) {
      $fail('Custom path is not the exact active canonical alias: ' . $path . '.');
    }
    return [
      'path' => $path,
      'node_uuid' => $node->uuid(),
      'alias_uuid' => $alias->uuid(),
      'published' => TRUE,
      'anonymous_access' => TRUE,
    ];
  };
  foreach ($required_custom_paths as $path) {
    $resolved = $resolve_page($path);
    if (isset($page_node_uuids[$resolved['node_uuid']])) {
      $fail('Two required custom paths resolve to the same Basic page.');
    }
    $page_node_uuids[$resolved['node_uuid']] = $path;
    $route_state[$path] = $resolved;
  }
  $optional_alias_counts = [];
  foreach ($optional_custom_paths as $path) {
    $optional_alias_counts[$path] = count($alias_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('alias', $path)
      ->execute());
  }
  if ($optional_alias_counts['/blog'] === 0 && $optional_alias_counts['/forum'] === 0) {
    foreach ($optional_custom_paths as $path) {
      if ($path_validator->getUrlIfValidWithoutAccessCheck($path) !== NULL) {
        $fail('Deferred optional path already resolves without its required Basic-page alias: ' . $path . '.');
      }
      $route_state[$path] = ['path' => $path, 'state' => 'deferred'];
    }
    $future_routes_ready = FALSE;
  }
  else {
    if ($optional_alias_counts['/blog'] !== 1 || $optional_alias_counts['/forum'] !== 1) {
      $fail('/blog and /forum aliases must be absent together or uniquely valid together.');
    }
    foreach ($optional_custom_paths as $path) {
      $resolved = $resolve_page($path);
      if (isset($page_node_uuids[$resolved['node_uuid']])) {
        $fail('Optional custom path reuses another curated Basic page.');
      }
      $page_node_uuids[$resolved['node_uuid']] = $path;
      $route_state[$path] = $resolved;
    }
    $future_routes_ready = TRUE;
  }

  $override_count = (int) $database->select('simple_sitemap_entity_overrides', 'o')
    ->countQuery()
    ->execute()
    ->fetchField();
  if ($override_count !== 0) {
    $fail(
      'simple_sitemap_entity_overrides must contain zero rows; per-entity drift is '
      . 'non-reproducible and this script never deletes or changes it.'
    );
  }

  $dynamic_bundles = ['article', 'concert', 'stage'];
  if ($forum_ready) {
    $dynamic_bundles[] = 'forum_topic';
  }
  $node_ids = $node_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', $dynamic_bundles, 'IN')
    ->sort('nid')
    ->execute();
  $dynamic_nodes = [];
  $dynamic_counts = [];
  $dynamic_aliases = [];
  foreach ($dynamic_bundles as $bundle) {
    $dynamic_counts[$bundle] = ['included' => 0, 'excluded' => 0];
  }
  foreach ($node_storage->loadMultiple($node_ids) as $node) {
    $published = $node->isPublished();
    $anonymous_access = $node->access('view', $anonymous, TRUE)->isAllowed();
    if (!$published && $anonymous_access) {
      $fail('An unpublished ' . $node->bundle() . ' node is anonymously viewable; sitemap policy fails closed.');
    }
    $included = $published && $anonymous_access;
    $dynamic_counts[$node->bundle()][$included ? 'included' : 'excluded']++;
    $canonical = NULL;
    $alias_uuid = NULL;
    if ($included) {
      $canonical_url = $node->toUrl('canonical');
      if (!$canonical_url->isRouted()
        || !$canonical_url->access($anonymous, TRUE)->isAllowed()) {
        $fail('Published accessible ' . $node->bundle() . ' canonical route is not anonymously accessible.');
      }
      $canonical = $canonical_url->toString();
      $canonical_parts = parse_url($canonical);
      if (!is_array($canonical_parts)
        || !is_string($canonical_parts['path'] ?? NULL)
        || ($canonical_parts['path'] ?? '') === ''
        || isset($canonical_parts['scheme'])
        || isset($canonical_parts['host'])
        || isset($canonical_parts['user'])
        || isset($canonical_parts['pass'])
        || isset($canonical_parts['query'])
        || isset($canonical_parts['fragment'])
        || $canonical !== $canonical_parts['path']
        || preg_match('#^/node/[1-9][0-9]*$#D', $canonical)) {
        $fail(
          'Published accessible ' . $node->bundle()
          . ' content must have one nonnumeric internal canonical alias without query or fragment.'
        );
      }
      if (isset($dynamic_aliases[$canonical]) || in_array($canonical, $all_custom_paths, TRUE)) {
        $fail('Published dynamic canonical alias is duplicated or collides with a curated Basic page: ' . $canonical . '.');
      }
      $alias_ids = $alias_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('alias', $canonical)
        ->condition('path', '/node/' . $node->id())
        ->execute();
      if (count($alias_ids) !== 1
        || $alias_manager->getAliasByPath('/node/' . $node->id(), $default_language) !== $canonical) {
        $fail('Published dynamic canonical must be backed by exactly one active PathAlias: ' . $canonical . '.');
      }
      $canonical_alias = $alias_storage->load(reset($alias_ids));
      if (!$canonical_alias) {
        $fail('Canonical PathAlias disappeared during dynamic-node inspection.');
      }
      $alias_uuid = $canonical_alias->uuid();
      $dynamic_aliases[$canonical] = $node->uuid();
    }
    $dynamic_nodes[] = [
      'uuid' => $node->uuid(),
      'bundle' => $node->bundle(),
      'published' => $published,
      'anonymous_access' => $anonymous_access,
      'canonical' => $canonical,
      'alias_uuid' => $alias_uuid,
    ];
  }

  $generated_chunks = [];
  $generated_rows = $database->select('simple_sitemap', 's')
    ->fields('s', ['id', 'type', 'delta', 'sitemap_string', 'sitemap_created', 'status', 'link_count'])
    ->orderBy('type')
    ->orderBy('delta')
    ->orderBy('id')
    ->execute()
    ->fetchAll();
  foreach ($generated_rows as $row) {
    $generated_chunks[] = [
      'id' => (int) $row->id,
      'type' => (string) $row->type,
      'delta' => (int) $row->delta,
      'created' => (int) $row->sitemap_created,
      'status' => (int) $row->status,
      'link_count' => (int) $row->link_count,
      'xml_sha256' => hash('sha256', (string) $row->sitemap_string),
    ];
  }

  $simple_sitemap_config = [];
  foreach ($simple_sitemap_names as $name) {
    $values = $config_storage->read($name);
    if (!is_array($values)) {
      $fail('Cannot fingerprint Simple Sitemap config ' . $name . '.');
    }
    $simple_sitemap_config[$name] = $values;
  }
  ksort($simple_sitemap_config, SORT_STRING);
  ksort($route_state, SORT_STRING);
  ksort($dynamic_counts, SORT_STRING);

  return [
    'format' => 'unisonges-sitemap-policy-state-v1',
    'site_uuid' => $active_site['uuid'],
    'front' => '/accueil',
    'origin' => $active_origin,
    'simple_sitemap_version' => $simple_sitemap_version,
    'source_hashes' => $source_hashes,
    'forum_ready' => $forum_ready,
    'future_routes_ready' => $future_routes_ready,
    'simple_sitemap_config' => $simple_sitemap_config,
    'nondefault_simple_sitemap_config' => $nondefault_simple_sitemap,
    'routes' => $route_state,
    'dynamic_nodes' => $dynamic_nodes,
    'dynamic_counts' => $dynamic_counts,
    'entity_overrides' => [],
    'generated_chunks' => $generated_chunks,
  ];
};

$build_apply_targets = static function (array $state) use (
  $settings_source,
  $type_source,
  $custom_source,
  $bundle_sources,
  $fail
): array {
  $active = $state['simple_sitemap_config'];
  foreach ([
    'simple_sitemap.settings',
    'simple_sitemap.type.default_hreflang',
    'simple_sitemap.custom_links.default',
  ] as $required) {
    if (!isset($active[$required]) || !is_array($active[$required])) {
      $fail('Cannot build target for missing config ' . $required . '.');
    }
  }
  $targets = [];
  $targets['simple_sitemap.settings'] = $active['simple_sitemap.settings'];
  $targets['simple_sitemap.settings']['enabled_entity_types'] = $settings_source['enabled_entity_types'];
  $targets['simple_sitemap.type.default_hreflang'] = $active['simple_sitemap.type.default_hreflang'];
  $targets['simple_sitemap.type.default_hreflang']['url_generators'] = $type_source['url_generators'];
  $targets['simple_sitemap.custom_links.default'] = $active['simple_sitemap.custom_links.default'];
  $targets['simple_sitemap.custom_links.default']['links'] = $custom_source['links'];
  foreach (['article', 'page', 'stage', 'concert'] as $bundle) {
    $targets['simple_sitemap.bundle_settings.default.node.' . $bundle] = $bundle_sources[$bundle];
  }
  if ($state['forum_ready'] === TRUE) {
    $targets['simple_sitemap.bundle_settings.default.node.forum_topic'] = $bundle_sources['forum_topic'];
  }
  ksort($targets, SORT_STRING);
  return $targets;
};

$simulate_targets = static function (array $state, array $targets): array {
  foreach ($targets as $name => $values) {
    $state['simple_sitemap_config'][$name] = $values;
  }
  ksort($state['simple_sitemap_config'], SORT_STRING);
  return $state;
};

$current_state = $inspect_state();
$current_fingerprint = $policy_fingerprint($current_state);
$current_generated_fingerprint = $generated_fingerprint($current_state);
$apply_targets = $build_apply_targets($current_state);

$section('Validated policy');
echo 'SITE OK origin=' . $active_origin . ' UUID=matches-tracked (value suppressed)' . PHP_EOL;
echo 'MODULE OK simple_sitemap=' . $simple_sitemap_version . ' API/schema=exact-reviewed' . PHP_EOL;
echo 'SITEMAPS OK active=default disabled=index unexpected=0' . PHP_EOL;
echo 'CUSTOM_REQUIRED READY count=' . count($required_custom_paths) . PHP_EOL;
echo $current_state['future_routes_ready']
  ? 'CUSTOM_FUTURE READY paths=/blog,/forum' . PHP_EOL
  : 'CUSTOM_FUTURE DEFERRED paths=/blog,/forum (kept in tracked policy; generator access omits absent paths)' . PHP_EOL;
echo $current_state['forum_ready']
  ? 'FORUM_BUNDLE READY policy will include published accessible forum_topic nodes' . PHP_EOL
  : 'FORUM_BUNDLE DEFERRED no active forum_topic sitemap bundle config will be written' . PHP_EOL;
foreach ($current_state['dynamic_counts'] as $bundle => $counts) {
  echo 'DYNAMIC ' . $bundle . ' include_published_accessible=' . $counts['included']
    . ' exclude_unpublished_or_private=' . $counts['excluded'] . PHP_EOL;
}
echo 'OVERRIDES READY rows=0 (any row is blocking non-reproducible drift)' . PHP_EOL;
echo 'GENERATED current_chunks=' . count($current_state['generated_chunks'])
  . ' (separate diagnostic; this script never regenerates or changes it)' . PHP_EOL;
echo 'GENERATED_FINGERPRINT ' . $current_generated_fingerprint . PHP_EOL;
echo 'EXCLUDE bundles=page,taxonomy_term,menu_link_content,user,commerce_product,commerce_product_variation,webform' . PHP_EOL;
echo 'EXCLUDE routes=/user,/admin,/cart,/checkout,/order,/payment,/webform,/form,/reservation*,/reserver,/product' . PHP_EOL;
echo 'FINGERPRINT_SCOPE current/planned/backup=policy+routes+nodes+sources generated=separate' . PHP_EOL;
echo 'CURRENT_FINGERPRINT ' . $current_fingerprint . PHP_EOL;

$load_backup = static function (string $path) use ($fail): array {
  $real = realpath($path);
  if ($real === FALSE || $real !== $path || !is_file($real) || is_link($path)) {
    $fail('Rollback backup path is not a canonical regular non-symlink file.');
  }
  $stat = stat($real);
  if (!is_array($stat) || (($stat['mode'] ?? 0) & 0777) !== 0600) {
    $fail('Rollback backup permissions must be exactly 0600.');
  }
  $contents = file_get_contents($real);
  if (!is_string($contents)) {
    $fail('Cannot read rollback backup.');
  }
  try {
    $backup = json_decode($contents, TRUE, 512, JSON_THROW_ON_ERROR);
  }
  catch (Throwable $throwable) {
    $fail('Cannot parse rollback backup JSON: ' . $throwable->getMessage());
  }
  if (!is_array($backup) || ($backup['format'] ?? NULL) !== 'unisonges-sitemap-policy-backup-v2') {
    $fail('Rollback backup format is unsupported.');
  }
  return $backup;
};

$rollback_backup = NULL;
$target_fingerprint = '';
if ($action === 'rollback') {
  $rollback_backup = $load_backup($backup_file);
  $required_backup_keys = [
    'format',
    'site_uuid',
    'initial_fingerprint',
    'applied_fingerprint',
    'generated_fingerprint_at_apply',
    'source_hashes',
    'configs',
  ];
  $backup_keys = array_keys($rollback_backup);
  sort($backup_keys, SORT_STRING);
  sort($required_backup_keys, SORT_STRING);
  if ($backup_keys !== $required_backup_keys
    || !is_string($rollback_backup['site_uuid'])
    || !hash_equals($current_state['site_uuid'], $rollback_backup['site_uuid'])
    || !preg_match('/^[0-9a-f]{64}$/D', (string) $rollback_backup['initial_fingerprint'])
    || !preg_match('/^[0-9a-f]{64}$/D', (string) $rollback_backup['applied_fingerprint'])
    || !preg_match('/^[0-9a-f]{64}$/D', (string) $rollback_backup['generated_fingerprint_at_apply'])
    || !is_array($rollback_backup['source_hashes'])
    || $rollback_backup['source_hashes'] !== $current_state['source_hashes']
    || !is_array($rollback_backup['configs'])) {
    $fail('Rollback backup identity, source hashes, or structure does not match this runtime.');
  }
  if (!hash_equals($expected_fingerprint, $current_fingerprint)
    || !hash_equals($rollback_backup['applied_fingerprint'], $current_fingerprint)) {
    $fail('Rollback requires both the supplied and recorded applied fingerprint to match current policy state.');
  }
  $base_backup_names = [
    'simple_sitemap.bundle_settings.default.node.article',
    'simple_sitemap.bundle_settings.default.node.concert',
    'simple_sitemap.bundle_settings.default.node.page',
    'simple_sitemap.bundle_settings.default.node.stage',
    'simple_sitemap.custom_links.default',
    'simple_sitemap.settings',
    'simple_sitemap.type.default_hreflang',
  ];
  $backup_names = array_keys($rollback_backup['configs']);
  sort($backup_names, SORT_STRING);
  $allowed_with_forum = array_merge($base_backup_names, [
    'simple_sitemap.bundle_settings.default.node.forum_topic',
  ]);
  sort($base_backup_names, SORT_STRING);
  sort($allowed_with_forum, SORT_STRING);
  if ($backup_names !== $base_backup_names && $backup_names !== $allowed_with_forum) {
    $fail('Rollback backup config allowlist is invalid.');
  }
  foreach ($rollback_backup['configs'] as $name => $snapshot) {
    if (!is_array($snapshot)
      || array_keys($snapshot) !== ['data', 'exists']
      || !is_bool($snapshot['exists'])
      || ($snapshot['exists'] && !is_array($snapshot['data']))
      || (!$snapshot['exists'] && $snapshot['data'] !== NULL)) {
      $fail('Malformed rollback config snapshot for ' . $name . '.');
    }
  }
  echo 'BACKUP_GENERATED_FINGERPRINT ' . $rollback_backup['generated_fingerprint_at_apply']
    . ' (diagnostic only; a reviewed generation between apply and rollback is allowed)' . PHP_EOL;
  $target_fingerprint = $rollback_backup['initial_fingerprint'];
  $section('Rollback plan');
  foreach ($rollback_backup['configs'] as $name => $snapshot) {
    echo ($snapshot['exists'] ? 'RESTORE ' : 'REMOVE ') . $name . PHP_EOL;
  }
  echo 'ROLLBACK_TARGET_FINGERPRINT ' . $target_fingerprint . PHP_EOL;
}
else {
  $planned_state = $simulate_targets($current_state, $apply_targets);
  $target_fingerprint = $policy_fingerprint($planned_state);
  $section('Apply plan');
  foreach ($apply_targets as $name => $target) {
    $active = $current_state['simple_sitemap_config'][$name] ?? NULL;
    echo ($active === NULL ? 'CREATE ' : ($canonicalize($active) === $canonicalize($target) ? 'NOOP ' : 'UPDATE '))
      . $name . PHP_EOL;
  }
  echo 'PLANNED_FINGERPRINT ' . $target_fingerprint . PHP_EOL;
}

if (!$is_apply) {
  $dry_run_final_state = $inspect_state();
  $dry_run_final_fingerprint = $policy_fingerprint($dry_run_final_state);
  $dry_run_final_generated_fingerprint = $generated_fingerprint($dry_run_final_state);
  if (!hash_equals($current_fingerprint, $dry_run_final_fingerprint)
    || !hash_equals($current_generated_fingerprint, $dry_run_final_generated_fingerprint)) {
    $fail('Policy or generated sitemap state changed during dry-run; rerun from a stable window.');
  }
  echo 'DRY_RUN_STABILITY policy=unchanged generated=unchanged' . PHP_EOL;
  echo PHP_EOL . 'DRY_RUN No persistent Drupal/config/content/alias/sitemap write occurred.' . PHP_EOL;
  echo $action === 'rollback'
    ? 'Rerun with --rollback --apply using the same backup and current fingerprint.' . PHP_EOL
    : 'Rerun with --apply, --expect-fingerprint=CURRENT_FINGERPRINT, and a new absolute backup path.' . PHP_EOL;
  return;
}

if (!hash_equals($expected_fingerprint, $current_fingerprint)) {
  $fail('Current state differs from --expect-fingerprint; rerun dry-run and review the new plan.');
}
if (\Drupal::state()->get('system.maintenance_mode', FALSE) !== TRUE) {
  $fail('Apply/rollback requires Drupal maintenance mode and an exclusive writer/cron window.');
}
if ($database->inTransaction()) {
  $fail('Refusing to nest the sitemap policy inside an existing database transaction.');
}

$persistent_lock = \Drupal::service('lock.persistent');
$script_lock_name = 'unisonges_sitemap_policy_2026';
$import_lock_name = ConfigImporter::LOCK_NAME;
$lock_ttl = 3600.0;
$script_locked = FALSE;
$import_locked = FALSE;
try {
  $script_locked = $persistent_lock->acquire($script_lock_name, $lock_ttl);
  if (!$script_locked) {
    $fail('Cannot acquire the sitemap-policy lock.');
  }
  $import_locked = $persistent_lock->acquire($import_lock_name, $lock_ttl);
  if (!$import_locked) {
    $fail('Cannot acquire the core config-importer lock.');
  }

  $locked_state = $inspect_state();
  $locked_fingerprint = $policy_fingerprint($locked_state);
  $locked_generated_fingerprint = $generated_fingerprint($locked_state);
  if (!hash_equals($current_fingerprint, $locked_fingerprint)
    || !hash_equals($current_generated_fingerprint, $locked_generated_fingerprint)) {
    $fail('Policy or generated sitemap state changed after planning; no policy config was written.');
  }

  if ($action === 'apply-policy') {
    if ($backup_file === '' || $backup_file[0] !== '/' || file_exists($backup_file) || is_link($backup_file)) {
      $fail('Normal apply backup path must be absolute and non-existing at the locked write boundary.');
    }
    $backup_parent = realpath(dirname($backup_file));
    if ($backup_parent === FALSE
      || $backup_parent . '/' . basename($backup_file) !== $backup_file
      || !is_dir($backup_parent)
      || is_link(dirname($backup_file))) {
      $fail('Normal apply backup parent is unsafe or noncanonical.');
    }
    $backup_configs = [];
    foreach ($apply_targets as $name => $_target) {
      $exists = $config_storage->exists($name);
      $data = $exists ? $config_storage->read($name) : NULL;
      if ($exists && !is_array($data)) {
        $fail('Cannot snapshot target config before write: ' . $name . '.');
      }
      $backup_configs[$name] = ['exists' => $exists, 'data' => $data];
    }
    $backup_payload = [
      'format' => 'unisonges-sitemap-policy-backup-v2',
      'site_uuid' => $locked_state['site_uuid'],
      'initial_fingerprint' => $locked_fingerprint,
      'applied_fingerprint' => $target_fingerprint,
      'generated_fingerprint_at_apply' => $locked_generated_fingerprint,
      'source_hashes' => $locked_state['source_hashes'],
      'configs' => $backup_configs,
    ];
    $handle = @fopen($backup_file, 'xb');
    if ($handle === FALSE) {
      $fail('Cannot exclusively create the new backup file.');
    }
    try {
      if (!chmod($backup_file, 0600)) {
        $fail('Cannot set backup permissions to 0600.');
      }
      $backup_json = $canonical_json($backup_payload) . PHP_EOL;
      $written = fwrite($handle, $backup_json);
      if ($written !== strlen($backup_json) || !fflush($handle)) {
        $fail('Cannot durably write the complete backup JSON.');
      }
      if (function_exists('fsync') && !fsync($handle)) {
        $fail('Cannot fsync the backup JSON.');
      }
    }
    finally {
      fclose($handle);
    }
    clearstatcache(TRUE, $backup_file);
    $backup_stat = stat($backup_file);
    $backup_disk_hash = hash_file('sha256', $backup_file);
    if (!is_array($backup_stat)
      || (($backup_stat['mode'] ?? 0) & 0777) !== 0600
      || !is_string($backup_disk_hash)
      || !hash_equals(hash('sha256', $backup_json), $backup_disk_hash)) {
      $fail('Backup verification failed before the configuration transaction.');
    }
    echo 'BACKUP CREATED mode=0600 sha256=verified (path suppressed)' . PHP_EOL;
  }

  $before_all_config = $snapshot_all_config();
  $expected_all_config = $before_all_config;
  $default_collection = StorageInterface::DEFAULT_COLLECTION;
  if ($action === 'apply-policy') {
    foreach ($apply_targets as $name => $target) {
      $expected_all_config[$default_collection][$name] = $target;
    }
  }
  else {
    foreach ($rollback_backup['configs'] as $name => $snapshot) {
      if ($snapshot['exists']) {
        $expected_all_config[$default_collection][$name] = $snapshot['data'];
      }
      else {
        unset($expected_all_config[$default_collection][$name]);
      }
    }
  }
  ksort($expected_all_config[$default_collection], SORT_STRING);

  $transaction = $database->startTransaction('unisonges_sitemap_policy_2026');
  $transaction_committed = FALSE;
  try {
    $write_config = static function (string $name, array $data, string $operation) use (
      $config_factory,
      $settings_source,
      $type_source,
      $custom_source,
      $fail
    ): void {
      $editable = $config_factory->getEditable($name);
      if ($operation === 'policy') {
        if ($name === 'simple_sitemap.settings') {
          $editable->set('enabled_entity_types', $settings_source['enabled_entity_types']);
        }
        elseif ($name === 'simple_sitemap.type.default_hreflang') {
          $editable->set('url_generators', $type_source['url_generators']);
        }
        elseif ($name === 'simple_sitemap.custom_links.default') {
          $editable->set('links', $custom_source['links']);
        }
        elseif (str_starts_with($name, 'simple_sitemap.bundle_settings.default.node.')) {
          $editable->setData($data);
        }
        else {
          $fail('Internal write allowlist rejected ' . $name . '.');
        }
      }
      else {
        $editable->setData($data);
      }
      $editable->save(TRUE);
      $config_factory->reset($name);
    };

    if ($action === 'apply-policy') {
      foreach ($apply_targets as $name => $target) {
        $active = $config_storage->exists($name) ? $config_storage->read($name) : NULL;
        if (is_array($active) && $canonicalize($active) === $canonicalize($target)) {
          echo 'NOOP ' . $name . PHP_EOL;
          continue;
        }
        echo ($active === NULL ? 'WRITE_CREATE ' : 'WRITE_UPDATE ') . $name . PHP_EOL;
        $write_config($name, $target, 'policy');
      }
    }
    else {
      foreach ($rollback_backup['configs'] as $name => $snapshot) {
        if ($snapshot['exists']) {
          $active = $config_storage->exists($name) ? $config_storage->read($name) : NULL;
          if (is_array($active) && $canonicalize($active) === $canonicalize($snapshot['data'])) {
            echo 'NOOP_RESTORE ' . $name . PHP_EOL;
            continue;
          }
          echo 'WRITE_RESTORE ' . $name . PHP_EOL;
          $write_config($name, $snapshot['data'], 'restore');
        }
        elseif ($config_storage->exists($name)) {
          echo 'WRITE_REMOVE ' . $name . PHP_EOL;
          $config_factory->getEditable($name)->delete();
          $config_factory->reset($name);
        }
        else {
          echo 'NOOP_ABSENT ' . $name . PHP_EOL;
        }
      }
    }

    $after_all_config = $snapshot_all_config();
    if ($canonicalize($after_all_config) !== $canonicalize($expected_all_config)) {
      $fail('Targeted write changed configuration outside the exact expected snapshot.');
    }
    $after_state = $inspect_state();
    $after_fingerprint = $policy_fingerprint($after_state);
    $after_generated_fingerprint = $generated_fingerprint($after_state);
    if (!hash_equals($target_fingerprint, $after_fingerprint)) {
      $fail('Post-write relevant-state fingerprint differs from the reviewed target.');
    }
    if (!hash_equals($locked_generated_fingerprint, $after_generated_fingerprint)) {
      $fail('Generated sitemap state changed during the targeted config transaction.');
    }
    $transaction->commitOrRelease();
    $transaction_committed = TRUE;
  }
  catch (Throwable $throwable) {
    $rollback_error = NULL;
    if (!$transaction_committed && $database->inTransaction()) {
      try {
        $transaction->rollBack();
      }
      catch (Throwable $rollback_throwable) {
        $rollback_error = $rollback_throwable;
      }
    }
    $reset_names = array_keys($apply_targets);
    if (is_array($rollback_backup['configs'] ?? NULL)) {
      $reset_names = array_merge($reset_names, array_keys($rollback_backup['configs']));
    }
    foreach (array_unique($reset_names) as $name) {
      $config_factory->reset($name);
    }
    if ($rollback_error !== NULL) {
      throw new RuntimeException(
        'Write failed and automatic database rollback could not be confirmed: '
        . $rollback_error->getMessage(),
        0,
        $throwable
      );
    }
    if (!$transaction_committed) {
      $restored_state = $inspect_state();
      $restored_fingerprint = $policy_fingerprint($restored_state);
      $restored_generated_fingerprint = $generated_fingerprint($restored_state);
      if (!hash_equals($locked_fingerprint, $restored_fingerprint)) {
        throw new RuntimeException(
          'Write failed and automatic rollback did not restore the initial fingerprint.',
          0,
          $throwable
        );
      }
      if (!hash_equals($locked_generated_fingerprint, $restored_generated_fingerprint)) {
        throw new RuntimeException(
          'Policy rollback succeeded, but generated sitemap state changed during the failed invocation.',
          0,
          $throwable
        );
      }
      throw new RuntimeException(
        'Targeted configuration transaction rolled back automatically: ' . $throwable->getMessage(),
        0,
        $throwable
      );
    }
    throw $throwable;
  }

  $config_factory->reset();
  $final_state = $inspect_state();
  $final_fingerprint = $policy_fingerprint($final_state);
  $final_generated_fingerprint = $generated_fingerprint($final_state);
  if (!hash_equals($target_fingerprint, $final_fingerprint)) {
    $fail('Committed state no longer matches the verified target fingerprint.');
  }
  if (!hash_equals($locked_generated_fingerprint, $final_generated_fingerprint)) {
    $fail('Generated sitemap state changed before invocation completion.');
  }
  echo 'RESULT_FINGERPRINT ' . $final_fingerprint . PHP_EOL;
  echo 'RESULT_GENERATED_FINGERPRINT ' . $final_generated_fingerprint . PHP_EOL;
  echo $action === 'apply-policy'
    ? 'APPLY_OK Policy config committed; sitemap generation was not requested.' . PHP_EOL
    : 'ROLLBACK_OK Exact pre-apply config presence/data and initial fingerprint restored.' . PHP_EOL;
}
finally {
  if ($import_locked) {
    $persistent_lock->release($import_lock_name);
  }
  if ($script_locked) {
    $persistent_lock->release($script_lock_name);
  }
}

__UNISONGES_SITEMAP_POLICY_PHP_END__
__UNISONGES_SITEMAP_POLICY_PHP_BLOCK__

printf 'Running sitemap policy %s (%s).\n' "${ACTION}" "${MODE}"
stream_embedded_php \
  | env -u DRUSH_OPTIONS_ROOT -u DRUSH_OPTIONS_URI \
    "${runtime_env[@]}" \
    "${DRUSH}" --root="${DRUPAL_ROOT}" --uri="${SITE_URI}" \
      php:eval 'eval(stream_get_contents(STDIN));'
