# VPS Deployment Permission Safety

Status date: 2026-09-02.

This is a manual operations guard. It is not an automatic deployment system,
does not authorize host access, and does not repair a checkout. PR #87 is now
merged into `release/prod`. PR #103 currently owns runtime resources, including
DDEV and Chromium work. PR #104 remains entirely static; its only outstanding
gate is the owner-run real-VPS read-only validation documented below.

## Confirmed incident and guard

The production outage had a precise filesystem cause:

1. an operator shell set `umask 077` globally before a code update;
2. Git recreated tracked `unisonges_theme.theme` as mode `600`, owned by the
   deployment user;
3. PHP-FPM ran as `www-data` and received `Permission denied` when including
   the theme file;
4. Drupal therefore could not load the theme-hook functions, and public routes
   returned HTTP 500;
5. restoring mode `644`, restarting PHP-FPM, and rebuilding the Drupal cache
   restored HTTP 200.

The database and Drupal message placement were not the cause. The operational
guard is to keep code operations under `umask 022`, then run the read-only
permission checker after the fast-forward update and before any Drupal cache
work.

Git records a regular file principally as `100644` or `100755`: it preserves
the executable distinction, but does not reliably restore ordinary read bits
such as `644` versus `600` through a reset, checkout, or pull. A restrictive
process umask can therefore make newly created tracked files unreadable to the
web identity even though Git reports no content change.

## Checker interface and policy

The interface is:

```text
check-web-runtime-permissions-2026.sh \
  [--project-root PATH] \
  [--web-user USER]
```

Examples from a repository checkout are:

```bash
./drupal/scripts/check-web-runtime-permissions-2026.sh

./drupal/scripts/check-web-runtime-permissions-2026.sh \
  --project-root /srv/example-app/releases/20260902/drupal \
  --web-user www-data
```

`/srv/example-app/releases/20260902/drupal` is a generic example, not a
production path. `--project-root` defaults to the Drupal project containing the
checker. `--web-user` defaults to `www-data` only if that account exists. An
explicit unknown account is refused; the checker never falls back silently to
the invoking user. Account lookup is pinned to the local `files` NSS source, so
it cannot contact a network-backed identity directory. Before a privileged
credential probe, the checker also requires the effective `initgroups` source
(or, when none is configured, the `group` source) in `/etc/nsswitch.conf` to be
exactly `files`. Any additional or ambiguous NSS source makes the result
indeterminate instead of risking a lookup or pretending that the supplementary
group list is complete. `UNISONGES_PHP_BIN` may name an absolute, trusted PHP
CLI when the project-compatible binary is not the default `php` in the
checker's fixed system `PATH`.

The accepted project root must be non-empty, must not be `/` or another broad
system root, must be inside a Git worktree, and must have tracked
`composer.json`, `composer.lock`, and `web/index.php` markers plus a real `web`
directory. No component of the supplied project path, Git worktree root, web
directory, or marker may be a symlink. The checker records canonical paths,
device/inode identities, and mount identities and revalidates them before it
finishes. A mount boundary below the accepted project root is indeterminate and
is not crossed.

The tracked-file inventory is read from Git with NUL delimiters. It selects:

- PHP-like runtime sources under `web` (`.php`, `.module`, `.theme`,
  `.install`, `.inc`, `.profile`, and `.engine`) and PHP tooling under
  `scripts`;
- Twig, YAML, CSS, and JavaScript runtime files under `web`;
- deployment YAML under `config` or `recipes`;
- runtime JSON below custom modules, themes, profiles, or `web/sites` when
  applicable;
- tracked `100755` shell helpers below `scripts`, classified separately as
  executable deployment/runtime tooling.

Documentation, test trees, screenshots, and binary media are not classified as
PHP runtime sources. An expected regular runtime file replaced by a symlink is
a definite failure; arbitrary untracked symlinks are not followed.

