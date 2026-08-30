# Lisibilité typographique du thème — 2026

## Base, périmètre et décisions

La PR #67 est rebasée sur `origin/release/prod` au commit
`22e16734745789ff14c68d3b8063210a04e295cb` du 30 août 2026. Cette base
contient notamment la PR #77 sur les sous-menus (`7a8ecef`) et la PR #72 sur
les contenus publics (`22e1673`). Le diff reste limité à :

- `drupal/web/themes/custom/unisonges_theme/css/styles.css` ;
- `docs/functional/theme-typography-readability-2026.md`.

Les choix testés sont les suivants :

- le `body` utilise une pile système, une graisse `400` et un interlignage
  global `1.6` ; les paragraphes de composants testés restent à un ratio d’au
  moins `1.55` et une mesure maximale de `68ch` ;
- les titres généraux et longs utilisent
  `system-ui, "Segoe UI", Arial, sans-serif`, en graisse `700` ;
- Taga Bold reste réservé à `.home-card__title`, donc à un titre d’affichage
  court ; il n’est plus appliqué aux titres génériques des cartes ;
- la navigation utilise exclusivement
  `system-ui, "Segoe UI", Arial, sans-serif`, sans capitale forcée ni
  translittération ;
- les racines du menu complet sont à `.92rem`, les enfants à `1rem`, avec
  retour à la ligne autorisé pour les enfants ;
- le titre du site conserve son identité et sa fonte, avec une taille responsive
  ajustée ; la marque existante est placée dans le flux, immédiatement à sa
  droite, par CSS uniquement ;
- les ressources d’image, les fontes et `site-header.html.twig` restent
  inchangés.

Les spécimens Taga du dépôt ne couvrent pas tous les caractères nécessaires à
la navigation. Même si Jura les couvre, une fonte d’affichage n’est pas
retenue pour le menu : la pile système offre la lisibilité et la couverture de
glyphes attendues.

## Menu final représentatif

Le menu temporaire utilisé dans Drupal respecte exactement le contenu observé
après le dernier déploiement :

```text
Cours & Stages
Concerts & Événements
Projets collectifs
  D’Jam
  Orchestre
  Forum
À propos
  L’Asso
  Partenaires
  Origine
  Blog
Contact
```

Les libellés sont conservés en UTF-8. Le menu compact historique de la PR #72,
qui contenait notamment « Ateliers », n’a pas servi de fixture à cette
validation finale.

## Compatibilité avec les changements récents

- Le tunnel de réservation prioritaire et son CSS de module restent
  inchangés. La route réelle `/reservation-cours` a été ouverte, sans envoi de
  formulaire.
- `navigation-submenus.css`, `navigation-submenus.js` et
  `auto-compact-nav.js` restent inchangés. Le CSS de la PR #67 ajuste la
  typographie de leurs composants sans modifier leurs fichiers ni leur logique
  JavaScript.
- Les contenus représentatifs de `/accueil`, `/cours`, `/stages` et
  `/association` reprennent les contrats structurels de la PR #72 : six cartes
  sur l’accueil, trois cartes et un CTA de réservation sur les cours, trois
  familles et une zone de publication sur les stages, puis cinq cartes et une
  section projets sur l’association.
- Les fichiers Codespaces et `.devcontainer` restent inchangés.
- Aucun PHP, Twig, JavaScript, fichier de fonte, script de contenu,
  `config/sync`, élément Commerce, URL publique ou routage n’est modifié par la
  PR.

## Préparation locale réversible

La base DDEV initiale était minimale : thème public Olivero, thème
d’administration Claro, page d’accueil `/node`, aucun lien de contenu dans le
menu principal et aucun bloc Uni-Songes.

Avant toute écriture Drupal, le snapshot suivant a été créé :

```text
pr67-typography-final-menu-prebrowser-20260830T145335Z
```

Le thème Uni-Songes, le menu représentatif et les pages de contrôle ont été
installés temporairement au moyen des API d’entités Drupal. Aucun SQL brut,
import de configuration, script de contenu du dépôt ni écriture Commerce n’a
été utilisé. `/reservation-cours` provenait de la route réelle du module ; les
quatre autres pages contrôlées étaient des fixtures locales représentatives.

Après les tests, le projet DDEV du worktree a été arrêté et désinscrit. Le
projet original `/workspaces/Uni-Songes/drupal`, au commit
`be485180c2c2d13419014b2489ea34f96006ace8`, a été redémarré et le snapshot a
été restauré. Le contrôle final par API Drupal donne exactement :

```json
{
  "default_theme": "olivero",
  "admin_theme": "claro",
  "installed_themes": {"olivero": 0, "claro": 0},
  "front_page": "/node",
  "main_link_ids": [],
  "static_home_enabled": "1",
  "unisonges_blocks": [],
  "fixture_node_ids": [],
  "fixture_alias_ids": [],
  "route_alias_ids": []
}
```

