# Uni-Songes — état courant

## Règle d’usage
Ce fichier contient uniquement l’état opérationnel actuel du projet.
Il doit rester court, maintenable et utile pour reprendre le travail.
Les détails historiques restent dans Git, les PR GitHub et les logs de déploiement.

## Environnement local
- Repo principal de développement : WSL `~/Uni-Songes/repo`.
- Dossier Drupal/DDEV : `~/Uni-Songes/repo/drupal`.
- DDEV opérationnel avec stack alignée VPS :
  - PHP 8.3
  - MariaDB 10.11
  - Drupal 11.3.3
  - Drush 13.7.1
- Composer installé via DDEV.
- Codex CLI installé dans WSL.
- GitHub CLI authentifié dans WSL.
- Le clone Windows `D:\Uni Songes\repo` est secondaire et ne doit plus être le repo principal de développement.

## Production
- Branche de production : `release/prod`.
- Repo GitHub : `Nyoha12/Uni-Songes`.
- VPS SSH : `ubuntu@91.134.255.237`.
- Chemin Drupal VPS : `/var/www/unisonges/repo/drupal`.
- Chemin Git VPS : `/var/www/unisonges/repo`.
- HEAD prod vérifié après PR #29 : `1435cf6`.

## Workflow validé
Le workflow de travail principal est :
1. travailler dans WSL `~/Uni-Songes/repo`;
2. utiliser DDEV pour tester PHP/Drush;
3. utiliser Codex depuis WSL;
4. ouvrir une PR GitHub non-draft;
5. vérifier la PR;
6. merger vers `release/prod`;
7. appliquer sur le VPS par `git pull --ff-only origin release/prod`;
8. vérifier prod.

## Règles de sécurité / méthode
- Ne pas utiliser le VPS comme environnement de développement.
- Le VPS sert uniquement à appliquer et vérifier des changements déjà validés.
- Une action logique par étape.
- Distinguer diagnostic lecture seule et action modificatrice.
- Ne pas utiliser `uid=1` pour les tests fonctionnels.
- Ne pas activer la vraie synchronisation Google Calendar tant que la couche réservation interne n’est pas stabilisée.
- Ne pas modifier Google Calendar, Commerce, stages/concerts ou Composer dans une PR qui cible uniquement `/reserver`.

## Réservation /reserver
- PR #26 appliquée : ajout du portail `/reserver`.
- PR #27 appliquée : correction du “Oops” AJAX après soumission.
- PR #29 appliquée : durcissement du flux réservation et du CSS du portail.
- `/reserver` affiche le portail et le webform `cours_particuliers_reservation`.
- PR #29 a été mergée, appliquée sur le VPS, puis testée en production avec `uid=2`.
- Le test connecté a confirmé :
  - soumission `sid=5` créée;
  - crédit décrémenté de 1 à 0;
  - ligne Google Calendar créée en `pending_create` avec `last_error` à `NULL`;
  - aucun “Oops” ni erreur Drupal observé.
- La soumission test `sid=5` et sa ligne Google Calendar ont été nettoyées.
- Le crédit `uid=2` a été remis à 1 après nettoyage.

## Compte test
Compte dédié aux tests `/reserver` :
- uid : `2`
- login : `test.reservation`
- mail : `test.reservation@unisonges.fr`
- usage : tests réservation uniquement.
Ne pas utiliser `uid=1` pour les tests réservation.

## Google Calendar
- La table de queue Google Calendar existe.
- Les lignes de réservation sont mises en queue en `pending_create`.
- La configuration reste désactivée / dry-run côté réel.
- Ne pas passer en synchronisation réelle avant validation complète du flux interne de réservation.

## Prochaine étape
- Utiliser `uid=2` pour les futurs tests `/reserver`, puis nettoyer les données test.
- Garder la vraie synchronisation Google Calendar désactivée jusqu’à validation dédiée.
