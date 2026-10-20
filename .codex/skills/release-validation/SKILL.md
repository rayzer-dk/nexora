---
name: release-validation
description: Validates complete release archive including language parity, packaging, versioning, docs and regression evidence.
---

# Release validation

Start from approved final source and changed-file manifest. Verify release version consistency, complete archive tree, installer and upgrade paths, nested archives where present, language parity/UTF-8 BOM, PHP syntax and tests for available versions, JavaScript bundles if applicable, packaging hygiene, no credentials, and README installation/upgrade/remove/backup guidance. For CodeCart modules follow `docs/CODECART_STANDARD.md` X.Y.Z restrictions and self-contained .ocmod.zip rules. Never package only changed files as a complete distribution. Record hash/manifest and observed validation. Do not upload or deploy without explicit approval.

Mandatory necessity check: `docs/CHANGE_NECESSITY.md`. Do not fix speculative issues or continue iterating after acceptance criteria are met. NO CHANGE is valid.
