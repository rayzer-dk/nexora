# Changelog

## 3.96.3 — 2026-10-06

Fixes found by a full end-to-end pass on a clean install.

- Appearance: the container width field accepted only steps of 16 px, so the default 1500 px made the browser refuse to save the whole Appearance page; the step is now 4 px.
- Forum: the "pinned" label has enough contrast in the light theme.
- The cash register (PRRO) end-to-end check now confirms the return receipt dialog and waits for each receipt.
