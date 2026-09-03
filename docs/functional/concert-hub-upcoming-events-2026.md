# Hub « Concerts et événements à venir » 2026

Ce document décrit la correction statique et ciblée de la View Drupal
`hub_concerts_posts`, affichée dans le bloc public limité à `/concerts`.
Elle ne crée aucune URL, ne modifie aucun nœud Concert et ne touche ni au hub
Stages, ni aux templates, ni au CSS, ni au sitemap.

La portée versionnée est limitée à :

- `drupal/config/sync/views.view.hub_concerts_posts.yml` ;
- `drupal/config/sync/block.block.unisonges_hub_concerts_posts.yml` ;
- `drupal/scripts/apply-concert-hub-upcoming-events-2026.sh` ;
- `docs/functional/concert-hub-upcoming-events-2026.md`.

## Audit préalable

Avant cette correction, la View :

- filtrait seulement les nœuds publiés (`status = 1`) et le bundle
  `concert` ;
- triait `node_field_data.created` en ordre décroissant avec le plugin
  `date` ;
- ne joignait pas `field_event_dates` ;
- n'avait ni filtre sur la fin de l'événement, ni état vide explicite, ni
  stratégie de cache adaptée à une borne temporelle mobile ;
- conservait le pager `mini`, 10 éléments par page, le rendu de ligne
  `entity:node` en mode `teaser`, l'accès `access content` et les contextes de
  cache liés aux node grants et aux permissions.

Le champ `node.concert.field_event_dates` est obligatoire. Son stockage
`node.field_event_dates` est un `daterange`, de cardinalité un, avec
`datetime_type: datetime`. Les colonnes Views confirmées sont donc :

- début : `node__field_event_dates.field_event_dates_value` ;
- fin : `node__field_event_dates.field_event_dates_end_value`.

Le handler Views de ces deux valeurs appartient au module `datetime`. La
dépendance directe correcte de la View est
`field.storage.node.field_event_dates`; `datetime_range` reste une dépendance
indirecte du stockage et ne doit pas être ajoutée artificiellement à la View.

Les affichages Concert complet et teaser rendent déjà le daterange avec le
formatter `daterange_default`, sans surcharge de fuseau. Le teaser, le pager et
les champs affichés sont conservés sans changement.

Le bloc existant garde son plugin
`views_block:hub_concerts_posts-block_1`, sa région, son thème et son unique
visibilité `request_path`, positive et exactement limitée à `/concerts`. Son
ancien libellé était « Nouveaux évènements : ». Le libellé cible est :

```text
Concerts et événements à venir
```

La copie statique actuelle de `/concerts` reste elle aussi intacte : le
template du nœud 6 fournit l'introduction sur les concerts et interventions,
les cartes Contact et D’Jam, puis le corps éditorial lorsqu'il existe. Cette PR
ne réécrit ni cette copie ni le contenu actif.

## Base rebasée et responsabilités d'intégration

Le 2 septembre 2026, la branche de la PR #89 a été rebasée sur le dernier
`origin/release/prod`, au commit `5b8e80c2e2ac266978ba2be0b8eee2c56a04605f`.
Cette base contient notamment les prérequis et intégrations déjà fusionnés :

- PR #71, modèle Stage validé pour les événements à venir ;
- PR #81, structure publique accessible et placement initial des messages,
  qui a terminé sa matrice et libéré les ressources runtime ;
- PR #100, chemin unique et cycle de vie des messages système ;
- PR #99, présentation authentification/compte strictement limitée aux routes
  utilisateur concernées.

Selon la coordination de travail actuelle, PR #98 est désormais l'unique
propriétaire des ressources runtime. La PR #89 reste donc statique et en
brouillon : elle n'utilise ni DDEV, ni Docker, ni Drush, ni Chromium, ni
Playwright, ni Mailpit, et n'accède pas au VPS.

### Combinaison prévue avec la PR #92

La PR #92 possède la présentation statique des hubs publics. Pour `/concerts`,
elle modifie `node--6.html.twig` et des composants Twig partagés ; elle ne
modifie ni la View Concert ni son bloc. L'intersection entre ses six fichiers
et les quatre fichiers de la PR #89 est vide.

Après intégration des deux PR, la couche #92 rendra l'introduction, les cartes
et les actions statiques du nœud `/concerts`. Drupal composera séparément le
bloc dynamique #89, toujours limité à cette route, avec son libellé, son état
vide et les Concerts éligibles. Le contenu principal de poids `-3` précédera le
bloc Concert de poids `50`. Aucun template Twig ou configuration Views ne
change de propriétaire.

### Intégrations #99 et #100

