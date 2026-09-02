#!/usr/bin/env bash
# shellcheck disable=SC2016 # Child Python/PHP snippets expand variables in their own processes.
set -euo pipefail

# Resolve every helper from a fixed system path, including when a privileged
# operator invokes the checker from a deployment account with a modified PATH.
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH

PROGRAM_NAME="check-web-runtime-permissions-2026"
SCRIPT_DIR_INPUT="$(dirname -- "${BASH_SOURCE[0]}")"
PROJECT_ROOT_INPUT="${SCRIPT_DIR_INPUT}/.."
WEB_USER=""
WEB_USER_EXPLICIT=0
PHP_BIN_INPUT="${UNISONGES_PHP_BIN:-}"

FAILURES=0
WARNINGS=0
LIMITATIONS=0
TRACKED_FILES=0
TRACKED_EXECUTABLES=0
RUNTIME_FILES=0
WEB_RUNTIME_FILES=0
TOOLING_FILES=0
EXECUTABLE_RUNTIME_FILES=0
EXECUTABLE_SHELL_FILES=0
JSON_FILES=0
PHP_CANDIDATES=0
PHP_FILES_LINTED=0
DIRECTORIES_CHECKED=0
IDENTITY_LIMITATION_RECORDED=0
LOCAL_GROUP_MEMBERSHIP_COMPLETE=0

declare -A WEB_GROUP_IDS=()
declare -A CHECKED_DIRECTORY_METADATA=()
declare -A CHECKED_WEB_DIRECTORIES=()
declare -A CHECKED_TOOL_DIRECTORIES=()
declare -A CHECKED_RUNTIME_PATHS=()
declare -a GIT_ENTRIES=()

# Do not let caller-supplied repository selectors redirect read-only Git queries.
unset CDPATH
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_COMMON_DIR
unset GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES
unset GIT_CEILING_DIRECTORIES GIT_DISCOVERY_ACROSS_FILESYSTEM
unset GIT_CONFIG_COUNT GIT_CONFIG_PARAMETERS GIT_CONFIG_GLOBAL GIT_CONFIG_SYSTEM
unset GIT_CONFIG_NOSYSTEM GIT_EXEC_PATH
unset GIT_TRACE GIT_TRACE_PACKET GIT_TRACE_PERFORMANCE GIT_TRACE_SETUP
unset GIT_TRACE_SHALLOW GIT_TRACE_CURL GIT_TRACE_CURL_NO_DATA
unset GIT_TRACE_REFS GIT_TRACE_FSMONITOR GIT_TRACE_PACK_ACCESS GIT_TRACE_PACKFILE
unset GIT_TRACE2 GIT_TRACE2_EVENT GIT_TRACE2_PERF GIT_TRACE_REDACT
unset GIT_REDIRECT_STDIN GIT_REDIRECT_STDOUT GIT_REDIRECT_STDERR

log() {
  printf '[%s] %s\n' "${PROGRAM_NAME}" "$*"
}

git_readonly() {
  GIT_NO_LAZY_FETCH=1 command git --no-optional-locks \
    -c core.fsmonitor=false \
    -c maintenance.auto=false \
    -c trace2.normalTarget=0 \
    -c trace2.perfTarget=0 \
    -c trace2.eventTarget=0 \
    "$@"
}

nss_database_is_files_only() {
  local requested_database="$1"
  local line
  local database
  local sources
  local matches=0

  [[ -r /etc/nsswitch.conf ]] || return 2
  while IFS= read -r line || [[ -n "${line}" ]]; do
    line="${line%%#*}"
    if [[ ! "${line}" =~ ^[[:space:]]*([[:alnum:]_-]+)[[:space:]]*:[[:space:]]*(.*)$ ]]; then
      continue
    fi
    database="${BASH_REMATCH[1]}"
    [[ "${database}" == "${requested_database}" ]] || continue
    matches=$((matches + 1))
    sources="${BASH_REMATCH[2]}"
    [[ "${sources}" =~ ^[[:space:]]*files[[:space:]]*$ ]] || return 1
  done </etc/nsswitch.conf

  if ((matches == 0)); then
    return 3
  fi
  ((matches == 1)) || return 2
}

local_group_membership_is_complete() {
  local group_status
  local initgroups_status

  if nss_database_is_files_only initgroups; then
    return 0
  else
    initgroups_status=$?
  fi

  # An absent initgroups database uses the group database. Any configured,
  # non-files source can add memberships that this network-free checker cannot
  # enumerate safely.
  if ((initgroups_status == 3)); then
    if nss_database_is_files_only group; then
      return 0
    else
      group_status=$?
      return "${group_status}"
    fi
  fi
  return "${initgroups_status}"
}

record_warning() {
  WARNINGS=$((WARNINGS + 1))
  printf '[%s] WARNING: %s\n' "${PROGRAM_NAME}" "$*" >&2
}

record_failure() {
  FAILURES=$((FAILURES + 1))
  printf '[%s] FAIL: %s\n' "${PROGRAM_NAME}" "$*" >&2
}

record_limitation() {
  LIMITATIONS=$((LIMITATIONS + 1))
  printf '[%s] LIMITATION: %s\n' "${PROGRAM_NAME}" "$*" >&2
}

die() {
  printf '[%s] REFUSED: %s\n' "${PROGRAM_NAME}" "$*" >&2
  exit 2
}

section() {
  printf '\n== %s ==\n' "$*"
}

usage() {
  cat <<'EOF'
Usage: ./scripts/check-web-runtime-permissions-2026.sh [options]

Read-only preflight for tracked Drupal runtime-file permissions and PHP syntax.
It derives the Drupal project root from the script location by default.

Options:
  --project-root PATH  Drupal project root to inspect. The path and every path
                       component must be real directories, never symlinks.
  --web-user USER      Runtime account whose read/traversal access is checked.
                       Defaults to www-data only when that account exists.
  -h, --help           Show this help.

Environment:
  UNISONGES_PHP_BIN    Absolute path to the PHP CLI matching the deployed
                       project runtime. Defaults to php resolved from PATH.

Exit status:
  0  web-user access was demonstrated and every selected check passed
  1  definite runtime readability, path-integrity, or PHP syntax failure
  2  refused root/arguments or an indeterminate verification environment

The checker has no repair mode and performs no deployment or service action.
EOF
}

path_contains_symlink_component() {
  local input_path="$1"
  local absolute_path
  local component
  local current_path="/"
  local remainder

  if [[ "${input_path}" == /* ]]; then
    absolute_path="${input_path}"
  else
    absolute_path="${PWD}/${input_path}"
  fi

  remainder="${absolute_path#/}"
  while [[ -n "${remainder}" ]]; do
    if [[ "${remainder}" == */* ]]; then
      component="${remainder%%/*}"
      remainder="${remainder#*/}"
    else
      component="${remainder}"
      remainder=""
    fi

    case "${component}" in
      ''|.)
        continue
        ;;
      ..)
        if [[ "${current_path}" != "/" ]]; then
          current_path="${current_path%/*}"
          [[ -n "${current_path}" ]] || current_path="/"
        fi
        ;;
      *)
        if [[ "${current_path}" == "/" ]]; then
          current_path="/${component}"
        else
          current_path="${current_path}/${component}"
        fi
        if [[ -L "${current_path}" ]]; then
          return 0
        fi
        ;;
    esac
  done

  return 1
}

