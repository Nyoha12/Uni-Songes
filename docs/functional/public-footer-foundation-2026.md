# Fondation du pied de page public — 2026

## Objectif et périmètre

Cette modification fournit exactement un landmark de pied de page à chacun des
deux shells publics du thème Uni-Songes. Le pied de page reste statique : il ne
crée aucune route, aucun contenu Drupal, aucun texte juridique et aucune donnée
d’organisation.

La continuation du 2 septembre 2026 a commencé par un contrôle du worktree :
bonne branche, index et fichiers suivis propres, aucun fichier non suivi, et
branche locale identique au head distant de la PR #94. Après `git fetch`, son
commit unique a été rebasé sans conflit sur `origin/release/prod` au commit
`5b8e80c2e2ac266978ba2be0b8eee2c56a04605f`. Cette base comprend les PR #81,
#83, #84, #91, #93, #99 et #100 fusionnées.

Tous les résultats finaux se rapportent à cette base. Cette phase reste
strictement statique : aucun DDEV, Docker, Drush, Chromium, Playwright, Mailpit,
navigateur ou VPS n’est utilisé. La PR #98 possède exclusivement les ressources
runtime actuelles. La validation Drupal et navigateur de la PR #94 reste donc
différée ; la PR demeure en brouillon.

## Audit du shell existant

### Modèle de défilement et footer

Avant cette modification, le shell normal suivait cette structure :

```text
main#main-content.layout
└── .container
    ├── page.highlighted?
    ├── page.help?
    └── #unisonges-scrollframe.scrollframe
        └── .scrollframe__inner
            └── page.content
footer.site-footer?                  hors scrollframe
└── page.footer
```

Le shell d’accueil s’arrêtait après `page.content` et n’avait aucun footer.
L’ancien `templates/includes/_footer.html.twig` existait comme fichier, mais
aucun template ne l’incluait. Sa présence ne constituait donc ni un chemin de
rendu ni une configuration active.

La cascade CSS verrouille `html` et `body` à la hauteur du viewport avec
`overflow: hidden`. Elle fixe `#unisonges-scrollframe`, lui donne une hauteur
contrainte, `overflow: auto` et un `z-index` de 2000. Le footer normal extérieur
restait dans le flux racine non défilable et à un niveau visuel inférieur. Le
contenu fixe du frame ne contribuait pas à la hauteur de son ancêtre ; le footer
pouvait donc se trouver derrière le frame ou hors viewport. Le focus clavier ne
pouvait pas faire défiler le frame vers ce footer, puisqu’il n’en était pas un
descendant.

Le contrôleur autonome BGFX lit exclusivement `scrollTop`, `scrollHeight` et
`clientHeight` de `#unisonges-scrollframe`. Un footer extérieur ne faisait pas
partie de sa fin de parcours. Son déplacement dans `.scrollframe__inner`
l’ajoute au même flux et à la même plage de défilement, sans nouveau conteneur
contraint. La règle historique `overflow: auto` de `.scrollframe__inner` reste
inchangée ; cet élément n’a ni hauteur ni hauteur maximale, et ne crée donc pas
une seconde plage de défilement indépendante dans cette structure.

### Région Drupal et comportement de Bootstrap Barrio

`system.theme.yml` désigne `unisonges_theme` comme thème public par défaut. Son
fichier `unisonges_theme.info.yml` déclare seulement les régions `header`,
`primary_menu`, `content` et `footer`. L’inventaire des 19 blocs synchronisés
ayant `theme: unisonges_theme` ne trouve aucun placement dans `region: footer`.
Ainsi, aucun bloc représenté dans le dépôt ne peuple actuellement
`page.footer`. Une configuration active non exportée ne peut pas être exclue
sans interroger Drupal ; cette vérification est différée.

Composer verrouille Bootstrap Barrio 5.5.20. Son template de page fournit son
propre footer et cinq régions `footer_*`, mais les deux templates de page du
sous-thème remplacent ce template. Barrio n’ajoute donc aucun footer autour de
leur sortie. Les blocs `bootstrap_barrio_powered` et `olivero_powered`
appartiennent à d’autres thèmes et ne peuplent pas la région Uni-Songes.

