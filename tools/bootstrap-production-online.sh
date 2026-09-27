#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if ! command -v php >/dev/null; then
  echo 'ERROR: PHP CLI is required.' >&2
  exit 20
fi
if ! command -v npm >/dev/null; then
  echo 'ERROR: npm is required.' >&2
  exit 21
fi

COMPOSER_BIN="$(command -v composer || true)"
LOCAL_COMPOSER="$ROOT/.build-tools/composer.phar"
if [ -z "$COMPOSER_BIN" ]; then
  mkdir -p "$ROOT/.build-tools"
  EXPECTED="$(php -r "copy('https://composer.github.io/installer.sig', 'php://stdout');")"
  php -r "copy('https://getcomposer.org/installer', '$ROOT/.build-tools/composer-setup.php');"
  ACTUAL="$(php -r "echo hash_file('sha384', '$ROOT/.build-tools/composer-setup.php');")"
  if [ -z "$EXPECTED" ] || [ "$EXPECTED" != "$ACTUAL" ]; then
    rm -f "$ROOT/.build-tools/composer-setup.php"
    echo 'ERROR: Composer installer signature verification failed.' >&2
    exit 22
  fi
  php "$ROOT/.build-tools/composer-setup.php" --quiet --install-dir="$ROOT/.build-tools" --filename=composer.phar
  rm -f "$ROOT/.build-tools/composer-setup.php"
  COMPOSER_BIN="php $LOCAL_COMPOSER"
fi

run_composer() {
  if [ "$COMPOSER_BIN" = "php $LOCAL_COMPOSER" ]; then
    php "$LOCAL_COMPOSER" "$@"
  else
    "$COMPOSER_BIN" "$@"
  fi
}

run_composer validate --strict
run_composer update --no-install --with-all-dependencies --no-interaction
npm install --package-lock-only --ignore-scripts

[ -f composer.lock ] || { echo 'ERROR: composer.lock was not generated.' >&2; exit 23; }
[ -f package-lock.json ] || { echo 'ERROR: package-lock.json was not generated.' >&2; exit 24; }

run_composer audit --locked
npm audit --omit=dev

run_composer install --no-dev --prefer-dist --optimize-autoloader --classmap-authoritative --no-interaction
npm ci
npm run typecheck
npm run build:production
rm -rf node_modules

php bin/dependency-integrity-check.php
php bin/release-check.php
php bin/release-contract-check.php --production

COMPOSER="$COMPOSER_BIN" bash tools/package-production.sh
