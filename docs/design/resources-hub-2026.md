# Hub public « Ressources » — proposition 2026

## Statut, périmètre et décision proposée

Ce document prépare une future section publique « Ressources » consacrée à des
liens externes sélectionnés par Uni-Songes. Il s'agit exclusivement d'une phase
de design, d'architecture de l'information et de modèle de données. Elle ne
crée ni menu, ni route, ni type de contenu, ni taxonomie, ni View, ni
configuration Drupal et ne modifie pas le site historique.

La proposition a été préparée le 1er septembre 2026 sur la base exacte
`origin/release/prod@48b9eb4bbc2eef8bde2c5b5244d5a21a4b620af8`. La branche
était alors propre et sans avance ni retard sur cette référence. Les quatre
seuls fichiers autorisés sont :

- `docs/design/resources-hub-2026.md` ;
- `docs/prototypes/resources-hub/index.html` ;
- `docs/prototypes/resources-hub/prototype.css` ;
- `docs/design/resources-hub-inventory-template.csv`.

La recommandation de départ est le **modèle A, page éditoriale simple**, tant
qu'aucun inventaire réel, aucune catégorie et aucun besoin de gestion par
notice ne sont approuvés. Cette décision évite de créer prématurément des
routes canoniques de nœuds et de termes. Le modèle B, type de contenu
« Ressource », devient obligatoire dès qu'un des seuils documentés plus bas est
franchi.

La future route proposée est `/ressources`, mais ce document ne l'autorise pas
à lui seul. Sa création, son canonical, son entrée de menu et son inclusion au
sitemap nécessitent une validation propriétaire explicite conformément à
`AGENTS.md`.

## Résumé de la direction publique

La page doit ressembler à une sélection éditoriale calme, pas à un annuaire ou
à un tableau de bord :

1. un H1 unique « Ressources » et une introduction courte ;
2. une navigation de thèmes discrète seulement lorsque plusieurs thèmes réels
   la rendent utile ;
3. des groupes verticaux et une liste plate séparée par des filets fins ;
4. pour chaque notice, un titre, une phrase factuelle, quelques métadonnées et
   une indication explicite de destination externe ;
5. l'ouverture dans le même onglet par défaut ;
6. une date de dernière vérification seulement si elle aide réellement à
   apprécier l'actualité du lien ;
7. aucun logo, favicon tiers, note, étoile, capture automatique, mur de cartes,
   masonry ou défilement infini.

Le prototype est une planche d'états, pas une source de contenu. Toutes ses
notices utilisent « Ressource exemple », des thèmes/types explicitement
fictifs et des URLs réservées sous `https://example.invalid/`.

## Audit du dépôt

### Architecture publique et langage éditorial

Le contrat versionné actuel décrit cinq racines de menu, dans cet ordre :
Cours & Stages, Concerts & Événements, Projets collectifs, À propos et Contact
(`docs/functional/content-architecture-2026.md:90-104` et
`drupal/scripts/apply-content-architecture-2026.sh:735-775`). Les trois racines
avec enfants sont Cours & Stages, Projets collectifs et À propos. Le bloc de
menu expose une profondeur de deux niveaux. Il s'agit de la source finale
versionnée, pas d'une affirmation sur l'état runtime : sa propre documentation
maintient une validation de l'environnement cible comme prérequis.

`/a-propos` utilise une introduction brève et cinq orientations : Association,
Artistes et partenaires, Origine, Blog, puis Services et activités artistiques
(`drupal/scripts/apply-content-architecture-2026.sh:566-602`). Le vocabulaire
est direct et factuel : « Découvrir », « Voir », « Explorer », « Retrouver ».
La convention éditoriale interdit déjà d'ajouter des faits, partenaires,
statistiques, dates ou disponibilités non documentés
(`docs/functional/content-architecture-2026.md:32-71`).

Le périmètre actuel du Blog mentionne déjà des « réflexions et des ressources »
(`docs/functional/content-architecture-2026.md:25-26` et
`drupal/scripts/apply-content-architecture-2026.sh:591-615`). La séparation
proposée est donc :

- **Blog** : contenus éditoriaux rédigés et publiés par Uni-Songes ;
- **Ressources** : notices factuelles qui conduisent vers des sites externes ;
- un même objet ne doit pas être dupliqué dans les deux espaces sans raison
  éditoriale explicite.

Cette frontière doit être confirmée par le propriétaire avant implémentation.

### Menu responsive et seuils réellement actifs

Le menu desktop ne revient pas à la ligne : la liste racine est forcée en
`nowrap` (`navigation-submenus.css:95-137`). Le passage en mode compact est
mesuré à l'exécution, pas fixé à une largeur d'écran : le script compare
`scrollWidth` et `clientWidth`, tolère 2 px et utilise 96 px d'hystérésis au
retour (`auto-compact-nav.js:16-47`). La matrice fusionnée observait le menu
actuel complet à 1160 px, compact à 1150 px et de nouveau complet à 1170 px
(`docs/functional/theme-typography-readability-2026.md:149-153`).

Le breakpoint CSS de 640 px adapte surtout la géométrie. Le drawer peut être
activé au-dessus de 640 px par la mesure de contenu. À 320 px, le mot « Menu »
est masqué mais le bouton conserve son nom accessible
(`styles.css:2730-2771`). Le drawer est verticalement scrollable ; ses
sous-menus sont des accordéons dans le flux et les cibles tactiles de
disclosure mesurent 44 × 44 px.

Conséquence : l'ajout de deux racines, avec trois libellés longs en début de
liste, déplacera probablement le seuil compact au-dessus de la mesure
actuelle. Il ne faut ni permettre un wrap desktop imprévisible, ni annoncer un
nouveau breakpoint théorique dans cette phase. La future PR de menu devra
mesurer le résultat avec les fontes réellement chargées, les actions de compte
et les six ou sept libellés effectivement présents. Un drawer plus fréquent
sur de petits ordinateurs portables est acceptable s'il est volontaire et
testé ; un menu toujours compact sur grands écrans ne l'est pas sans décision
propriétaire.

