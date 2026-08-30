#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DRUPAL_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"
REPO_ROOT="$(cd -- "${DRUPAL_DIR}/.." && pwd -P)"
readonly DRUPAL_ROOT="${DRUPAL_DIR}/web"
SYNC_DIR="${DRUPAL_DIR}/config/sync"
PHP_HELPER="${SCRIPT_DIR}/forum-blog-mvp-config.php"
ACCESS_HOOK_FILE="${DRUPAL_DIR}/web/modules/custom/unisonges_structure/unisonges_structure.module"
COMPOSER_LOCK="${DRUPAL_DIR}/composer.lock"
readonly DRUSH_CMD="${DRUPAL_DIR}/vendor/bin/drush"

MODE="dry-run"
REQUESTED_MODE=""
ACTION="install"
ALLOW_VPS="0"
BACKUP_CONFIRMED="0"
VERIFIED_LOCAL_DDEV="0"
SITE_URI=""
SITE_URI_SEEN="0"

readonly -a CONFIG_NAMES=(
  node.type.forum_topic
  core.base_field_override.node.forum_topic.status
  core.base_field_override.node.forum_topic.promote
  field.field.node.forum_topic.body
  field.field.node.forum_topic.comment
  core.entity_form_display.node.forum_topic.default
  core.entity_view_display.node.forum_topic.default
  core.entity_view_display.node.forum_topic.teaser
  views.view.blog_posts
  views.view.forum_topics
  webform.webform.forum_blog_proposal
  block.block.unisonges_blog_posts
  block.block.unisonges_forum_topics
  block.block.unisonges_forum_blog_proposal
  field.field.comment.comment.comment_body
)

log() {
  printf '[apply-forum-blog-mvp-2026] %s\n' "$*"
}

warn() {
  printf '[apply-forum-blog-mvp-2026] WARNING: %s\n' "$*" >&2
}

section() {
  printf '\n== %s ==\n' "$*"
}

usage() {
  cat <<'EOF'
Usage: ./scripts/apply-forum-blog-mvp-2026.sh --site-uri=https://approved-host.example [options]

Audits or applies the exact Forum + Blog MVP configuration. Dry-run is the
default. This script never runs a full or partial configuration import.

Options:
  --site-uri=ORIGIN  Required absolute http/https origin for the approved site.
                     Credentials, non-root paths, queries, and fragments are
                     forbidden.
  --dry-run          Audit prerequisites and print the immutable target plan.
                     This is the default and never writes.
  --apply            Apply the exact allowlist after all guards pass.
  --rollback         Select rollback instead of install. This is read-only
                     unless combined with --apply.
  --backup-confirmed Confirm that a current database backup/snapshot exists.
                     Required with --apply, including rollback.
  --allow-vps        Permit a reviewed staging checkout under /var/www. This
                     does not authorize production access or deployment.
  -h, --help         Show this help.

Examples:
  SITE_URI='https://approved-host.example' # Replace with the approved origin.
  ./scripts/apply-forum-blog-mvp-2026.sh --site-uri="${SITE_URI}"
  ./scripts/apply-forum-blog-mvp-2026.sh --site-uri="${SITE_URI}" --apply --backup-confirmed
  ./scripts/apply-forum-blog-mvp-2026.sh --site-uri="${SITE_URI}" --rollback
  ./scripts/apply-forum-blog-mvp-2026.sh --site-uri="${SITE_URI}" --rollback --apply --backup-confirmed

Safety properties:
  - Maintenance mode must already be active for every preflight, dry-run,
    install, or rollback. Rebuild caches after deploying code so the reviewed
    procedural hooks are registered.
  - The operator must stop cron/queue workers and prohibit all other privileged
    UI/CLI, config, and content writes for the full dry/apply/rollback window.
  - Every Drush bootstrap uses the fixed project executable, explicit Drupal
    root, and required site origin. DRUSH_OPTIONS_ROOT/URI are ignored, and no
    Drush alias argument is accepted.
  - /blog and /forum must already be distinct published Basic-page aliases;
    this script never creates or replaces those routes or their menu links.
  - New forum topics default to unpublished and unpromoted.
  - Views are block-only and filter explicitly for published content.
  - Registered access hooks deny non-admin draft/revision access, hide drafts
    from generic node Views, and forbid member edits to lesson-credit fields.
  - Any non-admin role with Forum or broad Node/Webform privileges blocks the
    run. Role permissions, including anonymous comment posting, are not written.
  - The proposal Webform is authenticated-only, block-only, private, and has
    no mail handler or content-creation handler.
  - The existing comment FieldConfig is saved with allowed_formats=basic_html
    and its calculated dependency; webform_default comments in any state block
    install. No existing comment is rewritten.
  - No business content is written. Webform's config lifecycle creates exactly
    one feature-owned serial-tracking row; Drupal may maintain internal
    config-entity key-value/state metadata.
  - Apply holds the feature and persistent config-importer locks with a one-hour
    TTL renewed before each write phase. Comment hardening and the atomic
    14-entity feature change use separate transactions.
  - Rollback refuses to run while forum topics or proposal submissions exist.
    It leaves the comment FieldConfig exactly as found, requires proposal
    aliases/state/webform_libraries/user-data/files to be absent, and deletes
    the tracking row.
    Any generated Forum FieldConfig UUID tombstone must be purged before install.
EOF
}

