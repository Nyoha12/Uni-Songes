# Architecture de contenu 2026

Ce document décrit la préparation de l'architecture de contenu Uni-Songes 2026
pour l'accueil, l'association, les cours, les stages, les artistes partenaires
et les prestations artistiques. La mise en place est portée par
`drupal/scripts/apply-content-architecture-2026.sh`.

## Carte des pages

| Page | Alias | Rôle |
| --- | --- | --- |
| Accueil | `/accueil` | Introduction courte et orientation vers Cours, Stages, Concerts, Artistes, Prestations et Association, avec CTA principal « Réserver un cours » vers `/reservation-cours`. |
| Cours | `/cours` | Hub avec trois cartes de discipline et un CTA principal « Réserver un cours » vers `/reservation-cours`. |
| Cours de didgeridoo | `/cours/didgeridoo` | Page détaillée avec cours d'essai à 10 EUR, cours à 25 EUR / heure ou 15 EUR / heure étudiant, CTA principal « Réserver un cours de didgeridoo » et CTA essai séparé vers le tunnel. |
| Cours de guimbarde | `/cours/guimbarde` | Page dédiée avec tarifs confirmés 25 EUR / heure et 15 EUR / heure étudiant, puis réservation guimbarde dans le tunnel. |
| Méditation / improvisation | `/cours/meditation-improvisation` | Page dédiée avec tarifs confirmés 25 EUR / heure et 15 EUR / heure étudiant, puis réservation méditation / improvisation dans le tunnel. |
| Stages | `/stages` | Hub des stages avec trois entrées : didgeridoo, musique improvisée / méditation, stages spéciaux, et routage vers les pages Stage publiées. |
| Stages didgeridoo | `/stages/didgeridoo` | Page des stages mensuels débutant et intermédiaire, tarif 20 EUR, réservation via les pages Stage publiées. |
| Musique improvisée / méditation | `/stages/musique-improvisee-meditation` | Page des stages musique improvisée / méditation, tarif 20 EUR, réservation via les pages Stage publiées. |
| Stages spéciaux | `/stages/speciaux` | Page des stages gong, guimbarde, éveil musical, etc., publiés via le système existant de pages Stage et billets. |
| L’Association | `/association` | Mission et activités musicales, pédagogiques et collectives, avec orientation vers les cours, stages, concerts, artistes, prestations et pages dédiées de D’Jam et de l'Orchestre des Rêveurs. |
| Les Artistes de l'asso | `/les-artistes-de-l-asso` | Page de présentation des artistes partenaires, avec sections à compléter. |
| Services et prestations artistiques | `/services-prestations-artistiques` | Page des services artistiques, pédagogiques et sonores avec CTA contact. |

## Conventions éditoriales 2026

- L'accueil reste bref : une introduction, un CTA principal vers le tunnel de
  réservation des cours et six cartes d'orientation. Il ne répète pas les
  textes commerciaux détaillés des pages de destination.
- La page Association décrit la mission et les activités sans ajouter de faits
  juridiques, de noms d'équipe, de dates ni de statistiques. D’Jam et
  l'Orchestre des Rêveurs y sont situés comme projets de l'association, tandis
  que leurs pages dédiées restent les sources de détail.
- Ces deux corps complètent le shell existant sans modifier le thème, les
  templates, le CSS, la configuration, les routes publiques ni la définition
  canonique du menu. Le script conserve sa réconciliation historique des liens
  de menu déjà documentés ci-dessous.
- Les pages de cours particuliers ne structurent plus l'offre avec un cadrage
  générique débutant / intermédiaire / avancé. Elles décrivent plutôt ce que le
  cours permet de travailler et renvoient vers le tunnel de réservation.
- Le didgeridoo conserve un cours d'essai à 10 EUR avec un CTA séparé. Les CTA
  d'achat direct et les CTA contact concurrents sont retirés des pages Cours.
  Les produits Commerce existants restent hors du périmètre de ce script.
- Les tarifs sont formulés de façon homogène : `25 EUR / heure`, `15 EUR /
  heure étudiant`, `20 EUR par stage`.
- Les stages didgeridoo gardent deux repères collectifs mensuels, débutant et
  intermédiaire, sans développer longuement le fonctionnement mensuel.
