# Deployment model

Nexora Commerce uses immutable-release deployment principles.

The preferred filesystem layout is:

- `releases/<version>/` — complete application release;
- `current` — atomic pointer to the active release;
- persistent runtime/shared data outside immutable release directories where appropriate;
- web document root points to `current/public`.

Core updates are staged into a new release directory. The active release is not patched in place. Preflight, signature/hash verification, database/files checkpoint, migrations, cache warmup and health/smoke checks run before activation. The pointer is switched only after checks pass.

Additional extensions cannot overwrite Core source. Removing an extension removes its executable integration while historical order snapshots remain readable.

Production release archives should already contain runtime Composer dependencies and compiled frontend output. Production servers should not require npm and should not require Composer merely to serve a request.
