#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DRUPAL_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"
REPO_ROOT="$(cd -- "${DRUPAL_DIR}/.." && pwd -P)"
readonly DRUPAL_ROOT="${DRUPAL_DIR}/web"
readonly SYNC_DIR="${DRUPAL_DIR}/config/sync"
readonly PHP_HELPER="${SCRIPT_DIR}/contact-form-mvp-config.php"
readonly COMPOSER_LOCK="${DRUPAL_DIR}/composer.lock"
readonly DRUSH_CMD="${DRUPAL_DIR}/vendor/bin/drush"

readonly -a CONFIG_NAMES=(
  webform.webform.contact
  block.block.unisonges_contact_form
)
readonly -a REVIEWED_FILES=(
  "drupal/config/sync/webform.webform.contact.yml"
  "drupal/config/sync/block.block.unisonges_contact_form.yml"
  "drupal/config/sync/language/fr/webform.webform.contact.yml"
  "drupal/scripts/apply-contact-form-mvp-2026.sh"
  "drupal/scripts/contact-form-mvp-config.php"
)

MODE="dry-run"
REQUESTED_MODE=""
ACTION="install"
ALLOW_VPS="0"
BACKUP_CONFIRMED="0"
SITE_URI=""
SITE_URI_SEEN="0"
VERIFIED_LOCAL_DDEV="0"

log() {
  printf '[apply-contact-form-mvp-2026] %s\n' "$*"
}

warn() {
  printf '[apply-contact-form-mvp-2026] WARNING: %s\n' "$*" >&2
}

section() {
  printf '\n== %s ==\n' "$*"
}

usage() {
  cat <<'EOF'
Usage: ./scripts/apply-contact-form-mvp-2026.sh --site-uri=https://approved-staging.example [options]

Audits or applies the exact Contact form MVP configuration. Dry-run is the
default. This script never runs a full or partial configuration import.

Options:
  --site-uri=ORIGIN  Required absolute HTTP(S) origin for the approved site.
                     Credentials, paths, queries and fragments are forbidden.
  --dry-run          Run every read-only prerequisite and drift check. Default.
  --apply            Write the immutable plan after a second complete preflight.
  --rollback         Select the safe rollback state instead of installation.
                     Rollback closes the Webform and disables its block; it
                     does not restore legacy handlers or delete submissions.
  --backup-confirmed Confirm that a current database backup/snapshot exists.
                     Required with --apply, including rollback.
  --allow-vps        Permit a reviewed staging checkout under /var/www. This
                     never authorizes production execution.
  -h, --help         Show this help.

Examples:
  SITE_URI='https://approved-staging.example'
  ./scripts/apply-contact-form-mvp-2026.sh --site-uri="${SITE_URI}"
  ./scripts/apply-contact-form-mvp-2026.sh --site-uri="${SITE_URI}" --apply --backup-confirmed
  ./scripts/apply-contact-form-mvp-2026.sh --site-uri="${SITE_URI}" --rollback
  ./scripts/apply-contact-form-mvp-2026.sh --site-uri="${SITE_URI}" --rollback --apply --backup-confirmed

Safety contract:
  - Exactly webform.webform.contact and
    block.block.unisonges_contact_form are writable.
  - The existing Contact Webform UUID is retained. A missing Webform, unknown
    drift, duplicate Contact form/block, unsafe role, route mismatch, missing
    dependency or UUID collision stops the run before configuration writes.
  - The tracked YAML and helper must match HEAD and must not be symlinks.
  - Apply requires Drupal maintenance mode, a current backup and an exclusive
    window with cron/queues and all other privileged writes stopped.
  - The helper acquires the persistent config-importer and feature locks,
    reruns the full preflight, and writes through config-entity lifecycles in
    one database transaction after verifying the active DatabaseStorage.
  - Contact submissions, page content, the /contact alias, roles and every
    unrelated configuration object are read-only and verified unchanged.
    Installing page=false may remove only the reviewed legacy aliases owned by
    Webform itself under /form/contact.
  - Production origins unisonges.fr and www.unisonges.fr are refused.
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
        warn '--site-uri requires a non-empty absolute HTTP(S) origin.'
        exit 2
      fi
      ;;
    --site-uri)
      warn 'Use --site-uri=https://approved-staging.example with a non-empty value.'
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
  warn '--site-uri=https://approved-staging.example is required for every invocation.'
  exit 2
