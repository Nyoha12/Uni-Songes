#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DRUPAL_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"
REPO_ROOT="$(cd -- "${DRUPAL_DIR}/.." && pwd -P)"
DRUPAL_ROOT="${DRUPAL_DIR}/web"
PHP_HELPER="${SCRIPT_DIR}/editorial-canonical-aliases.php"

readonly SCRIPT_DIR DRUPAL_DIR REPO_ROOT DRUPAL_ROOT PHP_HELPER

MODE="dry-run"
REQUESTED_MODE=""
SITE_URI=""
SITE_URI_SEEN="0"
BACKUP_CONFIRMED="0"
EXPECT_FINGERPRINT=""

readonly -a REVIEWED_RELATIVE_FILES=(
  drupal/composer.lock
  drupal/config/sync/core.extension.yml
  drupal/config/sync/core.base_field_override.node.forum_topic.status.yml
  drupal/config/sync/node.type.article.yml
  drupal/config/sync/node.type.forum_topic.yml
  drupal/config/sync/pathauto.pattern.article.yml
  drupal/config/sync/pathauto.pattern.concert.yml
  drupal/config/sync/pathauto.pattern.forum_topic.yml
  drupal/config/sync/pathauto.pattern.stage.yml
  drupal/config/sync/pathauto.settings.yml
  drupal/config/sync/redirect.settings.yml
  drupal/config/sync/system.site.yml
  drupal/config/sync/views.view.blog_posts.yml
  drupal/config/sync/views.view.forum_topics.yml
  drupal/scripts/apply-editorial-alias-policy-2026.sh
  drupal/scripts/editorial-canonical-aliases.php
  drupal/web/modules/custom/unisonges_structure/unisonges_structure.module
)

refuse() {
  printf '[apply-editorial-alias-policy-2026] REFUSE: %s\n' "$*" >&2
}

usage() {
  cat <<'EOF'
Usage: ./scripts/apply-editorial-alias-policy-2026.sh --site-uri=ORIGIN [options]

Audit existing Article and Forum Topic aliases, or generate aliases only for
eligible entities which genuinely have none. Dry-run is the default. Drupal is
bootstrapped directly; this tool never imports configuration or invokes Drush.

Options:
  --site-uri=ORIGIN       Required approved absolute HTTP(S) root origin.
  --dry-run               Print classifications and an immutable fingerprint.
                          This is the default and never writes aliases,
                          Redirects, Pathauto state, configuration, or content.
                          Drupal may warm technical caches while reading state.
  --apply                 Generate the exact reviewed missing-alias/state plan.
  --expect-fingerprint=H  Exact 64-character fingerprint from the dry-run.
                          Required with --apply.
  --backup-confirmed      Confirm a current database backup/snapshot and an
                          exclusive writer window. Required with --apply.
  -h, --help              Show this help.

The two reviewed Pathauto patterns must already be active and exactly match
config/sync. This helper never creates, updates, deletes, or imports a pattern.
It never replaces an alias, never overrides a Pathauto opt-out, and refuses
numeric, malformed, duplicate, ambiguous, or redirect-colliding states.
For each alias it creates, it records automatic ownership through Pathauto.
EOF
}

while (( $# > 0 )); do
  case "$1" in
    --site-uri=*)
      if [[ "${SITE_URI_SEEN}" == "1" ]]; then
        refuse '--site-uri may be supplied only once.'
        exit 2
      fi
      SITE_URI_SEEN="1"
      SITE_URI="${1#--site-uri=}"
      ;;
    --site-uri)
      refuse 'Use --site-uri=https://approved.example with a non-empty value.'
      exit 2
      ;;
    --dry-run)
      if [[ "${REQUESTED_MODE}" == "apply" ]]; then
        refuse 'Use either --dry-run or --apply, not both.'
        exit 2
      fi
      REQUESTED_MODE="dry-run"
      MODE="dry-run"
      ;;
    --apply)
      if [[ "${REQUESTED_MODE}" == "dry-run" ]]; then
        refuse 'Use either --dry-run or --apply, not both.'
        exit 2
      fi
      REQUESTED_MODE="apply"
      MODE="apply"
      ;;
    --expect-fingerprint=*)
      if [[ -n "${EXPECT_FINGERPRINT}" ]]; then
        refuse '--expect-fingerprint may be supplied only once.'
        exit 2
      fi
      EXPECT_FINGERPRINT="${1#--expect-fingerprint=}"
      ;;
    --expect-fingerprint)
      refuse 'Use --expect-fingerprint=<64-lowercase-hex-hash>.'
      exit 2
      ;;
    --backup-confirmed)
      BACKUP_CONFIRMED="1"
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      refuse "Unknown argument: $1"
      usage
      exit 2
      ;;
  esac
  shift
done

if [[ "${SITE_URI_SEEN}" != "1" || -z "${SITE_URI}" ]]; then
  refuse '--site-uri=https://approved.example is required for every audit or apply.'
  exit 2
fi
if [[ "${MODE}" == "apply" && "${BACKUP_CONFIRMED}" != "1" ]]; then
  refuse '--apply requires --backup-confirmed.'
  exit 2
