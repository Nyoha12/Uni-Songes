# Accueil éditorial du Blog — implémentation 2026

## Statut et périmètre

Cette évolution transforme `/accueil` en une surface éditoriale alimentée par
les Articles Drupal. La matrice statique et runtime a été exécutée sur une
installation DDEV locale jetable, sans donnée de production. Aucun déploiement,
aucune fusion et aucun accès au VPS n'ont eu lieu ; Mailpit n'a pas été requis.

La direction visuelle vient exactement de la PR de design #97 au commit
`7155cce198f99fb7e6b5b83716465bd3e1ca78a7`, avec les corrections produit
approuvées pour cette implémentation. Les trois fichiers de la PR #97 restent
inchangés.

## Audit de l'existant

### Intégration avec `release/prod`

La branche a été rebasée sur
`9021fc0197fc001ac3225e879cfa2c1a0b409e88`, merge de la PR #87 qui contient
aussi les PR #99 et #100 dans son ascendance. Le script complet
`apply-content-architecture-2026.sh` de #87 est préservé octet pour octet
(`SHA-256 9c92531dbde7141ac80107d0202e419cfd2695f027c44369207a4fe164980cdb`).
L'audit statique et Chromium après rebase confirme un seul H1, un seul `main`
et un seul chemin de messages fourni par le shell. Les routes d'authentification
et de compte, la présentation de leurs messages et tous les fichiers partagés
du thème restent inchangés.

Le delta complet reste limité à 17 fichiers. L'entrée unique ajoutée à
`core.extension.yml` est nécessaire pour représenter de façon reproductible le
module dont dépend le bloc synchronisé. Les seules adaptations au helper et à
la documentation Forum/Blog concernent la coexistence puis l'ordre de retrait
du troisième display de la View que cette feature partage ; elles ne modifient
pas le comportement des displays `default` et `block_1` de `/blog`.

### Accueil, shell et blocs

- `system.site:page.front` reste `/accueil` ; aucun alias ni chemin public
  n'est créé ou modifié.
- Le shell actif fournit déjà l'unique `main`, le header fixe, le scrollframe,
  le fond et la région de messages. Cette évolution n'en duplique aucun.
- Le bloc de titre au poids `-7` fournit l'unique H1 « Accueil ».
- Le bloc de contenu principal au poids `-3` rend le corps de la Basic page.
- Le nouveau bloc est placé au poids `0`, après ce corps, uniquement lorsque le
  chemin est exactement `/accueil`.
- Le footer en cours dans la PR #94 n'est ni copié ni modifié. Lorsqu'il sera
  intégré, il restera dans le scrollframe selon son propre contrat.

Le corps fusionné de `/accueil` contient une introduction promotionnelle, un
CTA de réservation et six grandes cartes. L'afficher avec la nouvelle liste
produirait une double homepage ; le helper ciblé reconnaît donc ce seul état
versionné exact et refuse toute autre valeur.

Le préflight verrouille aussi les configurations exactes des blocs de messages
(`-8`), de titre (`-7`) et de contenu principal (`-3`). Il refuse donc une
installation dans un shell qui ne garantirait plus ce H1, ce `main`, ce chemin
de messages ou l'ordre attendu, avant toute écriture.

### Blog et Articles

La View `blog_posts` est la source de vérité de la collection :

- table `node_field_data` ;
- bundle strict `article` ;
- `status = 1` ;
- tri `created DESC` ;
- mini-pager de 10 éléments ;
- accès `access content` et réécriture SQL d'accès Node conservée ;
- cache tagué avec contextes de langue, requête, grants Node et permissions.

Le display `/blog` existant `block_1` reste inchangé et continue d'alimenter le
bloc au poids `20` visible uniquement sur `/blog`. Le display homepage ajouté
est un `embed`, pas une page : il hérite de la même publication, du même tri,
du même accès et du même pager. Il ajoute seulement l'argument de thème et la
déduplication SQL nécessaire aux références multivaluées.

Le préflight relit aussi le bloc `/blog` stocké et effectif et exige son plugin,
sa visibilité, sa région et son poids exacts ; la homepage ne peut donc pas être
appliquée en masquant une dérive préalable de la collection complète.

Il n'existe aucun champ de date éditoriale distinct. `created` est donc utilisé
comme date discrète et comme clé de tri, sans le présenter comme une garantie
de première publication.

Le bundle Article fournit réellement :

- le titre de base Node ;
- `created` ;
- le corps facultatif `text_with_summary` ;
- `field_tags`, facultatif, multivalué et lié au vocabulaire `tags` ;
- une image facultative, qui n'est pas utilisée ici ;
- l'URL canonique retournée par Drupal.

