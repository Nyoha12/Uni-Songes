# GitHub Codespaces with DDEV

This environment runs Docker and DDEV inside GitHub Codespaces. For the browser
workflow, the Windows PC needs only a browser and an internet connection; it
does not need Docker, DDEV, PHP, Composer, Node.js, or MariaDB installed
locally. The optional PowerShell workflow requires GitHub CLI (`gh`) on the
Windows PC, but the application dependencies still run only in the Codespace.

The environment is development-only. The VPS is production only: never run
these setup, fixture, branch-testing, or database commands on the VPS.

## Create the first Codespace

After this configuration has been merged into `release/prod`:

1. Open the Uni-Songes repository on GitHub and select the `release/prod`
   branch.
2. Select **Code**, then **Codespaces**, then **Create codespace on
   release/prod**. To choose the machine explicitly, use **New with options**.
3. Select a 4-core machine for Drupal runtime and Commerce tests. A 2-core
   machine can work, but image pulls, Composer, Drupal installation, and tests
   will be noticeably slower.
4. Keep forwarded ports private. Codespaces makes them private by default; do
   not change Drupal or Mailpit to public visibility.
5. In GitHub **Settings → Codespaces**, set the default idle timeout to 15
   minutes when organization policy permits it. This conserves compute quota.

The repository is normally mounted at `/workspaces/Uni-Songes`. More generally,
Codespaces uses `/workspaces/<repository-name>`. The setup script derives the
actual Git repository root, so it does not depend on the repository name.

On a 4-core machine, allow roughly 8–20 minutes for the first container build,
Docker image pulls, DDEV start, and locked Composer installation. The explicit
Drupal and fixture initialization below can take another 5–15 minutes. Network
and GitHub cache conditions can change these estimates, and a 2-core machine
can take considerably longer. Later resumes usually take about 1–3 minutes
because the image and dependency layers are already present.

The automatic post-create step intentionally stops after DDEV and dependencies
are ready. It also creates ignored, Codespaces-only DDEV overrides that bind
Drupal to port 8080 and Mailpit's in-container UI port 8025 to port 8027. A
runtime-only Drupal settings include accepts the forwarded host, protocol, and
port only for that Codespace's exact private tunnel, so authenticated redirects
stay on the reachable `app.github.dev` URL. None of these generated files under
`drupal/.ddev` are tracked. A non-Codespaces run removes the managed override
files before DDEV starts, so they cannot alter normal DDEV behavior. The
post-create step does not claim that Drupal or fixtures are initialized.

## Initialize the local site once

From the Codespaces terminal, run this one explicit command:

```bash
cd "$(git rev-parse --show-toplevel)" && ./.devcontainer/setup-project.sh --initialize-site
```

The command installs Drupal only when the DDEV database is completely empty. It
refuses a non-empty partial or unknown database, never imports production data,
and never replaces an existing Drupal installation. It then runs the existing
reviewed fixture scripts in check-first/apply order for the narrow local
fixture and Commerce surfaces.

Re-running the command is restart-safe: the Drupal installer is skipped when
Drupal already bootstraps, matching allowlisted configuration is retained, and
fixture entities are found by their stable local identifiers. Existing fixture
user passwords are retained. If any step fails, read the reported blocker and
rerun only after correcting it; a success message is printed only after every
requested step succeeds.

The initialization uses a fresh standard-profile local database, reserved
`example.invalid` mail addresses, and only the local manual Commerce gateway.
It performs no broad Drupal configuration import and makes no real PayPal,
Google Calendar, or external email calls. Normal local Drupal mail is handled
by DDEV's built-in Mailpit service.

## Local-only test credentials

These are known disposable credentials for this isolated fixture database.
They must never be reused on staging, production, or another service.

| Purpose | Username | Local-only password |
| --- | --- | --- |
| Drupal administrator | `admin` | `admin` |
| Commerce checkout fixture | `local.fixture.checkout` | `local-fixture-only` |
| Reservation credit fixture | `local.fixture.with_credit` | `local-fixture-only` |

The Codespaces ports must remain private even though these credentials are
deliberately weak and local-only.

## Verify the environment

Run the following after environment preparation:

```bash
docker version
ddev version
cd "$(git rev-parse --show-toplevel)/drupal"
ddev describe
ddev drush status
```

After local initialization, `ddev drush status` must report an installed Drupal
site and a successful bootstrap. DDEV provides PHP 8.3, MariaDB 10.11, Node 24,
Composer, and the lock-installed Drush executable inside its containers.

If a Codespace resume leaves DDEV stopped, the post-start hook normally starts
it again. If needed, run `cd drupal && ddev poweroff && ddev start -y` and
inspect any error instead of assuming the site is ready. This stops stale DDEV
containers but preserves the database volume.

## Open Drupal and Mailpit

In browser-based VS Code, open the **Ports** panel:

- Select the globe/open-browser action for port **8080** (`Uni-Songes Drupal`)
  to open the Drupal site through the private Codespaces HTTPS tunnel.
- Select the globe/open-browser action for port **8027** (`DDEV Mailpit`) to
  inspect locally captured mail.

Do not make either port public. Normal Drupal messages sent through the local
PHP mail path appear in Mailpit and are not delivered externally. The active
Commerce credit-flow test deliberately uses Drupal's in-memory test mail
collector during the test, so those temporary test messages do not appear in
Mailpit.

The Ports panel uses private HTTPS tunnel URLs of the following form:

```text
https://<codespace-name>-8080.app.github.dev
https://<codespace-name>-8027.app.github.dev
```

Do not replace the Drupal tunnel URL with a DDEV `localhost` URL in this
browser workflow. Sign in at `/user/login` with the disposable `admin` account;
the authenticated destination and `/user` must remain on the same private
`app.github.dev` host.

