# Navigation principale à sous-menus accessibles — 2026

## Statut et objectif

Cette PR ajoute une couche de sous-menus accessible au menu principal Drupal.
Elle reste en brouillon jusqu'à la validation DDEV et navigateur qui suivra la
livraison de l'environnement appartenant à la PR #74.

Le contenu du menu reste entièrement géré dans Drupal. Aucun libellé, chemin ou
ordre de lien public n'est recopié dans Twig ou JavaScript. Le code parcourt les
listes imbriquées réellement rendues par le bloc `system_menu_block:main`.

La hiérarchie finale attendue, livrée par une PR parallèle, sert uniquement de
jeu de validation fonctionnelle :

- Cours & Stages
  - Cours particuliers
  - Stages
- Concerts & Événements
- Ateliers
  - D’Jam
  - Orchestre
- À propos
  - L’Asso
  - Partenaires
  - Origine
- Contact

Les caractères `&`, `É`, `À` et `’` doivent rester identiques à la source
Drupal. Le contrôleur ne transforme pas les libellés en identifiants et
n'applique aucune conversion ASCII ou mise en capitales.

## Périmètre exact

Fichiers de production concernés :

- `templates/partials/site-header.html.twig` ;
- `css/navigation-submenus.css` ;
- `js/navigation-submenus.js` ;
- `unisonges_theme.libraries.yml`.

Ce document est le cinquième fichier de la PR. La PR ne modifie ni
`css/styles.css`, propriété de la PR #67, ni `unisonges_theme.theme`, propriété
de la PR #74. Elle ne change aucune donnée/configuration de menu, URL publique,
logique métier, configuration DDEV ou base de données.

## Architecture retenue

Le bloc de région `page.primary_menu` est rendu une seule fois côté serveur,
dans le conteneur desktop. Cela supprime les deux copies actuelles des IDs
`block-unisonges-theme-main-menu` et
`block-unisonges-theme-main-menu-menu`, ainsi que les landmarks `<nav>`
imbriqués ajoutés par les anciens conteneurs Twig.

Au chargement, avant toute augmentation, JavaScript clone uniquement la liste
racine fournie par Drupal vers le landmark mobile du drawer. Le bloc Drupal,
son titre masqué et leurs IDs ne sont pas clonés. Tout ID éventuellement
présent dans la liste clonée est remplacé par un ID numérique propre à la copie
mobile ; les attributs ARIA qui le référencent sont réécrits avant insertion
dans le DOM. Les IDs des sous-menus sont également générés par portée et
compteur, jamais à partir d'un libellé.

La liste desktop reste en place pour que `auto-compact-nav.js` mesure toujours
sa largeur réelle. Déplacer une liste unique dans le drawer fausserait la
mesure synchrone effectuée lors du retour au mode desktop. La bibliothèque
charge donc le contrôleur de sous-menus avant le calcul de compactage : les
boutons de divulgation font partie de la largeur mesurée dès le premier calcul.

Pour chaque `<li>` ayant une liste enfant, le contrôleur :

1. conserve le lien Drupal, son `href`, son texte UTF-8 et son état actif ;
2. retire du lien les attributs de contrôle dropdown ajoutés par Barrio ;
3. ajoute à côté un bouton natif avec `aria-expanded` et `aria-controls` ;
4. synchronise l'état du bouton, l'attribut `hidden` de la liste et la classe
   visuelle par une seule fonction d'état.

Aucun rôle `menu`/`menuitem`, tabindex itinérant ou interception de Tab n'est
ajouté : il s'agit du pattern navigation + disclosure, pas d'un menu
d'application.

## Contrat fonctionnel

### Desktop

- Le libellé parent reste un lien normal vers la page hub ; seul le bouton
  adjacent ouvre ou ferme explicitement la liste enfant.
- Entrée, Espace et clic activent le bouton natif. Le survol est un complément,
  jamais le seul chemin d'ouverture.
- Le focus sur un parent ou dans ses descendants ouvre et maintient la branche.
  Un changement de focus à l'intérieur de la branche ne la ferme pas.
- Échap ferme la branche pertinente. Depuis un lien enfant, le focus revient
  au bouton qui la contrôle.
- Un clic extérieur et la sortie simultanée du pointeur et du focus ferment la
  branche. Une seule branche sœur reste ouverte.
- Les libellés de premier niveau restent sur une ligne. Les panneaux enfants
  utilisent une position fixe bornée au viewport pour ne pas modifier la
  largeur mesurée de la liste ni créer de débordement horizontal. Leur texte
  peut revenir à la ligne.

### Mobile / navigation compacte

- La copie mobile est placée dans le drawer existant et suit son ordre de
  focus : fermeture, navigation, puis liens de compte.
- Les sous-menus sont des accordéons dans le flux normal. Le drawer reste le
  seul conteneur de défilement vertical ; aucun scroll imbriqué n'est ajouté.