La classe `.site-footer` et la classe `.container` sont déjà définies dans la
feuille du thème. Les utilitaires `d-flex` et `flex-column` sont fournis par
Bootstrap 5 : le sous-thème hérite de Bootstrap Barrio 5.5.20, qui requiert
`twbs/bootstrap ^5`, verrouillé ici en 5.3.8, et la configuration synchronisée
sélectionne sa bibliothèque `production`. Ils sont appliqués seulement à `main` afin de
préserver l’ordre flex de #99 sans nouveau style. La liste du footer se replie
naturellement lorsque la largeur diminue.

### Landmarks, messages, comptes et navigation existants

La PR #81 a établi un unique `main#main-content` dans chaque shell, cible du
lien d’évitement hérité de Bootstrap Barrio. La PR #84 conserve un seul H1
sémantique. La PR #100 conserve un seul bloc actif `system_messages_block` du
thème dans la région `content`, au poids `-8`, et un seul wrapper en flux normal
`.unisonges-system-messages`. Les shells rendent `page.content` exactement une
fois dans `main`, ne rendent jamais `page.header`, et le footer ne contient ni
message, ni destination tardive, ni contournement JavaScript. Le chemin des
messages reste donc unique, dans le contenu principal, sans wrapper fixe ou
toast.

La PR #99 applique ses classes et sa bibliothèque uniquement aux routes
d’authentification et de compte. Sa colonne flex historique était
`.scrollframe__inner`, tandis que ses blocs titre et messages portent
respectivement `order: -30` et `order: -20`. L’interposition sémantique de
`main` les aurait rendus petits-enfants non flex ; `main.d-flex.flex-column`
rétablit donc leur ordre titre → messages → formulaire malgré les poids de blocs
source −7 et −8. Le formulaire, ses actions et ses liens contextuels restent
descendants de `page.content` dans `main`. Le footer est le frère suivant de
`main` : il ne devient ni descendant du formulaire ni action d’authentification,
même si les deux participent à l’unique parcours du scrollframe. Aucun fichier
CSS, bibliothèque ou preprocess de #99 n’est modifié.

La PR #103, encore ouverte, réserve son bloc éditorial d’accueil à la région
`content`. Comme `page.content` reste opaque et rendu une seule fois, ce bloc,
son rail, ses disclosures et sa liste d’articles resteront tous dans `main` ; le
footer suivra l’ensemble du contenu éditorial sans envelopper la liste ni
dupliquer un lien Blog. Aucun fichier de la PR #103 n’est modifié ici.

La source serveur du menu principal reste l’unique bloc
`system_menu_block:main`, rendu une fois par `page.primary_menu` dans le header.
Le drawer mobile vide reçoit ensuite seulement une copie JavaScript de sa liste
racine avec des IDs réécrits. Aucun fichier de header, de navigation ou de
drawer n’est modifié ici.

L’architecture de menu finale confirmée conserve cinq racines : Cours & Stages,
Concerts & Événements, Projets collectifs, À propos et Contact. Le footer
statique réutilise seulement les cinq destinations publiques explicitement
confirmées pour ce périmètre ; il n’essaie pas de reproduire tout l’arbre du
menu principal.

### Chevauchement avec les PR ouvertes

La liste complète des noms de fichiers a été relue par pagination via l’API
GitHub le 2 septembre 2026. Les 16 PR ouvertes représentent 81 entrées : #82
(11), #85 (5), #86 (2), #87 (2), #88 (6), #89 (4), #90 (4), #92 (6), #94
(4), #95 (2), #96 (4), #97 (3), #98 (2), #101 (5), #102 (4) et #103 (17).
Hors les quatre entrées de #94 elle-même, les 77 entrées des 15 autres PR ne
recoupent aucun des quatre chemins de ce périmètre.

La PR #98 possède les ressources runtime et modifie seulement
`docs/functional/background-motion-2026.md` et
`drupal/web/themes/custom/unisonges_theme/js/bgfx-scroll-11.js`.

