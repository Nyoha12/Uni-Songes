# Uni-Songes

L'application publique canonique est le site Drupal disponible sur
<https://unisonges.fr>. Le répertoire `drupal/` porte cette application et ses
procédures de maintenance.

## Surface Cloudflare Pages héritée

Le répertoire `public/` est l'ancien site HTML statique. Il est conservé
temporairement comme surface de compatibilité Cloudflare Pages afin de protéger
les anciens liens entrants pendant son retrait :

- les routes dont l'équivalent Drupal est confirmé redirigent de façon
  permanente vers une URL HTTPS absolue sur `https://unisonges.fr` ;
- les quelques pages sans équivalent approuvé restent disponibles à titre
  transitoire, avec une politique de réponse `X-Robots-Tag` qui interdit leur
  indexation ;
- aucun nouveau produit, tarif, parcours de réservation ou contenu éditorial
  ne doit être développé dans `public/`.

Cloudflare Pages, son origine `https://uni-songes.pages.dev` et ses previews ne
doivent jamais être présentés comme le site canonique. Les changements de DNS,
de domaine personnalisé et de configuration du compte Cloudflare restent hors
de ce dépôt.

## Contrat de build hérité

La configuration historiquement documentée pour Cloudflare Pages est :

- racine du projet : `/` ;
- répertoire de sortie : `public` ;
- aucune commande de build (ou `exit 0` si l'interface en exige une).

Ces paramètres de projet sont externes au dépôt et doivent être vérifiés dans
une preview autorisée avant tout déploiement. Le plan de retrait, les décisions
de préservation et les procédures de validation sont détaillés dans
[`docs/functional/legacy-cloudflare-pages-retirement-2026.md`](docs/functional/legacy-cloudflare-pages-retirement-2026.md).
