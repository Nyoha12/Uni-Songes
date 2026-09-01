# Intégrité des bibliothèques du thème — 2026

## Périmètre et statut

Le diff de cette modification est exclusivement statique. Il répare les
déclarations de bibliothèques du thème Uni-Songes sans modifier les templates,
les feuilles de style, les scripts, PHP, la configuration synchronisée ou une
URL publique. La base contrôlée est `origin/release/prod` au commit de fusion
de la PR #91, `894f054f6c1ffe6a75d43dc04889fbaeea0a157d`.

La stratégie A est retenue : la bibliothèque globale du thème devient
directement `unisonges_theme/unisonges-layout`, et `contact` est une petite
bibliothèque de compatibilité qui dépend de `unisonges-layout` sans déclarer
aucun actif. La stratégie B aurait conservé un alias `global` supplémentaire
sur chaque page sans supprimer un niveau de résolution ni une attache
redondante. La stratégie A donne donc le graphe le plus court et la surface de
maintenance la plus petite.

Après les fusions des PR #84 puis #91, la branche existante a été réalignée sur
la dernière base avec conservation de la stratégie A. La source runtime exacte
était le commit rebasé `f5dbbe9326eca2cb88bfa0c616da7eff97d58d55`.
La matrice DDEV/Drupal/Chromium a ensuite réussi dans les quatre combinaisons
agrégation/cache. Les ressources runtime ont été restaurées, arrêtées et
libérées avant la validation statique finale.

## Audit initial complet

### Références exécutables avant correction

| ID                                 | Source de la référence                                              | Déclaration avant | Actifs résolus avant      | Actifs aussi déclarés ailleurs |
| ---------------------------------- | ------------------------------------------------------------------- | ----------------- | ------------------------- | ------------------------------ |
| `unisonges_theme/global`           | `unisonges_theme.info.yml`, bibliothèque globale du thème           | absente           | aucun, référence invalide | aucun                          |
| `unisonges_theme/unisonges-layout` | `templates/page.html.twig:1` et `templates/page--front.html.twig:1` | présente          | 4 CSS et 5 JS             | aucun                          |
| `unisonges_theme/contact`          | `templates/content/node--7.html.twig:1`                             | absente           | aucun, référence invalide | aucun                          |

L'inventaire de tous les appels `attach_library()` du thème contient exactement
trois appels, répartis sur deux lignes du tableau : deux attaches de
`unisonges-layout` et une de `contact`. La référence à `global` provient du
fichier info, pas d'un appel Twig. Aucun fichier PHP du thème n'attache de
bibliothèque. À l'échelle du dépôt, la seule autre attache trouvée est
`unisonges_structure/reservation-first-tunnel` dans le formulaire de
réservation ; elle appartient à un autre module et est hors de ce graphe.

Les mentions documentaires antérieures sont limitées à
`docs/audits/public-site-content-layout-audit-2026.md:64,107,165,197,205`. Les
trois premières décrivent le défaut initial (`global` et `contact` manquantes,
puis le script Contact orphelin) ; les deux dernières répartissent la propriété
des futures corrections. Aucune ne constitue une attache Drupal. Le présent
document distingue de la même façon les graphes historiques des références
exécutables.

### Propriété initiale des actifs

`unisonges-layout` était déjà l'unique propriétaire des neuf actifs suivants.
Chaque chemin existe, est suivi par Git et apparaît exactement une fois dans
l'ensemble des fichiers `*.libraries.yml` et `*.libraries.yaml` suivis :

| Type | Chemin                              | Propriétaire avant | Nombre de déclarations avant |
| ---- | ----------------------------------- | ------------------ | ---------------------------: |
| CSS  | `fonts/jura/webfont/stylesheet.css` | `unisonges-layout` |                            1 |
| CSS  | `fonts/taga/webfont/stylesheet.css` | `unisonges-layout` |                            1 |
| CSS  | `css/styles.css`                    | `unisonges-layout` |                            1 |
| CSS  | `css/navigation-submenus.css`       | `unisonges-layout` |                            1 |
| JS   | `js/mobile-drawer.js`               | `unisonges-layout` |                            1 |
| JS   | `js/navigation-submenus.js`         | `unisonges-layout` |                            1 |
| JS   | `js/auto-compact-nav.js`            | `unisonges-layout` |                            1 |
| JS   | `js/bg-mirror-height.js`            | `unisonges-layout` |                            1 |
| JS   | `js/bgfx-scroll-11.js`              | `unisonges-layout` |                            1 |

