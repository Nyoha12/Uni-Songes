#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(git -C "${SCRIPT_DIR}" rev-parse --show-toplevel)"
cd "${REPO_ROOT}"

findings=0
failures=0
declare -A finding_ids=()
declare -a finding_files=()
declare -a finding_objects=()
declare -a finding_keys=()
declare -a finding_states=()

read_scan_source() {
  local file="$1"
  local source="$2"

  case "${source}" in
    index)
      git show ":${file}" 2>/dev/null
      ;;
    worktree)
      command cat -- "${file}" 2>/dev/null
      ;;
    *)
      return 2
      ;;
  esac
}

record_state() {
  local file="$1"
  local object_id="$2"
  local key_path="$3"
  local state="$4"
  local signature
  local finding_id
  local existing_state

  signature="${file}"$'\034'"${object_id}"$'\034'"${key_path}"
  if [[ -z "${finding_ids["${signature}"]+present}" ]]; then
    finding_id="${findings}"
    finding_ids["${signature}"]="${finding_id}"
    finding_files["${finding_id}"]="${file}"
    finding_objects["${finding_id}"]="${object_id}"
    finding_keys["${finding_id}"]="${key_path}"
    finding_states["${finding_id}"]="${state}"
    findings=$((findings + 1))
    return
  fi

  finding_id="${finding_ids["${signature}"]}"
  existing_state="${finding_states["${finding_id}"]}"
  case "${existing_state}:${state}" in
    non-empty:*|*:non-empty)
      finding_states["${finding_id}"]="non-empty"
      ;;
    unverified:*|*:unverified)
      finding_states["${finding_id}"]="unverified"
      ;;
    empty:empty|runtime-environment:runtime-environment)
      ;;
    *)
      finding_states["${finding_id}"]="unverified"
      ;;
  esac
}

emit_findings() {
  local finding_id
  local state

  for ((finding_id = 0; finding_id < findings; finding_id++)); do
    state="${finding_states["${finding_id}"]}"
    printf 'file=%s object_id=%s key=%s state=%s\n' \
      "${finding_files["${finding_id}"]}" \
      "${finding_objects["${finding_id}"]}" \
      "${finding_keys["${finding_id}"]}" \
      "${state}"

    case "${state}" in
      empty|runtime-environment)
        ;;
      *)
        failures=$((failures + 1))
        ;;
    esac
  done
}

scan_gateway_yaml() {
  local file="$1"
  local source="$2"
  local object_id="${file#drupal/config/sync/commerce_payment.commerce_payment_gateway.}"
  local scan_output
  object_id="${object_id%.yaml}"
  object_id="${object_id%.yml}"

  if ! scan_output="$(read_scan_source "${file}" "${source}" | awk -v object_id="${object_id}" '
      function trim(value) {
        gsub(/^[[:space:]]+|[[:space:]]+$/, "", value)
        return value
      }

      function unquote(value) {
        value = trim(value)
        if ((substr(value, 1, 1) == "\047" && substr(value, length(value), 1) == "\047") ||
            (substr(value, 1, 1) == "\"" && substr(value, length(value), 1) == "\"")) {
          return substr(value, 2, length(value) - 2)
        }
        return value
      }

      function scalar_state(value) {
        value = trim(value)
        if (value == "" || value == "\047\047" || value == "\"\"" ||
            value == "~" || tolower(value) == "null") {
          return "empty"
        }
        return "non-empty"
      }

      function remember(key, value, state) {
        state = scalar_state(value)
        key_count[key]++
        if (state == "non-empty") {
          key_non_empty[key] = 1
        }
      }

      /^plugin:[[:space:]]*/ {
        value = $0
        sub(/^plugin:[[:space:]]*/, "", value)
        plugin = unquote(value)
        plugin_count++
        next
      }

      /^configuration:/ {
        configuration_count++
        if ($0 ~ /^configuration:[[:space:]]*$/) {
          configuration_mapping = 1
          in_configuration = 1
        }
        else {
          configuration_invalid = 1
          in_configuration = 0
        }
        next
      }

      /^[^[:space:]#]/ {
        in_configuration = 0
      }

      in_configuration && /^  (client_id|secret):/ {
        separator = index($0, ":")
        key = trim(substr($0, 1, separator - 1))
        remember(key, substr($0, separator + 1))
      }

      function final_state(key) {
        if (key_non_empty[key]) {
          return "non-empty"
        }
        if (plugin_count != 1 || plugin !~ /^paypal_/ ||
            configuration_count != 1 || !configuration_mapping ||
            configuration_invalid || key_count[key] != 1) {
          return "unverified"
        }
        return "empty"
      }

      END {
        if (plugin !~ /^paypal_/ && object_id !~ /^paypal([_.-]|$)/) {
          exit
        }
        print "configuration.client_id\t" final_state("client_id")
        print "configuration.secret\t" final_state("secret")
      }
    ')"; then
    record_state "${file}" "${object_id}" "parser" "unverified"
    return
  fi

  while IFS=$'\t' read -r key_path state; do
    [[ -n "${key_path}" ]] || continue
    record_state "${file}" "${object_id}" "${key_path}" "${state}"
  done <<< "${scan_output}"
}