Le scope de la PR #99 est calculé uniquement pour les routes connexion,
inscription, mot de passe et compte du propriétaire. Sa bibliothèque CSS n'est
attachée que dans ce scope, et ses classes dédiées ne sont ajoutées que sur ces
routes. `/concerts` n'appartient pas à ce scope. La PR #89 ne modifie aucun
fichier d'authentification, de compte ou de style.

La PR #100 conserve, pour le thème public actif `unisonges_theme`, un seul bloc
actif `system_messages_block` et, lorsqu'un message est rendu, au plus une
destination `[data-drupal-messages]` dans le contenu principal. L'état vide de
la View Concert est une zone `text_custom`, pas un message Drupal. La PR #89
n'ajoute ni renderer de messages, ni destination, ni appel au messenger ; tout
message système éventuellement émis reste rendu par ce chemin unique. Aucun
fichier de bloc ou template de messages n'est modifié.

### Séparation avec la PR #103

La PR #103 possède l'accueil éditorial, l'extension de
`views.view.blog_posts` et les adaptations de cycle de vie Forum/Blog
nécessaires. Ses Articles restent filtrés par le bundle `article`, triés par
`created DESC` et, sur `/accueil`, éventuellement sélectionnés par les
paramètres `theme` et `page`.

La PR #89 ne modifie pas `views.view.blog_posts`, ne réutilise aucun filtre de
l'accueil éditorial et reste sur le bundle `concert`, le champ
`field_event_dates` et la route `/concerts`. Elle n'expose aucun paramètre
`theme` ou `date` : `now` est une valeur relative interne au filtre Views. Le
pager `page` commun à Drupal reste séparé par route. Il n'existe donc ni
collision de thème, ni collision de date, ni intersection de fichiers avec la
PR #103.

## Contrat fonctionnel cible

La View corrigée :

- conserve uniquement les nœuds publiés du bundle `concert` ;
- compare la fin complète avec l'instant courant au moyen de
  `field_event_dates_end_value >= now` ;
- utilise le plugin `datetime` et la valeur relative `type: offset`, sans date
  de calendrier calculée ou figée dans le YAML ;
- conserve ainsi les concerts en cours et futurs, et exclut seulement ceux
  dont la fin est strictement passée ;
- trie d'abord `field_event_dates_value ASC` ;
- départage deux débuts identiques par `node_field_data.nid ASC`, afin de
  stabiliser l'ordre et l'appartenance aux pages ;
- ne contient plus aucun tri sur la date de création du nœud ;
- affiche « Aucun concert ou événement à venir pour le moment. » lorsqu'aucun
  résultat n'est éligible ;
- désactive le cache de résultat Views (`cache.type: none`) et fixe la
  cacheabilité des displays `default` et `block_1` à `max-age: 0` ;
- conserve le pager, le teaser, la permission `access content`, le query
  plugin `views_query` et les contextes `user.node_grants:view` et
  `user.permissions`.

Une fin exactement égale à l'instant évalué satisfait l'opérateur inclusif.
Pour une plage déjà commencée, la fin décide de l'éligibilité et le début
décide de la position. Les événements en cours précèdent donc les événements
futurs lorsque leur début est antérieur.

Le filtre ne dépublie et ne supprime rien. Un ancien Concert publié reste une
page canonique accessible par son URL ; il disparaît seulement de ce bloc
« à venir » lorsque sa borne de fin est passée.

## Cache et borne mobile

`now` change sans invalidation par cache tag. Une entrée permanente pourrait
donc conserver un concert terminé dans le hub. Les deux protections sont
nécessaires :

1. aucun cache de résultat Views (`type: none`) ;
2. `cache_metadata.max-age: 0` sur le display par défaut et le bloc.

Les contextes d'accès existants restent présents. Cette décision privilégie
la justesse de la liste temporelle ; elle ne modifie pas la cacheabilité des
pages canoniques Concert elles-mêmes.

## UTC, Europe/Paris et changements d'heure

Les valeurs `datetime` Drupal sont stockées comme instants UTC. La valeur
relative `now`, évaluée au moment de la requête par le handler Views, est
normalisée pour la comparaison avec ces valeurs de stockage. Le projet
versionne `system.date:timezone.default` à `Europe/Paris` et le script ciblé
exige que cette valeur active soit déjà exacte ; il la lit mais ne l'écrit
jamais.

Le formatter teaser n'impose aucun fuseau, donc l'affichage public anonyme
utilise le défaut du site. Les visiteurs authentifiés peuvent avoir un fuseau
personnel pour l'affichage, sans changer l'instant absolu qui décide de
l'éligibilité.

