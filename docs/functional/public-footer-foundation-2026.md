# Fondation du pied de page public — 2026

## Objectif et périmètre

Cette modification fournit exactement un landmark de pied de page à chacun des
deux shells publics du thème Uni-Songes. Le pied de page reste statique : il ne
crée aucune route, aucun contenu Drupal, aucun texte juridique et aucune donnée
d’organisation.

Reprise du 7 septembre 2026 depuis le head publié
`65cbfbd347756cd73ccec202cf7da27de31e8c24`, worktree propre. Après fetch et
vérification de la ref GitHub, rebase sans conflit sur `origin/release/prod`
`9ef3d4a2c260af9f3f2fcfe4ac584648bb592e0c`. Cette base comprend notamment
#99/#100 et #103, fusionnée le 3 septembre au commit `36b023c`.

Tous les résultats finaux se rapportent à cette base. Cette phase reste
strictement statique : aucun DDEV, Docker, Drush, Chromium, Playwright, Mailpit,
navigateur ou VPS n’est utilisé. Terminal 1 / PR #113 possède seul DDEV et le
checkout servant. Le runtime de #94 reste différé, soumis à une nouvelle
autorisation même après la fin de #113 ; la PR demeure en brouillon.

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

BGFX reste autonome depuis #91/#98 : aucune dépendance à `scrollTop` ni listener
de scroll. Le footer dans `.scrollframe__inner` contribue à la plage de
défilement existante. La règle historique `overflow: auto` de cet inner n’est
pas accompagnée d’une hauteur contrainte ; #99 et #103 la remplacent par
`overflow: visible` sur leurs routes. Aucun nouveau scroller n’est ajouté.

Les règles de géométrie relues sont `.site-footer` et `.container`
(`css/styles.css:3,39`), le frame fixe et sa hauteur de viewport (`:1458–1473`),
son centrage/largeur/z-index et l’overflow de l’inner (`:1540–1554`).
`css/auth-account.css:43–65` borne la largeur/hauteur du frame des comptes et
définit l’inner flex. `editorial-home.css:2–12` garde le même frame sur
`body.section-accueil`, avec padding adaptatif et inner overflow-visible ; sa
grille interne gère le rail et les Articles. Les dimensions calculées, le
repliement et l’absence effective de piège restent à vérifier en runtime.

### Région Drupal et comportement de Bootstrap Barrio

`system.theme.yml` désigne `unisonges_theme` comme thème public par défaut. Son
fichier `unisonges_theme.info.yml` déclare seulement les régions `header`,
`primary_menu`, `content` et `footer`. L’audit conservé du 2 septembre comptait
19 blocs synchronisés Uni-Songes, aucun dans `footer` ; ce total historique
précède #103. Son nouveau bloc est explicitement dans `content`, poids 0.
La configuration active n’a pas été interrogée ici : la présence d’un fichier
YAML ne prouve pas l’état Drupal actuellement déployé.

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
sémantique. La PR #100 prévoit un seul bloc `system_messages_block` synchronisé du
thème dans la région `content`, au poids `-8`, et un seul wrapper en flux normal
`.unisonges-system-messages`. Les shells rendent `page.content` exactement une
fois dans `main`, ne rendent jamais `page.header`, et le footer ne contient ni
message, ni destination tardive, ni contournement JavaScript. Le chemin des
messages reste donc unique, dans le contenu principal, sans wrapper fixe ou
toast.

Les classes `main.d-flex.flex-column` validées lors de la première passe sont
conservées. #99 définit le titre à `order: -30` et les messages à `order: -20`,
avec les poids de blocs source −7 et −8. Les fixtures vérifient le main flex et
le chemin de messages, mais ne prouvent pas l’effet des wrappers de région
Drupal/Barrio sur cet ordre visuel : cela reste un test runtime. Le formulaire,
ses actions et le compte restent dans `page.content`, le footer après `main`
et hors de tout formulaire. Aucun fichier de #99/#100 n’est modifié.

#103 est fusionnée. Son bloc `content/0`, ses deux panneaux `details`, sa
liste et son pager restent sous `page.content` dans `main`, avant le footer.
Les fixtures utilisent maintenant le template éditorial réellement fusionné.
Les gardes du helper #103 verrouillent les configurations titre/messages/contenu,
pas le texte des shells : elles restent intactes. L’activation du bloc et son
marqueur de déploiement exigent Drupal ; leur présence dans Git ne suffit pas.

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

