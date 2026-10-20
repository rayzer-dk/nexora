# CodeCart PRO / OpenCart / ocStore project standards

This file applies only when the current repository is an OpenCart/ocStore/CodeCart core, theme, extension, OCMOD or module. Detect installed code versions and actual requirements, not guessed project settings.

## Safety and compatibility
- Prefer PHP 8.1–8.5 where supported by the actual baseline. Verify compatibility across the explicitly targeted versions, not just syntax on one interpreter. Use MySQL/MariaDB + DB_PREFIX, InnoDB and utf8mb4 where applicable.
- No obfuscated/encrypted code, eval, dangerous shell execution, or secret remote code loading. No insecure direct interpolation of request values into SQL: strings with db->escape(), numbers cast, ID lists integer-normalized, dynamic identifiers whitelist-only. Use prepared statements if supported. Validate user_token, access/modify and inputs for privileged admin/AJAX/API operations.
- Never leak API keys, license keys, passwords, private tokens or sensitive diagnostic payloads in UI or logs. Secret inputs use type=password, an adjacent eye toggle; persisted secrets remain intact on ordinary form saves/provider switches.
- A module must be fully inert while OFF: no runtime cron/AJAX/Events/frontend assets/queries/workers/state changes, except safe admin configuration/diagnostics/licensing checks. Runtime state and licensing state are separate. After enable and initial config, permitted background functions must work autonomously.
- Modules independent by default; integrations are optional safe bridges, not hard dependencies. Preserve existing license flows when present; do not add licensing unless requested. No forced recurring license reactivation. Transient server failure must not invalidate a valid signed token.
- Keep durable state (tokens, redirects, feeds, histories, queues, progress, logs, licenses) in DB or persistent module storage; not only temporary cache. Prefer atomic writes and safe queue jobs (batches, TTL locks, retries, observable progress).

## UI/UX and data integrity
- Isolate module CSS/JS to its own scope, load only where active/needed; do not modify global admin header. Support light and dark themes. Keep clean compact responsive UI for Bootstrap 3/legacy layouts; check mobile, content overflow and existing theme overrides.
- Consistent blue header line for admin tables: border-top: 3px solid #0057d9. Tables should provide relevant search/sort/filter/limit/pagination with stable state, compact columns, long-text title tooltips and responsive behavior. Default page-size must follow the module's existing or user-approved policy.
- Icon-only buttons require title/tooltips. Cron commands require copy-to-clipboard affordance. Text fields have explicit input type=text or textarea as appropriate; secrets type=password.
- Additional product/category/other entity fields save via the standard OpenCart main entity form, never hidden AJAX on tab switch.
- For multilingual URL schemes detect active languages and actual SEO/translation plugins dynamically. Do not hardcode language path prefixes; support missing optional integrations.

## Release discipline
- Use complete self-contained `.ocmod.zip` when required; validate root `install.xml` and `upload/`, controller/model/view/languages/assets/OCMOD and any nested archives. Never deliver a partial patch archive as a full module.
- Module versions use X.Y.Z with each component one digit (1.0.9 -> 1.1.0). Synchronize version in filename, code, README, labels and installer metadata. Name the customer-facing module without CodeCart; author is CodeCart PRO (https://codecartpro.com).
- By default languages uk-ua, ru-ru, en-gb; preserve other installed languages and use dynamic language discovery for runtime operations.
- If an existing implementation uses the inverse ocStore product.noindex convention, verify its actual frontend behavior: in known ocStore deployments noindex=1 can mean index allowed. Do not infer the meaning from the column name; inspect runtime robots headers/meta.
- Verify install, update, disable, removal, licenses if already present, migrations, repeat saves, cron and frontend with representative existing data. Always preserve rollback/backup paths.

## Extra attention on inherited modules
- Respect existing interfaces and license activation API. Don't attach mandatory changes to global templates or unrelated modules. Track dependency chains: OCMOD/Event/controller/model/Twig/JS/SQL/cache/integration/front-end.
- Avoid bloated checks on tiny tasks: apply only validations implicated by change type; run full release matrix at packaging time.