The checker inspects each required parent directory, suspicious owner-only
modes such as `600`, absence of read/traversal bits, writable modes, and the
worktree/index executable distinction. It opens files and traverses directories
through the kernel as the configured web identity whenever it can prove that
identity. A privileged invocation drops to the resolved UID, primary GID, and
locally complete supplementary-group set through an isolated Python process
using numeric credential syscalls only. The child verifies its real, effective,
saved, and filesystem IDs and verifies that access-bypassing capabilities are
absent before opening a path. It does not use a login shell, name-based NSS
calls, PAM, a password, or privilege escalation of its own. A non-root
deployment user that differs from the web user cannot prove allowed access and
therefore produces exit `2`, although mode bits with no ACL can still establish
a definite denial. Running as the web identity itself tests that process's
actual kernel group set. The privileged probe tests the standard local account
group set; a deployment that adds service-specific supplementary groups must
validate that separate configuration and treat uncertainty as exit `2`.

Mode `640` is not rejected categorically. It passes when an effective probe
proves that the web identity belongs to the owning group, and can also pass
when the kernel proves access granted by an ACL. If no effective probe is
possible, an ACL or security-context marker is reported as indeterminate; the
checker does not claim ACL support that it cannot prove. A mode `600` file
owned by the web identity may pass with a suspicious-mode warning, while the
incident pattern—mode `600`, deployment owner, different web user—fails.

Every selected PHP-like file is opened and syntax-checked only with a PHP CLI
that satisfies Drupal core's requirement recorded in `composer.lock`.
Filenames are passed as arguments, including leading-dash names, and Twig/YAML
are never sent to PHP. A syntax diagnostic reports the quoted failing path and
diagnostic, never source contents. An unavailable or incompatible PHP binary
makes syntax verification indeterminate.

Results are deliberately strict:

- `PASS` / exit `0`: the configured web identity demonstrably traversed and
  opened every selected web-runtime path, tooling checks passed, and all
  selected PHP-like files were syntactically valid;
- `FAIL` / exit `1`: at least one definite runtime-readability, traversal,
  regular-file/index-integrity, executable-tooling, or PHP syntax failure was
  found; definite failures take precedence if limitations were also seen;
- `INDETERMINATE` / exit `2`: arguments/root were refused, or identity
  switching, ACL/MAC context, Git/filesystem metadata, mount boundaries, PHP
  compatibility, or another environment limitation prevented proof.

Both exit `1` and exit `2` stop deployment. The access probe covers the named
UID/GIDs in the checker's current kernel security context; service-specific
mandatory-access-control confinement must still be reviewed on the real host.
The checker has no apply or repair mode.

## Safe deployment sequence

Run these steps in Bash from an already authorized production shell. Values
come from the approved release record; the examples contain no credentials or
real hostname.

### 1. Verify `release/prod`

```bash
set -euo pipefail
: "${APP_REPO_DIR:?Set the absolute production repository checkout}"
: "${APPROVED_TARGET_SHA:?Set the approved full 40-character release SHA}"
: "${PRIVATE_BACKUP_DIR:?Set an existing approved private backup directory}"
: "${PUBLIC_ORIGIN_HOST:?Set the approved public hostname}"
: "${LOCAL_NGINX_ADDRESS:=127.0.0.1}"
: "${HEALTH_PATH:=/}"

[[ "${APP_REPO_DIR}" == /* ]]
[[ "${PRIVATE_BACKUP_DIR}" == /* ]]
[[ "${APPROVED_TARGET_SHA}" =~ ^[0-9a-f]{40}$ ]]
APP_REPO_CANONICAL="$(cd -- "${APP_REPO_DIR}" && pwd -P)"
PRIVATE_BACKUP_CANONICAL="$(cd -- "${PRIVATE_BACKUP_DIR}" && pwd -P)"
test "${APP_REPO_CANONICAL}" = "${APP_REPO_DIR}"
test "${PRIVATE_BACKUP_CANONICAL}" = "${PRIVATE_BACKUP_DIR}"
[[ "${PRIVATE_BACKUP_CANONICAL}/" != "${APP_REPO_CANONICAL}/"* ]]
cd -- "${APP_REPO_DIR}"
test "$(git branch --show-current)" = "release/prod"
```