### Périmètre et preuves conservées

L’audit du 2 septembre avait contrôlé 77 entrées de fichiers des 15 autres PR,
sans chevauchement. Il est conservé comme preuve datée, sans nouvelle campagne
globale le 7 septembre. La garde actuelle compare le diff à la base vérifiée et
exige les quatre fichiers autorisés. Les sources fusionnées #99/#100/#103, les
styles, les dépendances et les autres worktrees ne sont pas modifiés.

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

- `footer_region|render` est évalué une fois et son résultat conservé ;
- si ce résultat n’est pas vide après `trim`, il est affiché une fois et
  remplace la navigation de repli ;
- sinon, la navigation statique de repli est rendue ;
- l’identité concise « Uni-Songes » reste visible dans les deux cas.

Cette branche évite de rendre simultanément des liens de repli et un futur bloc
administré qui pourrait contenir les mêmes liens. Elle fournit néanmoins un
landmark et un contenu utile lorsque la région est vide. Le défaut corrigé le
7 septembre était le test booléen du tableau brut : des métadonnées `#cache`
ou des enfants `#access: false` suffisaient à masquer le repli. Le test porte
désormais sur la sortie, sans `striptags` ni `raw` : un contenu « 0 » ou non
textuel reste accepté. Les wrappers vides, placeholders/lazy builders et la
propagation cache/attachments par le vrai renderer restent à vérifier dans
Drupal ; la simulation ci-dessous ne les reproduit pas.

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

Les preuves de la session précédente (Twig 3/3, quatre fixtures chaîne,
unicité/ordre HTML, revues indépendantes) servent de base. La reprise réutilise
Node 24.20.0, Twig.js 3.0.0 et html-validate 9.7.1 depuis leurs caches existants,
sans installation ni dépendance ajoutée.

Nouvelles vérifications ciblées :

- contre-exemple avant correction : le tableau PHP `[]` est faux, mais
  `['#cache' => …]` et un enfant refusé sont vrais ; le vieux Twig retire le
  repli dans les deux derniers cas ;
- les quatre fixtures chaîne sont conservées ; huit formes de render arrays
  sont créées comme vrais tableaux PHP, transportées en JSON et rendues par un
  filtre factice limité à `#markup`, `#plain_text`, enfants et `#access: false` ;
- normal/accueil × ces dix cas : 20 documents HTML, un main flex, un contenu,
  un header, une source de navigation, un drawer, un chemin de messages, un
  footer frère suivant et un exemplaire de chaque ID BGFX ;
- un appel de `|render` par footer ; zéro ou un contenu configuré, jamais le
  repli en plus ; mêmes cinq alias, sans Ressources, Boutique ni liens légaux ;
- chaque fixture accueil rend le template fusionné #103 avec deux Articles,
  un pager et deux panneaux ; ils sont tous dans main et absents du footer ;
- trois templates modifiés compilés, 20/20 fixtures et assertions DOM réussies ;
  les dix pages normales n’ont aucun diagnostic HTML ; chaque accueil signale
  seulement `unique-landmark` sur `#mobile-drawer`, également reproduit avec le
  shell de base sans footer. Ce drawer `aside hidden` n’a pas de nom propre ;
  le validateur le compte avec le rail #103. Le header est hors périmètre :
  aucune correction ici, à examiner au runtime drawer fermé/ouvert. Tout autre
  diagnostic échoue ; les trois exceptions historiques restent inchangées
  (`role="main"`, `<img />`, espaces) ;
- UTF-8/NFC, diff exact de quatre fichiers, `git diff --check` et scan des
  ajouts pour secrets ; aucun CSS, JS, PHP, bibliothèque ou config modifié.

Les fonctions Drupal sont simulées : ce contrôle ne compile pas avec le moteur
PHP/Twig de Drupal et ne prouve ni cacheabilité, ni activation #103, ni wrappers
réels, ni géométrie calculée. La revue indépendante ciblée confirme l’intégration
structurelle #103 et conserve ces limites explicites, notamment l’ordre #99.

### Reproduction exacte des fixtures

Depuis ce worktree, le harness réutilise les deux caches déjà présents en
lecture seule ; aucune installation et aucun fichier de sortie ne sont requis.
Le PHP CLI crée uniquement les données, sans chargement de Drupal.

