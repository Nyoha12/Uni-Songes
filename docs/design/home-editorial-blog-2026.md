# Accueil éditorial centré sur le Blog — proposition 2026

## Statut, périmètre et intention

Ce document accompagne un prototype statique de la page `/accueil`. Il propose
un accueil éditorial minimal, centré sur les Articles publiés, sans modifier le
site Drupal, sa configuration, son contenu, ses routes ni ses actifs.

La proposition est préparée sur la base exacte
`origin/release/prod@2bfb2b3b57bffcdbef72306a96a1c7f8a4055002`, le
1er septembre 2026. Elle reste une proposition de design à faire relire par le
propriétaire. Elle n'autorise ni déploiement ni activation de configuration.

Le périmètre suivi est volontairement limité à trois fichiers :

- `docs/design/home-editorial-blog-2026.md` ;
- `docs/prototypes/home-editorial-blog/index.html` ;
- `docs/prototypes/home-editorial-blog/prototype.css`.

Aucun JavaScript n'est nécessaire : les deux contrôles latéraux utilisent
`<details>` et `<summary>`, qui restent utilisables sans enrichissement client.
Le prototype ne charge ni image, ni fonte, ni CDN, ni analytique, ni service
embarqué. Il ne constitue pas une source de contenu Uni-Songes.

## Choix de design retenu

L'accueil devient une petite publication culturelle, pas une grande landing
page promotionnelle :

1. une identité compacte et un H1 unique ;
2. un premier Article seulement 10–12 % plus présent par sa typographie et un
   filet fin, sans fond de carte, hero géant ni image requise ;
3. une liste éditoriale plate et verticale, où Articles récents et plus anciens
   partagent le même axe et les mêmes séparateurs ;
4. un rail secondaire avec exactement deux contrôles : « Articles par thème »
   et « À propos d’Uni-Songes » ;
5. une surface de lecture ivoire, suffisamment opaque, posée dans le shell
   existant afin de laisser le fond autonome visible uniquement sur les bords ;
6. l'accent teal fusionné (`#0f766e`) comme unique accent du prototype, des
   filets fins, presque aucune ombre et aucun empilement de cartes
   promotionnelles. La chaleur vient des surfaces ivoire, pas d'une seconde
   couleur d'accent.

Sur desktop, la proposition respecte la largeur réelle de `980px` du
scrollframe fusionné. Après un padding interne maximal de `2.5rem`, la grille
réserve `15rem` au rail, `2–3rem` à la gouttière et le reste — environ
`36–39rem` — à la colonne éditoriale. L'identité occupe d'abord toute la
largeur ; le rail commence ensuite au niveau de « Articles récents ».

Le rail desktop reste statique. Sa hauteur dépend de termes réels et de deux
disclosures qui peuvent être ouverts simultanément : le rendre sticky pourrait
laisser des contrôles focalisés hors du scrollport. Sur tablette et mobile, il
reste dans le même ordre source, entre l'introduction et la liste. Il n'existe
jamais de sidebar étroite permanente sur mobile.

Il n'existe aucun contrôle, onglet, menu, taxonomie ou silo « Archives »
autonome. Les Articles plus anciens restent dans la même liste paginée et se
parcourent au moyen des vrais thèmes, toujours du plus récent au plus ancien.

Le prototype représente visuellement le fond existant par un aplat sombre,
sans recopier une image du thème. La production doit conserver le BGFX fusionné
et ne doit pas introduire un nouveau fond pour ce composant.

## Audit initial

### Accueil et architecture de contenu fusionnés

`drupal/config/sync/system.site.yml` définit `/accueil` comme front. Le helper
`drupal/scripts/apply-content-architecture-2026.sh` exige que cette Basic page
existe déjà (`create_if_missing: false`) et gère actuellement :

- une introduction courte ;
- un CTA vers `/reservation-cours` ;
- six cartes vers les principaux contenus publics.

`docs/functional/content-architecture-2026.md` établit le corps Drupal comme
source de vérité des sections éditoriales. Un futur Twig ne doit donc pas
hardcoder une seconde version du contenu actuel. Ajouter seulement un bloc Blog
sur `/accueil` laisserait aussi les six cartes devant la liste : l'état du corps
de `/accueil` est une dépendance d'implémentation explicite.

Il n'existe actuellement aucun bloc Blog placé sur `/accueil`.

### Blog Article View fusionnée et placement `/blog`

`drupal/config/sync/views.view.blog_posts.yml` fournit une View uniquement en
bloc :

- table `node_field_data` ;
- bundle strictement égal à `article` ;
- `status = 1` ;
- tri `created DESC` ;
- mini-pager de 10 éléments ;
- ligne rendue dans le mode `teaser` ;
- état vide « Aucun article publié pour le moment. » ;
- réécriture d'accès SQL conservée ;
- cache par tags, langue, arguments d'URL, node grants et permissions.

