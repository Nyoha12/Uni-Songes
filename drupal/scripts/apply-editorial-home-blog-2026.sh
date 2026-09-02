#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DRUPAL_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"
REPO_ROOT="$(cd -- "${DRUPAL_DIR}/.." && pwd -P)"
DRUPAL_ROOT="${DRUPAL_DIR}/web"
SYNC_DIR="${DRUPAL_DIR}/config/sync"
PHP_HELPER="${SCRIPT_DIR}/editorial-home-blog-config.php"
MODULE_DIR="${DRUPAL_ROOT}/modules/custom/unisonges_editorial_home"

readonly SCRIPT_DIR DRUPAL_DIR REPO_ROOT DRUPAL_ROOT SYNC_DIR PHP_HELPER MODULE_DIR

MODE="dry-run"
REQUESTED_MODE=""
ACTION="install"
SITE_URI=""
SITE_URI_SEEN="0"
BACKUP_CONFIRMED="0"
PLAN_TOKEN=""
ALLOW_VPS="0"
VERIFIED_LOCAL_DDEV="0"

readonly -a REVIEWED_RELATIVE_FILES=(
  drupal/composer.lock
  drupal/config/sync/block.block.unisonges_blog_posts.yml
  drupal/config/sync/block.block.unisonges_editorial_home.yml
  drupal/config/sync/block.block.unisonges_forum_blog_proposal.yml
  drupal/config/sync/block.block.unisonges_forum_topics.yml
  drupal/config/sync/core.base_field_override.node.forum_topic.promote.yml
  drupal/config/sync/core.base_field_override.node.forum_topic.status.yml
  drupal/config/sync/core.entity_form_display.node.forum_topic.default.yml
  drupal/config/sync/core.entity_view_display.node.forum_topic.default.yml
  drupal/config/sync/core.entity_view_display.node.forum_topic.teaser.yml
  drupal/config/sync/core.extension.yml
  drupal/config/sync/field.field.comment.comment.comment_body.yml
  drupal/config/sync/field.field.node.forum_topic.body.yml
  drupal/config/sync/field.field.node.forum_topic.comment.yml
  drupal/config/sync/field.field.node.article.field_tags.yml
  drupal/config/sync/field.field.node.page.body.yml
  drupal/config/sync/field.storage.node.field_tags.yml
  drupal/config/sync/filter.format.full_html.yml
  drupal/config/sync/node.type.article.yml
  drupal/config/sync/node.type.forum_topic.yml
  drupal/config/sync/node.type.page.yml
  drupal/config/sync/system.site.yml
  drupal/config/sync/system.theme.yml
  drupal/config/sync/taxonomy.vocabulary.tags.yml
  drupal/config/sync/user.role.anonymous.yml
  drupal/config/sync/views.view.blog_posts.yml
  drupal/config/sync/views.view.forum_topics.yml
  drupal/config/sync/webform.webform.forum_blog_proposal.yml
  drupal/scripts/apply-content-architecture-2026.sh
  drupal/scripts/apply-editorial-home-blog-2026.sh
  drupal/scripts/editorial-home-blog-config.php
  drupal/web/modules/custom/unisonges_editorial_home/css/editorial-home.css
  drupal/web/modules/custom/unisonges_editorial_home/src/EditorialHomeBuilder.php
  drupal/web/modules/custom/unisonges_editorial_home/src/EditorialHomeUninstallValidator.php
  drupal/web/modules/custom/unisonges_editorial_home/src/Plugin/Block/EditorialHomeBlock.php
  drupal/web/modules/custom/unisonges_editorial_home/templates/unisonges-editorial-home.html.twig
  drupal/web/modules/custom/unisonges_editorial_home/unisonges_editorial_home.info.yml
  drupal/web/modules/custom/unisonges_editorial_home/unisonges_editorial_home.libraries.yml
  drupal/web/modules/custom/unisonges_editorial_home/unisonges_editorial_home.module
  drupal/web/modules/custom/unisonges_editorial_home/unisonges_editorial_home.services.yml
  drupal/web/themes/custom/unisonges_theme/unisonges_theme.info.yml
)

readonly -a MODULE_RELATIVE_FILES=(
  css/editorial-home.css
  src/EditorialHomeBuilder.php
  src/EditorialHomeUninstallValidator.php
  src/Plugin/Block/EditorialHomeBlock.php
  templates/unisonges-editorial-home.html.twig
  unisonges_editorial_home.info.yml
  unisonges_editorial_home.libraries.yml
  unisonges_editorial_home.module
  unisonges_editorial_home.services.yml
)