canonical_directory() {
  local input_path="$1"
  (
    CDPATH='' cd -- "${input_path}" >/dev/null 2>&1 || return 1
    pwd -P
  )
}

display_path() {
  printf '%q' "$1"
}

display_diagnostic() {
  printf '%q' "$1"
}

runtime_kind() {
  local relative_path="$1"
  local index_mode="$2"

  case "${relative_path}" in
    docs/*|*/docs/*|*/test/*|*/tests/*|*/Tests/*)
      return 1
      ;;
  esac

  case "${relative_path}" in
    web/*.php|web/*.module|web/*.theme|web/*.install|web/*.inc|web/*.profile|web/*.engine|scripts/*.php)
      printf 'PHP'
      ;;
    web/*.twig)
      printf 'Twig'
      ;;
    web/*.yml|web/*.yaml|config/*.yml|config/*.yaml|recipes/*.yml|recipes/*.yaml)
      printf 'YAML'
      ;;
    web/*.css)
      printf 'CSS'
      ;;
    web/*.js|web/*.mjs|web/*.cjs)
      printf 'JavaScript'
      ;;
    web/modules/custom/*.json|web/themes/custom/*.json|web/profiles/custom/*.json|web/sites/*.json)
      printf 'JSON'
      ;;
    scripts/*.sh)
      if [[ "${index_mode}" == "100755" ]]; then
        printf 'Executable shell'
      else
        return 1
      fi
      ;;
    *)
      return 1
      ;;
  esac
}

access_scope_for_path() {
  case "$1" in
    web/*)
      printf 'web runtime'
      ;;
    *)
      printf 'deployment/runtime tooling'
      ;;
  esac
}

set_web_group_set_from_numeric_list() {
  local group_list="$1"
  local group_id
  local -a group_ids=()
  local -a unique_groups=()

  read -r -a group_ids <<<"${group_list}"
  ((${#group_ids[@]} > 0)) || return 2
  WEB_GROUP_IDS=()
  for group_id in "${group_ids[@]}"; do
    [[ "${group_id}" =~ ^[0-9]+$ ]] || return 2
    if [[ -z "${WEB_GROUP_IDS[${group_id}]+present}" ]]; then
      WEB_GROUP_IDS["${group_id}"]=1
      unique_groups+=("${group_id}")
    fi
  done
  WEB_GROUP_OUTPUT="${unique_groups[*]}"
  WEB_GROUP_CSV="$(IFS=,; printf '%s' "${unique_groups[*]}")"
}

load_local_web_identity() {
  local passwd_entry
  local account_name
  local _account_password
  local _account_gecos
  local _account_home
  local _account_shell
  local account_extra
  local group_database
  local _group_name
  local _group_password
  local group_id
  local group_members
  local group_extra
  local member
  local -a members=()
  local -a resolved_groups=()

  if ! passwd_entry="$(getent -s files passwd "${WEB_USER}" 2>/dev/null)"; then
    return 1
  fi
  IFS=: read -r account_name _account_password WEB_UID WEB_GID _account_gecos \
    _account_home _account_shell account_extra <<<"${passwd_entry}"
  [[ -z "${account_extra:-}" && "${account_name}" == "${WEB_USER}" \
    && "${WEB_UID}" =~ ^[0-9]+$ && "${WEB_GID}" =~ ^[0-9]+$ ]] || return 2

  if ! group_database="$(getent -s files group 2>/dev/null)"; then
    return 2
  fi
  WEB_GROUP_IDS["${WEB_GID}"]=1
  resolved_groups=("${WEB_GID}")
  while IFS=: read -r _group_name _group_password group_id group_members group_extra; do
    [[ -z "${group_extra:-}" && "${group_id}" =~ ^[0-9]+$ ]] || return 2
    members=()
    if [[ -n "${group_members}" ]]; then
      IFS=, read -r -a members <<<"${group_members}"
    fi
    for member in "${members[@]}"; do
      if [[ "${member}" == "${WEB_USER}" && -z "${WEB_GROUP_IDS[${group_id}]+present}" ]]; then
        WEB_GROUP_IDS["${group_id}"]=1
        resolved_groups+=("${group_id}")
      fi
    done
  done <<<"${group_database}"

  WEB_GROUP_OUTPUT="${resolved_groups[*]}"
  WEB_GROUP_CSV="$(IFS=,; printf '%s' "${resolved_groups[*]}")"
}

web_user_has_mode_access() {
  local object_uid="$1"
  local object_gid="$2"
  local object_mode="$3"
  local access_type="$4"
  local owner_mask
  local group_mask
  local other_mask
  local mode_value
  local group_allowed=0
  local other_allowed=0

  mode_value=$((8#${object_mode}))

  if [[ "${WEB_UID}" == "0" ]]; then
    return 2
  fi

  case "${access_type}" in
    read)
      owner_mask=$((8#400))
      group_mask=$((8#040))
      other_mask=$((8#004))
      ;;
    traverse)
      owner_mask=$((8#100))
      group_mask=$((8#010))
      other_mask=$((8#001))
      ;;
    *)
      return 2
      ;;
  esac

  if [[ "${WEB_UID}" == "${object_uid}" ]]; then
    ((mode_value & owner_mask))
    return
  fi

  if ((LOCAL_GROUP_MEMBERSHIP_COMPLETE == 0)); then
    if ((mode_value & group_mask)); then
      group_allowed=1
    fi
    if ((mode_value & other_mask)); then
      other_allowed=1
    fi
    if ((group_allowed == other_allowed)); then
      ((group_allowed == 1))
      return
    fi
    return 2
  fi

  if [[ -n "${WEB_GROUP_IDS[${object_gid}]+present}" ]]; then
    ((mode_value & group_mask))
    return
  fi

  ((mode_value & other_mask))
}

current_file_open_probe() {
  local object_path="$1"

  if (exec 3< "${object_path}") 2>/dev/null; then
    return 0
  fi
  return 1
}

direct_access_probe() {
  local object_path="$1"
  local access_type="$2"

  case "${access_type}" in
    read)
      current_file_open_probe "${object_path}"
      ;;
    traverse)
      (cd -- "${object_path}" 2>/dev/null)
      ;;
    *)
      return 2
      ;;
  esac
}

object_access_metadata_kind() {
  local object_path="$1"
  local long_listing
  local marker

  if ! long_listing="$(LC_ALL=C ls -ldn -- "${object_path}" 2>/dev/null)"; then
    return 2
  fi

  marker="${long_listing:10:1}"
  case "${marker}" in
    +)
      printf 'acl'
      ;;
    .)
      printf 'security-context'
      ;;
    ' ')
      printf 'none'
      ;;
    *)
      printf 'unknown'
      ;;
  esac
}

inspect_mandatory_access_context() {
  local apparmor_enabled="N"
  local current_security_context=""
  local selinux_enforcing="0"

  if [[ -r /sys/fs/selinux/enforce ]]; then
    if ! read -r selinux_enforcing </sys/fs/selinux/enforce; then
      record_limitation "cannot read the active SELinux enforcement state."
    elif [[ "${selinux_enforcing}" == "1" ]]; then
      record_limitation "SELinux is enforcing; this identity probe does not reproduce the PHP-FPM process domain."
    fi
  fi

  if [[ -r /sys/module/apparmor/parameters/enabled ]]; then
    if ! read -r apparmor_enabled </sys/module/apparmor/parameters/enabled; then
      record_limitation "cannot read the active AppArmor state."
      return 0
    fi
  fi
  [[ "${apparmor_enabled}" == "Y" ]] || return 0

  if [[ ! -r /proc/self/attr/current ]] \
    || ! read -r current_security_context </proc/self/attr/current; then
    record_limitation "AppArmor is enabled but the checker's current profile cannot be determined."
  elif [[ "${current_security_context}" != "unconfined" ]]; then
    record_limitation "AppArmor profile ${current_security_context} does not prove the PHP-FPM service profile."
  else
    record_limitation "AppArmor is enabled; an unconfined probe does not prove the PHP-FPM service profile."
  fi
}

record_identity_limitation_once() {
  if ((IDENTITY_LIMITATION_RECORDED == 0)); then
    IDENTITY_LIMITATION_RECORDED=1
    record_limitation "$*"
  fi
}

kernel_web_access_probe() {
  local object_path="$1"
  local access_type="$2"
  local probe_status

  if "${ENV_BIN}" -i PATH=/usr/sbin:/usr/bin:/sbin:/bin \
    "${PYTHON_BIN}" -I -S -B -c '
import os
import sys

uid = int(sys.argv[1])
gid = int(sys.argv[2])
groups = [int(value) for value in sys.argv[3].split(",") if value]
action = sys.argv[4]
path = sys.argv[5]

try:
    os.setgroups(groups)
    os.setresgid(gid, gid, gid)
    os.setresuid(uid, uid, uid)
except (OSError, ValueError):
    raise SystemExit(11)

if os.getresuid() != (uid, uid, uid) or os.getresgid() != (gid, gid, gid):
    raise SystemExit(11)
if set(os.getgroups()) != set(groups):
    raise SystemExit(11)

try:
    with open("/proc/self/status", "r", encoding="ascii") as status_file:
        status = {
            fields[0].rstrip(":"): fields[1:]
            for fields in (line.split() for line in status_file)
            if fields
        }
    if status.get("Uid") != [str(uid)] * 4 or status.get("Gid") != [str(gid)] * 4:
        raise SystemExit(11)
    if any(int(status.get(field, ["1"])[0], 16) != 0 for field in ("CapInh", "CapPrm", "CapEff", "CapAmb")):
        raise SystemExit(11)
except (OSError, ValueError):
    raise SystemExit(11)

if action == "identity":
    raise SystemExit(0)

try:
    if action == "read":
        flags = os.O_RDONLY | os.O_CLOEXEC
        if hasattr(os, "O_NOFOLLOW"):
            flags |= os.O_NOFOLLOW
        descriptor = os.open(path, flags)
        os.close(descriptor)
    elif action == "traverse":
        os.chdir(path)
    else:
        raise SystemExit(11)
except PermissionError:
    raise SystemExit(10)
except OSError:
    raise SystemExit(11)
' "${WEB_UID}" "${WEB_GID}" "${WEB_GROUP_CSV}" \
    "${access_type}" "${object_path}" 2>/dev/null; then
    return 0
  else
    probe_status=$?
  fi

  if ((probe_status == 10)); then
    return 1
  fi
  return 2
}

verify_web_user_access() {
  local object_path="$1"
  local object_uid="$2"
  local object_gid="$3"
  local object_mode="$4"
  local access_type="$5"
  local description="$6"
  local shown="$7"
  local metadata_kind
  local metadata_status
  local mode_status
  local probe_status

  [[ -n "${WEB_USER}" ]] || return 0

  if metadata_kind="$(object_access_metadata_kind "${object_path}")"; then
    metadata_status=0
  else
    metadata_status=$?
    metadata_kind="unavailable"
  fi

  if [[ "${ACCESS_PROOF_MODE}" == "direct" ]]; then
    if ! direct_access_probe "${object_path}" "${access_type}"; then
      if [[ "${access_type}" == "read" ]]; then
        record_failure "web user ${WEB_USER} cannot open ${description} for reading (mode ${object_mode}): ${shown}"
      else
        record_failure "web user ${WEB_USER} cannot traverse ${description} (mode ${object_mode}): ${shown}"
      fi
    fi
    if ((metadata_status != 0)); then
      record_limitation "cannot determine ACL/security metadata for ${description}: ${shown}"
    elif [[ "${metadata_kind}" == "security-context" || "${metadata_kind}" == "unknown" ]]; then
      record_limitation "${metadata_kind} metadata prevents a complete mandatory-access-control proof for ${description}: ${shown}"
    fi
    return 0
  fi

  if [[ "${ACCESS_PROOF_MODE}" == "credential-drop" ]]; then
    if kernel_web_access_probe "${object_path}" "${access_type}"; then
      probe_status=0
    else
      probe_status=$?
    fi
    if ((probe_status == 0)); then
      if ((metadata_status != 0)); then
        record_limitation "cannot determine ACL/security metadata for ${description}: ${shown}"
      elif [[ "${metadata_kind}" == "security-context" || "${metadata_kind}" == "unknown" ]]; then
        record_limitation "${metadata_kind} metadata prevents a complete mandatory-access-control proof for ${description}: ${shown}"
      fi
      return 0
    fi
    if ((probe_status == 1)); then
      if [[ "${access_type}" == "read" ]]; then
        record_failure "web user ${WEB_USER} cannot read ${description} mode ${object_mode}: ${shown}"
      else
        record_failure "web user ${WEB_USER} cannot traverse ${description} mode ${object_mode}: ${shown}"
      fi
      return 0
    fi

    ACCESS_PROOF_MODE="unavailable"
    record_identity_limitation_once "the privileged credential-drop probe failed; effective ${WEB_USER} access is indeterminate."
  fi

  if ((metadata_status != 0)); then
    record_limitation "cannot determine ACL/security metadata for ${description}: ${shown}"
    return 0
  fi

  if [[ "${metadata_kind}" != "none" ]]; then
    record_limitation "${metadata_kind} metadata prevents a conclusive ${WEB_USER} ${access_type} check for ${description}: ${shown}"
    return 0
  fi

  if web_user_has_mode_access "${object_uid}" "${object_gid}" "${object_mode}" "${access_type}"; then
    mode_status=0
  else
    mode_status=$?
  fi
  if ((mode_status == 1)); then
    if [[ "${access_type}" == "read" ]]; then
      record_failure "web user ${WEB_USER} cannot read ${description} mode ${object_mode}: ${shown}"
    else
      record_failure "web user ${WEB_USER} cannot traverse ${description} mode ${object_mode}: ${shown}"
    fi
  elif ((mode_status == 2)); then
    record_identity_limitation_once "local NSS metadata does not prove the complete supplementary-group set for web UID ${WEB_UID}."
  else
    record_identity_limitation_once "checker UID ${CURRENT_UID} cannot impersonate web UID ${WEB_UID}; permitted mode bits alone do not prove effective access."
  fi
}

check_directory_once() {
  local directory="$1"
  local access_scope="$2"
  local parent_directory
  local metadata
  local metadata_first=0
  local mode
  local object_uid
  local object_gid
  local mode_value
  local shown

  case "${access_scope}" in
    'web runtime')
      if [[ -n "${CHECKED_WEB_DIRECTORIES[${directory}]+present}" ]]; then
        return 0
      fi
      CHECKED_WEB_DIRECTORIES["${directory}"]=1
      ;;
    'deployment/runtime tooling')
      if [[ -n "${CHECKED_TOOL_DIRECTORIES[${directory}]+present}" ]]; then
        return 0
      fi
      CHECKED_TOOL_DIRECTORIES["${directory}"]=1
      ;;
    *)
      record_failure "unexpected directory access scope: ${access_scope}"
      return 0
      ;;
  esac

  if [[ -z "${CHECKED_DIRECTORY_METADATA[${directory}]+present}" ]]; then
    CHECKED_DIRECTORY_METADATA["${directory}"]=1
    DIRECTORIES_CHECKED=$((DIRECTORIES_CHECKED + 1))
    metadata_first=1
  fi
  shown="$(display_path "${directory}")"

  if [[ -L "${directory}" ]]; then
    record_failure "directory path is a symlink: ${shown}"
    return 0
  fi
  if [[ ! -d "${directory}" ]]; then
    parent_directory="${directory%/*}"
    [[ -n "${parent_directory}" ]] || parent_directory="/"
    if [[ -e "${directory}" || -x "${parent_directory}" ]]; then
      record_failure "parent directory is missing or not a directory: ${shown}"
    else
      record_limitation "checker cannot establish whether parent directory exists: ${shown}"
    fi
    return 0
  fi
  if ! metadata="$(stat -c '%a %u %g' -- "${directory}" 2>/dev/null)"; then
    record_limitation "cannot read directory metadata: ${shown}"
    return 0
  fi
  read -r mode object_uid object_gid <<<"${metadata}"
  if [[ ! "${mode}" =~ ^[0-7]{1,4}$ || ! "${object_uid}" =~ ^[0-9]+$ || ! "${object_gid}" =~ ^[0-9]+$ ]]; then
    record_limitation "unexpected directory metadata for ${shown}"
    return 0
  fi

  mode_value=$((8#${mode}))
  printf -v mode '%03o' "${mode_value}"
  if ((metadata_first)); then
    if (( (mode_value & 8#111) == 0 )); then
      record_failure "directory has no traversal bit (mode ${mode}): ${shown}"
    fi
    if (( (mode_value & 8#077) == 0 )); then
      record_warning "owner-only directory mode ${mode}: ${shown}"
    fi
    if (( mode_value & 8#022 )); then
      record_warning "group/other-writable directory mode ${mode}: ${shown}"
    fi
  fi

  if [[ "${access_scope}" == "web runtime" ]]; then
    verify_web_user_access "${directory}" "${object_uid}" "${object_gid}" "${mode}" traverse "directory" "${shown}"
  elif [[ ! -x "${directory}" ]]; then
    record_limitation "checker identity cannot traverse tooling directory mode ${mode}: ${shown}"
  fi

  log "directory checked scope=${access_scope} mode=${mode} path=${shown}"
}

check_parent_chain() {
  local file_path="$1"
  local access_scope="$2"
  local directory="${file_path%/*}"
  local component
  local current_path="/"
  local remainder

  check_directory_once "/" "${access_scope}"
  remainder="${directory#/}"
  while [[ -n "${remainder}" ]]; do
    if [[ "${remainder}" == */* ]]; then
      component="${remainder%%/*}"
      remainder="${remainder#*/}"
    else
      component="${remainder}"
      remainder=""
    fi
    [[ -n "${component}" ]] || continue
    if [[ "${current_path}" == "/" ]]; then
      current_path="/${component}"
    else
      current_path="${current_path}/${component}"
    fi
    check_directory_once "${current_path}" "${access_scope}"
  done
}

