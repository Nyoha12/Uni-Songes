#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRUPAL_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${DRUPAL_DIR}/.." && pwd)"

log() {
  printf '[plan-local-fixtures] %s\n' "$*"
}

section() {
  printf '\n== %s ==\n' "$*"
}

section "Scope"
log "Planning helper only. No Drupal data will be created, changed, imported, or deleted."
log "Read the implementation plan: ${REPO_ROOT}/docs/dev/ddev-fixtures-plan.md"

section "Fixture source strategy"
cat <<'EOF'
- Generate deterministic local data through Drupal/Commerce/Webform APIs.
- Never import production data or production database dumps.
- Use local-only identifiers: local.fixture.* users and LOCAL-FIXTURE-* SKUs.
- Stop if Drupal cannot bootstrap active config.
EOF

section "Future create phase"
cat <<'EOF'
1. Verify DDEV, Drupal bootstrap, required modules, credit fields, product types, webform, and Google Calendar dry-run config.
2. Create or update non-uid-1 local test users with documented baseline credit fields.
3. Create or reuse a local Commerce store and manual/test payment gateway.
4. Create or update local course products and variations for:
   - cours_essai
   - cours_deb_inter
   - cours_avance
   - pack_4_deb_inter
5. Leave reservation webform config unchanged and submit only generated local test data.
6. Assert Google Calendar queue rows locally while real sync remains disabled or dry-run.
EOF

section "Future reset phase"
cat <<'EOF'
- Default to dry-run output.
- Require an explicit apply flag before changing data.
- Reset or delete only fixture-owned records matching local.fixture.* or LOCAL-FIXTURE-*.
- Remove queue rows only when linked to fixture webform submissions.
- Never run sql-drop, site:install, config:import, or production commands.
EOF

section "Tests enabled later"
cat <<'EOF'
- user credit grants and decrements;
- course product checkout and completed orders;
- reservation webform submission;
- Google Calendar queue dry-run behavior.
EOF
