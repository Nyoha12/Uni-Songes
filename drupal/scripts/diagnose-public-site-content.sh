#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
DRUPAL_DIR="$(cd "${SCRIPT_DIR}/.." && pwd -P)"
REPO_ROOT="$(cd "${DRUPAL_DIR}/.." && pwd -P)"
THEME_DIR="${DRUPAL_DIR}/web/themes/custom/unisonges_theme"
MODULE_DIR="${DRUPAL_DIR}/web/modules/custom/unisonges_structure"
SYNC_DIR="${DRUPAL_DIR}/config/sync"

PUBLIC_CHECKS=0
DDEV_CHECKS=0
VPS_CHECKS=0
ALLOW_VPS=0
BASE_URL="${UNISONGES_PUBLIC_BASE_URL:-https://unisonges.fr}"
DDEV_PROJECT_DIR="${UNISONGES_DDEV_PROJECT_DIR:-${DRUPAL_DIR}}"
VPS_TARGET="${UNISONGES_VPS_TARGET:-ubuntu@91.134.255.237}"
VPS_DRUPAL_DIR="${UNISONGES_VPS_DRUPAL_DIR:-/var/www/unisonges/repo/drupal}"
CURL_CONNECT_TIMEOUT="${UNISONGES_CURL_CONNECT_TIMEOUT:-10}"
CURL_MAX_TIME="${UNISONGES_CURL_MAX_TIME:-30}"
PUBLIC_TEMP_DIR=""

PUBLIC_PATHS=(
  "/"
  "/accueil"
  "/cours"
  "/cours/didgeridoo"
  "/cours/guimbarde"
  "/cours/meditation-improvisation"
  "/reservation-cours"
  "/reserver"
  "/stages"
  "/stages/didgeridoo"
  "/stages/musique-improvisee-meditation"
  "/stages/speciaux"
  "/concerts"
  "/association"
  "/les-artistes-de-l-asso"
  "/services-prestations-artistiques"
  "/djam"
  "/orchestre-des-reveurs"
  "/contact"
  "/cart"
)

RELATED_PUBLIC_PATHS=(
  "/user/register"
  "/user/login"
  "/sitemap.xml"
  "/robots.txt"
)

LEGACY_PUBLIC_PATHS=(
  "/cours-bien-etre-respiration"
  "/cours-musique-impro-compo"
  "/reserver-un-cours"
  "/concerts-dates"
  "/association-unisonges"
  "/stages-debutant"
  "/stages-intermediaire"
  "/stages-sur-demande"
  "/masterclass-avancee"
  "/videos"
  "/mentions-legales"
  "/politique-confidentialite"
)

log() {
  printf '[diagnose-public-site-content] %s\n' "$*"
}

warn() {
  printf '[diagnose-public-site-content] WARNING: %s\n' "$*" >&2
}

die() {
  printf '[diagnose-public-site-content] ERROR: %s\n' "$*" >&2
  exit 2
}

section() {
  printf '\n== %s ==\n' "$*"
}

cleanup_public_temp() {
  if [[ -n "${PUBLIC_TEMP_DIR:-}" && -d "${PUBLIC_TEMP_DIR}" ]]; then
    rm -rf -- "${PUBLIC_TEMP_DIR}"
  fi
  PUBLIC_TEMP_DIR=""
}

usage() {
  cat <<'EOF'
Usage: ./scripts/diagnose-public-site-content.sh [options]

Read-only audit helper for the public Uni-Songes content and layout sources.
Without options it runs in repository-only mode: no network, DDEV, SSH, Drupal
bootstrap, database, config, or content access is attempted.

Optional checks:
  --public                 GET the allowlisted public URLs with curl and inspect
                           raw HTML signals (not a browser or visual audit).
  --base-url URL           Public origin. Default: https://unisonges.fr
  --ddev                   Inspect local active state through read-only DDEV and
                           SQL queries. DDEV must already be running.
  --ddev-project-dir PATH  Host DDEV project directory when it is outside this
                           worktree. Default: this Drupal directory.
  --vps                    Inspect the configured VPS over SSH using read-only
                           Git, file, Drush status, and SQL queries.
  --allow-vps              Mandatory acknowledgement for --vps. It never permits
                           write commands; it only unlocks the VPS read path.
  --vps-target USER@HOST   SSH target. Default: ubuntu@91.134.255.237
  --vps-drupal-dir PATH    Remote Drupal directory. Default:
                           /var/www/unisonges/repo/drupal
  --repository-only        Explicitly select the default repository-only mode.
  -h, --help               Show this help.

Environment overrides:
  UNISONGES_PUBLIC_BASE_URL
  UNISONGES_DDEV_PROJECT_DIR
  UNISONGES_VPS_TARGET
  UNISONGES_VPS_DRUPAL_DIR
  UNISONGES_CURL_CONNECT_TIMEOUT
  UNISONGES_CURL_MAX_TIME

Safety contract:
  - no database writes; SQL is wrapped in READ ONLY transactions;
  - no config import/export/write and no cache rebuild;
  - no node, block, menu, product, order, or other content writes;
  - no deployment, pull, checkout, Composer, or DDEV configuration command;
  - public checks use GET only and store responses in a removed temporary dir;
  - VPS checks require both --vps and --allow-vps.
EOF
}