### 2. Verify a clean tracked checkout

```bash
test -z "$(git status --porcelain=v1 --untracked-files=no)"
```

Stop rather than stashing, discarding, or overwriting unexplained tracked
production changes.

### 3. Record the rollback SHA

```bash
ROLLBACK_SHA="$(git rev-parse --verify 'HEAD^{commit}')"
[[ "${ROLLBACK_SHA}" =~ ^[0-9a-f]{40}$ ]]
printf 'Rollback SHA: %s\n' "${ROLLBACK_SHA}"
```

Record this value in the deployment ticket before changing code.

### 4. Use `umask 022` for every code operation

```bash
umask 022
test "$(umask)" = "0022"
```

The comparison above was verified with the Bash builtin output format (`0022`)
used by this runbook. Do not set `077` globally in this shell.

### 5. Fetch and verify the exact target SHA

```bash
git fetch --no-tags origin release/prod
git cat-file -e "${APPROVED_TARGET_SHA}^{commit}"
FETCHED_RELEASE_SHA="$(git rev-parse --verify 'origin/release/prod^{commit}')"
test "${FETCHED_RELEASE_SHA}" = "${APPROVED_TARGET_SHA}"
git merge-base --is-ancestor "${ROLLBACK_SHA}" "${APPROVED_TARGET_SHA}"
test -z "$(git status --porcelain=v1 --untracked-files=no)"
test "$(umask)" = "0022"
```

### 6. Fast-forward with `git pull --ff-only`

```bash
git pull --ff-only origin release/prod
test "$(git rev-parse --verify 'HEAD^{commit}')" = "${APPROVED_TARGET_SHA}"
test "$(git branch --show-current)" = "release/prod"
test -z "$(git status --porcelain=v1 --untracked-files=no)"
test "$(umask)" = "0022"
```

### 7. Run the permission checker before Drupal cache work

Keep the deployment shell under the checkout-owning deployment identity. Give
privilege only to the bounded checker invocation so it can drop to the PHP-FPM
identity and prove effective access; do not perform Git operations as root. The
checker itself contains no privilege-escalation command and never requests a
password.

```bash
sudo --non-interactive -- \
  "${APP_REPO_DIR}/drupal/scripts/check-web-runtime-permissions-2026.sh" \
  --project-root "${APP_REPO_DIR}/drupal" \
  --web-user www-data
```

Continue only for `PASS` / exit `0`, after reviewing warnings. Exit `1` or `2`
stops the deployment and requires a separate reviewed resolution.

### 8. Create the database backup only inside a scoped `umask 077` subshell

The destination must already be approved, private, outside Git, and specific
to this environment. Substitute the approved database-backup tool at the
marked line; do not place credentials in this runbook.

```bash
test -d "${PRIVATE_BACKUP_DIR}"
BACKUP_PATH="${PRIVATE_BACKUP_DIR}/database-${APPROVED_TARGET_SHA}-$(date -u +%Y%m%dT%H%M%SZ)"
[[ ! -e "${BACKUP_PATH}" && ! -L "${BACKUP_PATH}" ]]

ORIGINAL_UMASK="$(umask)"
test "${ORIGINAL_UMASK}" = "0022"

(
  umask 077
  test "$(umask)" = "0077"
  # Create the private database backup at "${BACKUP_PATH}" here.
)
```

The parentheses are mandatory: `077` is confined to backup creation and never
surrounds a Git code operation.

### 9. Prove the outer shell umask is unchanged

```bash
test "$(umask)" = "${ORIGINAL_UMASK}"
test "$(umask)" = "0022"
test -f "${BACKUP_PATH}"
test ! -L "${BACKUP_PATH}"
test -s "${BACKUP_PATH}"
BACKUP_MODE="$(stat -c '%a' -- "${BACKUP_PATH}")"
[[ "${BACKUP_MODE}" =~ ^[0-7]{3,4}$ ]]
(( (8#${BACKUP_MODE} & 8#077) == 0 ))
printf 'Private database backup: %s\n' "${BACKUP_PATH}"
```