### Types de contenu, taxonomies et workflow actuels

Le dépôt suit cinq bundles Node : `article`, `concert`, `forum_topic`, `page` et
`stage`. Il n'existe aucun bundle Resource. Le seul vocabulaire est
`tags`, libellé « Étiquettes ». Les étiquettes Article sont facultatives,
illimitées, traduisibles et auto-créables ; leurs pages de termes sont
publiques. Elles ne constituent donc pas une taxonomie gouvernée adaptée à ce
catalogue.

Réutiliser `article` serait incorrect : cela mélangerait les liens externes au
Blog, hériterait de l'image et des commentaires et ne fournirait aucun champ
d'URL externe structuré. Une Basic Page correspond en revanche au petit modèle
A. Le dépôt n'active actuellement ni `content_moderation` ni `workflows`
(`docs/functional/forum-blog-mvp-2026.md:41`).

La Basic Page possède un corps éditable et un statut publié/non publié. Le
format `basic_html` autorise seulement `href` et `hreflang` sur les ancres, pas
`target` ni `rel` (`drupal/config/sync/filter.format.basic_html.yml:31-39`). Ce
comportement favorise déjà le même onglet, mais ne remplace pas une contrainte
HTTPS, une validation d'hôte ou une présentation externe cohérente.

### Rendu actuel des liens externes

Le thème ne possède pas de composant générique pour les liens externes : pas
d'icône, de domaine affiché, de libellé accessible, de règle `target`, ni de
traitement `rel`. Les liens suivent le vert sarcelle et l'ambre généraux ou le
CTA en pilule.
Les pages structurées actuelles utilisent surtout des liens internes.

La future implémentation ne doit donc pas prétendre réutiliser une convention
existante. Elle devra introduire un rendu étroitement limité au hub : titre
lié, signe `↗` décoratif, texte accessible « site externe », hôte lisible si
utile, URL longue capable de se replier et aucun chargement depuis l'hôte.

### Sitemap et canonical

Sur la base auditée, Simple XML Sitemap accepte `node`, `taxonomy_term` et
`menu_link_content`, mais le seul lien custom suivi est `/`
(`simple_sitemap.settings.yml:17-20` et
`simple_sitemap.custom_links.default.yml:3-7`). La PR ouverte #82 propose une
politique plus conservatrice : exclusion globale des Basic Pages et liste
explicite des pages statiques. Elle ne connaît pas `/ressources`.

Le handoff doit donc coordonner la future route avec la politique effectivement
fusionnée au moment de l'implémentation :

- ajouter uniquement le canonical interne `https://unisonges.fr/ressources`
  après approbation et vérification ;
- ne jamais ajouter les URLs externes au sitemap Uni-Songes ;
- ne jamais publier des `/node/{id}` ou routes de termes par accident ;
- ne pas utiliser le sitemap pour déclarer une relation, un partenariat ou une
  recommandation.

### Retrait du site historique

Le dossier `public/` correspond au frontend historique distinct et ne doit pas
servir de source de design Drupal. La PR ouverte #88 prépare son retrait et
reste conditionnée à des décisions de routage. Le futur hub ne doit être ajouté
ni à son menu, ni à son sitemap, ni à ses redirections dans la PR
d'implémentation du hub. Une éventuelle compatibilité historique serait une
décision séparée, après existence et validation de `/ressources`.

En particulier, l'ancienne route `/videos/` est volontairement préservée dans
la proposition #88 faute d'équivalent Drupal approuvé. Elle ne doit pas être
redirigée ou rebaptisée silencieusement vers Ressources : ce serait une décision
de mapping public distincte.

### Tokens, typographie et fond

Les tokens suivis sont notamment : beige `#f3eee3`, panneau `#efe4d2`, texte
`#0b1220`, texte secondaire `#475569`, accent vert sarcelle `#0f766e`, bordure
`rgba(15,23,42,.14)` et ombre légère
(`drupal/web/themes/custom/unisonges_theme/css/styles.css:143-150`). La
typographie finale utilise une pile système, un corps 400/1.6 et des titres
700/1.25 (`styles.css:2510-2532`). Le contenu vit dans un scrollframe vitré de
980 px maximum (`styles.css:1538-1554`).

Les alias non mappés, dont `/a-propos` aujourd'hui et `/ressources` demain,
utilisent le fond générique `fontdefault.jpg`. Le mouvement BGFX est autonome,
sur 140 secondes et 14 px maximum, puis désactivé avec reduced motion ou
Save-Data. La proposition ne crée aucun nouveau fond. Le prototype remplace
l'image par des gradients CSS statiques, uniquement pour ne faire aucune
requête et ne pas recopier un actif de production.

### Fichiers des PR ouvertes au moment de l'audit

L'inventaire a été lu via GitHub le 1er septembre 2026. Aucun des quatorze
changements ouverts ne touche l'un des quatre chemins de cette proposition.

| PR | Fichiers ou surface principale | Interaction à surveiller |
| ---: | --- | --- |
| #82 | sitemap, robots, script ciblé et documentation | `/ressources` devra rejoindre sa future allowlist, pas la contourner. |
| #85 | Webform Contact, bloc et scripts ciblés | Aucune collision. |
| #86 | template de l'ancienne entrée réservation | Aucune collision. |
| #87 | architecture de contenu et script d'application | Source éditoriale citée ici ; réauditer après merge. |
| #88 | frontend historique, redirects, headers, robots, sitemap | Ne pas coupler le hub à son retrait. |
| #89 | View/bloc Concerts et script ciblé | Aucune collision. |
| #90 | View panier, template vide et script ciblé | La future racine Boutique reste hors de cette proposition. |
| #92 | templates des hubs publics et includes partagés | Réévaluer le langage de composants après merge, sans reprendre les cartes géantes. |
| #94 | footer et shells de page | Vérifier le shell final avant implémentation. |
| #95 | `styles.css` pour le contraste interactif | Rebaser avant toute reprise de tokens. |
| #96 | design/prototype d'authentification | Même namespace documentaire, slug distinct. |
| #97 | design/prototype d'accueil éditorial | Même namespace ; précédent utile de liste calme, aucun chemin commun. |
| #98 | contrôleur BGFX et sa documentation | Réauditer le mouvement/fond après merge. |
| #99 | implémentation auth, bibliothèque, CSS et thème | Peut modifier le poids réel des actions du header. |

