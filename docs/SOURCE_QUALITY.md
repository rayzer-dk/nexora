# Automatic refactoring and source safety

Nexora uses PHPStan for type/API verification, PHP_CodeSniffer for security
hygiene, Deptrac for architectural dependencies, and Rector for
controlled **source code modifications** in main only.

Rector runs read-only after ordinary pushes to main. An operator can
select one existing `src/*.php` file through the manual GitHub Actions
workflow and `apply=true`; it applies only the narrow
`SimplifyIfReturnBoolRector` transformation and commits after source
integrity, PHP lint, PHPCS, PHPStan, PHPUnit and diff checks pass.
No temporary branches, automatic deployment or broad rewrites.

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
