# Navigation principale à sous-menus accessibles — 2026

## Statut et objectif

Cette PR ajoute une couche de sous-menus accessible au menu principal Drupal.
La validation DDEV et Chromium a été terminée après l'intégration de la PR #74
dans `release/prod`. La PR peut sortir du brouillon : la fixture représentative,
la matrice navigateur, la restauration locale et les gardes de périmètre sont
documentées ci-dessous.

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
  réinitialise les accordéons. La fermeture rend le focus au bouton Menu ; au
  retour vers le desktop, un focus qui appartenait à la copie mobile revient au
  lien de marque visible. Aucun piège à focus supplémentaire n'est ajouté.
- Le focus seul n'ouvre pas un accordéon mobile : Entrée, Espace, clic ou toucher
  activent son bouton de divulgation. Le comportement `focus-within` reste
  réservé au desktop.

## Correctifs issus du navigateur réel

La première matrice Chromium a révélé quatre écarts qui n'étaient pas visibles
dans la fixture DOM statique :

- le contrôle différé de `focusout` utilisait une microtâche trop précoce pour
  le déplacement natif par Tab ; un tour de boucle laisse maintenant le focus
  atteindre le premier enfant avant de décider d'une fermeture ;
- les 6 px entre le déclencheur et le panneau fixe interrompaient la traversée
  au survol ; le panneau commence désormais exactement au bord du déclencheur ;
- la règle historique `display: flex` du drawer pouvait neutraliser son
  attribut `hidden` ; le fichier CSS scoped protège explicitement le drawer et
  le backdrop masqués ;
- les fermetures du drawer et les changements de mode remettent maintenant le
  focus sur un contrôle visible, tandis que les accordéons mobiles ne
  réagissent plus au focus seul.

Ces corrections restent entièrement dans les deux nouveaux assets de cette
PR ; `styles.css`, `mobile-drawer.js`, `auto-compact-nav.js` et
`unisonges_theme.theme` ne sont pas modifiés.

## Vérifications statiques

Ces contrôles restent indépendants de la fixture DDEV et ont été relancés
depuis la racine du worktree avant commit :

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

Le worktree ne contient pas `vendor/`. La syntaxe Twig et le YAML de la
bibliothèque ont été validés en lecture seule avec PHP 8.3 et les dépendances du
checkout DDEV, après comparaison exacte des deux `composer.lock`. Les fichiers
ont été copiés temporairement dans le conteneur puis supprimés ; cette
validation n'a pas bootstrappé Drupal.

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

Résultats finaux du 30 août 2026 sur la base `release/prod` `be48518` :

- syntaxe des trois scripts et contrôle ESLint ciblé
  `no-undef`/`no-unused-vars` : OK ;
- format Prettier des nouveaux JS, CSS et document, ainsi que du YAML modifié :
  OK ;
- CSS : 38 accolades ouvrantes/fermantes et aucun commentaire non apparié ;
- parsing Symfony YAML de la bibliothèque et compilation Twig du partial : OK,
  après comparaison exacte des deux `composer.lock` ;
- Chromium réel reprend le markup Barrio, la hiérarchie finale et ses
  caractères spéciaux : OK pour le clonage, l'unicité des IDs, les relations
  ARIA, la conservation des `href`, les branches sœurs, le focus, Échap, le
  clic extérieur et la réinitialisation du drawer ;
- revue statique accessibilité indépendante : aucun blocage restant ;
- garde des cinq fichiers et absence de diff sur les deux fichiers réservés :
  OK.

La validation automatisée ne remplace pas une annonce vocale réelle par NVDA
ou VoiceOver. Elle couvre toutefois les rôles DOM, noms accessibles, états,
relations, focus et interactions dans Chromium avec layout réel et toucher
émulé.

## Fixture DDEV représentative et restauration

La base locale initiale était le site minimal standard : aucun nœud et aucun
lien `menu_link_content` dans le menu principal, thème par défaut Olivero et
thème d'administration Claro. Le snapshot nommé suivant a été créé avant toute
écriture :

```text
pr77-navigation-submenus-preseed-20260830T131138Z
```