L'historique Git explique l'écart : le commit `a71abaf` a renommé `global` en
`unisonges-layout` et supprimé l'ancienne bibliothèque `contact`, qui chargeait
alors `js/contact-form.js`. Les commits suivants ont ajouté les actifs actuels
uniquement à `unisonges-layout`, sans mettre à jour les deux références
restées en place.

Le fichier Drupal
`drupal/web/themes/custom/unisonges_theme/js/contact-form.js` existe toujours,
mais n'est cité par aucune bibliothèque, aucun fichier info, aucun template et
aucun code PHP/YAML d'attachement. Le site historique sous `public/` contient
et charge une copie distincte depuis `public/contact/index.html`; cette copie
legacy est hors du thème Drupal et hors du périmètre de cette PR.

## Contrat Drupal 11 contrôlé

`drupal/composer.lock` verrouille `drupal/core` 11.3.3, commit amont
`7067385162ad51020c78052fe15334671bdf3552`, et `symfony/yaml` 7.4.6. Le
worktree ne contient pas `vendor/`. La revue a donc inspecté en lecture seule
la source officielle exacte de ce commit et a validé les deux YAML avec la
version exacte de Symfony YAML verrouillée, toutes deux téléchargées sous
`/tmp` sans modifier le dépôt.

Il n'existe pas de schéma JSON autonome pour `*.libraries.yml` dans cette
version : Drupal décode le YAML puis applique le contrat dans
`Drupal\Core\Asset\LibraryDiscoveryParser`. Le contrôle pertinent est donc le
suivant :

- `LibraryDiscoveryParser::buildByExtension()` accepte explicitement une
  définition possédant `dependencies`, même sans `css`, `js` ou
  `drupalSettings`, puis lui fournit des listes CSS/JS vides ;
- le test cœur `testBuildByExtensionWithOnlyDependencies()` et sa fixture
  `example_module_only_dependencies.libraries.yml` prouvent cette forme ;
- une définition réellement vide reste rejetée par
  `IncompleteLibraryDefinitionException` ; `contact` n'utilise donc ni
  `contact: {}` ni des cartes CSS/JS vides ;
- `LibraryDependencyResolver` attend des dépendances qualifiées sous la forme
  `extension/nom` et les développe avant que `AssetResolver` écarte, pour le
  type CSS ou JS demandé, le wrapper dépourvu d'actif ;
- `AttachedAssets::setLibraries()` convertit les attaches en ensemble avec
  `array_unique()`. Le résolveur utilise ensuite des clés par nom de
  bibliothèque et un sous-ensemble représentatif minimal : une bibliothèque
  attachée plusieurs fois et une dépendance partagée ne produisent qu'une
  occurrence effective ;
- le résolveur ne possède pas de garde générale contre un cycle avant sa
  récursion. Le graphe livré est donc contrôlé explicitement comme acyclique.

La déduplication est ainsi un comportement vérifié dans le code et les tests
de la version verrouillée, pas une hypothèse. La correction ne s'appuie
toutefois pas sur des listes copiées : les neuf actifs restent déclarés
physiquement une seule fois. Le comptage des requêtes réelles a ensuite été
confirmé par la validation runtime ci-dessous.

## Graphe avant et après

Avant :

```text
unisonges_theme.info.yml
└── unisonges_theme/global                         [ABSENTE]

page.html.twig
└── unisonges_theme/unisonges-layout
    ├── 4 feuilles de style
    └── 5 scripts

page--front.html.twig
└── unisonges_theme/unisonges-layout
    ├── les mêmes 4 feuilles, même propriétaire
    └── les mêmes 5 scripts, même propriétaire

node--7.html.twig
└── unisonges_theme/contact                        [ABSENTE]
```

Après :

```text
unisonges_theme.info.yml ───────────────┐
page.html.twig ─────────────────────────┼──> unisonges_theme/unisonges-layout
page--front.html.twig ──────────────────┘    ├── 4 feuilles de style
                                             └── 5 scripts

node--7.html.twig
└── unisonges_theme/contact                       [aucun actif direct]
    └── dépend de unisonges_theme/unisonges-layout
```

Sur une page standard ou la page d'accueil, l'attache globale et l'attache
Twig existante convergent vers l'unique bibliothèque canonique. Sur Contact,
le wrapper `contact`, l'attache globale et le shell de page convergent vers la
même bibliothèque. Drupal déduplique ces noms avant la construction des
collections ; aucun chemin CSS ou JS n'est recopié.

### Références exécutables après correction