for arg in "$@"; do
  case "${arg}" in
    --site-uri=*)
      if [[ "${SITE_URI_SEEN}" == "1" ]]; then
        warn '--site-uri may be specified only once.'
        exit 2
      fi
      SITE_URI_SEEN="1"
      SITE_URI="${arg#--site-uri=}"
      if [[ -z "${SITE_URI}" ]]; then
        warn '--site-uri requires a non-empty absolute http/https site origin.'
        exit 2
      fi
      ;;
    --site-uri)
      warn 'Use --site-uri=https://approved-host.example with a non-empty value.'
      exit 2
      ;;
    --dry-run)
      if [[ "${REQUESTED_MODE}" == "apply" ]]; then
        warn 'Use either --dry-run or --apply, not both.'
        usage
        exit 2
      fi
      REQUESTED_MODE="dry-run"
      MODE="dry-run"
      ;;
    --apply)
      if [[ "${REQUESTED_MODE}" == "dry-run" ]]; then
        warn 'Use either --dry-run or --apply, not both.'
        usage
        exit 2
      fi
      REQUESTED_MODE="apply"
      MODE="apply"
      ;;
    --rollback)
      ACTION="rollback"
      ;;
    --backup-confirmed)
      BACKUP_CONFIRMED="1"
      ;;
    --allow-vps)
      ALLOW_VPS="1"
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      warn "Unknown argument: ${arg}"
      usage
      exit 2
      ;;
  esac
done

if [[ "${SITE_URI_SEEN}" != "1" ]]; then
  warn '--site-uri=https://approved-host.example is required for every invocation.'
  exit 2
fi

# Explicit CLI --root/--uri values below are authoritative. Do not inherit a
# bootstrap target from the operator environment.
unset DRUSH_OPTIONS_ROOT DRUSH_OPTIONS_URI

if [[ "${MODE}" == "apply" && "${BACKUP_CONFIRMED}" != "1" ]]; then
  warn '--apply requires --backup-confirmed.'
  exit 2
fi
if [[ "${MODE}" == "dry-run" && "${BACKUP_CONFIRMED}" == "1" ]]; then
  warn '--backup-confirmed is only valid with --apply.'
  exit 2
fi

is_verified_local_ddev() {
  [[ "${IS_DDEV_PROJECT:-}" == "true" ]] || return 1
  [[ "${DEPLOY_NAME:-}" == "local" ]] || return 1
  [[ "${DDEV_PROJECT_TYPE:-}" == "drupal11" ]] || return 1
  [[ -f /mnt/ddev_config/config.yaml ]] || return 1

  local ddev_approot="${DDEV_APPROOT:-}"
  local ddev_composer_root="${DDEV_COMPOSER_ROOT:-}"
  local ddev_docroot="${DDEV_DOCROOT:-${DOCROOT:-}}"
  [[ -n "${ddev_approot}" && -n "${ddev_composer_root}" ]] || return 1
  ddev_approot="$(realpath -e -- "${ddev_approot}")" || return 1
  ddev_composer_root="$(realpath -e -- "${ddev_composer_root}")" || return 1
  [[ "${ddev_composer_root}" == "${DRUPAL_DIR}" ]] || return 1

  if [[ "${ddev_approot}" == "${DRUPAL_DIR}" ]]; then
    [[ "${ddev_docroot}" == "web" ]] || return 1
  elif [[ "${ddev_approot}" == "${REPO_ROOT}" ]]; then
    [[ "${ddev_docroot}" == "drupal/web" ]] || return 1
  else
    return 1
  fi
}