assert_project_root_stable() {
  local current_project_root
  local current_project_identity
  local current_repo_input
  local current_repo_root
  local current_repo_identity
  local current_project_mount_id
  local current_repo_mount_id

  if path_contains_symlink_component "${PROJECT_ROOT_INPUT}"; then
    die "project path gained a symlink component during validation."
  fi
  if ! current_project_root="$(canonical_directory "${PROJECT_ROOT_INPUT}")"; then
    die "project root became inaccessible during validation."
  fi
  [[ "${current_project_root}" == "${PROJECT_ROOT}" ]] || die "project root canonical path changed during validation."
  if ! current_project_identity="$(stat -c '%d:%i' -- "${PROJECT_ROOT}" 2>/dev/null)"; then
    die "project root identity became unavailable during validation."
  fi
  [[ "${current_project_identity}" == "${PROJECT_ROOT_IDENTITY}" ]] || die "project root device/inode changed during validation."
  if ! current_project_mount_id="$(findmnt --noheadings --output ID --target "${PROJECT_ROOT}" 2>/dev/null)"; then
    die "project root mount identity became unavailable during validation."
  fi
  [[ "${current_project_mount_id}" == "${PROJECT_MOUNT_ID}" ]] || die "project root mount identity changed during validation."

  if ! current_repo_input="$(git_readonly -C "${PROJECT_ROOT}" rev-parse --show-toplevel 2>/dev/null)"; then
    die "Git worktree metadata became inaccessible during validation."
  fi
  if path_contains_symlink_component "${current_repo_input}"; then
    die "Git worktree root gained a symlink component during validation."
  fi
  if ! current_repo_root="$(canonical_directory "${current_repo_input}")"; then
    die "Git worktree root became inaccessible during validation."
  fi
  [[ "${current_repo_root}" == "${REPO_ROOT}" ]] || die "Git worktree root changed during validation."
  if ! current_repo_identity="$(stat -c '%d:%i' -- "${REPO_ROOT}" 2>/dev/null)"; then
    die "Git worktree identity became unavailable during validation."
  fi
  [[ "${current_repo_identity}" == "${REPO_ROOT_IDENTITY}" ]] || die "Git worktree device/inode changed during validation."
  if ! current_repo_mount_id="$(findmnt --noheadings --output ID --target "${REPO_ROOT}" 2>/dev/null)"; then
    die "Git worktree mount identity became unavailable during validation."
  fi
  [[ "${current_repo_mount_id}" == "${REPO_MOUNT_ID}" ]] || die "Git worktree mount identity changed during validation."
}