Le prototype ne consomme aucun fichier de ces PR. Une future implémentation
devra repartir de leur état fusionné, pas recopier leurs branches.

## Comparaison des modèles maintenables

| Critère | A. Page éditoriale simple | B. Type de contenu Ressource gérée |
| --- | --- | --- |
| Bon contexte | Petit ensemble stable, une seule restitution, un responsable. | Catalogue croissant, plusieurs contributeurs ou réemploi. |
| Stockage | Un manifeste versionné, projeté dans le corps révisable d'une seule Basic Page. | Un nœud par ressource, champs structurés, révisions. |
| Organisation | Titres de section et ancres rédigés dans la page. | Taxonomies contrôlées et View groupée. |
| Administration | Intake CSV, revue du manifeste et révision de page entière ; aucune seconde édition manuelle. | Formulaire dédié, filtres admin, opérations en masse possibles. |
| Publication | Statut de la page entière ; retrait d'une ligne par édition. | Statut et modération par notice. |
| Vérification | Date et checklist entretenues manuellement dans le même document. | Date, état et rapports par notice ; automatisation possible. |
| Sécurité URL | Contrôle prépublication global ; contrainte de champ difficile. | Contrainte de champ HTTPS/hôte et tests unitaires dédiés. |
| Routes publiques | Une seule route approuvée. | Risque de canonical Node et de routes de termes supplémentaires. |
| Sitemap | Un seul canonical interne. | Hub seulement après exclusion explicite du bundle et des vocabulaires ; pages de notices uniquement si approuvées. |
| Coût initial | Faible, cohérent avec un inventaire encore inconnu. | Configuration, code d'accès/canonical, View, permissions, workflow et tests. |
| Limite | Fragile si les ajouts et vérifications deviennent fréquents. | Surdimensionné pour quelques liens rarement modifiés. |

### Seuil de passage obligatoire vers B

Le modèle A reste acceptable seulement tant que **toutes** les conditions
suivantes sont vraies :

- au plus 20 ressources publiées ;
- au plus 12 créations, modifications, masquages ou suppressions de notices
  dans toute fenêtre glissante de 90 jours ;
- une seule page publique et un seul responsable éditorial identifié ;
- aucun besoin de réutiliser une notice dans deux pages ;
- aucun filtre serveur, recherche, import, export ou classement dynamique ;
- aucune validation indépendante ou échéance automatisée par notice ;
- aucune page canonique individuelle voulue pour les ressources.

Le passage à B doit être planifié **avant la publication suivante** dès qu'un
seul plafond est dépassé ou qu'un besoin structurel apparaît : le treizième
changement dans n'importe quelle fenêtre de 90 jours déclenche donc B. Les
commits du manifeste font foi pour ce comptage. Le propriétaire réévalue les
autres critères chaque trimestre. « 20 » n'est pas une prévision de volume :
c'est une limite de gouvernance pour éviter qu'une page manuelle devienne un
mini-CMS caché. L'absence de responsable éditorial bloque le lancement ; elle
n'est pas résolue automatiquement par le choix de B.

## Contrat de notice proposé

Le même contrat logique s'applique à A et B. Le CSV exact demandé au
propriétaire est un **formulaire d'intake**, pas le registre éditorial complet :
il ne contient volontairement ni statut, ni poids, ni clé technique. Dans A,
une future PR normalise les lignes approuvées dans un manifeste versionné et
ajoute une `resource_key` immuable ; un changement d'URL ne crée pas une nouvelle
identité ni ne perd l'historique. Le manifeste stocke une liste ordonnée des
thèmes, puis l'ordre des notices dans chaque thème ; l'ordre brut du CSV
d'intake n'est jamais interprété comme une approbation. La présence d'une
notice vaut « publiée » et Git conserve l'historique d'un retrait. Dès qu'il
faut distinguer activement brouillon, masquage et archive par notice, B est
déclenché. Dans B, le contrat complet devient un schéma Drupal avec statuts et
poids explicites.

| Donnée | Obligation | Type/contrainte proposée | Rendu public |
| --- | --- | --- | --- |
| Titre public | Requis | Texte brut, 1 valeur, concis ; aucune marque modifiée. | Titre du lien. |
| URL externe | Requise | URI absolue HTTPS, 1 valeur, hôte valide, sans userinfo. | Lien direct même onglet et URL/hôte lisible si utile. |
| Description factuelle | Requise | Texte brut, une phrase, cible 90–240 caractères. | Sous le titre, sans HTML ni slogan inventé. |
| Thème | Requis à partir de 2 thèmes | Terme contrôlé ; valeurs fournies par le propriétaire. | Intertitre et métadonnée discrète. |
| Type de ressource | Requis | Terme contrôlé ; aucune valeur n'est créée dans cette phase. | Métadonnée. |
| Langue | Requise | Liste contrôlée fondée sur l'inventaire, une ou plusieurs valeurs. | Métadonnée lorsque discriminante. |
| Public visé | Facultatif | Liste/terme contrôlé, seulement si utile à la décision. | Métadonnée facultative. |
| Statut de publication | Requis | Brouillon, à relire, publié, masqué temporairement ou archivé. | Seul « publié » apparaît. |
| Dernière vérification | Requise avant publication | Date ISO `AAAA-MM-JJ`, mise à jour après contrôle humain ou sûr. | Affichée uniquement selon la règle approuvée. |
| Note éditoriale | Facultative | Texte brut interne ; source, limites et décision de sélection. | Non rendue par défaut. |
| Motif d'inclusion | Requis en interne | Une phrase factuelle, distincte de la description publique. | Jamais automatique. |
| Relation avec Uni-Songes | Requise en interne | Déclaration propriétaire, y compris « aucune » si confirmé. | Jamais convertie en « partenaire » sans accord. |

