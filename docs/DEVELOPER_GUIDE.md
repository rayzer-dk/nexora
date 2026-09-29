# Nexora Commerce Extension Developer Guide

This guide is the supported starting point for third-party extensions. Do not patch Core files and do not depend on undocumented classes. Target the published Extension API shown by `Commerce\Core\Platform\PlatformVersion::EXTENSION_API`.

## 1. Choose the extension model

Use `declarative` for settings, translations, Builder blocks, safe CSS and declarative pages. Use `remote_app` when the business logic lives in an external HTTPS service. Use `trusted_release` only when PHP/JavaScript, migrations, trusted routes or event handlers must run inside Nexora. Trusted releases require an Ed25519 publisher signature and a trusted publisher key.

## 2. Create a module

Basic module:

    php bin/console commerce:extension:scaffold acme.loyalty --name="Acme Loyalty"

Product Builder block:

    php bin/console commerce:extension:scaffold acme.product_badge --name="Product Badge" --preset=product-block

Remote integration:

    php bin/console commerce:extension:scaffold acme.crm --name="Acme CRM" --preset=remote-integration

Trusted route/event module:

    php bin/console commerce:extension:scaffold acme.erp --name="Acme ERP" --preset=trusted-route

The generated package uses settings schema v2, namespaced translations and a namespace derived from the extension code.

## 3. Package anatomy

Typical package:

    manifest.json
    settings.schema.json
    translations/uk-UA/messages.json
    translations/en-US/messages.json
    blocks/
    pages/
    assets/
    src/                 # trusted_release only
    migrations/          # trusted_release only
    SIGNATURE.ed25519    # trusted_release only

The ZIP root must contain `manifest.json`. Core files are never copied over or replaced.

## 4. manifest.json

Every package declares a stable `code`, semantic `version`, Core constraint, `extension_api`, execution model, permissions, capabilities, events, UI slots and optional blocks/routes/pages/assets/migrations. All extension-owned identifiers must use the namespace generated from the extension code.

Use `hot_disable: true`. Extension data should normally be retained on disable/uninstall; destructive purge is a separate explicit operation.

## 5. Settings

New extensions use `schema_version: 2`. Supported controls are text, textarea, number, integer, boolean, select, url, email, secret, color, range, multilingual_text and multilingual_textarea. Version 2 also supports group, placeholder and advanced flags.

Secrets must not have a real packaged default. Nexora encrypts saved secret values and never returns the clear value to the form.

## 6. Localization

Keep extension translations inside the package. Do not copy language files into Core. `uk-UA` is the mandatory fallback. Use domain JSON files when the module becomes large:

    translations/uk-UA/messages.json
    translations/uk-UA/admin.json
    translations/uk-UA/storefront.json
    translations/uk-UA/checkout.json

Do not hard-code merchant-facing text in PHP/Twig/JavaScript when it belongs in translations.

## 7. Builder and UI extension points

List the stable extension points exposed by the installed release:

    php bin/console commerce:extension:list-points

Declare Builder blocks in `blocks` and use only a stable surface/region. A disabled or removed extension must not make a saved layout fatal; Nexora skips unavailable extension components and reports the missing component to the administrator.

Do not inject arbitrary HTML/JavaScript into Core templates. JavaScript assets require a trusted signed module.

## 8. Routes and admin pages

Declarative public pages use `mode: page`. Trusted executable handlers use `mode: trusted_handler` and must be registered by the signed entrypoint.

Admin extension routes are restricted to:

    /admin/extensions/<normalized-extension-code>/...

An admin route must declare an extension-owned permission. The merchant assigns that permission using the normal role matrix.

## 9. Events

List public events for the installed release:

    php bin/console commerce:extension:list-events

A trusted package declares event names in `manifest.json` and registers handlers in its entrypoint with `TrustedExtensionContext::on()`. Do not monkey-patch controllers or subscribe to undocumented internal events.

## 10. Trusted provider contracts

Signed trusted modules can register payment, shipping, product-page and AI providers without editing Core or Symfony service configuration. Declare one or more capabilities: `provider.payment`, `provider.shipping`, `provider.product_block`, `provider.ai`, then register the implementation through `TrustedExtensionContext`. Duplicate provider codes and undeclared capabilities are rejected. See `docs/EXTENSION_PROVIDER_CONTRACTS.md`.

## 11. Database migrations

Only trusted signed packages may run migrations. Migration files live under `migrations/` and MUST return an array with reversible `up` and `down` callables receiving Doctrine DBAL `Connection`. Nexora records path and SHA-256. Applied migrations are not rerun, a checksum change is rejected, failed activation compensates newly applied migrations, and version rollback executes `down` in reverse order.

Design migrations to be forward-safe and idempotent at the schema level. Disabling a module must not delete its business data. Data purge is a separate explicit operation.

## 12. Remote integrations

Choose `remote_app` for CRM, ERP, fulfillment, analytics or SaaS integrations that do not need in-process PHP. Declare an HTTPS base URL and optional health/webhook paths. Use public Nexora API/webhook contracts rather than direct database access.

Use authentication, request signing, replay protection and idempotency keys for write operations. Never ship API secrets inside the extension ZIP.

## 13. Trusted releases and signing

Trusted executable packages are fail-closed. Unsigned PHP/JS is quarantined. Generate a key outside the package, register the publisher public key through the deployment/administrator trust process, then sign the finished ZIP:

    php bin/console commerce:extension:sign module.zip publisher-secret.key

