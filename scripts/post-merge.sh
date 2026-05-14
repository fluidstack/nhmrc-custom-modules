#!/bin/bash
set -euo pipefail

cd "$(dirname "$0")/.."

if [ -f drupal/composer.json ]; then
  if [ ! -d drupal/vendor ] || [ drupal/composer.lock -nt drupal/vendor ]; then
    (cd drupal && composer install --no-interaction --no-progress)
  fi
fi

if [ -x drupal/vendor/bin/drush ]; then
  (cd drupal && ./vendor/bin/drush cache:rebuild --yes) || true
fi