Ne font pas partie du contrat : contenu aspiré, notation, avis, tracking
d'affiliation, miniature automatique, favicon tiers, logo copié, contenu
embarqué, statut de partenaire déduit ou texte de recommandation automatique.

## Tableau exact à compléter par le propriétaire

Le fichier `docs/design/resources-hub-inventory-template.csv` contient les
colonnes exactes ci-dessous et une seule ligne fictive à remplacer. Chaque
ressource réelle occupe une ligne. Une cellule vide ne vaut jamais approbation.

| URL | public title | one-sentence description | theme | type | language | intended audience | reason for inclusion | relationship to Uni-Songes, if any | permission/logo status | last checked date |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `https://example.invalid/ressource-exemple` | Ressource exemple | Description factuelle fictive à remplacer. | Thème exemple (fictif) | Type exemple (fictif) | Langue exemple (fictive) | Public exemple (fictif) | À compléter | À compléter | À compléter | `AAAA-MM-JJ` |

Règles de remise : UTF-8, une URL par ligne, date ISO, aucune formule de tableur,
aucune redirection raccourcie et aucun logo joint à ce stade. Le propriétaire
doit remplacer ou supprimer la ligne exemple ; elle ne doit jamais être
importée comme contenu réel. L'import futur doit utiliser un parseur CSV strict,
borner taille de fichier/cellule, refuser les caractères de contrôle et toute
cellule qui commence, après espaces, par `=`, `+`, `-` ou `@`. Il n'exécute ni
formule ni conversion automatique de tableur.

## Sécurité des liens externes

### Validation à l'enregistrement

La future implémentation doit appliquer dans cet ordre :

1. supprimer les espaces de début/fin et refuser caractères de contrôle,
   retours de ligne, antislash ambigu et userinfo `nom:motdepasse@hote` ;
2. parser l'URL comme URI absolue, jamais comme chemin relatif ;
3. n'accepter que `https:` ; un éventuel `http:` exige une exception écrite,
   datée et liée à une ressource précise ;
4. rejeter explicitement `javascript:`, `data:`, `file:`, `vbscript:`, `blob:`,
   schéma vide et tout schéma non autorisé ;
5. normaliser le nom d'hôte Unicode selon IDNA2008 vers sa forme ASCII avant
   comparaison ; refuser hôte vide, label vide, point final, forme ambiguë,
   `localhost`, domaine local, hôte sans point et littéral IP ; afficher au
   relecteur les formes Unicode et ASCII lorsqu'elles diffèrent ;
6. refuser `.local`, `.internal`, `.invalid`, `.test` et `.example`, sauf dans
   les fixtures de test qui ne sont jamais importables ;
7. n'accepter qu'un port absent ou le port HTTPS par défaut 443 ; le port 80
   n'est possible qu'avec l'exception HTTP correspondante et tout autre port
   est refusé ; borner la sérialisation finale à 2 048 octets ;
8. conserver le chemin, la query utile et le fragment approuvés, sans ajouter
   ni propager de paramètres de tracking ; soumettre chaque query à une revue
   qui refuse paramètres de campagne, d'affiliation ou identifiants inutiles ;
9. rendre directement l'URL stockée. Ne jamais passer par `/go`, `/out`, un
   paramètre `destination` ou une autre route de redirection ouverte ;
10. encoder le texte et les attributs avec les API Drupal, sans HTML provenant
   de la ressource externe.

Une redirection HTTP rencontrée par un contrôle de santé doit être revalidée à
chaque saut : HTTPS, hôte public sûr, limite de sauts et destination finale
consignée. Elle n'est jamais recopiée automatiquement dans le champ.

### Rendu public

- ouverture dans le même onglet par défaut, donc aucun `target` ;
- texte visible ou accessible « site externe » et symbole `↗` décoratif ;
- domaine ou URL affiché seulement s'il améliore la compréhension ;
- aucune requête vers l'hôte avant l'activation volontaire du lien ;
- aucun favicon, aperçu, iframe, logo, pixel, DNS prefetch ou preconnect tiers ;
- aucune query ajoutée, aucun paramètre d'affiliation et aucune réécriture
  silencieuse ;
- aucun terme « recommandé », « partenaire », « officiel » ou équivalent sans
  preuve et validation éditoriale.

Le clic volontaire établit néanmoins une connexion vers le site externe et le
navigateur peut lui transmettre un référent selon la politique HTTP du site.
Avant implémentation, cette décision est un prérequis bloquant. Le choix préféré
pour le hub est un en-tête `Referrer-Policy: no-referrer`, limité à la route si
possible et testé contre le shell ; l'alternative est une acceptation explicite
de la divulgation de l'origine avec `strict-origin-when-cross-origin`. Un rendu
contrôlé peut aussi produire `rel="noreferrer"`. Le format `basic_html` actuel
ne conserve pas `rel` : pour A, privilégier l'en-tête et ne promettre aucune
protection avant sa validation. Dans tous les cas, le site tiers voit
nécessairement la visite après activation du lien.

Si un futur propriétaire exige un nouvel onglet, le rendu doit ajouter
`target="_blank"` **et** `rel="noopener noreferrer"`, annoncer « s'ouvre dans un
nouvel onglet » dans le nom accessible et rester une préférence explicite, pas
un comportement automatique.

### Contrôle de santé sans collecte de contenu

La première publication exige un contrôle humain. Ensuite, la recommandation
de gouvernance à approuver est : vérification manuelle semestrielle de toutes
les notices, complétée si utile par un contrôle mensuel léger.