`drupal/config/sync/block.block.unisonges_blog_posts.yml` place le display
`block_1` dans la région `content`, au poids `20`, uniquement lorsque le chemin
est exactement `/blog`.

La future implémentation doit ajouter un display/bloc distinct pour
`/accueil`. Elle ne doit ni déplacer ni transformer le display `/blog`, qui
reste le hub Blog complet.

### Champs et métadonnées Article réellement disponibles

Le bundle `article` possède seulement les champs configurables suivants :

| Donnée | Réalité suivie | Conséquence pour l'accueil |
| --- | --- | --- |
| Titre | Champ de base Node | Affiché comme titre principal non lié ; l'URL canonique est portée par l'unique lien texte discret « Lire l'article ». |
| Statut | Champ de base Node | Seuls les nœuds `status = 1` sont éligibles. |
| Date | Champ de base éditable `created` | La View actuelle trie dessus et le rendu public affiche une date de soumission. Ce n'est pas une date de première publication garantie. |
| Auteur | Champ de base éditable `uid` | Il existe, mais son affichage n'est pas confirmé comme choix éditorial de la homepage. Il est omis du prototype. |
| Corps/résumé | `body`, type `text_with_summary`, facultatif | Le résumé explicite est facultatif. Le teaser peut sinon tronquer le corps à 600 caractères. Corps et résumé peuvent tous deux manquer. |
| Thème | `field_tags`, facultatif et multivalué | Peut fournir zéro, un ou plusieurs termes du vocabulaire `tags`. Rien ne permet d'en supposer un. |
| Image | `field_image`, facultative | Elle n'est jamais requise ni réservée dans la composition proposée. |
| Commentaires | Champ `comment`, ouvert par défaut | Hors périmètre de l'accueil. |
| Alias | Widget Path disponible, sans pattern Article suivi | Toujours demander l'URL canonique à Drupal ; ne jamais construire `/blog/{slug}`. |

Le résumé n'est donc pas un champ séparé. Le contrat de rendu proposé est :

1. résumé explicite lorsqu'il existe ;
2. sinon extrait sûr du corps, selon le formatter Drupal ;
3. sinon aucun paragraphe de résumé et aucun texte éditorial synthétique.

### `field_tags` et taxonomie

`field_tags` référence le vocabulaire réel `tags`, nommé « Étiquettes ». Le
champ est :

- facultatif ;
- multivalué sans limite ;
- traduisible ;
- configuré avec auto-création de termes.

Il n'existe pas de vocabulaire distinct nommé « Thèmes ». Le libellé public
« Thèmes » est donc une proposition UX pour les termes de `tags`, pas un fait
du modèle. Cette correspondance et la gouvernance d'une taxonomie libre doivent
être approuvées avant implémentation.

Aucun terme réel n'est suivi dans Git. Aucun nom, identifiant de terme ou
catégorie décrite dans le texte de `/blog` ne peut être hardcodé dans le rail.
Le contrôle de production doit être construit à partir des entités Taxonomy
Term réellement accessibles.

Le View générique de termes expose actuellement `/taxonomy/term/%`, mais sans
filtre de bundle. Ces pages ne sont pas retenues comme architecture du filtre
homepage et aucune nouvelle route de taxonomie n'est proposée ici.

### Affichages Article teaser et canonical

`core.entity_view_display.node.article.teaser.yml` rend actuellement :

- l'image facultative ;
- le résumé ou corps tronqué à 600 caractères ;
- les étiquettes liées ;
- les liens Node.

`core.entity_view_display.node.article.default.yml` rend le corps intégral,
l'image, les étiquettes, les commentaires et les liens. Il n'existe ni template
`node--article` propre au thème ni display `full` Article séparé : le canonical
repose sur le display `default`.

Le homepage display doit donc utiliser des champs explicitement configurés et
un template de View étroitement suggéré. Modifier le teaser global casserait le
contrat de `/blog`, des recherches et des autres consommateurs.

### Shell `page--front`, H1 et scrollframe

Le shell fusionné `page--front.html.twig` rend :

```text
#unisonges-bgfx[aria-hidden=true]
└── #unisonges-bgfx-scroll
    └── #unisonges-bgfx-layer
header.site-header
main#main-content
└── #unisonges-scrollframe.scrollframe
    └── .scrollframe__inner
        └── page.content
```

`unisonges_theme_theme_suggestions_page_alter()` force aussi ce shell lorsque
l'alias courant est `/accueil`. Le bloc de titre global fournit aujourd'hui
l'unique H1 « Accueil » sur cette route. Les lignes et contrôles du futur bloc
ne doivent produire aucun H1.

Le prototype utilise « Le Blog » comme H1 de direction artistique. La manière
de l'obtenir en Drupal — conserver « Accueil », renommer le titre de la Basic
page ou modifier étroitement le titre de route — est une décision propriétaire
et une dépendance du futur changement de contenu. Deux H1 ne sont jamais une
option.