| ID                                 | Sources après                                                                     | Existe         | Actifs directs          | Actifs transitifs                  | Copie d'une liste d'actifs |
| ---------------------------------- | --------------------------------------------------------------------------------- | -------------- | ----------------------- | ---------------------------------- | -------------------------- |
| `unisonges_theme/unisonges-layout` | `unisonges_theme.info.yml:7`, les deux templates de page, dépendance de `contact` | oui            | les 9 actifs canoniques | les mêmes 9                        | non                        |
| `unisonges_theme/contact`          | `node--7.html.twig:1`                                                             | oui            | aucun                   | les 9 actifs de `unisonges-layout` | non                        |
| `unisonges_theme/global`           | aucune référence exécutable après correction                                      | non nécessaire | aucun                   | aucun                              | non                        |

Toutes les références exécutables suivies sous `unisonges_theme/*` se
résolvent donc. Le graphe de dépendances interne contient une seule arête,
`contact -> unisonges-layout`, et aucune arête retour.

## Contrats préservés

- `unisonges-layout` conserve son nom et reste l'unique propriétaire des deux
  feuilles de fontes, de `styles.css`, de `navigation-submenus.css` et des cinq
  systèmes JavaScript ;
- `styles.css` et `navigation-submenus.css` restent dans la fermeture de
  dépendances de toutes les pages du thème ;
- `mobile-drawer.js`, `navigation-submenus.js`, `auto-compact-nav.js`,
  `bg-mirror-height.js` et `bgfx-scroll-11.js` restent dans cette même
  fermeture ;
- `contact-form.js` n'est ajouté à aucune bibliothèque, n'est ni modifié ni
  supprimé et reste inutilisé par le thème Drupal ;
- `page.html.twig`, `page--front.html.twig` et `node--7.html.twig` ne sont pas
  modifiés ; leurs attaches explicites sont volontairement conservées ;
- aucun code de rendu, navigation, arrière-plan, réservation ou Contact n'est
  modifié ; aucun actif ne change de chemin, d'options ou d'ordre dans sa
  bibliothèque propriétaire.

## Validation statique

Tous les contrôles statiques finaux sont exécutés depuis la racine de ce
worktree et restent indépendants de DDEV, Docker, Drush et Chromium. La matrice
runtime documentée plus bas a été menée séparément dans le checkout de service
local. Aucun accès VPS n'a eu lieu.

Le parsing strict porte sur les deux fichiers YAML modifiés avec Symfony YAML
7.4.6, version verrouillée par `composer.lock`. Une revue structurelle
indépendante compare ensuite la forme obtenue au contrat et aux tests de
Drupal 11.3.3. Le CLI local utilise PHP 8.2 alors que ce cœur exige PHP 8.3 ou
plus : aucun bootstrap Drupal ni test PHPUnit n'a donc été lancé dans cette
phase statique. Les autres gardes utilisent exclusivement les fichiers suivis
par Git et la base distante rafraîchie :

```bash
git fetch origin release/prod --prune
test "$(git rev-parse HEAD^)" = "$(git rev-parse origin/release/prod)" || \
  test "$(git merge-base HEAD origin/release/prod)" = \
    "$(git rev-parse origin/release/prod)"

git grep -n -I 'attach_library' -- \
  'drupal/web/themes/custom/unisonges_theme/**'
git grep -n -I -E \
  'unisonges_theme/(global|contact|unisonges-layout)' -- \
  '*.twig' '*.php' '*.module' '*.inc' '*.install' '*.profile' '*.theme' \
  '*.yml' '*.yaml' '*.md' '*.txt' '*.rst' '*.adoc'

git diff --check origin/release/prod --
git diff --exit-code origin/release/prod -- \
  drupal/web/themes/custom/unisonges_theme/templates
```

Résultats du contrôle statique final :

- [x] `origin/release/prod` est le commit de base exact et le worktree était
      propre avant correction ;
- [x] parsing YAML strict, sans clé dupliquée ni type invalide ;
- [x] forme de bibliothèque conforme au parseur et au test dependency-only de
      Drupal 11.3.3 ;
- [x] inventaire complet des attaches et références runtime : toutes les
      références `unisonges_theme/*` se résolvent ;
- [x] graphe acyclique et sans dépendance récursive ;
- [x] neuf chemins d'actifs existants et suivis par Git ;
- [x] neuf actifs réels déclarés exactement une fois, exclusivement par
      `unisonges-layout` ;
- [x] `styles.css`, `navigation-submenus.css` et les cinq scripts attendus
      restent atteignables ;
