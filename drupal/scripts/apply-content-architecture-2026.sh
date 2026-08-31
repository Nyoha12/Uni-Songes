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
- /forum
- /a-propos
- /blog
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
- 20 Projets collectifs -> /ateliers
- 30 À propos -> /a-propos
- 40 Contact -> /contact
Children of Cours & Stages:
- 0 Cours particuliers -> /cours
- 10 Stages -> /stages
Children of Projets collectifs:
- 0 D’Jam -> /djam
- 10 Orchestre -> /orchestre-des-reveurs
- 20 Forum -> /forum
Children of À propos:
- 0 L’Asso -> /association
- 10 Partenaires -> /les-artistes-de-l-asso
- 20 Origine -> /origine
- 30 Blog -> /blog
Disabled in place, retained and not deleted:
- /services-prestations-artistiques

Guards:
- dry-run by default; writes require --apply
- all managed existing pages are resolved strictly by alias
- only /cours-et-stages, /ateliers, /forum, /a-propos, /blog and /origine may be created
- dry-run prints exact current and planned bodies when a body would change
- menu links are matched by normalized destination
- child parents use UUID-backed Drupal menu-link plugin identifiers
- only the six allowlisted menu destinations may be created
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

final class ContentArchitecturePreflightBlocked extends RuntimeException {}

final class ContentArchitectureApplyRolledBack extends RuntimeException {

  public function __construct(
    string $message,
    public readonly bool $rollbackConfirmed,
    ?Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

}

final class ContentArchitectureChangePlan {

  public readonly string $fingerprint;

  public function __construct(
    public readonly array $pages,
    public readonly array $aliases,
    public readonly array $references,
    public readonly array $menuLinks,
    public readonly array $operations,
  ) {
    $this->fingerprint = $this->calculateFingerprint();
  }

  public function assertIntegrity(): void {
    if (!hash_equals($this->fingerprint, $this->calculateFingerprint())) {
      throw new RuntimeException('The immutable change plan failed its integrity check.');
    }
  }

