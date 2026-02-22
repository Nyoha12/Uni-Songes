# AGENTS.md

## Objectif
Ce dépôt doit rester **déployable et reproductible depuis GitHub**. Toute contribution doit minimiser le risque de régression en production.

## Règles de contribution Codex
1. **Pas de refactor global** : limiter les changements au besoin explicitement demandé.
2. **Pas de nouvelles URLs publiques sans validation** : ne pas ajouter/modifier des chemins accessibles publiquement sans accord explicite.
3. **Petites PR** : une PR = un objectif clair, périmètre réduit, diff lisible.
4. **Tests obligatoires** : exécuter les vérifications pertinentes (lint, commandes de validation, scripts touchés) avant commit.
5. **Pas de secrets en dur** : aucun secret/API key/token/mot de passe dans le code, la config ou les scripts.

## Bonnes pratiques opérationnelles
- Préférer des scripts idempotents et verbeux pour le déploiement.
- Documenter les prérequis et commandes exactes utilisées pour staging.
- Éviter toute modification d'URL publique, de DNS ou de routage sans ticket/validation.