- Le lien parent navigue vers son hub et le bouton adjacent contrôle seulement
  ses enfants. Ouvrir une branche ferme ses sœurs.
- Un premier Échap depuis une branche ouverte ferme cette branche et reste
  dans le drawer. Sans branche ouverte, l'événement atteint le gestionnaire
  existant et ferme le drawer.
- La fermeture du drawer, un clic sur le backdrop ou un changement de mode
  réinitialise les accordéons. Aucun piège à focus supplémentaire n'est ajouté.

## Vérifications statiques de cette phase

Cette phase n'utilise ni DDEV, ni Drush, ni base locale. Les commandes à lancer
depuis la racine du worktree avant commit sont :

```bash
git diff --check release/prod --

node --check drupal/web/themes/custom/unisonges_theme/js/mobile-drawer.js
node --check drupal/web/themes/custom/unisonges_theme/js/navigation-submenus.js
node --check drupal/web/themes/custom/unisonges_theme/js/auto-compact-nav.js
```

Contrôle des accolades et commentaires CSS :

```bash
navigation_css=drupal/web/themes/custom/unisonges_theme/css/navigation-submenus.css
awk '
  {
    braces_open += gsub(/\{/, "&")
    braces_close += gsub(/\}/, "&")
    comments_open += gsub(/\/\*/, "&")
    comments_close += gsub(/\*\//, "&")
  }
  END {
    printf "braces=%d/%d comments=%d/%d\n", \
      braces_open, braces_close, comments_open, comments_close
    exit braces_open != braces_close || comments_open != comments_close
  }
' "$navigation_css"
```

Le worktree ne contient pas encore `vendor/`. La syntaxe Twig et le YAML de la
bibliothèque sont validés en lecture seule avec les dépendances du checkout
DDEV voisin uniquement après confirmation que les deux `composer.lock` sont
identiques. Cette validation ne démarre pas DDEV et ne bootstrappe pas Drupal.

Garde exacte des fichiers :

```bash
nav_expected_files="$(printf '%s\n' \
  docs/functional/navigation-submenus-2026.md \
  drupal/web/themes/custom/unisonges_theme/css/navigation-submenus.css \
  drupal/web/themes/custom/unisonges_theme/js/navigation-submenus.js \
  drupal/web/themes/custom/unisonges_theme/templates/partials/site-header.html.twig \
  drupal/web/themes/custom/unisonges_theme/unisonges_theme.libraries.yml |
  LC_ALL=C sort)"

nav_changed_files="$({
  git diff --no-renames --name-only --diff-filter=ACMRDTUXB release/prod --
  git ls-files --others --exclude-standard
} | LC_ALL=C sort -u)"

test "$nav_changed_files" = "$nav_expected_files"

git diff --exit-code release/prod -- \
  drupal/web/themes/custom/unisonges_theme/css/styles.css \
  drupal/web/themes/custom/unisonges_theme/unisonges_theme.theme
```

Résultats du 30 août 2026 sur la base `release/prod` `ddff8cc` :

- syntaxe des trois scripts et contrôle ESLint ciblé
  `no-undef`/`no-unused-vars` : OK ;
- format Prettier des nouveaux JS, CSS et document, ainsi que du YAML modifié :
  OK ;
- CSS : 37 accolades ouvrantes/fermantes et aucun commentaire non apparié ;
- parsing Symfony YAML de la bibliothèque et compilation Twig du partial : OK,
  après comparaison exacte des deux `composer.lock` ;
- fixture DOM temporaire JSDOM reprenant le markup Barrio, la hiérarchie finale
  et ses caractères spéciaux : OK pour le clonage, l'unicité des IDs, les
  relations ARIA, la conservation des `href`, les branches sœurs, le focus,
  Échap, le clic extérieur et la réinitialisation du drawer ;
- revue statique accessibilité indépendante : aucun blocage restant ;
- garde des cinq fichiers et absence de diff sur les deux fichiers réservés :
  OK.

La fixture Node ne remplace pas un navigateur avec calcul de layout, lecteur
d'écran ou entrée tactile. DDEV, Drush et la base locale n'ont pas été lancés.

## Matrice DDEV / navigateur différée

Préconditions après livraison de la PR #74 :

1. rebaser cette branche sur la version alors courante de `release/prod` ;
2. confirmer que la hiérarchie finale ci-dessus est active dans le menu
   principal et que le bloc continue d'exposer les enfants ;
3. depuis `drupal/`, démarrer l'environnement approuvé avec `ddev start`, puis
   vider les caches avec `ddev drush cr` ;
4. ne pas importer globalement `config/sync` et ne pas réécrire les liens du
   menu pour les besoins du test.

Les cases restent volontairement non cochées : aucune réussite navigateur ou
DDEV n'est revendiquée dans la phase statique actuelle.