Un contrôleur automatisé futur doit être isolé du rendu public et protégé
contre le SSRF. À chaque requête et à chaque redirection, il normalise l'hôte,
résout tous les enregistrements A/AAAA, refuse la destination si une adresse
n'est pas globalement routable, puis attache la connexion à une adresse
publique validée tout en conservant l'hôte d'origine pour SNI et la vérification
du certificat TLS. Il recommence cette séquence à chaque saut afin de bloquer le
DNS rebinding. Il n'utilise ni proxy implicite, ni authentification, ni cookie,
ni corps sortant ; il applique timeout court, taille de réponse maximale,
nombre de redirects limité, identité explicite, débit modéré, aucune exécution
JavaScript et aucun stockage du corps. `HEAD` peut être essayé puis un `GET`
borné si le serveur refuse `HEAD`.

Les journaux ne conservent ni secret, ni query, ni fragment, ni message d'erreur
qui recopierait l'URL complète : ces valeurs sont expurgées ou remplacées par
un hash. `2xx` est sain ; `3xx` reste à revoir sans réécriture automatique ;
`401`, `403`, `429`, `5xx`, timeout et erreur TLS sont indéterminés. Deux
réponses définitives `404`/`410` ou deux échecs `NXDOMAIN`, observés à au moins
sept jours d'intervalle, créent seulement une tâche de revue humaine. Aucune
dépublication n'est automatique. Seul un relecteur peut ensuite passer la
notice à « masqué temporairement ». Le lien public disparaît, l'enregistrement
et son historique restent disponibles. Il ne faut ni rediriger vers l'accueil,
ni substituer un autre site, ni garder un lien mort cliquable. Une notice
archivée n'est supprimée qu'après la politique de rétention approuvée.

## Architecture de l'information et design public

### Ordre de page

1. H1 « Ressources » ;
2. introduction de deux à trois phrases : nature externe, critères à confirmer,
   absence d'approbation implicite ;
3. si plusieurs thèmes existent, sommaire d'ancres textuelles court ;
4. groupes de thèmes dans l'ordre éditorial fourni par le propriétaire ;
5. au sein d'un groupe, ordre manuel stable, puis titre comme repli ;
6. notices plates séparées par un filet ;
7. éventuelle note de maintenance globale, jamais un tableau de statuts.

Avec un seul thème, le sommaire disparaît. Avec zéro ressource publiée, la page
conserve son H1 et une phrase honnête ; elle n'affiche ni thème vide, ni CTA
inventé. À fort volume, utiliser une pagination normale ou des filtres serveur
après passage à B, jamais un infinite scroll.

### Composition d'une notice

- titre lié, assez saillant pour le balayage visuel ;
- signe externe et libellé accessible intégrés au lien ;
- description sur une mesure de lecture d'environ 65 caractères ;
- métadonnées en texte simple séparé par des points, pas en grandes pilules ;
- URL longue avec `overflow-wrap: anywhere`, sans troncature qui masquerait
  l'hôte ;
- date de vérification plus discrète que la description et omise si sa règle
  n'est pas comprise par le public.

Les notices ne sont pas des cartes cliquables intégrales. Seul le lien est
interactif. Aucun hover ne doit déplacer la mise en page. Le focus clavier est
visible avec une outline de 3 px et un offset suffisant.

## Analyse du futur menu à sept racines

Ordre proposé, identique sur desktop et dans le drawer :

| Poids indicatif | Libellé | Rôle |
| ---: | --- | --- |
| 0 | Cours & Stages | Offre pédagogique. |
| 10 | Concerts & Événements | Agenda et événements. |
| 20 | Projets collectifs | Participation et projets. |
| 30 | Ressources | Liens externes éditorialisés. |
| 40 | Boutique | Surface commerciale future, hors périmètre. |
| 50 | À propos | Informations institutionnelles. |
| 60 | Contact | Action de contact. |

« Ressources » vient après les activités propres à Uni-Songes et avant la
surface commerciale : le parcours passe de ce que fait l'association à ce
qu'elle signale à l'extérieur, puis aux actions transactionnelles et
institutionnelles.

« Ressources » ne doit normalement avoir **aucun enfant** dans le menu global.
Les catégories réelles ne sont pas encore connues ; les inventer créerait des
promesses et des URLs. Même après validation, des thèmes évolutifs appartiennent
au sommaire interne de la page. Un sous-menu global ne serait justifié que si
chaque enfant devenait une destination canonique stable avec un volume et un
besoin utilisateur prouvés, décision qui dépasse ce projet.

Dans le drawer, Ressources reste donc un lien simple, sans chevron ni bouton de
disclosure. Son ordre source ne change pas. Le drawer doit rester scrollable,
ne pas intercepter Tab/Maj+Tab, fermer avec Échap, restaurer le focus au bouton
et laisser les actions de compte après la navigation.

### État transitoire si Boutique n'est pas encore présente

L'ajout de Ressources seul produit temporairement **six** racines : poids 0
Cours & Stages, 10 Concerts & Événements, 20 Projets collectifs, 30 Ressources,
50 À propos et 60 Contact. Le poids 40 est réservé à Boutique, mais la future PR
Ressources ne crée ni lien, ni route, ni libellé Boutique. Si Boutique existe
déjà au moment de l'implémentation, l'ordre final à sept racines ci-dessus
s'applique directement. Dans les deux cas, Ressources est immédiatement après
Projets collectifs et À propos/Contact sont repondérés de façon ciblée.

La validation responsive doit couvrir l'arbre actif à six racines et une
fixture représentative à sept racines. La fixture ne constitue ni une
publication de Boutique ni une validation de son URL.

## Prototype statique

`docs/prototypes/resources-hub/index.html` est une seule planche avec un H1.
Elle montre des variantes mutuellement exclusives en production :

| État demandé | Représentation |
| --- | --- |
| Aucune ressource | Message vide honnête, sans faux thème. |
| Quelques ressources | Deux groupes et notices détaillées. |
| Plusieurs thèmes | Sommaire d'ancres discret et groupes. |
| Un seul thème | Une notice exacte, sans sommaire inutile. |
| Nombreuses ressources | Vingt et une notices exactes, en liste dense, plate et groupée, sans masonry. |
| Titre long | Repli naturel dans la colonne. |
| URL longue | `overflow-wrap: anywhere`, hôte non masqué. |
| Indisponible/masquée | Aperçu éditorial séparé ; la notice est absente du rendu public. |
| Mobile | CSS fluide, menu d'aperçu compact, métadonnées verticales et reflow à 320 px. |

