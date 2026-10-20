---
name: engineering-autopilot
description: Orchestrates non-trivial engineering implementation from requirement to independent review and evidence. Use for multi-step development, system repairs and modules.
---

# Engineering Autopilot

Read `AGENTS.md`, `docs/TEAM_PROCESS.md` and the relevant project-specific standard. Identify user intent, acceptance criteria and active environment. Establish a baseline, classify change risk, map relevant dependencies and plan appropriately.

For a complex task, run independent read-only architect, security and compatibility investigation concurrently only when helpful. Do not delegate a basic task unnecessarily. After understanding the root cause/design, assign implementation to one writer per file or separate worktrees. Coordinate integration and run relevant tests. Request a read-only independent review of the **actual final diff**. Address supported blockers at their root causes, retest and produce the final package if asked. Stay within user's project and authorized scope; never push/deploy/live-write or erase work without approval. Summarize in Russian with evidence, not promises.

## Necessity and stop gate
Mandatory read: `docs/CHANGE_NECESSITY.md`. Default to one worker, use specialist subagents only for independent tasks. Add `visual-designer` only when visual work is requested, and `code-quality-auditor` only when duplication/slop review is worthwhile. Before each edit prove the defect or requirement; NO CHANGE is acceptable. Stop immediately once acceptance is verified and no blocker remains; do not invent follow-up refactors.