Le teaser global ne convient pas à l'accueil : son formatter fabrique un
extrait de corps jusqu'à 600 caractères et rend l'image, les tags et les liens
standards. Il reste intact pour `/blog` et ses autres consommateurs.

Aucun pattern Pathauto Article n'est suivi. Chaque lien de lecture utilise donc
`$node->toUrl('canonical')` ; aucun slug ou alias n'est construit ou généré.

### Taxonomie, publication et accès anonyme

Le libellé public « thème » correspond aux vrais termes du vocabulaire existant
`tags` (« Étiquettes »). Aucun vocabulaire, terme ou identifiant n'est créé ou
codé en dur.

Le builder exécute le display homepage sous le compte anonyme. Le filtre
`status = 1`, la réécriture Node access de Views et une vérification d'accès
par entité empêchent qu'un Article non publié ou non accessible anonymement
alimente la liste, un titre, un résumé ou un choix de thème.

## Architecture implémentée

Le module `unisonges_editorial_home` possède toute la présentation :

- un bloc étroitement placé sur `/accueil` ;
- un builder injecté qui exécute `blog_posts:editorial_home` ;
- un template Twig de composant ;
- une bibliothèque CSS attachée par le render array du bloc ;
- aucune route et aucun JavaScript.

Le module est masqué de l'UI d'extensions et son bloc ne rend rien tant que la
copie d'activation/rollback cohérente n'existe pas. Une activation isolée par
API ou import de configuration ne constitue donc pas une installation de la
feature : elle ne remplace pas le Body, ne rend pas la liste à côté de l'ancien
accueil et ne verrouille pas l'édition de `/accueil`. Le helper refuse cet état
partiel ; il reste le seul chemin pris en charge pour installer les cinq parties
couplées.

Le validateur de désinstallation refuse un retrait générique lorsqu'une copie
de rollback est présente ; le helper vérifie séparément sa forme et sa
cohérence avec la feature complète. Une activation isolée sans cette copie
reste désinstallable par Drupal, afin de ne pas piéger l'opérateur. Pour un
rollback complet, le helper conserve la copie jusqu'à l'uninstall effectif sous
son autorisation mémoire verrouillée, puis la supprime. Il exige zéro module ou
thème actif dépendant et interdit à Drupal d'élargir la liste de modules à
désinstaller.

La bibliothèque du thème, son fichier `.theme` et `styles.css` ne sont pas
modifiés. Le CSS du module est borné à `.section-accueil` et au composant. Il
fait du scrollframe existant l'unique surface ivoire, sans changer son identité,
sa hauteur, son rôle de scroller ou le fond autonome.

Le rendu conserve les métadonnées de cache de la View et ajoute les contextes
du chemin, de la requête — dont `theme` et le pager — et des langues, ainsi que
les dépendances des Articles, termes, accès et listes d'entités réellement
consultés. Les URL sont générées avec collecte de leurs métadonnées et le tag
`route_match` invalide les canonical lorsque les alias changent.

Une requête qui contient `theme` est volontairement rendue non persistante dans
le render cache du bloc. Drupal peut ainsi distinguer avant construction un
paramètre unique valide d'une répétition ambiguë telle que
`?theme=1&theme=2`; cette dernière reste un état invalide vide et ne peut jamais
réutiliser la réponse mise en cache d'un filtre valide.

## Composition finale

Le H1 « Accueil » et la courte introduction gérée par le corps précèdent le
composant. Sur desktop, le composant forme une colonne éditoriale principale et
un rail statique de `15rem`. Le rail contient exactement deux disclosures
natifs, fermés par défaut :

1. « Articles par thème » ;
2. « À propos d’Uni-Songes ».

Sous le breakpoint de reflow, leur ordre source les place avant la liste. Ils
ne deviennent ni onglets horizontaux, ni sidebar permanente, ni overlay. Le
rail n'est jamais sticky.

La liste est verticale et plate. Chaque ligne contient seulement :

- une date issue de `created` ;
- zéro, un ou plusieurs vrais thèmes ;
- le titre en H3, élément visuel principal ;
- le texte du résumé explicite `body.summary` lorsqu'il n'est pas vide ;
- un seul lien texte discret vers le canonical Article.