Les helpers temporaires ont également été supprimés du worktree. DDEV est de
nouveau rattaché au checkout original, et aucune fixture PR67 ne subsiste.

## Matrice Chromium exécutée

Chromium `140.0.7339.16`, fourni par l’image Playwright `v1.55.0-noble`, a été
exécuté réellement contre `http://127.0.0.1:8080`. Les niveaux de reflow sont
obtenus par une réduction proportionnelle du viewport CSS et un facteur de
périphérique correspondant, afin de conserver le même raster physique pour
chaque famille d’appareil.

| ID | Classe | Reflow | Viewport CSS | Facteur | État du menu | Résultat |
|---|---|---:|---:|---:|---|---|
| D-100 | desktop | 100 % | `1800 × 1000` | `1` | complet | PASS |
| D-150 | desktop | 150 % | `1200 × 667` | `1.5` | complet | PASS |
| D-200 | desktop | 200 % | `900 × 500` | `2` | compact | PASS |
| T-100 | tablette | 100 % | `1024 × 768` | `1` | compact | PASS |
| T-150 | tablette | 150 % | `683 × 512` | `1.5` | compact | PASS |
| T-200 | tablette | 200 % | `512 × 384` | `2` | compact | PASS |
| M-100 | mobile | 100 % | `390 × 844` | `1` | compact | PASS |
| M-150 | mobile | 150 % | `260 × 563` | `1.5` | compact | PASS |
| M-200 | mobile | 200 % | `195 × 422` | `2` | compact | PASS |

Les neuf lignes vérifient : texte UTF-8 exact, pile calculée, taille des
racines et enfants, cibles des sous-menus, absence de collision, panneaux dans
le viewport, retour à la ligne des enfants, absence de débordement horizontal,
position de la marque, texte courant, titre long et titre ludique court. Les
tiroirs compacts ont aussi été fermés par `Échap`, par la croix et par le fond
assombri ; les actions de compte restent accessibles après défilement.

Un balayage de seuil a produit 80 mesures. Le menu reste complet et sans
collision à `1160px`, devient compact à `1150px`, puis redevient complet à
`1170px` lors de l’élargissement. Douze mesures espacées de `100ms` à chacun de
ces trois seuils sont restées dans le même état : aucune oscillation n’a été
observée.

## Preuve des glyphes et de la marque

Les caractères `É é À à & ’` sont présents dans le DOM avec leurs points de
code exacts, possèdent chacun un rectangle peint non nul et sont visibles dans
la capture dédiée. Les libellés réels couvrent `É`, `é`, `À`, `&` et `’` ; la
ligne visible dédiée couvre aussi le `à` minuscule absent du menu final.

Chromium rapporte `WenQuanYi Zen Hei` comme fonte de plateforme effectivement
utilisée pour `Concerts & Événements`, `À propos`, `D’Jam` et la ligne de
glyphes. Aucun enregistrement de fonte Taga ou Jura n’apparaît pour ces textes.
La capture a été inspectée : aucun glyphe de remplacement, caractère rogné ou
translittéré n’est visible.

Sur desktop, l’écart calculé entre le titre et la marque est de `6px`, leurs
centres verticaux coïncident et la marque fait `48px` de large. Sur mobile, la
marque passe à `44px`, puis `32px` dans les viewports de reflow très étroits.
`mark-latest.png` est chargé avec des dimensions naturelles positives.
`.brand__logo` n’est pas rendu par la configuration locale (`count = 0`) :
aucun doublon visuel n’a donc été supposé ni supprimé, et tous les assets
existants sont préservés.

## Checklist de lecture et de pages

- [x] **Header desktop** — les cinq racines restent sur une ligne aux largeurs
  normales, à `.92rem`, sans chevaucher la marque ou les actions de compte ;
  les cibles racines mesurent au moins `32px` de haut et les sous-menus restent
  dans le viewport.
- [x] **Header tablette** — la bascule compacte intervient avant collision et
  reste stable pendant réduction et réélargissement.
- [x] **Tiroir mobile** — les cinq racines et sept enfants sont présents dans
  le bon ordre ; les boutons de divulgation font `44 × 44px`, les enfants
  peuvent revenir à la ligne, et aucun défilement horizontal n’apparaît jusqu’à
  `195px` CSS.
- [x] **Titres longs** — la pile système et la graisse `700` sont calculées sur
  desktop, tablette et mobile ; les lignes reviennent sans rognage ni
  débordement.
- [x] **Paragraphes normaux** — pile système, graisse normale, ratio
  d’interlignage au moins `1.55` et mesure éditoriale contenue ; aucun texte
  n’est coupé horizontalement.
- [x] **`/accueil`** — HTTP 200 sur la fixture locale, titre et introduction
  lisibles, CTA de réservation et six cartes ; le titre du site et la marque
  restent côte à côte.
- [x] **`/cours`** — HTTP 200 sur la fixture locale, introduction, trois cartes,
  CTA vers `/reservation-cours` et long titre lisible.
