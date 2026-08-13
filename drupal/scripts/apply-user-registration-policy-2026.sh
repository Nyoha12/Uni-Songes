#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
DRUPAL_DIR="$(cd "${SCRIPT_DIR}/.." && pwd -P)"
DRUSH="${DRUSH:-./vendor/bin/drush}"
if [[ "${DRUSH}" == /* ]]; then
  DRUSH_CMD="${DRUSH}"
else
  DRUSH_CMD="${DRUPAL_DIR}/${DRUSH}"
fi

MODE="dry-run"
REQUESTED_MODE=""
ALLOW_VPS="0"
TARGET_REGISTER="visitors"
TARGET_VERIFY_MAIL="true"

log() {
  printf '[apply-user-registration-policy-2026] %s\n' "$*"
}

warn() {
  printf '[apply-user-registration-policy-2026] WARNING: %s\n' "$*" >&2
}

section() {
  printf '\n== %s ==\n' "$*"
}

usage() {
  cat <<'EOF'
Usage: ./scripts/apply-user-registration-policy-2026.sh [--dry-run|--apply] [--allow-vps]

Audits and, with --apply, updates the active Drupal user registration policy.
Dry-run is the default. Writes require --apply.

Options:
  --dry-run    Print before, target, after, and rollback values. Default.
  --apply      Set only user.settings:register and user.settings:verify_mail.
  --allow-vps  Permit execution from /var/www paths. Required on VPS paths.
  -h, --help   Show this help.

This script never runs a full or partial config import and never writes any
configuration object or key other than the two registration keys above.
EOF
}

for arg in "$@"; do
  case "${arg}" in
    --dry-run)
      if [[ "${REQUESTED_MODE}" == "apply" ]]; then
        warn "Use either --dry-run or --apply, not both."
        usage
        exit 2
      fi
      REQUESTED_MODE="dry-run"
      MODE="dry-run"
      ;;
    --apply)
      if [[ "${REQUESTED_MODE}" == "dry-run" ]]; then
        warn "Use either --dry-run or --apply, not both."
        usage
        exit 2
      fi
      REQUESTED_MODE="apply"
      MODE="apply"
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

require_safe_path() {
  case "${DRUPAL_DIR}" in
    /mnt/c|/mnt/c/*)
      warn "Refusing to run from /mnt/c: ${DRUPAL_DIR}"
      exit 1
      ;;
    /var/www|/var/www/*)
      if [[ "${ALLOW_VPS}" != "1" ]]; then
        warn "Refusing to run from /var/www without --allow-vps: ${DRUPAL_DIR}"
        exit 1
      fi
      ;;
  esac
}

require_runtime() {
  if [[ ! -f "${DRUPAL_DIR}/composer.json" || ! -f "${DRUPAL_DIR}/web/core/lib/Drupal.php" ]]; then
    warn "Could not verify an installed Drupal codebase at ${DRUPAL_DIR}."
    exit 1
  fi
  if [[ ! -x "${DRUSH_CMD}" ]]; then
    warn "Local Drush is missing or not executable at ${DRUSH_CMD}."
    exit 1
  fi
  if ! command -v jq >/dev/null 2>&1; then
    warn "jq is required to read and verify typed active configuration values."
    exit 1
  fi
}

validate_settings_json() {
  local config_json="$1"

  printf '%s\n' "${config_json}" | jq -e '
    def exact_keys($expected):
      (keys | sort) == ($expected | sort);

    type == "object"
    and ((keys - [
      "_core",
      "anonymous",
      "cancel_method",
      "langcode",
      "notify",
      "password_reset_timeout",
      "password_strength",
      "register",
      "verify_mail"
    ]) | length == 0)
    and has("anonymous")
    and has("cancel_method")
    and has("langcode")
    and has("notify")
    and has("password_reset_timeout")
    and has("password_strength")
    and has("register")
    and has("verify_mail")
    and (.anonymous | type == "string" and length > 0)
    and (.cancel_method | type == "string")
    and (.langcode | type == "string")
    and (.password_reset_timeout | type == "number" and . == floor and . >= 1)
    and (.password_strength | type == "boolean")
    and (.register | type == "string")
    and (.verify_mail | type == "boolean")
    and (.notify |
      type == "object"
      and exact_keys([
        "cancel_confirm",
        "password_reset",
        "register_admin_created",
        "register_no_approval_required",
        "register_pending_approval",
        "status_activated",
        "status_blocked",
        "status_canceled"
      ])
      and all(.[]; type == "boolean")
    )
    and (
      (has("_core") | not)
      or (
        ._core
        | type == "object"
        and ((keys - ["default_config_hash"]) | length == 0)
        and (
          (has("default_config_hash") | not)
          or (.default_config_hash | type == "string")
        )
      )
    )
  ' >/dev/null
}

read_stored_policy() {
  local config_json
  local register_value
  local verify_mail_value
  local notify_registration_value
  local other_json

  if ! config_json="$("${DRUSH_CMD}" config:get user.settings --format=json)"; then
    warn "Could not read stored active user.settings."
    return 1
  fi
  if ! validate_settings_json "${config_json}"; then
    warn "Stored active user.settings does not match the canonical Drupal 11.3.3 schema shape and types."
    return 1
  fi

  if ! register_value="$(printf '%s\n' "${config_json}" | jq -er '.register')"; then
    warn "Could not extract stored user.settings:register."
    return 1
  fi
  if ! verify_mail_value="$(printf '%s\n' "${config_json}" | jq -er '.verify_mail | if . then "true" else "false" end')"; then
    warn "Could not extract stored user.settings:verify_mail."
    return 1
  fi
  if ! notify_registration_value="$(printf '%s\n' "${config_json}" | jq -er '.notify.register_no_approval_required | if . then "true" else "false" end')"; then
    warn "Could not extract stored registration notification policy."
    return 1
  fi
  if ! other_json="$(printf '%s\n' "${config_json}" | jq -eSc 'del(.register, .verify_mail)')"; then
    warn "Could not capture stored user.settings values outside the write allowlist."
    return 1
  fi

  STORED_REGISTER="${register_value}"
  STORED_VERIFY_MAIL="${verify_mail_value}"
  STORED_NOTIFY_REGISTRATION="${notify_registration_value}"
  STORED_OTHER_JSON="${other_json}"
}

read_effective_policy() {
  local config_json
  local register_value
  local verify_mail_value
  local notify_registration_value

  if ! config_json="$("${DRUSH_CMD}" config:get user.settings --include-overridden --format=json)"; then
    warn "Could not read effective user.settings with runtime overrides."
    return 1
  fi
  if ! validate_settings_json "${config_json}"; then
    warn "Effective user.settings does not match the canonical Drupal 11.3.3 schema shape and types."
    return 1
  fi

  if ! register_value="$(printf '%s\n' "${config_json}" | jq -er '.register')"; then
    warn "Could not extract effective user.settings:register."
    return 1
  fi
  if ! verify_mail_value="$(printf '%s\n' "${config_json}" | jq -er '.verify_mail | if . then "true" else "false" end')"; then
    warn "Could not extract effective user.settings:verify_mail."
    return 1
  fi
  if ! notify_registration_value="$(printf '%s\n' "${config_json}" | jq -er '.notify.register_no_approval_required | if . then "true" else "false" end')"; then
    warn "Could not extract effective registration notification policy."
    return 1
  fi

  EFFECTIVE_REGISTER="${register_value}"
  EFFECTIVE_VERIFY_MAIL="${verify_mail_value}"
  EFFECTIVE_NOTIFY_REGISTRATION="${notify_registration_value}"
}

print_policy() {
  local label="$1"
  local register_value="$2"
  local verify_mail_value="$3"

  section "${label}"
  printf 'register: %s\n' "${register_value}"
  printf 'verify_mail: %s\n' "${verify_mail_value}"
}

set_stored_policy() {
  local register_value="$1"
  local verify_mail_value="$2"
  local payload

  case "${register_value}" in
    visitors|admin_only|visitors_admin_approval)
      ;;
    *)
      warn "Refusing unsupported registration write value: ${register_value}"
      return 1
      ;;
  esac
  case "${verify_mail_value}" in
    true|false)
      ;;
    *)
      warn "Refusing non-boolean email verification write value: ${verify_mail_value}"
      return 1
      ;;
  esac

  if ! payload="$(jq -ecn \
    --arg register "${register_value}" \
    --argjson verify_mail "${verify_mail_value}" \
    '{verify_mail: $verify_mail, register: $register}')"; then
    warn "Could not build the allowlisted registration payload."
    return 1
  fi
  if [[ -z "${payload}" ]]; then
    warn "Refusing to call Drush with an empty registration payload."
    return 1
  fi
  if ! printf '%s\n' "${payload}" | jq -e '
    type == "object"
    and (keys | sort) == (["register", "verify_mail"] | sort)
    and (.register | type == "string")
    and (.verify_mail | type == "boolean")
  ' >/dev/null; then
    warn "Refusing a registration payload outside the exact two-key write allowlist."
    return 1
  fi

  log "Setting only user.settings:verify_mail=${verify_mail_value} and user.settings:register=${register_value} in one save."
  if ! "${DRUSH_CMD}" --yes config:set --input-format=yaml user.settings '?' "${payload}"; then
    warn "Drush could not save the allowlisted registration payload."
    return 1
  fi
}

rollback_to_safe_target() {
  warn "Restoring the printed automatic rollback target in one save."
  if ! set_stored_policy "${ROLLBACK_REGISTER}" "${ROLLBACK_VERIFY_MAIL}"; then
    warn "Could not write the automatic rollback target."
    return 1
  fi

  if ! read_stored_policy; then
    warn "Could not read stored configuration after rollback."
    return 1
  fi
  if ! read_effective_policy; then
    warn "Could not read effective configuration after rollback."
    return 1
  fi

  print_policy "After rollback (stored)" "${STORED_REGISTER}" "${STORED_VERIFY_MAIL}"
  print_policy "After rollback (effective, including overrides)" "${EFFECTIVE_REGISTER}" "${EFFECTIVE_VERIFY_MAIL}"

  if [[ "${STORED_REGISTER}" != "${ROLLBACK_REGISTER}" || "${STORED_VERIFY_MAIL}" != "${ROLLBACK_VERIFY_MAIL}" \
    || "${EFFECTIVE_REGISTER}" != "${ROLLBACK_REGISTER}" || "${EFFECTIVE_VERIFY_MAIL}" != "${ROLLBACK_VERIFY_MAIL}" \
    || "${STORED_NOTIFY_REGISTRATION}" != "true" || "${EFFECTIVE_NOTIFY_REGISTRATION}" != "true" \
    || "${STORED_OTHER_JSON}" != "${BEFORE_OTHER_JSON}" ]]; then
    warn "Automatic rollback could not establish and verify its exact safe target."
    return 1
  fi
}

require_safe_path
require_runtime
cd "${DRUPAL_DIR}"

section "Safety"
printf 'mode: %s\n' "${MODE}"
printf 'drupal_root: %s\n' "${DRUPAL_DIR}"
printf 'write_allowlist: user.settings:register, user.settings:verify_mail\n'
printf 'config_import: disabled (full and partial)\n'

read_stored_policy
read_effective_policy
BEFORE_REGISTER="${STORED_REGISTER}"
BEFORE_VERIFY_MAIL="${STORED_VERIFY_MAIL}"
BEFORE_OTHER_JSON="${STORED_OTHER_JSON}"
ROLLBACK_REGISTER="${BEFORE_REGISTER}"
ROLLBACK_VERIFY_MAIL="true"
if [[ "${BEFORE_VERIFY_MAIL}" != "true" ]]; then
  ROLLBACK_REGISTER="admin_only"
fi

print_policy "Before (stored)" "${BEFORE_REGISTER}" "${BEFORE_VERIFY_MAIL}"
print_policy "Before (effective, including overrides)" "${EFFECTIVE_REGISTER}" "${EFFECTIVE_VERIFY_MAIL}"
printf 'notify.register_no_approval_required (stored): %s\n' "${STORED_NOTIFY_REGISTRATION}"
printf 'notify.register_no_approval_required (effective): %s\n' "${EFFECTIVE_NOTIFY_REGISTRATION}"
print_policy "Target" "${TARGET_REGISTER}" "${TARGET_VERIFY_MAIL}"

section "Before values captured for audit"
printf 'register: %s\n' "${BEFORE_REGISTER}"
printf 'verify_mail: %s\n' "${BEFORE_VERIFY_MAIL}"

section "Automatic rollback target"
printf 'register: %s\n' "${ROLLBACK_REGISTER}"
printf 'verify_mail: %s\n' "${ROLLBACK_VERIFY_MAIL}"
printf 'write: one save containing both allowlisted keys\n'
if [[ "${BEFORE_VERIFY_MAIL}" != "${ROLLBACK_VERIFY_MAIL}" ]]; then
  printf 'note: fail-closed rollback replaces an unsafe initial pair with admin_only + true\n'
fi

case "${BEFORE_REGISTER}" in
  visitors|admin_only|visitors_admin_approval)
    ;;
  *)
    warn "Unsupported current user.settings:register value: ${BEFORE_REGISTER}"
    print_policy "After (unchanged)" "${BEFORE_REGISTER}" "${BEFORE_VERIFY_MAIL}"
    exit 1
    ;;
esac

case "${EFFECTIVE_REGISTER}" in
  visitors|admin_only|visitors_admin_approval)
    ;;
  *)
    warn "Unsupported effective user.settings:register value: ${EFFECTIVE_REGISTER}"
    print_policy "After (unchanged)" "${BEFORE_REGISTER}" "${BEFORE_VERIFY_MAIL}"
    exit 1
    ;;
esac

if [[ "${STORED_NOTIFY_REGISTRATION}" != "true" || "${EFFECTIVE_NOTIFY_REGISTRATION}" != "true" ]]; then
  warn "Refusing to open registration because notify.register_no_approval_required is not true in both stored and effective configuration."
  warn "That notification key is outside this script's write allowlist. Correct it in a separate reviewed change."
  print_policy "After (unchanged)" "${BEFORE_REGISTER}" "${BEFORE_VERIFY_MAIL}"
  exit 1
fi

if [[ "${EFFECTIVE_REGISTER}" != "${BEFORE_REGISTER}" || "${EFFECTIVE_VERIFY_MAIL}" != "${BEFORE_VERIFY_MAIL}" ]]; then
  warn "A runtime override changes user.settings:register or user.settings:verify_mail."
  warn "Refusing because an active-config write cannot establish the effective target policy."
  print_policy "After (unchanged)" "${BEFORE_REGISTER}" "${BEFORE_VERIFY_MAIL}"
  exit 1
fi

if [[ "${MODE}" == "dry-run" ]]; then
  print_policy "After (dry-run; stored unchanged)" "${BEFORE_REGISTER}" "${BEFORE_VERIFY_MAIL}"
  print_policy "After (dry-run; effective unchanged)" "${EFFECTIVE_REGISTER}" "${EFFECTIVE_VERIFY_MAIL}"
  log "Dry-run complete. Re-run with --apply to write the target policy."
  exit 0
fi

# A same-value runtime override cannot be identified by comparing stored and
# effective output. If verification is currently false, first close public
# registration and enable verification together, then require the effective
# verification value to be true before opening registration.
if [[ "${EFFECTIVE_VERIFY_MAIL}" != "true" ]]; then
  log "Establishing a closed-registration, verified-email guard policy before opening registration."
  if ! set_stored_policy admin_only true; then
    warn "Could not save the guard policy."
    rollback_to_safe_target || warn "Automatic rollback was incomplete; use the printed rollback target."
    exit 1
  fi
  if ! read_stored_policy || ! read_effective_policy; then
    warn "Could not verify the guard policy."
    rollback_to_safe_target || warn "Automatic rollback was incomplete; use the printed rollback target."
    exit 1
  fi
  print_policy "Guard (stored)" "${STORED_REGISTER}" "${STORED_VERIFY_MAIL}"
  print_policy "Guard (effective, including overrides)" "${EFFECTIVE_REGISTER}" "${EFFECTIVE_VERIFY_MAIL}"
  if [[ "${STORED_REGISTER}" != "admin_only" || "${STORED_VERIFY_MAIL}" != "true" \
    || "${EFFECTIVE_REGISTER}" != "admin_only" || "${EFFECTIVE_VERIFY_MAIL}" != "true" \
    || "${STORED_NOTIFY_REGISTRATION}" != "true" || "${EFFECTIVE_NOTIFY_REGISTRATION}" != "true" \
    || "${STORED_OTHER_JSON}" != "${BEFORE_OTHER_JSON}" ]]; then
    warn "The effective guard policy is not safe; registration will not be opened."
    rollback_to_safe_target || warn "Automatic rollback was incomplete; use the printed rollback target."
    exit 1
  fi
fi

# Canonical preflight types prevent schema casting from changing another
# user.settings value when both allowlisted keys are saved together.
if [[ "${BEFORE_REGISTER}" != "${TARGET_REGISTER}" || "${BEFORE_VERIFY_MAIL}" != "${TARGET_VERIFY_MAIL}" ]]; then
  if ! set_stored_policy "${TARGET_REGISTER}" "${TARGET_VERIFY_MAIL}"; then
    warn "Could not save the target registration policy."
    rollback_to_safe_target || warn "Automatic rollback was incomplete; use the printed rollback target."
    if read_stored_policy; then
      print_policy "After (failed apply)" "${STORED_REGISTER}" "${STORED_VERIFY_MAIL}"
    fi
    exit 1
  fi
fi

if ! read_stored_policy || ! read_effective_policy; then
  warn "Could not verify stored and effective configuration after apply."
  rollback_to_safe_target || warn "Automatic rollback was incomplete; use the printed rollback target."
  exit 1
fi

print_policy "After (stored)" "${STORED_REGISTER}" "${STORED_VERIFY_MAIL}"
print_policy "After (effective, including overrides)" "${EFFECTIVE_REGISTER}" "${EFFECTIVE_VERIFY_MAIL}"
printf 'notify.register_no_approval_required (stored): %s\n' "${STORED_NOTIFY_REGISTRATION}"
printf 'notify.register_no_approval_required (effective): %s\n' "${EFFECTIVE_NOTIFY_REGISTRATION}"

if [[ "${STORED_REGISTER}" != "${TARGET_REGISTER}" || "${STORED_VERIFY_MAIL}" != "${TARGET_VERIFY_MAIL}" \
  || "${EFFECTIVE_REGISTER}" != "${TARGET_REGISTER}" || "${EFFECTIVE_VERIFY_MAIL}" != "${TARGET_VERIFY_MAIL}" \
  || "${STORED_NOTIFY_REGISTRATION}" != "true" || "${EFFECTIVE_NOTIFY_REGISTRATION}" != "true" ]]; then
  warn "Post-apply stored or effective registration policy does not match the safe target."
  rollback_to_safe_target || warn "Automatic rollback was incomplete; use the printed rollback target."
  exit 1
fi

if [[ "${STORED_OTHER_JSON}" != "${BEFORE_OTHER_JSON}" ]]; then
  warn "A user.settings value outside the registration allowlist changed during apply."
  rollback_to_safe_target || warn "Automatic rollback was incomplete; use the printed rollback target."
  exit 1
fi

log "Apply complete. Visitor registration requires email verification and no administrator approval."
