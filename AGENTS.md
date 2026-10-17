# Nexora source quality: Codex and Claude Code

- Use ASCII in PHP identifiers and executable syntax. Preserve real Ukrainian/Russian and other Unicode in interface text, translations, comments and content.
- Never insert invisible or bidi Unicode controls, Markdown fences in source, omitted-code placeholders, or HTML-escaped operators into PHP.
- Confirm real method signatures and existing project services before using any API; check PHPStan and tests.
- Do not create redundant guards, broad catch-and-ignore exceptions, duplicate implementations, pointless tests or excessive trivial comments.
- Check Deptrac architecture rules and protect independent modules from hidden mandatory dependencies.
- On main, Rector reviews changes read-only. A single explicitly selected first-party PHP file may be auto-corrected on main only by manual workflow dispatch with apply=true after PHPStan, PHPUnit, PHPCS, syntax and source guard checks; no changes are deployed automatically.


## Quality Fixer (Codex / Claude Code)

When CI fails or a defect is reported, act as the shared Quality Fixer role:
1. Reproduce the failure using the smallest relevant check; inspect CI logs and the affected dependency chain before editing.
2. Identify the root cause; avoid speculative mass changes, suppressions, duplicated implementations and patches on patches.
3. Make the smallest coherent fix, preserving architecture boundaries, RBAC, security, translations and light/dark layout behavior.
4. Run targeted tests and then applicable PHP syntax, PHPStan, PHPUnit, PHPCS, source integrity, duplication and Deptrac checks.
5. If a check cannot run locally, explain exactly which check and why. Do not claim a green build without results.
6. Stop on success or on a concrete blocker; do not loop indefinitely or change unrelated files merely to produce activity.
7. Give a concise report of root cause, files changed, checks and remaining risks.
Never bypass CI gates, merge automatically or expose credentials.

## Source integrity and Quality Fixer
Quality Fixer handles evidenced CI failures and regressions, invoking existing Source Guard, PHPStan, PHPCS, Deptrac, PHPUnit and scoped Rector checks. Check BOM, invisible and bidi controls, suspicious mixed-script identifiers, HTML-escaped executable operators, accidental Markdown fences and omitted-code placeholders. Avoid invented API calls, redundant guards, swallowed exceptions, duplicate code and pointless tests. Source files are UTF-8 without BOM; Excel-oriented CSV may optionally add BOM only at export. Never blanket-rewrite typographic quotes, dashes or Unicode within strings, translations, comments, Twig or documentation; token/context-aware analysis is mandatory. Automatically strip only confirmed leading BOM through the existing source guard; all other suspicious issues require source-aware review. Work directly in main after checking the latest state, validate adjacent dependencies, and never overwrite unrelated work. Stop when fixed or when a specific blocker is known.
