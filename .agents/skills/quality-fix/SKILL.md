---
name: quality-fix
description: "Fix a reproduced defect safely and run the appropriate verification gates."
---

# Quality Fixer — проверяемое исправление ошибок

Role: executor, not an endless autonomous loop. Shared between Codex and Claude Code.

Trigger: explicit defect, failing CI, security finding or regression. Do not create speculative work. Follow AGENTS.md, docs/CHANGE_NECESSITY.md, project standards and existing quality gates.

1. Capture baseline: failing command, exact log, affected revision, relevant callers, dependencies and constraints. Reproduce before editing when practical.
2. Determine root cause; distinguish a genuine failure from false positives or tooling noise. Confirm actual framework classes, methods and signatures from repository source, Composer dependencies, IDE/LSP and PHPStan. For dynamic OpenCart models, add narrowly scoped accurate stubs/extensions; never hide all undefined properties or method calls globally.
3. Inspect byte-level corruption: BOM, zero-width, bidi/Trojan Source, deceptive mixed-script identifiers, NBSP, unusual quotes/dashes in executable syntax, HTML entities, Markdown fences and omitted-code placeholders. Use token/AST and context to distinguish syntax from user-facing translations, strings, templates and documentation. UTF-8 without BOM for source. Excel CSV BOM is output-specific and optional, not a source-code exception.
4. Default automatic Unicode repair: ONLY leading UTF-8 BOM when confirmed safe, via existing Nexora source_guard.php --fix-safe; report other Unicode for contextual human/agent review. Do NOT run generic code_sanitizer.py --fix wholesale or rewrite typography/Unicode in legitimate content. Preserve original newlines, encoding and file permissions.
5. Avoid AI-slop: invented APIs, dead and duplicated implementations, pointless wrappers, broad silent catch, excessive defensive checks, fabricated tests, placeholder code, unnecessary dependencies, speculative refactors and superficial PRs. Validate necessary guards at trust boundaries; don't remove security checks as redundant without proof.
6. Plan smallest coherent correction considering adjacent routes, UI, controllers, models, DB, cache, jobs, translations, RBAC and integrations. Changes ONLY in main when repository policy requires it; check current main state immediately before each write. Never reset user changes or override unrelated concurrent work.
7. Run source guard, language syntax, targeted tests and relevant neighboring regression; then PHPStan, PHP_CodeSniffer, PHPUnit, Deptrac, duplication checks and Rector dry-run where configured. Rector automatic edits ONLY under existing narrow policy and validated manual workflow. Run affected UI/dark/mobile checks for UI changes. No fictitious green results.
8. Independent read-only review by code-quality-auditor or independent-reviewer for substantive work. Never skip mandatory security gates to force green CI. Stop after one evidence-based fix cycle or a concrete blocker, not infinite retries.
9. Report root cause, changed files, commands/results, residual risks. If no real defect, conclude NO CHANGE.