Les 17 fichiers réservés à la PR #103 sont :

- `docs/functional/forum-blog-mvp-2026.md` ;
- `docs/functional/home-editorial-blog-implementation-2026.md` ;
- `drupal/config/sync/block.block.unisonges_editorial_home.yml` ;
- `drupal/config/sync/core.extension.yml` ;
- `drupal/config/sync/views.view.blog_posts.yml` ;
- `drupal/scripts/apply-editorial-home-blog-2026.sh` ;
- `drupal/scripts/editorial-home-blog-config.php` ;
- `drupal/scripts/forum-blog-mvp-config.php` ;
- `drupal/web/modules/custom/unisonges_editorial_home/css/editorial-home.css` ;
- `drupal/web/modules/custom/unisonges_editorial_home/src/EditorialHomeBuilder.php` ;
- `drupal/web/modules/custom/unisonges_editorial_home/src/EditorialHomeUninstallValidator.php` ;
- `drupal/web/modules/custom/unisonges_editorial_home/src/Plugin/Block/EditorialHomeBlock.php` ;
- `drupal/web/modules/custom/unisonges_editorial_home/templates/unisonges-editorial-home.html.twig` ;
- `drupal/web/modules/custom/unisonges_editorial_home/unisonges_editorial_home.info.yml` ;
- `drupal/web/modules/custom/unisonges_editorial_home/unisonges_editorial_home.libraries.yml` ;
- `drupal/web/modules/custom/unisonges_editorial_home/unisonges_editorial_home.module` ;
- `drupal/web/modules/custom/unisonges_editorial_home/unisonges_editorial_home.services.yml`.

Aucun de ces fichiers n’est modifié. Les CSS, JavaScript, PHP, bibliothèques,
configuration, navigation, header et autres fichiers fusionnés restent tous
hors du diff de #94.

## Structure et stratégie retenues

Les deux shells ont maintenant la même hiérarchie accessible :

```text
#unisonges-bgfx
└── #unisonges-bgfx-scroll
    └── #unisonges-bgfx-layer
site-header
#unisonges-scrollframe.scrollframe
└── .scrollframe__inner
    ├── main#main-content.d-flex.flex-column
    │   ├── page.highlighted? / page.help?  shell normal seulement
    │   └── page.content                    une fois
    └── footer.site-footer                  une fois
        └── .container
            ├── identité « Uni-Songes »
            └── page.footer ou navigation de repli
```

L’include `_footer.html.twig` possède le seul élément `<footer>`. Chaque shell
l’inclut exactement une fois après `main`, dans `.scrollframe__inner`. La
structure du header fixe, l’ID du scrollframe et les IDs des trois couches BGFX
restent inchangés.

La stratégie de région est volontairement exclusive et déterministe :

- si `page.footer` est renseigné, son contenu est rendu une fois et remplace la
  navigation de repli ;
- si `page.footer` est vide, la navigation statique de repli est rendue ;
- l’identité concise « Uni-Songes » reste visible dans les deux cas.

Cette branche évite de rendre simultanément des liens de repli et un futur bloc
administré qui pourrait contenir les mêmes liens. Elle fournit néanmoins un
landmark et un contenu utile lorsque la région est vide.

Les liens visibles dans le cas de repli sont exactement :

| Libellé | Destination |
| --- | --- |
| Cours & Stages | `/cours-et-stages` |
| Projets collectifs | `/ateliers` |
| À propos | `/a-propos` |
| Blog | `/blog` |
| Contact | `/contact` |

Les liens `/mentions-legales` et `/politique-confidentialite` sont
intentionnellement absents. Leur existence canonique Drupal et leur contenu
approuvé doivent faire l’objet d’une décision explicite du propriétaire de
contenu. Aucun texte ne doit être repris du site Cloudflare historique et aucun
lien ne devra être publié avant cette validation.

## Validation statique

