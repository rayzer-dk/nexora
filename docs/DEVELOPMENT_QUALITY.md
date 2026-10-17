# PHP development checks

Run checks from the repository root with PHP >= 8.4 and < 9.0. Install development
dependencies with `composer install`; the versions are pinned in `composer.lock`.
The four tools are development dependencies and are omitted by `composer install --no-dev`.

| Command | Scope |
| --- | --- |
| `composer quality:php` | Run the five checks below; stop at the first failure |
| `composer check:encoding` | Source text must be valid UTF-8 without BOM |
| `composer analyse` | PHPStan level 3, existing `src` scope, no baseline |
| `composer cs` | PHP tags, BOM, class declarations/names, method and constant names in `src` |
| `composer cs:psr12` | Full PSR-12 audit; existing formatting debt is not automatically rewritten |
| `composer deptrac` | Domain dependencies, with the explicit existing contracts below |
| `composer rector:check` | PHP 8.4 nullable-parameter and CSV-escape checks, always dry-run |
| `composer test:source-encoding` | Encoding guard regression tests |

On Windows, `tools/quality.ps1 -Task analyse` runs the same Composer command. It
uses PHP from PATH or Laragon's PHP 8.4 installation and Composer from PATH or
Laragon. Supply `-PhpBinary` and `-ComposerPhar` for other locations; set
`LARAGON_ROOT` for another Laragon directory. It does not alter the system PATH.

## Boundaries and policy

Deptrac protects the existing `Commerce\Modules\*\Domain` boundary. Domain may use
other Domain classes, `Core\I18n\CanonicalUiText`, `Catalog\Measurement\Quantity`,
and Symfony's `UserInterface` / `PasswordAuthenticatedUserInterface`. Dependencies
from Domain into other source layers or framework/database classes fail the check.
Other source layers are classified for coverage but their direction is not
restricted by this initial rule. No architectural baseline is installed.

PHPCS enforces the selected basic conventions in `phpcs.xml.dist`. Full PSR-12 is
an additional audit, since enforcing it across the inherited tree would require
unrelated formatting changes. The baseline audit on 2026-10-17 reported 38,656
errors and 8,763 warnings; counts can change as other development continues.

Rector's configured rules address PHP 8.4 deprecations. No automatic Rector or
PHPCBF command is part of the checks or CI. Broader rule sets and source rewrites
require a separate scoped change and regression verification.

Codex and Claude Code use these same commands through `AGENTS.md` and
`CLAUDE.md`. Preserve user changes and the active main branch. Inspect each
finding against the actual source and callers; do not hide errors with broad
ignores, baselines, casts or fabricated types to force a pass.

The `php-quality` job in `.github/workflows/quality.yml` runs the checks
independently after a locked Composer install, so one failed analysis does not
prevent the other tools from reporting. It does not access a database or deploy.

The encoding guard follows `docs/ENCODING.md` and never rewrites content. Its
default scan excludes dependencies, local agent state and generated/runtime
directories. Explicit paths can be checked separately. `.editorconfig` asks
editors to save UTF-8 without BOM; CI rejects BOM and invalid UTF-8.