repository_only_requested=0
while (($#)); do
  case "$1" in
    --public)
      PUBLIC_CHECKS=1
      ;;
    --base-url)
      (($# >= 2)) || die "--base-url requires a value."
      BASE_URL="$2"
      shift
      ;;
    --ddev)
      DDEV_CHECKS=1
      ;;
    --ddev-project-dir)
      (($# >= 2)) || die "--ddev-project-dir requires a value."
      DDEV_PROJECT_DIR="$2"
      shift
      ;;
    --vps)
      VPS_CHECKS=1
      ;;
    --allow-vps)
      ALLOW_VPS=1
      ;;
    --vps-target)
      (($# >= 2)) || die "--vps-target requires a value."
      VPS_TARGET="$2"
      shift
      ;;
    --vps-drupal-dir)
      (($# >= 2)) || die "--vps-drupal-dir requires a value."
      VPS_DRUPAL_DIR="$2"
      shift
      ;;
    --repository-only)
      repository_only_requested=1
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      die "Unknown argument: $1 (use --help)."
      ;;
  esac
  shift
done

if ((repository_only_requested)) && ((PUBLIC_CHECKS || DDEV_CHECKS || VPS_CHECKS)); then
  die "--repository-only cannot be combined with --public, --ddev, or --vps."
fi

if ((VPS_CHECKS)) && ((ALLOW_VPS == 0)); then
  die "--vps is refused without the explicit --allow-vps acknowledgement."
fi

if ((ALLOW_VPS)) && ((VPS_CHECKS == 0)); then
  die "--allow-vps is only valid together with --vps."
fi

case "${DRUPAL_DIR}" in
  /mnt/c|/mnt/c/*)
    die "Refusing to run from /mnt/c; use the WSL worktree or a reviewed VPS checkout."
    ;;
  /var/www|/var/www/*)
    die "Refusing to run the local script from /var/www; invoke VPS checks from a reviewed worktree."
    ;;
esac

if [[ ! -f "${DRUPAL_DIR}/composer.json" || ! -d "${THEME_DIR}" || ! -d "${SYNC_DIR}" ]]; then
  die "Expected Uni-Songes Drupal repository structure was not found under ${DRUPAL_DIR}."
fi

print_files_with_metadata() {
  local heading="$1"
  shift
  section "${heading}"

  if (($# == 0)); then
    printf '(none)\n'
    return 0
  fi

  local file
  for file in "$@"; do
    [[ -f "${file}" ]] || continue
    printf '%s\t%s lines\n' "${file#"${REPO_ROOT}/"}" "$(wc -l < "${file}" | tr -d ' ')"
  done
}

print_grep() {
  local heading="$1"
  local pattern="$2"
  shift 2
  section "${heading}"

  if (($# == 0)); then
    printf '(no files selected)\n'
    return 0
  fi

  local output
  output="$(LC_ALL=C grep -nEHi -- "${pattern}" "$@" 2>/dev/null || true)"
  if [[ -n "${output}" ]]; then
    printf '%s\n' "${output}" | sed "s#${REPO_ROOT}/##g"
  else
    printf '(no matches)\n'
  fi
}

run_repository_checks() {
  local selected_mode="repository-only"
  if ((PUBLIC_CHECKS || DDEV_CHECKS || VPS_CHECKS)); then
    selected_mode="repository plus selected optional checks"
  fi

  section "Read-only guard and scope"
  cat <<EOF
Mode: ${selected_mode}
Repository: ${REPO_ROOT}
Drupal directory: ${DRUPAL_DIR}
Network in repository phase: disabled
Mutations: disabled
Public path count: ${#PUBLIC_PATHS[@]}
EOF

  printf 'Allowlisted public paths:\n'
  printf '  - %s\n' "${PUBLIC_PATHS[@]}"
  printf 'Related target paths (public link/status checks only):\n'
  printf '  - %s\n' "${RELATED_PUBLIC_PATHS[@]}"
  printf 'Legacy route candidates (public status checks only):\n'
  printf '  - %s\n' "${LEGACY_PUBLIC_PATHS[@]}"

  section "Git baseline (read-only)"
  printf 'Branch: %s\n' "$(git --no-optional-locks -C "${REPO_ROOT}" branch --show-current 2>/dev/null || printf '(unknown)')"
  printf 'HEAD: %s\n' "$(git --no-optional-locks -C "${REPO_ROOT}" rev-parse HEAD 2>/dev/null || printf '(unknown)')"
  printf 'Status:\n'
  git --no-optional-locks -C "${REPO_ROOT}" status --short 2>/dev/null || true

  local -a node_templates=()
  local -a general_templates=()
  local -a content_scripts=()
  local -a block_configs=()
  local -a view_configs=()
  local -a menu_configs=()
  local -a module_files=()
  local -a css_files=()
  local -a legacy_public_files=()

  mapfile -d '' -t node_templates < <(find "${THEME_DIR}/templates" -type f -name 'node--*.html.twig' -print0 | sort -z)
  mapfile -d '' -t general_templates < <(find "${THEME_DIR}/templates" -type f -name '*.twig' ! -name 'node--*.html.twig' -print0 | sort -z)
  mapfile -d '' -t content_scripts < <(
    find "${DRUPAL_DIR}/scripts" -maxdepth 1 -type f -name '*.sh' \
      ! -name 'diagnose-public-site-content.sh' -print0 | sort -z
  )
  mapfile -d '' -t block_configs < <(find "${SYNC_DIR}" -maxdepth 1 -type f -name 'block.block.*.yml' -print0 | sort -z)
  mapfile -d '' -t view_configs < <(find "${SYNC_DIR}" -maxdepth 1 -type f -name 'views.view.*.yml' -print0 | sort -z)
  mapfile -d '' -t menu_configs < <(find "${SYNC_DIR}" -maxdepth 1 -type f \( -name 'system.menu.*.yml' -o -name 'core.menu.*.yml' \) -print0 | sort -z)
  mapfile -d '' -t module_files < <(find "${MODULE_DIR}" -type f \( -name '*.php' -o -name '*.module' -o -name '*.yml' \) -print0 | sort -z)
  mapfile -d '' -t css_files < <(find "${THEME_DIR}" -type f -name '*.css' -print0 | sort -z)
  mapfile -d '' -t legacy_public_files < <(find "${REPO_ROOT}/public" -type f \( -name '*.html' -o -name '*.xml' -o -name '*.js' \) -print0 | sort -z)

  print_files_with_metadata "Node-specific Twig templates" "${node_templates[@]}"
  print_files_with_metadata "General page/include Twig templates" "${general_templates[@]}"

  print_grep \
    "Hardcoded visible text and links in node-specific Twig" \
    "(intro:|title:|label:|link_label:|href=|<h[1-6]|<p>|<li>|#markup|#title)" \
    "${node_templates[@]}"

  print_grep \
    "Hardcoded visible text and links in general Twig" \
    "(href=|<h[1-6]|<p>|<li>|Réserver|Cours|Stages|Concerts|Association|Contact|Mon compte)" \
    "${general_templates[@]}"

  section "Block config names (repository filenames and selected metadata only)"
  local file
  for file in "${block_configs[@]}"; do
    printf '\n[%s]\n' "${file#"${REPO_ROOT}/"}"
    grep -nE '^(id|status|theme|region|plugin):|^  label:|^  label_display:' "${file}" || true
  done

  section "Views config names (repository filenames and selected metadata only)"
  for file in "${view_configs[@]}"; do
    printf '%s\t' "${file#"${REPO_ROOT}/"}"
    awk '/^id: / { id=$0; sub(/^id: /, "", id) } /^label: / { label=$0; sub(/^label: /, "", label) } END { printf "id=%s label=%s\n", id, label }' "${file}"
  done

  section "Menu definitions and static overrides (repository config only)"
  for file in "${menu_configs[@]}"; do
    printf '\n[%s]\n' "${file#"${REPO_ROOT}/"}"
    grep -nE '^(id|label|description|locked|status):|^[[:space:]]+(enabled|menu_name|parent|weight|expanded):' "${file}" || true
  done

  print_grep \
    "Custom module routes, render arrays, and embedded public paths" \
    "(#markup|#type|#theme|#title|#description|#prefix|#suffix|fromUserInput|fromUri|internal:|path:|/cours|/stages|/reserver|/reservation-cours|/cart|/product/)" \
    "${module_files[@]}"

  print_grep \
    "Content architecture sources and embedded visible copy" \
    "(alias|title|body|<section|<article|<h[1-6]|<p|<a[[:space:]]+href|menu|Cours|Stages|Artistes|Prestations|Association)" \
    "${content_scripts[@]}"

  print_grep \
    "Obsolete course, pack, level, advanced, and purchase-first wording" \
    "(cours[_ -]?avance|cours avanc|avancée?|pack[_ -]?4|pack de|débutant|intermédiaire|deb[_ -]?inter|masterclass|acheter|achat|voir les tarifs et acheter|product/[0-9]+|commerce_product)" \
    "${node_templates[@]}" "${general_templates[@]}" "${content_scripts[@]}" "${module_files[@]}" "${SYNC_DIR}"/*.yml

  print_grep \
    "Old and current route references" \
    "(/accueil|/cours-bien-etre-respiration|/cours-musique-impro-compo|/reserver-un-cours|/concerts-dates|/association-unisonges|/stages-debutant|/stages-intermediaire|/stages-sur-demande|/masterclass-avancee|/cours/didgeridoo|/cours/guimbarde|/cours/meditation-improvisation|/reservation-cours|/reserver|/stages/didgeridoo|/stages/musique-improvisee-meditation|/stages/speciaux|/les-artistes-de-l-asso|/services-prestations-artistiques)" \
    "${node_templates[@]}" "${general_templates[@]}" "${content_scripts[@]}" "${module_files[@]}" "${legacy_public_files[@]}"

  print_grep \
    "CSS selectors affecting page, section, card, portal, and responsive layout" \
    "(^|[,{[:space:]])(body|main|\.layout|\.container|\.scrollframe|\.panel|\.hero|\.card|\.card-grid|\.unisonges-card-grid|\.unisonges-offer-card|\.unisonges-detail-section|\.node-body|\.reservation-portal|\.views-|\.view-|\.commerce-|@media)" \
    "${css_files[@]}"

  section "Legacy static public tree (independent source-of-truth warning)"
  printf 'Legacy files counted: %s\n' "${#legacy_public_files[@]}"
  printf '%s\n' "${legacy_public_files[@]#"${REPO_ROOT}/"}"
  print_grep \
    "Legacy static canonical URLs, headings, and route links" \
    "(canonical|<h[1-6]|href=|uni-songes\.pages\.dev|cours-bien-etre|cours-musique-impro|masterclass|stages-(debutant|intermediaire|sur-demande)|reserver-un-cours|concerts-dates|association-unisonges)" \
    "${legacy_public_files[@]}"

  section "Repository interpretation boundary"
  cat <<'EOF'
The repository scan proves only that these strings/config names/selectors exist in
working-tree sources. It does not prove which active Drupal entity revision, block,
View display, menu link, template suggestion, cache entry, or CSS rule is rendered
in production. Use --public for raw rendered HTML evidence and --ddev/--vps for
read-only active-state evidence. None of these modes is a visual browser audit.
EOF
}

validate_base_url() {
  local validated_origin
  validated_origin="$(python3 - "${BASE_URL}" <<'PY'
import re
import sys
from urllib.parse import urlsplit

value = sys.argv[1]
parts = urlsplit(value)
netloc_pattern = re.compile(
    r"(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:.]+\])(?::[0-9]{1,5})?"
)

try:
    port = parts.port
except ValueError:
    raise SystemExit(1)

valid = (
    parts.scheme in {"http", "https"}
    and bool(parts.hostname)
    and parts.username is None
    and parts.password is None
    and parts.path in {"", "/"}
    and not parts.query
    and not parts.fragment
    and "?" not in value
    and "#" not in value
    and netloc_pattern.fullmatch(parts.netloc) is not None
    and (port is None or 1 <= port <= 65535)
)
if not valid:
    raise SystemExit(1)

print(f"{parts.scheme}://{parts.netloc}")
PY
  )" || die "--base-url must be a credential-free http(s) origin with no path, query, or fragment."
  BASE_URL="${validated_origin}"
}

print_html_signals() {
  local body_file="$1"
  python3 - "${body_file}" <<'PY'
import html
import collections
import re
import sys
from html.parser import HTMLParser


class Signals(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.capture = None
        self.buffer = []
        self.items = {"title": [], "h1": [], "h2": [], "p": []}
        self.canonical = []
        self.descriptions = []
        self.links = []
        self.ids = []
        self.main_count = 0
        self.main_depth = 0
        self.footer_count = 0
        self.form_count = 0
        self.ignored_depth = 0
        self.main_text = []

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag in ("script", "style", "template"):
            self.ignored_depth += 1
        if tag == "main":
            self.main_count += 1
            self.main_depth += 1
        if tag == "footer":
            self.footer_count += 1
        if tag == "form":
            self.form_count += 1
        if attrs.get("id"):
            self.ids.append(attrs["id"])
        if tag in self.items:
            self.capture = tag
            self.buffer = []
        if tag == "link" and "canonical" in attrs.get("rel", "").lower().split():
            self.canonical.append(attrs.get("href", ""))
        if tag == "meta" and attrs.get("name", "").casefold() == "description":
            self.descriptions.append(attrs.get("content", ""))
        if tag == "a" and attrs.get("href"):
            self.links.append(attrs["href"])

    def handle_endtag(self, tag):
        if self.capture == tag:
            value = re.sub(r"\s+", " ", html.unescape("".join(self.buffer))).strip()
            if value:
                self.items[tag].append(value)
            self.capture = None
            self.buffer = []
        if tag == "main" and self.main_depth:
            self.main_depth -= 1
        if tag in ("script", "style", "template") and self.ignored_depth:
            self.ignored_depth -= 1

    def handle_data(self, data):
        if self.capture:
            self.buffer.append(data)
        if self.main_depth and not self.ignored_depth:
            self.main_text.append(data)


parser = Signals()
with open(sys.argv[1], encoding="utf-8", errors="replace") as stream:
    parser.feed(stream.read())

def clipped(values, limit=240):
    return " | ".join(value[:limit] for value in values) or "(none)"

print(f"  title ({len(parser.items['title'])}): {clipped(parser.items['title'])}")
print(f"  canonical ({len(parser.canonical)}): {clipped(parser.canonical)}")
print(f"  meta description ({len(parser.descriptions)}): {clipped(parser.descriptions)}")
print(f"  h1 ({len(parser.items['h1'])}): {clipped(parser.items['h1'])}")
print(f"  h2 ({len(parser.items['h2'])}): {clipped(parser.items['h2'])}")
normalized = {}
for kind in ("h1", "h2", "p"):
    for value in parser.items[kind]:
        key = re.sub(r"\W+", " ", value.casefold()).strip()
        normalized.setdefault((kind, key), []).append(value)
duplicates = [f"{kind} x{len(values)}: {values[0][:180]}" for (kind, _), values in normalized.items() if len(values) > 1]
print(f"  duplicate heading/paragraph signals: {clipped(duplicates, 360)}")
duplicate_ids = [f"{value} x{count}" for value, count in collections.Counter(parser.ids).items() if count > 1]
print(f"  duplicate DOM IDs: {clipped(sorted(duplicate_ids), 360)}")
main_text = re.sub(r"\s+", " ", " ".join(parser.main_text)).strip()
main_words = re.findall(r"\b[\wÀ-ÿ’'-]+\b", main_text)
contact_links = [link for link in parser.links if link.startswith(("mailto:", "tel:"))]
signals = []
for label, pattern in (
    ("placeholder", r"section à compléter"),
    ("author metadata", r"soumis par"),
    ("advanced wording", r"\bavancé(?:e)?\b"),
    ("purchase wording", r"\bacheter\b"),
    ("empty-event heading", r"nouveaux évènements"),
):
    if re.search(pattern, main_text, re.IGNORECASE):
        signals.append(label)
print(f"  landmarks/forms: main={parser.main_count} footer={parser.footer_count} form={parser.form_count}")
print(f"  main-text word approximation: {len(main_words)}")
print(f"  mailto/tel hrefs: {clipped(contact_links)}")
print(f"  content flags: {clipped(signals)}")
print(f"  href count: {len(parser.links)}")
PY
}

extract_same_origin_paths() {
  local body_file="$1"
  local page_url="$2"
  python3 - "${body_file}" "${page_url}" "${BASE_URL}" <<'PY'
import sys
from html.parser import HTMLParser
from urllib.parse import urljoin, urlsplit


class LinkParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.links = []

    def handle_starttag(self, tag, attrs):
        if tag != "a":
            return
        attrs = dict(attrs)
        if attrs.get("href"):
            self.links.append(attrs["href"])


body_file, page_url, base_url = sys.argv[1:]
parser = LinkParser()
with open(body_file, encoding="utf-8", errors="replace") as stream:
    parser.feed(stream.read())
base_host = urlsplit(base_url).netloc.casefold()
paths = set()
for href in parser.links:
    if href.startswith(("#", "mailto:", "tel:", "javascript:")):
        continue
    absolute = urljoin(page_url, href)
    parsed = urlsplit(absolute)
    if parsed.netloc.casefold() == base_host:
        paths.add(parsed.path or "/")
for path in sorted(paths):
    print(path)
PY
}

print_cross_page_html_signals() {
  local manifest_file="$1"
  python3 - "${manifest_file}" "${BASE_URL}" <<'PY'
import collections
import html
import os
import re
import sys
from html.parser import HTMLParser
from urllib.parse import urljoin, urlsplit


class ContentParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.capture = None
        self.buffer = []
        self.text = {"h1": [], "h2": [], "p": []}
        self.links = []

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag in self.text:
            self.capture = tag
            self.buffer = []
        if tag == "a" and attrs.get("href"):
            self.links.append(attrs["href"])

    def handle_endtag(self, tag):
        if self.capture == tag:
            value = re.sub(r"\s+", " ", html.unescape("".join(self.buffer))).strip()
            if value:
                self.text[tag].append(value)
            self.capture = None
            self.buffer = []

    def handle_data(self, data):
        if self.capture:
            self.buffer.append(data)


manifest = []
with open(sys.argv[1], encoding="utf-8") as stream:
    for raw in stream:
        route, status, final_url, body_file = raw.rstrip("\n").split("\t", 3)
        manifest.append((route, status, final_url, body_file))

base = sys.argv[2]
base_host = urlsplit(base).netloc.casefold()
scope = {route.rstrip("/") or "/" for route, *_ in manifest}
incoming = collections.Counter()
occurrences = collections.defaultdict(list)
direct_product_links = []
out_of_scope_links = collections.defaultdict(set)

for route, status, final_url, body_file in manifest:
    if not os.path.isfile(body_file):
        continue
    parser = ContentParser()
    with open(body_file, encoding="utf-8", errors="replace") as stream:
        parser.feed(stream.read())

    for kind, values in parser.text.items():
        for value in values:
            normalized = re.sub(r"\W+", " ", value.casefold()).strip()
            if len(normalized) >= 30:
                occurrences[(kind, normalized)].append((route, value))

    for href in parser.links:
        if href.startswith(("#", "mailto:", "tel:", "javascript:")):
            continue
        absolute = urljoin(final_url or base + route, href)
        parsed = urlsplit(absolute)
        if parsed.netloc.casefold() != base_host:
            continue
        path = (parsed.path.rstrip("/") or "/")
        if re.search(r"/(product|checkout|cart)(/|$)", path):
            direct_product_links.append((route, href))
        if path in scope:
            if path != (route.rstrip("/") or "/"):
                incoming[path] += 1
        else:
            out_of_scope_links[route].add(path)

print("\nCross-page repeated heading/paragraph signals (exact normalized text, >=30 chars):")
repeats = []
for (kind, _), items in occurrences.items():
    routes = sorted({route for route, _ in items})
    if len(routes) > 1:
        repeats.append((kind, routes, items[0][1]))
if repeats:
    for kind, routes, value in sorted(repeats):
        print(f"  - {kind}: {value[:220]}")
        print(f"    routes: {', '.join(routes)}")
else:
    print("  (none)")

print("\nRequested-route incoming-link signals (within downloaded pages):")
for route in sorted(scope):
    print(f"  - {route}: {incoming[route]} incoming link(s)")

print("\nDirect Commerce/cart/checkout link signals in downloaded HTML:")
if direct_product_links:
    for source, href in sorted(set(direct_product_links)):
        print(f"  - {source} -> {href}")
else:
    print("  (none)")

print("\nInternal links outside the requested URL set (status not checked here):")
if out_of_scope_links:
    for source, paths in sorted(out_of_scope_links.items()):
        print(f"  - {source}: {', '.join(sorted(paths))}")
else:
    print("  (none)")
PY
}

run_public_checks() {
  command -v curl >/dev/null 2>&1 || die "curl is required for --public."
  command -v python3 >/dev/null 2>&1 || die "python3 is required to inspect --public HTML."
  validate_base_url

  local temp_dir
  temp_dir="$(mktemp -d -t unisonges-public-audit.XXXXXX)"
  PUBLIC_TEMP_DIR="${temp_dir}"
  trap cleanup_public_temp EXIT
  local manifest_file="${temp_dir}/manifest.tsv"
  local discovered_paths_file="${temp_dir}/discovered-paths.txt"
  : > "${manifest_file}"
  : > "${discovered_paths_file}"

  section "Public curl checks (raw HTTP/HTML, not a browser)"
  printf 'Observed at (UTC): %s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
  printf 'Base URL: %s\n' "${BASE_URL}"
  printf 'Method: unauthenticated curl GET, redirects followed, no JavaScript execution\n'

  local index=0
  local route
  for route in "${PUBLIC_PATHS[@]}"; do
    index=$((index + 1))
    local body_file="${temp_dir}/body-${index}.html"
    local header_file="${temp_dir}/headers-${index}.txt"
    local requested_url="${BASE_URL}${route}"
    local curl_meta
    local curl_exit=0

    curl_meta="$(curl --disable \
      --request GET \
      --proto '=http,https' \
      --proto-redir '=http,https' \
      --silent \
      --show-error \
      --location \
      --max-redirs 8 \
      --connect-timeout "${CURL_CONNECT_TIMEOUT}" \
      --max-time "${CURL_MAX_TIME}" \
      --user-agent 'UniSonges-read-only-content-audit/2026' \
      --dump-header "${header_file}" \
      --output "${body_file}" \
      --write-out $'%{http_code}\t%{url_effective}\t%{content_type}\t%{num_redirects}' \
      -- "${requested_url}")" || curl_exit=$?

    printf '\n[%s]\n' "${route}"
    if ((curl_exit != 0)); then
      printf '  curl exit: %s\n' "${curl_exit}"
      printf '  result: unavailable\n'
      printf '%s\t%s\t%s\t%s\n' "${route}" "curl-${curl_exit}" "" "${body_file}" >> "${manifest_file}"
      continue
    fi

    local status final_url content_type redirect_count
    IFS=$'\t' read -r status final_url content_type redirect_count <<< "${curl_meta}"
    printf '  status: %s\n' "${status}"
    printf '  final URL: %s\n' "${final_url}"
    printf '  content type: %s\n' "${content_type:-'(none)'}"
    printf '  redirects: %s\n' "${redirect_count}"
    printf '  Location chain: '
    local locations
    locations="$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/\r$/, ""); sub(/^[^:]+:[[:space:]]*/, ""); printf "%s%s", separator, $0; separator=" -> " }' "${header_file}")"
    printf '%s\n' "${locations:-'(none)'}"

    if [[ "${content_type}" == text/html* ]]; then
      print_html_signals "${body_file}"
      extract_same_origin_paths "${body_file}" "${final_url}" >> "${discovered_paths_file}"
    else
      printf '  HTML signals: skipped (non-HTML response)\n'
    fi
    printf '%s\t%s\t%s\t%s\n' "${route}" "${status}" "${final_url}" "${body_file}" >> "${manifest_file}"
  done

  section "Public cross-page raw-HTML signals"
  print_cross_page_html_signals "${manifest_file}"

  section "Related/discovered internal-link status checks"
  cat <<'EOF'