- Les stages spéciaux ne créent pas d'offre générique : le format, le tarif et
  les billets restent portés par chaque page Stage publiée.
- Le corps des pages Drupal est la source de vérité pour les contenus Accueil,
  Association, Cours et Stages 2026. Les templates de thème ne doivent pas
  injecter de sections éditoriales hardcodées qui réintroduisent l'ancienne
  structure.

## Parcours de réservation des cours

Le parcours public est : `/cours` → page de discipline ou CTA direct →
`/reservation-cours` → choisir la discipline → choisir le créneau → choisir le
paiement → confirmation.

| Choix | Destination |
| --- | --- |
| CTA général de `/cours` | `/reservation-cours` |
| Cours d'essai | `/reservation-cours?discipline=essai` |
| Didgeridoo | `/reservation-cours?discipline=didgeridoo` |
| Guimbarde | `/reservation-cours?discipline=guimbarde` |
| Méditation / improvisation | `/reservation-cours?discipline=meditation-improvisation` |

Cette évolution de contenu consomme le contrat du tunnel sans en modifier
l'implémentation ni la route.

## Menu principal

Les titres de pages restent ceux de la carte ci-dessus. Le menu principal
utilise des libellés plus courts pour éviter la surcharge visuelle.

L'ordre canonique est le suivant :

| Poids | Libellé | Destination |
| ---: | --- | --- |
| 0 | Cours | `/cours` |
| 10 | Stages | `/stages` |
| 20 | Concerts | `/concerts` |
| 30 | Association | `/association` |
| 40 | Artistes | `/les-artistes-de-l-asso` |
| 50 | Prestations | `/services-prestations-artistiques` |
| 60 | D’Jam | `/djam` |
| 70 | Orchestre | `/orchestre-des-reveurs` |
| 80 | Contact | `/contact` |

Le script retrouve ces liens par destination, impose leur libellé et leur poids
et les maintient au premier niveau. Il crée uniquement une destination absente,
refuse les correspondances ambiguës pour ne pas créer de doublon, ne supprime
aucun lien et préserve tous les liens non concernés.

## Contenu créé par le script

Le script crée ou met à jour les douze nœuds Drupal de type `page` listés dans la
carte des pages, leurs alias et les liens de l'ordre canonique ci-dessus. Les
corps de page utilisent les classes CSS contractuelles suivantes pour la PR CSS
parallèle :

- `unisonges-page-intro`
- `unisonges-card-grid`
- `unisonges-offer-card`
- `unisonges-offer-card__title`
- `unisonges-offer-card__text`
- `unisonges-offer-card__meta`
- `unisonges-offer-card__cta`
- `unisonges-detail-section`
- `unisonges-price-note`

Le script ne crée, ne modifie ni ne supprime de produit Commerce, ne crée pas de
termes de taxonomie, ne lance pas `drush config:import`, ne modifie pas
`config/sync` et ne supprime aucun contenu.

Pour `/accueil` et `/association`, l'alias est l'identifiant de résolution : si
un alias existe déjà, le script met à jour le nœud `page` qu'il cible et conserve
donc son identifiant. Si l'alias est absent, le script prévoit un nouveau nœud au
lieu d'adopter une autre page portant seulement le même titre. Les dix autres
pages conservent leur stratégie historique, alias prioritaire puis titre unique.
Si un même alias pointe vers plusieurs chemins, le préflight bloque l'exécution
au lieu de sélectionner arbitrairement un nœud.

En dry-run, chaque corps qui différerait est affiché intégralement dans un bloc
`BODY_CHANGE_EXACT`, avec le format de texte, le nombre d'octets et le SHA-256
des valeurs actuelle et prévue. Une création affiche le corps prévu et marque la
valeur actuelle comme absente. Cette sortie permet la revue exacte avant toute
application ; le mode dry-run n'écrit ni contenu, ni alias, ni menu.

## Décisions de contenu confirmées

- Cours d'essai : 10 EUR, réservation via
  `/reservation-cours?discipline=essai`.
- Cours de didgeridoo : 25 EUR / heure, 15 EUR / heure étudiant, réservation
  via `/reservation-cours?discipline=didgeridoo`.
- Cours de guimbarde : 25 EUR / heure, 15 EUR / heure étudiant, réservation via
  `/reservation-cours?discipline=guimbarde`.
