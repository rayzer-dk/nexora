# Installation and update QA

A deployment is not considered installable merely because Composer resolves. The same preflight service is used before first install and before every Core update.

Required checks: certified PHP range, required PHP extensions, writable runtime/media paths, database connectivity, supported MySQL/MariaDB version, InnoDB, utf8mb4, strict SQL mode, migration state and Extension API compatibility. Recommended checks include OPcache, free disk space, max_allowed_packet, Redis for distributed deployments and image processing acceleration.

Run `php bin/console commerce:system:check` from CLI. The future installer UI must call the same service; it must not maintain a second list of requirements.

Safe Core update phases are immutable: signed manifest -> SHA-256 -> runtime/database preflight -> extension compatibility -> drain write workers -> database/files checkpoint -> unpack to a new release directory -> Composer platform validation -> migrations -> cache warmup -> smoke/health tests -> atomic release switch -> resume workers. Any failure before activation leaves the old release active. A failure after a database migration requires restoring the matching database checkpoint; destructive down-migrations are not used as an unsafe substitute for a real backup.

Updates never write over the currently running release directory. Third-party extensions cannot patch Core files.

## First installation command

`commerce:install` is the canonical installer path. It refuses to seed a second store when `mc_installation` already exists. Required preflight checks run before database mutations; migrations run before the transactional initial-data seed. If the seed fails, no installation marker is written and the command can be retried after correcting the cause. CI must use `commerce:install` rather than calling Doctrine migrations alone.

The initial seed creates Ukraine/uk-UA/UAH defaults, the UA market, main inventory location, administrator, store contact profile, tax/consumer defaults, consent policy and information-page drafts. Production launch readiness is separate from successful installation: seller identity, delivery/payment providers, legal texts and payment credentials still require explicit configuration and health checks.
