#!/usr/bin/env bash
set -Eeuo pipefail

MODE="prepare"

log() {
  printf '[codespaces-setup] %s\n' "$*"
}

die() {
  printf '[codespaces-setup] ERROR: %s\n' "$*" >&2
  exit 1
}

usage() {
  cat <<'EOF'
Usage: ./.devcontainer/setup-project.sh [--initialize-site|--start-only]

Without an option, prepare the runtime, start DDEV, and install the exact
Composer lock dependencies. This is the safe post-create mode.

Options:
  --initialize-site  Prepare dependencies, install an empty local Drupal site,
                     and apply the reviewed local fixture workflow.
  --start-only       Restart an already configured DDEV project without
                     reinstalling dependencies. This is the post-start mode.
  -h, --help         Show this help.
EOF
}

if [[ "$#" -gt 1 ]]; then
  usage >&2
  exit 2
fi

if [[ "$#" -eq 1 ]]; then
  case "$1" in
    --initialize-site)
      MODE="initialize-site"
      ;;
    --start-only)
      MODE="start-only"
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      usage >&2
      exit 2
      ;;
  esac
fi

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
if ! REPO_ROOT="$(git -C "${SCRIPT_DIR}" rev-parse --show-toplevel 2>/dev/null)"; then
  die "Could not derive the repository root from ${SCRIPT_DIR}."
fi
REPO_ROOT="$(cd -- "${REPO_ROOT}" && pwd -P)"
DRUPAL_DIR="${REPO_ROOT}/drupal"
DDEV_DIR="${DRUPAL_DIR}/.ddev"
DDEV_CONFIG="${DDEV_DIR}/config.yaml"
DDEV_CODESPACES_CONFIG="${DDEV_DIR}/config.codespaces.yaml"
DDEV_CODESPACES_SETTINGS="${DDEV_DIR}/settings.codespaces.php"
DRUPAL_SETTINGS="${DRUPAL_DIR}/web/sites/default/settings.php"

