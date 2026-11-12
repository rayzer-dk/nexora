#!/usr/bin/env bash
# Sandbox setup for Nexora. Idempotent.
#   setup.sh          quick: services, vendor in sync with composer.lock, node_modules, assets (seconds when up to date)
#   setup.sh --full   also upgrades system packages, PHP extensions, Composer, npm and pip
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"; cd "$ROOT"
export COMPOSER_ALLOW_SUPERUSER=1 DEBIAN_FRONTEND=noninteractive
log() { echo "[sandbox] $*"; }

if [ "${1:-}" = "--full" ]; then
  log 'system packages'
  apt-get update -qq && apt-get -y -qq -o Dpkg::Options::=--force-confold upgrade >/dev/null
  apt-get install -y -qq php8.4-cli php8.4-sqlite3 php8.4-mysql php8.4-gd php8.4-intl php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-opcache mariadb-server brotli >/dev/null
  composer self-update --no-interaction >/dev/null 2>&1 || true
  npm i -g npm@latest >/dev/null 2>&1 || true
  pip list --outdated --format=json 2>/dev/null | python3 -c "import json,sys;print(' '.join(p['name'] for p in json.load(sys.stdin)))" | xargs -n1 pip install -q --break-system-packages -U >/dev/null 2>&1 || true
fi

if command -v mariadbd >/dev/null && ! mysqladmin ping --silent >/dev/null 2>&1; then
  log 'starting MariaDB'; service mariadb start >/dev/null 2>&1 || service mysql start >/dev/null 2>&1
  for _ in 1 2 3 4 5 6 7 8; do mysqladmin ping --silent >/dev/null 2>&1 && break; sleep 1; done
fi
if mysqladmin ping --silent >/dev/null 2>&1; then
  mysql -e "CREATE DATABASE IF NOT EXISTS nexora_e2e CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS 'nexora'@'localhost' IDENTIFIED BY 'nexora'; CREATE USER IF NOT EXISTS 'nexora'@'127.0.0.1' IDENTIFIED BY 'nexora'; GRANT ALL ON nexora_e2e.* TO 'nexora'@'localhost'; GRANT ALL ON nexora_e2e.* TO 'nexora'@'127.0.0.1'; GRANT ALL ON nexora.* TO 'nexora'@'localhost'; GRANT ALL ON nexora.* TO 'nexora'@'127.0.0.1';" 2>/dev/null || true
fi

if [ -f composer.lock ]; then
  python3 .claude/sandbox/vendor_from_git.py && composer dump-autoload -o --no-scripts --no-interaction >/dev/null 2>&1 || log 'vendor sync failed'
fi
if [ -f package-lock.json ] && [ ! -d node_modules/.bin ]; then log 'npm ci'; npm ci --no-audit --no-fund >/dev/null 2>&1 || log 'npm ci failed'; fi
if [ ! -f public/build/.vite/manifest.json ] && [ -d node_modules ]; then log 'building assets'; npm run build:production >/dev/null 2>&1 || log 'build failed'; fi
log 'done'
