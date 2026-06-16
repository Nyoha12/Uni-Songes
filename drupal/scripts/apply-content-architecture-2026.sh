#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
DRUPAL_DIR="$(cd "${SCRIPT_DIR}/.." && pwd -P)"
DRUSH="${DRUSH:-./vendor/bin/drush}"

MODE="dry-run"
REQUESTED_MODE=""
ALLOW_VPS=0

log() {
  printf '[apply-content-architecture-2026] %s\n' "$*"
}

warn() {
  printf '[apply-content-architecture-2026] WARNING: %s\n' "$*" >&2
}

section() {
  printf '\n== %s ==\n' "$*"
}

usage() {
  cat <<'EOF'
Usage: ./scripts/apply-content-architecture-2026.sh [--dry-run|--apply] [--allow-vps]

Creates or updates the 2026 Uni-Songes page content architecture through
Drupal APIs. Dry-run is the default; --apply is required before any writes.

Options:
  --dry-run    Print the plan against active Drupal content. Default.
  --apply      Create/update the allowlisted page nodes, aliases, and menu links.
  --allow-vps  Permit execution from /var/www paths. Required on the VPS.
  -h, --help   Show this help.

This script never runs drush config:import, never edits config/sync, never
deletes content, and never creates Commerce products.
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
      ALLOW_VPS=1
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
  esac

  case "${DRUPAL_DIR}" in
    /var/www|/var/www/*)
      if [[ "${ALLOW_VPS}" -ne 1 ]]; then
        warn "Refusing to run from /var/www without --allow-vps: ${DRUPAL_DIR}"
        exit 1
      fi
      ;;
  esac
}

require_drupal_codebase() {
  if [[ ! -f "${DRUPAL_DIR}/composer.json" || ! -f "${DRUPAL_DIR}/web/core/lib/Drupal.php" ]]; then
    warn "Could not verify a Drupal codebase at ${DRUPAL_DIR}."
    exit 1
  fi
}

require_drush_bootstrap() {
  section "Drupal bootstrap"

  if [[ ! -x "${DRUSH}" ]]; then
    warn "Drush is missing or not executable at ${DRUPAL_DIR}/${DRUSH}."
    exit 1
  fi

  if ! "${DRUSH}" php:eval 'echo "Drupal bootstrap OK: " . \Drupal::VERSION . PHP_EOL;' ; then
    warn "Drush could not bootstrap Drupal. No content was changed."
    exit 1
  fi
}

print_plan() {
  section "Content plan"
  cat <<'EOF'
Pages:
- /cours
- /cours/didgeridoo
- /cours/guimbarde
- /cours/meditation-improvisation
- /stages
- /stages/didgeridoo
- /stages/musique-improvisee-meditation
- /stages/speciaux
- /les-artistes-de-l-asso
- /services-prestations-artistiques

Main menu links:
- Les Artistes de l’asso -> /les-artistes-de-l-asso
- Services et prestations artistiques -> /services-prestations-artistiques

Guards:
- dry-run by default; writes require --apply
- no drush config:import
- no config/sync edits
- no raw SQL
- no content deletion
- no Commerce product creation
EOF
}

write_php_script() {
  PHP_SCRIPT="$(mktemp /tmp/unisonges-content-architecture-2026.XXXXXX.php)"
  cat > "${PHP_SCRIPT}" <<'PHP'
<?php

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

$mode = getenv('UNISONGES_CONTENT_ARCH_MODE') ?: 'dry-run';
$is_apply = $mode === 'apply';
$failed = FALSE;
$body_format = 'full_html';

$pages = [
  'cours' => [
    'title' => 'Cours',
    'alias' => '/cours',
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Les cours particuliers Uni-Songes accompagnent une pratique instrumentale ou sonore avec un cadre individuel, adaptable à votre point de départ et à votre objectif.</p>
  <p>Trois parcours sont ouverts : didgeridoo, guimbarde et méditation / improvisation. Le cours d'essai est à 10 EUR ; les cours sont à 25 EUR / heure, ou 15 EUR / heure au tarif étudiant.</p>
</section>

<div class="unisonges-card-grid">
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Cours de didgeridoo</h2>
    <p class="unisonges-offer-card__text">Souffle continu, vibration, rythmes, voix et construction d'un jeu personnel en séance individuelle.</p>
    <p class="unisonges-offer-card__meta">Essai 10 EUR. Puis 25 EUR / heure, 15 EUR / heure étudiant.</p>
    <p class="unisonges-offer-card__cta"><a href="/cours/didgeridoo">Voir les tarifs et acheter</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Cours de guimbarde</h2>
    <p class="unisonges-offer-card__text">Placement, attaques, respiration, rythmes et couleurs de bouche pour développer un jeu vivant.</p>
    <p class="unisonges-offer-card__meta">25 EUR / heure. Tarif étudiant : 15 EUR / heure.</p>
    <p class="unisonges-offer-card__cta"><a href="/cours/guimbarde">Réserver un cours</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Méditation / improvisation</h2>
    <p class="unisonges-offer-card__text">Écoute, présence, improvisation musicale et exploration sonore dans un accompagnement individuel.</p>
    <p class="unisonges-offer-card__meta">25 EUR / heure. Tarif étudiant : 15 EUR / heure.</p>
    <p class="unisonges-offer-card__cta"><a href="/cours/meditation-improvisation">Construire le format</a></p>
  </article>
</div>
HTML,
  ],
  'cours_didgeridoo' => [
    'title' => 'Cours de didgeridoo',
    'alias' => '/cours/didgeridoo',
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Le cours particulier de didgeridoo part de votre pratique du moment et avance vers un objectif clair : découvrir l'instrument, stabiliser une technique ou développer un jeu plus personnel.</p>
  <p>Les séances peuvent travailler le souffle continu, la vibration, les harmoniques, les rythmes, la voix, la composition ou la préparation d'un projet.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Tarifs confirmés</h2>
  <p class="unisonges-price-note">Cours d'essai : 10 EUR. Cours particulier : 25 EUR / heure. Tarif étudiant : 15 EUR / heure.</p>
  <p>Le tarif étudiant est confirmé au moment de la réservation.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Réservation et achat</h2>
  <p><a href="/product/4">Acheter un cours d'essai - 10 EUR</a></p>
  <p><a href="/product/5">Acheter un cours didgeridoo 1h - plein tarif - 25 EUR</a></p>
  <p><a href="/product/6">Acheter un cours didgeridoo 1h - tarif étudiant - 15 EUR</a></p>
  <p><a href="/contact">Contacter l'association pour choisir un créneau ou confirmer le tarif étudiant</a></p>
</section>
HTML,
  ],
  'cours_guimbarde' => [
    'title' => 'Cours de guimbarde',
    'alias' => '/cours/guimbarde',
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Le cours particulier de guimbarde accompagne la découverte ou l'approfondissement de l'instrument dans un cadre simple, précis et adapté à votre pratique.</p>
  <p>On peut y travailler la tenue, les attaques, la respiration, les rythmes, les mélodies de bouche et l'improvisation.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Tarifs confirmés</h2>
  <p class="unisonges-price-note">Cours particulier : 25 EUR / heure. Tarif étudiant : 15 EUR / heure.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Réservation</h2>
  <p>La réservation se fait pour l'instant par échange direct afin de choisir le créneau et le contenu du cours.</p>
  <p><a href="/contact">Contacter l'association pour réserver un cours de guimbarde</a></p>
</section>
HTML,
  ],
  'cours_meditation_improvisation' => [
    'title' => 'Méditation / improvisation',
    'alias' => '/cours/meditation-improvisation',
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Ce cours individuel accompagne l'écoute, la présence et l'improvisation musicale à partir de votre pratique, avec ou sans instrument.</p>
  <p>Le format peut soutenir une pratique personnelle, une méditation sonore, la confiance dans l'improvisation ou la préparation d'un projet artistique.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Tarifs confirmés</h2>
  <p class="unisonges-price-note">Cours particulier : 25 EUR / heure. Tarif étudiant : 15 EUR / heure.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Réservation</h2>
  <p>La réservation se construit par échange direct pour ajuster le cadre, la durée et l'intention de la séance.</p>
  <p><a href="/contact">Contacter l'association pour construire le format</a></p>
</section>
HTML,
  ],
  'stages' => [
    'title' => 'Stages',
    'alias' => '/stages',
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Les stages Uni-Songes sont des temps collectifs pour pratiquer le didgeridoo, l'improvisation musicale, la méditation sonore ou des formats ponctuels.</p>
  <p>Les familles ci-dessous orientent vers les contenus utiles. Les réservations et billets restent attachés aux dates publiées comme pages Stage.</p>
</section>

<div class="unisonges-card-grid">
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Stages didgeridoo</h2>
    <p class="unisonges-offer-card__text">Deux rendez-vous collectifs réguliers : débutant et intermédiaire, selon le calendrier publié.</p>
    <p class="unisonges-offer-card__meta">20 EUR par stage.</p>
    <p class="unisonges-offer-card__cta"><a href="/stages/didgeridoo">Voir les stages didgeridoo</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Musique improvisée / méditation</h2>
    <p class="unisonges-offer-card__text">Pratique collective de l'écoute, de la présence, de la respiration et de l'improvisation sonore.</p>
    <p class="unisonges-offer-card__meta">20 EUR par stage.</p>
    <p class="unisonges-offer-card__cta"><a href="/stages/musique-improvisee-meditation">Voir musique improvisée / méditation</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Stages spéciaux</h2>
    <p class="unisonges-offer-card__text">Gong, guimbarde, éveil musical et autres propositions ponctuelles publiées au fil de la saison.</p>
    <p class="unisonges-offer-card__meta">Tarifs et billets indiqués sur chaque date publiée.</p>
    <p class="unisonges-offer-card__cta"><a href="/stages/speciaux">Voir les stages spéciaux</a></p>
  </article>
</div>

<section class="unisonges-detail-section">
  <h2>Stages à venir</h2>
  <p>La zone de publication automatique des stages reste active sur cette page. Pour réserver, ouvrez une date publiée puis utilisez la billetterie associée.</p>
</section>
HTML,
  ],
  'stages_didgeridoo' => [
    'title' => 'Stages didgeridoo',
    'alias' => '/stages/didgeridoo',
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Les stages didgeridoo proposent deux rendez-vous collectifs mensuels : un stage débutant et un stage intermédiaire.</p>
  <p>Chaque date est publiée comme page Stage avec sa billetterie, son horaire et les informations pratiques.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Repères de pratique</h2>
  <p>Débutant mensuel : découverte de l'instrument, vibration de base, respiration et premiers rythmes.</p>
  <p>Intermédiaire mensuel : stabilité du souffle, variations rythmiques, voix, accents et construction de phrases.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Tarif et prochaines dates</h2>
  <p class="unisonges-price-note">20 EUR par stage.</p>
  <p><a href="/stages">Voir les prochains stages publiés et choisir une date</a></p>
  <p><a href="/contact">Proposer une date ou demander une information</a></p>
</section>
HTML,
  ],
  'stages_musique_improvisee_meditation' => [
    'title' => 'Musique improvisée / méditation',
    'alias' => '/stages/musique-improvisee-meditation',
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Ces stages proposent une pratique collective de l'écoute, de la méditation sonore et de l'improvisation musicale.</p>
  <p>Le cadre peut associer instruments acoustiques, voix, silence, respiration et textures sonores, avec une attention portée au groupe.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Tarif</h2>
  <p class="unisonges-price-note">20 EUR par stage.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Réservation</h2>
  <p>La réservation se fait depuis la page Stage publiée pour la date choisie, avec la billetterie associée.</p>
  <p><a href="/stages">Voir les dates publiées</a></p>
  <p><a href="/contact">Contacter l'association pour une réservation ou une question</a></p>
</section>
HTML,
  ],
  'stages_speciaux' => [
    'title' => 'Stages spéciaux',
    'alias' => '/stages/speciaux',
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Les stages spéciaux regroupent les propositions ponctuelles : gong, guimbarde, éveil musical et autres formats invités.</p>
  <p>Chaque proposition précise son format, son tarif et sa billetterie sur la page Stage publiée.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Réservation</h2>
  <p>Ces propositions passent par le système existant de contenus Stage et de billets. Cette page sert d'orientation, sans créer de produit générique.</p>
  <p><a href="/stages">Voir les prochains stages publiés</a></p>
</section>
HTML,
  ],
  'artistes' => [
    'title' => 'Les Artistes de l’asso',
    'alias' => '/les-artistes-de-l-asso',
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Cette page présente les artistes partenaires de l'association Uni-Songes, leurs univers et les formes de collaboration possibles.</p>
  <p>Elle sert de point d'entrée pour découvrir les parcours, les propositions artistiques et les services portés par les membres ou partenaires de l'association.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Biographies d'artistes</h2>
  <p>Section à compléter avec les biographies, photos, instruments, démarches artistiques et liens de chaque artiste partenaire.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Services proposés</h2>
  <p>Section à compléter avec les prestations, ateliers, concerts, accompagnements et formats pédagogiques portés par chaque artiste.</p>
  <p><a href="/services-prestations-artistiques">Voir les services et prestations artistiques</a></p>
</section>
HTML,
  ],
  'services' => [
    'title' => 'Services et prestations artistiques',
    'alias' => '/services-prestations-artistiques',
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Uni-Songes peut proposer des services artistiques, pédagogiques et sonores pour des structures culturelles, scolaires, associatives, thérapeutiques ou événementielles.</p>
  <p>Les formats sont adaptés au public, au lieu, à la durée et au niveau d'accompagnement souhaité.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Prestations possibles</h2>
  <ul>
    <li>Musique électroacoustique solo.</li>
    <li>Musique méditative, bain sonore et voyage sonore.</li>
    <li>Accompagnement sonore pour yoga, pratiques physiques, actions pédagogiques ou contextes thérapeutiques.</li>
    <li>Performance instrumentale pour enregistrement studio.</li>
    <li>Ateliers pédagogiques autour du son, du rythme, de l'écoute et des instruments.</li>
    <li>Interventions en écoles et éveil musical.</li>
    <li>Concerts pédagogiques.</li>
  </ul>
</section>

<section class="unisonges-detail-section">
  <h2>Demander une prestation</h2>
  <p>Pour construire une proposition, indiquez le public, le lieu, la date ou période envisagée, la durée et les contraintes techniques.</p>
  <p><a href="/contact">Contacter l'association</a></p>
</section>
HTML,
  ],
];

$menu_links = [
  [
    'title' => 'Les Artistes de l’asso',
    'page_key' => 'artistes',
  ],
  [
    'title' => 'Services et prestations artistiques',
    'page_key' => 'services',
  ],
];

section('Runtime guards');
check($mode === 'dry-run' || $mode === 'apply', 'mode is dry-run or apply');
check(\Drupal::entityTypeManager()->hasDefinition('node'), 'node entity type is available');
check(\Drupal::entityTypeManager()->hasDefinition('path_alias'), 'path_alias entity type is available');
check(\Drupal::entityTypeManager()->hasDefinition('menu_link_content'), 'menu_link_content entity type is available');
check((bool) \Drupal\node\Entity\NodeType::load('page'), 'page content type exists');

$format = \Drupal::entityTypeManager()->getStorage('filter_format')->load($body_format);
check((bool) $format && (bool) $format->status(), 'full_html text format exists and is enabled');

if ($failed) {
  echo PHP_EOL . 'Blocked before content inspection. No content was changed.' . PHP_EOL;
  exit(1);
}

section('Content preflight');
foreach ($pages as $page) {
  try {
    resolve_page_node($page['title'], $page['alias']);
    echo 'OK inspected page target ' . $page['alias'] . PHP_EOL;
  }
  catch (Throwable $throwable) {
    check(FALSE, $page['alias'] . ': ' . $throwable->getMessage());
  }
}

if ($failed) {
  echo PHP_EOL . 'Blocked before writes. No content was changed.' . PHP_EOL;
  exit(1);
}

$resolved_nodes = [];

section($is_apply ? 'Page apply' : 'Page dry-run');
foreach ($pages as $key => $page) {
  try {
    $node = resolve_page_node($page['title'], $page['alias']);
    if (!$node && $is_apply) {
      $node = Node::create([
        'type' => 'page',
        'title' => $page['title'],
        'langcode' => \Drupal::languageManager()->getDefaultLanguage()->getId(),
        'status' => NodeInterface::PUBLISHED,
        'body' => [
          'value' => $page['body'],
          'format' => $body_format,
        ],
      ]);
      $node->save();
      echo 'CREATED page ' . $page['alias'] . ' as node ' . $node->id() . PHP_EOL;
    }
    elseif (!$node) {
      echo 'WOULD_CREATE page ' . $page['alias'] . ' title "' . $page['title'] . '"' . PHP_EOL;
    }

    if ($node) {
      $changes = page_changes($node, $page['title'], $page['body'], $body_format);
      if ($changes) {
        if ($is_apply) {
          update_page_node($node, $page['title'], $page['body'], $body_format, $changes);
          echo 'UPDATED page ' . $page['alias'] . ' node ' . $node->id() . ': ' . implode(', ', $changes) . PHP_EOL;
        }
        else {
          echo 'WOULD_UPDATE page ' . $page['alias'] . ' node ' . $node->id() . ': ' . implode(', ', $changes) . PHP_EOL;
        }
      }
      else {
        echo 'OK page ' . $page['alias'] . ' node ' . $node->id() . ' already matches' . PHP_EOL;
      }
    }

    $target_node = $node ?: resolve_page_node($page['title'], $page['alias']);
    if ($target_node) {
      ensure_alias($target_node, $page['alias'], $is_apply);
      $resolved_nodes[$key] = $target_node;
    }
    elseif (!$is_apply) {
      echo 'WOULD_CREATE alias ' . $page['alias'] . ' after node creation' . PHP_EOL;
    }
  }
  catch (Throwable $throwable) {
    check(FALSE, $page['alias'] . ': ' . $throwable->getMessage());
  }
}

if ($failed) {
  echo PHP_EOL . 'Blocked while preparing pages. No menu links were changed.' . PHP_EOL;
  exit(1);
}

section($is_apply ? 'Menu apply' : 'Menu dry-run');
foreach ($menu_links as $menu_link) {
  try {
    $page = $pages[$menu_link['page_key']];
    $node = $resolved_nodes[$menu_link['page_key']] ?? resolve_page_node($page['title'], $page['alias']);
    if (!$node) {
      if ($is_apply) {
        throw new RuntimeException('Expected page node was not available for menu link.');
      }
      echo 'WOULD_CREATE main menu link "' . $menu_link['title'] . '" after page creation' . PHP_EOL;
      continue;
    }

    ensure_menu_link($menu_link['title'], $node, $page['alias'], $is_apply);
  }
  catch (Throwable $throwable) {
    check(FALSE, $menu_link['title'] . ': ' . $throwable->getMessage());
  }
}

if ($failed) {
  echo PHP_EOL . 'Content architecture failed.' . PHP_EOL;
  exit(1);
}

section('Result');
if ($is_apply) {
  echo 'Apply completed. Only the allowlisted page nodes, aliases, and main-menu links were created or updated.' . PHP_EOL;
}
else {
  echo 'Dry-run completed. No content, menu links, aliases, config, or Commerce data was changed.' . PHP_EOL;
}

function section(string $title): void {
  echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
}

function check(bool $ok, string $message): void {
  global $failed;
  echo ($ok ? 'OK ' : 'FAIL ') . $message . PHP_EOL;
  $failed = $failed || !$ok;
}

function resolve_page_node(string $title, string $alias): ?NodeInterface {
  $alias_node = page_node_by_alias($alias);
  if ($alias_node) {
    return $alias_node;
  }

  $node_ids = \Drupal::entityQuery('node')
    ->accessCheck(FALSE)
    ->condition('type', 'page')
    ->condition('title', $title)
    ->range(0, 2)
    ->execute();

  if (count($node_ids) > 1) {
    throw new RuntimeException('Multiple page nodes share title "' . $title . '" and alias ' . $alias . ' is not assigned.');
  }

  if (!$node_ids) {
    return NULL;
  }

  $node = Node::load((int) reset($node_ids));
  return $node instanceof NodeInterface ? $node : NULL;
}

function page_node_by_alias(string $alias): ?NodeInterface {
  $alias_entity = alias_entity($alias);
  if (!$alias_entity) {
    return NULL;
  }

  $path = (string) $alias_entity->getPath();
  if (!preg_match('/^\/node\/(\d+)$/', $path, $matches)) {
    throw new RuntimeException('Alias ' . $alias . ' already points to non-node path ' . $path . '.');
  }

  $node = Node::load((int) $matches[1]);
  if (!$node instanceof NodeInterface) {
    throw new RuntimeException('Alias ' . $alias . ' points to missing node ' . $matches[1] . '.');
  }
  if ($node->bundle() !== 'page') {
    throw new RuntimeException('Alias ' . $alias . ' points to a ' . $node->bundle() . ' node, not a page node.');
  }

  return $node;
}

function alias_entity(string $alias) {
  $aliases = \Drupal::entityTypeManager()
    ->getStorage('path_alias')
    ->loadByProperties(['alias' => $alias]);

  return $aliases ? reset($aliases) : NULL;
}

function page_changes(NodeInterface $node, string $title, string $body, string $format): array {
  $changes = [];
  if ($node->label() !== $title) {
    $changes[] = 'title';
  }
  if (!$node->isPublished()) {
    $changes[] = 'status';
  }

  $current_body = $node->hasField('body') && !$node->get('body')->isEmpty()
    ? (string) $node->get('body')->value
    : '';
  $current_format = $node->hasField('body') && !$node->get('body')->isEmpty()
    ? (string) $node->get('body')->format
    : '';
  if ($current_body !== $body || $current_format !== $format) {
    $changes[] = 'body';
  }

  return $changes;
}

function update_page_node(NodeInterface $node, string $title, string $body, string $format, array $changes): void {
  $node->setTitle($title);
  $node->setPublished(TRUE);
  $node->set('body', [
    'value' => $body,
    'format' => $format,
  ]);

  if (method_exists($node, 'setNewRevision')) {
    $node->setNewRevision(TRUE);
  }
  if (method_exists($node, 'setRevisionLogMessage')) {
    $node->setRevisionLogMessage('Content architecture 2026 update: ' . implode(', ', $changes) . '.');
  }
  if (method_exists($node, 'setRevisionCreationTime')) {
    $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
  }

  $node->save();
}

function ensure_alias(NodeInterface $node, string $alias, bool $is_apply): void {
  $path = '/node/' . $node->id();
  $alias_entity = alias_entity($alias);

  if ($alias_entity) {
    if ((string) $alias_entity->getPath() === $path) {
      echo 'OK alias ' . $alias . ' -> ' . $path . PHP_EOL;
      return;
    }

    throw new RuntimeException('Alias ' . $alias . ' changed unexpectedly during this run.');
  }

  if ($is_apply) {
    \Drupal::entityTypeManager()->getStorage('path_alias')->create([
      'path' => $path,
      'alias' => $alias,
      'langcode' => \Drupal::languageManager()->getDefaultLanguage()->getId(),
    ])->save();
    echo 'CREATED alias ' . $alias . ' -> ' . $path . PHP_EOL;
  }
  else {
    echo 'WOULD_CREATE alias ' . $alias . ' -> ' . $path . PHP_EOL;
  }
}

function ensure_menu_link(string $title, NodeInterface $node, string $alias, bool $is_apply): void {
  $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
  $expected_uri = 'entity:node/' . $node->id();
  $alias_uri = 'internal:' . $alias;

  $link = NULL;
  $links = $storage->loadByProperties(['menu_name' => 'main']);
  foreach ($links as $candidate) {
    if ($candidate->label() === $title) {
      $link = $candidate;
      break;
    }
  }
  if (!$link) {
    foreach ($links as $candidate) {
      $uri = menu_link_uri($candidate);
      if ($uri === $expected_uri || $uri === $alias_uri) {
        $link = $candidate;
        break;
      }
    }
  }

  if (!$link) {
    if ($is_apply) {
      $link = MenuLinkContent::create([
        'title' => $title,
        'menu_name' => 'main',
        'link' => ['uri' => $expected_uri],
        'enabled' => TRUE,
        'expanded' => FALSE,
      ]);
      $link->save();
      echo 'CREATED main menu link "' . $title . '" -> ' . $alias . PHP_EOL;
    }
    else {
      echo 'WOULD_CREATE main menu link "' . $title . '" -> ' . $alias . PHP_EOL;
    }
    return;
  }

  $changes = [];
  if ($link->label() !== $title) {
    $changes[] = 'title';
  }
  if (menu_link_uri($link) !== $expected_uri) {
    $changes[] = 'link';
  }
  if (!(bool) $link->get('enabled')->value) {
    $changes[] = 'enabled';
  }

  if (!$changes) {
    echo 'OK main menu link "' . $title . '" already matches' . PHP_EOL;
    return;
  }

  if ($is_apply) {
    $link->set('title', $title);
    $link->set('link', ['uri' => $expected_uri]);
    $link->set('enabled', TRUE);
    $link->save();
    echo 'UPDATED main menu link "' . $title . '": ' . implode(', ', $changes) . PHP_EOL;
  }
  else {
    echo 'WOULD_UPDATE main menu link "' . $title . '": ' . implode(', ', $changes) . PHP_EOL;
  }
}

function menu_link_uri(MenuLinkContent $link): string {
  $item = $link->get('link')->first();
  if (!$item) {
    return '';
  }
  $value = $item->getValue();
  return (string) ($value['uri'] ?? '');
}
PHP
}

cleanup_php_script() {
  if [[ -n "${PHP_SCRIPT:-}" && -f "${PHP_SCRIPT}" ]]; then
    rm -f "${PHP_SCRIPT}"
  fi
}

run_content_step() {
  write_php_script
  trap cleanup_php_script EXIT
  env UNISONGES_CONTENT_ARCH_MODE="${MODE}" "${DRUSH}" php:script "${PHP_SCRIPT}"
}

cd "${DRUPAL_DIR}"

log "Mode: ${MODE}"
require_safe_path
require_drupal_codebase
require_drush_bootstrap
print_plan
run_content_step

if [[ "${MODE}" == "dry-run" ]]; then
  section "Dry-run result"
  log "Dry-run completed. No content was changed."
else
  section "Apply result"
  log "Content architecture apply completed."
fi