Une aide temporaire non suivie a créé par les API d'entités Drupal uniquement
les 16 pages/alias historiques et les 9 liens de menu autorisés. Son manifeste
d'IDs et d'UUIDs a été conservé sous `/tmp`. Le lien statique `Home` du profil
standard, absent de la hiérarchie représentative, a été désactivé localement
par l'API du gestionnaire de liens et enregistré séparément. Aucun SQL
d'écriture, import de configuration, changement de `config/sync` ou accès VPS
n'a eu lieu.

Le script exact de la PR #72 a ensuite été extrait depuis le commit
`7a7d0583eab2714d2d8480a89b75f9aee9cb76e9` : blob Git
`f6dd08e2068fc8c1907eb881fedf790f23b33251`, SHA-256
`895bfa4a391ce410f8982d4e8147ac125d10d2c7eb1b86723074e530bd460c0a`.
Son dry-run a terminé avec zéro blocage et le plan exact suivant : 12 pages à
mettre à jour, 4 pages et alias à créer, 8 liens à réconcilier, 4 liens à créer
et Services à désactiver sans suppression. L'application atomique a terminé
avec statut 0 et vérification post-écriture dans la transaction. Un second
dry-run était entièrement idempotent : aucun marqueur de mutation restant.

Après application, la validation par API d'entités a confirmé la conservation
des IDs/UUIDs des 16 pages et alias historiques, la création de quatre pages et
alias, puis 12 liens actifs organisés en cinq racines et sept enfants, plus le
même lien Services retenu désactivé. Les quatre pages de référence ont
conservé leur identité et leur corps de fixture. La page
`/services-prestations-artistiques` répondait HTTP 200.

Après les tests, le snapshot a été restauré. Le dump stable hors caches avant
et après restauration est identique, SHA-256
`766846eba7632dc79b77a68421f34911c86086b6216fcb254aac70b785069484`.
Le dump complet de la table de configuration active est également identique,
SHA-256 `f2e47c1f8048a46af5ba15cac7b3d4f886e70c0bede8b2db482625afd304b086`.
Le contrôle des manifestes par API Drupal rapporte zéro nœud, alias ou lien de
fixture résiduel ; `standard.front_page` est de nouveau actif, Olivero/Claro
sont restaurés et le checkout principal est propre sur `release/prod`.

## Matrice Chromium / Playwright exécutée

La suite temporaire utilisait `@playwright/test` 1.55.0 et l'image officielle
`mcr.microsoft.com/playwright:v1.55.0-noble`, isolées sous `/tmp`. Le dernier
passage complet a terminé avec `7 passed` en 53,9 s. Les vues de zoom emploient
le viewport CSS et le `deviceScaleFactor` équivalents dans un raster physique
constant de 1800×1000 ; il s'agit de la reflow équivalente de Chromium headless,
pas d'une manipulation de l'interface de la barre d'outils Chrome.