This comparison uses the same Bash `umask` builtin before and after the
subshell, so its formatting is identical.

### 10. Rebuild Drupal caches

Only after the permission checker passes and the outer umask proof succeeds,
run the environment's separately approved Drupal cache-rebuild command from
`"${APP_REPO_DIR}/drupal"`. This step grants no authority to import
configuration, update schema, or restore data.

### 11. Restart PHP-FPM only when required

Do not restart PHP-FPM routinely. Use the separately approved service procedure
only when the release requires it, and record the exact reason and outcome. The
permission checker never invokes a service manager.

### 12. Test Nginx locally with `--resolve`

This tests local Nginx while preserving the public Host header and TLS SNI; it
does not disable certificate verification.

```bash
LOCAL_HTTP_STATUS="$(curl \
  --silent --show-error --output /dev/null --write-out '%{http_code}' \
  --connect-timeout 10 --max-time 30 --noproxy '*' \
  --resolve "${PUBLIC_ORIGIN_HOST}:443:${LOCAL_NGINX_ADDRESS}" \
  "https://${PUBLIC_ORIGIN_HOST}${HEALTH_PATH}")"
test "${LOCAL_HTTP_STATUS}" = "200"
```

### 13. Test the public origin

```bash
PUBLIC_HTTP_STATUS="$(curl \
  --silent --show-error --output /dev/null --write-out '%{http_code}' \
  --connect-timeout 10 --max-time 30 --noproxy '*' \
  "https://${PUBLIC_ORIGIN_HOST}${HEALTH_PATH}")"
test "${PUBLIC_HTTP_STATUS}" = "200"
```

HTTP 500, timeout, an unexpected redirect, or any non-200 result stops
completion.

### 14. Verify final branch, target SHA, and tracked status

```bash
cd -- "${APP_REPO_DIR}"
test "$(git branch --show-current)" = "release/prod"
test "$(git rev-parse --verify 'HEAD^{commit}')" = "${APPROVED_TARGET_SHA}"
test -z "$(git status --porcelain=v1 --untracked-files=no)"
test "$(umask)" = "0022"
```

### 15. Retain rollback and backup information

Record the approved/deployed SHA, `ROLLBACK_SHA`, `BACKUP_PATH` and retention
location, checker status and warnings, cache result, any approved PHP-FPM
restart, and both HTTP statuses. Rollback is never automatic; use a separately
reviewed rollback procedure if required.

## Deployment-permission regression matrix

Every future deployment must verify, at minimum:

| Runtime surface | Minimum check |
| --- | --- |
| `unisonges_theme.theme` | regular tracked file, PHP-FPM can traverse/read, valid PHP |
| custom `.module` and `.install` files | regular tracked files, traversable/readable, valid PHP |
| custom service PHP classes | regular tracked files, traversable/readable, valid PHP |
| changed Twig templates | regular tracked files, traversable/readable |
| changed CSS and JavaScript assets | regular tracked files, traversable/readable |
| newly added runtime files | included by the tracked runtime inventory and readable |
| every parent directory to the web root/files | PHP-FPM can traverse the complete chain from `/` through the web root and runtime descendants |

## Pre-merge real-VPS read-only validation for PR #104

Do not execute this procedure as part of static PR work. The owner must supply
the final reviewed PR head in `EXPECTED_PR104_HEAD` and run the following as
the checkout-owning deployment identity, with pre-authorized non-interactive
privilege for only the checker. It begins in the production Drupal checkout,
does not switch branches, extracts only the checker, performs no repair, and
retains a private log. The SHA is intentionally not hardcoded here.