fi
if [[ "${MODE}" == "apply" && "${BACKUP_CONFIRMED}" != "1" ]]; then
  warn '--apply requires --backup-confirmed.'
  exit 2
fi
if [[ "${MODE}" == "dry-run" && "${BACKUP_CONFIRMED}" == "1" ]]; then
  warn '--backup-confirmed is valid only with --apply.'
  exit 2
fi

# Explicit CLI options below are authoritative. Never inherit a Drush target.
unset DRUSH_OPTIONS_ROOT DRUSH_OPTIONS_URI

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

require_safe_paths() {
  case "${DRUPAL_DIR}" in
    /|/tmp|/tmp/*|/mnt/c|/mnt/c/*)
      warn "Refusing unsafe Drupal path: ${DRUPAL_DIR}"
      exit 1
      ;;
    /var/www|/var/www/*)
      if is_verified_local_ddev; then
        if [[ "${ALLOW_VPS}" == "1" ]]; then
          warn '--allow-vps is invalid inside a positively identified local DDEV container.'
          exit 2
        fi
        VERIFIED_LOCAL_DDEV="1"
      elif [[ "${ALLOW_VPS}" != "1" ]]; then
        warn "Refusing /var/www execution without --allow-vps: ${DRUPAL_DIR}"
        warn 'Use --allow-vps only on the reviewed staging checkout, never production.'
        exit 1
      fi
      ;;
    *)
      if [[ "${ALLOW_VPS}" == "1" ]]; then
        warn '--allow-vps is valid only for a reviewed staging checkout under /var/www.'
        exit 2
      fi
      ;;
  esac

  if [[ ! -f "${DRUPAL_DIR}/composer.json" || ! -f "${DRUPAL_ROOT}/index.php" ]]; then
    warn "Could not verify the Drupal project at ${DRUPAL_DIR}."
    exit 1
  fi
  if [[ ! -d "${SYNC_DIR}" || -L "${DRUPAL_DIR}/config" || -L "${SYNC_DIR}" ]]; then
    warn "Refusing a missing or symlinked sync directory: ${SYNC_DIR}"
    exit 1
  fi
  if [[ ! -d "${REPO_ROOT}/.git" && ! -f "${REPO_ROOT}/.git" ]]; then
    warn 'The deployment checkout must retain Git metadata for source-integrity guards.'
    exit 1
  fi
  if [[ "$(git -C "${REPO_ROOT}" rev-parse --show-toplevel)" != "${REPO_ROOT}" ]]; then
    warn 'Git top-level does not match the reviewed repository root.'
    exit 1
  fi

  local relative_path
  local absolute_path
  for relative_path in "${REVIEWED_FILES[@]}"; do
    absolute_path="${REPO_ROOT}/${relative_path}"
    if [[ ! -f "${absolute_path}" || ! -r "${absolute_path}" || -L "${absolute_path}" ]]; then
      warn "Reviewed source is missing, unreadable or a symlink: ${absolute_path}"
      exit 1
    fi
    if [[ "$(realpath -e -- "${absolute_path}")" != "${absolute_path}" ]]; then
      warn "Canonical-path guard failed: ${absolute_path}"
      exit 1
    fi
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

require_site_uri() {
  if ! command -v php >/dev/null 2>&1; then
    warn 'PHP CLI is required to validate --site-uri and composer.lock.'
    exit 1
  fi
  # Literal PHP; shell expansion would corrupt its variables.
  # shellcheck disable=SC2016
  if ! php -r '
$uri = $argv[1] ?? "";
if ($uri === "" || preg_match("/[\\x00-\\x20\\x7f]/", $uri)) {
  exit(1);
}
$parts = parse_url($uri);
$host = is_array($parts) && isset($parts["host"])
  ? rtrim(strtolower($parts["host"]), ".")
  : "";
if (!is_array($parts)
  || !filter_var($uri, FILTER_VALIDATE_URL)
  || !isset($parts["scheme"], $parts["host"])
  || !in_array(strtolower($parts["scheme"]), ["http", "https"], true)
  || $host === ""
  || isset($parts["user"])
  || isset($parts["pass"])
  || array_key_exists("query", $parts)
  || array_key_exists("fragment", $parts)
  || !in_array($parts["path"] ?? "", ["", "/"], true)
  || isset($parts["port"]) && ($parts["port"] < 1 || $parts["port"] > 65535)
) {
  exit(1);
}
if (in_array($host, ["unisonges.fr", "www.unisonges.fr"], true)) {
  exit(2);
}
' "${SITE_URI}"; then
    warn '--site-uri must be an approved non-production absolute HTTP(S) origin without credentials, path, query or fragment.'
    exit 2
  fi
}

require_runtime() {
  if [[ ! -x "${DRUSH_CMD}" ]]; then
    warn "Locked project Drush is missing or not executable: ${DRUSH_CMD}"
    warn 'Install the locked Composer dependencies before using this script.'
    exit 1
  fi
  if [[ ! -f "${DRUPAL_ROOT}/core/lib/Drupal.php" ]]; then
    warn 'The installed Drupal core runtime is missing.'
    exit 1
  fi
  if [[ ! -f "${COMPOSER_LOCK}" || ! -r "${COMPOSER_LOCK}" || -L "${COMPOSER_LOCK}" ]]; then
    warn 'composer.lock is missing, unreadable or a symlink.'
    exit 1
  fi
  if [[ "$(realpath -e -- "${COMPOSER_LOCK}")" != "${COMPOSER_LOCK}" ]]; then
    warn 'composer.lock canonical-path guard failed.'
    exit 1
  fi

  # Literal PHP; shell expansion would corrupt its variables.
  # shellcheck disable=SC2016
  if ! php -r '
$lock = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$required = [
  "drupal/core" => "11.3.3",
  "drupal/webform" => "6.3.0-beta7",
  "drush/drush" => "13.7.1",
];
$found = [];
foreach (["packages", "packages-dev"] as $section) {
  foreach (($lock[$section] ?? []) as $package) {
    $name = is_array($package) ? ($package["name"] ?? null) : null;
    if (is_string($name) && array_key_exists($name, $required)) {
      if (array_key_exists($name, $found)) {
        throw new RuntimeException("duplicate locked package: " . $name);
      }
      $found[$name] = $package["version"] ?? null;
    }
  }
}
foreach ($required as $name => $version) {
  if (($found[$name] ?? null) !== $version) {
    throw new RuntimeException($name . " must be locked at " . $version);
  }
}
' "${COMPOSER_LOCK}"; then
    warn 'composer.lock does not match the reviewed Drupal/Webform/Drush versions.'
    exit 1
  fi
}

print_plan() {
  section 'Guarded execution plan'
  printf 'Mode: %s\n' "${MODE}"
  printf 'Action: %s\n' "${ACTION}"
  printf 'Drupal project: %s\n' "${DRUPAL_DIR}"
  printf 'Approved site origin: %s\n' "${SITE_URI}"
  if [[ "${VERIFIED_LOCAL_DDEV}" == "1" ]]; then
    printf 'Execution context: positively identified local DDEV web container\n'
  elif [[ "${ALLOW_VPS}" == "1" ]]; then
    printf 'Execution context: explicitly acknowledged staging checkout under /var/www\n'
  else
    printf 'Execution context: canonical non-VPS host checkout\n'
  fi
  printf 'Writable configuration count: %d\n' "${#CONFIG_NAMES[@]}"
  printf 'Writable configuration: %s\n' "${CONFIG_NAMES[@]}"
  cat <<'EOF'
No full/partial config import or export is used. The Contact node, /contact
alias, roles, submissions and every non-allowlisted config are read-only. An
install may remove only reviewed legacy aliases owned by the Webform route.
EOF
}

require_safe_paths
require_site_uri
require_runtime
print_plan

cd -- "${DRUPAL_DIR}"
env \
  UNISONGES_CONTACT_FORM_MODE="${MODE}" \
  UNISONGES_CONTACT_FORM_ACTION="${ACTION}" \
  UNISONGES_CONTACT_FORM_SITE_URI="${SITE_URI}" \
  "${DRUSH_CMD}" \
  --root="${DRUPAL_ROOT}" \
  --uri="${SITE_URI}" \
  php:script "${PHP_HELPER}"

section 'Result'
if [[ "${MODE}" == "dry-run" ]]; then
  log 'Dry-run completed; no active configuration, content or submission was changed.'
elif [[ "${ACTION}" == "rollback" ]]; then
  log 'Safe rollback completed; the Webform is closed and its block is disabled.'
  log 'Contact submissions were retained for administrator review or deletion.'
else
  log 'Targeted Contact configuration apply completed.'
fi
