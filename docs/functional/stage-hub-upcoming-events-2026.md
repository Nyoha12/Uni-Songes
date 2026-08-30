# Hub « Stages à venir » 2026

Ce document décrit la correction ciblée de la View Drupal
`hub_stages_posts`, affichée sur le hub `/stages`. Le changement ne crée aucune
URL, ne modifie aucun contenu `stage` et ne touche ni aux templates ni au CSS.
Il porte uniquement sur
`drupal/config/sync/views.view.hub_stages_posts.yml`, avec une application
active contrôlée par `drupal/scripts/apply-stage-hub-view-2026.sh`.

## Cause du défaut

Avant cette correction, la View conservait seulement deux filtres : nœud
publié (`status = 1`) et bundle `stage`. Elle triait ensuite les résultats par
date de création du nœud, en ordre décroissant. Le libellé « Stages à venir »
n'avait donc aucun lien avec `field_event_dates` : un stage terminé en mars
2026 pouvait encore être présenté comme à venir en août 2026.

`field_event_dates` est un champ Drupal `daterange` obligatoire, de cardinalité
un, dont le type de date est `datetime`. Il fournit une borne de début `value`
et une borne de fin `end_value`. Pour une date-heure, Drupal stocke les valeurs
en UTC ; l'interprétation de la valeur relative `now` par le handler Views doit
être effectuée avec le fuseau actif `Europe/Paris`.

## Contrat fonctionnel

La View corrigée respecte les règles suivantes :

- elle conserve les filtres `status = 1` et bundle `stage` ;
- elle exclut un événement uniquement lorsque sa borne de fin complète est
  strictement antérieure à l'instant courant ;
- elle conserve donc un événement en cours, y compris lorsque son début est
  déjà passé ;
- elle compare `field_event_dates.end_value >= now` avec la sémantique
  `Europe/Paris` ;
- elle trie les résultats par `field_event_dates.value` en ordre croissant,
  indépendamment de la date de création du nœud ;
- elle affiche « Aucun stage à venir pour le moment. » lorsque la View ne
  retourne aucun résultat ;
- elle désactive le cache de résultat Views et fixe la cacheabilité des deux
  displays à `max-age: 0`.

Une fin exactement égale à l'instant courant satisfait encore le filtre
inclusif. Au-delà de cette borne, l'événement disparaît. Pour une plage, c'est
bien la fin complète qui décide de l'éligibilité et le début qui décide de la
position dans la liste.

Le dernier point est nécessaire parce que `now` varie sans invalidation par
tag : un résultat mis en cache sans limite temporelle pourrait conserver un
stage après sa fin. La requête et son rendu sont donc recalculés au chargement
du bloc. Le pager, le rendu `teaser`, le contrôle d'accès et le bloc existants
restent inchangés. Aucune entité Stage ni aucune donnée éditoriale n'est
écrite.

## Portée et garde de configuration

Le seul nom de configuration autorisé est :

```text
views.view.hub_stages_posts
```

Le script refuse une source dont le nom de fichier, l'identifiant de View ou le
nom actif ne correspond pas exactement à cette valeur. Il compare la
configuration active et le YAML versionné avant toute écriture. Son mode par
défaut est non mutateur ; `--apply` est obligatoire pour enregistrer une
différence.

Le préflight lit aussi `system.date:timezone.default` et refuse de continuer si
sa valeur active n'est pas `Europe/Paris`. Cette vérification est strictement
en lecture seule : le script ne corrige ni n'écrit jamais `system.date`. Le
résolveur de fuseau Drupal applique ce défaut aux visiteurs anonymes du hub ;
le handler Views normalise ensuite l'instant absolu `now` pour le comparer aux
dates-heures stockées en UTC.