| ID      | Vue / interaction                                        | Validation attendue                                                                                                                    | Statut |
| ------- | -------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| NAV-D01 | Desktop 1440×900 et 1280×800                             | Les cinq libellés finaux restent sur une ligne ; chaque parent et enfant affiche exactement le texte UTF-8 fourni par Drupal.          | [ ]    |
| NAV-D02 | Réduire la largeur pixel par pixel autour du seuil       | Le body passe en `compact-nav` avant collision avec le logo ou les actions ; aucun clignotement ni aller-retour de mode.               | [ ]    |
| NAV-D03 | Zoom navigateur 200 %                                    | Le compactage intervient avant chevauchement ; aucun scroll horizontal de page.                                                        | [ ]    |
| NAV-D04 | Souris sur chacun des trois parents                      | Le survol ouvre ; quitter ferme sauf si le focus reste dans la branche ; le bouton fonctionne indépendamment.                          | [ ]    |
| NAV-D05 | Clavier seul : Tab, Maj+Tab, Entrée, Espace              | Le lien parent reste activable ; le bouton annonce son nom, son état et sa relation ; tous les enfants sont atteignables dans l'ordre. | [ ]    |
| NAV-D06 | Focus parent → bouton → plusieurs enfants                | La branche ne se replie pas pendant les déplacements internes et `aria-expanded="true"` reste exact.                                   | [ ]    |
| NAV-D07 | Échap depuis chaque enfant                               | La branche se ferme, `hidden` et `aria-expanded` repassent à l'état fermé et le focus revient au bon bouton.                           | [ ]    |
| NAV-D08 | Clic extérieur puis réouverture                          | Toutes les branches se ferment sans empêcher l'action cliquée ; une seule branche sœur peut rester ouverte.                            | [ ]    |
| NAV-D09 | Panneau du parent le plus proche du bord droit           | Le panneau reste entièrement dans le viewport, son texte revient à la ligne et la page ne déborde pas horizontalement.                 | [ ]    |
| NAV-M01 | 320×568, 375×667 et 390×844, tactile                     | Le drawer s'ouvre ; liens parents, boutons et liens enfants ont des cibles distinctes et utilisables.                                  | [ ]    |
| NAV-M02 | Ouvrir successivement Cours & Stages, Ateliers, À propos | L'accordéon ouvert ferme sa sœur ; son lien parent continue de naviguer vers le hub.                                                   | [ ]    |
| NAV-M03 | Clavier dans le drawer                                   | Ordre fermeture → menu → compte cohérent ; Tab et Maj+Tab ne sont pas interceptés et aucun nouveau piège à focus n'apparaît.           | [ ]    |
| NAV-M04 | Premier puis second Échap                                | Le premier ferme la branche avec retour au bouton ; le second ferme le drawer via le gestionnaire existant.                            | [ ]    |
| NAV-M05 | Backdrop, bouton fermer et burger                        | Chaque fermeture réinitialise les accordéons ; la réouverture commence avec `aria-expanded="false"`.                                   | [ ]    |
| NAV-M06 | Paysage court 667×375                                    | Le drawer reste le seul scroller ; les derniers liens enfants et les liens de compte restent atteignables.                             | [ ]    |
| NAV-M07 | 200 % et texte agrandi                                   | Les enfants reviennent proprement à la ligne, les toggles restent visibles et aucun débordement horizontal n'apparaît.                 | [ ]    |
| NAV-R01 | Redimensionner avec une branche ouverte                  | Le changement desktop/compact ferme proprement l'état précédent, sans oscillation du seuil ni focus bloqué dans une copie cachée.      | [ ]    |
| NAV-R02 | Navigation réelle sur les 12 destinations finales        | Chaque `href` correspond à la valeur Drupal ; aucun clic de lien parent n'est intercepté par le contrôleur.                            | [ ]    |
| NAV-R03 | NVDA/Firefox et VoiceOver/Safari                         | Landmark, lien parent, bouton de divulgation, état développé/réduit et liens enfants sont annoncés sans rôle menu d'application.       | [ ]    |

Contrôles à exécuter dans la console sur une page avec la hiérarchie finale :

```js
const ids = [...document.querySelectorAll("[id]")].map(({ id }) => id);
console.assert(ids.length === new Set(ids).size, "IDs dupliqués");

document.querySelectorAll(".navigation-submenus__toggle").forEach((toggle) => {
  const id = toggle.getAttribute("aria-controls");
  const target = document.getElementById(id);
  console.assert(target, `Cible absente : ${id}`);
  console.assert(
    toggle.closest("nav, [data-navigation-submenus]").contains(target),
  );
});

console.assert(
  document.documentElement.scrollWidth <= document.documentElement.clientWidth,
  "Débordement horizontal",
);
```

## Repli

Le repli consiste à rétablir le partial et la déclaration de bibliothèque puis
à supprimer les deux nouveaux assets. Il n'exige aucune restauration de base,
de contenu, de configuration de menu, d'URL ou de routage, puisque cette PR
n'en modifie aucun.