scan_paypal_php() {
  local file="$1"
  local source="$2"
  local scan_output

  if ! scan_output="$(read_scan_source "${file}" "${source}" | awk '
      function trim(value) {
        gsub(/^[[:space:]]+|[[:space:]]+$/, "", value)
        return value
      }

      function compact(value) {
        gsub(/[[:space:]]+/, "", value)
        return value
      }

      function scalar_state(value) {
        value = compact(value)
        if (value == "" || value == "\047\047;" || value == "\"\";" ||
            value == "false;" || value == "FALSE;" || value == "null;" ||
            value == "NULL;") {
          return "empty"
        }
        return "non-empty"
      }

      function source_state(value, environment_name, single_quoted, double_quoted) {
        value = compact(value)
        single_quoted = "trim((string)getenv(\047" environment_name "\047));"
        double_quoted = "trim((string)getenv(\"" environment_name "\"));"
        if (value == single_quoted || value == double_quoted) {
          return "runtime-environment"
        }
        return scalar_state(value)
      }

      function credential_sink_state(value, expected_variable, single_quoted, double_quoted) {
        value = compact(value)
        single_quoted = "$unisonges_paypal_credentials_available?" expected_variable ":\047\047;"
        double_quoted = "$unisonges_paypal_credentials_available?" expected_variable ":\"\";"
        if (value == single_quoted || value == double_quoted || value == expected_variable ";") {
          return "runtime-environment"
        }
        return scalar_state(value)
      }

      function availability_state(value, single_quoted, double_quoted) {
        value = compact(value)
        single_quoted = "$unisonges_paypal_client_id!==\047\047&&$unisonges_paypal_client_secret!==\047\047;"
        double_quoted = "$unisonges_paypal_client_id!==\"\"&&$unisonges_paypal_client_secret!==\"\";"
        return (value == single_quoted || value == double_quoted) ? "runtime-environment" : "unverified"
      }

      function status_sink_state(value) {
        value = compact(value)
        return value == "$unisonges_paypal_credentials_available;" ? "runtime-environment" : "unverified"
      }

      function remember(slot, state) {
        slot_count[slot]++
        if (state == "non-empty") {
          slot_non_empty[slot] = 1
        }
        else if (state == "unverified") {
          slot_unverified[slot] = 1
        }
        else if (state == "runtime-environment") {
          slot_runtime[slot] = 1
        }
        else if (state == "empty") {
          slot_empty[slot] = 1
        }
      }

      function remember_pending(value) {
        if (pending == "client_sink") {
          remember(pending, credential_sink_state(value, "$unisonges_paypal_client_id"))
        }
        else if (pending == "secret_sink") {
          remember(pending, credential_sink_state(value, "$unisonges_paypal_client_secret"))
        }
        else if (pending == "status_sink") {
          remember(pending, status_sink_state(value))
        }
        pending = ""
      }

      function final_state(source_slot, sink_slot) {
        if (slot_non_empty[source_slot] || slot_non_empty[sink_slot]) {
          return "non-empty"
        }
        if (slot_count[source_slot] != 1 || slot_count[sink_slot] != 1 ||
            slot_unverified[source_slot] || slot_unverified[sink_slot] ||
            !slot_runtime[source_slot] || !slot_runtime[sink_slot] ||
            slot_count["availability"] != 1 || !slot_runtime["availability"] ||
            slot_unverified["availability"] || slot_count["status_sink"] != 1 ||
            !slot_runtime["status_sink"] || slot_unverified["status_sink"]) {
          return "unverified"
        }
        return "runtime-environment"
      }

      {
        line = $0

        if (pending != "") {
          if (line ~ /^[[:space:]]*$/ || line ~ /^[[:space:]]*\/\// ||
              line ~ /^[[:space:]]*#/) {
            next
          }
          remember_pending(line)
          next
        }

        if (line ~ /^[[:space:]]*\$unisonges_paypal_client_id[[:space:]]*=/) {
          relevant = 1
          separator = index(line, "=")
          remember("client_source", source_state(substr(line, separator + 1), "UNISONGES_PAYPAL_CLIENT_ID"))
          next
        }

        if (line ~ /^[[:space:]]*\$unisonges_paypal_client_secret[[:space:]]*=/) {
          relevant = 1
          separator = index(line, "=")
          remember("secret_source", source_state(substr(line, separator + 1), "UNISONGES_PAYPAL_CLIENT_SECRET"))
          next
        }

        if (line ~ /^[[:space:]]*\$unisonges_paypal_credentials_available[[:space:]]*=/) {
          relevant = 1
          separator = index(line, "=")
          remember("availability", availability_state(substr(line, separator + 1)))
          next
        }

        if (index(line, "commerce_payment.commerce_payment_gateway.paypal") > 0) {
          relevant = 1
          if (index(line, "client_id") > 0) {
            pending = "client_sink"
          }
          else if (index(line, "secret") > 0) {
            pending = "secret_sink"
          }
          else if (index(line, "status") > 0) {
            pending = "status_sink"
          }
          else {
            next
          }

          separator = index(line, "=")
          value = separator > 0 ? trim(substr(line, separator + 1)) : ""
          if (value != "") {
            remember_pending(value)
          }
          next
        }

        if (index(line, "UNISONGES_PAYPAL_CLIENT_ID") > 0) {
          relevant = 1
          remember("client_source", "unverified")
        }
        if (index(line, "UNISONGES_PAYPAL_CLIENT_SECRET") > 0) {
          relevant = 1
          remember("secret_source", "unverified")
        }
      }

      END {
        if (!relevant) {
          exit
        }
        print "configuration.client_id\t" final_state("client_source", "client_sink")
        print "configuration.secret\t" final_state("secret_source", "secret_sink")
      }
    ')"; then
    record_state "${file}" "paypal" "parser" "unverified"
    return
  fi

  while IFS=$'\t' read -r key_path state; do
    [[ -n "${key_path}" ]] || continue
    record_state "${file}" "paypal" "${key_path}" "${state}"
  done <<< "${scan_output}"
}

