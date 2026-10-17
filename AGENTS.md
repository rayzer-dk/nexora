# Nexora source quality: Codex and Claude Code

- Use ASCII in PHP identifiers and executable syntax. Preserve real Ukrainian/Russian and other Unicode in interface text, translations, comments and content.
- Never insert invisible or bidi Unicode controls, Markdown fences in source, omitted-code placeholders, or HTML-escaped operators into PHP.
- Confirm real method signatures and existing project services before using any API; check PHPStan and tests.
- Do not create redundant guards, broad catch-and-ignore exceptions, duplicate implementations, pointless tests or excessive trivial comments.
- Check Deptrac architecture rules and protect independent modules from hidden mandatory dependencies.
- On main, Rector reviews changes read-only. A single explicitly selected first-party PHP file may be auto-corrected on main only by manual workflow dispatch with apply=true after PHPStan, PHPUnit, PHPCS, syntax and source guard checks; no changes are deployed automatically.
