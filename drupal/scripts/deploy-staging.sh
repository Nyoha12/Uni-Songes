#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRUPAL_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${DRUPAL_DIR}"

echo "[deploy-staging] Starting deploy in ${DRUPAL_DIR}"

echo "[deploy-staging] git pull --ff-only"
git pull --ff-only

echo "[deploy-staging] composer install"
composer install --no-interaction --prefer-dist --optimize-autoloader

echo "[deploy-staging] drush updb -y"
vendor/bin/drush updb -y

echo "[deploy-staging] drush cr"
vendor/bin/drush cr

echo "[deploy-staging] Deploy completed successfully"