Le script ne lance jamais `drush config:import`, avec ou sans `--partial`. Il
n'importe aucun autre YAML et n'écrit aucune entité. Tout chemin qui ne peut
pas être rattaché sans ambiguïté au checkout Drupal attendu est refusé. Le
script résout son propre répertoire canoniquement, refuse `/tmp` et `/mnt/c`,
refuse un `config/sync` ou un fichier cible symbolique, et n'accepte que le
fichier exact `config/sync/views.view.hub_stages_posts.yml`. Un chemin sous
`/var/www` nécessite l'acquittement explicite `--allow-vps`, sauf dans le
conteneur web DDEV local identifié positivement. Cette exception exige
simultanément les marqueurs DDEV locaux, le type `drupal11`, le docroot `web`,
le montage `/mnt/ddev_config/config.yaml` et l'égalité canonique entre les
racines DDEV et celle calculée par le script. Dans ce contexte local,
`--allow-vps` est au contraire refusé. Sur un staging validé, ce drapeau
autorise seulement le chemin revu ; il n'autorise jamais une exécution en
production.

## Vérifications statiques

Depuis la racine exacte du worktree :

```bash
cd "$(git rev-parse --show-toplevel)"
git branch --show-current
git diff --check
bash -n drupal/scripts/apply-stage-hub-view-2026.sh
cd drupal
ddev exec --raw -- php -r '
require "vendor/autoload.php";
$path = "config/sync/views.view.hub_stages_posts.yml";
$data = Symfony\Component\Yaml\Yaml::parseFile($path);
if (($data["id"] ?? NULL) !== "hub_stages_posts") {
  throw new RuntimeException("Unexpected staged View id.");
}
echo "YAML OK: $path\n";
'
```

La branche affichée doit être `codex-filter-upcoming-stage-hub`. Vérifier aussi
que le diff reste limité aux trois fichiers autorisés et qu'aucun autre nom
`views.view.*` n'apparaît dans le script :

```bash
git diff --name-only release/prod...HEAD
rg -n 'views\.view\.' drupal/scripts/apply-stage-hub-view-2026.sh
```

## Dry-run dans un checkout hors VPS

Dans un checkout canonique hors `/var/www`, la commande sans option est un
dry-run strictement non mutateur :

```bash
cd "$(git rev-parse --show-toplevel)/drupal"
./scripts/apply-stage-hub-view-2026.sh
```

La forme explicite est équivalente :

```bash
./scripts/apply-stage-hub-view-2026.sh --dry-run
```

Ces commandes nécessitent tout de même un Drupal bootstrapable par le Drush du
projet. Elles ne démarrent pas DDEV et n'écrivent aucune configuration.

Dans le conteneur web DDEV local, utiliser la même commande sans acquittement
VPS :

```bash
cd "$(git rev-parse --show-toplevel)/drupal"
ddev exec ./scripts/apply-stage-hub-view-2026.sh --dry-run
```

Le plan doit annoncer `Execution context: verified local DDEV web container`.
L'ajout de `--allow-vps` dans ce contexte est une erreur et sort avec le code 2.

## Application contrôlée sur staging

Préparer une sauvegarde de la base de staging et noter le commit déployé avant
toute application. Depuis le répertoire Drupal canonique du checkout de
staging validé sous `/var/www`, lancer d'abord le dry-run avec l'acquittement
VPS :

```bash
cd /var/www/<site-staging>/drupal
./scripts/apply-stage-hub-view-2026.sh --dry-run --allow-vps
```

Relire la comparaison active/cible. Elle doit annoncer exactement
`views.view.hub_stages_posts`, les filtres de publication, bundle et fin de
plage, le tri ascendant par début, l'état vide attendu et l'absence de cache
Views. Si une autre configuration, un autre chemin, un autre fuseau ou une
dérive inattendue est signalé, arrêter l'opération.

Après validation du dry-run seulement :

```bash
./scripts/apply-stage-hub-view-2026.sh --apply --allow-vps
./scripts/apply-stage-hub-view-2026.sh --dry-run --allow-vps
```

Le dernier dry-run doit constater l'égalité entre la configuration active et
le YAML versionné. Cette procédure est une écriture ciblée de
`views.view.hub_stages_posts`, pas un import de configuration complet ou
partiel. Elle ne doit pas être exécutée en production dans le cadre de cette
PR.

## Rollback

Le rollback privilégié consiste à remettre en service le commit précédent et
sa version connue de `views.view.hub_stages_posts`, puis à réappliquer
uniquement ce nom de configuration avec la même séquence dry-run, validation,
apply et contrôle final. Ne pas utiliser `drush config:import` ni
`drush config:import --partial` pour revenir en arrière.

