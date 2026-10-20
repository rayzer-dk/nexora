# Change necessity and anti-slop policy (MANDATORY)

The default outcome is **NO CHANGE** unless a verified defect, explicit requirement, measurable gain or concrete security/compatibility issue justifies touching code. A no-change result is a valid successful task. No agent may invent new requirements, expand the work, rewrite working code for stylistic taste, or keep polishing after acceptance criteria pass.

Before proposing ANY modification, write down: (1) evidence/problem and affected behavior, (2) why the current implementation is actually insufficient, (3) minimal viable correction, (4) blast radius and dependent features, (5) targeted tests. For a minor harmless style difference, duplicated language resource, or speculative optimization, **report only**. If benefit is unproven or does not justify regression risk, STOP.

**AI-slop red flags**: helper layers that merely rename existing functions, wrappers without an abstraction benefit, duplicate classes/handlers/events, unused configuration/options, placeholder/TODO code presented as finished, catch-and-ignore exceptions, meaningless logging, speculative fallbacks, dead files, repeated UI widgets, redundant CSS overrides, polyfills already provided by the platform, unnecessary new dependencies, inflated prompts/docs, tests that only mirror the implementation, and changes made to keep an agent busy.

**Duplication audit** must distinguish harmful semantic duplication from reasonable repeated markup, translations, security guard checks at independent trust boundaries, compatibility shims, and intentionally isolated module code. Don't merge independent modules into hard dependencies for the sake of DRY. Compare business behavior and consumers, not only textual similarity. Prefer reusing existing canonical services **only** if dependencies remain optional and the change demonstrably reduces maintenance risk.

**Stop conditions**:
- STOP when all requested acceptance criteria are verified and independent review finds no blocking evidence.
- STOP without edits if no actionable defect exists; return evidence and 'NO CHANGE'.
- Do not reopen non-blocking cosmetic issues, speculative refactors, theoretical micro-optimizations, or new features without user request.
- Allow at most two *additional* repair-and-retest cycles for the same failure in one execution; if unresolved, report the reproducible blocker and required investigation instead of creating patch-on-patch loops. New material evidence can justify a revised root-cause plan, not blind looping.
- Run parallel roles only if genuinely independent; default 1 active worker, max 3 only when useful. QA and reviewer should stay read-only; never claim tests executed if not run.
- No unrequested commits, push, deployment, production access, secret exposure, destructive data changes, library upgrades, or unrelated file rewrites.

**Final change report**, compact: CHANGE / NO CHANGE; evidence; files modified; validation (PASS/FAIL/NOT RUN); outstanding issues. Do not report agent effort or elapsed time as proof of quality.