Le vrai scroller est `#unisonges-scrollframe`. La cascade fusionnée applique
cependant aussi `overflow: auto !important` à `.scrollframe__inner`. Cela peut
empêcher un descendant sticky de suivre le scroller attendu. La proposition
retient donc un rail statique sur tous les formats ; aucun sticky n'est requis
pour l'implémentation.

### Typographie fusionnée

La section finale de `styles.css` définit :

- corps en pile système, graisse `400`, interligne `1.6` ;
- titres généraux en pile système, graisse `700` ;
- navigation en pile système ;
- mesure de lecture jusqu'à `68ch` ;
- Taga réservée à certains titres courts `.home-card__title`.

Le prototype suit ces décisions. Il n'utilise ni fonte externe ni fonte locale
du thème. Le caractère éditorial vient de la mesure, des espacements, des
filets et de la hiérarchie, pas d'une nouvelle police d'affichage.

### Navigation fusionnée

Le header rend une source serveur `page.primary_menu`. Le script de navigation
clone ensuite la liste vers le drawer mobile en réécrivant les identifiants.
Le pattern documenté est navigation + disclosure, pas un widget tabs ni un
menu applicatif.

Le prototype fournit seulement un aperçu statique du shell avec les
destinations déjà confirmées. La future homepage doit conserver le header, sa
source Drupal, le drawer, les sous-menus et leurs scripts sans les recopier
dans le composant éditorial.

### Fond fixe autonome fusionné

Le BGFX fusionné :

- est fixé au viewport et `pointer-events: none` ;
- se déplace de manière autonome sur 140 secondes, au plus 14 px ;
- ne dépend plus du scroll, de la roue ou du tactile ;
- reste statique avec `prefers-reduced-motion: reduce` ou Save-Data.

La surface actuelle du scrollframe reste translucide, floutée, arrondie et
fortement ombrée. Ajouter un second panneau opaque à l'intérieur produirait un
effet panneau-dans-panneau. Le prototype choisit un seul contenant : le
scrollframe lui-même devient la surface ivoire opaque sur `/accueil`, tandis que
le contenu intérieur le remplit sans bord, rayon ou ombre supplémentaire. Cette
neutralisation visuelle doit être strictement route-scoped ; elle ne change ni
les IDs, ni le positionnement, ni la taille, ni le comportement de défilement du
shell et ne touche pas au BGFX.

### Footer proposé, non fusionné

Le footer n'est pas présent dans le shell d'accueil fusionné. Le footer du shell
normal est conditionnel et hors scrollframe ; le partial `_footer.html.twig`
actuel n'est pas inclus et référence deux routes juridiques qui ne sont pas
établies dans Drupal.

La draft PR #94 propose de placer `main` puis un footer comme frères dans
`.scrollframe__inner`. Lorsque la région Footer est vide, son repli contient
uniquement les destinations confirmées suivantes :

- `/cours-et-stages` ;
- `/ateliers` ;
- `/a-propos` ;
- `/blog` ;
- `/contact`.

Le prototype montre ce footer pour vérifier la fin du scrollframe, mais ne le
présente pas comme fusionné. L'implémentation doit intégrer l'état final de la
PR #94, sans dupliquer le footer et sans réintroduire les liens juridiques non
validés.

### Politique sitemap proposée, non fusionnée

Dans la base actuelle, le sitemap suivi ne contient qu'un lien custom `/`, les
types activés sont `node`, `taxonomy_term` et `menu_link_content`, aucun réglage
de bundle n'est suivi et `robots.txt` n'annonce aucun sitemap.

La draft PR #82 propose notamment :

- `/accueil` et `/blog` comme liens explicites ;
- l'inclusion du bundle Article ;
- l'exclusion globale des Basic pages hors allowlist ;
- la limitation du générateur aux Nodes ;
- l'exclusion des pages de termes ;
- un alias canonique non numérique obligatoire pour chaque Article publié.

Cette proposition n'est pas fusionnée. Le design n'ajoute aucune URL publique,
aucune page d'archive et aucune entrée sitemap. Les filtres restent des états
GET contrôlés de `/accueil`. Les liens texte des Articles utilisent l'URL
canonique retournée par Drupal, quelle que soit sa forme active.

### Archive Core existante, non retenue

`drupal/config/sync/views.view.archive.yml` est désactivée. Elle couvre tous
les contenus publiés, sans filtre Article, et contient un display de page
`/archive`. L'activer exposerait plusieurs bundles et créerait une route que ce
périmètre n'est pas autorisé à valider.

Elle demeure désactivée et n'est ni réutilisée ni activée. Aucun contrôle,
paramètre GET, agrégat, taxonomie ou route d'archive n'est proposé. Les contenus
anciens font naturellement partie de la liste Blog, filtrable par thème et
paginée dans l'ordre `created DESC`.

### Contenus et fixtures suivis

Le dépôt ne suit :

