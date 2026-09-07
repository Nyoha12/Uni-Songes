# Staging — déploiement reproductible Drupal

Ce guide décrit les commandes **exactes** pour déployer l'instance Drupal en staging depuis le dépôt GitHub.

## Pré-requis
- Accès SSH au serveur staging.
- PHP/Composer compatibles avec le projet.
- Base de données staging déjà provisionnée.
- `settings.php` staging (non versionné) déjà présent.

## 1) Récupération du code
```bash
cd /var/www/unisonges/drupal
git fetch --all --prune
git checkout <branch-ou-tag>
git pull --ff-only origin <branch-ou-tag>
```

## 2) Installation des dépendances
```bash
composer install --no-interaction --prefer-dist --optimize-autoloader
```

## 3) Déploiement applicatif (DB + cache)
```bash
./scripts/deploy-staging.sh
```

Le script exécute:
- `git pull --ff-only`
- `composer install`
- `drush updb -y`
- `drush cr`

## 4) Bootstrap commerce (idempotent)
```bash
./scripts/bootstrap-commerce.sh
```

Le script:
- crée un store `online` s'il est absent,
- crée un payment gateway `manual` si absent,
- ne valide ni ne modifie PayPal et renvoie vers le runbook runtime dédié.

## 5) Configuration PayPal runtime

La configuration exportée ne contient aucun credential et garde la passerelle
désactivée. Suivre le runbook
`docs/security/paypal-credential-remediation-2026.md` pour :

- faire tourner le credential sandbox exposé ;
- fournir `UNISONGES_PAYPAL_CLIENT_ID` et
  `UNISONGES_PAYPAL_CLIENT_SECRET` depuis le stockage VPS approuvé ;
- charger `config/runtime/paypal.settings.php` depuis le `settings.php` non
  versionné ;
- valider le comportement fail-closed sans afficher les valeurs.

Ne jamais saisir les valeurs réelles dans
`infra/paypal.env.example`. Ne pas sauvegarder le formulaire du gateway avec
les overrides actifs : cela peut recopier les valeurs runtime dans la
configuration active.

## 6) Flux de configuration Drupal

### Export (depuis environnement source)
```bash
./scripts/check-tracked-payment-secrets-2026.sh
vendor/bin/drush cex -y
./scripts/check-tracked-payment-secrets-2026.sh
```

### Import (sur staging)
```bash
vendor/bin/drush cim -y
vendor/bin/drush cr
```

## 7) Vérifications minimales
```bash
vendor/bin/drush status
vendor/bin/drush config:status
vendor/bin/drush watchdog:show --count=20
```

## Notes sécurité
- Le dépôt est public : aucun secret ou credential ne doit être suivi.
- En cas de fuite : rotation/révocation immédiate, même après nettoyage de
  `HEAD`.
- L'historique conserve l'ancienne valeur jusqu'à une réécriture séparée,
  approuvée et coordonnée. Aucune réécriture n'est réalisée par ce runbook.