- Méditation / improvisation : 25 EUR / heure, 15 EUR / heure étudiant,
  réservation via `/reservation-cours?discipline=meditation-improvisation`.
- Les pages produit existantes ne sont pas supprimées, mais ne sont plus les
  CTA d'achat des pages publiques Cours.
- Stages didgeridoo : 20 EUR, avec réservation sur les pages Stage publiées.
- Stages musique improvisée / méditation : 20 EUR, avec réservation sur les
  pages Stage publiées.
- Stages spéciaux : gong, guimbarde, éveil musical, etc., via le système
  existant de publication de stages et de billets.

## Raffinement éditorial et visuel

Le contenu des pages `/cours`, `/cours/didgeridoo`, `/cours/guimbarde`,
`/cours/meditation-improvisation`, `/stages`, `/stages/didgeridoo`,
`/stages/musique-improvisee-meditation` et `/stages/speciaux` a été resserré
pour :

- expliquer rapidement ce que propose chaque page ;
- afficher les prix confirmés avec les mêmes conventions ;
- éviter les textes trop génériques ou trop longs ;
- orienter les pages Cours vers le tunnel de réservation ;
- conserver le système existant de publication Stage et de billetterie.

Le CSS associé reste limité aux classes `unisonges-page-intro`,
`unisonges-card-grid`, `unisonges-offer-card`, `unisonges-detail-section` et
`unisonges-price-note`. Les ajustements visuels portent sur la lisibilité des
textes, la hiérarchie des titres, la mise en avant des prix, l'alignement des
CTA et la cohérence des panneaux de contenu.

## Checklist visuelle manuelle

- Vérifier `/accueil` : l'introduction reste courte, les six cartes mènent aux
  bonnes pages et le CTA principal mène à `/reservation-cours`.
- Vérifier `/association` : mission et activités restent concises, les cinq
  destinations demandées sont présentes et D’Jam comme l'Orchestre renvoient à
  leurs pages dédiées.
- Vérifier `/cours` : les trois cartes restent visibles, le CTA principal
  « Réserver un cours » mène à `/reservation-cours` et les cartes mènent aux
  pages de discipline.
- Vérifier `/cours/didgeridoo` : les tarifs 25 EUR / 15 EUR étudiant restent
  visibles, le CTA principal mène au deep-link didgeridoo et le CTA essai séparé
  mène au deep-link essai.
- Vérifier `/cours/guimbarde` et `/cours/meditation-improvisation` : les tarifs
  25 EUR / 15 EUR étudiant restent visibles et chaque CTA mène au bon deep-link.
- Vérifier qu'aucun CTA d'achat produit direct ne concurrence le tunnel sur les
  quatre pages Cours.
- Vérifier `/stages` : les trois familles de stages sont lisibles et la zone de
  publication automatique des contenus Stage reste présente.
- Vérifier `/stages/didgeridoo` : les repères débutant et intermédiaire
  mensuels sont mentionnés sans surcharge de texte.
- Vérifier `/stages/musique-improvisee-meditation` et `/stages/speciaux` : la
  réservation passe par les dates Stage publiées ou le contact, sans produit
  Commerce générique.
- Tester desktop, tablette et mobile : pas de chevauchement de texte, CTA
  tappables, titres et prix lisibles sur le fond Uni-Songes.

## Contenu manuel restant

- Publier les dates réelles des stages comme contenus `Stage`.
- Relier les prochaines dates depuis les pages de catégories si l'équipe veut
  des liens explicites en plus de la liste automatique.
- Compléter les biographies, photos, liens et prestations des artistes.
- Finaliser les textes commerciaux et les contraintes techniques des prestations.

## Préservation des hubs existants

La page `/stages` reste le hub de stages. Le script met à jour le corps du nœud
de page, mais ne modifie pas le bloc Views existant qui publie
automatiquement les contenus `Stage` sur `/stages`.

La page `/concerts` et son comportement existant ne sont pas touchés.

## Exécution locale

Dry-run local, sans écriture :

```bash
cd ~/Uni-Songes/repo/drupal
./scripts/apply-content-architecture-2026.sh --dry-run
```

Application locale :

```bash
cd ~/Uni-Songes/repo/drupal
./scripts/apply-content-architecture-2026.sh --apply
```

### Dry-run actif Codespaces du 30 août 2026