- aucun Article réel ;
- aucun terme `tags` réel ;
- aucun alias d'Article ;
- aucune fixture Article/taxonomie dans le système de fixtures local.

Les Articles utilisés dans les validations antérieures étaient temporaires et
ont été supprimés. Le prototype n'en reprend ni titre, ni date, ni auteur, ni
thème. Tout élément fictif porte visiblement le badge « Fixture » et utilise
des libellés génériques « Exemple… ».

## Inventaire des PR ouvertes et garde de chevauchement

L'inventaire GitHub réévalué le 1er septembre 2026 après la correction produit
trouve douze PR ouvertes vers `release/prod`, dont cette proposition #97. Toutes
sont marquées en brouillon. Aucune des onze autres PR ne modifie l'un des trois
fichiers de ce prototype.

| PR | Fichiers suivis par la PR |
| --- | --- |
| #97 — accueil éditorial Blog | `docs/design/home-editorial-blog-2026.md`; `docs/prototypes/home-editorial-blog/index.html`; `docs/prototypes/home-editorial-blog/prototype.css` |
| #96 — expérience d'authentification et de compte | `docs/design/auth-account-experience-2026.md`; `docs/prototypes/auth-account-experience/index.html`; `docs/prototypes/auth-account-experience/prototype.css`; `docs/prototypes/auth-account-experience/prototype.js` |
| #95 — contraste du Webform réservation | `docs/functional/interactive-text-contrast-2026.md`; `drupal/web/themes/custom/unisonges_theme/css/styles.css` |
| #94 — footer public | `docs/functional/public-footer-foundation-2026.md`; `drupal/web/themes/custom/unisonges_theme/templates/includes/_footer.html.twig`; `drupal/web/themes/custom/unisonges_theme/templates/page--front.html.twig`; `drupal/web/themes/custom/unisonges_theme/templates/page.html.twig` |
| #92 — composants des hubs | `docs/functional/public-hub-components-2026.md`; `drupal/web/themes/custom/unisonges_theme/templates/content/node--10.html.twig`; `drupal/web/themes/custom/unisonges_theme/templates/content/node--6.html.twig`; `drupal/web/themes/custom/unisonges_theme/templates/content/node--9.html.twig`; `drupal/web/themes/custom/unisonges_theme/templates/includes/_card-grid.html.twig`; `drupal/web/themes/custom/unisonges_theme/templates/includes/_public-hub-actions.html.twig` |
| #90 — panier Commerce | `docs/functional/cart-ux-integration-2026.md`; `drupal/config/sync/views.view.commerce_cart_form.yml`; `drupal/scripts/apply-cart-ux-2026.sh`; `drupal/web/themes/custom/unisonges_theme/templates/commerce/commerce-cart-empty-page.html.twig` |
| #89 — concerts à venir | `docs/functional/concert-hub-upcoming-events-2026.md`; `drupal/config/sync/block.block.unisonges_hub_concerts_posts.yml`; `drupal/config/sync/views.view.hub_concerts_posts.yml`; `drupal/scripts/apply-concert-hub-upcoming-events-2026.sh` |
| #88 — ancien frontend | `README.md`; `docs/functional/legacy-cloudflare-pages-retirement-2026.md`; `public/_headers`; `public/_redirects`; `public/robots.txt`; `public/sitemap.xml` |
| #87 — contenus artistes/partenaires | `docs/functional/content-architecture-2026.md`; `drupal/scripts/apply-content-architecture-2026.sh` |
| #86 — entrée réservation | `docs/functional/reservation-entry-cleanup-2026.md`; `drupal/web/themes/custom/unisonges_theme/templates/content/node--8.html.twig` |
| #85 — Contact | `docs/functional/contact-form-mvp-2026.md`; `drupal/config/sync/block.block.unisonges_contact_form.yml`; `drupal/config/sync/webform.webform.contact.yml`; `drupal/scripts/apply-contact-form-mvp-2026.sh`; `drupal/scripts/contact-form-mvp-config.php` |
| #82 — sitemap Drupal | `docs/functional/sitemap-robots-policy-2026.md`; `drupal/config/sync/simple_sitemap.bundle_settings.default.node.article.yml`; `drupal/config/sync/simple_sitemap.bundle_settings.default.node.concert.yml`; `drupal/config/sync/simple_sitemap.bundle_settings.default.node.forum_topic.yml`; `drupal/config/sync/simple_sitemap.bundle_settings.default.node.page.yml`; `drupal/config/sync/simple_sitemap.bundle_settings.default.node.stage.yml`; `drupal/config/sync/simple_sitemap.custom_links.default.yml`; `drupal/config/sync/simple_sitemap.settings.yml`; `drupal/config/sync/simple_sitemap.type.default_hreflang.yml`; `drupal/scripts/apply-sitemap-policy-2026.sh`; `drupal/web/robots.txt` |

