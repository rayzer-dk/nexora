#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
VERSION="$(php -r "require 'src/Core/Platform/PlatformVersion.php'; echo Commerce\\Core\\Platform\\PlatformVersion::VERSION;")"
OUT_DIR="$ROOT/build/production-$VERSION"
ZIP="$ROOT/build/Nexora_Commerce_v${VERSION}_PRODUCTION.zip"

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
php bin/production-critical-check.php
php bin/security-audit.php
php bin/security-regression-check.php
php bin/commerce-operations-check.php
php bin/commerce-ux-check.php
php bin/backup-feed-seo-check.php
php bin/demo-showcase-check.php

rm -rf "$OUT_DIR" "$ZIP"
mkdir -p "$OUT_DIR"
rsync -a ./ "$OUT_DIR/" \
  --exclude '/.git/' --exclude '/.build-tools/' --exclude '/node_modules/' --exclude '/build/' --exclude '/tests/' \
  --exclude '/phpunit.xml.dist' --exclude '/.github/' --exclude '/var/*' --exclude '/pw.local.config.ts' --exclude '/public/media/demo/dummyjson/' --exclude '/public/media/catalog/' --exclude '/public/media/video/' --exclude '/public/media/downloads/' --exclude '/.env' --exclude '/.env.local'

# The shipped vendor/ uses an authoritative class map (no filesystem lookups at runtime), so the map MUST be
# rebuilt from the files that are actually in this package; a map copied from an older build silently lacks
# every class added since ("Expected to find class ... but it was not found" during installation).
(cd "$OUT_DIR" && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts --no-interaction) >/dev/null

mkdir -p "$OUT_DIR/var/cache" "$OUT_DIR/var/log" "$OUT_DIR/var/install" "$OUT_DIR/var/extensions" "$OUT_DIR/var/feeds" "$OUT_DIR/var/recovery" "$OUT_DIR/var/sessions" "$OUT_DIR/public/media"
for d in cache log install extensions feeds recovery sessions; do : > "$OUT_DIR/var/$d/.gitkeep"; done
: > "$OUT_DIR/var/.gitkeep"
find "$OUT_DIR/var/cache" -mindepth 1 -delete || true
find "$OUT_DIR/var/log" -mindepth 1 -delete || true

# Do not ship a live .env file. The browser/CLI installer writes .env.local
# atomically with installation-specific secrets and database credentials.
rm -f "$OUT_DIR/.env" "$OUT_DIR/.env.local"


[ -f "$OUT_DIR/vendor/autoload_runtime.php" ] || { echo 'ERROR: vendor missing from production stage.' >&2; exit 30; }
[ -f "$OUT_DIR/public/build/.vite/manifest.json" ] || { echo 'ERROR: Vite manifest missing from production stage.' >&2; exit 31; }
[ ! -d "$OUT_DIR/node_modules" ] || { echo 'ERROR: node_modules leaked into production stage.' >&2; exit 32; }
[ ! -e "$OUT_DIR/var/install/installed.lock" ] || { echo 'ERROR: installed.lock leaked into package (installer would refuse to run).' >&2; exit 35; }
[ -z "$(find "$OUT_DIR/var" -type f ! -name .gitkeep -print -quit)" ] || { echo 'ERROR: runtime data leaked into var/.' >&2; exit 36; }
[ ! -e "$OUT_DIR/.env" ] || { echo 'ERROR: .env leaked into production stage.' >&2; exit 33; }
[ ! -e "$OUT_DIR/.env.local" ] || { echo 'ERROR: .env.local leaked into production stage.' >&2; exit 34; }
php tools/create-release-manifest.php "$OUT_DIR"
php tools/verify-packaged-release.php "$OUT_DIR"
(
  cd "$OUT_DIR"
  find . -type f ! -name 'SHA256SUMS.txt' -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS.txt
)
(cd "$OUT_DIR" && zip -qr "$ZIP" .)
sha256sum "$ZIP"
echo "$ZIP"