| ID      | Vue / interaction                                        | Validation attendue                                                                                                                    | Statut |
| ------- | -------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| NAV-D01 | Desktop large, puis 1440×900 et 1280×800                 | Les cinq libellés restent sur une ligne tant que la vue est desktop ; le mode compact prend le relais aux largeurs insuffisantes.      | [x]    |
| NAV-D02 | Réduire la largeur pixel par pixel autour du seuil       | Le body passe en `compact-nav` avant collision avec le logo ou les actions ; aucun clignotement ni aller-retour de mode.               | [x]    |
| NAV-D03 | Zoom navigateur équivalent 100 %, 150 % et 200 %         | Le compactage intervient avant chevauchement ; aucun scroll horizontal de page.                                                        | [x]    |
| NAV-D04 | Souris sur chacun des trois parents                      | Le survol ouvre ; quitter ferme sauf si le focus reste dans la branche ; le bouton fonctionne indépendamment.                          | [x]    |
| NAV-D05 | Clavier seul : Tab, Maj+Tab, Entrée, Espace              | Le lien parent reste activable ; le bouton annonce son nom, son état et sa relation ; tous les enfants sont atteignables dans l'ordre. | [x]    |
| NAV-D06 | Focus parent → bouton → plusieurs enfants                | La branche ne se replie pas pendant les déplacements internes et `aria-expanded="true"` reste exact.                                   | [x]    |
| NAV-D07 | Échap depuis un enfant                                   | La branche se ferme, `hidden` et `aria-expanded` repassent à l'état fermé et le focus revient au bon bouton.                           | [x]    |
| NAV-D08 | Clic extérieur puis réouverture                          | Toutes les branches se ferment sans empêcher l'action cliquée ; une seule branche sœur peut rester ouverte.                            | [x]    |
| NAV-D09 | Panneau du parent le plus proche du bord droit           | Le panneau reste entièrement dans le viewport, son texte revient à la ligne et la page ne déborde pas horizontalement.                 | [x]    |
| NAV-M01 | 320×568, 375×667 et 390×844, tactile                     | Le drawer s'ouvre ; liens parents, boutons et liens enfants ont des cibles distinctes et utilisables.                                  | [x]    |
| NAV-M02 | Ouvrir successivement Cours & Stages, Ateliers, À propos | L'accordéon ouvert ferme sa sœur ; son lien parent continue de naviguer vers le hub.                                                   | [x]    |
| NAV-M03 | Clavier dans le drawer                                   | Ordre fermeture → menu → compte cohérent ; Tab et Maj+Tab ne sont pas interceptés et aucun nouveau piège à focus n'apparaît.           | [x]    |
| NAV-M04 | Premier puis second Échap                                | Le premier ferme la branche avec retour au bouton ; le second ferme le drawer et rend le focus au bouton Menu.                         | [x]    |
| NAV-M05 | Backdrop, bouton fermer et burger                        | Chaque fermeture réinitialise les accordéons ; la réouverture commence avec `aria-expanded="false"`.                                   | [x]    |
| NAV-M06 | Paysage court 667×375                                    | Le drawer reste le seul scroller ; les derniers liens enfants et les liens de compte restent atteignables.                             | [x]    |
| NAV-M07 | 200 % et texte agrandi                                   | Les enfants reviennent proprement à la ligne, les toggles restent visibles et aucun débordement horizontal n'apparaît.                 | [x]    |
| NAV-R01 | Redimensionner avec une branche ouverte                  | Le changement desktop/compact ferme proprement l'état précédent, sans oscillation du seuil ni focus bloqué dans une copie cachée.      | [x]    |
| NAV-R02 | Navigation réelle sur les 12 destinations finales        | Chaque `href` correspond à la valeur Drupal ; aucun clic de lien parent n'est intercepté par le contrôleur.                            | [x]    |
| NAV-R03 | Sémantique d'accessibilité Chromium                      | Deux landmarks non imbriqués, liens parents, boutons nommés, états et cibles ARIA exacts ; aucun rôle de menu d'application.           | [x]    |

Les quatre captures inspectées visuellement sont conservées sous `/tmp` :

- `pr77-desktop-closed.png`, 1800×1000,
  SHA-256 `7392c61f1f7d4f0867627f68fead04adbfee96e2477b9bfc7c9f84e19c1363db` ;
- `pr77-desktop-open.png`, 1800×1000,
  SHA-256 `139accd4964d4fda2ac9f9c4e61ec21b2c817591261137ccb404622adb0b4f6d` ;
- `pr77-mobile-drawer.png`, 390×844,
  SHA-256 `723977292a99492129b48115343fae886b55eace147166a3af667eca7588248e` ;
- `pr77-zoom-200.png`, raster 1800×1000 pour un viewport CSS 900×500,
  SHA-256 `4a4c77332e02b30f41ae23673477d15d298a182fbd7c1979a5de56a6fa5580ed`.

Le journal final est `/tmp/pr77-playwright-final.log`, SHA-256
`19b6ddd62125448cecc32691ba02a42b63436b540ee65ca668b1ffa344df30f6`.

La suite Playwright a notamment exécuté les assertions DOM équivalentes
suivantes sur une page portant la hiérarchie finale :

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