```bash
node - \
  /home/vscode/.npm/_npx/f25bb07afff48db4/node_modules/twig \
  /home/vscode/.npm/_npx/a2fa10d1427fa9f6/node_modules/html-validate <<'NODE'
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const Twig = require(process.argv[2]);
const { HtmlValidate } = require(process.argv[3]);
const root = path.resolve('drupal/web/themes/custom/unisonges_theme/templates');
const sources = ['page.html.twig', 'page--front.html.twig', 'includes/_footer.html.twig'];
const expectedLinks = [
  ['/cours-et-stages', 'Cours & Stages'], ['/ateliers', 'Projets collectifs'],
  ['/a-propos', 'À propos'], ['/blog', 'Blog'], ['/contact', 'Contact'],
];
const ok = (value, message) => { if (!value) throw new Error(message); };
const visible = element => element.textContent
  .replaceAll('&amp;', '&').replace(/\s+/gu, ' ').trim();
const escape = value => String(value).replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
let renderCalls = 0;
let safeMarkup;
Twig.extend(core => { safeMarkup = core.Markup; });
// Simulation limitée : pas de theme wrappers, cache, lazy builder ou placeholder.
function renderArray(value) {
  if (value == null || value === false) return '';
  if (typeof value !== 'object') return String(value);
  if (value['#access'] === false) return '';
  if ('#plain_text' in value) return escape(value['#plain_text']);
  if ('#markup' in value) return String(value['#markup']);
  return Object.entries(value).filter(([key]) => !key.startsWith('#'))
    .map(([, child]) => renderArray(child)).join('');
}
Twig.extendFilter('render', value => {
  renderCalls++;
  return safeMarkup(renderArray(value));
});
Twig.extendFilter('t', (value, params = []) =>
  Object.entries(params[0] || {}).reduce((text, [key, replacement]) =>
    text.replaceAll(key, String(replacement)), value));
Twig.extendFunction('attach_library', () => '');
Twig.extendFunction('path', route => ({
  '<front>': '/', 'user.page': '/user', 'user.login': '/user/login',
  'user.logout': '/user/logout', 'user.register': '/user/register',
})[route] || '/fixture');
Twig.extendFunction('include', function (file) {
  return this.template.importFile(file).render(this.context, { isInclude: true });
});
for (const source of sources) {
  const filename = path.join(root, source);
  ok(typeof Twig.compile(fs.readFileSync(filename, 'utf8'), {
    filename, settings: {'twig options': {namespaces: {unisonges_theme: root}}},
  }) === 'function', 'compile ' + source);
}
const marker = '<div data-fixture-region="configured">Région configurée</div>';
const phpCases = JSON.parse(execFileSync('php', ['-r', `
$marker = '<div data-fixture-region="configured">Région configurée</div>';
echo json_encode([
  'array-empty' => [[], false, 0],
  'cache-only' => [['#cache' => ['tags' => ['config:block_list']]], false, 0],
  'empty-markup' => [['#markup' => '', '#attached' => ['library' => []]], false, 0],
  'whitespace' => [['#markup' => " \n\t"], false, 0],
  'denied' => [['block' => ['#access' => false, '#markup' => $marker]], false, 0],
  'nested' => [['#cache' => ['contexts' => ['user.permissions']],
    'block' => ['#markup' => $marker]], true, 1],
  'zero' => [['#markup' => '0'], true, 0],
  'nontext' => [['#markup' => '<hr>'], true, 0],
], JSON_THROW_ON_ERROR);
`], {encoding: 'utf8'}));
const cases = [
  ['empty', ['', false, 0]], ['populated', [marker, true, 1]],
  ...Object.entries(phpCases),
];
const editorial = Twig.twig({
  path: path.resolve('drupal/web/modules/custom/unisonges_editorial_home/'
    + 'templates/unisonges-editorial-home.html.twig'),
  async: false, rethrow: true,
}).render({
  all_articles_url: '/accueil', collection_start_url: '/accueil',
  about_url: '/a-propos', selected_theme: null, theme_invalid: false,
  theme_filtered: false, selected_theme_name: '', themes: [],
  articles: [1, 2].map(i => ({
    title: 'Article ' + i, url: '/node/' + i, datetime: '2026-09-07',
    date: '7 septembre 2026', terms: [], emphasized: i === 1,
    summary: '<p>Résumé de fixture.</p>',
  })),
  pager: {page: 1, previous: '/accueil', next: '/accueil?page=2'},
});
const validator = new HtmlValidate({
  extends: ['html-validate:recommended'],
  rules: {'no-redundant-role': 'off', 'void-style': 'off', 'no-trailing-whitespace': 'off'},
});
(async () => {
  let total = 0;
  for (const [kind, shell] of [['normal', 'page.html.twig'], ['front', 'page--front.html.twig']]) {
    const template = Twig.twig({
      path: path.join(root, shell), async: false,
      namespaces: {unisonges_theme: root}, rethrow: true,
    });
    for (const [state, [footer, populated, markers]] of cases) {
      const name = kind + '-' + state;
      const fixtureNav = '<nav data-fixture-nav="main" aria-label="Navigation principale"></nav>';
      renderCalls = 0;
      const fragment = template.render({
        site_name: 'Uni-Songes', logo: '', logged_in: false,
        page: {
          header: '<p data-unexpected-header>Ne doit pas être rendu</p>',
          highlighted: '', help: '',
          navigation: populated ? fixtureNav : '',
          primary_menu: populated ? '' : fixtureNav,
          content: '<div class="unisonges-system-messages" data-drupal-messages>'
            + '<div class="messages__wrapper"></div></div>'
            + '<div class="unisonges-page-title-block" data-fixture-content="main"><h1>Fixture</h1></div>'
            + (kind === 'front' ? editorial : '<form class="auth-account-form" action="/user/login" method="post">'
              + '<label for="fixture-name">Nom</label><input type="text" id="fixture-name" name="name">'
              + '<button type="submit">Se connecter</button></form>'),
          footer,
        },
      });
      ok(renderCalls === 1, name + ': render filter called once');
      const html = '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
        + '<title>Fixture</title></head><body><a href="#main-content">Éviter</a>'
        + fragment + '</body></html>';
      const report = await validator.validateString(html, name + '.html');
      const diagnostics = report.results.flatMap(result => result.messages);
      // Reproduit aussi avec le shell origin/release/prod sans footer :
      // html-validate inclut le drawer <aside hidden> parmi les landmarks.
      const inherited = diagnostic => kind === 'front'
        && diagnostic.ruleId === 'unique-landmark' && diagnostic.selector === '#mobile-drawer';
      ok(diagnostics.filter(inherited).length === (kind === 'front' ? 1 : 0),
        name + ': inherited drawer diagnostic');
      ok(diagnostics.every(inherited), name + ': unexpected HTML diagnostics '
        + JSON.stringify(diagnostics));
      const dom = (await validator.getParserFor(name + '.html')).parseHtml(html);
      const count = selector => dom.querySelectorAll(selector).length;
      for (const [selector, expected] of [
        ['main', 1], ['main#main-content.d-flex.flex-column', 1],
        ['footer', 1], ['footer.site-footer > .container > p', 1],
        ['#unisonges-scrollframe.scrollframe', 1], ['.scrollframe__inner', 1],
        ['.scrollframe .scrollframe', 0], ['main footer, form footer', 0],
        ['.scrollframe__inner > main#main-content + footer.site-footer', 1],
        ['.unisonges-system-messages[data-drupal-messages]', 1],
        ['main .unisonges-system-messages', 1], ['[data-unexpected-header]', 0],
        ['header .unisonges-system-messages, footer .unisonges-system-messages', 0],
        ['a[href="#main-content"]', 1], ['header.site-header', 1],
        ['[data-fixture-nav]', 1], ['#mobile-drawer', 1], ['[data-fixture-content]', 1],
        ['#unisonges-bgfx', 1], ['#unisonges-bgfx-scroll', 1], ['#unisonges-bgfx-layer', 1],
        ['#unisonges-bgfx > #unisonges-bgfx-scroll > #unisonges-bgfx-layer', 1],
        ['[data-fixture-region]', markers], ['h1', 1],
        ['footer h1, footer h2, footer h3, footer form', 0],
        ['main .auth-account-form', kind === 'normal' ? 1 : 0],
        ['main .unisonges-editorial-home', kind === 'front' ? 1 : 0],
        ['main .unisonges-editorial-home__articles > li', kind === 'front' ? 2 : 0],
        ['main .unisonges-editorial-home__pager', kind === 'front' ? 1 : 0],
        ['main .unisonges-editorial-home__disclosure', kind === 'front' ? 2 : 0],
        ['footer .unisonges-editorial-home', 0],
      ]) ok(count(selector) === expected, name + ': ' + selector);
      const ids = dom.querySelectorAll('[id]').map(node => node.getAttributeValue('id'));
      ok(ids.length === new Set(ids).size, name + ': unique IDs');
      ok(dom.querySelectorAll('[tabindex]').every(node =>
        Number(node.getAttributeValue('tabindex')) <= 0), name + ': tabindex');
      ok(!/mentions-legales|politique-confidentialite/u.test(html), name + ': legal route');
      const footerNode = dom.querySelector('footer');
      ok(visible(footerNode.querySelector('.container > p')) === 'Uni-Songes', name + ': identity');
      const links = footerNode.querySelectorAll('a').map(node =>
        [node.getAttributeValue('href'), visible(node)]);
      ok(JSON.stringify(links) === JSON.stringify(populated ? [] : expectedLinks),
        name + ': exact footer links');
      ok(footerNode.querySelectorAll('nav').filter(node =>
        node.getAttributeValue('aria-label') === 'Navigation de pied de page').length === (populated ? 0 : 1),
        name + ': exclusive fallback');
      if (state === 'zero') ok(visible(footerNode).endsWith('0'), name + ': zero retained');
      if (state === 'nontext') ok(count('footer hr') === 1, name + ': nontext retained');
      console.log('FIXTURE OK ' + name);
      total++;
    }
  }
  console.log('TWIG 3/3; FIXTURES/DOM ' + total + '/20 OK; HTML: zero new diagnostics, 10 inherited drawer diagnostics');
})().catch(error => { console.error(error.message); process.exitCode = 1; });
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

Terminal 1 / PR #113 possède seul DDEV et le checkout servant. Sa libération
ne déclenche aucun runtime de #94 : une nouvelle autorisation est obligatoire.
Aucun runtime n’est revendiqué et la PR reste en brouillon.

Séquence de reprise sous cette future autorisation :

1. vérifier la disponibilité auprès de #113, le checkout approuvé et le SHA de
   base ; actualiser la branche seulement si nécessaire et relancer les gardes
   affectées avant de consigner le SHA testé ;
2. sur Drupal local approuvé, tester une région footer vide puis peuplée d’un
   bloc contrôlé ; routes dans l’ordre accueil, Basic page, page courte, page
   longue, réservation, Blog, Forum, Contact, panier Commerce, connexion,
   inscription, mot de passe et compte ; chacune desktop → tablette → mobile ;
3. tester l’accueil #103 avec zéro/un/plusieurs Articles, longue liste,
   pagination et chacun des deux panneaux fermé/ouvert ; couvrir les états
   réels de région décrits ci-dessous et les caches froid/chaud ;
4. contrôler les logs et la console pendant les parcours, restaurer l’état
   initial de la région et refaire un smoke accueil/Basic page. Consigner les
   preuves avant toute décision de passage hors brouillon.

| Contrôle restant | Preuve attendue |
| --- | --- |
| Région réellement vide, métadonnées seules, tous blocs refusés | Cinq liens de repli ; pas de fausse région peuplée |
| Région peuplée, cache/attachments | Sortie une fois, aucun repli ; propagation du cache et des bibliothèques correcte |
| Wrappers vides, lazy builders/placeholders | Décision de repli correcte après rendu Drupal ; pas de double rendu |
| Activation #103 et ses états Article/pager/panneaux | Bloc réellement activé ; contenu complet dans main, footer ensuite |
| #99/#100 avec vrais wrappers Drupal/Barrio | Titre/messages/formulaire dans l’ordre prévu ; un seul chemin inline, hors footer |
| DOM et arbre accessible, drawer fermé/ouvert | Un main, un footer, navigation nommée ; examiner le diagnostic hérité du drawer |
| Clavier, lien d’évitement, dernier cran, tactile | Focus visible ; cible `#main-content` ; footer entièrement atteignable |
| Desktop/tablette/mobile, pages courtes et longues | Header fixe utilisable, BGFX autonome et bords sûrs ; aucun overflow horizontal ni scroll imbriqué effectif |
| Réservation, Contact, Commerce et compte | Formulaires/actions utilisables ; footer après tout le contenu |
| Logs PHP et console | Aucun warning/erreur nouveau |

Le CTA Blog de #103 et le lien Blog du footer ont des fonctions distinctes ;
aucun thème, pager ou composant éditorial n’est copié dans le footer.
