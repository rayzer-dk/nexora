---
name: ui-regression
description: Checks and fixes visual regressions including mobile, tables, dark/light themes and functional click paths.
---

# UI regression

Read design system, visual components and relevant forms/routes. Gather screenshots/viewport dimensions, browser console and network if browser tools are actually available. Check spacing, responsive overflow, modals/close buttons, icons/tooltips, input sizes and accessibility in both themes if supported. Assign scoped fixes to UI owner, avoid global overrides and verify browser behavior after rebuild. Distinguish source-level assumptions from genuine screenshot evidence. Include before/after observation and remaining untested viewports.

Mandatory necessity check: `docs/CHANGE_NECESSITY.md`. Do not fix speculative issues or continue iterating after acceptance criteria are met. NO CHANGE is valid.
