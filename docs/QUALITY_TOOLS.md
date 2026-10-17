# Nexora PHP quality tooling

PHPStan, PHP_CodeSniffer, Deptrac and Rector are development-only tools. They
are not part of the deployed application: `composer install --no-dev` omits them.
There is one set of configuration files and one CI workflow:

| File | Purpose |
| --- | --- |
| `phpstan.neon.dist` | PHPStan level 3, `src`, no baseline (blocking) |
| `phpstan.strict.neon.dist` | PHPStan level 5 report (advisory) |
| `phpcs.xml.dist` | Security, naming and file-hygiene rules (blocking) |
| `deptrac.yaml` | Core/Domain/Application/Infrastructure/Http layer map (advisory) |
| `rector.php` | Narrow approved rules, always dry-run (advisory) |
| `bin/source-encoding-check.php` | Whole repository: UTF-8, no UTF-8/16/32 BOM (blocking) |
| `tools/quality/source_guard.php` | Invisible/bidi Unicode, confusables, placeholders (blocking) |
| `.github/workflows/php-quality-tools.yml` | The PHP quality workflow running all of the above |
| `.github/workflows/rector-autofix.yml` | Rector dry-run report for changed files; never commits |

## Local commands

Install development dependencies with `composer install` (versions locked in
`composer.lock`). Run from the repository root with PHP >= 8.4.

| Command | Scope |
| --- | --- |
| `composer quality:php` | All blocking checks below; stops at the first failure |
| `composer check:encoding` | Source text must be valid UTF-8 without BOM |
| `composer test:source-encoding` | Encoding guard regression tests |
| `composer check:source-guard` · `composer test:source-guard` | Source guard on `src tests config tools` and its fixtures |
| `composer analyse` | PHPStan level 3 |
| `composer cs` | PHPCS with `phpcs.xml.dist` |
| `composer cs:psr12` | Full PSR-12 audit; existing formatting debt is not rewritten |
| `composer deptrac` | Deptrac report with `deptrac.yaml` (advisory, legacy violations) |
| `composer rector:check` | Rector dry-run (advisory, never applies changes) |

On Windows, `tools/quality.ps1 -Task analyse` runs the same Composer scripts. It
uses PHP from PATH or Laragon's PHP 8.4 and Composer from PATH or Laragon; supply
`-PhpBinary` and `-ComposerPhar` for other locations, or set `LARAGON_ROOT`.

CI installs the same tools in isolation from `tools/quality/composer.json`
(`composer install --working-dir=tools/quality`) so that the tool versions do
not depend on the application lock file.

## Policy

- Rector never writes to main by itself. Both workflows run it with `--dry-run`
  and only report. A suggestion is applied by hand in main, after review and
  PHPStan, PHPCS, PHPUnit and source guard checks.
- Deptrac is advisory until the existing violations (Application → Infrastructure,
  Core → Modules) are triaged. Cross-module exceptions remain documented in
  `config/architecture/module_boundaries.json`. No Deptrac baseline is installed.
- Full PSR-12 is a separate audit; enforcing it would reformat unrelated code.
- Never run PHPCBF or Rector without `--dry-run` across the project, and never
  hide findings with broad ignores, baselines, casts or invented types.
- Inspect each finding against the real source and callers; a green static check
  does not replace runtime tests or security review.

The encoding check follows `docs/ENCODING.md` and never rewrites content. Its
default scan excludes dependencies, local agent state and generated/runtime
directories. `.editorconfig` asks editors to save UTF-8 without BOM.
See `docs/SOURCE_QUALITY.md` for the source guard and the CSV/Excel BOM exception.