- [x] **`/stages`** — HTTP 200 sur la fixture locale, introduction, trois
  familles et section « Stages à venir », sans collision.
- [x] **`/association`** — HTTP 200 sur la fixture locale, deux paragraphes
  d’introduction, cinq cartes, section projets et liens D’Jam/Orchestre ; le
  long titre reste lisible sur mobile.
- [x] **`/reservation-cours`** — HTTP 200 sur la route réelle, cinq étapes,
  panneau d’identification et actions lisibles aux trois viewports ; aucune
  soumission et aucune modification du CSS propre au tunnel.

Ces cinq routes ont produit `15/15` contrôles PASS à `1440 × 900`,
`768 × 1024` et `390 × 844`. Cette validation porte sur le rendu local de la
branche et ses fixtures réversibles ; elle ne constitue ni un test du VPS ni
une validation des contenus de production.

## Validation statique

Commandes exécutées depuis la racine du dépôt :

```bash
typography_base=origin/release/prod
typography_css=drupal/web/themes/custom/unisonges_theme/css/styles.css

git diff --check "$typography_base"

awk '
  {
    braces_open += gsub(/\{/, "&")
    braces_close += gsub(/\}/, "&")
    comments_open += gsub(/\/\*/, "&")
    comments_close += gsub(/\*\//, "&")
  }
  END {
    printf "braces=%d/%d comments=%d/%d\n", braces_open, braces_close, comments_open, comments_close
    exit braces_open != braces_close || comments_open != comments_close
  }
' "$typography_css"

expected_files="$(printf '%s\n' \
  docs/functional/theme-typography-readability-2026.md \
  drupal/web/themes/custom/unisonges_theme/css/styles.css | LC_ALL=C sort)"
changed_files="$(git diff --name-only "$typography_base" | LC_ALL=C sort)"
test "$changed_files" = "$expected_files"

test -z "$(git diff --name-only "$typography_base" | rg -i \
  '(^|/)fonts?/|\.(woff2?|ttf|otf|eot)$' || true)"
test -z "$(git diff --name-only "$typography_base" | rg -i \
  '(^|/)(logo|mark)[^/]*\.(avif|gif|jpe?g|png|svg|webp)$' || true)"
test -z "$(git diff --unified=0 "$typography_base" -- "$typography_css" |
  sed -n '/^[+-][^+-]/p' | rg '\.brand__logo' || true)"

git diff --exit-code "$typography_base" -- \
  drupal/web/modules/custom/unisonges_structure/css/reservation-first-tunnel.css
test -z "$(git diff --unified=0 "$typography_base" -- "$typography_css" |
  sed -n '/^[+-][^+-]/p' |
  rg 'reservation-(first|portal)|cours-particuliers-reservation|webform-booking|unisonges-checkout-course-notice' || true)"

git diff --exit-code "$typography_base" -- \
  drupal/web/themes/custom/unisonges_theme/css/navigation-submenus.css \
  drupal/web/themes/custom/unisonges_theme/js/navigation-submenus.js \
  drupal/web/themes/custom/unisonges_theme/js/auto-compact-nav.js \
  drupal/web/themes/custom/unisonges_theme/templates/partials/site-header.html.twig \
  drupal/web/themes/custom/unisonges_theme/js/bgfx-scroll-11.js \
  drupal/config/sync \
  .devcontainer
```

Résultats : `git diff --check` sans sortie, équilibre CSS
`braces=500/500 comments=242/242`, garde exacte à deux fichiers, aucune fonte,
aucun asset ou sélecteur `.brand__logo`, aucun fichier interdit et aucun
sélecteur propre au tunnel modifiés.

## Preuves locales

Les 43 captures et les résultats JSON sont conservés sous :

```text
/tmp/pr67-playwright.Ckd3Dc/evidence-final
```

Empreintes principales :

```text
c5e1e057f087a69f9019ff796527ad727bcd921d57b7f47bb77aac846fabd5ad  styles.css testé
931b31342d397903e3c23052e4713af705d36caecbccb169581f971a08e4b319  pr67-browser-results.json
5f043b7445eb023fe72a77139bc3097c93fad40295c80b228936f759a834541e  pr67-threshold-results.json
e85c0e6c74c580443b958f49a1c69ebb62bddcdd8af43f0facd963d93fb91e48  pr67-route-results.json
610691c9b652ab3364d8693c5263e0d9e6733bba5e39035c24c096d0c3fdc1f6  pr67-browser.log
38c0fe860b2dd9ee954d33fa030ca03c56e753ddf78e0cc8f6e1aabb4fcc581d  pr67-route.log
0dc0823e6ac8de7ec22a1d233b36be43d75c0a1bca19f2b73547fb72487540d8  pr67-glyphs.png
```

La matrice locale, l’inspection visuelle des captures et la restauration sont
PASS. La PR peut sortir du mode brouillon après publication de ce commit, sans
fusion dans le cadre de cette intervention.