La syntaxe Bash du script a été validée. Deux invocations préliminaires se sont
arrêtées avant l'inspection du contenu Drupal, sans écriture : l'exécution
directe depuis l'hôte utilisait PHP 8.2.33 alors que le projet requiert PHP 8.3,
puis l'exécution DDEV sans dérogation de chemin a rencontré la garde
`/var/www`. Le dry-run actif a donc été exécuté dans le projet DDEV local avec :

```bash
ddev exec ./scripts/apply-content-architecture-2026.sh --dry-run --allow-vps
```

Dans cette commande locale, `--allow-vps` autorise uniquement le chemin interne
DDEV `/var/www/html` ; aucun VPS n'a été contacté. Drupal 11.3.3 a démarré et le
dry-run s'est terminé avec le statut 0, sans `--apply`.

La base Codespaces ne contient aucune des cibles attendues. La sortie complète
propose exactement :

- 12 `WOULD_CREATE page`, pour `/accueil`, `/cours`, les trois pages Cours, le
  hub `/stages`, les trois pages Stages, `/association`,
  `/les-artistes-de-l-asso` et `/services-prestations-artistiques` ;
- 12 blocs `BODY_CHANGE_EXACT` avec `node=NEW`, `CURRENT_FORMAT <absent>` et
  `CURRENT_BODY <absent>` ;
- 12 `WOULD_CREATE alias` ;
- 9 `WOULD_CREATE main menu link` ;
- aucun `WOULD_UPDATE page`, aucun `OK page /...`, aucun `OK alias`, aucun lien
  de menu `OK` ou `WOULD_UPDATE`, et aucun `FAIL`.

Les lignes `OK inspected page target` du préflight signifient seulement que la
résolution n'a pas levé d'exception ; elles ne prouvent pas qu'un nœud existe.
La sortie se termine par `Dry-run completed. No content, menu links, aliases,
config, or Commerce data was changed.` Aucun marqueur d'écriture réel
`CREATED`, `UPDATED` ou `DELETED` n'est présent.

Ce snapshot local vide pour ce périmètre ne reproduit pas le contenu actif de
production. Il ne permet donc de confirmer ni la conservation des nœuds
existants `/accueil` et `/association`, ni un delta limité à leurs deux corps.
La PR reste en brouillon jusqu'à un dry-run actif représentatif et revu.

## Exécution VPS

Le script refuse les chemins `/var/www` sauf si `--allow-vps` est passé
explicitement.

### Validation active ultérieure en lecture seule

Ce contrôle doit partir d'un checkout VPS déjà revu et contenant exactement le
commit approuvé de la PR. Il ne doit pas servir à basculer ni mettre à jour le
checkout déployé. Si le SHA attendu n'est pas déjà présent, préparer séparément
un checkout autorisé ou différer le contrôle.

```bash
cd /var/www/<site>
git status --short --branch --untracked-files=no
git diff --quiet
git diff --cached --quiet
git rev-parse HEAD

cd drupal
bash -n scripts/apply-content-architecture-2026.sh
./scripts/apply-content-architecture-2026.sh --dry-run --allow-vps
```

La revue doit vérifier dans la sortie complète que `/accueil` et `/association`
sont les deux seules lignes `WOULD_UPDATE page`, avec `body` comme seul
changement, que les dix autres pages et les douze alias sont `OK`, et que les
neuf liens de menu canoniques sont `OK` avec leur poids attendu et au premier
niveau. Aucun `WOULD_CREATE`, autre `WOULD_UPDATE`, `FAIL`, `CREATED`, `UPDATED`
ou `DELETED` ne doit apparaître. Ne lancer ni `--apply`, ni import de
configuration, ni reconstruction de cache. Toute divergence maintient la PR en
brouillon et exige une revue avant une opération d'écriture distinctement
autorisée.

Dry-run VPS :

```bash
cd /var/www/<site>/drupal
./scripts/apply-content-architecture-2026.sh --dry-run --allow-vps
```

Application VPS :

```bash
cd /var/www/<site>/drupal
./scripts/apply-content-architecture-2026.sh --apply --allow-vps
```

Avant toute application VPS, vérifier le dry-run, le chemin courant, la branche
déployée et la sauvegarde de base de données. Ne pas lancer d'import de
configuration pour cette opération.
