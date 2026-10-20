---
name: root-cause-fix
description: Finds and fixes first cause of a bug without patch stacking, verifying affected dependencies and regression.
---

# Root-cause fix

Reproduce the reported failure or establish its exact evidence. Determine good baseline and affected scope. Trace from visible symptom to the underlying broken condition using the whole relevant call chain (UI/routes/controllers/models/SQL/template/events/queues/integrations). Distinguish a hypothesis from a proven cause. Implement smallest coherent correction without stacking compatibility shims or blind search/replace. Re-run reproduction and neighbors; independent reviewer assesses remaining risk. When reproduction is unavailable state what was inspected and cannot be confirmed.

Mandatory necessity check: `docs/CHANGE_NECESSITY.md`. Do not fix speculative issues or continue iterating after acceptance criteria are met. NO CHANGE is valid.
