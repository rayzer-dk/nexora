# Production package

The one-upload package is built only from locked dependencies.

Required on the release build machine: PHP 8.4, Composer, Node.js/npm and network access to Packagist/npm registry.

1. Generate and review `composer.lock` and `package-lock.json` once when dependency versions change.
2. Run `tools/build-production.sh`.
3. The script installs Composer dependencies without dev packages, runs `npm ci`, typecheck, Vite production build, asset compression and release/security/commerce gates.
4. Output: `build/Nexora_Commerce_vX.Y.Z_PRODUCTION.zip`.
5. The archive includes `vendor`, compiled `public/build` assets, `release-manifest.json` and `SHA256SUMS.txt`. It must not ship `.env` or `.env.local`; the installer creates installation-specific environment configuration.

A release must not be labelled PRODUCTION when either lock file, `vendor/autoload_runtime.php`, or the Vite manifest is missing.


## Dependency locking

Run `tools/prepare-locks.sh` on a connected release machine whenever Composer or npm dependencies change. Commit both lock files. Never hand-create or partially fabricate a lock file.

SOURCE archives may omit `vendor` and `public/build`; PRODUCTION archives must include both and must pass `php bin/release-contract-check.php --production`.