Les règles IANA de `Europe/Paris` appliquent automatiquement CET/CEST : il ne
faut jamais supposer un offset fixe. Les fixtures runtime devront fournir des
instants ou offsets explicites autour :

- du saut de printemps, où certaines heures murales n'existent pas ;
- du repli d'automne, où la même heure murale existe deux fois avec deux
  offsets distincts.

Le format teaser `medium` existant peut afficher ces deux instants d'automne de
façon visuellement identique, mais leur filtrage et leur ordre restent fondés
sur leurs valeurs UTC distinctes. Aucun changement de formatter n'entre dans
le périmètre de cette PR.

## Application ciblée et fermée

Le script autorise exactement deux noms de configuration :

```text
views.view.hub_concerts_posts
block.block.unisonges_hub_concerts_posts
```

Il ne lance jamais `config:import`, avec ou sans `--partial`, n'utilise pas de
SQL brut et n'écrit aucune entité de contenu. Il vérifie les chemins
canoniques, les deux noms, les UUID, les plugins, les tables, les filtres, les
tris, l'état vide, le cache, les dépendances, la route et les empreintes des
deux sources revues.

Les empreintes SHA-256 de fichier verrouillées par le script sont :

- View cible :
  `66acd317b7885941e0ccfa4d0ff00547f0eef84506a1ee155060567ec4368f58` ;
- bloc cible :
  `52cf78f2b1c3aa7e0c6f61d0cc89b25b68cd71a19afbfee6264c34f8f6e039ce`.

Après parsing et canonicalisation des mappings, les états complets sont aussi
verrouillés. La canonicalisation conserve explicitement l'ordre de la map
Views `sorts`, car le premier handler est le tri primaire :

| Configuration | État précédent | État cible |
| --- | --- | --- |
| View | `204e859d00c69cd76b70372437da3ae3bdfffac3dfab85102b6d9d2a9a71672b` | `ac962dc6cc6859215640251b100903b423a4993823783e0f0961dcd35d3b3e5c` |
| Bloc | `9819379da6b600eedbf794b661635fdea8be021d54db8024703489221691dd8b` | `2f994d214933e1393f0993acf8d9e2120309d04ef4fce5425ce17392209b0c09` |

À partir de ces sources verrouillées, le script reconstruit aussi les deux
valeurs complètes précédentes. Avant toute écriture, chaque configuration
active doit correspondre exactement à son état précédent ou à sa cible. Une
valeur inconnue fait échouer toute l'opération avant la première écriture.

Un état mixte composé uniquement de valeurs connues peut résulter d'une
interruption entre les deux sauvegardes. Il est signalé, puis la direction
explicitement choisie écrit seulement la valeur restante. Une seconde
application dans la même direction est un `NOOP`.

Le dry-run crée uniquement le fichier PHP temporaire nécessaire à
`php:script`, supprimé à la sortie ; il ne réalise aucune écriture Drupal,
configuration ou contenu.

## Séquence de staging

Préparer une sauvegarde de la base, consigner le commit déployé et vérifier que
le checkout de staging est celui relu. Sous un chemin `/var/www`,
`--allow-vps` est un acquittement obligatoire du staging revu ; il n'autorise
jamais une exécution en production.

Depuis le répertoire Drupal du staging :

```bash
./scripts/apply-concert-hub-upcoming-events-2026.sh --dry-run --allow-vps
./scripts/apply-concert-hub-upcoming-events-2026.sh --apply --allow-vps
./scripts/apply-concert-hub-upcoming-events-2026.sh --dry-run --allow-vps
./scripts/apply-concert-hub-upcoming-events-2026.sh --apply --allow-vps
```

Le premier dry-run doit annoncer seulement les deux noms allowlistés et aucune
dérive inconnue. Le troisième appel doit constater l'état cible exact. Le
quatrième vérifie l'idempotence et doit annoncer `NOOP`, avec zéro écriture.

## Rollback ciblé

Le rollback restaure les deux valeurs actives exactes antérieures :

- View avec dépendances teaser/type seulement, modules `node` et `user`, tri
  `created DESC`, filtres publication/bundle seulement, sans état vide ni
  cache Views explicite, et `max-age: -1` sur les deux displays ;
- bloc avec le libellé exact « Nouveaux évènements : » ; toutes ses autres
  valeurs sont inchangées.

Après relecture du dry-run inverse :

```bash
./scripts/apply-concert-hub-upcoming-events-2026.sh --rollback --dry-run --allow-vps
./scripts/apply-concert-hub-upcoming-events-2026.sh --rollback --apply --allow-vps
./scripts/apply-concert-hub-upcoming-events-2026.sh --rollback --dry-run --allow-vps
```

