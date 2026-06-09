# Architecture de contenu 2026

Ce document décrit la préparation de l'architecture de contenu Uni-Songes 2026
pour les cours, les stages, les artistes partenaires et les prestations
artistiques. La mise en place est portée par
`drupal/scripts/apply-content-architecture-2026.sh`.

## Carte des pages

| Page | Alias | Rôle |
| --- | --- | --- |
| Cours | `/cours` | Hub des cours particuliers avec trois entrées : didgeridoo, guimbarde, méditation / improvisation. |
| Cours de didgeridoo | `/cours/didgeridoo` | Page détaillée du cours de didgeridoo, tous niveaux, avec tarifs confirmés. |
| Cours de guimbarde | `/cours/guimbarde` | Page dédiée guimbarde, sans tarif affirmé tant qu'il n'est pas confirmé. |
| Méditation / improvisation | `/cours/meditation-improvisation` | Page dédiée à l'accompagnement individuel autour de l'écoute, de la présence et de l'improvisation. |
| Stages | `/stages` | Hub des stages avec trois entrées : didgeridoo, musique improvisée / méditation, stages spéciaux. |
| Stages didgeridoo | `/stages/didgeridoo` | Page des stages mensuels débutant et intermédiaire, tarif 20 EUR. |
| Musique improvisée / méditation | `/stages/musique-improvisee-meditation` | Page des stages musique improvisée / méditation, tarif 20 EUR. |
| Stages spéciaux | `/stages/speciaux` | Page des stages gong, guimbarde, éveil musical, etc. |
| Les Artistes de l'asso | `/les-artistes-de-l-asso` | Page de présentation des artistes partenaires, avec sections à compléter. |
| Services et prestations artistiques | `/services-prestations-artistiques` | Page des services artistiques, pédagogiques et sonores avec CTA contact. |

## Menu principal

Le script crée ou met à jour uniquement ces liens du menu principal :

- `Les Artistes de l’asso` -> `/les-artistes-de-l-asso`
- `Services et prestations artistiques` -> `/services-prestations-artistiques`

Il ne réordonne pas le menu principal et ne supprime aucun lien existant.

## Contenu créé par le script

Le script crée ou met à jour les dix nœuds Drupal de type `page` listés dans la
carte des pages, leurs alias et les deux liens de menu ci-dessus. Les corps de
page utilisent les classes CSS contractuelles suivantes pour la PR CSS
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

Le script ne crée pas de produits Commerce, ne crée pas de termes de taxonomie,
ne lance pas `drush config:import`, ne modifie pas `config/sync` et ne supprime
aucun contenu.

## Contenu manuel restant

- Publier les dates réelles des stages comme contenus `Stage`.
- Relier les prochaines dates depuis les pages de catégories si l'équipe veut
  des liens explicites en plus de la liste automatique.
- Créer ou ajuster les produits Commerce si des ventes en ligne sont souhaitées.
- Confirmer les tarifs des cours de guimbarde et méditation / improvisation.
- Compléter les biographies, photos, liens et prestations des artistes.
- Finaliser les textes commerciaux et les contraintes techniques des prestations.
- Ajuster les poids du menu manuellement si un ordre précis est voulu.

## Préservation des hubs existants

La page `/stages` reste le hub de stages. Le script met à jour le corps du nœud
de page, mais ne modifie pas le bloc Views existant qui publie
automatiquement les contenus `Stage` sur `/stages`.

La page `/concerts` et son comportement existant ne sont pas touchés.

## Exécution locale

Dry-run local, sans écriture :

```bash
cd ~/Uni-Songes/worktrees/content-architecture-2026/drupal
./scripts/apply-content-architecture-2026.sh --dry-run
```

Application locale :

```bash
cd ~/Uni-Songes/worktrees/content-architecture-2026/drupal
./scripts/apply-content-architecture-2026.sh --apply
```

## Exécution VPS

Le script refuse les chemins `/var/www` sauf si `--allow-vps` est passé
explicitement.

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