- [x] le `contact-form.js` du thème reste présent mais absent de toute
      déclaration ou attache Drupal ;
- [x] aucun template, PHP, CSS, JS, configuration ou fichier hors périmètre
      n'est modifié ;
- [x] garde exacte des trois fichiers, `git diff --check`, UTF-8 strict et
      normalisation Unicode NFC ;
- [x] scan des trois fichiers et du diff : aucun secret, jeton ou identifiant
      ajouté ;
- [x] revue indépendante du contrat de bibliothèque et du graphe Drupal.

L'inventaire final compte 10 autres PR ouvertes vers `release/prod` : #82,
#85 à #90, #92, #94 et #95. Aucune ne modifie l'un des trois fichiers de cette
PR. La PR #85 ne touche que son document, deux configurations et ses deux
scripts Contact. Les PR #94 et #95 touchent respectivement les templates de
page et `styles.css`, que cette PR ne modifie pas. L'intersection de noms de
fichiers reste vide.

## Validation runtime réalisée

### Récupération sûre et état initial

Le worktree de la PR était propre, sans travail suivi non validé. Le commit de
la PR #91 a été prouvé ancêtre de `origin/release/prod`, puis la branche
existante a été rebasée et poussée avec `--force-with-lease`. Aucun autre
worktree, branche ou PR n'a été modifié.

Avant toute écriture DDEV, le snapshot nommé
`pr93-theme-library-integrity-pre-runtime-20260901T145232Z` a été créé. Les
empreintes et états de référence étaient :

| Élément                                    | Référence avant test                                                                        |
| ------------------------------------------ | ------------------------------------------------------------------------------------------- |
| Base de données normalisée                 | `161ef10fa5a32b0075cc19c4abd9a3ec8b9d8e0039be392db83f676397134b4b`                          |
| Configuration active brute, 314 lignes     | `07ec23fcbcbab78e48b746283be7ffb12fda49b5c59264fdf0fea31e0ec32702`                          |
| Configuration active canonique, 314 objets | `90913af66f81a020108e9f093fb874f3c7b02d782f0e0b77a9ff2c3a5ce4c46c`                          |
| Fichiers publics canoniques, 245 fichiers  | `4b1c467c828f6cececfa1245a8de996263476b7d9297f542552c1bc9407d2cac`                          |
| Thèmes                                     | actifs `olivero`, `claro` ; défaut `olivero` ; administration `claro`                       |
| Page d'accueil                             | `/node`                                                                                     |
| Agrégation initiale                        | `css.preprocess=true`, `js.preprocess=true`                                                 |
| Entités                                    | 0 nœud, 16 alias, 0 lien de menu, 7 utilisateurs, 0 soumission Webform, 0 commande Commerce |
| Checkout de service                        | branche `release/prod`, commit `a673a078430501d29f1631b96edf57cb65ec4c19`                   |

Le checkout de service a ensuite été placé en HEAD détachée sur la source PR
exacte. L'empreinte canonique des fichiers ignorés `drupal/.ddev` est restée
identique avant et après ce chargement (`cc889773a4fc5371436b423610dbf4f1fda259ca61c47de5a13db5dbbb2795e3`).
Le thème a été activé localement par les API Drupal/Drush. Aucun import de
configuration complet ou partiel n'a été utilisé.

La base locale ne contenait aucun nœud et le module Commerce Cart était
désactivé. Des fixtures étroites, marquées et réversibles ont donc été créées
par les API d'entités : nœuds 7 et 8 pour exercer les templates Contact et
réservation, pages aliasées pour les routes requises, une page ordinaire
longue, un menu français représentatif et une page locale `/cart`. La position
du bloc de titre a été alignée par l'API d'entité sur la configuration fusionnée
de la PR #84. Aucun utilisateur, paiement, appel PayPal/Google ou soumission de
formulaire n'a été créé.

### Quatre modes agrégation/cache

Chromium 151.0.7922.34 a exécuté les dix routes dans chaque mode. « Froid »
signifie une reconstruction des caches Drupal juste avant le mode ; « chaud »
réutilise ensuite ces caches. Le cache réseau du navigateur était désactivé
pour rendre chaque comptage de requête explicite.

| Mode | CSS        | JavaScript | Cache Drupal               | Résultat |
| ---- | ---------- | ---------- | -------------------------- | -------- |
| 1    | non agrégé | non agrégé | froid après reconstruction | réussi   |
| 2    | non agrégé | non agrégé | chaud                      | réussi   |
| 3    | agrégé     | agrégé     | froid après reconstruction | réussi   |
| 4    | agrégé     | agrégé     | chaud                      | réussi   |