La PR #93 a été fusionnée dans cette base sous le commit
`2bfb2b3b57bffcdbef72306a96a1c7f8a4055002`. Elle remplace la référence globale
invalide `unisonges_theme/global` par `unisonges_theme/unisonges-layout` et
ajoute un wrapper de compatibilité `contact`. La future bibliothèque CSS de
l'accueil peut dépendre de ce graphe désormais suivi, après validation de son
intégration active.

## Contrat éditorial proposé

### Identité et introduction

Le prototype emploie :

- le repère « Uni-Songes · Blog » ;
- le H1 proposé « Le Blog » ;
- l'introduction déjà approuvée dans le corps suivi de `/blog` : « Le Blog
  accueillera les actualités de l'association, des articles artistiques et
  pédagogiques, ainsi que des réflexions et des ressources autour de ses
  pratiques et de ses projets. »

Le temps verbal et le H1 doivent être validés avant implémentation. La source de
vérité future reste le contenu Drupal `/accueil`, pas le Twig.

### Présentation courte

Le panel « À propos d’Uni-Songes » reprend uniquement une phrase déjà approuvée
dans le contenu suivi de `/association` : « L'association Uni-Songes a pour
mission de favoriser la pratique, la transmission et la création musicales,
ainsi que les rencontres qui les rendent collectives. »

Il contient un lien vers `/a-propos`, sans nom, date, statistique, historique,
coordonnée ou manifeste ajouté.

### Ligne Article

Chaque ligne émet uniquement :

1. la date basée sur `created`, si et seulement si sa sémantique de publication
   est approuvée ;
2. zéro, un ou plusieurs thèmes issus de `field_tags` ;
3. le titre comme principal élément visuel, non transformé en bouton ;
4. le résumé explicite ou l'extrait Drupal, s'il existe ;
5. un unique lien texte discret « Lire l'article » vers le canonical, dont le
   nom accessible inclut le titre.

L'auteur, les commentaires, le statut « promu », la durée de lecture, les
statistiques et les CTA répétés sont absents. Le prototype retient une liste
textuelle sans image. Une future implémentation ne pourrait montrer qu'une
petite image réellement fournie par `field_image`, jamais un emplacement vide
ou une image de remplacement.

Le premier résultat reçoit uniquement un filet d'accent et une taille de titre
supérieure d'environ 10–12 %, uniquement sur la première page non filtrée.
C'est alors la dernière publication de la liste globale. Il conserve le même
fond, le même alignement horizontal et le même rythme que les autres lignes :
ce n'est ni une carte ni un hero. Les pages suivantes et les résultats filtrés
utilisent tous le rythme standard ; ils ne présentent pas leur premier résultat
comme la dernière publication du site.

## Comportement des contrôles

### Pattern commun

Les deux contrôles sont des disclosures natifs. Leur `summary` mesure au moins
44 px, décrit directement le panneau et conserve les comportements clavier du
navigateur : Tab pour atteindre, Entrée ou Espace pour ouvrir/fermer. Aucun
`role="tablist"`, `role="tab"`, `aria-selected` ou clavier à flèches n'est
ajouté.

Sans CSS, les deux disclosures restent dans le flux avant la liste. Sans
JavaScript, ils restent entièrement fonctionnels. Aucun contenu essentiel —
identité, titre, liste ou état vide — ne dépend de leur ouverture.

### Articles par thème

Architecture proposée : filtre exposé de la homepage View, identifiant GET
`theme`, dont les options sont construites depuis les vrais termes du
vocabulaire `tags`.

- « Articles par thème » est le libellé retenu après revue UX : il nomme à la
  fois le contenu et le mode de parcours, même lorsque le panel est fermé ;
- « Tous les articles » est toujours présent et retire le filtre ;
- les valeurs et identifiants ne sont jamais hardcodés ;
- seuls de vrais termes accessibles du vocabulaire sont rendus et ils sont
  triés alphabétiquement selon la langue d'interface ;
- le filtre ne pose aucune borne de date : Articles récents et anciens d'un
  thème restent ensemble, triés `created DESC` et accessibles par pagination ;
- le terme sélectionné reçoit `aria-current="true"` en plus du traitement
  visuel ;
- choisir un terme réinitialise `page` à la première page, puis le pager
  préserve ce terme ;
- si le vocabulaire ne contient aucun terme accessible, le panel conserve
  « Tous les articles » et affiche un état neutre ;
- les Articles sans `field_tags` restent visibles sous « Tous les articles » et
  n'affichent pas de séparateur vide dans leur métadonnée ;
- le display homepage surcharge l'option de requête héritée
  (`defaults.query: false`) et active `distinct: true`, ou l'option Views
  équivalente prouvée, afin qu'un Article multitag n'apparaisse qu'une fois ;
  le total et le pager sont testés avec cette déduplication ;
- les noms longs reviennent à la ligne et ne sont jamais ellipsés.

Un terme réel sans résultat accessible produit l'état « Aucun article n'est
associé à ce thème. » et un lien serveur « Voir tous les articles ». Il ne
réutilise pas l'état global « Aucun article publié ».