Never distribute the private signing key.

## 14. Validate, test and pack

Validate an existing ZIP without installing it:

    php bin/console commerce:extension:validate module.zip

Run SDK package checks:

    php bin/console commerce:extension:test module.zip

Build a ZIP from a source directory and validate the result:

    php bin/console commerce:extension:pack path/to/module --output=dist/module.zip

For trusted releases, sign the final package after packing, then validate/test the signed archive again.

## 15. Install/update lifecycle

Upload does not activate a module. Nexora validates and stages the package first. Executable packages that fail trust checks are quarantined. Activation runs extension migrations/assets before switching the active pointer. If activation fails, the currently active version remains in place. Previous installed versions may be used for rollback.

A module must tolerate disable/enable cycles and an upgrade from the previous published version. Never rely on code executing while the module is disabled.

## 16. Compatibility rules

Treat `extension_api` as the public extension contract. Do not use internal classes simply because they are technically autoloadable. When a capability is not exposed by a stable event, UI slot, route or public contract, request/introduce a platform extension point rather than patching Core.

Do not overwrite checkout, catalog, order or admin templates. Use Builder components, stable slots, events, routes and public APIs.

## 17. Release checklist

Before publishing, confirm: valid manifest; compatible Core and Extension API; no Core patching; no secrets; all settings validated; all merchant-facing strings localized; permissions least-privilege; admin routes namespaced; no unsafe external URLs; migrations forward-safe; disable works; upgrade works; package passes validate/test; trusted package signed after final build.

Reference source packages are under `docs/examples/extensions/`.


Developer inspection commands:

```bash
php bin/console commerce:extension:list-points
php bin/console commerce:extension:list-events
php bin/console commerce:extension:list-contracts
```

## Theme SDK 1.0

Nexora supports two theme levels.

1. Declarative theme: `type: theme`, `execution: declarative`. It can ship `theme.css`, fonts and images. It is the safest option and cannot execute PHP, JavaScript or Twig.
2. Trusted theme: `type: theme`, `execution: trusted_release`. A signed trusted theme may additionally ship storefront Twig overrides under `templates/`. The active theme directory is prepended to the `@storefront` Twig namespace, so a theme can override the storefront shell and components without modifying Core.

Trusted theme overrides are intentionally restricted to storefront templates. Paths under `templates/admin/`, `templates/email/` and `templates/order_document/` are rejected. Administration, transactional e-mail and legal/order documents remain Core-controlled.

Recommended override targets include `base.html.twig`, `home.html.twig`, `components/`, `catalog/`, `category/`, `product/`, `cart/`, `checkout/`, `account/`, `blog/`, `forum/`, `compare/` and `content/`.

A theme update is installed as a new semantic version. Activation atomically switches the active theme only after validation. The previous theme remains available for rollback.

## Extension upgrades and Core updates

Never overwrite an installed extension directory in place. Publish a new semantic version and install the ZIP normally. Nexora keeps the previous version, applies extension migrations, validates assets, and only then atomically changes the active pointer. If activation fails, the currently active version remains intact. The administrator can roll back to the previous verified version.

Before a Core update is staged, Nexora validates every active extension against the target Core version and target Extension API from the signed update manifest. If an active extension does not declare compatibility, the Core update is blocked and the incompatible extension is named in preflight output. Core does not overwrite `var/extensions/installed` or extension-owned data.

A trusted extension that fails while booting is isolated automatically: only that extension is disabled, its error is recorded in the extension lifecycle journal, and Core continues booting.

The admin Extensions screen contains an extension lifecycle journal for install, activate, disable, rollback, runtime isolation and failures. System-wide intercepted runtime errors remain available under System > Stability with request ID, route and safe error summary.

## Universal catalog import

For a known Nexora file use the fast standard CSV importer. For third-party files use Commerce > Import/Export > Universal CSV/XLSX.

The wizard accepts CSV or XLSX, previews the source columns and sample rows, suggests common mappings, then lets the administrator map source columns to Nexora fields. `sku`, `name` and `price` are required. Preview uses the same product validation/write pipeline as the final import.

This workflow is intended for ordinary exports from OpenCart/ocStore and other platforms when direct database migration is not available. For a full OpenCart/ocStore migration including languages, categories, options, SEO URLs, customers and orders, use Migration Center instead of a flat product file.


## Commercial and hosted extensions

Use `commercial.model` = `free`, `one_time`, `subscription` or `external`. A `remote_app` installs a connector manifest/settings package; it does not execute code fetched from the developer server. Authentication uses API/OAuth credentials configured after installation. Signed `trusted_release` packages carry their executable code inside the ZIP. See `docs/COMMERCIAL_EXTENSIONS.md`.


### Reversible migration example

```php
<?php
use Doctrine\DBAL\Connection;
return [
    'up' => static function (Connection $db): void { /* additive forward change */ },
    'down' => static function (Connection $db): void { /* exact reverse change */ },
];
```

Trusted migrations are required to be reversible before package activation.

## Signing trusted modules

    php bin/console commerce:extension:keygen acme.2026 --out=acme.2026.key
    php bin/console commerce:extension:pack path/to/module --output=module.zip
    php bin/console commerce:extension:sign module.zip acme.2026.key

`keygen` prints the manifest `publisher`/`signature` fragment and the public key to add to `config/extensions/trusted-publishers.json`. Product blocks must declare regions from: `hero_media`, `hero_summary`, `below_primary`, `below_secondary`, `mobile_sticky`.
