# Nexora project guardrails

Read the actual Nexora architecture, dependencies, environment files (never print secrets), migrations, API contracts, tests and package scripts before deciding the implementation approach. Do not presume a stack, database, rendering strategy, deployment target, or existing feature just from the project's name.

- Preserve existing behavior and data through architectural changes. New or altered features need documented interface contracts and compatible migrations where applicable.
- For CMS/page-builder work, favor structured versioned content blocks with schema validation, stable identifiers, explicit migrations, safe rendering and extensibility; don't replace a working page representation without verified migration and rollback.
- Keep content/editing UI intuitive, mobile-adaptive and efficient; ensure accessibility, responsive design and functional light/dark themes when relevant.
- Protect admin APIs with authentication, authorization, CSRF where needed, input validation, output escaping, and audit logs free of secrets.
- Test feature boundaries: persistence/reopen, renderer/editor correspondence, media, versions, localization, background jobs, caching and performance. Check relevant browser flows and actual screenshots for visual changes.
- Long jobs must support safe checkpoints, bounded batches, clear progress and recovery. Do not turn off protections to make a test pass.