Les 40 navigations principales ont renvoyé HTTP 200. Les empreintes du document
HTML et les listes normalisées de balises CSS/JS sont identiques entre modes 1
et 2, puis entre modes 3 et 4, pour les dix routes. Il n'existe donc ni erreur
du premier accès, ni disparition après réchauffement.

### Routes et comptages réseau

Les valeurs suivantes sont les nombres totaux de requêtes de feuilles de style
et de scripts par page. Elles incluent Drupal, le thème de base et les actifs
propres à la route.

| Route                                   | Modes 1/2 : CSS / JS | Modes 3/4 : CSS / JS | Résultat fonctionnel                            |
| --------------------------------------- | -------------------: | -------------------: | ----------------------------------------------- |
| `/accueil`                              |               43 / 9 |                7 / 6 | accueil, header et navigation visibles          |
| page ordinaire `/pr93-library-ordinary` |               44 / 9 |                7 / 6 | contenu long visible, défilement disponible     |
| `/contact`                              |               43 / 9 |                7 / 6 | contenu et deux actions visibles et navigables  |
| `/reservation-cours`                    |               45 / 9 |                7 / 6 | formulaire et feuille spécifique visibles       |
| `/reserver`                             |              54 / 35 |                7 / 8 | portail, formulaire et actions visibles         |
| `/ateliers`                             |               43 / 9 |                7 / 6 | contenu visible                                 |
| `/a-propos`                             |               43 / 9 |                7 / 6 | contenu visible                                 |
| `/blog`                                 |               43 / 9 |                7 / 6 | contenu visible                                 |
| `/forum`                                |               44 / 9 |                7 / 6 | contenu visible                                 |
| `/cart`                                 |               44 / 9 |                7 / 6 | fixture locale visible, sans opération Commerce |

Les totaux par mode sont 446 CSS et 116 JS sans agrégation, puis 70 CSS et
62 JS avec agrégation. Dans chacun des modes 3/4, les pages cumulent 30
occurrences de balises pointant vers des agrégats CSS et 11 occurrences de
balises d'agrégats JS, correspondant à 12 URL CSS et 3 URL JS distinctes. Les
neuf actifs Uni-Songes, qui conservent `preprocess: false`, restent directs dans
les quatre modes :

- chaque mode contient exactement 40 requêtes canoniques CSS, soit les quatre
  chemins attendus une fois sur chacune des dix routes ;
- chaque mode contient exactement 50 requêtes canoniques JS, soit les cinq
  chemins attendus une fois sur chacune des dix routes ;
- toutes répondent 200 ; aucun doublon de balise, de requête, d'URL effective,
  de contenu CSS ou de contrôleur JavaScript n'est observé sur une même page ;
- aucun actif CSS/JS ne répond 404 et aucune URL d'actif obsolète `global` ou
  `contact` n'est demandée ;
- `contact-form.js` compte 0 balise, 0 entrée Resource Timing et 0 requête dans
  les 40 pages.

Le journal Drupal a été borné au `wid` 178 avant la matrice : aucune entrée
ultérieure n'a été créée. Le scan des journaux web, des consoles Chromium, des
exceptions de page et des réponses ne trouve aucune bibliothèque inconnue ou
manquante, aucun avertissement/fatal PHP, aucune erreur JavaScript et aucune
réponse CSS/JS en échec.

### Rendu, navigation et Contact

Sur l'accueil et la page ordinaire, un seul `main#main-content`, un seul H1
visible, aucun ID dupliqué et aucun débordement horizontal subsistent. Le
header, le contenu et la navigation sont visibles dans chaque mode. Le drawer
mobile s'ouvre et se ferme, son état ARIA suit son état visuel et les sous-menus
desktop/mobile s'ouvrent une seule fois sans collision.

Les libellés `Cours & Stages`, `Concerts & Événements`, `Projets collectifs`,
`À propos` et `Contact`, ainsi que `É`, `é`, `À`, `&` et `’`, sont rendus sans
mojibake ni boîte de remplacement. La famille calculée est
`system-ui, "Segoe UI", Arial, sans-serif` ; Chromium sélectionne DejaVu Sans
Bold. La revue visuelle des captures desktop/mobile ne montre aucune collision.

