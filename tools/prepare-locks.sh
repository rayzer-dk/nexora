#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

command -v composer >/dev/null || { echo 'ERROR: Composer is required.' >&2; exit 20; }
command -v npm >/dev/null || { echo 'ERROR: npm is required.' >&2; exit 21; }

composer validate --strict
composer update --no-install --with-all-dependencies --no-interaction
npm install --package-lock-only --ignore-scripts

[ -f composer.lock ] || { echo 'ERROR: composer.lock was not generated.' >&2; exit 22; }
[ -f package-lock.json ] || { echo 'ERROR: package-lock.json was not generated.' >&2; exit 23; }

composer audit --locked
npm audit --omit=dev
php bin/release-contract-check.php

echo 'Dependency lock files generated and audited successfully.'