Dans un état filtré, le titre serveur devient « Articles — {nom réel du
thème} » et une indication textuelle « Du plus récent au plus ancien » rend
l'ordre explicite. L'état sans filtre conserve « Articles récents ».

Le HTML serveur doit être une liste de liens GET ou un formulaire natif. Aucun
custom select n'est prévu. Aucun contrôle mois/année ne l'accompagne. Si des
séparateurs d'année deviennent utiles dans un thème très long, ils restent de
simples repères secondaires dans la liste sélectionnée, jamais une navigation
ou un silo distinct ; le prototype n'en ajoute pas.

### À propos d’Uni-Songes

Ce disclosure ne filtre rien. Il expose une phrase courte approuvée et un lien
normal vers `/a-propos`. La copie doit rester éditable depuis une source
Drupal approuvée ; le prototype n'impose pas de la hardcoder dans Twig.

## Responsive et reflow

### Desktop large

- scrollframe/surface maximale `61.25rem` (`980px`), avec le fond visible autour ;
- grille principale `minmax(0, 1fr) 15rem`, gouttière maximale `3rem` ;
- rail aligné sur le début de la liste ;
- deux contrôles fermés par défaut, sans bloc coloré permanent ;
- rail statique, jamais fixé ni sticky ;
- les panneaux ouverts restent dans le rail et ne recouvrent jamais la colonne
  d'Articles ;
- mesure des résumés inférieure à `68ch` ;
- aucune largeur issue du contenu des titres ou termes.

### Tablette

Sous environ `62rem`, la grille devient une colonne. Ce seuil tient compte du
contenant réel, pas seulement d'un grand viewport autour du frame. Le rail
arrive après l'introduction et avant la liste. Les disclosures restent compacts et leurs
panneaux prennent toute la largeur disponible. La dernière publication reste
légèrement accentuée sur la première page non filtrée, sans changement de
structure.

### Mobile et zoom

- padding minimal `1rem`, réduit seulement de façon contrôlée à 320 px ;
- aucune sidebar permanente ;
- exactement deux disclosures « Articles par thème » et « À propos
  d’Uni-Songes » avant la liste, sans strip horizontal ;
- cibles de 44 px ;
- métadonnées et pagination reviennent à la ligne ;
- `min-width: 0` et `overflow-wrap: anywhere` protègent tous les enfants de
  grille ;
- aucune largeur fixe, aucun texte ellipsé et aucun scroll horizontal voulu ;
- à 150 % et 200 %, le breakpoint mono-colonne s'applique naturellement ;
- aucun contenu n'est positionné en absolu dans la surface de lecture.

## États et contenus partiels

| État | Rendu attendu |
| --- | --- |
| Aucun Article publié | H2 de section conservé, phrase « Aucun article publié pour le moment. » et aucun faux titre. |
| Un Article | L'unique ligne reçoit la légère emphase de dernière publication ; aucun vide réservé pour d'autres lignes. |
| Plusieurs Articles | Suite verticale, ordre décroissant, dernière publication accentuée uniquement sur la première page non filtrée et pager lorsque nécessaire. |
| Article sans thème | Bloc thème entièrement omis ; date et titre restent correctement alignés. |
| Aucun thème disponible | « Tous les articles » reste disponible, avec la phrase neutre « Aucun thème n'est disponible pour le moment. » et aucune catégorie fictive en production. |
| Thème sans résultat | « Aucun article n'est associé à ce thème. » et un lien pour revenir à tous les Articles ; ne pas employer l'état global. |
| Titre long | Retour à la ligne sans réduction extrême de taille, rognage ni collision avec le rail. |
| Nom de thème long | Retour à la ligne dans le rail et la métadonnée ; pas d'ellipse. |
| Résumé manquant | Aucun résumé synthétique et aucun espace artificiel ; le lien texte canonique suit directement le titre. |
| Limite de pagination | Précédent ou suivant absent du flux de tabulation lorsqu'indisponible ; page courante annoncée ; liens restants ≥ 44 px. |

Le prototype principal montre plusieurs Articles fictifs, dont un sans thème,
un titre long, un thème long, un résumé manquant et une limite de pagination.
Une section de fixtures séparée montre les états zéro Article, un Article,
aucun thème et thème sans résultat.

## Accessibilité

Le contrat de la future implémentation et du prototype est :

- exactement un H1 non vide ;
- H2 pour « Explorer », « Articles récents » et les états de test ;
- H3 pour chaque titre d'Article et chaque famille d'état ;
- aucun H1 dans une ligne View, un panel ou le footer ;
- lien d'évitement vers l'unique `main` ;
- listes sémantiques pour Articles, thèmes et pagination ;
- disclosures natifs, sans ARIA de tabs ;
- titre principal non lié et unique lien canonical avec un nom accessible
  distinct pour chaque fixture ;