Sur Contact, `contact` se résout, charge transitivement `unisonges-layout` une
fois et n'active aucun formulaire ou contrôleur Contact obsolète. Dans un
contrôle complémentaire de chacun des quatre modes, les deux liens ont été
réellement suivis : `/stages` et `/reserver` répondent 200, le contenu attendu
reste visible et aucune soumission n'est effectuée.

### Régression arrière-plan PR #91

Dans chaque mode, le contrôleur `window.__unisongesBgfxScroll11`, son style de
propriété de mouvement et les trois nœuds d'arrière-plan existent chacun une
seule fois. La sonde `requestAnimationFrame` observe au maximum un callback
`animate` en attente, donc aucune deuxième boucle.

Sur 4,5 secondes, le déplacement autonome mesuré varie de 0,171 à 0,178 px.
Le scrollframe long offre 2 183 px de défilement ; sa mise en bas puis son
retour en haut laissent la transformation d'arrière-plan strictement identique.
Les bords haut et bas restent couverts, les trois couches ont
`pointer-events: none`, et les liens restent cliquables. Avec
`prefers-reduced-motion: reduce`, la transformation reste statique et aucun
callback `animate` ne s'exécute. Le graphe de bibliothèques ne duplique, ne
supprime et ne casse donc pas le contrôleur final fusionné par la PR #91.

### Observation CTA hors périmètre

Une seconde sonde a séparé l'état visité pur des états hover/active. Les ratios
de contraste sont stables dans les quatre modes. Le champ `visited` de la sonde
principale a été écarté parce que son pointeur restait sur le lien ; la colonne
« Visité » ci-dessous provient de la sonde ciblée exécutée sans survol :

| CTA                |   Normal |   Visité |    Hover |    Focus |   Active |
| ------------------ | -------: | -------: | -------: | -------: | -------: |
| Header `Réserver`  | 5,4733:1 | 5,4733:1 | 2,5485:1 | 5,4733:1 | 2,5485:1 |
| CTA de réservation | 5,4733:1 | 5,4733:1 | 5,4733:1 | 5,4733:1 | 5,4733:1 |

La réparation de bibliothèque ne change donc pas le défaut connu de contraste
hover/active du CTA du header. Il reste la propriété de la PR CSS dédiée ;
`styles.css` n'est pas modifié ici.

Les formulaires locaux de réservation exposaient aussi des IDs dupliqués
préexistants (`edit-actions`, puis `edit-submit` sur `/reserver`). L'accueil et
la page ordinaire satisfont bien la garde d'IDs de ce périmètre ; cette
observation de formulaire est indépendante du graphe de bibliothèques et n'est
pas corrigée dans cette PR.

### Nettoyage et restauration

Les 15 nœuds, 15 alias et 11 liens de menu de la matrice principale, puis les
quatre fixtures étroites de contrôle complémentaire, ont été supprimés par
leurs IDs et marqueurs exacts avant restauration. Le snapshot nommé a été
restauré, puis les fichiers publics ont été remis depuis leur copie de
référence. Les résultats finaux sont identiques aux valeurs initiales :

| Garde après restauration       | Résultat                                                                                                          |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------------- |
| Base de données normalisée     | `161ef10fa5a32b0075cc19c4abd9a3ec8b9d8e0039be392db83f676397134b4b`                                                |
| Configuration active brute     | `07ec23fcbcbab78e48b746283be7ffb12fda49b5c59264fdf0fea31e0ec32702`                                                |
| Configuration active canonique | `90913af66f81a020108e9f093fb874f3c7b02d782f0e0b77a9ff2c3a5ce4c46c`                                                |
| Fichiers publics canoniques    | `4b1c467c828f6cececfa1245a8de996263476b7d9297f542552c1bc9407d2cac`, archive avant/après identique octet par octet |
| Entités                        | 0 fixture, 0 nœud, 16 alias, 0 lien de menu, 7 utilisateurs, 0 soumission, 0 commande                             |
| Thèmes et accueil              | `olivero`/`claro`, défaut `olivero`, administration `claro`, accueil `/node`                                      |
| Agrégation                     | valeurs initiales `true`/`true` restaurées                                                                        |
| Checkout de service            | `release/prod` propre au commit initial `a673a078430501d29f1631b96edf57cb65ec4c19`                                |

Les helpers et paquets navigateur temporaires ont été retirés. Les preuves JSON,
les synthèses, les archives d'empreinte et 12 captures représentatives ont été
conservées sous
`/tmp/pr93-theme-library-integrity-20260901T145232Z`. DDEV et son routeur sont
arrêtés ; la propriété runtime est libérée.
