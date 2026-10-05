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

## One canonical address (www / non-www, http / https)

The address in `APP_PUBLIC_URL` is the only public address of the site. A request to the other `www` variant of the same
host, or to plain `http` when `APP_PUBLIC_URL` is `https`, gets a permanent (301) redirect to it, with the path and query
kept. POST requests, `/health`, `/webhooks/*`, `/cron/*`, `/.well-known/*`, local hosts and IP addresses are never redirected.

- To make `www` the canonical host, set `APP_PUBLIC_URL=https://www.example.com`.
- Behind a proxy or CDN the redirect trusts `X-Forwarded-Proto: https` and Cloudflare's `CF-Visitor`. If your proxy talks to the
  origin over plain http without sending either header (for example Cloudflare "Flexible" SSL), set
  `COMMERCE_CANONICAL_REDIRECT=0` to avoid a redirect loop, or better, send `X-Forwarded-Proto`.
- `public/.htaccess` additionally disables directory listings and refuses dotfiles, backups, dumps and logs.
  On nginx the same is done by the server block (`autoindex off;` and `location ~ /\. { deny all; }`).
