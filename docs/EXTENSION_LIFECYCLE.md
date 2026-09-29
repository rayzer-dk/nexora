# Extension lifecycle and compatibility

Extension versions are immutable. `vendor.module` version `1.2.0` and `1.3.0` are stored as separate installations. Installing a newer ZIP never modifies the active version in place.

Lifecycle: validate -> stage -> migrate -> publish assets -> activate pointer. Previous active version -> disabled but retained -> rollback candidate.

The lifecycle audit records install, activation, disabling, rollback, automatic runtime isolation and errors. Diagnostic logging is fail-open and can never block the store.

Core update preflight reads the target signed update manifest and checks each active extension's `core` constraint and `extension_api`. An incompatible active package blocks the Core update before maintenance/snapshot/switching starts.

Trusted runtime failures are isolated to the failing package. Built-in Core remains active.

Uninstall removes one non-active version (files, published assets, settings). With the `purge_data` option its `down` migrations run first; otherwise module tables are retained and migrations must be idempotent on reinstall. The action is recorded as `uninstall` in the lifecycle audit trail.