scan_environment_example() {
  local file="$1"
  local source="$2"
  local scan_output

  if ! scan_output="$(read_scan_source "${file}" "${source}" | awk '
      function trim(value) {
        gsub(/^[[:space:]]+|[[:space:]]+$/, "", value)
        return value
      }

      function scalar_state(value) {
        value = trim(value)
        if (value == "" || value == "\047\047" || value == "\"\"") {
          return "empty"
        }
        return "non-empty"
      }

      function remember(key, value, state) {
        state = scalar_state(value)
        key_count[key]++
        if (state == "non-empty") {
          key_non_empty[key] = 1
        }
      }

      /^[[:space:]]*(export[[:space:]]+)?UNISONGES_PAYPAL_CLIENT_ID[[:space:]]*=/ {
        relevant = 1
        value = $0
        sub(/^[^=]*=/, "", value)
        remember("UNISONGES_PAYPAL_CLIENT_ID", value)
        next
      }

      /^[[:space:]]*(export[[:space:]]+)?UNISONGES_PAYPAL_CLIENT_SECRET[[:space:]]*=/ {
        relevant = 1
        value = $0
        sub(/^[^=]*=/, "", value)
        remember("UNISONGES_PAYPAL_CLIENT_SECRET", value)
        next
      }

      /UNISONGES_PAYPAL_CLIENT_ID/ {
        relevant = 1
        key_unverified["UNISONGES_PAYPAL_CLIENT_ID"] = 1
      }

      /UNISONGES_PAYPAL_CLIENT_SECRET/ {
        relevant = 1
        key_unverified["UNISONGES_PAYPAL_CLIENT_SECRET"] = 1
      }

      function final_state(key) {
        if (key_non_empty[key]) {
          return "non-empty"
        }
        if (key_unverified[key] || key_count[key] != 1) {
          return "unverified"
        }
        return "empty"
      }

      END {
        if (!relevant) {
          exit
        }
        print "UNISONGES_PAYPAL_CLIENT_ID\t" final_state("UNISONGES_PAYPAL_CLIENT_ID")
        print "UNISONGES_PAYPAL_CLIENT_SECRET\t" final_state("UNISONGES_PAYPAL_CLIENT_SECRET")
      }
    ')"; then
    record_state "${file}" "paypal" "parser" "unverified"
    return
  fi

  while IFS=$'\t' read -r key_path state; do
    [[ -n "${key_path}" ]] || continue
    record_state "${file}" "paypal" "${key_path}" "${state}"
  done <<< "${scan_output}"
}