Les trois templates Twig modifiés sont compilés, puis les deux shells sont
rendus avec quatre fixtures déterministes : page normale et accueil, chacun
avec une région `page.footer` vide puis peuplée. Les sorties complètes incluent
le lien d’évitement hérité et des marqueurs distincts pour le contenu, la
navigation principale, le chemin de messages #100 et la région footer.

Les assertions couvrent :

- un seul `main#main-content`, un seul footer et un seul scrollframe par sortie ;
- les classes Bootstrap `d-flex flex-column` présentes sur chaque main pour
  conserver l’ordre titre → messages → contenu des surfaces #99 ;
- `page.content` exactement une fois et `page.footer` au plus une fois ;
- identité « Uni-Songes » exactement une fois dans le footer, région vide ou
  peuplée ;
- footer frère suivant immédiatement `main` dans `.scrollframe__inner` ;
- cible `#main-content` unique et réellement référencée par le lien d’évitement ;
- un seul header, une seule source de navigation principale et un seul drawer ;
- un seul `.unisonges-system-messages`, dans `main` et jamais dans le header ou
  le footer ; aucun rendu de `page.header` dans les sources ;
- trois IDs BGFX uniques et inchangés ;
- cinq destinations de repli exactes lorsque la région est vide, aucune lorsque
  la région est peuplée ;
- aucune URL juridique ou de confidentialité émise ;
- aucune imbrication de `.scrollframe` et aucun nouveau conteneur défilant ;
- HTML valide, ordre des landmarks, titres et ordre clavier cohérents ;
- UTF-8 normalisé NFC, diff sans erreur d’espace et garde exacte des fichiers ;
- aucune modification de CSS, bibliothèque, JavaScript ou configuration ;
- absence de secret ou identifiant d’accès dans le diff ;
- absence de chevauchement avec les fichiers des PR ouvertes.

### Résultats

Le passage final utilise Node 24.20.0, Twig.js 3.0.0 et html-validate 9.7.1.
Ces deux paquets npm sont chargés depuis le cache de validation existant ;
aucune dépendance, lockfile ou sortie de fixture n’est ajoutée au dépôt.

- `Twig.compile()` accepte les trois templates modifiés : 3/3 ;
- les fixtures `normal-empty`, `normal-populated`, `front-empty` et
  `front-populated` sont rendues intégralement : 4/4 ;
- le preset recommandé de html-validate accepte les quatre documents : 4/4,
  zéro erreur et zéro avertissement ; seules les règles `no-redundant-role`,
  `void-style` et `no-trailing-whitespace` sont neutralisées pour les motifs
  hérités et inchangés `role="main"`, `<img />` et espaces du partial de header ;
- chaque sortie contient un main, un footer, un contenu, un header, une source
  serveur de navigation, un drawer, un scrollframe et un exemplaire de chaque ID
  BGFX ; les IDs sont tous uniques et chaque main porte `d-flex flex-column` ;
- deux fixtures exercent `page.primary_menu` et deux son fallback exclusif
  `page.navigation` ; chacune ne produit qu’une source serveur et un drawer ;
- chaque sortie contient exactement un marqueur `.unisonges-system-messages`
  dans `main`, aucun dans le header ou le footer ; les deux shells ne rendent
  pas `page.header` ;
- les deux fixtures vides rendent les cinq libellés et destinations de repli
  exacts ; les deux fixtures peuplées rendent le marqueur de région une fois et
  aucun lien de repli ; les quatre rendent l’identité une fois ;
- le footer n’ajoute aucun titre, rôle redondant, `tabindex` positif, claim
  d’organisation ou route légale/confidentialité ;
- l’audit de configuration trouve 19 blocs synchronisés Uni-Songes et zéro bloc
  placé dans sa région footer ;
- l’audit #100 confirme le bloc messages unique en `content/-8`, le titre en
  `content/-7`, l’unique template `.unisonges-system-messages` en flux et aucun
  chemin `page.header` ; la colonne flex de `main` préserve l’ordre #99
  titre → messages → formulaire ;
- l’audit #103 confirme que le composant ne possède aucun main, H1, footer,
  header ou message et que sa CSS laisse l’outer scrollframe seul contraint ;