Le dernier appel doit constater l'état précédent exact. Ne pas lancer
d'import de configuration complet ou partiel pour appliquer ou annuler cette
correction. Aucun rollback de contenu n'est nécessaire.

## Validations statiques

Depuis la racine exacte du worktree :

```bash
git branch --show-current
git diff --check
bash -n drupal/scripts/apply-concert-hub-upcoming-events-2026.sh
shellcheck drupal/scripts/apply-concert-hub-upcoming-events-2026.sh
npx --yes js-yaml@5.4.1 drupal/config/sync/views.view.hub_concerts_posts.yml >/dev/null
npx --yes js-yaml@5.4.1 drupal/config/sync/block.block.unisonges_hub_concerts_posts.yml >/dev/null
awk 'found { if ($0 == "PHP") exit; print } /^<\?php$/ { found=1; print }' \
  drupal/scripts/apply-concert-hub-upcoming-events-2026.sh | php -l
git diff --name-only origin/release/prod...HEAD
```

La commande `awk` transmet uniquement le PHP embarqué à `php -l`, sans créer
de fichier. Le parsing YAML strict et les assertions statiques doivent
notamment vérifier :

- les deux YAML comme mappings valides, leurs UUID uniques et leurs
  dépendances exactes ;
- le filtre `field_event_dates_end_value >= now`, `type: offset` ;
- le tri de début `ASC`, puis `nid ASC`, et l'absence du tri `created` ;
- une inversion `nid`/début produit un état et une empreinte distincts, refusés
  comme dérive inconnue ;
- l'état vide français exact et sa normalisation Unicode NFC ;
- `cache.type: none` et les deux `max-age: 0` ;
- les filtres `status = 1` et bundle `concert` ;
- la route de bloc exactement `/concerts` ;
- l'absence d'intersection de fichiers avec les PR #92 et #103 ;
- l'absence de modification du bloc de messages de la PR #100, de la portée
  auth/compte de la PR #99 et de `views.view.blog_posts` ;
- l'absence des commandes d'import, de SQL et d'écriture de contenu dans le
  script ;
- la liste exacte des quatre fichiers modifiés ;
- l'identité byte-for-byte des quatre artefacts Stage — View, bloc, script et
  documentation — avec `origin/release/prod`.

## Matrice DDEV différée

Cette PR est volontairement statique. PR #81 est fusionnée et a libéré les
ressources qu'elle possédait. Selon la coordination de travail actuelle,
PR #98 est le propriétaire runtime de DDEV, Docker, Drush, Chromium,
Playwright et Mailpit. La PR #89 doit rester en brouillon jusqu'à la libération
de ces outils et la réussite complète de la matrice suivante, dans un DDEV
local identifié et sans `--allow-vps` :

| Scénario | Assertion requise |
| --- | --- |
| Date unique passée | Concert absent du hub. |
| Date unique courante | Concert présent à la borne inclusive. |
| Plage en cours | Concert présent malgré un début passé. |
| Date unique future | Concert présent. |
| Plage terminée | Concert absent. |
| Plage future | Concert présent. |
| Ordre chronologique | Débuts en ordre croissant, indépendamment de `created`. |
| Même début | Ordre `nid ASC`, stable entre rendus et aux bornes du pager. |
| Liste vide | Texte français exact réellement rendu. |
| Fuseau | Défaut actif et rendu anonyme en `Europe/Paris`. |
| DST printemps | Instants autour du saut correctement filtrés et ordonnés. |
| DST automne | Deux occurrences explicites du repli correctement distinguées. |
| Borne mobile | Événement présent avant sa fin puis absent sans purge manuelle. |
| Publication | Publié présent ; non publié absent. |
| Accès anonyme | Permission, node grants et rendu public préservés. |
| Archive canonique | Ancien Concert absent du hub mais page publiée accessible. |
| Dry-run ciblé | Zéro écriture ; deux noms maximum annoncés. |
| Apply ciblé | Seules la View et le libellé du bloc sont écrits. |
| Second dry-run | Égalité active/cible constatée. |
| Second apply | `NOOP`, zéro écriture. |
| Rollback | Deux valeurs précédentes exactes restaurées. |
| Dérive inconnue allowlistée | Inverser les deux tris : `UNKNOWN_DRIFT`, statut non nul et zéro écriture des deux configs. |
| Dérive hors allowlist | Empreinte de toute configuration non allowlistée identique avant/après. |
| Résidus | Zéro nœud fixture et zéro prérequis temporaire résiduel. |

La matrice devra relever les versions Drupal/PHP/base, l'instant de référence,
les offsets DST explicites, les empreintes avant/après, les sorties du script
et les compteurs de contenu. Un échec conserve la PR en brouillon.
