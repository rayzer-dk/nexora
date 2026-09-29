#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
VERSION="$(php -r "require 'src/Core/Platform/PlatformVersion.php'; echo Commerce\\Core\\Platform\\PlatformVersion::VERSION;")"
OUT_DIR="$ROOT/build/production-$VERSION"
ZIP="$ROOT/build/Nexora_Commerce_v${VERSION}_PRODUCTION.zip"

command -v composer >/dev/null || { echo 'ERROR: composer is required on the build machine.' >&2; exit 20; }
command -v npm >/dev/null || { echo 'ERROR: npm is required on the build machine.' >&2; exit 21; }
[ -f composer.lock ] || { echo 'ERROR: composer.lock is required for a reproducible production build.' >&2; exit 22; }
[ -f package-lock.json ] || { echo 'ERROR: package-lock.json is required for a reproducible production build.' >&2; exit 23; }

composer validate --strict
php bin/release-contract-check.php

rm -rf "$OUT_DIR" "$ZIP"
composer install --prefer-dist --no-interaction
[ -x vendor/bin/phpunit ] || { echo 'ERROR: PHPUnit is required for release builds.' >&2; exit 24; }
[ -d tests ] || { echo 'ERROR: tests/ is required for release builds.' >&2; exit 25; }
[ -f phpunit.xml.dist ] || { echo 'ERROR: phpunit.xml.dist is required for release builds.' >&2; exit 26; }
vendor/bin/phpunit --configuration phpunit.xml.dist
npm ci
npm run typecheck
npm run build:production
composer install --no-dev --prefer-dist --optimize-autoloader --classmap-authoritative --no-interaction

php bin/dependency-integrity-check.php
php bin/release-check.php
php bin/release-contract-check.php --production
php bin/system-completeness-check.php
php bin/full-release-static-check.php
php bin/i18n-critical-journey-check.php
php bin/i18n-runtime-hardcode-audit.php --strict
php bin/twig-syntax-check.php themes/default/templates
php bin/admin-access-check.php
php bin/admin-confirm-check.php
php bin/commerce-analytics-check.php
php bin/license-audit.php
php bin/catalog-scale-audit.php
composer audit --locked --no-interaction
php bin/production-critical-check.php
php bin/security-audit.php
php bin/security-regression-check.php
php bin/commerce-operations-check.php
php bin/commerce-ux-check.php
php bin/backup-feed-seo-check.php
php bin/demo-showcase-check.php

mkdir -p "$OUT_DIR"
rsync -a ./ "$OUT_DIR/" \
  --exclude '/.git/' --exclude '/node_modules/' --exclude '/build/' --exclude '/tests/' \
  --exclude '/phpunit.xml.dist' --exclude '/.github/' --exclude '/var/cache/*' --exclude '/var/log/*'

mkdir -p "$OUT_DIR/var/cache" "$OUT_DIR/var/log" "$OUT_DIR/var/install" "$OUT_DIR/public/media"
find "$OUT_DIR/var/cache" -mindepth 1 -delete || true
find "$OUT_DIR/var/log" -mindepth 1 -delete || true

rm -f "$OUT_DIR/.env" "$OUT_DIR/.env.local"

[ -f "$OUT_DIR/vendor/autoload_runtime.php" ] || { echo 'ERROR: vendor missing from production stage.' >&2; exit 30; }
[ -f "$OUT_DIR/public/build/.vite/manifest.json" ] || { echo 'ERROR: Vite manifest missing from production stage.' >&2; exit 31; }
[ ! -d "$OUT_DIR/node_modules" ] || { echo 'ERROR: node_modules leaked into production stage.' >&2; exit 32; }
[ ! -e "$OUT_DIR/.env" ] || { echo 'ERROR: .env leaked into production stage.' >&2; exit 33; }
[ ! -e "$OUT_DIR/.env.local" ] || { echo 'ERROR: .env.local leaked into production stage.' >&2; exit 34; }
php tools/create-release-manifest.php "$OUT_DIR"
(
  cd "$OUT_DIR"
  find . -type f ! -name 'SHA256SUMS.txt' -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS.txt
)
(cd "$OUT_DIR" && zip -qr "$ZIP" .)
sha256sum "$ZIP"
echo "$ZIP"