log() {
  printf '[apply-editorial-home-blog-2026] %s\n' "$*"
}

warn() {
  printf '[apply-editorial-home-blog-2026] REFUSE: %s\n' "$*" >&2
}

usage() {
  cat <<'EOF'
Usage: ./scripts/apply-editorial-home-blog-2026.sh --site-uri=ORIGIN [options]

Audit, install, or roll back the reviewed editorial Blog homepage. Dry-run is
the default. Drupal is bootstrapped directly with project-local PHP code; no
configuration import, SQL command, or secondary CLI bootstrap is used.

Options:
  --site-uri=ORIGIN  Required approved absolute HTTP(S) root origin. User info,
                     a non-root path, query, and fragment are forbidden.
  --dry-run          Validate everything and print the exact immutable plan.
                     This is the default and never writes.
  --apply            Execute the plan only after all locked guards pass.
  --rollback         Plan rollback instead of install. Add --apply to execute.
  --backup-confirmed Confirm a current database backup/snapshot. Required for
                     every --apply, including rollback.
  --plan-token=HASH  Exact 64-character token emitted by the matching dry-run.
                     Required for every --apply.
  --allow-vps        Acknowledge an independently approved checkout under
                     /var/www. This never authorizes remote or production use.
  -h, --help         Show this help.

Examples:
  ./scripts/apply-editorial-home-blog-2026.sh --site-uri=https://approved.example
  ./scripts/apply-editorial-home-blog-2026.sh --site-uri=https://approved.example \
    --apply --backup-confirmed --plan-token=<HASH_FROM_DRY_RUN>
  ./scripts/apply-editorial-home-blog-2026.sh --site-uri=https://approved.example \
    --rollback
  ./scripts/apply-editorial-home-blog-2026.sh --site-uri=https://approved.example \
    --rollback --apply --backup-confirmed --plan-token=<ROLLBACK_DRY_RUN_HASH>

Apply safety:
  - Put the site in maintenance mode and stop cron, queues, and privileged
    writers before the apply window. The PHP preflight enforces maintenance.
  - The only active config changes are views.view.blog_posts,
    block.block.unisonges_editorial_home, and the module entry in core.extension.
  - The only business-content change is the body field of the unique published
    Basic page owning /accueil. Its exact prior body and identity are retained.
  - Rollback is refused without that consistent retained copy or when another
    active config entity depends on the custom module.
EOF
}

while (( $# > 0 )); do
  case "$1" in
    --site-uri=*)
      if [[ "${SITE_URI_SEEN}" == "1" ]]; then
        warn '--site-uri may be supplied only once.'
        exit 2
      fi
      SITE_URI_SEEN="1"
      SITE_URI="${1#--site-uri=}"
      ;;
    --site-uri)
      warn 'Use --site-uri=https://approved.example with a non-empty value.'
      exit 2
      ;;
    --dry-run)
      if [[ "${REQUESTED_MODE}" == "apply" ]]; then
        warn 'Use either --dry-run or --apply, not both.'
        exit 2
      fi
      REQUESTED_MODE="dry-run"
      MODE="dry-run"
      ;;
    --apply)
      if [[ "${REQUESTED_MODE}" == "dry-run" ]]; then
        warn 'Use either --dry-run or --apply, not both.'
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
    --plan-token=*)
      if [[ -n "${PLAN_TOKEN}" ]]; then
        warn '--plan-token may be supplied only once.'
        exit 2
      fi
      PLAN_TOKEN="${1#--plan-token=}"
      ;;
    --plan-token)
      warn 'Use --plan-token=<64-lowercase-hex-hash>.'
      exit 2
      ;;
    --allow-vps)
      ALLOW_VPS="1"
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      warn "Unknown argument: $1"
      usage
      exit 2
      ;;
  esac
  shift
done

if [[ "${SITE_URI_SEEN}" != "1" || -z "${SITE_URI}" ]]; then
  warn '--site-uri=https://approved.example is required for every invocation.'
  exit 2
fi
if [[ "${MODE}" == "apply" && "${BACKUP_CONFIRMED}" != "1" ]]; then
  warn '--apply requires --backup-confirmed.'
  exit 2
fi
if [[ "${MODE}" == "apply" && ! "${PLAN_TOKEN}" =~ ^[a-f0-9]{64}$ ]]; then
  warn '--apply requires the exact --plan-token=<64-lowercase-hex-hash> from dry-run.'
  exit 2
