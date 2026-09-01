# Intégrité des bibliothèques du thème — 2026

## Périmètre et statut

Cette modification est exclusivement statique. Elle répare les déclarations
de bibliothèques du thème Uni-Songes sans modifier les templates, les feuilles
de style, les scripts, PHP, la configuration synchronisée ou une URL publique.
La base contrôlée est `origin/release/prod` au commit
`a673a078430501d29f1631b96edf57cb65ec4c19`.

La stratégie A est retenue : la bibliothèque globale du thème devient
directement `unisonges_theme/unisonges-layout`, et `contact` est une petite
bibliothèque de compatibilité qui dépend de `unisonges-layout` sans déclarer
aucun actif. La stratégie B aurait conservé un alias `global` supplémentaire
sur chaque page sans supprimer un niveau de résolution ni une attache
redondante. La stratégie A donne donc le graphe le plus court et la surface de
maintenance la plus petite.

La PR #84 a été fusionnée pendant l'audit ; la branche a alors été réalignée
sur son commit de fusion. Cette phase reste néanmoins statique et ne suppose
pas que ses ressources DDEV, Docker, Drush ou Chromium ont déjà été libérées.
La présente PR doit rester en brouillon jusqu'à la validation de la matrice
d'exécution différée.

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
de la version verrouillée, pas une hypothèse. La correction ne s'appuie toutefois
pas sur des listes copiées : les neuf actifs restent déclarés physiquement une
seule fois. Le comptage des requêtes réelles restera une validation runtime
obligatoire.

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

Tous les contrôles sont exécutés depuis la racine de ce worktree, sans DDEV,
Docker, Drush, navigateur ou accès VPS.

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

Le premier inventaire comptait 11 PR ouvertes, dont la PR #84. Celle-ci a été
fusionnée pendant l'audit et ses quatre fichiers ont été intégrés à la nouvelle
base avant la validation finale. L'inventaire final compte 10 autres PR
ouvertes (#23, #82 et #85 à #92), toutes dirigées vers `release/prod`. Aucune
ne modifie l'un des trois fichiers de cette PR. La PR #85 ne touche que son
document, deux configurations et ses deux scripts Contact. Les PR #91 et #23
modifient respectivement `bgfx-scroll-11.js` et `styles.css`, dont cette PR
conserve seulement les déclarations existantes. L'intersection de noms de
fichiers reste vide.

## Matrice runtime différée

Cette matrice doit être exécutée dès que la PR #84 libère ses ressources. Elle
doit couvrir l'agrégation CSS/JS désactivée puis activée, et pour chaque mode un
cache froid après reconstruction puis un cache chaud.

| Cas                              | Vérification attendue                                                                     | Statut  |
| -------------------------------- | ----------------------------------------------------------------------------------------- | ------- |
| Reconstruction des caches Drupal | aucune erreur de découverte ou de dépendance de bibliothèque                              | différé |
| Page d'accueil anonyme           | rendu complet ; feuilles, navigation et arrière-plan présents                             | différé |
| Page ordinaire anonyme           | rendu complet ; aucun changement de shell ou de défilement                                | différé |
| Page Contact anonyme             | rendu complet ; comportement Webform standard ; aucune exception de bibliothèque          | différé |
| Page réservation anonyme         | tunnel et interactions inchangés                                                          | différé |
| Navigation desktop               | menu, sous-menus, compactage et clavier inchangés                                         | différé |
| Navigation mobile                | drawer, sous-menus, focus et fermeture inchangés                                          | différé |
| Arrière-plan autonome            | hauteur, miroir et mouvement autonomes inchangés                                          | différé |
| Comptage des requêtes CSS        | chacun des 4 chemins du thème exactement une fois ; aucun doublon avec ou sans agrégation | différé |
| Comptage des requêtes JS         | chacun des 5 chemins du thème exactement une fois ; aucun doublon avec ou sans agrégation | différé |
| Contact obsolète                 | aucune requête vers le `contact-form.js` du thème                                         | différé |
| Journaux et console              | aucune `unknown library`, erreur PHP/JS ou requête d'actif en échec                       | différé |

La PR reste en brouillon jusqu'à réussite de toutes les cellules dans les
quatre combinaisons agrégation activée/désactivée et cache chaud/froid.