- dates dans `<time datetime>` en production ;
- `aria-current` pour filtre/page active, jamais couleur seule ;
- focus visible d'au moins 2 px avec espace extérieur ;
- toutes les actions au moins 44 × 44 px ;
- ordre source : header, identité, contrôles, Articles, états de prototype,
  footer ;
- aucune animation requise et neutralisation avec
  `prefers-reduced-motion: reduce` ;
- contraste de texte normal au moins 4,5:1 et texte large au moins 3:1 ;
- reflow sans perte à 320 px, 100 %, 150 % et 200 %.

## Handoff Drupal proposé

### Séquence et dépendances exactes

Avant tout développement de production :

1. confirmer que la configuration ciblée Forum/Blog est active et identique
   aux sources suivies ; son merge seul ne l'active pas ;
2. contrôler l'état actif, le titre et le corps de `/accueil` ;
3. approuver la nouvelle composition du contenu géré par
   `apply-content-architecture-2026.sh`, sans réintroduire le corps dans Twig ;
4. inventorier les termes `tags` actifs et décider s'ils peuvent être nommés
   « Thèmes » ;
5. décider si `created` fait foi comme date publique et clé de tri ;
6. établir une politique d'alias Article ; aucun pattern Article n'est suivi ;
7. confirmer que le graphe de bibliothèques fusionné par #93 est actif avant de
   déclarer la bibliothèque scopée ;
8. intégrer l'état final du footer #94 sans doublon ;
9. réconcilier l'implémentation avec la politique sitemap #82 et son exigence
   d'alias ;
10. conserver Contact, réservation, `/blog`, les canonicals Article, le shell,
    la navigation et le BGFX.

### Plus petit ensemble maintenable

La cible recommandée, dans une PR d'implémentation séparée, est :

1. **View existante `blog_posts`** : ajouter un display bloc
   `home_editorial`, sans toucher à `block_1`. Il garde `type=article`,
   `status=1`, `created DESC`, la réécriture d'accès et les contextes de cache.
   Il utilise des champs contrôlés plutôt que le mode teaser : date, tags,
   titre non lié, résumé/extrait et unique lien texte vers l'URL canonique. Il
   remplace explicitement le mini-pager hérité par un full pager numéroté. Une
   page de six résultats est la valeur de prototype, à confirmer par le
   propriétaire.
2. **Filtre thème exposé** : terme réel `field_tags`, identifiant GET stable,
   rendu serveur en liens ou formulaire natif. « Tous les articles » retire le
   filtre ; un thème conserve tous ses Articles publiés, récents ou anciens,
   dans l'ordre `created DESC`. Le display n'hérite pas du
   `query.options.distinct: false` actuel : il surcharge la requête avec
   `defaults.query: false` et `distinct: true`, ou une option Views équivalente
   testée. Une fixture multitag doit prouver une ligne, un total et un pager
   sans doublon.
3. **Aucune architecture Archives** : aucun contextual filter, display de
   synthèse, paramètre `archive`, navigation mois/année, taxonomie ou route
   distincte. Le full pager de la liste plate donne accès aux contenus anciens.
4. **Bloc route-scoped** : placement exact sur `/accueil`, sans retirer ni
   déplacer le bloc `/blog`. L'introduction gérée par le corps `/accueil` reste
   au-dessus ; le composant View possède ensuite la grille Articles + rail.
5. **Twig suggéré étroitement** : un composant propre au display homepage.
   Aucun override du teaser ou canonical Article.
6. **Une bibliothèque CSS** `unisonges_theme/home-editorial-blog`, dépendante
   de `unisonges_theme/unisonges-layout` désormais fusionnée par #93, attachée
   uniquement au bloc ou à `/accueil`. Les sélecteurs du composant commencent par
   `.home-editorial-blog`; les seules exceptions admises sont les sélecteurs
   `.section-accueil` nécessaires à la surface du scrollframe.
7. **Progressive enhancement minimal** : aucun JavaScript initial. Conserver
   les disclosures natifs tant qu'un besoin testé ne justifie pas davantage.

Le premier résultat reçoit `is-latest` seulement lorsque `page=0` et que
`theme` n'est pas actif. Il ne faut pas charger le Node complet pour chaque
rangée ni calculer une URL à partir du titre.

### Placement, cache et sécurité

- visibilité du bloc : chemin exact `/accueil` seulement ;
- aucune route publique ajoutée ;
- full pager GET de la View, avec réinitialisation au changement de filtre ;
- cache metadata calculée par Core et vérifiée dans l'export :
  `url.query_args` pour le filtre exposé et le pager, langue, node grants et
  permissions ;
- tags de cache des nœuds et termes conservés ;
- `disable_sql_rewrite: false` ;
- filtre `status=1` obligatoire même si l'accès Node protège déjà le canonical ;
- aucune fuite de titre, résumé, terme ou compte d'un Article non publié dans
  la liste ou les options de thèmes ;
