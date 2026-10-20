---
name: full-system-audit
description: Performs read-only architecture, security, compatibility and functional audit before refactoring or release.
---

# Full system audit

Read relevant standards and repository test commands. Identify actual source baseline and versions. Delegate independent read-only audits by subsystem (architecture, compatibility, security, performance) when worthwhile, without overlapping full-repository scans. Prioritize concrete reproducible issues over speculation, group by severity and feature area, include file path/line, impact and how to verify. No changes during audit unless user also requested fixes and edits are appropriately isolated. Never claim tested behavior when only source was inspected.

Mandatory necessity check: `docs/CHANGE_NECESSITY.md`. Do not fix speculative issues or continue iterating after acceptance criteria are met. NO CHANGE is valid.