These are GET checks for same-origin links discovered in the audited HTML plus a
small allowlist of functional, legacy, and utility targets. A 4xx/5xx is status
evidence requiring target-specific interpretation; zero incoming links is only
an orphan signal within this downloaded corpus.
EOF
  printf '%s\n' "${RELATED_PUBLIC_PATHS[@]}" "${LEGACY_PUBLIC_PATHS[@]}" >> "${discovered_paths_file}"
  LC_ALL=C sort -u "${discovered_paths_file}" > "${temp_dir}/unique-discovered-paths.txt"
  while IFS= read -r route; do
    [[ -n "${route}" ]] || continue
    case "${route}" in
      /*) ;;
      *) continue ;;
    esac
    local link_meta
    local link_exit=0
    link_meta="$(curl --disable \
      --request GET \
      --proto '=http,https' \
      --proto-redir '=http,https' \
      --silent \
      --show-error \
      --location \
      --max-redirs 8 \
      --connect-timeout "${CURL_CONNECT_TIMEOUT}" \
      --max-time "${CURL_MAX_TIME}" \
      --user-agent 'UniSonges-read-only-content-audit/2026' \
      --output /dev/null \
      --write-out $'%{http_code}\t%{url_effective}\t%{num_redirects}' \
      -- "${BASE_URL}${route}")" || link_exit=$?
    if ((link_exit != 0)); then
      printf '%s\tcurl-%s\t(unavailable)\n' "${route}" "${link_exit}"
    else
      printf '%s\t%s\n' "${route}" "${link_meta}"
    fi
  done < "${temp_dir}/unique-discovered-paths.txt"

  section "Sitemap and robots coverage"
  local sitemap_file="${temp_dir}/sitemap.xml"
  local robots_file="${temp_dir}/robots.txt"
  local requested_paths_file="${temp_dir}/requested-paths.txt"
  printf '%s\n' "${PUBLIC_PATHS[@]}" > "${requested_paths_file}"
  if curl --disable \
    --request GET \
    --proto '=http,https' \
    --proto-redir '=http,https' \
    --silent \
    --show-error \
    --location \
    --connect-timeout "${CURL_CONNECT_TIMEOUT}" \
    --max-time "${CURL_MAX_TIME}" \
    --user-agent 'UniSonges-read-only-content-audit/2026' \
    --output "${sitemap_file}" \
    -- "${BASE_URL}/sitemap.xml"; then
    python3 - "${sitemap_file}" "${requested_paths_file}" "${BASE_URL}" <<'PY'
import sys
import xml.etree.ElementTree as ET
from urllib.parse import urlsplit

sitemap_file, requested_file, base_url = sys.argv[1:]
try:
    root = ET.parse(sitemap_file).getroot()
except ET.ParseError as error:
    print(f"Sitemap XML parse error: {error}")
    raise SystemExit(0)

locations = []
for element in root.iter():
    if element.tag.rsplit("}", 1)[-1] == "loc" and element.text:
        locations.append(element.text.strip())
paths = {urlsplit(location).path.rstrip("/") or "/" for location in locations}
with open(requested_file, encoding="utf-8") as stream:
    requested = [line.strip().rstrip("/") or "/" for line in stream if line.strip()]
missing = [path for path in requested if path not in paths]
print(f"Sitemap loc count: {len(locations)}")
for location in locations:
    print(f"  loc: {location}")
print(f"Requested paths absent from sitemap ({len(missing)}):")
for path in missing:
    print(f"  - {path}")
PY
  else
    warn "Could not download ${BASE_URL}/sitemap.xml for coverage inspection."
  fi

  if curl --disable \
    --request GET \
    --proto '=http,https' \
    --proto-redir '=http,https' \
    --silent \
    --show-error \
    --location \
    --connect-timeout "${CURL_CONNECT_TIMEOUT}" \
    --max-time "${CURL_MAX_TIME}" \
    --user-agent 'UniSonges-read-only-content-audit/2026' \
    --output "${robots_file}" \
    -- "${BASE_URL}/robots.txt"; then
    local sitemap_directives
    sitemap_directives="$(grep -iE '^Sitemap:[[:space:]]*' "${robots_file}" || true)"
    printf 'robots.txt Sitemap directives: %s\n' "${sitemap_directives:-(none)}"
  else
    warn "Could not download ${BASE_URL}/robots.txt for Sitemap directive inspection."
  fi

  cat <<'EOF'

Interpretation limit: HTTP status, redirects, source-order headings, exact text,
and hrefs above were observed in downloaded HTML. CSS geometry, visibility,
overlap, responsive behavior, JavaScript state, authenticated state, and visual
layout were not observed and must not be inferred from this output alone.
EOF

  cleanup_public_temp
  trap - EXIT
}

readonly_sql_queries() {
  cat <<'SQL'
START TRANSACTION READ ONLY;
SELECT 'PUBLIC ALIASES / NODE TITLES' AS audit_section;
SELECT pa.alias, pa.path, nfd.nid, nfd.status, nfd.title
FROM path_alias pa
LEFT JOIN node_field_data nfd ON pa.path = CONCAT('/node/', nfd.nid)
WHERE pa.alias IN ('/accueil','/cours','/cours/didgeridoo','/cours/guimbarde','/cours/meditation-improvisation','/reserver','/stages','/stages/didgeridoo','/stages/musique-improvisee-meditation','/stages/speciaux','/concerts','/association','/les-artistes-de-l-asso','/services-prestations-artistiques','/djam','/orchestre-des-reveurs','/contact')
ORDER BY pa.alias, nfd.langcode;
SELECT 'PUBLIC PAGE BODY PREFIXES' AS audit_section;
SELECT pa.alias, nfd.nid, LEFT(REPLACE(REPLACE(COALESCE(body.body_value, ''), CHAR(10), ' '), CHAR(13), ' '), 500) AS body_prefix
FROM path_alias pa
JOIN node_field_data nfd ON pa.path = CONCAT('/node/', nfd.nid)
LEFT JOIN node__body body ON body.entity_id = nfd.nid AND body.deleted = 0 AND body.langcode = nfd.langcode
WHERE pa.alias IN ('/accueil','/cours','/cours/didgeridoo','/cours/guimbarde','/cours/meditation-improvisation','/reserver','/stages','/stages/didgeridoo','/stages/musique-improvisee-meditation','/stages/speciaux','/concerts','/association','/les-artistes-de-l-asso','/services-prestations-artistiques','/djam','/orchestre-des-reveurs','/contact')
ORDER BY pa.alias;
SELECT 'MAIN MENU LINKS' AS audit_section;
SELECT id, title, link__uri, enabled, weight, parent
FROM menu_link_content_data
WHERE menu_name = 'main'
ORDER BY weight, id;
SELECT 'ACTIVE BLOCK / VIEW CONFIG NAMES' AS audit_section;
SELECT name
FROM config
WHERE collection = '' AND (name LIKE 'block.block.%' OR name LIKE 'views.view.%' OR name LIKE 'system.menu.%')
ORDER BY name;
ROLLBACK;
SQL
}

run_ddev_checks() {
  command -v ddev >/dev/null 2>&1 || die "ddev is required for --ddev."
  [[ -d "${DDEV_PROJECT_DIR}" ]] || die "DDEV project directory does not exist: ${DDEV_PROJECT_DIR}"
  DDEV_PROJECT_DIR="$(cd "${DDEV_PROJECT_DIR}" && pwd -P)"
  case "${DDEV_PROJECT_DIR}" in
    /mnt/c|/mnt/c/*|/var/www|/var/www/*|/srv|/srv/*)
      die "Refusing a DDEV project under a mounted Windows or deployment path: ${DDEV_PROJECT_DIR}"
      ;;
  esac
  [[ -f "${DDEV_PROJECT_DIR}/.ddev/config.yaml" ]] || \
    die "No .ddev/config.yaml was found in ${DDEV_PROJECT_DIR}. Use --ddev-project-dir."

  section "Local DDEV active-state checks (read-only)"
  cat <<'EOF'
Commands in this section use ddev describe, Drush status, and SELECT statements
inside an explicitly READ ONLY transaction. No cache rebuild or config/content
write command is run.
EOF

  (
    cd "${DDEV_PROJECT_DIR}"
    ddev describe
    ddev exec --dir /var/www/html --raw -- ./vendor/bin/drush -r web status --fields=bootstrap,db-status,uri
    local readonly_sql
    readonly_sql="$(readonly_sql_queries)"
    ddev exec --dir /var/www/html --raw -- ./vendor/bin/drush -r web sql:query "${readonly_sql}"
  )
}

validate_vps_options() {
  [[ "${VPS_TARGET}" =~ ^[A-Za-z0-9_.-]+@[A-Za-z0-9_.-]+$ ]] || \
    die "Unsafe --vps-target value; expected USER@HOST using letters, digits, dot, underscore, or hyphen."
  [[ "${VPS_DRUPAL_DIR}" =~ ^/var/www/[A-Za-z0-9._/-]+$ ]] || \
    die "Unsafe --vps-drupal-dir; use an absolute /var/www path with no spaces or shell metacharacters."
  case "/${VPS_DRUPAL_DIR#/}/" in
    *'/../'*|*'/./'*|*'//'*)
      die "Unsafe --vps-drupal-dir; dot segments and repeated slashes are refused."
      ;;
  esac
}

run_vps_checks() {
  command -v ssh >/dev/null 2>&1 || die "ssh is required for --vps."
  validate_vps_options

  section "VPS active-state checks (read-only; explicitly allowed)"
  printf 'Target: %s\n' "${VPS_TARGET}"
  printf 'Drupal directory: %s\n' "${VPS_DRUPAL_DIR}"
  cat <<'EOF'
The remote command reads Git metadata and tracked source names, runs Drush status,
and sends SELECT statements in a READ ONLY transaction. It does not deploy, pull,
checkout, install, rebuild caches, import/export config, or mutate content.
EOF

  ssh \
    -o BatchMode=yes \
    -o ConnectTimeout=15 \
    -o StrictHostKeyChecking=yes \
    -- "${VPS_TARGET}" bash -s -- "${VPS_DRUPAL_DIR}" <<'REMOTE'
set -euo pipefail
requested_drupal_dir="$1"
if ! drupal_dir="$(cd "${requested_drupal_dir}" && pwd -P)"; then
  printf 'Remote Drupal path is unavailable: %s\n' "${requested_drupal_dir}" >&2
  exit 2
fi
repo_root="$(cd "${drupal_dir}/.." && pwd -P)"

case "${drupal_dir}" in
  /var/www/*) ;;
  *) printf 'Refusing unexpected remote Drupal path: %s\n' "${drupal_dir}" >&2; exit 2 ;;
esac

cd "${drupal_dir}"
printf '\n-- Remote Git/source baseline --\n'
git --no-optional-locks -C "${repo_root}" branch --show-current
git --no-optional-locks -C "${repo_root}" rev-parse HEAD
git --no-optional-locks -C "${repo_root}" status --short
find web/themes/custom/unisonges_theme/templates -type f -name '*.twig' -print | LC_ALL=C sort
find config/sync -maxdepth 1 -type f \( -name 'block.block.*.yml' -o -name 'views.view.*.yml' -o -name 'system.menu.*.yml' \) -print | LC_ALL=C sort

printf '\n-- Remote Drupal status --\n'
./vendor/bin/drush -r web status --fields=bootstrap,db-status,uri

printf '\n-- Remote active-state SELECT results --\n'
readonly_sql="$(cat <<'SQL'
START TRANSACTION READ ONLY;
SELECT 'PUBLIC ALIASES / NODE TITLES' AS audit_section;
SELECT pa.alias, pa.path, nfd.nid, nfd.status, nfd.title
FROM path_alias pa
LEFT JOIN node_field_data nfd ON pa.path = CONCAT('/node/', nfd.nid)
WHERE pa.alias IN ('/accueil','/cours','/cours/didgeridoo','/cours/guimbarde','/cours/meditation-improvisation','/reserver','/stages','/stages/didgeridoo','/stages/musique-improvisee-meditation','/stages/speciaux','/concerts','/association','/les-artistes-de-l-asso','/services-prestations-artistiques','/djam','/orchestre-des-reveurs','/contact')
ORDER BY pa.alias, nfd.langcode;
SELECT 'PUBLIC PAGE BODY PREFIXES' AS audit_section;
SELECT pa.alias, nfd.nid, LEFT(REPLACE(REPLACE(COALESCE(body.body_value, ''), CHAR(10), ' '), CHAR(13), ' '), 500) AS body_prefix
FROM path_alias pa
JOIN node_field_data nfd ON pa.path = CONCAT('/node/', nfd.nid)
LEFT JOIN node__body body ON body.entity_id = nfd.nid AND body.deleted = 0 AND body.langcode = nfd.langcode
WHERE pa.alias IN ('/accueil','/cours','/cours/didgeridoo','/cours/guimbarde','/cours/meditation-improvisation','/reserver','/stages','/stages/didgeridoo','/stages/musique-improvisee-meditation','/stages/speciaux','/concerts','/association','/les-artistes-de-l-asso','/services-prestations-artistiques','/djam','/orchestre-des-reveurs','/contact')
ORDER BY pa.alias;
SELECT 'MAIN MENU LINKS' AS audit_section;
SELECT id, title, link__uri, enabled, weight, parent
FROM menu_link_content_data
WHERE menu_name = 'main'
ORDER BY weight, id;
SELECT 'ACTIVE BLOCK / VIEW CONFIG NAMES' AS audit_section;
SELECT name
FROM config
WHERE collection = '' AND (name LIKE 'block.block.%' OR name LIKE 'views.view.%' OR name LIKE 'system.menu.%')
ORDER BY name;
ROLLBACK;
SQL
 )"
./vendor/bin/drush -r web sql:query "${readonly_sql}"
REMOTE
}

run_repository_checks

if ((PUBLIC_CHECKS)); then
  run_public_checks
fi

if ((DDEV_CHECKS)); then
  run_ddev_checks
fi

if ((VPS_CHECKS)); then
  run_vps_checks
fi

section "Completion"
log "Read-only diagnostics completed. Review interpretation boundaries before filing corrections."