fi
if [[ "${MODE}" == "dry-run" && "${BACKUP_CONFIRMED}" == "1" ]]; then
  warn '--backup-confirmed is valid only with --apply.'
  exit 2
fi
if [[ "${MODE}" == "dry-run" && -n "${PLAN_TOKEN}" ]]; then
  warn '--plan-token is valid only with --apply.'
  exit 2
fi

if ! command -v php >/dev/null 2>&1; then
  warn 'PHP CLI is required for the direct Drupal kernel bootstrap.'
  exit 1
fi

# This is literal PHP. Shell expansion inside it would corrupt PHP variables.
# shellcheck disable=SC2016
if ! normalized_site_uri="$(php -r '
$uri = $argv[1] ?? "";
if ($uri === "" || preg_match("/[\\x00-\\x20\\x7f]/", $uri)) {
  exit(1);
}
$parts = parse_url($uri);
if (!is_array($parts)
  || !filter_var($uri, FILTER_VALIDATE_URL)
  || !isset($parts["scheme"], $parts["host"])
  || !in_array(strtolower($parts["scheme"]), ["http", "https"], true)
  || $parts["host"] === ""
  || isset($parts["user"])
  || isset($parts["pass"])
  || isset($parts["query"])
  || isset($parts["fragment"])
  || !in_array($parts["path"] ?? "", ["", "/"], true)) {
  exit(1);
}
$scheme = strtolower($parts["scheme"]);
$host = strtolower($parts["host"]);
$port = isset($parts["port"]) ? ":" . $parts["port"] : "";
echo $scheme . "://" . $host . $port;
' "${SITE_URI}")"; then
  warn '--site-uri must be an absolute HTTP(S) root origin without credentials, whitespace, query, or fragment.'
  exit 2
fi
SITE_URI="${normalized_site_uri}"
readonly SITE_URI

is_verified_local_ddev() {
  [[ "${IS_DDEV_PROJECT:-}" == "true" ]] || return 1
  [[ "${DEPLOY_NAME:-}" == "local" ]] || return 1
  [[ "${DDEV_PROJECT_TYPE:-}" == "drupal11" ]] || return 1
  [[ -f /mnt/ddev_config/config.yaml ]] || return 1

  local approot="${DDEV_APPROOT:-}"
  local composer_root="${DDEV_COMPOSER_ROOT:-}"
  local docroot="${DDEV_DOCROOT:-${DOCROOT:-}}"
  [[ -n "${approot}" && -n "${composer_root}" ]] || return 1
  approot="$(realpath -e -- "${approot}")" || return 1
  composer_root="$(realpath -e -- "${composer_root}")" || return 1
  [[ "${composer_root}" == "${DRUPAL_DIR}" ]] || return 1

  if [[ "${approot}" == "${DRUPAL_DIR}" ]]; then
    [[ "${docroot}" == "web" ]] || return 1
  elif [[ "${approot}" == "${REPO_ROOT}" ]]; then
    [[ "${docroot}" == "drupal/web" ]] || return 1
  else
    return 1
  fi
}