- les 15 autres PR ouvertes représentent 77 entrées de fichiers et zéro
  chevauchement avec les quatre fichiers de cette branche ;
- les quatre fichiers sont du UTF-8 sans BOM, normalisé NFC ; le scan ciblé des
  ajouts ne trouve aucune signature de clé privée, PAT, clé cloud, clé de
  paiement, bearer token ou affectation de credential ;
- `git diff --check`, la garde exacte des quatre fichiers et les gardes CSS,
  bibliothèque, JavaScript et configuration réussissent sur la base finale ;
- la première passe indépendante a détecté la perte du contexte flex #99 ; après
  ajout de `d-flex flex-column` aux deux mains et nouvelle exécution des
  fixtures, les revues accessibilité et fixed-scroll concluent à un PASS
  statique sans bloqueur ;
- le contrôleur BGFX conserve les mêmes IDs, reste autonome sans listener de
  scroll ni dépendance à `scrollTop`, tandis que seul le scrollframe extérieur
  possède une hauteur contrainte. Le focus visible, le dernier cran, le tactile
  et l’absence effective de piège restent dans la matrice runtime différée.

Les commandes de garde simples, relancées depuis la racine, sont :

```bash
git diff --check origin/release/prod --
git diff --name-only origin/release/prod --
git ls-files --others --exclude-standard
rg -n 'page\.content|page\.footer|main-content|unisonges-scrollframe' \
  drupal/web/themes/custom/unisonges_theme/templates/page*.html.twig
rg -n 'mentions-legales|politique-confidentialite' \
  drupal/web/themes/custom/unisonges_theme/templates
```

Le harness Node en mémoire enregistre les fonctions Drupal factices
`attach_library()`, `path()` et `include()`, résout le namespace
`@unisonges_theme`, compile les trois sources, rend les quatre contextes, valide
leur HTML complet puis interroge le DOM de html-validate. Cette méthode ne
bootstrappe pas Drupal et ne simule pas un résultat runtime.

### Reproduction exacte des fixtures

La commande suivante reconstruit le harness final dans un répertoire temporaire
avec les versions exactes. Elle requiert Node/npm et un accès au registre npm,
mais ne crée aucun fichier dans le dépôt :