require_safe_path() {
  case "${DRUPAL_DIR}" in
    /|/tmp|/tmp/*|/mnt/c|/mnt/c/*)
      warn "Refusing unsafe Drupal path: ${DRUPAL_DIR}"
      exit 1
      ;;
    /var/www|/var/www/*)
      if is_verified_local_ddev; then
        if [[ "${ALLOW_VPS}" == "1" ]]; then
          warn '--allow-vps is invalid inside a verified local DDEV container.'
          exit 2
        fi
        VERIFIED_LOCAL_DDEV="1"
      elif [[ "${ALLOW_VPS}" != "1" ]]; then
        warn "Refusing /var/www execution without --allow-vps: ${DRUPAL_DIR}"
        exit 1
      fi
      ;;
    *)
      if [[ "${ALLOW_VPS}" == "1" ]]; then
        warn '--allow-vps is only valid for a reviewed staging checkout under /var/www.'
        exit 2
      fi
      ;;
  esac

  if [[ ! -f "${DRUPAL_DIR}/composer.json" || ! -f "${DRUPAL_DIR}/web/index.php" ]]; then
    warn "Could not verify the Drupal project at ${DRUPAL_DIR}."
    exit 1
  fi
  if [[ ! -d "${SYNC_DIR}" || -L "${DRUPAL_DIR}/config" || -L "${SYNC_DIR}" ]]; then
    warn "Refusing a missing or symlinked sync directory: ${SYNC_DIR}"
    exit 1
  fi
  if [[ ! -f "${PHP_HELPER}" || ! -r "${PHP_HELPER}" || -L "${PHP_HELPER}" ]]; then
    warn "The reviewed PHP helper is missing, unreadable, or a symlink: ${PHP_HELPER}"
    exit 1
  fi
  if [[ ! -f "${ACCESS_HOOK_FILE}" || ! -r "${ACCESS_HOOK_FILE}" || -L "${ACCESS_HOOK_FILE}" ]]; then
    warn "The reviewed access-hook file is missing, unreadable, or a symlink: ${ACCESS_HOOK_FILE}"
    exit 1
  fi
  if [[ ! -f "${SYNC_DIR}/system.site.yml" || -L "${SYNC_DIR}/system.site.yml" ]]; then
    warn 'The system.site baseline is missing or a symlink.'
    exit 1
  fi
  if [[ "$(realpath -e -- "${PHP_HELPER}")" != "${PHP_HELPER}" ]]; then
    warn 'The PHP helper canonical-path guard failed.'
    exit 1
  fi
  if [[ "$(realpath -e -- "${ACCESS_HOOK_FILE}")" != "${ACCESS_HOOK_FILE}" ]]; then
    warn 'The access-hook canonical-path guard failed.'
    exit 1
  fi

  local config_name
  local config_file
  for config_name in "${CONFIG_NAMES[@]}"; do
    config_file="${SYNC_DIR}/${config_name}.yml"
    if [[ ! -f "${config_file}" || ! -r "${config_file}" || -L "${config_file}" ]]; then
      warn "Allowlisted config is missing, unreadable, or a symlink: ${config_file}"
      exit 1
    fi
    if [[ "$(realpath -e -- "${config_file}")" != "${config_file}" ]]; then
      warn "Config canonical-path guard failed: ${config_file}"
      exit 1
    fi
  done
}

require_runtime() {
  if [[ ! -x "${DRUSH_CMD}" ]]; then
    warn "Drush is missing or not executable at ${DRUSH_CMD}."
    warn 'Install the locked Composer dependencies before running this script.'
    exit 1
  fi
  if [[ ! -f "${DRUPAL_DIR}/web/core/lib/Drupal.php" ]]; then
    warn 'The installed Drupal core runtime is missing.'
    exit 1
  fi
  if [[ ! -f "${COMPOSER_LOCK}" || ! -r "${COMPOSER_LOCK}" || -L "${COMPOSER_LOCK}" ]]; then
    warn 'composer.lock is missing, unreadable, or a symlink.'
    exit 1
  fi
  if [[ "$(realpath -e -- "${COMPOSER_LOCK}")" != "${COMPOSER_LOCK}" ]]; then
    warn 'The composer.lock canonical-path guard failed.'
    exit 1
  fi
  # This argument is literal PHP; shell expansion would corrupt its variables.
  # shellcheck disable=SC2016
  if ! php -r '
$path = $argv[1];
$required = [
  "drupal/core" => "11.3.3",
  "drupal/webform" => "6.3.0-beta7",
  "drush/drush" => "13.7.1",
];
try {
  $json = file_get_contents($path);
  if ($json === false) {
    throw new RuntimeException("could not read composer.lock");
  }
  $lock = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
  if (!is_array($lock)) {
    throw new RuntimeException("composer.lock is not a JSON object");
  }
  $found = [];
  foreach (["packages", "packages-dev"] as $section) {
    foreach (($lock[$section] ?? []) as $package) {
      $name = is_array($package) ? ($package["name"] ?? null) : null;
      if (!is_string($name) || !array_key_exists($name, $required)) {
        continue;
      }
      if (array_key_exists($name, $found)) {
        throw new RuntimeException("duplicate locked package " . $name);
      }
      $found[$name] = $package["version"] ?? null;
    }
  }
  foreach ($required as $name => $version) {
    if (($found[$name] ?? null) !== $version) {
      throw new RuntimeException(
        $name . " must be locked at " . $version . "; found "
        . json_encode($found[$name] ?? null)
      );
    }
  }
}
catch (Throwable $throwable) {
  fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
  exit(1);
}
' "${COMPOSER_LOCK}"; then
    warn 'composer.lock does not contain the exact reviewed Drupal/Webform/Drush versions.'
    exit 1
  fi
}

require_site_uri() {
  if ! command -v php >/dev/null 2>&1; then
    warn 'A PHP CLI is required to validate --site-uri and composer.lock.'
    exit 1
  fi
  # This argument is literal PHP; shell expansion would corrupt its variables.
  # shellcheck disable=SC2016
  if ! php -r '
$uri = $argv[1] ?? "";
if ($uri === "" || preg_match("/[\\x00-\\x20\\x7f]/", $uri)) {
  exit(1);
}
$parts = parse_url($uri);
if (!is_array($parts)
  || !filter_var($uri, FILTER_VALIDATE_URL)
  || !isset($parts["scheme"], $parts["host"])
  || !in_array($parts["scheme"], ["http", "https"], true)
  || $parts["host"] === ""
  || array_key_exists("user", $parts)
  || array_key_exists("pass", $parts)
  || array_key_exists("query", $parts)
  || array_key_exists("fragment", $parts)
  || !in_array($parts["path"] ?? "", ["", "/"], true)) {
  exit(1);
}
' "${SITE_URI}"; then
    warn '--site-uri must be an absolute http/https site origin without credentials, non-root path, query, or fragment.'
    exit 2
  fi
}

require_maintenance_mode() {
  local maintenance_mode
  if ! maintenance_mode="$(
    cd "${DRUPAL_DIR}"
    "${DRUSH_CMD}" --root="${DRUPAL_ROOT}" --uri="${SITE_URI}" maint:get
  )"; then
    warn 'Could not read Drupal maintenance mode with the locked Drush runtime.'
    exit 1
  fi
  if [[ ! "${maintenance_mode}" =~ ^[[:space:]]*1[[:space:]]*$ ]]; then
    warn 'Maintenance mode must be enabled before preflight, dry-run, apply, or rollback.'
    warn "Run ${DRUSH_CMD} --root=\"${DRUPAL_ROOT}\" --uri=\"${SITE_URI}\" maint:set 1, then rebuild caches with the same explicit root and URI."
    exit 1
  fi
}

require_reviewed_git_source() {
  if ! git -C "${REPO_ROOT}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    warn "Could not verify a Git worktree at ${REPO_ROOT}."
    exit 1
  fi

  local relative_path
  local config_name
  local -a reviewed_files=(
    "${PHP_HELPER}"
    "${ACCESS_HOOK_FILE}"
    "${SCRIPT_DIR}/apply-forum-blog-mvp-2026.sh"
    "${COMPOSER_LOCK}"
    "${SYNC_DIR}/system.site.yml"
  )
  for config_name in "${CONFIG_NAMES[@]}"; do
    reviewed_files+=("${SYNC_DIR}/${config_name}.yml")
  done

  for relative_path in "${reviewed_files[@]}"; do
    relative_path="${relative_path#"${REPO_ROOT}/"}"
    if ! git -C "${REPO_ROOT}" ls-files --error-unmatch -- "${relative_path}" >/dev/null 2>&1; then
      warn "Reviewed source is not tracked by Git: ${relative_path}"
      exit 1
    fi
    if ! git -C "${REPO_ROOT}" diff --quiet HEAD -- "${relative_path}"; then
      warn "Reviewed source differs from HEAD: ${relative_path}"
      exit 1
    fi
  done
}

require_site_uri
require_safe_path
require_runtime
require_maintenance_mode
require_reviewed_git_source

section 'Safety plan'
printf 'Mode: %s\n' "${MODE}"
printf 'Action: %s\n' "${ACTION}"
printf 'Drupal project: %s\n' "${DRUPAL_DIR}"
printf 'Drupal root: %s\n' "${DRUPAL_ROOT}"
printf 'Approved site origin: %s\n' "${SITE_URI}"
printf 'Git HEAD: %s\n' "$(git -C "${REPO_ROOT}" rev-parse HEAD)"
printf 'Maintenance mode: required and active\n'
printf 'Exclusive window: cron/queues and all external privileged writes must be stopped\n'
printf 'Locked runtime: Drupal 11.3.3; Webform 6.3.0-beta7; Drush 13.7.1\n'
printf 'Config entity allowlist: 14 feature names\n'
printf 'Config import: disabled (full and partial)\n'
printf 'Business content writes: disabled\n'
printf 'Drupal lifecycle metadata: internal key-value/state maintenance permitted\n'
printf 'Concurrency: one-hour feature/importer locks renewed per write phase; maintenance window\n'
if [[ "${ACTION}" == "install" ]]; then
  printf 'Existing config mutation: comment FieldConfig allowed_formats + calculated basic_html dependency\n'
  printf 'Webform lifecycle side effect: create exactly one internal serial-tracking row\n'
  printf 'Atomicity: separate durable comment transaction; atomic 14-entity feature transaction\n'
else
  printf 'Existing comment config: left exactly unchanged\n'
  printf 'Webform rollback: aliases/state/user-data/files absent; tracking row removed\n'
  printf 'Atomicity: atomic 14-entity feature rollback transaction\n'
fi
printf 'Config verification: exact default and all non-default collection states\n'
printf 'Menu and Basic-page writes: disabled\n'
if [[ "${VERIFIED_LOCAL_DDEV}" == "1" ]]; then
  printf 'Execution context: verified local DDEV web container\n'
elif [[ "${DRUPAL_DIR}" == /var/www || "${DRUPAL_DIR}" == /var/www/* ]]; then
  printf 'Execution context: explicitly acknowledged staging checkout\n'
else
  printf 'Execution context: explicit site origin on non-/var/www checkout; verify host and database\n'
fi

cd "${DRUPAL_DIR}"
UNISONGES_FORUM_BLOG_MODE="${MODE}" \
UNISONGES_FORUM_BLOG_ACTION="${ACTION}" \
UNISONGES_FORUM_BLOG_SITE_URI="${SITE_URI}" \
  "${DRUSH_CMD}" --root="${DRUPAL_ROOT}" --uri="${SITE_URI}" php:script "${PHP_HELPER}"

section 'Result'
if [[ "${MODE}" == "dry-run" ]]; then
  log 'Dry-run completed; no active configuration or content was changed.'
else
  log "Targeted ${ACTION} completed. Keep maintenance mode active, run cache:rebuild, and follow the documented procedure."
fi