if ! gateway_files="$(git ls-files -- \
  'drupal/config/sync/commerce_payment.commerce_payment_gateway.*.yml' \
  'drupal/config/sync/commerce_payment.commerce_payment_gateway.*.yaml')"; then
  printf 'result=fail reason=gateway-file-enumeration\n' >&2
  exit 1
fi

while IFS= read -r file; do
  [[ -n "${file}" ]] || continue
  scan_gateway_yaml "${file}" "index"
  if [[ -f "${file}" ]]; then
    scan_gateway_yaml "${file}" "worktree"
  fi
done <<< "${gateway_files}"

if ! php_files="$(git ls-files -- '*.php')"; then
  printf 'result=fail reason=php-file-enumeration\n' >&2
  exit 1
fi

while IFS= read -r file; do
  [[ -n "${file}" ]] || continue
  scan_paypal_php "${file}" "index"
  if [[ -f "${file}" ]]; then
    scan_paypal_php "${file}" "worktree"
  fi
done <<< "${php_files}"

if ! tracked_files="$(git ls-files)"; then
  printf 'result=fail reason=tracked-file-enumeration\n' >&2
  exit 1
fi

while IFS= read -r file; do
  [[ -n "${file}" ]] || continue
  case "${file}" in
    *.env|*.env.*|*env*.example|*env*.dist|*env*.sample)
      scan_environment_example "${file}" "index"
      if [[ -f "${file}" ]]; then
        scan_environment_example "${file}" "worktree"
      fi
      ;;
  esac
done <<< "${tracked_files}"

if ((findings == 0)); then
  printf 'result=fail reason=no-relevant-key-paths-found\n' >&2
  exit 1
fi

emit_findings

if ((failures > 0)); then
  printf 'result=fail non_empty_or_unverified=%d\n' "${failures}" >&2
  exit 1
fi

printf 'result=pass findings=%d\n' "${findings}"
