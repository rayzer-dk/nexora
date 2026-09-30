# Dependency policy

The project requires PHP 8.4 or newer; PHP 8.4–8.5 are currently certified for production. Legacy PHP compatibility shims are not a design goal.

Dependencies are managed only through Composer/npm with lock files in production builds. Directly copied vendor libraries are not accepted. Stable maintained branches are preferred over beta/RC releases even when a prerelease has a higher version number.

Security checks are part of release QA: `composer audit --locked`, `composer check-platform-reqs`, `npm audit --omit=dev`, PHP lint, frontend typecheck/build and migration/schema smoke tests.

A library may be removed when the platform can implement the same capability with a maintained native/runtime component at lower complexity. No dependency is added only because it is fashionable.

## 0.8.0 baseline (2026-09-16)

- PHP runtime: `>=8.4 <9.0`. Verified on 8.4 (full suite) and 8.5 (unit suite, lint); PHP 8.6 is allowed but not yet run for real because no 8.6 build exists.
- Symfony: 8.1 stable train.
- Vue: 3.5.42.
- Vite: 8.3.0.
- Pinia: 4.0.3.
- Chart.js: 4.5.1.
- Floating UI DOM: 1.8.0.
- Lucide Vue: `@lucide/vue` 1.46.0.
- TypeScript: 7.0.2 stable native compiler.
- vue-tsc: 3.3.11, with strict Vue template checking enabled.

"Newest" means newest maintained stable version that passes the platform compatibility suite, not an unverified higher version number.

## One-command production materialization

A SOURCE checkout intentionally does not fabricate lock files, vendor code or compiled assets. On a build host with outbound HTTPS access, run:

```bash
bash tools/bootstrap-production-online.sh
```

The command verifies the Composer installer signature when Composer is not already installed, resolves and audits `composer.lock` and `package-lock.json`, installs production PHP dependencies, runs `npm ci`, typechecks and builds Vite assets, removes `node_modules`, validates `vendor/` plus the complete Vite manifest, executes the production release gates and finally creates the one-upload ZIP.

`tools/package-production.sh` is deliberately network-independent and refuses to package a release unless real lock files, `vendor/autoload_runtime.php` and every required Vite manifest entry already exist.

## PHP 8.6 readiness

`php tools/check-php86-readiness.php` statically scans `src/`, `bin/`, `tools/` and `public/` for constructs removed or deprecated in the PHP 8.6 line (0 findings as of 3.18.1). The upper bound is `<9.0`; when an 8.6 build is released, run the full unit and e2e suites on it and record the result here. The weekly `PHP next` workflow (`.github/workflows/php-next.yml`) already runs the unit suite on PHP 8.5 and on PHP nightly (future 8.6) without blocking releases.
