# Update and Component Center

The administration UI is designed to expose the platform version, Extension API, PHP runtime, required PHP extensions, Composer packages, frontend packages, database/schema version, system modules, third-party extensions and update history.

Core and protected system modules cannot be casually removed. Third-party extensions are isolated and may be disabled, updated or removed without rewriting core files.

Core update sequence: fetch signed manifest; verify Ed25519; check PHP/database/Extension API compatibility; verify package SHA-256; create rollback point; run versioned Doctrine migrations; run health/smoke checks; atomically activate the release; rollback on a critical failure.

Internal libraries may be upgraded independently while the public Extension API contract remains compatible. PHP/runtime upgrades are compatibility events, not permission to mutate unrelated modules.