L'aperçu du menu n'est pas une implémentation : les autres racines sont du
texte non cliquable et aucune route publique n'est créée. Le `details` mobile
sert uniquement à tester clavier/reflow sans JavaScript. La future production
conserve le menu Drupal et son drawer existants.

Le prototype ne charge ni image, ni fonte, ni script, ni favicon, ni CDN, ni
analytics. Le seul stylesheet est relatif et local. Les liens externes
pointent vers `example.invalid`, ne définissent pas `target` et ne déclenchent
donc aucune requête au chargement.

La planche ajoute un H2 par état, puis décale ses groupes/notices en H3/H4. En
production, où un seul état existe, la hiérarchie cible est H1 de page, H2 de
thème et H3 de notice. Son seuil CSS de `84rem` sert seulement à exposer les
deux aperçus du menu ; le seuil de production reste mesuré par le contenu. La
planche défile avec le document afin de juxtaposer les états : elle ne valide
pas le scrollframe de production, qui doit être contrôlé dans la future matrice
navigateur. Elle matérialise exactement 0, 1 et 21 notices, ainsi qu'un état
« quelques » à quatre notices. La future implémentation vérifie aussi 20, les
deux côtés du seuil A/B et les combinaisons qui ne justifient pas une seconde
planche documentaire.

## Handoff d'implémentation

### Modèle A recommandé au lancement

Après validation propriétaire :

- créer une Basic Page unique intitulée « Ressources » avec l'alias exact
  `/ressources` ;
- créer, dans la future PR d'implémentation, un manifeste versionné normalisé à
  partir du CSV signé : c'est l'unique source éditoriale ; chaque ligne reçoit
  une `resource_key` immuable, une liste de thèmes fixe l'ordre des groupes,
  l'ordre dans chaque groupe fixe celui des notices et seules les lignes
  approuvées présentes sont émises ;
- traiter le corps Drupal comme une projection générée, jamais comme une
  seconde zone d'édition manuelle et jamais comme une copie du prototype ;
- échapper chaque cellule comme texte, construire seulement le sous-ensemble
  `basic_html` autorisé (`h2`, `h3`, `p`, `ul`, `li`, `a[href]`) avec les API
  Link/Url et de rendu Drupal, et interdire concaténation vers `full_html` ou
  `#markup` brut ; le texte « site externe ↗ » appartient au lien lui-même ;
- allowlister les seules colonnes publiques titre, URL, description, thème,
  type, langue, public et date ; motif, relation et permission/logo restent
  internes et sont toujours ignorés par le générateur ;
- ajouter un style étroitement limité à la route/au corps Ressources, sans
  modifier les cartes globales ; les `section`, `article`, `span`, `time` et
  `rel` du prototype statique ne sont pas copiés dans le champ Basic HTML ;
- ajouter une seule entrée racine après Projets collectifs et repondérer À
  propos/Contact selon l'état de menu approuvé ci-dessous ;
- ne créer ni sous-menu, ni terme, ni page de détail ;
- inclure seulement le canonical du hub dans la politique sitemap approuvée.

Le script de contenu futur doit être idempotent, verbeux, retrouver la page et
le lien par alias/destination, produire un dry-run, bloquer les doublons et ne
jamais importer toute la configuration. Il refuse une différence manuelle entre
manifeste et corps au lieu de l'écraser silencieusement. Son deuxième dry-run
après application doit être sans opération.

Workflow A : le propriétaire approuve d'abord le CSV ; un reviewer normalise et
versionne les seules lignes publiables ; le générateur prépare une nouvelle
révision ou prévisualisation Basic HTML ; le préflight revalide toutes les URLs
et compare nombre, ordre, titres, descriptions et dates au manifeste ; le
responsable publie la révision complète. Toute modification repasse par cette
séquence. Retirer une ligne dans un commit revu masque la notice tout en gardant
son historique Git, puis génère une nouvelle révision sans dépublier les autres
notices. Un besoin de statuts actifs distincts déclenche B.

La future PR doit aussi réconcilier, avec un texte fourni ou approuvé par le
propriétaire, les deux mentions actuelles qui attribuent déjà des « ressources »
au Blog : carte de `/a-propos` et introduction de `/blog`. Seule la copie change ;
les URLs, la structure du Blog et son rôle de contenu rédigé par Uni-Songes sont
conservés. Les sources versionnées correspondantes sont synchronisées en même
temps : `drupal/scripts/apply-content-architecture-2026.sh:593,610` et
`docs/functional/content-architecture-2026.md:26`. Aucune formulation de
remplacement n'est inventée ici.

### Modèle B prêt pour le seuil de passage

Configuration recommandée, uniquement après déclenchement et validation des
URLs :

| Élément | Machine name proposé | Contrat |
| --- | --- | --- |
| Bundle Node | `resource` | Une notice par nœud, révisions activées, commentaires absents ; base fields `status = 0`, `promote = 0`, `sticky = 0` par défaut avant toute création. |
| Vocabulaire thème | `resource_theme` | Termes créés seulement depuis la liste propriétaire ; ordre manuel. |
| Vocabulaire type | `resource_type` | Termes contrôlés ; aucune auto-création par les éditeurs ordinaires. |
| View publique | `resources_hub` / `block_hub` | Bloc placé seulement sur la Basic Page `/ressources`, `status = 1`, groupement thème, ordre manuel puis titre ; aucun display Page. |
| View admin | `resources_admin` | Filtres statut, thème, type, langue et date de vérification. |
| Mode d'affichage | `resource_listing` | Champs explicites, sans lien vers canonical Node. |

Champs proposés :