Le corps n'est jamais tronqué pour fabriquer un résumé. Le balisage du résumé
explicite est ramené à son texte : il ne peut donc injecter ni titre secondaire,
ni média, ni bouton dans la ligne éditoriale. Aucun auteur, image, placeholder,
durée, statistique, bouton promotionnel ou contenu fictif n'est émis. Le premier
résultat reçoit un filet fin et un titre environ 10–12 % plus grand uniquement
sur la première page non filtrée. Il ne reçoit ni fond, ni ombre, ni boîte à
grand padding.

La présentation « À propos » est une adaptation courte du contenu approuvé de
`/a-propos` et contient un seul lien vers `/a-propos`. Elle n'ajoute aucun nom,
date, chiffre, récompense, lieu ou historique.

## Filtre `theme`

Le filtre est entièrement serveur et fonctionne sans JavaScript.

- « Tous les articles » omet `theme` et remet le pager à sa première page.
- Les choix viennent uniquement des termes `tags` publiés, accessibles et
  réellement référencés par au moins un Article éligible.
- Une sélection accepte un seul identifiant entier canonique. Le terme doit
  exister dans `tags`, être publié et accessible anonymement.
- Un terme réel et valide sans résultat ne devient pas un choix, mais une URL
  directe contrôlée produit l'état vide filtré et un retour vers tous les
  Articles.
- Une valeur inconnue, mal formée, issue d'un autre vocabulaire, non publiée ou
  inaccessible ne retombe jamais sur la collection complète et n'expose aucun
  libellé.
- Les liens générés ne conservent que `theme` et le `page` validés. Changer de
  thème omet `page`; le mini-pager conserve le thème courant et omet `page=0`.
- Les états filtrés, invalides, hors plage ou portant une clé GET non prise en
  charge reçoivent `noindex,follow`; aucune clé arbitraire n'est propagée. Une
  page hors plage indique qu'elle ne contient aucun Article et propose le retour
  à la première page de la collection courante. Le canonical front existant
  reste inchangé.
- `distinct: true` sur le display homepage garantit une ligne par Article même
  lorsque `field_tags` contient plusieurs valeurs.

Il n'y a aucun second paramètre de navigation temporelle, aucun contrôle
mois/année, aucune taxonomie supplémentaire et aucune route de collection
historique. Les Articles récents et anciens restent dans la même liste,
parcourable par vrais thèmes et par le pager existant. La View Core historique
désactivée n'est ni référencée ni activée.

## Transition ciblée du corps `/accueil`

Le corps cible est exactement :

```html
<section class="unisonges-editorial-home-intro">
  <p class="unisonges-editorial-home-intro__kicker">Uni-Songes · Blog</p>
  <p class="unisonges-editorial-home-intro__deck">Le Blog accueillera les actualités de l'association, des articles artistiques et pédagogiques, ainsi que des réflexions et des ressources autour de ses pratiques et de ses projets.</p>
  <a class="unisonges-editorial-home-intro__link" href="/blog">Parcourir le Blog complet</a>
</section>
```

Le helper résout `/accueil` par son unique PathAlias ; aucun NID n'est codé en
dur. Il exige une Basic page publiée, le front `/accueil`, une révision active
non divergente et le corps promotionnel exact fusionné dans
`apply-content-architecture-2026.sh`. Un corps vide ou toute autre variante
échoue donc en préflight sans écriture.

Il affiche le corps courant et le corps cible entre délimiteurs, avec longueur
et SHA-256. Avant la transition, il conserve l'item Body exact — valeur,
résumé, format et identité de contenu — dans un état privé au module. Le
rollback exige cette copie et restaure exactement l'état antérieur. Il ne crée,
ne supprime et ne modifie aucun autre contenu ou alias.

Le module protège aussi le Body de la page identifiée par `/accueil` pendant
qu'il est actif. Une modification de ce Body n'est autorisée que dans le process
CLI `--apply` de l'installateur éditorial, lié à son action, son token de plan et
son commit. Après le second préflight sous verrous, l'installateur définit en
plus une autorisation mémoire non transmissible portant exactement le token du
plan, l'action, le commit, le nœud et les tuples Body source/cible ; elle est
effacée en sortie de l'apply. L'identité enregistrée doit aussi correspondre à
l'alias `/accueil` effectivement résolu. Fournir de simples variables
d'environnement ne suffit pas. Le helper
historique `apply-content-architecture-2026.sh` reste inchangé, mais sa tentative
de réinstaller l'ancien corps promotionnel lève une exception et sa transaction
est rollbackée ; il ne peut donc pas laisser la double homepage active.
L'opérateur doit d'abord effectuer le rollback exact de cette feature.

