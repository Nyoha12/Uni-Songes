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
  --apply      Reconcile allowlisted pages, aliases, and menu-link state.
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
- /accueil
- /cours-et-stages
- /cours
- /cours/didgeridoo
- /cours/guimbarde
- /cours/meditation-improvisation
- /stages
- /stages/didgeridoo
- /stages/musique-improvisee-meditation
- /stages/speciaux
- /ateliers
- /a-propos
- /association
- /les-artistes-de-l-asso
- /origine
- /services-prestations-artistiques

Existing reference pages verified and preserved without body changes:
- /concerts
- /djam
- /orchestre-des-reveurs
- /contact

Main menu links (weight, label -> destination):
Top level:
- 0 Cours & Stages -> /cours-et-stages
- 10 Concerts & Événements -> /concerts
- 20 Ateliers -> /ateliers
- 30 À propos -> /a-propos
- 40 Contact -> /contact
Children of Cours & Stages:
- 0 Cours particuliers -> /cours
- 10 Stages -> /stages
Children of Ateliers:
- 0 D’Jam -> /djam
- 10 Orchestre -> /orchestre-des-reveurs
Children of À propos:
- 0 L’Asso -> /association
- 10 Partenaires -> /les-artistes-de-l-asso
- 20 Origine -> /origine
Disabled in place, retained and not deleted:
- /services-prestations-artistiques