- `title` — titre public ;
- `field_resource_url` — Link requis, cardinalité 1, contrainte HTTPS/hôte ;
- `field_resource_summary` — texte brut requis, cardinalité 1 ;
- `field_resource_theme` — référence à `resource_theme` ;
- `field_resource_type` — référence à `resource_type` ;
- `field_resource_language` — liste contrôlée multivaleur si nécessaire ;
- `field_resource_audience` — référence/liste facultative ;
- `field_resource_key` — texte ASCII immuable, unique et interne ;
- `field_resource_weight` — entier pour l'ordre éditorial ;
- `field_last_verified` — date requise à la publication ;
- `field_editorial_note` — texte long interne ;
- champs internes de motif et relation si le propriétaire veut les conserver
  dans Drupal plutôt que dans l'inventaire.

La Basic Page de A reste le hub, son introduction et l'unique propriétaire de
l'alias `/ressources`. Au passage à B, ses lignes manuelles sont remplacées par
le bloc View avec une visibilité exacte sur cet alias. La View ne crée aucune
seconde route publique.

Les taxonomies ne doivent pas exposer automatiquement des pages publiques. Un
nœud Drupal possède normalement une route canonique ; le catalogue souhaité
est au contraire listing-only. Avant B, une décision d'architecture est donc
obligatoire :

1. choix recommandé — énumérer sur la version Drupal cible toutes les routes
   anonymes d'affichage des nœuds `resource` et des termes concernés, puis leur
   faire renvoyer 404 par un contrôle limité à ces routes, tout en autorisant
   les entités publiées dans la View ; cela couvre au minimum les canonicals
   Node/terme, révisions, `latest-version` et variantes de traduction ;
2. ou approuver des pages et alias individuels, avec leur contenu, canonical et
   politique sitemap propres.

Le contrôle listing-only doit agir au niveau de la requête/route après
conversion des paramètres, **pas** via un refus générique d'accès à l'entité qui
casserait aussi la View ou l'administration. La future PR inventorie les noms
de routes réellement présents et échoue au déploiement si une route attendue
manque ou si une nouvelle route publique du bundle reste sans politique. Les
alias et `/node/{id}` résolvent vers ce même contrat ; recherche du site,
JSON:API/REST s'ils sont activés et sitemap excluent également ce bundle et ces
vocabulaires. Les tests doivent prouver à la fois la 404 anonyme directe et le
rendu du même nœud publié dans le bloc. Ces exclusions, ainsi que le défaut
`status = 0`, sont actives avant la première création. Si ce contrat ne peut
pas être garanti durablement, B doit employer une entité de contenu non
routable dédiée plutôt que laisser fuiter des canonicals implicites.

Ne jamais rediriger le canonical Node vers `field_resource_url`, ce qui créerait
une surface de redirection ouverte si la validation régressait.

### Workflow et permissions

Workflow B recommandé : Brouillon → À relire → Publié → Masqué temporairement →
Archivé. `Publié` est le seul état avec `published = true` et
`default_revision = true`. Brouillon et À relire sont non publiés ; leurs
propriétés de révision doivent préserver une précédente révision publique le
cas échéant. Masqué et Archivé sont non publiés et deviennent révisions par
défaut afin que le retrait soit effectif. Une transition vers Publié exige URL
valide, description factuelle, thème/type approuvés et `field_last_verified` du
jour du contrôle.

| Capacité | Éditeur de ressources | Relecteur/publisher | Administrateur |
| --- | :---: | :---: | :---: |
| Créer et modifier un brouillon | Oui | Oui | Oui |
| Proposer pour relecture | Oui | Oui | Oui |
| Publier/republier | Non | Oui | Oui |
| Masquer temporairement | Non | Oui | Oui |
| Archiver/restaurer | Non | Oui | Oui |
| Supprimer définitivement | Non | Non | Oui, après politique de rétention |
| Gérer les termes thème/type | Non | Selon délégation | Oui |
| Modifier la politique URL/workflow | Non | Non | Oui via code revu |

Appliquer le moindre privilège : permissions propres au bundle, aucune
permission générale d'administrer les nœuds/taxonomies et aucune auto-création
de termes. Le droit de publier doit rester distinct du droit de saisir si
plusieurs contributeurs déclenchent B.

Créer des rôles dédiés sans étendre implicitement le rôle actuel
`content_editor`. Les champs internes `field_editorial_note`, motif d'inclusion
et relation avec Uni-Songes restent en texte brut et sont protégés par un
contrôle d'accès de champ côté serveur (`hook_entity_field_access()` ou
équivalent testé) pour les rôles non autorisés. Les masquer dans un View mode,
un formulaire ou une View ne suffit pas.

### Canonical, sitemap et métadonnées

- Hub : canonical absolu HTTPS `/ressources`, une seule entrée sitemap après
  existence, publication et validation anonyme.
- URLs externes : jamais canonical Uni-Songes, jamais sitemap, jamais proxy.
- B listing-only : bundle `resource` et vocabulaires exclus du sitemap ; routes
  Node/terme refusées ou non exposées selon la décision approuvée.
- B avec pages individuelles approuvées : alias non numériques uniques,
  canonical auto-référent et inclusion seulement pour les notices publiées.
- Les queries de thème ou de pagination ne créent pas de canonicals concurrents
  sans stratégie SEO approuvée.
- Une ressource masquée n'entraîne ni redirect, ni canonical externe ; sa page
  individuelle éventuelle suit une décision 404/410/redirect interne séparée.

### Déploiement et rollback futurs

Prérequis : inventaire signé, thèmes/types approuvés, décision canonical de B,
backup base de données, dry-run ciblé propre, branche à jour de
`origin/release/prod` et fenêtre de validation staging.

Le rollout doit séparer : schéma/configuration, contenu, bloc View/présentation,
route, menu, puis sitemap. Chaque étape s'arrête sur drift inattendu. Aucun
`config:import` global, aucune écriture SQL brute et aucune suppression de
contenu.

