#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
DRUPAL_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"
SYNC_DIR="${DRUPAL_DIR}/config/sync"
CONFIG_NAME="block.block.unisonges_theme_messages"
CONFIG_FILE="${SYNC_DIR}/${CONFIG_NAME}.yml"
PHP_HELPER="${SCRIPT_DIR}/system-message-placement-config.php"
DRUSH_CMD="${DRUPAL_DIR}/vendor/bin/drush"

MODE="dry-run"
REQUESTED_MODE=""
ALLOW_VPS="0"
BACKUP_CONFIRMED="0"
PLAN_TOKEN=""
VERIFIED_LOCAL_DDEV="0"

log() {
  printf '[apply-system-message-placement-2026] %s\n' "$*"
}

warn() {
  printf '[apply-system-message-placement-2026] WARNING: %s\n' "$*" >&2
}

usage() {
  cat <<'EOF'
Usage: ./scripts/apply-system-message-placement-2026.sh [options]

Audits and, with explicit approval, repairs the one active system-messages
block whose reviewed sync config is already content/-8. Dry-run is the default.

Options:
  --dry-run          Audit and print the exact active-to-sync plan. Default.
  --apply            Update only region and weight on the allowlisted block.
  --backup-confirmed Confirm that a current database backup/snapshot exists.
                     Required with --apply.
  --plan-token=HASH  Bind --apply to the exact active/sync state reported by a
                     preceding dry-run. Required with --apply.
  --allow-vps        Acknowledge an independently approved checkout under
                     /var/www. This flag never authorizes VPS or production
                     access by itself.
  -h, --help         Show this help.

Examples:
  ./scripts/apply-system-message-placement-2026.sh
  ./scripts/apply-system-message-placement-2026.sh --apply \
    --backup-confirmed --plan-token=<HASH_FROM_DRY_RUN>

Safety:
  - No full or partial config import/export is run.
  - Missing, disabled, duplicate, wrong-theme, or wrong-plugin blocks refuse.
  - Only known legacy/target region and weight values are accepted.
  - Apply preserves every key except region and weight, verifies the save, and
    restores the previous values if verification fails.
  - Apply refuses without the token emitted by a preceding dry-run, and refuses
    if active or synced target configuration changed after that dry-run.
EOF
}