```bash
(
  set -euo pipefail

  : "${EXPECTED_PR104_HEAD:?Set the reviewed PR #104 head (40 lowercase hex characters)}"
  [[ "${EXPECTED_PR104_HEAD}" =~ ^[0-9a-f]{40}$ ]]

  cd -- /var/www/unisonges/repo/drupal
  PROD_REPO_ROOT="$(git rev-parse --show-toplevel)"
  INITIAL_BRANCH="$(git branch --show-current)"
  INITIAL_HEAD="$(git rev-parse --verify 'HEAD^{commit}')"
  INITIAL_TRACKED_STATUS="$(git status --porcelain=v1 --untracked-files=normal)"
  test "${INITIAL_BRANCH}" = "release/prod"
  test -z "${INITIAL_TRACKED_STATUS}"
  test "${PROD_REPO_ROOT}" = "/var/www/unisonges/repo"

  git fetch --no-tags origin \
    +refs/heads/release/prod:refs/remotes/origin/release/prod \
    +refs/heads/codex-harden-deployment-permission-safety:refs/remotes/origin/codex-harden-deployment-permission-safety

  FETCHED_PR104_HEAD="$(git rev-parse --verify 'origin/codex-harden-deployment-permission-safety^{commit}')"
  test "${FETCHED_PR104_HEAD}" = "${EXPECTED_PR104_HEAD}"
  git merge-base --is-ancestor origin/release/prod "${EXPECTED_PR104_HEAD}"
  test "$(git branch --show-current)" = "${INITIAL_BRANCH}"
  test "$(git rev-parse --verify 'HEAD^{commit}')" = "${INITIAL_HEAD}"
  test "$(git status --porcelain=v1 --untracked-files=normal)" = "${INITIAL_TRACKED_STATUS}"

  VALIDATION_DIR="$(umask 077; mktemp -d /var/tmp/pr104-permission-check.XXXXXXXXXX)"
  CHECKER_COPY="${VALIDATION_DIR}/check-web-runtime-permissions-2026.sh"
  LOG_PATH="${VALIDATION_DIR}/permission-check.log"
  printf 'PR104_PERMISSION_LOG=%s\n' "${LOG_PATH}"

  cleanup_checker() {
    if [[ -n "${CHECKER_COPY:-}" && ( -e "${CHECKER_COPY}" || -L "${CHECKER_COPY}" ) ]]; then
      unlink -- "${CHECKER_COPY}"
    fi
  }
  trap cleanup_checker EXIT

  (
    umask 077
    git -C "${PROD_REPO_ROOT}" show \
      "${EXPECTED_PR104_HEAD}:drupal/scripts/check-web-runtime-permissions-2026.sh" \
      >"${CHECKER_COPY}"
    : >"${LOG_PATH}"
  )

  bash --noprofile --norc -n -- "${CHECKER_COPY}"

  set +e
  PRIVILEGED_UID="$(sudo --non-interactive -- /usr/bin/id -u 2>/dev/null)"
  PRIVILEGE_STATUS=$?
  set -e
  if ((PRIVILEGE_STATUS != 0)) || [[ "${PRIVILEGED_UID}" != "0" ]]; then
    CHECKER_STATUS=2
    printf '%s\n' 'Privilege preflight failed; checker was not run.' | tee -- "${LOG_PATH}"
  else
    set +e
    sudo --non-interactive -- /bin/bash --noprofile --norc -- "${CHECKER_COPY}" \
      --project-root /var/www/unisonges/repo/drupal \
      --web-user www-data 2>&1 | tee -- "${LOG_PATH}"
    PIPELINE_STATUS=("${PIPESTATUS[@]}")
    set -e
    CHECKER_STATUS="${PIPELINE_STATUS[0]}"
    TEE_STATUS="${PIPELINE_STATUS[1]}"
    if ((TEE_STATUS != 0)); then
      CHECKER_STATUS=2
    fi
  fi

  RAW_CHECKER_STATUS="${CHECKER_STATUS}"
  if [[ "${RAW_CHECKER_STATUS}" == "0" ]] \
    && ! grep -Fqx -- \
      '[check-web-runtime-permissions-2026] RESULT: PASS (required access and syntax checks were demonstrated; review any warnings)' \
      "${LOG_PATH}"; then
    CHECKER_STATUS=2
  elif [[ "${RAW_CHECKER_STATUS}" == "1" ]] \
    && ! grep -Fqx -- \
      '[check-web-runtime-permissions-2026] RESULT: FAIL (definite runtime-readability, path-integrity, or PHP syntax failure)' \
      "${LOG_PATH}"; then
    CHECKER_STATUS=2
  fi

  cleanup_checker
  trap - EXIT
  test ! -e "${CHECKER_COPY}"
  test ! -L "${CHECKER_COPY}"

  FINAL_BRANCH="$(git branch --show-current)"
  FINAL_HEAD="$(git rev-parse --verify 'HEAD^{commit}')"
  FINAL_TRACKED_STATUS="$(git status --porcelain=v1 --untracked-files=normal)"
  test "${FINAL_BRANCH}" = "${INITIAL_BRANCH}"
  test "${FINAL_HEAD}" = "${INITIAL_HEAD}"
  test "${FINAL_TRACKED_STATUS}" = "${INITIAL_TRACKED_STATUS}"

  case "${CHECKER_STATUS}" in
    0)
      RESULT=PASS
      ACTION='runtime access proven'
      ;;
    1)
      RESULT=FAIL
      ACTION='definite failure; deployment must stop'
      ;;
    2)
      RESULT=INDETERMINATE
      ACTION='verification incomplete; deployment must stop until resolved'
      ;;
    *)
      RESULT=INDETERMINATE
      ACTION='unexpected checker status; deployment must stop until resolved'
      CHECKER_STATUS=2
      ;;
  esac

  {
    printf 'PR104_PERMISSION_RESULT=%s\n' "${RESULT}"
    printf 'PR104_PERMISSION_EXIT=%s\n' "${CHECKER_STATUS}"
    printf 'PR104_PERMISSION_ACTION=%s\n' "${ACTION}"
    printf 'PR104_PERMISSION_LOG=%s\n' "${LOG_PATH}"
  } | tee -a -- "${LOG_PATH}"
  exit "${CHECKER_STATUS}"
)
```