Le helper Forum/Blog choisit lui aussi une variante exacte : il crée sa View à
deux displays lorsque l'accueil éditorial est absent, et n'accepte la variante
à trois displays que lorsque le module, le bloc et la copie de rollback sont
tous actifs. L'ordre de retrait est donc déterministe : rollback de l'accueil
éditorial, puis rollback Forum/Blog. L'ordre inverse est refusé à cause de la
dépendance du bloc homepage envers `views.view.blog_posts`.

## Déploiement ciblé

Le wrapper n'utilise pas Drush. Il démarre le Drupal verrouillé directement par
PHP et n'accepte aucun nom de module, de configuration, de route ou de contenu
fourni par l'opérateur.

Prérequis : checkout entièrement propre du commit relu (fichiers suivis et non
suivis), dépendances Composer installées,
PHP compatible avec le lock, origine du site confirmée, mode maintenance déjà
actif pour toute écriture, fenêtre exclusive et sauvegarde courante.

Depuis `drupal/` :

```bash
SITE_URI='https://approved-host.example'

./scripts/apply-editorial-home-blog-2026.sh \
  --site-uri="${SITE_URI}" \
  --dry-run

./scripts/apply-editorial-home-blog-2026.sh \
  --site-uri="${SITE_URI}" \
  --apply \
  --backup-confirmed \
  --plan-token='<SHA-256 imprimé par le dry-run>'
```

Rollback, après son propre dry-run :

```bash
./scripts/apply-editorial-home-blog-2026.sh \
  --site-uri="${SITE_URI}" \
  --rollback \
  --dry-run

./scripts/apply-editorial-home-blog-2026.sh \
  --site-uri="${SITE_URI}" \
  --rollback \
  --apply \
  --backup-confirmed \
  --plan-token='<SHA-256 imprimé par le dry-run rollback>'
```

Le plan allowliste uniquement :

- le passage du display Blog exact à sa variante exacte avec
  `editorial_home`, ou sa restauration ;
- l'activation ou désactivation de `unisonges_editorial_home` ;
- la création ou suppression du bloc exact ;
- la copie de rollback appartenant au feature ;
- le corps de l'unique page résolue par `/accueil`.

L'installation suit l'ordre View, module, bloc, Body, copie de rollback. Le
rollback suit l'ordre Body, bloc, View, module, suppression de la copie. Les
dépendances existent donc avant leurs consommateurs, et la preuve permettant
le rollback reste disponible jusqu'à la désinstallation autorisée du module.

Il n'exécute aucun import complet ou partiel et aucune requête SQL brute. Toutes
les dépendances sont validées avant écriture, puis relues sous verrous juste
avant application. Le token lie l'origine, le commit, les sources relues,
l'identité et la révision de `/accueil`, les snapshots de configuration et les
opérations exactes. Une cible déjà conforme produit zéro opération.

## Validation statique

Les validations exécutées dans cette PR sont consignées dans sa description et
comprennent : lint PHP et shell, parsing YAML strict, parsing CSS et Twig,
assertions sémantiques de View, revue de l'injection de dépendances, unicité des
UUID, garde exacte des fichiers, absence de conflit avec les PR ouvertes, scan
de secrets et `git diff --check`.

Les assertions spécifiques vérifient notamment `status=1`, `created DESC`, le
pager 10 hérité, la déduplication multitag, l'argument `theme` validé, les URLs
canoniques, deux `details` fermés, zéro H1 dans le composant, zéro image requise,
zéro JavaScript et aucune route de View.

Le composant lui-même reste utilisable sans JavaScript : disclosures natifs,
liens GET et pager ordinaire. Le shell existant applique toutefois sa classe de
navigation compacte par JavaScript. Un passage Chromium à 320 px avec JavaScript
désactivé a confirmé le rendu de la feature, l'ordre de ses contrôles, leur
ouverture native et l'absence de débordement, sans modification des fichiers de
navigation exclus de cette PR.

## Validation runtime locale — 3 septembre 2026

La validation a utilisé DDEV 1.25.3, Drupal 11.3.3, PHP 8.3, MariaDB 10.11 et
Chromium 140 piloté par Playwright 1.55. Un snapshot nommé
`pr103-editorial-home-pre-runtime-20260902T180500Z` a précédé toute écriture.
L'état représentatif a été construit uniquement par les API Drupal et les
helpers ciblés du dépôt, sans import de configuration, SQL brut ni donnée de
production.

### Préflight et cycle de vie