while (( $# > 0 )); do
  case "$1" in
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

if [[ "${MODE}" == "apply" && "${BACKUP_CONFIRMED}" != "1" ]]; then
  warn '--apply requires --backup-confirmed.'
  exit 2
fi
if [[ "${MODE}" == "dry-run" && "${BACKUP_CONFIRMED}" == "1" ]]; then
  warn '--backup-confirmed is only valid with --apply.'
  exit 2
fi
if [[ "${MODE}" == "apply" && ! "${PLAN_TOKEN}" =~ ^[a-f0-9]{64}$ ]]; then
  warn '--apply requires --plan-token=<64-lowercase-hex-hash> from dry-run.'
  exit 2
fi
if [[ "${MODE}" == "dry-run" && -n "${PLAN_TOKEN}" ]]; then
  warn '--plan-token is only valid with --apply.'
  exit 2
fi

is_verified_local_ddev() {
  [[ "${IS_DDEV_PROJECT:-}" == "true" ]] || return 1
  [[ "${DEPLOY_NAME:-}" == "local" ]] || return 1
  [[ "${DDEV_PROJECT_TYPE:-}" == "drupal11" ]] || return 1
  [[ "${DDEV_DOCROOT:-${DOCROOT:-}}" == "web" ]] || return 1
  [[ -f /mnt/ddev_config/config.yaml ]] || return 1

  local ddev_approot="${DDEV_APPROOT:-}"
  local ddev_composer_root="${DDEV_COMPOSER_ROOT:-}"
  [[ -n "${ddev_approot}" && -n "${ddev_composer_root}" ]] || return 1

  ddev_approot="$(realpath -e -- "${ddev_approot}")" || return 1
  ddev_composer_root="$(realpath -e -- "${ddev_composer_root}")" || return 1
  [[ "${ddev_approot}" == "${DRUPAL_DIR}" ]] || return 1
  [[ "${ddev_composer_root}" == "${DRUPAL_DIR}" ]] || return 1
}

case "${DRUPAL_DIR}" in
  /|/tmp|/tmp/*|/mnt/c|/mnt/c/*)
    warn "Refusing unsafe Drupal path: ${DRUPAL_DIR}"
    exit 1
    ;;
  /var/www|/var/www/*)
    if is_verified_local_ddev; then
      if [[ "${ALLOW_VPS}" == "1" ]]; then
        warn '--allow-vps is invalid inside verified local DDEV.'
        exit 2
      fi
      VERIFIED_LOCAL_DDEV="1"
    elif [[ "${ALLOW_VPS}" != "1" ]]; then
      warn "Refusing /var/www execution without --allow-vps: ${DRUPAL_DIR}"
      warn 'The flag is only a path acknowledgement for an independently approved target.'
      exit 1
    fi
    ;;
  *)
    if [[ "${ALLOW_VPS}" == "1" ]]; then
      warn '--allow-vps is only valid for an approved checkout under /var/www.'
      exit 2
    fi
    ;;
esac

if [[ ! -f "${DRUPAL_DIR}/composer.json"
  || ! -f "${DRUPAL_DIR}/web/index.php"
  || ! -x "${DRUSH_CMD}" ]]; then
  warn 'Could not verify the installed Drupal project and project-local Drush.'
  exit 1
fi
if [[ ! -d "${SYNC_DIR}"
  || -L "${DRUPAL_DIR}/config"
  || -L "${SYNC_DIR}" ]]; then
  warn "Refusing a missing or symlinked config/sync path: ${SYNC_DIR}"
  exit 1
fi
for exact_file in "${CONFIG_FILE}" "${PHP_HELPER}"; do
  if [[ ! -f "${exact_file}" || ! -r "${exact_file}" || -L "${exact_file}" ]]; then
    warn "Required target/helper must be a readable regular file: ${exact_file}"
    exit 1
  fi
done
if [[ "$(realpath -e -- "${CONFIG_FILE}")" != "${CONFIG_FILE}"
  || "$(realpath -e -- "${PHP_HELPER}")" != "${PHP_HELPER}" ]]; then
  warn 'Resolved target/helper path differs from the exact allowlisted path.'
  exit 1
fi

unset DRUSH_OPTIONS_ROOT DRUSH_OPTIONS_URI
log "Mode: ${MODE}"
log "Drupal: ${DRUPAL_DIR}"
log "Writable config allowlist: ${CONFIG_NAME} (region and weight only)"
if [[ "${VERIFIED_LOCAL_DDEV}" == "1" ]]; then
  log 'Execution context: verified local DDEV web container'
else
  log 'Execution context: canonical host or acknowledged /var/www checkout'
fi

internal_arguments=("${MODE}")
if [[ "${MODE}" == "apply" ]]; then
  internal_arguments+=("${PLAN_TOKEN}")
fi

set +e
drush_output="$("${DRUSH_CMD}" --root="${DRUPAL_DIR}/web" \
  php:script "${PHP_HELPER}" -- "${internal_arguments[@]}" 2>&1)"
drush_status=$?
set -e
printf '%s\n' "${drush_output}"
if [[ "${drush_status}" -ne 0 ]]; then
  warn "Guarded Drupal audit failed with exit ${drush_status}."
  exit "${drush_status}"
fi

if ! grep -Eq "^MODE ${MODE}$" <<<"${drush_output}"; then
  warn 'Drush returned success without the expected helper mode marker.'
  exit 1
fi
if [[ "${MODE}" == "dry-run" ]]; then
  if ! grep -Eq '^(DRY_RUN_OK|NO_CHANGE) ' <<<"${drush_output}" \
    || ! grep -Eq '^PLAN_TOKEN [a-f0-9]{64}$' <<<"${drush_output}"; then
    warn 'Dry-run returned success without its verified terminal markers.'
    exit 1
  fi
elif ! grep -Eq '^(APPLIED|NO_CHANGE) ' <<<"${drush_output}"; then
  warn 'Apply returned success without its verified terminal marker.'
  exit 1
fi

if [[ "${MODE}" == "apply" ]]; then
  log 'Rebuilding Drupal caches after the verified targeted save.'
  if ! "${DRUSH_CMD}" --root="${DRUPAL_DIR}/web" cache:rebuild; then
    warn 'Placement was saved and verified, but cache rebuild failed.'
    warn 'Investigate and rerun cache:rebuild; do not repeat apply blindly.'
    exit 1
  fi
fi

log 'Completed successfully.'