The exact expected status lines are:

```text
PR104_PERMISSION_RESULT=PASS
PR104_PERMISSION_EXIT=0
PR104_PERMISSION_ACTION=runtime access proven
```

```text
PR104_PERMISSION_RESULT=FAIL
PR104_PERMISSION_EXIT=1
PR104_PERMISSION_ACTION=definite failure; deployment must stop
```

```text
PR104_PERMISSION_RESULT=INDETERMINATE
PR104_PERMISSION_EXIT=2
PR104_PERMISSION_ACTION=verification incomplete; deployment must stop until resolved
```

PR #104 must remain draft until the owner supplies the complete log and status
from this real-VPS read-only procedure.

## Read-only boundary and static validation

The checker reads only Git metadata, tracked paths/modes, filesystem and parent
directory metadata, selected runtime files, PHP parser results, and local
user/group metadata. It does not change permissions or ownership, edit ACLs or
tracked files, repair paths, update code, invoke dependency or Drupal tools,
change caches/configuration/data, restart services, create a deployment, or
make a network request.

Static validation uses Bash parsing, ShellCheck, isolated Git fixture trees,
exit-code assertions, paths with spaces and NUL-safe unusual filenames, an
exact changed-file allowlist, open-PR overlap review, `git diff --check`,
UTF-8/NFC validation, secret scanning, and a forbidden-operation scan of the
checker. Before and after every fixture invocation, a manifest compares content
hashes, file modes, ownership, directory metadata, and Git status. Where local
tracing is available, successful mutating syscalls in the fixture/repository
tree and network socket/connect attempts by the checker are rejected. Reading
may legitimately update atime on filesystems that enable it; the proof does
not claim literally zero metadata activity.

Fixtures include the exact incident sequence (`644` pass, recreated
deployment-owned `600` fail, restored `644` pass), matching and non-matching
group `640`, `000`, a non-traversable parent, executable tooling, symlinked
roots/runtime paths, PHP syntax failure, missing users, spaces and
newline-bearing filenames, inaccessible Git metadata, inability to switch
identity, and ACL uncertainty. The only outstanding PR #104 gate is the
owner-run real-VPS read-only procedure above; no remote-host or runtime-resource
action belongs in this entirely static change.