Onze états invalides ont chacun été testés séparément : prérequis Forum/Blog
absents, Body `/accueil` vide ou modifié, display Blog requis absent, module
partiellement activé, bloc sans module, copie de rollback absente ou divergente,
View Blog inconnue, dérive indépendante de `core.extension` et état impliquant
un nombre d'opérations différent du plan exact. Chaque cas a été bloqué avant
la phase d'écriture et a conservé les mêmes empreintes contenu, configuration
et modules.

Le dry-run valide a annoncé exactement cinq opérations dans l'ordre View,
module, bloc, Body `/accueil`, état de rollback. L'apply a exécuté ces cinq
opérations et rien d'autre. Le second dry-run puis le second apply ont annoncé
et exécuté zéro opération, sans nouvelle révision. Le rollback a exécuté les
cinq opérations inverses documentées, a restauré l'identité de révision et le
Body exacts, puis un second rollback a été refusé sans écriture. Des fautes
contrôlées après la View, après l'activation du module et après le Body ont
toutes produit un échec non nul et une restauration atomique complète, sans
résidu de bloc, module, état ou révision.

Le helper Forum/Blog a ensuite reconnu explicitement la variante active à trois
displays et conservé les quatorze objets Forum/Blog en `MATCH`. Ses displays
`default` et `block_1`, `/blog`, `/forum` et le contenu « Artistes et
partenaires » issu de #87 sont restés inchangés.

### Articles, thèmes et filtre

La matrice jetable a couvert zéro, un et plusieurs Articles, quinze Articles
éligibles sur dix-sept, Articles non publiés ou inaccessibles anonymement,
absence de thème, un ou plusieurs vrais termes, multitag et tags partagés,
terme réel sans résultat, résumés absent et explicite, titres et libellés longs,
Body vide, titres identiques, plus de dix résultats et seconde page. Elle a
confirmé : publication et accès anonyme seulement, `created DESC`, dix résultats
par page, aucune duplication multitag, aucun auteur/résumé/image inventé et des
liens obtenus par le canonical Drupal réel.

Le filtre a couvert l'absence de `theme`, un identifiant valide, inconnu,
malformé, nul, négatif ou répété, une clé indépendante, `theme` avec `page` et
un changement de thème depuis une page ultérieure. Les choix ne contiennent que
les termes réels utilisés par des Articles éligibles. Un filtre valide est
conservé dans le pager ; un changement le ramène à la première page. Tout état
filtré ou invalide reçoit `noindex,follow`, le canonical reste `/accueil`, et
une entrée invalide ne retombe jamais sur tous les Articles. Aucun contrôle,
paramètre, vocabulaire ou chemin Archives/mois/année n'existe.

Les URLs canoniques Article observées étaient numériques (`/fr/node/<nid>`),
car aucun pattern Article de la PR Pathauto menée séparément n'était présent.
C'est un prérequis SEO distinct : cette feature ne fabrique et ne modifie aucun
alias.

### Présentation et navigateur

Chromium a couvert les états zéro, un, long, paginé, filtré et invalide à
1440 px, tablette 820 px, mobiles 390 et 320 px, ainsi que les reflows effectifs
150 % et 200 %, souris, clavier, émulation tactile, couleurs forcées, mouvement
réduit, caches froid/chaud et agrégation CSS/JS activée puis désactivée. Les
états zéro et un ont été rejoués à 1440 px avec JavaScript et à 320 px sans
JavaScript. Ces pages ont conservé un H1, un `main`, un chemin de messages, zéro
identifiant dupliqué, zéro débordement horizontal et zéro scroll imbriqué
bloquant. Les deux `details` natifs sont utilisables au clavier et au toucher ;
sur mobile ils précèdent la liste.

Les styles calculés confirment une liste verticale plate, aucun fond ni ombre
de carte, un seul filet d'accent de 2 px, un titre le plus récent environ 11 %
plus grand et un rail secondaire de 15 rem. Aucun hero promotionnel, grille de
grandes cartes, carrousel, masonry, dashboard, image obligatoire ou contrôle
Archives n'est rendu. Le cycle BGFX reste celui de 44 secondes issu de la base
et son état ne dépend pas du scroll. `/blog`, `/forum`, « Artistes et
partenaires », les routes login/compte et leurs messages ont répondu sans 5xx,
warning PHP ni erreur navigateur attribuable à cette feature.

Toutes les fixtures marquées ont été supprimées et le rollback a laissé zéro
Article, terme, module, bloc, display ou état appartenant au test. Le snapshot
initial, les fichiers publics, le thème et la front page sont restaurés à la fin
de la fenêtre, puis DDEV est arrêté.