path_crosses_project_mount() {
  local object_path="$1"
  local component
  local component_device
  local component_mount_id
  local current_path="${PROJECT_ROOT}"
  local remainder

  CROSSING_PATH=""
  if [[ "${object_path}" == "${PROJECT_ROOT}" ]]; then
    return 1
  fi
  [[ "${object_path}" == "${PROJECT_ROOT}/"* ]] || return 2

  remainder="${object_path#"${PROJECT_ROOT}/"}"
  while [[ -n "${remainder}" ]]; do
    if [[ "${remainder}" == */* ]]; then
      component="${remainder%%/*}"
      remainder="${remainder#*/}"
    else
      component="${remainder}"
      remainder=""
    fi
    current_path="${current_path}/${component}"
    if [[ ! -e "${current_path}" && ! -L "${current_path}" ]]; then
      return 1
    fi
    if ! component_device="$(stat -c '%d' -- "${current_path}" 2>/dev/null)"; then
      CROSSING_PATH="${current_path}"
      return 2
    fi
    if [[ "${component_device}" != "${PROJECT_DEVICE}" ]]; then
      CROSSING_PATH="${current_path}"
      return 0
    fi
    if ! component_mount_id="$(findmnt --noheadings --output ID --target "${current_path}" 2>/dev/null)"; then
      CROSSING_PATH="${current_path}"
      return 2
    fi
    if [[ "${component_mount_id}" != "${PROJECT_MOUNT_ID}" ]]; then
      CROSSING_PATH="${current_path}"
      return 0
    fi
  done

  return 1
}

require_same_project_mount() {
  local object_path="$1"
  local description="$2"
  local mount_status

  if path_crosses_project_mount "${object_path}"; then
    die "${description} crosses the accepted project mount at $(display_path "${CROSSING_PATH}")."
  else
    mount_status=$?
  fi
  if ((mount_status == 2)); then
    die "cannot prove the mount identity for ${description}: $(display_path "${CROSSING_PATH}")"
  fi
}

validate_required_marker() {
  local relative_path="$1"
  local marker_entry
  local marker_metadata
  local marker_index_mode
  local marker_object_id
  local marker_stage
  local marker_extra
  local marker_index_path

  if ! marker_entry="$(git_readonly -C "${PROJECT_ROOT}" ls-files --stage -- "${relative_path}" 2>/dev/null)"; then
    die "cannot inspect required marker in the Git index: ${relative_path}"
  fi
  [[ -n "${marker_entry}" ]] || die "required Drupal marker is not tracked by Git: ${relative_path}"
  marker_metadata="${marker_entry%%$'\t'*}"
  marker_index_path="${marker_entry#*$'\t'}"
  [[ "${marker_metadata}" != "${marker_entry}" ]] || die "malformed Git marker entry: ${relative_path}"
  read -r marker_index_mode marker_object_id marker_stage marker_extra <<<"${marker_metadata}"
  [[ -z "${marker_extra:-}" && "${marker_object_id}" =~ ^[0-9a-f]{40,64}$ ]] \
    || die "invalid Git metadata for required marker: ${relative_path}"
  [[ "${marker_index_path}" == "${relative_path}" && "${marker_index_mode}" == "100644" && "${marker_stage}" == "0" ]] \
    || die "required marker is not a stage-0 ordinary tracked file: ${relative_path}"
  require_same_project_mount "${PROJECT_ROOT}/${relative_path}" "required marker ${relative_path}"
}

select_project_php() {
  local core_requirement
  local minimum_version
  local php_version

  if [[ -n "${PHP_BIN_INPUT}" ]]; then
    [[ "${PHP_BIN_INPUT}" == /* ]] || die "UNISONGES_PHP_BIN must be an absolute path."
    PHP_BIN="${PHP_BIN_INPUT}"
  else
    PHP_BIN="$(type -P php || true)"
  fi
  [[ -n "${PHP_BIN}" && -f "${PHP_BIN}" && -x "${PHP_BIN}" ]] || die "a usable PHP CLI binary is required."

  if ! php_version="$("${PHP_BIN}" -n -r 'echo PHP_VERSION;' 2>/dev/null)" || [[ -z "${php_version}" ]]; then
    die "selected PHP CLI could not report its version."
  fi
  PHP_VERSION_OUTPUT="${php_version}"

  if ! core_requirement="$("${PHP_BIN}" -n -r '
    $lock = json_decode(file_get_contents($argv[1]), true);
    if (!is_array($lock)) {
      exit(2);
    }
    foreach (array_merge($lock["packages"] ?? [], $lock["packages-dev"] ?? []) as $package) {
      if (($package["name"] ?? "") === "drupal/core") {
        $requirement = $package["require"]["php"] ?? "";
        if (!is_string($requirement) || $requirement === "") {
          exit(3);
        }
        echo $requirement;
        exit(0);
      }
    }
    exit(4);
  ' "${PROJECT_ROOT}/composer.lock" 2>/dev/null)"; then
    record_limitation "cannot derive the Drupal core PHP requirement from composer.lock."
    PHP_COMPATIBLE=0
    CORE_PHP_REQUIREMENT="unavailable"
    return 0
  fi
  CORE_PHP_REQUIREMENT="${core_requirement}"

  if [[ "${core_requirement}" =~ ^\>=([0-9]+(\.[0-9]+){1,2})$ ]]; then
    minimum_version="${BASH_REMATCH[1]}"
  else
    record_limitation "unsupported Drupal core PHP constraint ${core_requirement}; PHP syntax validation is indeterminate."
    PHP_COMPATIBLE=0
    return 0
  fi

  if "${PHP_BIN}" -n -r 'exit(version_compare(PHP_VERSION, $argv[1], ">=") ? 0 : 1);' "${minimum_version}"; then
    PHP_COMPATIBLE=1
  else
    record_limitation "selected PHP ${php_version} does not satisfy Drupal core requirement ${core_requirement}; PHP syntax validation is skipped."
    PHP_COMPATIBLE=0
  fi
}

while (($#)); do
  case "$1" in
    --project-root)
      (($# >= 2)) || die "--project-root requires a value."
      [[ -n "$2" ]] || die "--project-root must not be empty."
      PROJECT_ROOT_INPUT="$2"
      shift
      ;;
    --web-user)
      (($# >= 2)) || die "--web-user requires a value."
      [[ -n "$2" ]] || die "--web-user must not be empty."
      WEB_USER="$2"
      WEB_USER_EXPLICIT=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      die "unknown argument: $1 (use --help)."
      ;;
  esac
  shift
done

[[ -n "${PROJECT_ROOT_INPUT}" ]] || die "project root must not be empty."

for required_command in dirname env findmnt getent git id ls python3 stat; do
  command -v "${required_command}" >/dev/null 2>&1 || die "required command is unavailable: ${required_command}"
done
ENV_BIN="$(type -P env)"
PYTHON_BIN="$(type -P python3)"

if path_contains_symlink_component "${PROJECT_ROOT_INPUT}"; then
  die "project path contains a symlink component: $(display_path "${PROJECT_ROOT_INPUT}")"
fi
if [[ ! -d "${PROJECT_ROOT_INPUT}" ]]; then
  die "project root is missing or not a directory: $(display_path "${PROJECT_ROOT_INPUT}")"
fi
if ! PROJECT_ROOT="$(canonical_directory "${PROJECT_ROOT_INPUT}")"; then
  die "project root cannot be resolved: $(display_path "${PROJECT_ROOT_INPUT}")"
fi

case "${PROJECT_ROOT}" in
  /|/bin|/boot|/dev|/etc|/home|/lib|/lib64|/media|/mnt|/opt|/proc|/root|/run|/sbin|/srv|/sys|/tmp|/usr|/var|/var/www|/workspaces)
    die "unsafe broad project root: $(display_path "${PROJECT_ROOT}")"
    ;;
esac

if ! PROJECT_ROOT_IDENTITY="$(stat -c '%d:%i' -- "${PROJECT_ROOT}" 2>/dev/null)"; then
  die "cannot capture the project root device/inode."
fi
if ! PROJECT_DEVICE="$(stat -c '%d' -- "${PROJECT_ROOT}" 2>/dev/null)"; then
  die "cannot capture the project root device."
fi
if ! PROJECT_MOUNT_ID="$(findmnt --noheadings --output ID --target "${PROJECT_ROOT}" 2>/dev/null)"; then
  die "cannot capture the project root mount identity."
fi

web_directory="${PROJECT_ROOT}/web"
if path_contains_symlink_component "${web_directory}"; then
  die "required web directory uses a symlinked path: $(display_path "${web_directory}")"
fi
[[ -d "${web_directory}" ]] || die "required Drupal web directory is missing: $(display_path "${web_directory}")"
require_same_project_mount "${web_directory}" "required Drupal web directory"

for marker in composer.json composer.lock web/index.php; do
  marker_path="${PROJECT_ROOT}/${marker}"
  if path_contains_symlink_component "${marker_path}"; then
    die "required project marker uses a symlinked path: $(display_path "${marker_path}")"
  fi
  [[ -f "${marker_path}" ]] || die "required Drupal project marker is missing: $(display_path "${marker_path}")"
done

if ! REPO_ROOT_INPUT="$(git_readonly -C "${PROJECT_ROOT}" rev-parse --show-toplevel 2>/dev/null)"; then
  die "project root is not inside a Git worktree."
fi
if path_contains_symlink_component "${REPO_ROOT_INPUT}"; then
  die "Git worktree root contains a symlink component: $(display_path "${REPO_ROOT_INPUT}")"
fi
if ! REPO_ROOT="$(canonical_directory "${REPO_ROOT_INPUT}")"; then
  die "Git worktree root cannot be resolved."
fi
if [[ "${PROJECT_ROOT}" != "${REPO_ROOT}" && "${PROJECT_ROOT}" != "${REPO_ROOT}/"* ]]; then
  die "project root escaped its Git worktree."
fi
if ! REPO_ROOT_IDENTITY="$(stat -c '%d:%i' -- "${REPO_ROOT}" 2>/dev/null)"; then
  die "cannot capture the Git worktree root device/inode."
fi
if ! REPO_DEVICE="$(stat -c '%d' -- "${REPO_ROOT}" 2>/dev/null)"; then
  die "cannot capture the Git worktree root device."
fi
if ! REPO_MOUNT_ID="$(findmnt --noheadings --output ID --target "${REPO_ROOT}" 2>/dev/null)"; then
  die "cannot capture the Git worktree root mount identity."
fi
if [[ "${REPO_DEVICE}" != "${PROJECT_DEVICE}" || "${REPO_MOUNT_ID}" != "${PROJECT_MOUNT_ID}" ]]; then
  die "project root crosses a mount boundary inside its Git worktree."
fi
git_readonly -C "${PROJECT_ROOT}" ls-files --error-unmatch -- \
  composer.json composer.lock web/index.php >/dev/null 2>&1 || die "required Drupal project markers are not tracked by Git."
for marker in composer.json composer.lock web/index.php; do
  validate_required_marker "${marker}"
done
assert_project_root_stable

if ((WEB_USER_EXPLICIT)) && [[ ! "${WEB_USER}" =~ ^[[:alnum:]_][[:alnum:]_.-]*\$?$ ]]; then
  die "--web-user is not a valid local account name."
fi

if ((WEB_USER_EXPLICIT)); then
  if load_local_web_identity; then
    :
  else
    identity_status=$?
    if ((identity_status == 1)); then
      die "requested local web user does not exist: ${WEB_USER}"
    fi
    die "cannot safely resolve local UID/group metadata for web user: ${WEB_USER}"
  fi
elif getent -s files passwd www-data >/dev/null 2>&1; then
  WEB_USER="www-data"
  load_local_web_identity || die "cannot safely resolve local UID/group metadata for default web user: www-data"
else
  record_limitation "local www-data does not exist and --web-user was not provided; web-user access cannot be verified."
fi

CURRENT_UID="$(id -u)"
CURRENT_GROUP_OUTPUT="$(id -G)"
ACCESS_PROOF_MODE="unavailable"
if [[ -n "${WEB_USER}" ]]; then
  ((${#WEB_GROUP_IDS[@]} > 0)) || die "web user has no resolvable group membership: ${WEB_USER}"
  if local_group_membership_is_complete; then
    LOCAL_GROUP_MEMBERSHIP_COMPLETE=1
  fi

  if [[ "${WEB_UID}" == "0" ]]; then
    record_warning "the selected web user resolves to UID 0; review the service identity."
  fi

  if [[ "${CURRENT_UID}" == "${WEB_UID}" ]]; then
    if set_web_group_set_from_numeric_list "${CURRENT_GROUP_OUTPUT}"; then
      ACCESS_PROOF_MODE="direct"
    else
      record_identity_limitation_once "checker UID matches ${WEB_USER}, but its effective group set cannot be validated."
    fi
  elif [[ "${CURRENT_UID}" == "0" ]]; then
    if ((LOCAL_GROUP_MEMBERSHIP_COMPLETE == 0)); then
      record_identity_limitation_once "local NSS metadata does not prove the complete supplementary-group set for web UID ${WEB_UID}; no credential probe was attempted."
    else
      if kernel_web_access_probe "/" identity; then
        ACCESS_PROOF_MODE="credential-drop"
      else
        record_identity_limitation_once "the credential-drop preflight failed; effective ${WEB_USER} access is indeterminate."
      fi
    fi
  else
    record_identity_limitation_once "checker UID ${CURRENT_UID} cannot impersonate web UID ${WEB_UID}; permitted mode bits alone do not prove effective access."
  fi
fi

if [[ -n "${WEB_USER}" ]]; then
  inspect_mandatory_access_context
fi
select_project_php
if ! GIT_HEAD="$(git_readonly -C "${REPO_ROOT}" rev-parse --verify HEAD 2>/dev/null)"; then
  die "cannot resolve the Git worktree HEAD."
fi
[[ "${GIT_HEAD}" =~ ^[0-9a-f]{40,64}$ ]] || die "Git returned an invalid worktree HEAD."

section "Read-only scope"
printf 'Project root: %s\n' "$(display_path "${PROJECT_ROOT}")"
printf 'Git worktree: %s\n' "$(display_path "${REPO_ROOT}")"
printf 'Git HEAD: %s\n' "${GIT_HEAD}"
if [[ -n "${WEB_USER}" ]]; then
  printf 'Web user: %s (uid=%s primary-gid=%s groups=%s)\n' \
    "${WEB_USER}" "${WEB_UID}" "${WEB_GID}" "${WEB_GROUP_OUTPUT}"
  printf 'Access proof mode: %s\n' "${ACCESS_PROOF_MODE}"
else
  printf 'Web user: unavailable\n'
  printf 'Access proof mode: unavailable\n'
fi
printf 'PHP CLI: %s (version=%s; Drupal core requires %s)\n' \
  "$(display_path "${PHP_BIN}")" "${PHP_VERSION_OUTPUT}" "${CORE_PHP_REQUIREMENT}"
printf 'Access proof context: selected UID/GIDs in the checker process security context\n'
printf 'Mutation/repair mode: unavailable by design\n'

assert_project_root_stable
coproc GIT_INDEX_READER {
  git_readonly -C "${PROJECT_ROOT}" ls-files --stage -z -- .
}
git_reader_pid="${GIT_INDEX_READER_PID}"
git_reader_fd="${GIT_INDEX_READER[0]}"
mapfile -d '' -t GIT_ENTRIES <&"${git_reader_fd}"
if ! wait "${git_reader_pid}"; then
  die "cannot enumerate tracked project files completely."
fi
((${#GIT_ENTRIES[@]} > 0)) || die "Git returned an empty tracked-file inventory."
assert_project_root_stable

section "Tracked executable index entries"
for entry in "${GIT_ENTRIES[@]}"; do
  metadata="${entry%%$'\t'*}"
  relative_path="${entry#*$'\t'}"
  [[ "${metadata}" != "${entry}" ]] || die "malformed Git index entry."
  read -r index_mode _object_id stage_number extra_metadata <<<"${metadata}"
  [[ -z "${extra_metadata:-}" ]] || die "unexpected Git index metadata."
  [[ "${index_mode}" =~ ^[0-9]{6}$ && "${stage_number}" =~ ^[0-3]$ ]] || die "invalid Git index metadata."
  case "${relative_path}" in
    /*|../*|*/../*)
      die "Git returned a path outside the project root: $(display_path "${relative_path}")"
      ;;
  esac
  TRACKED_FILES=$((TRACKED_FILES + 1))
  if [[ "${stage_number}" == "0" && "${index_mode}" == "100755" ]]; then
    TRACKED_EXECUTABLES=$((TRACKED_EXECUTABLES + 1))
    printf '[TRACKED EXECUTABLE] %s\n' "$(display_path "${relative_path}")"
  fi
done
if ((TRACKED_EXECUTABLES == 0)); then
  printf '(none)\n'
fi

section "Tracked runtime files"
for entry in "${GIT_ENTRIES[@]}"; do
  metadata="${entry%%$'\t'*}"
  relative_path="${entry#*$'\t'}"
  read -r index_mode _object_id stage_number extra_metadata <<<"${metadata}"

  if ! kind="$(runtime_kind "${relative_path}" "${index_mode}")"; then
    continue
  fi
  RUNTIME_FILES=$((RUNTIME_FILES + 1))
  access_scope="$(access_scope_for_path "${relative_path}")"
  if [[ "${access_scope}" == "web runtime" ]]; then
    WEB_RUNTIME_FILES=$((WEB_RUNTIME_FILES + 1))
  else
    TOOLING_FILES=$((TOOLING_FILES + 1))
  fi
  if [[ "${kind}" == "PHP" ]]; then
    PHP_CANDIDATES=$((PHP_CANDIDATES + 1))
  elif [[ "${kind}" == "JSON" ]]; then
    JSON_FILES=$((JSON_FILES + 1))
  elif [[ "${kind}" == "Executable shell" ]]; then
    EXECUTABLE_SHELL_FILES=$((EXECUTABLE_SHELL_FILES + 1))
  fi

  shown="$(display_path "${relative_path}")"
  if [[ -n "${CHECKED_RUNTIME_PATHS[${relative_path}]+present}" ]]; then
    record_failure "runtime path has multiple Git index stages: ${shown}"
    continue
  fi
  CHECKED_RUNTIME_PATHS["${relative_path}"]=1

  if [[ "${stage_number}" != "0" ]]; then
    record_failure "runtime path is unmerged at Git index stage ${stage_number}: ${shown}"
    continue
  fi

  case "${index_mode}" in
    100644)
      classification="ordinary runtime file"
      ;;
    100755)
      if [[ "${kind}" == "Executable shell" ]]; then
        classification="tracked executable tooling script"
      else
        classification="tracked executable runtime file"
      fi
      EXECUTABLE_RUNTIME_FILES=$((EXECUTABLE_RUNTIME_FILES + 1))
      ;;
    120000)
      record_failure "tracked runtime path is a symlink: ${shown}"
      continue
      ;;
    *)
      record_failure "unsupported Git index mode ${index_mode} for runtime path: ${shown}"
      continue
      ;;
  esac

  absolute_path="${PROJECT_ROOT}/${relative_path}"
  if path_contains_symlink_component "${absolute_path}"; then
    record_failure "runtime file path contains a symlink component: ${shown}"
    continue
  fi
  if path_crosses_project_mount "${absolute_path}"; then
    record_limitation "runtime path crosses the accepted project mount at $(display_path "${CROSSING_PATH}"): ${shown}"
    continue
  else
    mount_check_status=$?
  fi
  if ((mount_check_status == 2)); then
    record_limitation "cannot prove the mount boundary for runtime path at $(display_path "${CROSSING_PATH}"): ${shown}"
    continue
  fi

  check_parent_chain "${absolute_path}" "${access_scope}"
  if [[ ! -e "${absolute_path}" && ! -L "${absolute_path}" ]]; then
    if [[ -x "${absolute_path%/*}" ]]; then
      record_failure "tracked runtime file is missing: ${shown}"
    else
      record_limitation "checker cannot establish whether tracked runtime file exists: ${shown}"
    fi
    continue
  fi
  if [[ ! -f "${absolute_path}" ]]; then
    record_failure "tracked runtime path is not a regular file: ${shown}"
    continue
  fi

  if ! file_metadata="$(stat -c '%a %u %g %d:%i' -- "${absolute_path}" 2>/dev/null)"; then
    record_limitation "cannot read file metadata: ${shown}"
    continue
  fi
  read -r file_mode file_uid file_gid file_identity extra_file_metadata <<<"${file_metadata}"
  if [[ -n "${extra_file_metadata:-}" || ! "${file_mode}" =~ ^[0-7]{1,4}$ \
    || ! "${file_uid}" =~ ^[0-9]+$ || ! "${file_gid}" =~ ^[0-9]+$ \
    || ! "${file_identity}" =~ ^[0-9]+:[0-9]+$ ]]; then
    record_limitation "unexpected file metadata: ${shown}"
    continue
  fi
  file_mode_value=$((8#${file_mode}))
  printf -v file_mode '%03o' "${file_mode_value}"

  if (( (file_mode_value & 8#444) == 0 )); then
    record_failure "runtime file has no read bit (mode ${file_mode}): ${shown}"
  fi
  checker_can_read=1
  if ! current_file_open_probe "${absolute_path}"; then
    checker_can_read=0
    if [[ "${access_scope}" == "deployment/runtime tooling" ]]; then
      record_limitation "checker identity cannot open tooling file for reading (mode ${file_mode}): ${shown}"
    else
      record_limitation "runtime file cannot be opened by the checker, so content checks are incomplete (mode ${file_mode}): ${shown}"
    fi
  fi
  if [[ "${access_scope}" == "web runtime" ]]; then
    verify_web_user_access "${absolute_path}" "${file_uid}" "${file_gid}" "${file_mode}" read "runtime file" "${shown}"
  fi
  if (( (file_mode_value & 8#077) == 0 )); then
    record_warning "owner-only runtime file mode ${file_mode} (incident pattern): ${shown}"
  fi
  if (( file_mode_value & 8#022 )); then
    record_warning "group/other-writable runtime file mode ${file_mode}: ${shown}"
  fi
  if [[ "${classification}" == "ordinary runtime file" ]] && ((file_mode_value & 8#111)); then
    record_warning "ordinary runtime file has executable bits (mode ${file_mode}): ${shown}"
  fi
  if [[ "${classification}" == "tracked executable tooling script" ]]; then
    if (( (file_mode_value & 8#111) == 0 )); then
      record_failure "tracked executable tooling script has no executable bit (mode ${file_mode}): ${shown}"
    elif [[ ! -x "${absolute_path}" ]]; then
      record_limitation "checker identity cannot prove execute access to tracked tooling script (mode ${file_mode}): ${shown}"
    fi
  elif [[ "${classification}" == "tracked executable runtime file" ]] && (( (file_mode_value & 8#111) == 0 )); then
    record_failure "tracked executable runtime file has no executable bit (mode ${file_mode}): ${shown}"
  fi

  syntax_status="not-applicable"
  if [[ "${kind}" == "PHP" ]]; then
    if ((PHP_COMPATIBLE == 0 || checker_can_read == 0)); then
      syntax_status="unavailable"
    else
      PHP_FILES_LINTED=$((PHP_FILES_LINTED + 1))
      if php_output="$("${PHP_BIN}" -n -l -f "${absolute_path}" 2>&1)"; then
        syntax_status="valid"
      else
        php_status=$?
        syntax_status="unavailable"
        if ((php_status == 255)); then
          syntax_status="invalid"
          record_failure "PHP syntax check failed path=${shown} diagnostic=$(display_diagnostic "${php_output}")"
        else
          record_limitation "PHP syntax tool failed with status ${php_status} path=${shown} diagnostic=$(display_diagnostic "${php_output}")"
        fi
      fi
    fi
  fi

  if [[ -L "${absolute_path}" ]]; then
    record_failure "runtime file became a symlink during validation: ${shown}"
  elif ! final_file_identity="$(stat -c '%d:%i' -- "${absolute_path}" 2>/dev/null)"; then
    record_limitation "runtime file identity became unavailable during validation: ${shown}"
  elif [[ "${final_file_identity}" != "${file_identity}" ]]; then
    record_limitation "runtime file identity changed during validation: ${shown}"
  fi

  printf '[CHECKED] kind=%s scope=%s class=%s mode=%s syntax=%s path=%s\n' \
    "${kind}" "${access_scope}" "${classification}" "${file_mode}" "${syntax_status}" "${shown}"
done

if ((RUNTIME_FILES == 0)); then
  record_failure "Git contains no selected tracked PHP, Twig, YAML, CSS, JavaScript, JSON, or executable shell files."
fi

assert_project_root_stable
if ! FINAL_GIT_HEAD="$(git_readonly -C "${REPO_ROOT}" rev-parse --verify HEAD 2>/dev/null)"; then
  die "Git HEAD became unavailable during validation."
fi
[[ "${FINAL_GIT_HEAD}" =~ ^[0-9a-f]{40,64}$ ]] || die "Git returned an invalid final worktree HEAD."
[[ "${FINAL_GIT_HEAD}" == "${GIT_HEAD}" ]] || die "Git HEAD changed during validation."

section "Summary"
printf 'Tracked project files: %d\n' "${TRACKED_FILES}"
printf 'Tracked executable index entries: %d\n' "${TRACKED_EXECUTABLES}"
printf 'Tracked runtime files checked: %d\n' "${RUNTIME_FILES}"
printf 'Web runtime files: %d\n' "${WEB_RUNTIME_FILES}"
printf 'Deployment/runtime tooling files: %d\n' "${TOOLING_FILES}"
printf 'Tracked executable runtime files: %d\n' "${EXECUTABLE_RUNTIME_FILES}"
printf 'Executable shell tooling files: %d\n' "${EXECUTABLE_SHELL_FILES}"
printf 'Applicable runtime JSON files: %d\n' "${JSON_FILES}"
printf 'PHP-family candidates: %d\n' "${PHP_CANDIDATES}"
printf 'PHP-family files syntax-checked: %d\n' "${PHP_FILES_LINTED}"
printf 'Parent directories checked: %d\n' "${DIRECTORIES_CHECKED}"
printf 'Warnings: %d\n' "${WARNINGS}"
printf 'Definite failures: %d\n' "${FAILURES}"
printf 'Verification limitations: %d\n' "${LIMITATIONS}"

if ((FAILURES > 0)); then
  log "RESULT: FAIL (definite runtime-readability, path-integrity, or PHP syntax failure)"
  exit 1
fi
if ((LIMITATIONS > 0)); then
  log "RESULT: INDETERMINATE (verification limitations must be resolved)"
  exit 2
fi

log "RESULT: PASS (required access and syntax checks were demonstrated; review any warnings)"
