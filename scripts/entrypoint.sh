#!/usr/bin/env bash
set -euo pipefail

cd /app/app

mkdir -p /home/app/.composer/cache

APP_ENV=${APP_ENV:-dev}
APP_DEBUG=${APP_DEBUG:-1}
export COMPOSER_MEMORY_LIMIT=${COMPOSER_MEMORY_LIMIT:--1}

COMPOSER_INSTALL=${COMPOSER_INSTALL:-auto}
if [ "${COMPOSER_INSTALL}" != "0" ]; then
  if [ "${COMPOSER_INSTALL}" = "auto" ] && [ -f vendor/autoload.php ]; then
    echo "Composer dependencies already installed; skipping (set COMPOSER_INSTALL=1 to force)."
  else
    if [ "${APP_ENV}" = "prod" ]; then
      echo "Installing PHP dependencies (production mode)..."
      composer install --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative --no-dev --no-scripts
    else
      echo "Installing PHP dependencies (development mode)..."
      composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts
    fi
  fi
fi

if [ ! -f app/config/parameters.yml ]; then
  echo "Bootstrapping Symfony parameters from dist file..."
  cp app/config/parameters.yml.dist app/config/parameters.yml
fi

/app/scripts/wait-for-db.sh "${POSTGRES_HOST:-db}" "${POSTGRES_PORT:-5432}"

DEFAULT_BOOTSTRAP=1
if [ "${APP_ENV}" = "prod" ]; then
  DEFAULT_BOOTSTRAP=0
fi

if [ "${RUN_DB_MIGRATIONS:-$DEFAULT_BOOTSTRAP}" = "1" ]; then
  php /app/scripts/migrate.php
fi

SYMFONY_ENV="${APP_ENV}"
ASSETIC_DEBUG_FLAG=""
if [ "${APP_DEBUG}" = "0" ]; then
  ASSETIC_DEBUG_FLAG="--no-debug"
fi

if [ "${RUN_SYMFONY_BUILD:-$DEFAULT_BOOTSTRAP}" = "1" ]; then
  php bin/console cache:clear --no-warmup --env="${SYMFONY_ENV}" || true
  php bin/console cache:warmup --env="${SYMFONY_ENV}" || true
  php bin/console assets:install --symlink --env="${SYMFONY_ENV}" || true
  php bin/console assetic:dump --env="${SYMFONY_ENV}" ${ASSETIC_DEBUG_FLAG} || true
fi

if [ "${RUN_SCHEMA_UPDATE:-$DEFAULT_BOOTSTRAP}" = "1" ]; then
  php bin/console doctrine:schema:update --force --env="${SYMFONY_ENV}" || true
fi

if [ "${RUN_DB_SEED:-$DEFAULT_BOOTSTRAP}" = "1" ]; then
  php /app/scripts/seed.php
fi

if [ "${RUN_ADMIN_SEED:-$DEFAULT_BOOTSTRAP}" = "1" ]; then
  php bin/console samli:user:create admin admin@example.org adminpass Admin Admin --super-admin --env="${SYMFONY_ENV}" >/dev/null 2>&1 || true
fi

SERVER_HOST=${APP_HOST:-0.0.0.0}
SERVER_PORT=${APP_PORT:-8080}

echo "Starting PHP built-in server on ${SERVER_HOST}:${SERVER_PORT}"

exec php -S "${SERVER_HOST}:${SERVER_PORT}" -t web web/app.php
