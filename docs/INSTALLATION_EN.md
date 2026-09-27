# Installation

Nexora Commerce is designed so that installation never requires moving application files after setup.

## Preferred production layout

Upload/extract the complete release directory outside the public web root, for example:

`/home/account/nexora-commerce/`

Point the domain document root to:

`/home/account/nexora-commerce/public/`

Only `public/` is web-accessible. `src/`, `config/`, `.env.local`, migrations, vendor metadata and runtime secrets stay outside the document root.

Do not copy `index.php`, assets or media to another directory after installation. Do not move the application after installation unless the deployment path is intentionally changed as one release unit.

## Production release vs source package

A production release must contain:

- application source;
- Composer `vendor/` dependencies;
- the generated Composer runtime loader;
- compiled frontend assets under `public/build/` when the frontend build requires them;
- migrations and immutable configuration defaults.

A source package may omit `vendor/` and generated frontend output. It is not considered a one-upload production package. The browser setup detects missing runtime dependencies instead of attempting a partial installation.

## Browser installation

1. Extract the complete production release.
2. Configure the domain to use `<application>/public` as its document root.
3. Create a MySQL/MariaDB database and database user, or give the installer account permission to create the database.
4. Open `https://your-domain.example/setup.php`.
5. Resolve every red server-requirement check.
6. Enter database credentials, store name and first administrator details.
7. Start installation.

Before migrations start, browser setup also verifies that the real web Document Root resolves to `public/`, performs an actual create/write/rename/delete probe in writable runtime directories and the PHP temporary directory, checks PHP CLI 8.4/8.5 plus `bin/console` syntax, validates `utf8mb4` collation and `foreign_key_checks`, and exercises database privileges through isolated CREATE/ALTER/INDEX/REFERENCES/INSERT/SELECT/UPDATE/DELETE/DROP probes. HTTPS is required for non-local production URLs. OPcache, outbound HTTPS, symlink support, `upload_max_filesize >= 64M`, `post_max_size >= 64M`, `max_execution_time >= 120`, and `max_input_vars >= 3000` are reported as production recommendations.

The bootstrap writes `.env.local` with a random application secret and database connection, creates a one-time installation request, then hands control to the canonical `commerce:install` command inside Symfony. The same preflight and migrations are therefore used by browser and CLI installation.

After successful installation:

- `mc_installation` prevents a second database installation;
- `var/install/installed.lock` disables the standalone browser setup;
- the one-time file containing the initial administrator password is deleted;
- administration opens at `/admin/login`;
- no files are moved or copied.

## CLI installation

CLI is the preferred recovery/developer path. Create `.env.local` with the database connection and run:

`php bin/console commerce:system:check`

Then:

`php bin/console commerce:install --store-name="My Store" --admin-name="Administrator" --admin-email="admin@example.com" --admin-password="a-long-unique-password" --public-url="https://shop.example.com"`

The command performs preflight checks, Doctrine migrations and the initial transactional seed.

## Default initial data

A new installation creates:

- country/market: Ukraine;
- locale: `uk-UA`;
- currency: `UAH`;
- timezone: `Europe/Kyiv`;
- one default store;
- one main inventory location;
- first administrator;
- base VAT/tax configuration;
- strict consent policy;
- draft required information pages.

The legal/information pages are intentionally drafts. The merchant must enter real company/legal data before publication.

## Required server access

The web server must be able to write only runtime locations needed by the application. During browser setup it also needs to create `.env.local`. After installation permissions should be tightened so application source and configuration are not generally writable by the web process unless the deployment/update mechanism explicitly requires it.

Recommended deployment uses HTTPS, OPcache and HTTP/2 or HTTP/3 at the server/CDN layer.

## Apache

The release contains `public/.htaccess`. The virtual host document root must still point to `public/`. Apache must permit the required rewrite rules.

## Nginx

Use `public/` as `root`. Serve existing static files directly and route missing paths to `/index.php`. Deny access to dotfiles except standardized public `.well-known` resources when enabled.

A minimal conceptual routing rule is:

`try_files $uri /index.php$is_args$args;`

PHP requests should be limited to the intended front controller/setup entrypoints rather than passing arbitrary `.php` paths to PHP-FPM.

## After installation

No application files are transferred anywhere else. The next operational steps are configuration, not file movement:

- fill company/contact/legal pages;
- configure delivery providers;
- configure payment providers when available;
- configure SMTP/email;
- optionally configure Telegram notifications;
- connect Google/Merchant services;
- configure scheduled workers only for features that use asynchronous jobs;
- run the System Health check.

The storefront remains usable without Redis or a separate search service. Those are optional scale accelerators.
