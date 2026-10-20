# Reusable engineering process

Mandatory read: `docs/CHANGE_NECESSITY.md`. First decide whether anything actually needs changing; refuse busywork even when the user requests an audit (an audit can return NO CHANGE).

1. Scope: objective, acceptance criteria, environment, suspected components, impact and available tests. Read the relevant project standards.
2. Baseline: inspect existing code, Git state, version, current artifacts, reproducible bug and neighboring behavior. Report any known unknowns.
3. Delegate: independent research, compatibility, security and architecture can run in parallel. Record scopes and dependencies. Never have two writers edit a shared file simultaneously.
4. Root cause and design: trace UI -> API/controllers -> persistence -> cache/jobs -> external integrations; compare hypotheses against evidence. Prefer minimal coherent change over accumulated patches.
5. Implementation: keep changes bounded, preserve changes from other collaborators, test while implementing, and make atomic commits only when user authorizes commits.
6. Verification: tests of the targeted behavior; regression and security check of adjacent functions; installation/upgrades; visual evidence for UI; performance measurements when performance is claimed.
7. Independent review: separate reviewer inspects final diff, reports severity and exact evidence, requests fixes where necessary; rerun tests after correction.
8. Necessity gate: code-quality-auditor may inspect relevant diff for semantic duplication, speculative abstractions, unused code and AI slop when risk justifies it. Skip this gate for trivial cases unless user explicitly requests it.
9. Stop gate: stop as soon as criteria pass and no blocking evidence remains. Never start additional polish cycles. Maximum two further cycles on the same identified fault; then report the blocker.
10. Handoff: produce artifact only after packaging checks; report what changed, affected versions, evidence and limitations. Do not claim acceptance without tests. Do not automatically deploy, publish or push.

## Modes
- Quick: tiny changes, one implementer, targeted validation, lightweight reviewer when risk warrants.
- Standard: bugs/features, lead + investigator + implementer + QA/reviewer.
- Critical: security, DB migration, licenses, core upgrades: extra specialist verification, migration/rollback plan, backups and explicit deployment approval.

## Suggested acceptance categories
Functionality | Data integrity | Security | Compatibility | Performance | UX/responsiveness | Installation/update | Logs | Rollback.
Only require categories relevant to the change; never pretend a category was tested when it was not.