La migration A vers B conserve la dernière révision A et mappe chaque ligne par
`resource_key`. Elle importe d'abord tous les nœuds en brouillon et compare
compte, ordre, clés et hash des champs publics au manifeste. Le relecteur fait
ensuite passer les nœuds validés à Publié ; avec toutes les protections de
routes déjà actives, un préflight anonyme sur staging recompare le rendu de la
View au manifeste. Le bloc ne bascule qu'après ce second contrôle, en une étape
réversible ; sinon A reste affiché.

Avant le premier lancement public, un rollback peut retirer le lien de menu,
dépublier la Basic Page et annuler son entrée sitemap, puisque le contrat public
n'existe pas encore. Après lancement, `/ressources` doit rester stable : le
rollback A restaure la dernière révision connue comme sûre ou une page vide
honnête au même alias, puis conserve normalement menu, canonical et sitemap.
Leur retrait post-lancement exige une décision propriétaire séparée.

Le rollback B désactive le placement du bloc, dépublie les notices concernées
et restaure sur la Basic Page le dernier corps A connu comme sûr, sans changer
l'alias. Il ne supprime ni bundle, ni champ, ni vocabulaire tant que des
données, références ou révisions existent ; une suppression ultérieure exige
export vérifié, inventaire à zéro et approbation explicite. Toute redirection ou
suppression de route publique reste soumise à validation propriétaire même
pendant le rollback.

La présente PR documentaire se rollback simplement par revert de ses quatre
fichiers ; elle ne nécessite aucune opération Drupal.

## Matrice DDEV et navigateurs pour la future implémentation

Cette phase n'exécute volontairement ni DDEV, Docker, Drush, Chromium ou
Mailpit. La future PR fonctionnelle devra réserver l'environnement partagé puis
documenter les commandes exactes adaptées au dépôt. Elle ne doit pas utiliser
un import complet de configuration tant que le drift reste bloquant.

| Axe | Cas minimum |
| --- | --- |
| Rôles | Anonyme, éditeur de ressources, relecteur, administrateur. |
| Données | 0, 1, quelques et 20/21 notices ; un et plusieurs thèmes ; titre/URL longs ; notice masquée. |
| Viewports | 320, 360/390, 640, 768, seuil compact mesuré ±10 px, 1280, 1440 px. |
| Navigateurs | Chromium, Firefox, WebKit/Safari récent ; clavier seul et zoom 200/400 %. |
| Préférences | reduced motion, contraste renforcé/forced colors si disponible, Save-Data. |
| Navigation | arbre actif à six racines si Boutique est absente, fixture à sept racines, drawer, Échap, focus restauré, Tab/Maj+Tab, sans enfant Ressources. |
| Liens | HTTPS valide ; chaque schéma interdit ; hôte invalide/local/IP ; URL Unicode/punycode ; redirect sûr/dangereux ; URL longue. |
| Publication | brouillon absent, publié visible, masqué absent, date requise, permissions négatives. |
| SEO | un H1, canonical, sitemap interne seulement, absence `/node/{id}` et pages de termes non approuvées. |
| Réseau | aucune sous-requête externe au chargement ; activation du lien non suivie par les tests UI. |
| Régression | shell, fond, footer, Contact, panier et compte inchangés ; seule la copie de frontière approuvée de Blog/À propos peut évoluer, sans modifier leur structure. |

Critères 320 px : aucune barre horizontale, aucun contenu coupé, métadonnées en
colonne si nécessaire, URL repliée, cible de 44 px, focus visible et ordre de
lecture identique au DOM. La date ou le domaine ne doit jamais forcer la notice
au-delà du viewport.

## Validation statique de cette proposition

Les contrôles attendus avant commit sont :

- HTML parsable, doctype HTML5, `lang="fr"`, UTF-8, viewport et un seul H1 ;
- CSS équilibré, sans `@import`, `url()`, animation ou largeur minimale causant
  un débordement ;
- aucune image, iframe, vidéo, script ou ressource externe au chargement ;
- uniquement `https://example.invalid/...` pour les liens de notices ;
- aucun `target`, tracking, affiliation, logo ou nom de marque tierce ;
- tous les fragments internes résolus, IDs uniques, skip link et focus visibles ;
- fichiers UTF-8 normalisés NFC et fins de ligne LF ;
- diff limité exactement aux quatre chemins autorisés ;
- aucune modification Drupal, legacy ou configuration ;
- `git diff --check` et scan de secrets sur le diff ;
- revues indépendantes éditoriale, sécurité, UX et accessibilité.

Les résultats exacts, y compris les limites liées à l'absence de navigateur et
de DDEV dans cette phase, doivent être consignés dans la draft PR.

## Décisions encore requises du propriétaire

1. approuver la frontière Ressources externes / Blog éditorial ;
2. fournir le tableau réel complet et confirmer le nombre de liens au lancement ;
3. approuver chaque URL, titre, description et motif d'inclusion ;
4. fournir les vrais thèmes, types, langues, publics et leur ordre ;
5. confirmer le modèle A au lancement ou signaler un déclencheur immédiat de B ;
6. approuver `/ressources`, son canonical et son insertion après Projets
   collectifs, avant Boutique ;
7. confirmer que Ressources reste sans enfant dans le menu ;
8. préciser l'URL et l'état de la future Boutique, hors de cette PR ;
9. désigner le responsable éditorial, le relecteur éventuel et la cadence de
   vérification ;
10. décider si la date de vérification est publique et dans quelles conditions ;
11. confirmer la règle même onglet et la `Referrer-Policy`, ou documenter les
    rares exceptions ;
12. confirmer qu'aucun logo n'est utilisé ; sinon fournir permission écrite et
    un projet distinct ;
13. pour B, décider listing-only ou pages canoniques individuelles ;
14. coordonner le sitemap avec l'état finalement fusionné de la PR #82 ;
15. valider la matrice responsive sur l'arbre actif à six racines et la fixture
    future à sept racines.

Tant que ces décisions et les données réelles manquent, le prototype reste une
proposition fictive et ne doit pas être transformé en contenu de production.