```bash
footer_validation_dir="$(mktemp -d /tmp/unisonges-footer-validation.XXXXXX)"
npm install --prefix "$footer_validation_dir" --ignore-scripts --no-save \
  --no-package-lock twig@3.0.0 html-validate@9.7.1
node - \
  "$footer_validation_dir/node_modules/twig" \
  "$footer_validation_dir/node_modules/html-validate" <<'NODE'
const fs = require('node:fs');
const path = require('node:path');
const Twig = require(process.argv[2]);
const { HtmlValidate } = require(process.argv[3]);
const root = path.resolve('drupal/web/themes/custom/unisonges_theme/templates');
const sources = [
  'page.html.twig',
  'page--front.html.twig',
  'includes/_footer.html.twig',
];
const expectedLinks = [
  ['/cours-et-stages', 'Cours & Stages'],
  ['/ateliers', 'Projets collectifs'],
  ['/a-propos', 'À propos'],
  ['/blog', 'Blog'],
  ['/contact', 'Contact'],
];
const ok = (value, message) => {
  if (!value) throw new Error(message);
};
const visible = element => element.textContent
  .replaceAll('&amp;', '&')
  .replace(/\s+/gu, ' ')
  .trim();

Twig.extendFunction('attach_library', () => '');
Twig.extendFunction('path', route => ({
  '<front>': '/',
  'user.page': '/user',
  'user.login': '/user/login',
  'user.logout': '/user/logout',
  'user.register': '/user/register',
})[route] || '/fixture');
Twig.extendFunction('include', function (file) {
  return this.template.importFile(file)
    .render(this.context, { isInclude: true });
});

for (const source of sources) {
  const filename = path.join(root, source);
  const compiled = Twig.compile(fs.readFileSync(filename, 'utf8'), {
    filename,
    settings: {
      'twig options': { namespaces: { unisonges_theme: root } },
    },
  });
  ok(typeof compiled === 'function', `compile ${source}`);
}

const validator = new HtmlValidate({
  extends: ['html-validate:recommended'],
  rules: {
    'no-redundant-role': 'off',
    'void-style': 'off',
    'no-trailing-whitespace': 'off',
  },
});
const fixtures = [
  ['normal-empty', 'page.html.twig', false, 'primary_menu'],
  ['normal-populated', 'page.html.twig', true, 'navigation'],
  ['front-empty', 'page--front.html.twig', false, 'primary_menu'],
  ['front-populated', 'page--front.html.twig', true, 'navigation'],
];

(async () => {
  for (const [name, shell, populated, navSlot] of fixtures) {
    const template = Twig.twig({
      path: path.join(root, shell),
      async: false,
      namespaces: { unisonges_theme: root },
      rethrow: true,
    });
    const fixtureNav = `<nav data-fixture-nav="${name}" `
      + 'aria-label="Navigation principale"></nav>';
    const fragment = template.render({
      site_name: 'Uni-Songes',
      logo: '',
      logged_in: false,
      page: {
        highlighted: '',
        help: '',
        navigation: navSlot === 'navigation' ? fixtureNav : '',
        primary_menu: navSlot === 'primary_menu' ? fixtureNav : '',
        content: '<div class="unisonges-system-messages" '
          + `data-drupal-messages data-fixture-message="${name}">`
          + '<div class="messages__wrapper"></div></div>'
          + `<article data-fixture-content="${name}">`
          + '<h1>Fixture</h1></article>',
        footer: populated
          ? `<div data-fixture-region="${name}">Région configurée</div>`
          : '',
      },
    });
    const html = '<!DOCTYPE html><html lang="fr"><head>'
      + '<meta charset="utf-8"><title>Fixture</title></head><body>'
      + '<a href="#main-content">Éviter</a>'
      + fragment
      + '</body></html>';
    const report = await validator.validateString(html, `${name}.html`);
    ok(report.valid && report.errorCount === 0 && report.warningCount === 0,
      `${name}: HTML`);
    const parser = await validator.getParserFor(`${name}.html`);
    const dom = parser.parseHtml(html);
    const count = selector => dom.querySelectorAll(selector).length;
    const expectedCounts = [
      ['main#main-content', 1],
      ['main#main-content.d-flex.flex-column', 1],
      ['footer.site-footer', 1],
      ['footer.site-footer > .container > p', 1],
      ['#unisonges-scrollframe.scrollframe', 1],
      ['.scrollframe .scrollframe', 0],
      ['.scrollframe__inner > main#main-content + footer.site-footer', 1],
      ['main footer', 0],
      ['.unisonges-system-messages[data-drupal-messages]', 1],
      ['main#main-content .unisonges-system-messages', 1],
      ['header .unisonges-system-messages, footer .unisonges-system-messages', 0],
      [`[data-fixture-message="${name}"]`, 1],
      ['a[href="#main-content"]', 1],
      ['header.site-header', 1],
      [`[data-fixture-nav="${name}"]`, 1],
      ['#mobile-drawer', 1],
      ['#unisonges-bgfx', 1],
      ['#unisonges-bgfx-scroll', 1],
      ['#unisonges-bgfx-layer', 1],
      ['#unisonges-bgfx > #unisonges-bgfx-scroll > #unisonges-bgfx-layer', 1],
      [`[data-fixture-content="${name}"]`, 1],
      [`[data-fixture-region="${name}"]`, populated ? 1 : 0],
      ['footer h1, footer h2, footer h3, footer h4, footer h5, footer h6', 0],
    ];
    for (const [selector, total] of expectedCounts) {
      ok(count(selector) === total, `${name}: ${selector}`);
    }
    const ids = dom.querySelectorAll('[id]')
      .map(node => node.getAttributeValue('id'));
    ok(ids.length === new Set(ids).size, `${name}: duplicate id`);
    ok(dom.querySelectorAll('[tabindex]').every(node =>
      Number(node.getAttributeValue('tabindex')) <= 0), `${name}: tabindex`);
    ok(!/mentions-legales|politique-confidentialite/u.test(html),
      `${name}: legal route`);
    ok(!/copyright|téléphone|adresse|siret|rna/iu
      .test(visible(dom.querySelector('footer'))), `${name}: claim`);
    ok(visible(dom.querySelector('footer.site-footer > .container > p'))
      === 'Uni-Songes', `${name}: identity`);
    const fallback = dom.querySelectorAll('footer nav').find(node =>
      node.getAttributeValue('aria-label') === 'Navigation de pied de page');
    if (populated) {
      ok(!fallback && count('footer a') === 0, `${name}: duplicate fallback`);
    }
    else {
      ok(Boolean(fallback), `${name}: fallback`);
      const links = fallback.querySelectorAll('a').map(node =>
        [node.getAttributeValue('href'), visible(node)]);
      ok(JSON.stringify(links) === JSON.stringify(expectedLinks),
        `${name}: links`);
      ok(fallback.querySelectorAll('ul > li > a').length === 5,
        `${name}: list`);
    }
    console.log(`FIXTURE OK ${name}`);
  }
  console.log('TWIG 3/3; FIXTURES/HTML/DOM 4/4 OK');
})().catch(error => {
  console.error(error.message);
  process.exitCode = 1;
});
NODE
```