case "${DRUPAL_DIR}" in
  /|/tmp|/tmp/*|/mnt/c|/mnt/c/*)
    warn "Unsafe Drupal project path: ${DRUPAL_DIR}"
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
      warn "Execution below /var/www requires the explicit staging path acknowledgement: ${DRUPAL_DIR}"
      exit 1
    fi
    ;;
  *)
    if [[ "${ALLOW_VPS}" == "1" ]]; then
      warn '--allow-vps is valid only for an independently approved checkout below /var/www.'
      exit 2
    fi
    ;;
esac

if [[ ! -f "${DRUPAL_DIR}/composer.json"
  || ! -f "${DRUPAL_ROOT}/index.php"
  || ! -f "${DRUPAL_DIR}/vendor/autoload.php"
  || ! -f "${DRUPAL_ROOT}/core/lib/Drupal/Core/DrupalKernel.php" ]]; then
  warn 'The installed project-local Drupal runtime is incomplete.'
  exit 1
fi
if [[ ! -d "${SYNC_DIR}" || -L "${DRUPAL_DIR}/config" || -L "${SYNC_DIR}" ]]; then
  warn "The config/sync directory is missing or symlinked: ${SYNC_DIR}"
  exit 1
fi
if [[ ! -d "${MODULE_DIR}" || -L "${MODULE_DIR}" ]]; then
  warn "The exact custom module directory is missing or symlinked: ${MODULE_DIR}"
  exit 1
fi
if find "${MODULE_DIR}" -type l -print -quit | grep -q .; then
  warn 'Symlinks are forbidden anywhere in the reviewed custom module.'
  exit 1
fi

mapfile -t actual_module_files < <(
  cd "${MODULE_DIR}"
  find . -type f -print | sed 's#^\./##' | LC_ALL=C sort
)
mapfile -t expected_module_files < <(printf '%s\n' "${MODULE_RELATIVE_FILES[@]}" | LC_ALL=C sort)
if [[ "${actual_module_files[*]}" != "${expected_module_files[*]}" ]]; then
  warn 'The custom module file inventory differs from the fixed reviewed allowlist.'
  printf 'Expected:\n' >&2
  printf '  %s\n' "${expected_module_files[@]}" >&2
  printf 'Found:\n' >&2
  printf '  %s\n' "${actual_module_files[@]}" >&2
  exit 1
fi

for relative_path in "${REVIEWED_RELATIVE_FILES[@]}"; do
  exact_path="${REPO_ROOT}/${relative_path}"
  if [[ ! -f "${exact_path}" || ! -r "${exact_path}" || -L "${exact_path}" ]]; then
    warn "Reviewed source must be a readable regular file: ${relative_path}"
    exit 1
  fi
  if [[ "$(realpath -e -- "${exact_path}")" != "${exact_path}" ]]; then
    warn "Canonical-path guard failed for reviewed source: ${relative_path}"
    exit 1
  fi
done

if ! git -C "${REPO_ROOT}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  warn "No Git worktree was found at ${REPO_ROOT}."
  exit 1
fi
git_root="$(git -C "${REPO_ROOT}" rev-parse --show-toplevel)"
git_root="$(realpath -e -- "${git_root}")"
if [[ "${git_root}" != "${REPO_ROOT}" ]]; then
  warn "Git top level differs from the canonical repository root: ${git_root}"
  exit 1
fi
if [[ -n "$(git -C "${REPO_ROOT}" status --porcelain=v1 --untracked-files=all)" ]]; then
  warn 'The deployment checkout must be completely clean, including untracked files.'
  exit 1
fi
for relative_path in "${REVIEWED_RELATIVE_FILES[@]}"; do
  if ! git -C "${REPO_ROOT}" ls-files --error-unmatch -- "${relative_path}" >/dev/null 2>&1; then
    warn "Reviewed source is not tracked by Git: ${relative_path}"
    exit 1
  fi
  if ! git -C "${REPO_ROOT}" diff --quiet HEAD -- "${relative_path}"; then
    warn "Reviewed source differs from Git HEAD: ${relative_path}"
    exit 1
  fi
done

GIT_HEAD="$(git -C "${REPO_ROOT}" rev-parse --verify HEAD)"
if [[ ! "${GIT_HEAD}" =~ ^[a-f0-9]{40}$ ]]; then
  warn 'Git HEAD is not an exact 40-character object ID.'
  exit 1
fi
readonly GIT_HEAD

log "Mode: ${MODE}"
log "Action: ${ACTION}"
log "Approved site origin: ${SITE_URI}"
log "Drupal root: ${DRUPAL_ROOT}"
log "Git HEAD: ${GIT_HEAD}"
log 'Writable config allowlist: views.view.blog_posts, block.block.unisonges_editorial_home, core.extension module entry'
log 'Writable content allowlist: body of the unique published Basic page at /accueil'
log 'Persistent state allowlist: unisonges_editorial_home.rollback.v1'
if [[ "${VERIFIED_LOCAL_DDEV}" == "1" ]]; then
  log 'Execution context: verified local DDEV web container'
elif [[ "${DRUPAL_DIR}" == /var/www || "${DRUPAL_DIR}" == /var/www/* ]]; then
  log 'Execution context: explicitly acknowledged /var/www checkout'
else
  log 'Execution context: canonical non-/var/www checkout'
fi

cd "${DRUPAL_ROOT}"
UNISONGES_EDITORIAL_HOME_MODE="${MODE}" \
UNISONGES_EDITORIAL_HOME_ACTION="${ACTION}" \
UNISONGES_EDITORIAL_HOME_SITE_URI="${SITE_URI}" \
UNISONGES_EDITORIAL_HOME_PLAN_TOKEN="${PLAN_TOKEN}" \
UNISONGES_EDITORIAL_HOME_GIT_HEAD="${GIT_HEAD}" \
  php "${PHP_HELPER}"

if [[ "${MODE}" == "dry-run" ]]; then
  log 'Dry-run completed; no configuration, state, module, or content write was requested.'
else
  log "Guarded ${ACTION} completed. Keep maintenance mode active for post-deployment verification."
fi
