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
- désactive PayPal si aucun secret n'est configuré.

## 5) Flux de configuration Drupal

### Export (depuis environnement source)
```bash
vendor/bin/drush cex -y
```

### Import (sur staging)
```bash
vendor/bin/drush cim -y
vendor/bin/drush cr
```

## 6) Vérifications minimales
```bash
vendor/bin/drush status
vendor/bin/drush config:status
vendor/bin/drush watchdog:show --count=20
```

## Notes sécurité
- Repo privé : possible de committer les secrets si voulu.
- Repo public : interdit de committer les secrets ou clés privées.
- En cas de fuite de secret : rotation immédiate.
