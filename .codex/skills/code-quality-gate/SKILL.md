---
name: code-quality-gate
description: Read-only review of harmful code duplication, unnecessary refactoring and AI-slop in a specified changed area. Use on request or when a substantial diff needs quality verification.
---

# Code quality gate (NO UNNECESSARY CHANGE)

Read `AGENTS.md`, `docs/CHANGE_NECESSITY.md`, appropriate project standard. Check only the current scope and its actual consumers, starting with the final diff. Ask `code-quality-auditor` for an independent read-only check if available and beneficial. Do not alter files.

1. Establish what must work and the verified baseline.
2. Inspect duplicate **behavior**, not just duplicate lines. Identify existing canonical code and module boundaries.
3. Check for wrappers, unused options, blanket catches, redundant fallbacks, repeated OCMOD hooks, CSS overrides, dead implementations, speculative improvements and invented dependencies.
4. Check false positives: intentionally independent modules, translation and localization, guards at distinct trust boundaries and compatibility layers.
5. Classify as BLOCKER / ACTIONABLE / OPTIONAL / NO ISSUE, with concrete file references and reproducible reasoning.
6. Recommend edits only for demonstrable defect or material maintenance risk. If none, say NO CHANGE. Do not create an additional work cycle merely to justify agent activity.