## Revue clavier et landmarks

L’ordre source reste : lien d’évitement hérité, header et contrôles de
navigation, contenu principal, puis navigation de footer. Activer le lien
d’évitement cible toujours l’unique `main#main-content`, désormais descendant
du vrai conteneur défilant. Les liens du footer suivent tout le contenu
principal ; leur prise de focus demande au même scrollframe de les rendre
visibles. Aucun `tabindex`, rôle ARIA redondant, footer fixe ou JavaScript n’est
ajouté.

Le `<footer>` est frère de `main`, et non son descendant. Il représente donc le
pied de page du site et bénéficie du rôle implicite `contentinfo`. La navigation
de repli possède un nom accessible propre. Les liens sont dans une liste
sémantique verticale et peuvent revenir à la ligne sans largeur imposée.

## Matrice Drupal et navigateur différée

Au 2 septembre 2026, la PR #98 détient exclusivement DDEV, Docker, Drush,
Chromium, Playwright, Mailpit et l’ensemble des ressources runtime. Aucun de ces
outils, aucun navigateur et aucun accès VPS n’a été utilisé pour #94. La PR #94
reste en brouillon ; sa matrice ne commence qu’après une libération explicite et
consignée par #98, et elle ne sera pas marquée prête avant réussite complète.

La séquence différée est déterministe :

1. attendre la libération et le transfert explicites de #98, sans aucune action
   runtime avant ce signal ;
2. rebaser #94 sur le dernier `origin/release/prod`, relancer les gardes
   statiques, quatre fichiers et PR ouvertes, puis consigner le SHA testé ;
3. sur une pile Drupal locale approuvée, jamais sur le VPS, établir une région
   `page.footer` vide et tester, dans cet ordre, accueil, Basic page ordinaire,
   page courte, page longue, réservation, Blog, Forum, Contact, panier Commerce,
   puis les surfaces #99 connexion, inscription, mot de passe et compte ; pour
   chaque route, parcourir desktop, tablette, puis mobile ;
4. dans chaque cas vide, vérifier l’identité et les cinq alias de repli exacts,
   un header, une source de navigation, un drawer, un seul chemin
   `.unisonges-system-messages` dans un main unique, `page.content` une fois, un
   footer après main, la cible d’évitement, l’ordre clavier, la fin de scroll,
   la visibilité complète du footer, le header fixe, BGFX autonome et sûr aux
   bords, et l’absence de débordement horizontal ou de piège imbriqué ;
5. peupler `page.footer` avec un bloc marqueur contrôlé et répéter le même ordre
   de routes, viewports et contrôles, en exigeant identité et marqueur une fois,
   zéro lien de repli et zéro contenu configuré dupliqué ;