case "${REPO_ROOT}" in
  /workspaces/*)
    ;;
  *)
    die "Refusing to run outside a dev-container workspace under /workspaces."
    ;;
esac

require_command() {
  local command_name="$1"
  command -v "${command_name}" >/dev/null 2>&1 || die "Required command not found: ${command_name}"
}

is_codespaces() {
  [[ "${CODESPACES:-}" == "true" ]]
}

replace_file_if_changed() {
  local target="$1"
  local temporary_file

  temporary_file="$(mktemp "${target}.tmp.XXXXXX")"
  cat > "${temporary_file}"
  chmod 0644 "${temporary_file}"

  if [[ -f "${target}" ]] && cmp -s "${temporary_file}" "${target}"; then
    rm -f "${temporary_file}"
    return 0
  fi

  mv -f "${temporary_file}" "${target}"
  log "Updated runtime-only file: ${target}"
}

ensure_runtime_git_exclude() {
  local exclude_file
  exclude_file="$(git -C "${REPO_ROOT}" rev-parse --git-path info/exclude)"
  [[ -f "${exclude_file}" ]] || die "Missing local Git exclude file: ${exclude_file}"

  if grep -Fqx 'drupal/.ddev/' "${exclude_file}"; then
    return 0
  fi

  printf '\n# Uni-Songes runtime-only DDEV configuration\ndrupal/.ddev/\n' >> "${exclude_file}"
  log "Added drupal/.ddev/ to the clone-local Git exclude list."
}

require_project_files() {
  [[ -f "${DRUPAL_DIR}/composer.json" ]] || die "Missing drupal/composer.json."
  [[ -f "${DRUPAL_DIR}/composer.lock" ]] || die "Missing drupal/composer.lock; refusing an unlocked install."
  [[ -x "${DRUPAL_DIR}/scripts/bootstrap-local-fixture-site.sh" ]] || die "Missing fixture bootstrap script."
  if [[ ! -x "${DRUPAL_DIR}/scripts/bootstrap-local-commerce-fixture-site.sh" ]]; then
    die "Missing Commerce fixture bootstrap script."
  fi
  [[ -x "${DRUPAL_DIR}/scripts/create-local-fixtures.sh" ]] || die "Missing fixture creation script."
  [[ -x "${DRUPAL_DIR}/scripts/test-local-commerce-credit-flow.sh" ]] || die "Missing Commerce fixture test script."

  if git -C "${REPO_ROOT}" ls-files --error-unmatch drupal/.ddev/config.yaml >/dev/null 2>&1; then
    die "drupal/.ddev/config.yaml must remain runtime-local and untracked."
  fi
  if ! git -C "${REPO_ROOT}" check-ignore -q --no-index drupal/.ddev/config.yaml; then
    die "drupal/.ddev/config.yaml is not ignored; refusing to create runtime configuration."
  fi
}

wait_for_docker() {
  local attempt
  local max_attempts=60

  for ((attempt = 1; attempt <= max_attempts; attempt++)); do
    if docker info >/dev/null 2>&1; then
      log "Docker is ready."
      return 0
    fi

    if ((attempt % 10 == 0)); then
      log "Waiting for Docker (${attempt}/${max_attempts})..."
    fi
    sleep 2
  done

  die "Docker did not become ready within 120 seconds. See the Codespace creation log."
}

ensure_ddev_config() {
  if [[ -f "${DDEV_CONFIG}" ]]; then
    log "Keeping existing runtime DDEV configuration: ${DDEV_CONFIG}"
    return 0
  fi

  log "Creating runtime-only DDEV configuration."
  ddev config --auto \
    --project-name=unisonges \
    --project-type=drupal11 \
    --docroot=web \
    --php-version=8.3 \
    --database=mariadb:10.11 \
    --nodejs-version=24 \
    --webserver-type=nginx-fpm \
    --host-webserver-port=8080 \
    --host-https-port=8443
}

ensure_codespaces_runtime_config() {
  if ! is_codespaces; then
    if [[ -f "${DDEV_CODESPACES_CONFIG}" || -f "${DDEV_CODESPACES_SETTINGS}" ]]; then
      rm -f -- "${DDEV_CODESPACES_CONFIG}" "${DDEV_CODESPACES_SETTINGS}"
      log "Removed stale Codespaces-only DDEV runtime files."
    fi
    return 0
  fi

  [[ -n "${CODESPACE_NAME:-}" ]] || die "CODESPACE_NAME is missing from the Codespaces environment."
  if [[ ! "${CODESPACE_NAME}" =~ ^[A-Za-z0-9][A-Za-z0-9-]*$ ]]; then
    die "CODESPACE_NAME contains unexpected characters."
  fi

  [[ -n "${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-}" ]] || \
    die "GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN is missing from the Codespaces environment."
  if [[ ! "${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}" =~ ^[A-Za-z0-9.-]+$ ]]; then
    die "GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN contains unexpected characters."
  fi

  replace_file_if_changed "${DDEV_CODESPACES_CONFIG}" <<EOF
#ddev-silent-no-warn
# Runtime-only direct bindings for the private GitHub Codespaces tunnels.
host_webserver_port: "8080"
host_https_port: "8443"
host_mailpit_port: "8027"
web_environment:
  - CODESPACES=true
  - CODESPACE_NAME=${CODESPACE_NAME}
  - GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN=${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}
EOF

  replace_file_if_changed "${DDEV_CODESPACES_SETTINGS}" <<'PHP'
<?php

/**
 * @file
 * Runtime-only reverse-proxy settings for the private Codespaces tunnel.
 */

if (getenv('CODESPACES') !== 'true') {
  return;
}

$codespace_name = getenv('CODESPACE_NAME') ?: '';
$forwarding_domain = getenv('GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN') ?: '';
if (
  preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/D', $codespace_name) !== 1 ||
  preg_match('/^[A-Za-z0-9.-]+$/D', $forwarding_domain) !== 1
) {
  return;
}

