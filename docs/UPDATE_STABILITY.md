# Update and stability contract

The platform separates implementation versions from public contracts.

- PHP/Symfony/DBAL/internal Core components may be upgraded independently.
- Extensions target the versioned Extension API, not Core internals.
- Core services intended for modules are exposed through contracts/interfaces only.
- Internal classes are not an extension API.
- A Core update runs compatibility checks before activation.
- Modules can be disabled without modifying Core files.
- Uninstall removes executable integration points but does not silently delete business history.
- Destructive data removal is a separate explicit purge operation.
- Orders keep immutable snapshots of payment/shipping/provider data so historical orders survive provider removal.
- Database migrations are owned by the package that creates the schema; another module may not rewrite those tables directly.
- External calls are behind adapters and failure boundaries.

The target result is that upgrading PHP or a framework library affects the Core package only unless a public contract itself is intentionally versioned to a new major release.