## Connect from Windows PowerShell

Install [GitHub CLI](https://cli.github.com/) on Windows and authenticate it,
then list the repository's Codespaces:

```powershell
gh auth login
gh codespace list --repo Nyoha12/Uni-Songes
$Codespace = "<codespace-name>"
```

Open an interactive shell in the dev container with:

```powershell
gh codespace ssh -c $Codespace
```

The committed `sshd` devcontainer feature is required for this command. Rebuild
an older Codespace after changing devcontainer features before retrying SSH.

To use local browser URLs, keep each of these blocking commands open in its own
PowerShell window:

```powershell
gh codespace ports forward 8080:8080 -c $Codespace
```

```powershell
gh codespace ports forward 8027:8027 -c $Codespace
```

The mapping order is `remote:local`. GitHub CLI binds these tunnels to the
Windows loopback interface by default; do not add `--all-interfaces`. Open:

- Drupal: <http://localhost:8080>
- Mailpit: <http://localhost:8027>

These localhost URLs use HTTP because they terminate at DDEV's direct HTTP
bindings inside the Codespace. They are distinct from the private HTTPS
`app.github.dev` URLs used by the Ports panel. Stop a local tunnel with
`Ctrl+C`.

Confirm that the Codespaces ports remain private:

```powershell
gh codespace ports -c $Codespace --json sourcePort,visibility
gh codespace ports visibility 8080:private 8027:private -c $Codespace
```

## Install Codex CLI in the Codespace shell

Node.js 24 and npm are installed in the outer dev container, so Codex CLI can
be installed from either the Codespaces terminal or a `gh codespace ssh`
session:

```bash
node --version
npm install -g @openai/codex
codex --version
codex
```

Follow the interactive sign-in flow. Do not put authentication tokens, API
keys, npm configuration containing credentials, or Codex authentication files
in this repository. The project itself requires no OpenAI secret. See the
[official Codex CLI documentation](https://developers.openai.com/codex/cli/)
for current installation and sign-in options.

## Test an existing pull request safely

For an open PR targeting `release/prod`, test GitHub's synthetic merge ref. It
contains the PR integrated with the current base, including this Codespaces
configuration, while the checkout remains detached. Start with a clean
worktree and replace `<PR_NUMBER>` with the pull request number. Initial setup
also records `drupal/.ddev/` in the clone's local Git exclude file, so the
runtime directory stays ignored across branch changes.

```bash
cd "$(git rev-parse --show-toplevel)"
git status --short
git switch release/prod
git pull --ff-only origin release/prod
git fetch origin pull/<PR_NUMBER>/merge
git switch --detach FETCH_HEAD
cd drupal
ddev start -y
ddev composer install --no-interaction --prefer-dist
ddev drush status
./scripts/test-local-commerce-credit-flow.sh --dry-run
```

The PR merge preview is checked out detached from `release/prod`. This checkout
neither creates a commit on, pushes, nor rewrites the base branch. If the merge
ref cannot be fetched, do not force the checkout: confirm that the PR is open,
targets `release/prod`, and is currently mergeable.

Run the credit-flow script's active mode only when the dry-run is clean and the
PR specifically needs that runtime coverage:

```bash
./scripts/test-local-commerce-credit-flow.sh --run
```

Keep all tests on generated local fixtures: do not add real payment
credentials, enable Google synchronization, send external mail, or import
production data. The DDEV database is shared between branches in this
Codespace, so export a backup before a test that may change local schema or
data.

Return to the base branch afterward:

```bash
cd "$(git rev-parse --show-toplevel)"
git status --short
git switch release/prod
git pull --ff-only origin release/prod
cd drupal
ddev composer install --no-interaction --prefer-dist
ddev restart
ddev drush status
```

If `git status --short` is not empty, preserve or discard those test changes
deliberately before switching branches. Do not force-switch over work that must
be kept.

## Preserve the local database before a rebuild

A normal stop/resume is not a full rebuild. A **Full Rebuild Container** can
remove the nested Docker volumes and therefore the DDEV database. Export a
local-only backup outside the Git checkout first:

```bash
mkdir -p /workspaces/.ddev-backups
cd "$(git rev-parse --show-toplevel)/drupal"
ddev export-db --file="/workspaces/.ddev-backups/unisonges-local-$(date +%Y%m%d-%H%M%S).sql.gz"
ls -lh /workspaces/.ddev-backups
```

This backup location is outside the repository. Back up only the generated
local fixture database; never place a production dump in the Codespace or Git
checkout.

## Stop or delete the Codespace

To stop compute usage from browser-based VS Code, open the Codespaces menu in
the lower-left corner and choose **Stop Current Codespace**. Alternatively, open
<https://github.com/codespaces>, use the Codespace's **…** menu, and choose
**Stop codespace**.

A stopped Codespace no longer consumes compute time, but it still consumes
storage until it is deleted. With a 15-minute idle timeout, forgotten sessions
stop quickly, but storage remains allocated.

When the environment and any local-only backup are no longer needed, open
<https://github.com/codespaces>, use the Codespace's **…** menu, choose
**Delete**, and confirm the deletion. Deletion removes the Codespace and its
unexported DDEV database state.

## Security boundaries

- No Codespaces secrets are required by this environment.
- Codex and GitHub CLI authentication remains user-specific and outside the
  repository.
- Do not copy any payment credential into `.devcontainer`, `.ddev`, or the
  local database.
- Do not upload SSH private keys or production database dumps.
- Keep Drupal and Mailpit ports private.
- Use only the generated manual Commerce gateway and invalid local email
  addresses.
- The production VPS is not a development or PR-testing target.
