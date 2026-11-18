# Dead-code and unused-symbol audit

Workflow: `.github/workflows/dead-code-audit.yml` (main pushes, relevant pull requests, manual dispatch).

The existing blocking PHP quality workflow is preserved. The fixture check here **must pass**: PHPStan 2.3+ bleeding-edge rules must identify an unused local, a never-read private property, and an unused private method. The whole-project report is **advisory** while existing findings are triaged; GitHub Actions saves the full report as an artifact.

Native PHPStan checks: unreachable branches, unnecessary assignments, unused local variables, private methods, and private properties. The separate `shipmonk/dead-code-detector` pass extends this to transitive dead method/property cycles across PHP source files. These tools are installed only in `tools/quality`, not the application deployment.

Run locally:

```sh
composer install --working-dir=tools/quality
composer install --no-scripts
tools/quality/vendor/bin/phpstan analyse -c phpstan.dead-code.neon.dist --no-progress
tools/quality/vendor/bin/phpstan analyse -c phpstan.dead-code-project.neon.dist --no-progress
```

Dynamic callbacks, service-container registrations, reflective access, CMS extensions and serialized properties must be checked before removing a reported symbol. ShipMonk includes usage providers for several frameworks but is not infallible.

Twig template variables and missing context keys are **not** verified by this PHP-only workflow. PhpStorm can inspect templates locally; robust CI validation requires rendering-context-aware template analysis, which has not been introduced by this change. Never delete PHP or Twig code solely because of a static-analysis warning.
