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
`/var/www` nécessite en plus l'acquittement explicite `--allow-vps`. Ce drapeau
autorise seulement le chemin d'un staging validé ; il n'autorise pas une
exécution en production.

## Vérifications statiques

Depuis la racine exacte du worktree :

```bash
cd ~/Uni-Songes/worktrees/filter-upcoming-stage-hub
git branch --show-current
git diff --check
bash -n drupal/scripts/apply-stage-hub-view-2026.sh
python3 - <<'PY'
from pathlib import Path

import yaml

path = Path("drupal/config/sync/views.view.hub_stages_posts.yml")
with path.open(encoding="utf-8") as stream:
    yaml.safe_load(stream)
print(f"YAML OK: {path}")
PY
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
cd ~/Uni-Songes/worktrees/filter-upcoming-stage-hub/drupal
./scripts/apply-stage-hub-view-2026.sh
```

La forme explicite est équivalente :

```bash
./scripts/apply-stage-hub-view-2026.sh --dry-run
```

Ces commandes nécessitent tout de même un Drupal bootstrapable par le Drush du
projet. Elles ne démarrent pas DDEV et n'écrivent aucune configuration.

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

## Matrice DDEV à exécuter ultérieurement

Cette matrice doit être jouée dans une base locale jetable, avec un instant de
référence `T0` explicite en `Europe/Paris`. Pour détecter une régression vers le
tri par création, créer volontairement les fixtures dans un ordre différent de
leurs dates d'événement.

| Cas | Fixture autour de `T0` (`Europe/Paris`) | Résultat attendu |
| --- | --- | --- |
| Passé, date unique | Stage publié, début et fin le même jour, fin `< T0` | Absent. |
| Passé, plage | Stage publié, début plusieurs jours avant et fin juste avant `T0` | Absent. |
| Borne de fin | Stage publié, fin exactement `T0` | Présent à la borne inclusive. |
| En cours, même jour | Stage publié, début `< T0` et fin `> T0` le même jour | Présent. |
| En cours, plage | Stage publié, début la veille et fin le lendemain | Présent et classé selon son début. |
| Futur, date unique proche | Stage publié, début et fin après `T0` le même jour | Présent avant les futurs plus tardifs. |
| Futur, plage éloignée | Stage publié, début après le cas précédent et fin plusieurs jours plus tard | Présent après le futur proche. |
| Futur non publié | Stage non publié avec une date après `T0` | Absent ; le filtre de publication est préservé. |
| Autre bundle | Contenu publié non `stage` daté après `T0` | Absent ; le filtre de bundle est préservé. |
| Aucun résultat | Tous les stages publiés ont une fin `< T0` | Texte « Aucun stage à venir pour le moment. » visible. |

Assertions complémentaires :

- l'ordre affiché est strictement croissant sur la borne de début, y compris
  pour les événements en cours ;
- un titre récemment créé mais plus tardif ne remonte pas devant un événement
  plus proche ;
- les comparaisons restent correctes de part et d'autre d'un changement
  d'heure été/hiver à Paris ;
- le teaser et le pager existants continuent à fonctionner ;
- une page déjà visitée ne conserve pas un stage dont la fin vient de passer,
  sans nécessiter de vidage manuel du cache ;
- le second dry-run après application ne propose aucune écriture ;
- aucune fixture, révision ou valeur de `field_event_dates` n'est modifiée par
  le script d'application.

Après la fin du test parallèle du tunnel, exécuter la matrice depuis ce
worktree DDEV, puis nettoyer les fixtures en restaurant le snapshot local. Ne
pas réutiliser une base de staging ou de production pour ces cas.

## État de validation de la PR

DDEV n'a pas été lancé pendant le test parallèle du tunnel. La PR doit rester
en brouillon tant que la matrice runtime ci-dessus n'a pas été exécutée et
validée. Les vérifications statiques ne remplacent pas ce contrôle Drupal réel,
notamment pour la conversion de fuseau, la borne inclusive et les plages de
dates.