fi
if [[ "${MODE}" == "apply" && ! "${EXPECT_FINGERPRINT}" =~ ^[a-f0-9]{64}$ ]]; then
  refuse '--apply requires the exact dry-run --expect-fingerprint value.'
  exit 2
fi
if [[ "${MODE}" == "dry-run" && "${BACKUP_CONFIRMED}" == "1" ]]; then
  refuse '--backup-confirmed is valid only with --apply.'
  exit 2
fi
if [[ "${MODE}" == "dry-run" && -n "${EXPECT_FINGERPRINT}" ]]; then
  refuse '--expect-fingerprint is valid only with --apply.'
  exit 2
fi
if ! command -v php >/dev/null 2>&1; then
  refuse 'PHP CLI is required for the direct Drupal bootstrap.'
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
$port_number = $parts["port"] ?? null;
$is_default_port = ($scheme === "https" && $port_number === 443)
  || ($scheme === "http" && $port_number === 80);
$port = $port_number === null || $is_default_port ? "" : ":" . $port_number;
echo $scheme . "://" . $host . $port;
' "${SITE_URI}")"; then
  refuse '--site-uri must be an absolute HTTP(S) root origin without credentials, whitespace, query, or fragment.'
  exit 2
fi
SITE_URI="${normalized_site_uri}"
readonly SITE_URI

is_verified_local_ddev() {
  [[ "${IS_DDEV_PROJECT:-}" == "true" ]] || return 1
  [[ "${DEPLOY_NAME:-}" == "local" ]] || return 1
  [[ "${DDEV_PROJECT_TYPE:-}" == "drupal11" ]] || return 1
  [[ -f /mnt/ddev_config/config.yaml ]] || return 1

  local composer_root="${DDEV_COMPOSER_ROOT:-}"
  [[ -n "${composer_root}" ]] || return 1
  composer_root="$(realpath -e -- "${composer_root}")" || return 1
  [[ "${composer_root}" == "${DRUPAL_DIR}" ]]
}

case "${DRUPAL_DIR}" in
  /|/tmp|/tmp/*|/mnt/c|/mnt/c/*)
    refuse "Unsafe Drupal project path: ${DRUPAL_DIR}"
    exit 1
    ;;
  /var/www|/var/www/*)
    if ! is_verified_local_ddev; then
      refuse 'Runtime execution below /var/www is reserved for a verified local DDEV container; VPS execution is not authorized.'
      exit 1
    fi
    ;;
esac

if [[ ! -f "${DRUPAL_DIR}/composer.json"
  || ! -f "${DRUPAL_ROOT}/index.php"
  || ! -f "${DRUPAL_ROOT}/autoload.php"
  || ! -f "${DRUPAL_DIR}/vendor/autoload.php"
  || ! -f "${PHP_HELPER}" ]]; then
  refuse 'The installed project-local Drupal runtime or helper is incomplete.'
  exit 1
fi

for relative_path in "${REVIEWED_RELATIVE_FILES[@]}"; do
  exact_path="${REPO_ROOT}/${relative_path}"
  if [[ ! -f "${exact_path}" || ! -r "${exact_path}" || -L "${exact_path}" ]]; then
    refuse "Reviewed source must be a readable regular file: ${relative_path}"
    exit 1
  fi
  if [[ "$(realpath -e -- "${exact_path}")" != "${exact_path}" ]]; then
    refuse "Canonical-path guard failed for reviewed source: ${relative_path}"
    exit 1
  fi
done

git_root="$(git -C "${REPO_ROOT}" rev-parse --show-toplevel)"
git_root="$(realpath -e -- "${git_root}")"
if [[ "${git_root}" != "${REPO_ROOT}" ]]; then
  refuse "Git top level differs from the canonical repository root: ${git_root}"
  exit 1
fi
if [[ -n "$(git -C "${REPO_ROOT}" status --porcelain=v1 --untracked-files=all)" ]]; then
  refuse 'The runtime checkout must be completely clean, including untracked files.'
  exit 1
fi
for relative_path in "${REVIEWED_RELATIVE_FILES[@]}"; do
  if ! git -C "${REPO_ROOT}" ls-files --error-unmatch -- "${relative_path}" >/dev/null 2>&1; then
    refuse "Reviewed source is not tracked by Git: ${relative_path}"
    exit 1
  fi
done

GIT_HEAD="$(git -C "${REPO_ROOT}" rev-parse --verify HEAD)"
if [[ ! "${GIT_HEAD}" =~ ^[a-f0-9]{40}$ ]]; then
  refuse 'Git HEAD is not an exact 40-character object ID.'
  exit 1
fi
readonly GIT_HEAD

cd "${DRUPAL_ROOT}"
UNISONGES_EDITORIAL_ALIAS_MODE="${MODE}" \
UNISONGES_EDITORIAL_ALIAS_SITE_URI="${SITE_URI}" \
UNISONGES_EDITORIAL_ALIAS_EXPECT_FINGERPRINT="${EXPECT_FINGERPRINT}" \
UNISONGES_EDITORIAL_ALIAS_GIT_HEAD="${GIT_HEAD}" \
UNISONGES_EDITORIAL_ALIAS_BACKUP_CONFIRMED="${BACKUP_CONFIRMED}" \
  php "${PHP_HELPER}"