Concrètement, préparer et faire relire un commit de rollback qui restaure
uniquement l'ancien YAML tout en conservant le script ciblé, déployer ce commit
sur staging, puis exécuter :

```bash
cd /var/www/<site-staging>/drupal
./scripts/apply-stage-hub-view-2026.sh --dry-run --allow-vps
./scripts/apply-stage-hub-view-2026.sh --apply --allow-vps
./scripts/apply-stage-hub-view-2026.sh --dry-run --allow-vps
```

Le premier dry-run doit montrer la différence inverse et le dernier doit
confirmer l'égalité active/YAML. Ne pas improviser ce rollback directement
dans le checkout déployé.

Si la version précédente ne peut pas être réappliquée de façon ciblée, restaurer
la sauvegarde de staging prise immédiatement avant l'opération, en tenant compte
des éventuelles écritures intervenues depuis. Après rollback, contrôler la
configuration active et le rendu du hub. Aucun rollback de contenu Stage n'est
nécessaire, puisque ce changement ne modifie aucune entité.

## Matrice DDEV exécutée le 30 août 2026

La matrice a été exécutée dans le Codespace local, sans VPS, sans appel externe
et sans import de configuration complet ou partiel. Versions observées : DDEV
1.25.3, Drupal 11.3.3, PHP 8.3.31 et MariaDB 10.11. La branche était rebasée sur
`origin/release/prod` `39cef5f`, qui contient la PR #76. Les sources exactes
testées avaient les SHA-256 de fichier suivants :

- `e865bd45b33630fccef468258d15949fef864d474059f782103aad0a815844e1`
  pour le YAML de la View ;
- `f233f46ef4bfa13417ddab2871cc1f2a58a36c2ff3857314c69d81a44c130d55`
  pour le script d'application gardé.

La base DDEV issue du profil `standard` ne possédait initialement ni bundle
`stage`, ni champ `field_event_dates`, ni View active, et utilisait `Etc/UTC`.
Le harnais local ignoré par Git a donc créé exclusivement ces prérequis par les
API Drupal, activé temporairement `datetime_range`, fixé temporairement
`Europe/Paris` et injecté l'ancienne forme de la View comme cible de départ.
Ces opérations n'étaient pas un `config:import`. Le hook de génération de
tickets, hors surface de cette PR et incomplet dans cette base minimale, a été
retiré de la liste des hooks uniquement en mémoire pendant la transaction puis
restauré ; aucune configuration de module n'a changé.

Les commandes gardées réellement exécutées dans le conteneur local étaient :

```bash
cd "$(git rev-parse --show-toplevel)/drupal"
ddev exec ./scripts/apply-stage-hub-view-2026.sh --dry-run
ddev exec ./scripts/apply-stage-hub-view-2026.sh --apply
ddev exec ./scripts/apply-stage-hub-view-2026.sh --dry-run
```

Le premier dry-run a comparé le SHA-256 canonique actif
`3509713caac42d299f26557674746d5c1c0877e2e455bf88144da03185e46e0f`
à la cible
`3e7961e26a3f4bdad92d2a9ae928df2a945fe58b8c617ee2871e85a3a7224ed8`
et annoncé 12 valeurs différentes sans écriture. `--apply` a annoncé
`Written config count: 1` et uniquement `views.view.hub_stages_posts`. Le
dernier dry-run a obtenu deux empreintes canoniques identiques et `MATCH`, sans
proposer d'écriture. Un `/var/www` dont le marqueur DDEV principal était retiré
a bien été refusé avec le code 1 ; `--allow-vps` dans le DDEV vérifié a été
refusé avec le code 2.

L'instant réel de référence de l'exécution finale était
`T0 = 2026-08-30T09:55:49+02:00` (`Europe/Paris`). Les nœuds ont été créés avec
des dates `created` qui auraient produit l'ordre inverse en tri décroissant,
afin de détecter tout retour au tri historique.

