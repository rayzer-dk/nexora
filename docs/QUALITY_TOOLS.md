# Nexora PHP quality tooling

PHPStan, PHP_CodeSniffer, Deptrac, and Rector are development-only tools for Nexora.
They are not dependencies of the deployed application. The production composer.json
and composer.lock are deliberately unchanged.

## Install (PHP 8.4 and Composer 2)

From the repository root:

```sh
cd tools/quality
composer install
cd ../..
```

The first Composer install resolves dependencies because this isolated tools
manifest does not yet carry a lockfile. Commit a generated tools/quality/composer.lock
after a verified run for fully reproducible future installs. Do not run Composer
updates automatically on the production dependency lockfile.

## Run

```sh
# Existing enforced PHPStan: .github/workflows/qa.yml (level 3).
# For local use, first install application dependencies: composer install
tools/quality/vendor/bin/phpstan analyse -c phpstan.neon.dist --memory-limit=2G --no-progress

# Focused security and file-hygiene rules. Does not rewrite files.
tools/quality/vendor/bin/phpcs --standard=phpcs.xml.dist --report=summary

# Dependency architecture report; current project debt must be reviewed.
tools/quality/vendor/bin/deptrac analyse --config-file=deptrac.yaml

# Read-only Rector check; main-only manual workflow can apply a validated one-file change.
tools/quality/vendor/bin/rector process --config=rector.php --dry-run --no-progress-bar
```

The existing PHPStan gate in qa.yml is not replaced or duplicated. It currently
downloads a pinned PHPStan PHAR and checks level 3. The isolated tool installation
allows agents and local development to invoke all four tools consistently.

The initial PHPCS profile intentionally checks a small set of high-signal rules.
Whole-project PSR-12 reformatting is **not** enabled until legacy findings are
assessed, to avoid unrelated formatting churn.

The Deptrac layer map checks Core/Domain/Application/Infrastructure/Http dependency
directions. Existing cross-module exceptions are documented in
config/architecture/module_boundaries.json and must not be expanded without review.
Deptrac is advisory until the existing violations are triaged and an accurate
baseline is established.

The regular quality workflow never commits refactoring changes. Rector on main
reviews changed files in dry-run mode; a separate manual main-only workflow
can apply corrections to one selected PHP file after tests. Do not use it to
hide dependency, configuration or PHP-version errors.

## Safety

Never add these tools to production deployments, run php-cbf or Rector auto-fix
against the full project without review, or suppress errors by automatically
adding ignore/baseline entries. Any refactor needs focused tests and regression
checks before being merged.