$expected_host = strtolower($codespace_name . '-8080.' . $forwarding_domain);
$forwarded_host = strtolower(trim($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
$forwarded_proto = strtolower(trim($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
$forwarded_port = trim($_SERVER['HTTP_X_FORWARDED_PORT'] ?? '');

// Replace DDEV's development wildcard with the hosts used by this runtime.
$settings['trusted_host_patterns'] = [
  '^' . preg_quote($expected_host, '/') . '$',
  '^localhost$',
  '^127\\.0\\.0\\.1$',
  '^\\[::1\\]$',
  '^unisonges\\.ddev\\.site$',
  '^web$',
];

// Only the canonical private Codespaces tunnel may influence generated URLs.
// Direct localhost requests, including gh CLI tunnels, keep their original URL.
if (
  !in_array($forwarded_host, [$expected_host, $expected_host . ':443'], TRUE) ||
  $forwarded_proto !== 'https' ||
  ($forwarded_port !== '' && $forwarded_port !== '443')
) {
  unset(
    $_SERVER['HTTP_X_FORWARDED_HOST'],
    $_SERVER['HTTP_X_FORWARDED_PORT'],
    $_SERVER['HTTP_X_FORWARDED_PROTO']
  );
  return;
}

// Published Docker ports reach the web container through its bridge gateway.
// Trust that immediate peer, but never the client address forwarding chain.
$codespaces_proxy_address = $_SERVER['REMOTE_ADDR'] ?? '';
if (filter_var($codespaces_proxy_address, FILTER_VALIDATE_IP) === FALSE) {
  return;
}

$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_addresses'] = [$codespaces_proxy_address];
$settings['reverse_proxy_trusted_headers'] =
  \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_HOST |
  \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO;
if ($forwarded_port === '443') {
  $settings['reverse_proxy_trusted_headers'] |=
    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT;
}
PHP
}

ensure_codespaces_drupal_settings_include() {
  local marker='// Uni-Songes Codespaces runtime proxy settings.'

  if ! is_codespaces; then
    return 0
  fi

  [[ -f "${DRUPAL_SETTINGS}" ]] || die "DDEV did not create ${DRUPAL_SETTINGS}."
  if grep -Fqx "${marker}" "${DRUPAL_SETTINGS}"; then
    return 0
  fi
  [[ -w "${DRUPAL_SETTINGS}" ]] || die "Drupal settings file is not writable: ${DRUPAL_SETTINGS}"

  cat >> "${DRUPAL_SETTINGS}" <<'PHP'

// Uni-Songes Codespaces runtime proxy settings.
if (getenv('CODESPACES') === 'true') {
  $codespaces_settings = dirname(__DIR__, 3) . '/.ddev/settings.codespaces.php';
  if (is_readable($codespaces_settings)) {
    include $codespaces_settings;
  }
}
PHP
  log "Enabled the runtime-only Drupal Codespaces proxy settings."
}

verify_codespaces_runtime_ports() {
  local drupal_status

  if ! is_codespaces; then
    return 0
  fi

  if ! drupal_status="$(curl --noproxy '*' --silent --show-error --output /dev/null \
    --write-out '%{http_code}' --max-time 15 http://127.0.0.1:8080/)"; then
    die "Drupal is not reachable through Codespaces port 8080."
  fi
  [[ "${drupal_status}" =~ ^[123][0-9][0-9]$ ]] || \
    die "Drupal port 8080 returned unexpected HTTP status ${drupal_status}."

  if ! curl --noproxy '*' --fail --silent --show-error --output /dev/null \
    --max-time 15 http://127.0.0.1:8027/api/v1/info; then
    die "Mailpit UI is not reachable through Codespaces port 8027."
  fi

  log "Verified local listeners for Drupal (8080) and Mailpit (8027)."
}

start_ddev() {
  log "Stopping any stale DDEV containers left by a Codespace rebuild."
  ddev poweroff
  log "Starting DDEV."
  ddev start -y
}

install_locked_dependencies() {
  log "Installing dependencies from composer.lock."
  ddev composer install --no-interaction --prefer-dist
  ddev exec test -x ./vendor/bin/drush || die "Composer finished without an executable vendor/bin/drush."
}

database_scalar() {
  local query="$1"
  ddev mysql -NBe "${query}" | tr -d '[:space:]'
}

ensure_local_drupal_site() {
  local key_value_table
  local table_count

  key_value_table="$(database_scalar "SHOW TABLES FROM db LIKE 'key_value';")"
  if [[ "${key_value_table}" == "key_value" ]]; then
    if ! ddev drush php:eval 'echo \Drupal::VERSION . PHP_EOL;' >/dev/null; then
      die "The local database has Drupal tables but Drupal cannot bootstrap; refusing to reinstall it."
    fi
    log "Existing local Drupal installation detected; site installation is unchanged."
    return 0
  fi

  if [[ -n "${key_value_table}" ]]; then
    die "Unexpected database response while checking for the Drupal key_value table."
  fi

  table_count="$(database_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'db';")"
  if [[ ! "${table_count}" =~ ^[0-9]+$ ]]; then
    die "Could not determine whether the local database is empty."
  fi
  if [[ "${table_count}" -ne 0 ]]; then
    die "The local database is non-empty but Drupal is not installed; refusing to overwrite partial or unknown data."
  fi

  log "Installing a new standard-profile Drupal site in the empty local DDEV database."
  # These deliberately weak credentials are restricted to the private local fixture site.
  ddev drush site:install standard \
    --yes \
    --site-name='Uni-Songes Local Codespace' \
    --account-name=admin \
    --account-pass=admin \
    --account-mail=admin@example.invalid \
    --site-mail=site@example.invalid \
    install_configure_form.enable_update_status_emails=NULL

  if ! ddev drush php:eval 'echo \Drupal::VERSION . PHP_EOL;' >/dev/null; then
    die "Drupal installation did not bootstrap successfully."
  fi
  log "Local Drupal installation completed."
}

initialize_local_fixtures() {
  log "Checking the reviewed base fixture bootstrap."
  ./scripts/bootstrap-local-fixture-site.sh --dry-run
  ./scripts/bootstrap-local-fixture-site.sh --apply

  log "Checking the reviewed Commerce fixture bootstrap."
  ./scripts/bootstrap-local-commerce-fixture-site.sh --dry-run
  ./scripts/bootstrap-local-commerce-fixture-site.sh --apply

  log "Checking and applying local-only fixture users and Commerce entities."
  ./scripts/create-local-fixtures.sh --dry-run --with-commerce
  ./scripts/create-local-fixtures.sh --apply --with-commerce
}

print_prepare_status() {
  printf '\n'
  log "Environment preparation completed successfully."
  log "DDEV is running and Composer dependencies match composer.lock."
  if ddev drush php:eval 'echo \Drupal::VERSION;' >/dev/null 2>&1; then
    log "An installed Drupal site can bootstrap; fixture state was not changed."
  else
    log "Drupal is not initialized yet. Run ./.devcontainer/setup-project.sh --initialize-site explicitly."
  fi
  log "Use the Codespaces Ports panel for Drupal (8080) and Mailpit (8027)."
}

require_command git
require_command docker
require_command ddev
require_command grep
require_command cmp
require_command curl
require_command mktemp
ensure_runtime_git_exclude
require_project_files
wait_for_docker
cd "${DRUPAL_DIR}"

if [[ "${MODE}" == "start-only" ]]; then
  [[ -f "${DDEV_CONFIG}" ]] || die "Runtime DDEV configuration is absent; rerun the post-create setup."
else
  ensure_ddev_config
fi

ensure_codespaces_runtime_config
start_ddev
ensure_codespaces_drupal_settings_include

if [[ "${MODE}" == "start-only" ]]; then
  verify_codespaces_runtime_ports
  log "DDEV restart completed; dependencies and Drupal data were not changed."
  exit 0
fi

install_locked_dependencies
verify_codespaces_runtime_ports

if [[ "${MODE}" == "initialize-site" ]]; then
  ensure_local_drupal_site
  initialize_local_fixtures
  printf '\n'
  log "Local Drupal and Commerce fixture initialization completed successfully."
  log "Only local example.invalid identities and the manual fixture gateway were prepared."
  log "Use the Codespaces Ports panel for Drupal (8080) and Mailpit (8027)."
  exit 0
fi

print_prepare_status