Guards:
- dry-run by default; writes require --apply
- all managed existing pages are resolved strictly by alias
- only /cours-et-stages, /ateliers, /a-propos and /origine may be created
- dry-run prints exact current and planned bodies when a body would change
- menu links are matched by normalized destination
- child parents use UUID-backed Drupal menu-link plugin identifiers
- only the four new menu destinations may be created
- the existing services menu link is disabled in place, never deleted
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
  'accueil' => [
    'title' => 'Accueil',
    'alias' => '/accueil',
    'create_if_missing' => FALSE,
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Uni-Songes rassemble des propositions pour apprendre, pratiquer, écouter et partager la musique. Retrouvez ici les cours, les stages, les concerts, les artistes et les activités de l'association.</p>
  <p class="unisonges-offer-card__cta"><a href="/reservation-cours">Réserver un cours</a></p>
</section>

<div class="unisonges-card-grid">
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Cours</h2>
    <p class="unisonges-offer-card__text">Choisir un accompagnement individuel autour du didgeridoo, de la guimbarde, de l'écoute ou de l'improvisation.</p>
    <p class="unisonges-offer-card__cta"><a href="/cours">Découvrir les cours</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Stages</h2>
    <p class="unisonges-offer-card__text">Pratiquer en groupe lors des rendez-vous et formats collectifs publiés.</p>
    <p class="unisonges-offer-card__cta"><a href="/stages">Découvrir les stages</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Concerts</h2>
    <p class="unisonges-offer-card__text">Consulter les rendez-vous musicaux proposés par Uni-Songes.</p>
    <p class="unisonges-offer-card__cta"><a href="/concerts">Voir les concerts</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Artistes</h2>
    <p class="unisonges-offer-card__text">Découvrir les artistes liés à l'association et leurs univers.</p>
    <p class="unisonges-offer-card__cta"><a href="/les-artistes-de-l-asso">Voir les artistes</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Prestations</h2>
    <p class="unisonges-offer-card__text">Explorer les interventions artistiques, pédagogiques et sonores proposées.</p>
    <p class="unisonges-offer-card__cta"><a href="/services-prestations-artistiques">Voir les prestations</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Association</h2>
    <p class="unisonges-offer-card__text">Comprendre la mission et les activités portées collectivement par Uni-Songes.</p>
    <p class="unisonges-offer-card__cta"><a href="/association">Découvrir l'association</a></p>
  </article>
</div>
HTML,
  ],
  'cours_et_stages' => [
    'title' => 'Cours & Stages',
    'alias' => '/cours-et-stages',
    'create_if_missing' => TRUE,
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Entre accompagnement individuel et pratique collective, Uni-Songes propose des cours particuliers et des stages autour du didgeridoo, de la guimbarde, de l'écoute et de l'improvisation musicale.</p>
  <p class="unisonges-offer-card__cta"><a href="/reservation-cours">Réserver un cours</a></p>
</section>

<div class="unisonges-card-grid">
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Cours particuliers</h2>
    <p class="unisonges-offer-card__text">Choisir une discipline et avancer dans un cadre individuel adapté à sa pratique.</p>
    <p class="unisonges-offer-card__meta">Cours d'essai didgeridoo : 10 EUR. Cours particulier : 25 EUR / heure, 15 EUR / heure étudiant.</p>
    <p class="unisonges-offer-card__cta"><a href="/cours">Découvrir les cours</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Stages</h2>
    <p class="unisonges-offer-card__text">Pratiquer en groupe lors de stages de didgeridoo, de musique improvisée, de méditation sonore ou de formats ponctuels.</p>
    <p class="unisonges-offer-card__meta">20 EUR pour les stages réguliers concernés ; chaque date publiée précise le tarif applicable.</p>
    <p class="unisonges-offer-card__cta"><a href="/stages">Découvrir les stages</a></p>
  </article>
</div>
HTML,
  ],
  'cours' => [
    'title' => 'Cours',
    'alias' => '/cours',
    'create_if_missing' => FALSE,
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Les cours particuliers Uni-Songes accompagnent une pratique instrumentale ou sonore avec un cadre individuel, adaptable à votre point de départ et à votre objectif.</p>
  <p>Choisissez une discipline, un créneau, puis votre mode de paiement. Vous recevez ensuite une confirmation.</p>
  <p class="unisonges-offer-card__cta"><a href="/reservation-cours">Réserver un cours</a></p>
</section>

<div class="unisonges-card-grid">
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Cours de didgeridoo</h2>
    <p class="unisonges-offer-card__text">Souffle continu, vibration, rythmes, voix et construction d'un jeu personnel en séance individuelle.</p>
    <p class="unisonges-offer-card__meta">Essai 10 EUR. Puis 25 EUR / heure, 15 EUR / heure étudiant.</p>
    <p class="unisonges-offer-card__cta"><a href="/cours/didgeridoo">Voir le cours de didgeridoo</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Cours de guimbarde</h2>
    <p class="unisonges-offer-card__text">Placement, attaques, respiration, rythmes et couleurs de bouche pour développer un jeu vivant.</p>
    <p class="unisonges-offer-card__meta">25 EUR / heure. Tarif étudiant : 15 EUR / heure.</p>
    <p class="unisonges-offer-card__cta"><a href="/cours/guimbarde">Voir le cours de guimbarde</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Méditation / improvisation</h2>
    <p class="unisonges-offer-card__text">Écoute, présence, improvisation musicale et exploration sonore dans un accompagnement individuel.</p>
    <p class="unisonges-offer-card__meta">25 EUR / heure. Tarif étudiant : 15 EUR / heure.</p>
    <p class="unisonges-offer-card__cta"><a href="/cours/meditation-improvisation">Voir le cours de méditation / improvisation</a></p>
  </article>
</div>
HTML,
  ],
  'cours_didgeridoo' => [
    'title' => 'Cours de didgeridoo',
    'alias' => '/cours/didgeridoo',
    'create_if_missing' => FALSE,
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
  <h2>Réserver</h2>
  <p>Choisissez un créneau, puis votre mode de paiement. Vous recevez ensuite une confirmation.</p>
  <p class="unisonges-offer-card__cta"><a href="/reservation-cours?discipline=didgeridoo">Réserver un cours de didgeridoo</a></p>
  <p><a href="/reservation-cours?discipline=essai">Réserver un cours d'essai</a></p>
</section>
HTML,
  ],
  'cours_guimbarde' => [
    'title' => 'Cours de guimbarde',
    'alias' => '/cours/guimbarde',
    'create_if_missing' => FALSE,
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
  <h2>Réserver</h2>
  <p>Choisissez un créneau, puis votre mode de paiement. Vous recevez ensuite une confirmation.</p>
  <p class="unisonges-offer-card__cta"><a href="/reservation-cours?discipline=guimbarde">Réserver un cours de guimbarde</a></p>
</section>
HTML,
  ],
  'cours_meditation_improvisation' => [
    'title' => 'Méditation / improvisation',
    'alias' => '/cours/meditation-improvisation',
    'create_if_missing' => FALSE,
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
  <h2>Réserver</h2>
  <p>Choisissez un créneau, puis votre mode de paiement. Vous recevez ensuite une confirmation.</p>
  <p class="unisonges-offer-card__cta"><a href="/reservation-cours?discipline=meditation-improvisation">Réserver un cours de méditation / improvisation</a></p>
</section>
HTML,
  ],
  'stages' => [
    'title' => 'Stages',
    'alias' => '/stages',
    'create_if_missing' => FALSE,
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
    'create_if_missing' => FALSE,
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
    'create_if_missing' => FALSE,
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
    'create_if_missing' => FALSE,
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
  'ateliers' => [
    'title' => 'Ateliers',
    'alias' => '/ateliers',
    'create_if_missing' => TRUE,
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Les ateliers Uni-Songes ouvrent des espaces de pratique musicale collective, d'écoute, d'improvisation et de partage.</p>
</section>

<div class="unisonges-card-grid">
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">D’Jam</h2>
    <p class="unisonges-offer-card__text">Une session conviviale avec le didgeridoo à l'honneur et une place ouverte aux autres instruments.</p>
    <p class="unisonges-offer-card__cta"><a href="/djam">Découvrir D’Jam</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Orchestre</h2>
    <p class="unisonges-offer-card__text">Un espace de création collective autour du didgeridoo, de l'écoute et de l'improvisation musicale.</p>
    <p class="unisonges-offer-card__cta"><a href="/orchestre-des-reveurs">Découvrir l'Orchestre des Rêveurs</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Services et prestations artistiques</h2>
    <p class="unisonges-offer-card__text">Découvrir les interventions artistiques, pédagogiques et sonores proposées dans différents contextes.</p>
    <p class="unisonges-offer-card__cta"><a href="/services-prestations-artistiques">Voir les services et prestations</a></p>
  </article>
</div>
HTML,
  ],
  'a_propos' => [
    'title' => 'À propos',
    'alias' => '/a-propos',
    'create_if_missing' => TRUE,
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Cette page oriente vers l'association Uni-Songes, les artistes et partenaires qu'elle présente, les origines de sa démarche et ses activités artistiques et pédagogiques.</p>
</section>

<div class="unisonges-card-grid">
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">L’Association</h2>
    <p class="unisonges-offer-card__text">Comprendre sa mission autour de la pratique, de la transmission et de la création musicales.</p>
    <p class="unisonges-offer-card__cta"><a href="/association">Découvrir l'association</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Artistes et partenaires</h2>
    <p class="unisonges-offer-card__text">Découvrir les artistes partenaires présentés par Uni-Songes et leurs univers.</p>
    <p class="unisonges-offer-card__cta"><a href="/les-artistes-de-l-asso">Voir les artistes et partenaires</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Origine</h2>
    <p class="unisonges-offer-card__text">Explorer les racines artistiques et pédagogiques de la démarche Uni-Songes.</p>
    <p class="unisonges-offer-card__cta"><a href="/origine">Découvrir l'origine</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Services et activités artistiques</h2>
    <p class="unisonges-offer-card__text">Explorer les interventions artistiques, pédagogiques et sonores.</p>
    <p class="unisonges-offer-card__cta"><a href="/services-prestations-artistiques">Voir les services et prestations</a></p>
  </article>
</div>
HTML,
  ],
  'association' => [
    'title' => 'L’Association',
    'alias' => '/association',
    'create_if_missing' => FALSE,
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>L'association Uni-Songes a pour mission de favoriser la pratique, la transmission et la création musicales, ainsi que les rencontres qui les rendent collectives.</p>
  <p>Ses activités musicales, pédagogiques et collectives se découvrent à travers les cours, les stages, les concerts, les artistes et les prestations.</p>
</section>

<div class="unisonges-card-grid">
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Cours</h2>
    <p class="unisonges-offer-card__text">Découvrir les pratiques accompagnées en cours individuel.</p>
    <p class="unisonges-offer-card__cta"><a href="/cours">Voir les cours</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Stages</h2>
    <p class="unisonges-offer-card__text">Retrouver les temps de pratique collective et les formats publiés.</p>
    <p class="unisonges-offer-card__cta"><a href="/stages">Voir les stages</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Concerts</h2>
    <p class="unisonges-offer-card__text">Consulter les concerts et les dates publiées.</p>
    <p class="unisonges-offer-card__cta"><a href="/concerts">Voir les concerts</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Artistes de l'association</h2>
    <p class="unisonges-offer-card__text">Découvrir les artistes présentés par Uni-Songes et leurs univers.</p>
    <p class="unisonges-offer-card__cta"><a href="/les-artistes-de-l-asso">Voir les artistes</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Prestations artistiques</h2>
    <p class="unisonges-offer-card__text">Explorer les interventions artistiques, pédagogiques et sonores.</p>
    <p class="unisonges-offer-card__cta"><a href="/services-prestations-artistiques">Voir les prestations</a></p>
  </article>
</div>

<section class="unisonges-detail-section">
  <h2>Projets de l'association</h2>
  <p>D’Jam et l'Orchestre des Rêveurs sont des projets de l'association. Leurs pages dédiées présentent ces projets séparément.</p>
  <p><a href="/djam">Découvrir D’Jam</a></p>
  <p><a href="/orchestre-des-reveurs">Découvrir l'Orchestre des Rêveurs</a></p>
</section>
HTML,
  ],
  'artistes' => [
    'title' => 'Les Artistes de l’asso',
    'alias' => '/les-artistes-de-l-asso',
    'create_if_missing' => FALSE,
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
  'origine' => [
    'title' => 'Origine',
    'alias' => '/origine',
    'create_if_missing' => TRUE,
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>La démarche d'Uni-Songes trouve ses racines dans le souffle, le didgeridoo, l'improvisation, l'écoute et la pratique collective.</p>
  <p>Elle relie création artistique et transmission pédagogique, dans des formats individuels ou partagés.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Une pratique vivante</h2>
  <p>Cette démarche se déploie aujourd'hui à travers des cours, des stages, des ateliers, des concerts et des services artistiques.</p>
</section>
HTML,
  ],
  'services' => [
    'title' => 'Services et prestations artistiques',
    'alias' => '/services-prestations-artistiques',
    'create_if_missing' => FALSE,
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

$main_menu_links = [
  [
    'title' => 'Cours & Stages',
    'path' => '/cours-et-stages',
    'weight' => 0,
    'parent_path' => NULL,
    'enabled' => TRUE,
    'create_if_missing' => TRUE,
  ],
  [
    'title' => 'Concerts & Événements',
    'path' => '/concerts',
    'weight' => 10,
    'parent_path' => NULL,
    'enabled' => TRUE,
    'create_if_missing' => FALSE,
  ],
  [
    'title' => 'Ateliers',
    'path' => '/ateliers',
    'weight' => 20,
    'parent_path' => NULL,
    'enabled' => TRUE,
    'create_if_missing' => TRUE,
  ],
  [
    'title' => 'À propos',
    'path' => '/a-propos',
    'weight' => 30,
    'parent_path' => NULL,
    'enabled' => TRUE,
    'create_if_missing' => TRUE,
  ],
  [
    'title' => 'Contact',
    'path' => '/contact',
    'weight' => 40,
    'parent_path' => NULL,
    'enabled' => TRUE,
    'create_if_missing' => FALSE,
  ],
  [
    'title' => 'Cours particuliers',
    'path' => '/cours',
    'weight' => 0,
    'parent_path' => '/cours-et-stages',
    'enabled' => TRUE,
    'create_if_missing' => FALSE,
  ],
  [
    'title' => 'Stages',
    'path' => '/stages',
    'weight' => 10,
    'parent_path' => '/cours-et-stages',
    'enabled' => TRUE,
    'create_if_missing' => FALSE,
  ],
  [
    'title' => 'D’Jam',
    'path' => '/djam',
    'weight' => 0,
    'parent_path' => '/ateliers',
    'enabled' => TRUE,
    'create_if_missing' => FALSE,
  ],
  [
    'title' => 'Orchestre',
    'path' => '/orchestre-des-reveurs',
    'weight' => 10,
    'parent_path' => '/ateliers',
    'enabled' => TRUE,
    'create_if_missing' => FALSE,
  ],
  [
    'title' => 'L’Asso',
    'path' => '/association',
    'weight' => 0,
    'parent_path' => '/a-propos',
    'enabled' => TRUE,
    'create_if_missing' => FALSE,
  ],
  [
    'title' => 'Partenaires',
    'path' => '/les-artistes-de-l-asso',
    'weight' => 10,
    'parent_path' => '/a-propos',
    'enabled' => TRUE,
    'create_if_missing' => FALSE,
  ],
  [
    'title' => 'Origine',
    'path' => '/origine',
    'weight' => 20,
    'parent_path' => '/a-propos',
    'enabled' => TRUE,
    'create_if_missing' => TRUE,
  ],
];

$main_menu_links_to_disable = [
  '/services-prestations-artistiques',
];

$required_reference_page_aliases = [
  '/concerts',
  '/djam',
  '/orchestre-des-reveurs',
  '/contact',
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
$resolved_page_nodes = [];
$creatable_page_aliases = [];
foreach ($pages as $page) {
  try {
    $create_if_missing = $page['create_if_missing'] ?? NULL;
    if (!is_bool($create_if_missing)) {
      throw new RuntimeException('Page create_if_missing must be boolean.');
    }
    if ($create_if_missing) {
      $creatable_page_aliases[] = $page['alias'];
    }

    $node = resolve_page_node($page['alias']);
    if ($node) {
      $node_id = (string) $node->id();
      if (isset($resolved_page_nodes[$node_id])) {
        throw new RuntimeException(
          'Page aliases ' . $resolved_page_nodes[$node_id] . ' and ' . $page['alias']
          . ' resolve to the same node ' . $node_id . '; refusing to update it twice.'
        );
      }
      $resolved_page_nodes[$node_id] = $page['alias'];
      echo 'OK inspected existing page target ' . $page['alias'] . ' -> node ' . $node_id . PHP_EOL;
    }
    else {
      if (!$create_if_missing) {
        throw new RuntimeException(
          'Required existing page alias is missing; refusing to create or adopt a page by title.'
        );
      }
      $same_title_node_ids = page_node_ids_by_title($page['title']);
      if ($same_title_node_ids) {
        throw new RuntimeException(
          'Alias is missing but page title "' . $page['title'] . '" already belongs to node(s) '
          . implode(', ', $same_title_node_ids) . '; refusing to create a duplicate or adopt by title.'
        );
      }
      echo 'OK inspected new page target ' . $page['alias'] . PHP_EOL;
    }
  }
  catch (Throwable $throwable) {
    check(FALSE, $page['alias'] . ': ' . $throwable->getMessage());
  }
}

foreach ($required_reference_page_aliases as $alias) {
  try {
    $node = page_node_by_alias($alias);
    if (!$node) {
      throw new RuntimeException('Required existing reference page alias is missing.');
    }
    $node_id = (string) $node->id();
    if (isset($resolved_page_nodes[$node_id])) {
      throw new RuntimeException(
        'Reference alias resolves to node ' . $node_id . ', already selected by '
        . $resolved_page_nodes[$node_id] . '.'
      );
    }
    $resolved_page_nodes[$node_id] = $alias;
    echo 'OK alias ' . $alias . ' -> /node/' . $node_id
      . ' (reference page preserved; body unchanged)' . PHP_EOL;
  }
  catch (Throwable $throwable) {
    check(FALSE, $alias . ': ' . $throwable->getMessage());
  }
}

check(
  $creatable_page_aliases === ['/cours-et-stages', '/ateliers', '/a-propos', '/origine'],
  'only the four new public pages may be created'
);

if ($failed) {
  echo PHP_EOL . 'Blocked before writes. No content was changed.' . PHP_EOL;
  exit(1);
}

section('Menu preflight');
try {
  preflight_main_menu_architecture($main_menu_links, $main_menu_links_to_disable);
  echo 'OK main menu architecture is unambiguous and safe to reconcile' . PHP_EOL;
}
catch (Throwable $throwable) {
  check(FALSE, $throwable->getMessage());
}

if ($failed) {
  echo PHP_EOL . 'Blocked before writes. No content or menu links were changed.' . PHP_EOL;
  exit(1);
}

section($is_apply ? 'Page apply' : 'Page dry-run');
$transaction = $is_apply ? \Drupal::database()->startTransaction() : NULL;
foreach ($pages as $page) {
  try {
    $node = resolve_page_node($page['alias']);
    $create_if_missing = (bool) $page['create_if_missing'];
    if (!$node && $is_apply) {
      if (!$create_if_missing) {
        throw new RuntimeException('Required existing page disappeared after preflight; refusing to create it.');
      }
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
      if (!$create_if_missing) {
        throw new RuntimeException('Required existing page disappeared after preflight; refusing to create it.');
      }
      echo 'WOULD_CREATE page ' . $page['alias'] . ' title "' . $page['title'] . '"' . PHP_EOL;
      print_exact_body_change($page['alias'], NULL, $page['body'], $body_format);
    }

    if ($node) {
      $changes = page_changes($node, $page['title'], $page['body'], $body_format);
      if ($changes) {
        if ($is_apply) {
          update_page_node($node, $page['title'], $page['body'], $body_format, $changes);
          echo 'UPDATED page ' . $page['alias'] . ' node ' . $node->id() . ': ' . implode('; ', $changes) . PHP_EOL;
        }
        else {
          echo 'WOULD_UPDATE page ' . $page['alias'] . ' node ' . $node->id() . ': ' . implode('; ', $changes) . PHP_EOL;
          if (isset($changes['body'])) {
            print_exact_body_change($page['alias'], $node, $page['body'], $body_format);
          }
        }
      }
      else {
        echo 'OK page ' . $page['alias'] . ' node ' . $node->id() . ' already matches' . PHP_EOL;
      }
    }

    if ($node) {
      ensure_alias($node, $page['alias'], $is_apply);
    }
    elseif (!$is_apply) {
      echo 'WOULD_CREATE alias ' . $page['alias'] . ' after node creation' . PHP_EOL;
    }
  }
  catch (Throwable $throwable) {
    check(FALSE, $page['alias'] . ': ' . $throwable->getMessage());
    if ($is_apply) {
      break;
    }
  }
}

if ($failed) {
  if ($transaction) {
    $transaction->rollBack();
  }
  echo PHP_EOL . 'Blocked while preparing pages. No menu links were changed.' . PHP_EOL;
  echo $is_apply ? 'All page writes from this run were rolled back.' . PHP_EOL : '';
  exit(1);
}

section($is_apply ? 'Menu apply' : 'Menu dry-run');
foreach ($main_menu_links as $menu_link) {
  try {
    ensure_main_menu_link($menu_link, $main_menu_links, $is_apply);
  }
  catch (Throwable $throwable) {
    check(FALSE, $menu_link['title'] . ': ' . $throwable->getMessage());
    if ($is_apply) {
      break;
    }
  }
}

if (!$failed) {
  foreach ($main_menu_links_to_disable as $menu_path) {
    try {
      disable_main_menu_link($menu_path, $is_apply);
    }
    catch (Throwable $throwable) {
      check(FALSE, $menu_path . ': ' . $throwable->getMessage());
      if ($is_apply) {
        break;
      }
    }
  }
}

if ($failed) {
  if ($transaction) {
    $transaction->rollBack();
  }
  echo PHP_EOL . 'Content architecture failed.' . PHP_EOL;
  echo $is_apply ? 'All writes from this run were rolled back.' . PHP_EOL : '';
  exit(1);
}

unset($transaction);

section('Result');
if ($is_apply) {
  echo 'Apply completed. Only allowlisted page nodes, aliases, and main-menu links were reconciled; the services link was retained and disabled.' . PHP_EOL;
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

function resolve_page_node(string $alias): ?NodeInterface {
  return page_node_by_alias($alias);
}

function page_node_ids_by_title(string $title): array {
  $node_ids = \Drupal::entityQuery('node')
    ->accessCheck(FALSE)
    ->condition('type', 'page')
    ->condition('title', $title)
    ->execute();
  return array_map('strval', array_values($node_ids));
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

  if (!$aliases) {
    return NULL;
  }

  if (count($aliases) > 1) {
    $descriptions = [];
    foreach ($aliases as $candidate) {
      $descriptions[] = 'id=' . $candidate->id() . ', path=' . $candidate->getPath()
        . ', langcode=' . $candidate->language()->getId();
    }
    throw new RuntimeException(
      'Alias ' . $alias . ' has duplicate records: ' . implode('; ', $descriptions) . '.'
    );
  }

  return reset($aliases);
}

function page_changes(NodeInterface $node, string $title, string $body, string $format): array {
  $changes = [];
  if ($node->label() !== $title) {
    $changes['title'] = 'title "' . $node->label() . '" -> "' . $title . '"';
  }
  if (!$node->isPublished()) {
    $changes['status'] = 'status unpublished -> published';
  }

  $current_body = $node->hasField('body') && !$node->get('body')->isEmpty()
    ? (string) $node->get('body')->value
    : '';
  $current_format = $node->hasField('body') && !$node->get('body')->isEmpty()
    ? (string) $node->get('body')->format
    : '';
  if ($current_body !== $body || $current_format !== $format) {
    $changes['body'] = 'body differs; exact current and planned values follow';
  }

  return $changes;
}

function print_exact_body_change(string $alias, ?NodeInterface $node, string $planned_body, string $planned_format): void {
  echo 'BODY_CHANGE_EXACT alias=' . $alias;
  echo $node ? ' node=' . $node->id() . PHP_EOL : ' node=NEW' . PHP_EOL;

  if ($node) {
    $current_body = $node->hasField('body') && !$node->get('body')->isEmpty()
      ? (string) $node->get('body')->value
      : '';
    $current_format = $node->hasField('body') && !$node->get('body')->isEmpty()
      ? (string) $node->get('body')->format
      : '';
    echo 'CURRENT_FORMAT ' . $current_format . PHP_EOL;
    print_exact_body_block('CURRENT_BODY', $current_body);
  }
  else {
    echo 'CURRENT_FORMAT <absent>' . PHP_EOL;
    echo 'CURRENT_BODY <absent>' . PHP_EOL;
  }

  echo 'PLANNED_FORMAT ' . $planned_format . PHP_EOL;
  print_exact_body_block('PLANNED_BODY', $planned_body);
  echo 'END_BODY_CHANGE_EXACT alias=' . $alias . PHP_EOL;
}

function print_exact_body_block(string $label, string $body): void {
  echo $label . '_BEGIN bytes=' . strlen($body) . ' sha256=' . hash('sha256', $body) . PHP_EOL;
  echo $body;
  if ($body === '' || substr($body, -1) !== "\n") {
    echo PHP_EOL;
  }
  echo $label . '_END' . PHP_EOL;
}

function update_page_node(NodeInterface $node, string $title, string $body, string $format, array $changes): void {
  $current_summary = $node->hasField('body') && !$node->get('body')->isEmpty()
    ? $node->get('body')->summary
    : NULL;
  $node->setTitle($title);
  $node->setPublished(TRUE);
  $node->set('body', [
    'value' => $body,
    'format' => $format,
    'summary' => $current_summary,
  ]);

  if (method_exists($node, 'setNewRevision')) {
    $node->setNewRevision(TRUE);
  }
  if (method_exists($node, 'setRevisionLogMessage')) {
    $node->setRevisionLogMessage('Content architecture 2026 update: ' . implode(', ', array_keys($changes)) . '.');
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

function preflight_main_menu_architecture(array $specs, array $paths_to_disable): void {
  $declared_paths = [];
  $declared_titles = [];
  $normalized_paths = [];
  $selected_plugins = [];

  foreach ($specs as $spec) {
    $title = (string) ($spec['title'] ?? '');
    $path = (string) ($spec['path'] ?? '');
    $parent_path = $spec['parent_path'] ?? NULL;
    $enabled = $spec['enabled'] ?? NULL;
    $create_if_missing = $spec['create_if_missing'] ?? NULL;

    if ($title === '' || preg_match('//u', $title) !== 1) {
      throw new RuntimeException('Every canonical menu label must be non-empty UTF-8.');
    }
    if (!is_ascii_public_path($path)) {
      throw new RuntimeException('Canonical menu path is not an ASCII public path: ' . $path . '.');
    }
    if (isset($declared_paths[$path])) {
      throw new RuntimeException('Canonical menu path is declared more than once: ' . $path . '.');
    }
    if (isset($declared_titles[$title])) {
      throw new RuntimeException('Canonical menu label is declared more than once: "' . $title . '".');
    }
    if ($enabled !== TRUE) {
      throw new RuntimeException('Canonical active menu link must declare enabled TRUE: ' . $path . '.');
    }
    if (!is_bool($create_if_missing)) {
      throw new RuntimeException('Menu create_if_missing must be boolean for ' . $path . '.');
    }
    if ($parent_path !== NULL && (!is_string($parent_path) || !isset($declared_paths[$parent_path]))) {
      throw new RuntimeException('Parent ' . (string) $parent_path . ' must be declared before child ' . $path . '.');
    }

    $normalized_path = normalized_internal_path($path);
    if (isset($normalized_paths[$normalized_path])) {
      throw new RuntimeException(
        'Canonical paths ' . $normalized_paths[$normalized_path] . ' and ' . $path
        . ' normalize to the same destination ' . $normalized_path . '.'
      );
    }

    $link = find_unique_main_menu_link($path);
    if (!$link && !$create_if_missing) {
      throw new RuntimeException(
        'Required existing main menu link for ' . $path . ' is missing; refusing to create it.'
      );
    }
    if ($link) {
      assert_main_menu_destination_is_canonical($link, $path);
    }
    assert_main_menu_label_available($title, $link);

    if ($link) {
      $plugin_id = menu_link_plugin_id($link);
      if (isset($selected_plugins[$plugin_id])) {
        throw new RuntimeException(
          'Menu paths ' . $selected_plugins[$plugin_id] . ' and ' . $path
          . ' select the same link plugin ' . $plugin_id . '.'
        );
      }
      $selected_plugins[$plugin_id] = $path;
      echo 'OK inspected existing main menu target ' . $path . ' [' . $plugin_id . ']' . PHP_EOL;
    }
    else {
      echo 'OK inspected new main menu target ' . $path . PHP_EOL;
    }

    $declared_paths[$path] = TRUE;
    $declared_titles[$title] = TRUE;
    $normalized_paths[$normalized_path] = $path;
  }

  foreach ($paths_to_disable as $path) {
    if (!is_string($path) || !is_ascii_public_path($path)) {
      throw new RuntimeException('Disabled menu path is not an ASCII public path.');
    }
    if (isset($declared_paths[$path])) {
      throw new RuntimeException('Menu path cannot be active and disabled in the same plan: ' . $path . '.');
    }

    $normalized_path = normalized_internal_path($path);
    if (isset($normalized_paths[$normalized_path])) {
      throw new RuntimeException(
        'Disabled path ' . $path . ' and active path ' . $normalized_paths[$normalized_path]
        . ' normalize to the same destination ' . $normalized_path . '.'
      );
    }

    $link = find_unique_main_menu_link($path);
    if (!$link) {
      throw new RuntimeException(
        'Required existing main menu link for ' . $path . ' is missing; there is nothing to disable.'
      );
    }
    assert_main_menu_destination_is_canonical($link, $path);
    if ((string) $link->get('parent')->value !== '') {
      throw new RuntimeException(
        'Required services link is not top-level; refusing to disable it from unexpected parent '
        . current_menu_parent_description((string) $link->get('parent')->value) . '.'
      );
    }
    $plugin_id = menu_link_plugin_id($link);
    if (isset($selected_plugins[$plugin_id])) {
      throw new RuntimeException(
        'Disabled path ' . $path . ' selects the same link plugin as ' . $selected_plugins[$plugin_id] . '.'
      );
    }
    echo 'OK inspected existing main menu target to disable ' . $path . ' [' . $plugin_id . ']' . PHP_EOL;
    $selected_plugins[$plugin_id] = $path;
    $normalized_paths[$normalized_path] = $path;
  }
}

function ensure_main_menu_link(array $spec, array $specs, bool $is_apply): void {
  $title = (string) $spec['title'];
  $path = (string) $spec['path'];
  $weight = (int) $spec['weight'];
  $enabled = (bool) $spec['enabled'];
  $create_if_missing = (bool) $spec['create_if_missing'];
  $planned_parent = planned_menu_parent($spec, $specs, $is_apply);
  $planned_state = menu_link_state($title, $weight, $planned_parent['description'], $enabled);
  $link = find_unique_main_menu_link($path);

  if (!$link) {
    if (!$create_if_missing) {
      throw new RuntimeException('Required existing link disappeared after preflight; refusing to create it.');
    }
    if ($is_apply) {
      if (!is_string($planned_parent['plugin_id'])) {
        throw new RuntimeException('Parent plugin ID is unavailable for ' . $path . '.');
      }
      $link = MenuLinkContent::create([
        'title' => $title,
        'menu_name' => 'main',
        'link' => ['uri' => 'internal:' . $path],
        'enabled' => $enabled,
        'expanded' => FALSE,
        'weight' => $weight,
        'parent' => $planned_parent['plugin_id'],
      ]);
      $link->save();
      echo 'CREATED main menu link ' . $path . ': planned ' . $planned_state . PHP_EOL;
    }
    else {
      echo 'WOULD_CREATE main menu link ' . $path . ': planned ' . $planned_state . PHP_EOL;
    }
    return;
  }

  $current_parent_id = (string) $link->get('parent')->value;
  $current_state = menu_link_state(
    $link->label(),
    (int) $link->get('weight')->value,
    current_menu_parent_description($current_parent_id),
    (bool) $link->get('enabled')->value
  );
  $parent_changed = $planned_parent['plugin_id'] === NULL
    || $current_parent_id !== $planned_parent['plugin_id'];
  $has_changes = $link->label() !== $title
    || (int) $link->get('weight')->value !== $weight
    || $parent_changed
    || (bool) $link->get('enabled')->value !== $enabled;

  if (!$has_changes) {
    echo 'OK main menu link ' . $path . ': ' . $planned_state . PHP_EOL;
    return;
  }

  if ($is_apply) {
    if (!is_string($planned_parent['plugin_id'])) {
      throw new RuntimeException('Parent plugin ID is unavailable for ' . $path . '.');
    }
    if ($link->label() !== $title) {
      $link->set('title', $title);
    }
    if ((int) $link->get('weight')->value !== $weight) {
      $link->set('weight', $weight);
    }
    if ($current_parent_id !== $planned_parent['plugin_id']) {
      $link->set('parent', $planned_parent['plugin_id']);
    }
    if ((bool) $link->get('enabled')->value !== $enabled) {
      $link->set('enabled', $enabled);
    }
    $link->save();
    echo 'UPDATED main menu link ' . $path . ': current ' . $current_state . '; planned ' . $planned_state . PHP_EOL;
  }
  else {
    echo 'WOULD_UPDATE main menu link ' . $path . ': current ' . $current_state . '; planned ' . $planned_state . PHP_EOL;
  }
}

function disable_main_menu_link(string $path, bool $is_apply): void {
  $link = find_unique_main_menu_link($path);
  if (!$link) {
    throw new RuntimeException('Required existing link disappeared after preflight; refusing to create it.');
  }

  $label = $link->label();
  $weight = (int) $link->get('weight')->value;
  $parent = current_menu_parent_description((string) $link->get('parent')->value);
  $is_enabled = (bool) $link->get('enabled')->value;
  $current_state = menu_link_state($label, $weight, $parent, $is_enabled);
  $planned_state = menu_link_state($label, $weight, $parent, FALSE);

  if (!$is_enabled) {
    echo 'OK disabled main menu link ' . $path . ': ' . $planned_state . '; retained, not deleted' . PHP_EOL;
    return;
  }

  if ($is_apply) {
    $link->set('enabled', FALSE);
    $link->save();
    echo 'DISABLED main menu link ' . $path . ': current ' . $current_state . '; planned ' . $planned_state . '; retained, not deleted' . PHP_EOL;
  }
  else {
    echo 'WOULD_DISABLE main menu link ' . $path . ': current ' . $current_state . '; planned ' . $planned_state . '; retained, not deleted' . PHP_EOL;
  }
}

function planned_menu_parent(array $spec, array $specs, bool $is_apply): array {
  $parent_path = $spec['parent_path'];
  if ($parent_path === NULL) {
    return [
      'plugin_id' => '',
      'description' => 'top-level',
    ];
  }

  $parent_spec = menu_spec_by_path($specs, $parent_path);
  $parent_link = find_unique_main_menu_link($parent_path);
  if (!$parent_link) {
    if ($is_apply) {
      throw new RuntimeException('Parent link ' . $parent_path . ' was not created before its child.');
    }
    return [
      'plugin_id' => NULL,
      'description' => 'child of "' . $parent_spec['title'] . '" (' . $parent_path . '; UUID assigned on apply)',
    ];
  }

  $plugin_id = menu_link_plugin_id($parent_link);
  return [
    'plugin_id' => $plugin_id,
    'description' => 'child of "' . $parent_spec['title'] . '" (' . $parent_path . '; ' . $plugin_id . ')',
  ];
}

function menu_spec_by_path(array $specs, string $path): array {
  foreach ($specs as $spec) {
    if (($spec['path'] ?? NULL) === $path) {
      return $spec;
    }
  }
  throw new RuntimeException('No canonical menu specification exists for parent ' . $path . '.');
}

function find_unique_main_menu_link(string $path): ?MenuLinkContent {
  $expected_path = normalized_internal_path($path);
  $matching_links = [];

  foreach (main_menu_content_links() as $candidate) {
    if (menu_link_system_path($candidate) === $expected_path || menu_link_uri($candidate) === 'internal:' . $path) {
      $matching_links[] = $candidate;
    }
  }

  if (count($matching_links) > 1) {
    $plugin_ids = array_map(
      static fn(MenuLinkContent $link): string => menu_link_plugin_id($link),
      $matching_links
    );
    throw new RuntimeException(
      'Multiple main menu links normalize to ' . $path . ': ' . implode(', ', $plugin_ids)
      . '; refusing an ambiguous destination.'
    );
  }

  return $matching_links ? reset($matching_links) : NULL;
}

function main_menu_content_links(): array {
  $links = \Drupal::entityTypeManager()
    ->getStorage('menu_link_content')
    ->loadByProperties(['menu_name' => 'main']);

  return array_values(array_filter(
    $links,
    static fn($link): bool => $link instanceof MenuLinkContent
  ));
}

function assert_main_menu_label_available(string $title, ?MenuLinkContent $selected): void {
  $selected_plugin_id = $selected ? menu_link_plugin_id($selected) : NULL;
  foreach (main_menu_content_links() as $candidate) {
    if ($candidate->label() !== $title) {
      continue;
    }
    $candidate_plugin_id = menu_link_plugin_id($candidate);
    if ($candidate_plugin_id !== $selected_plugin_id) {
      throw new RuntimeException(
        'Another main menu link already uses label "' . $title . '" at URI '
        . menu_link_uri($candidate) . ' [' . $candidate_plugin_id . '].'
      );
    }
  }
}

function assert_main_menu_destination_is_canonical(MenuLinkContent $link, string $path): void {
  $uri = menu_link_uri($link);
  if ($uri === 'internal:' . $path) {
    return;
  }

  if (preg_match('/^(?:entity:node\/|internal:\/node\/)(\d+)$/', $uri, $matches)) {
    $system_path = '/node/' . $matches[1];
    $langcode = \Drupal::languageManager()->getDefaultLanguage()->getId();
    $outbound_path = \Drupal::service('path_alias.manager')->getAliasByPath($system_path, $langcode);
    if ($outbound_path === $path) {
      return;
    }
    throw new RuntimeException(
      'Main menu link for ' . $path . ' uses node destination ' . $uri
      . ' whose canonical outbound alias is ' . $outbound_path . '.'
    );
  }

  throw new RuntimeException(
    'Main menu link for ' . $path . ' is stored as non-canonical URI ' . $uri
    . '; refusing to rewrite it implicitly.'
  );
}

function normalized_internal_path(string $path): string {
  if (preg_match('/^\/node\/\d+$/', $path)) {
    return $path;
  }
  $alias = alias_entity($path);
  return $alias ? (string) $alias->getPath() : $path;
}

function menu_link_system_path(MenuLinkContent $link): string {
  $uri = menu_link_uri($link);
  if (strpos($uri, 'internal:') === 0) {
    $path = substr($uri, strlen('internal:'));
    return strpos($path, '/') === 0 ? normalized_internal_path($path) : '';
  }
  if (preg_match('/^entity:node\/(\d+)$/', $uri, $matches)) {
    return '/node/' . $matches[1];
  }
  return '';
}

function menu_link_plugin_id(MenuLinkContent $link): string {
  $plugin_id = $link->getPluginId();
  if (!preg_match('/^menu_link_content:[0-9a-fA-F-]{36}$/', $plugin_id)) {
    throw new RuntimeException('Menu link has no UUID-backed plugin ID: ' . $plugin_id . '.');
  }
  return $plugin_id;
}

function current_menu_parent_description(string $parent_id): string {
  if ($parent_id === '') {
    return 'top-level';
  }
  foreach (main_menu_content_links() as $candidate) {
    if (menu_link_plugin_id($candidate) === $parent_id) {
      return 'child of "' . $candidate->label() . '" (' . menu_link_uri($candidate) . '; ' . $parent_id . ')';
    }
  }
  return 'plugin "' . $parent_id . '"';
}

function menu_link_state(string $title, int $weight, string $parent, bool $enabled): string {
  return '{label="' . str_replace('"', '\\"', $title) . '", weight=' . $weight
    . ', parent=' . $parent . ', enabled=' . ($enabled ? 'TRUE' : 'FALSE') . '}';
}

function is_ascii_public_path(string $path): bool {
  return preg_match('#^/[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$#D', $path) === 1;
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