- valeur de thème inconnue gérée par le filtre exposé sans message SQL ni
  redirection vers une route inventée.

### Canonical, indexation et pagination

Le canonical du Node vient de Drupal. Le prototype utilise uniquement des
ancres locales pour ne pas inventer d'alias Article.

Pour `/accueil` :

- la page sans filtre reste le canonical principal ;
- les états `theme` ne créent pas d'entrées sitemap ;
- les pages du pager doivent rester parcourables et cohérentes avec les liens
  `previous/next` ;
- la stratégie exacte de canonical pour `?page=N` et `?theme=...` doit être
  validée avec la PR #82 avant implémentation ;
- ne pas forcer toutes les pages paginées sur le canonical de la page 1 sans
  décision SEO, car cela peut rendre les Articles plus profonds moins
  découvrables ;
- `/blog` reste le hub complet et son pager actuel reste inchangé.

### Shell, rail, footer et fond

Le composant reste dans `.scrollframe__inner` et n'ajoute aucun scroller. Pour
éviter un double panneau, la bibliothèque route-scoped doit faire du
`#unisonges-scrollframe` l'unique surface ivoire opaque sur `/accueil`, retirer
son blur, son rayon et son ombre forte, puis laisser le wrapper éditorial le
remplir sans marge, bord ni ombre supplémentaires. Elle doit aussi neutraliser
sur cette route l'`overflow:auto` concurrent de `.scrollframe__inner`, tout en
conservant `#unisonges-scrollframe` comme seul scroller, sa largeur maximale de
`980px`, ses offsets edge-safe et tous ses IDs.

Le rail reste `position: static` sur tous les formats. Aucun changement de
hauteur de panel ou de zoom ne peut ainsi maintenir un contrôle focalisé hors
écran.

Le footer doit rester après le contenu dans le même scrollframe, selon la
décision finale de #94. Le composant ne contient ni Contact, ni réservation, ni
footer parallèles : il préserve leurs accès existants dans le shell.

## Décisions du propriétaire encore requises

1. Le H1 public reste-t-il « Accueil » ou devient-il « Le Blog » ?
2. Le corps actuel de `/accueil` — CTA réservation et six cartes — est-il
   remplacé, déplacé ou partiellement conservé ?
3. La phrase introductive suivie de `/blog` peut-elle être utilisée au présent
   lorsque des Articles existent ?
4. Le vocabulaire libre « Étiquettes » peut-il être présenté comme « Thèmes » ?
5. Faut-il limiter le nombre de tags visibles par Article ou gouverner leur
   création avant le lancement ?
6. `created` est-il la date éditoriale de publication et de tri, ou faut-il
   ajouter un champ de publication distinct dans un autre périmètre ?
7. L'auteur doit-il rester absent uniquement de l'accueil, ou aussi être
   explicitement traité sur `/blog` et le canonical ?
8. Quelle politique d'alias Article garantit des canonicals non numériques ?
9. Six Articles par page est-il le bon rythme ?
10. Les états filtrés et paginés doivent-ils être indexables, self-canonical ou
    canonicalisés vers `/accueil` ?
11. La phrase courte « À propos » reste-t-elle celle du contenu Association, et
    dans quelle source Drupal éditable doit-elle vivre ?
12. La neutralisation visuelle route-scoped du scrollframe — surface opaque,
    sans blur, grand rayon ni ombre forte — est-elle approuvée ?
13. Le comportement statique du rail sur desktop comme sur mobile est-il
    approuvé ?
14. La proposition footer #94 et la politique sitemap #82 sont-elles approuvées
    comme dépendances de l'implémentation ?

## Validation statique du prototype

Les contrôles finaux doivent couvrir, sans DDEV, Docker, Drush, navigateur ni
VPS :

- validation HTML complète ;
- parsing syntaxique CSS ;
- absence de JavaScript et justification du pattern natif ;
- aucune ressource externe ni URL d'asset ;
- un H1 exactement ;
- hiérarchie H2/H3/H4 logique ;
- exactement deux disclosures latéraux natifs et utilisables au clavier, hors
  disclosure du menu compact du shell ;
- focus visible, cibles 44 px et états courants non fondés sur la couleur seule ;
- fixtures explicitement marquées ;
- états zéro, un et plusieurs Articles ;
- Article sans thème, résumé absent, aucun thème disponible, titre et thème
  longs ;
- limite de pagination ;
- raisonnement 320 px, reflow 150/200 % et absence de débordement par design ;
- UTF-8 strict et normalisation NFC ;
- `git diff --check` ;
- garde exacte des trois fichiers ;
- intersection vide avec les fichiers des PR ouvertes ;
- scan de secrets du diff ;
- revues indépendantes UX éditoriale, design graphique et accessibilité.

Les résultats exécutés et les éventuelles réserves des revues sont consignés
dans la PR de design. Les contrôles Drupal et visuels restent volontairement
différés à la future PR d'implémentation, après libération et intégration des
dépendances concernées.
