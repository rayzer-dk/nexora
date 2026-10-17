# Automatic refactoring and source safety

Nexora uses PHPStan for type/API verification, PHP_CodeSniffer for security
hygiene, Deptrac for architectural dependencies, and Rector for
automatic **source code modifications** on trusted `autofix/**` branches.

A developer or a coding agent pushes modifications into an `autofix/feature`
branch. The Rector workflow fixes up to eight changes, only on PHP files
from the triggering commit, runs syntax, source-guard and PHPUnit checks,
then commits verified corrections to the same branch. It never pushes
to main, merges PRs, modifies the database or deploys to production.

This is intentionally not a full rewrite of legacy code. The first
automatic Rector rule makes boolean comparisons explicit. Additional
rules require separate review and tests before enabling.

`php tools/quality/source_guard.php --check src` inspects sources.
`php tools/quality/source_guard.php --fix-safe src` removes only UTF-8
BOM at the beginning of files. All other suspicious Unicode is reported,
never guessed or rewritten. Unicode in legitimate texts is preserved.

`php tools/quality/source_guard_test.php` runs regression fixtures.
Code quality heuristics cannot prove a comment is unnecessary, an API
exists at runtime, or a test is meaningful; inspect dependencies and
run substantive runtime tests before merging.

## BOM policy and Excel CSV

All tracked PHP, JS/TS, Twig, XML, CSS, SQL, JSON, YAML and shell source files must
be UTF-8 **without BOM**. The root `.editorconfig` expresses this preference;
Source Guard checks the actual leading bytes on changed source files.

UTF-8 CSV intended for direct opening in older Windows Excel installations can
optionally be emitted with the byte prefix `EF BB BF` by its **CSV exporter**.
That is an output-format decision, not a source-code encoding exception: do not
commit BOM-prefixed CSV as executable code and do not process export CSV through
Source Guard. Modern Excel import flows may support BOM-free UTF-8.

Never run the uploaded generic `code_sanitizer.py --fix` across CMS files: it
blindly replaces Unicode quotation marks, punctuation, invisible characters
and formatting in strings, HTML and translations. The integrated guard only
repairs a leading BOM; ambiguous symbols are reported for contextual review.
