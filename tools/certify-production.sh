#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

: "${DATABASE_URL:?DATABASE_URL must point to an isolated certification database}"
: "${E2E_BASE_URL:?E2E_BASE_URL is required for browser/HTTP certification}"
: "${PERF_BASE_URL:?PERF_BASE_URL is required for live performance certification}"
[ "${ALLOW_DESTRUCTIVE_QA:-0}" = "1" ] || { echo 'ERROR: set ALLOW_DESTRUCTIVE_QA=1 for an isolated QA database.' >&2; exit 40; }
command -v k6 >/dev/null || { echo 'ERROR: k6 is required for certified production load testing.' >&2; exit 41; }
command -v pa11y >/dev/null || { echo 'ERROR: pa11y is required for browser accessibility certification.' >&2; exit 44; }
[ -x vendor/bin/phpunit ] || { echo 'ERROR: install dev dependencies before certification.' >&2; exit 42; }

php bin/production-certification-readiness-check.php
php bin/console lint:container
php bin/console lint:twig themes/default/templates
php bin/twig-syntax-check.php themes/default/templates
php bin/admin-access-check.php
php bin/license-audit.php
php bin/commerce-analytics-check.php
php bin/console doctrine:migrations:migrate --no-interaction
vendor/bin/phpunit --configuration phpunit.xml.dist

php -r '
$base=rtrim(getenv("E2E_BASE_URL"),"/");
foreach (["/","/catalog","/admin/login"] as $path) {
 $ctx=stream_context_create(["http"=>["timeout"=>15,"ignore_errors"=>true,"follow_location"=>0]]);
 @file_get_contents($base.$path,false,$ctx);
 $line=$http_response_header[0]??"";
 if (!preg_match("#HTTP/\\S+\\s+(\\d{3})#",$line,$m) || (int)$m[1]>=500) { fwrite(STDERR,"HTTP smoke failed for $path: $line\n"); exit(43); }
 echo "$path $line\n";
}
'
php bin/performance-audit.php 10k
BASE_URL="$E2E_BASE_URL" k6 run qa/load/k6-storefront.js
pa11y --standard WCAG2AA "$E2E_BASE_URL/"
pa11y --standard WCAG2AA "$E2E_BASE_URL/catalog"
pa11y --standard WCAG2AA "$E2E_BASE_URL/admin/login"

echo 'Nexora live production certification gates: PASSED'