6. si #103 est fusionnée ou présente dans la base testée, parcourir aussi les
   états éditoriaux de `/accueil` avec zéro, un et plusieurs Articles,
   disclosures fermées puis ouvertes et liste longue, dans l’ordre desktop,
   tablette, mobile ; prouver que le bloc complet reste dans `page.content` et
   `main`, puis que le footer le suit. Sinon, conserver cette étape comme porte
   d’intégration future explicite ;
7. surveiller les logs PHP et la console pendant toute la matrice, exiger zéro
   warning ou erreur, restaurer l’état initial de la région footer, reconstruire
   les caches selon la procédure approuvée, refaire un smoke accueil + Basic
   page et conserver les preuves. Alors seulement la PR pourra être envisagée
   comme prête ; cette tâche ne la fusionne pas.

La matrice complète associée est :

| Scénario | Viewports | Vérifications attendues | Statut |
| --- | --- | --- | --- |
| Accueil | Desktop, tablette, mobile | Footer en fin du frame, contenu et landmarks uniques | Différé |
| Basic page ordinaire | Desktop, tablette, mobile | Titre, messages, contenu, main et footer uniques | Différé |
| Page courte | Desktop, tablette, mobile | Footer lisible sans masquer le contenu | Différé |
| Page longue | Desktop, tablette, mobile | Scroll continu jusqu’au footer, sans second scroll | Différé |
| Réservation | Desktop, tablette, mobile | Formulaire, erreurs et messages rendus une fois | Différé |
| Blog et Forum | Desktop, tablette, mobile | Blocs dynamiques et footer sans doublon | Différé |
| Contact | Desktop, tablette, mobile | Formulaire, erreurs et messages sans doublon | Différé |
| Panier Commerce | Desktop, tablette, mobile | Panier vide/peuplé, messages et footer accessibles | Différé |
| Authentification et compte #99 | Desktop, tablette, mobile | Surface route-scopée dans main ; footer après main et hors formulaire | Différé |
| Accueil éditorial #103, si présent | Desktop, tablette, mobile | États Article/disclosure ; contenu complet dans main, footer ensuite | Différé |
| Région `page.footer` vide | Desktop, tablette, mobile | Cinq liens de repli exacts | Différé |
| Région `page.footer` peuplée | Desktop, tablette, mobile | Bloc une fois, aucun lien de repli dupliqué | Différé |
| Parcours clavier | Desktop, tablette, mobile | Ordre header → main → footer, focus toujours visible | Différé |
| Lien d’évitement | Desktop, tablette, mobile | Focus et viewport atteignent `#main-content` | Différé |
| Fin du scrollframe | Desktop, tablette, mobile | Footer entièrement visible au dernier cran | Différé |
| DOM et arbre accessible | Desktop, tablette, mobile | Un main, un contentinfo, navigation nommée | Différé |
| Header fixe | Desktop, tablette, mobile | Header et drawer utilisables, aucun contenu masqué | Différé |
| BGFX autonome | Desktop, tablette, mobile | Fond fixe, mouvement autonome et bords sûrs | Différé |
| Débordement | Desktop, tablette, mobile | Aucun débordement horizontal | Différé |
| Piège de défilement | Desktop, tablette, mobile | Aucun scrollframe imbriqué ni piège clavier/tactile | Différé |
| Diagnostics serveur | Toutes les routes ci-dessus | Aucun warning ou fatal PHP | Différé |
| Diagnostics client | Toutes les routes ci-dessus | Aucune erreur de console navigateur | Différé |

La validation devra tester les réponses HTML serveur et le DOM après
enrichissement JavaScript, puis confirmer visuellement le dernier état du
scrollframe. Le CTA éditorial `/blog` de #103 et le lien `/blog` du footer ont
des fonctions distinctes ; aucun thème, pager ou bloc de navigation éditorial
ne doit être copié dans le footer. Une fois cette matrice terminée, le document
pourra recevoir les preuves runtime dans une PR de validation dédiée ou un
commit de suivi revu.