| Assertion runtime | Résultat observé |
| --- | --- |
| Passé, date unique | `PR71-RUNTIME-PAST-SINGLE` absent. |
| En cours, plage | `PR71-RUNTIME-ONGOING-RANGE` présent. |
| Futur, date unique | `PR71-RUNTIME-FUTURE-SINGLE` présent. |
| Passé, plage | `PR71-RUNTIME-PAST-RANGE` absent. |
| Futur, plage | `PR71-RUNTIME-FUTURE-RANGE` présent. |
| Publication préservée | `PR71-RUNTIME-UNPUBLISHED-FUTURE` absent. |
| État vide | Avant les fixtures éligibles, résultat vide, `max-age: 0` et texte « Aucun stage à venir pour le moment. » réellement rendu. |
| Ordre ascendant | En cours → borne mobile → futur unique → future plage → cas DST, selon les valeurs de début UTC stockées. |
| Fuseau | `system.date` et le fuseau PHP effectif étaient exactement `Europe/Paris`. |
| Frontière DST | Plage du `2026-10-25T02:30:00+02:00` au `2026-10-25T02:30:00+01:00` : même heure murale, offsets CEST/CET distincts et une heure absolue, présente et correctement classée. |
| Frontière mobile `now` | Le stage finissant 12 secondes après `T0` était rendu au premier passage puis absent d'une nouvelle exécution et d'un nouveau rendu, sans vidage de cache. |
| Pager et ligne | `mini`, 10 éléments par page, ligne `entity:node` et mode `teaser` inchangés. |

L'ordre initial exact était :

```text
PR71-RUNTIME-ONGOING-RANGE
> PR71-RUNTIME-MOVING-BOUNDARY
> PR71-RUNTIME-FUTURE-SINGLE
> PR71-RUNTIME-FUTURE-RANGE
> PR71-RUNTIME-DST-FOLD
```

Après le passage de la borne mobile, seul
`PR71-RUNTIME-MOVING-BOUNDARY` avait disparu. L'opérateur inclusif `>=` sur
`end_value`, le filtre bundle `stage` et le filtre de publication ont aussi été
réassertés sur la configuration active, identique sémantiquement au YAML
versionné après canonicalisation.

### Rollback, résidus et dérive active

Toutes les créations de contenu et tous les rendus ont été placés dans une
transaction racine explicitement rollbackée. Les compteurs avant/après sont
restés à zéro dans les six tables contrôlées : `node`, `node_field_data`,
`node_revision`, `node_field_revision`, `node__field_event_dates` et
`node_revision__field_event_dates`. Les NID et VID capturés étaient absents de
ces tables après rollback. Une seconde commande Drush, donc un nouveau process
Drupal, a confirmé zéro entité portant le préfixe `PR71-RUNTIME-`.

L'empreinte canonique de toutes les collections de configuration active, hors
View autorisée, est restée identique avant et après le dry-run puis
l'application. Elle est restée identique pendant toute la matrice. Après
suppression ciblée des prérequis locaux, l'empreinte complète est revenue
exactement à sa valeur initiale :

```text
314:89b596879c9a921a49ca134cd80ee989bb7dcb4aa2b3f5dc13748ab319ee8178
```

La vérification finale a confirmé : zéro fixture, aucun prérequis temporaire,
aucune View active résiduelle dans cette base initialement vide et aucune
configuration active sans rapport modifiée.

## État de validation de la PR

La matrice finale passe après correction, mais deux scénarios du script gardé
ont réellement échoué pendant cette validation :

1. l'exécution DDEV initiale classait `/var/www/html` comme un chemin VPS et
   refusait le dry-run local avant bootstrap ;
2. après correction de ce garde, le PHP inclus imprimait un dry-run réussi mais
   `exit(0)` faisait terminer `drush php:script` 13.7 avec le code 1 et
   `Drush command terminated abnormally`.

Le premier défaut est corrigé par la détection DDEV positive et fermée décrite
plus haut. Le second est corrigé par un retour du fichier PHP inclus, qui laisse
Drush terminer normalement. Les deux corrections restent dans le script déjà
autorisé par cette PR. Comme au moins un scénario a échoué pendant la campagne,
la PR #71 reste en brouillon conformément à la consigne, malgré la réussite de
la matrice rejouée.
