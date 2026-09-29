# Canonical source

Nexora Commerce has one canonical product line.

After PR #2 is merged, the only canonical source branch is `main`. Temporary audit, migration, release-preparation and development branches must never be treated as separate product versions. They may exist only while work is in progress and must be merged, synchronized or removed when the work is accepted.

Rules:
- `main` defines the current Nexora source, database schema and release metadata.
- A feature is not considered complete merely because an admin page saves data; its runtime/storefront consumer must be covered by a contract test where applicable.
- Stable modules must have a working end-to-end lifecycle or an explicit disabled/not-configured state. Placeholder success states are not allowed.
- Release/version/schema metadata must be derived from the canonical platform version and validated by CI.
- Old audit branches must not be merged over a newer canonical line.
- Generated dependencies and compiled assets are release artifacts, not independent source branches.
