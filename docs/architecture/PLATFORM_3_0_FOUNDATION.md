# Platform 3.0 foundation

Platform 3.0 keeps a single-install modular monolith while adding hard boundaries around deployment, extensions and presentation.

## Stability rules

- Core, customer media, runtime secrets and extension data are physically separated.
- Production deployment uses `releases/<id>`, `current` and `shared`.
- A release is prepared and health-checked before the `current` symlink is changed.
- A module cannot patch Core files. Declarative and remote extensions are preferred; trusted PHP extensions require a signed release pipeline.
- Optional functionality must fail soft and never make checkout or storefront bootstrap depend on it.

## Presentation

Design is resolved in three layers: Core defaults -> theme tokens -> user overrides. Page, header, footer and product layouts are structured JSON, validated before publish and always have a built-in Safe Layout fallback.

## Redistribution

The platform Core is MIT licensed. A dependency license gate rejects unknown or non-approved runtime licenses from release builds. Optional external services are never mandatory for a basic self-hosted store.