  private function calculateFingerprint(): string {
    return hash('sha256', serialize([
      'pages' => $this->pages,
      'aliases' => $this->aliases,
      'references' => $this->references,
      'menu_links' => $this->menuLinks,
      'operations' => $this->operations,
    ]));
  }

}

$mode = getenv('UNISONGES_CONTENT_ARCH_MODE') ?: 'dry-run';
$is_apply = $mode === 'apply';
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
    'title' => 'Projets collectifs',
    'alias' => '/ateliers',
    'create_if_missing' => TRUE,
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Les projets collectifs d'Uni-Songes réunissent des espaces de pratique, de création, d'écoute, d'improvisation et d'échange autour de la musique et des projets de l'association.</p>
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
    <h2 class="unisonges-offer-card__title">Forum</h2>
    <p class="unisonges-offer-card__text">Un espace pour échanger des idées autour de la musique, des pratiques collectives et des projets de l'association.</p>
    <p class="unisonges-offer-card__cta"><a href="/forum">Découvrir le Forum</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Services et prestations artistiques</h2>
    <p class="unisonges-offer-card__text">Découvrir les interventions artistiques, pédagogiques et sonores proposées dans différents contextes.</p>
    <p class="unisonges-offer-card__cta"><a href="/services-prestations-artistiques">Voir les services et prestations</a></p>
  </article>
</div>
HTML,
  ],
  'forum' => [
    'title' => 'Forum',
    'alias' => '/forum',
    'create_if_missing' => TRUE,
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Le Forum est un espace d'échange d'idées autour de la musique, des pratiques collectives et des projets de l'association.</p>
  <p>Les membres peuvent proposer des sujets de discussion, des idées d'articles ou des thèmes pour le Blog. Les contributions sont modérées avant leur publication.</p>
</section>

<section class="unisonges-detail-section" id="forum-mvp" aria-labelledby="forum-mvp-title">
  <h2 id="forum-mvp-title">Participer au Forum</h2>
  <p>L'espace de participation sera intégré dans cette zone par l'implémentation fonctionnelle dédiée. Aucune fonctionnalité de compte, de publication ou de réponse n'est annoncée tant qu'elle n'est pas disponible.</p>
  <p class="unisonges-offer-card__cta"><a href="/contact">Proposer un sujet à l'association</a></p>
</section>
HTML,
  ],
  'a_propos' => [
    'title' => 'À propos',
    'alias' => '/a-propos',
    'create_if_missing' => TRUE,
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Cette page oriente vers l'association Uni-Songes, les artistes et partenaires qu'elle présente, les origines de sa démarche, son Blog et ses activités artistiques et pédagogiques.</p>
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
    <h2 class="unisonges-offer-card__title">Blog</h2>
    <p class="unisonges-offer-card__text">Retrouver les actualités de l'association, des articles artistiques et pédagogiques, des réflexions et des ressources.</p>
    <p class="unisonges-offer-card__cta"><a href="/blog">Découvrir le Blog</a></p>
  </article>
  <article class="unisonges-offer-card">
    <h2 class="unisonges-offer-card__title">Services et activités artistiques</h2>
    <p class="unisonges-offer-card__text">Explorer les interventions artistiques, pédagogiques et sonores.</p>
    <p class="unisonges-offer-card__cta"><a href="/services-prestations-artistiques">Voir les services et prestations</a></p>
  </article>
</div>
HTML,
  ],
  'blog' => [
    'title' => 'Blog',
    'alias' => '/blog',
    'create_if_missing' => TRUE,
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Le Blog accueillera les actualités de l'association, des articles artistiques et pédagogiques, ainsi que des réflexions et des ressources autour de ses pratiques et de ses projets.</p>
</section>

<section class="unisonges-detail-section" id="blog-articles" aria-labelledby="blog-articles-title">
  <h2 id="blog-articles-title">Articles</h2>
  <p>Les futurs articles publiés seront listés dynamiquement dans cette zone par l'implémentation Blog dédiée. Ce contenu introductif ne liste aucun article.</p>
</section>
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
    'title' => 'Artistes et partenaires',
    'alias' => '/les-artistes-de-l-asso',
    'create_if_missing' => FALSE,
    'body' => <<<'HTML'
<section class="unisonges-page-intro">
  <p>Cette page présente l’environnement artistique et collaboratif autour d’Uni-Songes.</p>
  <p>Elle propose des repères généraux sur les pratiques, les approches et les formes de projets possibles.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Pratiques et approches</h2>
  <p>Les pratiques abordées autour d’Uni-Songes comprennent le didgeridoo, la guimbarde, l’écoute et l’improvisation musicale. La pratique collective et la transmission artistique et pédagogique complètent ces approches.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Projets et collaborations</h2>
  <p>Selon les projets, les activités d’Uni-Songes peuvent prendre la forme de concerts, de projets collectifs, d’ateliers ou d’interventions pédagogiques, ainsi que de prestations artistiques et sonores.</p>
</section>

<section class="unisonges-detail-section">
  <h2>Découvrir et prendre contact</h2>
  <ul>
    <li><a href="/concerts">Consulter les concerts</a></li>
    <li><a href="/ateliers">Découvrir les projets collectifs</a></li>
    <li><a href="/services-prestations-artistiques">Voir les services et prestations artistiques</a></li>
    <li><a href="/contact">Contacter Uni-Songes</a></li>
  </ul>
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
    'title' => 'Projets collectifs',
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
    'title' => 'Forum',
    'path' => '/forum',
    'weight' => 20,
    'parent_path' => '/ateliers',
    'enabled' => TRUE,
    'create_if_missing' => TRUE,
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
  [
    'title' => 'Blog',
    'path' => '/blog',
    'weight' => 30,
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

$phase = 'Phase A';
try {
  section('Phase A — complete read-only discovery and validation');
  assert_runtime_guards($mode, $body_format);
  $change_plan = build_change_plan(
    $pages,
    $required_reference_page_aliases,
    $main_menu_links,
    $main_menu_links_to_disable,
    $body_format
  );
  $change_plan->assertIntegrity();
  echo 'OK Phase A complete: immutable plan sha256=' . $change_plan->fingerprint . PHP_EOL;
  print_change_plan($change_plan, $is_apply ? 'PLAN' : 'WOULD');
}
catch (Throwable $throwable) {
  echo PHP_EOL . 'BLOCKED Phase A: ' . $throwable->getMessage() . PHP_EOL;
  echo 'FAIL Phase A did not produce a valid complete plan.' . PHP_EOL;
  echo 'Phase B was not started; transaction_started=FALSE; writes=0.' . PHP_EOL;
  exit(1);
}

if (!$is_apply) {
  section('Dry-run result');
  echo 'Dry-run completed. No content, menu links, aliases, config, or Commerce data was changed.' . PHP_EOL;
}
else {
  $phase = 'Phase B';
  try {
    section('Phase B — atomic apply');
    $committed_messages = apply_change_plan($change_plan);
    foreach ($committed_messages as $message) {
      echo $message . PHP_EOL;
    }
    section('Apply result');
    echo 'Apply completed. The complete immutable plan was committed atomically; the services link was retained and disabled.' . PHP_EOL;
  }
  catch (Throwable $throwable) {
    echo PHP_EOL . 'BLOCKED Phase B: ' . $throwable->getMessage() . PHP_EOL;
    echo 'FAIL Phase B did not complete with a confirmed atomic commit.' . PHP_EOL;
    if ($throwable instanceof ContentArchitectureApplyRolledBack && $throwable->rollbackConfirmed) {
      echo 'ROLLBACK_CONFIRMED; no planned entity write was committed.' . PHP_EOL;
    }
    else {
      echo 'ROLLBACK_UNCONFIRMED; persistence state requires operator inspection.' . PHP_EOL;
    }
    echo 'No apply-success message was emitted.' . PHP_EOL;
    exit(1);
  }
}

function section(string $title): void {
  echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
}

function assert_runtime_guards(string $mode, string $body_format): void {
  $checks = [
    'mode is dry-run or apply' => $mode === 'dry-run' || $mode === 'apply',
    'node entity type is available' => \Drupal::entityTypeManager()->hasDefinition('node'),
    'path_alias entity type is available' => \Drupal::entityTypeManager()->hasDefinition('path_alias'),
    'menu_link_content entity type is available' => \Drupal::entityTypeManager()->hasDefinition('menu_link_content'),
    'page content type exists' => (bool) \Drupal\node\Entity\NodeType::load('page'),
  ];

  $format = \Drupal::entityTypeManager()->getStorage('filter_format')->load($body_format);
  $checks[$body_format . ' text format exists and is enabled'] = (bool) $format && (bool) $format->status();

  $failures = [];
  foreach ($checks as $message => $ok) {
    echo ($ok ? 'OK ' : 'FAIL ') . 'Phase A runtime guard: ' . $message . PHP_EOL;
    if (!$ok) {
      $failures[] = $message;
    }
  }

  if ($failures) {
    throw new ContentArchitecturePreflightBlocked(
      'Runtime guards failed: ' . implode('; ', $failures) . '.'
    );
  }
}

function build_change_plan(
  array $page_specs,
  array $reference_aliases,
  array $menu_specs,
  array $menu_paths_to_disable,
  string $body_format,
): ContentArchitectureChangePlan {
  $blockers = [];
  $reserved_uuids = [];
  $seen_node_ids = [];
  $page_plans = [];
  $alias_plans = [];
  $reference_plans = [];

  $declared_creatable_pages = [];
  foreach ($page_specs as $key => $spec) {
    try {
      validate_page_spec($key, $spec);
      if ($spec['create_if_missing']) {
        $declared_creatable_pages[] = $spec['alias'];
      }

      $node = resolve_page_node($spec['alias']);
      if ($node) {
        $node_id = (string) $node->id();
        if (isset($seen_node_ids[$node_id])) {
          throw new RuntimeException(
            'Aliases ' . $seen_node_ids[$node_id] . ' and ' . $spec['alias']
            . ' resolve to the same node ' . $node_id . '.'
          );
        }
        $seen_node_ids[$node_id] = $spec['alias'];

        $alias_entity = alias_entity($spec['alias']);
        if (!$alias_entity) {
          throw new RuntimeException('Resolved page has no canonical alias entity.');
        }
        $current = page_entity_snapshot($node);
        $planned = planned_page_snapshot($current, $spec, $body_format);
        $changes = page_snapshot_changes($current, $planned);
        if ($changes) {
          assert_planned_page_entity_valid($node, $planned, $spec['alias']);
        }
        $page_plans[$key] = [
          'key' => $key,
          'alias' => $spec['alias'],
          'action' => $changes ? 'update' : 'none',
          'entity_id' => (int) $node->id(),
          'expected' => $current,
          'planned' => $planned,
          'changes' => $changes,
        ];
        $alias_snapshot = path_alias_snapshot($alias_entity);
        $alias_plans[$spec['alias']] = [
          'alias' => $spec['alias'],
          'action' => 'none',
          'target_page_key' => $key,
          'entity_id' => (int) $alias_entity->id(),
          'expected' => $alias_snapshot,
          'planned' => $alias_snapshot,
        ];
      }
      else {
        if (!$spec['create_if_missing']) {
          throw new RuntimeException(
            'Required existing page alias is missing; refusing to create or adopt a page by title.'
          );
        }
        $same_title_node_ids = page_node_ids_by_title($spec['title']);
        if ($same_title_node_ids) {
          throw new RuntimeException(
            'Alias is missing but title "' . $spec['title'] . '" belongs to node(s) '
            . implode(', ', $same_title_node_ids) . '; refusing a duplicate or title adoption.'
          );
        }

        $node_uuid = reserve_entity_uuid('node', $reserved_uuids);
        $alias_uuid = reserve_entity_uuid('path_alias', $reserved_uuids);
        $langcode = \Drupal::languageManager()->getDefaultLanguage()->getId();
        $planned = [
          'uuid' => $node_uuid,
          'langcode' => $langcode,
          'title' => $spec['title'],
          'published' => TRUE,
          'body' => $spec['body'],
          'format' => $body_format,
          'summary' => NULL,
        ];
        assert_planned_page_entity_valid(NULL, $planned, $spec['alias']);
        $page_plans[$key] = [
          'key' => $key,
          'alias' => $spec['alias'],
          'action' => 'create',
          'entity_id' => NULL,
          'expected' => NULL,
          'planned' => $planned,
          'changes' => [
            'title' => 'title <absent> -> "' . $spec['title'] . '"',
            'status' => 'status <absent> -> published',
            'body' => 'body <absent>; exact planned value follows',
          ],
        ];
        $alias_plans[$spec['alias']] = [
          'alias' => $spec['alias'],
          'action' => 'create',
          'target_page_key' => $key,
          'entity_id' => NULL,
          'expected' => NULL,
          'planned' => [
            'uuid' => $alias_uuid,
            'alias' => $spec['alias'],
            'langcode' => $langcode,
            'path' => NULL,
          ],
        ];
      }
    }
    catch (Throwable $throwable) {
      $blockers[] = 'page ' . ($spec['alias'] ?? (string) $key) . ': ' . $throwable->getMessage();
    }
  }

  $expected_creatable_pages = [
    '/cours-et-stages',
    '/ateliers',
    '/forum',
    '/a-propos',
    '/blog',
    '/origine',
  ];
  if ($declared_creatable_pages !== $expected_creatable_pages) {
    $blockers[] = 'page create allowlist differs from the exact six canonical pages.';
  }
  if (count($page_specs) !== 18) {
    $blockers[] = 'canonical managed page plan must contain exactly eighteen pages.';
  }

  $validation_node_id = NULL;
  foreach ($page_plans as $page_plan) {
    if ($page_plan['entity_id'] !== NULL) {
      $validation_node_id = $page_plan['entity_id'];
      break;
    }
  }
  foreach ($alias_plans as $alias_plan) {
    if ($alias_plan['action'] !== 'create') {
      continue;
    }
    if ($validation_node_id === NULL) {
      $blockers[] = 'alias ' . $alias_plan['alias']
        . ': no resolved existing page is available for read-only path validation.';
      continue;
    }
    try {
      assert_planned_alias_entity_valid(
        $alias_plan['planned'],
        $alias_plan['alias'],
        $validation_node_id
      );
    }
    catch (Throwable $throwable) {
      $blockers[] = 'alias ' . $alias_plan['alias'] . ': ' . $throwable->getMessage();
    }
  }

  foreach ($reference_aliases as $alias) {
    try {
      if (!is_string($alias) || !is_ascii_public_path($alias)) {
        throw new RuntimeException('Reference alias must be an ASCII public path.');
      }
      $node = page_node_by_alias($alias);
      if (!$node) {
        throw new RuntimeException('Required existing reference page alias is missing.');
      }
      $node_id = (string) $node->id();
      if (isset($seen_node_ids[$node_id])) {
        throw new RuntimeException(
          'Reference alias resolves to node ' . $node_id . ', already selected by '
          . $seen_node_ids[$node_id] . '.'
        );
      }
      $seen_node_ids[$node_id] = $alias;
      $alias_entity = alias_entity($alias);
      if (!$alias_entity) {
        throw new RuntimeException('Reference page has no canonical alias entity.');
      }
      $reference_plans[$alias] = [
        'alias' => $alias,
        'node' => page_entity_snapshot($node),
        'path_alias' => path_alias_snapshot($alias_entity),
      ];
    }
    catch (Throwable $throwable) {
      $blockers[] = 'reference page ' . (string) $alias . ': ' . $throwable->getMessage();
    }
  }

  if ($reference_aliases !== ['/concerts', '/djam', '/orchestre-des-reveurs', '/contact']) {
    $blockers[] = 'reference-page allowlist differs from the exact four preserved aliases.';
  }

  $menu_plans = discover_menu_change_plan(
    $menu_specs,
    $menu_paths_to_disable,
    $reserved_uuids,
    $blockers
  );

  if ($blockers) {
    foreach ($blockers as $blocker) {
      echo 'FAIL Phase A blocker: ' . $blocker . PHP_EOL;
    }
    throw new ContentArchitecturePreflightBlocked(
      count($blockers) . ' preflight blocker(s) detected; complete plan rejected.'
    );
  }

  $operations = [];
  foreach ($page_plans as $key => $page_plan) {
    if ($page_plan['action'] !== 'none') {
      $operations[] = ['type' => 'page_' . $page_plan['action'], 'key' => $key];
    }
  }
  foreach ($alias_plans as $alias => $alias_plan) {
    if ($alias_plan['action'] !== 'none') {
      $operations[] = ['type' => 'alias_' . $alias_plan['action'], 'key' => $alias];
    }
  }
  foreach ($menu_plans as $path => $menu_plan) {
    if ($menu_plan['action'] !== 'none') {
      $operations[] = ['type' => 'menu_' . $menu_plan['action'], 'key' => $path];
    }
  }

  return new ContentArchitectureChangePlan(
    $page_plans,
    $alias_plans,
    $reference_plans,
    $menu_plans,
    $operations
  );
}

function validate_page_spec(string $key, array $spec): void {
  foreach (['title', 'alias', 'body', 'create_if_missing'] as $required_key) {
    if (!array_key_exists($required_key, $spec)) {
      throw new RuntimeException('Missing page specification key ' . $required_key . '.');
    }
  }
  if ($key === '' || !is_string($spec['title']) || preg_match('//u', $spec['title']) !== 1) {
    throw new RuntimeException('Page key and UTF-8 title must be valid.');
  }
  if (!is_ascii_public_path($spec['alias'])) {
    throw new RuntimeException('Page alias is not an ASCII public path.');
  }
  if (!is_string($spec['body']) || preg_match('//u', $spec['body']) !== 1) {
    throw new RuntimeException('Page body must be valid UTF-8.');
  }
  if (!is_bool($spec['create_if_missing'])) {
    throw new RuntimeException('Page create_if_missing must be boolean.');
  }
}

function assert_planned_page_entity_valid(
  ?NodeInterface $current,
  array $planned,
  string $alias,
): void {
  $candidate = $current ? clone $current : Node::create([
    'type' => 'page',
    'uuid' => $planned['uuid'],
    'langcode' => $planned['langcode'],
  ]);
  $candidate->setTitle($planned['title']);
  if ($planned['published']) {
    $candidate->setPublished();
  }
  else {
    $candidate->setUnpublished();
  }
  $candidate->set('body', [
    'value' => $planned['body'],
    'format' => $planned['format'],
    'summary' => $planned['summary'],
  ]);
  assert_entity_constraint_valid(
    $candidate,
    'planned page ' . $alias,
    // The Drush bootstrap user has no text-format permissions. Phase A's
    // runtime guard independently proves that full_html exists and is active.
    ['body.0.format']
  );
}

function assert_planned_alias_entity_valid(
  array $planned,
  string $alias,
  int $validation_node_id,
): void {
  $candidate = \Drupal::entityTypeManager()->getStorage('path_alias')->create([
    'uuid' => $planned['uuid'],
    // The planned node's exact ID is allocated only by its transactional
    // insert. A resolved required page provides a real route for Phase A's
    // read-only constraint validation without reserving an ID.
    'path' => '/node/' . $validation_node_id,
    'alias' => $planned['alias'],
    'langcode' => $planned['langcode'],
  ]);
  assert_entity_constraint_valid($candidate, 'planned alias ' . $alias);
}

function assert_entity_constraint_valid(
  $entity,
  string $description,
  array $ignored_property_paths = [],
): void {
  $violations = $entity->validate();
  $messages = [];
  foreach ($violations as $violation) {
    $property = $violation->getPropertyPath();
    if (in_array($property, $ignored_property_paths, TRUE)) {
      continue;
    }
    $messages[] = ($property !== '' ? $property . ': ' : '') . (string) $violation->getMessage();
  }
  if (!$messages) {
    return;
  }
  throw new RuntimeException(
    'Drupal entity validation failed for ' . $description . ': ' . implode('; ', $messages) . '.'
  );
}

function reserve_entity_uuid(string $entity_type, array &$reserved_uuids): string {
  $storage = \Drupal::entityTypeManager()->getStorage($entity_type);
  for ($attempt = 0; $attempt < 10; $attempt++) {
    $uuid = \Drupal::service('uuid')->generate();
    if (isset($reserved_uuids[$uuid])) {
      continue;
    }
    if ($storage->loadByProperties(['uuid' => $uuid])) {
      continue;
    }
    $reserved_uuids[$uuid] = $entity_type;
    return $uuid;
  }
  throw new RuntimeException('Could not reserve a unique UUID for ' . $entity_type . '.');
}

function page_entity_snapshot(NodeInterface $node): array {
  $has_body = $node->hasField('body') && !$node->get('body')->isEmpty();
  return [
    'entity_id' => (int) $node->id(),
    'uuid' => (string) $node->uuid(),
    'revision_id' => (int) $node->getRevisionId(),
    'langcode' => (string) $node->language()->getId(),
    'title' => (string) $node->label(),
    'published' => (bool) $node->isPublished(),
    'body' => $has_body ? (string) $node->get('body')->value : '',
    'format' => $has_body ? (string) $node->get('body')->format : '',
    'summary' => $has_body ? $node->get('body')->summary : NULL,
  ];
}

function planned_page_snapshot(array $current, array $spec, string $body_format): array {
  return [
    'uuid' => $current['uuid'],
    'langcode' => $current['langcode'],
    'title' => $spec['title'],
    'published' => TRUE,
    'body' => $spec['body'],
    'format' => $body_format,
    'summary' => $current['summary'],
  ];
}

function page_snapshot_changes(array $current, array $planned): array {
  $changes = [];
  if ($current['title'] !== $planned['title']) {
    $changes['title'] = 'title "' . $current['title'] . '" -> "' . $planned['title'] . '"';
  }
  if (!$current['published']) {
    $changes['status'] = 'status unpublished -> published';
  }
  if ($current['body'] !== $planned['body'] || $current['format'] !== $planned['format']) {
    $changes['body'] = 'body differs; exact current and planned values follow';
  }
  return $changes;
}

function path_alias_snapshot($alias_entity): array {
  return [
    'entity_id' => (int) $alias_entity->id(),
    'uuid' => (string) $alias_entity->uuid(),
    'alias' => (string) $alias_entity->getAlias(),
    'path' => (string) $alias_entity->getPath(),
    'langcode' => (string) $alias_entity->language()->getId(),
  ];
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

function print_exact_body_block(string $label, string $body): void {
  echo $label . '_BEGIN bytes=' . strlen($body) . ' sha256=' . hash('sha256', $body) . PHP_EOL;
  echo $body;
  if ($body === '' || substr($body, -1) !== "\n") {
    echo PHP_EOL;
  }
  echo $label . '_END' . PHP_EOL;
}

function discover_menu_change_plan(
  array $specs,
  array $paths_to_disable,
  array &$reserved_uuids,
  array &$blockers,
): array {
  $inventory = main_menu_content_links();
  $plans = [];
  $declared_paths = [];
  $declared_titles = [];
  $normalized_paths = [];
  $selected_plugins = [];
  $plugin_by_path = [];
  $declared_creatable_paths = [];

  foreach ($specs as $index => $spec) {
    $path = is_string($spec['path'] ?? NULL) ? $spec['path'] : 'spec#' . $index;
    try {
      $title = $spec['title'] ?? NULL;
      $parent_path = $spec['parent_path'] ?? NULL;
      $enabled = $spec['enabled'] ?? NULL;
      $create_if_missing = $spec['create_if_missing'] ?? NULL;
      $weight = $spec['weight'] ?? NULL;

      if (!is_string($title) || $title === '' || preg_match('//u', $title) !== 1) {
        throw new RuntimeException('Canonical menu label must be non-empty UTF-8.');
      }
      if (!is_ascii_public_path($path)) {
        throw new RuntimeException('Canonical menu path is not an ASCII public path.');
      }
      if (!is_int($weight)) {
        throw new RuntimeException('Canonical menu weight must be an integer.');
      }
      if ($enabled !== TRUE) {
        throw new RuntimeException('Canonical active menu link must declare enabled TRUE.');
      }
      if (!is_bool($create_if_missing)) {
        throw new RuntimeException('Menu create_if_missing must be boolean.');
      }
      if (isset($declared_paths[$path])) {
        throw new RuntimeException('Canonical menu path is declared more than once.');
      }
      if (isset($declared_titles[$title])) {
        throw new RuntimeException('Canonical menu label is declared more than once: "' . $title . '".');
      }
      if ($parent_path !== NULL && (!is_string($parent_path) || !isset($plugin_by_path[$parent_path]))) {
        throw new RuntimeException('Parent ' . (string) $parent_path . ' is not fully planned before its child.');
      }

      if ($create_if_missing) {
        $declared_creatable_paths[] = $path;
      }
      $normalized_path = normalized_internal_path($path);
      if (isset($normalized_paths[$normalized_path])) {
        throw new RuntimeException(
          'Canonical paths ' . $normalized_paths[$normalized_path] . ' and ' . $path
          . ' normalize to the same destination ' . $normalized_path . '.'
        );
      }

      $link = find_unique_main_menu_link($path, $inventory);
      if (!$link && !$create_if_missing) {
        throw new RuntimeException('Required existing main-menu link is missing; refusing to create it.');
      }
      if ($link) {
        assert_main_menu_destination_is_canonical($link, $path);
      }
      assert_main_menu_label_available($title, $link, $inventory);

      if ($link) {
        $current = menu_link_snapshot($link, $inventory);
        $plugin_id = $current['plugin_id'];
        $uuid = $current['uuid'];
        $entity_id = $current['entity_id'];
      }
      else {
        $current = NULL;
        $uuid = reserve_entity_uuid('menu_link_content', $reserved_uuids);
        $plugin_id = 'menu_link_content:' . $uuid;
        if (\Drupal::service('plugin.manager.menu.link')->hasDefinition($plugin_id)) {
          throw new RuntimeException('Reserved menu plugin ID already exists in the menu tree: ' . $plugin_id . '.');
        }
        $entity_id = NULL;
      }

      if (isset($selected_plugins[$plugin_id])) {
        throw new RuntimeException(
          'Paths ' . $selected_plugins[$plugin_id] . ' and ' . $path
          . ' select the same menu plugin ' . $plugin_id . '.'
        );
      }

      $planned_parent_id = $parent_path === NULL ? '' : $plugin_by_path[$parent_path];
      $parent_spec = $parent_path === NULL ? NULL : menu_spec_by_path($specs, $parent_path);
      $planned_parent_description = $parent_path === NULL
        ? 'top-level'
        : 'child of "' . $parent_spec['title']
          . '" (' . $parent_path . '; ' . $planned_parent_id . ')';
      $planned = [
        'entity_id' => $entity_id,
        'uuid' => $uuid,
        'plugin_id' => $plugin_id,
        'title' => $title,
        'uri' => $current ? $current['uri'] : 'internal:' . $path,
        'weight' => $weight,
        'parent' => $planned_parent_id,
        'parent_description' => $planned_parent_description,
        'enabled' => TRUE,
        'expanded' => $current ? $current['expanded'] : FALSE,
      ];
      $action = !$current
        ? 'create'
        : (menu_link_requires_update($current, $planned) ? 'update' : 'none');
      if ($action !== 'none') {
        assert_planned_menu_entity_valid($link, $planned, $path);
      }
      $plans[$path] = [
        'kind' => 'active',
        'path' => $path,
        'parent_path' => $parent_path,
        'create_if_missing' => $create_if_missing,
        'action' => $action,
        'entity_id' => $entity_id,
        'expected' => $current,
        'planned' => $planned,
      ];

      $declared_paths[$path] = TRUE;
      $declared_titles[$title] = TRUE;
      $normalized_paths[$normalized_path] = $path;
      $selected_plugins[$plugin_id] = $path;
      $plugin_by_path[$path] = $plugin_id;
    }
    catch (Throwable $throwable) {
      $blockers[] = 'main-menu ' . $path . ': ' . $throwable->getMessage();
    }
  }

  $expected_creatable_paths = [
    '/cours-et-stages',
    '/ateliers',
    '/a-propos',
    '/forum',
    '/origine',
    '/blog',
  ];
  if ($declared_creatable_paths !== $expected_creatable_paths) {
    $blockers[] = 'menu create allowlist differs from the exact six canonical destinations.';
  }
  if (count($specs) !== 14) {
    $blockers[] = 'canonical active menu plan must contain exactly fourteen links.';
  }
  if ($paths_to_disable !== ['/services-prestations-artistiques']) {
    $blockers[] = 'menu disable allowlist must contain only /services-prestations-artistiques.';
  }

  foreach ($paths_to_disable as $path) {
    try {
      if (!is_string($path) || !is_ascii_public_path($path)) {
        throw new RuntimeException('Disabled path is not an ASCII public path.');
      }
      if (isset($declared_paths[$path])) {
        throw new RuntimeException('Path cannot be active and disabled in the same plan.');
      }
      $normalized_path = normalized_internal_path($path);
      if (isset($normalized_paths[$normalized_path])) {
        throw new RuntimeException(
          'Disabled path and active path ' . $normalized_paths[$normalized_path]
          . ' normalize to the same destination.'
        );
      }
      $link = find_unique_main_menu_link($path, $inventory);
      if (!$link) {
        throw new RuntimeException('Required existing Services link is missing; there is nothing to disable.');
      }
      assert_main_menu_destination_is_canonical($link, $path);
      $current = menu_link_snapshot($link, $inventory);
      if ($current['parent'] !== '') {
        throw new RuntimeException(
          'Required Services link is not top-level; unexpected parent '
          . $current['parent_description'] . '.'
        );
      }
      if (isset($selected_plugins[$current['plugin_id']])) {
        throw new RuntimeException(
          'Services path selects the same plugin as ' . $selected_plugins[$current['plugin_id']] . '.'
        );
      }
      $planned = $current;
      $planned['enabled'] = FALSE;
      if ($current['enabled']) {
        assert_planned_menu_entity_valid($link, $planned, $path);
      }
      $plans[$path] = [
        'kind' => 'disable',
        'path' => $path,
        'parent_path' => NULL,
        'create_if_missing' => FALSE,
        'action' => $current['enabled'] ? 'disable' : 'none',
        'entity_id' => $current['entity_id'],
        'expected' => $current,
        'planned' => $planned,
      ];
      $selected_plugins[$current['plugin_id']] = $path;
      $normalized_paths[$normalized_path] = $path;
    }
    catch (Throwable $throwable) {
      $blockers[] = 'main-menu ' . (string) $path . ': ' . $throwable->getMessage();
    }
  }

  return $plans;
}

function menu_link_snapshot(MenuLinkContent $link, array $inventory): array {
  $parent_id = (string) $link->get('parent')->value;
  assert_menu_plugin_definition_matches_entity($link);
  return [
    'entity_id' => (int) $link->id(),
    'uuid' => (string) $link->uuid(),
    'plugin_id' => menu_link_plugin_id($link),
    'title' => (string) $link->label(),
    'uri' => menu_link_uri($link),
    'weight' => (int) $link->get('weight')->value,
    'parent' => $parent_id,
    'parent_description' => menu_parent_description($parent_id, $inventory),
    'enabled' => (bool) $link->get('enabled')->value,
    'expanded' => (bool) $link->get('expanded')->value,
  ];
}

function menu_parent_description(string $parent_id, array $inventory): string {
  if ($parent_id === '') {
    return 'top-level';
  }
  foreach ($inventory as $candidate) {
    if (menu_link_plugin_id($candidate) === $parent_id) {
      return 'child of "' . $candidate->label() . '" (' . menu_link_uri($candidate) . '; ' . $parent_id . ')';
    }
  }
  return 'plugin "' . $parent_id . '"';
}

function menu_link_requires_update(array $current, array $planned): bool {
  foreach (['title', 'weight', 'parent', 'enabled'] as $field) {
    if ($current[$field] !== $planned[$field]) {
      return TRUE;
    }
  }
  return FALSE;
}

function assert_planned_menu_entity_valid(
  ?MenuLinkContent $current,
  array $planned,
  string $path,
): void {
  $candidate = $current ? clone $current : MenuLinkContent::create([
    'uuid' => $planned['uuid'],
    'menu_name' => 'main',
    'link' => ['uri' => $planned['uri']],
  ]);
  $candidate->set('title', $planned['title']);
  $candidate->set('weight', $planned['weight']);
  $candidate->set('parent', $planned['parent']);
  $candidate->set('enabled', $planned['enabled']);
  $candidate->set('expanded', $planned['expanded']);
  assert_entity_constraint_valid($candidate, 'planned main-menu link ' . $path);
}

function assert_menu_plugin_definition_matches_entity(MenuLinkContent $link): void {
  $plugin_id = menu_link_plugin_id($link);
  $definition = \Drupal::service('plugin.manager.menu.link')->getDefinition($plugin_id, FALSE);
  if (!$definition) {
    throw new RuntimeException('Menu plugin definition is missing for ' . $plugin_id . '.');
  }

  $entity_definition = $link->getPluginDefinition();
  $expected = [
    'id' => $plugin_id,
    'menu_name' => 'main',
    'title' => (string) $link->label(),
    'weight' => (int) $link->get('weight')->value,
    'parent' => (string) $link->get('parent')->value,
    'enabled' => (int) (bool) $link->get('enabled')->value,
    'expanded' => (int) (bool) $link->get('expanded')->value,
  ];
  foreach (['route_name', 'route_parameters', 'url', 'options'] as $destination_field) {
    $expected[$destination_field] = $entity_definition[$destination_field] ?? NULL;
  }
  foreach ($expected as $field => $value) {
    $definition_value = $definition[$field] ?? NULL;
    if ($field === 'weight') {
      $definition_value = (int) $definition_value;
    }
    elseif ($field === 'enabled' || $field === 'expanded') {
      $definition_value = (int) (bool) $definition_value;
    }
    elseif ($field === 'url' && $definition_value === '') {
      $definition_value = NULL;
    }
    if ($definition_value !== $value) {
      throw new RuntimeException(
        'Menu plugin definition ' . $plugin_id . ' has stale ' . $field . ' state.'
      );
    }
  }
}

function print_change_plan(ContentArchitectureChangePlan $plan, string $mutation_prefix): void {
  $plan->assertIntegrity();
  section('Complete immutable change plan');

  foreach ($plan->references as $reference) {
    echo 'OK alias ' . $reference['alias'] . ' -> ' . $reference['path_alias']['path']
      . ' (reference page preserved; body unchanged)' . PHP_EOL;
  }

  foreach ($plan->pages as $page) {
    if ($page['action'] === 'create') {
      echo $mutation_prefix . '_CREATE page ' . $page['alias'] . ' title "'
        . $page['planned']['title'] . '" uuid=' . $page['planned']['uuid'] . PHP_EOL;
      print_exact_planned_body_change($page);
    }
    elseif ($page['action'] === 'update') {
      echo $mutation_prefix . '_UPDATE page ' . $page['alias'] . ' node '
        . $page['entity_id'] . ': ' . implode('; ', $page['changes']) . PHP_EOL;
      if (isset($page['changes']['body'])) {
        print_exact_planned_body_change($page);
      }
    }
    else {
      echo 'OK page ' . $page['alias'] . ' node ' . $page['entity_id'] . ' already matches' . PHP_EOL;
    }
  }

  foreach ($plan->aliases as $alias) {
    if ($alias['action'] === 'create') {
      echo $mutation_prefix . '_CREATE alias ' . $alias['alias']
        . ' after planned page creation uuid=' . $alias['planned']['uuid'] . PHP_EOL;
    }
    else {
      echo 'OK alias ' . $alias['alias'] . ' -> ' . $alias['planned']['path'] . PHP_EOL;
    }
  }

  foreach ($plan->menuLinks as $menu_link) {
    $path = $menu_link['path'];
    $planned_state = menu_snapshot_state($menu_link['planned']);
    if ($menu_link['action'] === 'create') {
      echo $mutation_prefix . '_CREATE main menu link ' . $path . ': planned ' . $planned_state . PHP_EOL;
    }
    elseif ($menu_link['action'] === 'update') {
      echo $mutation_prefix . '_UPDATE main menu link ' . $path . ': current '
        . menu_snapshot_state($menu_link['expected']) . '; planned ' . $planned_state . PHP_EOL;
    }
    elseif ($menu_link['action'] === 'disable') {
      echo $mutation_prefix . '_DISABLE main menu link ' . $path . ': current '
        . menu_snapshot_state($menu_link['expected']) . '; planned ' . $planned_state
        . '; retained, not deleted' . PHP_EOL;
    }
    elseif ($menu_link['kind'] === 'disable') {
      echo 'OK disabled main menu link ' . $path . ': ' . $planned_state
        . '; retained, not deleted' . PHP_EOL;
    }
    else {
      echo 'OK main menu link ' . $path . ': ' . $planned_state . PHP_EOL;
    }
  }
}

function print_exact_planned_body_change(array $page): void {
  echo 'BODY_CHANGE_EXACT alias=' . $page['alias'];
  echo $page['expected'] ? ' node=' . $page['entity_id'] . PHP_EOL : ' node=NEW' . PHP_EOL;
  if ($page['expected']) {
    echo 'CURRENT_FORMAT ' . $page['expected']['format'] . PHP_EOL;
    print_exact_body_block('CURRENT_BODY', $page['expected']['body']);
  }
  else {
    echo 'CURRENT_FORMAT <absent>' . PHP_EOL;
    echo 'CURRENT_BODY <absent>' . PHP_EOL;
  }
  echo 'PLANNED_FORMAT ' . $page['planned']['format'] . PHP_EOL;
  print_exact_body_block('PLANNED_BODY', $page['planned']['body']);
  echo 'END_BODY_CHANGE_EXACT alias=' . $page['alias'] . PHP_EOL;
}

function menu_snapshot_state(array $snapshot): string {
  return menu_link_state(
    $snapshot['title'],
    $snapshot['weight'],
    $snapshot['parent_description'],
    $snapshot['enabled']
  );
}

function apply_change_plan(ContentArchitectureChangePlan $plan): array {
  $plan->assertIntegrity();
  $connection = \Drupal::database();
  if ($connection->inTransaction()) {
    throw new ContentArchitectureApplyRolledBack(
      'Refusing to run inside an existing database transaction; writes=0.',
      TRUE
    );
  }
  try {
    $transaction = $connection->startTransaction('unisonges_content_architecture_2026');
    if (!$connection->inTransaction()) {
      throw new RuntimeException('Database connection did not enter a transaction.');
    }
  }
  catch (Throwable $throwable) {
    throw new ContentArchitectureApplyRolledBack(
      'Transaction could not start; writes=0. ' . $throwable->getMessage(),
      TRUE,
      $throwable
    );
  }

  try {
    assert_change_plan_still_current($plan);
    [$messages, $runtime_ids] = execute_change_plan($plan);
    verify_applied_change_plan($plan, $runtime_ids);
    $messages[] = 'OK Phase B post-apply verification passed inside the transaction.';
    reset_content_entity_memory_cache();
  }
  catch (Throwable $throwable) {
    $rollback_failure = NULL;
    try {
      $transaction->rollBack();
    }
    catch (Throwable $caught_rollback_failure) {
      $rollback_failure = $caught_rollback_failure;
    }
    unset($transaction);
    reset_content_entity_memory_cache();
    $rollback_confirmed = $rollback_failure === NULL && !$connection->inTransaction();
    if (!$rollback_confirmed) {
      throw new ContentArchitectureApplyRolledBack(
        'Atomic apply failed and rollback could not be confirmed. Cause: '
        . $throwable->getMessage()
        . ($rollback_failure ? '. Rollback failure: ' . $rollback_failure->getMessage() : ''),
        FALSE,
        $throwable
      );
    }
    throw new ContentArchitectureApplyRolledBack(
      'Atomic apply failed; transaction rollback confirmed. Cause: '
      . $throwable->getMessage() . '.',
      TRUE,
      $throwable
    );
  }

  try {
    $transaction->commitOrRelease();
    unset($transaction);
  }
  catch (Throwable $throwable) {
    $rollback_failure = NULL;
    $rollback_confirmed = FALSE;
    if ($connection->inTransaction()) {
      try {
        $transaction->rollBack();
        $rollback_confirmed = !$connection->inTransaction();
      }
      catch (Throwable $caught_rollback_failure) {
        $rollback_failure = $caught_rollback_failure;
      }
    }
    unset($transaction);
    reset_content_entity_memory_cache();
    throw new ContentArchitectureApplyRolledBack(
      $rollback_confirmed
        ? 'Transaction finalization failed before commit; rollback confirmed. Cause: '
          . $throwable->getMessage()
        : 'Transaction finalization failed; commit state is unknown. Cause: '
          . $throwable->getMessage()
          . ($rollback_failure ? '. Rollback failure: ' . $rollback_failure->getMessage() : ''),
      $rollback_confirmed,
      $throwable
    );
  }

  return $messages;
}

function assert_change_plan_still_current(ContentArchitectureChangePlan $plan): void {
  $plan->assertIntegrity();
  reset_content_entity_memory_cache();

  foreach ($plan->pages as $page) {
    if ($page['expected']) {
      $node = Node::load($page['entity_id']);
      if (!$node instanceof NodeInterface || page_entity_snapshot($node) !== $page['expected']) {
        throw new RuntimeException('Page drift detected before first write for ' . $page['alias'] . '.');
      }
    }
    else {
      if (alias_entity($page['alias'])) {
        throw new RuntimeException('New page alias appeared after Phase A: ' . $page['alias'] . '.');
      }
      if (page_node_ids_by_title($page['planned']['title'])) {
        throw new RuntimeException('New page title appeared after Phase A: ' . $page['planned']['title'] . '.');
      }
      if (\Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $page['planned']['uuid']])) {
        throw new RuntimeException('Reserved node UUID is no longer available for ' . $page['alias'] . '.');
      }
    }
  }

  foreach ($plan->aliases as $alias) {
    $current = alias_entity($alias['alias']);
    if ($alias['expected']) {
      if (!$current || path_alias_snapshot($current) !== $alias['expected']) {
        throw new RuntimeException('Alias drift detected before first write for ' . $alias['alias'] . '.');
      }
    }
    elseif ($current) {
      throw new RuntimeException('Planned new alias appeared after Phase A: ' . $alias['alias'] . '.');
    }
    elseif (\Drupal::entityTypeManager()->getStorage('path_alias')->loadByProperties([
      'uuid' => $alias['planned']['uuid'],
    ])) {
      throw new RuntimeException('Reserved alias UUID is no longer available for ' . $alias['alias'] . '.');
    }
  }

  foreach ($plan->references as $reference) {
    $node = Node::load($reference['node']['entity_id']);
    $alias = alias_entity($reference['alias']);
    if (!$node instanceof NodeInterface || page_entity_snapshot($node) !== $reference['node']) {
      throw new RuntimeException('Reference page drift detected before first write for ' . $reference['alias'] . '.');
    }
    if (!$alias || path_alias_snapshot($alias) !== $reference['path_alias']) {
      throw new RuntimeException('Reference alias drift detected before first write for ' . $reference['alias'] . '.');
    }
  }

  $inventory = main_menu_content_links();
  foreach ($plan->menuLinks as $menu_link) {
    $current = find_unique_main_menu_link($menu_link['path'], $inventory);
    if ($menu_link['expected']) {
      if (!$current || menu_link_snapshot($current, $inventory) !== $menu_link['expected']) {
        throw new RuntimeException('Menu-link drift detected before first write for ' . $menu_link['path'] . '.');
      }
    }
    else {
      if ($current) {
        throw new RuntimeException('Planned new menu destination appeared after Phase A: ' . $menu_link['path'] . '.');
      }
      if (\Drupal::entityTypeManager()->getStorage('menu_link_content')->loadByProperties([
        'uuid' => $menu_link['planned']['uuid'],
      ])) {
        throw new RuntimeException('Reserved menu UUID is no longer available for ' . $menu_link['path'] . '.');
      }
      if (\Drupal::service('plugin.manager.menu.link')->hasDefinition($menu_link['planned']['plugin_id'])) {
        throw new RuntimeException(
          'Reserved menu plugin ID appeared in the menu tree for ' . $menu_link['path'] . '.'
        );
      }
    }
    if ($menu_link['kind'] === 'active') {
      assert_main_menu_label_available($menu_link['planned']['title'], $current, $inventory);
    }
  }

  $plugins_by_path = [];
  foreach ($plan->menuLinks as $path => $menu_link) {
    if ($menu_link['kind'] === 'active') {
      $plugins_by_path[$path] = $menu_link['planned']['plugin_id'];
    }
  }
  foreach ($plan->menuLinks as $menu_link) {
    if ($menu_link['kind'] !== 'active') {
      continue;
    }
    $expected_parent = $menu_link['parent_path'] === NULL
      ? ''
      : ($plugins_by_path[$menu_link['parent_path']] ?? NULL);
    if (!is_string($expected_parent) || $menu_link['planned']['parent'] !== $expected_parent) {
      throw new RuntimeException('Unresolved planned parent dependency for ' . $menu_link['path'] . '.');
    }
  }

  echo 'OK Phase B concurrency guard: complete plan still matches; writes=0.' . PHP_EOL;
}

function execute_change_plan(ContentArchitectureChangePlan $plan): array {
  $messages = [];
  $runtime_ids = [
    'pages' => [],
    'aliases' => [],
    'menu_links' => [],
  ];
  $available_menu_plugins = [];

  foreach ($plan->pages as $key => $page) {
    if ($page['entity_id'] !== NULL) {
      $runtime_ids['pages'][$key] = $page['entity_id'];
    }
  }
  foreach ($plan->aliases as $alias => $alias_plan) {
    if ($alias_plan['entity_id'] !== NULL) {
      $runtime_ids['aliases'][$alias] = $alias_plan['entity_id'];
    }
  }
  foreach ($plan->menuLinks as $path => $menu_link) {
    if ($menu_link['entity_id'] !== NULL) {
      $runtime_ids['menu_links'][$path] = $menu_link['entity_id'];
      $available_menu_plugins[$menu_link['planned']['plugin_id']] = TRUE;
    }
  }

  foreach ($plan->operations as $operation) {
    $type = $operation['type'];
    $key = $operation['key'];
    if ($type === 'page_create') {
      $page = $plan->pages[$key];
      $node = Node::create([
        'type' => 'page',
        'uuid' => $page['planned']['uuid'],
        'langcode' => $page['planned']['langcode'],
        'title' => $page['planned']['title'],
        'status' => NodeInterface::PUBLISHED,
        'body' => [
          'value' => $page['planned']['body'],
          'format' => $page['planned']['format'],
          'summary' => $page['planned']['summary'],
        ],
      ]);
      assert_entity_save_result($node->save(), SAVED_NEW, 'page ' . $page['alias']);
      $runtime_ids['pages'][$key] = (int) $node->id();
      $messages[] = 'CREATED page ' . $page['alias'] . ' as node ' . $node->id();
    }
    elseif ($type === 'page_update') {
      $page = $plan->pages[$key];
      $node = Node::load($page['entity_id']);
      if (!$node instanceof NodeInterface) {
        throw new RuntimeException('Planned page disappeared during apply: ' . $page['alias'] . '.');
      }
      set_planned_page_values($node, $page['planned'], $page['changes']);
      assert_entity_save_result($node->save(), SAVED_UPDATED, 'page ' . $page['alias']);
      $messages[] = 'UPDATED page ' . $page['alias'] . ' node ' . $node->id()
        . ': ' . implode('; ', $page['changes']);
    }
    elseif ($type === 'alias_create') {
      $alias = $plan->aliases[$key];
      $page_id = $runtime_ids['pages'][$alias['target_page_key']] ?? NULL;
      if (!is_int($page_id)) {
        throw new RuntimeException('Planned alias has no resolved page ID: ' . $alias['alias'] . '.');
      }
      $alias_entity = \Drupal::entityTypeManager()->getStorage('path_alias')->create([
        'uuid' => $alias['planned']['uuid'],
        'path' => '/node/' . $page_id,
        'alias' => $alias['planned']['alias'],
        'langcode' => $alias['planned']['langcode'],
      ]);
      assert_entity_constraint_valid($alias_entity, 'final planned alias ' . $alias['alias']);
      assert_entity_save_result(
        $alias_entity->save(),
        SAVED_NEW,
        'alias ' . $alias['alias']
      );
      $runtime_ids['aliases'][$key] = (int) $alias_entity->id();
      $messages[] = 'CREATED alias ' . $alias['alias'] . ' -> /node/' . $page_id;
    }
    elseif (strpos($type, 'menu_') === 0) {
      $menu_link = $plan->menuLinks[$key];
      $parent_plugin = $menu_link['planned']['parent'];
      if ($parent_plugin !== '' && !isset($available_menu_plugins[$parent_plugin])) {
        throw new RuntimeException('Planned parent plugin is unavailable for ' . $menu_link['path'] . '.');
      }
      if (
        $parent_plugin !== ''
        && !\Drupal::service('plugin.manager.menu.link')->hasDefinition($parent_plugin)
      ) {
        throw new RuntimeException('Planned parent plugin is unresolved in the menu tree for ' . $menu_link['path'] . '.');
      }

      if ($type === 'menu_create') {
        $link = MenuLinkContent::create([
          'uuid' => $menu_link['planned']['uuid'],
          'title' => $menu_link['planned']['title'],
          'menu_name' => 'main',
          'link' => ['uri' => $menu_link['planned']['uri']],
          'enabled' => $menu_link['planned']['enabled'],
          'expanded' => $menu_link['planned']['expanded'],
          'weight' => $menu_link['planned']['weight'],
          'parent' => $parent_plugin,
        ]);
        if (menu_link_plugin_id($link) !== $menu_link['planned']['plugin_id']) {
          throw new RuntimeException('Preallocated menu plugin ID mismatch for ' . $menu_link['path'] . '.');
        }
        assert_entity_save_result(
          $link->save(),
          SAVED_NEW,
          'main-menu link ' . $menu_link['path']
        );
        assert_menu_plugin_definition_matches_entity($link);
        $runtime_ids['menu_links'][$key] = (int) $link->id();
        $available_menu_plugins[$menu_link['planned']['plugin_id']] = TRUE;
        $messages[] = 'CREATED main menu link ' . $menu_link['path'] . ': planned '
          . menu_snapshot_state($menu_link['planned']);
      }
      elseif ($type === 'menu_update' || $type === 'menu_disable') {
        $link = MenuLinkContent::load($menu_link['entity_id']);
        if (!$link instanceof MenuLinkContent) {
          throw new RuntimeException('Planned main-menu link disappeared: ' . $menu_link['path'] . '.');
        }
        $link->set('title', $menu_link['planned']['title']);
        $link->set('weight', $menu_link['planned']['weight']);
        $link->set('parent', $parent_plugin);
        $link->set('enabled', $menu_link['planned']['enabled']);
        assert_entity_save_result(
          $link->save(),
          SAVED_UPDATED,
          'main-menu link ' . $menu_link['path']
        );
        assert_menu_plugin_definition_matches_entity($link);
        $verb = $type === 'menu_disable' ? 'DISABLED' : 'UPDATED';
        $messages[] = $verb . ' main menu link ' . $menu_link['path'] . ': current '
          . menu_snapshot_state($menu_link['expected']) . '; planned '
          . menu_snapshot_state($menu_link['planned'])
          . ($type === 'menu_disable' ? '; retained, not deleted' : '');
      }
      else {
        throw new RuntimeException('Unknown planned menu operation ' . $type . '.');
      }
    }
    else {
      throw new RuntimeException('Unknown planned operation ' . $type . '.');
    }
  }

  return [$messages, $runtime_ids];
}

function set_planned_page_values(NodeInterface $node, array $planned, array $changes): void {
  $node->setTitle($planned['title']);
  if (!$planned['published']) {
    throw new RuntimeException('Managed page plan must keep every page published.');
  }
  $node->setPublished();
  $node->set('body', [
    'value' => $planned['body'],
    'format' => $planned['format'],
    'summary' => $planned['summary'],
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
}

function assert_entity_save_result(int $actual, int $expected, string $description): void {
  if ($actual !== $expected) {
    throw new RuntimeException(
      'Unexpected save result for ' . $description . ': expected ' . $expected . ', got ' . $actual . '.'
    );
  }
}

function verify_applied_change_plan(ContentArchitectureChangePlan $plan, array $runtime_ids): void {
  reset_content_entity_memory_cache();

  foreach ($plan->pages as $key => $page) {
    $node_id = $runtime_ids['pages'][$key] ?? NULL;
    $node = is_int($node_id) ? Node::load($node_id) : NULL;
    if (!$node instanceof NodeInterface || page_final_snapshot($node) !== $page['planned']) {
      throw new RuntimeException('Post-apply page verification failed for ' . $page['alias'] . '.');
    }
  }

  foreach ($plan->aliases as $alias_plan) {
    $alias_entity = alias_entity($alias_plan['alias']);
    $page_id = $runtime_ids['pages'][$alias_plan['target_page_key']] ?? NULL;
    if (!$alias_entity || !is_int($page_id)) {
      throw new RuntimeException('Post-apply alias verification failed for ' . $alias_plan['alias'] . '.');
    }
    $snapshot = path_alias_snapshot($alias_entity);
    $planned = $alias_plan['planned'];
    if (
      $snapshot['uuid'] !== $planned['uuid']
      || $snapshot['alias'] !== $planned['alias']
      || $snapshot['langcode'] !== $planned['langcode']
      || $snapshot['path'] !== '/node/' . $page_id
    ) {
      throw new RuntimeException('Post-apply alias state differs for ' . $alias_plan['alias'] . '.');
    }
  }

  foreach ($plan->references as $reference) {
    $node = Node::load($reference['node']['entity_id']);
    $alias = alias_entity($reference['alias']);
    if (!$node instanceof NodeInterface || page_entity_snapshot($node) !== $reference['node']) {
      throw new RuntimeException('Reference page changed during apply: ' . $reference['alias'] . '.');
    }
    if (!$alias || path_alias_snapshot($alias) !== $reference['path_alias']) {
      throw new RuntimeException('Reference alias changed during apply: ' . $reference['alias'] . '.');
    }
  }

  $inventory = main_menu_content_links();
  foreach ($plan->menuLinks as $menu_link) {
    $link = find_unique_main_menu_link($menu_link['path'], $inventory);
    if (!$link) {
      throw new RuntimeException('Post-apply menu link is missing: ' . $menu_link['path'] . '.');
    }
    $snapshot = menu_link_snapshot($link, $inventory);
    foreach (['uuid', 'plugin_id', 'title', 'uri', 'weight', 'parent', 'enabled', 'expanded'] as $field) {
      if ($snapshot[$field] !== $menu_link['planned'][$field]) {
        throw new RuntimeException(
          'Post-apply menu field ' . $field . ' differs for ' . $menu_link['path'] . '.'
        );
      }
    }
  }
}

function page_final_snapshot(NodeInterface $node): array {
  $snapshot = page_entity_snapshot($node);
  unset($snapshot['entity_id'], $snapshot['revision_id']);
  return $snapshot;
}

function reset_content_entity_memory_cache(): void {
  \Drupal::service('entity.memory_cache')->deleteAll();
}

function menu_spec_by_path(array $specs, string $path): array {
  foreach ($specs as $spec) {
    if (($spec['path'] ?? NULL) === $path) {
      return $spec;
    }
  }
  throw new RuntimeException('No canonical menu specification exists for parent ' . $path . '.');
}

function find_unique_main_menu_link(string $path, ?array $inventory = NULL): ?MenuLinkContent {
  $expected_path = normalized_internal_path($path);
  $matching_links = [];

  foreach ($inventory ?? main_menu_content_links() as $candidate) {
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

function assert_main_menu_label_available(
  string $title,
  ?MenuLinkContent $selected,
  ?array $inventory = NULL,
): void {
  $selected_plugin_id = $selected ? menu_link_plugin_id($selected) : NULL;
  foreach ($inventory ?? main_menu_content_links() as $candidate) {
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
