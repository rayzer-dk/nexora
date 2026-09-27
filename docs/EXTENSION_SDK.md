# Nexora Extension SDK 1.1

Nexora extensions never patch Core files. Extension API 2.0 separates three execution models:

- `declarative`: settings, translations, safe CSS, pages and Builder contributions without executable third-party code;
- `remote_app`: HTTPS integration through public contracts/API without running third-party PHP in Core;
- `trusted_release`: executable PHP/JavaScript package allowed only after Ed25519 publisher-signature verification against `config/extensions/trusted-publishers.json`.

Unsigned PHP/JS remains quarantined. Shell/native executables and PHAR payloads are rejected entirely.

## Package structure

A full trusted module can use:

```text
manifest.json
translations/
  uk-UA/
    messages.json
    admin.json
    storefront.json
    checkout.json
blocks/
pages/
settings.schema.json
migrations/
assets/
src/
SIGNATURE.ed25519
```

The installed version remains isolated under `var/extensions/installed/<code>/<version>/`. Core files are never modified.

## Contributions

`manifest.json` can declare `blocks`, `routes`, `pages`, `slot_contributions`, `permissions`, `events`, `assets`, `migrations`, localization and trusted PSR-4 bootstrap metadata.

Builder component IDs, routes, pages, translation keys and permissions are extension-namespaced. For `vendor.delivery`, the namespace is `extension.vendor_delivery.*`. Installation is rejected on collisions with another installed extension. Route installation also rejects collisions with real Core routes.

Dynamic Builder blocks support `home`, `header`, `footer`, `product` and `checkout` surfaces. Product and checkout runtime already consume extension components. If an extension is disabled or removed, a saved `extension.*` block does not invalidate the whole layout: storefront runtime skips it and Builder shows an administrator warning.

Core checkout blocks `checkout_contact`, `checkout_shipping`, `checkout_payment`, `checkout_summary` and `checkout_consent` remain mandatory and cannot be disabled by extensions.

Stable UI slots are defined by `Commerce\Core\Extension\ExtensionPoint`, including product, checkout, admin, navigation and content points. `slot_contributions` may reference only those stable slots and only blocks declared by the same extension.

## Routes and pages

Public extension routes are resolved after normal Core routes and before the SEO fallback. Declarative `page` routes render extension-owned translated content. Trusted modules may register `trusted_handler` routes from their signed entrypoint.

Admin routes are restricted to:

`/admin/extensions/<normalized-extension-code>/...`

They require `trusted_handler` mode and a permission declared by the same module. The normal role matrix can assign extension permissions after activation.

## Trusted runtime

A trusted module declares `autoload.psr4` and `entrypoint`. Its entrypoint implements `TrustedExtensionEntrypointInterface` and receives `TrustedExtensionContext`.

The context currently supports trusted route handlers and domain-event handlers. Active trusted modules are booted lazily; disabled versions are not loaded.

The platform event catalog includes checkout, product, order, customer, content, layout and navigation lifecycle names in addition to the existing commerce outbox events. A trusted package may subscribe only through declared events and the runtime bridge; it does not patch controllers.

## Migrations

Trusted modules keep migrations inside their own package. A migration file returns `['up' => callable, 'down' => callable]`; both callables receive Doctrine DBAL `Connection`. Nexora records the migration path and SHA-256 in `mc_extension_migration`. Already-applied migrations are not rerun, a changed checksum is rejected, failed activation compensates newly applied migrations, and rollback runs `down` in reverse order.

Extension data is retained on disable/uninstall by default. Destructive data purge must remain an explicit separate action.

## Assets

Assets are declared with path, type (`css` or `js`) and scopes. JavaScript requires a trusted signed module. Declared assets are copied only to the extension's versioned public directory and are exposed through `extension_assets()` for `storefront.global` and exact route scopes such as `route:storefront_product_show`.

Extensions cannot inject arbitrary script tags into Core templates.

## Localization

Core translations use `resources/translations/<locale>/<domain>.php`. Extensions keep JSON translations inside their own package and never copy them into Core.

`uk-UA` is the mandatory extension fallback locale. Small modules normally use `translations/uk-UA/messages.json`; larger modules may split admin/storefront/checkout files. Extension translations use the namespaced `translations/<locale>/<domain>.json` structure only.

## Signing

Trusted publishers are registered by key id in `config/extensions/trusted-publishers.json`. The package signature is `SIGNATURE.ed25519`. The signed payload is a canonical digest of every ZIP entry except the signature file, preventing post-signing modification of manifest, PHP, migrations, assets or translations.

Create a package skeleton:

`php bin/console commerce:extension:scaffold vendor.module --name="My Module"`

Trusted skeleton:

`php bin/console commerce:extension:scaffold vendor.module --name="My Module" --execution=trusted_release`

Sign the final ZIP with a base64 Ed25519 secret key file:

`php bin/console commerce:extension:sign module.zip publisher-secret.key`

## Developer workflow in 3.5.0

Use `commerce:extension:validate`, `commerce:extension:test`, `commerce:extension:pack`, `commerce:extension:list-points` and `commerce:extension:list-events` during development. Scaffold presets include `basic`, `product-block`, `remote-integration` and `trusted-route`.

Settings schema v2 is the default for new extensions. Stable provider registration for signed trusted modules is documented in `EXTENSION_PROVIDER_CONTRACTS.md`.

Full tutorial: `DEVELOPER_GUIDE.md`. Ukrainian quick guide: `DEVELOPER_GUIDE_UK.md`. Reference packages: `examples/extensions/`.


Developer inspection commands:

```bash
php bin/console commerce:extension:list-points
php bin/console commerce:extension:list-events
php bin/console commerce:extension:list-contracts
```
